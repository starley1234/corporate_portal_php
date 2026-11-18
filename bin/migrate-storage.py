#!/usr/bin/env python3
"""Безопасно скопировать старый public_html/storage в корневой storage.

По умолчанию только проверяет. --apply копирует, но НИКОГДА не удаляет
источник и не перезаписывает разные файлы. Запускать при остановленных
веб/CLI-писателях и после резервной копии; см. docs/ENVIRONMENT.md.
"""

from __future__ import annotations

import argparse
import hashlib
import os
import shutil
import stat
import sys
from dataclasses import dataclass
from pathlib import Path


class MigrationError(Exception):
    """Данные нельзя перенести без вмешательства оператора."""


@dataclass
class Plan:
    directories: list[Path]
    files: list[Path]
    identical: int


def _lstat(path: Path) -> os.stat_result | None:
    try:
        return path.lstat()
    except FileNotFoundError:
        return None


def _open_read(path: Path):
    # Символические ссылки не являются данными для автоматического переноса.
    fd = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    return os.fdopen(fd, "rb")


def _digest(path: Path) -> bytes:
    digest = hashlib.sha256()
    with _open_read(path) as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.digest()


def _compare_file(source: Path, target: Path) -> bool:
    src_stat, dst_stat = _lstat(source), _lstat(target)
    if src_stat is None or not stat.S_ISREG(src_stat.st_mode):
        raise MigrationError(f"Источник не является обычным файлом: {source}")
    if dst_stat is None:
        return False
    if not stat.S_ISREG(dst_stat.st_mode):
        raise MigrationError(f"Назначение не является обычным файлом: {target}")
    if src_stat.st_size != dst_stat.st_size or _digest(source) != _digest(target):
        raise MigrationError(f"Разные файлы с одинаковым именем, перезапись запрещена: {target}")
    return True


def _directory(path: Path, *, required: bool) -> bool:
    info = _lstat(path)
    if info is None:
        if required:
            raise MigrationError(f"Нет каталога: {path}")
        return False
    if not stat.S_ISDIR(info.st_mode):
        raise MigrationError(f"Ожидается каталог без symlink: {path}")
    return True


def make_plan(source: Path, target: Path) -> Plan:
    """Проверить ВСЁ дерево до записи, не следуя ссылкам и не затирая файлы."""
    _directory(source, required=True)
    target_exists = _directory(target, required=False)
    if target_exists and os.path.samefile(source, target):
        raise MigrationError("Источник и назначение совпадают")
    if target.resolve().is_relative_to(source.resolve()):
        raise MigrationError("Назначение находится внутри исходного каталога")

    directories: list[Path] = []
    files: list[Path] = []
    identical = 0

    def fail_walk(error: OSError) -> None:
        raise error

    for current, names, filenames in os.walk(source, topdown=True, followlinks=False, onerror=fail_walk):
        names.sort()
        filenames.sort()
        current_path = Path(current)
        for name in names:
            src = current_path / name
            rel = src.relative_to(source)
            _directory(src, required=True)  # включает запрет symlink
            _directory(target / rel, required=False)
            directories.append(rel)
        for name in filenames:
            src = current_path / name
            rel = src.relative_to(source)
            if _compare_file(src, target / rel):
                identical += 1
            files.append(rel)

    return Plan(directories, files, identical)


def copy_plan(source: Path, target: Path, plan: Plan) -> int:
    """Добавить недостающие файлы; права новых папок 0700, файлов 0600."""
    _directory(source, required=True)
    _directory(target, required=False)
    target.mkdir(mode=0o700, parents=True, exist_ok=True)
    copied = 0

    for rel in plan.directories:
        destination = target / rel
        _directory(destination, required=False)
        destination.mkdir(mode=0o700, exist_ok=True)

    for rel in plan.files:
        src, dst = source / rel, target / rel
        if _compare_file(src, dst):
            continue

        created = False
        identity = None
        try:
            with _open_read(src) as incoming:
                fd = os.open(dst, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_NOFOLLOW", 0), 0o600)
                created = True
                identity = (os.fstat(fd).st_dev, os.fstat(fd).st_ino)
                with os.fdopen(fd, "wb") as outgoing:
                    shutil.copyfileobj(incoming, outgoing, length=1024 * 1024)
            # Если источник изменился или запись повреждена, копия не считается готовой.
            _compare_file(src, dst)
            copied += 1
        except BaseException:
            # Убираем только созданный нами незавершённый файл, а не чужую замену.
            existing = _lstat(dst) if created else None
            if existing is not None and (existing.st_dev, existing.st_ino) == identity:
                dst.unlink()
            raise

    return copied


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--apply", action="store_true", help="скопировать после проверки; источник остаётся на месте")
    mode.add_argument("--verify", action="store_true", help="проверить, что все файлы и каталоги уже скопированы")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    source, target = root / "public_html" / "storage", root / "storage"

    try:
        if _lstat(source) is None:
            print("Старый public_html/storage отсутствует — перенос не требуется.")
            return 0
        plan = make_plan(source, target)
        missing_files = len(plan.files) - plan.identical
        print(f"Проверено: каталогов {len(plan.directories)}, файлов {len(plan.files)}, "
              f"уже совпадают {plan.identical}, ожидают копирования {missing_files}.")
        if args.verify:
            missing_dirs = (0 if _directory(target, required=False) else 1)
            missing_dirs += sum(not _directory(target / rel, required=False) for rel in plan.directories)
            if missing_files or missing_dirs:
                raise MigrationError(f"Перенос не завершён: файлов {missing_files}, каталогов {missing_dirs}")
            print("Все исходные файлы и каталоги проверены в новом storage.")
            return 0
        if not args.apply:
            print("Это только проверка. Для копирования после остановки записи используйте --apply.")
            return 0
        copied = copy_plan(source, target, plan)
        print(f"Скопировано файлов: {copied}. Источник НЕ удалён; проверьте данные и права.")
        return 0
    except (MigrationError, OSError) as exc:
        print(f"Перенос остановлен: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
