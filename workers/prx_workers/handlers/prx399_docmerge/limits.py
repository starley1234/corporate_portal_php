"""Shared prx399 env contract. Keep defaults/ranges in sync with PHP and .env.example."""
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
        "max_pdf_bytes": _integer(os.getenv("PRX399_MAX_PDF_BYTES"), 25 * MIB, MIB, 200 * MIB),
        "max_pages": _integer(os.getenv("PRX399_MAX_PAGES"), 300, 1, 1000),
        "max_text_bytes": _integer(os.getenv("PRX399_MAX_TEXT_BYTES"), 512 * 1024, 4096, 16 * MIB),
        "max_chunk_chars": _integer(os.getenv("PRX399_MAX_CHUNK_CHARS"), 12000, 2000, 100000),
        "task_timeout_sec": _integer(os.getenv("PRX399_TASK_TIMEOUT_SEC"), 3600, 60, 14400),
        # probe_pdf требует pdf_memory_mb (RLIMIT_AS подпроцесса pypdf);
        # пропуск ключа = PermanentJobError("worker_config") на каждой задаче.
        "pdf_memory_mb": _integer(os.getenv("PRX399_PDF_MEMORY_MB"), 512, 64, 4096),
    }
