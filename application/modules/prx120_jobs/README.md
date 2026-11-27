# Очередь фоновых задач (prx120_jobs)

Асинхронный механизм платформы: PHP-модули ставят задачи, python-воркеры
(`workers/`) их выполняют. Подробный гайд — `docs/WORKERS.md`.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx120_jobs/README.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Зачем | 18 | 0,5 КБ |
|   Компоненты | 26 | 0,5 КБ |
|   Таблицы | 35 | 0,3 КБ |
|   Использование (PHP) | 42 | 0,3 КБ |
|   HTTP API (для воркеров) | 55 | 0,6 КБ |
|   Обслуживание | 69 | 0,3 КБ |
|   Администрирование | 76 | 0,9 КБ |
<!-- context-index:end -->
## Зачем

* HTTP-запрос не должен ждать LLM, эмбеддинг-сервер, конвертацию файлов и пр.
* Тяжёлая обработка живёт на отдельном сервере воркеров и масштабируется
  независимо от монолита.
* Очередь — таблица PostgreSQL: транзакционность с бизнес-данными,
  конкурентный захват (`FOR UPDATE SKIP LOCKED`), история и ретраи из коробки.

## Компоненты

| Класс | Роль |
|---|---|
| `Service_Prx120_JobQueue` | постановка/захват/завершение задач, статистика, возврат зависших |
| `Controller_Prx120_Api` | HTTP-API для воркеров без доступа к БД (`/prx120/api/*`, токен `WORKER_API_TOKEN`) |
| `Model_Prx120_Job` | ORM-модель задачи (для админок) |
| `Task_Prx120_RequeueStale` | Minion-задача обслуживания (cron) |

## Таблицы

* `prx_120_jobs` — задачи: `channel`, `payload` (jsonb), `status`
  (`pending → processing → done|failed|cancelled`), `priority`, `attempts`,
  `timeout_sec`, `result`, `callback_url` и т.д.
* `prx_120_job_events` — журнал жизни задачи.

## Использование (PHP)

```php
$id = Service_Prx120_JobQueue::push('embeddings.index', ['ids' => [1,2]], [
    'module' => 'prx401',
]);

// результат задачи:
$job = Service_Prx120_JobQueue::instance()->get($id);
```

События шины: `prx120.job_done`, `prx120.job_failed`.

## HTTP API (для воркеров)

```
POST /prx120/api/claim           {"channels":["..."], "worker":"w1"}  → {"ok":true,"job":{...}|null}
POST /prx120/api/heartbeat/<id>  {"worker":"w1"}
POST /prx120/api/complete/<id>   {"worker":"w1","result":{...}}
POST /prx120/api/fail/<id>       {"worker":"w1","error":"...","retry":true}
GET  /prx120/api/job/<id>
GET  /prx120/api/stats
```

Аутентификация: заголовок `X-Worker-Token: <WORKER_API_TOKEN>`.
Если `WORKER_API_TOKEN` пуст — API разрешён только в KOHANA_ENV=development.

## Обслуживание

```bash
bin/console Prx120:RequeueStale    # манифест schedule.maintenance, раз в минуту — исполняет portal:maintain
bin/console db:migrate             # создание таблиц (миграция 120_001)
```

## Администрирование

* `/prx120/monitor` — задачи, пульс воркера; «Повторить» для упавших и
  отменённых (новой задачей), фильтр канала `?ch=`.
* `/prx120/channels` — по каналу: ожидают, в работе, упали за сутки, сколько
  ждёт самая старая; **лимиты** «одновременно» и «интервал, с» (соблюдают и
  PHP-, и python-воркеры); «Повторить упавшие» за 7 дней.
* `/prx120/usage` — токены и **стоимость в рублях** по каналам, моделям и
  людям; цены — `config/prx120_prices.php` (модель без цены — «—», не 0 ₽).
* Метрики очереди и LLM для Prometheus — `/health/metrics` (модуль prx103).
