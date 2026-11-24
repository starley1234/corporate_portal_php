"""Контроль границ фасадов и служебных классов."""
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / '.tools'))
spec = importlib.util.spec_from_file_location('classes_lint', ROOT / '.tools/classes_lint.py')
lint = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lint)


class ClassesLintTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        (self.root / 'application/modules').mkdir(parents=True)

    def tearDown(self):
        self.tmp.cleanup()

    def put(self, name, code):
        path = self.root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(code)

    def test_rejects_internal_calls_and_facade_overrides(self):
        self.put('application/modules/prx999_test/classes/Example.php',
                 '<?php Prx_Acl::check([], "x"); Prx_Gate::worker_request($request);\nclass Event {}\nclass Other extends Storage {}')
        result = lint.errors(self.root)
        self.assertEqual(4, len(result), result)

    def test_exceptions_and_comments(self):
        self.put('application/classes/Prx/Actor.php', '<?php Prx_Acl::check([], "x");')
        self.put('application/modules/prx120_jobs/classes/Controller/Prx120/Api.php',
                 '<?php Prx_Gate::worker_request($request); // Prx_Acl::check()')
        self.put('application/classes/Event.php', '<?php class Event {}')
        self.assertEqual([], lint.errors(self.root))

    def test_prx_migrations_are_pg_only_but_libraries_are_exempt(self):
        (self.root / 'application/modules/prx999_test/migrations').mkdir(parents=True)
        (self.root / 'application/modules/llm/migrations').mkdir(parents=True)
        result = lint.errors(self.root)
        self.assertEqual(1, len(result), result)
        self.assertIn('prx999_test/migrations', result[0])

    def test_views_must_match_module_id(self):
        self.put('application/modules/prx301_demo/views/prx301/index.php', "<?php View::factory('partials/page_header');")
        self.put('application/modules/prx301_demo/classes/Page.php', "<?php View::factory('prx301/index');")
        self.assertEqual([], lint.errors(self.root))
        self.put('application/modules/prx301_demo/views/301/index.php', '<?php')
        self.put('application/modules/prx301_demo/classes/Bad.php', '<?php View::factory("prx302/index");')
        result = lint.errors(self.root)
        self.assertEqual(2, len(result), result)
        self.assertTrue(any('prx301/' in item and 'views' in item for item in result))
        self.assertTrue(any('prx302/' in item for item in result))

    def test_libraries_exempt_and_comments_ignored(self):
        self.put('application/modules/llm/views/llm/index.php', "<?php View::factory('llm/example');")
        self.put('application/modules/prx301_demo/classes/Page.php', "<?php // View::factory('301/index');\nView::factory('partials/header');")
        self.assertEqual([], lint.errors(self.root))

    def test_comments_on_same_line_and_strings_ignored(self):
        self.put('application/modules/prx301_demo/classes/Page.php', "<?php View::factory('prx301/index'); // View::factory('302/index');\n$text = \"View::factory('303/index')\";")
        self.assertEqual([], lint.errors(self.root))

    def test_scaffold_templates_use_canonical_view(self):
        source = (ROOT / 'application/modules/prx103_platform/classes/Task/Module/Scaffold.php').read_text(encoding='utf-8')
        self.assertIn("'views/prx__ID__/index.php'", source)
        self.assertIn("View::factory('prx__ID__/index'", source)
        self.assertIn("strtr($template, $replace)", source)
        self.assertIn("strtr($content, $replace)", source)

    def test_repository(self):
        self.assertEqual([], lint.errors(ROOT))


if __name__ == '__main__':
    unittest.main()
