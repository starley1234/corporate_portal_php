"""Лимиты prx398 из env."""
from __future__ import annotations

import os

MIB = 1024 ** 2


def _int(name: str, default: int, lo: int, hi: int) -> int:
    raw = os.getenv(name, "").strip()
    if not raw.isdigit() or (raw.startswith("0") and raw != "0"):
        return default
    value = int(raw)
    return value if lo <= value <= hi else default


def _float(name: str, default: float, lo: float, hi: float) -> float:
    raw = os.getenv(name, "").strip()
    if raw == "":
        return default
    try:
        value = float(raw)
    except ValueError:
        return default
    return value if lo <= value <= hi else default


def limits() -> dict:
    mode = os.getenv("PRX398_CHECKPOINT", "postgres").strip().lower() or "postgres"
    if mode not in ("postgres", "memory"):
        mode = "postgres"
    return {
        "max_pdf_bytes": _int("PRX398_MAX_PDF_BYTES", 10 * MIB, MIB, 100 * MIB),
        "max_pages": _int("PRX398_MAX_PAGES", 20, 1, 200),
        "confidence_min": _float("PRX398_CONFIDENCE_MIN", 0.8, 0.0, 1.0),
        "max_retries": _int("PRX398_MAX_RETRIES", 2, 0, 10),
        "checkpoint": mode,
    }
