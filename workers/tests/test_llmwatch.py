"""Долгие вызовы модели под тикером (llmwatch).

Эталон — сборка обзоров GitHub (nj593): пока модель думает, в статус-ряд уходит
«модель думает N с (лимит M с)…», а по исчерпании лимита вызов снимается и
поднимается понятная причина. Владелец, /leads: этапы застывали на «ИИ извлекает
компании» — снаружи работу модели от мёртвой задачи не отличить.
"""
import asyncio

import pytest

from n2j_workers import llmwatch


@pytest.mark.asyncio
async def test_ticks_report_progress_and_return_result():
    ticks = []

    async def slow():
        await asyncio.sleep(0.3)
        return "готово"

    async def on_tick(waited, limit):
        ticks.append((waited, limit))

    out = await llmwatch.wait_with_ticks(slow, every=0.05, limit=5, on_tick=on_tick)

    assert out == "готово"
    assert ticks, "тикер не отчитался о ходе — этап выглядит зависшим"
    assert all(limit == 5 for _waited, limit in ticks)


@pytest.mark.asyncio
async def test_limit_cancels_call_with_clear_reason():
    async def forever():
        await asyncio.sleep(600)

    with pytest.raises(asyncio.TimeoutError) as err:
        await llmwatch.wait_with_ticks(forever, every=0.05, limit=0.15)

    assert "не ответила" in str(err.value)
    assert "0.15" in str(err.value) or "0" in str(err.value)


@pytest.mark.asyncio
async def test_cancellation_propagates_and_kills_inner_call():
    started = asyncio.Event()
    cancelled = {"inner": False}

    async def forever():
        started.set()

        try:
            await asyncio.sleep(600)
        except asyncio.CancelledError:
            cancelled["inner"] = True
            raise

    task = asyncio.create_task(llmwatch.wait_with_ticks(forever, every=0.05))
    await started.wait()

    task.cancel()

    with pytest.raises(asyncio.CancelledError):
        await task

    # отмена «Прервать» не должна оставлять запрос к модели жить своей жизнью
    await asyncio.sleep(0.05)
    assert cancelled["inner"], "внутренний вызов не снят — токены продолжают тратиться"


@pytest.mark.asyncio
async def test_tick_failure_does_not_break_call():
    """Рапорт о ходе не важнее ответа модели."""
    async def slow():
        await asyncio.sleep(0.15)
        return "ok"

    async def bad_tick(waited, limit):
        raise RuntimeError("сайт недоступен")

    assert await llmwatch.wait_with_ticks(slow, every=0.02, limit=5, on_tick=bad_tick) == "ok"
