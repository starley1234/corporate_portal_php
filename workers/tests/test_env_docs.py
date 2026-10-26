"""Примеры .env обязаны описывать переменные, которые код реально читает.

Повод (28.09.2026, задача 60): задача semantic.index упала с сообщением
«Векторы не дошли до сайта … проверьте NJ115_API_BASE (или общий NJ120_API_BASE)
и WORKER_SITE_TOKEN в workers/.env», а владелец справедливо ответил: «в примерах
нет этих переменных». Настроить то, чего нет в примере, невозможно — и владелец
ищет причину по монитору вместо того, чтобы поправить одну строку.

Тест держит соответствие: каждый префикс, которым обработчик ходит на сайт
(site.post_json/site.get_json), должен быть описан в workers/.env.example —
адрес API модуля и токен. Плюс отдельно проверяются общие переменные доставки
(NJ120_API_BASE, WORKER_SITE_TOKEN) и то, что путь моста векторов не удваивается.
"""
from pathlib import Path
import re

import pytest

from n2j_workers import site
from n2j_workers.handlers import semantic_index

WORKERS = Path(__file__).resolve().parents[1]          # …/n2j.ru/workers
ENV_EXAMPLE = WORKERS / ".env.example"
HANDLERS = WORKERS / "n2j_workers" / "handlers"


def prefixes_used_by_handlers() -> dict[str, set[str]]:
    """{префикс: файлы} — все каналы, которые ходят на сайт через site.py."""
    used: dict[str, set[str]] = {}

    for path in sorted(HANDLERS.glob("*.py")):
        src = path.read_text(encoding="utf-8")
        names = set(re.findall(r'site\.(?:post_json|get_json)\(\s*"([A-Z0-9_]+)"', src))

        if names:
            names |= set(re.findall(r'_PREFIX\s*=\s*"([A-Z0-9_]+)"', src))

        for name in names:
            used.setdefault(name, set()).add(path.name)

    return used


def documented(prefix: str) -> bool:
    """Есть ли в примере строка <PREFIX>_API_BASE (можно закомментированная)."""
    text = ENV_EXAMPLE.read_text(encoding="utf-8")
    return re.search(r"^#?" + re.escape(prefix) + r"_API_BASE=", text, re.M) is not None


def test_every_channel_prefix_is_documented():
    """Новый канал, ходящий на сайт, обязан появиться в workers/.env.example."""
    used = prefixes_used_by_handlers()
    missing = sorted(p for p in used if not documented(p))

    assert not missing, (
        "эти каналы ходят на сайт, но их адрес не описан в workers/.env.example: "
        + ", ".join(f"{p} ({', '.join(sorted(used[p]))})" for p in missing)
    )


def test_shared_delivery_variables_are_documented():
    """Общие переменные доставки — именно те имена, что читает site.py."""
    text = ENV_EXAMPLE.read_text(encoding="utf-8")

    assert re.search(r"^WORKER_SITE_TOKEN=", text, re.M), (
        "нет WORKER_SITE_TOKEN — в режиме pg сам WORKER_API_TOKEN у воркера пуст, "
        "без этой переменной доставка результатов не настроится"
    )
    assert re.search(r"^NJ120_API_BASE=", text, re.M), (
        "нет NJ120_API_BASE — это общий запасной адрес сайта для каналов "
        "без своего NJxxx_API_BASE"
    )


def test_nj115_bridge_section_is_documented():
    """Ту ошибку, из-за которой это всё писалось, должно быть чем исправить."""
    text = ENV_EXAMPLE.read_text(encoding="utf-8")

    assert re.search(r"^NJ115_API_BASE=", text, re.M)
    assert re.search(r"^NJ115_SITE_TOKEN=", text, re.M)


@pytest.mark.parametrize("base,path", [
    ("https://n2j.ru/nj115/api", "/vectors"),          # адрес API модуля — как у других каналов
    ("https://n2j.ru", "/nj115/api/vectors"),          # корень сайта — тоже естественный вариант
    ("https://n2j.ru/nj115", "/api/vectors"),
    ("", "/nj115/api/vectors"),                        # не настроено — прежнее поведение
])
def test_vectors_path_is_not_doubled(base, path):
    """База может быть и модульной, и корнем сайта: путь не должен удваиваться."""
    assert semantic_index._vectors_path(base) == path


def test_settings_hint_lists_what_is_missing(monkeypatch):
    """Подсказка в ошибке называет ровно то, чего не хватает в окружении."""
    for name in ("NJ115_API_BASE", "NJ120_API_BASE", "NJ115_SITE_TOKEN",
                 "WORKER_SITE_TOKEN", "WORKER_API_TOKEN"):
        monkeypatch.delenv(name, raising=False)

    hint = semantic_index._settings_hint()

    assert "NJ115_API_BASE" in hint and "NJ115_SITE_TOKEN" in hint
    assert "workers/.env" in hint


def test_settings_hint_says_config_is_fine_when_it_is(monkeypatch):
    """Если адрес и токен заданы, подсказка не советует их искать."""
    monkeypatch.setenv("NJ115_API_BASE", "https://n2j.ru/nj115/api")
    monkeypatch.setenv("NJ115_SITE_TOKEN", "token")

    hint = semantic_index._settings_hint()

    assert "Не задано" not in hint
    assert "размерность" in hint


def test_origin_of_keeps_only_the_site_root():
    """Из адреса API очереди получаем корень сайта — без пути /nj120/api."""
    assert site.origin_of("https://n2j.ru/nj120/api") == "https://n2j.ru"
    assert site.origin_of("https://n2j.ru/nj120/api/") == "https://n2j.ru"
    assert site.origin_of("http://127.0.0.1/nj120/api") == "http://127.0.0.1"
    assert site.origin_of("") == ""
    assert site.origin_of("не-адрес") == ""


@pytest.mark.parametrize("env,expected", [
    ({"NJ115_API_BASE": "https://n2j.ru/nj115/api"}, "https://n2j.ru/nj115/api"),
    ({"NJ120_API_BASE": "https://n2j.ru"}, "https://n2j.ru"),
    ({"NJ120_API_URL": "https://n2j.ru/nj120/api"}, "https://n2j.ru"),   # адрес уже был задан
    ({}, ""),
])
def test_site_base_resolution_order(monkeypatch, env, expected):
    """Порядок разрешения адреса: свой → общий → корень из NJ120_API_URL."""
    for name in ("NJ115_API_BASE", "NJ120_API_BASE", "NJ120_API_URL"):
        monkeypatch.delenv(name, raising=False)

    for name, value in env.items():
        monkeypatch.setenv(name, value)

    assert semantic_index._site_base() == expected


@pytest.mark.asyncio
async def test_http_mode_worker_without_new_variables_still_delivers(monkeypatch):
    """Рабочий случай http-режима: задан только адрес очереди — доставка идёт.

    Владелец (задача 60): задача падала с «Векторы не дошли до сайта …
    проверьте NJ115_API_BASE и WORKER_SITE_TOKEN», хотя воркер в этом режиме уже
    знает и сайт (NJ120_API_URL), и токен (WORKER_API_TOKEN = токен сайта).
    """
    from tests.test_semantic_index import _Ctx, _Embed, _payload

    for name in ("NJ115_API_BASE", "NJ120_API_BASE", "NJ115_SITE_TOKEN", "WORKER_SITE_TOKEN"):
        monkeypatch.delenv(name, raising=False)

    monkeypatch.setenv("NJ120_API_URL", "https://n2j.ru/nj120/api")
    monkeypatch.setenv("WORKER_API_TOKEN", "site-token")
    monkeypatch.setenv("WORKER_QUEUE_MODE", "http")

    calls = []

    async def _fake_post(prefix, path, data, timeout=60.0, retries=2, json_body=False):
        calls.append((prefix, path, data))
        return True, "", {"ok": True, "stored": len(data["items"])}

    monkeypatch.setattr(semantic_index.site, "post_json", _fake_post)

    out = await semantic_index.handle(_Ctx(None, _Embed()), _payload(2))

    assert out["mode"] == "http-bridge" and out["count"] == 2
    assert calls[0][1] == "/nj115/api/vectors", "корень сайта + полный путь, без удвоения"


@pytest.mark.parametrize("env,expected", [
    ({"NJ661_AIRADAR_API_BASE": "https://other.example/airadar/api"},
     "https://other.example/airadar/api"),            # свой адрес — канал на другом сайте
    ({"NJ120_API_BASE": "https://n2j.ru"}, "https://n2j.ru/airadar/api"),
    ({"NJ120_API_URL": "https://n2j.ru/nj120/api"}, "https://n2j.ru/airadar/api"),
    ({}, ""),                                          # не настроено — честная ошибка
])
def test_module_api_base_resolution_order(monkeypatch, env, expected):
    """Модульный канал: свой NJxxx_API_BASE → общий корень + путь модуля.

    Один общий адрес сайта обслуживает и каналы с короткими путями
    (замечание владельца 06.10.2026 «не раздувать .env»): воркер сам
    подставляет путь модуля — /airadar/api.
    """
    for name in ("NJ661_AIRADAR_API_BASE", "NJ120_API_BASE", "NJ120_API_URL"):
        monkeypatch.delenv(name, raising=False)

    for name, value in env.items():
        monkeypatch.setenv(name, value)

    assert site.module_api_base("NJ661_AIRADAR", "/airadar/api") == expected


def test_nj661_block_documents_the_common_fallback():
    """Блок nj661 в примере объясняет: адрес и токен — из общих переменных."""
    text = ENV_EXAMPLE.read_text(encoding="utf-8")
    block = text.split("nj661_airadar:", 1)[1].split("\n# ---", 1)[0]

    assert re.search(r"^#NJ661_AIRADAR_API_BASE=", text, re.M), (
        "строка NJ661_AIRADAR_API_BASE должна остаться (пусть закомментированной): "
        "подсказка ошибки зовёт переменную по имени"
    )
    assert "NJ120_API_BASE" in block and "WORKER_SITE_TOKEN" in block, (
        "блок nj661 должен объяснять, что адрес и токен по умолчанию берутся "
        "из общих NJ120_API_BASE и WORKER_SITE_TOKEN"
    )
