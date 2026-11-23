"""Зависшие задачи: воркер не имеет права ни блокировать цикл, ни молчать.

Владелец: «почти всё работает, но некоторые зависают» — в мониторе это
выглядит как «Выполняется, пульс 4 мин» при живом (по журналу) этапе. Причины
зависшая задача снимается СТОРОЖЕВЫМ ПОТОКОМ — вне цикла, поэтому сработает
даже при заморозке → проверяем снятие и запись «failed» синхронным
(неблокирующим цикл) путём, а также освобождение задач прошлого запуска.
"""
import asyncio
import contextlib
import logging
import threading
import time

import pytest

from prx_workers import base


# ---------------------------------------------------------------------------
# 1. Сторожевой таймер (вне event loop)
# ---------------------------------------------------------------------------

class _FakeQueue:
    """Очередь, которая запоминает синхронные провалы из сторожевого потока."""

    def __init__(self):
        self.blocking_fails = []
        self.release_calls = []

    def fail_blocking(self, job_id, worker, error):
        self.blocking_fails.append((job_id, worker, error))
        return True

    async def release_own_orphans(self, worker):
        self.release_calls.append(worker)
        return 3


class _Cfg:
    worker_name = "w1"


def _worker_without_init(queue):
    w = base.BaseWorker.__new__(base.BaseWorker)
    w.config = _Cfg()
    w.queue = queue
    w._stage_of = {}
    w._deadline_of = {}
    w._limit_of = {}
    w._hard_killed = set()
    w._current_jobs = {}
    w._hard_stop = base.threading.Event()
    return w


def test_hard_watchdog_kills_overdue_job(caplog):
    """Задача за лимитом снимается сторожем: «failed» пишется ВНЕ цикла."""
    q = _FakeQueue()
    w = _worker_without_init(q)

    w._deadline_of[42] = time.monotonic() - 1      # срок вышел
    w._limit_of[42] = 600
    w._stage_of[42] = "prx606.leads: ИИ извлекает компании"

    with caplog.at_level(logging.ERROR, logger="prx.worker"):
        assert w.hard_watchdog_pass() == 1

    assert q.blocking_fails, "сторож обязан провалить задачу сам"
    job_id, worker, err = q.blocking_fails[0]
    assert (job_id, worker) == (42, "w1")
    assert "сторожевой таймер" in err
    assert "prx606.leads" in err
    assert "Сторож задач" in caplog.text

    # повторный проход не дублирует запись
    assert w.hard_watchdog_pass() == 0
    assert len(q.blocking_fails) == 1


def test_hard_watchdog_leaves_fresh_jobs_alone():
    q = _FakeQueue()
    w = _worker_without_init(q)
    w._deadline_of[7] = time.monotonic() + 300
    w._limit_of[7] = 600

    assert w.hard_watchdog_pass() == 0
    assert q.blocking_fails == []


@pytest.mark.asyncio
async def test_hard_watchdog_cancels_asyncio_task():
    """Слот воркера освобождается: задача отменяется в своём же loop."""
    q = _FakeQueue()
    w = _worker_without_init(q)
    w._loop = asyncio.get_running_loop()

    started = asyncio.Event()
    cancelled = asyncio.Event()

    async def long_handler():
        started.set()
        try:
            await asyncio.sleep(3600)
        except asyncio.CancelledError:
            cancelled.set()
            raise

    task = asyncio.create_task(long_handler())
    w._current_jobs[5] = task
    w._deadline_of[5] = time.monotonic() - 1
    w._limit_of[5] = 10
    w._stage_of[5] = "semantic.index: Считаю векторы"

    await started.wait()
    assert w.hard_watchdog_pass() == 1

    for _ in range(50):
        if cancelled.is_set():
            break
        await asyncio.sleep(0.02)

    assert cancelled.is_set(), "сторож обязан снять задачу, а не только записать failed"


@pytest.mark.asyncio
async def test_release_orphans_on_start(caplog):
    """Сироты прошлого запуска закрываются при старте (иначе «Выполняется» вечно)."""
    q = _FakeQueue()
    w = _worker_without_init(q)
    w._worker = ""

    with caplog.at_level(logging.WARNING, logger="prx.worker"):
        await w._release_orphans()

    assert q.release_calls == ["w1"]
    assert "прошлого запуска" in caplog.text


@pytest.mark.asyncio
async def test_release_orphans_can_be_disabled(monkeypatch):
    q = _FakeQueue()
    w = _worker_without_init(q)
    monkeypatch.setenv("PRX_RELEASE_ORPHANS", "0")

    await w._release_orphans()

    assert q.release_calls == []


@pytest.mark.asyncio
async def test_lag_watchdog_reports_frozen_loop(caplog, monkeypatch):
    """Заморозка цикла видна в журнале — с названием задач, которые держали слот."""
    # Порог журнала — не меньше 1 с (мелкие подёргивания не считаем заморозкой).
    monkeypatch.setenv("PRX_LOOP_LAG_WARN", "1")

    q = _FakeQueue()
    w = _worker_without_init(q)
    w._stopping = False

    with caplog.at_level(logging.WARNING, logger="prx.worker"):
        task = asyncio.create_task(w._lag_watchdog(step=0.05))
        await asyncio.sleep(0.1)

        w._stage_of[9] = "prx591.profile: Читаю главную страницу"
        w._last_lag_log = 0.0           # без «не спамить» — проверяем саму запись
        time.sleep(1.5)                 # имитация синхронной заморозки цикла
        await asyncio.sleep(0.3)

        task.cancel()
        try:
            await task
        except asyncio.CancelledError:
            pass

    assert "был занят" in caplog.text
    assert "prx591.profile" in caplog.text


# ---------------------------------------------------------------------------
# 2. Очередь: задачи прошлого запуска и синхронный провал сторожа
# ---------------------------------------------------------------------------

def test_http_release_mine_asks_site():
    """HTTP-режим: старт воркера освобождает свои задачи на сайте."""
    from prx_workers.queue import HttpQueue

    q = HttpQueue("https://prx.ru/prx120/api", "tok")
    seen = {}

    async def fake_post(path, payload):
        seen["path"] = path
        seen["payload"] = payload
        return {"ok": True, "released": 2}

    q._post = fake_post

    assert asyncio.run(q.release_own_orphans("worker-1")) == 2
    assert seen["path"] == "/release_mine"
    assert seen["payload"]["worker"] == "worker-1"


def test_http_fail_blocking_uses_sync_client(monkeypatch):
    """Сторож пишет провал СИНХРОННЫМ httpx — цикл воркера тут ни при чём."""
    from prx_workers.queue import HttpQueue

    calls = {}

    class _Resp:
        status_code = 200
        is_error = False

        def json(self):
            return {"ok": True}

    class _Client:
        def __init__(self, timeout=None):
            calls["timeout"] = timeout

        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def post(self, url, json=None, headers=None):
            calls["url"] = url
            calls["json"] = json
            calls["headers"] = headers
            return _Resp()

    import httpx
    monkeypatch.setattr(httpx, "Client", _Client)

    q = HttpQueue("https://prx.ru/prx120/api", "tok")
    ok = q.fail_blocking(42, "worker-1", "не уложилась в 600 с")

    assert ok is True
    assert calls["url"].endswith("/fail/42")
    assert calls["json"]["retry"] is False
    assert "Сторожевой таймер воркера" in calls["json"]["error"]


def test_pg_release_own_orphans_requeues_or_fails():
    """pg-режим: свои processing → pending, а исчерпавшие попытки → failed."""
    from prx_workers.queue import PgQueue

    class _Conn:
        def __init__(self):
            self.executed = []

        async def fetch(self, sql, *args):
            if "status = 'pending'" in sql:
                return [{"id": 11}, {"id": 12}]
            return [{"id": 13}]

        async def execute(self, sql, *args):
            self.executed.append((sql, args))
            return "UPDATE 1"

        async def close(self):
            pass

    class _Pool:
        def __init__(self, conn):
            self._conn = conn

        def acquire(self, timeout=None):
            conn = self._conn

            class _Ctx:
                async def __aenter__(self):
                    return conn

                async def __aexit__(self, *a):
                    return False

            return _Ctx()

    conn = _Conn()
    q = PgQueue.__new__(PgQueue)          # без реального пула
    q._dsn = "postgresql://x"
    q._pool = _Pool(conn)

    released = asyncio.run(q.release_own_orphans("worker-1"))

    assert released == 3, "две вернулись в очередь + одна провалена"
    events = [sql for sql, _ in conn.executed if "prx_120_job_events" in sql]
    assert len(events) == 3


def test_pg_fail_blocking_falls_back_to_asyncpg(monkeypatch):
    """Нет sync-драйвера — сторож всё равно закрывает задачу (свой цикл)."""
    import sys
    from prx_workers.queue import PgQueue

    q = PgQueue.__new__(PgQueue)
    q._dsn = "postgresql://x"

    monkeypatch.setattr(q, "_fail_sync_direct",
                        lambda connect, job_id, error: (_ for _ in ()).throw(RuntimeError("no")))
    called = {}

    def fake_asyncpg_path(job_id, error):
        called["job"] = job_id
        called["error"] = error
        return True

    monkeypatch.setattr(q, "_fail_sync_asyncpg", fake_asyncpg_path)

    # psycopg-модулей в окружении теста нет — селекторы пусты, идём в asyncpg-путь
    monkeypatch.setitem(sys.modules, "psycopg2", None)
    monkeypatch.setitem(sys.modules, "psycopg", None)

    assert q.fail_blocking(7, "w1", "зависла") is True
    assert called["job"] == 7


def test_stalled_loop_restarts_process(monkeypatch):
    """Цикл встал насмерть: задачи закрываются, процесс уходит на рестарт."""
    monkeypatch.setenv("PRX_LOOP_STALL_EXIT", "900")

    q = _FakeQueue()
    w = _worker_without_init(q)
    w._stopping = False
    w.config = _Cfg()
    w._stage_of = {861: "semantic.index: Считаю векторы"}
    w._loop_tick = time.monotonic() - 1000      # цикл молчит 1000 с

    exits = []
    monkeypatch.setattr(base.os, "_exit", lambda code: exits.append(code))

    assert w.stalled_loop_pass() is True
    assert exits == [9], "воркер обязан уйти на рестарт systemd"
    assert q.blocking_fails and q.blocking_fails[0][0] == 861
    assert "заморожен" in q.blocking_fails[0][2]


def test_stalled_loop_ignores_healthy_worker(monkeypatch):
    monkeypatch.setenv("PRX_LOOP_STALL_EXIT", "900")
    q = _FakeQueue()
    w = _worker_without_init(q)
    w._stopping = False
    w._stage_of = {7: "prx602.article: Пишу текст"}
    w._loop_tick = time.monotonic()      # цикл только что тикал

    exits = []
    monkeypatch.setattr(base.os, "_exit", lambda code: exits.append(code))

    assert w.stalled_loop_pass() is False
    assert exits == []
    assert q.blocking_fails == []


def test_stalled_loop_can_be_disabled(monkeypatch):
    monkeypatch.setenv("PRX_LOOP_STALL_EXIT", "0")
    q = _FakeQueue()
    w = _worker_without_init(q)
    w._stopping = False
    w._stage_of = {7: "prx602.article: Пишу текст"}
    w._loop_tick = time.monotonic() - 5000

    exits = []
    monkeypatch.setattr(base.os, "_exit", lambda code: exits.append(code))

    assert w.stalled_loop_pass() is False
    assert exits == []


# ---------------------------------------------------------------------------
# 3. Отмена задачи САЙТОМ (кнопка «Прервать» / авто-стоп / перехват)
# ---------------------------------------------------------------------------

class _HeartbeatQueue:
    """Очередь, которая сначала принимает пульс, потом отказывает."""

    def __init__(self, accept: int = 1):
        self.accept = accept
        self.calls = 0

    async def heartbeat(self, job_id, worker, note=None):
        self.calls += 1
        return self.calls <= self.accept


@pytest.mark.asyncio
async def test_heartbeat_cancels_job_taken_by_site():
    """Два отказа heartbeat подряд → воркер прерывает работу по задаче."""
    q = _HeartbeatQueue(accept=1)
    w = _worker_without_init(q)
    w._stopping = False
    w._current_jobs = {}
    w._site_killed = set()
    w.config.worker_name = "w1"

    started = asyncio.Event()
    cancelled = asyncio.Event()

    async def long_job():
        started.set()
        try:
            await asyncio.sleep(3600)
        except asyncio.CancelledError:
            cancelled.set()
            raise

    task = asyncio.create_task(long_job())
    w._current_jobs[861] = task

    await started.wait()
    hb = asyncio.create_task(w._heartbeat_loop(0.02))

    for _ in range(80):
        if cancelled.is_set():
            break
        await asyncio.sleep(0.02)

    hb.cancel()
    with contextlib.suppress(asyncio.CancelledError):
        await hb
    with contextlib.suppress(asyncio.CancelledError):
        await task

    assert cancelled.is_set(), "задача, снятая на сайте, должна быть прервана"
    assert 861 in w._site_killed
    assert q.calls >= 3, "два отказа подряд — минимальный порог перед отменой"


@pytest.mark.asyncio
async def test_heartbeat_keeps_job_after_single_refusal():
    """Одиночный отказ (гонка) НЕ должен срывать живую задачу."""
    q = _HeartbeatQueue(accept=0)

    # первый вызов — отказ, второй — «принят»: имитируем случайную гонку
    async def flaky(job_id, worker, note=None):
        q.calls += 1
        return q.calls != 1

    q.heartbeat = flaky

    w = _worker_without_init(q)
    w._stopping = False
    w._current_jobs = {}
    w._site_killed = set()
    w.config.worker_name = "w1"

    done = asyncio.Event()

    async def job():
        try:
            await asyncio.sleep(0.15)
        finally:
            done.set()

    task = asyncio.create_task(job())
    w._current_jobs[7] = task

    hb = asyncio.create_task(w._heartbeat_loop(0.02))
    await asyncio.sleep(0.12)
    hb.cancel()
    with contextlib.suppress(asyncio.CancelledError):
        await hb

    assert not task.cancelled(), "одиночный отказ — не повод прерывать работу"
    await task
    assert done.is_set()
