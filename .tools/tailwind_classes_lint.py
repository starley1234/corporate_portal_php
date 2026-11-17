#!/usr/bin/env python3
"""Каждый Tailwind-класс из вёрстки обязан быть в собранном CSS.

28.09.2026 сайт перестал подключать cdn.tailwindcss.com (компилятор в
браузере) и перешёл на собранный `public_html/assets/css/app.css`. У сборки
есть ровно одна опасность: Tailwind видит имена классов, только если они
написаны в файле целиком. Склейка вроде `'bg-' . $color` в сборку не попадёт,
и класс тихо перестанет существовать — вёрстка поедет там, куда никто не
смотрел.

Проверка читает все `class="…"` в PHP и JS (включая литералы внутри
PHP-тернарников), отбирает то, что похоже на утилиту Tailwind, и требует
найти её в собранном CSS. Появился класс, которого в сборке нет, — ошибка:
либо опечатка, либо динамическая склейка, которой нужен safelist.

    python3 .tools/tailwind_classes_lint.py

ИЗВЕСТНЫЕ ОТСУТСТВУЮЩИЕ — список ниже. Он не «замазывание»: каждая строка
объясняет, почему класса нет и почему это не регрессия. Пополнять его без
объяснения нельзя.
"""
import pathlib
import re
import sys

CSS_FILE = 'public_html/assets/css/app.css'

# Классы, которых в сборке нет ЗАКОННО. Play CDN их тоже не генерировал:
# либо это вообще не Tailwind, либо утилита из другой версии.
KNOWN_ABSENT = {
    # --- утилит с такими именами в Tailwind v3 нет ---------------------------
    # Их не было и на Play CDN: строки в вёрстке есть, стиля не было никогда.
    'focus:shadow-outline': 'имя из Tailwind v1, удалено во v2 — стиля нет с тех пор',
    'group-hover:animate-ring': 'анимации animate-ring в наборе нет',
    'order': 'без значения (order-1, order-first) утилиты не существует',

}

TOKEN = re.compile(r"[A-Za-z0-9_:\-\[\]\./%!#\(\)]+")

# Что вообще считаем утилитой Tailwind (иначе в список попадут все имена
# собственных css-классов проекта: btn-, modal-, prx597- и прочие).
UTILITY = re.compile(
    r'^(?:(?:sm|md|lg|xl|2xl|dark|hover|focus|active|disabled|checked|group-hover'
    r'|first|last|odd|even|before|after|placeholder|motion-safe|print|open)\:)*'
    r'(bg|text|border|ring|shadow|rounded|p[xytblr]?|m[xytblr]?|w|h|min-[wh]|max-[wh]'
    r'|flex|grid|gap|items|justify|self|space|divide|font|leading|tracking|whitespace'
    r'|truncate|opacity|backdrop|transition|duration|ease|animate|translate|scale'
    r'|rotate|cursor|outline|overflow|top|right|bottom|left|inset|z|order|object'
    r'|aspect|basis|grow|shrink|col|row|fill|stroke|underline|line|decoration|blur'
    r'|from|to|via|sr)(-|$)')

SPECIAL = ':/.[]()%!#,&<>=+*~\'"$^{}|?`@;'


def selector(cls):
    """Как Tailwind пишет класс в CSS: спецсимволы экранируются, ведущая
    цифра — шестнадцатеричным кодом (`2xl:` → `\\32xl\\:`)."""
    body = ''.join('\\' + ch if ch in SPECIAL else ch for ch in cls)

    if body and body[0].isdigit():
        body = '\\3' + body[0] + body[1:]

    return '.' + body


def candidates():
    """Токен => файл, где он впервые встретился; и атрибуты, оборванные кавычкой."""
    found = {}
    broken = []

    for root in ('application', 'public_html'):
        for f in pathlib.Path(root).rglob('*'):
            if f.suffix not in ('.php', '.js') or not f.is_file():
                continue

            path = str(f).replace('\\', '/')

            if '/assets/vendor/' in path or '/assets/css/' in path:
                continue

            text = f.read_text(encoding='utf8', errors='ignore')

            for m in re.finditer(r'class\s*=\s*(["\'])(.*?)\1', text, re.S):
                value = m.group(2)

                # литералы внутри <?php … ?> (тернарники) — отдельно,
                # сам код выбрасываем, иначе в токены попадут имена переменных
                inline = [a or b
                          for code in re.findall(r'<\?(?:php|=)?(.*?)\?>', value, re.S)
                          for a, b in re.findall(r"'([^']*)'|\"([^\"]*)\"", code)]
                plain = re.sub(r'<\?(?:php|=)?.*?\?>', ' ', value, flags=re.S)

                # `data-[selected="1"]:…` внутри class="…" — кавычка варианта
                # закрывает атрибут: скобка «[» остаётся без пары, класс
                # обрывается, и браузер теряет все классы после неё (20.10.2026).
                if plain.count('[') != plain.count(']'):
                    line = text.count('\n', 0, m.start()) + 1
                    broken.append(f'{path}:{line}: в class="…" не сходятся скобки [ ] — '
                                  f'кавычка внутри варианта закрыла атрибут; пишите data-[x=\'1\']')

                for chunk in [plain] + inline:
                    for token in TOKEN.findall(chunk):
                        if 1 < len(token) < 70:
                            found.setdefault(token, path)

    return found, broken


def main():
    css_path = pathlib.Path(CSS_FILE)

    if not css_path.is_file():
        print(f'Нет собранного CSS ({CSS_FILE}) — выполните: npm run build:css')
        return 1

    css = css_path.read_text(encoding='utf8')
    found, broken = candidates()
    checked = 0
    missing = []

    for row in broken:
        print(row)

    for token, where in sorted(found.items()):
        if not UTILITY.match(token):
            continue

        checked += 1

        if token in KNOWN_ABSENT or selector(token) in css:
            continue

        missing.append((token, where))

    for token, where in missing:
        print(f'{where}: класса «{token}» нет в собранном CSS — '
              f'опечатка, утилита не из этой версии Tailwind, или имя склеивается '
              f'в рантайме (тогда нужен safelist в tailwind.config.js)')

    print(f'Классов Tailwind в вёрстке: {checked}; '
          f'{"все есть в сборке" if not missing else f"нет в сборке: {len(missing)}"}; '
          f'известных исключений: {len(KNOWN_ABSENT)}')

    return 1 if missing or broken else 0


if __name__ == '__main__':
    sys.exit(main())
