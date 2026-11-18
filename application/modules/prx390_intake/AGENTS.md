# prx390_intake — приём входящих документов

## Состояние

MVP: папка на сервере + ручная загрузка PDF, дедупликация по SHA-256, файл в
prx212, канал `doc.normalize`, текст и событие `prx390.text.ready`. Почтового
канала (IMAP) ещё нет — он добавит тот же конверт, не меняя контракт.

## Границы

Модуль владеет `prx_390_channels`, `prx_390_messages`, `prx_390_files`,
`prx_390_texts`. Карточку не создаёт: это делает prx391 по событию. Файлы
хранит prx212; модуль только ссылается на `file_id`.

## Маршруты

- `/inbox` — журнал конвертов, показатели, папка и ручная загрузка.
- `POST /inbox/scan`, `POST /inbox/upload`, `POST /inbox/retry/<id>`.
- `/prx390/api/health|file/<file_id>|text/<message_id>` — API воркера,
  токен `X-Worker-Token` (`PRX390_SITE_TOKEN` → `WORKER_SITE_TOKEN` →
  `WORKER_API_TOKEN`).
- CLI-сканирование: `bin/console prx390:scan` (для cron).

## Права

`prx390.view` — `login`, `prx390.manage` — `docflow_clerk`,
`docflow_manager`. Роли создаёт миграция этого модуля.

## Конвейер

1. `receive_path` проверяет расширение, MIME, размер, считает SHA-256.
2. Дубликат возвращает `duplicate` и файл уходит в `duplicates/`.
3. `Service_Prx212_Storage::save()` кладёт файл, затем пишется конверт.
4. `doc.normalize` (payload `message_id`) → текст → `POST /prx390/api/text/<id>`
   (`file_id`, `text`, `pages`, `engine`, `confidence`).
5. `store_text` ставит `text_ready` (≥ 40 символов) или `needs_ocr` и
   публикует `prx390.text.ready`.

Слушатель события — в `init.php` этого модуля; он вызывает
`Service_Prx391_Documents::register_from_message()`. Диагностика приёма,
API и регистрации использует `prx390_diag`; Python-этапы `doc.normalize`
сопоставляются по `job_id`, `message_id`, `file_id`. В эти записи не включать
имена файлов, текст, тело HTTP-ответа, адреса с секретами и значения токенов.
Порядок сбора — `docs/systemd-сервис.md`, шаг 7.

## Настройки и данные

`config/prx390_intake.php`: `inbox_dir`, `processed_dir`, `duplicates_dir`,
`error_dir`, лимит размера. Умолчания — `storage/docflow/*` в корне проекта,
вне public_html (gitignored); `ensure_dirs()` создаёт недостающие каталоги.
Старые значения `public_html/storage/*` перенаправляются, но файлы не копируются
автоматически: процедура остановки записи и переноса — `docs/ENVIRONMENT.md`.
Демо-PDF — `demo/`, `make docflow-demo` использует настроенный inbox и не
перезаписывает файлы. Воркер не сканирует папку: нужен `prx390:scan` отдельно.

Текст старше `PRX390_TEXT_RETENTION_DAYS` (365) удаляется manifest-cleanup;
конверты и карточки остаются.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`,
`python3 -m pytest workers/tests/test_docflow.py`.
