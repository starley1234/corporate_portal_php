"""Извлечение полей счёта/УПД из PDF (prx397).

PHP (job+file) → worker: OCR → LangChain structured output → POST /result.
В очереди/БД не хранится текст. HTTP-протокол — общий `job_api` (Д6).
"""
from __future__ import annotations

import logging
import tempfile
from pathlib import Path

import httpx
from pydantic import BaseModel, Field

from ... import job_api, site
from ...base import HandlerContext, PermanentJobError
from ...langchain_llm import arun_structured
from ...ocr import extract_pdf
from .limits import limits

CHANNEL = "invoice.extract"
log = logging.getLogger("prx.invoice")

PERMANENT_CODES = {
    "invalid_pdf", "encrypted_pdf", "page_limit", "result_too_large", "no_text",
    "ocr_config", "ocr_unavailable", "ocr_auth", "ocr_rejected", "ocr_invalid_response",
    "ocr_incomplete", "ocr_page_failed", "file_unavailable", "worker_auth", "lease_lost",
    "invalid_result", "invalid_attempt", "method_not_allowed", "text_too_large_for_llm",
    "pdf_required",
}


def ocr_limits(lim: dict) -> dict:
    """Лимиты OCR для общего extract_pdf.

    PRX397_MAX_LLM_CHARS задан в символах, а bounded() в ocr/text.py считает
    байты UTF-8 (до 4 на символ в кириллице). Даём байтовый запас: решение о
    лимите LLM принимаем ниже по len(text), а не по байтам.
    """
    return {
        "max_pdf_bytes": lim["max_pdf_bytes"],
        "max_pages": lim["max_pages"],
        "max_text_bytes": lim["max_llm_chars"] * 4 + 65536,
        "pdf_memory_mb": lim["pdf_memory_mb"],
    }


class Party(BaseModel):
    name: str = ""
    inn: str = ""


class Line(BaseModel):
    name: str = ""
    qty: float | None = None
    price: float | None = None
    sum: float | None = None


class InvoiceFields(BaseModel):
    number: str = ""
    date: str = ""
    seller: Party = Field(default_factory=Party)
    buyer: Party = Field(default_factory=Party)
    total: float | None = None
    vat: float | None = None
    currency: str = ""
    lines: list[Line] = Field(default_factory=list)


SYSTEM = """Ты извлекаешь поля из счёта или УПД. Верни только структурированные данные по схеме.
Правила:
- Не выдумывай значения; если поле не найдено, верни пустую строку/null/пустой список.
- ИНН верни строкой только из цифр.
- Даты оставляй как в документе, если не уверен в нормализации.
- Суммы/количества верни числами.
- lines: каждая позиция с name, qty, price, sum (null если неизвестно)."""


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    job = ctx._current_job or {}
    job_id, attempt = int(job.get("id") or 0), int(job.get("attempts") or 0)
    if not job_id or not attempt:
        raise PermanentJobError("worker_config")

    base, source = site.module_api_config("PRX397_INVOICE", "/prx397_invoice/worker")
    if not site.valid_http_base(base) or not ctx.config.api_token:
        log.error("invoice.extract job_id=%s php=%s (%s): проверьте адрес и WORKER_API_TOKEN",
                  job_id, site.safe_origin(base), source)
        raise PermanentJobError("worker_config")

    headers = {"X-Worker-Token": ctx.config.api_token, "X-PRX-Worker-Attempt": str(attempt),
               "Accept-Encoding": "identity"}
    lim = limits()
    url = f"{base}/{job_id}"

    try:
        async with httpx.AsyncClient(headers=headers, timeout=60, follow_redirects=False) as client:
            with tempfile.TemporaryDirectory(prefix="prx397_") as tmp:
                path = Path(tmp) / "document.pdf"
                await ctx.progress("download_pdf")
                await job_api.download_pdf(client, url + "/file", path, lim["max_pdf_bytes"])

                await ctx.progress("ocr")
                ocr = await extract_pdf(path, limits=ocr_limits(lim), module="PRX397",
                                        progress=lambda stage, detail="": ctx.progress(stage, detail))
                text = ocr.get("text", "")
                if len(text) > lim["max_llm_chars"]:
                    await ctx.progress("failed")
                    await job_api.post_result(client, url + "/result", {
                        "error_code": "text_too_large_for_llm", "fields": {}, "text_chars": len(text)})
                    return {"status": "failed", "error_code": "text_too_large_for_llm"}

                await ctx.progress("llm")
                parsed, usage = await arun_structured(InvoiceFields, SYSTEM, f"Текст документа:\n\n{text}")
                fields = parsed.model_dump() if hasattr(parsed, "model_dump") else dict(parsed)

                await ctx.progress("store")
                result = await job_api.post_result(client, url + "/result", {
                    "fields": fields,
                    "confidence": None,
                    "text_chars": len(text),
                    "usage": usage,
                })
                return {"status": result.get("status", "done"), "job_id": job_id}
    except PermanentJobError as e:
        code = str(e)
        # OCR отверг текст по байтовому предохранителю — для владельца это
        # ровно тот же случай, что и превышение лимита символов LLM.
        if code == "result_too_large":
            code = "text_too_large_for_llm"
        if code in PERMANENT_CODES:
            await job_api.report_failure(headers, url, code)
        if code != str(e):
            raise PermanentJobError(code) from None
        raise
