"""Архитектурные регрессии: служебные обходы ACL нельзя возвращать в HTTP-контроллеры."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
MODULES = [ROOT / 'application/modules' / name for name in (
    'prx390_intake', 'prx391_documents', 'prx392_analysis', 'prx393_workflow')]


TRUSTED_CALL = r'::(document_for_processing|open_ids_for_processing|headers_for_processing|[A-Za-z_]+_automatically)\s*\('


def table_references(source):
    """Лексический ratchet известных таблиц; не SQL/PHP-парсер и не security boundary."""
    return re.findall(
        r'\b(?:FROM|JOIN|UPDATE|INTO|TABLE)\s+(?:"?[a-zA-Z_][a-zA-Z0-9_]*"?\.)?'
        r'"?(prx_\d+_[a-zA-Z0-9_]+|roles_users|users|roles)\b', source, re.I)


class DocflowAccessBoundaryTest(unittest.TestCase):
    def test_http_never_calls_automation_entry_points(self):
        for module in MODULES:
            for path in (module / 'classes/Controller').rglob('*.php'):
                with self.subTest(path=path):
                    self.assertNotRegex(path.read_text(encoding='utf-8'), r'::(?:document_for_processing|open_ids_for_processing|headers_for_processing|[A-Za-z_]+_automatically)\s*\(')

    def test_actor_arrays_and_system_flags_are_not_service_contracts(self):
        for module in MODULES:
            for path in (module / 'classes').rglob('*.php'):
                with self.subTest(path=path):
                    source = path.read_text(encoding='utf-8')
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
            if re.search(r'::document_for_processing\s*\(', path.read_text(encoding='utf-8')):
                relative = path.relative_to(ROOT / 'application/modules').as_posix()
                self.assertIn(relative, allowed)

    def test_cron_and_events_use_explicit_automation(self):
        scan = MODULES[0] / 'classes/Task/Prx390/Scan.php'
        self.assertIn('::scan_folder_automatically(', scan.read_text(encoding='utf-8'))
        events = (MODULES[3] / 'init.php').read_text(encoding='utf-8')
        self.assertEqual(events.count('::route_automatically('), 2)
        self.assertNotIn('is_system', events)
        self.assertNotIn("$payload['actor_id']", events)

    def test_docflow_sql_references_only_owner_tables(self):
        for module in MODULES:
            owner = module.name[3:6]
            for path in list((module / 'classes').rglob('*.php')) + list((module / 'views').rglob('*.php')):
                with self.subTest(path=path):
                    refs = table_references(path.read_text(encoding='utf-8'))
                    self.assertTrue(all(table.startswith(f'prx_{owner}_') for table in refs), refs)

    def test_table_guard_handles_quoted_schema_and_write_statements(self):
        self.assertEqual(table_references('SELECT * FROM "public"."prx_391_documents" JOIN roles_users r ON true'),
                         ['prx_391_documents', 'roles_users'])
        self.assertEqual(table_references('UPDATE prx_392_runs SET status=1; INSERT INTO prx_393_rules VALUES(1)'),
                         ['prx_392_runs', 'prx_393_rules'])
        self.assertEqual(table_references('EXTRACT(EPOCH FROM now()); ON CONFLICT DO UPDATE SET status=1'), [])

    def test_views_never_call_processing_or_automation(self):
        for module in MODULES:
            for path in (module / 'views').rglob('*.php'):
                with self.subTest(path=path):
                    self.assertNotRegex(path.read_text(encoding='utf-8'), TRUSTED_CALL)

    def test_tasks_have_only_declared_automation_calls(self):
        allowed = {'prx390_intake/classes/Task/Prx390/Scan.php': ['scan_folder_automatically']}
        for module in MODULES:
            for path in (module / 'classes/Task').rglob('*.php'):
                relative = path.relative_to(ROOT / 'application/modules').as_posix()
                with self.subTest(path=path):
                    self.assertEqual(re.findall(TRUSTED_CALL, path.read_text(encoding='utf-8')),
                                     allowed.get(relative, []))

    def test_batch_processing_reads_have_only_declared_callers(self):
        allowed = {
            'open_ids_for_processing': {
                'prx391_documents/classes/Service/Prx391/Documents.php',
                'prx393_workflow/classes/Service/Prx393/Workflow.php',
            },
            'headers_for_processing': {
                'prx391_documents/classes/Service/Prx391/Documents.php',
                'prx392_analysis/classes/Service/Prx392/Runs.php',
            },
        }
        for path in (ROOT / 'application').rglob('*.php'):
            source = path.read_text(encoding='utf-8')
            for method, callers in allowed.items():
                if re.search(r'::' + method + r'\s*\(', source):
                    self.assertIn(path.relative_to(ROOT / 'application/modules').as_posix(), callers)
