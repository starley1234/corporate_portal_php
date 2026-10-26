#!/usr/bin/env python3
"""Ловит ссылку на столбец, которого нет в подзапросе LATERAL («монитор 500»).

Зачем (живая авария 28.09.2026): `/nj120/monitor` отдавал 500 —

    Database_Exception [ 0 ]: ОШИБКА: столбец ev.created_at не существует

В `Service/Nj120/JobQueue.php` «чем занят воркер сейчас» собиралось так:

    SELECT … ev.message AS stage, ev.created_at AS stage_at
      FROM nj_120_jobs j
      LEFT JOIN LATERAL (
          SELECT e.message FROM nj_120_job_events e …   -- created_at НЕ выбран
      ) ev ON TRUE

Запрос синтаксически корректен, колонка в таблице есть, PHP-кода внутри строки
нет — ни разбор грамматики, ни `php_sql_lint`, ни `php_ternary_lint` этого не
видят. PostgreSQL отвергает запрос ЦЕЛИКОМ, поэтому падает вся страница, хотя
остальные её запросы исправны. Настоящая проверка — исполнить SQL на живой базе
(`.tools/nj120_monitor_pg_stand.py`); этот линтер ловит опечатку статически и
сразу во всём проекте.

Что проверяем: для каждого `LATERAL ( … ) alias ON …` собираем список столбцов,
которые подзапрос реально отдаёт (`AS name` или `t.name`), и ищем в файле
ссылки `alias.<col>` на столбцы вне этого списка. Псевдонимы, которые в том же
файле используются и как имя таблицы (`FROM users u`), пропускаем — там ссылка
может быть законной.

Запуск:
    python3 .tools/php_lateral_lint.py               # по умолчанию application/
    python3 .tools/php_lateral_lint.py .             # весь репозиторий
    python3 .tools/php_lateral_lint.py путь/файл.php # конкретный файл
    python3 .tools/php_lateral_lint.py --selftest    # проверка самого линтера

Выход: 0 — чисто, 1 — найдены подозрительные ссылки.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SKIP_DIRS = {".git", "vendor", "node_modules", "system", "userguide", "__pycache__"}


def find_laterals(text):
    """Все LATERAL-подзапросы файла: (позиция, sql подзапроса, псевдоним)."""
    out = []
    for m in re.finditer(r"\bLATERAL\s*\(", text):
        start = m.end()          # сразу после открывающей скобки
        depth, i = 1, start

        while i < len(text) and depth > 0:
            if text[i] == "(":
                depth += 1
            elif text[i] == ")":
                depth -= 1
            i += 1

        if depth != 0:
            continue

        body = text[start:i - 1]
        tail = text[i:i + 40]
        am = re.match(r"\s*(?:AS\s+)?([A-Za-z_]\w*)\s+ON\b", tail)

        if not am:
            continue

        out.append((m.start(), body, am.group(1)))

    return out


def select_names(body):
    """Имена столбцов, которые подзапрос отдаёт наружу. None — если SELECT *."""
    depth, i, last_select = 0, 0, -1

    while i < len(body):
        ch = body[i]

        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        elif depth == 0:
            if re.match(r"(?i)\bSELECT\b", body[i:]):
                last_select = i
                i += 6
                continue
            if re.match(r"(?i)\bFROM\b", body[i:]) and last_select >= 0:
                list_sql = body[last_select + 6:i]
                break
        i += 1
    else:
        return None

    if re.search(r"(?i)^\s*\*", list_sql) or re.search(r"(?i),\s*\*", list_sql):
        return None

    names, depth, part = set(), 0, ""

    for ch in list_sql + ",":
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1

        if ch == "," and depth == 0:
            piece = " ".join(part.split())
            part = ""

            if not piece:
                continue

            am = re.search(r"(?i)\bAS\s+([A-Za-z_]\w*)\s*$", piece)

            if am:
                names.add(am.group(1).lower())
                continue

            tail_col = re.search(r"([A-Za-z_]\w*)\s*$", piece)

            if tail_col:
                names.add(tail_col.group(1).lower())
        else:
            part += ch

    return names


def table_alias_of(text, alias):
    """True — псевдоним используется в файле и как имя таблицы (значит, ссылки законны)."""
    return re.search(r"(?i)\b(?:FROM|JOIN)\s+[A-Za-z_][\w.]*\s+(?:AS\s+)?" + re.escape(alias) + r"\b", text) is not None


def check_file(path):
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return []

    laterals = find_laterals(text)

    if not laterals:
        return []

    # alias → {столбцы}; None в множестве = «есть SELECT *» → не проверяем
    provided = {}

    for _, body, alias in laterals:
        names = select_names(body)
        cur = provided.setdefault(alias, set())

        if names is None:
            provided[alias] = None
        elif provided[alias] is not None:
            cur |= names

    problems = []

    for alias, names in provided.items():
        if names is None or table_alias_of(text, alias):
            continue

        for m in re.finditer(r"\b" + re.escape(alias) + r"\.([A-Za-z_]\w*)", text):
            col = m.group(1).lower()

            if col not in names:
                line = text.count("\n", 0, m.start()) + 1
                problems.append((line, alias, m.group(1), sorted(names)))

    return problems


def iter_files(target):
    p = Path(target)

    if not p.is_absolute():
        p = ROOT / target

    if p.is_file():
        yield p
        return

    for f in sorted(p.rglob("*.php")):
        if any(part in SKIP_DIRS for part in f.parts):
            continue
        yield f


SELFTEST_BAD = """<?php
$rows = $db->query(Database::SELECT,
    'SELECT j.id, ev.message AS stage, ev.created_at AS stage_at
       FROM nj_120_jobs j
       LEFT JOIN LATERAL (
           SELECT e.message FROM nj_120_job_events e WHERE e.job_id = j.id
       ) ev ON TRUE', FALSE)->as_array();
"""

SELFTEST_GOOD = """<?php
$rows = $db->query(Database::SELECT,
    'SELECT j.id, ev.message AS stage, ev.created_at AS stage_at
       FROM nj_120_jobs j
       LEFT JOIN LATERAL (
           SELECT e.message, e.created_at FROM nj_120_job_events e WHERE e.job_id = j.id
       ) ev ON TRUE', FALSE)->as_array();

$rows2 = $db->query(Database::SELECT,
    'SELECT * FROM nj_120_jobs j LEFT JOIN LATERAL (
         SELECT e.message FROM nj_120_job_events e
     ) ev ON TRUE', FALSE)->as_array();
"""


def selftest():
    bad = Path("/tmp/_lateral_lint_bad.php")
    good = Path("/tmp/_lateral_lint_good.php")
    bad.write_text(SELFTEST_BAD, encoding="utf-8")
    good.write_text(SELFTEST_GOOD, encoding="utf-8")

    found_bad = check_file(bad)
    found_good = check_file(good)

    ok = len(found_bad) == 1 and found_bad[0][2] == "created_at" and not found_good

    print(("ОК   " if ok else "ПЛОХО") + " самопроверка: сломанный LATERAL найден (1), исправленный и SELECT * — нет")

    if not ok:
        print("     сломанный: %r" % (found_bad,))
        print("     исправленный: %r" % (found_good,))

    return 0 if ok else 1


def main():
    args = [a for a in sys.argv[1:]]

    if args and args[0] == "--selftest":
        sys.exit(selftest())

    target = args[0] if args else "application"
    total = 0

    for path in iter_files(target):
        for line, alias, col, names in check_file(path):
            total += 1
            rel = path.relative_to(ROOT) if path.is_absolute() and ROOT in path.parents else path
            print("%s:%d — %s.%s нет в подзапросе LATERAL (подзапрос отдаёт: %s)"
                  % (rel, line, alias, col, ", ".join(names)))

    if total == 0:
        print("LATERAL: ссылок на несуществующие столбцы нет (%s)" % target)
    else:
        print("LATERAL: замечаний — %d" % total)

    sys.exit(1 if total else 0)


if __name__ == "__main__":
    main()
