"""Платформенные каналы. Доменные обработчики добавляются явно."""
from . import echo, embeddings, llm_task, semantic_index

HANDLERS = {
    echo.CHANNEL: echo.handle,
    embeddings.CHANNEL: embeddings.handle,
    llm_task.CHANNEL: llm_task.handle,
    semantic_index.CHANNEL: semantic_index.handle,
}
