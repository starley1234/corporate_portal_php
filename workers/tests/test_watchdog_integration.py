"""Интеграция: воркер целиком с «зависшим» обработчиком.

Проверяем главный сценарий владельца: задача висит дольше своего лимита —
воркер обязан САМ её закрыть (внятный failed) и освободить слот, а не
показывать «Выполняется» сутки. Обработчик здесь зависает по-настоящему:
бесконечный сон, никаких прогрессов.
"""
import asyncio
import time

import pytest

from n2j_workers import base


class _Cfg:
    worker_name = "w-test"
    channels = ["hang"]
    concurrency = 1
    poll_interval = 0.1
    heartbeat_interval = 0.2
    queue_mode = "http"
    llm_providers = {}
    llm_default_provider = None
    llm_base_url = None
    llm_api_key = None
    llm_model = None
    embed_base_url = None
    embed_api_key = None
    embed_model = None
    embed_dimensions = None


class _Queue:
    """Очередь-заглушка: одна зависшая задача, дальше пусто."""

    def __init__(self):
        self.handler_pool = None
        self.claimed = False
        self.failed = []
        self.blocking_fails = []
        self.blocking_at = None
        self.completed = []
        self.released = 0

    async def connect(self):
        return True

    async def claim(self, channels, worker):
        if not self.claimed:
            self.claimed = True
            return {"id": 55, "channel": "hang", "payload": {}, "timeout_sec": 1,
                    "attempts": 1, "max_attempts": 3}
        return None

    async def wait_for_wake(self, seconds):
        await asyncio.sleep(min(0.1, seconds))

    async def heartbeat(self, job_id, worker, note=None):
        return True

    async def progress(self, job_id, worker, note):
        return True

    async def complete(self, job_id, worker, result=None, usage=None):
        self.completed.append(job_id)
        return True

    async def fail(self, job_id, worker, error, retry=True, usage=None):
        self.failed.append((job_id, error))
        return True

    def fail_blocking(self, job_id, worker, error):
        self.blocking_at = time.monotonic()   # когда именно закрыл сторож
        self.blocking_fails.append((job_id, worker, error))
        return True

    async def release_own_orphans(self, worker):
        self.released += 1
        return 0

    async def close(self):
        return None


async def _hang(ctx, payload):
    await asyncio.sleep(600)            # «обработчик, который завис»


# Момент, когда цикл разморозился после синхронной блокировки (эмулируем
# худший случай: обработчик занял loop целиком, asyncio-таймаут не тикает).
FREEZE = {"unfrozen": None, "failed_before_unfreeze": None}


def _hang_freezing(ctx, payload):
    FREEZE["unfrozen"] = None
    FREEZE["failed_before_unfreeze"] = None
    time.sleep(4.0)                     # СИНХРОННАЯ заморозка event loop
    FREEZE["unfrozen"] = time.monotonic()


@pytest.mark.asyncio
async def test_hard_watchdog_closes_job_while_loop_is_frozen(monkeypatch):
    """Главный сценарий: цикл заморожен, asyncio-таймаут молчит — закрывает сторож."""
    monkeypatch.setenv("N2J_HARD_WATCHDOG_SEC", "1")
    monkeypatch.setenv("N2J_RELEASE_ORPHANS", "1")
    # запас к таймауту — минимальный, чтобы тест не ждал 180 с
    monkeypatch.setattr(base.BaseWorker, "_hard_grace", staticmethod(lambda: 0.5))
    monkeypatch.setattr(base.BaseWorker, "_install_signal_handlers",
                        lambda self: None)   # pytest — не главный поток

    queue = _Queue()
    worker = base.BaseWorker(_Cfg(), queue, {"hang": _hang_freezing})

    run_task = asyncio.create_task(worker.run())

    deadline = time.monotonic() + 15
    while time.monotonic() < deadline and not queue.blocking_fails:
        await asyncio.sleep(0.2)

    assert queue.released == 1, "при старте воркер обязан освободить задачи прошлого запуска"
    assert queue.blocking_fails, ("сторожевой таймер не закрыл зависшую задачу — "
                                 "именно так владелец видел «Выполняется, пульс N мин»")

    job_id, worker_name, error = queue.blocking_fails[0]
    assert job_id == 55
    assert worker_name == "w-test"
    assert "сторожевой таймер" in error.lower()
    assert "hang" in error

    # Сторож — ВНЕ цикла: он закрыл задачу, ПОКА loop был заморожен синхронным
    # обработчиком (asyncio-таймаут в этот момент не тикал в принципе).
    assert queue.blocking_at is not None and FREEZE["unfrozen"] is not None
    assert queue.blocking_at < FREEZE["unfrozen"] - 0.2, (
        "задачу закрыл обычный таймаут, а не сторож — в замороженном цикле "
        "он бы не сработал (blocking_at=%.2f, unfrozen=%.2f)" % (
            queue.blocking_at, FREEZE["unfrozen"]))

    worker._stopping = True
    await asyncio.wait_for(run_task, timeout=10)

    # задача не осталась processing: воркер сам не «дожимал» её complete()
    assert queue.completed == []


@pytest.mark.asyncio
async def test_worker_cancels_hung_task_in_its_loop(monkeypatch):
    """Слот освобождается: само asyncio-задание снимается, а не остаётся висеть."""
    monkeypatch.setenv("N2J_HARD_WATCHDOG_SEC", "1")
    monkeypatch.setattr(base.BaseWorker, "_hard_grace", staticmethod(lambda: 0.5))
    monkeypatch.setattr(base.BaseWorker, "_install_signal_handlers", lambda self: None)

    queue = _Queue()
    worker = base.BaseWorker(_Cfg(), queue, {"hang": _hang})

    run_task = asyncio.create_task(worker.run())

    deadline = time.monotonic() + 12
    while time.monotonic() < deadline and not queue.blocking_fails:
        await asyncio.sleep(0.2)

    # обработчик снят → реестр активных задач пуст, слот свободен
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline and worker._stage_of:
        await asyncio.sleep(0.1)

    assert worker._stage_of == {}, "задача всё ещё держит слот воркера"

    worker._stopping = True
    await asyncio.wait_for(run_task, timeout=10)
