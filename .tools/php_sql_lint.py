#!/usr/bin/env python3
"""Ловит PHP-код, случайно уехавший ВНУТРЬ текста SQL-запроса.

Зачем (живая авария 28.09.2026): в `Service/Nj120/JobQueue.php` правило
авто-остановки молчащего воркера собиралось так:

    "UPDATE nj_120_jobs SET
        error = 'Воркер не отвечал больше '.max(1, (int) round($floor / 60)).' мин …'"

Внутри ДВОЙНОЙ кавычки `'` и `.` — обычные символы, а не конкатенация: в
PostgreSQL уходил текст `… больше '.max(1, (int) round(240 / 60)).' мин …`,
запрос падал с синтаксической ошибкой, а `catch (Throwable $e)` глушил её в лог.
Следствие: авто-остановка зависших задач не работала вообще (и «Прервать
зависшие» в мониторе — тоже), поэтому владелец видел вечное «Выполняется» и
«монитор ВСЕГДА показывает зависший процесс».

Такой текст — валидный PHP (проверки разбора, баланса скобок и вызовов методов
его не видят), но НЕвалидный SQL: ошибка вылезает только на живом запросе.
Поэтому здесь ищем внутри строковых литералов характерные признаки PHP-кода.

Запуск:
    python3 .tools/php_sql_lint.py               # по умолчанию application/
    python3 .tools/php_sql_lint.py .             # весь репозиторий
    python3 .tools/php_sql_lint.py путь/файл.php # конкретный файл

Выход: 0 — чисто, 1 — найдены подозрительные строки.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

try:
    import tree_sitter
    import tree_sitter_php
except ImportError:  # pragma: no cover - подсказка вместо стектрейса
    print("Нет tree-sitter: pip3 install --break-system-packages tree_sitter tree_sitter_php")
    sys.exit(2)

PARSER = tree_sitter.Parser(tree_sitter.Language(tree_sitter_php.language_php()))

# Минифицированный Adminer: разбор спотыкается (см. php_parse_lint).
SKIP = {"public_html/adminer/index.php"}

# Осматриваем ТЕКСТ внутри кавычек и heredoc: SQL собирается склейкой из
# нескольких кусков, поэтому в одном узле может не быть слова UPDATE, а следы
# PHP-склейки — быть.
TEXT_NODES = {"string_content", "heredoc_body", "nowdoc_body"}

# PHP-функции, которые в тексте запроса означают именно склейку (`'.max(1, …).'`).
PHP_FUNCS = (
    "max|min|round|floor|ceil|abs|intval|floatval|strval|number_format|sprintf|"
    "str_pad|str_repeat|implode|trim|mb_substr|strlen|count|date|time|json_encode"
)

# Что считаем признаком уехавшего PHP-кода.
#
# Чего здесь НЕТ намеренно:
#   * одиночные `$переменные`, `{$выражение}` и `->` — это нормальная
#     интерполяция в двойных кавычках и jsonb-операторы `->`/`->>`;
#   * «точка после скобки» — в русских текстах это обычное «(…)…»,
#     ложных срабатываний было больше, чем пользы.
# Экранированные точки (`\\.(` в регулярках) отсекаются lookbehind-ом.
MARKERS = [
    (re.compile(r"(?<!\\)\.\s*(?:" + PHP_FUNCS + r")\s*\(", re.IGNORECASE),
     "вызов PHP-функции с точкой-склейкой"),
    (re.compile(r"(?<!\\)\.\("), "точка перед скобкой (склейка PHP внутри строки)"),
    (re.compile(r"\.\$"), "точка перед переменной (склейка PHP внутри строки)"),
]


def _text(source: bytes, node) -> str:
    return source[node.start_byte:node.end_byte].decode("utf-8", "replace")


def _walk(node):
    yield node

    for child in node.children:
        yield from _walk(child)


def problems_in(source: bytes) -> list[str]:
    tree = PARSER.parse(source)
    out: list[str] = []

    for node in _walk(tree.root_node):
        if node.type not in TEXT_NODES:
            continue

        raw = _text(source, node)

        for pattern, why in MARKERS:
            hit = pattern.search(raw)

            if hit is None:
                continue

            row, _ = node.start_point
            fragment = raw[max(0, hit.start() - 30):hit.end() + 30].replace("\n", " ⏎ ")

            out.append(f"строка {row + 1}: внутри строки {why} — «…{fragment.strip()}…»")
            break

    return out


def files(target: str) -> list[Path]:
    path = (ROOT / target).resolve() if not Path(target).is_absolute() else Path(target)

    if path.is_file():
        return [path]

    return sorted(
        p for p in path.rglob("*.php")
        if ".git" not in p.parts and str(p.relative_to(ROOT)) not in SKIP
    )


def main(argv: list[str]) -> int:
    target = argv[1] if len(argv) > 1 else "application"
    bad = 0
    checked = 0

    for path in files(target):
        checked += 1

        try:
            source = path.read_bytes()
        except OSError:
            continue

        for problem in problems_in(source):
            rel = path.relative_to(ROOT) if path.is_relative_to(ROOT) else path
            print(f"{rel}: {problem}")
            bad += 1

    print(f"Файлов: {checked}, подозрительных строк: {bad}")

    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
