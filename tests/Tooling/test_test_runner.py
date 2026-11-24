"""Контракт bin/test без PHP: подменяется только исполняемый файл интерпретатора."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class TestRunnerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        (self.root / 'bin').mkdir()
        (self.root / 'tests/Contracts/fixtures').mkdir(parents=True)
        for name in ('test', '_env.sh'):
            shutil.copy2(ROOT / 'bin' / name, self.root / 'bin' / name)
        self.log = self.root / 'calls.log'
        self.php = self.root / 'fake-php'
        self.php.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$CALLS"\n'
                            'case "$1" in *broken.php) exit 7;; esac\n')
        self.php.chmod(0o755)
        self.env = {**os.environ, 'PHP_BIN': str(self.php), 'CALLS': str(self.log)}

    def run_tests(self, *args):
        return subprocess.run(['bash', 'bin/test', *args], cwd=self.root,
                              env=self.env, text=True, capture_output=True)

    def test_contracts_are_separate_processes_and_skip_fixtures(self):
        for name in ('alpha.php', 'beta.php', 'fixtures/helper.php'):
            (self.root / 'tests/Contracts' / name).touch()
        result = self.run_tests('--contracts')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.log.read_text().splitlines(), [
            'tests/Contracts/alpha.php', 'tests/Contracts/beta.php'])

    def test_failed_contract_fails_suite_but_does_not_hide_later_tests(self):
        for name in ('broken.php', 'later.php'):
            (self.root / 'tests/Contracts' / name).touch()
        result = self.run_tests('--contracts')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('tests/Contracts/later.php', self.log.read_text())

    def test_contracts_are_not_intercepted_by_phpunit(self):
        (self.root / 'vendor/bin').mkdir(parents=True)
        (self.root / 'vendor/bin/phpunit').touch()
        (self.root / 'tests/Contracts/alpha.php').touch()
        result = self.run_tests('--contracts')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('phpunit', self.log.read_text())

    def test_default_passes_filter_to_phpunit(self):
        (self.root / 'vendor/bin').mkdir(parents=True)
        (self.root / 'vendor/bin/phpunit').touch()
        result = self.run_tests('--filter', 'PrxFoundation')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.log.read_text().strip(),
                         'vendor/bin/phpunit -c phpunit.xml.dist --filter PrxFoundation')

    def test_fallback_uses_support_directory(self):
        (self.root / 'tests/Support').mkdir()
        (self.root / 'tests/Support/mini_runner.php').touch()
        result = self.run_tests('PrxFoundation')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.log.read_text().strip(),
                         'tests/Support/mini_runner.php PrxFoundation')

    def test_empty_contract_suite_fails_explicitly(self):
        result = self.run_tests('--contracts')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('PHP-контракты не найдены', result.stderr)
        self.assertFalse(self.log.exists())

    def test_removed_pdf_mode_fails_instead_of_reporting_empty_success(self):
        result = self.run_tests('--pdf')
        self.assertEqual(result.returncode, 2)
        self.assertIn('tests/README.md', result.stderr)
        self.assertFalse(self.log.exists())
