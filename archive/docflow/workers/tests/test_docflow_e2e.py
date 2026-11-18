"""Сквозной прогон каналов docflow: настоящий mock-LLM и настоящие PDF.

Сайт подменяется двумя функциями (`site.get_json` / `site.post_json`), поэтому
проверяется всё, кроме PostgreSQL: разбор PDF через pypdf, маршрутизация
`MOCK_LLM_ROUTES`, разбор JSON-ответа модели и форма тела, которую получает сайт.
"""
from __future__ import annotations

import asyncio
import json
import os
import shutil
import socket
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import urlparse

import pytest

from prx_workers.handlers import doc_analyze, doc_draft, doc_normalize
from prx_workers.llm import LlmClient, Provider

ROOT = Path(__file__).resolve().parents[2]
DEMO = ROOT / "application" / "modules" / "prx390_intake" / "demo"
MOCK_SERVER = ROOT / "workers" / "mock" / "mock_llm_server.py"


class Ctx:
    def __init__(self, llm=None):
        self.llm = llm
        self.events = []

    async def progress(self, *args, **kwargs):
        self.events.append(args)


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def _wait_port(port: int, timeout: float = 10.0) -> None:
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        with socket.socket() as s:
            if s.connect_ex(("127.0.0.1", port)) == 0:
                return
        time.sleep(0.05)
    raise RuntimeError("mock-LLM не поднялся")


@pytest.fixture(scope="module")
def mock_llm():
    port = _free_port()
    routes = ";".join(
        f"{(DEMO / 'mock' / name).resolve()}={needle}"
        for name, needle in (
            ("claim.json", "ПРЕТЕНЗИЯ"),
            ("court.json", "СУДЕБНОЕ ИЗВЕЩЕНИЕ"),
            ("tax.json", "ТРЕБОВАНИЕ"),
            ("draft.json", "Составь проект ответа"),
        )
    )
    env = {
        **os.environ,
        "MOCK_PORT": str(port),
        "MOCK_LLM_ROUTES": routes,
        "PYTHONUNBUFFERED": "1",
    }
    proc = subprocess.Popen(
        [sys.executable, str(MOCK_SERVER)], env=env,
        stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
    )
    try:
        _wait_port(port)
        yield port
    finally:
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()


def _llm(port: int) -> LlmClient:
    return LlmClient(
        providers={"LOCAL": Provider(
            name="LOCAL", base_url=f"http://127.0.0.1:{port}/v1", model="mock-llm",
        )},
        default_provider="LOCAL",
        timeout=30.0,
    )


def test_normalize_posts_text_from_demo_pdf(monkeypatch):
    pdf = DEMO / "01_claim_romashka.pdf"
    sent = {}

    async def fake_download(file_id, dest):
        shutil.copyfile(pdf, dest)

    async def fake_get(prefix, path, **kwargs):
        return True, "ok", {"files": [{"file_id": 7, "filename": pdf.name}]}

    async def fake_post(prefix, path, body, **kwargs):
        sent.update(body)
        sent["_path"] = path
        return True, "ok", {"saved": True}

    monkeypatch.setattr(doc_normalize, "_download", fake_download)
    monkeypatch.setattr(doc_normalize.site, "get_json", fake_get)
    monkeypatch.setattr(doc_normalize.site, "post_json", fake_post)

    result = asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))

    assert result["file_id"] == 7
    assert result["engine"] == "pypdf"
    assert result["chars"] > 300
    assert sent["_path"] == "/text/12"
    assert "ПРЕТЕНЗИЯ" in sent["text"]
    assert sent["file_id"] == 7


def test_analyze_against_mock_llm_routes(mock_llm, monkeypatch):
    posted = {}

    async def fake_get(prefix, path, **kwargs):
        return True, "ok", {
            "model": "local::mock-llm",
            "messages": [{"role": "user", "content": "ПРЕТЕНЗИЯ ООО «Ромашка»"}],
        }

    async def fake_post(prefix, path, body, **kwargs):
        posted.update(body)
        posted["_path"] = path
        return True, "ok", {"saved": True}

    monkeypatch.setattr(doc_analyze.site, "get_json", fake_get)
    monkeypatch.setattr(doc_analyze.site, "post_json", fake_post)

    client = _llm(mock_llm)
    result = asyncio.run(doc_analyze.handle(Ctx(client), {"run_id": 3}))
    asyncio.run(client.close())

    assert posted["_path"] == "/result/3"
    assert result["type"] == "claim"
    assert posted["result"]["type"] == "claim"
    assert posted["result"]["fields"]["amount"]["value"]
    assert posted["tokens"] > 0
    assert result["duration_ms"] >= 0


def test_draft_against_mock_llm_routes(mock_llm, monkeypatch):
    posted = {}

    async def fake_get(prefix, path, **kwargs):
        return True, "ok", {
            "model": "local::mock-llm",
            "messages": [{"role": "user", "content": "Составь проект ответа на претензию"}],
            "citations": [{"page": 1, "quote": "1 245 000,00"}],
        }

    async def fake_post(prefix, path, body, **kwargs):
        posted.update(body)
        posted["_path"] = path
        return True, "ok", {"saved": True}

    monkeypatch.setattr(doc_draft.site, "get_json", fake_get)
    monkeypatch.setattr(doc_draft.site, "post_json", fake_post)

    client = _llm(mock_llm)
    result = asyncio.run(doc_draft.handle(Ctx(client), {"draft_id": 4}))
    asyncio.run(client.close())

    expected = json.loads((DEMO / "mock" / "draft.json").read_text(encoding="utf-8"))
    assert posted["_path"] == "/result/4"
    assert posted["body"] == expected["body"]
    assert len(posted["body"]) >= 40
    assert posted["citations"][0]["page"] == 1
    assert result["chars"] == len(posted["body"])
