# prx398_doc_router — маршрутизация документов

Асинхронная маршрутизация PDF: OCR → classify → extract → validate → route/retry/manual; LangGraph checkpoint.

## Границы
- Доступ UI: сессия + ACL `prx398.ui`. API: Bearer + scope через prx150.
- Очередь: prx120, канал см. `config/prx_module.php`. Текст/PDF не хранить в очереди и БД модуля; файлы через `Storage::` (prx212).
- Владелец/права — в `Policy_*`, не в LLM. Чужие задачи недоступны (404).
- Не читать чужие таблицы; платформенные сервисы только через публичные контракты.

## Проверки
- Worker: `PYTHONPATH=workers python -m pytest workers/tests -q`
- Манифест: `python .tools/manifest_check.py`
