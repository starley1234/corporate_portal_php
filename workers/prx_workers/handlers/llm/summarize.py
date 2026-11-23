"""Канал 'llm.summarize': пример LLM-задачи.

Пайплайн: промпт из payload → LLM-шлюз (модель из payload) → текст ответа в результате.

Ожидаемый payload:
    {"prompt": "текст для обработки", "system": "Ты ..." (опционально),
     "model": "..." (опционально)}

Результат: {"text": "ответ модели", "model": "..."}
"""

from __future__ import annotations

from ...base import HandlerContext

CHANNEL = "llm.summarize"


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    prompt = (payload.get("prompt") or "").strip()
    if not prompt:
        raise ValueError("payload.prompt обязателен")

    if ctx.llm is None:
        raise RuntimeError("LLM-провайдер не настроен — задайте LOCAL_API_URL или другой <ИМЯ>_API_URL")

    system = payload.get("system")
    messages = []
    if system:
        messages.append({"role": "system", "content": system})
    messages.append({"role": "user", "content": prompt})

    text = await ctx.llm.chat(messages, model=payload.get("model"))

    return {"text": text, "model": payload.get("model") or ctx.llm.model}
