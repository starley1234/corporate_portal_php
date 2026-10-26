"""Конвейер радара обязан ДОНОСИТЬ находки до сайта.

Владелец: «Посмотри алгоритм поиска заказов… я готовый результат ни разу не
видел». Проверяем именно конвейер ПОСЛЕ поиска: сниппеты доходят до отбора,
ссылка ИИ сопоставляется нормализованно, а при пустом/некорректном ответе ИИ
радар отдаёт запасной список (спрос, а не магазины) — вместо «ничего не нашлось».
"""
import json

import pytest

import n2j_workers.handlers.nj605_radar as mod


class _Ctx:
    def __init__(self):
        self.steps = []

    async def progress(self, stage=None, note=None):
        self.steps.append((stage, note))

    async def heartbeat(self):
        return True


DEMAND_CARD = {
    "url": "https://rts-tender.ru/market/zakupka-rele/tender-4277244/",
    "title": "Закупка реле РЭС-9 — мониторинг рынка цен",
    "snippet": "Предмет закупки: поставка реле; заказчик АО «Прибор»",
}
SUPPLY_CARD = {
    "url": "https://shop.example/relay",
    "title": "Продам реле авиационные, в наличии, прайс",
    "snippet": "реле купить оптом со склада",
}

PAGE_HTML = "<html><body><h1>Закупка реле</h1><p>" + ("текст " * 80) + "</p></body></html>"


def _patch(monkeypatch, cards, llm_reply, posted, fetched=None):
    async def fake_ddg(q, max_results=8):
        return [{"url": c["url"], "title": c["title"], "body": c["snippet"]} for c in cards]

    async def fake_fetch(url, **kw):
        if fetched is not None:
            fetched.append(url)
        return PAGE_HTML, 200

    async def fake_llm(ctx, messages, tag, **kw):
        posted["prompt"] = messages[-1]["content"]
        return llm_reply

    async def fake_post(ctx, path, data):
        # воркер отправляет items JSON-СТРОКОЙ (как в контракте /radar/api/items)
        items = json.loads(data.get("items") or "[]")
        posted.setdefault("posts", []).append((path, data))
        return {"saved": len(items)}

    monkeypatch.setattr(mod.webread, "ddg_text", fake_ddg)
    monkeypatch.setattr(mod.webread, "fetch", fake_fetch)
    monkeypatch.setattr(mod, "_llm", fake_llm)
    monkeypatch.setattr(mod, "_api_post", fake_post)

    # площадки закупок в тесте не нужны — гоняем только общий поиск;
    # пауза между запросами тоже ни к чему (в бою она бережёт поисковик)
    monkeypatch.setenv("NJ605_RADAR_SITE_DOMAINS", "example.invalid")
    monkeypatch.setenv("NJ605_RADAR_SEARCH_DELAY", "0")


@pytest.mark.asyncio
async def test_items_are_delivered(monkeypatch):
    """ИИ вернул ссылку с «/» на конце — находка ОБЯЗАНА дойти до сайта."""
    posted = {}
    _patch(monkeypatch, [DEMAND_CARD], (
        '{"items": [{"title": "Закупка реле РЭС-9", '
        '"url": "https://rts-tender.ru/market/zakupka-rele/tender-4277244", '
        '"score": 80, "why": "нужны реле"}]}'), posted)

    res = await mod.handle(_Ctx(), {"watch_id": 1, "code": "abc", "query": "Авиационные Реле"})

    assert res["saved"] == 1
    path, data = posted["posts"][0]
    assert path == "/items"
    # сниппеты поиска обязаны попадать в промпт отбора
    assert "РЕЗУЛЬТАТЫ ПОИСКА" in posted["prompt"]
    assert "Предмет закупки: поставка реле" in posted["prompt"]


@pytest.mark.asyncio
async def test_ne_schema_fallback(monkeypatch):
    """ИИ ответил не по схеме — задача не падает, отдаём совпадения по запросу."""
    posted = {}
    _patch(monkeypatch, [DEMAND_CARD, SUPPLY_CARD], "извините, не могу", posted)

    res = await mod.handle(_Ctx(), {"watch_id": 2, "code": "xyz", "query": "Авиационные Реле"})

    assert res["saved"] == 1
    items = [i["url"] for i in json.loads(posted["posts"][0][1]["items"])]
    assert DEMAND_CARD["url"] in items
    # магазин («продам/в наличии/прайс») в запасной список не попадает
    assert SUPPLY_CARD["url"] not in items


@pytest.mark.asyncio
async def test_model_silent_fallback_is_labelled(monkeypatch):
    """Модель молчит (исключение, не мусор) — радар не падает, а честно помечает.

    Владелец: «где включается эвристика?» — эвристика включается, когда модель
    недоступна/не ответила: находки отбираются по словам запроса, и итог несёт
    пометку «модель не ответила», чтобы это не приняли за разбор моделью.
    """
    posted = {}
    _patch(monkeypatch, [DEMAND_CARD, SUPPLY_CARD], None, posted)

    async def boom(ctx, messages, tag, **kw):
        raise TimeoutError("модель не ответила")

    monkeypatch.setattr(mod, "_llm", boom)

    res = await mod.handle(_Ctx(), {"watch_id": 21, "code": "silent", "query": "Авиационные Реле"})

    assert res["saved"] == 1
    assert "не ответила" in res["note"]
    items = [i["url"] for i in json.loads(posted["posts"][0][1]["items"])]
    assert DEMAND_CARD["url"] in items


@pytest.mark.asyncio
async def test_empty_llm_items_fallback(monkeypatch):
    """ИИ отобрал пусто, но закупка есть в сниппете — радар отдаёт её."""
    posted = {}
    _patch(monkeypatch, [DEMAND_CARD], '{"items": []}', posted)

    res = await mod.handle(_Ctx(), {"watch_id": 3, "code": "qwe", "query": "Авиационные Реле"})

    assert res["saved"] == 1
    items = json.loads(posted["posts"][0][1]["items"])
    assert [i["url"] for i in items] == [DEMAND_CARD["url"]]


def test_norm_url():
    assert mod._norm_url("HTTPS://WWW.Zakupki.gov.ru/a/b/?utm=1#x") == "zakupki.gov.ru/a/b"
    assert mod._norm_url("http://x.ru/") == "x.ru"


@pytest.mark.asyncio
async def test_pages_are_read_across_hosts(monkeypatch):
    """Хостов много — читаем по странице с РАЗНЫХ площадок, а не всё с первой.

    Регрессия: при per_host == 1 (как раз случай «площадок много») каждый хост
    отдавал в пул до _max_sites() ссылок, и первые страницы пула оказывались
    страницами одного хоста. Если это агрегатор с антиботом, чтение давало ноль
    блоков → «ни один источник не удалось прочитать» и пустой радар, хотя на
    других площадках закупки были.
    """
    cards = [{
        "url": f"https://p{i}.example/tender-{i}{j}",
        "title": f"Закупка реле, площадка {i}",
        "snippet": "поставка реле, запрос предложений",
    } for i in range(6) for j in range(3)]

    posted = {}
    fetched = []
    _patch(monkeypatch, cards, '{"items": []}', posted, fetched=fetched)
    monkeypatch.setenv("NJ605_RADAR_MAX_SITES", "6")

    await mod.handle(_Ctx(), {"watch_id": 4, "code": "bal", "query": "Реле"})

    hosts = {mod._host_of(u) for u in fetched}
    assert len(fetched) == 6
    assert len(hosts) == 6, f"читали только {hosts} — площадки не распределяются"
