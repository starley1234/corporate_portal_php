#!/usr/bin/env python3
"""CSRF на клиенте: глобальный хук и отсутствие небезопасных запросов без токена.

Серверная проверка — `Prx_PostGuard` (токен `csrf` или заголовок
`X-CSRF-Token`, машинные каналы — Authorization/X-Worker-Token). Клиент
закрывает её одним хуком в `app.js`, который добавляет заголовок ко всем
same-origin POST/PUT/PATCH/DELETE.

Проверяем структурно, а не по каждому вызову:

  1. в `partials/head.php` есть `<meta name="csrf-token">` (для вошедших);
  2. `app.js` ставит заголовок в fetch и XMLHttpRequest;
  3. ни один POST в вьюхах/JS не уходит на чужой origin — токен нельзя
     отправлять за пределы своего сайта.

Запуск: python3 .tools/js_csrf_lint.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
HEAD = ROOT / 'application/views/partials/head.php'
APP_JS = ROOT / 'public_html/assets/js/app.js'
TARGETS = [ROOT / 'application', ROOT / 'public_html/assets/js']
POST = re.compile(r"""(method\s*:\s*['\"]POST['"]|type\s*:\s*['\"]POST['"]|\$\.(?:ajax|post)\s*\(|\.open\s*\(\s*['\"]POST['"])""")
ABSOLUTE_URL = re.compile(r"""https?://(?!\$\{)[^'"\s)]+""")

OFFENDERS: list[str] = []


def require(label: str, condition: bool) -> None:
    if not condition:
        OFFENDERS.append(label)


def scan_cross_origin(path: Path) -> None:
    source = path.read_text(encoding='utf-8', errors='ignore')
    lines = source.splitlines()

    for index, line in enumerate(lines):
        if not POST.search(line):
            continue
        window = '\n'.join(lines[max(0, index - 4):index + 12])
        if ABSOLUTE_URL.search(window):
            OFFENDERS.append(f'{path.relative_to(ROOT)}:{index + 1} — POST на чужой origin')


def main() -> int:
    head = HEAD.read_text(encoding='utf-8') if HEAD.is_file() else ''
    app = APP_JS.read_text(encoding='utf-8') if APP_JS.is_file() else ''

    require('partials/head.php: нет <meta name="csrf-token">', 'name="csrf-token"' in head)
    require('app.js: хук не добавляет X-CSRF-Token в fetch',
            'X-CSRF-Token' in app and 'window.fetch' in app)
    require('app.js: хук не добавляет X-CSRF-Token в XMLHttpRequest',
            "XMLHttpRequest" in app and 'setRequestHeader' in app)
    require('app.js: нет PRX.csrfToken()', 'PRX.csrfToken' in app)

    for base in TARGETS:
        for path in base.rglob('*'):
            if path.suffix in ('.php', '.js') and 'vendor' not in path.parts:
                scan_cross_origin(path)

    for item in OFFENDERS:
        print(f'  ✗ {item}')
    print(f'замечаний CSRF на клиенте: {len(OFFENDERS)}')
    return 1 if OFFENDERS else 0


if __name__ == '__main__':
    raise SystemExit(main())
