# PRX workers

`prx_workers/` — runtime (очередь, сторожа, конфиг) и `handlers/<модуль-владелец>/`
— по пакету на канал; `prx390_intake/` и `prx392_analysis/` — условный документооборот, включается
только на стенде. Активные каналы: echo, embeddings.index, llm.summarize,
semantic.index, document.extract. Зависимости — requirements.txt; тестовые —
requirements-dev.txt. Секреты — workers/.env (создаёт bin/setup.sh).

Запуск: `python3 -m prx_workers` из workers/. Тесты (из корня репозитория):
`PYTHONPATH=workers python3 -m pytest workers/tests -q`.
Структура, правила и новый канал: [../docs/WORKERS.md](../docs/WORKERS.md).
