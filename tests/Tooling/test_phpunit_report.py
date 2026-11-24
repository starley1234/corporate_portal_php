"""JUnit-диагностика доступна даже без полного Actions log."""
import importlib.util
import io
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('phpunit_report', ROOT / '.tools/phpunit_report.py')
reporter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reporter)


class PhpunitReportTest(unittest.TestCase):
    def report(self, xml):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'junit.xml'
            if xml is not None:
                path.write_text(xml)
            output = io.StringIO()
            with redirect_stdout(output):
                status = reporter.report(path)
            return status, output.getvalue()

    def test_success(self):
        status, output = self.report('<testsuites><testsuite><testcase name="ok"/></testsuite></testsuites>')
        self.assertEqual(status, 0)
        self.assertIn('1 tests, 0 failures/errors', output)
        self.assertNotIn('::error', output)

    def test_skipped_is_counted_but_not_failed(self):
        status, output = self.report('<testsuite><testcase name="optional"><skipped/></testcase></testsuite>')
        self.assertEqual(status, 0)
        self.assertIn('1 skipped', output)

    def test_failure_and_error(self):
        status, output = self.report('<testsuites><testsuite><testcase classname="A" name="b"><failure>bad</failure></testcase><testcase name="c"><error>broken</error></testcase></testsuite></testsuites>')
        self.assertEqual(status, 1)
        self.assertEqual(output.count('::error '), 2)
        self.assertIn('title=A%3A%3Ab::bad', output)

    def test_command_injection_escaped(self):
        status, output = self.report('<testsuites><testcase name="a,b&#10;::notice::bad"><failure>first\n::notice::bad%0A</failure></testcase></testsuites>')
        self.assertEqual(status, 1)
        self.assertIn('a%2Cb%0A', output)
        self.assertIn('first%0A::notice::bad%250A', output)
        self.assertNotIn('\n::notice::bad', output)

    def test_source_location(self):
        file = ROOT / 'tests/Smoke/EmployeeDirectoryTest.php'
        status, output = self.report(f'<testsuite><testcase file="{file}" line="12"><failure>x</failure></testcase></testsuite>')
        self.assertEqual(status, 1)
        self.assertIn('file=tests/Smoke/EmployeeDirectoryTest.php,line=12', output)

    def test_outside_paths_and_invalid_lines_not_emitted(self):
        _, output = self.report('<testsuite><testcase file="/outside/file" line="x"><error>x</error></testcase></testsuite>')
        self.assertNotIn('file=', output)
        self.assertNotIn('line=', output)

    def test_process_output_is_bounded_and_escaped(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'phpunit.log'
            path.write_text('x' * 15000 + '\n::notice::injected%')
            output = io.StringIO()
            with redirect_stdout(output):
                status = reporter.report_output(path)
            self.assertEqual(status, 0)
            self.assertIn('%0A::notice::injected%25', output.getvalue())
            self.assertNotIn('\n::notice', output.getvalue())
            self.assertLess(len(output.getvalue()), 12200)

    def test_missing_malformed_and_empty_fail(self):
        for xml in [None, '<broken', '<testsuites/>']:
            with self.subTest(xml=xml):
                status, output = self.report(xml)
                self.assertEqual(status, 1)
                self.assertIn('::error', output)
