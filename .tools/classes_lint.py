#!/usr/bin/env python3
"""Границы платформенных классов, фасадов и каталогов миграций."""
from __future__ import annotations

import re
import sys
from pathlib import Path

from php_class_ref_lint import strip_code

ROOT = Path(__file__).resolve().parents[1]
FACADES = {'Event', 'Storage', 'Audit', 'Visitor', 'Manifest'}
# Эти HTTP-точки обязаны проверять токен; прочим модулям Gate недоступен.
GATE_ENDPOINTS = {
    'application/classes/HTTP/Exception/401.php',
    'application/modules/prx115_semantic/classes/Controller/Prx115/Api.php',
    'application/modules/prx120_jobs/classes/Controller/Prx120/Api.php',
    'application/modules/prx390_intake/classes/Controller/Prx39x/Api.php',
    'application/modules/prx395_document_extract/classes/Controller/Prx395/Worker.php',
    'application/modules/prx597_notifications/classes/Controller/Prx597/Api.php',
}
ACL_BASE = 'application/modules/prx102_core/classes/Controller/Prx102/Base.php'


def php_code_mask(source: str) -> str:
    """Скрыть строки, комментарии и heredoc, сохранив позиции кода."""
    pattern = re.compile(r"<<<[ \t]*['\"]?([A-Za-z_]\w*)['\"]?[^\n]*\n.*?^[ \t]*\1[;,]?[^\S\n]*$|/\*.*?\*/|//[^\n]*|\#[^\n]*|'(?:\\.|[^'\\])*'|\"(?:\\.|[^\"\\])*\"|`(?:\\.|[^`\\])*`", re.S | re.M)
    return pattern.sub(lambda m: ''.join('\n' if c == '\n' else ' ' for c in m.group()), source)


def errors(root: Path = ROOT) -> list[str]:
    found = []
    for path in sorted((root / 'application').rglob('*.php')):
        rel = path.relative_to(root).as_posix()
        code = strip_code(path.read_text(encoding='utf-8'))
        for n, line in enumerate(code.splitlines(), 1):
            for kind in ('Acl', 'Gate'):
                if re.search(r'\bPrx_' + kind + r'\s*::', line, re.I):
                    inside = rel.startswith('application/classes/Prx/')
                    allowed = rel == ACL_BASE if kind == 'Acl' else rel in GATE_ENDPOINTS
                    if not inside and not allowed:
                        found.append(f'{rel}:{n}: Prx_{kind} вне разрешённых границ')
            m = re.search(r'\bclass\s+(Event|Storage|Audit|Visitor|Manifest)\b', line, re.I)
            if m:
                canonical = next(name for name in FACADES if name.lower() == m.group(1).lower())
                if rel != f'application/classes/{canonical}.php':
                    found.append(f'{rel}:{n}: повторное определение фасада {canonical}')
            m = re.search(r'\bextends\s+(Event|Storage|Audit|Visitor|Manifest)\b', line, re.I)
            if m:
                found.append(f'{rel}:{n}: переопределение фасада {m.group(1)}')
    modules = root / 'application/modules'
    for module in sorted(modules.glob('prx[0-9]*_*')):
        if module.is_dir():
            for forbidden in module.rglob('migrations'):
                if forbidden.is_dir():
                    found.append(f'{forbidden.relative_to(root)}: миграции модуля размещаются только в migrations_pg/')
            match = re.fullmatch(r'prx([0-9]+)_.+', module.name)
            if not match:
                continue
            expected = 'prx' + match.group(1)
            views = module / 'views'
            if views.is_dir():
                entries = sorted(p.name for p in views.iterdir())
                if entries != [expected] or not (views / expected).is_dir():
                    found.append(f'{views.relative_to(root)}: ожидается только каталог {expected}/, получено {entries}')
            for path in sorted(module.rglob('*.php')):
                rel = path.relative_to(root).as_posix()
                source = path.read_text(encoding='utf-8')
                code_mask = php_code_mask(source)
                pattern = r"\bView\s*::\s*factory\s*\(\s*(['\"])([^'\"/]+)(?:/[^'\"]*)?\1(?=\s*[,\)])"
                for ref in re.finditer(pattern, source, re.I):
                    line = source.count('\n', 0, ref.start()) + 1
                    if not re.match(r'View\s*::\s*factory\s*\(', code_mask[ref.start():], re.I):
                        continue
                    target = ref.group(2)
                    if target == expected or target == 'partials':
                        continue
                    # Авторизация ядра использует общие application/views/auth и emails.
                    if rel == 'application/modules/prx102_core/classes/Controller/Prx102/Auth.php' and target in {'auth', 'emails'}:
                        continue
                    found.append(f'{rel}:{line}: View::factory с {target}/, ожидалось {expected}/ или partials/')
    return found


if __name__ == '__main__':
    messages = errors()
    if messages:
        print('\n'.join('FAIL: ' + message for message in messages))
        sys.exit(1)
    print('Расположение классов, фасадов и миграций: OK')
