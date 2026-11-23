"""Число и slug манифеста совпадают с именем папки."""
import importlib.util
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / '.tools'))
spec = importlib.util.spec_from_file_location('manifest_check', ROOT / '.tools/manifest_check.py')
lint = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lint)


class ManifestNamingTest(unittest.TestCase):
    def check_manifest(self, folder, fields):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / folder / 'config/prx_module.php'
            path.parent.mkdir(parents=True)
            path.write_text("<?php return [" + fields + "];", encoding='utf-8')
            lint.ERRORS.clear()
            lint.check_module(path, {}, set())
            return list(lint.ERRORS)

    def test_exact_id_and_slug_required(self):
        base = "'kind'=>'platform', 'title'=>'x', 'short'=>'x', "
        self.assertEqual([], self.check_manifest('prx102_core', base + "'id'=>102, 'slug'=>'prx102_core'"))
        self.assertTrue(any('id' in e for e in self.check_manifest('prx100_core', base + "'id'=>102, 'slug'=>'prx100_core'")))
        self.assertTrue(any('slug' in e for e in self.check_manifest('prx102_core', base + "'id'=>102, 'slug'=>'core'")))

    def test_libraries_are_prefixless_and_have_no_identity_fields(self):
        base = "'kind'=>'library', 'title'=>'x', 'short'=>'x'"
        self.assertEqual([], self.check_manifest('PostgreSQL_PDO', base))
        self.assertTrue(self.check_manifest('prx102_library', base))
        self.assertTrue(self.check_manifest('llm', base + ", 'id'=>102"))
        self.assertTrue(self.check_manifest('core', "'kind'=>'platform', 'title'=>'x', 'short'=>'x', 'id'=>102, 'slug'=>'core'"))


if __name__ == '__main__':
    unittest.main()
