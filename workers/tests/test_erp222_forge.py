"""Тесты erp222_forge: чистые функции конвейера «Кузницы систем» (без сети/LLM).

Запуск из каталога workers/:
    python3 -m pytest tests/test_erp222_forge.py -q

Проверяем то, на чём держится ценность артефактов:
  * _clean_artifact: ответ модели приводится к виду, который ждёт сайт (тело из
    объекта, метаданные из строк, оценки как пришли, пустая сводка — из тела);
  * _document_gaps: «документ или пересказ» — ловим короткое тело, отсутствие
    входов/выходов, контракт API без методов, схему без DDL, риски без меры;
  * _existing_block / _knowledge_block: контекст шага попадает в промпт с
    номерами артефактов, фактов и идей (иначе модель не сможет сослаться);
  * _json_from: JSON из ответа модели (в том числе внутри текста) и массив;
  * _max_tokens: бюджет ответа не выходит за пределы контекста модели;
  * _run_synth/_run_critic: на сайт уходит ТИП из плана шага (иначе «Схема
    данных» легла бы спецификацией), пробельный черновик — через доработку,
    разбор Критика несёт номер разбираемого артефакта и его новые оценки.
"""

from __future__ import annotations

import json
import os

import pytest


def _load():
    import n2j_workers.handlers.erp222_forge as mod

    return mod


# ------------------------------------------------------------------ артефакт


def test_clean_artifact_normalizes_fields():
    mod = _load()

    got = mod._clean_artifact({
        "title": "  Спецификация   учёта заявок \n",
        "summary": "",
        "body": {"sections": ["цель", "границы"]},
        "metadata": {"inputs": "заявка от клиента; справочник услуг", "outputs": ["номер заявки"]},
        "scores": {"usefulness": 0.9},
    })

    assert got["title"] == "Спецификация учёта заявок"
    assert "sections" in got["body"]                       # объект стал JSON-строкой
    assert got["metadata"]["inputs"] == ["заявка от клиента", "справочник услуг"]
    assert got["metadata"]["outputs"] == ["номер заявки"]
    assert got["metadata"]["assumptions"] == []
    assert got["scores"] == {"usefulness": 0.9}
    assert got["summary"]                                   # сводка взята из тела


def test_clean_artifact_cuts_long_body():
    mod = _load()

    got = mod._clean_artifact({"title": "T", "body": "я" * (mod._BODY_MAX + 500)})

    assert len(got["body"]) == mod._BODY_MAX


def test_string_list_tolerates_shapes():
    mod = _load()

    assert mod._string_list(None) == []
    assert mod._string_list("a\n b ; c") == ["a", "b", "c"]
    assert mod._string_list([{"id": 1}, "  ", 42]) == ['{"id": 1}', "42"]
    assert mod._string_list({"a": "x"}) == ["x"]


# ------------------------------------------------------------------ проверка


def test_document_gaps_short_body():
    mod = _load()

    gaps = mod._document_gaps({"body": "коротко", "metadata": {}}, "spec")

    assert any("короткое" in g for g in gaps)


def test_document_gaps_api_without_methods():
    mod = _load()

    body = "Описание сервиса заявок. " * 80
    gaps = mod._document_gaps({"body": body, "metadata": {"inputs": ["a"], "outputs": ["b"]}}, "api")

    assert any("метода" in g for g in gaps)

    ok = "POST /v1/orders — создаёт заявку\n" + body
    gaps_ok = mod._document_gaps({"body": ok, "metadata": {"inputs": ["a"], "outputs": ["b"]}}, "api")

    assert not any("метода" in g for g in gaps_ok)


def test_document_gaps_risk_without_measure():
    mod = _load()

    body = "Риск: подрядчик не успеет. " * 60
    gaps = mod._document_gaps({"body": body, "metadata": {}}, "risk")

    assert any("мера" in g for g in gaps)

    body_ok = body + " Мера: заранее держим второго подрядчика в резерве."
    gaps_ok = mod._document_gaps({"body": body_ok, "metadata": {}}, "risk")

    assert not any("мера" in g for g in gaps_ok)


def test_document_gaps_schema_without_ddl():
    mod = _load()

    body = "Таблицы описаны словами. " * 60
    gaps = mod._document_gaps({"body": body, "metadata": {}}, "schema")

    assert any("DDL" in g for g in gaps)

    body_ok = body + "\nCREATE TABLE orders (id bigserial PRIMARY KEY);"
    gaps_ok = mod._document_gaps({"body": body_ok, "metadata": {}}, "schema")

    assert not any("DDL" in g for g in gaps_ok)


def test_document_needs_work_respects_flag(monkeypatch):
    mod = _load()
    monkeypatch.setenv("ERP222_FORGE_POLISH", "0")

    assert mod._document_needs_work({"body": "мало", "metadata": {}}, "spec") == []

    monkeypatch.delenv("ERP222_FORGE_POLISH")
    assert mod._document_needs_work({"body": "мало", "metadata": {}}, "spec") != []


# ------------------------------------------------------------------ контекст


def test_existing_block_lists_ids():
    mod = _load()

    block = mod._existing_block({"existing": [
        {"id": 7, "type": "spec", "title": "Спецификация заявок", "value_score": 78, "summary": "о чём"},
    ]})

    assert "#7" in block and "Спецификация заявок" in block and "78" in block
    assert "не на что" in mod._existing_block({})


def test_knowledge_block_keeps_numbers():
    mod = _load()

    block = mod._knowledge_block({"knowledge": {
        "facts": [{"id": 12, "text": "Gemma 3 12B — 128K контекст"}],
        "ideas": [{"id": 3, "title": "Кузница", "body": "выпускать артефакты"}],
    }})

    assert "факт #12" in block and "идея #3" in block
    assert mod._knowledge_block({}) == ""


def test_world_block_carries_role_and_proof():
    mod = _load()

    block = mod._world_block({
        "world": {"title": "Учёт заявок", "brief": "сервисный центр", "goal": "меньше ручной работы"},
        "role": {"code": "engineer", "title": "Инженер", "focus": "контракты и алгоритмы"},
        "type": "api",
        "type_proof": "для каждого метода есть запрос и пример ответа",
    }, 3, 6)

    assert "ШАГ 3 из 6" in block
    assert "Инженер" in block
    assert "пример ответа" in block                          # состав типа подставлен
    assert "признак готовности" in block.lower()


def test_target_block_for_critic():
    mod = _load()

    block = mod._target_block({"target": {
        "id": 21, "type": "spec", "title": "Спецификация", "summary": "кратко",
        "body": "тело документа", "value_score": 64, "critique": "старый разбор",
    }})

    assert "#21" in block and "тело документа" in block and "старый разбор" in block
    assert mod._target_block({}) == ""


# ------------------------------------------------------------------ разбор JSON


def test_json_from_text_and_array():
    mod = _load()

    assert mod._json_from('```json\n{"title": "A", "body": "B"}\n```')["title"] == "A"
    assert mod._json_from(json.dumps([{"nope": 1}, {"title": "B", "body": "C"}]))["title"] == "B"
    assert mod._json_from("никакого json") == {}


def test_max_tokens_fits_context(monkeypatch):
    mod = _load()
    monkeypatch.delenv("ERP222_FORGE_MAX_TOKENS", raising=False)

    small = mod._max_tokens({"context": 8000})
    big = mod._max_tokens({"context": 64000})

    assert small >= 1400 and small <= 2000
    assert big <= 6000
    assert mod._max_tokens({"context": 32000}) >= small


def test_model_prefers_env(monkeypatch):
    mod = _load()
    monkeypatch.delenv("ERP222_FORGE_MODEL", raising=False)

    assert mod._model({"model": "openrouter::qwen/qwen3-32b"}) == "openrouter::qwen/qwen3-32b"
    assert mod._model({}) is None

    monkeypatch.setenv("ERP222_FORGE_MODEL", "local::gemma-3-12b")
    assert mod._model({"model": "openrouter::qwen/qwen3-32b"}) == "local::gemma-3-12b"


# ------------------------------------------------------------------ вызовы LLM
#
# _run_synth/_run_critic ходят в модель и возвращают документ для сайта. Здесь
# модель подменена: проверяем не «что ответила LLM», а то, что конвейер делает
# с ответом, — и главное, что на сайт уходит ТИП из плана шага.

class _FakeLLM:
    """ctx.llm: отдаёт заготовленные ответы по очереди (дальше — последний)."""

    def __init__(self, replies):
        self.replies = list(replies)
        self.calls = []

    async def chat_with_usage(self, messages, model=None, max_tokens=None, request_timeout=None):
        self.calls.append({"messages": messages, "max_tokens": max_tokens})

        index = min(len(self.calls) - 1, len(self.replies) - 1)

        return self.replies[index], {"total_tokens": 10}


class _FakeCtx:
    def __init__(self, replies):
        self.llm = _FakeLLM(replies)
        self.steps = []

    async def progress(self, stage, note=""):
        self.steps.append((stage, note))


def _plan(type_code: str, role_code: str, **extra) -> dict:
    plan = {
        "type": type_code,
        "step": 2,
        "steps": 6,
        "role": {"code": role_code, "title": "Инженер", "focus": "как это сделать"},
        "world": {"title": "Учёт заявок", "brief": "Сервисный центр теряет статусы заявок",
                  "goal": "заявка видна всем", "protocol": "ценность — готовность к работе",
                  "domain": "сервис"},
        "existing": [],
        "rubric": {"usefulness": 0.35, "concreteness": 0.3},
    }
    plan.update(extra)

    return plan


def _doc(title: str, body: str, **extra) -> dict:
    data = {
        "title": title,
        "summary": "Документ о том, как устроен учёт заявок сервисного центра, и что с ним делать.",
        "body": body,
        "metadata": {"inputs": ["заявка: клиент, техника"], "outputs": ["заявка со статусом"]},
        "scores": {"usefulness": 0.8, "concreteness": 0.8, "novelty": 0.7, "feasibility": 0.9},
    }
    data.update(extra)

    return data


LONG_BODY = ("## Назначение\nУчёт заявок сервисного центра.\n\n## Шаги\n"
             "1. Оператор фиксирует заявку. 2. Диспетчер назначает мастера. "
             "3. Мастер закрывает заявку.\n\nCREATE TABLE requests (id bigserial PRIMARY KEY, due_at timestamptz);\n") * 12


@pytest.mark.asyncio
async def test_synth_carries_planned_type():
    """Тип шага берётся из плана: иначе «Схема данных» легла бы Спецификацией."""
    mod = _load()
    ctx = _FakeCtx([json.dumps(_doc("Схема данных заявок", LONG_BODY), ensure_ascii=False)])

    artifact, problem, polished = await mod._run_synth(ctx, _plan("schema", "architect"), {})

    assert problem == ""
    assert artifact["type"] == "schema"
    assert polished is False
    assert len(ctx.llm.calls) == 1


@pytest.mark.asyncio
async def test_synth_polishes_gappy_draft():
    """Черновик с пробелами уходит в доработку, и сайт получает доработанный."""
    mod = _load()
    draft = _doc("Черновик спецификации", "Коротко: система нужна.", metadata={})
    fixed = _doc("Спецификация учёта заявок", LONG_BODY)

    ctx = _FakeCtx([json.dumps(draft, ensure_ascii=False), json.dumps(fixed, ensure_ascii=False)])

    artifact, problem, polished = await mod._run_synth(ctx, _plan("spec", "architect"), {})

    assert polished is True
    assert problem == ""
    assert artifact["title"] == "Спецификация учёта заявок"
    assert artifact["type"] == "spec"
    assert len(ctx.llm.calls) == 2
    # Второй вызов — доработка: в промпте перечислены найденные пробелы.
    assert "пробелы" in ctx.llm.calls[1]["messages"][-1]["content"].lower()


@pytest.mark.asyncio
async def test_critic_returns_risk_with_target():
    """Разбор Критика: тип risk, номер разбираемого артефакта и его новые оценки."""
    mod = _load()
    reply = _doc("Разбор: где сломается учёт заявок", "## Находки\n1. Нет SLA. Мера: поле due_at.",
                 target_scores={"usefulness": 0.5, "concreteness": 0.4, "novelty": 0.6, "feasibility": 0.8})

    ctx = _FakeCtx([json.dumps(reply, ensure_ascii=False)])
    plan = _plan("risk", "critic", target={"id": 7, "type": "spec", "title": "Спецификация",
                                           "value_score": 88, "summary": "Границы", "body": "Тело"})

    artifact, problem, polished = await mod._run_critic(ctx, plan, {})

    assert problem == "" and polished is False
    assert artifact["type"] == "risk"
    assert artifact["target_id"] == 7
    assert artifact["target_scores"]["concreteness"] == 0.4
