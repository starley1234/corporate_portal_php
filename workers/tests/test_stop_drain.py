"""Остановка воркера: незавершённые задачи получают понятный финал сразу.

Владелец, 26.09 (/leads): «запустил — не отработало», этапы застыли на «ИИ
извлекает компании источников: 6», а через ~33 минуты задача сама перешла в
«Ошибку» со словами «Воркер перестал подавать признаки жизни — задача отказана
(попытки исчерпаны)». Так выглядит рестарт (обновление сайта, OOM, деплой):
процесс умирает, строка в очереди остаётся processing, и закрывает её только
сайтовый свипер по мёртвому пульсу.

Правильное поведение: воркер при остановке ждёт текущие задачи ограниченное
время (N2J_STOP_GRACE_SEC), а не успевшие — закрывает САМ, с понятной причиной.
"""
import asyncio
import time

import pytest

from n2j_workers import base


class _Cfg:
    worker_name = "w-test"
    channels = ["slow"]
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
    """Очередь-заглушка: одна долгая задача, дальше пусто."""

    def __init__(self, timeout_sec: int = 900):
        self.handler_pool = None
        self._timeout = timeout_sec
        self.claimed = False
        self.failed = []
        self.blocking_fails = []
        self.completed = []
        self.released = 0

    async def connect(self):
        return True

    async def claim(self, channels, worker):
        if not self.claimed:
            self.claimed = True
            return {"id": 606, "channel": "slow", "payload": {}, "timeout_sec": self._timeout,
                    "attempts": 1, "max_attempts": 1}
        return None

    async def wait_for_wake(self, seconds):
        await asyncio.sleep(min(0.1, seconds))

    def wake(self):
        return None

    async def heartbeat(self, job_id, worker, note=None):
        return True

    async def progress(self, job_id, worker, note):
        return True

    async def complete(self, job_id, worker, result=None, usage=None):
        self.completed.append(job_id)
        return True

    async def fail(self, job_id, worker, error, retry=True, usage=None):
        self.failed.append((job_id, error, retry))
        return True

    def fail_blocking(self, job_id, worker, error):
        self.blocking_fails.append((job_id, error))
        return True

    async def release_own_orphans(self, worker):
        self.released += 1
        return 0

    async def close(self):
        return None


async def _slow(ctx, payload):
    """Обработчик, который не успевает за grace-секунды остановки."""
    await asyncio.sleep(600)


async def _quick(ctx, payload):
    return {"ok": True}


def _prep(monkeypatch, grace: str = "1"):
    monkeypatch.setenv("N2J_STOP_GRACE_SEC", grace)
    monkeypatch.setenv("N2J_RELEASE_ORPHANS", "1")
    monkeypatch.setenv("N2J_HARD_WATCHDOG_SEC", "3600")  # сторож тут не при чём
    monkeypatch.setattr(base.BaseWorker, "_install_signal_handlers", lambda self: None)


async def _run_until_inflight(worker, run_task, deadline_sec: float = 10.0):
    """Дождаться, пока задача попала в работу."""
    deadline = time.monotonic() + deadline_sec

    while time.monotonic() < deadline:
        if worker._current_jobs:
            return True
        if run_task.done():
            return False
        await asyncio.sleep(0.05)

    return False


@pytest.mark.asyncio
async def test_interrupted_job_gets_clear_final_on_stop(monkeypatch):
    """Задача, не успевшая при остановке, закрывается с понятной причиной."""
    _prep(monkeypatch)

    queue = _Queue()
    worker = base.BaseWorker(_Cfg(), queue, {"slow": _slow})

    run_task = asyncio.create_task(worker.run())

    assert await _run_until_inflight(worker, run_task), "задача не попала в работу"

    worker._stopping = True
    worker.queue.wake()

    await asyncio.wait_for(run_task, timeout=20)

    assert queue.failed, ("оборванная остановкой задача осталась processing — "
                          "владелец увидит «Выполняется» ещё полчаса")
    job_id, error, retry = queue.failed[0]
    assert job_id == 606
    assert "остановлен" in error and "заново" in error
    assert retry is False, "прерванную задачу нельзя выдавать за выполненную"


@pytest.mark.asyncio
async def test_finished_job_is_not_failed_on_stop(monkeypatch):
    """Быстрая задача при остановке закрывается как выполненная, а не как сбой."""
    _prep(monkeypatch)

    queue = _Queue()
    worker = base.BaseWorker(_Cfg(), queue, {"slow": _quick})

    run_task = asyncio.create_task(worker.run())

    deadline = time.monotonic() + 10

    while time.monotonic() < deadline and not queue.completed:
        await asyncio.sleep(0.05)

    worker._stopping = True
    worker.queue.wake()
    await asyncio.wait_for(run_task, timeout=20)

    assert queue.completed == [606]
    assert queue.failed == []
