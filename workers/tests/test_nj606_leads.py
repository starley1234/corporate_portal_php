"""Поток заказчиков (/leads) обязан: рапортовать этапы, отдавать находки и
заканчиваться понятной причиной вместо тишины.

Владелец (26.09): «Подвисает процесс на https://n2j.ru/leads» — скриншот:
«Собираем компании 2:25», «нет пульса 10 мин», этап «ИИ извлекает компании
источников: 3» и дальше ничего.

Причина оказалась в сборке промпта: сниппеты поиска собирались двойным обходом
словаря `sources` ({хост: карточка}), из-за чего `c['title']` падал с TypeError
ровно перед вызовом модели. Тесты ниже фиксируют и этот конвейер, и видимость
этапов (в том числе «модель думает N с»), и понятные финалы.
"""
import asyncio
import json

import pytest

import n2j_workers.handlers.nj606_leads as mod


class _Ctx:
    def __init__(self):
        self.steps = []

    async def progress(self, stage=None, note=None):
        self.steps.append((stage, note))

    async def heartbeat(self):
        return True

    def stages(self):
        return [s for s, _ in self.steps]

    def texts(self):
        return " | ".join(f"{s}: {n}" for s, n in self.steps)


HTML = "<html><body><h1>ООО «Гофрокартон»</h1><p>" + ("текст " * 120) + "</p></body></html>"
MD = "ООО «Гофрокартон» — производитель упаковки. " * 20

CARDS = [
    {"url": "https://gofra.example/about", "title": "Гофрокартон — производители",
     "snippet": "ООО Гофрокартон, упаковка из гофрокартона, Казань"},
    {"url": "https://pack.example/catalog", "title": "Каталог упаковки",
     "snippet": "производитель упаковки ООО Пак-Трейд"},
]

GOOD_REPLY = json.dumps({"companies": [
    {"name": "ООО «Гофрокартон»", "site": "https://gofra.example", "inn": "",
     "what": "упаковка из гофрокартона", "hook": "новый цех в Казани", "score": 80},
    {"name": "ООО «Пак-Трейд»", "site": "https://pack.example", "inn": "",
     "what": "производство упаковки", "hook": "каталог 2026", "score": 65},
]}, ensure_ascii=False)


def _patch(monkeypatch, llm_reply, posted, *, cards=None, delay="0"):
    async def fake_ddg(q, max_results=8):
        return [{"url": c["url"], "title": c["title"], "body": c["snippet"]}
                for c in (cards if cards is not None else CARDS)]

    async def fake_fetch(url, **kw):
        return HTML, 200

    async def fake_md(html, cap=None):
        return MD, "ООО «Гофрокартон»"

    async def fake_llm(ctx, messages, tag, **kw):
        posted["messages"] = messages
        return llm_reply

    async def fake_post_json(prefix, path, data):
        posted.setdefault("posts", []).append((path, data))
        companies = json.loads(data.get("companies") or "[]")
        return True, "", {"saved": len(companies)}

    monkeypatch.setattr(mod.webread, "ddg_text", fake_ddg)
    monkeypatch.setattr(mod.webread, "fetch", fake_fetch)
    monkeypatch.setattr(mod.webread, "to_markdown", fake_md)
    monkeypatch.setattr(mod, "_llm", fake_llm)
    monkeypatch.setattr(mod.site, "post_json", fake_post_json)
    monkeypatch.setenv("NJ606_LEADS_SEARCH_DELAY", delay)
    monkeypatch.setenv("NJ606_LEADS_MAX_SITES", "4")


@pytest.mark.asyncio
async def test_companies_are_delivered(monkeypatch):
    """Компании из ответа модели ОБЯЗАНЫ дойти до сайта, а не упасть в промпте.

    Регрессия: сборка сниппетов двойным обходом словаря падала с TypeError
    («string indices must be integers») — конвейер обрывался на этапе ИИ.
    """
    posted = {}
    _patch(monkeypatch, GOOD_REPLY, posted)

    res = await mod.handle(_Ctx(), {"flow_id": 7, "industry": "производители упаковки",
                                    "region": "Казань"})

    assert res["found"] == 2
    assert res["saved"] == 2
    path, data = posted["posts"][0]
    assert path == "/companies"
    companies = json.loads(data["companies"])
    assert [c["name"] for c in companies] == ["ООО «Гофрокартон»", "ООО «Пак-Трейд»"]

    # сниппеты поиска — в промпте (в них часто прямо названы компании)
    prompt = posted["messages"][-1]["content"]
    assert "РЕЗУЛЬТАТЫ ПОИСКА" in prompt
    assert "Гофрокартон — производители" in prompt
    assert "ООО Пак-Трейд" in prompt


@pytest.mark.asyncio
async def test_stages_are_reported(monkeypatch):
    """Каждый шаг конвейера виден в статус-ряде — «залипло» не должно случаться."""
    posted = {}
    _patch(monkeypatch, GOOD_REPLY, posted)
    ctx = _Ctx()

    await mod.handle(ctx, {"flow_id": 8, "industry": "производители упаковки"})

    for stage in ("Поиск источников", "Чтение источников",
                  "ИИ извлекает компании", "Отправляю компании"):
        assert stage in ctx.stages(), f"этап «{stage}» не рапортует"

    assert "найдено: 2" in ctx.texts()
    assert "Чтение источников: 1/" in ctx.texts()  # прогресс чтения по шагам


@pytest.mark.asyncio
async def test_model_ticks_are_visible(monkeypatch):
    """Пока модель думает, в журнал идут тики «модель думает N с (лимит M с)»."""
    posted = {}
    _patch(monkeypatch, GOOD_REPLY, posted)

    real = mod.wait_with_ticks

    async def fast_ticks(make_awaitable, *, every=20.0, limit=None, on_tick=None, what="модель"):
        # тот же контракт, только без 20-секундного ожидания в тесте
        return await real(make_awaitable, every=0.05, limit=limit, on_tick=on_tick, what=what)

    monkeypatch.setattr(mod, "wait_with_ticks", fast_ticks)

    async def slow_ask():
        await asyncio.sleep(0.12)
        return GOOD_REPLY

    async def fake_llm(ctx, messages, tag, **kw):
        return await slow_ask()

    monkeypatch.setattr(mod, "_llm", fake_llm)

    ctx = _Ctx()
    await mod.handle(ctx, {"flow_id": 9, "industry": "производители упаковки"})

    assert "модель думает" in ctx.texts()
    assert "лимит" in ctx.texts()


@pytest.mark.asyncio
async def test_model_timeout_falls_back_to_heuristics(monkeypatch):
    """Модель молчала — компании собираются эвристикой и итог ЧЕСТНО помечен.

    Владелец (26.09): «где включается эвристика?» — эвристика включается ровно
    здесь: модель не ответила, а находки уже собраны. Компании берём только те,
    что явно написаны в сниппетах/текстах, и говорим об этом в note, чтобы
    результат эвристики не принимали за разбор моделью.
    """
    posted = {}
    _patch(monkeypatch, GOOD_REPLY, posted)

    async def boom(make_awaitable, *, every=20.0, limit=None, on_tick=None, what="модель"):
        raise asyncio.TimeoutError()

    monkeypatch.setattr(mod, "wait_with_ticks", boom)
    monkeypatch.setenv("NJ606_LEADS_LLM_TIMEOUT", "90")

    ctx = _Ctx()
    res = await mod.handle(ctx, {"flow_id": 10, "industry": "производители упаковки"})

    assert res["found"] >= 1
    assert "эвристик" in res["note"]
    assert "не ответила за 90 с" in res["note"]
    assert "Эвристика без модели" in ctx.stages()
    assert posted["posts"][0][0] == "/companies"


@pytest.mark.asyncio
async def test_model_silent_and_no_heuristics_is_clear_error(monkeypatch):
    """Модель молчала, эвристике не за что зацепиться — ошибка с причиной.

    Площадки-агрегаторы (каталог, справочник) компаниями не считаются: лид из
    заголовка «Каталог упаковки» — это выдумка, а не находка.
    """
    posted = {}
    _patch(monkeypatch, GOOD_REPLY, posted, cards=[
        {"url": "https://avito.ru/ufa", "title": "Каталог упаковки — объявления",
         "snippet": "объявления о продаже упаковки"},
        {"url": "https://rusprofile.ru/x", "title": "Справочник организаций",
         "snippet": "база данных организаций России"},
    ])

    async def boom(make_awaitable, *, every=20.0, limit=None, on_tick=None, what="модель"):
        raise asyncio.TimeoutError()

    monkeypatch.setattr(mod, "wait_with_ticks", boom)
    monkeypatch.setenv("NJ606_LEADS_LLM_TIMEOUT", "90")

    ctx = _Ctx()

    with pytest.raises(RuntimeError) as e:
        await mod.handle(ctx, {"flow_id": 14, "industry": "производители упаковки"})

    assert "не ответила за 90 с" in str(e.value)
    assert "эвристика" in str(e.value).lower()
    assert "Не получилось" in ctx.stages()


@pytest.mark.asyncio
async def test_heuristics_skip_aggregators(monkeypatch):
    """Эвристика берёт компании с организационной формой и НЕ берёт агрегаторы."""
    sources = {
        "avito.ru": {"url": "https://avito.ru/1", "title": "Каталог", "snippet": "ООО «Ненужное»"},
        "gofra.example": {"url": "https://gofra.example/about", "title": "Гофрокартон",
                          "snippet": "ООО «Гофрокартон», упаковка, ИНН 1650123456"},
    }
    items = mod._heuristic_companies(sources, [])
    names = [i["name"] for i in items]

    assert any("Гофрокартон" in n for n in names)
    assert not any("Ненужное" in n for n in names)
    assert items[0]["site"].startswith("https://")
    assert items[0]["hook"] == ""  # зацепку эвристика не выдумывает


@pytest.mark.asyncio
async def test_empty_companies_is_valid_finish(monkeypatch):
    """Модель честно ничего не нашла — это «Готово», а не ошибка."""
    posted = {}
    _patch(monkeypatch, '{"companies": []}', posted)
    ctx = _Ctx()

    res = await mod.handle(ctx, {"flow_id": 11, "industry": "производители упаковки"})

    assert res["saved"] == 0
    assert "Готово" in ctx.stages()
    assert not posted.get("posts")


@pytest.mark.asyncio
async def test_model_garbage_falls_back_to_heuristics(monkeypatch):
    """Модель ответила не по схеме — работаем эвристикой и помечаем это в итоге.

    Отличать «модель посмотрела и не нашла» от «ответ нечитаем» обязательно:
    в первом случае это законное «Готово», во втором — итог эвристики с пометкой.
    """
    posted = {}
    _patch(monkeypatch, "извините, не могу", posted)

    res = await mod.handle(_Ctx(), {"flow_id": 12, "industry": "производители упаковки"})

    assert res["found"] >= 1
    assert "не разобранным текстом" in res["note"]
    assert "эвристик" in res["note"]


@pytest.mark.asyncio
async def test_search_failure_names_the_reason(monkeypatch):
    """Поисковик молчит — в этапах видна настоящая причина, а не «нет компаний»."""
    posted = {}
    _patch(monkeypatch, GOOD_REPLY, posted, cards=[])
    ctx = _Ctx()

    with pytest.raises(RuntimeError) as e:
        await mod.handle(ctx, {"flow_id": 13, "industry": "производители упаковки"})

    assert "поисковик не ответил" in ctx.texts()
    assert "Поиск не дал источников" in str(e.value)


def test_llm_timeout_default_is_generous(monkeypatch):
    """Лимит модели: 420 с по умолчанию (локальная модель идёт 5-7 минут)."""
    monkeypatch.delenv("NJ606_LEADS_LLM_TIMEOUT", raising=False)
    assert mod._llm_timeout() == 420.0

    monkeypatch.setenv("NJ606_LEADS_LLM_TIMEOUT", "600")
    assert mod._llm_timeout() == 600.0

    monkeypatch.setenv("NJ606_LEADS_LLM_TIMEOUT", "5")
    assert mod._llm_timeout() == 30.0  # ниже 30 с смысла нет
