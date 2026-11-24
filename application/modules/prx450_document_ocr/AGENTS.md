# prx450_document_ocr — распознавание документов

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx450_document_ocr/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 15 | 0,4 КБ |
|   Синхронный или асинхронный | 21 | 0,5 КБ |
|   Контракты | 28 | 1,1 КБ |
|   Переключение шлюза | 43 | 0,4 КБ |
|   Состояние | 52 | 0,7 КБ |
|   Карта | 61 | 0,4 КБ |
|   Проверки | 73 | 0,7 КБ |
<!-- context-index:end -->
## Назначение

Внешний OCR-шлюз как сервис портала: потребитель с ключом prx150 загружает
PDF/скан, получает Markdown/JSON. Модуль не хранит документы и не реализует OCR
сам — он проверяет файл и параметры, зовёт шлюз и нормализует ответ.

## Синхронный или асинхронный

`prx450` — синхронный: один документ, ответ сразу, файл не хранится.
`prx395` — асинхронный: пачки PDF, очередь `prx120` + Tesseract, ответ `202` +
polling, приватные артефакты `prx212` с TTL 24 ч. Модули не дублируют друг
друга — это разные задачи; оба остаются.

## Контракты

- Машинный API: `POST /api/v1/document-ocr/parse`, multipart `file`, query-
  параметры из OpenAPI шлюза; ответ — `{file, content, cached, cache_source,
  engine_used, llm_fixed, raw_result}`.
- Доступ: ключ prx150 + scope `prx450.parse`; лимит — в ключе.
- Страница проверки: `/prx450_document_ocr` (право `prx450.view`, CSRF).
- Настройки: `config/prx450_document_ocr.php`; секреты — только в `.env`
  (`PRX450_OCR_BASE_URL`, `PRX450_OCR_API_KEY`).
- Параметры потребителя ограничены `client_overrides`; остальное — только конфиг.
- Ошибки: `Prx450_Error` (наследует `Prx150_Error`) → JSON, без внутренностей.
- Событие: `prx450.document.parsed` (engine, duration_ms, cached, api_client_id);
  пользователь передаётся отдельным initiator_id. Отпечаток документа не публикуется.
- Машинный контракт: `openapi/prx450.yaml`; ключи сверяются с whitelist тестом.

## Переключение шлюза

Один контракт `/parse` используют боевой шлюз и локальная обёртка над
Tesseract (`services/tesseract-ocr/`). Меняется только `PRX450_OCR_BASE_URL`:
- production: `https://ocr-gateway.example`;
- dev/test: `http://127.0.0.1:8090` (FastAPI + tesseract, только локально).

Подробности — `docs/integrations/document-ocr.md`.

## Состояние

Реализованы синхронный API/UI, ограничение multipart по post_max_size,
проверки MIME + сигнатуры, нейтральное имя, лимиты content/raw_result/ответа
шлюза, no-store, UI busy/таймер и клиентский экспорт без хранения.
Дизайн-аудит: 0 ошибок / 0 замечаний, модуль уже в DONE и DARK_DONE.
Контракты и fake-gateway тесты описаны ниже; настоящий шлюз, прокси/FPM и
production-коды отказов локально не проверены. Очередь и миграции не нужны.

## Карта

| что | где |
|---|---|
| маршруты | `init.php` |
| манифест и scope | `config/prx_module.php` |
| настройки | `config/prx450_document_ocr.php` |
| API | `classes/Controller/Prx450/Api.php` |
| страница | `classes/Controller/Prx450/Page.php`, `views/prx450/index.php` |
| шлюз и валидация | `classes/Service/Prx450/Ocr.php` |
| ошибки | `classes/Prx450/Error.php` |

## Проверки

`make check`, `npm test`, `make test-contracts`, `python3 .tools/design_audit.py --check`.
`tests/Contracts/prx450_ocr.php` проверяет параметры, magic bytes, 413,
нормализацию и лимиты. `tests/Smoke/OcrGatewayIntegrationTest.php` проверяет
настоящий cURL multipart, отказ от редиректа и ограничение тела на фейковом шлюзе.
`tests/Js/prx450.test.mjs` — busy, повторный submit, blob и OpenAPI whitelist.
На стенде: ключ со scope `prx450.parse`, пробный PDF, 401/403/413/415/422/502,
журнал `/prx150_api_hub/usage`.
