"""Общие примитивы OCR для PDF-каналов (prx395, prx396, prx397, prx398).

Модули:
- probe: проверка PDF, число страниц, текстовый слой.
- page: рендер страницы в изображение и tesseract.
- remote: вызов внешнего /parse.
- text: нормализация и проверка размера текста.
"""
from __future__ import annotations

from pathlib import Path

from ..base import PermanentJobError
from ..ocr_client import provider_name, tesseract_available
from .page import tesseract_page
from .probe import probe_pdf
from .remote import extract_remote
from .text import bounded


async def extract_pdf(path: Path, *, provider: str | None = None, limits: dict, progress) -> dict:
    """Извлечь текст из PDF. Возвращает dict с text/format/provider/pages_total/pages_processed/warnings."""
    if provider is None:
        try:
            provider = provider_name()
        except RuntimeError:
            raise PermanentJobError("ocr_config") from None
    await progress("validate_pdf")
    probe = await probe_pdf(path, local=provider == "tesseract", limits=limits)
    if provider == "tesseract":
        values = probe["text_layers"]
        total = probe["pages"]
        if not all(values) and not tesseract_available():
            raise PermanentJobError("ocr_unavailable")
        for page in range(total):
            if not values[page]:
                await progress("ocr_page", f"{page + 1}/{total}")
                values[page] = await tesseract_page(path, page + 1)
        return {
            "text": bounded("\n\n".join(values), limits["max_text_bytes"]),
            "format": "plain",
            "provider": "tesseract",
            "pages_total": total,
            "pages_processed": total,
            "warnings": [] if all(values) else ["empty_page"],
        }
    await progress("ocr_remote")
    return await extract_remote(path, probe["pages"], max_text_bytes=limits["max_text_bytes"])
