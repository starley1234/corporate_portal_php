"""Рендер PDF-страницы в изображение и распознавание tesseract."""

from __future__ import annotations

import os
from pathlib import Path

from ..base import PermanentJobError
from ..ocr_client import TESSERACT_LANGS
from .errors import DocumentJobError
from .proc import run


async def tesseract_page(path: Path, page: int) -> str:
    prefix = path.parent / "page"
    image = prefix.with_suffix(".png")
    try:
        rc, _ = await run("pdftoppm", "-f", str(page), "-l", str(page), "-singlefile",
                          "-scale-to", "2200", "-png", str(path), str(prefix), timeout=20)
        if rc or not image.is_file():
            raise PermanentJobError("ocr_page_failed")
        rc, output = await run("tesseract", str(image), "stdout", "-l", TESSERACT_LANGS,
                               "--psm", "3", timeout=35, env={**os.environ, "OMP_THREAD_LIMIT": "1"})
        if rc:
            raise PermanentJobError("ocr_page_failed")
        return output.decode("utf-8", errors="replace").strip()
    except FileNotFoundError:
        raise PermanentJobError("ocr_unavailable") from None
    except TimeoutError:
        raise DocumentJobError("ocr_timeout") from None
    finally:
        image.unlink(missing_ok=True)
