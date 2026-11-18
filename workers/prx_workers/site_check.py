"""Проверка HTTP-связи с PHP без файлов, задач и вывода секретов."""
from __future__ import annotations

import logging

import httpx

from . import site

TARGETS = {
    "document.extract": ("PRX395_DOCUMENT_EXTRACT", "/prx395/worker", "prx395"),
    "doc.normalize": ("PRX390", "/prx390/api", "prx390"),
}
log = logging.getLogger("prx.site")


async def docflow_enabled(token: str, *, transport=None) -> bool:
    """PHP — источник состояния; сбой связи не блокирует платформенные каналы."""
    base, source = site.module_api_config(*TARGETS["document.extract"][:2])
    if not token or not site.valid_http_base(base):
        log.warning("Документооборот не проверен: задайте APP_URL и WORKER_API_TOKEN")
        return False
    try:
        async with httpx.AsyncClient(timeout=4, follow_redirects=False, transport=transport) as client:
            response = await client.get(base + "/health", headers={"X-Worker-Token": token})
        if response.status_code == 200:
            data = response.json()
            if (isinstance(data, dict) and data.get("ok") is True and data.get("module") == "prx395"
                    and isinstance(data.get("docflow_enabled"), bool)):
                return data["docflow_enabled"]
            raise ValueError
        reason = f"HTTP {response.status_code}"
    except (httpx.HTTPError, httpx.InvalidURL) as exc:
        reason = site.network_error(exc)
    except ValueError:
        reason = "PHP не сообщил состояние; проверьте версию сайта"
    log.warning("Документооборот не проверен: %s (%s) — %s. Запустите --check-site",
                site.safe_origin(base), source, reason)
    return False


async def check_site(token: str, *, legacy: bool | None, transport=None) -> int:
    success = True
    async with httpx.AsyncClient(timeout=8, follow_redirects=False, transport=transport) as client:
        for channel, (prefix, path, module) in TARGETS.items():
            if channel == "doc.normalize" and not legacy:
                continue
            base, source = site.module_api_config(prefix, path)
            name = f"{channel}: {site.safe_origin(base)} ({source})"
            key = token if channel == "document.extract" else site.site_token(prefix)[0]
            if not site.valid_http_base(base):
                log.error("%s — укажите APP_URL или PRX120_API_BASE (корень сайта)", name)
            elif not key:
                log.error("%s — токен воркера не задан", name)
            else:
                try:
                    response = await client.get(base + "/health", headers={"X-Worker-Token": key})
                except (httpx.HTTPError, httpx.InvalidURL) as exc:
                    log.error("%s — %s", name, site.network_error(exc))
                else:
                    if response.status_code == 200:
                        try:
                            body = response.json()
                        except ValueError:
                            body = None
                        if isinstance(body, dict) and body.get("ok") is True and body.get("module") == module:
                            log.info("%s — OK, PHP принял токен", name)
                            if channel == "document.extract" and legacy is None:
                                state = body.get("docflow_enabled")
                                legacy = state is True
                                label = "включён" if legacy else "выключен" if state is False else "PHP не сообщил состояние"
                                log.info("Документооборот: %s", label)
                            continue
                        log.error("%s — HTTP 200, но ответ не от ожидаемого API; проверьте адрес и прокси", name)
                    else:
                        status = response.status_code
                        hint = ("проверьте токен и правила прокси" if status in (401, 403) else
                                "проверьте адрес, версию PHP-кода и включение модуля" if status == 404 else
                                "задайте конечный URL без перенаправления" if 300 <= status < 400 else
                                "проверьте PHP и веб-сервер")
                        log.error("%s — HTTP %s: %s", name, status, hint)
            success = False
    return 0 if success else 1
