# PRX workers

Общий runtime: `prx_workers/`. Каналы: echo, embeddings.index,
llm.summarize, semantic.index, document.extract; пробные doc.normalize и
doc.analyze включаются только на стенде (см. docs/WORKERS.md). Зависимости — requirements.txt; тестовые —
requirements-dev.txt. Секреты — workers/.env (создаёт bin/setup.sh).

Запуск: `python3 -m prx_workers` из workers/.
Документация и расписание обслуживания PHP outbox: [../docs/WORKERS.md](../docs/WORKERS.md).
