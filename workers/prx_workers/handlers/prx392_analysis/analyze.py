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

import httpx

from ...llm import LlmConfigError
from .diagnostics import save_reply

from ... import site
from ...base import HandlerContext

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

    await ctx.progress("Этап 1 из 3 · Получение документа", f"прогон #{run_id}")
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

    await ctx.progress("Этап 2 из 3 · Модель думает", model)
    started = time.monotonic()

    options = {
        "temperature": float(data.get("temperature") or 0.0),
        "max_tokens": int(data.get("max_tokens") or 4000),
        "request_timeout": 400.0,
    }
    # JSON задан промптом и проверяется parse_json_reply + схемой сайта.
    # Не навязываем json_object: часть локальных серверов поддерживает только
    # json_schema либо обычную генерацию и отвечает 400 на json_object.
    if data.get("response_format"):
        options["response_format"] = data["response_format"]
    try:
        try:
            text, usage = await ctx.llm.chat_with_usage(messages, model=model, **options)
        except httpx.HTTPStatusError as exc:
            detail = exc.response.text.lower()
            if (exc.response.status_code == 400 and "response_format" in options
                    and any(key in detail for key in ("response_format", "json_object", "json_schema"))):
                options.pop("response_format")
                text, usage = await ctx.llm.chat_with_usage(messages, model=model, **options)
            else:
                raise
    except httpx.HTTPStatusError as exc:
        status = exc.response.status_code
        if status in (400, 404, 413, 422):
            # Повтор того же некорректного запроса не исправляет параметры.
            # Не включаем заголовки/запрос с текстом документа в диагностику.
            detail = exc.response.text.strip()[:800]
            raise LlmConfigError(
                f"LLM отклонила запрос: HTTP {status}, модель {model}. "
                f"Ответ сервера: {detail or 'без пояснения'}. "
                "Проверьте поддержку модели, формат ответа и размер контекста."
            ) from None
        raise

    duration_ms = int((time.monotonic() - started) * 1000)
    save_reply(run_id, model, text, 'received')
    try:
        result = parse_json_reply(text)
    except ValueError as exc:
        save_reply(run_id, model, text, 'invalid_json', str(exc))
        raise

    tokens = 0
    if isinstance(usage, dict):
        tokens = int(usage.get("total_tokens") or 0)
        if not tokens:
            tokens = int(usage.get("prompt_tokens") or 0) + int(usage.get("completion_tokens") or 0)

    await ctx.progress("Этап 3 из 3 · Сохранение", f"тип: {result.get('type') or '—'}")

    ok, reason, _res = await site.post_json(
        _PREFIX,
        f"/result/{run_id}",
        {"result": result, "tokens": tokens, "duration_ms": duration_ms},
        base=_api_base(),
        json_body=True,
        timeout=120.0,
    )
    if not ok:
        save_reply(run_id, model, text, 'delivery_failed', reason)
        raise RuntimeError(f"сайт не принял результат: {reason}")
    save_reply(run_id, model, text, 'delivered')

    await ctx.progress("Готово", f"{duration_ms} мс · токенов {tokens}")

    return {
        "run_id": run_id,
        "type": result.get("type"),
        "confidence": result.get("type_confidence"),
        "tokens": tokens,
        "duration_ms": duration_ms,
    }
