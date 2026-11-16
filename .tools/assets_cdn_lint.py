#!/usr/bin/env python3
"""Внешние CDN во вьюхах: что положено локально — тянуть снаружи нельзя.

28.09.2026 (план, этап 2.4): страницы тянули три внешних хоста без SRI и без
фолбэка — блокировка любого из них оставляла сайт без стилей и без иконок.
jQuery и Font Awesome положены в public_html/assets/vendor/. Проверка следит,
чтобы они туда не вернулись на CDN: это ровно тот случай, когда «поправлю
одну строчку» тихо возвращает внешнюю зависимость на все страницы.

Отдельная строка — api.qrserver.com. Это не CDN: туда уходил СЕКРЕТ второго
фактора в адресе картинки, то есть в чужие логи. QR рисуется в браузере
локальной библиотекой (prl530 так делал с самого начала).

Tailwind закрыт последним (28.09.2026, решение владельца): Play CDN — не файл,
а компилятор в браузере, поэтому вместо «положить локально» пришлось завести
сборку. Что классы при этом не потерялись, проверяет .tools/tailwind_classes_lint.py.

    python3 .tools/assets_cdn_lint.py [каталог…]
"""
import pathlib
import sys

# хост -> чем заменить
BANNED = {
    'code.jquery.com': '/assets/vendor/jquery/jquery-3.7.1.min.js',
    'cdnjs.cloudflare.com': '/assets/vendor/fontawesome/css/all.min.css',
    'cdn.datatables.net': '/assets/vendor/datatables/…',
    'cdn.jsdelivr.net': '/assets/vendor/{sortable,select2}/…',
    'cdn.tailwindcss.com': '/assets/css/app.css (npm run build:css)',
    # не CDN, а чужой сервис: QR рисуется в браузере, см. 599/script.php
    'api.qrserver.com': '/assets/vendor/qrcode/qrcode.js — рисуйте QR локально',
}

SUFFIXES = ('.php', '.html', '.js', '.twig')
SKIP_PARTS = ('/assets/vendor/', '/vendor/', '/node_modules/', '/.git/')


def check(roots):
    hits = []

    for root in roots:
        base = pathlib.Path(root)
        files = [base] if base.is_file() else base.rglob('*')

        for f in files:
            if not f.is_file() or f.suffix not in SUFFIXES:
                continue
            if any(p in '/' + str(f).replace('\\', '/') for p in SKIP_PARTS):
                continue

            try:
                text = f.read_text(encoding='utf8')
            except (UnicodeDecodeError, OSError):
                continue

            for n, line in enumerate(text.split('\n'), 1):
                for host, local in BANNED.items():
                    if host in line:
                        hits.append((f, n, host, local))

    return hits


def main():
    roots = sys.argv[1:] or ['application', 'public_html']
    hits = check(roots)

    for f, n, host, local in hits:
        print(f'{f}:{n}: внешний {host} — положите локально и ссылайтесь на {local}')

    print(f'Проверено на внешние CDN: {", ".join(roots)}; '
          f'{"замечаний нет" if not hits else f"замечаний: {len(hits)}"}')

    return 1 if hits else 0


if __name__ == '__main__':
    sys.exit(main())
