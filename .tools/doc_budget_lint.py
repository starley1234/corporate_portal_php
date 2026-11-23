#!/usr/bin/env python3
"""Потолки документации: доктрина и памятки не должны расти без предела.

Зачем этот файл. Агент перед работой читает корневой `AGENTS.md`, а по модулю —
его памятку. Это и есть главная статья расхода токенов: к 01.10.2026 корневой
файл вырос до 44 КБ, памятка `prx643_cad` — до 33 КБ, а сумма всех модульных
документов — до 490 КБ. Никто не обязан читать 490 КБ, но ничто и не мешало
одной памятке дорасти до размера книги: у неё нет владельца, который следит за
объёмом.

Проверка держит четыре правила:

1. **Потолки «всегда читаемых» документов.** Они падают на первой же сессии,
   поэтому их размер — цена входа в проект. Потолок можно только опускать.
2. **Каждому модулю — памятка.** Она единственный документ модуля (решение
   28.09.2026); модуль без неё агент вынужден изучать чтением кода.
3. **Большая памятка (> 12 КБ) обязана иметь индекс разделов.** Модуль
   `prx643_cad` не станет меньше от наших пожеланий, но читать его целиком
   не нужно: индекс даёт строку и размер каждого раздела, а `context_pack.py
   section "<заголовок>" --module <модуль>` отдаёт только нужный.
4. **Контекст-пак собирается по каждому модулю и влезает в бюджет.** Это ещё и
   прогон разбора манифестов (`config/prx_module.php`) по всем 79 модулям:
   если правка сломала парсер, узнаем здесь, а не в середине задачи.

Чинится всё одной-двумя командами, которые проверка печатает рядом с ошибкой.

    python3 .tools/doc_budget_lint.py            # проверка (код 1 при ошибке)
    python3 .tools/doc_budget_lint.py --report   # только таблица размеров
"""
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, '.tools'))

import context_pack as cp  # noqa: E402  (инструмент лежит рядом)

# «Всегда читаемые» документы — их открывает каждая сессия. Потолки выставлены
# по факту 01.10.2026 с зазором ~1–3 КБ и опускаются вместе с сокращением.
# docs/ARCHITECTURE.md: 06.10.2026 потолок поднят до 79 КБ решением владельца
# (реестр модулей растёт с каждым модулем; 42 КБ перестали вмещать).
ALWAYS_READ = (
    ('AGENTS.md', 52500, 'доктрина платформы — читает каждый агент'),
    ('README.md', 9000, 'вход в проект для человека'),
    ('docs/CONTEXT.md', 26000, 'протокол экономной помодульной работы'),
    ('docs/ARCHITECTURE.md', 80896, 'архитектура и реестр модулей (79 КБ, решение владельца 06.10.2026)'),
)

# Памятка модуля: больше — либо индекс разделов, либо разделение.
MODULE_DOC_CAP = 12288      # 12 КБ
MODULE_DOC_HARD = 40960     # 40 КБ — предел даже с индексом

# Модули без памятки. Не «долг»: модуль не подключён в bootstrap.php, решение
# владельца 28.09.2026 — пока не удалять (docs/REMOVED-MODULES.md).
NO_DOC_EXEMPT = {
    'PostgreSQL_PDO': 'не подключён в bootstrap.php (решение владельца 28.09.2026)',
}

INDEX_BEGIN = cp.INDEX_BEGIN
INDEX_END = cp.INDEX_END

STATE_RX = r'^##\s+(Состояние|Готовность|Статус)'


def kb(n):
    return cp._kb(n)


def check_always_read(errors, report):
    """Потолки документов, которые читаются всегда."""
    for rel, cap, why in ALWAYS_READ:
        path = os.path.join(ROOT, rel)

        if not os.path.isfile(path):
            errors.append('нет документа %s (%s)' % (rel, why))
            continue

        size = os.path.getsize(path)
        free = cap - size
        report.append('%-24s %8s  потолок %8s  запас %8s' % (rel, kb(size), kb(cap), kb(free)))

        if size > cap:
            errors.append('%s: %s > потолка %s (%s). Правило: сократить документ '
                          'или поднять потолок отдельным решением — docs/CONTEXT.md'
                          % (rel, kb(size), kb(cap), why))

        # большие «всегда читаемые» доки тоже навигируются индексом
        check_index(path, rel, errors, required=size > MODULE_DOC_CAP)


def check_index(path, rel, errors, required=False):
    """Индекс разделов: наличие (если нужен) и совпадение с файлом."""
    text = cp._text(path)
    has = INDEX_BEGIN in text and INDEX_END in text

    if not has:
        if required:
            errors.append('%s: %s > %s и нет индекса разделов — починить: '
                          'python3 .tools/context_pack.py index %s --write'
                          % (rel, kb(os.path.getsize(path)), kb(MODULE_DOC_CAP), rel))

        return False

    stored = text[text.index(INDEX_BEGIN) + len(INDEX_BEGIN):text.index(INDEX_END)]

    if cp.render_index(path).strip() not in stored:
        errors.append('%s: индекс разделов разошёлся с файлом (правка сдвинула строки) — '
                      'обновить: python3 .tools/context_pack.py index %s --write' % (rel, rel))

    return True


def check_modules(errors, warnings, report):
    """Памятки модулей: наличие, потолки, индекс, состояние работ."""
    big = []
    no_state = []

    for name in cp.modules():
        base = os.path.join(cp.MODULES_DIR, name)
        path = os.path.join(base, 'AGENTS.md')

        if not os.path.isfile(path):
            if name in NO_DOC_EXEMPT:
                continue

            errors.append('%s: нет AGENTS.md — единственного документа модуля '
                          '(шаблон: docs/templates/module-AGENTS.md)' % name)
            continue

        size = os.path.getsize(path)
        text = cp._text(path)
        big.append((size, name))

        check_index(path, 'application/modules/%s/AGENTS.md' % name, errors,
                    required=size > MODULE_DOC_CAP)

        if size > MODULE_DOC_HARD:
            errors.append('%s/AGENTS.md: %s > %s — разделить документ '
                          '(индекс не спасает: файл читают целиком при передаче задачи)'
                          % (name, kb(size), kb(MODULE_DOC_HARD)))

        if not cp.re.search(STATE_RX, text, cp.re.M):
            no_state.append(name)

    big.sort(reverse=True)
    report.append('')
    report.append('Памятки модулей: %d, сумма %s; крупнейшие:' %
                  (len(big), kb(sum(s for s, _ in big))))

    for size, name in big[:8]:
        report.append('  %-24s %8s %s' % (name, kb(size),
                                          'индекс есть' if INDEX_BEGIN in cp._text(
                                              os.path.join(cp.MODULES_DIR, name, 'AGENTS.md'))
                                          else ('нужен индекс' if size > MODULE_DOC_CAP else '')))

    if no_state:
        warnings.append('у %d модулей в памятке нет раздела «Состояние»/«Готовность» — '
                        'новая сессия не узнает, на чём остановились: %s%s'
                        % (len(no_state), ', '.join(no_state[:8]),
                           ' …' if len(no_state) > 8 else ''))


def check_packs(errors, report):
    """Карточка собирается по каждому модулю и влезает в бюджет."""
    worst = []
    checked = 0

    for name in cp.modules():
        if not os.path.isfile(os.path.join(cp.MODULES_DIR, name, 'AGENTS.md')) and \
                name in NO_DOC_EXEMPT:
            continue

        try:
            text, _dropped = cp.build_pack(name, max_bytes=cp.HARD_MAX)
        except SystemExit as exc:
            errors.append('%s: контекст-пак не собирается (%s)' % (name, exc))
            continue

        size = len(text.encode('utf-8'))
        checked += 1
        worst.append((size, name))

        if size > cp.HARD_MAX:
            errors.append('%s: контекст-пак %s > %s — почистить блоки карточки'
                          % (name, kb(size), kb(cp.HARD_MAX)))

    worst.sort(reverse=True)
    report.append('')
    report.append('Контекст-пак: собран по %d модулям, бюджет %s; крупнейшие:'
                  % (checked, kb(cp.HARD_MAX)))

    for size, name in worst[:5]:
        report.append('  %-24s %8s' % (name, kb(size)))


def report_backlog(report):
    """Строка-сводка: сколько модулей ждут работы (подробности — context_pack.py backlog)."""
    modules_with, items = [], 0

    for name in cp.modules():
        found = cp.todo_items(name)

        if found:
            modules_with.append(name)
            items += len(found)

    report.append('')
    report.append('Очередь работы: открытые пункты в %d модулях, всего %d '
                  '(подробности: python3 .tools/context_pack.py backlog)'
                  % (len(modules_with), items))


def selftest(errors):
    """Разбор PHP-массивов — на фикстуре, чтобы падение было видно здесь."""
    fixture = """
    <?php defined('SYSPATH') or die('No direct script access.');
    /* комментарий с [скобкой] и 'кавычкой' */
    return [
        'id' => 401,
        'flag' => FALSE,
        'note' => 'первая часть '
                  .'вторая часть',
        'items' => array(
            'a' => ['label' => 'A'],
            'b' => ['label' => 'B', 'days' => 30],
        ),
        'list' => ['x', 'y'],
        'sql' => "SELECT 1 FROM t WHERE a = 'z' AND b LIKE '%'",
    ];
    """
    expected = {
        'id': 401,
        'flag': False,
        'note': 'первая часть вторая часть',
        'items': {'a': {'label': 'A'}, 'b': {'label': 'B', 'days': 30}},
        'list': ['x', 'y'],
        'sql': "SELECT 1 FROM t WHERE a = 'z' AND b LIKE '%'",
    }

    try:
        got = cp.php_array(fixture)
    except ValueError as exc:
        errors.append('разбор PHP-массива сломан: %s' % exc)
        return

    if got != expected:
        errors.append('разбор PHP-массива вернул не то: %r' % (got,))


def main(argv):
    report_only = '--report' in argv
    errors, warnings, report = [], [], []

    selftest(errors)
    check_always_read(errors, report)
    check_modules(errors, warnings, report)
    check_packs(errors, report)
    report_backlog(report)

    print('== Бюджет документации PRX (проверяет .tools/doc_budget_lint.py) ==')
    print('')
    print('Всегда читаемые документы (потолок можно только опускать):')

    for line in report:
        print(line)

    print('')

    if warnings:
        print('== Предупреждения (сборку не роняют) ==')

        for w in warnings:
            print('  - %s' % w)

        print('')

    if errors:
        print('== Ошибки ==')

        for e in errors:
            print('  - %s' % e)

        print('')
        print('Ошибок: %d. Протокол работы — docs/CONTEXT.md' % len(errors))
        return 1 if not report_only else 0

    print('Всё в бюджете: доктрина и памятки не разрослись, паки собираются.')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
