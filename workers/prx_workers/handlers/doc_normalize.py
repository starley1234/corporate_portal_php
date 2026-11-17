"""Канал `doc.normalize`: PDF → текст (pypdf, при необходимости OCR).

Модуль-владелец — prx390_intake. Воркер НЕ пишет в базу: он скачивает файл
через `/prx390/api/file/<file_id>`, извлекает текст и отправляет его на
`POST /prx390/api/text/<message_id>`. Сайт сам решает, хватает ли текста
(`text_ready` / `needs_ocr`) и публикует событие для prx391.

Порядок:
  1. pypdf читает текстовый слой;
  2. если осмысленных символов >= 100 и текст не похож на мусор — OCR нет;
  3. иначе активный OCR-провайдер из `ocr_client` (tesseract или remote).

Ожидаемый payload:
    {"message_id": 12}

Результат: {"message_id": 12, "chars": 4312, "engine": "pypdf", "pages": 3}
Тело для сайта: {"file_id", "text", "pages", "engine", "confidence"}.
"""

from __future__ import annotations

import asyncio
import logging
import tempfile
import unicodedata
from pathlib import Path
from typing import Any

import httpx

from .. import ocr_client, site
from ..base import HandlerContext

log = logging.getLogger("prx.docflow")

CHANNEL = "doc.normalize"
_PREFIX = "PRX390"
_ROUTE_BASE = "/prx390/api"

# Ниже этого числа осмысленных (букв/цифр) символов включается OCR.
_MIN_CHARS = 100
_OCR_MAX_PAGES = 5


def _api_base() -> str:
    return site.module_api_base(_PREFIX, _ROUTE_BASE)


def _clean(text: str) -> str:
    return ocr_client.clean_text(text)


def meaningful_chars(text: str) -> int:
    """Сколько в тексте букв/цифр: пробелы и пунктуация не считаются."""
    return sum(1 for char in (text or "") if char.isalnum())


def looks_like_garbage(text: str) -> bool:
    """Грубая эвристика для «текста», который pypdf выдал из битых глифов."""
    chars = [char for char in (text or "") if not char.isspace()]
    if not chars:
        return True

    bad = 0
    for char in chars:
        if char == "\ufffd" or unicodedata.category(char) in {"Cc", "Co", "Cs", "Cn"}:
            bad += 1
    if bad / len(chars) > 0.02:
        return True

    if len(chars) >= _MIN_CHARS:
        alnum = sum(1 for char in chars if char.isalnum())
        if alnum / len(chars) < 0.15:
            return True

    return False


def is_usable_text(text: str) -> bool:
    """Достаточно ли текстового слоя, чтобы не запускать OCR."""
    return meaningful_chars(text) >= _MIN_CHARS and not looks_like_garbage(text)


def _extract_pypdf(path: Path) -> tuple[str, int]:
    try:
        from pypdf import PdfReader
    except Exception as exc:  # noqa: BLE001
        raise RuntimeError(
            "pypdf не установлен в воркере: добавьте `pypdf` в workers/requirements.txt "
            "и перезапустите воркер"
        ) from exc

    reader = PdfReader(str(path))
    if reader.is_encrypted:
        try:
            reader.decrypt("")
        except Exception:  # noqa: BLE001
            pass

    parts: list[str] = []
    for page in reader.pages:
        try:
            parts.append(page.extract_text() or "")
        except Exception:  # noqa: BLE001 — одна битая страница не должна убить документ
            parts.append("")

    return _clean("\n\n".join(parts)), len(reader.pages)


def _ocr_available() -> bool:
    """Есть ли локальный Tesseract (совместимость со старым кодом)."""
    return ocr_client.tesseract_available()


def _extract_ocr(path: Path, max_pages: int = 5) -> tuple[str, int]:
    """Совместимая синхронная обёртка над активным OCR-провайдером."""
    result = ocr_client.extract_sync(path, max_pages=max_pages)
    return result.text, result.pages


async def extract_text(path: Path, max_pages: int = _OCR_MAX_PAGES) -> dict[str, Any]:
    """Текст PDF и метаданные для `POST /prx390/api/text/<message_id>`.

    Сначала пробуем pypdf. Если текстового слоя достаточно (>= 100 осмысленных
    символов) — OCR не вызывается вообще, даже если провайдер настроен.
    """
    pypdf_text, pypdf_pages = "", 0
    pypdf_error: Exception | None = None

    try:
        pypdf_text, pypdf_pages = await asyncio.to_thread(_extract_pypdf, path)
    except Exception as exc:  # noqa: BLE001 — OCR ещё может спасти документ
        pypdf_error = exc
        log.warning("doc.normalize: pypdf не прочитал файл: %s", exc)

    if is_usable_text(pypdf_text):
        return {
            "text": pypdf_text,
            "pages": pypdf_pages,
            "engine": "pypdf",
            "confidence": None,
        }

    ocr_error: Exception | None = None
    try:
        ocr_result = await ocr_client.extract(path, max_pages=max_pages)
    except Exception as exc:  # noqa: BLE001 — ошибка провайдера не должна терять pypdf-текст
        ocr_error = exc
        ocr_result = ocr_client.OcrResult.failed()

    if ocr_result.text:
        return {
            "text": ocr_result.text,
            "pages": ocr_result.pages or pypdf_pages,
            "engine": ocr_result.engine or "ocr",
            "confidence": ocr_result.confidence,
        }

    if pypdf_text:
        # OCR не дал текста, но что-то из текстового слоя есть: сайт сам
        # поставит needs_ocr, а мы не теряем письмо.
        return {
            "text": pypdf_text,
            "pages": pypdf_pages,
            "engine": "pypdf",
            "confidence": None,
        }

    if pypdf_error is not None:
        raise pypdf_error
    if ocr_error is not None:
        raise ocr_error

    return {
        "text": "",
        "pages": 0,
        "engine": "failed",
        "confidence": None,
    }


async def _download(file_id: int, dest: Path) -> None:
    base = _api_base()
    if not base:
        raise RuntimeError("PRX390_API_BASE / PRX120_API_BASE не задан в workers/.env")

    token, _src = site.site_token(_PREFIX)
    if not token:
        raise RuntimeError("PRX390_SITE_TOKEN / WORKER_SITE_TOKEN не задан в workers/.env")

    url = f"{base}/file/{int(file_id)}"
    async with httpx.AsyncClient(timeout=120.0, follow_redirects=True) as client:
        r = await client.get(url, headers={"X-Worker-Token": token})
        if r.status_code != 200:
            raise RuntimeError(f"не удалось скачать файл #{file_id}: HTTP {r.status_code}")
        dest.write_bytes(r.content)


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    message_id = int(payload.get("message_id") or 0)
    if not message_id:
        raise ValueError("payload.message_id обязателен")

    ok, reason, data = await site.get_json(_PREFIX, f"/text/{message_id}", base=_api_base())
    if not ok:
        raise RuntimeError(f"конверт #{message_id} не получен: {reason}")

    files = data.get("files") or []
    if not files:
        raise RuntimeError(f"у конверта #{message_id} нет вложений")

    await ctx.progress("Подготовка", f"файлов: {len(files)}")

    best_text = ""
    best_engine = ""
    best_pages = 0
    best_file = 0
    best_confidence: float | None = None
    errors: list[str] = []

    with tempfile.TemporaryDirectory(prefix="prx390_pdf_") as tmp:
        for item in files:
            file_id = int(item.get("file_id") or 0)
            if not file_id:
                continue

            name = str(item.get("filename") or f"file-{file_id}.pdf")
            dest = Path(tmp) / f"{file_id}.pdf"

            try:
                await ctx.progress("Скачивание", name)
                await _download(file_id, dest)
            except Exception as exc:  # noqa: BLE001
                errors.append(f"{name}: {exc}")
                continue

            text, pages, engine, confidence = "", 0, "", None
            try:
                await ctx.progress("Текст", name)
                extracted = await extract_text(dest, max_pages=_OCR_MAX_PAGES)
                text = str(extracted.get("text") or "")
                pages = int(extracted.get("pages") or 0)
                engine = str(extracted.get("engine") or "")
                confidence = extracted.get("confidence")

                if engine == "failed" or not text:
                    errors.append(f"{name}: OCR-провайдер не дал текст")
                else:
                    await ctx.progress("Текст", f"{name} · {engine}")
            except Exception as exc:  # noqa: BLE001
                errors.append(f"{name}: {exc}")

            if len(text) > len(best_text):
                best_text, best_pages, best_engine, best_file = text, pages, engine, file_id
                best_confidence = confidence

    if not best_text:
        detail = "; ".join(errors[:3]) or "текстового слоя нет"
        raise RuntimeError(f"не удалось извлечь текст из PDF: {detail}")

    await ctx.progress("Сохранение", f"{len(best_text)} символов")
    ok, reason, _res = await site.post_json(
        _PREFIX,
        f"/text/{message_id}",
        {
            "file_id": best_file,
            "text": best_text,
            "pages": best_pages,
            "engine": best_engine or "pypdf",
            "confidence": best_confidence,
        },
        base=_api_base(),
        timeout=120.0,
    )
    if not ok:
        raise RuntimeError(f"сайт не принял текст: {reason}")

    await ctx.progress("Готово", f"{len(best_text)} символов · {best_engine}")

    return {
        "message_id": message_id,
        "file_id": best_file,
        "chars": len(best_text),
        "pages": best_pages,
        "engine": best_engine,
        "confidence": best_confidence,
        "files": len(files),
    }
