#!/usr/bin/env python3
"""Мёртвые ссылки в документации.

Markdown-ссылки вида `[текст](path)` и `<path>` должны указывать на
существующий файл. Ссылки на отсутствующие документы — главный источник дрейфа:
агент читает README, идёт по ссылке и попадает в никуда.

Проверяются: `*.md` в корне, `docs/`, `application/modules/*/AGENTS.md`,
`application/modules/*/README.md`. Якоря (`#...`), `http(s)://`, `mailto:`
и шаблоны с `<...>` пропускаются.

Запуск: python3 .tools/doc_links_check.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LINK = re.compile(r'\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)')
AUTOLINK = re.compile(r'<([^<>:\s]+\.(?:md|php|py|js|css|json|yaml|yml|sh))>')
# `<файл.md>` в таблице команд — метасинтаксис, а не ссылка
PLACEHOLDER = re.compile(r'^[a-zа-яё*]+\.', re.I)
SKIP = ('http://', 'https://', 'mailto:', '#', '/', 'data:')

BROKEN: list[str] = []


def targets() -> list[Path]:
    found = list(ROOT.glob('*.md'))
    found += list((ROOT / 'docs').rglob('*.md'))
    for module in (ROOT / 'application/modules').glob('*'):
        found += [module / 'AGENTS.md', module / 'README.md']
    return [path for path in found if path.is_file()]


def check(document: Path) -> None:
    source = document.read_text(encoding='utf-8', errors='ignore')
    # фenced-код не проверяем: там примеры
    source = re.sub(r'```.*?```', '', source, flags=re.S)

    candidates = [match.group(1) for match in LINK.finditer(source)]
    candidates += [match.group(1) for match in AUTOLINK.finditer(source)]

    for raw in candidates:
        link = raw.strip()
        if any(link.startswith(prefix) for prefix in SKIP):
            continue
        if '<' in link or '>' in link or ' ' in link:
            continue
        if PLACEHOLDER.match(link):
            continue
        link = link.split('#')[0]
        if not link:
            continue

        resolved = (document.parent / link).resolve()
        if not resolved.exists():
            BROKEN.append(f'{document.relative_to(ROOT)} → {link}')


def main() -> int:
    for document in targets():
        check(document)

    for item in BROKEN:
        print(f'  ✗ {item}')
    print(f'документов: {len(targets())}, мёртвых ссылок: {len(BROKEN)}')
    return 1 if BROKEN else 0


if __name__ == '__main__':
    raise SystemExit(main())
