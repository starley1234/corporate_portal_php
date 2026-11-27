"""Разбор спецификации/BOM из PDF (prx400).

PHP (spec+file) → worker: OCR → детерминированный парсер по шаблону
(семантические якоря, синонимы колонок) → математический аудит → POST /result.
Режим ocr — без LLM (воспроизводимо); vision/ocr_vision распознают страницы
мультимодальной LLM. Шаблоны едут в payload задачи. Терминальная ошибка —
один POST /result {error_code} (handle), повторяемые — повтор очереди.
"""
from __future__ import annotations

import json
import logging
import tempfile
from pathlib import Path

import httpx

from ... import job_api, site
from ...base import HandlerContext, PermanentJobError
from ...ocr import extract_pdf
from ...ocr.probe import probe_pdf
from . import vision
from . import parse
from .limits import limits

CHANNEL = "specflow.parse"
log = logging.getLogger("prx.specflow")

# Терминальные коды разбора, которые PHP принимает в /result ({error_code}) —
# зеркало Service_Prx400_Specs::FAILED_CODES. Протокольные ошибки (lease_lost,
# worker_auth, invalid_attempt, worker_config…) PHP не сообщаются: запись им
# не принадлежит или сайт недоступен — задачу закроет очередь, а prx400:purge
# сверит статус.
REPORTED_CODES = {
    "invalid_pdf", "encrypted_pdf", "page_limit", "result_too_large", "no_text",
    "ocr_config", "ocr_unavailable", "ocr_auth", "ocr_rejected", "ocr_invalid_response",
    "ocr_incomplete", "ocr_page_failed", "file_unavailable", "pdf_required",
    "header_not_found", "template_missing", "template_not_found", "template_invalid",
    "no_rows", "vision_config", "vision_unavailable", "bad_payload",
}
PARSE_MODES = ("ocr", "ocr_vision", "vision")


def ocr_limits(lim: dict) -> dict:
    """Лимиты для общего extract_pdf (REQUIRED_LIMIT_KEYS)."""
    return {
        "max_pdf_bytes": lim["max_pdf_bytes"],
        "max_pages": lim["max_pages"],
        "max_text_bytes": lim["max_text_bytes"],
        "pdf_memory_mb": lim["pdf_memory_mb"],
    }


def _spec_number(text: str) -> str:
    """Номер спецификации из шапки: «СПЕЦИФИКАЦИЯ №…» / «Ведомость №…»."""
    import re

    m = re.search(r"(?:спецификация|ведомость)[^\n]{0,40}?№\s*([A-Za-zА-Яа-я0-9][A-Za-zА-Яа-я0-9._-]{0,32})",
                  text[:4000], re.I)
    return m.group(1) if m else ""


def _department(text: str) -> str:
    import re

    m = re.search(r"^([^\n]{0,120}(?:отдел|управление|подразделение|служба)[^\n]{0,60})$",
                  text[:6000], re.I | re.M)
    return re.sub(r"\s+", " ", m.group(1)).strip()[:255] if m else ""


def result_body(tpl: dict, hits: list[str], text: str, info: dict, pages: int, result: dict,
                warnings: list[str]) -> dict:
    """Тело POST /result — контракт Service_Prx400_Pipeline::store_result."""
    stats = result.get("stats") or {}
    return {
        "template_id": tpl.get("template_id", ""),
        "template_title": tpl.get("template_name", ""),
        "anchors_matched": hits,
        "spec_number": _spec_number(text) or (info.get("spec_number") or ""),
        "department": _department(text) or (info.get("department") or ""),
        "pages": pages,
        "text_sha256": parse.text_sha256(text),
        "rows": result["rows"],
        "math": result["math"],
        "warnings": sorted(set(warnings)),
        "stats": {"via": stats.get("via") or "ocr", "pages_processed": stats.get("pages_processed", pages)},
    }


def check_result_size(body: dict, lim: dict) -> None:
    """Тело результата в пределах PRX400_MAX_RESULT_BYTES (тот же предел у PHP)."""
    size = len(json.dumps(body, ensure_ascii=False).encode("utf-8"))
    if size > lim["max_result_bytes"]:
        log.warning("specflow.parse result bytes=%d > limit=%d", size, lim["max_result_bytes"])
        raise PermanentJobError("result_too_large")


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    job = ctx._current_job or {}
    job_id, attempt = int(job.get("id") or 0), int(job.get("attempts") or 0)
    if not job_id or not attempt:
        raise PermanentJobError("worker_config")

    base, source = site.module_api_config("PRX400_SPECFLOW", "/prx400_specflow/worker")
    if not site.valid_http_base(base) or not ctx.config.api_token:
        log.error("specflow.parse job_id=%s php=%s (%s): проверьте адрес и WORKER_API_TOKEN",
                  job_id, site.safe_origin(base), source)
        raise PermanentJobError("worker_config")

    headers = {"X-Worker-Token": ctx.config.api_token, "X-PRX-Worker-Attempt": str(attempt),
               "Accept-Encoding": "identity"}
    url = f"{base}/{job_id}"
    try:
        return await _run(ctx, payload, headers, url, job_id)
    except PermanentJobError as e:
        # Единственная точка сообщения терминальной ошибки PHP (запись → failed).
        code = str(e)
        if code in REPORTED_CODES:
            await job_api.report_failure(headers, url, code)
        raise


async def _run(ctx: HandlerContext, payload: dict, headers: dict, url: str, job_id: int) -> dict:
    templates = payload.get("templates")
    if not isinstance(templates, list) or not templates or not all(isinstance(t, dict) for t in templates):
        raise PermanentJobError("template_missing")

    # Режим разбора: ocr — только текстовый конвейер (без LLM); ocr_vision — OCR,
    # при пустом результате vision-фолбэк; vision — сразу vision по страницам.
    # Неизвестный режим — ошибка задачи, а не тихий откат: PHP режим уже проверил.
    mode = payload.get("parse_mode") or "ocr"
    if mode not in PARSE_MODES:
        raise PermanentJobError("bad_payload")
    forced = str(payload.get("template_id") or "")
    forced_tpl = next((t for t in templates if t.get("template_id") == forced), None)
    if forced and forced_tpl is None:
        raise PermanentJobError("template_not_found")
    log.info("specflow.parse job_id=%s mode=%s templates=%d forced=%s", job_id, mode, len(templates), forced or "-")

    lim = limits()
    async with httpx.AsyncClient(headers=headers, timeout=60, follow_redirects=False) as client:
        with tempfile.TemporaryDirectory(prefix="prx400_") as tmp:
            path = Path(tmp) / "document.pdf"
            await ctx.progress("download_pdf")
            await job_api.download_pdf(client, url + "/file", path, lim["max_pdf_bytes"])

            probe = await probe_pdf(path, local=True, limits=lim)
            pages = int(probe["pages"])

            result: dict | None = None
            tpl: dict | None = None
            hits: list[str] = []
            info: dict = {}
            text = ""
            warnings: list[str] = []

            if mode != "vision":
                await ctx.progress("ocr")
                ocr = await extract_pdf(path, limits=ocr_limits(lim), module="PRX400",
                                        progress=lambda stage, detail="": ctx.progress(stage, detail))
                text = ocr.get("text", "")
                warnings += [w for w in (ocr.get("warnings") or []) if isinstance(w, str)]
                if len(text.strip()) >= 40:
                    await ctx.progress("template")
                    if forced_tpl is not None:
                        tpl, hits = forced_tpl, ["forced"]
                    else:
                        tpl, hits = parse.detect_template(text, templates)
                    if tpl is not None:
                        await ctx.progress("parse")
                        try:
                            result = parse.parse(text, tpl)
                            result.setdefault("stats", {})["via"] = "ocr"
                            result["stats"]["pages_processed"] = int(ocr.get("pages_processed") or pages)
                        except ValueError:
                            result = None  # ocr_vision уйдёт в vision-фолбэк

            if mode == "ocr":
                # Детерминированный путь без фолбэков: ошибка вместо «результат 0».
                if len(text.strip()) < 40:
                    raise PermanentJobError("no_text")
                if tpl is None or result is None:
                    raise PermanentJobError("header_not_found")

            if mode != "ocr" and (result is None or not result["rows"]):
                await ctx.progress("vision")
                if forced_tpl is not None:
                    tpl = forced_tpl
                else:
                    info = await vision.detect(path, pages, templates, lim)
                    tpl = next((t for t in templates if t.get("template_id") == info["template_id"]), tpl)
                vres = await vision.extract(path, pages, tpl, lim, ctx.progress)
                warnings += vres["warnings"]
                if vres["rows"]:
                    rules = (tpl or {}).get("table_rules") or {}
                    totals: dict = {}
                    math = parse.run_math_checks(vres["rows"], totals, rules)
                    result = {
                        "rows": vres["rows"], "totals": totals, "math": math,
                        "stats": {"via": "vision", "pages_processed": vres["pages_processed"]},
                    }
                    hits = []

            if tpl is None:
                raise PermanentJobError("header_not_found")
            if result is None or not result["rows"]:
                raise PermanentJobError("no_rows")
            if len(result["rows"]) > lim["max_rows"]:
                raise PermanentJobError("result_too_large")

            body = result_body(tpl, hits, text, info, pages, result, warnings)
            check_result_size(body, lim)
            await ctx.progress("store")
            await job_api.post_result(client, url + "/result", body)
            return {"status": "done", "job_id": job_id}
