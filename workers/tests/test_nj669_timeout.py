"""Регрессия запуска Solid3D без asyncio.timeout; дедлайн, отмена и учёт ресурсов."""
import asyncio

import pytest

from n2j_workers.handlers import nj669_assist, nj669_solid3d
from n2j_workers.usage import Usage


@pytest.fixture(params=['generate', 'verify', 'export', 'ideas', 'caption'])
def channel(request, monkeypatch):
    kind = request.param
    module = nj669_assist if kind in ('ideas', 'caption') else nj669_solid3d
    handler = {'generate': nj669_solid3d.handle, 'verify': nj669_solid3d.handle_verify, 'export': nj669_solid3d.handle_export,
               'ideas': nj669_assist.handle_ideas, 'caption': nj669_assist.handle_caption}[kind]
    # Имя функции, которую обёртка ждёт под бюджетом: run / verify / run у помощников.
    runner = {'verify': 'verify', 'export': 'export'}.get(kind, 'run')
    payload = {'task_id': 12, 'run_token': 'a' * 48} if kind in ('ideas', 'caption') else {'revision_id': 12, 'run_token': 'a' * 48, 'export_id': 7}
    budget = {'generate': 1650, 'verify': 540, 'export': 840}.get(kind, 480)
    fail = {'generate': 'fail', 'verify': 'verify_fail', 'export': 'export_fail'}.get(kind, 'assist_fail')
    calls = []

    async def api(payload, action, body=None):
        calls.append((action, body))
        return {'ok': True}

    monkeypatch.setattr(module, '_api', api)
    # Моделируем отсутствие API, появившегося в Python 3.11, не только ищем текст в коде.
    monkeypatch.delattr(asyncio, 'timeout', raising=False)
    return module, handler, payload, budget, fail, calls, runner


def test_success_without_asyncio_timeout(channel, monkeypatch):
    module, handler, payload, _, _, calls, runner = channel

    async def run(*args):
        await asyncio.sleep(0)
        return {'accepted': True}

    monkeypatch.setattr(module, runner, run)
    assert asyncio.run(handler(None, payload)) == {'accepted': True}
    assert calls == []


def test_budget_cancels_pipeline_before_reporting_failure(channel, monkeypatch):
    module, handler, payload, budget, fail, calls, runner = channel
    wait_for = asyncio.wait_for
    seen_budgets = []
    stopped = []

    async def run(*args):
        try:
            await asyncio.Event().wait()
        finally:
            stopped.append(True)

    async def short_wait(awaitable, timeout):
        seen_budgets.append(timeout)
        return await wait_for(awaitable, timeout=0.01)

    monkeypatch.setattr(module, runner, run)
    monkeypatch.setattr(asyncio, 'wait_for', short_wait)
    # На Python 3.10 asyncio.TimeoutError не является встроенным TimeoutError.
    with pytest.raises(asyncio.TimeoutError):
        asyncio.run(handler(None, payload))
    assert seen_budgets == [budget]
    assert stopped == [True]
    assert len(calls) == 1 and calls[0][0] == fail
    assert calls[0][1]['error']  # не пустая ошибка таймаута, даже если str(exc) == ''


def test_external_cancellation_is_not_swallowed_or_reported_as_success(channel, monkeypatch):
    module, handler, payload, _, _, calls, runner = channel

    async def scenario():
        entered = asyncio.Event()
        stopped = asyncio.Event()

        async def run(*args):
            try:
                entered.set()
                await asyncio.Event().wait()
            finally:
                stopped.set()

        monkeypatch.setattr(module, runner, run)
        task = asyncio.create_task(handler(None, payload))
        await entered.wait()
        task.cancel()
        with pytest.raises(asyncio.CancelledError):
            await task
        assert stopped.is_set()

    asyncio.run(scenario())
    assert calls == []


def test_usage_context_survives_wait_for_task(channel, monkeypatch):
    module, handler, payload, _, _, _, runner = channel

    async def scenario():
        usage = Usage.begin()

        async def run(*args):
            assert Usage.current() is usage
            Usage.current().add_llm('test', 'model', {'prompt_tokens': 7, 'completion_tokens': 3})
            return {'ok': True}

        monkeypatch.setattr(module, runner, run)
        await handler(None, payload)
        assert usage.to_dict()['prompt_tokens'] == 7
        assert usage.to_dict()['completion_tokens'] == 3
        assert usage.to_dict()['llm_calls'] == 1

    asyncio.run(scenario())
