"""Архитектурные регрессии: служебные обходы ACL нельзя возвращать в HTTP-контроллеры."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MODULES = [ROOT / 'application/modules' / name for name in (
    'prx390_intake', 'prx391_documents', 'prx392_analysis', 'prx393_workflow')]


class DocflowAccessBoundaryTest(unittest.TestCase):
    def test_http_never_calls_automation_entry_points(self):
        for module in MODULES:
            for path in (module / 'classes/Controller').rglob('*.php'):
                with self.subTest(path=path):
                    self.assertNotRegex(path.read_text(), r'::(?:document_for_processing|route_automatically|scan_folder_automatically)\s*\(')

    def test_actor_arrays_and_system_flags_are_not_service_contracts(self):
        for module in MODULES:
            for path in (module / 'classes').rglob('*.php'):
                with self.subTest(path=path):
                    source = path.read_text()
                    self.assertNotRegex(source, r'array\s+\$actor\b|\bis_system\b|\b_system_actor\s*\(')
                    self.assertNotIn('Prx_Actor::fromArray', source)
                    self.assertNotIn('$this->_actor()', source)

    def test_processing_reads_have_only_explicit_service_callers(self):
        allowed = {
            'prx391_documents/classes/Service/Prx391/Documents.php',
            'prx392_analysis/classes/Service/Prx392/Analysis.php',
            'prx393_workflow/classes/Service/Prx393/Workflow.php',
        }
        for path in (ROOT / 'application').rglob('*.php'):
            if re.search(r'::document_for_processing\s*\(', path.read_text()):
                relative = path.relative_to(ROOT / 'application/modules').as_posix()
                self.assertIn(relative, allowed)

    def test_cron_and_events_use_explicit_automation(self):
        scan = MODULES[0] / 'classes/Task/Prx390/Scan.php'
        self.assertIn('::scan_folder_automatically(', scan.read_text())
        events = (MODULES[3] / 'init.php').read_text()
        self.assertEqual(events.count('::route_automatically('), 2)
        self.assertNotIn('is_system', events)
        self.assertNotIn("$payload['actor_id']", events)
