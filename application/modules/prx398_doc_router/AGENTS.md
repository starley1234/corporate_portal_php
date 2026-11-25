# prx398_doc_router — маршрутизация документов

Асинхронная маршрутизация PDF: OCR → classify → extract → validate → route/retry/manual; LangGraph checkpoint.

## Границы
- Доступ UI: сессия + ACL `prx398.ui`. API: Bearer + scope через prx150.
- Очередь: prx120, канал `doc.route` (см. `config/prx_module.php`). Текст/PDF не хранить в очереди и БД модуля; файлы через `Storage::` (prx212). Задача ставится с `visitor_id` (правило prx120).
- Владелец/права — в `Policy_*`, не в LLM. Чужие задачи недоступны (404).
- Не читать чужие таблицы; платформенные сервисы только через публичные контракты.

## Поведение
- Граф: `ocr → classify → extract → validate → route|retry|manual`. `retry` возвращает в `classify` (уверенность даёт классификация), при исчерпании повторов — `manual`.
- Статус: `failed` очереди показывается как `failed`; `error` — только коды из `FAILED_CODES` (Service_Prx398_Route).
- Чекпойнт LangGraph (thread_id = job_id) хранит OCR-текст временно: воркер удаляет поток через `adelete_thread` после терминального результата, `prx398:purge` добирает остаток guarded-DELETE по `thread_id`, если таблицы чекпойнта видны в схеме очереди. Свой DSN для режима `WORKER_QUEUE_MODE=http` — `PRX398_CHECKPOINT_DSN`.

## Проверки
- Worker: `PYTHONPATH=workers python -m pytest workers/tests -q`
- Манифест: `python .tools/manifest_check.py`
