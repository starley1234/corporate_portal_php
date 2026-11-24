# PRX workers

`prx_workers/` — runtime (очередь, сторожа, конфиг) и `handlers/<модуль-владелец>/`
— по пакету на канал; `prx390_intake/` и `prx392_analysis/` — условный документооборот, включается
только на стенде. Активные каналы: echo, embeddings.index, llm.summarize,
semantic.index, document.extract. Зависимости — requirements.txt; тестовые —
requirements-dev.txt. Секреты — workers/.env (создаёт bin/setup.sh).

Запуск: `python3 -m prx_workers` из workers/. Тесты (из корня репозитория):
`PYTHONPATH=workers python3 -m pytest workers/tests -q`.
Структура, правила и новый канал: [../docs/WORKERS.md](../docs/WORKERS.md).

### Последние ответы разбора документов (prx392)

Перед разбором JSON и отправкой callback `doc.analyze` сохраняет ответ модели
в `workers/logs/prx392/run-<run_id>.json` (путь относительно каталога workers,
независимо от cwd). Один последний ответ на прогон, максимум 20 файлов;
ответ ограничен 64 КиБ, наличие обрезки отмечено `truncated`.
Файл содержит модель, время, ответ и состояние `received`, `invalid_json`,
`delivery_failed` или `delivered`; при отказе callback — причину отказа.
Промпты и HTTP-заголовки не записываются. Каталог 0700, файлы 0600,
вне web-root и Git. Ответ может содержать персональные/коммерческие сведения:
перед передачей файла удалите чувствительные данные. Публичной ссылки нет;
заберите файл с хоста воркера. Для удаления журнала удалите эти JSON-файлы.
Ответы прежних запусков восстановить этим журналом нельзя.
