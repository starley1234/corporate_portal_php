#!/usr/bin/env python3
"""Файлы: единственный вход — фасад Storage:: (application/classes/Storage.php).

Что запрещено в application/**/*.php вне prx212_file_storage и самого фасада:
  - Service_Prx212_*, Model_Prx212_*, ORM::factory('Prx212…') — внутренности
    модуля хранения; модуль можно заменить, не трогая вызывающие модули;
  - в самом фасаде — Auth::instance(): кто действует, передаётся явно ($user_id).

Запуск: python3 .tools/storage_lint.py   (вызывается из portal_check.py)
Выход:  0 — чисто, 1 — есть замечания.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CANONICAL = 'доступ к файлам — только `Storage::` (см. `docs/FOUNDATION.md`)'
FACADE = 'application/classes/Storage.php'
OWNER = 'application/modules/prx212_file_storage/'

FORBIDDEN = (
    (re.compile(r'\bService_Prx212_'), 'Service_Prx212_* — внутренний сервис prx212'),
    (re.compile(r'\bModel_Prx212_'), 'Model_Prx212_* — модель prx212'),
    (re.compile(r"ORM::factory\(\s*['\"]Prx212"), "ORM::factory('Prx212…') — модель prx212"),
)


def errors(root: Path = ROOT) -> list[str]:
    out = []
    for path in sorted((root / 'application').rglob('*.php')):
        rel = path.relative_to(root).as_posix()
        if rel.startswith(OWNER):
            continue
        for number, line in enumerate(path.read_text(encoding='utf-8').splitlines(), 1):
            if rel == FACADE:
                if 'Auth::instance()' in line:
                    out.append(f'{rel}:{number}: Auth::instance() в фасаде Storage — кто действует, '
                               'передаётся явно ($user_id)')
                continue
            for pattern, why in FORBIDDEN:
                if pattern.search(line):
                    out.append(f'{rel}:{number}: {why} — {CANONICAL}')
    return out


if __name__ == '__main__':
    found = errors()
    if found:
        print('\n'.join('FAIL: ' + item for item in found))
        sys.exit(1)
    print('Файлы: доступ только через Storage::')
