#!/usr/bin/env python3
"""Tesseract OCR shim для prx450.

Локальная замена внешнего OCR-шлюза с тем же контрактом `/parse`:
PDF/изображение -> Markdown. Нужен для разработки и тестирования, когда
боевой шлюз недоступен. Не для production: качество ниже Docling/MinerU,
нет LLM-коррекции и кэша.

Запуск:
    pip install -r requirements.txt
    uvicorn main:app --host 127.0.0.1 --port 8090

Проверка:
    curl -F 'file=@sample.pdf' 'http://127.0.0.1:8090/parse?engine=auto'
"""

from __future__ import annotations

import json
import os
import tempfile
import time
from pathlib import Path

import pypdfium2 as pdfium
import pytesseract
from fastapi import FastAPI, File, HTTPException, Query, UploadFile
from PIL import Image

MAX_BYTES = int(os.getenv("TESSERACT_OCR_MAX_BYTES", str(26_214_400)))
DEFAULT_LANG = os.getenv("TESSERACT_OCR_LANG", "rus+eng")
DEFAULT_DPI = int(os.getenv("TESSERACT_OCR_DPI", "300"))
TIMEOUT_SEC = int(os.getenv("TESSERACT_OCR_TIMEOUT", "120"))

ALLOWED = {
    "application/pdf": ".pdf",
    "image/png": ".png",
    "image/jpeg": ".jpg",
    "image/tiff": ".tif",
    "image/webp": ".webp",
    "image/bmp": ".bmp",
}

app = FastAPI(title="Tesseract OCR shim", version="1.0.0")


@app.get("/health")
def health() -> dict:
    """Tesseract установлен и отвечает."""
    try:
        languages = pytesseract.get_languages(config="")
    except Exception as error:  # noqa: BLE001 - пробрасываем как 503
        raise HTTPException(status_code=503, detail=f"tesseract unavailable: {error}") from error

    return {
        "status": "ok",
        "engine": "tesseract",
        "languages": languages,
        "lang": DEFAULT_LANG,
        "dpi": DEFAULT_DPI,
    }


@app.get("/guide")
def guide() -> dict:
    """Короткая шпаргалка — тот же endpoint, что у боевого шлюза."""
    return {
        "parse": "POST /parse multipart file + query",
        "params": {
            "engine": "ignored, always tesseract",
            "output_format": "md | full",
            "lang": "tesseract languages, default rus+eng",
            "dpi": "PDF render DPI, 72..600",
            "psm": "tesseract page segmentation mode, 0..13",
        },
        "response": ["file", "content", "cached", "cache_source", "engine_used", "llm_fixed", "raw_result"],
    }


@app.post("/parse")
def parse(
    file: UploadFile = File(...),
    engine: str = Query("auto"),
    output_format: str = Query("md"),
    lang: str | None = Query(None),
    dpi: int | None = Query(None),
    psm: int | None = Query(None),
    extra_config: str | None = Query(None),
) -> dict:
    started = time.time()
    extra = _extra(extra_config)
    language = (lang or extra.get("lang") or DEFAULT_LANG).strip()
    render_dpi = min(600, max(72, int(dpi or extra.get("dpi") or DEFAULT_DPI)))
    mode = min(13, max(0, int(psm if psm is not None else extra.get("psm", 3))))
    output_format = output_format if output_format in ("md", "full") else "md"

    suffix = ALLOWED.get(file.content_type or "")
    if suffix is None:
        raise HTTPException(status_code=415, detail="unsupported file type")

    data = file.file.read(MAX_BYTES + 1)
    if len(data) > MAX_BYTES:
        raise HTTPException(status_code=413, detail="file too large")
    if not data:
        raise HTTPException(status_code=422, detail="empty file")

    name = Path(file.filename or "document").name[:255] or "document"
    with tempfile.TemporaryDirectory(prefix="prx450-ocr-") as tmp:
        source = Path(tmp) / f"input{suffix}"
        source.write_bytes(data)

        try:
            if suffix == ".pdf":
                pages = _pdf_text(source, language, render_dpi, mode)
            else:
                pages = [_image_text(source, language, mode)]
        except RuntimeError as error:
            raise HTTPException(status_code=504, detail=str(error)) from error
        except Exception as error:  # noqa: BLE001
            raise HTTPException(status_code=422, detail=f"ocr failed: {error}") from error

    content = "\n\n".join(page.strip() for page in pages if page and page.strip())
    return {
        "file": name,
        "content": content,
        "cached": False,
        "cache_source": None,
        "engine_used": "tesseract",
        "llm_fixed": False,
        "raw_result": {
            "pages": len(pages),
            "lang": language,
            "dpi": render_dpi,
            "psm": mode,
            "duration_ms": int((time.time() - started) * 1000),
        } if output_format == "full" else None,
    }


def _extra(raw: str | None) -> dict:
    if not raw:
        return {}
    try:
        value = json.loads(raw)
    except json.JSONDecodeError as error:
        raise HTTPException(status_code=422, detail="extra_config must be JSON") from error
    return value if isinstance(value, dict) else {}


def _pdf_text(path: Path, lang: str, dpi: int, psm: int) -> list[str]:
    document = pdfium.PdfDocument(str(path))
    pages: list[str] = []
    try:
        for index in range(len(document)):
            page = document[index]
            bitmap = page.render(scale=dpi / 72)
            image = bitmap.to_pil().convert("RGB")
            pages.append(_ocr(image, lang, psm))
            page.close()
    finally:
        document.close()
    return pages


def _image_text(path: Path, lang: str, psm: int) -> str:
    with Image.open(path) as image:
        return _ocr(image.convert("RGB"), lang, psm)


def _ocr(image: Image.Image, lang: str, psm: int) -> str:
    try:
        return pytesseract.image_to_string(
            image,
            lang=lang,
            config=f"--oem 1 --psm {psm}",
            timeout=TIMEOUT_SEC,
        )
    except RuntimeError as error:
        raise RuntimeError(f"tesseract timeout after {TIMEOUT_SEC}s") from error
