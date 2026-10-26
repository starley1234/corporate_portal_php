"""Тесты каналов nj670.* — ProcessHub.

Запуск на хосте воркера::

    python -m pytest tests/test_nj670_processhub.py

Модуль импортируется в обход ``handlers/__init__.py``: реестр тянет все каналы
и их зависимости, а здесь нужен только nj670. Заглушки пакетов делают импорт
предсказуемым и в среде без остальных библиотек.
"""

from __future__ import annotations

import sys
import types
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

if "n2j_workers" not in sys.modules:
    package = types.ModuleType("n2j_workers")
    package.__path__ = [str(ROOT / "n2j_workers")]
    sys.modules["n2j_workers"] = package

if "n2j_workers.handlers" not in sys.modules:
    handlers = types.ModuleType("n2j_workers.handlers")
    handlers.__path__ = [str(ROOT / "n2j_workers" / "handlers")]
    sys.modules["n2j_workers.handlers"] = handlers

from n2j_workers.handlers import nj670_processhub as hub  # noqa: E402


ONTOLOGY = {
    "role": [
        {"code": "initiator", "name": "Инициатор закупки", "rate_per_hour": 1200},
        {"code": "manager", "name": "Руководитель подразделения", "rate_per_hour": 2000},
        {"code": "lawyer", "name": "Юрист", "rate_per_hour": 2500},
        {"code": "security", "name": "Служба безопасности", "rate_per_hour": 2000},
        {"code": "accountant", "name": "Бухгалтер", "rate_per_hour": 1500},
    ],
    "system": [
        {"code": "1c", "name": "1С:ERP"},
        {"code": "telegram", "name": "Telegram"},
        {"code": "outlook", "name": "Outlook"},
    ],
    "artifact": [
        {"code": "memo", "name": "Служебная записка"},
        {"code": "contract", "name": "Проект договора"},
    ],
}

CONTEXT = {
    "process": {"title": "Закупка ТМЦ"},
    "ontology": ONTOLOGY,
    "limits": {"max_nodes": 80, "max_edges": 160},
    "economy": {"default_rate_per_hour": 1500, "wait_cost_share": 0.5},
}


def _types(graph):
    return [node["type"] for node in graph["nodes"]]


def _by_role(graph, role):
    return [node for node in graph["nodes"] if node.get("role_code") == role]


# --------------------------------------------------------------- синтез плана


def test_text_graph_recognises_roles_systems_and_durations():
    graph = hub._text_graph(
        "Инициатор закупки создаёт служебную записку в 1С:ERP, 60 мин\n"
        "Руководитель подразделения согласует заявку, ожидание 4 ч\n"
        "Юрист проверяет проект договора, 2 ч\n"
        "Бухгалтер оплачивает счёт, 30 мин",
        CONTEXT,
    )

    assert _types(graph)[0] == "start" and _types(graph)[-1] == "end"
    # start + 4 шага + end
    assert len(graph["nodes"]) == 6
    assert len(graph["edges"]) >= 5

    roles = {node.get("role_code") for node in graph["nodes"]}
    assert {"initiator", "manager", "lawyer", "accountant"} <= roles

    request = _by_role(graph, "initiator")[0]
    assert request["system_code"] == "1c"
    assert request["duration_minutes"] == 60

    approval = _by_role(graph, "manager")[0]
    # «ожидание 4 ч» — это простой, а не работа: 240 мин ожидания и 15 % на действие.
    assert approval["wait_time_minutes"] == 240
    assert approval["duration_minutes"] == 36

    assert {lane["role_code"] for lane in graph["lanes"]} >= {"initiator", "lawyer"}
    assert graph["meta"]["mode"] == "plan"
    assert graph["meta"]["source"] == "rules"


def test_text_graph_adds_xor_gateway_for_conditional_line():
    graph = hub._text_graph(
        "Инициатор закупки создаёт служебную записку в 1С:ERP, 30 мин\n"
        "Если договор возвращён, юрист проверяет повторно",
        CONTEXT,
    )

    assert "gateway_xor" in _types(graph)
    assert any(edge["type"] == "conditional" for edge in graph["edges"])


def test_text_graph_rejects_text_without_steps():
    try:
        hub._text_graph("Утверждаю", CONTEXT)
    except ValueError as error:
        assert "шаги" in str(error)
    else:  # pragma: no cover - защита от регресса
        raise AssertionError("на пустом тексте ожидалась ошибка")


def test_duration_units_match_the_site():
    assert hub._duration_of("30 мин") == 30
    assert hub._duration_of("2 ч") == 120
    assert hub._duration_of("1,5 ч") == 90
    assert hub._duration_of("1 раб. дн.") == 480
    assert hub._duration_of("2 недели") == 4800
    assert hub._duration_of("без срока") == 0


def test_clean_graph_drops_unknown_nodes_and_keeps_start_end():
    graph = hub._clean_graph(
        {
            "nodes": [
                {"id": "a", "type": "robot", "title": "Сбор заявки", "duration_minutes": -5},
                {"id": "a", "type": "user_task", "title": "Дубль"},
                {"id": "b", "type": "user_task", "title": "Согласование"},
            ],
            "edges": [
                {"from": "a", "to": "b", "type": "magic"},
                {"from": "a", "to": "missing"},
            ],
        },
        CONTEXT,
    )

    assert len(graph["nodes"]) == 4  # a, b, start, end — дубль отброшен
    assert _types(graph)[0] == "start" and _types(graph)[-1] == "end"
    assert {node["id"] for node in graph["nodes"]} == {"a", "b", "start", "end"}
    # Неизвестный тип становится обычной задачей, отрицательная длительность — нулём.
    assert next(node for node in graph["nodes"] if node["id"] == "a")["type"] == "user_task"
    assert next(node for node in graph["nodes"] if node["id"] == "a")["duration_minutes"] == 0
    assert graph["edges"][0]["type"] == "sequence"
    assert all(edge["from"] != "a" or edge["to"] != "missing" for edge in graph["edges"])


# ------------------------------------------------------------ процесс-майнинг


def _journal_rows():
    return [
        ["Case_ID", "Activity", "Timestamp", "Resource"],
        ["A-1", "Создание заявки", "2026-03-01 09:00", "Инициатор закупки"],
        ["A-1", "Проверка юриста", "2026-03-01 11:00", "Юрист"],
        ["A-1", "Оплата счёта", "2026-03-02 10:00", "Бухгалтер"],
        ["A-2", "Создание заявки", "2026-03-01 10:00", "Инициатор закупки"],
        ["A-2", "Проверка юриста", "2026-03-01 12:00", "Юрист"],
        ["A-2", "Оплата счёта", "2026-03-02 12:00", "Бухгалтер"],
    ]


def test_mine_graph_builds_fact_from_event_log():
    graph, stats = hub._mine_graph(_journal_rows(), {**CONTEXT, "mode": "fact"})

    assert stats == {
        "cases": 2,
        "events": 6,
        "activities": 3,
        "transitions": 2,
        "skipped": 0,
        "median_minutes": 1530,
    }
    assert graph["meta"]["mode"] == "fact"
    assert graph["meta"]["elapsed_minutes"] == 1530

    slugs = {node["step_id"] for node in graph["nodes"]}
    assert {"sozdanie-zayavki", "proverka-yurista", "oplata-scheta"} <= slugs

    roles = {node.get("role_code") for node in graph["nodes"]}
    assert roles >= {"initiator", "lawyer", "accountant"}

    # 09:00 → 11:00 у «Создание заявки»: 120 минут простоя на кейс.
    request = next(node for node in graph["nodes"] if node["step_id"] == "sozdanie-zayavki")
    assert request["wait_time_minutes"] == 120
    assert request["properties"]["frequency"] == 2

    edge = next(e for e in graph["edges"]
                if e["from"] == request["id"] and graph["nodes"][
                    [n["id"] for n in graph["nodes"]].index(e["to"])]["step_id"] == "proverka-yurista")
    assert edge["weight"] == 2


def test_mine_graph_requires_case_activity_timestamp():
    rows = [["Случай", "Шаг"], ["A-1", "Заявка"]]

    try:
        hub._mine_graph(rows, CONTEXT)
    except ValueError as error:
        assert "Case_ID" in str(error)
    else:  # pragma: no cover
        raise AssertionError("журнал без колонок должен отвергаться")


def test_slug_is_stable_and_latin():
    assert hub._slug("Проверка СБ") == "proverka-sb"
    assert hub._slug("Оплата счёта") == "oplata-scheta"
    assert hub._slug("Проверка СБ") == hub._slug("Проверка СБ")


# ------------------------------------------------------------------- AI-дифф


def _step(node_id, title, step_id, **extra):
    node = {
        "id": node_id,
        "step_id": step_id,
        "type": "user_task",
        "title": title,
        "lane": "",
        "duration_minutes": 60,
        "wait_time_minutes": 0,
        "properties": {},
    }
    node.update(extra)
    return node


def _plan_fact():
    plan = {
        "lanes": [],
        "nodes": [
            {"id": "start", "step_id": "start", "type": "start", "title": "Начало"},
            _step("request", "Создаёт заявку", "request", role_code="initiator", system_code="1c"),
            _step("legal", "Проверяет договор", "legal", role_code="lawyer", system_code="outlook"),
            {"id": "end", "step_id": "end", "type": "end", "title": "Конец"},
        ],
        "edges": [],
        "meta": {"elapsed_minutes": 1440},
    }
    fact = {
        "lanes": [],
        "nodes": [
            {"id": "start", "step_id": "start", "type": "start", "title": "Начало"},
            _step("request", "Создаёт заявку", "request", role_code="initiator", system_code="1c"),
            _step("tg", "Согласование в Telegram", "tg_approval", role_code="manager",
                  system_code="telegram", wait_time_minutes=3600,
                  properties={"shadow": True}),
            {"id": "end", "step_id": "end", "type": "end", "title": "Конец"},
        ],
        "edges": [],
        "meta": {"elapsed_minutes": 6720},
    }
    return plan, fact


def test_rules_diff_finds_shadow_it_and_skipped_control():
    plan, fact = _plan_fact()
    diff = hub._rules_diff(CONTEXT, plan, fact)
    categories = {finding["category"] for finding in diff["findings"]}

    assert "shadow_it" in categories
    assert "missing_control" in categories
    assert "bottleneck" in categories
    assert "sla" in categories

    shadow = next(f for f in diff["findings"] if f["category"] == "shadow_it")
    assert shadow["severity"] == "critical"
    assert shadow["impact_rub"] > 0
    assert "Telegram" in shadow["title"]

    missing = next(f for f in diff["findings"] if f["category"] == "missing_control")
    assert missing["node_id"] == "legal"

    bottleneck = next(f for f in diff["findings"] if f["category"] == "bottleneck")
    assert bottleneck["metric"] == "53.6 %"

    assert diff["total_impact_rub"] > 0
    assert diff["risk_level"] == "critical"
    assert diff["model"] == "rules-engine"


def test_rules_diff_is_quiet_when_fact_matches_plan():
    plan, _ = _plan_fact()
    diff = hub._rules_diff(CONTEXT, plan, plan)

    assert diff["findings"] == []
    assert diff["total_impact_rub"] == 0


# -------------------------------------------------------------------- экспорт


def test_document_json_builds_gost_sections_and_raci():
    document = {
        "org_name": "ООО «Ромашка»",
        "title": "Закупка ТМЦ",
        "city": "Москва",
        "year": 2026,
        "version_tag": "plan-v5",
        "kind_label": "План (To-Be)",
        "department": "Отдел закупок",
        "owner": "Иванов И. И.",
        "approve_role": "Директор",
        "gost": "ГОСТ Р 7.0.97-2016",
        "steps": [
            {"n": 1, "role": "Инициатор", "input": "Служебная записка", "title": "Создаёт заявку",
             "system": "1С:ERP", "output": "Заявка", "sla": "60 мин"},
            {"n": 2, "role": "Юрист", "input": "Проект договора", "title": "Проверяет договор",
             "system": "Outlook", "output": "Виза", "sla": "2 ч"},
        ],
        "raci": {
            "columns": [{"code": "initiator", "name": "Инициатор"}, {"code": "lawyer", "name": "Юрист"}],
            "rows": [{"title": "Создаёт заявку", "cells": {"initiator": "R", "lawyer": "I"}}],
        },
        "rules": [{"code": "R-1", "name": "Договор визирует юрист", "note": "обязательно"}],
        "audit": {
            "summary": "План 1 440 мин, факт 6 720 мин.",
            "risk_level": "critical",
            "impact_rub": 18400,
            "findings": [{"severity": "critical", "title": "Telegram вместо 1С",
                          "evidence": "40 из 40 кейсов", "recommendation": "Убрать мессенджер"}],
        },
    }

    doc = hub._document_json(document)
    headings = [section["heading"] for section in doc["sections"]]

    assert doc["needs_title_page"] is True
    assert "Регламент бизнес-процесса «Закупка ТМЦ»" == doc["title"]
    assert doc["city_year"] == "Москва, 2026"
    assert "Директор" in doc["approve_line"]

    assert any("Общие сведения" in h for h in headings)
    steps = next(s for s in doc["sections"] if "Операционная таблица" in s["heading"])
    table = steps["blocks"][0]
    assert table["columns"] == ["№", "Роль", "Вход", "Действие", "Система", "Выход", "SLA"]
    assert len(table["rows"]) == 2
    assert table["rows"][0][3] == "Создаёт заявку"

    raci = next(s for s in doc["sections"] if "RACI" in s["heading"])
    assert raci["blocks"][0]["columns"] == ["Шаг", "Инициатор", "Юрист"]
    assert raci["blocks"][0]["rows"] == [["Создаёт заявку", "R", "I"]]

    audit = next(s for s in doc["sections"] if "аудит" in s["heading"].lower())
    assert audit["blocks"][0]["text"].startswith("План 1 440 мин")
    assert audit["blocks"][2]["rows"][0][1] == "Telegram вместо 1С"


def test_diagram_png_renders_nodes_and_edges():
    plan, _ = _plan_fact()

    for index, node in enumerate(plan["nodes"]):
        node.update({"x": 60 + index * 280, "y": 100, "w": 196, "h": 86})

    plan["edges"] = [{"from": "start", "to": "request", "type": "sequence"},
                     {"from": "request", "to": "legal", "type": "sequence"},
                     {"from": "legal", "to": "end", "type": "sequence"}]

    png = hub._diagram_png(plan, "Закупка ТМЦ")

    assert png.startswith(b"\x89PNG\r\n\x1a\n")
    assert len(png) > 1000


def test_minutes_text_matches_the_site_rules():
    assert hub._minutes(45) == "45 мин"
    assert hub._minutes(90) == "1 ч 30 мин"
    assert hub._minutes(480) == "1 дн."
    assert hub._minutes(1440) == "3 дн."


# --------------------------------------------------- онтология из документа


_DOC = """Регламент закупки товарно-материальных ценностей

Инициатор закупки создаёт заявку в 1С:ERP и прикладывает служебную записку, 40 мин.
Руководитель подразделения согласует заявку, ожидание 4 ч.
Если сумма выше 500 000 рублей, юрист проверяет проект договора, 90 мин.
Служба безопасности проверяет контрагента, ожидание 2 ч.
Бухгалтер оплачивает счёт и подшивает платёжное поручение, 30 мин.
"""


def test_rules_ontology_reuses_dictionary_codes_and_builds_process():
    result = hub._rules_ontology(
        _DOC,
        {"ontology": ONTOLOGY, "job": {"source_name": "reglament.docx"}},
    )

    pairs = {(entity["class_type"], entity["code"]) for entity in result["entities"]}
    # Знакомые роли и системы не дублируются новыми кодами.
    assert ("role", "lawyer") in pairs
    assert ("role", "security") in pairs
    assert ("system", "1c") in pairs
    # «Проект договора» из словаря, а не второй код «dogovor».
    assert ("artifact", "contract") in pairs
    assert ("artifact", "dogovor") not in pairs
    # Служба безопасности — роль, а не подразделение.
    assert not any(entity["class_type"] == "department" for entity in result["entities"])

    assert len(result["processes"]) == 1
    process = result["processes"][0]
    assert process["title"] == "reglament"
    assert len(process["steps"]) >= 4

    # Шаги получают коды из справочника, а не пустые строки.
    roles = {step["role_code"] for step in process["steps"]}
    assert "lawyer" in roles and "accountant" in roles
    # Условная строка не теряется.
    assert any(step["condition"] for step in process["steps"])
    assert process["steps"][0]["duration_minutes"] == 40


def test_rules_ontology_is_honest_about_empty_document():
    result = hub._rules_ontology("Коротко.", {"ontology": ONTOLOGY})

    assert result["processes"] == []
    assert result["note"]


class _Ctx:
    """Мини-контекст обработчика: progressive и (опционально) LLM."""

    llm = None

    async def progress(self, *args, **kwargs):  # noqa: ARG002
        return None


def test_rules_ontology_extracts_departments_without_verbs():
    result = hub._rules_ontology(
        "Отдел закупок формирует заявку в 1С.\n"
        "Департамент логистики планирует поставки.\n",
        {"ontology": {}, "job": {}},
    )

    names = [e["name"] for e in result["entities"] if e["class_type"] == "department"]

    assert "Отдел закупок" in names
    assert "Департамент логистики" in names
    assert not any("планирует" in name.lower() for name in names)


def test_handle_ontology_without_llm_saves_rules_result(monkeypatch):
    calls = []

    async def fake_api(path, data, timeout=180.0):  # noqa: ARG001
        calls.append((path, data))
        if path == "/context":
            return {
                "ontology": ONTOLOGY,
                "job": {"source_name": "reglament.txt"},
                "version": {"source_text": _DOC},
            }
        return {"ok": True}

    async def fake_soft(path, data, timeout=180.0):  # noqa: ARG001
        calls.append((path, data))
        return {"ok": True}

    monkeypatch.setattr(hub, "_api", fake_api)
    monkeypatch.setattr(hub, "_api_soft", fake_soft)

    import asyncio

    out = asyncio.run(hub.handle_ontology(_Ctx(), {"ontology_job_id": 7}))

    assert out["model"] == "rules-engine"
    assert out["entities"] > 0
    assert calls[-1][0] == "/ontology"
    assert calls[-1][1]["ontology_job_id"] == 7
    assert calls[-1][1]["result"]["entities"]


def test_handle_ontology_marks_failure(monkeypatch):
    calls = []

    async def fake_api(path, data, timeout=180.0):  # noqa: ARG001
        raise RuntimeError("сайт недоступен")

    async def fake_soft(path, data, timeout=180.0):  # noqa: ARG001
        calls.append((path, data))
        return {"ok": True}

    monkeypatch.setattr(hub, "_api", fake_api)
    monkeypatch.setattr(hub, "_api_soft", fake_soft)

    import asyncio
    import pytest

    with pytest.raises(RuntimeError):
        asyncio.run(hub.handle_ontology(_Ctx(), {"ontology_job_id": 9}))

    assert calls[-1][0] == "/ontology_fail"
    assert calls[-1][1]["error"]


def test_llm_ontology_parses_json_and_marks_model():
    import asyncio

    class _LLM:
        async def chat_with_usage(self, messages, model=None, max_tokens=6000, request_timeout=300):
            return (
                '{"entities":[{"class_type":"role","code":"lawyer","name":"Юрист",'
                '"attributes":{"description":"проверяет договоры"}}],'
                '"processes":[],"note":"проверено"}',
                {"_llm_model": "test-model"},
            )

    class _CtxLLM(_Ctx):
        llm = _LLM()

    raw = asyncio.run(hub._llm_ontology(_CtxLLM(), {
        "ontology": ONTOLOGY,
        "limits": {"max_text": 1000, "out_tokens": 500},
    }, _DOC, {}))

    assert raw["entities"][0]["code"] == "lawyer"
    assert "test-model" in raw["note"]
