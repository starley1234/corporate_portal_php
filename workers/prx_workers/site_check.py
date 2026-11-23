"""Проверка HTTP-связи с PHP без файлов, задач и вывода секретов.

Какие каналы ходят в PHP и куда — решают сами обработчики: в модуле обработчика
лежит SITE = (префикс env, путь API, модуль, чей токен: "worker" | "site").
runtime ни про один домен здесь не знает.
"""
from __future__ import annotations

import logging
import sys

import httpx

from . import site
from .handlers import active_handlers, CONDITIONAL_CHANNELS, HEALTH_FLAG

log = logging.getLogger("prx.site")


def targets(handlers) -> dict[str, tuple[str, str, str, str]]:
    """Канал → SITE для тех обработчиков из `handlers`, кому нужен PHP."""
    out = {}
    for channel, handler in handlers.items():
        spec = getattr(sys.modules[handler.__module__], "SITE", None)
        if spec:
            out[channel] = tuple(spec)
    return out


async def check_site(token: str, *, legacy: bool | None, transport=None) -> int:
    """Проверить каждый канал с SITE: адрес, токен, ответ /health.

    `legacy`: None — спросить у PHP, включена ли проба пера (handlers.enabled через
    health document.extract); True/False — явный режим. Ничего не пишет в лог,
    кроме безопасного происхождения адреса.
    """
    success = True
    async with httpx.AsyncClient(timeout=8, follow_redirects=False, transport=transport) as client:
        queue = list(targets(active_handlers(legacy is True)).items())
        index = 0
        while index < len(queue):
            channel, (prefix, path, module, token_kind) = queue[index]
            index += 1
            if channel in CONDITIONAL_CHANNELS and not legacy:
                continue
            base, source = site.module_api_config(prefix, path)
            name = f"{channel}: {site.safe_origin(base)} ({source})"
            key = token if token_kind == "worker" else site.site_token(prefix)[0]
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
                            if legacy is None and isinstance(body.get(HEALTH_FLAG), bool):
                                legacy = body[HEALTH_FLAG]
                                log.info("Пробные каналы (trial): %s", "включены" if legacy else "выключены")
                                if legacy:
                                    queue.extend(targets(active_handlers(True)).items())
                            elif legacy is None:
                                log.info("Пробные каналы (trial): PHP не сообщил состояние")
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
