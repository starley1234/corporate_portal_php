"""Канал `doc.normalize`: PDF → текст (pypdf, при необходимости OCR).

Модуль-владелец — prl390_intake. Воркер НЕ пишет в базу: он скачивает файл
через `/prl390/api/file/<file_id>`, извлекает текст и отправляет его на
`POST /prl390/api/text/<message_id>`. Сайт сам решает, хватает ли текста
(`text_ready` / `needs_ocr`) и публикует событие для prl391.

Ожидаемый payload:
    {"message_id": 12}

Результат: {"message_id": 12, "chars": 4312, "engine": "pypdf", "pages": 3}
"""

from __future__ import annotations

import asyncio
import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

import httpx

from .. import site
from ..base import HandlerContext

CHANNEL = "doc.normalize"
_PREFIX = "PRL390"
_ROUTE_BASE = "/prl390/api"
_MIN_CHARS = 40


def _api_base() -> str:
    return site.module_api_base(_PREFIX, _ROUTE_BASE)


def _clean(text: str) -> str:
    text = (text or "").replace("\xad", "").replace("\ufeff", "")
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


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
    return bool(shutil.which("pdftoppm") and shutil.which("tesseract"))


def _extract_ocr(path: Path, max_pages: int = 5) -> tuple[str, int]:
    """OCR через pdftoppm + tesseract. Если бинарей нет — честно возвращаем пусто."""
    if not _ocr_available():
        return "", 0

    with tempfile.TemporaryDirectory(prefix="prl390_ocr_") as tmp:
        prefix = str(Path(tmp) / "page")
        try:
            subprocess.run(
                ["pdftoppm", "-r", "200", "-png", "-f", "1", "-l", str(max_pages), str(path), prefix],
                check=True, capture_output=True, timeout=180,
            )
        except Exception:  # noqa: BLE001
            return "", 0

        pages = sorted(Path(tmp).glob("page-*.png"))
        texts: list[str] = []
        for png in pages:
            try:
                out = subprocess.run(
                    ["tesseract", str(png), "stdout", "-l", "rus+eng", "--psm", "3"],
                    check=True, capture_output=True, timeout=120,
                )
                texts.append(out.stdout.decode("utf-8", errors="replace"))
            except Exception:  # noqa: BLE001
                continue

        return _clean("\n\n".join(texts)), len(pages)


async def _download(file_id: int, dest: Path) -> None:
    base = _api_base()
    if not base:
        raise RuntimeError("PRL390_API_BASE / PRL120_API_BASE не задан в workers/.env")

    token, _src = site.site_token(_PREFIX)
    if not token:
        raise RuntimeError("PRL390_SITE_TOKEN / WORKER_SITE_TOKEN не задан в workers/.env")

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
    errors: list[str] = []

    with tempfile.TemporaryDirectory(prefix="prl390_pdf_") as tmp:
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

            text, pages, engine = "", 0, ""
            try:
                await ctx.progress("Текст", name)
                text, pages = await asyncio.to_thread(_extract_pypdf, dest)
                engine = "pypdf"
            except Exception as exc:  # noqa: BLE001
                errors.append(f"{name}: {exc}")

            if len(text) < _MIN_CHARS and _ocr_available():
                await ctx.progress("OCR", f"{name} · tesseract")
                try:
                    ocr_text, ocr_pages = await asyncio.to_thread(_extract_ocr, dest)
                    if len(ocr_text) > len(text):
                        text, pages, engine = ocr_text, ocr_pages or pages, "tesseract"
                except Exception as exc:  # noqa: BLE001
                    errors.append(f"{name}: OCR: {exc}")

            if len(text) > len(best_text):
                best_text, best_pages, best_engine, best_file = text, pages, engine, file_id

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
            "confidence": None,
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
        "files": len(files),
    }
