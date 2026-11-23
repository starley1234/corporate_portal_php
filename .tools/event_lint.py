#!/usr/bin/env python3
"""События: единственный вход — Event::publish() (application/classes/Event.php).

Что запрещено в application/**/*.php:
  - устаревшие Event::fire( / Event::queue( и класс Prx_Event (удалены);
  - публикация мимо фасада: Service_Prx208_EventBus::instance()->publish(...)
    и то же через переменную (->publish у *event_bus*). Исключение — сам фасад.

Запуск: python3 .tools/event_lint.py   (вызывается из portal_check.py)
Выход:  0 — чисто, 1 — есть замечания.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CANONICAL = "Event::publish('prxNNN.entity.action', [...], $initiator_id)"
FACADE = Path('application/classes/Event.php')

# (регулярное выражение, чем заменить)
FORBIDDEN = (
    (re.compile(r'Event::fire\('), 'Event::fire удалён'),
    (re.compile(r'Event::queue\('), 'Event::queue удалён'),
    (re.compile(r'\bPrx_Event\b'), 'Prx_Event удалён'),
    (re.compile(r'(?:Service_Prx208_EventBus::instance\(\)|[A-Za-z_]*event_bus[A-Za-z_]*)->publish\(', re.I),
     'публикация мимо фасада'),
)


def errors(root: Path = ROOT) -> list[str]:
    out = []
    for path in sorted((root / 'application').rglob('*.php')):
        rel = path.relative_to(root).as_posix()
        for number, line in enumerate(path.read_text(encoding='utf-8').splitlines(), 1):
            for pattern, why in FORBIDDEN:
                if pattern.search(line):
                    if why == 'публикация мимо фасада' and rel == FACADE.as_posix():
                        continue
                    out.append(f'{rel}:{number}: {why} — публикуйте через {CANONICAL}')
    return out


if __name__ == '__main__':
    found = errors()
    if found:
        print('\n'.join('FAIL: ' + item for item in found))
        sys.exit(1)
    print('События: публикация только через Event::publish()')
