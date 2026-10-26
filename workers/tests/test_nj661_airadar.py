"""Тесты nj661_airadar: чистые функции конвейера ИИ-радара (без сети/LLM).

Запуск из каталога workers/:
    python3 -m pytest tests/test_nj661_airadar.py -q

Проверяем точечно:
  * _parse_feed: RSS 2.0 и Atom (link href), битый XML не должен ронять прогон;
  * _dedupe: трекинг-параметры и регистр хоста дают один материал;
  * _knowledge_block: контекст базы знаний попадает в промпт анализа;
  * _item_block: текст страницы и мета попадают в блок материала;
  * рассуждающие модели (reasoning-токены съедают бюджет ответа): радар
    расширяет бюджет, мельчит пачки, глушит размышления и называет причину.
"""

from __future__ import annotations

import xml.etree.ElementTree as ET
import asyncio
import json
import re
from types import SimpleNamespace

import httpx
import pytest


def _load():
    import n2j_workers.handlers.nj661_airadar as mod

    return mod


RSS = """<rss version="2.0"><channel><title>AI News</title>
<item><title>Qwen released open weights</title>
<link>https://example.com/qwen?utm_source=rss</link>
<description>&lt;b&gt;235B&lt;/b&gt; parameters, apache-2.0</description>
<pubDate>Mon, 05 Oct 2026 10:00:00 +0000</pubDate></item>
<item><title>No link item</title><description>skipped</description></item>
</channel></rss>"""

ATOM = """<feed xmlns="http://www.w3.org/2005/Atom">
<entry><title>Atom entry</title>
<link href="https://example.com/atom" rel="alternate"/>
<summary>summary text</summary>
<updated>2026-10-05T10:00:00Z</updated></entry></feed>"""


def test_parse_feed_rss():
    nj661 = _load()
    rows = nj661._parse_feed(RSS, limit=6)

    assert len(rows) == 1                      # item без ссылки пропущен
    assert rows[0]["url"] == "https://example.com/qwen?utm_source=rss"
    assert rows[0]["title"] == "Qwen released open weights"
    assert "235B" in rows[0]["snippet"]
    assert "<" not in rows[0]["snippet"]       # теги вырезаны
    assert rows[0]["published_at"] is not None


def test_parse_feed_atom():
    nj661 = _load()
    rows = nj661._parse_feed(ATOM, limit=6)

    assert len(rows) == 1
    assert rows[0]["url"] == "https://example.com/atom"
    assert rows[0]["title"] == "Atom entry"


def test_parse_feed_limit():
    nj661 = _load()
    entries = "".join(
        f"<item><title>t{i}</title><link>https://e.com/{i}</link></item>"
        for i in range(10)
    )
    rows = nj661._parse_feed(f"<rss><channel>{entries}</channel></rss>", limit=3)

    assert len(rows) == 3


def test_parse_feed_broken_xml_raises():
    nj661 = _load()

    with pytest.raises(ET.ParseError):
        nj661._parse_feed("<<< not xml", limit=3)


def test_dedupe_urls():
    nj661 = _load()
    items = [
        {"source": "ddg", "url": "https://Example.com/page/?utm_source=x", "title": "a"},
        {"source": "rss", "url": "https://example.com/page", "title": "a-dup"},
        {"source": "ddg", "url": "https://other.com/y", "title": "b"},
        {"source": "ddg", "url": "", "title": "no url"},
    ]
    out = nj661._dedupe(items)

    assert len(out) == 2
    assert out[0]["title"] == "a"


def test_knowledge_block():
    nj661 = _load()
    knowledge = {
        "total_facts": 12,
        "total_ideas": 3,
        "recent_facts": [{"text": "Qwen3 имеет 235B параметров"}],
        "known_tags": ["qwen", "llm"],
    }
    block = nj661._knowledge_block(knowledge)

    assert "12 фактов" in block
    assert "Qwen3" in block
    assert "qwen" in block

    assert nj661._knowledge_block({}) .startswith("База знаний пуста")


def test_item_block_page_text():
    nj661 = _load()
    item = {"id": 7, "title": "T", "source": "ddg", "url": "https://x",
            "snippet": "S", "meta": {}}
    block = nj661._item_block(item, "длинный текст страницы")

    assert "МАТЕРИАЛ #7" in block
    assert "длинный текст страницы" in block

    arena = {"id": 9, "title": "A", "source": "arena", "url": "https://y",
             "snippet": "S", "meta": {"text": "таблица лидерборда"}}
    assert "таблица лидерборда" in nj661._item_block(arena, "")


@pytest.mark.asyncio
async def test_api_post_uses_common_base(monkeypatch):
    """Адрес приёмки — из общих переменных: NJ120_API_BASE + /airadar/api.

    Свой NJ661_AIRADAR_API_BASE нужен только если канал живёт на другом
    сайте (замечание владельца 06.10.2026: не раздувать .env).
    """
    mod = _load()

    for name in ("NJ661_AIRADAR_API_BASE", "NJ120_API_BASE", "NJ120_API_URL"):
        monkeypatch.delenv(name, raising=False)

    monkeypatch.setenv("NJ120_API_BASE", "https://n2j.ru")

    seen = {}

    async def _fake_post(prefix, path, data, timeout=60.0, retries=2,
                         json_body=False, base=None):
        seen["prefix"], seen["path"], seen["base"] = prefix, path, base
        return True, "", {"ok": True}

    monkeypatch.setattr(mod.site, "post_json", _fake_post)

    out = await mod._api_post(None, "/items", {"items": []})

    assert seen == {"prefix": "NJ661_AIRADAR", "path": "/items",
                    "base": "https://n2j.ru/airadar/api"}
    assert out == {"ok": True}


def test_mix_sources_round_robin():
    """Богатый источник не вытесняет остальных из среза (живой случай:
    «в материалах из 120 только hf» — HF отдал ~200 и стоял первым)."""
    mod = _load()

    hf = [{"source": "huggingface", "url": f"https://hf.co/m{i}"} for i in range(200)]
    ort = [{"source": "openrouter", "url": f"https://or.ai/m{i}"} for i in range(30)]
    rss = [{"source": "rss", "url": f"https://rss.io/{i}"} for i in range(20)]
    ddg = [{"source": "ddg", "url": f"https://ddg.io/{i}"} for i in range(10)]
    arena = [{"source": "arena", "url": "https://lmarena.ai/leaderboard"}]

    mixed = mod._mix_sources([hf, ort, rss, ddg, arena])[:120]
    kinds = {it["source"] for it in mixed}

    assert kinds == {"huggingface", "openrouter", "rss", "ddg", "arena"}
    # срез делится честно: маленькие источники входят целиком
    assert sum(1 for it in mixed if it["source"] == "openrouter") == 30
    assert sum(1 for it in mixed if it["source"] == "arena") == 1
    # и сразу в начале среза есть не только hf
    assert mixed[0]["source"] == "huggingface" and mixed[1]["source"] == "openrouter"


def test_context_knobs_fit_16k(monkeypatch):
    """NJ661_AIRADAR_CONTEXT=16000 (локальная Gemma): батч до 3 (бюджет ответа), обрезка 2000.

    Владелец: «на моей карте контекст больше 16К выделить не могу».
    """
    mod = _load()

    for name in ("NJ661_AIRADAR_CONTEXT", "NJ661_AIRADAR_BATCH", "NJ661_AIRADAR_PAGE_CAP"):
        monkeypatch.delenv(name, raising=False)

    monkeypatch.setenv("NJ661_AIRADAR_CONTEXT", "16000")
    assert mod._batch_size({}) == 3
    assert mod._page_cap({}) == 2000
    assert mod._out_tokens({}) == 3000

    # безопасный дефолт — 16000, а не прежние 32000
    monkeypatch.setenv("NJ661_AIRADAR_CONTEXT", "")
    assert mod._batch_size({}) == 3
    assert mod._page_cap({}) == 2000

    # явный BATCH перекрывает вычисленное из контекста
    monkeypatch.setenv("NJ661_AIRADAR_CONTEXT", "16000")
    monkeypatch.setenv("NJ661_AIRADAR_BATCH", "2")
    assert mod._batch_size({}) == 2


def result(ident, relevant=True):
    return {"id": ident, "relevant": relevant, "category": "release", "score": 80,
            "why": "Открытые веса", "facts": [{"text": "Модель содержит 12 миллиардов параметров"}], "ideas": []}


@pytest.mark.parametrize("context", [4000, 8000, 16000, 32000])
def test_total_prompt_budget_includes_growing_knowledge(monkeypatch, context):
    mod = _load()
    monkeypatch.setenv("NJ661_AIRADAR_CONTEXT", str(context))
    monkeypatch.setenv("NJ661_AIRADAR_BATCH", "100")
    monkeypatch.setenv("NJ661_AIRADAR_PAGE_CAP", "100000")
    batch = [{"id": i, "title": "д" * 10000, "snippet": "с" * 10000,
              "meta": {"text": "т" * 10000}} for i in range(mod._batch_size({}))]
    knowledge = {"recent_facts": [{"text": "ф" * 500} for _ in range(40)]}
    messages = mod._analysis_messages(batch, {}, knowledge, {})
    assert sum(len(m["content"]) for m in messages) + mod._out_tokens({}) + 512 <= context
    for row in batch:
        assert f"МАТЕРИАЛ #{row['id']}" in messages[1]["content"]
    assert mod._batch_size({}) <= 3


def test_partial_and_foreign_ids_are_not_silently_counted():
    mod = _load()
    rows = [result(1), result("invalid"), result(999), {"id": 2, "relevant": True}]
    assert mod._analysis_results(json.dumps({"items": rows}), [{"id": 1}, {"id": 2}]) == [result(1)]


def test_llm_field_variants_are_normalized_not_dropped():
    """Модель прислала relevant строкой, score строкой, без ideas — чиним."""
    mod = _load()
    raw = json.dumps({"items": [{"id": 1, "relevant": "true", "score": "85/100",
                                 "why": "Открытые веса", "facts": [{"text": "12B параметров"}]}]})
    rows = mod._analysis_results(raw, [{"id": 1}])

    assert len(rows) == 1
    row = rows[0]
    assert row["relevant"] is True and row["score"] == 85 and row["ideas"] == []
    assert row["category"] in mod._CATEGORIES
    assert row["facts"] == [{"text": "12B параметров"}]


def test_truncated_batch_keeps_complete_objects():
    """Обрыв по токенам: целые объекты батча сохраняются, битый хвост — нет."""
    mod = _load()
    raw = ('{"items": [' + json.dumps(result(1), ensure_ascii=False) + ', '
           + json.dumps(result(2), ensure_ascii=False) + ', {"id": 3, "relevant": tr')
    rows = mod._analysis_results(raw, [{"id": 1}, {"id": 2}, {"id": 3}])

    assert {row["id"] for row in rows} == {1, 2}


def test_toplevel_array_answer_is_accepted():
    """Модель ответила массивом без обёртки items — это не повод терять пачку."""
    mod = _load()
    raw = json.dumps([result(1), result(2)])
    assert {row["id"] for row in mod._analysis_results(raw, [{"id": 1}, {"id": 2}])} == {1, 2}


def test_non_json_answer_is_a_clear_error():
    mod = _load()

    with pytest.raises(ValueError, match="не вернула items"):
        mod._analysis_results("Извините, не могу помочь с этим запросом.", [{"id": 1}])


@pytest.fixture
def pipeline(monkeypatch):
    mod = _load()
    for key in ("CONTEXT", "BATCH", "PAGE_CAP"):
        monkeypatch.delenv(f"NJ661_AIRADAR_{key}", raising=False)
    state = SimpleNamespace(calls=[], saved=[], reports=[], finishes=[], phases=[], candidates=None)
    candidates = [{"url": f"https://example.com/{i}", "title": f"Материал {i}",
                   "source": "test", "meta": {"text": "Известный текст"}} for i in range(1, 5)]

    async def source(*args):
        return candidates

    async def empty(*args):
        return []

    monkeypatch.setattr(mod, "_source_huggingface", source)
    for name in ("_source_openrouter", "_source_rss", "_source_ddgs", "_source_arena"):
        monkeypatch.setattr(mod, name, empty)

    async def progress(stage, note):
        state.phases.append((stage, note))

    ctx = SimpleNamespace(progress=progress, embed=None)

    async def api(ctx, path, data):
        state.calls.append((path, data))
        if path == "/items":
            state.candidates = json.loads(data["items"])
            return {"ok": True, "accepted": [dict(row, id=i) for i, row in enumerate(state.candidates, 1)]}
        if path == "/analysis":
            state.saved.extend(json.loads(data["results"]))
            return {"ok": True, "facts_new": 1}
        if path == "/report_input":
            return {"ok": True, "items": [dict(row, title="Материал") for row in state.saved],
                    "facts": [], "ideas": [], "knowledge": {}}
        if path == "/report":
            state.reports.append(data)
            return {"ok": True, "report_id": 42}
        if path == "/finish":
            state.finishes.append(data)
        return {"ok": True}

    async def llm(ctx, messages, phase, **kwargs):
        if phase == "report":
            return json.dumps({"title": "Сводка", "summary": "Есть полезные модели", "highlights": []})
        ids = re.findall(r"МАТЕРИАЛ #(\d+)", messages[1]["content"])
        return json.dumps({"items": [result(int(i)) for i in ids]})

    monkeypatch.setattr(mod, "_api_post", api)
    monkeypatch.setattr(mod, "_llm", llm)
    state.mod, state.ctx, state.llm, state.api, state.rows = mod, ctx, llm, api, candidates
    return state


@pytest.mark.asyncio
async def test_pipeline_saves_progress_stats_and_report(pipeline):
    p = pipeline
    out = await p.mod.handle(p.ctx, {"run_id": 7})
    assert out["ok"] and out["stats"]["analyzed"] == 4
    assert p.reports[0]["stats"]["candidates"] == 4
    assert p.finishes[-1]["status"] == "done"
    assert {stage for stage, _ in p.phases} >= {"Источники", "Анализ", "Отчёт"}
    assert any(path == "/progress" for path, _ in p.calls)


@pytest.mark.asyncio
async def test_truncated_batch_retries_only_missing_articles(pipeline, monkeypatch):
    p = pipeline
    n = 0

    async def llm(*args, **kwargs):
        nonlocal n
        n += 1
        if n == 1:
            return json.dumps({"items": [result(1), {"id": 2, "relevant": True}]})
        return await p.llm(*args, **kwargs)

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})
    assert out["ok"] and out["stats"]["pending"] == 0
    assert sorted(row["id"] for row in p.saved) == [1, 2, 3, 4]


@pytest.mark.asyncio
async def test_all_llm_errors_fail_without_empty_report(pipeline, monkeypatch):
    p = pipeline

    async def broken(*args, **kwargs):
        raise RuntimeError("context length exceeded")

    monkeypatch.setattr(p.mod, "_llm", broken)
    with pytest.raises(RuntimeError, match="ни одного"):
        await p.mod.handle(p.ctx, {"run_id": 7})
    assert not p.reports
    assert p.finishes[-1]["status"] == "fail"
    assert "context length exceeded" in p.finishes[-1]["error"]


@pytest.mark.asyncio
async def test_partial_failure_is_explicit_and_keeps_results(pipeline, monkeypatch):
    p = pipeline

    async def llm(ctx, messages, phase, **kwargs):
        if phase == "analysis" and "МАТЕРИАЛ #4" in messages[1]["content"]:
            return "не JSON"
        return await p.llm(ctx, messages, phase, **kwargs)

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})
    # Неполный прогон не «остановлен»: отчёт есть, результаты сохранены, а
    # потеря одного материала из четырёх видна и в отчёте, и в error прогона.
    assert out["ok"] and out["stats"]["pending"] == 1
    assert "Неполный прогон" in p.reports[0]["report"]["summary"]
    assert p.finishes[-1]["status"] == "done"
    assert "Не обработано материалов: 1 из 4" in p.finishes[-1]["error"]
    assert len(p.saved) == 3


@pytest.mark.asyncio
async def test_big_partial_loss_still_fails_the_run(pipeline, monkeypatch):
    """Провал остаётся провалом, когда потеряна заметная доля материалов."""
    p = pipeline
    for i in range(5, 13):
        p.rows.append(dict(p.rows[0], url=f"https://example.com/{i}"))

    async def llm(ctx, messages, phase, **kwargs):
        if phase == "analysis" and not any(f"МАТЕРИАЛ #{i} " in messages[1]["content"] for i in (1, 2)):
            return "не JSON"
        return await p.llm(ctx, messages, phase, **kwargs)

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})
    assert not out["ok"]
    assert out["stats"]["pending"] > max(3, 12 // 10)
    assert p.finishes[-1]["status"] == "fail"
    assert p.reports, "отчёт по разобранной части должен сохраниться"


@pytest.mark.asyncio
async def test_report_failure_has_useful_fallback_not_blank_summary(pipeline, monkeypatch):
    p = pipeline

    async def llm(ctx, messages, phase, **kwargs):
        return "{}" if phase == "report" else await p.llm(ctx, messages, phase, **kwargs)

    monkeypatch.setattr(p.mod, "_llm", llm)
    await p.mod.handle(p.ctx, {"run_id": 7})
    assert "Редактор LLM не подготовил" in p.reports[0]["report"]["summary"]
    assert p.reports[0]["stats"]["report_fallback"]


@pytest.mark.asyncio
async def test_cancelled_run_notifies_site(pipeline, monkeypatch):
    p = pipeline

    async def cancelled(*args, **kwargs):
        raise asyncio.CancelledError()

    monkeypatch.setattr(p.mod, "_llm", cancelled)
    with pytest.raises(asyncio.CancelledError):
        await p.mod.handle(p.ctx, {"run_id": 7})
    assert p.finishes[-1]["status"] == "fail" and not p.reports


@pytest.mark.asyncio
async def test_source_failure_not_reported_as_nothing_new(pipeline):
    p = pipeline
    p.rows.clear()
    with pytest.raises(RuntimeError, match="0 кандидатов"):
        await p.mod.handle(p.ctx, {"run_id": 7})
    assert not p.reports and p.finishes[-1]["status"] == "fail"


@pytest.mark.asyncio
async def test_candidates_not_truncated_before_server_dedup(pipeline):
    p = pipeline
    for i in range(5, 130):
        p.rows.append(dict(p.rows[0], url=f"https://example.com/{i}"))
    await p.mod.handle(p.ctx, {"run_id": 7, "limits": {"max_items": 120}})
    assert len(p.candidates) == 129


@pytest.mark.asyncio
async def test_api_config_error_is_not_silent_success(monkeypatch):
    mod = _load()

    async def broken(*args, **kwargs):
        return False, "адрес сайта не задан", {}

    monkeypatch.setattr(mod.site, "post_json", broken)
    with pytest.raises(RuntimeError, match="не задан"):
        await mod._api_post(None, "/items", {})


@pytest.mark.asyncio
async def test_failed_delivery_closes_run_instead_of_success(pipeline, monkeypatch):
    p = pipeline

    async def api(ctx, path, data):
        if path == "/analysis":
            raise RuntimeError("HTTP 503 при сохранении")
        return await p.api(ctx, path, data)

    monkeypatch.setattr(p.mod, "_api_post", api)
    with pytest.raises(RuntimeError, match="503"):
        await p.mod.handle(p.ctx, {"run_id": 7})
    assert not p.reports
    assert p.finishes[-1]["status"] == "fail"


@pytest.mark.asyncio
async def test_embedding_is_optional_worker_side_work(pipeline, monkeypatch):
    p = pipeline
    calls = []

    async def embed(texts):
        calls.append(texts)
        return [[0.5] * 8 for _ in texts]

    p.ctx.embed = SimpleNamespace(embed=embed)
    await p.mod.handle(p.ctx, {"run_id": 7})
    assert calls
    assert p.saved[0]["facts"][0]["embedding"] == [0.5] * 8


@pytest.mark.asyncio
async def test_embedding_timeout_does_not_lose_facts(pipeline):
    p = pipeline

    async def embed(texts):
        raise TimeoutError("embedding timeout")

    p.ctx.embed = SimpleNamespace(embed=embed)
    out = await p.mod.handle(p.ctx, {"run_id": 7})
    assert out["ok"] and out["stats"]["embedding_errors"] > 0
    assert p.saved and "embedding" not in p.saved[0]["facts"][0]


@pytest.mark.asyncio
async def test_time_budget_keeps_time_for_partial_report(pipeline, monkeypatch):
    p = pipeline
    now = [0]
    # Не меняем глобальный time.monotonic, используемый самим asyncio.
    monkeypatch.setattr(p.mod, "time", SimpleNamespace(monotonic=lambda: now[0], time=lambda: 0, strftime=lambda _: "15.10.2026"))

    async def llm(*args, **kwargs):
        out = await p.llm(*args, **kwargs)
        now[0] = 2401
        return out

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})
    # Успели 3 из 4 — конвейер доводит прогон до отчёта, а не бросает его:
    # причина (дедлайн анализа) остаётся в stats и в error прогона.
    assert out["ok"]
    assert out["stats"]["analyzed"] == 3 and out["stats"]["pending"] == 1
    assert out["stats"]["aborted"] == "deadline"
    assert p.reports and p.finishes[-1]["status"] == "done"


@pytest.mark.asyncio
async def test_no_new_items_does_not_call_llm(pipeline, monkeypatch):
    p = pipeline

    async def api(ctx, path, data):
        if path == "/items":
            return {"ok": True, "accepted": [], "skipped": 4}
        return await p.api(ctx, path, data)

    async def llm(*args, **kwargs):
        raise AssertionError("Нет новых материалов — LLM не нужна")

    monkeypatch.setattr(p.mod, "_api_post", api)
    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})
    assert out["ok"] and p.reports[0]["report"]["title"] == "ИИ-радар: ничего нового"


# --- рассуждающие модели: обрыв JSON по reasoning-токенам (15.10.2026) ------
#
# Живой прогон #10 на локальной Qwen3.5-9B: usage показал 2953 reasoning-токена
# из 3000, content оборвался на «"score": 92,», и прогон закрылся «LLM не
# проанализировала ни одного материала». Проверяем, что радар это видит,
# расширяет бюджет ответа, мельчит пачки и глушит размышления.

TRUNCATED = '{"items": [{"id": 667, "relevant": true, "category": "release", "score": 92,'

LENGTH_USAGE = {"_llm_finish_reason": "length", "completion_tokens": 3000,
                "completion_tokens_details": {"reasoning_tokens": 2953}}
STOP_USAGE = {"_llm_finish_reason": "stop", "completion_tokens": 900,
              "completion_tokens_details": {"reasoning_tokens": 1200}}


def test_out_tokens_and_batch_wide_mode(monkeypatch):
    """Рассуждающей модели — запас ответа, но не больше половины контекста."""
    mod = _load()
    monkeypatch.delenv("NJ661_AIRADAR_OUT_TOKENS", raising=False)
    limits = {}

    assert mod._out_tokens(limits) == 3000            # ctx 16000 → база
    assert mod._out_tokens(limits, True) == 8000      # wide: reasoning-запас
    assert mod._batch_size(limits) == 3
    assert mod._batch_size(limits, True) == 2         # 8000 // 3000

    monkeypatch.setenv("NJ661_AIRADAR_OUT_TOKENS", "50000")
    assert mod._out_tokens(limits, True) == 8000      # потолок: половина контекста
    monkeypatch.setenv("NJ661_AIRADAR_CONTEXT", "16000")
    monkeypatch.setenv("NJ661_AIRADAR_OUT_TOKENS", "100")
    assert mod._out_tokens(limits, True) >= 3000      # ниже базы не опускаемся


def test_text_usage_normalizes_stub_and_real():
    """Стенды возвращают строку, боевой llmchat — (текст, usage)."""
    mod = _load()

    assert mod._text_usage("{}") == ("{}", {})
    assert mod._text_usage(None) == ("", {})
    assert mod._text_usage(("{}", LENGTH_USAGE)) == ("{}", LENGTH_USAGE)
    assert mod._text_usage(("{}", "мусор")) == ("{}", {})

    assert mod._reasoning_tokens(LENGTH_USAGE) == 2953
    assert mod._reasoning_tokens({}) == 0
    assert mod._reasoning_tokens({"completion_tokens_details": "мусор"}) == 0
    assert mod._is_length(LENGTH_USAGE) and not mod._is_length(STOP_USAGE)


def test_think_switch_and_hint(monkeypatch):
    """NJ661_AIRADAR_THINK: auto/0/1; подсказка называет ручки настройки."""
    mod = _load()
    monkeypatch.delenv("NJ661_AIRADAR_THINK", raising=False)
    assert mod._think_off_requested() is None

    monkeypatch.setenv("NJ661_AIRADAR_THINK", "0")
    assert mod._think_off_requested() is False
    monkeypatch.setenv("NJ661_AIRADAR_THINK", "on")
    assert mod._think_off_requested() is True

    hint = mod._truncation_hint(2953, 3000)
    assert "2953" in hint and "NJ661_AIRADAR_THINK=0" in hint and "NJ661_AIRADAR_OUT_TOKENS" in hint
    assert "NJ661_AIRADAR_OUT_TOKENS" in mod._truncation_hint(0, 3000)


@pytest.mark.asyncio
async def test_reasoning_model_escalates_budget_and_finishes(pipeline, monkeypatch):
    """Первый вызов обрывается на размышлениях — следующий уже с запасом."""
    p = pipeline
    calls = []

    async def llm(ctx, messages, phase, **kwargs):
        calls.append((phase, kwargs.get("max_tokens"), kwargs.get("options"),
                      re.findall(r"МАТЕРИАЛ #(\d+)", messages[1]["content"])))
        if phase == "report":
            return json.dumps({"title": "Сводка", "summary": "Есть модели", "highlights": []})
        if len(calls) == 1:
            return TRUNCATED, LENGTH_USAGE
        ids = re.findall(r"МАТЕРИАЛ #(\d+)", messages[1]["content"])
        return json.dumps({"items": [result(int(i)) for i in ids]}), STOP_USAGE

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})

    assert out["ok"] and out["stats"]["analyzed"] == 4
    assert out["stats"]["output_truncated"] == 1
    assert out["stats"]["reasoning_tokens"] == 2953
    assert sorted(row["id"] for row in p.saved) == [1, 2, 3, 4]
    assert any("рассуждения модели" in note for _, note in p.phases)

    analysis = [c for c in calls if c[0] == "analysis"]
    assert analysis[0][1] == 3000 and analysis[0][2] is None      # первый — как раньше
    assert analysis[1][1] == 8000                                 # бюджет вырос
    assert analysis[1][2]["chat_template_kwargs"] == {"enable_thinking": False}
    assert analysis[1][2]["reasoning_effort"] == "none"
    assert [len(ids) for _, _, _, ids in analysis[1:]] == [1, 1, 1, 1]


@pytest.mark.asyncio
async def test_think_off_env_starts_with_suppression_and_wide_budget(pipeline, monkeypatch):
    """NJ661_AIRADAR_THINK=0 — не ждём обрыва: сразу без размышлений."""
    p = pipeline
    monkeypatch.setenv("NJ661_AIRADAR_THINK", "0")
    calls = []

    async def llm(ctx, messages, phase, **kwargs):
        calls.append((phase, kwargs.get("max_tokens"), kwargs.get("options")))
        ids = re.findall(r"МАТЕРИАЛ #(\d+)", messages[1]["content"])
        if phase == "report":
            return json.dumps({"title": "С", "summary": "С", "highlights": []})
        return json.dumps({"items": [result(int(i)) for i in ids]})

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})

    assert out["ok"] and out["stats"]["analyzed"] == 4
    first = calls[0]
    assert first[1] == 8000 and first[2]["chat_template_kwargs"]["enable_thinking"] is False
    assert all(c[2] for c in calls if c[0] == "analysis")


@pytest.mark.asyncio
async def test_provider_rejecting_thinking_off_is_retried_without_options(pipeline, monkeypatch):
    """Локальный сервер не знает chat_template_kwargs — работаем без них."""
    p = pipeline
    monkeypatch.setenv("NJ661_AIRADAR_THINK", "0")
    seen = []

    async def llm(ctx, messages, phase, **kwargs):
        seen.append(kwargs.get("options"))

        if kwargs.get("options"):
            request = httpx.Request("POST", "http://llm.local/v1/chat/completions")
            response = httpx.Response(400, request=request, text="unknown field 'chat_template_kwargs'")
            raise httpx.HTTPStatusError("400", request=request, response=response)

        ids = re.findall(r"МАТЕРИАЛ #(\d+)", messages[1]["content"])
        if phase == "report":
            return json.dumps({"title": "С", "summary": "С", "highlights": []})
        return json.dumps({"items": [result(int(i)) for i in ids]})

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})

    assert out["ok"] and out["stats"]["analyzed"] == 4
    assert seen[0] and not any(seen[1:])   # попытка с полями одна — дальше без них


@pytest.mark.asyncio
async def test_all_truncated_reports_reasoning_cause(pipeline, monkeypatch):
    """Ничего не разобрали — причина с обрывом, а не «пустой JSON»."""
    p = pipeline

    async def llm(ctx, messages, phase, **kwargs):
        return TRUNCATED, LENGTH_USAGE

    monkeypatch.setattr(p.mod, "_llm", llm)
    with pytest.raises(RuntimeError) as err:
        await p.mod.handle(p.ctx, {"run_id": 7})

    assert "размышления" in str(err.value) and "NJ661_AIRADAR_THINK=0" in str(err.value)
    assert not p.reports
    assert p.finishes[-1]["status"] == "fail"
    assert "2953" in p.finishes[-1]["error"]


def test_repack_splits_only_long_batches():
    """Дробим пачки, но не теряем и не путаем флаг повтора."""
    mod = _load()
    pending = mod.deque([([{"id": 1}, {"id": 2}, {"id": 3}], False), ([{"id": 4}], True)])
    out = mod._repack(pending, 1)

    assert [(len(b), r) for b, r in out] == [(1, False), (1, False), (1, False), (1, True)]
    assert [it["id"] for b, _ in out for it in b] == [1, 2, 3, 4]


@pytest.mark.asyncio
async def test_provider_output_cap_falls_back_to_base_budget(pipeline, monkeypatch):
    """Провайдер не принял 8000 токенов ответа — работаем на базовых 3000."""
    p = pipeline
    calls = []

    async def llm(ctx, messages, phase, **kwargs):
        calls.append((kwargs.get("options"), kwargs.get("max_tokens")))

        if phase == "analysis" and (kwargs.get("options") or (kwargs.get("max_tokens") or 0) > 3000):
            request = httpx.Request("POST", "http://llm.local/v1/chat/completions")
            response = httpx.Response(400, request=request, text="max_tokens too large for this model")
            raise httpx.HTTPStatusError("400", request=request, response=response)

        ids = re.findall(r"МАТЕРИАЛ #(\d+)", messages[1]["content"])
        if phase == "report":
            return json.dumps({"title": "С", "summary": "С", "highlights": []})
        if len(calls) == 1:
            return TRUNCATED, LENGTH_USAGE
        return json.dumps({"items": [result(int(i)) for i in ids]}), STOP_USAGE

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})

    assert out["ok"] and out["stats"]["analyzed"] == 4
    assert out["stats"]["output_capped_by_provider"] is True
    assert calls[-1][1] == 3000          # дальше — базовый бюджет


def test_think_mode_env_beats_config_and_out_tokens_from_limits(monkeypatch):
    """Ручки настройки: env важнее limits из конфига сайта."""
    mod = _load()
    monkeypatch.delenv("NJ661_AIRADAR_THINK", raising=False)
    monkeypatch.delenv("NJ661_AIRADAR_OUT_TOKENS", raising=False)

    assert mod._think_mode({}) is None                    # auto по умолчанию
    assert mod._think_mode({"think": "auto"}) is None
    assert mod._think_mode({"think": 0}) is False
    assert mod._think_mode({"think": "1"}) is True

    monkeypatch.setenv("NJ661_AIRADAR_THINK", "on")
    assert mod._think_mode({"think": 0}) is True          # env важнее конфига

    assert mod._out_tokens({"out_tokens": 6000}, True) == 6000
    monkeypatch.setenv("NJ661_AIRADAR_OUT_TOKENS", "7000")
    assert mod._out_tokens({"out_tokens": 6000}, True) == 7000


@pytest.mark.asyncio
async def test_reasoning_batches_are_single_material(pipeline, monkeypatch):
    """Размышляющая модель получает по одному материалу — весь бюджет его."""
    p = pipeline
    sizes = []

    async def llm(ctx, messages, phase, **kwargs):
        ids = re.findall(r"МАТЕРИАЛ #(\d+)", messages[1]["content"])

        if phase == "analysis":
            sizes.append(len(ids))

            if len(sizes) == 1:
                return TRUNCATED, LENGTH_USAGE

        if phase == "report":
            return json.dumps({"title": "С", "summary": "С", "highlights": []})

        return json.dumps({"items": [result(int(i)) for i in ids]}), STOP_USAGE

    monkeypatch.setattr(p.mod, "_llm", llm)
    out = await p.mod.handle(p.ctx, {"run_id": 7})

    assert out["ok"] and out["stats"]["analyzed"] == 4
    assert sizes[0] == 3 and set(sizes[1:]) == {1}
