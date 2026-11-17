"""Логи воркера: плоский ротируемый файл в корне воркеров.

Владелец (03.10.2026), после разбора инцидента «фото уходит, результата нет»:
«может, последние логи писать и на стороне воркеров (простой ротируемый лог в
корне воркера, чтобы я тебе его скинул в следующий раз, чтобы не гадать что
произошло)». Здесь закрепляем: файл появляется, пишется, ротируется по размеру,
настраивается через WORKER_LOG_* и НЕ роняет воркер, если каталог недоступен.
"""

from __future__ import annotations

import logging
import importlib
from pathlib import Path

import pytest

import prx_workers.__main__ as entry


@pytest.fixture(autouse=True)
def _clean_logging():
    """Каждый тест — свой набор обработчиков (basicConfig(force=True) в setup)."""
    yield
    logging.shutdown()


def _write_line(text: str = "проверка записи") -> None:
    logging.getLogger("prx.test").info("%s", text)
    for handler in logging.getLogger().handlers:
        handler.flush()


def test_log_file_is_created_and_written(tmp_path, monkeypatch):
    target = tmp_path / "logs" / "prx-worker.log"
    monkeypatch.setenv("WORKER_LOG_FILE", str(target))

    path = entry.setup_logging()
    _write_line("строка для разбора инцидента")

    assert path == str(target)
    text = target.read_text(encoding="utf-8")
    assert "строка для разбора инцидента" in text
    assert "INFO prx.test" in text  # формат: время уровень имя: сообщение


def test_default_path_is_workers_logs(monkeypatch):
    monkeypatch.delenv("WORKER_LOG_FILE", raising=False)

    assert entry._log_file() == entry.WORKERS_ROOT / "logs" / "prx-worker.log"


def test_log_rotates_by_size(tmp_path, monkeypatch):
    target = tmp_path / "prx-worker.log"
    monkeypatch.setenv("WORKER_LOG_FILE", str(target))
    monkeypatch.setenv("WORKER_LOG_MAX_MB", "0.001")   # ~1 КБ на файл
    monkeypatch.setenv("WORKER_LOG_BACKUPS", "2")

    entry.setup_logging()

    for i in range(200):
        _write_line(f"строка номер {i} " + "х" * 40)

    assert target.exists()
    # старые файлы рядом: .1 (и, возможно, .2) — ротация по размеру состоялась
    assert (target.parent / (target.name + ".1")).exists()


def test_log_can_be_disabled(tmp_path, monkeypatch):
    target = tmp_path / "prx-worker.log"
    monkeypatch.setenv("WORKER_LOG_FILE", str(target))
    monkeypatch.setenv("WORKER_LOG_ENABLED", "0")

    path = entry.setup_logging()
    _write_line()

    assert path == ""
    assert not target.exists()


def test_unwritable_dir_does_not_break_the_worker(tmp_path, monkeypatch):
    # «каталог» — это файл: создать его каталогом нельзя, лог обязан сдаться,
    # но воркер продолжить работу (в бою его ведёт systemd → stderr → journalctl)
    blocker = tmp_path / "not-a-dir"
    blocker.write_text("я файл, а не каталог", encoding="utf-8")

    monkeypatch.setenv("WORKER_LOG_FILE", str(blocker / "prx-worker.log"))

    assert entry.setup_logging() == ""
    assert logging.getLogger().handlers  # stderr-обработчик остался


def test_level_from_env(tmp_path, monkeypatch):
    target = tmp_path / "prx-worker.log"
    monkeypatch.setenv("WORKER_LOG_FILE", str(target))
    monkeypatch.setenv("WORKER_LOG_LEVEL", "WARNING")

    entry.setup_logging()
    _write_line("это INFO и его быть не должно")

    assert "INFO" not in target.read_text(encoding="utf-8")


def test_values_from_workers_env_are_picked_up_after_config_load(tmp_path, monkeypatch):
    """WORKER_LOG_FILE может лежать в workers/.env — его читает Config.load().

    Порядок в __main__.main(): сначала Config.load() (он подтягивает .env в
    окружение), потом setup_logging(). Обратный порядок молча терял бы
    настройки лога из .env — а это как раз тот случай, когда «лог не пишется»
    выглядит как «воркер не работает».
    """
    from prx_workers import config as config_module

    target = tmp_path / "from-dotenv.log"
    dotenv = tmp_path / ".env"
    dotenv.write_text(f"WORKER_LOG_FILE={target}\n", encoding="utf-8")
    monkeypatch.delenv("WORKER_LOG_FILE", raising=False)

    config_module._load_dotenv(dotenv)

    assert entry.setup_logging() == str(target)
