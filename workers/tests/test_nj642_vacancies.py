"""Модуль вакансий (nj642) обязан доносить вакансии с сайта работодателя.

Владелец (26.09.2026): «смотри страницы „Карьера“ „Вакансии“ „О компании“ — эта
информация важна, её можно обновлять и хранить историю появления и исчезновения
вакансий; по вакансиям от работодателя можно сделать отдельный модуль».

Проверяем конвейер воркера: страницы работодателя → строгий JSON от модели →
POST /vacancies/api/ingest. Пустой ответ модели — честный пустой список (не
выдуманные вакансии), агрегатор вакансий вместо сайта работодателя — пропуск,
сломанный сайт одной компании не срывает сбор по остальным.
"""
import asyncio
import json

import pytest

import n2j_workers.handlers.nj642_vacancies as mod
from n2j_workers import webread


class _Ctx:
    def __init__(self, reply="{}"):
        self.steps = []
        self.reply = reply
        self.llm = self

    async def progress(self, stage=None, note=None):
        self.steps.append((stage, note))

    async def heartbeat(self):
        return True

    async def chat_with_usage(self, messages, **kw):
        # модель отвечает JSON-строкой, как настоящая: парсит webread.json_from_text
        return self.reply, {"prompt_tokens": 10, "completion_tokens": 5}


PAGE_HTML = ("<html><body><h1>Вакансии</h1><p>" + ("текст страницы " * 40)
             + "</p><a href='/vacancies/povar'>Повар</a></body></html>")


def test_clean_trims_and_never_invents():
    v = mod._clean({"title": "  Повар  ", "city": None, "salary": "60 000", "url": ""}, "https://a.ru/v")

    assert v["title"] == "Повар"
    assert v["city"] == ""
    assert v["salary"] == "60 000"
    assert v["url"] == ""
    assert v["source_url"] == "https://a.ru/v"


def test_blocked_hosts_are_aggregators():
    assert mod._is_blocked("hh.ru")
    assert mod._is_blocked("www.superjob.ru")
    assert not mod._is_blocked("romashka.ru")


def test_extract_vacancies_keeps_only_real_rows(monkeypatch):
    ctx = _Ctx()
    ctx.reply = json.dumps({"vacancies": [
        {"title": "Повар", "city": "Москва", "salary": "60 000", "snippet": "смены 5/2"},
        {"title": "Повар", "city": "Москва"},          # дубль — не нужен
        {"title": ""},                                  # пустая должность — не нужна
        "мусор",                                        # не объект
    ], "note": "страница вакансий"}, ensure_ascii=False)

    pages = [{"url": "https://a.ru/vacancies", "label": "Вакансии", "md": "текст"}]
    found, usage = asyncio.run(mod.extract_vacancies(ctx, "Компания: А", pages))

    assert [v["title"] for v in found] == ["Повар"]
    assert usage["found"] == 1
    assert found[0]["source_url"] == "https://a.ru/vacancies"


def test_extract_vacancies_without_json_is_loud():
    ctx = _Ctx()
    ctx.reply = "извините, не могу"

    with pytest.raises(ValueError):
        asyncio.run(mod.extract_vacancies(ctx, "Компания: А", [{"url": "u", "md": "текст"}]))


def test_empty_page_gives_empty_list_not_inventions():
    ctx = _Ctx()
    ctx.reply = json.dumps({"vacancies": [], "note": "вакансий сейчас нет"}, ensure_ascii=False)

    found, _ = asyncio.run(mod.extract_vacancies(ctx, "Компания: А", [{"url": "u", "md": "сейчас вакансий нет"}]))

    assert found == []


def test_ingest_from_pages_posts_payload(monkeypatch):
    monkeypatch.setenv("NJ642_VACANCIES_API_BASE", "https://n2j.ru/vacancies/api")
    ctx = _Ctx()
    ctx.reply = json.dumps({"vacancies": [{"title": "Повар", "city": "Москва"}]}, ensure_ascii=False)
    posted = []

    async def fake_post(prefix, path, data, **kw):
        posted.append((prefix, path, data))
        return True, "", {"ok": True, "added": 1, "restored": 1}

    monkeypatch.setattr(mod.site, "post_json", fake_post)

    n = asyncio.run(mod.ingest_from_pages(
        ctx, company="Ромашка", domain="romashka.ru", profile_code="cmp1",
        pages=[{"url": "https://romashka.ru/vacancies", "label": "Вакансии", "md": "текст"}], watch_id=7))

    assert n == 2   # added + restored
    prefix, path, data = posted[0]
    assert prefix == "NJ642_VACANCIES"
    assert path == "/ingest"
    assert data["company"] == "Ромашка"
    assert data["watch_id"] == "7"
    assert data["full"] == "1"
    assert json.loads(data["vacancies"])[0]["title"] == "Повар"


def test_handle_requires_companies():
    ctx = _Ctx()

    with pytest.raises(RuntimeError):
        asyncio.run(mod.handle(ctx, {"watch_id": 1}))


def test_api_base_can_be_derived_from_site_env(monkeypatch):
    """Новый модуль: адрес API выводится из уже настроенного адреса сайта."""
    monkeypatch.delenv("NJ642_VACANCIES_API_BASE", raising=False)
    monkeypatch.delenv("NJ642_API_BASE", raising=False)
    monkeypatch.setenv("NJ591_PROFILE_API_BASE", "https://n2j.ru/company/api")

    assert mod._api_base() == "https://n2j.ru/vacancies/api"


def test_api_base_empty_without_any_site_env(monkeypatch):
    monkeypatch.delenv("NJ642_VACANCIES_API_BASE", raising=False)
    monkeypatch.delenv("NJ642_API_BASE", raising=False)
    monkeypatch.delenv("NJ591_PROFILE_API_BASE", raising=False)
    monkeypatch.delenv("NJ605_RADAR_API_BASE", raising=False)
    monkeypatch.delenv("NJ641_API_BASE", raising=False)
    monkeypatch.delenv("WORKER_API_BASE", raising=False)
    monkeypatch.delenv("WORKER_SITE_BASE", raising=False)

    assert mod._api_base() == ""


def test_handle_collects_per_company_and_survives_one_broken_site(monkeypatch):
    monkeypatch.setenv("NJ642_VACANCIES_API_BASE", "https://n2j.ru/vacancies/api")
    ctx = _Ctx()
    ctx.reply = json.dumps({"vacancies": [{"title": "Повар", "city": "Москва"}]}, ensure_ascii=False)
    posted = []
    finished = []

    async def fake_fetch(url, **kw):
        if "bad" in url:
            raise webread.WebReadError("нет ответа")
        return PAGE_HTML, 200

    async def fake_markdown(html, cap=2200):
        return ("Вакансии: повар, Москва, 60 000 ₽, смены 5/2. "
                "Сварщик, Москва, опыт от года. " * 3), "Вакансии"

    async def fake_post(prefix, path, data, **kw):
        if path == "/finish":
            finished.append(data)
        else:
            posted.append(data)
        return True, "", {"ok": True, "added": 1, "restored": 0}

    monkeypatch.setattr(mod.webread, "fetch", fake_fetch)
    monkeypatch.setattr(mod.webread, "to_markdown", fake_markdown)
    monkeypatch.setattr(mod.webread, "extract_links",
                        lambda html, base, patterns, limit=3: [{"url": base + "vacancies", "label": "Вакансии"}])
    monkeypatch.setattr(mod.site, "post_json", fake_post)

    res = asyncio.run(mod.handle(ctx, {
        "watch_id": 5, "code": "vac1", "city": "Москва", "direction": "повар",
        "companies": [
            {"name": "Ромашка", "domain": "romashka.ru"},
            {"name": "Сломатая", "domain": "bad.example"},
            {"name": "Агрегатор", "domain": "hh.ru"},
        ],
    }))

    assert res["companies"] == 1
    assert len(posted) == 1                     # только живой сайт работодателя
    assert posted[0]["domain"] == "romashka.ru"
    # обход закончен — задача очереди закрывается («Готово»)
    assert finished and finished[0]["watch_id"] == "5"
    assert any("Собираю вакансии" in str(s) for s in ctx.steps)


def test_handle_loud_when_no_page_read():
    ctx = _Ctx()

    with pytest.raises(RuntimeError):
        asyncio.run(mod.handle(ctx, {"watch_id": 5, "code": "vac1",
                                     "companies": [{"name": "Без сайта", "domain": ""}]}))


# ---------------------------------------------------------------------------
# Авто-режим: «только город + направление» — компании с сайтами воркер находит сам
# (владелец, 27.09.2026)
# ---------------------------------------------------------------------------

def test_find_blocked_keeps_employer_sites_and_drops_boards():
    assert mod._find_blocked("hh.ru")
    assert mod._find_blocked("www.2gis.ru")
    assert mod._find_blocked("vk.com")
    assert not mod._find_blocked("romashka.ru")
    assert not mod._find_blocked("")


def test_clean_found_name_strips_tails():
    assert mod._clean_found_name("ООО «Ромашка» — производство мебели в Москве") == "ООО «Ромашка»"
    assert mod._clean_found_name("Ромашка | Официальный сайт") == "Ромашка"
    assert mod._clean_found_name("Ромашка: вакансии и карьера") == "Ромашка"
    assert mod._clean_found_name("") == ""


def test_discover_queries_include_city_and_direction():
    q = mod._discover_queries("Москва", "повар", "ночные смены")

    assert len(q) == 3
    assert all("Москва" in x and "повар" in x for x in q)


def test_discover_prefers_our_own_base_and_skips_search_when_enough(monkeypatch):
    """Своё знание (карта отрасли nj607, профили, прошлые сборы) — до поиска."""
    posted = []
    searched = []

    async def fake_get(prefix, path, **kw):
        assert path.startswith("/hints/")
        return True, "", {"ok": True, "companies": [
            {"name": "Ромашка", "domain": "romashka.ru", "source": "карта отрасли", "why": "nj607"},
            {"name": "Василёк", "domain": "vasilek.ru", "source": "профиль компании"},
        ]}

    async def fake_post(prefix, path, data, **kw):
        posted.append((path, data))
        return True, "", {"ok": True, "added": 2, "total": 2, "companies": json.loads(data["companies"])}

    async def fake_ddg(query, max_results=8):
        searched.append(query)
        return []

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod.site, "post_json", fake_post)
    monkeypatch.setattr(mod.webread, "ddg_text", fake_ddg)

    ctx = _Ctx()
    # цель 2 — из своей базы уже набралось ровно столько: поиск не нужен
    picked = asyncio.run(mod.discover(ctx, "vac1", 5, "Москва", "повар", "", want=2))

    assert [c["domain"] for c in picked] == ["romashka.ru", "vasilek.ru"]
    assert [p[0] for p in posted] == ["/companies"]
    assert json.loads(posted[0][1]["companies"])[0]["name"] == "Ромашка"
    assert searched == []           # из своей базы уже набралось — поиск не нужен
    assert any("Подбираю компании" in str(s) for s in ctx.steps)


def test_discover_adds_search_results_and_filters_aggregators(monkeypatch):
    async def fake_get(prefix, path, **kw):
        return True, "", {"ok": True, "companies": []}

    sent = []

    async def fake_post(prefix, path, data, **kw):
        sent.append(json.loads(data["companies"]))
        return True, "", {"ok": True, "added": 1, "total": 1,
                          "companies": [{"name": "ООО «Ромашка»", "domain": "romashka.ru"}]}

    async def fake_ddg(query, max_results=8):
        return [
            {"title": "Вакансии повара — hh.ru", "url": "https://hh.ru/vacancies/povar"},
            {"title": "ООО «Ромашка» — производство мебели в Москве", "url": "https://romashka.ru/"},
            {"title": "Справочник", "url": "https://2gis.ru/moscow"},
        ]

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod.site, "post_json", fake_post)
    monkeypatch.setattr(mod.webread, "ddg_text", fake_ddg)

    picked = asyncio.run(mod.discover(_Ctx(), "vac1", 5, "Москва", "повар", "", want=8))

    # hh.ru и 2gis.ru — не сайты работодателей; сайту уходит только Ромашка,
    # с очищенным названием и пояснением, откуда она взялась
    assert [c["domain"] for c in sent[0]] == ["romashka.ru"]
    assert sent[0][0]["name"] == "ООО «Ромашка»"
    assert sent[0][0]["source"] == "поиск"
    assert "нашлась поиском" in sent[0][0]["why"]
    # ответ сайта — итоговый список компаний монитора, он главнее
    assert picked == [{"name": "ООО «Ромашка»", "domain": "romashka.ru", "profile_code": ""}]


def test_handle_auto_mode_finds_companies_and_scrapes(monkeypatch):
    """Монитор без компаний («город + направление») реально стартует."""
    monkeypatch.setenv("NJ642_VACANCIES_API_BASE", "https://n2j.ru/vacancies/api")
    ctx = _Ctx()
    ctx.reply = json.dumps({"vacancies": [{"title": "Повар", "city": "Москва"}]}, ensure_ascii=False)
    ingested = []
    finished = []

    async def fake_get(prefix, path, **kw):
        return True, "", {"ok": True, "companies": [
            {"name": "Ромашка", "domain": "romashka.ru", "source": "карта отрасли"},
        ]}

    async def fake_post(prefix, path, data, **kw):
        if path == "/finish":
            finished.append(data)
        elif path == "/companies":
            return True, "", {"ok": True, "added": 1, "total": 1,
                              "companies": [{"name": "Ромашка", "domain": "romashka.ru"}]}
        else:
            ingested.append(data)

        return True, "", {"ok": True, "added": 1, "restored": 0}

    async def fake_fetch(url, **kw):
        return PAGE_HTML, 200

    async def fake_markdown(html, cap=2200):
        return "Вакансии: повар, Москва, 60 000 ₽. " * 5, "Вакансии"

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod.site, "post_json", fake_post)
    monkeypatch.setattr(mod.webread, "fetch", fake_fetch)
    monkeypatch.setattr(mod.webread, "to_markdown", fake_markdown)
    monkeypatch.setattr(mod.webread, "extract_links",
                        lambda html, base, patterns, limit=3: [{"url": base + "vacancies", "label": "Вакансии"}])

    res = asyncio.run(mod.handle(ctx, {
        "watch_id": 9, "code": "vac9", "city": "Москва", "direction": "повар",
        "mode": "auto", "find": 8, "companies": [],
    }))

    assert res["companies"] == 1
    assert ingested and ingested[0]["domain"] == "romashka.ru"
    assert finished and finished[0]["watch_id"] == "9"


def test_handle_auto_mode_without_found_companies_is_honest_done(monkeypatch):
    """Ничего не нашлось — это честный «Готово», а не молчание задачи."""
    monkeypatch.setenv("NJ642_VACANCIES_API_BASE", "https://n2j.ru/vacancies/api")
    finished = []

    async def fake_get(prefix, path, **kw):
        return True, "", {"ok": True, "companies": []}

    async def fake_post(prefix, path, data, **kw):
        if path == "/finish":
            finished.append(data)

        return True, "", {"ok": True, "added": 0, "total": 0, "companies": []}

    async def fake_ddg(query, max_results=8):
        return []

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod.site, "post_json", fake_post)
    monkeypatch.setattr(mod.webread, "ddg_text", fake_ddg)

    ctx = _Ctx()
    res = asyncio.run(mod.handle(ctx, {
        "watch_id": 9, "code": "vac9", "city": "Москва", "direction": "повар",
        "mode": "auto", "find": 8, "companies": [],
    }))

    assert res["companies"] == 0 and res["found"] == 0
    assert finished and finished[0]["watch_id"] == "9"
    assert any("не нашлось" in str(s) for s in ctx.steps)


def test_handle_sites_mode_without_companies_is_still_loud():
    """Режим «свои компании» без компаний — ошибка задачи, о ней надо кричать."""
    with pytest.raises(RuntimeError):
        asyncio.run(mod.handle(_Ctx(), {"watch_id": 1, "mode": "sites", "companies": []}))


def test_auto_mode_limits_come_from_env(monkeypatch):
    monkeypatch.setenv("NJ642_VACANCIES_COMPANIES", "7")
    monkeypatch.setenv("NJ642_VACANCIES_DISCOVER_BUDGET", "45")

    assert mod._max_companies() == 7
    assert mod._discover_budget() == 45.0

    monkeypatch.setenv("NJ642_VACANCIES_COMPANIES", "мусор")
    monkeypatch.setenv("NJ642_VACANCIES_DISCOVER_BUDGET", "мусор")

    assert mod._max_companies() == 12
    assert mod._discover_budget() == 120.0
