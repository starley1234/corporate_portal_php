# prx396_pdf_chunks — большие PDF по частям

Асинхронный разбор большого PDF по частям через очередь prx120 и Python-воркер.

## Слои (пилот Д8, 25.11.2026)
- `Service/Prx396/Extraction` — сценарий: валидация, статусы/lease, Storage,
  очередь prx120, идемпотентность, транзакции (`Prx_Db::transaction`).
- `Model/Prx396/{Batches,Parts}` — только запросы: один публичный метод = один
  запрос, только `:param` (динамические VALUES в `insert_ranges` — из int),
  без логики/HTTP/Storage; возврат array/int. Транзакцию владеет Service.
  Гейт Д1 сканирует Model вместе с Service.

## Границы
- Доступ UI: сессия + ACL `prx396.ui`. API: Bearer + scope через prx150.
- Очередь: prx120, канал см. `config/prx_module.php`. Текст/PDF не хранить в очереди и БД модуля; файлы через `Storage::` (prx212).
- Владелец/права — в `Policy_*`, не в LLM. Чужие задачи недоступны (404).
- Не читать чужие таблицы; платформенные сервисы только через публичные контракты.

## Проверки
- Worker: `PYTHONPATH=workers python -m pytest workers/tests -q`
- Манифест: `python .tools/manifest_check.py`
