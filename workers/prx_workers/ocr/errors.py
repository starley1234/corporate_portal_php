"""Общие ошибки OCR-обработчиков PDF."""

from __future__ import annotations


class DocumentJobError(RuntimeError):
    """Временный отказ: сообщение — только стабильный код, подходит для очереди."""
