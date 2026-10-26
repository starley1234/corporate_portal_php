#!/usr/bin/env python3
"""Ловит вызовы методов, которых у нашего класса НЕТ.

Зачем: 27.09.2026 при чистке nj643_cad вместе с диагностикой движка случайно уехал
метод `runs()`, который звала страница `/cad/admin` — и управление падало живой
500-кой «Call to undefined method Service_Nj643_Cad::runs()». Ни один линтер этого
не поймал: синтаксис цел, `$this` не в static-методе, статические вызовы тоже
корректны — просто метода нет. Такую поломку видно только на живом запросе, а
значит её нужно искать до пуша.

Что делает:
  1. Разбирает .php грамматикой PHP (tree-sitter) и собирает карту своих классов:
     имя → методы (+ родители, `use`-трейты, `__call`/`__callStatic`);
  2. Ищет вызовы `ИмяКласса::метод(` и `self::`/`parent::`/`static::` внутри классов;
  3. Ругается только если класс — НАШ (есть в карте), а метода нет ни у него, ни у
     родителей, ни в трейтах, и нет магии `__call*`. Чужие классы (Kohana, vendor),
     динамические имена и `$obj->метод()` пропускаются.

Осознанные пропуски (чтобы не пугать ложными):
  * строка с `# noqa: method` — исключение из проверки;
  * имя класса не найдено в карте (фреймворк, system/, vendor) — молчим;
  * метод с именем переменной/конкатенацией — молчим.

Запуск: python3 .tools/php_method_lint.py [пути…]   (по умолчанию application/)
Выход:  0 — чисто, 1 — есть замечания.
"""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

try:
    import tree_sitter
    import tree_sitter_php
except ImportError:  # pragma: no cover
    print("Нет tree-sitter: pip3 install --break-system-packages tree_sitter tree_sitter_php")
    sys.exit(2)

sys.path.insert(0, str(Path(__file__).resolve().parent))

# Переиспользуем разбор карты классов из соседнего линтера: там уже решены
# пространства имён, `use … as`, группы use и родители.
from php_static_call_lint import (  # noqa: E402  (импорт после пути)
    PARSER, collect_classes, imports_of, php_files, resolve,
)


def trait_parents(path, classes):
    """Дополняет карту классов трейтами: `class A { use T; }` → методы T видны в A.

    Возвращает ОБРАТНУЮ карту «трейт → классы, которые его используют»: внутри
    трейта `self::` — это класс-носитель (см. main), и без обратной карты линтер
    ругался бы на каждый помощник, который трейт берёт у своего класса.
    """
    for path_ in path:
        tree = PARSER.parse(path_.read_bytes())
        imports = imports_of(tree)

        def walk(node, current=None, ns=""):
            if node.type == "namespace_definition":
                name = ""

                for child in node.children:
                    if child.type == "name":
                        name = child.text.decode()

                for child in node.children:
                    walk(child, current, name)

                return

            if node.type in ("class_declaration", "trait_declaration", "interface_declaration"):
                name = None

                for child in node.children:
                    if child.type == "name" and name is None:
                        name = child.text.decode()

                fq = f"{ns}\\{name}" if (name and ns) else name

                for child in node.children:
                    if child.type == "declaration_list" and fq in classes:
                        for sub in child.children:
                            if sub.type != "use_declaration":
                                continue

                            for part in sub.text.decode()[len("use"):].split(","):
                                # `use A;`, `use A, B;`, `use A { m as n; }` —
                                # хвостовая `;` и адаптации в скобках не часть имени.
                                trait = part.strip().split("{")[0].strip().rstrip(";").strip()

                                if trait:
                                    classes[fq].setdefault("traits", []).append(
                                        resolve(trait, imports, ns))

                    walk(child, name, ns)

                return

            for child in node.children:
                walk(child, current, ns)

        walk(tree.root_node)

    users = {}

    for cls, info in classes.items():
        for trait in info.get("traits", []):
            users.setdefault(trait, []).append(cls)

    return users


def has_method(classes, cls, method, seen=None):
    """Есть ли метод у класса, его родителей или трейтов; магия тоже считается."""
    seen = seen or set()

    while cls and cls not in seen:
        seen.add(cls)
        info = classes.get(cls)

        if info is None:
            return None                      # класс чужой — не наше дело

        if info["methods"].get("__callStatic") or info["methods"].get("__call"):
            return True

        if method in info["methods"]:
            return True

        for trait in info.get("traits", []):
            if has_method(classes, trait, method, seen):
                return True

        cls = info["parent"]

    return False


def main() -> int:
    args = sys.argv[1:]
    roots = [Path(a) for a in args] if args else [Path("application")]
    files = php_files(roots)
    classes = collect_classes(files)
    trait_users = trait_parents(files, classes)
    problems = 0

    for path in files:
        source = path.read_bytes()
        tree = PARSER.parse(source)
        imports = imports_of(tree)
        found = []

        def walk(node, current=None, ns="", holder=""):
            if node.type == "namespace_definition":
                name = ""

                for child in node.children:
                    if child.type == "name":
                        name = child.text.decode()

                for child in node.children:
                    walk(child, current, name, holder)

                return

            if node.type in ("class_declaration", "trait_declaration", "interface_declaration"):
                name = None

                for child in node.children:
                    if child.type == "name" and name is None:
                        name = child.text.decode()

                kind = ("trait" if node.type == "trait_declaration"
                        else "interface" if node.type == "interface_declaration" else "class")

                for child in node.children:
                    walk(child, name or current, ns, kind)

                return

            if node.type == "scoped_call_expression":
                scope = None

                for child in node.children:
                    if child.type in ("name", "qualified_name", "relative_scope"):
                        scope = child.text.decode()
                        break

                name_node = node.child_by_field_name("name")

                if name_node is not None and scope and name_node.type == "name":
                    relative = scope in ("static", "self", "parent")
                    cls = (current and resolve(current, imports, ns)) if relative \
                        else resolve(scope, imports, ns)
                    method = name_node.text.decode()
                    bad = False

                    # `parent::` внутри трейта — родитель класса-носителя, и мы его
                    # не знаем: молчим, а не пугаем ложным «метода нет».
                    if holder == "trait" and relative and scope == "parent":
                        cls = None

                    if cls in classes:
                        verdict = has_method(classes, cls, method)

                        # `self::` внутри трейта — это КЛАСС, который трейт
                        # подключил. Метод мог приехать из самого класса (или его
                        # родителей), поэтому спрашиваем всех носителей: нашли у
                        # любого — трейт в порядке.
                        if verdict is False and holder == "trait" and scope in ("self", "static"):
                            owners = trait_users.get(cls, [])
                            verdict = True if any(
                                has_method(classes, owner, method) is not False for owner in owners
                            ) else None

                        bad = verdict is False

                    if bad:
                        row = node.start_point[0] + 1
                        line = source.splitlines()[row - 1].decode(errors="replace")

                        if "noqa: method" not in line:
                            found.append(
                                f"строка {row}: {scope}::{method}() — метода нет ни в "
                                f"{cls}, ни у родителей/трейтов")

            for child in node.children:
                walk(child, current, ns, holder)

        walk(tree.root_node)

        if found:
            problems += len(found)

            try:
                shown = path.relative_to(ROOT)
            except ValueError:
                shown = path

            print(f"\n{shown}")

            for item in found:
                print(f"  {item}")

    if problems:
        print(f"\nЗамечаний: {problems} — вызов метода, которого нет, это живая 500-ка")
    else:
        print(f"Файлов: {len(files)}, классов в карте: {len(classes)}, замечаний: 0")

    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
