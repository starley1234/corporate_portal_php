"""Реестр каналов.

Пакет обработчика называется как модуль-владелец в application/modules/<имя>;
канал из `worker.channels` его манифеста — это файл с CHANNEL в этом пакете.
Явная регистрация — только здесь. Платформенные каналы грузятся всегда,
условные каналы — только при включении в PHP.
"""
import logging

import httpx

from .. import site

CONDITIONAL_CHANNELS = frozenset({"doc.normalize", "doc.analyze"})

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


from .prx120_jobs import echo, embeddings
from .prx115_semantic import index as semantic_index
from .llm import summarize as llm_task
from .prx395_document_extract import handler as document_extract
from .prx396_pdf_chunks import handler as pdf_chunks
from .prx397_invoice import handler as invoice_extract
from .prx398_doc_router import handler as doc_route


HANDLERS = {
    document_extract.CHANNEL: document_extract.handle,
    pdf_chunks.CHANNEL: pdf_chunks.handle,
    invoice_extract.CHANNEL: invoice_extract.handle,
    doc_route.CHANNEL: doc_route.handle,
    echo.CHANNEL: echo.handle,
    embeddings.CHANNEL: embeddings.handle,
    llm_task.CHANNEL: llm_task.handle,
    semantic_index.CHANNEL: semantic_index.handle,
}

def active_handlers(trial_enabled: bool = False):
    handlers = dict(HANDLERS)
    if trial_enabled:
        from .prx390_intake import normalize
        from .prx392_analysis import analyze

        handlers.update({
            normalize.CHANNEL: normalize.handle,
            analyze.CHANNEL: analyze.handle,
        })
    return handlers
