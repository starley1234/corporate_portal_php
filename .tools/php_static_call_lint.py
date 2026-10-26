#!/usr/bin/env python3
"""Ловит вызов НЕстатического метода статически: `Foo::bar()` вместо `$foo->bar()`.

Зачем: в PHP 8 это фатал (`Error: Non-static method Foo::bar() cannot be called
statically`) — то есть живая 500-ка на странице, причём только в той ветке кода,
где вызов исполняется. Грамматику такой вызов проходит (php_parse_lint молчит),
баланс скобок цел, а `.tools/php_static_this.py` проверяет обратный случай
(`$this` внутри static-метода). Этот линтер — про третью сторону той же монеты.

Как работает:
  1. Разбирает все .php репозитория грамматикой PHP (tree-sitter) и собирает
     карту своих классов: имя → {метод: static?}, плюс родителя (extends) и
     наличие `__callStatic`;
  2. Ищет вызовы вида `ИмяКласса::метод(` (`scoped_call_expression`) и
     `static::метод(` внутри классов — для вторых смотрит на текущий класс;
  3. Предупреждает, только если метод найден в НАШЕЙ карте и он не static.
     Чужие классы (Kohana, vendor, фреймворк), методы, которых нет в карте,
     магический `__callStatic` и динамические имена — пропускаются: лучше
     промолчать, чем пугать ложным срабатыванием.

Запуск: python3 .tools/php_static_call_lint.py [пути…]   (по умолчанию application/)
Выход:  0 — чисто, 1 — есть замечания.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

try:
    import tree_sitter
    import tree_sitter_php
except ImportError:  # pragma: no cover
    print("Нет tree-sitter: pip3 install --break-system-packages tree_sitter tree_sitter_php")
    sys.exit(2)

PARSER = tree_sitter.Parser(tree_sitter.Language(tree_sitter_php.language_php()))
IGNORE_DIRS = (".git", "vendor", "node_modules", "public_html/adminer")


def php_files(roots):
    seen = set()
    out = []

    for root in roots:
        base = root if root.is_absolute() else ROOT / root
        items = [base] if base.is_file() else sorted(base.rglob("*.php"))

        for path in items:
            rel = str(path)

            if path.suffix != ".php" or rel in seen:
                continue

            if any(f"/{d}" in rel or rel.startswith(f"{d}/") for d in IGNORE_DIRS):
                continue

            seen.add(rel)
            out.append(path)

    return out


USE_RE = re.compile(r"use\s+([A-Za-z_][\w\\]*)(?:\s+as\s+(\w+))?", re.S)
USE_GROUP_RE = re.compile(r"use\s+([A-Za-z_][\w\\]*)\\s*\{([^}]*)\}", re.S)


def imports_of(tree):
    """{короткое имя: полное имя} из `use …` этого файла (с учётом `as` и групп)."""
    out = {}

    def walk(node):
        if node.type == "namespace_use_declaration":
            text = node.text.decode()

            for prefix, inside in USE_GROUP_RE.findall(text):
                for part in inside.split(","):
                    part = part.strip()

                    if not part:
                        continue

                    bits = re.split(r"\s+as\s+", part)
                    out[bits[1].strip() if len(bits) > 1 else bits[0].strip()] = f"{prefix}\\{bits[0].strip()}"
            else:
                for full, alias in USE_RE.findall(USE_GROUP_RE.sub("", text)):
                    out[alias or full.rsplit("\\", 1)[-1]] = full

        for child in node.children:
            walk(child)

    walk(tree.root_node)

    return out


def resolve(name, imports, ns=""):
    """Короткое имя → полное: через `use`-импорт, затем через текущий namespace."""
    if not name:
        return name

    if name.startswith("\\"):
        return name.lstrip("\\")

    head = name.split("\\", 1)[0]

    if head in imports:
        tail = name[len(head):]

        return imports[head] + tail

    return f"{ns}\\{name}" if ns else name


def collect_classes(files):
    """{класс: {'methods': {метод: static}, 'parent': строка|None, 'call_static': bool}}"""
    classes = {}

    for path in files:
        tree = PARSER.parse(path.read_bytes())
        imports = imports_of(tree)

        def walk(node, inside=None, ns=""):
            if node.type == "namespace_definition":
                name = ""

                for child in node.children:
                    if child.type == "name":
                        name = child.text.decode()

                for child in node.children:
                    walk(child, inside, name)

                return

            if node.type in ("class_declaration", "interface_declaration", "trait_declaration"):
                name = parent = None
                methods = {}
                magic = False

                for child in node.children:
                    if child.type == "name" and name is None:
                        name = child.text.decode()

                    if child.type == "base_clause":
                        for sub in child.children:
                            if sub.type in ("name", "qualified_name"):
                                parent = resolve(sub.text.decode(), imports, ns)

                    if child.type == "declaration_list":
                        for sub in child.children:
                            if sub.type != "method_declaration":
                                continue

                            method = None
                            is_static = any(c.type == "static_modifier" for c in sub.children)

                            for c in sub.children:
                                if c.type == "name" and method is None:
                                    method = c.text.decode()

                            if method:
                                methods[method] = is_static

                                if method == "__callStatic":
                                    magic = True

                if name:
                    # Ключ — полное имя с пространством имён: иначе мини-раннер
                    # из tests/ (namespace PHPUnit, класс TestCase) «подменял»
                    # собой PHPUnit\Framework\TestCase и рождал ложные находки.
                    fq = f"{ns}\\{name}" if ns else name
                    classes[fq] = {"methods": methods, "parent": parent,
                                   "call_static": magic, "ns": ns, "name": name}

                    for child in node.children:
                        if child.type == "declaration_list":
                            walk(child, name, ns)

                    return

            for child in node.children:
                walk(child, inside, ns)

        walk(tree.root_node)

    return classes


def is_static_method(classes, cls, method):
    """True/False — если знаем; None — если класс/метод нам незнаком (не трогаем)."""
    seen = set()

    if cls is None:
        return None

    while cls and cls not in seen:
        seen.add(cls)
        info = classes.get(cls)

        if not info:
            return None

        if info["call_static"]:
            return True

        if method in info["methods"]:
            return info["methods"][method]

        cls = info["parent"]

    return None


def main() -> int:
    args = sys.argv[1:]
    roots = [Path(a) for a in args] if args else [Path("application")]
    files = php_files(roots)
    classes = collect_classes(files)
    problems = 0

    for path in files:
        source = path.read_bytes()
        tree = PARSER.parse(source)
        imports = imports_of(tree)
        found = []

        # Важно: `self::m()` / `parent::m()` для НЕстатического метода легальны,
        # когда рядом есть $this (обычный метод экземпляра) — PHP ругается только
        # там, где объекта нет: в static-методе, во free-функции, в глобальной
        # области. Поэтому контекст отслеживаем, а не считаем вызовы вслепую.
        def walk(node, current=None, object_ctx=False, ns=""):
            if node.type == "namespace_definition":
                name = ""

                for child in node.children:
                    if child.type == "name":
                        name = child.text.decode()

                for child in node.children:
                    walk(child, current, object_ctx, name)

                return

            if node.type in ("class_declaration", "interface_declaration", "trait_declaration"):
                name = None

                for child in node.children:
                    if child.type == "name" and name is None:
                        name = child.text.decode()

                for child in node.children:
                    walk(child, name, False, ns)

                return

            if node.type in ("method_declaration", "function_definition"):
                is_static = any(c.type == "static_modifier" for c in node.children)
                inside_object = (node.type == "method_declaration" and not is_static)

                for child in node.children:
                    walk(child, current, inside_object, ns)

                return

            if node.type == "scoped_call_expression":
                scope = None

                for child in node.children:
                    if child.type in ("name", "qualified_name", "relative_scope"):
                        scope = child.text.decode()
                        break

                name_node = node.child_by_field_name("name")

                if name_node is not None and scope:
                    relative = scope in ("static", "self", "parent") or scope == current
                    method = name_node.text.decode()

                    if not (relative and object_ctx):
                        cls = resolve(scope, imports, ns) if scope not in ("static", "self", "parent") \
                            else (current and resolve(current, imports, ns))
                        result = is_static_method(classes, cls, method)

                        if result is False:
                            row = node.start_point[0] + 1
                            found.append(f"строка {row}: {scope}::{method}() — "
                                         f"метод не static (нужно `->` через экземпляр)")

            for child in node.children:
                walk(child, current, object_ctx, ns)

        walk(tree.root_node)

        if found:
            problems += len(found)

            try:
                shown = path.relative_to(ROOT)
            except ValueError:
                shown = path

            print(f"[!] {shown}")

            for line in found[:8]:
                print(f"    — {line}")

            if len(found) > 8:
                print(f"    … ещё {len(found) - 8}")

    print(f"Файлов: {len(files)}, классов в карте: {len(classes)}, замечаний: {problems}")

    return 1 if problems else 0


if __name__ == "__main__":
    sys.exit(main())
