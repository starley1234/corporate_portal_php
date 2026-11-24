"""Отчёты Engine — одна read-only обязанность, без обратного вызова фасада."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SERVICE = ROOT / 'application/modules/prx597_notifications/classes/Service/Prx597'
METHODS = ('journal', 'queue', 'waiting_reason', 'recent_sent', 'recent_inbox', 'stats', 'dead', 'feed_events')


class NotificationReportsBoundaryTest(unittest.TestCase):
    def test_reports_have_no_mutations_delivery_or_facade_calls(self):
        source = (SERVICE / 'EngineReports.php').read_text(encoding='utf-8')
        self.assertNotRegex(source, r'Service_Prx597_(?:Engine|Notify)\s*::')
        self.assertNotRegex(source, r'\b(?:INSERT\s+INTO|UPDATE\s+"?prx_|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE|FOR\s+UPDATE)\b')
        self.assertNotRegex(source, r'::(?:send|deliver|ensure_tables|lock|unlock)\s*\(')
        self.assertNotIn('use Service_', source)

    def test_engine_keeps_compatible_delegating_report_methods(self):
        source = (SERVICE / 'Engine.php').read_text(encoding='utf-8')
        for method in METHODS:
            with self.subTest(method=method):
                match = re.search(r'function ' + method + r'\([^\n]*\)\s*\{([^}]+)\}', source)
                self.assertIsNotNone(match)
                self.assertIn('return Service_Prx597_EngineReports::' + method + '(', match.group(1))
                self.assertNotRegex(match.group(1), r'\b(?:SELECT|INSERT|UPDATE|DELETE)\b')
