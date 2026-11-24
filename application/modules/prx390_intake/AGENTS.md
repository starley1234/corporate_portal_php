# prx390_intake — приём входящих документов

> Тестовый контур: подключение — явный общий блок в `application/bootstrap.php`; UI только для администратора. См. `docs/QUICK-CHECK.md`.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx390_intake/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние | 17 | 0,4 КБ |
|   Границы | 23 | 0,3 КБ |
|   Маршруты | 29 | 0,5 КБ |
|   Права | 38 | 0,7 КБ |
|   Конвейер | 48 | 1,2 КБ |
|   Настройки и данные | 65 | 1,0 КБ |
|   Проверки | 77 | 0,5 КБ |
<!-- context-index:end -->
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

Текущий `acl.php` ограничивает контур администратором; роли docflow,
созданные миграциями, сами по себе доступ не открывают.
`scan_folder/receive_upload/receive_path/retry` принимают `Prx_Actor` и
проверяют `prx390.manage` в сервисе. Пустой actor — гость, не система.
CLI `prx390:scan` использует явный `scan_folder_automatically()`:
это доверенная локальная cron-операция, инициатор в БД — NULL, не фиктивный admin.
Публичный HTTP-контроллер этот метод не вызывает; массивов `is_system` нет.

## Конвейер

1. `receive_path` проверяет расширение, MIME, размер, считает SHA-256.
2. Дубликат возвращает `duplicate` и файл уходит в `duplicates/`.
3. `Storage::save($file, 'prx390', $actor->id())` кладёт файл, затем пишется конверт.
4. `doc.normalize` (payload `message_id`) → текст → `POST /prx390/api/text/<id>`
   (`file_id`, `text`, `pages`, `engine`, `confidence`).
5. `store_text` ставит `text_ready` (≥ 40 символов) или `needs_ocr` и
   публикует `prx390.text.ready`.

Слушатель события — в `init.php` этого модуля; он вызывает
`Service_Prx391_Documents::register_from_message()`. Диагностика приёма,
API и регистрации использует `prx390_diag`; Python-этапы `doc.normalize`
сопоставляются по `job_id`, `message_id`, `file_id`. В эти записи не включать
имена файлов, текст, тело HTTP-ответа, адреса с секретами и значения токенов.
Порядок сбора — `docs/DEPLOY-SYSTEMD.md`, шаг 7.

## Настройки и данные

`config/prx390_intake.php`: `inbox_dir`, `processed_dir`, `duplicates_dir`,
`error_dir`, лимит размера. Умолчания — `storage/docflow/*` в корне проекта,
вне public_html (gitignored); `ensure_dirs()` создаёт недостающие каталоги.
Старые значения `public_html/storage/*` перенаправляются, но файлы не копируются
автоматически: процедура остановки записи и переноса — `docs/ENVIRONMENT.md`.
Демо-PDF — `demo/`. Кнопка «Принять пробное письмо» в `/inbox` не требует сканирования папки. Воркер не сканирует папку: нужен `prx390:scan` отдельно.

Текст старше `PRX390_TEXT_RETENTION_DAYS` (365) удаляется manifest-cleanup;
конверты и карточки остаются.

## Проверки

`python3 .tools/portal_check.py`, `bash .tools/php_check_all.sh`,
`python3 -m pytest workers/tests/handlers/prx390_intake/test_docflow.py`.

Типы/отказы без БД: `tests/Contracts/docflow_access.php`.
На PostgreSQL `DocflowAccessTest` проверяет скан временной папки, дедупликацию,
сохранение синтетического текста и события регистрации/маршрутизации. OCR не запускается.
