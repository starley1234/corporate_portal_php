#!/usr/bin/env python3
"""Ищет `static`-методы PHP, которые используют `$this`.

Почему: у такого метода нет объекта — PHP падает фаталом
«Using $this when not in object context» прямо на проде
(живой случай 26.09: /nj602/admin — `protected static function _filters()`
с `$this->request->query(...)`).

Запуск:  python3 .tools/php_static_this.py [пути…]   (по умолчанию application/)
Выход:   0 — чисто, 1 — есть статические методы с $this.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

SIGNATURE = re.compile(
    r"^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+(?P<name>\w+)\s*\(")
MODIFIERS = ("public", "protected", "private", "static", "final", "abstract")


def methods(lines: list[str]):
    """Пары (индекс строки, имя, тело) для каждого объявления функции в файле."""
    i = 0

    while i < len(lines):
        line = lines[i]
        m = SIGNATURE.search(line)
        head = line[:m.start("name")] if m else ""

        if m and "static" in head and line.strip().startswith(MODIFIERS):
            depth, started, j = 0, False, i

            while j < len(lines):
                depth += lines[j].count("{") - lines[j].count("}")
                started = started or "{" in lines[j]

                if started and depth <= 0:
                    break

                j += 1

            yield i, m.group("name"), lines[i:j + 1]
            i = j

        i += 1


def main(argv: list[str]) -> int:
    targets = argv[1:] or ["application"]
    bad = 0

    for target in targets:
        path = Path(target) if Path(target).is_absolute() else ROOT / target

        if not path.exists():
            print(f"нет пути: {target}")
            continue

        files = sorted(path.rglob("*.php")) if path.is_dir() else [path]

        for f in files:
            lines = f.read_text(encoding="utf-8", errors="replace").splitlines()

            for line_no, name, body in methods(lines):
                if re.search(r"\$this\s*->", "\n".join(body)):
                    bad += 1
                    shown = f.relative_to(ROOT) if f.is_relative_to(ROOT) else f
                    print(f"{shown}:{line_no + 1}: static function {name}() использует $this")

    print(f"Найдено: {bad}")

    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
