"""nj592.email — «Холодное письмо» (N2J.ru /coldmail).

Пайплайн: профиль nj591 или домен → свежие новости о компании → LLM пишет варианты первого абзаца → POST /coldmail/api/item.

Ввод: компания (готовый профиль nj591 в payload ИЛИ домен/название) + что
предлагаем (+ кому, тон). Конвейер на общем инструменте webread (курс на
малые LLM):
  * профиль nj591 в payload → факты уже на руках, ноль обращений к сайту;
  * иначе: официальный сайт (DDG по названию, если надо) → Главная + 1-2
    страницы «Новости/Блог» → Markdown ≤1600 знаков каждая;
  * свежие новости — DDG (бережно, 2 запроса, топ-3, не критично);
  * ОДИН LLM-вызов: N вариантов {subject, paragraph, why}; анти-галлюцинации:
    факты персонализации — только из материалов; нет фактов — честный
    нейтральный заход без выдумок.

Env: NJ592_EMAIL_API_BASE (напр. https://n2j.ru/coldmail/api),
NJ592_EMAIL_SITE_TOKEN (или общий WORKER_API_TOKEN),
NJ592_EMAIL_MODEL (спека «провайдер::модель»), NJ592_EMAIL_MAX_VARIANTS (5).
"""

from __future__ import annotations

import logging
import os
import time

from .. import site
from .. import webread
from .nj591_profile import _md_cap as _nj591_md_cap
from .nj591_profile import _resolve_site

log = logging.getLogger("n2j.nj592")

CHANNEL = "nj592.email"


def _model() -> str:
    return os.getenv("NJ592_EMAIL_MODEL", "").strip()


def _max_variants() -> int:
    try:
        return max(1, min(5, int(os.getenv("NJ592_EMAIL_MAX_VARIANTS", "5"))))
    except ValueError:
        return 5


# ---------------------------------------------------------------------------
# Промпт (строгая JSON-схема; анти-галлюцинации — как в nj580/nj591)
# ---------------------------------------------------------------------------

_SYSTEM = {
    "business": "Тон: деловой, спокойный, по делу.",
    "friendly": "Тон: дружелюбный, живой, но профессиональный.",
    "bold": "Тон: смелый, цепляющий, без канцелярита.",
}

SYSTEM_COLDMAIL = (
    "Ты пишешь ПЕРВЫЙ АБЗАЦ холодного B2B-письма (и тему письма). Верни СТРОГО JSON без markdown: "
    '{"variants": [{"subject": "тема письма (до 60 знаков)", '
    '"paragraph": "первый абзац, 2-4 предложения", '
    '"why": "одно предложение: на каком факте построен крючок"}]}. '
    "ЖЁСТКИЕ ПРАВИЛА ПЕРСОНАЛИЗАЦИИ: "
    "(1) крючок — только факты из материалов ниже (сайт, профиль, новости); НЕ выдумывай события, "
    "цифры, награды, клиентов и названия продуктов компании; "
    "(2) если фактов нет — нейтральный заход по отрасли компании (отрасль видна из материалов), "
    "без фальшивой похвалы; "
    "(3) запрещены штампы: «надеюсь, письмо нашло вас хорошо», «не спамлю», «по объединению наших компаний»; "
    "(4) абзац подводит к предложению отправителя, но НЕ продаёт внутри абзаца; "
    "(5) язык — как в «что предлагаем»; "
    "каждый вариант отличается крючком, не только формулировкой."
)


# ---------------------------------------------------------------------------
# Сайт
# ---------------------------------------------------------------------------

async def _api_progress(ctx, letter_id: int, phase: str) -> None:
    # Тот же этап — в журнал очереди: этап виден в мониторе nj120,
    # «Моих задачах» и статус-ряде модуля (единый механизм /trends).
    # Сбой журнала прогресс не отменяет: своя страница важнее.
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    ok, reason, _j = await site.post_json("NJ592_EMAIL", "/progress",
                                          {"letter_id": str(letter_id), "phase": phase[:300]},
                                          timeout=30, retries=1)

    if not ok and not ("_API_BASE не задан" in reason or "токен сайта не задан" in reason):
        log.warning("nj592: api/progress -> %s", reason)


async def _api_result(ctx, letter_id: int, result: dict, sources: list) -> bool:
    ok, reason, _j = await site.post_json("NJ592_EMAIL", "/result", {
        "letter_id": str(letter_id),
        "result": __import__("json").dumps(result, ensure_ascii=False),
        "sources": __import__("json").dumps(sources, ensure_ascii=False),
    })
    return ok, reason


# ---------------------------------------------------------------------------
# Факты о компании
# ---------------------------------------------------------------------------

_NEWS_PATTERNS = [r"news|novost|новост|blog|блог|press|пресс|media|медиа|company-life"]


async def _profile_knowledge(profile: dict, sources: list) -> str:
    """Профиль nj591 (из payload) → компактный текст фактов (без обращений к сайту)."""
    res = profile.get("result") or {}
    lines = [f"Компания: {profile.get('company') or 'название не определено'}"]

    if profile.get("domain"):
        lines.append(f"Сайт: {profile['domain']}")

    for key, label in (("sphere", "Сфера"), ("summary", "Описание"),
                       ("size_estimate", "Размер")):
        v = str(res.get(key) or "").strip()

        if v and v != "не найдено":
            lines.append(f"{label}: {v}")

    contacts = res.get("contacts") or {}

    if isinstance(contacts, dict):
        bits = []

        for e in (contacts.get("emails") or [])[:3]:
            bits.append(str(e))

        for p in (contacts.get("phones") or [])[:3]:
            bits.append(str(p))

        if bits:
            lines.append("Контакты: " + ", ".join(bits))

    dms = res.get("decision_makers") or []

    if dms:
        lines.append("ЛПР на сайте: " + "; ".join(
            f"{d.get('name', '')} ({d.get('role', '')})" for d in dms[:5] if d.get("name")))

    tech = res.get("tech_stack") or []

    if tech:
        lines.append("Стек: " + ", ".join(map(str, tech[:10])))

    for s in (profile.get("sources") or [])[:6]:
        sources.append({"type": "profile", "url": str(s.get("url") or ""), "title": str(s.get("title") or "")})

    return "\n".join(lines)


async def _fresh_knowledge(kind: str, domain: str, name: str, sources: list) -> str:
    """Без профиля: сайт (главная + 1-2 новостные) + DDG-новости."""
    lines: list[str] = []
    site_md = ""

    if domain or name:
        try:
            await _emit_progress_src(kind, domain, name)
        except Exception:  # noqa: BLE001
            pass

    if domain or name:
        base_url = await _resolve_site(kind, domain, name)
        html, title = await webread.fetch(base_url)
        md, _ = await webread.to_markdown(html, cap=_nj591_md_cap())
        site_md = f"## Главная: {base_url}\n{md}"
        sources.append({"type": "page", "url": base_url, "title": title or base_url})
        lines.append(f"Сайт компании ({base_url}):\n{site_md}")

        news_links = webread.extract_links(html, base_url, _NEWS_PATTERNS, limit=2)

        for link in news_links:
            try:
                nhtml, _ = await webread.fetch(link["url"])
            except webread.WebReadError:
                continue

            nmd, _ = await webread.to_markdown(nhtml, cap=1200)

            if len(nmd) >= 80:
                lines.append(f"## Страница новостей: {link['url']}\n{nmd}")
                sources.append({"type": "page", "url": link["url"], "title": link["label"] or link["url"]})

    # DDG-новости: 2 бережных запроса, не критичны
    query_domain = f"{domain} новости" if domain else ""
    query_name = f'"{name}" новости' if name else ""

    for q in (query_domain, query_name):
        if not q:
            continue

        rows = await webread.ddg_text(q, max_results=3, timeout_sec=45.0)

        for r in rows:
            title = str(r.get("title") or "").strip()
            body = str(r.get("body") or "").strip()[:220]
            url = str(r.get("href") or r.get("url") or "").strip()

            if title:
                lines.append(f"- Новость: {title}. {body} ({url})")
                sources.append({"type": "news", "url": url, "title": title})

    return "\n\n".join(lines)[:18000]


async def _emit_progress_src(kind: str, domain: str, name: str) -> None:
    """Заглушка для совместимости прогресса (фаза ставится в handle)."""


# ---------------------------------------------------------------------------
# Обработчик канала
# ---------------------------------------------------------------------------

async def handle(ctx, payload: dict) -> dict:
    letter_id = int(payload.get("letter_id") or 0)
    code = str(payload.get("code") or "")
    kind = str(payload.get("kind") or "domain")
    domain = str(payload.get("domain") or "").strip()
    name = str(payload.get("name") or "").strip()
    profile = payload.get("profile") if isinstance(payload.get("profile"), dict) else None
    product = str(payload.get("product") or "").strip()[:2000]
    audience = str(payload.get("audience") or "").strip()[:300]
    tone = str(payload.get("tone") or "business")
    n = max(1, min(_max_variants(), int(payload.get("n") or 3)))

    if not letter_id:
        raise ValueError("nj592: payload должен содержать letter_id")

    if not product:
        raise ValueError("nj592: не указано предложение (product)")

    if ctx.llm is None:
        raise RuntimeError("nj592: LLM-провайдеры не настроены (workers/.env)")

    sink: list = []
    job_id = ctx._current_job["id"] if ctx._current_job else 0
    sources: list = []

    # --- 1. факты о компании -------------------------------------------------
    if profile:
        await _api_progress(ctx, letter_id, "Беру готовый профиль компании…")
        knowledge = await _profile_knowledge(profile, sources)
        log.info("nj592[%s] профиль %s: %d знаков фактов", code, profile.get("domain"), len(knowledge))
    else:
        await _api_progress(ctx, letter_id, "Изучаю компанию (сайт и новости)…")
        await ctx.heartbeat()
        knowledge = await _fresh_knowledge(kind, domain, name, sources)

    if len(knowledge.strip()) < 40:
        raise webread.WebReadError(
            "Не удалось собрать факты о компании (сайт недоступен?) — укажите домен или соберите профиль сначала")

    # --- 2. один LLM-вызов ----------------------------------------------------
    await _api_progress(ctx, letter_id, "Пишу варианты первого абзаца…")
    await ctx.heartbeat()

    user = (f"Что предлагаем отправителю: {product}\n"
            + (f"Кому пишете: {audience}\n" if audience else "")
            + f"Вариантов нужно: {n}\n\n"
            + "Материалы о компании (единственный источник фактов):\n\n" + knowledge)

    t0 = time.monotonic()
    kw = {"model": _model() or None, "max_tokens": 2500, "request_timeout": 300}
    text, usage = await ctx.llm.chat_with_usage(
        [{"role": "system", "content": SYSTEM_COLDMAIL + " " + _SYSTEM.get(tone, _SYSTEM["business"])},
         {"role": "user", "content": user}], **kw)
    dt = int((time.monotonic() - t0) * 1000)

    pt = int(usage.get("prompt_tokens") or 0)
    ct = int(usage.get("completion_tokens") or 0)
    model_used = _model() or ctx.config.llm_model or "default"
    log.info("nj592[coldmail] model=%s %d+%d tok, %d ms", model_used, pt, ct, dt)

    sink.append({"letter_id": letter_id, "phase": "coldmail", "model": model_used,
                 "prompt_tokens": pt, "completion_tokens": ct, "duration_ms": dt})

    obj = webread.json_from_text(text)
    raw_variants = obj.get("variants") if obj else None

    if not isinstance(raw_variants, list) or not raw_variants:
        raise ValueError(f"Модель вернула не JSON/без вариантов (начало: {text[:120]!r})")

    variants = []

    for v in raw_variants[:n]:
        if not isinstance(v, dict):
            continue

        para = str(v.get("paragraph") or "").strip()[:2500]

        if len(para) < 40:
            continue

        variants.append({
            "subject": str(v.get("subject") or "").strip()[:120],
            "paragraph": para,
            "why": str(v.get("why") or "").strip()[:400],
        })

    if not variants:
        raise ValueError("Модель вернула только пустые абзацы")

    result = {"variants": variants}

    # --- 3. выгрузка ----------------------------------------------------------
    await _api_progress(ctx, letter_id, "Сохраняю варианты…")

    ok, why = await _api_result(ctx, letter_id, result, sources)

    if not ok:
        raise RuntimeError(f"Сайт не принял варианты — {why}")

    return {"usage": sink, "variants": len(variants)}
