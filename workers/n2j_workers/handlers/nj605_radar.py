"""nj605.radar — Радар заказов: сбор находок спроса по наблюдению пользователя.

Пайплайн: ddgs-поиск (несколько формулировок + site:-запросы по площадкам) →
чтение топ-страниц → LLM отбирает заказы со score → POST /radar/api/items.

Пайплайн задачи:
  1. ddgs-поиск по формулировкам (продукт+регион × «закупка/тендер/коммерческий
     тендер/запрос предложений/ищу поставщика»); список расширяется через
     NJ605_RADAR_QUERIES (через «|», {query}/{region} подставляются);
  2. таргетированный site:-поиск по площадкам закупок (см. _default_tender_sites;
     переопределяется NJ605_RADAR_SITE_DOMAINS). Прямого API у площадок нет —
     только честный поиск их публичной выдачи;
  3. дедуп по URL (не по хосту): агрегаторы отдают по несколько страниц
     с заказами — все учитываются, но не более NJ605_RADAR_MAX_SITES на хост;
  4. чтение страниц конвейером webread (Markdown ≤ cap);
  5. ОДИН LLM-вызов со строгой JSON-схемой: отобрать РЕАЛЬНЫЕ заказы/запросы
     и оценить релевантность радару (score 0-100 + почему);
  6. POST /radar/api/items (дубли по URL сайт отсекает сам; source = host площадки).

Env: NJ605_RADAR_API_BASE (https://n2j.ru/radar/api), NJ605_RADAR_SITE_TOKEN
(или WORKER_API_TOKEN), NJ605_RADAR_MODEL («провайдер::модель», опц.),
NJ605_RADAR_MAX_SITES (8), NJ605_RADAR_SEARCH_DELAY (2.0),
NJ605_RADAR_QUERIES (опц., «|»-список доп. формулировок),
NJ605_RADAR_SITE_DOMAINS (опц., «|»-список площадок для site:-поиска).
"""

from __future__ import annotations

import logging
import os
import time


from .. import webread, site
from ..llmchat import chat as _llm
from .nj590_design import _readable_reason
from .nj598_dossier import _host_of

log = logging.getLogger("n2j.nj605")

CHANNEL = "nj605.radar"

SYSTEM = (
    "Ты аналитик по продажам B2B. Дают наблюдение («что ищет компания») и находки "
    "из интернета (результаты поиска и тексты страниц). Отбери РЕАЛЬНЫЙ спрос на "
    "товар наблюдения: закупки, тендеры, запросы предложений, объявления «ищу "
    "поставщика», а также КОНКРЕТНЫЕ позиции из подборок и категорий закупок "
    "(например, «Закупка реле РЭС-9», «Поставка реле РП-21»), если товар из "
    "наблюдения в них назван. Подборка/категория — допустимая ссылка, но в title "
    "пиши КОНКРЕТНУЮ закупку из неё, а не название раздела. "
    "Смежные и частичные совпадения включай (score ниже): радар ищет возможности, "
    "а не только точное словосочетание. НЕ включай рекламу поставщиков и статьи "
    "«что такое реле» — нужен СПРОС, а не предложение. "
    "score (0-100) — насколько находка соответствует наблюдению. why — одним "
    "предложением по-русски, почему стоит посмотреть. customer — заказчик, если "
    "виден. price — сумма/бюджет строкой, если указан. date — дата публикации "
    "строкой, если видна. url бери ТОЛЬКО из приведённых находок, дословно. "
    "Ответ — ТОЛЬКО JSON: {\"items\": [{\"title\", \"url\", \"customer\", \"price\", "
    "\"date\", \"score\", \"why\"}]}."
)


def _default_tender_sites() -> list[str]:
    """Площадки закупок с публичной (доступной без ключа) выдачей.

    Никакого парсинга HTML этих доменов: воркер ищет прямыми site:-запросами
    через поисковик, а площадка лишь помечается как источник находки. Список
    минимален и консервативен — крупные/государственные контуры, без площадок,
    требующих авторизации. Дополняется через NJ605_RADAR_SITE_DOMAINS.
    """
    return [
        "zakupki.gov.ru",   # ЕИС: госзакупки
        "rostender.info",   # агрегатор тендеров
        "rts-tender.ru",    # РТС-тендер (в т.ч. закупки Росатома)
        "b2b-center.ru",    # B2B-Center
        "tektorg.ru",       # ТЭК-Торг (нефтегаз)
        "sberbank-ast.ru",  # Сбербанк-АСТ
        "fabrikant.ru",     # Фабрикант
        "tenderplan.ru",    # Тендерплан
    ]



def _max_sites() -> int:
    try:
        return max(1, int(os.environ.get("NJ605_RADAR_MAX_SITES", "8")))
    except ValueError:
        return 6


def _search_budget() -> float:
    """Сколько секунд отпущено на поиск (формулировки + площадки).

    Пайплайн длиннее таймаута задачи (900 c) бессмысленен: очередь откажет
    задачу уже после реально сделанной работы. Поэтому поиск ограничен
    бюджетом, а найденное к этому моменту идёт в чтение и оценку (владелец:
    «радар залипал и никогда нормально не работал»).
    """
    try:
        return max(60.0, float(os.environ.get("NJ605_RADAR_SEARCH_BUDGET", "240")))
    except ValueError:
        return 240.0


def _read_budget() -> float:
    """Сколько секунд отпущено на чтение страниц (те же причины)."""
    try:
        return max(60.0, float(os.environ.get("NJ605_RADAR_READ_BUDGET", "240")))
    except ValueError:
        return 240.0


def _delay() -> float:
    try:
        return max(0.0, float(os.environ.get("NJ605_RADAR_SEARCH_DELAY", "2.0")))
    except ValueError:
        return 2.0


def _model_spec(payload: dict) -> str:
    return str(payload.get("model") or os.environ.get("NJ605_RADAR_MODEL") or "").strip()


_PREFIX = "NJ605_RADAR"


def _api_unset(reason: str) -> bool:
    return "_API_BASE не задан" in reason or "токен сайта не задан" in reason


async def _site_post(path: str, data: dict) -> tuple:
    return await site.post_json(_PREFIX, path, data)


async def _api_post(ctx, path: str, data: dict) -> dict:
    """POST на сайт через общий слой site.post_json; ошибка = RuntimeError."""
    ok, reason, j = await _site_post(path, data)

    if not ok:
        if _api_unset(reason):
            log.info("nj605_radar: API сайта не настроен (NJ605_RADAR_API_BASE/токен) — пропускаю отправку")
            return {}

        raise RuntimeError(f"nj605_radar: сайт {path} → {reason}")

    return j if isinstance(j, dict) else {}


async def handle(ctx, payload: dict) -> dict:
    """Собрать находки по одному радару (payload: watch_id, code, query, region)."""
    watch_id = int(payload.get("watch_id") or 0)
    query = str(payload.get("query") or "").strip()[:300]
    region = str(payload.get("region") or "").strip()[:120]

    if not watch_id or len(query) < 3:
        raise RuntimeError("nj605_radar: в задаче нет watch_id или запроса")

    # Живые этапы для статус-ряда на /radar (ctx.progress → события очереди,
    # их читает Service_Nj605_Radar::live_for → /radar/api/status/<code>).
    await ctx.progress("Поиск заказов", f"— «{query}»" + (f", регион {region}" if region else ""))

    # --- 1. поиск: несколько формулировок, бережный интервал --------------------
    # Базовые формулировки покрывают разные контуры спроса. Их можно расширить
    # через env NJ605_RADAR_QUERIES (через «|»): каждый элемент — отдельный
    # поисковый запрос; {query} и {region} подставляются.
    # Формулировки. Раньше первая была «{query} регион {region} закупка» — без
    # региона в запрос попадало слово «регион», и выдача была мусорной.
    default_queries = [
        "{query} закупка тендер",
        "{query} поставка заявка",
        "{query} запрос предложений",
        "{query} ищу поставщика объявление",
        "{query} коммерческое предложение заказ",
    ]

    if region:
        default_queries.insert(1, "{query} {region} закупка тендер")

    extra = [q.strip() for q in os.environ.get("NJ605_RADAR_QUERIES", "").split("|") if q.strip()]

    for q in extra:
        if q not in default_queries:
            default_queries.append(q)

    queries = []

    for q in default_queries:
        qq = " ".join(q.replace("{query}", query).replace("{region}", region).split())

        if qq and qq not in queries:
            queries.append(qq)

    sources: dict[str, list] = {}      # host -> карточки страниц (дедуп по URL)
    seen_urls: set[str] = set()

    def _collect(rows):
        for r in rows:
            url = str(r.get("url") or r.get("href") or "").strip()
            host = _host_of(url)

            if not url.startswith("http") or not host or url in seen_urls:
                continue

            seen_urls.add(url)

            # один хост может отдать много страниц с заказами (агрегаторы:
            # zakupki.gov.ru, rostender.info и т.п.) — сохраняем ВСЕ, но
            # не более _max_sites() ссылок на хост, чтобы не засорять контекст.
            if host not in sources:
                sources[host] = []
            if len(sources[host]) >= _max_sites():
                continue

            sources[host].append({
                "url": url,
                "title": str(r.get("title") or "")[:200],
                "snippet": str(r.get("body") or "")[:500],
                "host": host,
            })

    t_search = time.monotonic()
    search_left = _search_budget()

    for i, q in enumerate(queries, 1):
        rows = await webread.ddg_text(q, max_results=8)
        before = len(seen_urls)
        _collect(rows)

        if not rows and len(seen_urls) == before:
            # Молчащий поисковик легко принять за «спроса нет» — говорим прямо.
            await ctx.progress("Поиск заказов",
                               f"{i}/{len(queries)} — поисковик не ответил, ссылок: {len(seen_urls)}")
        else:
            await ctx.progress("Поиск заказов", f"{i}/{len(queries)} формулировок, ссылок: {len(seen_urls)}")
        await _sleep(_delay())

        search_left = _search_budget() - (time.monotonic() - t_search)

        if search_left <= 0:
            await ctx.progress("Поиск заказов",
                               f"бюджет поиска исчерпан, ссылок: {len(seen_urls)} — перехожу к чтению")
            break

    # 1б. таргетированный поиск по площадкам закупок (site:-запросы): у площадок
    # нет открытого API, поэтому честно ищем ПРЯМО в их выдаче через поисковик.
    # Домашние домены переопределяются через NJ605_RADAR_SITE_DOMAINS (через «|»).
    sites_env = [x.strip().lower().rstrip("/") for x in
                 os.environ.get("NJ605_RADAR_SITE_DOMAINS", "").split("|") if x.strip()]
    tender_sites = sites_env or _default_tender_sites()

    for site in tender_sites:
        if _search_budget() - (time.monotonic() - t_search) <= 0:
            await ctx.progress("Поиск заказов",
                               f"бюджет поиска исчерпан — площадки пропускаю, ссылок: {len(seen_urls)}")
            break

        host = site.replace("www.", "") if site.startswith("www.") else site
        # пропускаем площадки, которые уже принес общий поиск (не дублируем трафик)
        if host in sources:
            continue

        await ctx.progress("Проверяю площадку", host)

        try:
            rows = await webread.ddg_text(f"site:{site} {query}", max_results=5)
        except Exception:  # noqa: BLE001 — площадка не критична
            rows = []

        _collect(rows)
        await _sleep(_delay())

    # Итог поиска. ВАЖНО: проверка стоит ПОСЛЕ площадок — общая выдача поисковика
    # может не дать ничего (лимиты/гео), а site:-запросы по площадкам закупок
    # работают; раньше радар падал «Поиск не дал источников», не попробовав их.
    if not sources:
        raise RuntimeError(
            "Поиск не дал ни одного источника. Если в этапах «поисковик не ответил» — "
            "проверьте сеть/прокси на сервере воркера; иначе попробуйте "
            "переформулировать запрос радара.")

    # --- 2. читаем топ-страницы (равномерно по хостам, не только первые N) ------
    blocks: list[dict] = []
    pool: list[dict] = []
    per_host = max(1, _max_sites() // max(1, len(sources)))

    for host, cards in sources.items():
        # Ровно per_host с каждого хоста. Раньше при per_host == 1 (а это как раз
        # случай «хостов много», когда site:-запросы уже отработали) каждый хост
        # отдавал до _max_sites() ссылок, и первые страницы пула оказывались
        # страницами ОДНОГО хоста: читались 8 ссылок агрегатора с антиботом,
        # остальные площадки (включая конкретную закупку) не читались вообще.
        pool.extend(cards[:per_host])

    # добираем до общего лимита страниц
    if len(pool) < _max_sites():
        for host, cards in sources.items():
            for c in cards:
                if c not in pool:
                    pool.append(c)
                if len(pool) >= _max_sites():
                    break
            if len(pool) >= _max_sites():
                break

    pages = pool[:_max_sites()]

    t_read = time.monotonic()

    for i, s in enumerate(pages, 1):
        if _read_budget() - (time.monotonic() - t_read) <= 0:
            await ctx.progress("Читаю страницу",
                               f"бюджет чтения исчерпан: прочитано {len(blocks)} из {len(pages)}")
            break

        await ctx.progress("Читаю страницу", f"{i}/{len(pages)} — {_host_of(s['url'])}")

        try:
            html, _ = await webread.fetch(s["url"])
            md, title = await webread.to_markdown(html, cap=3000)
        except Exception as e:  # noqa: BLE001 — страница не читается, идём дальше
            log.info("nj605_radar: не прочитали %s: %s", s["url"], _readable_reason(str(e)))
            continue

        if len(md) >= 200:
            blocks.append({**s, "title": title or s["title"], "md": md})

    if not blocks:
        raise RuntimeError("Ни один источник не удалось прочитать (страницы недоступны).")

    await ctx.progress("ИИ оценивает находки", f"страниц прочитано: {len(blocks)}")

    # --- 3. LLM-скоринг одним вызовом -----------------------------------------
    parts = [f"НАБЛЮДЕНИЕ: {query}" + (f"; регион: {region}" if region else ""), "",
             "НАХОДКИ ИЗ ИСТОЧНИКОВ:"]

    for b in blocks:
        parts.append(f"--- {b['title']} ({_host_of(b['url'])}) ---")
        parts.append(f"URL: {b['url']}")
        parts.append(b["md"])

    # Сниппеты поиска — САМЫЙ полезный источник для радара: в них часто прямо
    # написана закупка («Поставка реле …», «Предмет тендера: …»), а страница
    # может не открыться (площадки закрыты/тяжёлые). Раньше сниппеты в промпт
    # не попадали, и радар регулярно возвращал «ничего не нашлось».
    cards = cards_from(sources)

    if cards:
        parts.append("")
        parts.append("РЕЗУЛЬТАТЫ ПОИСКА (сниппеты — здесь тоже бывают закупки):")

        for c in cards[:60]:
            parts.append(f"--- {c['title']} ({c['host']}) ---")
            parts.append(f"URL: {c['url']}")
            parts.append(c["snippet"] or "(без описания)")

    parts.append("")
    parts.append('Верни JSON: {"items": [{"title": "...", "url": "точный URL из находок", '
                 '"customer": "кто заказчик или пусто", "price": "бюджет или пусто", '
                 '"date": "дата или пусто", "score": 0, "why": "почему подходит"}]}')

    llm_note = ""

    try:
        raw = await _llm(ctx, [{"role": "system", "content": SYSTEM},
                               {"role": "user", "content": "\n".join(parts)}],
                         "nj605_radar", model_env="NJ605_RADAR_MODEL", max_tokens=2500, timeout=300)
    except Exception as e:  # noqa: BLE001 — модель молчит: не роняем радар
        log.warning("nj605_radar[%s]: модель недоступна: %s", watch_id, _readable_reason(str(e)))
        raw = ""
        llm_note = "модель не ответила — находки отобраны по словам запроса"

    parsed = webread.json_from_text(raw)

    if not isinstance(parsed, dict) or not isinstance(parsed.get("items"), list):
        # ИИ недоступен или ответил не по схеме (бывает у длинных подборок
        # закупок). Не роняем задачу: ищем совпадения по словам запроса прямо в
        # сниппетах — радар обязан вернуть хоть что-то проверяемое, а не
        # «Ошибку», и ЧЕСТНО сказать, что модель тут не участвовала.
        items = _fallback_items(cards_from(sources), query)

        if items:
            note = llm_note or "ИИ ответил не по схеме — находки отобраны по словам запроса"

            await ctx.progress("Отправляю находки", f"{note} (находок: {len(items)})")

            res = await _api_post(ctx, "/items", {"watch_id": watch_id, "items": _to_json(items)})
            log.info("nj605_radar[%s]: запасной отбор %d, сайт принял %s",
                     watch_id, len(items), res.get("saved", "?"))

            return {"ok": True, "saved": int(res.get("saved") or 0), "note": note}

        raise RuntimeError(
            "Модель не ответила, и совпадений по словам запроса не нашлось — "
            "проверьте LLM-провайдера и повторите сбор."
            if llm_note else "ИИ вернул не JSON со списком items")

    # URL берём только из реальных находок — защита от выдуманных ссылок.
    # Сравнение НОРМАЛИЗОВАННОЕ (схема/www/хвост/параметры): строгое равенство
    # выбрасывало годные находки только потому, что модель вернула ссылку без
    # «https://» или с «/» на конце.
    known_urls = [_norm_url(b["url"]) for b in blocks]
    known_urls += [_norm_url(c["url"]) for c in cards]

    def _known(url: str) -> str:
        n = _norm_url(url)

        if not n:
            return ""

        if n in known_urls:
            return url

        # префиксное совпадение: карточка «…/tender-4277244/», модель дала
        # «…/tender-4277244» (или наоборот) — считаем той же находкой
        for k in known_urls:
            if len(n) >= 25 and (n.startswith(k) or k.startswith(n)):
                return url

        return ""

    items = []

    for it in parsed["items"][:30]:
        if not isinstance(it, dict):
            continue

        url = _known(str(it.get("url") or "").strip())

        if url:
            items.append({
                "title": str(it.get("title") or "")[:300],
                "url": url,
                "customer": str(it.get("customer") or "")[:300],
                "price": str(it.get("price") or "")[:60],
                "date": str(it.get("date") or "")[:40],
                "score": it.get("score") or 0,
                "why": str(it.get("why") or "")[:1000],
                # площадка: даём воркеру прокинуть домены, но сайт сам выводит
                # host из URL, если поле пустое/отсутствует — на доверии не строим
                "source": _host_of(url) or "",
            })

    if not items:
        # ИИ не увидел заказов — отдаём совпадения по словам запроса, если они
        # есть в сниппетах (лучше проверяемая находка, чем пустой радар)
        items = _fallback_items(cards, query)

        if items:
            await ctx.progress("Отправляю находки",
                               f"ИИ ничего не отобрал — отдаю совпадения по запросу: {len(items)}")

    if not items:
        await ctx.progress("Готово", "подходящих заказов в находках нет")
        return {"ok": True, "saved": 0, "note": "подходящих заказов в находках нет"}

    await ctx.progress("Отправляю находки", f"найдено: {len(items)}")

    res = await _api_post(ctx, "/items", {
        "watch_id": watch_id,
        "items": _to_json(items),
    })

    log.info("nj605_radar[%s]: найдено %d, сайт принял %s", watch_id, len(items),
             res.get("saved", "?"))

    return {"ok": True, "found": len(items), "saved": res.get("saved", 0)}


def cards_from(sources: dict) -> list[dict]:
    """Все карточки поиска одним списком (сниппеты — тоже находки)."""
    return [c for cards_list in (sources or {}).values() for c in cards_list]


def _norm_url(url: str) -> str:
    """URL к сравнимому виду: без схемы, www, хвостового «/» и параметров."""
    u = (url or "").strip().lower()

    if not u:
        return ""

    u = u.split("#", 1)[0].split("?", 1)[0]
    u = u.replace("https://", "", 1).replace("http://", "", 1)
    u = u.replace("www.", "", 1)
    return u.rstrip("/")


def _tokens(text: str) -> list[str]:
    """Слова запроса длиной ≥4 (по ним ищем совпадения в сниппетах)."""
    import re as _re

    return [t for t in _re.split(r"[^0-9a-zA-Zа-яА-ЯёЁ]+", (text or "").lower()) if len(t) >= 4]


# Маркеры СПРОСА (заказчик) и ПРЕДЛОЖЕНИЯ (продавец). Радар ищет спрос, поэтому
# в запасном отборе объявления «продам/в наличии/прайс» отбрасываются: иначе
# пользователю прилетят магазины вместо заказов.
_DEMAND = ("закупк", "тендер", "аукцион", "котировк", "запрос предложен",
           "запрос цен", "поставк", "ищу ", "требуется", "конкурс", "подряд",
           "zakupki", "tender", "нужны ", "нужен ")
_SUPPLY = ("продам", "продаю", "купить", "в наличии", "оптом", "прайс", "цена",
           "магазин", "склад", "каталог", "распродажа", "б/у", "ремонт", "купим")


def _fallback_items(cards: list[dict], query: str, limit: int = 8) -> list[dict]:
    """Запасной отбор, когда ИИ вернул пусто, а находки есть.

    Радар должен приносить ХОТЯ БЫ совпадения по словам запроса: иначе
    пользователь видит «ничего не нашлось» даже там, где закупка лежит в
    сниппете (владелец: «готовый результат ни разу не видел»). Помечаем честно
    — «совпадение по запросу, без оценки ИИ» и низким score.
    """
    words = _tokens(query)

    if not words:
        return []

    out = []

    for c in cards:
        title = (c.get("title") or "").lower()
        hay = f"{title} {c.get('snippet', '')}".lower()

        if not any(w in hay for w in words):
            continue

        # спрос, а не предложение: «продам/в наличии/прайс» — не заказ
        if not any(m in hay for m in _DEMAND):
            continue

        if any(m in title for m in _SUPPLY):
            continue

        out.append({
            "title": (c.get("title") or c["url"])[:300],
            "url": c["url"],
            "customer": "",
            "price": "",
            "date": "",
            "score": 40,
            "why": f"Совпадение по запросу «{query}» — без оценки ИИ, проверьте вручную",
            "source": c.get("host", "") or _host_of(c["url"]) or "",
        })

        if len(out) >= limit:
            break

    return out


def _to_json(data) -> str:
    import json

    return json.dumps(data, ensure_ascii=False)


async def _sleep(sec: float) -> None:
    import asyncio

    if sec > 0:
        await asyncio.sleep(sec)
