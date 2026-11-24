"""Изолированный процесс pypdf: лимит памяти, JSON без имени/данных в stderr.

Запуск: python pdf_probe.py <path> <local|remote> <limits_json>
limits_json: {"max_pages":..,"max_text_bytes":..,"pdf_memory_mb":..}
"""
from __future__ import annotations

import json
import logging
import sys
from pathlib import Path

MIB = 1024 ** 2


def inspect(path: Path, with_text: bool, settings: dict) -> dict:
    try:
        from pypdf import PdfReader
    except ImportError:
        return {"error": "ocr_config"}

    try:
        reader = PdfReader(path, strict=False)
        if reader.is_encrypted:
            return {"error": "encrypted_pdf"}
        pages = reader.pages
        # Быстрый отказ до разворачивания дерева, затем проверка фактического числа.
        if int(reader.root_object["/Pages"].get("/Count", 0)) > settings["max_pages"]:
            return {"error": "page_limit"}
        total = len(pages)
        if not 1 <= total <= settings["max_pages"]:
            return {"error": "page_limit" if total else "invalid_pdf"}
        result: dict = {"pages": total}
        if with_text:
            values = []
            used = 0
            for page in pages:
                try:
                    text = (page.extract_text() or "").strip()
                    # Сохраняем даже короткие надписи, если это нормальный текст.
                    if not any(c.isalnum() for c in text) or text.count("\ufffd") * 10 > len(text):
                        text = ""
                except MemoryError:
                    raise
                except Exception:
                    # Поломанный текстовый слой страницы всё ещё может отрисоваться.
                    text = ""
                used += len(text.encode("utf-8", errors="replace"))
                if used + 2 * total > settings["max_text_bytes"]:
                    return {"error": "result_too_large"}
                values.append(text)
            result["text_layers"] = values
        return result
    except Exception:
        return {"error": "invalid_pdf"}


def _limits(argv: list[str]) -> dict:
    if len(argv) > 3:
        data = json.loads(argv[3])
        return {
            "max_pages": int(data["max_pages"]),
            "max_text_bytes": int(data["max_text_bytes"]),
            "pdf_memory_mb": int(data["pdf_memory_mb"]),
        }
    # Backward compat: prx395 limits from env.
    try:
        from prx_workers.handlers.prx395_document_extract.limits import limits
    except Exception:  # noqa: BLE001
        from ..handlers.prx395_document_extract.limits import limits  # type: ignore
    return limits()


def main() -> None:
    logging.disable(logging.CRITICAL)
    settings = _limits(sys.argv)
    if sys.platform.startswith("linux"):
        import resource
        cap = settings["pdf_memory_mb"] * MIB
        _, hard = resource.getrlimit(resource.RLIMIT_AS)
        cap = cap if hard == resource.RLIM_INFINITY else min(cap, hard)
        resource.setrlimit(resource.RLIMIT_AS, (cap, hard))
    print(json.dumps(inspect(Path(sys.argv[1]), sys.argv[2] == "local", settings), ensure_ascii=True))


if __name__ == "__main__":
    main()
