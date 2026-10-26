"""nj606.leads — Поток заказчиков: найти компании под оффер пользователя.

Пайплайн: ddgs-поиск производителей (industry+region, 3 формулировки) →
чтение топ-страниц → ОДИН LLM-вызов: извлечь компании (название, сайт,
ИНН если виден, чем занимаются, зацепка для письма, score) →
POST /leads/api/companies (дедуп по сайту/названию — на сайте).

Env: NJ606_LEADS_API_BASE, NJ606_LEADS_SITE_TOKEN (или WORKER_API_TOKEN),
NJ606_LEADS_MAX_SITES (6), NJ606_LEADS_SEARCH_DELAY (2.0).
"""

from __future__ import annotations

import asyncio
import logging
import os
import re
import time

from .. import webread, site
from ..llmchat import chat as _llm
from ..llmwatch import wait_with_ticks
from .nj590_design import _readable_reason
from .nj598_dossier import _host_of

log = logging.getLogger("n2j.nj606")

CHANNEL = "nj606.leads"

_PREFIX = "NJ606_LEADS"  # NJ606_LEADS_API_BASE + NJ606_LEADS_SITE_TOKEN

SYSTEM = (
    "Ты помощник отдела продаж B2B. Дают критерий («какие компании нужны») и "
    "находки из интернета. Извлеки РЕАЛЬНЫЕ компании, подходящие под критерий: "
    "только те, чьи названия и сайты реально встречаются в находках — ничего "
    "не выдумывай. what — чем компания занимается (1-2 предложения по-русски). "
    "hook — зацепка для первого письма: конкретный наблюдаемый факт (продукт, "
    "проект, новость со страницы), без выдумок. score (0-100) — соответствие "
    "критерию. Ответ — ТОЛЬКО JSON: {\"companies\": [{\"name\", \"site\", "
    "\"inn\", \"what\", \"hook\", \"score\"}]}."
)


def _max_sites() -> int:
    try:
        return max(1, int(os.environ.get("NJ606_LEADS_MAX_SITES", "6")))
    except ValueError:
        return 6


def _search_budget() -> float:
    """Бюджет поиска (секунды) — пайплайн обязан укладываться в таймаут задачи."""
    try:
        return max(60.0, float(os.environ.get("NJ606_LEADS_SEARCH_BUDGET", "150")))
    except ValueError:
        return 150.0


def _llm_timeout() -> float:
    """Предел ожидания ответа модели (секунды) — этап обязан уложиться в бюджет.

    Вызов идёт под тикером: снаружи видно «модель думает N с (лимит M с)», а
    по исчерпании предела задача заканчивается понятной ошибкой, а не тишиной
    (владелец, 26.09: /leads «запустил — не отработало», этапы дошли до «ИИ
    извлекает компании» и всё).

    Дефолт 420 с: локальная модель на длинном промпте (источники + сниппеты,
    до 2500 токенов ответа) нередко идёт 5-7 минут, и прежние 240 с рубили
    честную работу. Тишины это не создаёт: каждые 20 с в журнал уходит
    «модель думает N с», а сайтовые правила гасят задачу только по молчанию
    пульса (4 мин) или по застывшему прогрессу (10 мин).
    """
    try:
        return max(30.0, float(os.environ.get("NJ606_LEADS_LLM_TIMEOUT", "420")))
    except ValueError:
        return 420.0


def _read_budget() -> float:
    """Бюджет чтения источников (секунды)."""
    try:
        return max(60.0, float(os.environ.get("NJ606_LEADS_READ_BUDGET", "240")))
    except ValueError:
        return 240.0


def _delay() -> float:
    try:
        return max(0.0, float(os.environ.get("NJ606_LEADS_SEARCH_DELAY", "2.0")))
    except ValueError:
        return 2.0


async def _sleep(sec: float) -> None:
    import asyncio

    if sec > 0:
        await asyncio.sleep(sec)


def _to_json(data) -> str:
    import json

    return json.dumps(data, ensure_ascii=False)


async def _post(ctx, path: str, data: dict) -> dict:
    """POST на сайт через общий слой (site.post_json) — эталон для остальных.

    Семантика как раньше: API/токен не настроены — тихий пропуск ({});
    сайт вернул ошибку доставки — честный RuntimeError (задача уйдёт в ретрай).
    """
    ok, reason, j = await site.post_json(_PREFIX, path, data)

    if not ok:
        if "_API_BASE не задан" in reason or "токен сайта не задан" in reason:
            log.info("nj606_leads: API сайта не настроен (%s) — отправка пропущена", reason)
            return {}

        raise RuntimeError(f"nj606_leads: сайт {path} не принял результат — {reason}")

    return j if isinstance(j, dict) else {}


async def handle(ctx, payload: dict) -> dict:
    """Собрать компании по одному потоку (payload: flow_id, industry, region)."""
    flow_id = int(payload.get("flow_id") or 0)
    industry = str(payload.get("industry") or "").strip()[:300]
    region = str(payload.get("region") or "").strip()[:120]

    if not flow_id or len(industry) < 3:
        raise RuntimeError("nj606_leads: в задаче нет flow_id или критерия")

    # --- 1. поиск: 3 формулировки --------------------------------------------
    await ctx.progress("Поиск источников", f"{industry} {region}".strip())
    queries = [
        f"{industry} {region} производители компании".strip(),
        f"{industry} {region} заводы сайты".strip(),
        f"{industry} поставщики производители каталог".strip(),
    ]

    sources: dict[str, dict] = {}

    t_search = time.monotonic()

    for qi, q in enumerate(queries, 1):
        if _search_budget() - (time.monotonic() - t_search) <= 0:
            await ctx.progress("Поиск источников",
                               f"бюджет поиска исчерпан: площадок {len(sources)} — перехожу к чтению")
            break

        # Пошаговый рапорт: поиск идёт минутами, а «одна и та же» строка этапа
        # выглядит как зависание (владелец: «/leads залип на "Поиск источников"»).
        await ctx.progress("Поиск источников", f"{qi}/{len(queries)} — {q[:120]}")

        rows = await webread.ddg_text(q, max_results=8)

        if not rows:
            # «Найдено площадок: 0» на всех формулировках = поисковик не отвечает,
            # а не отсутствие компаний: владелец должен видеть настоящую причину.
            await ctx.progress("Поиск источников",
                               f"{qi}/{len(queries)} — поисковик не ответил (проверьте сеть воркера)")

        for r in rows:
            url = str(r.get("url") or r.get("href") or "").strip()
            host = _host_of(url)

            if not url.startswith("http") or not host or host in sources:
                continue

            sources[host] = {
                "url": url,
                "title": str(r.get("title") or "")[:200],
                # сниппет поиска — часто содержит название компании и профиль
                "snippet": str(r.get("body") or r.get("description") or "")[:500],
            }

        await ctx.progress("Поиск источников", f"{qi}/{len(queries)} — найдено площадок: {len(sources)}")

        await _sleep(_delay())

    if not sources:
        raise RuntimeError(
            "Поиск не дал источников. Если в этапах «поисковик не ответил» — проверьте "
            "сеть/прокси на сервере воркера; иначе расширьте критерий потока.")

    # --- 2. читаем страницы ----------------------------------------------------
    await ctx.progress("Чтение источников", f"{len(sources)} сайтов")
    blocks: list[dict] = []

    pages = list(sources.values())[:_max_sites()]

    t_read = time.monotonic()

    for i, s in enumerate(pages, 1):
        if _read_budget() - (time.monotonic() - t_read) <= 0:
            await ctx.progress("Чтение источников",
                               f"бюджет чтения исчерпан: прочитано {len(blocks)} из {len(pages)}")
            break

        await ctx.progress("Чтение источников", f"{i}/{len(pages)} — {_host_of(s['url'])}")

        try:
            html, _ = await webread.fetch(s["url"])
            md, title = await webread.to_markdown(html, cap=1200)
        except Exception as e:  # noqa: BLE001
            log.info("nj606_leads: не прочитали %s: %s", s["url"], _readable_reason(str(e)))
            continue

        if len(md) >= 200:
            blocks.append({"url": s["url"], "title": title or s["title"], "md": md})

    if not blocks:
        raise RuntimeError("Ни один источник не удалось прочитать.")

    # --- 3. LLM извлекает компании ---------------------------------------------
    await ctx.progress("ИИ извлекает компании", f"источников: {len(blocks)}")
    parts = [f"КРИТЕРИЙ: {industry}" + (f"; регион: {region}" if region else ""), "",
             "НАХОДКИ ИЗ ИСТОЧНИКОВ:"]

    for b in blocks:
        parts.append(f"--- {b['title']} ({_host_of(b['url'])}) ---")
        parts.append(f"URL: {b['url']}")
        parts.append(b["md"])

    # Сниппеты поиска — в них часто прямо названы компании («ООО … производитель
    # …»), даже если страница не открылась. Раньше они в промпт не попадали.
    # sources — это {хост: карточка}, поэтому значения и есть карточки.
    # Внимание: раньше здесь был двойной обход (for c in cards_list), который
    # перебирал КЛЮЧИ словаря-карточки, и уже следующая строка (c['title'])
    # падала с TypeError — сбор обрывался ровно на этапе «ИИ извлекает
    # компании» и снаружи выглядел зависшим (владелец, 26.09: «Подвисает
    # процесс на /leads»).
    cards = list(sources.values())

    if cards:
        parts.append("")
        parts.append("РЕЗУЛЬТАТЫ ПОИСКА (сниппеты):")

        for c in cards[:60]:
            parts.append(f"--- {c['title']} ({_host_of(c['url'])}) ---")
            parts.append(f"URL: {c['url']}")
            parts.append(c.get("snippet") or "(без описания)")

    parts.append("")
    parts.append('Верни JSON: {"companies": [{"name": "название", "site": "https://...", '
                 '"inn": "ИНН или пусто", "what": "чем занимаются", "hook": "зацепка для письма", '
                 '"score": 0}]}')

    llm_limit = _llm_timeout()

    async def _ask() -> str:
        return await _llm(ctx, [{"role": "system", "content": SYSTEM},
                                {"role": "user", "content": "\n".join(parts)}],
                          "nj606_leads", model_env="NJ606_LEADS_MODEL",
                          max_tokens=2500, timeout=llm_limit)

    async def _tick(waited: int, limit: float | None) -> None:
        # «модель думает N с (лимит M с)» вместо застывшей строки этапа —
        # эталон: сборка обзоров GitHub (nj593).
        tail = f" (лимит {int(limit)} с)" if limit else ""
        await ctx.progress("ИИ извлекает компании", f"модель думает {waited} с{tail}…")

    raw = ""
    ai_error = ""

    try:
        raw = await wait_with_ticks(_ask, limit=llm_limit, on_tick=_tick)
    except asyncio.TimeoutError:
        ai_error = f"модель не ответила за {int(llm_limit)} с"
    except Exception as e:  # noqa: BLE001
        ai_error = f"модель недоступна ({_readable_reason(str(e))})"

    companies = []

    if raw and _llm_answered(raw):
        # Модель ответила: её «пусто» — законный финал («посмотрела и не нашла»).
        parsed = webread.json_from_text(raw)

        if isinstance(parsed, dict) and isinstance(parsed.get("companies"), list):
            for c in parsed["companies"][:30]:
                if isinstance(c, dict) and str(c.get("name") or "").strip():
                    companies.append({
                        "name": str(c.get("name"))[:300],
                        "site": str(c.get("site") or "")[:300],
                        "inn": str(c.get("inn") or "")[:20],
                        "what": str(c.get("what") or "")[:1000],
                        "hook": str(c.get("hook") or "")[:1000],
                        "score": c.get("score") or 0,
                    })
        else:
            ai_error = "модель ответила не разобранным текстом"
    elif not ai_error:
        ai_error = "модель вернула пустой ответ"

    note = ""

    if not companies and ai_error:
        # Модель молчала — но находки уже собраны: отдаём то, что в них написано
        # явно, и ЧЕСТНО помечаем это в итоге (владелец не должен принимать
        # эвристику за разбор моделью).
        companies = _heuristic_companies(sources, blocks)

        if companies:
            note = f"{ai_error} — компании собраны по эвристике (названия и сайты из находок), проверьте список"
            await ctx.progress("Эвристика без модели", note)
        else:
            await ctx.progress("Не получилось", ai_error)

            raise RuntimeError(f"{ai_error}. Эвристика по находкам ничего не нашла — "
                               "проверьте LLM-провайдера и повторите сбор.")

    if not companies:
        await ctx.progress("Готово", "подходящих компаний в находках нет")
        return {"ok": True, "saved": 0, "note": "подходящих компаний в находках нет"}

    await ctx.progress("Отправляю компании",
                       f"найдено: {len(companies)}" + (" (эвристика)" if note else ""))

    res = await _post(ctx, "/companies", {"flow_id": flow_id, "companies": _to_json(companies)})
    log.info("nj606_leads[%s]: извлечено %d, сайт принял %s", flow_id, len(companies), res.get("saved", "?"))

    out = {"ok": True, "found": len(companies), "saved": res.get("saved", 0)}

    if note:
        out["note"] = note

    return out

# ---------------------------------------------------------------------------
# Эвристика без модели
# ---------------------------------------------------------------------------

# Площадки-агрегаторы (каталоги, маркетплейсы, СМИ, справочники): их названия —
# это НЕ компании-заказчики, в лиды они попадать не должны.
_STOP_HOSTS = (
    "avito.", "ozon.", "wildberries", "market.yandex", "aliexpress", "hh.ru", "rabota.",
    "wikipedia.org", "rusprofile", "list-org", "sbis.ru", "kontur.ru", "zakupki.gov",
    "rts-tender", "tender.", "nalog.", "vk.com", "ok.ru", "youtube", "dzen.ru", "rutube",
    "rbc.ru", "forbes", "kommersant", "vedomosti", "iz.ru", "tass.ru", "ria.ru", "lenta.ru",
    "banki.ru", "audit-it", "otzovik", "flamp", "yell.ru", "2gis", "google.", "yandex.",
    "mail.ru", "tgstat", "t.me", "pinterest", "facebook", "instagram", "tiktok", "habr.com",
    "vc.ru", "sberbank", "vtb.ru", "alfabank", "gazprombank", "psbank.ru",
)

_STOP_WORDS = (
    "каталог", "рейтинг", "список", "новости", "база", "форум", "объявлен", "работа",
    "вакансии", "статьи", "сравнение", "подборка", "доска", "маркетплейс", "оптовые",
    "тендеры", "закупки", "википеди", "энциклопедия", "справочник", "отзывы",
)

# Название компании пишется с организационной формой: только такое и берём.
_NAME_RE = re.compile(
    r"((?:ООО|ОАО|ЗАО|ПАО|АО|ИП|НПО|ТД|ТК|ГК|ПК)\s*[«\"']?[А-ЯЁA-Z][^,.;:«»\"']{1,80})"
)


def _clean_name(raw: str) -> str:
    name = re.sub(r"\s+", " ", str(raw or "")).strip(" «»\"'—-,;:.")
    name = re.sub(r"\s+(упаковк|производств|поставк|оптов|розниц|компан|завод|фабрик)\w*$", "", name, flags=re.I)
    return name[:120]


def _company_from_text(text: str) -> tuple[str, str]:
    """(название, ИНН) из строки текста — или пусто, если компании там нет."""
    m = _NAME_RE.search(str(text or ""))

    if not m:
        return "", ""

    inn_m = re.search(r"ИНН[:\s]*(\d{10,12})", str(text or ""))

    return _clean_name(m.group(1)), (inn_m.group(1) if inn_m else "")


def _host_ok(host: str) -> bool:
    host = (host or "").lower()

    if not host:
        return False

    return not any(stop in host for stop in _STOP_HOSTS)


def _heuristic_companies(sources: dict, blocks: list, limit: int = 15) -> list[dict]:
    """Компании без модели: только явные названия из сниппетов и текстов.

    Ничего не додумываем: нет названия с организационной формой — нет компании.
    Агрегаторы и каталоги отбрасываются по домену и стоп-словам.
    """
    out: list[dict] = []
    seen: set[str] = set()

    def add(name: str, host: str, url: str, what: str, inn: str = "", score: int = 35) -> None:
        if not name:
            return

        key = re.sub(r"\W+", "", name.lower())
        host = (host or "").lower()

        if key in seen or any(w in name.lower() for w in _STOP_WORDS):
            return

        seen.add(key)
        site = f"https://{host}" if host else url
        out.append({
            "name": name[:300],
            "site": site[:300],
            "inn": inn[:20],
            "what": str(what or "")[:1000],
            "hook": "",
            "score": score,
        })

    for card in (sources or {}).values():
        url = str(card.get("url") or "")
        host = _host_of(url)

        if not _host_ok(host):
            continue

        name, inn = _company_from_text(card.get("snippet") or "")

        if not name:
            name, inn = _company_from_text(card.get("title") or "")

        add(name, host, url, card.get("snippet") or card.get("title") or "", inn)

        if len(out) >= limit:
            return out

    for b in (blocks or []):
        host = _host_of(str(b.get("url") or ""))

        if not _host_ok(host):
            continue

        for line in re.split(r"[\n\r]+", str(b.get("md") or ""))[:400]:
            name, inn = _company_from_text(line)

            if not name:
                continue

            add(name, host, str(b.get("url") or ""), line.strip()[:400], inn)

            if len(out) >= limit:
                return out

    return out


def _llm_answered(raw: str) -> bool:
    """Модель ВООБЩЕ ответила (пусть и мусором).

    Граница «модель посмотрела и не нашла» (валидный финал) и «модель молчала»
    (тогда работаем эвристикой и честно помечаем это в итоге).
    """
    return bool(str(raw or "").strip())
