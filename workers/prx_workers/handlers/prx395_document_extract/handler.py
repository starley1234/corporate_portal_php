"""Извлечение текста из PDF для корпоративных систем.

Пайплайн: PHP (приватный PDF + prx120) → Python (PDF/OCR) → приватный артефакт PHP
→ prx120.complete с квитанцией. Содержимое не попадает в очередь.
"""
from __future__ import annotations

import logging
import re
import tempfile
from pathlib import Path

import httpx

from . import document_extract
from ... import job_api, site
from ...base import HandlerContext, PermanentJobError
from .document_extract import DocumentJobError
from .limits import limits

CHANNEL = "document.extract"

# Куда воркер ходит за файлом и куда отдаёт результат: (префикс env, путь API,
# модуль, чей токен показывать в --check-site — "worker" | "site").
SITE = ("PRX395_DOCUMENT_EXTRACT", "/prx395/worker", "prx395", "worker")
log = logging.getLogger("prx.document_extract")


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    job = ctx._current_job or {}
    job_id, attempt = int(job.get("id") or 0), int(job.get("attempts") or 0)
    if not job_id or not attempt:
        raise PermanentJobError("worker_config")
    base, source = site.module_api_config("PRX395_DOCUMENT_EXTRACT", "/prx395/worker")
    if not site.valid_http_base(base) or not ctx.config.api_token:
        log.error("document.extract job_id=%s php=%s (%s): проверьте адрес и WORKER_API_TOKEN",
                  job_id, site.safe_origin(base), source)
        raise PermanentJobError("worker_config")
    headers = {"X-Worker-Token": ctx.config.api_token, "X-PRX-Worker-Attempt": str(attempt),
               "Accept-Encoding": "identity"}
    url = f"{base}/{job_id}"
    phase = "download_pdf"
    try:
        async with httpx.AsyncClient(headers=headers, timeout=25, follow_redirects=False) as client:
            with tempfile.TemporaryDirectory(prefix="prx395_") as tmp:
                path = Path(tmp) / "document.pdf"
                await ctx.progress("download_pdf")
                await job_api.download_pdf(client, url + "/file", path, limits()["max_pdf_bytes"])
                result = await document_extract.extract(path, ctx.progress)
                phase = "store_result"
                await ctx.progress(phase)
                # job_api.post_result читает код ошибки из тела ответа PHP,
                # поэтому invalid_result/result_too_large видны как есть, а не
                # как worker_endpoint_unavailable.
                receipt = (await job_api.post_result(client, url + "/result", result)).get("receipt")
                if not isinstance(receipt, str) or not re.fullmatch(r"[a-f0-9]{32}", receipt):
                    raise ValueError
                return {"receipt": receipt}
    except httpx.HTTPError as exc:
        log.warning("document.extract job_id=%s stage=%s php=%s (%s): %s",
                    job_id, phase, site.safe_origin(base), source, site.network_error(exc))
        raise DocumentJobError("worker_endpoint_unavailable") from None
    except OSError:
        raise DocumentJobError("worker_endpoint_unavailable") from None
    except (DocumentJobError, PermanentJobError) as exc:
        if str(exc) in {"worker_endpoint_unavailable", "worker_auth"}:
            log.warning("document.extract job_id=%s stage=%s php=%s (%s): %s",
                        job_id, phase, site.safe_origin(base), source, str(exc))
        raise
    except (ValueError, AttributeError, httpx.InvalidURL):
        raise PermanentJobError("worker_endpoint_unavailable") from None
