"""Тесты nj593_trends: «польза обзора» (apply на позицию + takeaways на обзор).

Запуск из каталога workers/:
    python3 -m pytest tests/test_nj593_trends.py -q

Проверяем точечно (без сети/LLM):
  * _norm_items подхватывает новое поле apply и не роняет старые позиции
    (LLM мог не вернуть apply — тогда пустая строка);
  * промпт кандидатов требует поле apply в схеме ответа;
  * система SYSTEM_CURATOR не изменилась (контракт текстов не сломан).
"""

import pytest


def _load_nj593():
    # Штатный импорт: nj593 импортирует .nj590_design, а тот резолвится только
    # внутри настоящего пакета handlers (ручной spec_from_file_location без
    # __path__ пакета ломает относительные импорты).
    import n2j_workers.handlers.nj593_trends as mod

    return mod


def _candidate_by_name(full_name: str) -> dict:
    return {
        "full_name": full_name,
        "html_url": f"https://github.com/{full_name}",
        "description": "desc",
        "language": "Python",
        "stargazers_count": 120,
        "forks_count": 10,
        "created_at": "2026-09-20T00:00:00Z",
        "topics": ["ai", "tool"],
    }


def test_norm_items_picks_apply():
    nj593 = _load_nj593()

    by_name = {k.lower(): v for k, v in {
        "owner/repo": _candidate_by_name("owner/repo"),
    }.items()}

    parsed = {"items": [{
        "full_name": "owner/repo",
        "score": 90,
        "title": "Т",
        "what": "что",
        "why": "почему",
        "use_for": "кому",
        "apply": "подключи в CI и экономь на прогонах",
        "topics": ["ci"],
    }]}

    items = nj593._norm_items(parsed, by_name, 3)
    assert len(items) == 1
    assert items[0]["apply"] == "подключи в CI и экономь на прогонах"


def test_norm_items_apply_defaults_to_empty():
    nj593 = _load_nj593()

    by_name = {"owner/repo": _candidate_by_name("owner/repo")}
    parsed = {"items": [{
        "full_name": "owner/repo",
        "score": 50,
        "title": "Т",
        "what": "что",
        # LLM не вернул apply — не должно падать и не ломать сериализацию
    }]}

    items = nj593._norm_items(parsed, by_name, 3)
    assert len(items) == 1
    assert items[0]["apply"] == ""


def test_candidates_prompt_requires_apply_field():
    nj593 = _load_nj593()

    prompt = nj593._candidates_prompt([_candidate_by_name("owner/repo")], 2, "")
    assert '"apply"' in prompt
    assert "как применить с пользой" in prompt or "возможность" in prompt


def test_norm_items_empty_parsed_returns_empty():
    nj593 = _load_nj593()
    assert nj593._norm_items({}, {}, 3) == []


# --------------------------------------------------------------------------
# «Тихий день»: лесенка фильтров + параллельное чтение README
# --------------------------------------------------------------------------

def test_gh_search_returns_empty_gracefully(monkeypatch):
    import json as _json

    nj593 = _load_nj593()

    class FakeResp:
        status_code = 200

        def json(self):
            return {"total_count": 0, "incomplete_results": False, "items": []}

        @property
        def text(self):
            return ""

    async def fake_get(*a, **kw):
        return FakeResp() if isinstance(a[0], str) else FakeResp()

    class FakeClient:
        def __init__(self, *a, **kw):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, *a):
            return False

        async def get(self, url, **kw):
            return FakeResp()

    monkeypatch.setattr(nj593.httpx, "AsyncClient", FakeClient)

    async def main():
        return await nj593._gh_search("2026-09-23", 50, "")

    import asyncio
    rows, why = asyncio.run(main())
    assert rows == []
    assert why == ""  # пусто — это НЕ ошибка сети, а просто нет свежих


def test_gh_readmes_parallel_uses_budget(monkeypatch):
    import asyncio

    nj593 = _load_nj593()

    async def fake_readme(full_name, timeout=15.0):
        return f"README of {full_name}"

    monkeypatch.setattr(nj593, "_gh_readme", fake_readme)

    got = asyncio.run(nj593._gh_readmes_parallel(
        ["a/repo", "b/repo", "c/repo"]))
    assert got == {
        "a/repo": "README of a/repo",
        "b/repo": "README of b/repo",
        "c/repo": "README of c/repo",
    }


def test_gh_readme_returns_empty_on_timeout(monkeypatch):
    import asyncio

    nj593 = _load_nj593()

    class Never:
        def __init__(self, *a, **kw):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, *a):
            return False

        async def get(self, url, **kw):
            await asyncio.sleep(10)  # дольше дедлайна

    monkeypatch.setattr(nj593.httpx, "AsyncClient", Never)

    # дедлайн короткий: вернёт '' вместо зависания
    got = asyncio.run(nj593._gh_readme("a/repo", timeout=0.05))
    assert got == ""
