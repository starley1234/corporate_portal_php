"""Платформенные каналы. Доменные обработчики добавляются явно."""
from . import document_extract, echo, embeddings, llm_task, semantic_index

HANDLERS = {
    document_extract.CHANNEL: document_extract.handle,
    echo.CHANNEL: echo.handle,
    embeddings.CHANNEL: embeddings.handle,
    llm_task.CHANNEL: llm_task.handle,
    semantic_index.CHANNEL: semantic_index.handle,
}
