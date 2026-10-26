"""Тесты фиксов надёжности/безопасности воркеров N2J.

Запуск из каталога workers/:
    python3 -m pytest tests/test_worker_fixes.py -q

Покрытие (правки, внесённые в этом спринте):
  * nj639_qc._capture_rtsp — SSRF-запрет адреса ДО запуска ffmpeg;
  * nj639_qc._assert_capture_host — локальные/метаданные адреса закрыты;
  * webread._httpx_get — работает из рабочего потока (asyncio.run);
  * base.BaseWorker._heartbeat_loop — фоновый heartbeat текущей задачи;
  * queue.HttpQueue.complete — проброс usage в http-режиме;
  * config — мусор в env даёт читаемую ошибку, а не ValueError.
"""

import asyncio
import os
import types

import pytest


# --------------------------------------------------------------------------
# nj639_qc: SSRF в RTSP-захвате
# --------------------------------------------------------------------------

def _load_nj639():
    import importlib.util
    import sys

    name = "n2j_workers.handlers.nj639_qc"
    if name in sys.modules:
        return sys.modules[name]

    # база пакета нужна для относительного импорта `from ..base import ...`
    import n2j_workers.base  # noqa: F401

    spec = importlib.util.spec_from_file_location(
        name, os.path.join(os.path.dirname(__file__), "..",
                           "n2j_workers", "handlers", "nj639_qc.py"))
    mod = importlib.util.module_from_spec(spec)
    sys.modules[name] = mod
    spec.loader.exec_module(mod)
    return mod


def test_nj639_rtsp_blocks_loopback_before_ffmpeg(monkeypatch):
    nj639 = _load_nj639()

    called = {"run": False}

    def fake_run(cmd, **kwargs):
        called["run"] = True
        raise AssertionError("ffmpeg не должен запускаться для localhost")

    # subprocess импортируется ЛОКАЛЬНО внутри _capture_rtsp — правим сам модуль
    import subprocess
    monkeypatch.setattr(subprocess, "run", fake_run)

    with pytest.raises(RuntimeError):
        nj639._capture_rtsp({"rtsp_url": "rtsp://127.0.0.1:8554/stream"})

    assert called["run"] is False


def test_nj639_rtsp_blocks_metadata_ip_even_with_lan(monkeypatch):
    nj639 = _load_nj639()

    # метаданные облака запрещены всегда, даже если NJ639_ALLOW_LAN=1
    monkeypatch.setenv("NJ639_ALLOW_LAN", "1")
    with pytest.raises(RuntimeError):
        nj639._assert_capture_host("rtsp://169.254.169.254/latest/meta-data")

    monkeypatch.delenv("NJ639_ALLOW_LAN")


def test_nj639_assert_host_allows_public():
    nj639 = _load_nj639()
    # публичный адрес резолвится и не запрещён (сеть не дергается — getaddrinfo)
    nj639._assert_capture_host("rtsp://example.com:554/stream")


# --------------------------------------------------------------------------
# webread._httpx_get: asyncio.run() в рабочем потоке
# --------------------------------------------------------------------------

def test_webread_httpx_get_works_from_thread(monkeypatch):
    import n2j_workers.webread as webread

    class FakeResponse:
        status_code = 200
        text = "<html>ok</html>"

    class FakeClient:
        def __init__(self, *a, **kw):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, *a):
            return False

        async def get(self, url):
            return FakeResponse()

    monkeypatch.setattr(webread.httpx, "AsyncClient", FakeClient)

    async def main():
        # ровно так fetch_sync вызывает _httpx_get из asyncio.to_thread
        return await asyncio.to_thread(webread._httpx_get, "http://example.com/")

    status, text = asyncio.run(main())
    assert status == 200
    assert "ok" in text


# --------------------------------------------------------------------------
# base: фоновый heartbeat
# --------------------------------------------------------------------------

class _FakeQueue:
    def __init__(self):
        self.heartbeats = []
        self.wake = asyncio.Event

    async def heartbeat(self, job_id, worker):
        self.heartbeats.append((job_id, worker))
        return True


class _FakeConfig:
    worker_name = "test-worker"
    queue_mode = "pg"
    heartbeat_interval = 0.05


def test_base_worker_heartbeat_loop_ticks_all_active_jobs():
    import n2j_workers.base as base

    q = _FakeQueue()
    w = base.BaseWorker.__new__(base.BaseWorker)  # без __init__ (не нужны LLM-клиенты)
    w.config = _FakeConfig()
    w.queue = q
    w._stopping = False
    # реестр активных задач: при конкурентности > 1 пульс идёт КАЖДОЙ задаче
    w._current_jobs = {42: None, 43: None}

    async def main():
        task = asyncio.create_task(w._heartbeat_loop(0.05))
        await asyncio.sleep(0.14)
        w._stopping = True
        try:
            await asyncio.wait_for(task, timeout=1.0)
        except asyncio.CancelledError:
            pass

    asyncio.run(main())
    assert q.heartbeats, "фоновый heartbeat не сработал"
    beats = {jid for jid, _worker in q.heartbeats}
    assert beats >= {42, 43}, "heartbeat должен идти всем активным задачам, а не только одной"
    assert all(worker == "test-worker" for _jid, worker in q.heartbeats)


def test_handler_context_job_is_task_local() -> None:
    """ctx._current_job изолирован per-task (contextvar), не общий слот."""
    import n2j_workers.base as base

    ctx = base.HandlerContext(llm=None, embed=None, config=None)
    # вне обработчика задачи нет
    assert ctx._current_job is None

    async def set_and_sleep(v: int) -> None:
        ctx._current_job = {"id": v}
        await asyncio.sleep(0.05)
        # каждая asyncio-задача всё ещё видит СВОЮ задачу
        assert ctx._current_job == {"id": v}, f"{v}: {ctx._current_job}"

    async def main() -> None:
        await asyncio.gather(set_and_sleep(1), set_and_sleep(2), set_and_sleep(3))

    asyncio.run(main())


# --------------------------------------------------------------------------
# queue.PgQueue: обработчикам — отдельный пул, не служебный пул очереди
# --------------------------------------------------------------------------
def test_pg_queue_exposes_separate_handler_pool() -> None:
    """Очередь отдаёт ОТДЕЛЬНЫЙ handler_pool.

    Корневая причина «задача висит processing при доставленном результате»:
    обработчики (semantic.index держит соединение на время внешнего
    embedding-вызова) делили ЕДИНЫЙ пул с complete()/fail()/heartbeat() и
    блокировали финализацию навсегда. Теперь очереди и обработчикам — разные
    пулы, а операционные acquire() имеют таймаут _ACQUIRE_TIMEOUT.
    """
    from n2j_workers.queue import PgQueue, _ACQUIRE_TIMEOUT

    q = PgQueue.__new__(PgQueue)  # без __init__ и без сети
    q._pool = object()
    q._handler_pool = object()

    assert q.handler_pool is q._handler_pool
    assert q.handler_pool is not q._pool, "обработчики НЕ должны делить пул очереди"
    assert _ACQUIRE_TIMEOUT > 0


# --------------------------------------------------------------------------
# queue.HttpQueue.complete: usage уходит на сайт
# --------------------------------------------------------------------------

def test_http_queue_complete_forwards_usage():
    from n2j_workers.queue import HttpQueue

    q = HttpQueue("https://n2j.ru/nj120/api", "tok")
    seen = {}

    async def fake_post(path, payload):
        seen["path"] = path
        seen["payload"] = payload
        return {"ok": True}

    q._post = fake_post

    ok = asyncio.run(q.complete(7, "worker-1", {"r": 1},
                                usage={"llm_calls": 3}))
    assert ok is True
    assert seen["path"] == "/complete/7"
    assert seen["payload"]["usage"] == {"llm_calls": 3}


# --------------------------------------------------------------------------
# config: читаемая ошибка вместо ValueError
# --------------------------------------------------------------------------

def test_config_bad_int_env_raises_clear_error(monkeypatch):
    import n2j_workers.config as cfg

    monkeypatch.setenv("WORKER_CONCURRENCY", "abc")
    with pytest.raises(RuntimeError) as ei:
        cfg.Config.load()
    assert "WORKER_CONCURRENCY" in str(ei.value)
