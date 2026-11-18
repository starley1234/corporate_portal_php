"""Платформенные каналы. Доменные обработчики добавляются явно."""
import os

from . import document_extract, echo, embeddings, llm_task, semantic_index

HANDLERS = {
    document_extract.CHANNEL: document_extract.handle,
    echo.CHANNEL: echo.handle,
    embeddings.CHANNEL: embeddings.handle,
    llm_task.CHANNEL: llm_task.handle,
    semantic_index.CHANNEL: semantic_index.handle,
}


def active_handlers():
    handlers = dict(HANDLERS)
    if os.getenv("PRX_DOCFLOW_ENABLED") == "1":
        from . import doc_analyze, doc_normalize

        handlers.update({doc_analyze.CHANNEL: doc_analyze.handle, doc_normalize.CHANNEL: doc_normalize.handle})
    return handlers
