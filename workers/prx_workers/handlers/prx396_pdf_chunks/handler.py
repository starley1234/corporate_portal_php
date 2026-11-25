"""Извлечение текста из большого PDF по частям с продолжением после сбоя.

Пайплайн: PHP (batch + prx120) → Python (plan/parts) → PHP сохраняет части в prx212
→ batch done. В очереди только batch_id.
"""
from __future__ import annotations

import json
import logging
import re
import tempfile
from pathlib import Path

import httpx

from ... import job_api, site
from ...base import HandlerContext, PermanentJobError
from ...ocr.errors import DocumentJobError
from ...ocr.page import tesseract_page
from ...ocr.probe import probe_pdf, slice_pdf
from ...ocr.remote import extract_remote
from ...ocr.text import bounded
from ...ocr_client import provider_name, tesseract_available
from .limits import limits

CHANNEL = "pdf.chunks"

SITE = ("PRX396_PDF_CHUNKS", "/prx396_pdf_chunks/worker", "prx396", "worker")
log = logging.getLogger("prx.pdf_chunks")

# Коды, при которых batch в PHP валится через complete(error_code) — те же,
# что Service_Prx396_Extraction::PERMANENT_CODES. Сетевые/временные сюда не
# входят: их воркер поднимает как DocumentJobError и ретраит.
PERMANENT_CODES = {
    "invalid_pdf", "encrypted_pdf", "page_limit", "result_too_large", "no_text",
    "ocr_config", "ocr_unavailable", "ocr_auth", "ocr_rejected", "ocr_invalid_response",
    "ocr_incomplete", "ocr_page_failed", "file_unavailable", "lease_lost",
    "invalid_part", "invalid_plan", "invalid_result", "invalid_attempt", "method_not_allowed",
}


def _check(response: httpx.Response) -> dict | None:
    code = response.status_code
    if code in (401, 403):
        raise PermanentJobError("worker_auth")
    if code == 409:
        detail = _error_code(response)
        if detail == "batch_incomplete":
            raise DocumentJobError("worker_endpoint_unavailable")
        raise PermanentJobError("lease_lost")
    if code in (404, 410):
        raise PermanentJobError("file_unavailable")
    if code == 422:
        detail = _error_code(response)
        raise PermanentJobError(detail or "job_failed")
    if code in (408, 429) or code >= 500:
        raise DocumentJobError("worker_endpoint_unavailable")
    if not response.is_success:
        # 4xx от /part и прочих методов: настоящий код ошибки из тела ответа
        # (invalid_part, result_too_large, …), а не «недоступен». 413 без тела
        # (отрезал прокси) — тоже result_too_large: текст не влез в запрос.
        detail = _error_code(response)
        raise PermanentJobError(detail or ("result_too_large" if code == 413 else "job_failed"))
    try:
        body = response.content
    except httpx.ResponseNotRead:
        return None  # streamed-ответ: по статусу достаточно
    if body:
        try:
            return response.json()
        except ValueError:
            return None
    return None


def _error_code(response: httpx.Response) -> str:
    try:
        data = response.json()
        code = data.get("error", {}).get("code")
        return code if isinstance(code, str) else ""
    except Exception:  # noqa: BLE001
        return ""


async def _download(client: httpx.AsyncClient, url: str, path: Path, max_pdf_bytes: int) -> None:
    async with client.stream("GET", url) as response:
        # Стриминговый ответ: тело не читаем, только статус.
        job_api.check_status(response)
        if response.headers.get("Content-Type", "").split(";", 1)[0].strip().lower() != "application/pdf":
            raise PermanentJobError("file_unavailable")
        size = 0
        with path.open("xb") as pdf:
            async for block in response.aiter_bytes(chunk_size=65536):
                size += len(block)
                if size > max_pdf_bytes:
                    raise PermanentJobError("file_unavailable")
                pdf.write(block)
        if not size:
            raise PermanentJobError("file_unavailable")


async def _extract_part_local(path: Path, part: dict, progress, lim: dict) -> dict:
    page_from = int(part["page_from"])
    page_to = int(part["page_to"])
    total = page_to - page_from + 1
    # Слои только страниц части: проба всего документа с текстом убивала
    # крупный валидный PDF по таймауту (invalid_pdf без возможности повтора).
    part_probe = await probe_pdf(path, local=True, limits=lim, text=True,
                                 page_from=page_from, page_to=page_to)
    layers = part_probe.get("text_layers")
    if not isinstance(layers, list) or len(layers) != total:
        raise PermanentJobError("invalid_part")
    needed = [i for i in range(total) if not layers[i]]
    if needed and not tesseract_available():
        raise PermanentJobError("ocr_unavailable")
    values: list[str] = []
    for i in range(total):
        if layers[i]:
            values.append(layers[i])
        else:
            page_no = page_from + i
            await progress("ocr_page", f"{page_no}/{part_probe['pages']}")
            values.append(await tesseract_page(path, page_no))
    text = bounded("\n\n".join(values), lim["max_text_bytes"])
    return {
        "text": text,
        "format": "plain",
        "provider": "tesseract",
        "pages_processed": total,
        "warnings": [] if not needed else ["empty_page"],
    }


async def _extract_part_remote(path: Path, part: dict, progress, lim: dict) -> dict:
    page_from = int(part["page_from"])
    page_to = int(part["page_to"])
    total = page_to - page_from + 1
    await progress("ocr_remote", f"{page_from}-{page_to}")
    with tempfile.TemporaryDirectory(prefix="prx396_part_") as tmp:
        part_pdf = Path(tmp) / "part.pdf"
        count = await slice_pdf(path, part_pdf, page_from, page_to, limits=lim)
        result = await extract_remote(part_pdf, count, max_text_bytes=lim["max_text_bytes"], module="PRX396")
    result["pages_processed"] = result.get("pages_processed") if isinstance(result.get("pages_processed"), int) else total
    if result["pages_processed"] != total:
        raise PermanentJobError("ocr_incomplete")
    return {
        "text": result["text"],
        "format": "markdown",
        "provider": "remote",
        "pages_processed": total,
        "warnings": result.get("warnings", []),
    }


async def _post_json(client: httpx.AsyncClient, url: str, body: dict) -> dict:
    response = await client.post(url, json=body)
    data = _check(response)
    return data if isinstance(data, dict) else {}


async def _plan_or_none(client: httpx.AsyncClient, url: str) -> dict | None:
    response = await client.post(url + "/plan", json={})
    if response.status_code == 400:
        code = _error_code(response)
        if code == "invalid_plan":
            return None
        _check(response)
    data = _check(response)
    return data if isinstance(data, dict) else {}


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    job = ctx._current_job or {}
    job_id, attempt = int(job.get("id") or 0), int(job.get("attempts") or 0)
    if not job_id or not attempt:
        raise PermanentJobError("worker_config")

    batch_id = payload.get("batch_id")
    if not isinstance(batch_id, int) or batch_id < 1:
        raise PermanentJobError("worker_config")

    base, source = site.module_api_config("PRX396_PDF_CHUNKS", "/prx396_pdf_chunks/worker")
    if not site.valid_http_base(base) or not ctx.config.api_token:
        log.error("pdf.chunks job_id=%s php=%s (%s): проверьте адрес и WORKER_API_TOKEN",
                  job_id, site.safe_origin(base), source)
        raise PermanentJobError("worker_config")

    headers = {"X-Worker-Token": ctx.config.api_token, "X-PRX-Worker-Attempt": str(attempt),
               "Accept-Encoding": "identity"}
    url = f"{base}/{batch_id}"
    # Снимок лимитов из полезной нагрузки (PHP положил его при постановке)
    # перекрывает живое окружение: поведение задания не меняется, если .env
    # поправили, пока задача ждала в очереди. Без снимка (старые задачи) — env.
    lim = limits()
    snapshot = payload.get("limits")
    if isinstance(snapshot, dict):
        for key, value in snapshot.items():
            if key in lim and isinstance(value, int) and not isinstance(value, bool) and value > 0:
                lim[key] = value
    phase = "plan"

    try:
        async with httpx.AsyncClient(headers=headers, timeout=60, follow_redirects=False) as client:
            await ctx.progress("plan")
            plan = await _plan_or_none(client, url)
            if isinstance(plan, dict):
                parts = plan.get("parts", [])
                if isinstance(parts, list) and parts and all(isinstance(p, dict) and p.get("status") == "done" for p in parts):
                    phase = "complete"
                    await ctx.progress(phase)
                    await _post_json(client, url + "/complete", {})
                    return {"batch_id": batch_id, "pages_total": plan.get("pages_total"), "parts_total": len(parts)}

            with tempfile.TemporaryDirectory(prefix="prx396_") as tmp:
                path = Path(tmp) / "document.pdf"
                phase = "download_pdf"
                await ctx.progress(phase)
                await _download(client, url + "/file", path, lim["max_pdf_bytes"])

                phase = "validate_pdf"
                await ctx.progress(phase)
                try:
                    provider = provider_name()
                except RuntimeError:
                    raise PermanentJobError("ocr_config") from None
                # Проба без текстовых слоев: только структура и число страниц.
                # Слои добираются ниже для каждой части (см. _extract_part_local).
                probe = await probe_pdf(path, local=provider == "tesseract", limits=lim, text=False)

                if plan is None or not plan.get("parts"):
                    phase = "plan"
                    await ctx.progress(phase, f"pages={probe['pages']}")
                    plan = await _post_json(client, url + "/plan", {"pages_total": probe["pages"]})

                parts = plan.get("parts", [])
                if not isinstance(parts, list):
                    raise PermanentJobError("worker_endpoint_unavailable")

                pending = [p for p in parts if isinstance(p, dict) and p.get("status") in ("pending", "failed")]
                for part in pending:
                    part_no = int(part.get("part_no", 0))
                    page_from = int(part.get("page_from", 0))
                    page_to = int(part.get("page_to", 0))
                    phase = f"part_{part_no}"
                    await ctx.progress("extract_part", f"{part_no}/{len(parts)} pages={page_from}-{page_to}")

                    if provider == "tesseract":
                        result = await _extract_part_local(path, part, progress=ctx.progress, lim=lim)
                    else:
                        result = await _extract_part_remote(path, part, progress=ctx.progress, lim=lim)

                    phase = "store_part"
                    await ctx.progress("store_part", f"{part_no}/{len(parts)}")
                    await _post_json(client, url + "/part", {
                        "part_no": part_no,
                        "text": result["text"],
                        "format": result["format"],
                        "provider": result["provider"],
                        "pages_processed": result["pages_processed"],
                        "warnings": result.get("warnings", []),
                    })

                phase = "complete"
                await ctx.progress(phase)
                await _post_json(client, url + "/complete", {})
                return {"batch_id": batch_id, "pages_total": probe["pages"], "parts_total": len(parts)}

    except httpx.HTTPError as exc:
        log.warning("pdf.chunks job_id=%s batch_id=%s stage=%s php=%s (%s): %s",
                    job_id, batch_id, phase, site.safe_origin(base), source, site.network_error(exc))
        raise DocumentJobError("worker_endpoint_unavailable") from None
    except OSError:
        raise DocumentJobError("worker_endpoint_unavailable") from None
    except (DocumentJobError, PermanentJobError) as exc:
        if str(exc) in {"worker_endpoint_unavailable", "worker_auth"}:
            log.warning("pdf.chunks job_id=%s batch_id=%s stage=%s php=%s (%s): %s",
                        job_id, batch_id, phase, site.safe_origin(base), source, str(exc))
        if isinstance(exc, PermanentJobError) and str(exc) in PERMANENT_CODES:
            try:
                async with httpx.AsyncClient(headers=headers, timeout=15, follow_redirects=False) as c:
                    await c.post(url + "/complete", json={"error_code": str(exc)})
            except Exception:  # noqa: BLE001
                pass
        raise
    except (ValueError, AttributeError, httpx.InvalidURL):
        raise PermanentJobError("worker_endpoint_unavailable") from None
