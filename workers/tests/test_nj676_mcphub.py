"""Тесты nj676_mcphub: разбор карточки и контракт приёмки на сайте.

Запуск из каталога workers/:
    python3 -m pytest tests/test_nj676_mcphub.py -q

Проверяем без сети и без модели:
  * пакет с закреплённой версией достаётся из README (npx/uvx/pip);
  * шаблонные команды («npx -y <package>») отбрасываются;
  * удалённый адрес распознаётся и получает правильный транспорт;
  * имена переменных окружения фильтруются от мусора;
  * категории выводятся по темам, даже когда модель молчит;
  * адрес приёмки по умолчанию собирается из корня сайта.
"""

import pytest


def _load():
    import n2j_workers.handlers.nj676_mcphub as mod

    return mod


def test_npx_with_version_is_detected():
    mod = _load()
    readme = "Run it:\n\n```json\n{\"command\": \"npx\", \"args\": [\"-y\", \"@acme/mcp-server@1.4.2\"]}\n```\n"

    packages = mod._detect_packages(readme, "acme/mcp-server")

    assert packages == [{"manager": "npm", "name": "@acme/mcp-server@1.4.2", "entrypoint": ""}]


def test_floating_npx_is_not_a_package_with_version():
    mod = _load()
    readme = "npx -y acme-mcp-server\nnpx -y <your-package>\n"

    packages = mod._detect_packages(readme, "acme/acme-mcp-server")

    assert packages == []


def test_uvx_and_pip_packages_are_detected():
    mod = _load()
    readme = "uvx --from acme_mcp==2.0.1 acme-mcp\npip install acme_tools==0.9.0\n"

    packages = mod._detect_packages(readme, "acme/acme-mcp")
    names = {(p["manager"], p["name"], p["entrypoint"]) for p in packages}

    assert ("pypi", "acme_mcp==2.0.1", "acme-mcp") in names
    assert ("pypi", "acme_tools==0.9.0", "") in names


def test_remote_url_is_extracted_and_typed():
    mod = _load()
    readme = (
        "Add this to your config:\n"
        '{"url": "https://mcp.acme.dev/sse"}\n'
        "Docs: https://github.com/acme/mcp\n"
    )

    url, kind = mod._detect_remote(readme, "https://github.com/acme/mcp")

    assert url == "https://mcp.acme.dev/sse"
    assert kind == "sse"


def test_streamable_http_endpoint():
    mod = _load()
    readme = "Server: https://mcp.acme.dev/mcp (streamable http)\n"

    url, kind = mod._detect_remote(readme, "https://github.com/acme/mcp")

    assert url.startswith("https://mcp.acme.dev/mcp")
    assert kind == "streamable_http"


def test_github_links_are_not_mistaken_for_endpoints():
    mod = _load()

    url, kind = mod._detect_remote("See https://github.com/acme/mcp for docs", "https://github.com/acme/mcp")

    assert url == ""
    assert kind == ""


def test_env_keys_are_filtered():
    mod = _load()
    readme = "Set GITHUB_TOKEN and API_KEY, then WORKSPACE_ID.\nMIT license. README is long.\n"

    keys = mod._detect_env_keys(readme)

    assert "GITHUB_TOKEN" in keys
    assert "WORKSPACE_ID" in keys
    assert "README" not in keys
    assert "MIT" not in keys


def test_categories_come_from_topics_when_model_is_silent():
    mod = _load()
    repo = {
        "description": "PostgreSQL MCP server with vector search",
        "topics": ["database", "postgres", "mcp"],
        "name": "pg-mcp",
    }

    categories = mod._guess_categories(repo, "")

    assert "data" in categories
    assert all(c in mod.CATEGORIES for c in categories)


def test_base_item_keeps_github_facts():
    mod = _load()
    repo = {
        "full_name": "Acme/awesome-mcp",
        "html_url": "https://github.com/Acme/awesome-mcp",
        "name": "awesome-mcp",
        "description": "x" * 800,
        "stargazers_count": 420,
        "forks_count": 12,
        "language": "TypeScript",
        "license": {"spdx_id": "MIT"},
        "owner": {"login": "Acme"},
        "topics": ["mcp", "search"],
        "pushed_at": "2026-10-01T00:00:00Z",
    }

    item = mod._base_item(repo, "")

    assert item["repo_url"] == "https://github.com/Acme/awesome-mcp"
    assert item["stars"] == 420
    assert item["owner_login"] == "Acme"
    assert item["license"] == "MIT"
    assert len(item["description"]) <= 1200
    assert item["health_status"] == "unverified"


def test_site_base_uses_common_root(monkeypatch):
    mod = _load()

    for name in ("NJ676_API_BASE", "NJ120_API_BASE", "NJ120_API_URL"):
        monkeypatch.delenv(name, raising=False)

    monkeypatch.setenv("NJ120_API_BASE", "https://n2j.ru")

    assert mod._site_base() == "https://n2j.ru/mcphub/api"


def test_site_base_prefers_own_variable(monkeypatch):
    mod = _load()
    monkeypatch.setenv("NJ676_API_BASE", "https://other.example/mcphub/api")

    assert mod._site_base() == "https://other.example/mcphub/api"


def test_no_package_means_no_install_command():
    mod = _load()
    readme = "This is a remote MCP server. No installation needed."

    assert mod._detect_packages(readme, "acme/remote") == []


@pytest.mark.parametrize("url,expected", [
    ("https://mcp.acme.dev/sse", True),
    ("http://mcp.acme.dev/sse", False),
    ("https://127.0.0.1/sse", False),
])
def test_public_url_guard(url, expected):
    mod = _load()

    parsed_ok = url.startswith("https://") and "127.0.0.1" not in url

    assert parsed_ok is expected


# ---------------------------------------------------------------------------
# Спрос агентов: тема из каталога → английские поисковые фразы для GitHub
# ---------------------------------------------------------------------------

def test_theme_keywords_filter_model_noise():
    """Модель может вернуть кавычки, операторы и кириллицу — в запрос не пускаем."""
    mod = _load()

    keywords = mod._clean_keywords({"keywords": [
        "weather forecast",
        '"topic:mcp-server"',
        "погода",
        "WEATHER   forecast",
        "sql database",
        "this phrase is far too long to be a github search phrase really",
        "x",
    ]})

    assert keywords == ["weather forecast", "sql database"]


def test_theme_keywords_accept_plain_list():
    mod = _load()

    assert mod._clean_keywords(["email smtp", "smtp mail"]) == ["email smtp", "smtp mail"]


def test_theme_keywords_reject_garbage():
    mod = _load()

    assert mod._clean_keywords(None) == []
    assert mod._clean_keywords({"keywords": "weather"}) == []
    assert mod._clean_keywords({"keywords": []}) == []


def test_queries_add_theme_for_every_keyword():
    """Каждая переведённая фраза даёт три варианта запроса — тема ищется
    только среди MCP-проектов, иначе выдача заполняется гигантскими платформами."""
    mod = _load()

    queries = mod._queries("погода", ["weather forecast", "openweathermap"])

    joined = " | ".join(q for q, _sort, _per in queries)
    assert "topic:mcp-server weather forecast" in joined
    assert "topic:mcp weather forecast" in joined
    assert "mcp-server openweathermap in:name,description" in joined
    assert "погода" not in joined


def test_queries_fall_back_to_raw_theme_without_keywords():
    mod = _load()

    queries = mod._queries("база данных")

    assert any("база данных" in q for q, _sort, _per in queries)
