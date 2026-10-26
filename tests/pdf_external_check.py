#!/usr/bin/env python3
"""Независимая валидация PDF-выхода prl634 через pypdf.

Свой PHP-парсер не должен быть единственным судьёй своих же файлов: здесь
файлы гоняет сторонняя библиотека pypdf. Генерацию делает PHP-хелпер
(tests/pdf_generate.php) через ни же сервисы; проверка — pypdf.

Запуск: bin/test --pdf  (пропускается, если python3/pypdf нет)
"""
import json
import subprocess
import sys
import tempfile
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def php_bin():
    for c in ("php8.4", "php8.3", "php8.4-cli", "php"):
        p = subprocess.run(
            ["which", c], capture_output=True, text=True)
        if p.returncode == 0 and p.stdout.strip():
            return p.stdout.strip()
    return None


def sh(*args, **kw):
    return subprocess.run(args, capture_output=True, text=True, **kw)


def main():
    php = php_bin()
    if not php:
        print("SKIP: php не найден")
        return 0

    from pypdf import PdfReader

    with tempfile.TemporaryDirectory() as tmp:
        out = sh(php, os.path.join(ROOT, "tests", "pdf_generate.php"), tmp)
        if out.returncode != 0:
            print("FAIL: генерация PHP упала\n" + out.stderr[-2000:])
            return 1

        report = json.loads(out.stdout.strip())

        problems = []

        for name in ("multi", "split", "merge"):
            path = os.path.join(tmp, name + ".pdf")
            if not os.path.exists(path):
                problems.append(f"{name}: файл не создан")
                continue
            try:
                reader = PdfReader(path)
                pages = len(reader.pages)
                text = (reader.pages[0].extract_text() or "").strip()
                problems.append(f"{name}: страниц={pages}")
                # сплит 2-3 → 2 страницы; merge 3,1,2 → 3 страницы
                if name == "split" and pages != 2:
                    problems.append(f"{name}: ожидалось 2 страницы, получено {pages}")
                if name == "merge" and pages != 3:
                    problems.append(f"{name}: ожидалось 3 страницы, получено {pages}")
                if name == "multi" and pages != report.get("multi_pages"):
                    problems.append(f"{name}: рассинхрон числа страниц с PHP-отчётом")
                if not text:
                    problems.append(f"{name}: pypdf не извлёк текст с первой страницы")
            except Exception as e:  # noqa: BLE001
                problems.append(f"{name}: pypdf упал: {type(e).__name__}: {e}")

        real = [p for p in problems if "страниц=" not in p]
        if real:
            print("FAIL pypdf:")
            print("\n".join(problems))
            return 1

        print("PDF external check OK:")
        for p in problems:
            print("  " + p)
        return 0


if __name__ == "__main__":
    sys.exit(main())
