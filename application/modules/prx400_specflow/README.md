# prx400_specflow — Оцифровка спецификаций

Корпоративный сервис оцифровки заказных спецификаций, ведомостей покупных
изделий (ВП/BOM) и материальных ведомостей: скан → структурированная таблица
с математическим контролем → коды НСИ → проверка оператором → JSON для ERP.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx400_specflow/README.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Статусы спецификаций | 21 | 0,7 КБ |
|   Падение воркера | 31 | 0,9 КБ |
|   Цвета строк (Confidence Scoring) — `Service_Prx400_Match::row_status` | 41 | 0,8 КБ |
|   Справочник НСИ | 52 | 1,1 КБ |
|   Конфигурация | 64 | 0,4 КБ |
|   Внешнее API (prx150) | 70 | 0,7 КБ |
|   Хранение | 82 | 0,4 КБ |
|   Тесты | 88 | 0,4 КБ |
|   Деплой | 94 | 0,2 КБ |
<!-- context-index:end -->
## Статусы спецификаций
`queued → running → review (есть позиции на проверку) → done`; `failed` —
ошибка разбора с машинным кодом (`prx_400_specs.error`, текст —
`Prx400_Messages`). Отменённая задача во внешнем API — `canceled`.
Повтор, смена режима/шаблона и перезаливка PDF — на карточке.

Статус в работе сверяется с очередью только для показа (GET ничего не пишет);
записывает итог сверки `bin/console prx400:purge` (манифест `schedule`, раз в
10 минут исполняет `portal:maintain`).

## Падение воркера
Воркер замолчал посреди разбора (упал, перезапущен, завис) — обслуживание
очереди prx120 через ~4 минуты останавливает задачу, а `prx400:purge` по
`Service_Prx120_JobQueue::stop_reason()` ставит разбор заново с тем же режимом
и шаблоном (`PRX400_CRASH_RETRIES`, по умолчанию 1; счётчик —
`prx_400_specs.recoveries`, ручной запуск его обнуляет). Исчерпан — ошибка
`worker_lost`. Воркер падает на этом же файле (`worker_crash_loop`) или
задачу отменил человек (`job_canceled`) — не повторяется. Поздний результат
мёртвой попытки отклоняется (`lease_lost`).

## Цвета строк (Confidence Scoring) — `Service_Prx400_Match::row_status`
- **зелёный (ok)** — код НСИ привязан (≥ auto_accept или вручную);
- **жёлтый (review)** — лучший кандидат ≥ manual_review, либо дробное
  количество штучных изделий;
- **красный (new)** — кандидатов выше порога нет;
- **красный (error)** — не сошлась арифметика строки (qty × цена ≠ сумма).

Построчные флаги (`math_row`, `unit_fractional`) считает PHP
(`Service_Prx400_Checks`) — и при приёме результата, и после правки
оператором; `gap` (пропуск нумерации) приходит от воркера.

## Справочник НСИ
Страница «Справочник НСИ»: поиск, ручное добавление и загрузка выгрузки
CSV/XLSX (1С, MDM, Excel; `Service_Prx400_CatalogImport`). Первая строка —
заголовок: «Код», «Наименование» обязательны, «Ед. изм.», «ОКЕИ», «Ключевые
слова» — по желанию (латинские `code/name/unit/okei/keywords` тоже), прочие
столбцы пропускаются. «Проверить» ничего не пишет; ошибка хотя бы в одной
строке — файл не применяется, в отчёте номера строк. Upsert по коду в одной
транзакции, отсутствующие в файле позиции не удаляются. Выгрузка CSV
принимается импортом обратно. CLI: `bin/console prx400:catalog
--import=ФАЙЛ [--apply=1]` / `--export=ФАЙЛ`. Пределы:
`PRX400_CATALOG_IMPORT_MAX_ROWS`, `PRX400_CATALOG_IMPORT_MAX_BYTES`.

## Конфигурация
`config/prx400_specflow.php` — пороги матчинга и пределы из env с границами
(читать только через `Service_Prx400_Limits`); `config/templates/*.json` —
встроенные шаблоны форм; пользовательские — страница «Шаблоны».
Переменные — `.env.example`, раздел prx400.

## Внешнее API (prx150)
Ключ выпускается в хабе API со scope `prx400.extract`
(`Authorization: Bearer <ключ>`); ключ видит только свои спецификации.

1. `POST /api/v1/spec-extractions` — multipart `file` (+ `parse_mode`,
   `template_id`), заголовок `Idempotency-Key` → `202 {spec_id, status, status_url}`.
2. `GET /api/v1/spec-extractions/<id>` → `200 {status: queued|running|failed|canceled|review|done}`;
   для review/done — `result {meta, positions, audit}` (та же схема, что
   экспорт ERP с карточки) и `awaiting_operator`.

Человекочитаемый контракт: `/prx400_specflow/api-docs`.

## Хранение
Фасад `Storage` (prx212): PDF — артефакт модуля без пользователя
(`read_owned`/`delete_owned`), ссылка — `prx_400_specs.file_id`, целостность —
`file_sha256`. Файлы без записи старше 48 ч и записи старше
`PRX400_RETENTION_DAYS` (если > 0) убирает `prx400:purge`.

## Тесты
- PHP-контракт: `tests/Contracts/prx400_match_export.php` — `bin/test --contracts`.
- Smoke: `SpecflowPipelineTest`, `SpecflowRecoveryTest`, `SpecflowCatalogImportTest`;
  читатель таблиц — `tests/Unit/PrxSpreadsheetTest.php`.
- Python: `workers/tests/handlers/prx400_specflow/` (парсер, протокол воркера, vision).

## Деплой
`make migrate` (до `pgMigration_Prx_400_005_Platform`), рестарт воркеров
(канал `specflow.parse`), расписание `prx400:purge` из манифеста.
