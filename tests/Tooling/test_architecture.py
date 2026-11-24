"""Небольшие регрессии структуры и границ общего HTTP-контроллера."""
import importlib.util
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / '.tools'))
SPEC = importlib.util.spec_from_file_location('prx_context_pack', ROOT / '.tools/context_pack.py')
context = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(context)


class ArchitectureTest(unittest.TestCase):
    def test_tools_do_not_contain_php_test_stands(self):
        self.assertEqual(list((ROOT / '.tools').glob('*_stand.php')), [])
        self.assertEqual(list((ROOT / '.tools').glob('*_cases.php')), [])

    def test_common_http_controller_does_not_run_queue_maintenance(self):
        source = (ROOT / 'application/modules/prx102_core/classes/Controller/Prx102/Base.php').read_text()
        self.assertNotIn('Service_Prx120_JobQueue', source)
        self.assertNotIn('action_update_declared_classes_cache', source)

    def test_semantic_status_does_not_run_maintenance(self):
        source = (ROOT / 'application/modules/prx115_semantic/classes/Controller/Prx115/Api.php').read_text()
        method = source.split('public function action_status()', 1)[1].split('public function action_retry()', 1)[0]
        self.assertNotIn('maybe_maintain(', method)
        self.assertIn("last_status('semantic.index')", method)

    def test_context_pack_finds_contracts_but_not_included_fixtures(self):
        coverage = context.checks('prx597_notifications')
        self.assertIn('tests/Contracts/prx597_engine.php', coverage['stands'])
        self.assertFalse(any('/fixtures/' in path for path in coverage['stands']))
        self.assertTrue(all((ROOT / path).is_file() for path in coverage['stands']))

    def test_context_pack_finds_nested_worker_tests(self):
        coverage = context.checks('prx395_document_extract')
        self.assertTrue(coverage['worker_tests'])
        self.assertTrue(all((ROOT / path).is_file() for path in coverage['worker_tests']))

    def test_fallback_shares_bootstrap_with_phpunit(self):
        source = (ROOT / 'tests/Support/mini_runner.php').read_text()
        self.assertIn("require dirname(__DIR__).'/bootstrap.php'", source)
        self.assertNotIn("define('APPPATH'", source)
