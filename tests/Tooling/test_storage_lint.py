"""Файлы: доступ только через Storage:: — правило из .tools/storage_lint.py."""

from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("prx_storage_lint", ROOT / ".tools/storage_lint.py")
assert SPEC and SPEC.loader
storage_lint = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(storage_lint)

FACADE = "application/classes/Storage.php"
CANONICAL = "доступ к файлам — только `Storage::` (см. `docs/FOUNDATION.md`)"


class StorageLintTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory(prefix="prx-storage-lint-")
        self.root = Path(self.tmp.name)
        (self.root / "application" / "classes").mkdir(parents=True)
        (self.root / FACADE).write_text(
            "<?php\nfinal class Storage { public static function save(array $f, string $m): int"
            " { return Service_Prx212_Storage::instance()->save($f, ['module' => $m]); } }\n",
            encoding="utf-8",
        )

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def snippet(self, name: str, code: str) -> None:
        (self.root / "application" / name).parent.mkdir(parents=True, exist_ok=True)
        (self.root / "application" / name).write_text(code, encoding="utf-8")

    def test_facade_and_owner_module_may_use_prx212(self) -> None:
        self.snippet("modules/prx212_file_storage/classes/Controller/Prx212/Api.php",
                     "<?php\n$s = Service_Prx212_Storage::instance(); $f = ORM::factory('Prx212_File', 1);\n")
        self.snippet("classes/Storage/Exception.php", "<?php\nclass Storage_Exception extends RuntimeException {}\n")
        self.assertEqual([], storage_lint.errors(self.root))

    def test_prx212_internals_outside_module_are_rejected_with_canonical_hint(self) -> None:
        self.snippet("modules/prx390_intake/classes/Service.php",
                     "<?php\n// line 2\nService_Prx212_Storage::instance()->getFilePath($id);\n"
                     "$m = ORM::factory('Prx212_File', $id);\n$x = new Model_Prx212_File;\n")
        found = storage_lint.errors(self.root)
        self.assertEqual(3, len(found), found)
        for item in found:
            self.assertTrue(item.startswith("application/modules/prx390_intake/classes/Service.php:"), item)
            self.assertIn(CANONICAL, item)
        self.assertIn("Service_Prx212_", found[0])
        self.assertIn("ORM::factory('Prx212", found[1])
        self.assertIn("Model_Prx212_", found[2])
        self.assertTrue(found[0].startswith("application/modules/prx390_intake/classes/Service.php:3:"), found[0])

    def test_facade_must_not_read_auth_session(self) -> None:
        facade = self.root / FACADE
        facade.write_text(facade.read_text(encoding="utf-8")
                          + "Auth::instance()->get_user();\n", encoding="utf-8")
        found = storage_lint.errors(self.root)
        self.assertEqual(1, len(found), found)
        self.assertTrue(found[0].startswith(FACADE + ":3:"), found[0])
        self.assertIn("Auth", found[0])
        self.assertIn("$user_id", found[0])

    def test_repository_uses_storage_facade_only(self) -> None:
        self.assertEqual([], storage_lint.errors(ROOT))


if __name__ == "__main__":
    unittest.main()
