"""nj612.touch — «Серии касаний»: письмо очередного шага follow-up.

Пайплайн: сайт ставит задачу шага → LLM пишет письмо (учёт прошлых шагов) → POST /touches/api/step → владелец отправляет или пиналка по расписанию.

Сайт ставит задачу payload = {code, seq, company, name, offer, tone,
total, prev: [{seq, subject, body}, ...]}. Воркер ОДНИМ LLM-вызовом пишет
короткое письмо шага: шаг 1 — первое касание с крючком, шаги 2+ — вежливый
follow-up с оглядкой на прошлые письма (не повторяться, добавлять новое).
Результат POST /touches/api/step {code, seq, subject, body}.

Без веб-поиска: письмо должно быть честным — только то, что дал владелец.
Env: NJ612_TOUCH_API_BASE (напр. https://n2j.ru/touches/api),
NJ612_TOUCH_SITE_TOKEN (или WORKER_SITE_TOKEN → WORKER_API_TOKEN),
NJ612_TOUCH_MODEL (спека «провайдер::модель»).
"""

from __future__ import annotations

import logging
import os

from .. import site

log = logging.getLogger("n2j.nj612")

CHANNEL = "nj612.touch"

TONES = {
    "business": "деловой, уважительный, без панибратства",
    "friendly": "дружелюбный, живой, на «вы», но тепло",
    "bold": "смелый и прямой: короткие фразы, конкретная выгода, без канцелярита",
}

SYSTEM = (
    "Ты опытный B2B-продавец, который пишет письма руками, а не шаблонами. "
    "Пишешь НА РУССКОМ письмо очередного шага вежливой серии касаний. "
    "Правила: коротко (60–130 слов в теле), конкретно про пользу для адресата, "
    "без выдумок — используешь только факты из задания. Не давишь, не "
    "мелируешь, не пишешь «надеюсь, письмо нашло вас хорошо». Шаг 1 — первое "
    "касание: почему пишешь именно ему и что предлагаешь. Шаг 2+ — мягкое "
    "напоминание: новое место письма (пример пользы, вопрос, короткий кейс "
    "БЕЗ выдуманных цифр), ссылка на прошлые письма без упрёков. Подпись — "
    "просто «[Ваше имя]». Отвечаешь СТРОГО одним JSON-объектом без "
    "markdown-заборов."
)


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _model_spec() -> str | None:
    return _env("NJ612_TOUCH_MODEL", "") or None


def _tone_text(tone: str) -> str:
    return TONES.get(tone, TONES["business"])


def _prompt(p: dict) -> str:
    seq = int(p.get("seq") or 1)
    total = int(p.get("total") or 1)
    prev = p.get("prev") or []

    parts = [
        f"Шаг {seq} из {total}.",
        f"Компания: {p.get('company') or 'не указана'}",
    ]

    if p.get("name"):
        parts.append(f"Имя контакта: {p['name']} (приветствие по имени, если уместно)")

    parts.append(f"Что мы предлагаем: {p.get('offer') or ''}")
    parts.append(f"Тон письма: {_tone_text(str(p.get('tone') or 'business'))}")

    if prev:
        parts.append("ПРОШЛЫЕ ПИСЬМА (не повторяй их дословно, развивая мысль):")

        for it in prev:
            parts.append(f"--- шаг {it.get('seq')} ---")
            parts.append(f"Тема: {it.get('subject')}")
            parts.append(str(it.get("body") or "")[:1200])

    if seq == 1:
        parts.append("Задача: первое касание. Зацепка — конкретная польза для этой компании.")
    else:
        parts.append("Задача: вежливый follow-up. Добавь новый угол пользы или задай один точный вопрос; вежливо напомни о прошлом письме.")

    parts.append(
        'Верни JSON: {"subject": "тема письма (без Re:, по-русски, до 90 знаков)", '
        '"body": "текст письма: 1-2 абзаца простым текстом, без markdown и подписи именем"}')

    return "\n".join(parts)


async def handle(ctx, payload: dict) -> dict:
    code = str(payload.get("code") or "")
    seq = int(payload.get("seq") or 0)

    if not code or seq < 1:
        raise RuntimeError("в payload нет code или номера шага")

    if ctx.llm is None:
        raise RuntimeError("LLM-клиент воркера не создан: проверьте настройки провайдера")

    await ctx.progress("Пишу шаг серии", f"{code}, шаг {seq}")

    text, usage = await ctx.llm.chat_with_usage(
        [{"role": "system", "content": SYSTEM},
         {"role": "user", "content": _prompt(payload)}],
        model=_model_spec(), max_tokens=800, request_timeout=180.0)

    log.info("nj612[%s] шаг %s: %s+%s tok",
             code, seq, int(usage.get("prompt_tokens") or 0), int(usage.get("completion_tokens") or 0))

    # разбор ответа: JSON или «почти JSON»
    import json as _json
    import re as _re

    j = None

    try:
        j = _json.loads(text)
    except Exception:  # noqa: BLE001
        m = _re.search(r"\{.*\}", text, _re.S)

        if m:
            try:
                j = _json.loads(m.group(0))
            except Exception:  # noqa: BLE001
                j = None

    if not isinstance(j, dict) or not str(j.get("subject") or "").strip() or not str(j.get("body") or "").strip():
        raise RuntimeError("ИИ вернул письмо без темы или тела")

    subject = str(j["subject"]).strip()[:200]
    body = str(j["body"]).strip()[:6000]

    await ctx.progress("Отправляю черновик", "шаг " + str(seq))

    ok, reason, j = await site.post_json("NJ612_TOUCH", "/step", {
        "code": code, "seq": seq, "subject": subject, "body": body,
    }, json_body=True)

    if not ok:
        raise RuntimeError(f"сайт не принял шаг: {reason[:300]}")

    return {"ok": True, "code": code, "seq": seq, "subject": subject, "chars": len(body)}
