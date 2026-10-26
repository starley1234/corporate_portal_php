"""nj676.discover — автосборка каталога MCP-серверов (N2J /mcphub).

Что делает (наработки nj593_trends и nj661_airadar: GitHub API + «утка»
DuckDuckGo + webread + один LLM-вызов со строгой JSON-схемой + приёмка на сайте):

  1. GitHub: свежие и заметные репозитории по тегам mcp / mcp-server, плюс
     целевой поиск по тематике («базы данных», «финансы», …);
  2. «Утка»: поиск в интернете добирает проекты без тегов — из выдачи берём
     только ссылки на github.com;
  3. сайт говорит, что уже есть в каталоге (POST /mcphub/api/discover-state) —
     дубликаты не тратят модель;
  4. README: пакет npm/PyPI, удалённый адрес (SSE / streamable HTTP), имена
     переменных окружения, транспорт, авторизация;
  5. LLM: русское описание, категории, «что умеет» и «кому пригодится»;
     без модели всё равно работает — описание берётся из GitHub, категории
     выводятся по темам;
  6. живая проверка удалённых серверов: initialize + tools/list, замер задержки;
  7. каждая карточка уходит на сайт (POST /mcphub/api/discover-item), прогресс —
     /discover-progress, финал — /discover-finish.

Env: адрес и токен по умолчанию — общие NJ120_API_BASE и WORKER_SITE_TOKEN
(или свои NJ676_API_BASE / NJ676_SITE_TOKEN). Модель — NJ676_MODEL
(«провайдер::модель»), пусто = модель воркера. NJ676_GITHUB_TOKEN — 5000
запросов/час вместо 60 (можно переиспользовать NJ593_GITHUB_TOKEN).
NJ676_MIN_STARS (10), NJ676_MAX_ITEMS (30), NJ676_README_CAP (4000),
NJ676_LLM_BATCH (5), NJ676_PROBE (1 — проверять удалённые адреса),
NJ676_PROBE_TIMEOUT (8), NJ676_DDG (1 — добирать поиском).
"""

from __future__ import annotations

import asyncio
import json
import logging
import math
import os
import re
import time
from urllib.parse import urlparse

import httpx

from .. import site, webread
from ..llmchat import chat_with_usage as _llm

log = logging.getLogger("n2j.nj676")

CHANNEL = "nj676.discover"
_PREFIX = "NJ676"
GH_API = "https://api.github.com"

# Категории каталога (должны совпадать с Service_Nj676_McpHub::CATEGORIES).
CATEGORIES = [
    "development", "web", "data", "ai", "files", "productivity", "cloud",
    "communication", "finance", "security", "media", "research", "other",
]

# Подсказки категорий по темам GitHub и словам описания.
_CATEGORY_HINTS = {
    "development": ("git", "github", "gitlab", "code", "docker", "kubernetes", "devops",
                    "ide", "lint", "test", "build", "repository", "codegen"),
    "web": ("web", "search", "browser", "scrap", "crawl", "http", "fetch", "url", "seo", "playwright"),
    "data": ("database", "postgres", "mysql", "sqlite", "mongo", "sql", "vector", "etl",
             "bigquery", "snowflake", "duckdb", "redis", "kafka"),
    "ai": ("llm", "ai", "openai", "anthropic", "claude", "model", "embedding", "rag",
           "agent", "inference", "gemini", "mistral"),
    "files": ("file", "filesystem", "pdf", "docx", "office", "excel", "csv", "document", "storage"),
    "productivity": ("notion", "todo", "calendar", "task", "productivity", "notes", "asana",
                     "trello", "linear", "jira"),
    "cloud": ("aws", "azure", "gcp", "cloudflare", "kubernetes", "docker", "terraform",
              "serverless", "cloud"),
    "communication": ("email", "mail", "gmail", "smtp", "imap", "slack", "telegram", "discord",
                      "whatsapp", "teams", "messenger"),
    "finance": ("finance", "payment", "stripe", "bank", "invoice", "crypto", "trading", "accounting"),
    "security": ("security", "vulnerability", "cve", "audit", "secret", "pentest", "malware"),
    "media": ("image", "video", "audio", "youtube", "music", "photo", "media", "transcri"),
    "research": ("research", "arxiv", "paper", "scholar", "science", "wikipedia", "knowledge"),
}

_ENV_NOISE = {
    "MCP", "API", "URL", "HTTP", "HTTPS", "JSON", "README", "TODO", "MIT", "ISC", "GPL",
    "CLI", "SDK", "LLM", "AI", "ID", "PATH", "HOME", "NODE", "PYTHON", "DOCKER", "GET",
    "POST", "PUT", "DELETE", "TRUE", "FALSE", "NULL", "UTF", "CPU", "GPU", "OS", "APP",
}

SYSTEM_CURATOR = (
    "Ты — редактор каталога MCP-серверов для сайта N2J.ru. Тебе дают метаданные "
    "репозиториев и начало README. Задача: по-русски коротко и по делу объяснить, "
    "что делает сервер и кому он нужен. Только факты из присланных данных, без "
    "выдумок и рекламы. Отвечай СТРОГО одним JSON-объектом без markdown."
)

_PROMPT = (
    "Для каждого проекта верни: slug (owner/repo как пришёл), "
    "name (название проекта как у автора, латиницей), "
    "name_ru (русское название для каталога: 2–6 слов, по делу, без кавычек и "
    "рекламы; бренд и аббревиатуры можно оставить латиницей, например "
    "«MCP-сервер Playwright»), description (2 предложения по-русски: что делает "
    "и зачем), categories (1–3 из списка: {cats}), auth (none|api_key|oauth|unknown), "
    "highlights (до 3 коротких фактов по-русски: что именно умеет), use_cases (до 2 по-русски: кому "
    "пригодится), tags (до 5 английских тегов), quality (0–100: насколько "
    "сервер зрелый и полезный). Если проект — не MCP-сервер или это форк/учебная "
    "поделка, поставь quality 0 и description «не MCP-сервер». "
    "Формат: {{\"items\": [{{\"slug\": \"owner/repo\", \"name\": \"…\", "
    "\"name_ru\": \"…\", \"description\": \"…\", \"categories\": [\"development\"], \"auth\": \"unknown\", "
    "\"highlights\": [\"…\"], \"use_cases\": [\"…\"], \"tags\": [\"…\"], \"quality\": 70}}]}}"
)


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _env_int(name: str, default: int, low: int, high: int) -> int:
    try:
        return max(low, min(high, int(_env(name) or default)))
    except ValueError:
        return default


def _flag(name: str, default: bool = True) -> bool:
    value = _env(name).lower()
    if not value:
        return default
    return value not in ("0", "false", "no", "off")


def _site_base() -> str:
    return site.module_api_base(_PREFIX, "/mcphub/api")


def _github_token() -> str:
    for name in ("NJ676_GITHUB_TOKEN", "NJ593_GITHUB_TOKEN", "GITHUB_TOKEN"):
        value = _env(name)
        if value:
            return value
    return ""


def _gh_headers() -> dict:
    headers = {
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "n2j-mcphub-worker",
    }
    token = _github_token()
    if token:
        headers["Authorization"] = f"Bearer {token}"
    return headers


def _think_options() -> dict:
    """Отключить размышления рассуждающей модели: каталожные тексты короткие."""
    return {"reasoning_effort": "none", "chat_template_kwargs": {"enable_thinking": False}}


def _strip_think(text: str) -> str:
    return re.sub(r"<think>.*?</think>", "", text or "", flags=re.S | re.I).strip()


# ---------------------------------------------------------------------------
# Сайт
# ---------------------------------------------------------------------------

async def _site_post(base: str, path: str, data: dict) -> tuple[bool, str, dict]:
    return await site.post_json(_PREFIX, path, data, json_body=True, base=base, timeout=60)


async def _progress(ctx, base: str, run_id: int, phase: str, found: int = 0) -> None:
    try:
        await ctx.progress(phase, f"найдено: {found}" if found else "")
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    if run_id > 0:
        await _site_post(base, "/discover-progress", {"run_id": run_id, "phase": phase})


# ---------------------------------------------------------------------------
# GitHub
# ---------------------------------------------------------------------------

async def _gh_get(path: str, params: dict | None = None, timeout: float = 25.0) -> tuple[dict | list | None, str]:
    try:
        async def _call():
            async with httpx.AsyncClient(timeout=30, follow_redirects=False) as client:
                return await client.get(f"{GH_API}{path}", params=params or {}, headers=_gh_headers())

        response = await asyncio.wait_for(_call(), timeout=timeout)

        if response.status_code == 403 and "rate limit" in response.text.lower():
            return None, "GitHub: исчерпан лимит запросов (задайте NJ676_GITHUB_TOKEN)"

        if response.status_code == 404:
            return None, ""

        if response.status_code != 200:
            return None, f"GitHub HTTP {response.status_code}"

        return response.json(), ""
    except asyncio.TimeoutError:
        return None, f"GitHub не ответил за {timeout:.0f} с"
    except Exception as exc:  # noqa: BLE001
        return None, f"GitHub сеть: {exc.__class__.__name__}"


async def _gh_search(query: str, sort: str, per_page: int, timeout: float = 25.0) -> tuple[list[dict], str]:
    data, error = await _gh_get("/search/repositories", {
        "q": query,
        "sort": sort,
        "order": "desc",
        "per_page": max(1, min(100, per_page)),
    }, timeout)

    if not isinstance(data, dict):
        return [], error

    return list(data.get("items") or []), ""


def _clean_keywords(parsed) -> list[str]:
    """Ключевые слова из ответа модели: только короткие ASCII-фразы.

    GitHub ищет по английским словам. Русская тема («погода», «база данных»)
    без перевода даёт пустую выдачу: `topic:mcp-server погода` не находит
    ничего, и спрос агента остаётся без ответа. Поэтому тему переводим в 2–3
    поисковые фразы, а этот фильтр не даёт модели протащить мусор в запрос —
    кавычки, решётки, готовые `topic:`-операторы и кириллицу.
    """
    if isinstance(parsed, dict):
        raw = parsed.get("keywords")
    else:
        raw = parsed

    if not isinstance(raw, list):
        return []

    out: list[str] = []
    # Операторы GitHub-поиска в переводе темы не нужны: тему мы и так ищем
    # только среди MCP-проектов, а `topic:`/`in:name` из ответа модели может
    # незаметно сузить выдачу до нуля.
    operators = re.compile(
        r"(^|[\s])(topic|in|is|stars|forks|language|user|org|repo|size|pushed)\s*:"
    )

    for item in raw:
        phrase = str(item or "").strip().lower()
        if ":" in phrase or operators.search(phrase):
            continue
        phrase = re.sub(r"[^a-z0-9 +#.-]", " ", phrase)
        phrase = re.sub(r"\s+", " ", phrase).strip(" .-+")
        if not (2 <= len(phrase) <= 60):
            continue
        if not re.search(r"[a-z]", phrase):
            continue
        if phrase not in out:
            out.append(phrase)
        if len(out) >= 3:
            break

    return out


async def _theme_keywords(ctx, theme: str) -> list[str]:
    """Русская тема спроса → английские поисковые фразы (через модель).

    Без модели или при её ошибке возвращаем саму тему: лучше поискать как есть,
    чем не искать вовсе. Результат нигде не кэшируем — тема уникальна.
    """
    theme = (theme or "").strip()[:200]
    if not theme:
        return []
    if not re.search(r"[А-Яа-яЁё]", theme):
        # Тема уже латиницей (или это бренд) — переводить нечего.
        return [theme]

    prompt = (
        "Тема, которую агенты ищут в каталоге MCP-серверов: «" + theme + "». "
        "Верни СТРОГО JSON {\"keywords\": [\"…\", \"…\"]} — 2–3 короткие "
        "английские поисковые фразы (1–3 слова), которыми на GitHub ищут "
        "MCP-сервер под эту задачу. Например: погода → weather forecast, "
        "база данных → database sql, почта → email smtp. Без пояснений."
    )

    try:
        raw, _usage = await _llm(
            ctx,
            [{"role": "system", "content": "Ты переводчик поисковых запросов. Отвечай только JSON."},
             {"role": "user", "content": prompt}],
            "Уточняю тему для поиска",
            model_env="NJ676_MODEL",
            max_tokens=200,
            timeout=30,
            options=_think_options(),
        )
        keywords = _clean_keywords(webread.json_from_text(_strip_think(raw or "")))
        if keywords:
            log.info("nj676: тема «%s» → %s", theme, ", ".join(keywords))
            return keywords
    except Exception as exc:  # noqa: BLE001 — поиск важнее перевода
        log.info("nj676: тема без перевода (%s)", exc.__class__.__name__)

    return [theme]


def _queries(theme: str, keywords: list[str] | None = None) -> list[tuple[str, str, int]]:
    """(запрос, сортировка, сколько взять) — от общего к тематическому.

    `keywords` — уже переведённые фразы (см. _theme_keywords). Если их нет,
    работает старое поведение: тема как есть.
    """
    queries = [
        ("topic:mcp-server stars:>=15", "stars", 50),
        ("topic:model-context-protocol stars:>=15", "stars", 40),
        ("topic:mcp-servers stars:>=15", "stars", 40),
        ("topic:mcp stars:>=30", "stars", 50),
        ('"mcp server" in:name,description stars:>=20', "stars", 40),
        ('topic:mcp-server "streamable-http" stars:>=5', "stars", 30),
    ]

    phrases: list[str] = []
    for phrase in (keywords or []):
        cleaned = re.sub(r"[^A-Za-zА-Яа-яЁё0-9 ._+#-]", " ", str(phrase or "")).strip()
        cleaned = re.sub(r"\s+", " ", cleaned)[:120]
        if cleaned and cleaned not in phrases:
            phrases.append(cleaned)

    if not phrases and theme:
        cleaned = re.sub(r"[^A-Za-zА-Яа-яЁё0-9 ._-]", " ", theme).strip()
        cleaned = re.sub(r"\s+", " ", cleaned)[:120]
        if cleaned:
            phrases.append(cleaned)

    for cleaned in phrases:
        # Тема ищется ТОЛЬКО среди MCP-проектов: свободный поиск по README
        # приносит гигантские платформы, где MCP лишь упомянут.
        queries.append((f"topic:mcp-server {cleaned}", "stars", 40))
        queries.append((f"topic:model-context-protocol {cleaned}", "stars", 30))
        queries.append((f"topic:mcp {cleaned}", "stars", 40))
        queries.append((f"mcp-server {cleaned} in:name,description", "best-match", 30))

    return queries


async def _gh_readmes(full_names: list[str], cap: int, budget: float = 45.0) -> dict[str, str]:
    """README пачкой с общим дедлайном: 30 последовательных чтений вешали этап."""
    sem = asyncio.Semaphore(5)
    out: dict[str, str] = {}

    async def one(full_name: str) -> None:
        async with sem:
            try:
                async with httpx.AsyncClient(timeout=25, follow_redirects=True) as client:
                    response = await asyncio.wait_for(client.get(
                        f"{GH_API}/repos/{full_name}/readme",
                        headers={**_gh_headers(), "Accept": "application/vnd.github.raw+json"},
                    ), timeout=20)
                if response.status_code == 200 and response.text:
                    out[full_name.lower()] = response.text[:cap]
            except Exception:  # noqa: BLE001 — README не обязателен
                pass

    try:
        await asyncio.wait_for(asyncio.gather(*(one(name) for name in full_names)), timeout=budget)
    except asyncio.TimeoutError:
        log.info("nj676: README не успели за %.0f с — работаем с тем, что есть", budget)

    return out


# ---------------------------------------------------------------------------
# DuckDuckGo («утка»)
# ---------------------------------------------------------------------------

async def _ddg_repos(theme: str, limit: int = 8) -> list[str]:
    query = f"MCP server {theme} github" if theme else "awesome MCP servers github list"
    try:
        rows = await webread.ddg_text(query, max_results=limit, timeout_sec=20)
    except Exception as exc:  # noqa: BLE001 — поиск не критичен
        log.info("nj676: поиск не дал результатов: %s", exc.__class__.__name__)
        return []

    repos: list[str] = []
    for row in rows:
        url = str(row.get("href") or row.get("url") or row.get("link") or "")
        match = re.match(r"https?://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)", url)
        if match:
            repo = f"{match.group(1)}/{re.sub(r'[#?].*$', '', match.group(2))}"
            if repo.lower() not in (r.lower() for r in repos):
                repos.append(repo)
    return repos


async def _npm_repos(theme: str, limit: int = 12) -> list[str]:
    """Добор GitHub-репозиториев MCP-серверов через поиск registry.npmjs.org (keywords:mcp-server)."""
    q = f"keywords:mcp-server {theme}".strip() if theme else "keywords:mcp-server"
    try:
        async with httpx.AsyncClient(timeout=15, follow_redirects=True) as client:
            resp = await client.get(
                "https://registry.npmjs.org/-/v1/search",
                params={"text": q, "size": max(1, min(50, limit * 2))},
            )
            if resp.status_code != 200:
                return []
            objects = (resp.json() or {}).get("objects") or []
    except Exception as exc:  # noqa: BLE001
        log.info("nj676: поиск npm не дал результатов: %s", exc.__class__.__name__)
        return []

    repos: list[str] = []
    for obj in objects:
        pkg = (obj or {}).get("package") if isinstance(obj, dict) else None
        links = (pkg or {}).get("links") if isinstance(pkg, dict) else None
        repo_url = str((links or {}).get("repository") or "").strip()
        if repo_url.startswith("git+"):
            repo_url = repo_url[4:]
        if repo_url.endswith(".git"):
            repo_url = repo_url[:-4]
        match = re.match(r"https?://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)", repo_url)
        if match:
            repo = f"{match.group(1)}/{re.sub(r'[#?].*$', '', match.group(2))}"
            if repo.lower() not in (r.lower() for r in repos):
                repos.append(repo)
                if len(repos) >= limit:
                    break
    return repos


# ---------------------------------------------------------------------------
# Разбор карточки: README → пакет, адрес, ключи, транспорт
# ---------------------------------------------------------------------------

_RE_NPX = re.compile(r"npx\s+(?:-y\s+|--yes\s+)?(@?[a-z0-9][a-z0-9._/-]*)(@(\d+\.\d+\.\d+[^\s\"'`]*))?", re.I)
_RE_UVX = re.compile(r"uvx\s+(?:--from\s+)?([A-Za-z0-9][A-Za-z0-9._-]*)(?:==(\d+\.\d+\.\d+[^\s\"'`]*))?(?:\s+([A-Za-z0-9][A-Za-z0-9._-]*))?", re.I)
_RE_PIP = re.compile(r"pip(?:3)?\s+install\s+([A-Za-z0-9][A-Za-z0-9._-]*)(?:==(\d+\.\d+\.\d+[^\s\"'`]*))?", re.I)
# JSON-конфиг в README: {"args": ["-y", "@scope/pkg@1.2.3"]} — команды npx там нет,
# но пакет с версией указан явно.
_RE_NPM_TOKEN = re.compile(r'"(@?[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)?@\d+\.\d+\.\d+[^"\s]*)"', re.I)
# Пакет в JSON-конфиге без версии: "args": ["-y", "pkg"]
_RE_JSON_ARG = re.compile(r'"(?:-y|--yes|--from)"\s*,\s*"(@?[a-z0-9][a-z0-9._/-]*)"', re.I)
_RE_REMOTE = re.compile(r"https://([a-z0-9.-]+\.[a-z]{2,})(/[^\s\"'<>\)`]*)?", re.I)

# Каталоги и зеркала MCP: их страницы похожи на адрес сервера, но вызов туда
# уйдёт в никуда (и это чужой сайт, а не MCP-эндпоинт).
_REMOTE_BLOCK = (
    "glama.ai", "smithery.ai", "mcp.so", "pulsemcp.com", "mcpservers.org",
    "mcpmarket.com", "claudemcp.com", "mcp.aibase.com", "lobehub.com",
    "github.com", "githubusercontent.com", "npmjs.com", "pypi.org",
    "shields.io", "img.shields.io", "badge.fury.io", "opensource.org",
    "doi.org", "arxiv.org", "modelcontextprotocol.io", "anthropic.com",
    "docs.", "blog.", "discord.gg", "x.com", "twitter.com",
)
_RE_ENV = re.compile(r"\b([A-Z][A-Z0-9_]{2,40})\b")


def _detect_packages(readme: str, full_name: str) -> list[dict]:
    """Пакеты запуска из README. Версия нужна точная — иначе карточка без конфига."""
    text = readme or ""
    out: list[dict] = []
    repo_name = full_name.split("/")[-1].lower()

    for match in _RE_NPX.finditer(text):
        name = match.group(1).lower().lstrip("@")
        # В README часто пишут шаблон «npx -y <package>» — такие пропускаем.
        if not re.match(r"^(@[a-z0-9._-]+/)?[a-z0-9][a-z0-9._-]*$", name) or "<" in name:
            continue
        package = match.group(1)
        version = (match.group(3) or "").strip()
        if not version:
            continue  # версию добираем из npm отдельно
        out.append({"manager": "npm", "name": f"{package}@{version}", "entrypoint": ""})

    if "npx" in text.lower() or "npm" in text.lower():
        for match in _RE_NPM_TOKEN.finditer(text):
            package = match.group(1)
            if match.group(1).count("@") >= 2 and not package.startswith("@"):
                continue
            out.append({"manager": "npm", "name": package, "entrypoint": ""})

    for match in _RE_UVX.finditer(text):
        name = match.group(1).lower()
        if not re.match(r"^[a-z0-9][a-z0-9._-]*$", name):
            continue
        version = (match.group(2) or "").strip()
        entry = (match.group(3) or "").strip()
        if version:
            out.append({"manager": "pypi", "name": f"{name}=={version}", "entrypoint": entry})

    for match in _RE_PIP.finditer(text):
        name = match.group(1).lower()
        version = (match.group(2) or "").strip()
        if version and re.match(r"^[a-z0-9][a-z0-9._-]*$", name):
            out.append({"manager": "pypi", "name": f"{name}=={version}", "entrypoint": ""})

    # Дедуп по (manager, name); приоритет — пакету, похожему на имя репозитория.
    unique: dict[tuple[str, str], dict] = {}
    for package in out:
        key = (package["manager"], package["name"].lower())
        unique.setdefault(key, package)

    packages = list(unique.values())
    packages.sort(key=lambda p: 0 if repo_name and repo_name in p["name"].lower() else 1)
    return packages[:3]


async def _resolve_versions(repo_name: str, readme: str) -> list[dict]:
    """Добрать версию пакета из npm/PyPI, если README её не называет."""
    candidates: list[tuple[str, str]] = []

    # Только явные упоминания пакетов без версии. Угадывать пакет по имени
    # репозитория нельзя: «rea» и «hermes-agent» есть и в npm, и в PyPI, но к
    # MCP-серверу отношения не имеют — каталог выдал бы битую конфигурацию.
    for match in _RE_NPX.finditer(readme or ""):
        name = match.group(1)
        if not match.group(3) and re.match(r"^(@[a-z0-9._-]+/)?[a-z0-9][a-z0-9._-]*$", name.lower()):
            candidates.append(("npm", name))

    # JSON-конфиг без версии: {"command":"npx","args":["-y","firecrawl-mcp"]}.
    for match in _RE_JSON_ARG.finditer(readme or ""):
        name = match.group(1)
        if re.match(r"^(@[a-z0-9._-]+/)?[a-z0-9][a-z0-9._-]*$", name.lower()):
            candidates.append(("npm", name))

    # PyPI без имени исполняемой команды бесполезен для uvx — только uvx-строки.
    for match in _RE_UVX.finditer(readme or ""):
        if not match.group(2) and match.group(3):
            candidates.append(("pypi", match.group(1) + "::" + match.group(3)))

    resolved: list[dict] = []
    repo_tokens = {part for part in re.split(r"[^a-z0-9]+", repo_name.lower()) if len(part) >= 3}

    for candidate in candidates[:3]:
        if "::" in candidate[1]:
            manager, name, entrypoint = candidate[0], candidate[1].split("::", 1)[0], candidate[1].split("::", 1)[1]
        else:
            manager, name, entrypoint = candidate[0], candidate[1], ""

        # Имя пакета должно пересекаться с именем репозитория: иначе это чужой
        # пакет, случайно упомянутый в README (например, зависимость примера).
        name_tokens = {part for part in re.split(r"[^a-z0-9]+", name.lower()) if len(part) >= 3}
        if repo_tokens and not (repo_tokens & name_tokens):
            continue

        version = await _registry_version(manager, name)
        if not version:
            continue

        resolved.append({
            "manager": manager,
            "name": f"{name}@{version}" if manager == "npm" else f"{name}=={version}",
            "entrypoint": entrypoint,
        })

    return resolved


async def _registry_version(manager: str, name: str) -> str:
    try:
        async with httpx.AsyncClient(timeout=12, follow_redirects=True) as client:
            if manager == "npm":
                response = await client.get(f"https://registry.npmjs.org/{name}/latest")
                if response.status_code != 200:
                    return ""
                data = response.json()
                return str(data.get("version") or "")
            response = await client.get(f"https://pypi.org/pypi/{name}/json")
            if response.status_code != 200:
                return ""
            data = response.json()
            return str((data.get("info") or {}).get("version") or "")
    except Exception:  # noqa: BLE001 — реестр не обязателен
        return ""


def _detect_remote(readme: str, repo_url: str) -> tuple[str, str]:
    """Удалённый адрес MCP из README. → (url, 'sse'|'streamable_http'|'')."""
    text = (readme or "").replace("\n", " ")
    best = ""

    for match in _RE_REMOTE.finditer(text):
        host = match.group(1).lower()
        path = match.group(2) or ""

        if any(host == blocked or host.endswith("." + blocked) or host.startswith(blocked)
               for blocked in _REMOTE_BLOCK):
            continue
        url = f"https://{host}{path}".rstrip(".,;:)]}")
        low = url.lower()
        if "/sse" in low:
            return url, "sse"
        if re.search(r"/mcp(/|$)", low) or low.rstrip("/").endswith("/v1"):
            best = url

    return best, ("streamable_http" if best else "")


def _detect_env_keys(readme: str) -> list[str]:
    text = readme or ""
    keys: list[str] = []

    # Сначала — строки вида «KEY=…» внутри code-блоков и списков переменных.
    for match in _RE_ENV.finditer(text):
        key = match.group(1)
        if key in _ENV_NOISE or len(key) < 4:
            continue
        # Плейсхолдеры из документации — не переменные окружения.
        if re.match(r"^(YOUR|MY|EXAMPLE|PLACEHOLDER|CHANGE|INSERT|REPLACE|SOME)[_A-Z0-9]*$", key):
            continue
        if not re.search(r"(KEY|TOKEN|SECRET|URL|HOST|ID|PASSWORD|DSN|ACCOUNT|REGION|BUCKET|PROJECT|BASE)$", key):
            continue
        if key not in keys:
            keys.append(key)

    return keys[:8]


def _detect_auth(readme: str, env_keys: list[str]) -> str:
    """Нужен ли ключ. Переменная вида SEARXNG_URL — это адрес, а не авторизация.

    Порядок важен: «no API key required» содержит слова «API key», поэтому
    сначала смотрим на реальные имена переменных с секретом и на OAuth, и лишь
    потом — на отрицание.
    """
    text = (readme or "").lower()
    secret_keys = [key for key in env_keys
                   if re.search(r"(KEY|TOKEN|SECRET|PASSWORD|CREDENTIAL)$", key)]

    if secret_keys:
        return "api_key"
    if re.search(r"\boauth\b", text):
        return "oauth"
    if re.search(r"no (api )?key|without (an )?api key|no authentication|без ключа", text):
        return "none"
    if re.search(r"api[_ -]?key|access[_ -]?token|bearer\s+[a-z0-9]", text):
        return "api_key"

    return "unknown"


def _guess_categories(repo: dict, readme: str) -> list[str]:
    haystack = " ".join([
        str(repo.get("description") or ""),
        " ".join(repo.get("topics") or []),
        (repo.get("name") or ""),
        (readme or "")[:2000],
    ]).lower()

    scores: dict[str, int] = {}
    for category, words in _CATEGORY_HINTS.items():
        scores[category] = sum(1 for word in words if word in haystack)

    ranked = sorted(scores.items(), key=lambda item: item[1], reverse=True)
    picked = [name for name, score in ranked if score > 0][:3]

    return picked or ["other"]


def _relevance(repo: dict, theme: str) -> float:
    """Насколько репозиторий похож на MCP-сервер по нужной теме.

    Сортировать кандидатов по звёздам нельзя: в топе оказываются гигантские
    платформы, где MCP лишь упомянут. Считаем прямые признаки (mcp в названии,
    описании, тегах), совпадение с тематикой и лишь как добавку — звёзды.
    """
    name = str(repo.get("name") or "").lower()
    desc = str(repo.get("description") or "").lower()
    topics = [str(t).lower() for t in (repo.get("topics") or [])]

    score = 0.0
    if re.search(r"\bmcp\b", name):
        score += 5
    if re.search(r"\bmcp\b", desc):
        score += 4
    if "mcp-server" in topics or "model-context-protocol" in topics:
        score += 2

    for word in {w for w in re.split(r"[^a-zа-яё0-9]+", theme.lower()) if len(w) >= 3}:
        if word in name or word in desc:
            score += 2
        elif any(word in topic for topic in topics):
            score += 1.5

    try:
        score += min(3.0, math.log10(int(repo.get("stargazers_count") or 0) + 1))
    except (TypeError, ValueError):
        pass

    return score


def _looks_like_mcp(repo: dict, readme: str) -> bool:
    """Это MCP-сервер, а не большой продукт, где MCP лишь упомянут?

    Поиск по словам приносит гигантские репозитории (n8n, «awesome»-списки,
    агентские платформы): MCP у них есть в тегах или глубоко в README, но
    каталогу нужны серверы. Поэтому требуем ПРЯМОЙ признак:
      * «mcp» в названии или описании репозитория, ИЛИ
      * «mcp server» / «mcp-server» / «model context protocol» в НАЧАЛЕ README;
    плюс след запуска (пакет, npx/uvx, python -m, docker run) и запрет на
    «awesome»-списки.
    """
    name = str(repo.get("name") or "").lower()
    desc = str(repo.get("description") or "").lower()
    head = (readme or "")[:1800].lower()
    text = (readme or "").lower()

    if "awesome" in name or "curated list" in desc or "collection of" in desc:
        return False

    primary = bool(re.search(r"\bmcp\b", name + " " + desc)) or any(
        marker in head for marker in ("mcp server", "mcp-server", "model context protocol"))
    if not primary:
        return False

    launch = bool(re.search(r"(npx|uvx|pip install|python3? -m|docker run|npm install)\s", text))

    return launch or bool(re.search(r"\bmcp\b", name + " " + desc))


def _clean_description(text: str, cap: int = 400) -> str:
    text = re.sub(r"<[^>]+>", " ", text or "")
    text = re.sub(r"!\[[^\]]*\]\([^)]*\)", " ", text)
    text = re.sub(r"\[([^\]]+)\]\([^)]*\)", r"\1", text)
    text = re.sub(r"[`*_>#|]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:cap]


# ---------------------------------------------------------------------------
# Живая проверка удалённого MCP
# ---------------------------------------------------------------------------

async def _probe_remote(url: str) -> tuple[bool, int, str, list[dict]]:
    """initialize + tools/list. → (ok, ms, note, tools)."""
    timeout = _env_int("NJ676_PROBE_TIMEOUT", 8, 3, 30)

    try:
        parsed = urlparse(url)
        if parsed.scheme != "https" or not parsed.hostname:
            return False, 0, "адрес не HTTPS", []
        await asyncio.to_thread(webread.assert_public_host, parsed.hostname)
    except Exception as exc:  # noqa: BLE001
        return False, 0, f"адрес отклонён проверкой: {type(exc).__name__}", []

    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        "User-Agent": "n2j-mcphub-probe/1.0",
    }
    started = time.monotonic()

    def _post_once(body: dict) -> dict | None:
        with httpx.Client(timeout=timeout, follow_redirects=False) as client:
            response = client.post(url, json=body, headers=headers)
        if response.status_code != 200:
            # 5xx и 429 — временные: пусть _post повторит запрос. Прочие
            # не-200 (4xx) — осознанный отказ сервера, повторять бессмысленно.
            if response.status_code >= 500 or response.status_code == 429:
                raise RuntimeError("upstream HTTP %d" % response.status_code)
            return None
        try:
            return response.json()
        except ValueError:
            # SSE-ответ: берём последний data:-объект
            payload = None
            for line in response.text.splitlines():
                if line.startswith("data:"):
                    try:
                        payload = json.loads(line[5:].strip())
                    except ValueError:
                        continue
            return payload

    def _post(body: dict) -> dict | None:
        """Один повтор при сетевом сбое.

        Публичные MCP-серверы за CDN регулярно рвут первое соединение, и одна
        неудачная проба объявляла живой сервер сломанным (тест 24.10.2026:
        open-meteo отвечал на вызовы, но получил degraded из-за пробы).
        Повтор безопасен: initialize/tools/list ничего не меняют.
        """
        last_exc: Exception | None = None
        for attempt in (1, 2):
            try:
                result = _post_once(body)
                if result is not None:
                    return result
            except Exception as exc:  # noqa: BLE001
                last_exc = exc
            if attempt == 1:
                time.sleep(0.4)
        if last_exc is not None:
            raise last_exc
        return None

    try:
        init = await asyncio.to_thread(_post, {
            "jsonrpc": "2.0", "id": 1, "method": "initialize",
            "params": {
                "protocolVersion": "2024-11-05",
                "capabilities": {},
                "clientInfo": {"name": "n2j-mcphub-probe", "version": "1.0"},
            },
        })
        if not isinstance(init, dict) or "result" not in init:
            return False, int((time.monotonic() - started) * 1000), "initialize не ответил", []

        listed = await asyncio.to_thread(_post, {
            "jsonrpc": "2.0", "id": 2, "method": "tools/list", "params": {},
        })
    except Exception as exc:  # noqa: BLE001
        return False, int((time.monotonic() - started) * 1000), f"сеть: {type(exc).__name__}", []

    latency = int((time.monotonic() - started) * 1000)

    tools = []
    if isinstance(listed, dict):
        for tool in ((listed.get("result") or {}).get("tools") or [])[:100]:
            if isinstance(tool, dict) and tool.get("name"):
                tools.append({
                    "name": str(tool["name"])[:128],
                    "description": _clean_description(str(tool.get("description") or ""), 600),
                    "inputSchema": tool.get("inputSchema") if isinstance(tool.get("inputSchema"), dict) else {"type": "object"},
                })

    if not isinstance(listed, dict) or "result" not in listed:
        # Ответа на tools/list нет вовсе: это сбой пробы, а не «инструментов
        # нет». Сайт оставит прошлый снимок (см. apply_probe).
        return False, latency, "tools/list не ответил", []

    if not tools:
        # Сервер честно ответил пустым списком. Успешная проба — да, но
        # инструментов нет: карточка не должна обещать вызов (тест 24.10.2026,
        # ai-1325-mcp). Сайт оставит прошлый снимок, если он был.
        return True, latency, "tools/list пуст", []

    return True, latency, "tools/list: %d" % len(tools), tools


# ---------------------------------------------------------------------------
# LLM: русские тексты и категории
# ---------------------------------------------------------------------------

def _prompt_items(candidates: list[dict], cap: int) -> str:
    chunks = []
    for item in candidates:
        repo = item["repo"]
        chunks.append(
            f"- slug: {repo['full_name']}\n"
            f"  name: {repo.get('name')}\n"
            f"  description: {_clean_description(str(repo.get('description') or ''), 300)}\n"
            f"  topics: {', '.join((repo.get('topics') or [])[:8])}\n"
            f"  language: {repo.get('language') or '-'}; stars: {repo.get('stargazers_count') or 0}\n"
            f"  readme: {_clean_description(item.get('readme') or '', cap)}"
        )
    return "\n".join(chunks)


async def _enrich(ctx, candidates: list[dict], batch: int, cap: int) -> dict[str, dict]:
    """LLM-описания по пачкам. Ошибка модели не останавливает сборку."""
    out: dict[str, dict] = {}
    if not candidates:
        return out

    model = _env("NJ676_MODEL")

    for start in range(0, len(candidates), batch):
        part = candidates[start:start + batch]
        prompt = (
            _PROMPT.replace("{cats}", ", ".join(CATEGORIES))
            + "\n\nПроекты:\n" + _prompt_items(part, cap)
        )

        try:
            raw, _usage = await _llm(
                ctx,
                [{"role": "system", "content": SYSTEM_CURATOR}, {"role": "user", "content": prompt}],
                "Пишу описания",
                model_env="NJ676_MODEL",
                max_tokens=min(6000, 900 * len(part) + 500),
                timeout=240,
                options=_think_options(),
            )
        except Exception as exc:  # noqa: BLE001 — без модели соберём по фактам
            log.warning("nj676: модель не ответила (%s) — описания из GitHub", exc.__class__.__name__)
            break

        parsed = webread.json_from_text(_strip_think(raw or ""))
        items = (parsed or {}).get("items") if isinstance(parsed, dict) else None

        if not isinstance(items, list):
            log.warning("nj676: модель вернула не JSON — описания из GitHub")
            continue

        known = {item["repo"]["full_name"].lower(): item["repo"] for item in part}

        for entry in items:
            if not isinstance(entry, dict):
                continue
            slug = str(entry.get("slug") or "").strip().lower()
            repo = known.get(slug)
            if not repo:
                continue

            categories = [str(c) for c in (entry.get("categories") or []) if str(c) in CATEGORIES]
            quality = entry.get("quality")
            try:
                quality = max(0, min(100, int(quality))) if quality is not None else None
            except (TypeError, ValueError):
                quality = None

            out[slug] = {
                "name": str(entry.get("name") or repo.get("name") or "")[:160],
                # Русское название: сайт покажет его первым, а оригинал оставит
                # рядом. Пустая строка = перевода нет, интерфейс возьмёт name.
                "name_ru": _clean_description(str(entry.get("name_ru") or ""), 160),
                "description": _clean_description(str(entry.get("description") or ""), 1200),
                "categories": categories[:3],
                "auth": str(entry.get("auth") or "unknown").lower(),
                "highlights": [_clean_description(str(x), 200) for x in (entry.get("highlights") or [])[:3]],
                "use_cases": [_clean_description(str(x), 200) for x in (entry.get("use_cases") or [])[:2]],
                "tags": [re.sub(r"[^a-z0-9._-]", "", str(t).lower())[:40] for t in (entry.get("tags") or [])[:5]],
                "quality": quality,
            }

    return out


# ---------------------------------------------------------------------------
# Сборка карточки
# ---------------------------------------------------------------------------

def _base_item(repo: dict, source_note: str) -> dict:
    full_name = str(repo.get("full_name") or "")
    owner = str((repo.get("owner") or {}).get("login") or "")
    if not owner and "/" in full_name:
        owner = full_name.split("/")[0]
    topics = [str(t).lower()[:40] for t in (repo.get("topics") or [])[:12] if re.match(r"^[A-Za-z0-9._-]+$", str(t))]

    return {
        "repo_url": str(repo.get("html_url") or f"https://github.com/{full_name}"),
        "name": str(repo.get("name") or full_name)[:160],
        "description": _clean_description(str(repo.get("description") or ""), 1200),
        "homepage_url": str(repo.get("homepage") or "") if str(repo.get("homepage") or "").startswith("https://") else "",
        "topics": topics,
        "stars": int(repo.get("stargazers_count") or 0),
        "forks": int(repo.get("forks_count") or 0),
        "language": str(repo.get("language") or "")[:60],
        "license": str((repo.get("license") or {}).get("spdx_id") or "")[:80].replace("NOASSERTION", ""),
        "owner_login": owner[:120],
        "github_id": int(repo.get("id") or 0),
        "repo_pushed_at": str(repo.get("pushed_at") or ""),
        "repo_archived": bool(repo.get("archived")),
        "health_status": "unverified",
        "health_note": "",
        "source_note": source_note,
    }


async def _build_item(repo: dict, readme: str, enrichment: dict, probe: bool) -> dict:
    item = _base_item(repo, "")

    packages = _detect_packages(readme, str(repo.get("full_name") or ""))
    if not packages:
        packages = await _resolve_versions(str(repo.get("name") or "").lower(), readme)

    remote_url, remote_kind = _detect_remote(readme, item["repo_url"])
    env_keys = _detect_env_keys(readme)

    transport = "unknown"
    if remote_kind:
        transport = remote_kind
    elif packages:
        transport = "stdio"
    elif re.search(r"\bstdio\b|\bnpx\b|\buvx\b|pip install|python3? -m|node dist", (readme or "").lower()):
        # Запуск локальный, даже если точную команду из README не вытащили:
        # карточке честнее сказать «локальный», чем «не указан».
        transport = "stdio"

    auth = str(enrichment.get("auth") or "").lower()
    if auth not in ("none", "api_key", "oauth", "unknown"):
        auth = _detect_auth(readme, env_keys)

    # Проверка удалённого сервера, которому нужен ключ, не может пройти:
    # «недоступен» здесь было бы враньём, поэтому оставляем «не проверен».
    requires_key = auth in ("api_key", "oauth")

    item.update({
        "packages": packages,
        "remote_url": remote_url,
        "transport": transport,
        "auth_mode": auth,
        "env_keys": env_keys,
        "categories": enrichment.get("categories") or _guess_categories(repo, readme),
        "description": item["description"] or enrichment.get("description") or "",
        "description_ru": enrichment.get("description_ru") or enrichment.get("description") or "",
        "name": enrichment.get("name") or item["name"],
        # Перевод — отдельные поля: оригинал автора остаётся в name/description.
        "name_ru": enrichment.get("name_ru") or "",
        "ru_source": "llm" if (enrichment.get("name_ru") or enrichment.get("description")) else "",
        "highlights": enrichment.get("highlights") or [],
        "use_cases": enrichment.get("use_cases") or [],
        "tools": [],
        "quality": enrichment.get("quality"),
    })

    if not item["description"]:
        item["description"] = f"MCP-сервер {item['name']} ({item['repo_url']})."

    if remote_url and probe:
        ok, latency, note, tools = await _probe_remote(remote_url)

        if ok:
            item["health_status"] = "operational"
        elif requires_key:
            item["health_status"] = "unverified"
            note = "проверка без ключа невозможна: " + note
        else:
            item["health_status"] = "offline"

        item["health_note"] = note
        item["latency_ms"] = latency
        if tools:
            item["tools"] = tools

    return item


# ---------------------------------------------------------------------------
# Точка входа
# ---------------------------------------------------------------------------

async def handle(ctx, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    theme = str(payload.get("theme") or "").strip()[:200]
    limit = max(1, min(_env_int("NJ676_MAX_ITEMS", 30, 1, 30), int(payload.get("limit") or 12)))
    cap = _env_int("NJ676_README_CAP", 4000, 800, 12000)
    batch = _env_int("NJ676_LLM_BATCH", 5, 1, 10)
    min_stars = _env_int("NJ676_MIN_STARS", 10, 0, 100000)
    probe = _flag("NJ676_PROBE", True)

    base = _site_base()
    if not base:
        raise RuntimeError(
            "не настроен адрес сайта: NJ676_API_BASE (или общий NJ120_API_BASE) "
            "в workers/.env"
        )

    stats = {"found": 0, "added": 0, "updated": 0, "skipped": 0, "probed": 0, "errors": 0}

    async def finish(status: str, error: str = "", progress: str = "") -> dict:
        await _site_post(base, "/discover-finish", {
            "run_id": run_id,
            "status": status,
            "error": error,
            "progress": progress,
            **{key: stats[key] for key in ("found", "added", "updated", "skipped", "probed")},
        })
        return {"run_id": run_id, "theme": theme, **stats, "error": error}

    try:
        # Тема приходит от спроса агентов (Service_Nj676_Demand): «курс валют
        # ЦБ», «база данных». GitHub ищет по английским словам, поэтому тему
        # сначала переводим в 2–3 поисковые фразы; без модели — ищем как есть.
        keywords = await _theme_keywords(ctx, theme)
        search_theme = " ".join(keywords) if keywords else theme

        await _progress(ctx, base, run_id,
                        "Ищу проекты MCP на GitHub…"
                        + (f" ({', '.join(keywords)})" if keywords and keywords != [theme] else ""))

        seen: dict[str, dict] = {}
        errors: list[str] = []

        for query, sort, per_page in _queries(theme, keywords):
            await ctx.heartbeat()

            items, error = await _gh_search(query, sort, per_page)
            if error:
                errors.append(error)

            for repo in items:
                full_name = str(repo.get("full_name") or "")
                key = full_name.lower()
                if not full_name or key in seen:
                    continue
                if repo.get("archived"):
                    continue
                if int(repo.get("stargazers_count") or 0) < (3 if theme and "in:name" in query else min_stars):
                    continue

                pushed = str(repo.get("pushed_at") or "")
                if pushed and pushed[:4].isdigit() and int(pushed[:4]) < time.gmtime().tm_year - 2:
                    continue

                seen[key] = repo

        await _progress(ctx, base, run_id, f"GitHub: кандидатов {len(seen)}", len(seen))

        # «Утка» и реестр пакетов npm: добираем проекты без тегов mcp на GitHub.
        if _flag("NJ676_DDG", True) and len(seen) < limit * 3:
            await _progress(ctx, base, run_id, "Ищу в интернете и npm проекты без тегов…")
            extra_repos = list(await _ddg_repos(search_theme)) + list(await _npm_repos(search_theme))
            for full_name in extra_repos:
                key = full_name.lower()
                if key in seen:
                    continue
                repo, error = await _gh_get(f"/repos/{full_name}")
                if isinstance(repo, dict):
                    seen[key] = repo
                if error:
                    errors.append(error)

        candidates = sorted(seen.values(), key=lambda r: _relevance(r, search_theme), reverse=True)

        if not candidates:
            reason = errors[0] if errors else "GitHub не дал кандидатов по тематике"
            await finish("fail", reason, "Ничего не найдено")
            raise RuntimeError(reason)

        stats["found"] = len(candidates)

        # Сайт подсказывает, что уже собрано: новое — в LLM, известное — только метаданные.
        known_repos: list[str] = []
        new_repos: list[str] = []

        repos_chunk = [r.get("full_name") for r in candidates]
        ok, reason, state = await _site_post(base, "/discover-state", {"repos": repos_chunk})
        if ok and isinstance(state.get("known"), list):
            known_repos = [str(x) for x in state["known"]]
            new_repos = [str(x) for x in state.get("new") or []]

        known_lower = {name.lower() for name in known_repos}
        fresh = [repo for repo in candidates if str(repo.get("full_name")).lower() not in known_lower]
        existing = [repo for repo in candidates if str(repo.get("full_name")).lower() in known_lower]

        selected = fresh[:limit]
        refresh = existing[:(max(1, limit // 3))]

        if not selected and not refresh:
            await finish("done", "", "Новых проектов нет — каталог уже полон по этой теме")
            return {"run_id": run_id, "theme": theme, **stats, "note": "новых нет"}

        await _progress(ctx, base, run_id,
                        f"Читаю README: {len(selected)} новых, {len(refresh)} обновить", stats["found"])

        all_names = [str(r.get("full_name")) for r in selected + refresh]
        # Читаем больше, чем отдаём модели: команда установки часто ниже начала
        # README. В промпт идёт обрезка по NJ676_README_CAP.
        readmes = await _gh_readmes(all_names, max(cap, 8000))

        prepared = []
        for repo in selected:
            readme = readmes.get(str(repo.get("full_name")).lower(), "")
            if not _looks_like_mcp(repo, readme):
                stats["skipped"] += 1
                log.info("nj676: %s не похож на MCP-сервер — пропущен", repo.get("full_name"))
                continue
            prepared.append({"repo": repo, "readme": readme})

        if not prepared:
            await finish("done", "", "Новых MCP-серверов по теме не нашлось")
            return {"run_id": run_id, "theme": theme, **stats, "note": "новых MCP-серверов нет"}

        # Модель получает пачки; каждая пачка — отдельный вызов с понятным этапом.
        enrichment: dict[str, dict] = {}
        for start in range(0, len(prepared), batch):
            part = prepared[start:start + batch]
            await _progress(ctx, base, run_id,
                            f"Пишу описания: {min(start + batch, len(prepared))} из {len(prepared)}",
                            stats["found"])
            enrichment.update(await _enrich(ctx, part, batch, cap))
            await ctx.heartbeat()

        for index, entry in enumerate(prepared, start=1):
            repo = entry["repo"]
            full_name = str(repo.get("full_name"))
            await ctx.heartbeat()

            try:
                item = await _build_item(repo, entry["readme"], enrichment.get(full_name.lower(), {}), probe)
            except Exception as exc:  # noqa: BLE001 — одна карточка не роняет прогон
                stats["skipped"] += 1
                log.warning("nj676: карточка %s не собралась: %s", full_name, exc)
                continue

            ok, reason, answer = await _site_post(base, "/discover-item", {
                "run_id": run_id,
                "item": item,
            })

            if not ok:
                stats["skipped"] += 1
                log.warning("nj676: %s отклонён сайтом: %s", full_name, reason)
            elif answer.get("status") == "added":
                stats["added"] += 1
            elif answer.get("status") == "updated":
                stats["updated"] += 1
            else:
                stats["skipped"] += 1

            if item.get("latency_ms") is not None:
                stats["probed"] += 1

            await _progress(ctx, base, run_id,
                            f"Записал карточки: {index} из {len(prepared)} (+{stats['added']})",
                            stats["found"])

        # Известные проекты: обновляем звёзды и свежесть без модели.
        for repo in refresh:
            try:
                item = _base_item(repo, "")
                item.update({
                    "packages": [], "remote_url": "", "transport": "unknown", "auth_mode": "unknown",
                    "env_keys": [], "categories": _guess_categories(repo, ""),
                    # Описание при обновлении метаданных НЕ присылаем: иначе
                    # английский текст GitHub затирал русский перевод модели.
                    "description": "",
                    "highlights": [], "use_cases": [], "tools": [], "health_status": "unverified",
                })
                ok, _reason, answer = await _site_post(base, "/discover-item", {"run_id": run_id, "item": item})
                if ok and answer.get("status") == "updated":
                    stats["updated"] += 1
            except Exception as exc:  # noqa: BLE001
                log.info("nj676: метаданные %s не обновились: %s", repo.get("full_name"), exc)

        note = f"добавлено {stats['added']}, обновлено {stats['updated']}"
        await finish("done", "", f"Готово: {note}")
        log.info("nj676: прогон %s завершён: %s", run_id, note)

        return {"run_id": run_id, "theme": theme, **stats, "note": note}

    except Exception as exc:  # noqa: BLE001 — финал обязан быть виден
        reason = str(exc)[:500] or exc.__class__.__name__

        try:
            await finish("fail", reason, "Сборка остановлена ошибкой")
        except Exception:  # noqa: BLE001
            log.warning("nj676: финал не доставлен")

        raise
