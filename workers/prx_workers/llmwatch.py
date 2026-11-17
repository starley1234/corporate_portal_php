"""Ожидание ответа LLM с регулярными отчётами о ходе.

Один LLM-вызов на локальной модели может идти минуты: снаружи это выглядит
как «завис на этапе ИИ» — пульс задачи есть, а в статус-ряде висит один и тот
же текст (владелец, 26.09: «завис… непонятно»; эталон — сборка обзоров
GitHub, prx593). Здесь вызов идёт под тикером: каждые `every` секунд
вызывающий код получает шанс показать «модель думает N с (лимит M с)…»,
а по исчерпании `limit` вызов снимается и поднимается `asyncio.TimeoutError`
с понятной причиной.

Отмена (владелец нажал «Прервать», сторож снял задачу) приходит как
CancelledError — вызов гасим, чтобы не тянуть токены впустую.
"""

from __future__ import annotations

import asyncio
import inspect
import time
from typing import Any, Awaitable, Callable


async def wait_with_ticks(
    make_awaitable: Callable[[], Awaitable[Any]],
    *,
    every: float = 20.0,
    limit: float | None = None,
    on_tick: Callable[[int, float | None], Awaitable[None]] | None = None,
    what: str = "модель",
) -> Any:
    """Дождаться результата вызова, показывая, что он жив.

    make_awaitable — фабрика корутины (например, lambda: chat(...));
    on_tick(waited, limit) — рапорт о ходе (ошибки рапорта игнорируются:
    он не важнее ответа модели);
    limit — жёсткий предел ожидания в секундах, после которого вызов
    снимается и поднимается asyncio.TimeoutError.
    """
    engine = make_awaitable() if inspect.iscoroutinefunction(make_awaitable) else make_awaitable()
    task: asyncio.Task = asyncio.create_task(engine)
    t0 = time.monotonic()

    try:
        while True:
            left = None if not limit else max(0.0, float(limit) - (time.monotonic() - t0))
            done, _ = await asyncio.wait({task}, timeout=every if left is None else min(every, left))

            if done:
                break

            waited = int(time.monotonic() - t0)

            if left is not None and left <= 0:
                # Жёсткий предел: клиент мог не применить свой timeout, а задача
                # «висеть» не должна. Гасим вызов и отдаём причину вызывающему.
                task.cancel()
                await asyncio.wait({task}, timeout=2.0)

                raise asyncio.TimeoutError(
                    f"{what} не ответила за {int(limit)} с — вызов прерван")

            if on_tick is not None:
                try:
                    await on_tick(waited, limit)
                except Exception:  # noqa: BLE001 — рапорт не важнее ответа модели
                    pass

        return task.result()
    except BaseException:
        task.cancel()
        raise
