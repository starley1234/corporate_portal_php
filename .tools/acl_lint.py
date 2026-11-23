#!/usr/bin/env python3
"""ACL: каждый ключ права объявлен в config/acl.php и хотя бы раз используется.

Deny by default работает только тогда, когда ключи — реестр, а не строковые
константы в коде. Проверяем:

  использование — `can('key')`, `->can('key')`, `ACL::check(..., 'key')`,
                  `'acl' => 'key'` в манифестах и пунктах меню;
  объявление    — `application/config/acl.php`.

Ошибки: используемый ключ не объявлен (кроме `all` и констант-выражений).
Предупреждения: объявленный ключ нигде не используется.

Запуск: python3 .tools/acl_lint.py
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from php_literal import read_config  # noqa: E402

ROOT = Path(__file__).resolve().parent.parent
SCAN = [ROOT / 'application/classes', ROOT / 'application/modules']

KEY = r"[a-z][a-z0-9_]*\.[a-z0-9_*]+(?:\.[a-z0-9_*]+)*"
CALL = re.compile(rf"""\b(?:can|ACL::check)\s*\(\s*(?:[^,()]|\([^()]*\))*?,\s*(['"])({KEY})\1""")
CAN_ONE = re.compile(rf"""\bcan\s*\(\s*(['"])({KEY})\1""")
ARRAY_ACL = re.compile(rf"""['"]acl['"]\s*=>\s*(['"])({KEY})\1""")

ERRORS: list[str] = []
WARNINGS: list[str] = []


def declared() -> set[str]:
    return {str(key) for key in read_config(ROOT / 'application/config/acl.php')}


def used() -> dict[str, set[str]]:
    found: dict[str, set[str]] = {}

    def add(key: str, path: Path) -> None:
        if key == 'all' or key.endswith('.*'):
            return
        found.setdefault(key, set()).add(str(path.relative_to(ROOT)))

    for base in SCAN:
        for path in base.rglob('*.php'):
            if path.name == 'prx212_storage.php':
                continue  # 'acl' => 'private' — ACL объекта S3, а не право
            source = path.read_text(encoding='utf-8', errors='ignore')
            source = re.sub(r'/\*.*?\*/', '', source, flags=re.S)
            for pattern in (CALL, CAN_ONE, ARRAY_ACL):
                for match in pattern.finditer(source):
                    add(match.group(2), path)

    return found


def main() -> int:
    keys = declared()
    usage = used()

    for key in sorted(usage):
        if key in keys:
            continue
        module = key.split('.', 1)[0]
        if f'{module}.*' in keys:
            continue
        where = ', '.join(sorted(usage[key])[:2])
        ERRORS.append(f'ключ не объявлен в acl.php: {key} ({where})')

    for key in sorted(keys):
        if key.endswith('.*'):
            continue
        if key not in usage:
            WARNINGS.append(f'ключ объявлен, но не используется: {key}')

    for error in ERRORS:
        print(f'  ✗ {error}')
    for warning in WARNINGS:
        print(f'  ! {warning}')
    print(f'объявлено: {len(keys)}, используется: {len(usage)}, '
          f'ошибок: {len(ERRORS)}, предупреждений: {len(WARNINGS)}')

    return 1 if ERRORS else 0


if __name__ == '__main__':
    raise SystemExit(main())
