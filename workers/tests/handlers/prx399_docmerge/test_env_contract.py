"""Синхронизация лимитов prx399: .env.example ↔ limits() ↔ диапазоны."""
import re
from pathlib import Path

from prx_workers.handlers.prx399_docmerge.limits import limits

# Контракт лимитов воркера живёт в workers/.env.example (рядом с кодом воркера).
WORKERS_EXAMPLE = next(
    path for path in Path(__file__).resolve().parents if (path / "workers/.env.example").is_file()
) / "workers/.env.example"


def documented_limits():
    return dict(re.findall(r"^# (PRX399_[A-Z_]+)=([0-9]+)$", WORKERS_EXAMPLE.read_text(), re.M))


def test_documented_env_limits_match_worker_defaults():
    documented = documented_limits()
    assert len(documented) == 5
    live = limits()
    mapping = {
        "PRX399_MAX_PDF_BYTES": "max_pdf_bytes",
        "PRX399_MAX_PAGES": "max_pages",
        "PRX399_MAX_TEXT_BYTES": "max_text_bytes",
        "PRX399_MAX_CHUNK_CHARS": "max_chunk_chars",
        "PRX399_TASK_TIMEOUT_SEC": "task_timeout_sec",
    }
    for name, key in mapping.items():
        assert live[key] == int(documented[name]), name


def test_bad_env_values_fall_back_to_defaults(monkeypatch):
    monkeypatch.setenv("PRX399_MAX_PAGES", "abc")
    monkeypatch.setenv("PRX399_MAX_CHUNK_CHARS", "1")  # ниже минимума
    live = limits()
    assert live["max_pages"] == 300
    assert live["max_chunk_chars"] == 12000
