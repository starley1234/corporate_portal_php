# prx400_specflow — Оцифровка спецификаций

Корпоративный сервис оцифровки заказных спецификаций, ведомостей покупных
изделий (ВП/BOM) и материальных ведомостей: скан → структурированная таблица
с математическим контролем → коды НСИ → JSON для ERP.

## Статусы спецификаций
queued → running → review (есть спорные) → done; failed — ошибка разбора
(«Повторить разбор» на карточке).

## Цвета строк (Confidence Scoring)
- **зелёный (ok)** — найдено в НСИ с высокой уверенностью (≥ auto_accept);
- **жёлтый (review)** — низкая уверенность (manual_review…auto_accept), подтверждается оператором;
- **красный (new)** — не найдено в НСИ / ошибка арифметики строки.

## Конфигурация
`config/prx400_specflow.php` — пороги матчинга (auto_accept/manual_review),
лимиты; `config/templates/*.json` — шаблоны форм (декларативно, без кода).

## Тесты
- Python: `workers/tests/handlers/prx400_specflow/test_parse.py` (парсер, математика).
- PHP-контракт: `tests/Contracts/prx400_match_export.php` (шаблоны, матчинг, экспорт) — `make test-contracts`.

## Внешнее API (PDF → JSON)

Асинхронный интеграционный контур: токен в `.env` — `PRX400_API_TOKEN`
(пусто = API выключен), заголовок `X-PRX-API-Token`.

1. `POST /prx400_specflow/api/spec` — multipart: `pdf` (обязателен),
   `parse_mode` (`ocr|ocr_vision|vision`), `template_id` (пусто = авто).
   Ответ `202`: `{spec_id, status:"queued", poll_url}`.
2. `GET /prx400_specflow/api/spec/<id>` — поллинг: `202` в работе,
   `200` готово: `{status:"review"|"done", result:{meta,positions,audit},
   awaiting_operator}`; при ошибке разбора `{status:"failed", error:{code}}`.
Схема `result` идентична экспорту ERP страницы карточки. Страница для
интеграторов (человекочитаемый контракт + состояние API): `/prx400_specflow/api-docs`.

## Хранение
Фасад `Storage` (prx212): файлы переживают выкатки, путь — маркер
`prx212:<file_id>`. Легаси-локальные записи читаются с самолечением
(`Service_Prx400_Specs::resolve_pdf`); `PRX400_STORAGE_DIR` нужен только им.

## Деплой
`make migrate` (миграция prx_400_001) + рестарт воркеров (новый канал
specflow.parse в реестре). Наполнение `prx_400_catalog` — см. карточку модуля.
