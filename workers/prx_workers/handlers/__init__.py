"""Реестр каналов.

Пакет обработчика называется как модуль-владелец в application/modules/<имя>;
канал из `worker.channels` его манифеста — это файл с CHANNEL в этом пакете.
Явная регистрация — только здесь. Платформенные каналы грузятся всегда,
условные каналы — только при включении в PHP.

Загрузка ЛЕНИВАЯ: каждый обработчик импортируется отдельно, и ошибка импорта
(например, у модуля не стоит необязательная зависимость — `pydantic`,
`langgraph`) отключает ТОЛЬКО его канал, а не весь воркер. Раньше один такой
модуль валил старт воркера целиком.
"""
import importlib
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

# Платформенные обработчики: (пакет-владелец, модуль). Импортируются при старте.
REGISTRY = (
    ("prx120_jobs", "echo"),
    ("prx120_jobs", "embeddings"),
    ("prx115_semantic", "index"),
    ("llm", "summarize"),
    ("prx395_document_extract", "handler"),
    ("prx396_pdf_chunks", "handler"),
    ("prx397_invoice", "handler"),
    ("prx398_doc_router", "handler"),
)

# Условные каналы пробы пера: импортируются только при явном включении в PHP.
CONDITIONAL_REGISTRY = (
    ("prx390_intake", "normalize"),
    ("prx392_analysis", "analyze"),
)


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


def _load(registry) -> dict:
    """Загрузить обработчики: сбой импорта одного — не смерть остальных."""
    handlers: dict = {}

    for package, name in registry:
        try:
            module = importlib.import_module(f".{package}.{name}", __package__)
        except Exception as exc:  # noqa: BLE001 — изолируем один канал
            log.error("Канал %s/%s недоступен: %s: %s. Проверьте зависимости "
                      "(workers/requirements.txt) и перезапустите воркер.",
                      package, name, type(exc).__name__, exc)
            continue
        handlers[module.CHANNEL] = module.handle

    return handlers


HANDLERS = _load(REGISTRY)


def active_handlers(trial_enabled: bool = False):
    handlers = dict(HANDLERS)
    if trial_enabled:
        handlers.update(_load(CONDITIONAL_REGISTRY))
    return handlers
