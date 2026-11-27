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


# Содержательные ошибки одного файла многофайловой спецификации: файл
# пропускается с предупреждением file_<n>_<code>, если другие дали строки.
FILE_SOFT_CODES = {"no_text", "header_not_found", "no_rows"}
MAX_FILES = 50


def payload_files(payload: dict) -> list[int]:
    """Номера файлов спецификации из payload (files: [{file_no}]); старые задачи — [1]."""
    files = payload.get("files")
    if files is None:
        return [1]
    if not isinstance(files, list) or not files or len(files) > MAX_FILES:
        raise PermanentJobError("bad_payload")
    numbers = []
    for f in files:
        n = f.get("file_no") if isinstance(f, dict) else None
        if not isinstance(n, int) or isinstance(n, bool) or not 1 <= n <= 99 or n in numbers:
            raise PermanentJobError("bad_payload")
        numbers.append(n)
    return numbers


def paged_text(ocr: dict, lim: dict) -> str:
    """Текст со страницами через \f (номер страницы строки); иначе склейка OCR."""
    pages = ocr.get("page_texts")
    if isinstance(pages, list) and pages and all(isinstance(p, str) for p in pages):
        joined = "\f".join(p.replace("\f", "\n") for p in pages)
        if len(joined.encode("utf-8")) <= lim["max_text_bytes"]:
            return joined
    return ocr.get("text", "")


def merge_math(parts: list[dict]) -> dict:
    """Математика нескольких файлов: ошибки и пропуски — списком, итоги — суммой."""
    if len(parts) == 1:
        return parts[0]
    declared = [p["totals"]["declared"] for p in parts]
    computed = [p["totals"]["computed"] for p in parts]
    d = round(sum(declared), 2) if all(v is not None for v in declared) else None
    c = round(sum(computed), 2) if all(v is not None for v in computed) else None
    ok = abs(d - c) <= 0.01 if d is not None and c is not None else None
    return {
        "row_errors": [e for p in parts for e in p["row_errors"]],
        "gaps": [g for p in parts for g in p["gaps"]],
        "totals": {"declared": d, "computed": c, "ok": ok},
        "summary": "; ".join(f"файл {i}: {p['summary']}" for i, p in enumerate(parts, 1)),
    }


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
    numbers = payload_files(payload)
    legacy = payload.get("files") is None
    log.info("specflow.parse job_id=%s mode=%s templates=%d forced=%s files=%d",
             job_id, mode, len(templates), forced or "-", len(numbers))

    lim = limits()
    parts: list[dict] = []
    skipped: list[tuple[int, str]] = []
    async with httpx.AsyncClient(headers=headers, timeout=60, follow_redirects=False) as client:
        with tempfile.TemporaryDirectory(prefix="prx400_") as tmp:
            for n in numbers:
                path = Path(tmp) / f"document_{n}.pdf"
                await ctx.progress("download_pdf", f"{n}/{len(numbers)}" if len(numbers) > 1 else "")
                await job_api.download_pdf(client, url + ("/file" if legacy else f"/file/{n}"), path, lim["max_pdf_bytes"])
                # Листы-продолжения часто без якорей шаблона: берём шаблон первого файла.
                fallback = parts[0]["tpl"] if parts else None
                try:
                    part = await _parse_file(ctx, path, mode, templates, forced_tpl or None, fallback, lim)
                except PermanentJobError as e:
                    if len(numbers) == 1 or str(e) not in FILE_SOFT_CODES:
                        raise
                    log.warning("specflow.parse job_id=%s file=%d skipped code=%s", job_id, n, e)
                    skipped.append((n, str(e)))
                    path.unlink(missing_ok=True)
                    continue
                path.unlink(missing_ok=True)
                for row in part["result"]["rows"]:
                    row["file_no"] = n
                part["file_no"] = n
                parts.append(part)
                if sum(len(p["result"]["rows"]) for p in parts) > lim["max_rows"]:
                    raise PermanentJobError("result_too_large")

            if not parts:
                raise PermanentJobError(skipped[0][1] if skipped else "no_rows")

            first = parts[0]
            rows = [r for p in parts for r in p["result"]["rows"]]
            warnings = [w for p in parts for w in p["warnings"]] + [f"file_{n}_{code}" for n, code in skipped]
            vias = {p["result"].get("stats", {}).get("via") or "ocr" for p in parts}
            result = {
                "rows": rows,
                "math": merge_math([p["result"]["math"] for p in parts]),
                "stats": {"via": vias.pop() if len(vias) == 1 else "mixed",
                          "pages_processed": sum(int(p["result"].get("stats", {}).get("pages_processed") or p["pages"]) for p in parts)},
            }
            text = "\n\n".join(p["text"] for p in parts if p["text"])
            body = result_body(first["tpl"], first["hits"], text, first["info"], sum(p["pages"] for p in parts), result, warnings)
            check_result_size(body, lim)
            await ctx.progress("store")
            await job_api.post_result(client, url + "/result", body)
            return {"status": "done", "job_id": job_id, "files": len(parts)}


async def _parse_file(ctx: HandlerContext, path: Path, mode: str, templates: list[dict], forced_tpl: dict | None,
                      fallback_tpl: dict | None, lim: dict) -> dict:
    """Один PDF → {tpl, hits, info, text, warnings, result, pages}; строки с page."""
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
                if tpl is None and fallback_tpl is not None:
                    tpl, hits = fallback_tpl, ["continuation"]
            if tpl is not None:
                await ctx.progress("parse")
                try:
                    result = parse.parse(paged_text(ocr, lim), tpl)
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
        elif fallback_tpl is not None and tpl is None:
            tpl = fallback_tpl
        else:
            info = await vision.detect(path, pages, templates, lim)
            tpl = next((t for t in templates if t.get("template_id") == info["template_id"]), tpl)
        vres = await vision.extract(path, pages, tpl, lim, ctx.progress)
        warnings += vres["warnings"]
        if vres["rows"]:
            rules = (tpl or {}).get("table_rules") or {}
            totals: dict = {}
            parse.normalize_levels(vres["rows"])
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

    return {"tpl": tpl, "hits": hits, "info": info, "text": text, "warnings": warnings, "result": result, "pages": pages}
