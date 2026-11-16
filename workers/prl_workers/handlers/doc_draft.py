"""Канал `doc.draft`: черновик ответа на входящее письмо.

Контракт:
    GET  /prl394/api/input/<draft_id>   — шаблон + карточка + текст
    POST /prl394/api/result/<draft_id>  — {"body": "...", "citations": [...]}

Отправки здесь нет и быть не может: воркер только готовит текст.
"""

from __future__ import annotations

import json
import re
import time

from .. import site
from ..base import HandlerContext

CHANNEL = "doc.draft"
_PREFIX = "PRL394"
_ROUTE_BASE = "/prl394/api"


def _api_base() -> str:
    return site.module_api_base(_PREFIX, _ROUTE_BASE)


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    draft_id = int(payload.get("draft_id") or 0)
    if not draft_id:
        raise ValueError("payload.draft_id обязателен")

    ok, reason, data = await site.get_json(_PREFIX, f"/input/{draft_id}", base=_api_base(), timeout=90.0)
    if not ok:
        raise RuntimeError(f"задание черновика #{draft_id} не получено: {reason}")

    if data.get("done"):
        return {"draft_id": draft_id, "skipped": True, "status": data.get("status")}

    model = str(data.get("model") or "")
    messages = data.get("messages") or []
    if not model or not messages:
        raise RuntimeError("сайт вернул неполное задание: нет model/messages")

    if ctx.llm is None:
        raise RuntimeError("LLM-провайдеры не настроены в workers/.env")

    await ctx.progress("Пишу ответ", model)
    started = time.monotonic()

    body = await ctx.llm.chat(
        messages,
        model=model,
        temperature=float(data.get("temperature") or 0.2),
        max_tokens=int(data.get("max_tokens") or 1800),
        request_timeout=400.0,
    )

    duration_ms = int((time.monotonic() - started) * 1000)
    body = (body or "").strip()
    # Некоторые модели (и мок-сервер в e2e) отвечают JSON-объектом. Если это
    # {"body": "..."} — берём текст, иначе оставляем ответ как есть.
    if body.startswith("{"):
        try:
            parsed = json.loads(body)
            if isinstance(parsed, dict) and isinstance(parsed.get("body"), str):
                body = parsed["body"].strip()
        except json.JSONDecodeError:
            pass
    body = re.sub(r"```(?:\w+)?\s*|\s*```", "", body).strip()
    if len(body) < 40:
        raise RuntimeError("модель вернула пустой или слишком короткий ответ")

    await ctx.progress("Сохранение", f"{len(body)} символов")

    ok, reason, _res = await site.post_json(
        _PREFIX,
        f"/result/{draft_id}",
        {
            "body": body,
            "citations": data.get("citations") or [],
            "duration_ms": duration_ms,
        },
        base=_api_base(),
        json_body=True,
        timeout=120.0,
    )
    if not ok:
        raise RuntimeError(f"сайт не принял черновик: {reason}")

    await ctx.progress("Готово", f"{duration_ms} мс")

    return {"draft_id": draft_id, "chars": len(body), "duration_ms": duration_ms}
