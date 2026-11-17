# Очередь и эксплуатационные задачи PRX

## Процесс воркера

`workers/prx_workers` — общий runtime: claim, heartbeat, progress,
complete/fail, retry/backoff, отмена, watchdog, graceful shutdown и usage.
Режим `pg` использует asyncpg и LISTEN/NOTIFY; `http` обращается к сайту
через `/prx120/api/*` с `X-Worker-Token`.

```bash
python3 -m pip install -r workers/requirements.txt
cd workers && python3 -m prx_workers
```

Настройки — `workers/.env` (процесс важнее файла). `WORKER_QUEUE_MODE=pg|http`,
`WORKER_PG_DSN`, `PRX120_API_URL`, `WORKER_API_TOKEN`, `WORKER_CHANNELS`,
`WORKER_CONCURRENCY`, `WORKER_POLL_INTERVAL`. Логи — `workers/logs/prx-worker.log`.
Токен воркера должен совпадать с сайтом; разные серверы используют публичный
адрес сайта, не localhost. В браузерном JS — только относительные URL.

| Канал | Что делает |
|---|---|
| `echo` | Дымовая проверка runtime |
| `llm.summarize` | LLM-запрос из payload, возвращает текст |
| `embeddings.index` | Возвращает sample векторов для инфраструктурных задач |
| `semantic.index` | Индексирует тексты коллекции prx115 через PG или HTTP |
| `doc.normalize` | PDF/текст из prx212 → текстовый слой (pypdf; OCR, если <100 осмысленных символов) |
| `doc.analyze` | Карточка-предложение по тексту; ответ только JSON (`parse_json_reply`) |
| `doc.draft` | Проект ответа по шаблону и карточке; кладёт текст в prx394 |

Секреты и адреса моделей — окружение, не payload. Общие клиенты `llm.py`,
watchdog `llmwatch.py`, ресурсный учёт `usage.py`, webread не дублировать.
Новый канал добавляется явно в HANDLERS и в `config/prx_module.php` владельца.
Для HTTP-доставки semantic.index нужны `PRX115_API_BASE`, `PRX115_SITE_TOKEN`
(или `WORKER_SITE_TOKEN`). В PG-режиме индексация пишет в БД напрямую.

### Документооборот (prx390–prx394)

Полезная нагрузка содержит только ID, секреты и токены в очередь не кладутся:
`{"message_id": N}` → `doc.normalize`, `{"run_id": N}` → `doc.analyze`,
`{"draft_id": N}` → `doc.draft`. Модель задаётся `PRX392_MODEL` и
`PRX394_MODEL` (префикс `local::` — для собственного шлюза). Для оффлайн-демо
есть `workers/mock/mock_llm_server.py` и `MOCK_LLM_ROUTES`; сценарий и три PDF —
в [демо prx390](../application/modules/prx390_intake/demo/README.md). Если
`pypdf` и активный OCR не дали текста, `doc.normalize` честно падает в ошибку,
а не отдаёт пустой текст.

### OCR: локальный Tesseract и внешний сервис

`doc.normalize` сначала читает текстовый слой через pypdf. Если в нём меньше
100 осмысленных символов (букв/цифр) или он похож на мусор, вызывается
активный OCR-провайдер из `prx_workers/ocr_client.py`:

| Переменная | Значение |
|---|---|
| `OCR_PROVIDER` | `tesseract` (по умолчанию, dev) или `remote` (prod) |
| `OCR_SERVICE_URL` | например `http://ocr.internal:8000/v1/extract` |
| `OCR_TIMEOUT` | таймаут HTTP в секундах, по умолчанию `120` |
| `OCR_API_KEY` | опциональный токен; по умолчанию `Authorization: Bearer <key>` |
| `OCR_AUTH_HEADER_NAME` | например `X-API-Key`: отправить сырой ключ в этом заголовке |
| `OCR_EXTRA_PARAMS` | JSON-объект параметров парсинга, уходит в `data=` |
| `OCR_EXTRA_HEADERS` | JSON-объект дополнительных HTTP-заголовков |

Remote отправляет PDF как `multipart/form-data`: поле `file`, имя
`document.pdf`, `Content-Type: application/pdf`; параметры из
`OCR_EXTRA_PARAMS` добавляются в `data=`. Ответ — JSON
`{"text": "<markdown>", "pages": 3, "engine": "docling|mineru|paddleocr",
"confidence": 0.93|null}`. Сеть, таймаут, 4xx/5xx и невалидный JSON — инцидент
в логе и `engine="failed", text=""`; тело ответа и документ в лог не пишутся.
Tesseract работает через `pdftoppm` + `tesseract` с `OMP_THREAD_LIMIT=1`;
PNG живут в `TemporaryDirectory` и удаляются даже при ошибке. Внешний сервис
не заменяет локальный fallback автоматически: провайдер выбирается окружением.

## Обслуживание и уведомления

Старый прикладной планировщик удалён. Новый платформенный CLI:

```bash
bin/console portal:maintain
```

Один проход: reconcile/requeue stale, сбор результатов задач в уведомления,
outbox, дайджесты и poll мессенджеров (если настроены). Ошибка завершает команду
ненулевым кодом — оператор должен отслеживать его. Части доставки используют
свои блокировки; дополнительно исключайте параллельный запуск всей команды.
Пример cron от пользователя приложения (пути адаптировать):

```cron
* * * * * cd /srv/portal && flock -n /srv/portal/application/cache/maintain.lock bin/console portal:maintain >> /srv/portal/application/logs/maintenance.log 2>&1
```

Альтернатива — HTTP `/prx120/api/maintain` и `/prx597/api/outbox` с
`X-Worker-Token`. Не кладите токен в URL/cron-логи. Секции `schedule` манифестов
сами по себе ничего не запускают. Docker worker не запускает PHP maintenance;
таймер нужен отдельно.

По умолчанию email выключен, мессенджеры не привязаны и внешние токены пусты.
Для email: `composer install`, `EMAIL_DSN`, `EMAIL_FROM`,
`PRX597_EMAIL_ENABLED=1`; настройте разрешённые каналы/шаблоны в админке.
Перед production проверьте SMTP, idempotency, retry и dead letter на тестовых
получателях. Не посылайте кадровые данные через внешние каналы по умолчанию.

## Проверки

```bash
python3 -m pip install -r workers/requirements-dev.txt
PYTHONPATH=workers python3 -m pytest workers/tests -q
```

Mock LLM и embeddings — в `workers/mock`. Они предназначены для разработки,
не показывают реальное качество RAG и не заменяют проверку прав на документы.
