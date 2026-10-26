"""nj593 «Тренды GitHub»: прерывание сборки и живой этап LLM.

Владелец: «почти идеальный процесс, но не хватает элемента для прерывания» и
«обычно работает, а сейчас завис». Здесь проверяются обе части:

  * долгий LLM-вызов обновляет этап («модель думает N с…») — статус-ряд не
    выглядит замершим, даже если модель считает минуты;
  * отмена (кнопка «Прервать» → cancelled в очереди) гасит и LLM-вызов, и
    работу воркера по задаче;
  * снятая задача больше не «висит» в UI: ряд статусов знает 'cancelled'.
"""
import asyncio

import pytest

from n2j_workers.handlers import nj593_trends as m


@pytest.mark.asyncio
async def test_llm_watch_reports_progress_while_model_thinks():
    """Пока модель думает, этап обновляется — «завис» не выглядит зависшим."""
    ticks = []

    async def slow_llm():
        await asyncio.sleep(0.15)
        return "ответ модели"

    async def on_tick(note):
        ticks.append(note)

    out = await m._llm_watch("ИИ отбирает интересное и пишет тексты…",
                             slow_llm, on_tick=on_tick, every=0.03)

    assert out == "ответ модели"
    assert len(ticks) >= 2, "этап обязан обновляться, пока идёт LLM-вызов"
    assert all("модель думает" in t for t in ticks)
    assert all(t.startswith("ИИ отбирает") for t in ticks)


@pytest.mark.asyncio
async def test_llm_watch_cancels_model_call():
    """Отмена сборки прерывает и сам вызов модели (токены не тратятся зря)."""
    cancelled = []

    async def forever():
        try:
            await asyncio.sleep(300)
        except asyncio.CancelledError:
            cancelled.append(True)
            raise

    async def on_tick(note):
        pass

    watch = asyncio.create_task(m._llm_watch("curate", forever, on_tick=on_tick, every=0.02))
    await asyncio.sleep(0.06)
    watch.cancel()

    with pytest.raises(asyncio.CancelledError):
        await watch

    assert cancelled, "внутренний LLM-запрос должен быть погашен"


@pytest.mark.asyncio
async def test_llm_watch_tick_failure_does_not_break_answer():
    """Сбой рапорта о прогрессе не должен терять ответ модели."""
    async def llm():
        await asyncio.sleep(0.08)
        return "важно"

    async def broken_tick(note):
        raise RuntimeError("сайт недоступен")

    out = await m._llm_watch("curate", llm, on_tick=broken_tick, every=0.02)

    assert out == "важно"


def test_cancelled_reason_distinguishes_manual_stop_from_error():
    """«Остановлено вручную» — это отмена, а не ошибка воркера."""
    assert m._cancelled_reason("Сборка остановлена вручную — новые позиции не принимаются")
    assert m._cancelled_reason("Обзор остановлен вручную — завершение не принято")
    assert not m._cancelled_reason("сбой сохранения: колонка не найдена")
    assert not m._cancelled_reason("")
    assert not m._cancelled_reason("Воркер молчал слишком долго — задача провалена")
