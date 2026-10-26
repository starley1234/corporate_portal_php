#!/usr/bin/env python3
"""Настоящий синтаксический линтер PHP (tree-sitter), а не эвристика.

Зачем: 26.09 файл nj643_cad/classes/Service/Nj643/Cad.php уехал на прод со
склейкой `'…модели ('DOCROOT.'storage/nj643)'` (пропущена точка). PHP падал
живым `ParseError: syntax error, unexpected identifier "DOCROOT"` на /cad, а
собственные .tools/php_lint.py и .tools/php_balance.py молчали: баланс скобок
был цел, а грамматику они не разбирают. Этот линтер разбирает файл целиком
грамматикой PHP 8 и показывает любые узлы ERROR/MISSING — то есть ровно те
места, где PHP скажет «syntax error, unexpected …».

Плюс короткие открывающие теги `<?` (28.09.2026). Грамматика их принимает, а
боевой PHP с `short_open_tag=Off` — нет: `<?` остаётся обычным текстом. Так в
erp118_links два представления содержали `<? endif; ?>`, из-за чего `if` не
закрывался, и `php -l` падал на «unexpected endforeach». Цена ошибки выше, чем
кажется: в безобидном случае (`<? echo $secret ?>`) страница не падает, а
печатает исходный код прямо в браузер. `<?=` законен — включён всегда с 5.4.

Установка (нужна один раз, работает в песочнице без root):
    pip3 install --break-system-packages tree_sitter tree_sitter_php

Запуск:
    python3 .tools/php_parse_lint.py                 # по умолчанию application/
    python3 .tools/php_parse_lint.py .                 # весь репозиторий
    python3 .tools/php_parse_lint.py путь/файл.php     # конкретный файл
    python3 .tools/php_parse_lint.py . --all           # вместе с исключениями

Выход: 0 — чисто, 1 — есть синтаксические ошибки.
"""

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

# Сторонние файлы, на которых грамматика tree-sitter спотыкается, а настоящий
# PHP работает. Сейчас список пуст: единственная запись (public_html/adminer/
# index.php, минифицированный дубль docker-контейнера adminer) удалена вместе
# с самим файлом 28.09.2026. При `--all` исключения игнорируются.
KNOWN_FALSE_POSITIVES: set[str] = set()


# `<?php` и `<?=` — законные теги; всё остальное, начинающееся с `<?`,
# боевой PHP за код не считает. Теги внутри строк и комментариев сюда не
# попадают: это узлы грамматики, а не текстовый поиск.
VALID_OPEN_TAGS = {"<?php", "<?="}


def short_tags_in(tree, source: bytes) -> list[str]:
    out: list[str] = []
    stack = [tree.root_node]

    while stack:
        node = stack.pop()

        if node.type == "php_tag":
            tag = source[node.start_byte:node.end_byte].decode("utf-8", "replace").strip()

            if tag not in VALID_OPEN_TAGS:
                row, col = node.start_point
                out.append(
                    f"строка {row + 1}, столбец {col + 1}: короткий тег «{tag}» — "
                    f"PHP с short_open_tag=Off сочтёт его текстом; пишите <?php"
                )

        stack.extend(node.children)

    return sorted(out)


def non_ascii_vars_in(tree, source: bytes) -> list[str]:
    """Имя переменной с не-ASCII символом внутри строки в двойных кавычках.

    PHP разрешает байты 0x80+ в идентификаторах, поэтому "«$what»" — это
    обращение к переменной с именем `what»`, а не к `$what` в кавычках-ёлочках.
    Ошибка тихая: «Undefined variable», причём только когда строка реально
    выполнится. В кодовой базе, где ВСЕ сообщения на русском, а имена
    переменных латиницей, такое написание всегда опечатка: лечится `{$what}`.
    Поймано 28.09.2026 в tests/Unit/ManifestRegistryTest.php.
    """
    out: list[str] = []
    stack = [(tree.root_node, False)]

    while stack:
        node, inside = stack.pop()

        if node.type in ("encapsed_string", "heredoc_body"):
            inside = True

        if inside and node.type == "variable_name":
            name = source[node.start_byte:node.end_byte].decode("utf-8", "replace")

            if any(ord(ch) > 127 for ch in name):
                row, col = node.start_point
                out.append(
                    f"строка {row + 1}, столбец {col + 1}: имя «{name}» содержит "
                    f"не-ASCII символ — PHP считает его частью имени переменной; "
                    f"нужны фигурные скобки, например {{$имя}}"
                )

        for child in node.children:
            stack.append((child, inside))

    return sorted(out)


def problems_in(source: bytes) -> list[str]:
    tree = PARSER.parse(source)
    out: list[str] = short_tags_in(tree, source) + non_ascii_vars_in(tree, source)

    if not tree.root_node.has_error:
        return out

    def walk(node) -> None:
        missing = node.is_missing

        if node.type == "ERROR" or missing:
            row, col = node.start_point
            frag = source[node.start_byte:node.end_byte].decode("utf-8", "replace")
            frag = " ".join(frag.split())

            if len(frag) > 60:
                frag = frag[:60] + "…"

            what = "нет части выражения" if missing else f"лишний/непонятный фрагмент «{frag}»"
            where = "" if missing else ""

            out.append(f"строка {row + 1}, столбец {col + 1}: {what}{where}")

            return

        for child in node.children:
            walk(child)

    walk(tree.root_node)

    return out


def main() -> int:
    args = [a for a in sys.argv[1:] if a != "--all"]
    check_all = "--all" in sys.argv[1:]
    roots = [Path(a) for a in args] if args else [Path("application")]
    files: list[Path] = []
    skipped: list[str] = []

    for root in roots:
        base = root if root.is_absolute() else ROOT / root

        if base.is_file() and base.suffix == ".php":
            files.append(base)
        elif base.is_dir():
            files.extend(sorted(base.rglob("*.php")))
        else:
            print(f"Пропущено (нет такого пути): {root}")

    if not check_all:
        keep = []

        for path in files:
            try:
                rel = str(path.relative_to(ROOT))
            except ValueError:
                rel = str(path)

            if rel in KNOWN_FALSE_POSITIVES:
                skipped.append(rel)
                continue

            keep.append(path)

        files = keep

    bad = 0

    for path in files:
        source = path.read_bytes()
        found = problems_in(source)

        if not found:
            continue

        bad += 1

        try:
            shown = path.relative_to(ROOT)
        except ValueError:
            shown = path

        print(f"[!] {shown}")

        for line in found[:6]:
            print(f"    — {line}")

        if len(found) > 6:
            print(f"    … ещё {len(found) - 6}")

    for rel in skipped:
        print(f"[i] {rel} — пропущен (сторонний минифицированный файл, ложное срабатывание)")

    print(f"Проверено файлов: {len(files)}, с синтаксическими ошибками: {bad}")

    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
