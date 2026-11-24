"""Shared prx395 env contract. Keep defaults/ranges in sync with PHP and .env.example."""
from __future__ import annotations

import os
import re

MIB = 1024 ** 2


def _integer(raw: str | None, default: int, minimum: int, maximum: int) -> int:
    value = (raw or "").strip(" \t\r\n\v")
    if not re.fullmatch(r"[1-9][0-9]{0,8}", value):
        return default
    parsed = int(value)
    return parsed if minimum <= parsed <= maximum else default


def limits() -> dict[str, int]:
    return {
        "max_pdf_bytes": _integer(os.getenv("PRX395_MAX_PDF_BYTES"), 10 * MIB, MIB, 100 * MIB),
        "max_pages": _integer(os.getenv("PRX395_MAX_PAGES"), 20, 1, 200),
        "max_text_bytes": _integer(os.getenv("PRX395_MAX_TEXT_BYTES"), MIB, 1024, 8 * MIB),
        "result_ttl_hours": _integer(os.getenv("PRX395_RESULT_TTL_HOURS"), 24, 1, 168),
        "task_timeout_sec": _integer(os.getenv("PRX395_TASK_TIMEOUT_SEC"), 300, 1, 3600),
        "max_attempts": _integer(os.getenv("PRX395_MAX_ATTEMPTS"), 3, 1, 10),
        "pdf_memory_mb": _integer(os.getenv("PRX395_PDF_MEMORY_MB"), 384, 64, 4096),
    }
