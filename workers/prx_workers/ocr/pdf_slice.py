"""Изолированный процесс pypdf: нарезка диапазона страниц с лимитом памяти.

Запуск: python pdf_slice.py <src> <dst> <page_from> <page_to> <limits_json>
limits_json: {"pdf_memory_mb":..}
Ответ: {"pages": N} или {"error": "..."} в stdout; MemoryError не
проглатывается — процесс завершается с ненулевым кодом, чтобы вызывающий
код счёл это ресурсной ошибкой и повторил попытку, а не permanent
invalid_pdf.
"""
from __future__ import annotations

import json
import logging
import sys
from pathlib import Path

MIB = 1024 ** 2


def slice_pdf(src: Path, dst: Path, page_from: int, page_to: int, settings: dict) -> dict:
    try:
        from pypdf import PdfReader, PdfWriter
    except ImportError:
        return {"error": "ocr_config"}

    try:
        reader = PdfReader(str(src), strict=False)
        writer = PdfWriter()
        total = len(reader.pages)
        start = max(1, page_from)
        end = min(total, page_to)
        if start > end:
            return {"error": "invalid_pdf"}
        for i in range(start - 1, end):
            writer.add_page(reader.pages[i])
        with dst.open("wb") as fh:
            writer.write(fh)
        return {"pages": end - start + 1}
    except MemoryError:
        raise  # ненулевой код процесса → ресурсная ошибка у вызывающего
    except Exception:
        return {"error": "invalid_pdf"}


def main() -> None:
    logging.disable(logging.CRITICAL)
    src, dst = Path(sys.argv[1]), Path(sys.argv[2])
    page_from, page_to = int(sys.argv[3]), int(sys.argv[4])
    settings = json.loads(sys.argv[5]) if len(sys.argv) > 5 else {}
    if sys.platform.startswith("linux"):
        import resource
        cap = int(settings.get("pdf_memory_mb", 512)) * MIB
        _, hard = resource.getrlimit(resource.RLIMIT_AS)
        cap = cap if hard == resource.RLIM_INFINITY else min(cap, hard)
        resource.setrlimit(resource.RLIMIT_AS, (cap, hard))
    print(json.dumps(slice_pdf(src, dst, page_from, page_to, settings), ensure_ascii=True))


if __name__ == "__main__":
    main()
