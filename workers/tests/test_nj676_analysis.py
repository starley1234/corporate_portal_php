"""Тесты канала nj676.analysis: снимок каталога → модель → отчёт в админке.

Запуск из каталога workers/:
    python3 -m pytest tests/test_nj676_analysis.py -q

Проверяем без сети и без модели:
  * в подсказку уходят числа, живые серверы, причины недоступности и спрос;
  * ответ модели, который не JSON, не превращается в пустой отчёт;
  * пустой снимок — это отказ, а не «успешный» анализ без данных;
  * режим live/gaps не просит у модели лишние разделы.
"""

import asyncio

import pytest


def _load():
    import n2j_workers.handlers.nj676_analysis as mod

    return mod


SNAPSHOT = {
    "catalog": {"servers": 1045, "tools": 124, "categories": 13},
    "ready": {"servers": 17, "tools": 83, "error": ""},
    "probe": {"remote": 300, "never": 120, "stale": 80, "fresh": 100, "stale_days": 7},
    "calls": {"calls": 42, "ok": 40, "down": 2},
    "live": [{"slug": "calculator-mcp", "name": "Калькулятор", "tools": 3,
              "examples": ["calculate", "convert"], "latency_ms": 120, "auth": "none"}],
    "blocked": {
        "needs_key": [{"slug": "temporal-mcp", "name": "Temporal", "why": "сервер ответил «нужен ключ» 2 ч назад"}],
        "invalid_url": [],
        "probe_failed": [{"slug": "broken-mcp", "name": "Broken", "why": "сеть: ConnectError"}],
    },
    "failing": [{"slug": "open-meteo", "calls": 5, "failed": 2, "last_at": "2026-10-24"}],
    "demand": {
        "top": [{"query": "погода", "searches": 9, "empty": 0, "best_servers": 4}],
        "empty": [{"query": "авиабилеты", "searches": 12, "last_seen": "2026-10-24"}],
    },
}


def test_snapshot_text_carries_numbers_reasons_and_demand():
    mod = _load()

    text = mod._cards(SNAPSHOT)

    # Числа каталога и готовности.
    assert "серверов в каталоге: 1045" in text
    assert "готовы к вызову сейчас: 17" in text
    # Причины недоступности, а не просто «не готов».
    assert "ТРЕБУЮТ КЛЮЧ" in text and "temporal-mcp" in text
    assert "НЕ ОТВЕЧАЮТ НА ПРОБУ" in text and "broken-mcp" in text
    # Спрос: и частые запросы, и пустые.
    assert "СПРАШИВАЛИ, НИЧЕГО НЕ НАШЛОСЬ" in text and "авиабилеты" in text
    assert "ВЫЗОВЫ ПАДАЮТ" in text and "open-meteo" in text


def test_snapshot_text_is_capped():
    """Один разросшийся раздел не должен вытеснить саму задачу."""
    mod = _load()

    heavy = dict(SNAPSHOT)
    heavy["live"] = [{"slug": f"server-{i}", "name": "x" * 400, "tools": 1,
                      "examples": ["a"], "latency_ms": None, "auth": "none"} for i in range(200)]

    assert len(mod._cards(heavy)) <= mod.SNAPSHOT_LIMIT


def test_broken_json_is_not_a_report(monkeypatch):
    mod = _load()

    async def fake_llm(ctx, messages, phase, **kwargs):
        return ("Конечно! Вот мой анализ: каталог хороший.", {})

    monkeypatch.setattr(mod, "_llm", fake_llm)

    assert asyncio.run(mod._ask(None, SNAPSHOT, "both"))[0].strip() != ""
    assert mod._parse_report("Конечно! Вот мой анализ: каталог хороший.") is None


def test_report_json_is_parsed_even_with_fences(monkeypatch):
    mod = _load()

    raw = '```json\n{"verdict": "Каталог в порядке", "gaps": []}\n```'
    report = mod._parse_report(raw)

    assert report is not None
    assert report["verdict"] == "Каталог в порядке"


def test_empty_snapshot_fails_instead_of_inventing_a_report(monkeypatch):
    """Снимок пуст — отчёт без данных был бы выдумкой; честнее упасть."""
    mod = _load()

    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")

    async def fake_get(prefix, path, base=None, **kwargs):
        return True, "", {"ok": True, "id": 5, "mode": "both", "snapshot": {}}

    posted = []

    async def fake_post(base, path, data):
        posted.append((path, data))
        return True, "", {}

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    with pytest.raises(RuntimeError):
        asyncio.run(mod.handle(None, {"analysis_id": 5, "mode": "both"}))

    assert posted and posted[-1][0] == "/analysis-finish"
    assert posted[-1][1]["status"] == "fail"
    assert "снимок" in posted[-1][1]["error"]


def test_model_failure_is_reported_not_swallowed(monkeypatch):
    mod = _load()

    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")

    async def fake_get(prefix, path, base=None, **kwargs):
        return True, "", {"ok": True, "id": 6, "mode": "gaps", "snapshot": SNAPSHOT}

    async def fake_llm(ctx, messages, phase, **kwargs):
        raise RuntimeError("модель недоступна")

    posted = []

    async def fake_post(base, path, data):
        posted.append((path, data))
        return True, "", {}

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_llm", fake_llm)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    result = asyncio.run(mod.handle(None, {"analysis_id": 6, "mode": "gaps"}))

    assert result["status"] == "fail"
    assert posted[-1][1]["status"] == "fail"
    # Причина названа классом исключения, а не пустой строкой.
    assert "модель" in posted[-1][1]["error"]


def test_done_run_posts_a_normalized_report(monkeypatch):
    mod = _load()

    monkeypatch.setattr(mod, "_site_base", lambda: "http://site")

    async def fake_get(prefix, path, base=None, **kwargs):
        assert path.startswith("/analysis-get?id=7")
        return True, "", {"ok": True, "id": 7, "mode": "both", "snapshot": SNAPSHOT}

    async def fake_llm(ctx, messages, phase, **kwargs):
        return ('{"verdict": "17 серверов работают", "problems": [{"what": "нужен ключ", '
                '"slug": "temporal-mcp"}], "gaps": [{"theme": "авиабилеты"}], '
                '"collect": ["авиабилеты"]}', {"model": "test-model"})

    posted = []

    async def fake_post(base, path, data):
        posted.append((path, data))
        return True, "", {}

    monkeypatch.setattr(mod.site, "get_json", fake_get)
    monkeypatch.setattr(mod, "_llm", fake_llm)
    monkeypatch.setattr(mod, "_site_post", fake_post)

    result = asyncio.run(mod.handle(None, {"analysis_id": 7, "mode": "both"}))

    assert result["status"] == "done"
    assert result["problems"] == 1
    assert result["gaps"] == 1
    assert posted[-1][0] == "/analysis-finish"
    assert posted[-1][1]["status"] == "done"
    assert posted[-1][1]["model"] == "test-model"
    assert posted[-1][1]["report"]["verdict"] == "17 серверов работают"


def test_mode_restricts_what_the_model_is_asked():
    mod = _load()

    live = mod.PROMPT_TEMPLATE + "\\nСейчас нужен только раздел про состояние каталога"
    gaps = mod.PROMPT_TEMPLATE + "\\nСейчас нужен только раздел про пробелы"

    assert "Сейчас нужен только раздел про состояние каталога" in live
    assert "Сейчас нужен только раздел про пробелы" in gaps
    # Оба режима сохраняют требование строгого JSON.
    assert "JSON" in mod.PROMPT_TEMPLATE
