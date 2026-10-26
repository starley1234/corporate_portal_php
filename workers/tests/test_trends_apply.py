"""nj593 «Тренды GitHub»: «Как применить» есть у КАЖДОЙ позиции.

Владелец (26.09, обзор trd23e6de40): «куда дел "Как применить" — верни — это
самое интересное!». Причина пропажи: малая модель не заполнила поле `apply`
в отборе, а страница показывала блок только при непустом значении.

Здесь фиксируется поведение воркера:
  * если в отборе не хватает строк «как применить» — делается ОДИН целевой
    запрос только за ними (дешёвый, с собственным бюджетом времени);
  * если и он не помог — позиция всё равно уходит на сайт с практической
    подсказкой: блок применения не исчезает никогда.
"""
import asyncio

import pytest

from n2j_workers.handlers import nj593_trends as m


def _cand(name, stars, desc="Описание проекта"):
    return {
        "full_name": name,
        "html_url": f"https://github.com/{name}",
        "description": desc,
        "language": "Go",
        "stargazers_count": stars,
        "forks_count": 3,
        "created_at": "2026-09-25T00:00:00Z",
        "topics": ["cli", "tools"],
    }


class _Ctx:
    def __init__(self, chat):
        self.notes = []
        self.llm = __import__("types").SimpleNamespace(chat_with_usage=chat)

    async def progress(self, note):
        self.notes.append(note)


def _payload(**kw):
    data = {"review_id": 7, "code": "trdTEST", "date": "2026-09-26",
            "period": 1, "count": 2, "language": "", "min_stars": 50, "focus": ""}
    data.update(kw)
    return data


def _curate_json(names):
    """Ответ отбора: позиции БЕЗ строки «как применить» (так и случилось на сайте)."""
    items = [{"full_name": n, "score": 90 - i, "title": f"Тема {i + 1}",
              "what": "что это", "why": "почему в обзоре", "use_for": "кому пригодится",
              "apply": ""} for i, n in enumerate(names)]
    import json
    return json.dumps({"title": "Обзор дня", "intro": "Вступление", "items": items},
                      ensure_ascii=False)


async def _setup(monkeypatch, posted):
    async def fake_post(ctx, path, data):
        posted.append((path, dict(data)))
        return True, "", {}

    async def fake_search(since, min_stars, language, per_page=60):
        return [_cand("acme/alpha", 500), _cand("acme/beta", 90)], ""

    async def fake_readmes(names, budget_sec=40.0):
        return {}

    monkeypatch.setattr(m, "_gh_search", fake_search)
    monkeypatch.setattr(m, "_gh_readmes_parallel", fake_readmes)
    monkeypatch.setattr(m, "_post_site", fake_post)


def _usage():
    return {"_llm_model": "local::test", "prompt_tokens": 10, "completion_tokens": 5}


@pytest.mark.asyncio
async def test_missing_apply_is_filled_by_targeted_call(monkeypatch):
    """Отбор не дал `apply` → целевой запрос дописывает строки у всех позиций."""
    posted = []
    await _setup(monkeypatch, posted)

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        system = messages[0]["content"]

        if "КАК им применить" in system:
            return ('{"apply": {"acme/alpha": "Подключите как зависимость и прогоните '
                    'на своём репозитории — увидите экономию сразу.", '
                    '"acme/beta": "Соберите конфиг по примеру из README и проверьте '
                    'на тестовом проекте."}}'), _usage()

        return _curate_json(["acme/alpha", "acme/beta"]), _usage()

    res = await m.handle(_Ctx(chat), _payload())

    items = [d for p, d in posted if p == "/item"]
    assert len(items) == 2 and res["items"] == 2
    for it in items:
        assert it["apply"].strip(), "«Как применить» обязан быть у каждой позиции"
    assert "зависимость" in items[0]["apply"] or "зависимость" in items[1]["apply"]


@pytest.mark.asyncio
async def test_apply_hint_when_targeted_call_fails(monkeypatch):
    """Целевой запрос не удался → позиция всё равно выходит с практической подсказкой."""
    posted = []
    await _setup(monkeypatch, posted)

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        system = messages[0]["content"]

        if "КАК им применить" in system:
            raise asyncio.TimeoutError("модель молчит на доборе")

        return _curate_json(["acme/alpha", "acme/beta"]), _usage()

    res = await m.handle(_Ctx(chat), _payload())

    items = [d for p, d in posted if p == "/item"]
    assert len(items) == 2 and res["items"] == 2
    for it in items:
        assert it["apply"].strip() == m._APPLY_HINT


@pytest.mark.asyncio
async def test_apply_hint_not_used_when_apply_present(monkeypatch):
    """Строка от модели остаётся как есть — подсказка-заглушка не подменяет её."""
    posted = []
    await _setup(monkeypatch, posted)

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        system = messages[0]["content"]

        if "КАК им применить" in system:
            raise AssertionError("добор не нужен: строки уже есть")

        import json
        return json.dumps({"title": "Т", "intro": "И", "items": [
            {"full_name": "acme/alpha", "score": 90, "title": "Тема", "what": "ч",
             "why": "п", "use_for": "к", "apply": "Внедрите в CI и экономьте время."},
            {"full_name": "acme/beta", "score": 80, "title": "Тема 2", "what": "ч",
             "why": "п", "use_for": "к", "apply": "Возьмите как основу для своего плагина."},
        ]}, ensure_ascii=False), _usage()

    await m.handle(_Ctx(chat), _payload())

    items = [d for p, d in posted if p == "/item"]
    assert len(items) == 2
    assert items[0]["apply"] == "Внедрите в CI и экономьте время."
    assert items[1]["apply"] == "Возьмите как основу для своего плагина."


@pytest.mark.asyncio
async def test_apply_hint_when_llm_offline(monkeypatch):
    """ИИ молчала целиком → позиции по фактам GitHub тоже без пустого применения."""
    posted = []
    await _setup(monkeypatch, posted)

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        raise asyncio.TimeoutError("модель не ответила")

    res = await m.handle(_Ctx(chat), _payload())

    assert res["ai_offline"] is True and res["items"] == 2

    items = [d for p, d in posted if p == "/item"]
    assert len(items) == 2
    for it in items:
        assert it["apply"].strip() == m._APPLY_HINT


def test_apply_timeout_budget(monkeypatch):
    """Бюджет добора «как применить» ограничен и настраивается окружением."""
    monkeypatch.delenv("NJ593_LLM_APPLY_TIMEOUT", raising=False)
    assert m._apply_timeout() == 120.0

    monkeypatch.setenv("NJ593_LLM_APPLY_TIMEOUT", "5")      # зажим снизу
    assert m._apply_timeout() == 30.0

    monkeypatch.setenv("NJ593_LLM_APPLY_TIMEOUT", "99999")  # зажим сверху
    assert m._apply_timeout() == 900.0
