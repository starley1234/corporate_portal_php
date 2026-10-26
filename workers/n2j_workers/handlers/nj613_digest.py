"""nj613.digest — «Дайджест руководителю»: недельное резюме по цифрам.

Пайплайн: пиналка → сайт собирает счётчики недели → LLM пишет человеческое резюме → POST /digest/api/item → сохранение, уведомление nj597 и письмо владельцу.

Сайт собирает счётчики недели и ставит задачу payload = {user_id, from,
to, stats: {radar_finds, leads, inbox_new, touches_sent, articles},
email}. Воркер ОДНИМ LLM-вызовом пишет тёплое человеческое резюме недели
(5–8 предложений) без выдумок — только присланные цифры. Результат POST
/digest/api/item {user_id, from, to, summary, email} — сайт сохраняет,
уведомляет и шлёт письмо.

Env: NJ613_DIGEST_API_BASE (напр. https://n2j.ru/digest/api),
NJ613_DIGEST_SITE_TOKEN (или WORKER_SITE_TOKEN → WORKER_API_TOKEN),
NJ613_DIGEST_MODEL (спека «провайдер::модель»).
"""

from __future__ import annotations

import json
import logging
import os
import re

from .. import site

log = logging.getLogger("n2j.nj613")

CHANNEL = "nj613.digest"

SYSTEM = (
    "Ты помощник руководителя малого бизнеса. По присланным цифрам недели "
    "пишешь короткое тёплое резюме НА РУССКОМ: 5–8 предложений, живым "
    "языком, без канцелярита и без выдумок — только присланные цифры и "
    "очевидные из них наблюдения. Отметь, что выросло или требует внимания, "
    "и дай один короткий практичный совет. Не хвали пусто, не льёшь воду. "
    "Отвечаешь обычным текстом, БЕЗ markdown-разметки и заголовков."
)


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _model_spec() -> str | None:
    return _env("NJ613_DIGEST_MODEL", "") or None


LABELS = [
    ("radar_finds", "находок радара"),
    ("leads", "новых компаний-лидов"),
    ("inbox_new", "неразобранных входящих"),
    ("touches_sent", "отправлено писем по сериям"),
    ("articles", "опубликовано статей"),
]


def _prompt(p: dict) -> str:
    stats = p.get("stats") or {}
    lines = [f"{label}: {int(stats.get(key) or 0)}" for key, label in LABELS]

    return (
        f"Неделя: с {p.get('from')[:10]} по {p.get('to')[:10]}.\n"
        + "\n".join(lines)
        + "\n\nНапиши резюме недели для руководителя."
    )


async def handle(ctx, payload: dict) -> dict:
    user_id = int(payload.get("user_id") or 0)

    if user_id <= 0:
        raise RuntimeError("в payload нет user_id")

    if ctx.llm is None:
        raise RuntimeError("LLM-клиент воркера не создан: проверьте настройки провайдера")

    await ctx.progress("Собираю дайджест", "свожу цифры недели")

    text, usage = await ctx.llm.chat_with_usage(
        [{"role": "system", "content": SYSTEM},
         {"role": "user", "content": _prompt(payload)}],
        model=_model_spec(), max_tokens=600, request_timeout=180.0)

    log.info("nj613[uid %s]: %s+%s tok", user_id,
             int(usage.get("prompt_tokens") or 0), int(usage.get("completion_tokens") or 0))

    summary = re.sub(r"\s+", " ", (text or "").strip()).strip()

    if len(summary) < 80:
        raise RuntimeError("ИИ вернул слишком короткое резюме")

    await ctx.progress("Отправляю дайджест", f"{len(summary)} знаков")

    ok, reason, j = await site.post_json("NJ613_DIGEST", "/item", {
        "user_id": user_id,
        "from": str(payload.get("from") or ""),
        "to": str(payload.get("to") or ""),
        "summary": summary,
        "email": str(payload.get("email") or ""),
    }, json_body=True)

    if not ok:
        raise RuntimeError(f"сайт не принял дайджест: {reason[:300]}")

    return {"ok": True, "user_id": user_id, "chars": len(summary)}
