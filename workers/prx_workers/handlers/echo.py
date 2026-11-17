"""Демонстрационный канал: самопроверка очереди и воркера.

Пайплайн: claim задачи → эхо-проверка очереди и воркера → отметка времени в результате.
"""

from __future__ import annotations

import asyncio

from ..base import HandlerContext, utcnow_iso

CHANNEL = "echo"


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    """Проверка конвейера: PHP push -> воркер -> результат."""
    await asyncio.sleep(float(payload.get("sleep", 0.3)))
    return {
        "echo": payload,
        "at": utcnow_iso(),
        "worker": ctx.config.worker_name,
    }
