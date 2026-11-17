"""Платформенные каналы. Доменные обработчики добавляются явно."""
from . import doc_analyze, doc_draft, doc_normalize, echo, embeddings, llm_task, semantic_index

HANDLERS = {
    echo.CHANNEL: echo.handle,
    embeddings.CHANNEL: embeddings.handle,
    llm_task.CHANNEL: llm_task.handle,
    semantic_index.CHANNEL: semantic_index.handle,
    # Документооборот prx390–prx394
    doc_normalize.CHANNEL: doc_normalize.handle,
    doc_analyze.CHANNEL: doc_analyze.handle,
    doc_draft.CHANNEL: doc_draft.handle,
}
