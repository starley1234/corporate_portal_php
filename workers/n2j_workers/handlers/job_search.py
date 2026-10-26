"""Канал 'nj550.jobs': поиск вакансий в городе по направлению (модуль nj550_jobs,

Пайплайн: критерии поиска из payload → подбор вакансий → нормализация записей → результат задачи.
сервис «Работа 2 Оффер» / Job 2 Offer).

Конвейер:
  1) 2–4 аккуратных запроса к поисковику DuckDuckGo (библиотека ddgs; бывшая
     duckduckgo_search) — джоб-борды и сайты компаний. МЕЖДУ ЗАПРОСАМИ ПАУЗА
     (NJ550_SEARCH_DELAY, по умолчанию 2.5 с) — бережём поисковик, чтобы не
     попасть под бан; глубина и число запросов ограничены настройками.
  2) опционально: fetch top-страниц вакансий (httpx «fetchurl», лимит
     NJ550_FETCH_PAGES, по умолчанию выключено) — достаём текст для качества;
  3) LangChain-шаг: LLM отбирает только вакансии/карьерные страницы,
     выкидывает статьи и форумы, сортирует по релевантности и пишет краткое
     резюме рынка. Url-ы фильтруются по входным (анти-галлюцинация).
  4) Деградация: без langchain — сырой список (engine='plain').

Режимы очереди:
  * pg   — результаты пишутся прямо в nj_550_results/nj_550_searches (ctx.pg);
  * http — результат возвращается в job.result, PHP дозапишет
    (Service_Nj550_Jobs::reconcile).

Настройки бережности (окружение / workers/.env):
    NJ550_MAX_QUERIES=3        # поисковых запросов на задачу (<= 4)
    NJ550_RESULTS_PER_QUERY=10 # сырых результатов с запроса
    NJ550_MAX_RESULTS=15       # сколько вакансий отдать пользователю
    NJ550_SEARCH_DELAY=2.5     # пауза между запросами к поисковику, сек
    NJ550_REGION=ru-ru         # регион поисковика
    NJ550_FETCH_PAGES=0        # сколько страниц вакансий докачивать (0 = нет)
    NJ550_FETCH_TIMEOUT=8      # таймаут загрузки страницы, сек
    NJ550_MODEL=               # модель LLM (спека 'провайдер::модель', см. llm.py)

payload (формирует Service_Nj550_Jobs):
    {"search_id": 7, "code": "jb…", "city": "Казань", "direction": "повар"}
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import traceback
from urllib.parse import urlparse

from ..base import HandlerContext

CHANNEL = "nj550.jobs"

log = logging.getLogger("n2j.nj550")

# Бэкенды поиска: джоб-борды + карьерные страницы компаний
QUERY_PATTERNS = [
    "{direction} вакансии {city}",
    "{direction} работа {city} hh.ru OR superjob.ru OR habr.com",
    "{city} компании {direction} вакансии карьера",
    "{direction} {city} свежие вакансии 2026",
]


def _env_float(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name) or default)
    except ValueError:
        return default


def _env_int(name: str, default: int, cap: int | None = None) -> int:
    try:
        value = int(os.environ.get(name) or default)
    except ValueError:
        value = default
    return min(value, cap) if cap is not None else max(0, value)


# ---------------------------------------------------------------------------
# Поисковик (ddgs / duckduckgo_search) — отдельно для тестируемости
# ---------------------------------------------------------------------------

def _ddgs_class():
    from ddgs import DDGS  # новое имя пакета (2025+)
    return DDGS


def ddg_search(query: str, max_results: int, region: str) -> list[dict]:
    """Один запрос к поисковику. Возвращает [{title, href, body}]."""
    DDGS = _ddgs_class()

    with DDGS() as ddgs:
        raw = ddgs.text(query, region=region, safesearch="moderate",
                        max_results=max_results) or []

    return [
        {
            "title": str(r.get("title") or "")[:300],
            "url": str(r.get("href") or r.get("url") or ""),
            "snippet": str(r.get("body") or r.get("description") or "")[:1000],
        }
        for r in raw
        if r.get("href") or r.get("url")
    ]


def _source_of(url: str) -> str:
    try:
        return (urlparse(url).hostname or "").replace("www.", "")
    except ValueError:
        return ""


async def _collect_raw(city: str, direction: str) -> tuple[list[dict], list[str]]:
    """Несколько запросов с паузами (бережный режим). → (items, queries)."""
    max_queries = min(_env_int("NJ550_MAX_QUERIES", 3, cap=len(QUERY_PATTERNS)), len(QUERY_PATTERNS))
    per_query = _env_int("NJ550_RESULTS_PER_QUERY", 10)
    delay = _env_float("NJ550_SEARCH_DELAY", 2.5)
    region = os.environ.get("NJ550_REGION") or "ru-ru"

    items: list[dict] = []
    seen: set[str] = set()
    queries: list[str] = []

    for pattern in QUERY_PATTERNS[:max_queries]:
        query = pattern.format(city=city, direction=direction)
        queries.append(query)

        try:
            found = await asyncio.to_thread(ddg_search, query, per_query, region)
        except Exception:  # noqa: BLE001 — один сбой поиска не убивает задачу
            log.warning("nj550: запрос к поисковику не удался: %s\n%s", query, traceback.format_exc())
            found = []

        for item in found:
            url = item["url"].strip()
            if not url.startswith("http") or url in seen:
                continue
            seen.add(url)
            items.append({**item, "url": url, "source": _source_of(url)})

        log.info("nj550: «%s» → %d новых (всего %d)", query, len(found), len(items))
        await asyncio.sleep(delay)

    return items, queries


# ---------------------------------------------------------------------------
# Fetch страниц вакансий («fetchurl») — выключен по умолчанию
# ---------------------------------------------------------------------------

def _fetch_text(url: str, timeout: float) -> str:
    # SSRF-запрет как в webread.fetch(): внутренние/локальные адреса закрыты,
    # источник URL приходит из поисковой выдачи — пользовательским не считается,
    # но проверять обязаны, раз включаем NJ550_FETCH_PAGES.
    host = urlparse(url).hostname
    if host:
        from .. import webread
        webread.assert_public_host(host)

    import httpx

    r = httpx.get(
        url,
        timeout=timeout,
        follow_redirects=True,
        headers={"User-Agent": "Mozilla/5.0 (X11; Linux x86_64) N2J-JobSearch/1.0"},
    )
    r.raise_for_status()

    text = r.text
    # грубая вычистка HTML: теги и скрипты → пробелы
    import re
    text = re.sub(r"(?is)<(script|style)[^>]*>.*?</\1>", " ", text)
    text = re.sub(r"(?s)<[^>]+>", " ", text)
    return re.sub(r"\s+", " ", text)[:3000]


async def _enrich_with_pages(items: list[dict]) -> None:
    pages = _env_int("NJ550_FETCH_PAGES", 0)
    timeout = _env_float("NJ550_FETCH_TIMEOUT", 8.0)

    for item in items[:pages]:
        try:
            item["snippet"] = (item["snippet"] + " " + await asyncio.to_thread(
                _fetch_text, item["url"], timeout)).strip()[:1200]
            await asyncio.sleep(1.5)  # и к сайтам вакансий не спешим
        except Exception:  # noqa: BLE001 — страница недоступна, живём без неё
            log.info("nj550: страница не скачалась (%s)", item["url"])


# ---------------------------------------------------------------------------
# LangChain-шаг: отбор и ранжирование
# ---------------------------------------------------------------------------

RANK_SYSTEM = (
    "Ты — помощник по поиску работы. Даны результаты веб-поиска (title, url, source, snippet) "
    "по запросу «{direction} в городе {city}».\n"
    'Верни ТОЛЬКО валидный JSON без markdown: {{"summary": "2-3 предложения: что нашлось, '
    'какие компании/сайты, что известно про зарплаты (если упомянуто), один короткий совет", '
    '"jobs": [{{"title": "...", "company": "..." или null, "url": "точный url из входных данных", '
    '"source": "домен", "snippet": "1-2 предложения по существу", "relevance": 0-10}}]}}\n'
    "Правила: оставляй ТОЛЬКО реальные вакансии, карьерные страницы или разделы компаний с "
    "вакансиями по теме; статьи, форумы, рекламу и нерелевантное — отбрасывай; url бери только "
    "из входных данных, ничего не выдумывай; максимум {max_results} позиций, самые релевантные первыми; "
    "relevance: 9-10 — явная вакансия по теме, 6-8 — карьерная страница компании, ниже 6 не включай."
)


def _llm_model(config) -> str:
    return os.environ.get("NJ550_MODEL") or config.llm_model


async def _rank_with_langchain(config, city: str, direction: str,
                               items: list[dict], max_results: int):
    from langchain_core.output_parsers import JsonOutputParser
    from langchain_core.prompts import ChatPromptTemplate
    from langchain_openai import ChatOpenAI

    from ..llm import resolve_endpoint

    base_url, api_key, model, proxy = resolve_endpoint(config, _llm_model(config))
    extra: dict = {}

    if proxy:
        import httpx

        extra["http_client"] = httpx.AsyncClient(proxy=proxy)

    llm = ChatOpenAI(
        model=model or "gpt-4o-mini",
        temperature=0.2,
        timeout=120,
        max_retries=1,
        api_key=api_key or "EMPTY",
        base_url=base_url,
        **extra,
    )

    listing = "\n".join(
        f"{i+1}. title: {it['title']}\n   url: {it['url']}\n   source: {it.get('source', '')}\n"
        f"   snippet: {it['snippet'][:400]}"
        for i, it in enumerate(items[:40])
    )

    chain = ChatPromptTemplate.from_messages(
        [("system", RANK_SYSTEM), ("human", "Результаты поиска:\n{listing}")]
    ) | llm | JsonOutputParser()

    parsed = await chain.ainvoke({
        "direction": direction,
        "city": city,
        "max_results": max_results,
        "listing": listing,
    })

    if not isinstance(parsed, dict):
        raise ValueError("LLM вернула не JSON-объект")

    allowed = {it["url"] for it in items}
    jobs = []
    for job in parsed.get("jobs") or []:
        url = str(job.get("url") or "").strip()
        if url not in allowed:  # анти-галлюцинация: только реальные url поиска
            continue
        jobs.append({
            "title": str(job.get("title") or "")[:300],
            "company": (str(job.get("company"))[:200] if job.get("company") else None),
            "url": url,
            "source": _source_of(url),
            "snippet": str(job.get("snippet") or "")[:1000],
            "relevance": max(0, min(10, int(job.get("relevance") or 0))),
        })

    jobs.sort(key=lambda j: -j["relevance"])
    return parsed.get("summary"), jobs[:max_results], "langchain"


def _plain_jobs(items: list[dict], max_results: int) -> list[dict]:
    return [
        {
            "title": it["title"],
            "company": None,
            "url": it["url"],
            "source": it.get("source", ""),
            "snippet": it["snippet"][:500],
            "relevance": 5,
        }
        for it in items[:max_results]
    ]


# ---------------------------------------------------------------------------
# Запись результата
# ---------------------------------------------------------------------------

async def _save_pg(ctx: HandlerContext, search_id: int, summary: str | None,
                   jobs: list[dict], meta: dict) -> None:
    async with ctx.pg.acquire() as conn:
        async with conn.transaction():
            for job in jobs:
                await conn.execute(
                    "INSERT INTO nj_550_results (search_id, title, company, url, url_hash, source, snippet, relevance) "
                    "VALUES ($1, $2, $3, $4, md5($4), $5, $6, $7) "
                    "ON CONFLICT (search_id, url_hash) DO NOTHING",
                    search_id, job["title"], job.get("company"), job["url"],
                    job.get("source"), job.get("snippet"), job["relevance"],
                )
            await conn.execute(
                "UPDATE nj_550_searches SET status = 'done', summary = $2, "
                "meta = meta || $3::jsonb, finished_at = now(), error = NULL "
                "WHERE id = $1 AND status = 'new'",
                search_id, summary, json.dumps(meta, ensure_ascii=False),
            )
    log.info("nj550: поиск %s записан (%d вакансий)", search_id, len(jobs))


# ---------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    search_id = int(payload.get("search_id") or 0)
    city = (payload.get("city") or "").strip()
    direction = (payload.get("direction") or "").strip()

    if search_id <= 0 or not city or not direction:
        raise ValueError("payload: search_id, city, direction обязательны")

    if ctx.config is None or not getattr(ctx.config, "llm_base_url", ""):
        raise RuntimeError("TOOLFORGE_API_URL не настроен — LLM-шлюз недоступен")

    max_results = _env_int("NJ550_MAX_RESULTS", 15)

    await ctx.progress("Ищу вакансии", f"{direction}, {city}")
    items, queries = await _collect_raw(city, direction)

    if not items:
        raise RuntimeError(
            "Поисковик не вернул результатов (пустой ответ или лимиты) — попробуйте повторить позже"
        )

    if _env_int("NJ550_FETCH_PAGES", 0) > 0:
        await ctx.progress("Читаю страницы вакансий", f"найдено ссылок: {len(items)}")
        await _enrich_with_pages(items)

    engine = "plain"
    summary: str | None = None
    jobs: list[dict]

    try:
        summary, jobs, engine = await _rank_with_langchain(
            ctx.config, city, direction, items, max_results
        )
    except ImportError:
        log.warning("nj550: langchain не установлен — сырой список без ранжирования")
        jobs = _plain_jobs(items, max_results)
    except Exception:  # noqa: BLE001 — отдаём сырой список вместо падения
        log.warning("nj550: LangChain-ранжирование упало, отдаю сырой список:\n%s", traceback.format_exc())
        jobs = _plain_jobs(items, max_results)

    await ctx.progress("Отбираю лучшие вакансии", f"вариантов: {len(items)} → {len(jobs)}")

    meta = {"model": _llm_model(ctx.config), "engine": engine, "queries": queries}

    if ctx.pg is None:
        log.info("nj550: нет pg-пула (http-режим) — результат вернётся в job.result, PHP дозапишет")
    else:
        await _save_pg(ctx, search_id, summary, jobs, meta)

    return {
        "search_id": search_id,
        "code": payload.get("code"),
        "status": "done",
        "summary": summary,
        "jobs": jobs,
        "engine": engine,
        "model": meta["model"],
        "queries": queries,
    }
