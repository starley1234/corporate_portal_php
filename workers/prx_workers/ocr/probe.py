"""Проверка и нарезка PDF через изолированные подпроцессы pypdf.

Почему отдельно от обработчика: pypdf на большом PDF съедает память и
время — подпроцесс получает RLIMIT_AS (pdf_memory_mb) и таймаут
(probe_timeout_sec), а упавший процесс не роняет воркер.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

from ..base import PermanentJobError
from .errors import DocumentJobError
from .proc import run

PROBE_SCRIPT = Path(__file__).with_name("pdf_probe.py")
SLICE_SCRIPT = Path(__file__).with_name("pdf_slice.py")


def _timeout(limits: dict) -> float:
    # prx395 ключа не задаёт — прежние 25 с; prx396 читает PRX396_PROBE_TIMEOUT_SEC.
    return float(limits.get("probe_timeout_sec") or 25)


async def probe_pdf(path: Path, local: bool, *, limits: dict,
                    text: bool | None = None, page_from: int | None = None,
                    page_to: int | None = None) -> dict:
    """Проба PDF. text=False — только структура и число страниц (без
    текстовых слоев); text=True — плюс слои страниц, при заданном диапазоне
    [page_from..page_to] — только их (часть prx396, а не весь документ)."""
    payload: dict = {
        "max_pages": int(limits["max_pages"]),
        "max_text_bytes": int(limits["max_text_bytes"]),
        "pdf_memory_mb": int(limits["pdf_memory_mb"]),
        "text": (local if text is None else bool(text)),
    }
    if page_from is not None:
        payload["page_from"] = int(page_from)
    if page_to is not None:
        payload["page_to"] = int(page_to)
    try:
        code, output = await run(
            sys.executable, str(PROBE_SCRIPT), str(path),
            "local" if local else "remote", json.dumps(payload, separators=(",", ":")),
            timeout=_timeout(limits),
        )
        if code:
            raise PermanentJobError("invalid_pdf")
        data = json.loads(output)
    except (ValueError, TimeoutError):
        raise PermanentJobError("invalid_pdf") from None
    if "error" in data:
        raise PermanentJobError(data["error"])
    return data


async def slice_pdf(src: Path, dst: Path, page_from: int, page_to: int, *, limits: dict) -> int:
    """Нарезать страницы [page_from..page_to] в dst под лимитом памяти.

    Ресурсная ошибка подпроцесса (OOM-убийство, таймаут) — повторная попытка;
    pypdf не смог прочитать файл — permanent invalid_pdf.
    """
    payload = json.dumps({"pdf_memory_mb": int(limits["pdf_memory_mb"])}, separators=(",", ":"))
    try:
        code, output = await run(
            sys.executable, str(SLICE_SCRIPT), str(src), str(dst),
            str(int(page_from)), str(int(page_to)), payload, timeout=_timeout(limits),
        )
    except TimeoutError:
        raise DocumentJobError("worker_endpoint_unavailable") from None
    if code:
        raise DocumentJobError("worker_endpoint_unavailable")
    try:
        data = json.loads(output)
    except ValueError:
        raise DocumentJobError("worker_endpoint_unavailable") from None
    if "error" in data:
        raise PermanentJobError(data["error"])
    return int(data.get("pages") or 0)
