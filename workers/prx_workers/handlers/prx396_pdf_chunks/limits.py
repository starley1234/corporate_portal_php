"""Shared prx396 env contract. Keep defaults/ranges in sync with PHP and .env.example."""
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
        "max_pdf_bytes": _integer(os.getenv("PRX396_MAX_PDF_BYTES"), 100 * MIB, MIB, 500 * MIB),
        "max_pages": _integer(os.getenv("PRX396_MAX_PAGES"), 500, 1, 2000),
        "chunk_pages": _integer(os.getenv("PRX396_CHUNK_PAGES"), 10, 1, 100),
        "max_text_bytes": _integer(os.getenv("PRX396_MAX_TEXT_BYTES"), 50 * MIB, 1024, 200 * MIB),
        "result_ttl_hours": _integer(os.getenv("PRX396_TTL_HOURS") or os.getenv("PRX396_RESULT_TTL_HOURS"), 72, 1, 720),
        "task_timeout_sec": _integer(os.getenv("PRX396_TASK_TIMEOUT_SEC"), 1800, 1, 7200),
        "max_attempts": _integer(os.getenv("PRX396_MAX_ATTEMPTS"), 3, 1, 10),
        "pdf_memory_mb": _integer(os.getenv("PRX396_PDF_MEMORY_MB"), 512, 64, 4096),
        "parallel_parts": _integer(os.getenv("PRX396_PARALLEL_PARTS"), 1, 1, 8),
    }
