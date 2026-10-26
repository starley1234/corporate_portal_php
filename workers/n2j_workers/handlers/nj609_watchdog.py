"""nj609.watchdog — Сторож контрагентов: сигналы риска по одной компании.

Пайплайн: 3 ddgs-запроса (суды, банкротство, новости) → чтение топ-3 страниц → LLM фильтрует реальные события → POST /watchdog/api/events.

ddgs: суды/арбитраж, банкротство, изменения/новости (3 запроса) → чтение
топ-3 страниц → LLM фильтрует шум и возвращает только реальные события
(severity risk/warn/info) → POST /watchdog/api/events.

Env: NJ609_WATCHDOG_API_BASE, NJ609_WATCHDOG_SITE_TOKEN (или WORKER_API_TOKEN).
"""

from __future__ import annotations

import logging
import os


from .. import webread, site
from ..llmchat import chat as _llm
from .nj590_design import _readable_reason
from .nj598_dossier import _host_of

log = logging.getLogger("n2j.nj609")

CHANNEL = "nj609.watchdog"

SYSTEM = (
    "Ты риск-аналитик. Дают компанию и находки из интернета. Отбери ТОЛЬКО "
    "реальные события о ЭТОЙ компании (проверяй название/ИНН в находке — "
    "однофамильцев не включай): суды и арбитражи, банкротство, ликвидация/"
    "реорганизация, смена руководства, крупные негативные новости (массовые "
    "увольнения, отзыв лицензии, аварии). severity: risk — прямой финансовый "
    "риск (банкротство, иск на крупную сумму), warn — настораживает, info — "
    "важно знать. summary — 1-2 предложения по-русски. Пустых выдумок нет: "
    "нет событий — верни пустой список. Ответ — ТОЛЬКО JSON: {\"events\": "
    "[{\"kind\": \"суд|банкротство|егрюл|новость\", \"severity\": \"risk|warn|info\", "
    "\"title\": \"...\", \"url\": \"URL из находок\", \"summary\": \"...\"}]}."
)



async def _post(ctx, path: str, data: dict) -> dict:
    """POST на сайт через общий слой site.post_json; ошибка = RuntimeError."""
    ok, reason, j = await site.post_json("NJ609_WATCHDOG", path, data)

    if not ok:
        if "_API_BASE не задан" in reason or "токен сайта не задан" in reason:
            log.info("nj609_watchdog: API сайта не настроен — отправка пропущена")
            return {}

        raise RuntimeError(f"nj609_watchdog: сайт {path} → {reason}")

    return j if isinstance(j, dict) else {}


async def handle(ctx, payload: dict) -> dict:
    """Проверить одного контрагента (payload: subject_id, company, inn)."""
    subject_id = int(payload.get("subject_id") or 0)
    company = str(payload.get("company") or "").strip()[:300]
    inn = str(payload.get("inn") or "").strip()

    if not subject_id or len(company) < 3:
        raise RuntimeError("nj609_watchdog: в задаче нет subject_id или компании")

    # Этапы — в журнал очереди: монитор и страница модуля показывают, что идёт
    await ctx.progress("Проверяю контрагента", company)

    # --- 1. поиск: 3 запроса ----------------------------------------------------
    import asyncio

    queries = [
        f"{company} суд арбитраж иск",
        f"{company} банкротство ликвидация",
        f"{company}" + (f" ИНН {inn}" if inn else "") + " новости",
    ]

    sources: dict[str, dict] = {}

    for qi, q in enumerate(queries, 1):
        await ctx.progress("Ищу события", f"{qi}/{len(queries)}: {q[:80]}")
        rows = await webread.ddg_text(q, max_results=6)

        for r in rows:
            url = str(r.get("url") or r.get("href") or "").strip()
            host = _host_of(url)

            if not url.startswith("http") or not host or host in sources:
                continue

            sources[host] = {"url": url,
                             "title": str(r.get("title") or "")[:200],
                             "snippet": str(r.get("body") or "")[:400]}

        await asyncio.sleep(1.5)

    if not sources:
        return {"ok": True, "saved": 0, "note": "источников не нашлось — тишина в открытых источниках"}

    # --- 2. читаем топ-3 страницы -------------------------------------------------
    blocks: list[dict] = []

    for s in list(sources.values())[:3]:
        try:
            html, _ = await webread.fetch(s["url"])
            md, title = await webread.to_markdown(html, cap=1200)
        except Exception as e:  # noqa: BLE001
            log.info("nj609_watchdog: не прочитали %s: %s", s["url"], _readable_reason(str(e)))
            continue

        if len(md) >= 200:
            blocks.append({**s, "title": title or s["title"], "md": md})

    # сниппеты поиска тоже отдаём в промпт (дёшево и полезно)
    parts = [f"КОМПАНИЯ: {company}" + (f" (ИНН {inn})" if inn else ""), "",
             "НАХОДКИ (заголовки/сниппеты поиска):"]

    for s in sources.values():
        parts.append(f"- {s['title']} ({_host_of(s['url'])}): {s.get('snippet', '')}")

    if blocks:
        parts.append("")
        parts.append("ПОЛНЫЕ ТЕКСТЫ СТРАНИЦ:")

        for b in blocks:
            parts.append(f"--- {b['title']} ({_host_of(b['url'])}) ---")
            parts.append(b["md"])

    # --- 3. LLM фильтрует -----------------------------------------------------------
    await ctx.progress("ИИ отбирает события", f"источников: {len(sources)}, прочитано: {len(blocks)}")

    raw = await _llm(ctx, [{"role": "system", "content": SYSTEM},
                           {"role": "user", "content": "\n".join(parts)}],
                     "nj609_watchdog", model_env="NJ609_WATCHDOG_MODEL", max_tokens=2000, timeout=300)

    parsed = webread.json_from_text(raw)

    if not isinstance(parsed, dict) or not isinstance(parsed.get("events"), list):
        raise RuntimeError("ИИ вернул не JSON со списком events")

    known = {s["url"] for s in sources.values()} | {b["url"] for b in blocks}
    events = []

    for ev in parsed["events"][:20]:
        if not isinstance(ev, dict):
            continue

        url = str(ev.get("url") or "").strip()

        if url in known:
            events.append({
                "kind": str(ev.get("kind") or "")[:30],
                "severity": str(ev.get("severity") or "info"),
                "title": str(ev.get("title") or "")[:300],
                "url": url,
                "summary": str(ev.get("summary") or "")[:1000],
            })

    if not events:
        await ctx.progress("Готово", "событий риска не найдено")
        return {"ok": True, "saved": 0, "note": "событий риска не найдено"}

    await ctx.progress("Отправляю события", f"найдено: {len(events)}")

    res = await _post(ctx, "/events", {"subject_id": subject_id,
                                       "events": __import__("json").dumps(events, ensure_ascii=False)})
    log.info("nj609_watchdog[%s]: событий %d, сайт принял %s", subject_id, len(events), res.get("saved", "?"))

    return {"ok": True, "found": len(events), "saved": res.get("saved", 0)}
