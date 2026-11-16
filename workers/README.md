# PRL workers

Общий runtime: `prl_workers/`. Каналы: echo, embeddings.index,
llm.summarize, semantic.index. Зависимости — requirements.txt; тестовые —
requirements-dev.txt. Секреты — workers/.env (создаёт bin/setup.sh).

Запуск: `python3 -m prl_workers` из workers/.
Документация и расписание обслуживания PHP outbox: [../docs/WORKERS.md](../docs/WORKERS.md).
