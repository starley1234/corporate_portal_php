#!/usr/bin/env python3
"""Мёртвые методы PHP-классов: публичные, которых никто не зовёт.

Зачем. После распила и серии правок в классе остаются обёртки и выборки, которые
когда-то были нужны, а теперь дублируют живые методы (в nj646 так нашлось восемь:
`run_catchup()` звала `run_watch()`, `unknown_top()` дублировал `unknown_queue()`,
`title_auto()` — `title_auto_info()`). Глазами такое не найти: класс на тысячу
строк выглядит «живым» целиком.

Что делает. Собирает по репозиторию ВСЕ вызовы вида `Кто::метод(` и `Кто->метод(`
(включая `self::`/`static::`/`parent::`) и сверяет с публичными методами цели.
Вызовы делятся на ВНЕШНИЕ (через имя класса, `->`) и ВНУТРЕННИЕ (`self::`,
`static::`, `parent::`). Твёрдый кандидат — без единого вызова; слабый («зовут
только изнутри») печатается отдельно: сам метод не мёртв, но если цепочку тянет
только мёртвый собрат — это видно.

Методы трейтов проверяются наравне с классами: их зовут через класс-носитель.
Не трогаем `__магию` и `action_*` (их вызывает роутер/фреймворк) — иначе отчёт врал
бы на контроллерах.

Это отчёт, а не приговор: метод может быть точкой входа для воркера, CRON или
внешнего кода. Но каждый твёрдый кандидат обязан иметь ответ «кто его зовёт».

Использование:
    python3 .tools/php_dead_methods.py application/modules/nj646_label/classes/Service
    python3 .tools/php_dead_methods.py <файл.php> [<каталог> ...]

Код возврата: 0 — твёрдых кандидатов нет, 1 — есть (можно звать из CI).
"""
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SKIP_DIRS = {'node_modules', '.git', 'vendor', 'logs', 'cache'}
TEXT_SUFFIXES = {'.php', '.py', '.js', '.mjs', '.md', '.json', '.yml', '.yaml', '.sh'}

# Методы, которых не бывает «мёртвыми»: их зовёт фреймворк или сам PHP.
IGNORED_PREFIXES = ('__', 'action_')
IGNORED_NAMES = {'initialize', 'instance', 'factory', 'setUp', 'tearDown'}

CALL = re.compile(r'(\w+)\s*(::|->)\s*(\w+)\s*\(')


def collect_calls():
    """Все вызовы репозитория: (внешние, внутренние) — пары (оператор, имя метода)."""
    external, internal = set(), set()

    for path in ROOT.rglob('*'):
        if not path.is_file() or path.suffix not in TEXT_SUFFIXES:
            continue

        if any(part in SKIP_DIRS for part in path.parts):
            continue

        try:
            text = path.read_text(errors='ignore')
        except OSError:
            continue

        for m in CALL.finditer(text):
            who, op, name = m.group(1), m.group(2), m.group(3)

            if who in ('self', 'static', 'parent'):
                internal.add((op, name))
            else:
                external.add((op, name))

    return external, internal


def files_of(targets):
    out = []

    for target in targets:
        path = (ROOT / target).resolve()

        if path.is_dir():
            out.extend(sorted(path.rglob('*.php')))
        elif path.is_file():
            out.append(path)

    return out


def methods_of(src):
    """(имя, статический?) для публичных методов класса/трейта."""
    return [
        (m.group(2), bool(m.group(1)))
        for m in re.finditer(
            r'^\s{4}(?:final\s+|abstract\s+)?public\s+(?:(static)\s+)?function\s+(\w+)',
            src,
            re.M,
        )
    ]


def main(argv):
    targets = argv[1:]

    if not targets:
        print(__doc__.strip().split('\n\n')[0])
        print('\nУкажите путь: файл или каталог.')
        return 2

    external, internal = collect_calls()

    total = 0
    solid = []
    weak = []

    for path in files_of(targets):
        src = path.read_text(errors='ignore')

        if re.search(r'^interface ', src, re.M):
            continue

        rel = path.relative_to(ROOT)
        dead, chain = [], []

        for name, is_static in methods_of(src):
            total += 1

            if name.startswith(IGNORED_PREFIXES) or name in IGNORED_NAMES:
                continue

            lead = '::' if is_static else '->'

            if (lead, name) not in external and (lead, name) not in internal:
                dead.append(name)
            elif (lead, name) not in external:
                chain.append(name)

        if dead:
            solid.append((rel, dead))

        if chain:
            weak.append((rel, chain))

    print('== вызовов нет вовсе (твёрдые кандидаты) ==\n')

    for rel, dead in solid:
        print(f'{rel}:')

        for name in dead:
            print(f'  · {name}() — не зовёт никто')

    if not solid:
        print('  (пусто)')

    print('\n== зовут только изнутри класса/трейта (посмотреть цепочку) ==\n')

    for rel, chain in weak:
        print(f'{rel}: '.ljust(72) + ', '.join(chain) + '()')

    if not weak:
        print('  (пусто)')

    count_solid = sum(len(x) for _, x in solid)
    count_weak = sum(len(x) for _, x in weak)

    print(f'\nПроверено методов: {total}, твёрдых кандидатов: {count_solid}, слабых: {count_weak}')

    if count_solid:
        print('Каждый твёрдый кандидат — вопрос «кто его зовёт?»: точка входа воркера/CRON тоже зовётся, просто не отсюда.')
    elif count_weak:
        print('Твёрдых нет: у каждого метода есть хотя бы внутренний вызов.')

    return 1 if count_solid else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
