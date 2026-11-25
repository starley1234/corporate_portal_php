"""Канал `doc.merge.audit`: риск-аудит собранного договора (Map-Reduce).

Контракт:
    GET  /prx399/api/audit_input/<contract_id>   — дерево + карточка + политика
    POST /prx399/api/audit_result/<contract_id>  — {summary, risks[]}

Map:   тематические чанки (chunks.py) — по одному вызову модели на чанк;
Reduce: программная дедупликация точных повторов + финальный вызов модели,
       который сливает дубли по смыслу и пишет паспорт сделки (summary).
Битые ссылки сюда не доходят: их находит PHP при сборке (source='static').
"""

from __future__ import annotations

import json

from ... import site
from ...base import HandlerContext
from .chunks import chunk
from .delta import _PREFIX, _api_base, _llm_json

CHANNEL = "doc.merge.audit"

FOCUS_RU = {
    "balanced": "сбалансированный двусторонний анализ",
    "customer": "аудит в интересах Заказчика/Арендатора/Покупателя",
    "contractor": "аудит в интересах Исполнителя/Арендодателя/Поставщика",
}

_MAP_PROMPT = """Ты — юрист по договорному праву. Анализируешь ОДИН смысловой блок
актуальной редакции договора (__THEME__; пункты __PATH__).

Карточка сделки: __CARD__
Фокус аудита: __FOCUS__.
Политика рисков владельца: __POLICY__

Найди риски этого блока: финансовые ловушки, дисбаланс ответственности,
размытые условия одностороннего отказа, внутренние противоречия блока.
Только JSON:
{"risks": [
    {"severity": "high|medium|low", "area": "область", "chunk_path": "__PATH__",
     "title": "не длиннее 120 символов", "detail": "что именно и где",
     "recommendation": "как поправить формулировку"}
]}
Не выдумывай пункты, которых нет в блоке. Пустой список — валидный ответ.

Текст блока:
<<<БЛОК
__TEXT__
БЛОК>>>"""

_REDUCE_PROMPT = """Ты — юрист. Ниже — черновые находки риск-аудита договора по тематическим
блокам (Map-Reduce). Слей дубли по смыслу, откалибруй severity и напиши
паспорт сделки (Executive Summary): итоговая цена, актуальный график оплат,
финальные сроки — с учётом всех допсоглашений.

Карточка сделки: __CARD__
Фокус аудита: __FOCUS__

Черновые находки:
__RISKS__

Только JSON:
{"summary": "3-6 предложений паспорта сделки",
 "risks": [{"severity": "high|medium|low", "area": "…", "chunk_path": "…",
            "title": "…", "detail": "…", "recommendation": "…"}]}
Сохраняй содержание находок — не теряй ни одной проблемы."""


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    contract_id = int(payload.get("contract_id") or 0)
    if not contract_id:
        raise ValueError("payload.contract_id обязателен")

    ok, reason, data = await site.get_json(_PREFIX, f"/audit_input/{contract_id}", base=_api_base(), timeout=60.0)
    if not ok:
        raise RuntimeError(f"аудит #{contract_id} не получен: {reason}")
    if data.get("done"):
        return {"contract_id": contract_id, "skipped": True, "status": data.get("status")}

    nodes = data.get("nodes") or []
    if not isinstance(nodes, list) or not nodes:
        raise ValueError("сайт вернул пустое дерево договора")
    card = data.get("deal_card") or {}
    focus = FOCUS_RU.get(str(data.get("focus")), "сбалансированный двусторонний анализ")
    policy = str(data.get("policy") or "") or "стандарты разумной рыночной практики и ГК РФ"
    model = str(data.get("model") or "")
    temperature = float(data.get("temperature") or 0.0)
    max_tokens = int(data.get("max_tokens") or 4000)
    card_json = json.dumps(card, ensure_ascii=False) if card else "не сформирована"

    # --- Map: по чанку на вызов -------------------------------------------
    parts = chunk(nodes)
    if not parts:
        raise ValueError("в дереве нет живых узлов")
    drafts: list[dict] = []
    for i, part in enumerate(parts, 1):
        await ctx.progress("map", f"блок {i}/{len(parts)}: {part['title']}")
        reply = await _llm_json(
            ctx,
            _MAP_PROMPT
            .replace("__THEME__", part["title"])
            .replace("__PATH__", part["path"])
            .replace("__CARD__", card_json)
            .replace("__FOCUS__", focus)
            .replace("__POLICY__", policy)
            .replace("__TEXT__", part["text"]),
            model, temperature, max_tokens,
        )
        for risk in reply.get("risks") or []:
            if isinstance(risk, dict) and risk.get("title"):
                risk.setdefault("chunk_path", part["path"])
                drafts.append(risk)

    # --- Reduce: дедуп точных повторов + финальный вызов модели ------------
    seen: set[str] = set()
    unique: list[dict] = []
    for risk in drafts:
        key = str(risk.get("title", "")).strip().lower()
        if key and key not in seen:
            seen.add(key)
            unique.append(risk)

    await ctx.progress("reduce", f"слияние {len(unique)} находок")
    try:
        final = await _llm_json(
            ctx,
            _REDUCE_PROMPT
            .replace("__CARD__", card_json)
            .replace("__FOCUS__", focus)
            .replace("__RISKS__", json.dumps(unique, ensure_ascii=False)[:20000]),
            model, temperature, max_tokens,
        )
    except Exception:  # noqa: BLE001 — Reduce не обязателен для честности отчёта
        # Reduce не обязателен для честности отчёта: отдаём дедуплицированный
        # список и техническую сводку, владелец видит находки в любом случае.
        counts = {"high": 0, "medium": 0, "low": 0}
        for risk in unique:
            counts[str(risk.get("severity", "low"))] = counts.get(str(risk.get("severity", "low")), 0) + 1
        final = {
            "summary": (
                f"Паспорт сделки сформирован программно (Reduce-шаг модели недоступен). "
                f"Выявлено рисков: {counts['high']} high, {counts['medium']} medium, {counts['low']} low."
            ),
            "risks": unique,
        }

    risks = final.get("risks")
    if not isinstance(risks, list):
        risks = unique
    if not str(final.get("summary") or "").strip():
        raise ValueError("модель не вернула summary")

    ok, reason, _resp = await site.post_json(
        _PREFIX, f"/audit_result/{contract_id}",
        {"summary": str(final["summary"]), "risks": risks},
        base=_api_base(), timeout=120.0, json_body=True,
    )
    if not ok:
        raise RuntimeError(f"аудит #{contract_id} не принят: {reason}")

    return {"contract_id": contract_id, "risks": len(risks), "chunks": len(parts)}
