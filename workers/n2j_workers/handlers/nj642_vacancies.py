"""nj642.vacancies — «Вакансии компаний» (N2J.ru /vacancies).

Владелец (26.09.2026): «смотри страницы „Карьера“ „Вакансии“ „О компании“ — эта
важная информация, её можно обновлять и хранить историю появления и исчезновения
вакансий; по вакансиям от работодателя можно сделать отдельный модуль: указать
город, направление и мониторить изменение вакансий на сайтах компаний
(с уведомлением письмом)».

Два режима монитора:
  * «свои компании» — владелец перечислил сайты работодателей (companies в задаче);
  * «город + направление» (mode=auto) — компаний в мониторе может не быть вовсе:
    воркер ПОДБИРАЕТ их сам (сначала своё знание сайта: карта отрасли nj607,
    профили nj591, находки nj606, прошлые сборы; затем открытый поиск), отправляет
    найденное на сайт (/vacancies/api/companies) — монитор показывает, у кого
    именно собираются вакансии. Владелец (27.09.2026): «режим: только город +
    направление, компании с сайтами сервис находит сам — максимально
    автоматизируем, ориентируемся на онтологию».

Пайплайн по каждой компании монитора:
  1. сайт — из монитора (домен) или из профиля компании (nj591);
  2. главная → страницы «Карьера / Вакансии / Работа / О компании» (webread:
     только текст, Markdown, ≤ cap — курс на малые LLM);
  3. ОДИН LLM-вызов на компанию со строгой JSON-схемой; анти-галлюцинации:
     должность, город, зарплата и ссылка — ТОЛЬКО из текста страниц;
  4. результат → POST /vacancies/api/ingest (X-Worker-Token). Сайт сам ведёт
     историю (появилась/исчезла/вернулась), связывает вакансии с мониторами и
     шлёт письмо о новых подходящих — воркер в это не вмешивается.

Функцией ingest_from_pages() пользуется и обработчик профиля компании (nj591):
те же страницы работодателя он уже прочитал для профиля.

Env: NJ642_VACANCIES_API_BASE (напр. https://n2j.ru/vacancies/api),
NJ642_VACANCIES_SITE_TOKEN (или общий WORKER_API_TOKEN), NJ642_VACANCIES_MODEL
(спека «провайдер::модель»), NJ642_VACANCIES_PAGES (страниц на компанию, 3),
NJ642_VACANCIES_COMPANY_BUDGET (секунд на компанию, 150),
NJ642_VACANCIES_MD_CAP (знаков на страницу, 2200),
NJ642_VACANCIES_COMPANIES (компаний на сбор, 12),
NJ642_VACANCIES_DISCOVER_BUDGET (секунд на автоподбор компаний, 120).
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import time
from urllib.parse import urlparse

from .. import site
from .. import webread

log = logging.getLogger("n2j.nj642")

CHANNEL = "nj642.vacancies"

_PREFIX = "NJ642_VACANCIES"

# Страницы работодателя. Порядок важен: сначала собственно вакансии, потом
# «О компании» (там тоже бывают блоки «мы ищем»).
_PAGE_PATTERNS = [
    r"vacanc|vakans|ваканс|job|jobs|rabota|работа|career|karier|карьер|hr",
    r"about|o-nas|о-нас|onas|company|kompan|компан|about-us",
]

# Агрегаторы вакансий официальным сайтом компании быть не могут: если домен из
# монитора ведёт на job-борд, вакансии оттуда — не «с сайта работодателя».
_BLOCKED_HOSTS = (
    "hh.ru", "superjob.ru", "zarplata.ru", "avito.ru", "trudvsem.ru", "rabota.ru",
    "gorodrabot.ru", "job.ru", "linkedin.com", "indeed.com", "glassdoor.",
    "wikipedia.org", "yandex.", "google.", "duckduckgo.com", "vk.com", "ok.ru",
)


# Подбор площадок идёт через открытый поиск, и в выдачу попадают справочники,
# объявления, соцсети и сервисы-агрегаторы. Ни один из них не может быть
# «официальным сайтом работодателя» — такие хосты отбрасываем ещё до чтения.
_FIND_BLOCKED = (
    "hh.ru", "superjob", "zarplata", "avito.", "trudvsem", "rabota.", "gorodrabot",
    "job.ru", "jcat.", "jobsora", "jooble", "career.ru", "linkedin.", "indeed.",
    "glassdoor", "wikipedia.", "wikimedia", "yandex.", "google.", "duckduckgo.",
    "bing.com", "mail.ru", "vk.com", "ok.ru", "facebook.", "instagram.", "youtube.",
    "t.me", "telegram.", "twitter.", "x.com", "2gis.", "zoon.ru", "irr.ru", "olx.",
    "youla", "profi.ru", "farpost", "drom.ru", "auto.ru", "ozon.ru", "wildberries",
    "market.", "rutube", "dzen.ru", "livejournal", "pikabu", "habr.com", "vc.ru",
    "rbc.ru", "kommersant", "forbes", "gosuslugi", "nalog.ru", "zakupki.gov.ru",
    "rts-tender", "rostender", "bicotender", "tender.", "sbis.ru", "kontur.ru",
)


def _api_base() -> str:
    """База API модуля вакансий.

    Если NJ642_VACANCIES_API_BASE не задан (новый модуль — в .env воркера его
    ещё нет), выводим её из ЛЮБОЙ уже настроенной *_API_BASE того же сайта:
    «https://n2j.ru/company/api» → «https://n2j.ru/vacancies/api». Так вакансии
    из профиля компании (nj591) начинают доставляться сразу после обновления,
    без правки окружения на сервере.
    """
    base = (os.getenv(_PREFIX + "_API_BASE") or "").rstrip("/")

    if base:
        return base

    for name in ("NJ591_PROFILE_API_BASE", "NJ605_RADAR_API_BASE", "NJ641_API_BASE",
                 "NJ642_API_BASE", "WORKER_API_BASE", "WORKER_SITE_BASE"):
        value = (os.getenv(name) or "").strip()

        if not value:
            continue

        parts = urlparse(value)

        if parts.scheme and parts.netloc:
            base = f"{parts.scheme}://{parts.netloc}/vacancies/api"
            log.info("nj642: %s не задан — беру базу сайта из %s: %s", _PREFIX + "_API_BASE", name, base)
            os.environ[_PREFIX + "_API_BASE"] = base
            return base

    return ""


def _model() -> str:
    return os.getenv("NJ642_VACANCIES_MODEL", "").strip()


def _max_pages() -> int:
    try:
        return max(1, min(6, int(os.getenv("NJ642_VACANCIES_PAGES", "3"))))
    except ValueError:
        return 3


def _md_cap() -> int:
    try:
        return max(600, int(os.getenv("NJ642_VACANCIES_MD_CAP", "2200")))
    except ValueError:
        return 2200


def _max_companies() -> int:
    """Компаний на один сбор (совпадает с max_companies модуля на сайте)."""
    try:
        return max(1, min(24, int(os.getenv("NJ642_VACANCIES_COMPANIES", "12"))))
    except ValueError:
        return 12


def _discover_budget() -> float:
    """Секунд на автоподбор компаний: сбор обязан закончиться сам."""
    try:
        return max(30.0, float(os.getenv("NJ642_VACANCIES_DISCOVER_BUDGET", "120")))
    except ValueError:
        return 120.0


def _company_budget() -> float:
    """Секунд на одну компанию: сбор обязан закончиться сам, а не висеть."""
    try:
        return max(30.0, float(os.getenv("NJ642_VACANCIES_COMPANY_BUDGET", "150")))
    except ValueError:
        return 150.0


# ---------------------------------------------------------------------------
# Промпт: строгая JSON-схема, ноль выдумок
# ---------------------------------------------------------------------------

SYSTEM_VACANCIES = (
    "Ты извлекаешь ВАКАНСИИ из текста страниц сайта работодателя. "
    "Верни СТРОГО JSON без markdown: "
    '{"vacancies": [{"title": "должность", "city": "город или пусто", '
    '"direction": "направление/сфера одним-двумя словами или пусто", '
    '"salary": "зарплата как написано или пусто", "url": "ссылка на вакансию или пусто", '
    '"snippet": "1 предложение из текста страницы (до 200 знаков)"}], '
    '"note": "одна короткая фраза о том, что на странице"}. '
    "ЖЁСТКИЕ ПРАВИЛА: бери ТОЛЬКО то, что реально написано на страницах; ничего не "
    "додумывай и не ищи по памяти. Если вакансий на странице нет (например «сейчас "
    "вакансий нет») — верни пустой список vacancies. Одна вакансия — один объект; "
    "не дублируй. Названия должностей оставляй как на сайте. Всё по-русски."
)


async def _api_post(path: str, data: dict) -> tuple[bool, str, dict]:
    base = _api_base()

    if not base:
        return False, f"{_PREFIX}_API_BASE не задан в workers/.env", {}

    return await site.post_json(_PREFIX, path, data)


def _api_unset(reason: str) -> bool:
    return "_API_BASE не задан" in reason or "токен сайта не задан" in reason


def _host_of(url: str) -> str:
    try:
        return (urlparse(url).hostname or "").lower()
    except ValueError:
        return ""


def _is_blocked(host: str) -> bool:
    h = host.lower().lstrip("www.")
    return any(b in h for b in _BLOCKED_HOSTS)


# ---------------------------------------------------------------------------
# Извлечение вакансий
# ---------------------------------------------------------------------------

def _clean(raw: dict, page_url: str) -> dict:
    """Нормализация строки от модели: пустое не выдумываем."""
    def s(key: str, limit: int) -> str:
        return str(raw.get(key) or "").strip()[:limit]

    return {
        "title": s("title", 300),
        "city": s("city", 120),
        "direction": s("direction", 120),
        "salary": s("salary", 120),
        "url": s("url", 600),
        "snippet": s("snippet", 1000),
        "source_url": page_url[:600],
    }


async def extract_vacancies(ctx, hint: str, pages: list[dict]) -> tuple[list[dict], dict]:
    """ОДИН LLM-вызов на компанию: вакансии строго из текста страниц.

    @return (вакансии, usage)
    """
    if ctx.llm is None:
        raise RuntimeError("nj642: LLM-провайдеры не настроены (workers/.env)")

    parts = []

    for p in pages:
        md = str(p.get("md") or "").strip()

        if not md:
            continue

        parts.append(f"## {p.get('label') or 'Страница'}: {p.get('url')}\n{md}")

    corpus = "\n\n".join(parts)[:24000]

    user = (f"{hint}\n\nТексты страниц сайта работодателя:\n\n{corpus}")

    t0 = time.monotonic()
    text, usage = await ctx.llm.chat_with_usage(
        [{"role": "system", "content": SYSTEM_VACANCIES}, {"role": "user", "content": user}],
        model=_model() or None, max_tokens=2500, request_timeout=300)
    dt = int((time.monotonic() - t0) * 1000)

    obj = webread.json_from_text(text)

    if not isinstance(obj, dict):
        raise ValueError(f"nj642: модель вернула не JSON (начало: {text[:120]!r})")

    rows = obj.get("vacancies")

    if not isinstance(rows, list):
        rows = []

    page_url = str(pages[0].get("url") or "") if pages else ""
    out: list[dict] = []
    seen: set[str] = set()

    for r in rows[:60]:
        if not isinstance(r, dict):
            continue

        v = _clean(r, page_url)

        if len(v["title"]) < 3:
            continue

        key = (v["title"].lower(), v["city"].lower())

        if key in seen:
            continue

        seen.add(key)
        out.append(v)

    usage_out = {
        "phase": "vacancies",
        "model": _model() or getattr(getattr(ctx, "config", None), "llm_model", "") or "default",
        "prompt_tokens": int(usage.get("prompt_tokens") or 0),
        "completion_tokens": int(usage.get("completion_tokens") or 0),
        "duration_ms": dt,
        "found": len(out),
    }

    return out, usage_out


async def ingest_from_pages(ctx, company: str, domain: str, profile_code: str = "",
                            pages: list[dict] | None = None, watch_id: int = 0,
                            hint: str = "") -> int:
    """Извлечь вакансии из уже прочитанных страниц и отдать сайту.

    Возвращает число вакансий, принятых сайтом. Используется и монитором, и
    профилем компании (nj591) — история вакансий одна на всю платформу.
    """
    pages = pages or []

    if not pages:
        return 0

    vacancies, usage = await extract_vacancies(ctx, hint or f"Компания: {company}", pages)

    log.info("nj642[%s]: вакансий извлечено %d (%d+%d tok)",
             company, len(vacancies), usage["prompt_tokens"], usage["completion_tokens"])

    ok, reason, res = await _api_post("/ingest", {
        "company": company[:200],
        "domain": domain[:180],
        "profile_code": profile_code[:16],
        "source_url": str(pages[0].get("url") or "")[:600],
        "watch_id": str(int(watch_id)),
        "full": "1",              # страницы прочитаны полностью — можно отмечать исчезнувшие
        "pages_read": str(len(pages)),
        "vacancies": json.dumps(vacancies, ensure_ascii=False),
    })

    if not ok:
        if _api_unset(reason):
            log.info("nj642: API сайта не настроен (%s_API_BASE/токен) — вакансии не отправлены",
                     _PREFIX)
            return 0

        raise RuntimeError(f"nj642: сайт /ingest → {reason}")

    return int(res.get("added") or 0) + int(res.get("restored") or 0)


# ---------------------------------------------------------------------------
# Автоподбор компаний: режим «только город + направление»
# ---------------------------------------------------------------------------

def _find_blocked(host: str) -> bool:
    h = (host or "").lower().lstrip("www.")
    return any(b in h for b in _FIND_BLOCKED)


def _clean_found_name(raw: str) -> str:
    """Название компании из заголовка поисковой выдачи.

    «ООО «Ромашка» — производство мебели в Москве» → «ООО «Ромашка»». Хвосты
    вида «— вакансии», «| официальный сайт» в названии монитора только мешают:
    владелец видит его в списке компаний.
    """
    t = re.sub(r"\s+", " ", str(raw or "")).strip()
    t = re.split(r"\s[—–|·:·]\s|\s[—-]\s|\s*\|\s*", t)[0].strip()
    t = re.sub(r"(?i)\b(официальный сайт|вакансии|работа|карьера|jobs|careers?)\b.*$", "", t).strip(" -–|·:,.")

    return t[:200]


def _discover_queries(city: str, direction: str, keywords: str) -> list[str]:
    what = " ".join(x for x in (direction, keywords) if x).strip()
    where = city.strip()
    q = []

    if where:
        q.append(f"{where} {what} компании производители официальные сайты")
        q.append(f"{where} {what} работа вакансии компании сайт работодателя")
    else:
        q.append(f"{what} компании производители официальные сайты")
        q.append(f"{what} работа вакансии компании сайт работодателя")

    q.append(f"{what} компании сайт {where}".strip())

    return q[:3]


async def _site_seeds(code: str) -> list[dict]:
    """Кандидаты из НАШЕЙ базы: карта отрасли nj607, профили, прошлые сборы.

    Дёшево и без поисковика: сначала спрашиваем своё знание, поиск — только
    добавка. Если сайт недоступен, подбор продолжается поиском.
    """
    ok, reason, res = await site.get_json(_PREFIX, f"/hints/{code}")

    if not ok:
        log.info("nj642[%s]: подсказки с сайта не получены: %s", code, reason)
        return []

    rows = res.get("companies")

    if not isinstance(rows, list):
        return []

    out = []

    for r in rows:
        if not isinstance(r, dict):
            continue

        domain = str(r.get("domain") or "").strip().lower()

        if domain and _find_blocked(_host_of("https://" + domain)):
            continue

        name = str(r.get("name") or domain).strip()

        if not name:
            continue

        out.append({
            "name": name[:200],
            "domain": domain,
            "source": str(r.get("source") or "наша база")[:60],
            "why": str(r.get("why") or "")[:160],
        })

    return out


async def discover(ctx, code: str, watch_id: int, city: str, direction: str,
                   keywords: str, want: int) -> list[dict]:
    """Подобрать сайты работодателей под «город + направление».

    Порядок как у человека: сначала то, что уже знает платформа (онтология и
    свои модули), затем открытый поиск. Найденное отдаём сайту — он ведёт список
    компаний монитора и показывает владельцу, откуда компания взялась.
    """
    want = max(0, min(int(want or 0), _max_companies()))
    await ctx.progress("Подбираю компании", f"— {direction or city}".strip(" —"))

    found: dict[str, dict] = {}

    for c in await _site_seeds(code):
        key = (c["domain"] or c["name"]).lower()

        if key and key not in found:
            found[key] = c

    await ctx.progress("Подбираю компании", f"из нашей базы: {len(found)}")

    if len(found) < want:
        t0 = time.monotonic()
        queries = _discover_queries(city, direction, keywords)

        for qi, q in enumerate(queries, 1):
            if len(found) >= want or _discover_budget() - (time.monotonic() - t0) <= 0:
                break

            await ctx.progress("Ищу компании в открытых источниках",
                               f"{qi}/{len(queries)} — {q[:110]}")
            await ctx.heartbeat()

            try:
                rows = await webread.ddg_text(q, max_results=8)
            except Exception as e:  # noqa: BLE001 — поиск не обязан отвечать
                log.info("nj642[%s]: поиск %r не ответил: %s", code, q, e)
                continue

            for r in rows:
                url = str(r.get("url") or r.get("href") or "").strip()
                host = _host_of(url)

                if not url.startswith("http") or not host or _find_blocked(host) or _is_blocked(host):
                    continue

                name = _clean_found_name(r.get("title") or "")

                if len(name) < 3:
                    name = host

                key = host.lower()

                if key in found:
                    continue

                found[key] = {
                    "name": name,
                    "domain": host,
                    "source": "поиск",
                    "why": f"нашлась поиском: {q[:120]}",
                }

            await ctx.progress("Ищу компании в открытых источниках",
                               f"{qi}/{len(queries)} — подходящих площадок: {len(found)}")

    if not found:
        log.info("nj642[%s]: компании не нашлись (город %r, направление %r)", code, city, direction)
        return []

    picked = list(found.values())[:max(1, want)] if want else list(found.values())

    # Отдаём сайту: он ведёт список компаний монитора и подсказывает владельцу,
    # откуда компания взялась. Ответ — итоговый список монитора (он главнее).
    ok, reason, res = await _api_post("/companies", {
        "watch_id": str(int(watch_id)),
        "source": "поиск",
        "companies": json.dumps(picked, ensure_ascii=False),
    })

    if not ok:
        if _api_unset(reason):
            log.info("nj642: API сайта не настроен — компании не отправлены")
        else:
            log.warning("nj642[%s]: компании не отправлены сайту: %s", code, reason)

        return picked

    rows = res.get("companies")

    if isinstance(rows, list) and rows:
        out = []

        for r in rows:
            if not isinstance(r, dict):
                continue

            name = str(r.get("name") or r.get("domain") or "").strip()

            if name:
                out.append({"name": name[:200], "domain": str(r.get("domain") or "").strip(),
                            "profile_code": str(r.get("profile_code") or "")})

        await ctx.progress("Компании монитора", f"всего {len(out)} — приняты сайтом")

        return out

    return picked


# ---------------------------------------------------------------------------
# Сбор по монитору
# ---------------------------------------------------------------------------

async def _read_company(ctx, watch_code: str, comp: dict) -> list[dict]:
    """Страницы работодателя одной компании: [{'url','label','md'}, …]."""
    domain = str(comp.get("domain") or "").strip()
    name = str(comp.get("name") or "").strip()
    base_url = "https://" + domain if domain else ""

    if not base_url:
        log.info("nj642[%s]: у компании %r нет сайта — пропускаю", watch_code, name)
        return []

    host = _host_of(base_url)

    if _is_blocked(host):
        log.info("nj642[%s]: %s — агрегатор вакансий, не сайт работодателя", watch_code, host)
        return []

    home_html, _ = await webread.fetch(base_url)
    links = webread.extract_links(home_html, base_url, _PAGE_PATTERNS, limit=_max_pages())

    # Если на главной нет ссылок на «Карьеру», читаем типовые адреса: сайты без
    # меню (одностраничники) иначе остались бы вообще без вакансий.
    candidates = [(l.get("url"), l.get("label") or "") for l in links]

    if not candidates:
        for path in ("/vacancies", "/career", "/jobs", "/vakansii", "/rabota"):
            candidates.append((base_url.rstrip("/") + path, path.lstrip("/")))

    pages: list[dict] = []
    seen: set[str] = set()

    for url, label in candidates:
        url = str(url or "").strip()

        if not url or url in seen:
            continue

        seen.add(url)

        try:
            html, _ = await webread.fetch(url)
        except webread.WebReadError as e:
            log.info("nj642[%s]: страница %s пропущена: %s", watch_code, url, e)
            continue

        md, title = await webread.to_markdown(html, cap=_md_cap())

        if len(md) < 40:
            continue

        pages.append({"url": url, "label": label or title, "md": md})
        await ctx.heartbeat()

    return pages


async def handle(ctx, payload: dict) -> dict:
    watch_id = int(payload.get("watch_id") or 0)
    code = str(payload.get("code") or "")
    city = str(payload.get("city") or "").strip()
    direction = str(payload.get("direction") or "").strip()
    keywords = str(payload.get("keywords") or "").strip()
    mode = str(payload.get("mode") or "").strip()
    want = int(payload.get("find") or 0)
    companies = payload.get("companies") or []

    if not watch_id:
        raise RuntimeError("nj642: в задаче нет watch_id")

    if not isinstance(companies, list):
        companies = []

    # Задача без компаний в режиме «свои компании» — это ошибка сборки задачи,
    # о ней надо кричать в статусе. В авто-режиме пустой список — норма.
    if not companies and mode != "auto":
        raise RuntimeError("nj642: в задаче нет watch_id или компаний")

    what = ", ".join(x for x in (city, direction, keywords) if x) or "вакансии"
    await ctx.progress("Собираю вакансии", f"— {what}")

    # Авто-режим («только город + направление»): сайтов работодателей может не
    # быть вовсе — подбираем их сами и сразу показываем владельцу в мониторе.
    if mode == "auto" and (want > 0 or not companies):
        picked = await discover(ctx, code, watch_id, city, direction, keywords, want or 8)

        if picked:
            companies = picked

    if not companies:
        # Ничего не нашли: это честный «пусто», а не сбой — закрываем задачу
        # сами, чтобы ряд не ждал авто-остановки по пульсу (правило модулей).
        await ctx.progress("Сайтов работодателей не нашлось",
                           "— уточните направление или добавьте компании вручную")
        ok, reason, _j = await _api_post("/finish", {"watch_id": str(watch_id)})

        if not ok and not _api_unset(reason):
            log.warning("nj642[%s]: не закрыл задачу очереди: %s", code, reason)

        return {"companies": 0, "found": 0, "note": "компании не найдены"}

    sink: list = []
    total = 0
    read_companies = 0

    for i, comp in enumerate(companies[:_max_companies()]):
        comp = comp if isinstance(comp, dict) else {"name": str(comp)}
        name = str(comp.get("name") or comp.get("domain") or "").strip()

        await ctx.progress("Читаю сайт работодателя", f"— {name} ({i + 1} из {len(companies)})")
        await ctx.heartbeat()

        try:
            pages = await asyncio.wait_for(_read_company(ctx, code, comp), timeout=_company_budget())
        except asyncio.TimeoutError:
            log.warning("nj642[%s]: %s — сайт не ответил за %.0f с, пропускаю",
                        code, name, _company_budget())
            continue
        except webread.WebReadError as e:
            log.info("nj642[%s]: %s — сайт не прочитан: %s", code, name, e)
            continue

        if not pages:
            log.info("nj642[%s]: %s — страниц с вакансиями не нашлось", code, name)
            continue

        read_companies += 1

        await ctx.progress("Извлекаю вакансии", f"— {name} ({len(pages)} стр.)")

        try:
            found = await ingest_from_pages(
                ctx,
                company=name,
                domain=str(comp.get("domain") or ""),
                profile_code=str(comp.get("profile_code") or ""),
                pages=pages,
                watch_id=watch_id,
                hint=(f"Компания: {name}. Монитор ищет: {what}. "
                      "Выдели все вакансии, которые видишь на этих страницах."))
        except Exception as e:  # noqa: BLE001 — одна компания не срывает сбор остальных
            log.warning("nj642[%s]: %s — вакансии не отправлены: %s", code, name, e)
            continue

        total += found
        sink.append({"company": name, "found": found, "pages": len(pages)})

    if read_companies == 0:
        raise RuntimeError(
            "Не удалось прочитать ни одной страницы вакансий: сайты компаний не ответили "
            "или у них нет страниц «Карьера»/«Вакансии»")

    # Обход закончен — закрываем задачу очереди сами («Готово»), иначе статус-ряд
    # ждал бы авто-остановки по пульсу, хотя работа выполнена.
    ok, reason, _j = await _api_post("/finish", {"watch_id": str(watch_id)})

    if not ok and not _api_unset(reason):
        log.warning("nj642[%s]: не закрыл задачу очереди: %s", code, reason)

    return {"usage": sink, "companies": read_companies, "found": total}
