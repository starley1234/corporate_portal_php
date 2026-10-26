"""nj607.industry — Карта отрасли: авто-отчёт по производителям.

Пайплайн: 9 ddgs-запросов → чтение до 10 страниц → LLM синтезирует Markdown-отчёт → POST /industry/api/result.

Глубже, чем nj606: 9 поисковых запросов, до 10 страниц, LLM синтезирует
связный Markdown-отчёт: игроки, что и как производят, сегменты/цены,
каналы, возможности входа. Прогресс → /industry/api/progress,
результат → /industry/api/result, ошибка → /industry/api/fail.

Env: NJ607_INDUSTRY_API_BASE, NJ607_INDUSTRY_SITE_TOKEN (или WORKER_API_TOKEN),
NJ607_INDUSTRY_MAX_SITES (10).
"""

from __future__ import annotations

import logging
import os


from .. import site
from .. import webread
from ..llmchat import chat as _llm
from .nj590_design import _readable_reason
from .nj598_dossier import _host_of

log = logging.getLogger("n2j.nj607")

CHANNEL = "nj607.industry"

SYSTEM = (
    "Ты аналитик рынков. Из находок в интернете составь КАРТУ ОТРАСЛИ строго "
    "по находкам (без домыслов, данные не названы — честно пиши «нет данных»). "
    "Структура отчёта (Markdown, по-русски):\n"
    "## Кто на рынке — список игроков: название, сайт, чем занимаются (каждый с новой строки)\n"
    "## Что и как производят — продукты, технологии, масштаб по признакам из находок\n"
    "## Сегменты и цены — где видны цены/сегменты; если нет — написать, что данных нет\n"
    "## Каналы и клиенты — как продают, кто покупает (по находкам)\n"
    "## Возможности входа — 3-5 конкретных ниш/ходов, которые вырисовываются из находок\n"
    "Ссылки на источники указывай в тексте как (domain.ru). Ответ — ТОЛЬКО Markdown."
)


def _max_sites() -> int:
    try:
        return max(1, int(os.environ.get("NJ607_INDUSTRY_MAX_SITES", "10")))
    except ValueError:
        return 10


async def _post(ctx, path: str, code: str, data: dict) -> dict:
    """POST на сайт через общий слой site.post_json (путь вида /{path}/{code})."""
    ok, reason, j = await site.post_json("NJ607_INDUSTRY", f"/{path}/{code}", data)

    if not ok:
        if "_API_BASE не задан" in reason or "токен сайта не задан" in reason:
            log.info("nj607_industry: API сайта не настроен — отправка пропущена")
            return {}

        raise RuntimeError(f"nj607_industry: {path}/{code} → {reason}")

    return j if isinstance(j, dict) else {}


async def handle(ctx, payload: dict) -> dict:
    """Собрать карту отрасли (payload: report_id, code, industry, region)."""
    report_id = int(payload.get("report_id") or 0)
    code = str(payload.get("code") or "")
    industry = str(payload.get("industry") or "").strip()[:300]
    region = str(payload.get("region") or "").strip()[:120]

    if not report_id or len(industry) < 3:
        raise RuntimeError("nj607_industry: в задаче нет report_id или отрасли")

    async def progress(phase: str) -> None:
        # Свой прогресс карты отрасли (страница /industry/<code>) + журнал
        # очереди: этап виден в мониторе nj120 и в «Моих задачах» — единый
        # статус-ряд, как на /trends/<code>.
        try:
            await ctx.progress(phase)
        except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
            pass

        await _post(ctx, "progress", code, {"phase": phase[:300]})

    async def fail(reason: str) -> None:
        # сайт обязан увидеть финал, иначе отчёт висит «running» навсегда
        await _post(ctx, "fail", code, {"error": reason[:500]})

    try:
        return await _run(ctx, report_id, code, industry, region, progress, fail)
    except Exception as e:  # noqa: BLE001 — любая причина должна дойти до сайта
        log.warning("nj607_industry[%s]: сбор не удался: %s", code, str(e)[:200])

        try:
            await fail(str(e))
        except Exception:  # noqa: BLE001 — сайт мог быть недоступен
            pass

        raise


async def _run(ctx, report_id: int, code: str, industry: str, region: str,
               progress, fail) -> dict:
    # --- 1. поиск: 3 группы по 3 запроса ---------------------------------------
    await progress("Ищу производителей отрасли…")

    groups = [
        [f"{industry} {region} производители", f"{industry} крупнейшие компании", f"{industry} заводы Россия"],
        [f"{industry} каталог производителей", f"{industry} выставка участники", f"{industry} ассоциация члены"],
        [f"{industry} обзор рынок 2026", f"{industry} цены производителей", f"{industry} экспорт поставки"],
    ]

    sources: dict[str, dict] = {}

    import asyncio

    for group in groups:
        for q in group:
            rows = await webread.ddg_text(q, max_results=8)

            for r in rows:
                url = str(r.get("url") or r.get("href") or "").strip()
                host = _host_of(url)

                if not url.startswith("http") or not host or host in sources:
                    continue

                sources[host] = {"url": url, "title": str(r.get("title") or "")[:200]}

            await asyncio.sleep(1.0)

    if not sources:
        raise RuntimeError("Поиск не дал источников по отрасли — уточните формулировку.")

    # --- 2. читаем до 10 страниц ------------------------------------------------
    await progress(f"Читаю источники ({min(len(sources), _max_sites())} шт.)…")

    blocks: list[dict] = []

    for s in list(sources.values())[:_max_sites()]:
        try:
            html, _ = await webread.fetch(s["url"])
            md, title = await webread.to_markdown(html, cap=1800)
        except Exception as e:  # noqa: BLE001
            log.info("nj607_industry: не прочитали %s: %s", s["url"], _readable_reason(str(e)))
            continue

        if len(md) >= 200:
            blocks.append({"url": s["url"], "title": title or s["title"], "md": md})

    if not blocks:
        raise RuntimeError("Ни один источник не удалось прочитать.")

    # --- 3. синтез отчёта --------------------------------------------------------
    await progress("ИИ собирает карту отрасли…")

    parts = [f"ОТРАСЛЬ: {industry}" + (f"; регион: {region}" if region else ""), "",
             "НАХОДКИ ИЗ ИСТОЧНИКОВ:"]

    for b in blocks:
        parts.append(f"--- {b['title']} ({_host_of(b['url'])}) ---")
        parts.append(b["md"])

    raw = await _llm(ctx, [{"role": "system", "content": SYSTEM},
                           {"role": "user", "content": "\n".join(parts)}],
                     "nj607_industry", model_env="NJ607_INDUSTRY_MODEL", max_tokens=4000, timeout=480)

    if len(raw.strip()) < 500:
        raise RuntimeError("Отчёт получился подозрительно коротким — повторите сбор")

    await _post(ctx, "result", code, {"report_md": raw, "model_used": ""})
    log.info("nj607_industry[%s]: отчёт готов (%d зн., источников %d)", code, len(raw), len(blocks))

    return {"ok": True, "chars": len(raw), "sources": len(blocks)}
