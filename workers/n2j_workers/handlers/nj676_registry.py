"""nj676.registry — импорт официального реестра MCP-серверов в каталог N2J.

Источник: https://registry.modelcontextprotocol.io (спецификация MCP Registry,
API v0.1). Это единственный источник, где серверы опубликованы машинно:
имя с проверенным пространством имён (io.github.owner/repo), версия,
репозиторий, пакеты npm/PyPI/OCI и удалённые адреса.

Почему отдельный канал, а не nj676.discover:
  * здесь не нужны GitHub-поиск, DuckDuckGo, README и LLM — только HTTP и
    нормализация, поэтому прогон дешёвый и быстрый;
  * пачки пишутся на сайт (POST /mcphub/api/registry-batch), сайт сам решает,
    обновить карточку или создать: по registry_name, репозиторию, удалённому
    адресу. Повторный импорт не плодит дубликаты.

Env: NJ676_REGISTRY_URL (по умолчанию registry.modelcontextprotocol.io),
NJ676_REGISTRY_PAGE (100), NJ676_REGISTRY_MAX (сколько записей за прогон),
NJ676_REGISTRY_DELAY (пауза между страницами, с), NJ676_PROBE (1 — проверять
удалённые адреса перед записью), адрес и токен сайта — как у nj676.discover.
"""

from __future__ import annotations

import asyncio
import logging
import os
import re
import time
from typing import Any

import httpx

from .nj676_mcphub import (
    CATEGORIES,
    _CATEGORY_HINTS,
    _clean_description,
    _env,
    _env_int,
    _gh_headers,
    _probe_remote,
    _site_base,
    _site_post,
)

log = logging.getLogger("n2j.nj676")

CHANNEL = "nj676.registry"

REGISTRY_URL = "https://registry.modelcontextprotocol.io"

# Реестр отдаёт типы транспорта в разных написаниях.
_REMOTE_TYPES = {
    "streamable-http": "streamable_http",
    "streamable_http": "streamable_http",
    "http": "streamable_http",
    "sse": "sse",
}


def _registry_base() -> str:
    return (_env("NJ676_REGISTRY_URL", REGISTRY_URL) or REGISTRY_URL).rstrip("/")


def _pick(entry: dict) -> dict | None:
    """Запись реестра → плоская карточка для сайта.

    Реестр отдаёт {"server": {...}, "_meta": {...}} на v0.1 и плоский объект в
    старом v0. Тело защищаем от неожиданностей: любое поле может отсутствовать.
    """
    if not isinstance(entry, dict):
        return None

    server = entry.get("server") if isinstance(entry.get("server"), dict) else entry
    name = str(server.get("name") or "").strip()
    if not name:
        return None

    repository = server.get("repository") if isinstance(server.get("repository"), dict) else {}
    repo_url = str(repository.get("url") or "").strip()
    if not repo_url:
        repo_url = str(server.get("repository") or "").strip()

    homepage = str(server.get("websiteUrl") or server.get("homepage") or "").strip()

    remotes = server.get("remotes") or server.get("remote") or []
    remote_url = ""
    transport = "unknown"
    remote_headers: list[str] = []

    if isinstance(remotes, list):
        for remote in remotes:
            if not isinstance(remote, dict):
                continue
            url = str(remote.get("url") or "").strip()
            if not url.startswith("https://"):
                continue
            remote_url = url
            transport = _REMOTE_TYPES.get(str(remote.get("type") or "").lower(), "streamable_http")
            for header in remote.get("headers") or []:
                if isinstance(header, dict) and header.get("name"):
                    remote_headers.append(str(header["name"]))
            break

    packages: list[dict] = []
    env_keys: list[str] = []
    for package in server.get("packages") or []:
        if not isinstance(package, dict):
            continue
        manager = str(package.get("registryType") or package.get("registry_name") or "").lower()
        manager = "pypi" if manager in ("pypi", "python") else ("npm" if manager == "npm" else manager)
        identifier = str(package.get("identifier") or package.get("name") or "").strip()
        if manager not in ("npm", "pypi", "oci") or not identifier:
            continue
        packages.append({
            "manager": manager,
            "identifier": identifier[:200],
            "version": str(package.get("version") or "")[:60],
            "transport": "stdio",
        })
        for variable in package.get("environmentVariables") or []:
            if isinstance(variable, dict) and variable.get("name"):
                env_keys.append(str(variable["name"]))
        if len(packages) >= 4:
            break

    if transport == "unknown" and packages:
        transport = "stdio"

    title = str(server.get("title") or "").strip()
    description = _clean_description(str(server.get("description") or ""), 1200)

    if not description:
        short = name.split("/")[-1].replace("-", " ").replace("_", " ")
        description = f"MCP-сервер {short} из официального реестра MCP."

    categories = _categories(name, title, description)

    if remote_headers and not env_keys:
        env_keys = remote_headers

    auth = "none"
    if env_keys:
        auth = "api_key"
    elif remote_url and not env_keys:
        auth = "none"

    return {
        "registry_name": name[:200],
        "name": (title or name.split("/")[-1])[:160],
        "description": description,
        "repo_url": repo_url if repo_url.startswith("https://") else "",
        "homepage_url": homepage if homepage.startswith("https://") else "",
        "version": str(server.get("version") or "")[:60],
        "transport": transport,
        "auth_mode": auth,
        "remote_url": remote_url,
        "env_keys": list(dict.fromkeys(env_keys))[:12],
        "packages": packages,
        "categories": categories,
        "stars": 0,
    }


# Реестр присылает короткие английские описания, поэтому к общим подсказкам
# добавляем слова, которые в них встречаются чаще всего.
_EXTRA_HINTS = {
    "research": ("weather", "climate", "forecast", "academic", "scholar"),
    "data": ("dataset", "analytics", "sql", "database"),
    "web": ("scraping", "browsing", "search"),
    "development": ("sdk", "library", "compiler", "typescript", "python"),
    "productivity": ("task", "notes", "calendar"),
    "files": ("filesystem", "documents", "storage"),
    "finance": ("billing", "payments", "invoices"),
}


def _categories(name: str, title: str, description: str) -> list[str]:
    haystack = f"{name} {title} {description}".lower()
    found: list[str] = []

    hints: dict[str, tuple[str, ...]] = {}
    for category, words in _CATEGORY_HINTS.items():
        hints[category] = tuple(words)
    for category, words in _EXTRA_HINTS.items():
        hints[category] = tuple(hints.get(category, ())) + words

    for category, words in hints.items():
        if category not in CATEGORIES:
            continue
        if any(word in haystack for word in words):
            found.append(category)
        if len(found) >= 3:
            break

    return found or ["development"]


async def _page(client: httpx.AsyncClient, cursor: str | None, limit: int) -> tuple[list[dict], str | None, str]:
    params: dict[str, Any] = {"limit": limit, "version": "latest"}
    if cursor:
        params["cursor"] = cursor

    url = f"{_registry_base()}/v0.1/servers"

    try:
        response = await client.get(url, params=params)
    except Exception as exc:  # noqa: BLE001
        return [], None, f"сеть: {type(exc).__name__}"

    if response.status_code == 404:
        # Реестр переехал на v0 — пробуем без версии в пути.
        url = f"{_registry_base()}/v0/servers"
        try:
            response = await client.get(url, params=params)
        except Exception as exc:  # noqa: BLE001
            return [], None, f"сеть: {type(exc).__name__}"

    if response.status_code != 200:
        return [], None, f"HTTP {response.status_code}"

    try:
        body = response.json()
    except ValueError:
        return [], None, "ответ не JSON"

    servers = body.get("servers") if isinstance(body, dict) else None
    if not isinstance(servers, list):
        return [], None, "нет списка servers"

    metadata = body.get("metadata") if isinstance(body.get("metadata"), dict) else {}

    return servers, metadata.get("nextCursor") or None, ""


# ---------------------------------------------------------------------------
# Дополнительные онлайн-источники MCP-серверов (удалённые эндпоинты и реестры)
# ---------------------------------------------------------------------------

REMOTE_CATALOG_REPOS = (
    "punkpeye/awesome-remote-mcp-servers",
    "jaw9c/awesome-remote-mcp-servers",
    "modelcontextprotocol/servers",
)

_SECTION_CATEGORIES = {
    "aggregators": "web",
    "browser": "web",
    "search": "web",
    "cloud": "cloud",
    "communication": "communication",
    "social": "communication",
    "content": "files",
    "file": "files",
    "document": "files",
    "crm": "productivity",
    "project": "productivity",
    "workplace": "productivity",
    "productivity": "productivity",
    "scheduling": "productivity",
    "meeting": "productivity",
    "database": "data",
    "data": "data",
    "developer": "development",
    "software": "development",
    "version control": "development",
    "finance": "finance",
    "payment": "finance",
    "e-commerce": "finance",
    "knowledge": "ai",
    "rag": "ai",
    "multimedia": "media",
    "design": "media",
    "art": "media",
    "science": "research",
    "research": "research",
    "education": "research",
    "legal": "research",
    "security": "security",
    "monitoring": "cloud",
    "observability": "cloud",
}

_RE_PUNKPEYE_ENTRY = re.compile(
    r"^- \[([^\]]+)\]\((https?://[^)\s]+)\)\s+`(https://[^`\s]+)`\s*\n"
    r"(?:\s*\[!\[[^\]]*\]\(https://glama\.ai/mcp/connectors/([^/]+/[^/]+)/badges/[^)]+\)\]\([^)]+\)\s*\n)?"
    r"\s*([🔓🔑🔐])\s*-\s*(.+)$",
    re.M,
)

_RE_JAW9C_ROW = re.compile(
    r"^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*`(https://[^`\s]+)`\s*\|\s*([^|]+?)\s*\|\s*\[([^\]]+)\]\((https?://[^)\s]+)\)",
    re.M,
)


def _slugify_remote(name: str, url: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", (name or "").lower()).strip("-")
    if len(slug) < 2:
        host = re.sub(r"^https?://", "", (url or "").lower()).split("/")[0]
        slug = re.sub(r"[^a-z0-9]+", "-", host).strip("-")
    return slug[:80] or "remote-mcp"


def _section_category(heading: str) -> list[str]:
    low = (heading or "").lower()
    for key, cat in _SECTION_CATEGORIES.items():
        if key in low and cat in CATEGORIES:
            return [cat]
    return []


def _parse_awesome_remote_punkpeye(markdown: str) -> list[dict]:
    """Разбор каталога punkpeye/awesome-remote-mcp-servers (1400+ проверенных онлайн MCP-эндпоинтов)."""
    if not markdown:
        return []

    out: list[dict] = []
    seen_urls: set[str] = set()
    sections = re.split(r"^###\s+", markdown, flags=re.M)

    for block in sections:
        lines = block.splitlines()
        heading = lines[0] if lines else ""
        sec_cats = _section_category(heading)

        for match in _RE_PUNKPEYE_ENTRY.finditer(block):
            title = match.group(1).strip()
            homepage = match.group(2).strip()
            remote_url = match.group(3).strip().rstrip(".,;:)]}")
            glama_id = (match.group(4) or "").strip()
            auth_icon = match.group(5)
            desc = _clean_description(match.group(6), 1000)

            if not remote_url.startswith("https://") or remote_url.lower() in seen_urls:
                continue
            seen_urls.add(remote_url.lower())

            slug = _slugify_remote(title, remote_url)
            registry_name = glama_id if glama_id else f"remote.punkpeye/{slug}"
            transport = "sse" if "/sse" in remote_url.lower() else "streamable_http"
            auth = "none" if auth_icon == "🔓" else ("api_key" if auth_icon == "🔑" else "oauth")

            cats = list(dict.fromkeys(sec_cats + _categories(registry_name, title, desc)))[:3]
            repo_url = homepage if homepage.startswith("https://github.com/") else ""

            out.append({
                "registry_name": registry_name[:200],
                "name": title[:160],
                "description": desc or f"Удалённый MCP-сервер {title}.",
                "repo_url": repo_url,
                "homepage_url": homepage if homepage.startswith("https://") else "",
                "version": "",
                "transport": transport,
                "auth_mode": auth,
                "remote_url": remote_url,
                "env_keys": [],
                "packages": [],
                "categories": cats or ["web"],
                "stars": 0,
            })

    return out


def _parse_awesome_remote_jaw9c(markdown: str) -> list[dict]:
    """Разбор таблицы официальных онлайн MCP-серверов jaw9c/awesome-remote-mcp-servers."""
    if not markdown:
        return []

    out: list[dict] = []
    seen_urls: set[str] = set()

    for match in _RE_JAW9C_ROW.finditer(markdown):
        title = match.group(1).strip()
        cat_label = match.group(2).strip()
        remote_url = match.group(3).strip().rstrip(".,;:)]}")
        auth_label = match.group(4).strip().lower()
        maintainer = match.group(5).strip()
        homepage = match.group(6).strip()

        if title.lower() == "name" or not remote_url.startswith("https://"):
            continue
        if remote_url.lower() in seen_urls:
            continue
        seen_urls.add(remote_url.lower())

        slug = _slugify_remote(title, remote_url)
        registry_name = f"remote.official/{slug}"
        transport = "sse" if "/sse" in remote_url.lower() else "streamable_http"

        if "open" in auth_label or "none" in auth_label:
            auth = "none"
        elif "oauth" in auth_label:
            auth = "oauth"
        elif "key" in auth_label or "token" in auth_label:
            auth = "api_key"
        else:
            auth = "unknown"

        desc = f"Официальный удалённый MCP-сервер {title} ({cat_label}, поддержка {maintainer})."
        cats = list(dict.fromkeys(_section_category(cat_label) + _categories(slug, title, cat_label)))[:3]
        repo_url = homepage if homepage.startswith("https://github.com/") else ""

        out.append({
            "registry_name": registry_name[:200],
            "name": title[:160],
            "description": desc,
            "repo_url": repo_url,
            "homepage_url": homepage if homepage.startswith("https://") else "",
            "version": "",
            "transport": transport,
            "auth_mode": auth,
            "remote_url": remote_url,
            "env_keys": [],
            "packages": [],
            "categories": cats or ["web"],
            "stars": 0,
        })

    return out


def _pick_npm(obj: dict) -> dict | None:
    """Запись поиска registry.npmjs.org (keywords:mcp-server) → плоская карточка."""
    if not isinstance(obj, dict):
        return None
    pkg = obj.get("package") if isinstance(obj.get("package"), dict) else obj
    name = str(pkg.get("name") or "").strip()
    version = str(pkg.get("version") or "").strip()
    if not name or not version or not re.match(r"^(@[a-z0-9._-]+/)?[a-z0-9][a-z0-9._-]*$", name.lower()):
        return None

    links = pkg.get("links") if isinstance(pkg.get("links"), dict) else {}
    repo_url = str(links.get("repository") or "").strip()
    if repo_url.startswith("git+"):
        repo_url = repo_url[4:]
    if repo_url.endswith(".git"):
        repo_url = repo_url[:-4]
    if not repo_url.startswith("https://github.com/"):
        repo_url = ""

    homepage = str(links.get("homepage") or links.get("npm") or "").strip()
    desc = _clean_description(str(pkg.get("description") or ""), 1000)
    if not desc:
        desc = f"MCP-сервер {name} из реестра пакетов npm."

    short_title = name.split("/")[-1]
    categories = _categories(name, short_title, desc)

    return {
        "registry_name": f"npm/{name}"[:200],
        "name": short_title[:160],
        "description": desc,
        "repo_url": repo_url,
        "homepage_url": homepage if homepage.startswith("https://") else "",
        "version": version[:60],
        "transport": "stdio",
        "auth_mode": "unknown",
        "remote_url": "",
        "env_keys": [],
        "packages": [{
            "manager": "npm",
            "identifier": name[:200],
            "version": version[:60],
            "transport": "stdio",
        }],
        "categories": categories,
        "stars": 0,
    }


async def _fetch_extra_online_sources(client: httpx.AsyncClient, max_items: int) -> list[dict]:
    """Добор онлайн MCP-серверов из проверенных каталогов GitHub и реестра npm."""
    if max_items <= 0 or _env("NJ676_EXTRA_SOURCES", "1").lower() in ("0", "false", "no", "off"):
        return []

    collected: list[dict] = []
    gh_hdrs = {**_gh_headers(), "Accept": "application/vnd.github.raw+json"}

    for repo, parser in (
        ("punkpeye/awesome-remote-mcp-servers", _parse_awesome_remote_punkpeye),
        ("jaw9c/awesome-remote-mcp-servers", _parse_awesome_remote_jaw9c),
    ):
        if len(collected) >= max_items:
            break
        try:
            resp = await client.get(f"https://api.github.com/repos/{repo}/readme", headers=gh_hdrs)
            if resp.status_code == 200 and resp.text:
                parsed = parser(resp.text)
                parsed.sort(key=lambda item: 0 if item.get("auth_mode") == "none" else 1)
                collected.extend(parsed[: max(0, max_items - len(collected))])
        except Exception as exc:  # noqa: BLE001
            log.info("nj676.registry: доп. источник %s недоступен: %s", repo, exc.__class__.__name__)

    if len(collected) < max_items:
        npm_need = min(250, max_items - len(collected))
        try:
            resp = await client.get(
                "https://registry.npmjs.org/-/v1/search",
                params={"text": "keywords:mcp-server", "size": npm_need},
            )
            if resp.status_code == 200:
                objects = (resp.json() or {}).get("objects") or []
                for obj in objects:
                    item = _pick_npm(obj)
                    if item is not None:
                        collected.append(item)
                        if len(collected) >= max_items:
                            break
        except Exception as exc:  # noqa: BLE001
            log.info("nj676.registry: поиск npm недоступен: %s", exc.__class__.__name__)

    return collected


async def handle(ctx, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    limit = max(10, min(_env_int("NJ676_REGISTRY_MAX", 5000, 10, 20000), int(payload.get("limit") or 2500)))
    page_size = _env_int("NJ676_REGISTRY_PAGE", 100, 10, 100)
    delay = float(_env("NJ676_REGISTRY_DELAY", "0.15") or 0.15)

    base = _site_base()
    if not base:
        raise RuntimeError("не настроен адрес сайта: NJ676_API_BASE (или NJ120_API_BASE) в workers/.env")

    stats = {"found": 0, "added": 0, "updated": 0, "skipped": 0, "probed": 0}
    errors: list[str] = []
    cursor: str | None = None
    batch: list[dict] = []
    started = time.monotonic()

    async def progress(text: str) -> None:
        await _site_post(base, "/discover-progress", {"run_id": run_id, "phase": text})

    async def flush(force: bool = False) -> None:
        nonlocal batch
        if not batch or (len(batch) < 25 and not force):
            return

        ok, reason, answer = await _site_post(base, "/registry-batch", {
            "run_id": run_id,
            "items": batch,
            "progress": f"Записал в каталог: {stats['found']} записей реестра",
        })

        if ok:
            stats["added"] += int(answer.get("added") or 0)
            stats["updated"] += int(answer.get("updated") or 0)
            stats["skipped"] += int(answer.get("skipped") or 0)
        else:
            errors.append(f"сайт: {reason}")
            log.warning("nj676.registry: пачка не принята: %s", reason)

        batch = []

    try:
        await progress("Читаю официальный реестр MCP…")

        timeout = httpx.Timeout(25.0, connect=10.0)
        headers = {"User-Agent": "n2j-mcphub-registry/1.0", "Accept": "application/json"}

        async with httpx.AsyncClient(timeout=timeout, follow_redirects=True, headers=headers) as client:
            seen_keys: set[str] = set()

            while stats["found"] < limit:
                await ctx.heartbeat()

                entries, cursor, error = await _page(client, cursor, page_size)

                if error:
                    errors.append(error)
                    log.warning("nj676.registry: страница не прочитана: %s", error)
                    break

                if not entries:
                    break

                for entry in entries:
                    item = _pick(entry)
                    if item is None:
                        stats["skipped"] += 1
                        continue

                    dedup_key = (item.get("remote_url") or item.get("registry_name") or "").lower()
                    if dedup_key:
                        seen_keys.add(dedup_key)

                    stats["found"] += 1
                    batch.append(item)

                    if len(batch) >= 25:
                        await flush(force=True)

                await progress(
                    f"Реестр: прочитано {stats['found']}, "
                    f"добавлено {stats['added']}, обновлено {stats['updated']}"
                )

                if not cursor:
                    break

                await asyncio.sleep(max(0.0, min(2.0, delay)))

            # Добираем проверенные онлайн MCP-эндпоинты (awesome-remote-mcp-servers)
            # и пакеты npm (keywords:mcp-server), если лимит прогона ещё не выбран.
            if stats["found"] < limit:
                await ctx.heartbeat()
                await progress("Добираю онлайн MCP-эндпоинты из внешних каталогов…")
                extra_items = await _fetch_extra_online_sources(client, limit - stats["found"])
                for item in extra_items:
                    dedup_key = (item.get("remote_url") or item.get("registry_name") or "").lower()
                    if dedup_key and dedup_key in seen_keys:
                        continue
                    if dedup_key:
                        seen_keys.add(dedup_key)

                    stats["found"] += 1
                    batch.append(item)

                    if len(batch) >= 25:
                        await flush(force=True)
                        await ctx.heartbeat()

        await flush(force=True)

        # Живой статус ставит сайт (карточка → «Проверить» или admin-действие):
        # проверять чужие адреса из воркера — это чужой DNS в нашем процессе,
        # а на сайте уже есть проверка с ограничением на публичные хосты.

        note = f"добавлено {stats['added']}, обновлено {stats['updated']}"
        await _site_post(base, "/registry-finish", {
            "run_id": run_id,
            "status": "done",
            "error": "",
            "progress": f"Реестр MCP: {note}" + (f" · {errors[0]}" if errors else ""),
            "found": stats["found"],
            "added": stats["added"],
            "updated": stats["updated"],
            "skipped": stats["skipped"],
            "probed": stats["probed"],
        })

        log.info("nj676.registry: прогон %s завершён за %.1f с: %s",
                 run_id, time.monotonic() - started, note)

        return {"run_id": run_id, "source": "registry", **stats, "errors": errors[:3]}

    except Exception as exc:  # noqa: BLE001 — финал обязан быть виден
        reason = str(exc)[:500] or exc.__class__.__name__

        try:
            await _site_post(base, "/registry-finish", {
                "run_id": run_id,
                "status": "fail",
                "error": reason,
                "progress": "Импорт реестра остановлен ошибкой",
                **{key: stats[key] for key in ("found", "added", "updated", "skipped", "probed")},
            })
        except Exception:  # noqa: BLE001
            log.warning("nj676.registry: финал не доставлен")

        raise


# ---------------------------------------------------------------------------
# Проверка удалённых серверов (канал nj676.probe)
# ---------------------------------------------------------------------------

CHANNEL_PROBE = "nj676.probe"


async def handle_probe(ctx, payload: dict) -> dict:
    """Проверить удалённые MCP-серверы из каталога и вернуть живой статус.

    Сайт присылает список {slug, url} (не больше NJ676_PROBE_MAX за прогон).
    Воркер делает initialize + tools/list тем же кодом, что и автосборка, и
    отправляет результат на POST /mcphub/api/probe — карточка получает статус,
    задержку и реальные схемы инструментов.
    """
    base = _site_base()
    if not base:
        raise RuntimeError("не настроен адрес сайта: NJ676_API_BASE (или NJ120_API_BASE) в workers/.env")

    targets = payload.get("targets")
    if not isinstance(targets, list):
        targets = []

    # Потолок — на случай, если сайт пришлёт больше, чем воркер успеет за
    # таймаут задачи. Кнопка в админке даёт выбрать 24/48/60, и все выбранные
    # должны реально провериться: раньше здесь всегда резалось до 24, и
    # «60 серверов» в настройке были обманом.
    limit = _env_int("NJ676_PROBE_MAX", 60, 1, 60)
    concurrency = _env_int("NJ676_PROBE_CONCURRENCY", 6, 1, 16)
    targets = [t for t in targets if isinstance(t, dict) and t.get("url")][:limit]

    stats = {"checked": 0, "up": 0, "down": 0}
    sem = asyncio.Semaphore(concurrency)

    async def _probe_one(target: dict) -> None:
        slug = str(target.get("slug") or "")[:100]
        url = str(target.get("url") or "")
        if not slug or not url:
            return

        async with sem:
            await ctx.heartbeat()
            ok, latency, note, tools = await _probe_remote(url)

            stats["checked"] += 1
            stats["up" if ok else "down"] += 1

            sent, reason, _answer = await _site_post(base, "/probe", {
                "slug": slug,
                "ok": ok,
                "latency_ms": latency,
                "error": "" if ok else note,
                "tools": tools,
            })

            if not sent:
                log.warning("nj676.probe: результат %s не принят: %s", slug, reason)

    if targets:
        await asyncio.gather(*[_probe_one(t) for t in targets])

    run_id = int(payload.get("run_id") or 0)
    if run_id:
        await _site_post(base, "/discover-finish", {
            "run_id": run_id,
            "status": "done",
            "progress": f"Проверено {stats['checked']}: ответили {stats['up']}, не ответили {stats['down']}",
            "found": stats["checked"],
            "added": 0,
            "updated": stats["up"],
            "skipped": stats["down"],
            "probed": stats["up"],
        })

    log.info("nj676.probe: проверено %s, ответили %s", stats["checked"], stats["up"])

    return {"source": "probe", **stats}
