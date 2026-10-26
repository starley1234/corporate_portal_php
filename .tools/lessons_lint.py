#!/usr/bin/env python3
"""Журнал уроков не должен превращаться в декорацию.

Правило (28.09.2026, docs/PLAN-2026-09-28.md п. 5.4): **урок живёт в прозе
14 дней**. За это время он обязан стать проверкой в `.tools/`, тестом или
строкой чек-листа — и тогда в разделе появляется пометка `Закреплено:`.
Не стал — раздел удаляется. Проект уже так умеет: php_dupe_lint, php_sql_lint,
php_ternary_lint, php_lateral_lint и assets_cdn_lint родились ровно из уроков.

Проверка делает две вещи:

1. **Потолок объёма — жёсткий.** Файл длиннее лимита = ошибка. Потолок
   срабатывает не от времени, а когда кто-то дописывает урок: чтобы добавить
   новый, нужно закрыть старый. Потолок можно только опускать.
2. **Список просроченного — справочный.** Разделы старше 14 дней без пометки
   `Закреплено:` перечисляются, но сборку не роняют: код в них не виноват.

    python3 .tools/lessons_lint.py [файл] [--strict] [--cap N]

`--strict` роняет и на просроченных — для разбора журнала отдельным заходом.
"""
import datetime
import pathlib
import re
import sys

DEFAULT_FILE = 'docs/AGENT-LESSONS.md'

# Потолок опускается по мере того, как уроки становятся проверками.
# Поднимать его — значит признать, что правило не работает.
DEFAULT_CAP = 2500

LIVE_DAYS = 14
MARK = 'Закреплено:'

DATE_PATTERNS = (
    (re.compile(r'(\d{4})-(\d{2})-(\d{2})'), lambda m: (int(m[1]), int(m[2]), int(m[3]))),
    (re.compile(r'(\d{1,2})\.(\d{1,2})\.(\d{4})'), lambda m: (int(m[3]), int(m[2]), int(m[1]))),
    (re.compile(r'(\d{1,2})\.(\d{1,2})(?!\.\d)'), None),   # без года — год берём текущий
)


def parse_date(title, today):
    """Первая дата из заголовка раздела или None."""
    for rx, build in DATE_PATTERNS:
        m = rx.search(title)

        if not m:
            continue

        try:
            if build is None:
                return datetime.date(today.year, int(m[2]), int(m[1]))

            y, mo, d = build(m)
            return datetime.date(y, mo, d)
        except ValueError:
            return None

    return None


def sections(text):
    """[(строка, заголовок, [строки тела])] по разделам верхнего уровня."""
    out, title, body, start = [], None, [], 0

    for n, line in enumerate(text.split('\n'), 1):
        if line.startswith('## '):
            if title is not None:
                out.append((start, title, body))
            title, body, start = line[3:].strip(), [], n
        elif title is not None:
            body.append(line)

    if title is not None:
        out.append((start, title, body))

    return out


def main():
    args = [a for a in sys.argv[1:]]
    strict = '--strict' in args
    args = [a for a in args if a != '--strict']

    cap = DEFAULT_CAP

    if '--cap' in args:
        i = args.index('--cap')
        cap = int(args[i + 1])
        del args[i:i + 2]

    path = pathlib.Path(args[0] if args else DEFAULT_FILE)

    if not path.is_file():
        print(f'Нет файла {path}')
        return 1

    text = path.read_text(encoding='utf8')
    lines = len(text.split('\n'))
    today = datetime.date.today()
    overdue, undated = [], 0

    for start, title, body in sections(text):
        date = parse_date(title, today)

        if date is None:
            # раздел без даты не стареет: это постоянное правило, а не урок.
            # Считаем их, чтобы правило нельзя было обойти, просто не поставив дату.
            undated += 1
            continue

        if (today - date).days <= LIVE_DAYS:
            continue

        if any(MARK in b for b in body):
            continue

        overdue.append((start, title, (today - date).days))

    for start, title, age in overdue:
        print(f'{path}:{start}: уроку {age} дн., пометки «{MARK}» нет — '
              f'закрепите проверкой или удалите: {title[:70]}')

    too_long = lines > cap

    if too_long:
        print(f'{path}: {lines} строк при потолке {cap}. Чтобы дописать урок, '
              f'сначала закройте старый проверкой и удалите его прозу.')

    print(f'Журнал уроков: {lines} строк (потолок {cap}), '
          f'просрочено разделов: {len(overdue)}, без даты (не стареют): {undated}')

    return 1 if (too_long or (strict and overdue)) else 0


if __name__ == '__main__':
    sys.exit(main())
