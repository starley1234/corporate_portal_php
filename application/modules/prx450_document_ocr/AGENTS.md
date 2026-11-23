# prx450_document_ocr — распознавание документов

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx450_document_ocr/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 14 | 0,4 КБ |
|   Контракты | 20 | 0,9 КБ |
|   Переключение шлюза | 33 | 0,4 КБ |
|   Состояние | 42 | 0,4 КБ |
|   Карта | 48 | 0,4 КБ |
|   Проверки | 60 | 0,2 КБ |
<!-- context-index:end -->
## Назначение

Внешний OCR-шлюз как сервис портала: потребитель с ключом prx150 загружает
PDF/скан, получает Markdown/JSON. Модуль не хранит документы и не реализует OCR
сам — он проверяет файл и параметры, зовёт шлюз и нормализует ответ.

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
- Событие: `prx450.document.parsed` (sha256, engine, duration_ms, cached).

## Переключение шлюза

Один контракт `/parse` используют боевой шлюз и локальная обёртка над
Tesseract (`services/tesseract-ocr/`). Меняется только `PRX450_OCR_BASE_URL`:
- production: `https://ocr-gateway.example`;
- dev/test: `http://127.0.0.1:8090` (FastAPI + tesseract, только локально).

Подробности — `docs/integrations/document-ocr.md`.

## Состояние

Каркас готов: API, страница проверки, конфиг шлюза, валидация и событие.
Не проверено на PHP-стенде: реальный шлюз, 413/415/422/502/504, локальная
Tesseract-обёртка. Миграций нет — документы не хранятся.

## Карта

| что | где |
|---|---|
| маршруты | `init.php` |
| манифест и scope | `config/prx_module.php` |
| настройки | `config/prx450_document_ocr.php` |
| API | `classes/Controller/Prx450/Api.php` |
| страница | `classes/Controller/Prx450/Page.php`, `views/450/index.php` |
| шлюз и валидация | `classes/Service/Prx450/Ocr.php` |
| ошибки | `classes/Prx450/Error.php` |

## Проверки

`make check` (манифест, маршруты, ссылки), `bash .tools/php_check_all.sh`.
На стенде: ключ со scope `prx450.parse`, пробный PDF, 401/403/413/415/422/502,
журнал `/prx150_api_hub/usage`.
