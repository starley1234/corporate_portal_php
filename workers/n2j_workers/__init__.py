"""Асинхронные воркеры платформы N2J.

Забирают задачи из очереди nj_120_jobs (PostgreSQL или HTTP-API модуля
nj120_jobs) и выполняют обработчики каналов из n2j_workers.handlers.

Точка входа: python -m n2j_workers
Документация: docs/WORKERS.md (в корне репозитория).
"""

__version__ = "0.1.0"
