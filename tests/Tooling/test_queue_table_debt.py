"""Замороженный долг, НЕ разрешение платформе читать/менять чужую очередь.

Область: четыре модуля из ревизии. Уменьшение разрешено; новые SQL-ссылки
требуют публичного контракта prx120, а не расширения baseline. Инвентарь и
план переноса — docs/WORKERS.md. Это лексический ratchet, не SQL-парсер.
"""
import re
import unittest
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2] / 'application/modules'
BASELINE = {}  # Все исключения четырёх проверенных модулей устранены.


def queue_references(source):
    return Counter((operation.upper(), table.lower()) for operation, table in re.findall(
        r'\b(FROM|JOIN|UPDATE|INTO|TABLE)\s+(?:"?[a-zA-Z_][a-zA-Z0-9_]*"?\.)?'
        r'"?(prx_120_[a-zA-Z0-9_]+)\b', source, re.I))


class QueueTableDebtTest(unittest.TestCase):
    def test_existing_queue_debt_cannot_expand(self):
        for name in ('prx103_platform', 'prx115_semantic', 'prx595_useradmin', 'prx597_notifications'):
            for path in (ROOT / name).rglob('*.php'):
                relative = path.relative_to(ROOT).as_posix()
                found = queue_references(path.read_text(encoding='utf-8'))
                with self.subTest(path=relative):
                    self.assertFalse(found - Counter(BASELINE.get(relative, {})),
                                     'Новая SQL-зависимость: используйте сервис prx120')

    def test_guard_counts_ddl_writes_and_quoted_reads(self):
        self.assertEqual(queue_references('ALTER TABLE "prx_120_jobs" ADD x int; '
                                         'UPDATE prx_120_jobs SET x=1; '
                                         'SELECT * FROM "public"."prx_120_usage"'),
                         Counter({('TABLE', 'prx_120_jobs'): 1, ('UPDATE', 'prx_120_jobs'): 1,
                                  ('FROM', 'prx_120_usage'): 1}))
