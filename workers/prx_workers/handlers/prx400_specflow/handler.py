"""Разбор спецификации/BOM из PDF (prx400).

PHP (spec+file) → worker: OCR → детерминированный парсер по шаблону
(семантические якоря, синонимы колонок) → математический аудит → POST /result.
LLM не используется: разбор воспроизводим, шаблоны едут в payload задачи.
"""
from __future__ import annotations

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

PERMANENT_CODES = {
    "invalid_pdf", "encrypted_pdf", "page_limit", "result_too_large", "no_text",
    "ocr_config", "ocr_unavailable", "ocr_auth", "ocr_rejected", "ocr_invalid_response",
    "ocr_incomplete", "ocr_page_failed", "file_unavailable", "worker_auth", "lease_lost",
    "invalid_result", "invalid_attempt", "method_not_allowed", "header_not_found",
    "template_missing", "pdf_required", "worker_config", "bad_payload",
    "no_rows", "vision_config", "vision_unavailable", "template_not_found",
}


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


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    job = ctx._current_job or {}
    job_id, attempt = int(job.get("id") or 0), int(job.get("attempts") or 0)
    if not job_id or not attempt:
        raise PermanentJobError("worker_config")

    templates = payload.get("templates")
    if not isinstance(templates, list) or not templates:
        raise PermanentJobError("template_missing")

    base, source = site.module_api_config("PRX400_SPECFLOW", "/prx400_specflow/worker")
    if not site.valid_http_base(base) or not ctx.config.api_token:
        log.error("specflow.parse job_id=%s php=%s (%s): проверьте адрес и WORKER_API_TOKEN",
                  job_id, site.safe_origin(base), source)
        raise PermanentJobError("worker_config")

    headers = {"X-Worker-Token": ctx.config.api_token, "X-PRX-Worker-Attempt": str(attempt),
               "Accept-Encoding": "identity"}
    lim = limits()
    url = f"{base}/{job_id}"

    # Режим разбора (payload parse_mode; канон детерминированного ocr — по умолчанию):
    # ocr — только текстовый конвейер; ocr_vision — OCR, при пустом результате
    # vision-фолбэк; vision — сразу vision-разбор страниц (сканы, где tesseract
    # даёт «результат 0»).
    mode = payload.get("parse_mode") or "ocr"
    if mode not in ("ocr", "ocr_vision", "vision"):
        mode = "ocr"
    log.info("specflow.parse job_id=%s mode=%s templates=%d", job_id, mode, len(templates))
    forced = str(payload.get("template_id") or "")
    forced_tpl = next((t for t in templates if t.get("template_id") == forced), None)
    if forced and forced_tpl is None:
        await job_api.report_failure(headers, url, "template_not_found")
        raise PermanentJobError("template_not_found")
    if forced:
        log.info("specflow.parse job_id=%s forced_template=%s", job_id, forced)

    try:
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

                if mode != "vision":
                    await ctx.progress("ocr")
                    ocr = await extract_pdf(path, limits=ocr_limits(lim), module="PRX400",
                                            progress=lambda stage, detail="": ctx.progress(stage, detail))
                    text = ocr.get("text", "")
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
                            except ValueError:
                                result = None  # ocr_vision уйдёт в vision-фолбэк

                if mode == "ocr":
                    # Детерминированный путь без фолбэков: ошибка вместо «результат 0».
                    if len(text.strip()) < 40:
                        await job_api.report_failure(headers, url, "no_text")
                        raise PermanentJobError("no_text")
                    if tpl is None or result is None:
                        await job_api.report_failure(headers, url, "header_not_found")
                        raise PermanentJobError("header_not_found")
                    if not result["rows"]:
                        await job_api.report_failure(headers, url, "no_rows")
                        raise PermanentJobError("no_rows")

                if mode != "ocr" and (result is None or not result["rows"]):
                    await ctx.progress("vision")
                    if forced_tpl is not None:
                        tpl = forced_tpl
                    else:
                        info = await vision.detect(path, pages, templates, lim)
                        tpl = next((t for t in templates if t.get("template_id") == info["template_id"]), tpl)
                    vres = await vision.extract(path, pages, tpl, lim, ctx.progress)
                    if vres["rows"]:
                        rules = (tpl or {}).get("table_rules") or {}
                        totals: dict = {}
                        math = parse.run_math_checks(vres["rows"], totals, rules)
                        result = {
                            "rows": vres["rows"], "totals": totals, "math": math,
                            "stats": {"rows": len(vres["rows"]),
                                      "sections": len({r["section"] for r in vres["rows"] if r["section"]}),
                                      "via": "vision"},
                        }
                        hits = []

                if tpl is None:
                    await job_api.report_failure(headers, url, "header_not_found")
                    raise PermanentJobError("header_not_found")
                if result is None or not result["rows"]:
                    await job_api.report_failure(headers, url, "no_rows")
                    raise PermanentJobError("no_rows")
                if len(result["rows"]) > lim["max_rows"]:
                    await job_api.report_failure(headers, url, "result_too_large")
                    raise PermanentJobError("result_too_large")

                await ctx.progress("store")
                await job_api.post_result(client, url + "/result", {
                    "template_id": tpl.get("template_id", ""),
                    "template_title": tpl.get("template_name", ""),
                    "anchors_matched": hits,
                    "spec_number": _spec_number(text) or (info.get("spec_number") or ""),
                    "department": _department(text) or (info.get("department") or ""),
                    "pages": pages,
                    "text_sha256": parse.text_sha256(text),
                    "rows": result["rows"],
                    "totals": result["totals"],
                    "math": result["math"],
                    "stats": result["stats"],
                    "text_chars": len(text),
                    "mode": mode,
                })
                return {"status": "done", "job_id": job_id}
    except PermanentJobError as e:
        code = str(e)
        if code in PERMANENT_CODES:
            await job_api.report_failure(headers, url, code)
        raise
