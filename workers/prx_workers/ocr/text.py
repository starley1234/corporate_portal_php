"""Проверка и нормализация извлечённого текста."""

from __future__ import annotations

from ..base import PermanentJobError


def bounded(text: str, max_text_bytes: int) -> str:
    text = text.replace("\x00", "").strip()
    if not text:
        raise PermanentJobError("no_text")
    try:
        size = len(text.encode("utf-8"))
    except UnicodeError:
        raise PermanentJobError("ocr_invalid_response") from None
    if size > max_text_bytes:
        raise PermanentJobError("result_too_large")
    return text
