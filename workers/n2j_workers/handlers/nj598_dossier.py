"""nj598.dossier — AI Досье на работодателя (N2J.ru /dossier).

Пайплайн: серия ddgs-поисков по компании → чтение топ-страниц webread → один LLM-вызов со строгой JSON-схемой → POST /dossier/api/item.

Company Intelligence для соискателя: по названию компании (или ссылке на
вакансию) агент делает бережную серию DuckDuckGo-поисков:
  1) отзывы сотрудников (Хабр Карьера, Dream Job, форумы) — боты отсеиваются
     эвристикой доменов/повторов;
  2) техстатьи инженеров компании — реальный стек и «боли»;
  3) новости: суды, сокращения, инвестиции.
Топ-страницы читаются конвейером webread (curl_cffi → trafilatura →
Markdown ≤ cap; сырой HTML в промпт НЕ попадает). ОДИН LLM-вызов со строгой
JSON-схемой; анти-галлюцинации: только находки из источников, данных мало →
честное «данных мало», пустые списки.
Результат → /dossier/api/result, прогресс → /dossier/api/progress.

Env: NJ598_DOSSIER_API_BASE (https://n2j.ru/dossier/api),
NJ598_DOSSIER_SITE_TOKEN (или WORKER_SITE_TOKEN → WORKER_API_TOKEN),
NJ598_DOSSIER_MODEL (спека «провайдер::модель»),
NJ598_DOSSIER_MD_CAP (1800), NJ598_DOSSIER_MAX_SITES (8),
NJ598_DOSSIER_SEARCH_DELAY (2.5) — бережный ddgs, не ужесточать.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import time
from urllib.parse import urlparse

from .. import site
from .. import webread

log = logging.getLogger("n2j.nj598")

CHANNEL = "nj598.dossier"


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _md_cap() -> int:
    try:
        return max(600, min(4000, int(_env("NJ598_DOSSIER_MD_CAP", "1800"))))
    except ValueError:
        return 1800


def _max_sites() -> int:
    try:
        return max(3, min(12, int(_env("NJ598_DOSSIER_MAX_SITES", "8"))))
    except ValueError:
        return 8


def _delay() -> float:
    try:
        return max(1.0, float(_env("NJ598_DOSSIER_SEARCH_DELAY", "2.5")))
    except ValueError:
        return 2.5


def _model_spec(payload: dict) -> str:
    return (str(payload.get("model") or "").strip()[:120]
            or _env("NJ598_DOSSIER_MODEL"))


# ---------------------------------------------------------------------------
# API сайта (токен воркера; ошибки — читаемым текстом)
# ---------------------------------------------------------------------------

async def _post_site(ctx, path: str, data: dict) -> tuple[bool, str, dict]:
    """POST на сайт. → (ok, причина, json-ответ). Две попытки на сеть."""
    return await site.post_json("NJ598_DOSSIER", path, data)


async def _api_progress(ctx, dossier_id: int, phase: str) -> None:
    # Тот же этап — в журнал очереди: этап виден в мониторе nj120,
    # «Моих задачах» и статус-ряде модуля (единый механизм /trends).
    # Сбой журнала прогресс не отменяет: своя страница важнее.
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    await _post_site(ctx, "/progress", {"dossier_id": str(dossier_id), "phase": phase[:300]})


# ---------------------------------------------------------------------------
# Поиск (бережный ddgs) и чтение источников (webread)
# ---------------------------------------------------------------------------

REVIEW_HOSTS = ("habr.com", "career.habr.com", "dreamjob.ru", "otzovik.com",
                "irecommend.ru", "reddit.com", "glassdoor.com", "indeed.com")

QUERY_GROUPS: list[tuple[str, list[str]]] = [
    ("отзывы", [
        "{c} отзывы сотрудников",
        "{c} отзывы работающих habr карьера",
        "{c} отзывы dream job",
    ]),
    ("статьи", [
        "{c} инженерная статья стек технологии",
        "{c} tech blog engineering",
        "работаю в {c} опыт инженера",
    ]),
    ("новости", [
        "{c} суд иск арбитраж",
        "{c} сокращения увольнения",
        "{c} инвестиции раунд новости",
    ]),
]

_SKIP_HOSTS = ("vk.com", "t.me", "youtube.com", "pikabu.ru", "twitter.com", "x.com")


def _host_of(url: str) -> str:
    try:
        return (urlparse(url).hostname or "").replace("www.", "").lower()
    except ValueError:
        return ""


async def _search_group(company: str, group: str, queries: list[str]) -> list[dict]:
    """3 запроса группы с паузами → [{url, title, snippet, kind}]."""
    out: list[dict] = []
    seen: set[str] = set()

    for q in queries:
        query = q.format(c=company)

        try:
            rows = await webread.ddg_text(query, max_results=8)
        except Exception:  # noqa: BLE001 — сбой одного запроса не убивает задачу
            log.warning("nj598: поиск не удался: %s", query, exc_info=True)
            rows = []

        for r in rows:
            url = str(r.get("url") or r.get("href") or "").strip()
            host = _host_of(url)

            if not url.startswith("http") or url in seen or not host:
                continue
            if host in _SKIP_HOSTS:
                continue
            if group == "отзывы" and not any(h in host for h in REVIEW_HOSTS) \
                    and "отзыв" not in (str(r.get("title") or "") + str(r.get("body") or "")).lower():
                continue

            seen.add(url)
            out.append({"url": url, "title": str(r.get("title") or "")[:200],
                        "snippet": str(r.get("body") or "")[:500], "kind": group})

        await asyncio.sleep(_delay())

    log.info("nj598[%s]: %d источников", group, len(out))
    return out


async def _read_pages(sources: list[dict], max_sites: int) -> list[dict]:
    """Читаем топ-страницы конвейером webread (Markdown ≤ cap)."""
    blocks: list[dict] = []

    for i, s in enumerate(sources[:max_sites]):
        try:
            webread.assert_public_host(_host_of(s["url"]))
            html, _ = await webread.fetch(s["url"])
            md, title = await webread.to_markdown(html, cap=_md_cap())
        except Exception as e:  # noqa: BLE001
            log.warning("nj598: не прочитали %s: %s", s["url"], e.__class__.__name__)
            continue

        if len(md) < 200:
            continue

        blocks.append({**s, "title": title or s["title"], "md": md})
        log.info("nj598: прочитан %s (%d симв.)", s["url"], len(md))
        await asyncio.sleep(1.0)

    return blocks


# ---------------------------------------------------------------------------
# LLM: строгий JSON по находкам
# ---------------------------------------------------------------------------

SYSTEM = (
    "Ты аналитик-разведчик работодателей. Тебе дают находки из интернета "
    "(отзывы сотрудников, техстатьи, новости) о компании. Строго по находкам "
    "составь JSON БЕЗ домыслов. Правила: каждая позиция red_flags/pros — "
    "объект {\"text\": суть по-русски, \"source\": домен источника}; если "
    "находок мало — пустой список и в cheatsheet.about честно напиши "
    "«открытых данных мало». Не выдумывай факты, имена, цифры. В summary дай краткое резюме компании "
    "(3-5 предложений: кто такие, чем занимаются, масштаб, финансовое положение, "
    "что главное для кандидата). В identifiers внеси ИНН/ОГРН/ОГРНИП только если "
    "они реально встречаются в находках, иначе пустые строки. Вопросы "
    "questions_to_ask — чтоCandidate должен спросить тимлида с упором на "
    "НАЙДЕННЫЕ боли компании. likely_screen_questions — типичные для их стека "
    "вопросы скрининга (если стек известен из находок). Ответ — ТОЛЬКО JSON."
)


def _prompt(blocks: list[dict], company: str) -> str:
    parts = [f"Компания: {company}", "", "НАХОДКИ ИЗ ИСТОЧНИКОВ:"]

    for b in blocks:
        parts.append(f"--- [{b['kind']}] {b['title']} ({_host_of(b['url'])}) ---")
        parts.append(b["md"][:_md_cap()])

    parts.append("")
    parts.append(
        'Верни JSON: {"company": "...", '
        '"summary": "краткое резюме 3-5 предложений", '
        '"identifiers": {"inn": "...", "ogrn": "...", "ogrnip": "..."}, '
        '"red_flags": [{"text": "...", "source": "домен"}], '
        '"pros": [{"text": "...", "source": "домен"}], '
        '"cheatsheet": {"about": "2-4 предложения о компании строго по находкам", '
        '"questions_to_ask": ["5-7 вопросов по их реальным болям"], '
        '"likely_screen_questions": ["5-8 вероятных вопросов скрининга"]}, '
        '"sources": [{"url": "...", "title": "...", "kind": "отзывы|статьи|новости"}]}')

    return "\n".join(parts)


# ---------------------------------------------------------------------------
# handle
# ---------------------------------------------------------------------------

async def handle(ctx, payload: dict) -> dict:
    dossier_id = int(payload.get("dossier_id") or 0)
    code = str(payload.get("code") or "")
    company = str(payload.get("company") or "").strip()[:200]
    model_spec = _model_spec(payload)

    if not company:
        raise RuntimeError("пустое название компании в задаче")

    sink_usage: list[dict] = []

    async def _chat(messages: list[dict], phase: str, max_tokens: int,
                    timeout: float = 420.0) -> str:
        t0 = time.monotonic()
        text, usage = await ctx.llm.chat_with_usage(messages, model=model_spec or None,
                                                    max_tokens=max_tokens,
                                                    request_timeout=timeout)
        dt = int((time.monotonic() - t0) * 1000)
        model_real = str(usage.get("_llm_model") or "") or (model_spec or "default")
        note = str(usage.get("_llm_note") or "")
        log.info("nj598[%s] model=%s%s %d+%d tok, %d ms",
                 phase, model_real, (" — " + note) if note else "",
                 int(usage.get("prompt_tokens") or 0), int(usage.get("completion_tokens") or 0), dt)
        sink_usage.append({
            "phase": phase, "model": model_real,
            "prompt_tokens": int(usage.get("prompt_tokens") or 0),
            "completion_tokens": int(usage.get("completion_tokens") or 0),
        })

        return text

    # --- 1. поиски ----------------------------------------------------------
    await _api_progress(ctx, dossier_id, "Ищу отзывы сотрудников, техстатьи и новости…")

    sources: list[dict] = []
    seen: set[str] = set()

    for group, queries in QUERY_GROUPS:
        for s in await _search_group(company, group, queries):
            if s["url"] not in seen:
                seen.add(s["url"])
                sources.append(s)

    # приоритет: отзывы → статьи → новости
    sources.sort(key=lambda s: {"отзывы": 0, "статьи": 1, "новости": 2}.get(s["kind"], 3))

    if not sources:
        raise RuntimeError(
            "Поиск не дал ни одного источника по компании "
            f"«{company}». Проверьте написание или укажите домен компании.")

    # --- 2. чтение страниц --------------------------------------------------
    await _api_progress(ctx, dossier_id, f"Читаю источники (топ-{min(_max_sites(), len(sources))})…")

    blocks = await _read_pages(sources, _max_sites())

    if not blocks:
        raise RuntimeError(
            "Ни один источник не удалось прочитать (страницы недоступны или "
            "закрыты от роботов). Попробуйте позже или укажите другой источник.")

    # --- 3. LLM-синтез ------------------------------------------------------
    await _api_progress(ctx, dossier_id, "ИИ собирает досье: флаги, плюсы, шпаргалка…")

    raw = await _chat([{"role": "system", "content": SYSTEM},
                       {"role": "user", "content": _prompt(blocks, company)}],
                      "synthesize", max_tokens=5000, timeout=480)

    parsed = webread.json_from_text(raw)

    if not isinstance(parsed, dict):
        raise RuntimeError("ИИ вернул не JSON — повторите задачу (модель может быть перегружена)")

    result = {
        "company": str(parsed.get("company") or company)[:300],
        "summary": str(parsed.get("summary") or "")[:2000],
        "identifiers": {k: str((parsed.get("identifiers") or {}).get(k) or "").strip()[:20]
                        for k in ("inn", "ogrn", "ogrnip")},
        "red_flags": parsed.get("red_flags") or [],
        "pros": parsed.get("pros") or [],
        "cheatsheet": {
            "about": str((parsed.get("cheatsheet") or {}).get("about") or ""),
            "questions_to_ask": (parsed.get("cheatsheet") or {}).get("questions_to_ask") or [],
            "likely_screen_questions": (parsed.get("cheatsheet") or {}).get("likely_screen_questions") or [],
        },
        "sources": [{"url": s["url"], "title": s["title"], "kind": s["kind"]} for s in blocks],
    }

    # --- 4. выгрузка --------------------------------------------------------
    model_used = (sink_usage[-1]["model"] if sink_usage else model_spec) or model_spec

    ok, why, _ = await _post_site(ctx, "/result", {
        "dossier_id": str(dossier_id),
        "company": result["company"],
        "model_used": model_used,
        "usage": json.dumps(sink_usage, ensure_ascii=False),
        "result": json.dumps(result, ensure_ascii=False),
    })

    if not ok:
        raise RuntimeError(f"Сайт не принял результат досье — {why}")

    await _api_progress(ctx, dossier_id, "Досье готово")

    return {
        "ok": True,
        "code": code,
        "company": result["company"],
        "red_flags": len(result["red_flags"]),
        "pros": len(result["pros"]),
        "sources": len(blocks),
    }
