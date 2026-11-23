"""Тесты контракта завершения задачи: queue.complete() больше не игнорируется.

Корневая причина «выполнено, но статус висит processing» (монитор prx120):
воркер звал complete(), получал False (строка вышла из processing — свипер
вернул её в очередь, задача перехвачена/отменена), но _process игнорировал
возврат и логировал «выполнена». Результат на сайт обработчик уже доставил
(образец prx602/prx606), а строка задачи навсегда оставалась processing.

Запуск из каталога workers/:
    python3 -m pytest tests/test_complete_contract.py -q
"""

import asyncio

import pytest


class _FakeQueue:
    """Очередь с управляемым поведением complete()/fail()."""

    def __init__(self):
        self.complete_calls = 0
        self.complete_ok_after = None  # сколько раз вернуть False, потом True
        self.fail_calls = []
        self.progress_calls = []
        self.last_result = None

    async def complete(self, job_id, worker, result=None, usage=None):
        self.complete_calls += 1
        self.last_result = result
        if self.complete_ok_after is not None and self.complete_calls <= self.complete_ok_after:
            return False
        return True

    async def fail(self, job_id, worker, error, retry=True, usage=None):
        self.fail_calls.append((job_id, worker, error, retry))
        return True

    async def progress(self, job_id, worker, note):
        self.progress_calls.append((job_id, worker, note))
        return True


class _FakeConfig:
    worker_name = "test-worker"
    heartbeat_interval = 0.05
    concurrency = 4
    poll_interval = 5.0


def _make_worker(queue):
    import prx_workers.base as base

    w = base.BaseWorker.__new__(base.BaseWorker)  # без __init__: не нужны LLM-клиенты
    w.config = _FakeConfig()
    w.queue = queue
    w.handlers = {"test.channel": _ok_handler}
    w._inflight = 0
    w._stopping = False
    w._tasks = set()
    w._current_jobs = {}
    # минимальный контекст обработчика (ctx._current_job чистится в finally)
    w.ctx = base.HandlerContext(llm=None, embed=None, config=w.config)
    w.ctx._queue = queue
    w.ctx._worker = w.config.worker_name
    return w


async def _ok_handler(ctx, payload):
    return {"ok": True, "artifact": "saved"}


def _job():
    return {"id": 7, "channel": "test.channel", "payload": {},
            "attempts": 1, "max_attempts": 1, "timeout_sec": 60}


def test_process_completes_and_stays_done():
    q = _FakeQueue()
    w = _make_worker(q)

    asyncio.run(w._process(_job()))

    assert q.complete_calls == 1
    assert q.fail_calls == []
    assert w._current_jobs == {}


def test_process_retries_complete_once_then_succeeds():
    q = _FakeQueue()
    q.complete_ok_after = 1  # первый complete() вернёт False
    w = _make_worker(q)

    asyncio.run(w._process(_job()))

    assert q.complete_calls == 2, "complete() должен повториться при False"
    assert q.fail_calls == [], "после успешного повтора fail() не нужен"


def test_process_terminal_failure_when_queue_rejects_complete():
    q = _FakeQueue()
    q.complete_ok_after = 99  # complete() всегда False
    w = _make_worker(q)

    asyncio.run(w._process(_job()))

    assert q.complete_calls == 2, "ровно одна мягкая повторная попытка"
    assert len(q.fail_calls) == 1, "задача должна уйти в terminal (fail), а не висеть"
    _jid, worker, error, retry = q.fail_calls[0]
    assert _jid == 7 and worker == "test-worker"
    assert retry is False, "реальная работа уже сделана — ретрай дал бы дубль артефакта"
    assert w._current_jobs == {}


def test_process_handler_error_uses_fail():
    async def bad_handler(ctx, payload):
        raise RuntimeError("boom")

    q = _FakeQueue()
    w = _make_worker(q)
    w.handlers = {"test.channel": bad_handler}

    asyncio.run(w._process(_job()))

    assert q.complete_calls == 0
    assert len(q.fail_calls) == 1


def test_usage_is_passed_to_complete():
    q = _FakeQueue()
    w = _make_worker(q)
    asyncio.run(w._process(_job()))
    # result уже содержит usage-отчёт (подмешен в _process)
    assert isinstance(q.last_result, dict)
    assert "usage" in q.last_result
    assert "artifact" in q.last_result


def test_handler_progress_lands_in_result_and_events():
    """ctx.progress() собрается в result['_progress'] и дублируется событиями очереди (pg)."""

    async def progressy_handler(ctx, payload):
        await ctx.progress("шаг 1", "поиск")
        await ctx.progress("шаг 2")
        return {"ok": True}

    q = _FakeQueue()
    w = _make_worker(q)
    w.handlers = {"test.channel": progressy_handler}

    asyncio.run(w._process(_job()))

    assert q.last_result is not None
    assert "_progress" in q.last_result
    assert [s["phase"] for s in q.last_result["_progress"]] == ["шаг 1", "шаг 2"]
    # события-дубль ушли в очередь (pg-режим по умолчанию в тесте)
    assert len(q.progress_calls) == 2
    # result не «вырос» в usage observer: usage и артефакт целы
    assert "usage" in q.last_result
