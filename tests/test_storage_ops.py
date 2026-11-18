"""Операционные проверки setup и безопасного переноса storage без PHP/БД."""

from __future__ import annotations

import importlib.util
import os
import pwd
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.request
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parent.parent
SPEC = importlib.util.spec_from_file_location("prx_storage_migration", ROOT / "bin/migrate-storage.py")
assert SPEC and SPEC.loader
migration = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = migration
SPEC.loader.exec_module(migration)


class StorageMigrationTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory(prefix="prx-storage-migration-")
        self.root = Path(self.tmp.name)
        self.source = self.root / "public_html" / "storage"
        self.target = self.root / "storage"
        (self.source / "docflow" / "inbox").mkdir(parents=True)

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def test_preflight_and_copy_are_private_idempotent_and_keep_source(self) -> None:
        original = self.source / "docflow" / "inbox" / "document.pdf"
        original.write_bytes(b"example PDF\x00")
        (self.source / "other-module").mkdir()
        (self.source / "other-module" / "data.bin").write_bytes(b"other module")

        plan = migration.make_plan(self.source, self.target)
        self.assertEqual(len(plan.files), 2)
        self.assertFalse(self.target.exists())  # preflight не меняет диск
        self.assertEqual(migration.copy_plan(self.source, self.target, plan), 2)
        self.assertEqual(original.read_bytes(), b"example PDF\x00")
        self.assertEqual((self.target / "docflow/inbox/document.pdf").read_bytes(), original.read_bytes())
        self.assertEqual((self.target / "other-module/data.bin").read_bytes(), b"other module")
        self.assertEqual(stat.S_IMODE((self.target / "docflow/inbox").stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((self.target / "docflow/inbox/document.pdf").stat().st_mode), 0o600)

        second = migration.make_plan(self.source, self.target)
        self.assertEqual(second.identical, 2)
        self.assertEqual(migration.copy_plan(self.source, self.target, second), 0)
        self.assertTrue(original.exists())

    def test_cli_preflight_apply_and_repeat(self) -> None:
        script = self.root / "bin/migrate-storage.py"
        script.parent.mkdir()
        shutil.copy2(ROOT / "bin/migrate-storage.py", script)
        original = self.source / "docflow/inbox/cli.pdf"
        original.write_bytes(b"CLI document")

        def run(*args: str) -> subprocess.CompletedProcess[str]:
            return subprocess.run([sys.executable, str(script), *args], cwd=self.root,
                                  capture_output=True, text=True, check=False)

        result = run()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("только проверка", result.stdout)
        self.assertFalse(self.target.exists())
        result = run("--verify")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Перенос не завершён", result.stderr)
        result = run("--apply")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Скопировано файлов: 1", result.stdout)
        self.assertEqual((self.target / "docflow/inbox/cli.pdf").read_bytes(), b"CLI document")
        self.assertEqual(original.read_bytes(), b"CLI document")
        result = run("--verify")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Все исходные файлы", result.stdout)
        result = run("--apply")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("Скопировано файлов: 0", result.stdout)

    def test_conflict_stops_before_any_copy(self) -> None:
        (self.source / "docflow/inbox/a.pdf").write_bytes(b"old")
        (self.source / "docflow/inbox/b.pdf").write_bytes(b"new file")
        (self.target / "docflow/inbox").mkdir(parents=True)
        conflict = self.target / "docflow/inbox/a.pdf"
        conflict.write_bytes(b"different")

        with self.assertRaisesRegex(migration.MigrationError, "перезапись запрещена"):
            migration.make_plan(self.source, self.target)
        self.assertEqual(conflict.read_bytes(), b"different")
        self.assertFalse((self.target / "docflow/inbox/b.pdf").exists())
        self.assertTrue((self.source / "docflow/inbox/b.pdf").exists())

    def test_source_and_destination_symlinks_are_rejected(self) -> None:
        outside = self.root / "outside"
        outside.mkdir()
        sentinel = outside / "keep"
        sentinel.write_text("untouched")
        source_link = self.source / "docflow/inbox/link"
        source_link.symlink_to(outside, target_is_directory=True)
        with self.assertRaises(migration.MigrationError):
            migration.make_plan(self.source, self.target)
        source_link.unlink()

        self.target.mkdir()
        (self.target / "docflow").symlink_to(outside, target_is_directory=True)
        with self.assertRaises(migration.MigrationError):
            migration.make_plan(self.source, self.target)
        self.assertEqual(sentinel.read_text(), "untouched")

    def test_partial_copy_is_removed_without_deleting_source(self) -> None:
        original = self.source / "docflow/inbox/document.pdf"
        original.write_bytes(b"abcdef")
        plan = migration.make_plan(self.source, self.target)

        def fail_copy(incoming, outgoing, length):
            outgoing.write(b"abc")
            raise OSError("disk full")

        with mock.patch.object(migration.shutil, "copyfileobj", side_effect=fail_copy):
            with self.assertRaisesRegex(OSError, "disk full"):
                migration.copy_plan(self.source, self.target, plan)
        self.assertTrue(original.exists())
        self.assertFalse((self.target / "docflow/inbox/document.pdf").exists())

    @unittest.skipUnless(hasattr(os, "mkfifo"), "FIFO не поддерживается")
    def test_special_files_are_rejected(self) -> None:
        os.mkfifo(self.source / "docflow/inbox/pipe")
        with self.assertRaises(migration.MigrationError):
            migration.make_plan(self.source, self.target)


class StorageSetupTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory(prefix="prx-storage-setup-")
        self.root = Path(self.tmp.name) / "portal"
        (self.root / "bin").mkdir(parents=True)
        (self.root / "vendor").mkdir()
        (self.root / "vendor/autoload.php").write_text("<?php\n", encoding="utf-8")
        for name in ("setup.sh", "_storage_permissions.sh"):
            shutil.copy2(ROOT / "bin" / name, self.root / "bin" / name)
        shutil.copy2(ROOT / ".env.example", self.root / ".env.example")
        self.env = os.environ.copy()
        self.env["DEPLOY_WEB_USER"] = pwd.getpwuid(os.geteuid()).pw_name

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def run_setup(self) -> subprocess.CompletedProcess[str]:
        return subprocess.run(["bash", str(self.root / "bin/setup.sh")], cwd=self.root,
                              env=self.env, capture_output=True, text=True, check=False)

    def test_fresh_setup_creates_private_directories_and_is_idempotent(self) -> None:
        result = self.run_setup()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse((self.root / "public_html/storage").exists())
        for rel in ("storage", "storage/docflow/inbox", "storage/docflow/processed",
                    "storage/docflow/duplicates", "storage/docflow/error",
                    "storage/prx212_file_storage/files"):
            directory = self.root / rel
            self.assertTrue(directory.is_dir(), rel)
            self.assertEqual(stat.S_IMODE(directory.stat().st_mode), 0o700, rel)
        marker = self.root / "storage/prx212_file_storage/files/keep.txt"
        marker.write_text("keep", encoding="utf-8")
        result = self.run_setup()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(marker.read_text(encoding="utf-8"), "keep")
        self.assertEqual(stat.S_IMODE(marker.stat().st_mode), 0o600)

    def test_setup_preserves_legacy_data_and_does_not_open_its_permissions(self) -> None:
        legacy = self.root / "public_html/storage/docflow/inbox"
        legacy.mkdir(parents=True, mode=0o700)
        marker = legacy / "document.pdf"
        marker.write_bytes(b"legacy")
        marker.chmod(0o600)
        result = self.run_setup()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn("перенос выполняется отдельно", result.stdout)
        self.assertEqual(marker.read_bytes(), b"legacy")
        self.assertEqual(stat.S_IMODE(marker.stat().st_mode), 0o600)
        self.assertFalse((self.root / "storage/docflow/inbox/document.pdf").exists())

    def test_setup_refuses_symlinked_private_child(self) -> None:
        outside = Path(self.tmp.name) / "outside"
        outside.mkdir()
        marker = outside / "keep"
        marker.write_text("untouched", encoding="utf-8")
        (self.root / "storage").mkdir()
        (self.root / "storage/docflow").symlink_to(outside, target_is_directory=True)
        result = self.run_setup()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("symlink", result.stderr)
        self.assertEqual(marker.read_text(encoding="utf-8"), "untouched")
        self.assertFalse((outside / "inbox").exists())


class StorageRouterTest(unittest.TestCase):
    def test_dev_server_blocks_legacy_files_and_routes_download_even_on_collision(self) -> None:
        php = os.environ.get("PHP_BIN") or shutil.which("php")
        if not php:
            self.skipTest("PHP не найден; сценарий встроенного сервера пропущен")
        with tempfile.TemporaryDirectory(prefix="prx-storage-router-") as tmp:
            root = Path(tmp)
            (root / "bin").mkdir()
            (root / "public_html/storage/docflow/inbox").mkdir(parents=True)
            (root / "public_html/storage/get").mkdir(parents=True)
            (root / "public_html/assets").mkdir()
            shutil.copy2(ROOT / "bin/router.php", root / "bin/router.php")
            (root / "public_html/index.php").write_text(
                "<?php echo 'FRONT:'.$_SERVER['REQUEST_URI'];", encoding="utf-8")
            (root / "public_html/storage/docflow/inbox/private.pdf").write_bytes(b"secret")
            (root / "public_html/storage/get/731").write_bytes(b"must not be served")
            (root / "public_html/assets/public.txt").write_bytes(b"asset")

            with socket.socket() as listener:
                listener.bind(("127.0.0.1", 0))
                port = listener.getsockname()[1]
            process = subprocess.Popen(
                [php, "-S", f"127.0.0.1:{port}", "-t", str(root / "public_html"), str(root / "bin/router.php")],
                cwd=root, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            )
            try:
                deadline = time.monotonic() + 10
                while True:
                    if process.poll() is not None:
                        self.fail("Встроенный PHP-сервер завершился до запуска")
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                            break
                    except OSError:
                        if time.monotonic() >= deadline:
                            self.fail("Встроенный PHP-сервер не запустился")
                        time.sleep(0.05)

                def request(path: str) -> tuple[int, bytes]:
                    try:
                        with urllib.request.urlopen(f"http://127.0.0.1:{port}{path}", timeout=5) as response:
                            return response.status, response.read()
                    except urllib.error.HTTPError as error:
                        return error.code, error.read()

                self.assertEqual(request("/storage")[0], 404)
                self.assertEqual(request("/storage/docflow/inbox/private.pdf")[0], 404)
                self.assertEqual(request("/storage/get/731/extra")[0], 404)
                self.assertEqual(request("/storage/get/731"), (200, b"FRONT:/storage/get/731"))
                self.assertEqual(request("/assets/public.txt"), (200, b"asset"))
            finally:
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)


if __name__ == "__main__":
    unittest.main()
