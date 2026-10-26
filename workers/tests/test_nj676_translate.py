"""Тесты канала nj676.translate: карточки → перевод модели → сайт.

Запуск из каталога workers/:
    python3 -m pytest tests/test_nj676_translate.py -q

Проверяем без сети и без модели:
  * в подсказку модели уходит описание и инструменты, но не схемы;
  * перевод, которого нет в присланной пачке, не принимается;
  * пустое русское название не сохраняется;
  * результат прогона считает переведённые и пропущенные карточки.
"""

import asyncio

import pytest


def _load():
    import n2j_workers.handlers.nj676_translate as mod

    return mod


def test_card_text_keeps_description_and_tool_names():
    mod = _load()

    text = mod._card_text({
        "slug": "acme/mail",
        "name": "Mail MCP",
        "description": "Send email",
        "categories": ["communication"],
        "tools": [{"name": "send_email", "description": "Send a letter"}],
    })

    assert "Mail MCP" in text
    assert "Send email" in text
    assert "send_email: Send a letter" in text
    assert "inputSchema" not in text


def test_model_answer_is_filtered_by_requested_slugs(monkeypatch):
    mod = _load()

    async def fake_llm(ctx, messages, phase, **kwargs):
        return (
            '{"items": ['
            '{"slug": "acme/mail", "name_ru": "Почтовый сервер", "description_ru": "Отправляет письма"},'
            '{"slug": "чужая/карточка", "name_ru": "Чужое", "description_ru": "…"},'
            '{"slug": "acme/mail", "name_ru": "", "description_ru": "пусто"}'
            ']}',
            {},
        )

    monkeypatch.setattr(mod, "_llm", fake_llm)

    cards = [{"slug": "acme/mail", "name": "Mail MCP", "description": "Send email"}]
    result = asyncio.run(mod._translate_batch(None, cards))

    assert result == [{
        "slug": "acme/mail",
        "name_ru": "Почтовый сервер",
        "description_ru": "Отправляет письма",
    }]


def test_broken_json_does_not_break_the_run(monkeypatch):
    mod = _load()

    async def fake_llm(ctx, messages, phase, **kwargs):
        return ("не JSON вовсе", {})

    monkeypatch.setattr(mod, "_llm", fake_llm)

    cards = [{"slug": "acme/mail", "name": "Mail MCP", "description": "Send email"}]

    assert asyncio.run(mod._translate_batch(None, cards)) == []


def test_empty_answer_is_retried_and_then_split(monkeypatch):
    """Пустой ответ модели стоит одной карточки, а не пачки."""
    mod = _load()

    calls = []

    async def fake_batch(ctx, cards):
        calls.append(len(cards))
        if len(cards) > 1:
            return []

        return [{"slug": cards[0]["slug"], "name_ru": "Имя", "description_ru": "Описание"}]

    monkeypatch.setattr(mod, "_translate_batch", fake_batch)

    cards = [{"slug": f"acme/{i}", "name": "N", "description": "d"} for i in range(4)]
    out = asyncio.run(mod._translate_cards(None, cards, []))

    # Каждая карточка доехала поодиночке: плохой ответ модели стоит одного
    # вызова, но не всей пачки.
    assert [c["slug"] for c in out] == ["acme/0", "acme/1", "acme/2", "acme/3"]
    assert calls == [4, 4, 2, 1, 1, 2, 1, 1]


def test_run_finishes_by_its_own_time_budget_and_reports(monkeypatch):
    """Время вышло — прогон заканчивается сам и докладывает итог."""
    mod = _load()

    real_env_int = mod._env_int
    monkeypatch.setattr(mod, "_env_int", lambda name, default, low, high: (
        0 if name == "NJ676_TRANSLATE_BUDGET" else real_env_int(name, default, low, high)
    ))
    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")

    async def fake_get(prefix, path, base=None, **kwargs):
        return True, "", {"ok": True, "count": 1, "pending": 7, "items": [{"slug": "acme/mail"}]}

    posted = []

    async def fake_post(base, path, data):
        posted.append((path, data))
        return True, "", {"saved": 0}

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    result = asyncio.run(mod.handle(None, {"limit": 20, "mode": "batch"}))

    assert result["translated"] == 0
    assert any("время прогона" in error for error in result["errors"])
    assert result["remaining"] == 7
    assert posted[-1][0] == "/translate-finish"
    assert posted[-1][1]["stats"]["remaining"] == 7


def test_translate_all_asks_for_forty_cards(monkeypatch):
    """Режим «Перевести всё» — 40 карточек на задачу (ALL_JOB на сайте).

    Забираем их окнами по две пачки, а не все 40 разом: карточка, взятая в
    клейм и не тронутая, — это чужая работа, запертая до конца прогона.
    """
    mod = _load()

    asked = []

    async def fake_get(prefix, path, base=None, **kwargs):
        asked.append(path)
        limit = int(path.split("limit=")[1].split("&")[0])
        items = [{"slug": f"acme/{len(asked)}-{i}"} for i in range(limit)]

        return True, "", {"ok": True, "count": len(items), "pending": 40, "items": items}

    async def fake_batch(ctx, cards):
        return []

    async def fake_post(base, path, data):
        return True, "", {}

    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")
    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_translate_batch", fake_batch)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    result = asyncio.run(mod.handle(None, {"mode": "all"}))

    assert result["limit"] == 40
    # Первое окно — две пачки по умолчанию (5), не весь лимит задачи.
    assert asked[0].startswith("/translate-candidates?limit=10")
    # Номер задачи едет в запросе: по нему сайт снимает клейм, когда задача
    # закончилась, вместо ожидания часа.
    assert "job=" in asked[0]
    # Контрольный запрос остатка не забирает карточку в работу.
    assert asked[-1].endswith("&claim=0")


def test_cards_taken_but_not_translated_are_returned_to_queue(monkeypatch):
    """Что взяли и не перевели — сразу обратно в очередь, а не через час."""
    mod = _load()

    async def fake_get(prefix, path, base=None, **kwargs):
        limit = int(path.split("limit=")[1].split("&")[0])
        items = [{"slug": f"acme/{i}"} for i in range(limit)]

        return True, "", {"ok": True, "count": len(items), "pending": 6, "items": items}

    async def fake_batch(ctx, cards):
        # Переводим только первую карточку пачки, остальные «не осилили».
        return [{"slug": cards[0]["slug"], "name_ru": "Имя", "description_ru": "Описание"}]

    async def fake_post(base, path, data):
        if path == "/translate-item":
            return True, "", {"saved": len(data.get("items") or [])}

        return True, "", {}

    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")
    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_translate_batch", fake_batch)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    result = asyncio.run(mod.handle(None, {"limit": 10, "mode": "batch"}))

    # Взяли десять карточек двумя пачками, перевели по одной из каждой — все
    # остальные восемь обязаны вернуться в очередь сразу, а не через час.
    assert result["translated"] == 2
    assert set(result.get("untranslated") or []) == {
        "acme/1", "acme/2", "acme/3", "acme/4", "acme/6", "acme/7", "acme/8", "acme/9",
    }


def test_time_budget_returns_cards_that_were_never_started(monkeypatch):
    """Время вышло — неприступленные карточки отпускаем, а не держим в клейме."""
    mod = _load()

    clock = {"t": 0.0}
    monkeypatch.setattr(mod.time, "monotonic", lambda: clock["t"])

    async def fake_get(prefix, path, base=None, **kwargs):
        limit = int(path.split("limit=")[1].split("&")[0])
        items = [{"slug": f"acme/{i}"} for i in range(limit)]

        return True, "", {"ok": True, "count": len(items), "pending": 6, "items": items}

    async def slow_batch(ctx, cards):
        # Первая пачка съедает весь бюджет: остальные карточки даже не начинали.
        clock["t"] = 10_000.0

        return [{"slug": cards[0]["slug"], "name_ru": "Имя", "description_ru": "Описание"}]

    async def fake_post(base, path, data):
        if path == "/translate-item":
            return True, "", {"saved": len(data.get("items") or [])}

        return True, "", {}

    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")
    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_translate_batch", slow_batch)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    result = asyncio.run(mod.handle(None, {"limit": 10, "mode": "batch"}))

    returned = set(result.get("untranslated") or [])

    assert result["translated"] == 1
    # Взяли 10, перевели одну — девять вернулись в очередь.
    assert len(returned) == 9
    assert "acme/9" in returned


def test_crash_reports_and_returns_claims(monkeypatch):
    """Падение прогона не оставляет карточки в клейме до истечения срока."""
    mod = _load()

    async def fake_get(prefix, path, base=None, **kwargs):
        if "claim=0" in path:
            return True, "", {"ok": True, "pending": 6, "items": []}

        return True, "", {"ok": True, "count": 2, "pending": 6, "items": [
            {"slug": "acme/a"}, {"slug": "acme/b"}
        ]}

    async def boom(ctx, cards, errors=None):
        raise RuntimeError("модель недоступна")

    posted = []

    async def fake_post(base, path, data):
        posted.append((path, data))

        return True, "", {}

    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")
    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_translate_cards", boom)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    with pytest.raises(RuntimeError):
        asyncio.run(mod.handle(None, {"limit": 5, "mode": "batch"}))

    finish = [data for path, data in posted if path == "/translate-finish"]

    assert finish, "отчёт о прерванном прогоне обязан уйти на сайт"
    assert set(finish[-1]["stats"]["untranslated"]) == {"acme/a", "acme/b"}
