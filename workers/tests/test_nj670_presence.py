"""Пульс ProcessHub (nj670): конфиг из окружения и цикл отправки.

Зачем отдельно от тестов каналов: жалоба «воркер настроен и перезапущен, а
сайт пишет, что он не в сети» — это про признак жизни, а не про обработчик.
Проверяем ровно то, что ломается тихо: адрес приёмной, порядок поиска токена,
выключение пульса, поведение при 401/403 и остановку цикла.

    python -m pytest tests/test_nj670_presence.py
"""

from __future__ import annotations

import asyncio
import sys
import types
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

if "n2j_workers" not in sys.modules:
    package = types.ModuleType("n2j_workers")
    package.__path__ = [str(ROOT / "n2j_workers")]
    sys.modules["n2j_workers"] = package

from n2j_workers.presence import (  # noqa: E402
    PresenceConfig,
    config_from_env,
    normalize_base,
    ping_once,
    presence_loop,
)


class FakeResponse:
    def __init__(self, status_code: int = 200):
        self.status_code = status_code


class FakeClient:
    """Поддельный httpx-клиент: запоминает запросы, отвечает как сайт."""

    def __init__(self, status_code: int = 200, fail: Exception | None = None):
        self.status_code = status_code
        self.fail = fail
        self.calls: list[dict] = []
        self.closed = False

    async def post(self, url: str, **kwargs):
        if self.fail is not None:
            raise self.fail

        self.calls.append({"url": url, **kwargs})

        return FakeResponse(self.status_code)

    async def aclose(self) -> None:
        self.closed = True


def test_normalize_base_adds_endpoint_suffix():
    assert normalize_base("https://n2j.ru") == "https://n2j.ru/process/w"
    assert normalize_base("https://n2j.ru/") == "https://n2j.ru/process/w"
    assert normalize_base("https://n2j.ru/process/w") == "https://n2j.ru/process/w"
    assert normalize_base("https://n2j.ru/process/w/") == "https://n2j.ru/process/w"
    assert normalize_base("  ") == ""


def test_config_falls_back_to_queue_site_url():
    # http-режим очереди уже знает сайт: отдельная строка для пульса не нужна.
    env = {
        "NJ120_API_URL": "https://n2j.ru/nj120/api",
        "WORKER_API_TOKEN": "api",
    }
    config = config_from_env("w1", [], env=env)

    assert config is not None
    assert config.url == "https://n2j.ru/process/w/ping"
    assert config.token == "api"

    # Явный адрес главнее и не переписывается.
    config = config_from_env("w1", [], env={**env, "NJ670_API_BASE": "https://other.test/process/w"})
    assert config.url == "https://other.test/process/w/ping"


def test_config_requires_base_and_token():
    assert config_from_env("w1", [], env={}) is None
    assert config_from_env("w1", [], env={"NJ670_API_BASE": "https://n2j.ru"}) is None

    config = config_from_env(
        "w1", ["nj670.synthesize"], env={
            "NJ670_API_BASE": "https://n2j.ru",
            "NJ670_SITE_TOKEN": "secret",
        })

    assert config is not None
    assert config.base == "https://n2j.ru/process/w"
    assert config.url == "https://n2j.ru/process/w/ping"
    assert config.channels == ["nj670.synthesize"]


def test_config_token_fallback_order():
    env = {
        "NJ670_API_BASE": "https://n2j.ru/process/w",
        "WORKER_SITE_TOKEN": "site",
        "WORKER_API_TOKEN": "api",
    }
    assert config_from_env("w1", [], env=env).token == "site"

    env.pop("WORKER_SITE_TOKEN")
    assert config_from_env("w1", [], env=env).token == "api"


def test_config_can_be_disabled_and_interval_is_clamped():
    env = {
        "NJ670_API_BASE": "https://n2j.ru",
        "NJ670_SITE_TOKEN": "secret",
        "NJ670_PING_INTERVAL": "1",
    }
    assert config_from_env("w1", [], env={**env, "NJ670_PING": "0"}) is None
    assert config_from_env("w1", [], env=env).interval >= 5.0
    assert config_from_env("w1", [], env={**env, "NJ670_PING_INTERVAL": "abc"}).interval == 60.0


def test_ping_payload_and_headers():
    config = PresenceConfig(
        base="https://n2j.ru/process/w", token="secret", worker="w1",
        channels=["nj670.synthesize"], version="test-1",
    )
    client = FakeClient()

    assert asyncio.run(ping_once(config, client)) is True
    assert len(client.calls) == 1

    call = client.calls[0]
    assert call["url"] == "https://n2j.ru/process/w/ping"
    assert call["json"] == {
        "worker": "w1", "version": "test-1", "channels": ["nj670.synthesize"],
    }
    assert call["headers"]["X-Worker-Token"] == "secret"


def test_ping_survives_bad_token_and_network_errors():
    config = PresenceConfig(base="https://n2j.ru/process/w", token="bad", worker="w1")

    assert asyncio.run(ping_once(config, FakeClient(status_code=403))) is False
    assert asyncio.run(ping_once(config, FakeClient(fail=OSError("no route")))) is False


def test_presence_loop_stops_on_event():
    config = PresenceConfig(
        base="https://n2j.ru/process/w", token="secret", worker="w1", interval=5.0,
    )
    client = FakeClient()
    stop = asyncio.Event()

    async def scenario():
        task = asyncio.create_task(presence_loop(config, client=client, stop=stop))
        await asyncio.sleep(0.05)
        assert len(client.calls) == 1, "первый пульс уходит сразу, не через интервал"
        stop.set()
        await asyncio.wait_for(task, timeout=5.0)

    asyncio.run(scenario())
    assert client.closed is False, "чужой клиент цикл не закрывает"
