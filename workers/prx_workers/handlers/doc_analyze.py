"""Канал `doc.analyze`: разбор входящего документа локальной LLM.

Контракт:
    GET  /prx392/api/input/<run_id>   — сообщения, модель, лимиты
    POST /prx392/api/result/<run_id>  — {"result": {...}, "tokens": 123, "duration_ms": 4500}

Модель обязана быть локальной — это проверяет сайт ДО постановки задачи.
Воркер не пишет в карточку: он возвращает JSON, сайт валидирует и сохраняет
предложение, а человек решает, применять ли его.
"""

from __future__ import annotations

import json
import re
import time

from .. import site
from ..base import HandlerContext

CHANNEL = "doc.analyze"
_PREFIX = "PRX392"
_ROUTE_BASE = "/prx392/api"


def _api_base() -> str:
    return site.module_api_base(_PREFIX, _ROUTE_BASE)


def parse_json_reply(text: str) -> dict:
    """Достать JSON из ответа модели: без markdown-заборов и лишних слов."""
    raw = (text or "").strip()
    if not raw:
        raise ValueError("пустой ответ модели")

    fence = re.search(r"```(?:json)?\s*(.*?)```", raw, re.S | re.I)
    if fence:
        raw = fence.group(1).strip()

    start = raw.find("{")
    end = raw.rfind("}")
    if start >= 0 and end > start:
        raw = raw[start:end + 1]

    try:
        data = json.loads(raw)
    except json.JSONDecodeError:
        raise ValueError("модель вернула невалидный JSON") from None

    if not isinstance(data, dict):
        raise ValueError("ответ модели должен быть JSON-объектом")

    return data


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    if not run_id:
        raise ValueError("payload.run_id обязателен")

    ok, reason, data = await site.get_json(_PREFIX, f"/input/{run_id}", base=_api_base(), timeout=90.0)
    if not ok:
        raise RuntimeError(f"задание #{run_id} не получено: {reason}")

    if data.get("done"):
        return {"run_id": run_id, "skipped": True, "status": data.get("status")}

    model = str(data.get("model") or "")
    messages = data.get("messages") or []
    if not model or not messages:
        raise RuntimeError("сайт вернул неполное задание: нет model/messages")

    if ctx.llm is None:
        raise RuntimeError("LLM-провайдеры не настроены в workers/.env")

    await ctx.progress("Модель думает", model)
    started = time.monotonic()

    text, usage = await ctx.llm.chat_with_usage(
        messages,
        model=model,
        temperature=float(data.get("temperature") or 0.0),
        max_tokens=int(data.get("max_tokens") or 4000),
        response_format=data.get("response_format") or {"type": "json_object"},
        request_timeout=400.0,
    )

    duration_ms = int((time.monotonic() - started) * 1000)
    result = parse_json_reply(text)

    tokens = 0
    if isinstance(usage, dict):
        tokens = int(usage.get("total_tokens") or 0)
        if not tokens:
            tokens = int(usage.get("prompt_tokens") or 0) + int(usage.get("completion_tokens") or 0)

    await ctx.progress("Сохранение", f"тип: {result.get('type') or '—'}")

    ok, reason, _res = await site.post_json(
        _PREFIX,
        f"/result/{run_id}",
        {"result": result, "tokens": tokens, "duration_ms": duration_ms},
        base=_api_base(),
        json_body=True,
        timeout=120.0,
    )
    if not ok:
        raise RuntimeError(f"сайт не принял результат: {reason}")

    await ctx.progress("Готово", f"{duration_ms} мс · токенов {tokens}")

    return {
        "run_id": run_id,
        "type": result.get("type"),
        "confidence": result.get("type_confidence"),
        "tokens": tokens,
        "duration_ms": duration_ms,
    }
