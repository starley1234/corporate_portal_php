"""Лимиты prx400 из env (источник истины — .env.example)."""
from __future__ import annotations

import os

MIB = 1024 ** 2


def _int(name: str, default: int, lo: int, hi: int) -> int:
    raw = os.getenv(name, "").strip()
    if not raw.isdigit() or raw.startswith("0") and raw != "0":
        return default
    value = int(raw)
    return value if lo <= value <= hi else default


def limits() -> dict[str, int]:
    return {
        "max_pdf_bytes": _int("PRX400_MAX_PDF_BYTES", 20 * MIB, MIB, 100 * MIB),
        "max_pages": _int("PRX400_MAX_PAGES", 40, 1, 500),
        "max_text_bytes": _int("PRX400_MAX_TEXT_BYTES", 4 * MIB, 65536, 64 * MIB),
        "pdf_memory_mb": _int("PRX400_PDF_MEMORY_MB", 512, 64, 4096),
        "max_rows": _int("PRX400_MAX_ROWS", 2000, 10, 100000),
        "max_vision_pages": _int("PRX400_MAX_VISION_PAGES", 8, 1, 50),
    }
