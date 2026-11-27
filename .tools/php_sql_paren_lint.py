#!/usr/bin/env python3
"""Ловит несбалансированные скобки в SQL, собранном склейкой строк PHP.

Зачем (27.11.2026, найдено интеграционными тестами): в двух местах
`VALUES (…` собиралось склейкой, и закрывающая `)` потерялась на стыке:

    'VALUES ('.$org_id.', … :source_system, '.$source_updated_sql.'
     RETURNING id'

    "VALUES (".(int) $row['id'].", ".(int) $row['document_id'].", :level
     RETURNING id"

PHP валиден, PHP-кода внутри SQL нет, скобки PHP целы — `php_parse_lint`,
`php_sql_lint` и `php_balance` молчат. PostgreSQL отвечает «syntax error at or
near RETURNING», а `catch` превращает это в «Не удалось применить пакет» (импорт
сотрудников prx300 не работал вообще) или молча пропускает (эскалации
просроченных сроков prx393 не отправлялись никогда).

Что проверяем: каждое выражение верхнего уровня — строковый литерал или
цепочку склеек `.` — собираем в текст, подставляя `?` вместо любой PHP-части
(переменной, вызова, приведения). Если текст начинается как SQL-запрос, считаем
скобки вне SQL-кавычек: итог должен быть нулевым и ни разу не уходить в минус.
Запрос, разложенный по нескольким переменным (`$sql .= …`), не проверяется —
ложных срабатываний такая проверка не даёт, поэтому она строгая.

Запуск:
    python3 .tools/php_sql_paren_lint.py               # по умолчанию application/
    python3 .tools/php_sql_paren_lint.py .             # весь репозиторий
    python3 .tools/php_sql_paren_lint.py путь/файл.php # конкретный файл
    python3 .tools/php_sql_paren_lint.py --selftest    # проверка самого линтера

Выход: 0 — чисто, 1 — найдены запросы с несбалансированными скобками.
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
SKIP_DIRS = {"vendor", "node_modules", ".git"}

SQL_START = re.compile(
    r"^\s*(INSERT\s+INTO|UPDATE\s+[\w\"]+(\s+\w+)?\s+SET|SELECT\s|DELETE\s+FROM|WITH\s|"
    r"MERGE\s+INTO|CREATE\s|ALTER\s)",
    re.I,
)
ESCAPES = {"n": "\n", "t": "\t", "r": "\r", "\\": "\\", '"': '"', "'": "'", "$": "$", "0": "\0"}


def _text(node, src):
    return src[node.start_byte:node.end_byte].decode("utf-8", "replace")


def _flatten(node, src, out):
    """Текст склейки: литералы как есть, любая PHP-часть — `?`."""
    if node.type == "binary_expression":
        op = node.child_by_field_name("operator")
        if op is not None and _text(op, src) == ".":
            _flatten(node.child_by_field_name("left"), src, out)
            _flatten(node.child_by_field_name("right"), src, out)
            return
    if node.type in ("encapsed_string", "string"):
        parts = []
        for child in node.children:
            if child.type in ("string_content", "string_value"):
                parts.append(_text(child, src))
            elif child.type == "escape_sequence":
                raw = _text(child, src)
                parts.append(ESCAPES.get(raw[1:2], "?"))
            elif child.type in ('"', "'"):
                continue
            else:  # интерполяция {$x} / $x
                parts.append("?")
        out.append("".join(parts))
        return
    out.append("?")


def balance(sql):
    """(итог, ушли_в_минус) — скобки вне '…', "…" и комментариев `--`."""
    depth, quote, i, negative = 0, None, 0, False
    while i < len(sql):
        ch = sql[i]
        if quote:
            if ch == quote:
                if sql[i + 1:i + 2] == quote:
                    i += 1
                else:
                    quote = None
        elif ch in ("'", '"'):
            quote = ch
        elif ch == "-" and sql[i + 1:i + 2] == "-":
            nl = sql.find("\n", i)
            i = len(sql) if nl < 0 else nl
            continue
        elif ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            negative = negative or depth < 0
        i += 1
    return depth, negative


SCOPES = {"function_definition", "method_declaration", "anonymous_function", "arrow_function", "program"}


def _continued_later(node, src):
    """`$sql = 'SELECT … ('; … $sql .= ')';` — запрос дописывается в той же функции."""
    parent = node.parent
    if parent is None or parent.type != "assignment_expression":
        return False
    left = parent.child_by_field_name("left")
    if left is None:
        return False
    name = _text(left, src)
    scope = parent
    while scope is not None and scope.type not in SCOPES:
        scope = scope.parent
    stack = [scope] if scope is not None else []
    while stack:
        cur = stack.pop()
        if cur.type == "augmented_assignment_expression":
            target = cur.child_by_field_name("left")
            op = cur.child_by_field_name("operator")
            if target is not None and _text(target, src) == name and (op is None or _text(op, src) == ".="):
                return True
        stack.extend(cur.children)
    return False


def _walk(node, src, found):
    if node.type in ("binary_expression", "encapsed_string", "string"):
        parent = node.parent
        nested = False
        if parent is not None and parent.type == "binary_expression":
            op = parent.child_by_field_name("operator")
            nested = op is not None and _text(op, src) == "."
        is_concat = node.type != "binary_expression" or (
            node.child_by_field_name("operator") is not None
            and _text(node.child_by_field_name("operator"), src) == "."
        )
        if is_concat and not nested:
            out = []
            _flatten(node, src, out)
            sql = "".join(out)
            if SQL_START.match(sql) and not _continued_later(node, src):
                depth, negative = balance(sql)
                if depth != 0 or negative:
                    found.append((node.start_point[0] + 1, depth, " ".join(sql.split())))
            if node.type != "binary_expression":
                return
    for child in node.children:
        _walk(child, src, found)


def check_source(src):
    if isinstance(src, str):
        src = src.encode("utf-8")
    found = []
    _walk(PARSER.parse(src).root_node, src, found)
    return found


def _files(target):
    path = (ROOT / target) if not Path(target).is_absolute() else Path(target)
    if path.is_file():
        yield path
        return
    for file in sorted(path.rglob("*.php")):
        rel = file.relative_to(ROOT).as_posix() if file.is_relative_to(ROOT) else file.as_posix()
        if rel in SKIP or SKIP_DIRS.intersection(file.parts):
            continue
        yield file


def selftest():
    bad = [
        "<?php $q = 'INSERT INTO t (a, b) VALUES ('.$a.', :b RETURNING id';",
        '<?php $q = "INSERT INTO t (a) VALUES (".(int) $x.", :l\n RETURNING id";',
        "<?php $q = 'SELECT count(* FROM t';",
        "<?php $q = 'UPDATE t SET a = 1) WHERE id = 2';",
        "<?php f('DELETE FROM t WHERE id IN ('.implode(',', $ids));",
    ]
    good = [
        "<?php $q = 'INSERT INTO t (a, b) VALUES ('.$a.', :b) RETURNING id';",
        "<?php $q = \"SELECT ')' AS paren, '(' AS other FROM t\";",
        "<?php $q = 'SELECT a FROM t -- (комментарий\n WHERE b = 1';",
        "<?php $q = 'SELECT * FROM t WHERE id IN ('.implode(',', $ids).')';",
        "<?php $sql = 'SELECT * FROM t WHERE ('; $sql .= 'a = 1)';",  # раскладка по переменной — не наша забота
        "<?php $t = 'Выберите (необязательно';",  # не SQL
        '<?php $q = "UPDATE t SET note = \'(\' WHERE id = {$id}";',
    ]
    fails = 0
    for code in bad:
        if not check_source(code):
            print("selftest: не поймано:", code)
            fails += 1
    for code in good:
        if check_source(code):
            print("selftest: ложное срабатывание:", code)
            fails += 1
    print("selftest:", "OK" if not fails else f"{fails} ошибок")
    return 1 if fails else 0


def main(argv):
    if argv and argv[0] == "--selftest":
        return selftest()
    targets = argv or ["application"]
    total = 0
    for target in targets:
        for file in _files(target):
            for line, depth, sql in check_source(file.read_bytes()):
                total += 1
                rel = file.relative_to(ROOT) if file.is_relative_to(ROOT) else file
                print(f"{rel}:{line}: скобки SQL не сходятся ({depth:+d}): {sql[:180]}")
    if total:
        print(f"php_sql_paren_lint: {total} запрос(ов) с несбалансированными скобками — PG ответит syntax error")
        return 1
    print("php_sql_paren_lint: скобки SQL сходятся")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
