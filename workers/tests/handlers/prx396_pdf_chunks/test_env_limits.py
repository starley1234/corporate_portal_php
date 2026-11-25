"""Синхронизация лимитов prx396: .env.example ↔ limits() ↔ диапазоны."""

import re
from pathlib import Path

import pytest

from prx_workers.handlers.prx396_pdf_chunks.limits import limits

ROOT = next(path for path in Path(__file__).resolve().parents if (path / "application").is_dir())


def documented_limits():
    return dict(re.findall(r"^# (PRX396_[A-Z_]+)=([0-9]+)$", (ROOT / ".env.example").read_text(), re.M))


def _expected():
    expected = {}
    for name, value in documented_limits().items():
        key = name.removeprefix("PRX396_").lower()
        # PRX396_TTL_HOURS — основное имя, PRX396_RESULT_TTL_HOURS — алиас.
        if key in ("ttl_hours", "result_ttl_hours"):
            key = "result_ttl_hours"
        expected[key] = int(value)
    return expected


def test_worker_defaults_match_shared_env_example(monkeypatch):
    documented = documented_limits()
    assert len(documented) == 10
    for name in documented:
        monkeypatch.delenv(name, raising=False)
    assert limits() == _expected()


def test_documented_keys_are_readable_by_worker():
    # Каждый задокументированный ключ имеет одноимённый (или алиасный)
    # читаемый limits() ключ — иначе настройка в .env молча не работает.
    assert set(_expected()) <= set(limits())


@pytest.mark.parametrize("bad", ["0", "2001", "1.5", "01", "99999999999999999999"])
def test_invalid_or_out_of_range_values_fall_back(monkeypatch, bad):
    monkeypatch.setenv("PRX396_MAX_PAGES", bad)
    assert limits()["max_pages"] == 500


def test_whitespace_value_is_trimmed(monkeypatch):
    monkeypatch.setenv("PRX396_MAX_PAGES", " 200 ")
    assert limits()["max_pages"] == 200


def test_probe_timeout_and_memory_defaults(monkeypatch):
    monkeypatch.delenv("PRX396_PROBE_TIMEOUT_SEC", raising=False)
    monkeypatch.delenv("PRX396_PDF_MEMORY_MB", raising=False)
    assert limits()["probe_timeout_sec"] == 60
    assert limits()["pdf_memory_mb"] == 512  # МиБ
