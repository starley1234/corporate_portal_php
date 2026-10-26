# Очередь и эксплуатационные задачи PRL

## Процесс воркера

`workers/prl_workers` — общий runtime: claim, heartbeat, progress,
complete/fail, retry/backoff, отмена, watchdog, graceful shutdown и usage.
Режим `pg` использует asyncpg и LISTEN/NOTIFY; `http` обращается к сайту
через `/prl120/api/*` с `X-Worker-Token`.

```bash
python3 -m pip install -r workers/requirements.txt
cd workers && python3 -m prl_workers
```

Настройки — `workers/.env` (процесс важнее файла). `WORKER_QUEUE_MODE=pg|http`,
`WORKER_PG_DSN`, `PRL120_API_URL`, `WORKER_API_TOKEN`, `WORKER_CHANNELS`,
`WORKER_CONCURRENCY`, `WORKER_POLL_INTERVAL`. Логи — `workers/logs/prl-worker.log`.
Токен воркера должен совпадать с сайтом; разные серверы используют публичный
адрес сайта, не localhost. В браузерном JS — только относительные URL.

| Канал | Что делает |
|---|---|
| `echo` | Дымовая проверка runtime |
| `llm.summarize` | LLM-запрос из payload, возвращает текст |
| `embeddings.index` | Возвращает sample векторов для инфраструктурных задач |
| `semantic.index` | Индексирует тексты коллекции prl115 через PG или HTTP |

Секреты и адреса моделей — окружение, не payload. Общие клиенты `llm.py`,
watchdog `llmwatch.py`, ресурсный учёт `usage.py`, webread не дублировать.
Новый канал добавляется явно в HANDLERS и в `config/prl_module.php` владельца.
Для HTTP-доставки semantic.index нужны `PRL115_API_BASE`, `PRL115_SITE_TOKEN`
(или `WORKER_SITE_TOKEN`). В PG-режиме индексация пишет в БД напрямую.

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

Альтернатива — HTTP `/prl120/api/maintain` и `/prl597/api/outbox` с
`X-Worker-Token`. Не кладите токен в URL/cron-логи. Секции `schedule` манифестов
сами по себе ничего не запускают. Docker worker не запускает PHP maintenance;
таймер нужен отдельно.

По умолчанию email выключен, мессенджеры не привязаны и внешние токены пусты.
Для email: `composer install`, `EMAIL_DSN`, `EMAIL_FROM`,
`PRL597_EMAIL_ENABLED=1`; настройте разрешённые каналы/шаблоны в админке.
Перед production проверьте SMTP, idempotency, retry и dead letter на тестовых
получателях. Не посылайте кадровые данные через внешние каналы по умолчанию.

## Проверки

```bash
python3 -m pip install -r workers/requirements-dev.txt
PYTHONPATH=workers python3 -m pytest workers/tests -q
```

Mock LLM и embeddings — в `workers/mock`. Они предназначены для разработки,
не показывают реальное качество RAG и не заменяют проверку прав на документы.
