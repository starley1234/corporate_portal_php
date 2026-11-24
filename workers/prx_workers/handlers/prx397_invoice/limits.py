"""Лимиты prx397 из env (источник истины — .env.example)."""
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
        "max_pdf_bytes": _int("PRX397_MAX_PDF_BYTES", 10 * MIB, MIB, 100 * MIB),
        "max_pages": _int("PRX397_MAX_PAGES", 20, 1, 200),
        "max_llm_chars": _int("PRX397_MAX_LLM_CHARS", 60000, 1000, 500000),
    }
