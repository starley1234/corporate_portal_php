"""Проба пера: документооборот prx390–prx393. Включается только на стенде.

Удаляется одним `rm -r handlers/trial` вместе с application/modules/prx39*;
runtime о документообороте не знает — только спрашивает здесь, включён ли он.
"""
from __future__ import annotations

import logging

import httpx

from ... import site

CHANNELS = frozenset({"doc.normalize", "doc.analyze"})

# PHP сам сообщает, подключены ли prx390/prx392: ключ в ответе /prx395/worker/health
# (Controller_Prx395_Worker::action_health). Спрашиваем через health самого
# document.extract, отдельного эндпоинта у пробы пера нет.
HEALTH_FLAG = "docflow_enabled"
HEALTH = ("PRX395_DOCUMENT_EXTRACT", "/prx395/worker")
log = logging.getLogger("prx.site")


async def enabled(token: str, *, transport=None) -> bool:
    """PHP — источник состояния; сбой связи не блокирует платформенные каналы."""
    base, source = site.module_api_config(*HEALTH)
    if not token or not site.valid_http_base(base):
        log.warning("Документооборот не проверен: задайте APP_URL и WORKER_API_TOKEN")
        return False
    try:
        async with httpx.AsyncClient(timeout=4, follow_redirects=False, transport=transport) as client:
            response = await client.get(base + "/health", headers={"X-Worker-Token": token})
        if response.status_code == 200:
            data = response.json()
            if (isinstance(data, dict) and data.get("ok") is True and data.get("module") == "prx395"
                    and isinstance(data.get(HEALTH_FLAG), bool)):
                return data[HEALTH_FLAG]
            raise ValueError
        reason = f"HTTP {response.status_code}"
    except (httpx.HTTPError, httpx.InvalidURL) as exc:
        reason = site.network_error(exc)
    except ValueError:
        reason = "PHP не сообщил состояние; проверьте версию сайта"
    log.warning("Документооборот не проверен: %s (%s) — %s. Запустите --check-site",
                site.safe_origin(base), source, reason)
    return False
