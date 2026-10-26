"""Общий LLM-хелпер воркеров (nj598_llmchat): один вызов чата через ctx.llm.

Модель берётся из env «<PREFIX>_MODEL» («провайдер::модель»), иначе —
дефолт воркера. `chat()` отдаёт текст, `chat_with_usage()` — текст и usage
(обрыв по лимиту, расход на размышления), чтобы обработчик видел причину
неполного ответа модели, а не только «не JSON».
"""

from __future__ import annotations

import os


async def chat_with_usage(ctx, messages: list[dict], phase: str, *,
                         model_env: str = "", max_tokens: int = 2000,
                         timeout: int = 300, options: dict | None = None) -> tuple[str, dict]:
    """Один вызов LLM: messages → (текст, usage).

    Usage нужен там, где обработчик обязан увидеть обрыв ответа
    (_llm_finish_reason=length) и расход на размышления рассуждающей модели
    (completion_tokens_details.reasoning_tokens): инцидент 15.10.2026 —
    радиолокационный анализ 3000 токенов целиком ушёл в reasoning, JSON
    оборвался, прогон закрылся «ни одного материала». options — поля
    OpenAI-совместимого тела запроса (напр. отключение размышлений),
    провайдер, который их не знает, обычно их игнорирует.
    """
    model = (os.environ.get(model_env, "").strip() if model_env else "") or None

    return await ctx.llm.chat_with_usage(
        messages, model=model, max_tokens=max_tokens, request_timeout=timeout,
        **(options or {}))


async def chat(ctx, messages: list[dict], phase: str, *,
               model_env: str = "", max_tokens: int = 2000,
               timeout: int = 300, options: dict | None = None) -> str:
    """Только текст ответа (для тех, кому usage не нужен)."""
    text, _usage = await chat_with_usage(ctx, messages, phase, model_env=model_env,
                                         max_tokens=max_tokens, timeout=timeout,
                                         options=options)

    return text
