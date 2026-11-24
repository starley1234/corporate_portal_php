"""Проверка PDF через изолированный подпроцесс pypdf."""

from __future__ import annotations

import json
from pathlib import Path

from ..base import PermanentJobError
from .proc import run

PROBE_SCRIPT = Path(__file__).with_name("pdf_probe.py")


async def probe_pdf(path: Path, local: bool, *, limits: dict) -> dict:
    payload = json.dumps({
        "max_pages": int(limits["max_pages"]),
        "max_text_bytes": int(limits["max_text_bytes"]),
        "pdf_memory_mb": int(limits["pdf_memory_mb"]),
    }, separators=(",", ":"))
    try:
        code, output = await run(
            __import__("sys").executable, str(PROBE_SCRIPT), str(path),
            "local" if local else "remote", payload, timeout=25,
        )
        if code:
            raise PermanentJobError("invalid_pdf")
        data = json.loads(output)
    except (ValueError, TimeoutError):
        raise PermanentJobError("invalid_pdf") from None
    if "error" in data:
        raise PermanentJobError(data["error"])
    return data
