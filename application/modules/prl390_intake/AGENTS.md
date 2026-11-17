# prl390_intake — приём входящих документов

## Состояние

MVP: папка на сервере + ручная загрузка PDF, дедупликация по SHA-256, файл в
prl212, канал `doc.normalize`, текст и событие `prl390.text.ready`. Почтового
канала (IMAP) ещё нет — он добавит тот же конверт, не меняя контракт.

## Границы

Модуль владеет `prl_390_channels`, `prl_390_messages`, `prl_390_files`,
`prl_390_texts`. Карточку не создаёт: это делает prl391 по событию. Файлы
хранит prl212; модуль только ссылается на `file_id`.

## Маршруты

- `/inbox` — журнал конвертов, показатели, папка и ручная загрузка.
- `POST /inbox/scan`, `POST /inbox/upload`, `POST /inbox/retry/<id>`.
- `/prl390/api/health|file/<file_id>|text/<message_id>` — API воркера,
  токен `X-Worker-Token` (`PRL390_SITE_TOKEN` → `WORKER_SITE_TOKEN` →
  `WORKER_API_TOKEN`).
- CLI-сканирование: `bin/console prl390:scan` (для cron).

## Права

`prl390.view` — `login`, `prl390.manage` — `docflow_clerk`,
`docflow_manager`. Роли создаёт миграция этого модуля.

## Конвейер

1. `receive_path` проверяет расширение, MIME, размер, считает SHA-256.
2. Дубликат возвращает `duplicate` и файл уходит в `duplicates/`.
3. `Service_Prl212_Storage::save()` кладёт файл, затем пишется конверт.
4. `doc.normalize` (payload `message_id`) → текст → `POST /prl390/api/text/<id>`.
5. `store_text` ставит `text_ready` (≥ 40 символов) или `needs_ocr` и
   публикует `prl390.text.ready`.

Слушатель события — в `init.php` этого модуля; он вызывает
`Service_Prl391_Documents::register_from_message()`.

## Настройки и данные

`config/prl390_intake.php`: `inbox_dir`, `processed_dir`, `duplicates_dir`,
`error_dir`, лимит размера. Пути по умолчанию — `public_html/storage/docflow/*` (закрыты от прямой отдачи)
(gitignored). Демо-PDF — `examples/docflow/`, команда `make docflow-demo`.

Текст старше `PRL390_TEXT_RETENTION_DAYS` (365) удаляется manifest-cleanup;
конверты и карточки остаются.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`,
`python3 -m pytest workers/tests/test_docflow.py`.
