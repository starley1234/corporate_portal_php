# prx397_invoice — поля из счёта/УПД

Асинхронное извлечение полей счёта/УПД из PDF: OCR → LLM structured output → проверки → done/review/failed.

## Границы
- Доступ UI: сессия + ACL `prx397.ui`. API: Bearer + scope через prx150.
- Очередь: prx120, канал `invoice.extract` (см. `config/prx_module.php`). Текст/PDF не хранить в очереди и БД модуля; файлы через `Storage::` (prx212). Задача ставится с `visitor_id` (правило prx120).
- Владелец/права — в `Policy_*`, не в LLM. Чужие задачи недоступны (404).
- Не читать чужие таблицы; платформенные сервисы только через публичные контракты.

## Поведение
- Статус: `failed` очереди показывается как `failed`, а не «В очереди»: повтор не заявлен.
- `error` отдаётся только для кодов из `FAILED_CODES` (Service_Prx397_Extraction), остальное — `job_failed`.
- `PRX397_MAX_LLM_CHARS` — символы; OCR-байтовый предохранитель считается как `4×лимит + 64 КиБ`, решение — по длине текста. Превышение → терминальный `text_too_large_for_llm`.

## Проверки
- Worker: `PYTHONPATH=workers python -m pytest workers/tests -q`
- Манифест: `python .tools/manifest_check.py`
