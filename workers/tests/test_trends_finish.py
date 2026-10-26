"""nj593 «Тренды GitHub»: у каждой сборки есть ПОНЯТНЫЙ финал.

Владелец (26.09, обзор trda72ff421): «сбор завис — в работе, ИИ отбирает…
0 из 8… может не нашлось ничего, а может llm не отработала — непонятно.
Нужно это отрабатывать логично, чтобы не висели».

Здесь фиксируется поведение, при котором «висеть» больше нечему:

  * у LLM-вызова есть жёсткий предел ожидания (клиент может проигнорировать
    свой timeout — обзор всё равно не остаётся без финала);
  * «ничего не нашлось по фокусу» — это ВАЛИДНОЕ «Готово» с объяснением, а не
    ошибка и не вечное ожидание;
  * если ИИ не ответила — обзор всё равно собирается по фактам GitHub и честно
    помечается «ИИ-разбор не удался: <причина>»;
  * если GitHub не дал кандидатов вообще — задача падает с понятной причиной
    (failed с текстом, а не «в работе» навсегда).
"""
import asyncio
from types import SimpleNamespace

import pytest

from n2j_workers.handlers import nj593_trends as m


# ---------------------------------------------------------------------------
# Вспомогательное
# ---------------------------------------------------------------------------

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
    """Минимальный ctx воркера: прогресс + LLM."""

    def __init__(self, chat):
        self.notes = []
        self.llm = SimpleNamespace(chat_with_usage=chat)

    async def progress(self, note):
        self.notes.append(note)


def _ctx(chat):
    return _Ctx(chat)


def _payload(**kw):
    data = {"review_id": 7, "code": "trdTEST", "date": "2026-09-26",
            "period": 1, "count": 3, "language": "", "min_stars": 50, "focus": ""}
    data.update(kw)
    return data


# ---------------------------------------------------------------------------
# 1. Жёсткие пределы и причины
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_llm_watch_enforces_hard_limit_and_cancels_call():
    """Клиент проигнорировал свой timeout — вызов всё равно прерывается."""
    cancelled = []

    async def forever():
        try:
            await asyncio.sleep(300)
        except asyncio.CancelledError:
            cancelled.append(True)
            raise

    ticks = []

    async def on_tick(note):
        ticks.append(note)

    with pytest.raises(asyncio.TimeoutError):
        await m._llm_watch("curate", forever, on_tick=on_tick, every=0.02, limit=0.1)

    assert cancelled, "зависший LLM-вызов обязан быть снят, а не ждать вечно"
    assert ticks and "лимит" in ticks[0], "в этапе виден лимит ожидания"


def test_llm_err_text_short_and_readable():
    assert m._llm_err_text(asyncio.TimeoutError("x"), 300) == "модель не ответила за 300 с"
    assert m._llm_err_text(RuntimeError("401 Unauthorized"), 300).startswith("RuntimeError: 401")


def test_llm_budgets_are_short(monkeypatch):
    """Бюджеты меньше прежних 480+480 с — статус-ряд не «висит» четверть часа."""
    for var in ("NJ593_LLM_CURATE_TIMEOUT", "NJ593_LLM_CURATE_RETRY_TIMEOUT",
                "NJ593_LLM_TAKEAWAYS_TIMEOUT"):
        monkeypatch.delenv(var, raising=False)

    assert m._curate_timeout() <= 300
    assert m._curate_retry_timeout() <= 150
    assert m._takeaways_timeout() <= 150

    monkeypatch.setenv("NJ593_LLM_CURATE_TIMEOUT", "45")
    assert m._curate_timeout() == 45.0

    monkeypatch.setenv("NJ593_LLM_CURATE_TIMEOUT", "99999")   # зажим сверху
    assert m._curate_timeout() == 900.0


# ---------------------------------------------------------------------------
# 2. Деградация без ИИ: обзор всё равно собирается
# ---------------------------------------------------------------------------

def test_fallback_items_keep_facts_only():
    items = m._fallback_items([
        _cand("acme/beta", 90, "Второй"),
        _cand("acme/alpha", 500, "Первый"),
        _cand("acme/alpha", 500, "Дубль"),
        {"full_name": ""},
    ], 3)

    assert [i["full_name"] for i in items] == ["acme/alpha", "acme/beta"], "порядок по звёздам, без дублей"
    assert items[0]["stars"] == 500 and items[0]["language"] == "Go"
    assert items[0]["what"] == "Первый"
    assert items[0]["score"] == 0


@pytest.mark.asyncio
async def test_pipeline_degrades_when_llm_silent(monkeypatch):
    """ИИ молчит — обзор собирается по фактам GitHub и помечается в сводке."""
    posted = []

    async def fake_post(ctx, path, data):
        posted.append((path, dict(data)))
        return True, "", {}

    async def fake_search(since, min_stars, language, per_page=60):
        return [_cand("acme/alpha", 500), _cand("acme/beta", 90), _cand("acme/gamma", 50)], ""

    async def fake_readmes(names, budget_sec=40.0):
        return {}

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        raise asyncio.TimeoutError("модель молчит")

    monkeypatch.setattr(m, "_gh_search", fake_search)
    monkeypatch.setattr(m, "_gh_readmes_parallel", fake_readmes)
    monkeypatch.setattr(m, "_post_site", fake_post)

    res = await m.handle(_ctx(chat), _payload())

    paths = [p for p, _ in posted]
    assert res["ai_offline"] is True
    assert res["items"] == 3, "обзор обязан собраться даже без ИИ"
    assert paths.count("/item") == 3
    assert "/fail" not in paths and "/complete" in paths

    complete = [d for p, d in posted if p == "/complete"][0]
    assert "ИИ-разбор не удался" in complete["summary"]
    assert "модель не ответила" in complete["summary"]
    assert complete["model_used"] == "", "модели нельзя приписывать чужие тексты"


# ---------------------------------------------------------------------------
# 3. «Ничего не нашлось» — валидное «Готово»
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pipeline_empty_focus_finishes_as_done(monkeypatch):
    """Фокус без релевантных репозиториев → «Готово» с объяснением, без позиций."""
    posted = []

    async def fake_post(ctx, path, data):
        posted.append((path, dict(data)))
        return True, "", {}

    async def fake_search(since, min_stars, language, per_page=60):
        return [_cand("acme/alpha", 500), _cand("acme/beta", 90), _cand("acme/gamma", 50)], ""

    async def fake_readmes(names, budget_sec=40.0):
        return {}

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        # и первый, и повторный заход отвечают «подходящего нет»
        return '{"items": []}', {"_llm_model": "local::test", "prompt_tokens": 10,
                                 "completion_tokens": 2}

    monkeypatch.setattr(m, "_gh_search", fake_search)
    monkeypatch.setattr(m, "_gh_readmes_parallel", fake_readmes)
    monkeypatch.setattr(m, "_post_site", fake_post)

    res = await m.handle(_ctx(chat), _payload(focus="конструкторские отчёты"))

    paths = [p for p, _ in posted]
    assert res.get("empty_ok") is True
    assert res["items"] == 0
    assert "/item" not in paths
    assert "/complete" in paths

    complete = [d for p, d in posted if p == "/complete"][0]
    assert "ничего" in complete["summary"].lower()
    assert "конструкторские отчёты" in complete["summary"]


# ---------------------------------------------------------------------------
# 4. GitHub пуст — ошибка с причиной (не «в работе»)
# ---------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_pipeline_fails_with_reason_when_github_empty(monkeypatch):
    async def fake_search(since, min_stars, language, per_page=60):
        return [], "rate limit exceeded"

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        raise AssertionError("LLM не должна зваться без кандидатов")

    monkeypatch.setattr(m, "_gh_search", fake_search)

    with pytest.raises(RuntimeError) as e:
        await m.handle(_ctx(chat), _payload())

    assert "GitHub" in str(e.value)


@pytest.mark.asyncio
async def test_pipeline_focus_with_silent_llm_is_not_mistaken_for_empty(monkeypatch):
    """Фокус + молчащая ИИ — это НЕ «ничего не нашлось»: причина в сводке другая."""
    posted = []

    async def fake_post(ctx, path, data):
        posted.append((path, dict(data)))
        return True, "", {}

    async def fake_search(since, min_stars, language, per_page=60):
        return [_cand("acme/alpha", 500), _cand("acme/beta", 90), _cand("acme/gamma", 50)], ""

    async def fake_readmes(names, budget_sec=40.0):
        return {}

    async def chat(messages, model=None, max_tokens=None, request_timeout=None):
        raise RuntimeError("401 Unauthorized")

    monkeypatch.setattr(m, "_gh_search", fake_search)
    monkeypatch.setattr(m, "_gh_readmes_parallel", fake_readmes)
    monkeypatch.setattr(m, "_post_site", fake_post)

    res = await m.handle(_ctx(chat), _payload(focus="авиационные реле"))

    complete = [d for p, d in posted if p == "/complete"][0]
    assert "ИИ-разбор не удался" in complete["summary"]
    assert "401" in complete["summary"]
    assert "ничего релевантного не нашлось" not in complete["summary"]
    assert res["items"] == 3, "позиции по фактам GitHub всё равно показываем"
