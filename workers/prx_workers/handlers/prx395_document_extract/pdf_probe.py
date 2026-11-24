"""Изолированный процесс pypdf: лимит памяти, JSON без имени/данных в stderr."""
from __future__ import annotations

import json
import logging
import sys
from pathlib import Path

if __package__:
    from .limits import MIB, limits
else:  # Запуск файла напрямую отдельным процессом, без импорта обработчиков.
    from limits import MIB, limits


def inspect(path: Path, with_text: bool) -> dict:
    try:
        from pypdf import PdfReader
    except ImportError:
        return {"error": "ocr_config"}

    settings = limits()
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


def main() -> None:
    logging.disable(logging.CRITICAL)
    if sys.platform.startswith("linux"):
        import resource
        cap = limits()["pdf_memory_mb"] * MIB
        _, hard = resource.getrlimit(resource.RLIMIT_AS)
        cap = cap if hard == resource.RLIM_INFINITY else min(cap, hard)
        resource.setrlimit(resource.RLIMIT_AS, (cap, hard))
    print(json.dumps(inspect(Path(sys.argv[1]), sys.argv[2] == "local"), ensure_ascii=True))


if __name__ == "__main__":
    main()
