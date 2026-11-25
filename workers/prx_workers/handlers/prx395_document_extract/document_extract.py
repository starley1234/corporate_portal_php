"""OCR для прогона PDF → текст: отдельный /parse-контракт и постраничный local.

Тонкая обёртка над общим `prx_workers.ocr`: сохраняет публичный API модуля
(имена, сигнатуры, поведение), чтобы тесты и обработчик prx395 не менялись.
"""
from __future__ import annotations

from pathlib import Path

from ...base import PermanentJobError
from ...ocr import page as ocr_page
from ...ocr import probe as ocr_probe
from ...ocr import remote as ocr_remote
from ...ocr.errors import DocumentJobError
from ...ocr.text import bounded as _bounded_common
from ...ocr_client import provider_name, tesseract_available
from .limits import MIB, limits

# Re-export for monkeypatching in tests.
_tesseract_page = ocr_page.tesseract_page


def _bounded(text: str, max_text_bytes: int | None = None) -> str:
    if max_text_bytes is None:
        max_text_bytes = limits()["max_text_bytes"]
    return _bounded_common(text, max_text_bytes)


async def probe_pdf(path: Path, local: bool) -> dict:
    return await ocr_probe.probe_pdf(path, local, limits=limits())


async def extract_local(path: Path, probe: dict, progress) -> dict:
    values = probe["text_layers"]
    total = probe["pages"]
    if not all(values) and not tesseract_available():
        raise PermanentJobError("ocr_unavailable")
    for page in range(total):
        if not values[page]:
            await progress("ocr_page", f"{page + 1}/{total}")
            values[page] = await _tesseract_page(path, page + 1)
    return {
        "text": _bounded("\n\n".join(values), limits()["max_text_bytes"]), "format": "plain", "provider": "tesseract",
        "pages_total": total, "pages_processed": total, "warnings": [] if all(values) else ["empty_page"],
    }


async def extract_remote(path: Path, pages: int, *, transport=None) -> dict:
    return await ocr_remote.extract_remote(path, pages, max_text_bytes=limits()["max_text_bytes"], transport=transport, module="PRX395")


async def extract(path: Path, progress) -> dict:
    try:
        provider = provider_name()
    except RuntimeError:
        raise PermanentJobError("ocr_config") from None
    await progress("validate_pdf")
    probe = await probe_pdf(path, local=provider == "tesseract")
    if provider == "tesseract":
        return await extract_local(path, probe, progress)
    await progress("ocr_remote")
    return await extract_remote(path, probe["pages"])
