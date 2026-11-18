"""Безопасный контекст диагностики doc.normalize, изолированный по задаче."""
from __future__ import annotations

from contextvars import ContextVar

# (job_id, message_id, file_id); 0 означает, что обработчик вызван вне очереди.
# asyncio.to_thread копирует контекст, поэтому OCR не теряет идентификаторы.
NORMALIZE_TRACE: ContextVar[tuple[int, int, int]] = ContextVar(
    "prx390_diagnostic_trace", default=(0, 0, 0))
