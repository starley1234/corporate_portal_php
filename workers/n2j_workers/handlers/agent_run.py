"""Канал 'agent.run': stateless-исполнение LLM-агента (модуль nj130_agents).

Пайплайн: PHP кладёт в payload конфиг агента и историю → сборка сообщений → один LLM-вызов → ответ в результате задачи.

В payload PHP (Service_Nj130_Agents::_job_payload) кладёт ВСЁ необходимое —
конфиг агента и историю сообщений, поэтому обработчик не ходит в таблицы
агентов и работает в любом режиме очереди (pg/http, любой сервер).

Ожидаемый payload:
    {
      "run_id": 5,
      "agent": {"slug": "support", "system": "...", "model": "", 
                "temperature": 0.2, "max_tokens": null, "config": {}},
      "messages": [{"role": "user", "content": "..."}, ...]
    }

Результат задачи (попадает в nj_120_jobs.result, применяется PHP sync_run):
    {"reply": "...", "model": "...", "messages_count": N}
"""

from __future__ import annotations

from ..base import HandlerContext

CHANNEL = "agent.run"


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    if ctx.llm is None:
        raise RuntimeError("TOOLFORGE_API_URL не настроен — LLM-шлюз недоступен")

    agent = payload.get("agent") or {}
    messages = payload.get("messages") or []
    run_id = payload.get("run_id")

    if not messages:
        raise ValueError("payload.messages пусты — нечего отвечать")

    chat_messages: list[dict] = []
    system = agent.get("system")
    if system:
        chat_messages.append({"role": "system", "content": system})
    for m in messages:
        role = m.get("role", "user")
        if role in ("system",):  # системный уже добавлен из конфига агента
            continue
        chat_messages.append({"role": role, "content": m.get("content", "")})

    options: dict = {}
    if agent.get("temperature") is not None:
        options["temperature"] = float(agent["temperature"])
    if agent.get("max_tokens"):
        options["max_tokens"] = int(agent["max_tokens"])

    reply = await ctx.llm.chat(chat_messages, model=agent.get("model") or None, **options)

    return {
        "run_id": run_id,
        "reply": reply,
        "model": agent.get("model") or ctx.llm.model,
        "messages_count": len(chat_messages),
    }
