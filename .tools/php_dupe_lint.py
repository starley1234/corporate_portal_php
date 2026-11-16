#!/usr/bin/env python3
"""Ловит ПОВТОРНЫЕ объявления: методы, функции, маршруты, ключи массивов.

Зачем (живая авария 28.09.2026): в `Controller/Prl643/Cad.php` метод
`action_archive()` оказался объявлен ДВА раза — скрипт правки вставил блок поверх
такого же блока, а все проверки молчали: разбор грамматикой видит оба метода как
корректные (дубликат — не синтаксическая ошибка), `php_balance` считает скобки,
`php_method_lint` ищет вызовы несуществующих методов. Живой PHP падает
**фатально на компиляции класса**:

    PHP Fatal error: Cannot redeclare Controller_Prl643_Cad::action_archive()
    in .../Controller/Prl643/Cad.php on line 448

и падает не только архив, а ВСЯ ветка модуля: файл класса компилируется целиком,
поэтому 500-ку получает и /cad, и /cad/<code>, и /cad/admin. Такое надо ловить
до пуша, а не в логах nginx.

Что проверяет:
  * методы внутри класса (в том числе трейтов и интерфейсов);
  * функции на верхнем уровне файла;
  * повторные `Route::set('имя', …)` — второй вызов молча затирает первый;
  * повторные ключи в одном литерале массива (`'audit' => …, 'audit' => …`) —
    PHP берёт последний, и первая строка тихо теряется.

Запуск:
    python3 .tools/php_dupe_lint.py               # по умолчанию application/
    python3 .tools/php_dupe_lint.py .             # весь репозиторий
    python3 .tools/php_dupe_lint.py путь/файл.php # конкретный файл

Выход: 0 — чисто, 1 — есть повторные объявления.
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

# Минифицированный Adminer: разбор спотыкается (см. php_parse_lint), а объявлений
# классов/методов в нём для нас нет.
KNOWN_FALSE_POSITIVES = {
    "public_html/adminer/index.php",
}

CLASS_NODES = {
    "class_declaration",
    "interface_declaration",
    "trait_declaration",
    "enum_declaration",
}


def _text(source: bytes, node) -> str:
    return source[node.start_byte:node.end_byte].decode("utf-8", "replace")


def _name_of(source: bytes, node) -> str | None:
    """Имя объявления: из узла `name`, иначе — из имени класса/метода."""
    for child in node.children:
        if child.type in ("name", "namespace_name"):
            return _text(source, child)

    # у некоторых версий грамматики имя лежит отдельным полем
    field = node.child_by_field_name("name")

    return _text(source, field) if field is not None else None


def _string_literal(source: bytes, node) -> str | None:
    """Значение строкового литерала (одинарные/двойные кавычки без интерполяции)."""
    if node is None or node.type not in ("string", "string_content", "encapsed_string"):
        return None

    raw = _text(source, node)

    if raw[:1] in ("'", '"') and raw[-1:] == raw[:1] and len(raw) >= 2:
        return raw[1:-1]

    if node.type == "encapsed_string":
        return raw

    return None


def _walk(node):
    yield node

    for child in node.children:
        yield from _walk(child)


def problems_in(source: bytes) -> list[str]:
    tree = PARSER.parse(source)
    out: list[str] = []
    seen_top: dict[str, int] = {}

    def report(node, what: str) -> None:
        row, _ = node.start_point
        out.append(f"строка {row + 1}: повторно объявлено — {what}")

    # 1. методы класса/трейта/интерфейса и функции файла
    for node in _walk(tree.root_node):
        if node.type in CLASS_NODES:
            seen: dict[str, int] = {}

            for child in node.children:
                if child.type not in ("declaration_list", "body"):
                    continue

                for member in child.children:
                    if member.type != "method_declaration":
                        continue

                    name = _name_of(source, member)

                    if name is None:
                        continue

                    seen[name] = seen.get(name, 0) + 1

                    if seen[name] > 1:
                        report(member, f"метод {name}()")

        elif node.type == "function_definition":
            # только верхний уровень: у вложенных функций своя область видимости,
            # а у методов тип узла другой (method_declaration)
            if node.parent is not None and node.parent.type == "program":
                name = _name_of(source, node)

                if name is not None:
                    seen_top[name] = seen_top.get(name, 0) + 1

                    if seen_top[name] > 1:
                        report(node, f"функция {name}()")

    # 2. повторные Route::set('имя')
    routes: dict[str, int] = {}

    for node in _walk(tree.root_node):
        if node.type != "scoped_call_expression":
            continue

        scope = node.child_by_field_name("scope")
        name = node.child_by_field_name("name")

        if scope is None or name is None or _text(source, scope) != "Route" or _text(source, name) != "set":
            continue

        args = node.child_by_field_name("arguments")

        if args is None:
            continue

        first_arg = None

        for child in args.children:
            if child.type == "argument":
                first_arg = child.children[0] if child.children else None
                break

        first = _string_literal(source, first_arg)

        if first is None:
            continue

        routes[first] = routes.get(first, 0) + 1

        if routes[first] > 1:
            report(node, f"маршрут Route::set('{first}')")

    # 3. повторные ключи в одном литерале массива
    for node in _walk(tree.root_node):
        if node.type != "array_creation_expression":
            continue

        keys: dict[str, int] = {}

        for element in node.children:
            if element.type != "array_element_initializer":
                continue

            # ключ — только то, что стоит ПЕРЕД «=>»: у элемента без ключа
            # (обычное значение) никакого ключа нет, и пустая строка-значение
            # не должна выглядеть как повторяющийся ключ
            parts = [c for c in element.children if c.type != ","]
            key_node = None

            for idx, child in enumerate(parts):
                if child.type == "=>" and idx > 0:
                    key_node = parts[idx - 1]
                    break

            if key_node is None:
                continue

            key = _string_literal(source, key_node)

            if key is None:
                continue

            keys[key] = keys.get(key, 0) + 1

            if keys[key] > 1:
                report(element, f"ключ массива '{key}'")

    return out


def main() -> int:
    roots = [Path(a) for a in sys.argv[1:]] or [Path("application")]
    files: list[Path] = []

    for root in roots:
        base = root if root.is_absolute() else ROOT / root

        if base.is_file() and base.suffix == ".php":
            files.append(base)
        elif base.is_dir():
            files.extend(sorted(base.rglob("*.php")))
        else:
            print(f"Пропущено (нет такого пути): {root}")

    bad = 0

    for path in files:
        try:
            rel = str(path.relative_to(ROOT))
        except ValueError:
            rel = str(path)

        if rel in KNOWN_FALSE_POSITIVES:
            continue

        source = path.read_bytes()
        found = problems_in(source)

        if not found:
            continue

        bad += 1
        print(f"{rel}:")

        for line in found:
            print(f"    {line}")

    if bad:
        print(f"Файлов с повторными объявлениями: {bad}")
        return 1

    print(f"Повторных объявлений нет: проверено файлов {len(files)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
