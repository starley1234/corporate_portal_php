#!/usr/bin/env python3
"""Ищет в PHP-коде «склейку без оператора» — забытую точку или запятую.

Резервный инструмент: если доступен `.tools/php_parse_lint.py` (tree-sitter,
настоящая грамматика PHP) — сначала гоняйте его, он ловит все синтаксические
ошибки. Этот скрипт нужен там, где tree-sitter поставить нельзя: он на чистом
Python, без зависимостей.

Почему: живой случай 26.09 — в nj643_cad стояло

    'Нет прав на запись файлов модели ('DOCROOT.'storage/nj643)'

вместо

    'Нет прав на запись файлов модели ('.DOCROOT.'storage/nj643)'

PHP падает на этом `ParseError: syntax error, unexpected identifier "DOCROOT"`,
причём только когда страница доходит до этой строки (на /cad — фатал 500).
Обычные линтеры такое не видят: это не баланс скобок, а пропущенный оператор
между двумя выражениями.

Как работает: файл разбирается «по-настоящему» — HTML вне `<?php … ?>`
игнорируется, внутри PHP распознаются строки ('…' и "…" с экранированием),
heredoc/nowdoc, комментарии, идентификаторы, переменные, числа и операторы.
Затем проверяются соседние ЗНАЧИМЫЕ токены:

  * STRING сразу за STRING / IDENT / $VAR / NUMBER — ошибка;
  * NUMBER сразу за IDENT / NUMBER — ошибка.

Ошибка не в количестве скобок, а в отсутствии оператора — то есть в реальном
фатале PHP («unexpected identifier», «expecting ']'»).

Запуск:  python3 .tools/php_adjacent_lint.py [пути…]   (по умолчанию application/)
Выход:   0 — чисто, 1 — есть подозрительные склейки.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

IDENT = re.compile(r"[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*")
NUMBER = re.compile(r"\d[\w.]*")
HERE = re.compile(r"<<<[ \t]*('?)([A-Za-z_][A-Za-z0-9_]*)\1[ \t]*\r?\n")
OPEN_PHP = re.compile(r"<\?(php\b|=)")


def tokens(text: str):
    """Значимые токены PHP: (вид, текст, строка). HTML вне <?php … ?> пропускаем."""
    out = []
    i, n = 0, len(text)
    line = 1
    in_php = False

    while i < n:
        if not in_php:
            m = OPEN_PHP.search(text, i)

            if not m:
                break

            line += text.count("\n", i, m.end())
            out.append(("OTHER", "<?php", line))
            i = m.end()
            in_php = True
            continue

        ch = text[i]

        if ch == "\n":
            line += 1
            i += 1
            continue

        if ch in " \t\r":
            i += 1
            continue

        if text.startswith("?>", i):
            out.append(("OTHER", "?>", line))
            in_php = False
            i += 2
            continue

        if text.startswith("//", i) or ch == "#":
            # в однострочном комментарии PHP-режим закрывает и `?>` — по
            # спецификации PHP «HTML после // … ?> будет напечатан»
            j = text.find("\n", i)
            k = text.find("?>", i)

            if j == -1:
                j = len(text)

            if k != -1 and k < j:
                i = k
                continue

            i = j
            continue

        if text.startswith("/*", i):
            j = text.find("*/", i + 2)
            end = n if j == -1 else j + 2
            line += text.count("\n", i, end)
            i = end
            continue

        if text.startswith("<<<", i):
            m = HERE.match(text, i)

            if m:
                label = m.group(2)
                start_line = line
                j = m.end()

                while j < n:
                    k = text.find("\n", j)
                    stop = k if k != -1 else n
                    tail = text[j:stop].strip()

                    if tail.startswith(label) and (
                            len(tail) == len(label) or not IDENT.match(tail[len(label):])):
                        # продолжаем разбор СРАЗУ после метки: «SQL;» и «SQL);»
                        # несут значимые ; и ), их нельзя проглотить
                        at = text.find(label, j, stop)
                        line += text.count("\n", m.end(), j)
                        i = at + len(label)
                        break

                    if k == -1:
                        i = n
                        break

                    j = k + 1

                out.append(("STRING", "heredoc", start_line))
                continue

            i += 3
            continue

        if ch in "'\"":
            start_line = line
            j = i + 1

            while j < n:
                if text[j] == "\\":
                    j += 2
                    continue

                if text[j] == ch:
                    break

                if text[j] == "\n":
                    line += 1

                j += 1

            out.append(("STRING", text[i:min(j + 1, n)], start_line))
            i = j + 1
            continue

        if ch == "$":
            m = re.match(r"\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*", text[i:])

            if m:
                out.append(("VAR", m.group(0), line))
                i += len(m.group(0))
                continue

            i += 1
            continue

        m = IDENT.match(text, i)

        if m:
            word = m.group(0)

            # пространства имён и вызовы статических методов: Foo\Bar::baz
            while i + len(word) < n and text[i + len(word)] == "\\":
                m2 = IDENT.match(text, i + len(word) + 1)

                if not m2:
                    break

                word += "\\" + m2.group(0)

            out.append(("IDENT", word, line))
            i += len(word)
            continue

        m = NUMBER.match(text, i)

        if m:
            out.append(("NUMBER", m.group(0), line))
            i += len(m.group(0))
            continue

        # оператор/разделитель: тоже значимый токен — он «разводит» выражения
        out.append(("OTHER", ch, line))
        i += 1

    return out


# Ключевые слова-операторы: `'x' AND $y`, `1 OR 2`, `$a AS $b` — это валидный PHP,
# а вовсе не «склейка». Всё остальное после строки/числа — потерянный оператор.
OPERATOR_WORDS = {"and", "or", "xor", "instanceof", "as", "insteadof"}


def check_file(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8", errors="replace")
    problems = []
    toks = tokens(text)

    for k in range(1, len(toks)):
        prev_kind, prev_text, _prev_line = toks[k - 1]
        kind, text_cur, line = toks[k]

        word = text_cur.lower() if kind == "IDENT" else ""

        if prev_kind == "STRING" and (kind in ("STRING", "VAR", "NUMBER")
                or (kind == "IDENT" and word not in OPERATOR_WORDS)):
            problems.append(f"строка {line}: после {short(prev_text)} сразу {short(text_cur)} — "
                            "похоже, пропущен оператор (точка/запятая)")
        elif prev_kind == "NUMBER" and (kind in ("STRING", "VAR", "NUMBER")
                or (kind == "IDENT" and word not in OPERATOR_WORDS)):
            problems.append(f"строка {line}: после {short(prev_text)} сразу {short(text_cur)} — "
                            "похоже, пропущен оператор")

    return problems


def short(text: str, limit: int = 34) -> str:
    text = text.replace("\n", " ")

    return text if len(text) <= limit else text[:limit] + "…"


def main() -> int:
    args = sys.argv[1:]
    roots = [Path(a) for a in args] if args else [Path("application")]

    files: list[Path] = []

    for root in roots:
        base = root if root.is_absolute() else ROOT / root

        if base.is_file() and base.suffix == ".php":
            files.append(base)
        elif base.is_dir():
            files.extend(sorted(base.rglob("*.php")))

    errors = 0

    for path in files:
        problems = check_file(path)

        if problems:
            errors += 1

            try:
                rel = path.relative_to(ROOT) if path.is_absolute() else path
            except ValueError:
                rel = path
            print(f"[!] {rel}")

            for p in problems[:6]:
                print(f"    — {p}")

            if len(problems) > 6:
                print(f"    … ещё {len(problems) - 6}")

    print(f"Проверено файлов: {len(files)}, с подозрительными склейками: {errors}")

    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
