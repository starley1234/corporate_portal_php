"""Сквозной прогон каналов prx399 без сети: сайт подменяется на record/replay,
LLM — фейк с ответами по подсказке в промпте (как MOCK_LLM_ROUTES, только в
памяти). Проверяется всё, кроме PostgreSQL: скачивание PDF (mock-transport),
AST, форма тел, которые получает сайт, дедуп и Reduce-фолбэк аудита.
"""
from __future__ import annotations

import asyncio
import json
from pathlib import Path

import httpx
import pytest

from prx_workers import site
from prx_workers.base import HandlerContext
from prx_workers.handlers.prx399_docmerge import audit, delta

INPUT_CALLS: list[str] = []
POSTED: list[tuple[str, dict]] = []


def _fake_site(monkeypatch, inputs: dict):
    INPUT_CALLS.clear()
    POSTED.clear()

    async def get_json(prefix, path, **kwargs):
        INPUT_CALLS.append(path)
        for key, value in inputs.items():
            if path.startswith(key):
                return True, "", value
        return False, "нет задания", {}

    async def post_json(prefix, path, data, **kwargs):
        POSTED.append((path, data))
        return True, "", {"ok": True}

    monkeypatch.setattr(site, "get_json", get_json)
    monkeypatch.setattr(site, "post_json", post_json)
    monkeypatch.setattr(delta, "site", site)
    monkeypatch.setattr(audit, "site", site)


def _fake_llm(replies):
    """Класс-двойник LlmClient: ответ выбирается по подсказке в промпте."""

    class FakeLlm:
        async def chat_with_usage(self, messages, model=None, **options):
            prompt = messages[0]["content"]
            for marker, reply in replies:
                if marker in prompt:
                    return json.dumps(reply, ensure_ascii=False), {"total_tokens": 42}
            raise AssertionError("промпт не совпал ни с одним маршрутом фейка")

    return FakeLlm()


def _ctx(llm):
    return HandlerContext(llm=llm, embed=None, config=None)


def _fake_pdf(monkeypatch, text="1.1. Поставщик обязуется поставить товар в срок до 30.06.2026.\n"):
    """Канонический приём этой папки: MockTransport не стримит — контент
    подаём async-генератором (см. tests/clients)."""
    body = b"%PDF-1.4 fake"

    def handler(request: httpx.Request) -> httpx.Response:
        async def stream():
            yield body

        return httpx.Response(200, content=stream(), headers={"Content-Type": "application/pdf"})

    async def fake_download(ctx, file_id, path):
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            async with client.stream("GET", "http://site.test/file") as response:
                with path.open("xb") as pdf:
                    async for block in response.aiter_bytes():
                        pdf.write(block)

    monkeypatch.setattr(delta, "_download_pdf", fake_download)

    async def fake_text(ctx, path):
        assert path.read_bytes() == body
        return text, 1

    monkeypatch.setattr(delta, "_text_of_pdf", fake_text)


BASE_CARD = {"parties": [{"name": "ООО «Ромашка»", "role": "Заказчик"}], "subject": "поставка"}
BASE_AST_NODES = [{"path": "1.1", "title": "Пункт 1.1", "text": "Поставщик обязуется поставить товар.",
                   "status": "base", "source": "base", "history": []}]


def test_delta_base_file_posts_ast_and_card(monkeypatch):
    _fake_site(monkeypatch, {"/delta_input/10": {
        "done": False, "kind": "base", "file_id": 10, "contract_id": 1,
        "original_name": "contract.pdf", "model": "", "temperature": 0, "max_tokens": 2000,
    }})
    _fake_pdf(monkeypatch)
    _fake_site(monkeypatch, {"/delta_input/10": {
        "done": False, "kind": "base", "file_id": 10, "contract_id": 1,
        "original_name": "contract.pdf", "model": "", "temperature": 0, "max_tokens": 2000,
    }})
    llm = _fake_llm([("карточку сделки", BASE_CARD)])

    result = asyncio.run(delta.handle(_ctx(llm), {"file_id": 10}))
    assert result["kind"] == "base" and result["pages"] == 1

    paths = [path for path, _data in POSTED]
    assert paths == ["/delta_result/10"]
    posted = POSTED[0][1]
    assert posted["pages"] == 1 and len(posted["text_sha256"]) == 64
    assert posted["ast"]["nodes"][0]["path"] == "1.1"
    assert posted["deal_card"]["subject"] == "поставка"


def test_delta_ds_posts_ops_only(monkeypatch):
    ops_reply = {"ops": [{"op": "replace", "anchor": "1.1", "body": "Срок до 31.07.2026.", "confidence": 0.9}]}
    _fake_site(monkeypatch, {"/delta_input/11": {
        "done": False, "kind": "ds", "file_id": 11, "contract_id": 1, "ordinal": 1,
        "original_name": "ДС-1.pdf", "deal_card": BASE_CARD,
        "model": "", "temperature": 0, "max_tokens": 4000,
    }})
    _fake_pdf(monkeypatch, text="Изложить пункт 1.1 в следующей редакции: срок до 31.07.2026.")
    llm = _fake_llm([("инструкций для сборки", ops_reply)])

    result = asyncio.run(delta.handle(_ctx(llm), {"file_id": 11}))
    assert result["note"] == "дельт: 1"

    posted = POSTED[0][1]
    assert "ops" in posted and "ast" not in posted
    assert posted["ops"][0]["anchor"] == "1.1"


def test_delta_done_input_is_skipped(monkeypatch):
    _fake_site(monkeypatch, {"/delta_input/12": {"done": True, "status": "parsed"}})
    result = asyncio.run(delta.handle(_ctx(None), {"file_id": 12}))
    assert result == {"file_id": 12, "skipped": True, "status": "parsed"}
    assert not POSTED


def test_audit_map_reduce_posts_summary_and_risks(monkeypatch):
    nodes = [
        {"path": "1.1", "title": "Пункт 1.1. Оплата", "text": "оплата 10 дней", "status": "base", "source": "base", "history": []},
        {"path": "3.1", "title": "Пункт 3.1. Штрафы", "text": "неустойка 1% в день", "status": "changed", "source": "ДС №1", "history": []},
        {"path": "9.1", "title": "Пункт 9.1. Реквизиты", "text": "подписи", "status": "base", "source": "base", "history": []},
    ]
    _fake_site(monkeypatch, {"/audit_input/1": {
        "done": False, "contract_id": 1, "title": "Договор поставки", "focus": "customer",
        "policy": "неустойка не выше 0,1%", "deal_card": BASE_CARD, "nodes": nodes,
        "model": "", "temperature": 0, "max_tokens": 4000,
    }})
    map_reply = {"risks": [
        {"severity": "high", "area": "Штрафы", "title": "Неустойка 1% в день — выше рыночной", "detail": "п. 3.1"},
        {"severity": "medium", "area": "Штрафы", "title": "Дубликат-ловушка", "detail": "п. 3.1"},
    ]}
    reduce_reply = {"summary": "Поставка товара до 30.06.2026, цена 1,2 млн руб.",
                    "risks": [{"severity": "high", "area": "Штрафы", "title": "Неустойка 1% в день — выше рыночной",
                               "detail": "п. 3.1", "chunk_path": "3.1", "recommendation": "снизить до 0,1%"}]}
    llm = _fake_llm([("смысловой блок", map_reply), ("Map-Reduce", reduce_reply)])

    result = asyncio.run(audit.handle(_ctx(llm), {"contract_id": 1}))
    assert result["risks"] == 1 and result["chunks"] >= 2

    posted = POSTED[0][1]
    assert posted["summary"].startswith("Поставка товара")
    assert len(posted["risks"]) == 1  # дедуп отработал до Reduce


def test_audit_reduces_without_llm_and_still_reports(monkeypatch):
    """Reduce-фолбэк: LLM недоступен после Map — владелец всё равно получает находки."""
    nodes = [{"path": "1.1", "title": "Пункт 1.1. Штрафы", "text": "неустойка 1%",
              "status": "base", "source": "base", "history": []}]

    class BrokenReduceLlm:
        async def chat_with_usage(self, messages, model=None, **options):
            if "Map-Reduce" in messages[0]["content"]:
                raise RuntimeError("LLM отвалился на Reduce")
            return json.dumps({"risks": [
                {"severity": "high", "title": "Неустойка 1% в день", "area": "Штрафы"},
                {"severity": "high", "title": "Неустойка 1% в день", "area": "Штрафы"},
            ]}), {}

    _fake_site(monkeypatch, {"/audit_input/2": {
        "done": False, "contract_id": 2, "title": "Д", "focus": "balanced",
        "policy": "", "deal_card": None, "nodes": nodes,
        "model": "", "temperature": 0, "max_tokens": 4000,
    }})

    result = asyncio.run(audit.handle(_ctx(BrokenReduceLlm()), {"contract_id": 2}))
    assert result["risks"] == 1  # дедуп точных повторов

    posted = POSTED[0][1]
    assert "программно" in posted["summary"] and len(posted["risks"]) == 1
