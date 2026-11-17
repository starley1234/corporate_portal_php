#!/usr/bin/env python3
"""Ловит ловушку приоритета `AND`/`OR` в PHP (они слабее `=`, `?:` и `&&`).

Почему это важно (живые находки 28.09.2026):

    $elapsed = ($status === 'processing' OR $status === 'pending') AND $started_ts
        ? max(0, time() - $started_ts)
        : 0;                       # → bool: секундомер показывал «0:01», «0:00»

    $sql = '... finished_at = '.($status === 'done' OR $status === 'error'
        ? 'now()' : 'finished_at').' ...';   # → в SQL уезжало «finished_at = 1»

    $is_admin = (bool) $user_id AND $this->can('prx605.admin');  # → админ = «вошёл»

Разбор PHP: `AND`/`OR`/`XOR` связывают СЛАБЕЕ `=`, `?:`, `&&`/`||`. Поэтому
выражение выше читается как `$x = A AND (B ? C : D)`, а не как `$x = (A AND B) ? C : D`,
и вместо значения получается булево (или присваивается только левая часть).

Две проверки:
  * тернарник внутри `AND`/`OR` БЕЗ скобок — значение станет bool;
  * присваивание слева от `AND`/`OR` — справа от `=` окажется только первый
    операнд (остальные посчитаются и выбросятся).

Раньше проверка была построчной (регэксп по одной строке) и пропускала
многострочные выражения — именно так `_status_of()` в prx120 и прожил с bool.
Теперь это разбор грамматикой (tree-sitter), как у остальных линтеров проекта.

Запуск:  python3 .tools/php_ternary_lint.py [путь]   (по умолчанию application/)
Выход:   0 — чисто, 1 — есть подозрительные места, 2 — нет tree-sitter.
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

# Минифицированный Adminer: разбор спотыкается (см. php_parse_lint).
SKIP = {"public_html/adminer/index.php"}

# Операторы, которые связывают СЛАБЕЕ присваивания и тернарника. `&&`/`||`
# наоборот сильнее — они безопасны, их не проверяем.
LOOSE = {"and", "or", "xor"}

ASSIGNMENTS = {"assignment_expression", "augmented_assignment_expression"}


def _walk(node):
    yield node
    for child in node.children:
        yield from _walk(child)


def _operator(node) -> str:
    for child in node.children:
        if not child.is_named and child.type in LOOSE:
            return child.type
    return ""


def _ternary_before_parens(node) -> bool:
    """Есть ли тернарник в этом операнде, если НЕ заглядывать в скобки?

    `A AND (B ? C : D)` — автор явно так и хотел (значение оператора — bool),
    поэтому скобки считаем защитой и внутрь не идём.
    """
    if node.type == "parenthesized_expression":
        return False

    if node.type == "conditional_expression":
        return True

    return any(_ternary_before_parens(child) for child in node.children)


def _snippet(source: bytes, node) -> str:
    text = source[node.start_byte:node.end_byte].decode("utf-8", "replace")
    return " ".join(text.split())[:120]


def problems_in(source: bytes) -> list[str]:
    tree = PARSER.parse(source)
    out = []

    for node in _walk(tree.root_node):
        if node.type != "binary_expression":
            continue

        operator = _operator(node)

        if operator == "":
            continue

        left = node.child_by_field_name("left")
        right = node.child_by_field_name("right")

        if left is None or right is None:
            continue

        row = node.start_point[0] + 1

        if _ternary_before_parens(right):
            out.append(
                f"строка {row}: тернарник внутри «{operator.upper()}» без скобок — "
                f"значением станет bool, а не ветка: «{_snippet(source, node)}…»"
            )
            continue

        if left.type in ASSIGNMENTS:
            out.append(
                f"строка {row}: присваивание слева от «{operator.upper()}» — "
                f"присвоится только первый операнд: «{_snippet(source, node)}…»"
            )

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
