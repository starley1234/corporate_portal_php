"""Асинхронные воркеры платформы PRL.

Забирают задачи из очереди prl_120_jobs (PostgreSQL или HTTP-API модуля
prl120_jobs) и выполняют обработчики каналов из prl_workers.handlers.

Точка входа: python -m prl_workers
Документация: docs/WORKERS.md (в корне репозитория).
"""

__version__ = "0.1.0"
