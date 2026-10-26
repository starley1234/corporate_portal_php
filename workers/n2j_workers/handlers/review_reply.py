"""Канал 'nj540.reply': публичный ответ на отзыв клиента (модуль nj540_reply,

Пайплайн: отзыв из payload → LCEL-шаг: разбор отзыва → LCEL-шаг: черновик ответа → текст ответа в результате задачи.
сервис «Отзыв 2 Ответ» / Review 2 Reply).

LangChain-конвейер (LCEL, два шага на основном LLM-шлюзе):
  1) анализ отзыва -> JSON {sentiment, topics, escalation, language};
  2) генерация публичного ответа в выбранном тоне с учётом разбора.

LLM: провайдеры <ИМЯ>_API_URL (OpenAI-совместимые); модель — NJ540_MODEL
(спека "провайдер::модель" | "модель" | пусто = дефолт; см. workers/n2j_workers/llm.py).

Режимы очереди:
  * pg   — ответ пишется прямо в nj_540_replies (ctx.pg), как semantic.index;
  * http — БД недоступна: результат возвращается в job.result, и PHP
    дозапишет его лениво (Service_Nj540_Reply::reconcile при запросе статуса).

Деградация: если langchain не установлен ИЛИ конвейер упал — один запрос
через httpx-клиент ctx.llm без анализа (engine='plain'). Канал не блокируется.

Ожидаемый payload (формирует Service_Nj540_Reply):
    {"reply_id": 12, "code": "r2a...", "review": "текст отзыва",
     "shop": "Мой Магазин" (опц.), "tone": "friendly|formal|brief|apology|premium"}
"""

from __future__ import annotations

import json
import logging
import os
import traceback

from ..base import HandlerContext

CHANNEL = "nj540.reply"

log = logging.getLogger("n2j.nj540")

# Должно совпадать с Service_Nj540_Reply::TONES (PHP)
TONES = {
    "friendly": "тёплый, человечный, дружелюбный",
    "formal": "официально-деловой, сдержанно-вежливый",
    "brief": "максимально краткий, 1–2 предложения, по делу",
    "apology": "с искренним извинением и конкретным шагом решения проблемы",
    "premium": "премиальный, персональный, подчёркнуто заботливый",
}

ANALYZE_SYSTEM = (
    "Ты — аналитик клиентских отзывов. Проанализируй отзыв и верни ТОЛЬКО валидный "
    'JSON без markdown по схеме: {{"sentiment": "positive|negative|mixed|neutral", '
    '"topics": ["1-3 слова на тему"], "escalation": true|false, "language": "код языка, напр. ru"}}. '
    "escalation=true, если отзыв требует личной эскалации: угрозы, суд, юристы, "
    "требование возврата денег, обвинения в обмане."
)


def _shop_line(shop: str) -> str:
    return f" магазина «{shop}»" if shop else ""


def _reply_system(tone: str, shop: str, analysis: dict | None) -> str:
    language = "ru"
    analysis_block = ""
    if isinstance(analysis, dict):
        language = str(analysis.get("language") or "ru")
        topics = ", ".join(str(t) for t in (analysis.get("topics") or [])[:5])
        analysis_block = (
            f"\nРазбор отзыва: тональность — {analysis.get('sentiment', 'неизвестно')}; "
            f"темы — {topics or 'не определены'}; эскалация — "
            f"{'ДА, предложи решить вопрос лично' if analysis.get('escalation') else 'нет'}."
        )

    return (
        f"Ты — специалист по работе с клиентами и пишешь ПУБЛИЧНЫЙ ответ от лица"
        f"{_shop_line(shop) or ' магазина'} на отзыв клиента.\n"
        f"Тон ответа: {TONES.get(tone, TONES['friendly'])}.\n"
        f"Язык ответа: {language}.\n"
        "Правила:\n"
        "- обратись к конкретным темам отзыва, без общих фраз и канцелярита;\n"
        "- если есть проблема — признай её и предложи конкретный следующий шаг;\n"
        "- не выдумывай фактов, которых нет в отзыве; не обещай невозможного;\n"
        "- 2-5 предложений (если тон не требует короче); без подписи; чистый текст, без markdown."
        + analysis_block
    )


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    reply_id = int(payload.get("reply_id") or 0)
    review = (payload.get("review") or "").strip()

    if reply_id <= 0 or not review:
        raise ValueError("payload: reply_id и review обязательны")

    tone = payload.get("tone") if payload.get("tone") in TONES else "friendly"
    shop = (payload.get("shop") or "").strip()

    if ctx.config is None or not getattr(ctx.config, "llm_base_url", ""):
        raise RuntimeError("LLM-провайдеры не настроены (<ИМЯ>_API_URL в workers/.env) — шлюз недоступен")

    from ..llm import resolve_endpoint

    model = os.environ.get("NJ540_MODEL") or ctx.config.llm_model
    base_url, api_key, model, proxy = resolve_endpoint(ctx.config, model)

    # Этап в журнал очереди (монитор nj120, «Мои задачи», статус-ряд модуля)
    await ctx.progress("ИИ пишет ответ на отзыв", (review or "")[:120])

    try:
        model, engine, analysis, reply = await _langchain_pipeline(
            base_url, api_key, model, review, shop, tone, proxy
        )
    except ImportError:
        log.warning("nj540: langchain не установлен — упрощённый режим (один запрос)")
        return await _plain(ctx, reply_id, payload, review, shop, tone, model)
    except Exception:  # noqa: BLE001 — деградация вместо падения задачи
        log.warning("nj540: LangChain-конвейер упал, пробую упрощённый режим:\n%s", traceback.format_exc())
        return await _plain(ctx, reply_id, payload, review, shop, tone, model)

    await ctx.progress("Сохраняю ответ", "готово")

    await _save(ctx, reply_id, analysis, reply, model, engine)
    return {
        "reply_id": reply_id,
        "code": payload.get("code"),
        "status": "done",
        "model": model,
        "engine": engine,
        "analysis": analysis,
        "reply": reply,
    }


# ---------------------------------------------------------------------------
# LangChain-конвейер (основной путь)
# ---------------------------------------------------------------------------

async def _langchain_pipeline(base_url: str, api_key: str, model: str,
                              review: str, shop: str, tone: str,
                              proxy: str | None = None):
    from langchain_core.output_parsers import JsonOutputParser, StrOutputParser
    from langchain_core.prompts import ChatPromptTemplate
    from langchain_openai import ChatOpenAI

    extra: dict = {}

    if proxy:
        import httpx

        extra["http_client"] = httpx.AsyncClient(proxy=proxy)

    llm = ChatOpenAI(
        model=model or "gpt-4o-mini",
        temperature=0.6,
        timeout=90,
        max_retries=1,
        api_key=api_key or "EMPTY",
        base_url=base_url,
        **extra,
    )

    analyze_chain = (
        ChatPromptTemplate.from_messages(
            [("system", ANALYZE_SYSTEM), ("human", "Отзыв клиента{shop_line}:\n{review}")]
        )
        | llm
        | JsonOutputParser()
    )
    reply_chain = (
        ChatPromptTemplate.from_messages(
            [("system", "{system}"), ("human", "Отзыв клиента:\n{review}")]
        )
        | llm
        | StrOutputParser()
    )

    analysis: dict | None = None
    try:
        parsed = await analyze_chain.ainvoke(
            {"shop_line": _shop_line(shop), "review": review}
        )
        analysis = parsed if isinstance(parsed, dict) else None
    except Exception:  # noqa: BLE001 — ответ важнее анализа
        log.warning("nj540: шаг анализа не удался, отвечаю без разбора:\n%s", traceback.format_exc())

    reply = (
        await reply_chain.ainvoke(
            {"system": _reply_system(tone, shop, analysis), "review": review}
        )
    ).strip()

    if not reply:
        raise RuntimeError("LLM вернула пустой ответ")

    return model, "langchain", analysis, reply


# ---------------------------------------------------------------------------
# Упрощённый режим: один запрос через httpx-клиент (ctx.llm)
# ---------------------------------------------------------------------------

async def _plain(ctx: HandlerContext, reply_id: int, payload: dict,
                 review: str, shop: str, tone: str, model: str) -> dict:
    if ctx.llm is None:
        raise RuntimeError("LLM-шлюз недоступен (TOOLFORGE_API_URL)")

    text = await ctx.llm.chat(
        [
            {"role": "system", "content": _reply_system(tone, shop, None)},
            {"role": "user", "content": review},
        ],
        model=model or None,
    )
    reply = (text or "").strip()

    if not reply:
        raise RuntimeError("LLM вернула пустой ответ")

    engine, analysis = "plain", None
    await _save(ctx, reply_id, analysis, reply, ctx.llm.model, engine)

    return {
        "reply_id": reply_id,
        "code": payload.get("code"),
        "status": "done",
        "model": ctx.llm.model,
        "engine": engine,
        "analysis": analysis,
        "reply": reply,
    }


# ---------------------------------------------------------------------------
# Запись результата
# ---------------------------------------------------------------------------

async def _save(ctx: HandlerContext, reply_id: int, analysis: dict | None,
                reply: str, model: str, engine: str) -> None:
    """pg-режим: прямо в nj_540_replies; http-режим: результат вернётся в job.result."""
    if ctx.pg is None:
        log.info("nj540: нет pg-пула (http-режим) — ответ вернётся в job.result, PHP дозапишет")
        return

    meta = json.dumps({"model": model, "engine": engine}, ensure_ascii=False)
    analysis_json = (
        json.dumps(analysis, ensure_ascii=False) if isinstance(analysis, dict) else None
    )

    async with ctx.pg.acquire() as conn:
        updated = await conn.execute(
            "UPDATE nj_540_replies SET status = 'done', reply_text = $2, "
            "analysis = $3::jsonb, meta = meta || $4::jsonb, finished_at = now(), "
            "error = NULL WHERE id = $1 AND status = 'new'",
            reply_id, reply, analysis_json, meta,
        )
        log.info("nj540: отзыв %s записан (%s)", reply_id, updated)
