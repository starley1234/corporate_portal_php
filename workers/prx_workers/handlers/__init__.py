"""Реестр каналов.

Пакет обработчика называется как модуль-владелец в application/modules/<имя>;
канал из `worker.channels` его манифеста — это файл с CHANNEL в этом пакете.
Явная регистрация — только здесь. Платформенные каналы грузятся всегда,
handlers/trial — только на стенде (спрашивает у PHP через trial.enabled).
"""
from .prx120_jobs import echo, embeddings
from .prx115_semantic import index as semantic_index
from .llm import summarize as llm_task
from .prx395_document_extract import handler as document_extract
from . import trial

HANDLERS = {
    document_extract.CHANNEL: document_extract.handle,
    echo.CHANNEL: echo.handle,
    embeddings.CHANNEL: embeddings.handle,
    llm_task.CHANNEL: llm_task.handle,
    semantic_index.CHANNEL: semantic_index.handle,
}

# Каналы пробы пера: воркер не падает, если их назвали в WORKER_CHANNELS,
# но выполняет их только на стенде.
TRIAL_CHANNELS = trial.CHANNELS


def active_handlers(trial_enabled: bool = False):
    handlers = dict(HANDLERS)
    if trial_enabled:
        from .trial.prx390_intake import normalize
        from .trial.prx392_analysis import analyze

        handlers.update({
            normalize.CHANNEL: normalize.handle,
            analyze.CHANNEL: analyze.handle,
        })
    return handlers
