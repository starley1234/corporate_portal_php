"""Асинхронные воркеры платформы PRX.

Забирают задачи из очереди prx_120_jobs (PostgreSQL или HTTP-API модуля
prx120_jobs) и выполняют обработчики каналов из prx_workers.handlers.

Точка входа: python -m prx_workers
Документация: docs/WORKERS.md (в корне репозитория).
"""

__version__ = "0.1.0"
