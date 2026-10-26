#!/usr/bin/env python3
"""Проверяет классы миграций PostgreSQL (application/modules/*/migrations_pg).

Почему: живой случай 26.09 — миграция модуля prl643_cad была объявлена как
`class pgMigration_… extends Migration`. Класса `Migration` в проекте нет, а
`Migrator::inventory()` просто require-ит файл миграции, поэтому ломалась ВСЯ
страница /migrate_pg: `Error [ 0 ]: Class "Migration" not found`.

Что проверяем:
  1. класс объявлен и наследует `pgMigration` (модуль prl111_migrations);
  2. есть методы up() и down();
  3. DDL идемпотентен: строки CREATE TABLE/INDEX содержат IF NOT EXISTS
     (иначе повторный накат на проде падает);
  4. нет ручного разреза SQL по «;» через explode — «;» внутри строкового
     литерала (например, COMMENT ON … IS '…; …') роняет накат
     «незавершённая строка в кавычках» (живой случай prl661/001, 06.10.2026);
     многооператорный SQL выполняется через $this->run_script().

Запуск:  python3 .tools/php_migration_lint.py [пути…]   (по умолчанию application/)
Выход:   0 — чисто, 1 — есть проблемы.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

CLASS = re.compile(r"^\s*class\s+(?P<name>\w+)\s+extends\s+(?P<base>\w+)", re.M)
FUNC = re.compile(r"^\s*(?:public|protected|private)\s+function\s+(?P<name>\w+)\s*\(", re.M)
CREATE = re.compile(r"\bCREATE\s+(?:UNIQUE\s+)?(?:TABLE|INDEX)\b(?!\s+IF\s+NOT\s+EXISTS)", re.I)
# explode(';', …) / explode(";\n", …) — разрез SQL по «;» без учёта кавычек
EXPLODE_SEMI = re.compile(r"explode\s*\(\s*(['\"]).*?;.*?\1")


BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.S)
LINE_COMMENT = re.compile(r"(?m)^\s*(?://|#|\*).*$")


def check_file(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8", errors="replace")
    problems = []

    # DDL ищем в коде, а не в комментариях: иначе слово «CREATE TABLE» в
    # пояснении («CREATE TABLE доступен шире, чем ALTER…») даёт ложное срабатывание
    code = LINE_COMMENT.sub("", BLOCK_COMMENT.sub("", text))

    classes = CLASS.findall(text)

    if not classes:
        problems.append("нет объявления класса миграции")
    else:
        name, base = classes[0]

        if base != "pgMigration":
            problems.append(f"{name} наследует {base}, а нужно pgMigration "
                            "(иначе /migrate_pg падает: Class \"Migration\" not found)")

        if not name.startswith("pgMigration_"):
            problems.append(f"имя класса {name} — ожидается pgMigration_<Модуль>_<номер>_<Название>")

    methods = {m.group("name") for m in FUNC.finditer(text)}

    for need in ("up", "down"):
        if need not in methods:
            problems.append(f"нет метода {need}()")

    for m in CREATE.finditer(code):
        line = code[:m.start()].count("\n") + 1
        problems.append(f"строка {line}: CREATE без IF NOT EXISTS — "
                        "повторный накат упадёт на занятом имени")

    for m in EXPLODE_SEMI.finditer(code):
        line = code[:m.start()].count("\n") + 1
        problems.append(f"строка {line}: разрез SQL по «;» через explode — "
                        "«;» внутри литерала роняет накат; использовать "
                        "$this->run_script()")

    return problems


def main() -> int:
    args = sys.argv[1:]
    roots = [Path(a) for a in args] if args else [Path("application")]

    files: list[Path] = []

    for root in roots:
        base = root if root.is_absolute() else ROOT / root

        if base.is_file() and base.suffix == ".php":
            files.append(base)
        elif base.is_dir():
            files.extend(sorted(base.glob("modules/*/migrations_pg/*.php")))

    errors = 0

    for path in files:
        problems = check_file(path)
        rel = path.relative_to(ROOT) if path.is_absolute() else path

        if problems:
            errors += 1
            print(f"[!] {rel}")
            for p in problems:
                print(f"    — {p}")

    print(f"Проверено миграций: {len(files)}, с замечаниями: {errors}")

    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
