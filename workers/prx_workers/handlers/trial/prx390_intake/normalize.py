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
import os
import tempfile
import time
import unicodedata
from pathlib import Path
from typing import Any

import httpx

from .... import ocr_client, site
from ....base import HandlerContext
from ....docflow_trace import NORMALIZE_TRACE as _TRACE

log = logging.getLogger("prx.docflow")

CHANNEL = "doc.normalize"
SITE = ("PRX390", "/prx390/api", "prx390", "site")
_PREFIX = "PRX390"
_ROUTE_BASE = "/prx390/api"

# Ниже этого числа осмысленных (букв/цифр) символов включается OCR.
_MIN_CHARS = 100
_OCR_MAX_PAGES = 5

# Контекст изолирован по asyncio-задаче и сохраняется в asyncio.to_thread.
# В логе только числовые ID; имена файлов и содержимое документов не попадают.
class _SafeFailure(RuntimeError):
    """Только составленное нами сообщение без произвольного текста извне."""


def _event(stage: str, message: str = "", *args: Any, level: int = logging.INFO) -> None:
    job_id, message_id, file_id = _TRACE.get()
    log.log(level, "doc.normalize job_id=%d message_id=%d file_id=%d stage=%s " + message,
            job_id, message_id, file_id, stage, *args)


def _engine_label(engine: str) -> str:
    known = {"pypdf", "tesseract", "remote", "ocr", "docling", "paddleocr", "mineru", "failed"}
    return engine if engine in known else "other"


def _env_present(*names: str) -> str:
    return "yes" if any(os.environ.get(name, "").strip() for name in names) else "no"


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

    started = time.monotonic()
    try:
        pypdf_text, pypdf_pages = await asyncio.to_thread(_extract_pypdf, path)
    except Exception as exc:  # noqa: BLE001 — OCR ещё может спасти документ
        pypdf_error = exc
        _event("pypdf", "result=error error_type=%s", type(exc).__name__, level=logging.WARNING)

    usable = is_usable_text(pypdf_text)
    _event("pypdf", "result=%s pages=%d chars=%d meaningful=%d elapsed_ms=%d",
           "usable" if usable else "needs_ocr", pypdf_pages, len(pypdf_text),
           meaningful_chars(pypdf_text), int((time.monotonic() - started) * 1000))
    if usable:
        return {
            "text": pypdf_text,
            "pages": pypdf_pages,
            "engine": "pypdf",
            "confidence": None,
        }

    provider = os.environ.get("OCR_PROVIDER", "").strip().lower() or "tesseract"
    provider = provider if provider in ocr_client.SUPPORTED_PROVIDERS else "invalid"
    _event("ocr", "result=start provider=%s tesseract_available=%s",
           provider, "yes" if ocr_client.tesseract_available() else "no")
    started = time.monotonic()
    ocr_error: Exception | None = None
    try:
        ocr_result = await ocr_client.extract(path, max_pages=max_pages)
    except Exception as exc:  # noqa: BLE001 — ошибка провайдера не должна терять pypdf-текст
        ocr_error = exc
        _event("ocr", "result=error error_type=%s", type(exc).__name__, level=logging.WARNING)
        ocr_result = ocr_client.OcrResult.failed()
    _event("ocr", "result=%s engine=%s pages=%d chars=%d elapsed_ms=%d",
           "text" if ocr_result.text else "empty", _engine_label(ocr_result.engine),
           ocr_result.pages, len(ocr_result.text), int((time.monotonic() - started) * 1000))

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
        raise _SafeFailure("PRX390_API_BASE / PRX120_API_BASE не задан в workers/.env")

    token, _src = site.site_token(_PREFIX)
    if not token:
        raise _SafeFailure("PRX390_SITE_TOKEN / WORKER_SITE_TOKEN не задан в workers/.env")

    url = f"{base}/file/{int(file_id)}"
    started = time.monotonic()
    try:
        async with httpx.AsyncClient(timeout=120.0, follow_redirects=True) as client:
            r = await client.get(url, headers={"X-Worker-Token": token})
    except Exception as exc:  # noqa: BLE001 — URL и текст исключения могут содержать секреты
        raise _SafeFailure(site.network_error(exc)) from None

    content_type = r.headers.get("content-type", "").split(";", 1)[0].strip().lower()
    content_kind = "pdf" if content_type == "application/pdf" else (
        "html" if content_type in ("text/html", "application/xhtml+xml") else "other")
    _event("file_response", "http=%d redirects=%d final_origin=%s content=%s bytes=%d elapsed_ms=%d",
           r.status_code, len(r.history), site.safe_origin(str(r.url)), content_kind,
           len(r.content), int((time.monotonic() - started) * 1000))
    if r.status_code != 200:
        raise _SafeFailure(site.private_http_error(r))
    dest.write_bytes(r.content)


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    job = getattr(ctx, "_current_job", None) or {}
    job_id = int(job.get("id") or 0)
    attempt = int(job.get("attempts") or 0)
    max_attempts = int(job.get("max_attempts") or 0)
    try:
        message_id = int(payload.get("message_id") or 0)
    except (AttributeError, TypeError, ValueError, OverflowError):
        message_id = 0
    if message_id <= 0:
        log.error("doc.normalize job_id=%d message_id=0 file_id=0 stage=failed "
                  "attempt=%d/%d reason=invalid_message_id", job_id, attempt, max_attempts)
        raise ValueError("payload.message_id должен быть положительным целым")

    trace = _TRACE.set((job_id, message_id, 0))
    try:
        _event("start", "attempt=%d/%d", attempt, max_attempts)
        return await _handle(ctx, message_id)
    except _SafeFailure as exc:
        _event("failed", "reason=%s", str(exc), level=logging.ERROR)
        raise RuntimeError(str(exc)) from None
    except Exception as exc:  # noqa: BLE001 — не выдаём документ или секрет из неизвестного исключения
        _event("failed", "error_type=%s", type(exc).__name__, level=logging.ERROR)
        raise RuntimeError(f"doc.normalize: ошибка обработки ({type(exc).__name__})") from None
    finally:
        _TRACE.reset(trace)


async def _handle(ctx: HandlerContext, message_id: int) -> dict:
    base, base_source = site.module_api_config(_PREFIX, _ROUTE_BASE)
    token, token_source = site.site_token(_PREFIX)
    path_kind, has_query, has_fragment, has_userinfo = site.safe_base_details(base, _ROUTE_BASE)
    _event("site_config", "origin=%s base_source=%s base_path=%s query=%s fragment=%s "
           "userinfo=%s token_source=%s proxy_env=%s no_proxy_env=%s",
           site.safe_origin(base), base_source, path_kind, has_query, has_fragment,
           has_userinfo, token_source if token else "не задан",
           _env_present("HTTP_PROXY", "http_proxy", "HTTPS_PROXY", "https_proxy", "ALL_PROXY", "all_proxy"),
           _env_present("NO_PROXY", "no_proxy"))

    _event("envelope_get", "result=start method=GET route=/text/<message_id>")
    started = time.monotonic()
    ok, reason, data = await site.get_json(
        _PREFIX, f"/text/{message_id}", base=base, safe_errors=True)
    elapsed_ms = int((time.monotonic() - started) * 1000)
    if not ok:
        _event("envelope_get", "result=error elapsed_ms=%d reason=%s", elapsed_ms, reason,
               level=logging.WARNING)
        raise _SafeFailure(f"конверт #{message_id} не получен: {reason}")

    files = data.get("files") or []
    if not isinstance(files, list) or not files:
        raise _SafeFailure(f"у конверта #{message_id} нет вложений")
    _event("envelope_get", "result=ok http=200 files=%d elapsed_ms=%d", len(files), elapsed_ms)
    await ctx.progress("Подготовка", f"файлов: {len(files)}")

    best_text = ""
    best_engine = ""
    best_pages = 0
    best_file = 0
    best_confidence: float | None = None
    errors: list[str] = []

    with tempfile.TemporaryDirectory(prefix="prx390_pdf_") as tmp:
        for item in files:
            try:
                file_id = int(item.get("file_id") or 0) if isinstance(item, dict) else 0
            except (TypeError, ValueError, OverflowError):
                file_id = 0
            if file_id <= 0:
                _event("file", "result=invalid_id", level=logging.WARNING)
                continue

            job_id, _, _ = _TRACE.get()
            file_trace = _TRACE.set((job_id, message_id, file_id))
            try:
                dest = Path(tmp) / f"{file_id}.pdf"
                _event("file_get", "result=start method=GET route=/file/<file_id>")
                try:
                    await ctx.progress("Скачивание", f"файл #{file_id}")
                    await _download(file_id, dest)
                except Exception as exc:  # noqa: BLE001
                    detail = str(exc) if isinstance(exc, _SafeFailure) else type(exc).__name__
                    _event("file_get", "result=error reason=%s", detail, level=logging.WARNING)
                    errors.append(f"файл #{file_id}: {detail}")
                    continue

                _event("extract", "result=start")
                text, pages, engine, confidence = "", 0, "", None
                try:
                    await ctx.progress("Текст", f"файл #{file_id}")
                    extracted = await extract_text(dest, max_pages=_OCR_MAX_PAGES)
                    text = str(extracted.get("text") or "")
                    pages = int(extracted.get("pages") or 0)
                    engine = str(extracted.get("engine") or "")
                    confidence = extracted.get("confidence")

                    if engine == "failed" or not text:
                        errors.append(f"файл #{file_id}: OCR-провайдер не дал текст")
                    else:
                        await ctx.progress("Текст", f"файл #{file_id} · {_engine_label(engine)}")
                    _event("extract", "result=%s engine=%s pages=%d chars=%d",
                           "ok" if text and engine != "failed" else "empty",
                           _engine_label(engine), pages, len(text))
                except Exception as exc:  # noqa: BLE001
                    _event("extract", "result=error error_type=%s", type(exc).__name__, level=logging.WARNING)
                    errors.append(f"файл #{file_id}: ошибка {type(exc).__name__}")

                if len(text) > len(best_text):
                    best_text, best_pages, best_engine, best_file = text, pages, engine, file_id
                    best_confidence = confidence
            finally:
                _TRACE.reset(file_trace)

    if not best_text:
        detail = "; ".join(errors[:3]) or "текстового слоя нет"
        raise _SafeFailure(f"не удалось извлечь текст из PDF: {detail}")

    await ctx.progress("Сохранение", f"{len(best_text)} символов")
    site_payload = ocr_client.ExtractedText(
        text=best_text,
        pages=best_pages,
        engine=best_engine or "pypdf",
        confidence=best_confidence,
    ).as_payload(best_file)
    _event("text_post", "result=start file_id_selected=%d chars=%d", best_file, len(best_text))
    started = time.monotonic()
    ok, reason, response = await site.post_json(
        _PREFIX,
        f"/text/{message_id}",
        site_payload,
        base=base,
        timeout=120.0,
        safe_errors=True,
    )
    elapsed_ms = int((time.monotonic() - started) * 1000)
    if not ok:
        _event("text_post", "result=error elapsed_ms=%d reason=%s", elapsed_ms, reason,
               level=logging.WARNING)
        raise _SafeFailure(f"сайт не принял текст: {reason}")

    status = response.get("status")
    status = status if status in ("text_ready", "needs_ocr", "registered") else "other"
    _event("text_post", "result=ok http=200 status=%s elapsed_ms=%d", status, elapsed_ms)
    await ctx.progress("Готово", f"{len(best_text)} символов · {_engine_label(best_engine)}")
    _event("complete", "file_id_selected=%d engine=%s pages=%d chars=%d files=%d",
           best_file, _engine_label(best_engine), best_pages, len(best_text), len(files))

    return {
        "message_id": message_id,
        "file_id": best_file,
        "chars": len(best_text),
        "pages": best_pages,
        "engine": best_engine,
        "confidence": best_confidence,
        "files": len(files),
    }
