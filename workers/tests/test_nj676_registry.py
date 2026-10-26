"""Тесты импорта официального реестра MCP (nj676.registry).

Проверяем только чистые функции разбора: сама сеть и запись в БД — на стенде
сайта. Записи реестра намеренно «грязные»: чего только не присылают в поля.
"""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from n2j_workers.handlers import nj676_registry as reg  # noqa: E402


def _entry(**server) -> dict:
    base = {
        "name": "io.github.owner/cool-server",
        "description": "Searches the web and returns pages.",
        "version": "1.2.3",
        "repository": {"url": "https://github.com/owner/cool-server", "source": "github"},
    }
    base.update(server)
    return {"server": base, "_meta": {}}


def test_v01_envelope_is_unwrapped():
    item = reg._pick(_entry(title="Cool Server"))

    assert item is not None
    assert item["registry_name"] == "io.github.owner/cool-server"
    assert item["name"] == "Cool Server"
    assert item["repo_url"] == "https://github.com/owner/cool-server"
    assert item["version"] == "1.2.3"
    assert item["transport"] == "stdio" or item["transport"] == "unknown"


def test_flat_v0_entry_also_works():
    item = reg._pick({"name": "io.github.a/b", "description": "x", "version": "1.0.0"})

    assert item is not None
    assert item["registry_name"] == "io.github.a/b"
    assert item["categories"]


def test_remote_preferred_and_typed():
    item = reg._pick(_entry(remotes=[
        {"type": "sse", "url": "http://insecure.example/sse"},
        {"type": "streamable-http", "url": "https://api.example.com/mcp"},
    ]))

    assert item["remote_url"] == "https://api.example.com/mcp"
    assert item["transport"] == "streamable_http"


def test_packages_and_env_vars():
    item = reg._pick(_entry(packages=[{
        "registryType": "npm",
        "identifier": "@scope/server",
        "version": "2.0.1",
        "environmentVariables": [{"name": "API_KEY"}],
    }]))

    assert item["packages"][0]["manager"] == "npm"
    assert item["packages"][0]["identifier"] == "@scope/server"
    assert item["env_keys"] == ["API_KEY"]
    assert item["auth_mode"] == "api_key"
    assert item["transport"] == "stdio"


def test_no_auth_when_remote_without_keys():
    item = reg._pick(_entry(remotes=[{"type": "streamable-http", "url": "https://x.example/mcp"}]))

    assert item["auth_mode"] == "none"


def test_categories_from_description():
    assert "data" in reg._categories("x", "", "PostgreSQL and MySQL access")
    assert "research" in reg._categories("x", "", "Weather forecast API")


def test_junk_is_rejected():
    assert reg._pick(None) is None
    assert reg._pick({}) is None
    assert reg._pick({"server": {"description": "no name"}}) is None


def test_description_fallback_is_russian():
    item = reg._pick({"server": {"name": "io.github.x/y", "description": ""}})

    assert "MCP-сервер" in item["description"]


def test_registry_base_env(monkeypatch):
    monkeypatch.setenv("NJ676_REGISTRY_URL", "https://staging.example/")

    assert reg._registry_base() == "https://staging.example"


def test_channel_name_matches_manifest():
    assert reg.CHANNEL == "nj676.registry"


def test_handle_probe_runs_concurrently_and_reports_finish(monkeypatch):
    import asyncio

    monkeypatch.setenv("NJ676_API_BASE", "https://n2j.ru/mcphub/api")
    monkeypatch.setenv("NJ676_PROBE_CONCURRENCY", "4")

    posted = []

    async def fake_probe(url: str):
        await asyncio.sleep(0.01)
        ok = "good" in url
        return ok, 42, "tools/list: 1" if ok else "timeout", ([{"name": "ping"}] if ok else [])

    async def fake_post(base: str, path: str, payload: dict):
        posted.append((path, payload))
        return True, "", {"ok": True}

    class DummyCtx:
        async def heartbeat(self):
            return None

    monkeypatch.setattr(reg, "_probe_remote", fake_probe)
    monkeypatch.setattr(reg, "_site_post", fake_post)

    res = asyncio.run(reg.handle_probe(DummyCtx(), {
        "run_id": 99,
        "targets": [
            {"slug": "srv-1", "url": "https://good1.example/mcp"},
            {"slug": "srv-2", "url": "https://bad1.example/mcp"},
            {"slug": "srv-3", "url": "https://good2.example/mcp"},
        ],
    }))

    assert res["checked"] == 3
    assert res["up"] == 2
    assert res["down"] == 1
    assert any(p == "/discover-finish" and d["run_id"] == 99 for p, d in posted)


def test_parse_awesome_remote_punkpeye():
    md = (
        "### 🗄️ <a name=\"databases\"></a>Databases\n"
        "- [7Maps](https://7it.co.il/7maps/) `https://7it.co.il/7maps/mcp`\n"
        "  [![7Maps MCP connector](https://glama.ai/mcp/connectors/io.github.XLSV777/7maps/badges/score.svg)](https://glama.ai/mcp/connectors/io.github.XLSV777/7maps)\n"
        "  🔓 - Daily map of 22,000+ public MCP servers.\n"
        "- [AIsa](https://aisa.one) `https://mcp.aisa.one/mcp`\n"
        "  🔐 - One key for 950+ APIs.\n"
    )
    items = reg._parse_awesome_remote_punkpeye(md)
    assert len(items) == 2
    assert items[0]["registry_name"] == "io.github.XLSV777/7maps"
    assert items[0]["remote_url"] == "https://7it.co.il/7maps/mcp"
    assert items[0]["auth_mode"] == "none"
    assert items[0]["transport"] == "streamable_http"
    assert "data" in items[0]["categories"]
    assert items[1]["auth_mode"] == "oauth"


def test_parse_awesome_remote_jaw9c():
    md = (
        "| Name | Category | URL | Authentication | Maintainer |\n"
        "|------|----------|-----|----------------|------------|\n"
        "| AWS Knowledge | Software Development | `https://knowledge-mcp.global.api.aws` | Open | [AWS](https://aws.github.io/) |\n"
        "| Asana | Project Management | `https://mcp.asana.com/sse` | OAuth2.1 | [Asana](https://asana.com) |\n"
    )
    items = reg._parse_awesome_remote_jaw9c(md)
    assert len(items) == 2
    assert items[0]["name"] == "AWS Knowledge"
    assert items[0]["auth_mode"] == "none"
    assert items[0]["transport"] == "streamable_http"
    assert items[1]["transport"] == "sse"
    assert items[1]["auth_mode"] == "oauth"


def test_pick_npm_package():
    item = reg._pick_npm({
        "package": {
            "name": "@acme/postgres-mcp",
            "version": "1.4.0",
            "description": "PostgreSQL database MCP server",
            "links": {
                "repository": "git+https://github.com/acme/postgres-mcp.git",
                "homepage": "https://github.com/acme/postgres-mcp#readme",
            },
        }
    })
    assert item is not None
    assert item["registry_name"] == "npm/@acme/postgres-mcp"
    assert item["repo_url"] == "https://github.com/acme/postgres-mcp"
    assert item["packages"][0]["identifier"] == "@acme/postgres-mcp"
    assert item["packages"][0]["version"] == "1.4.0"
    assert "data" in item["categories"]


