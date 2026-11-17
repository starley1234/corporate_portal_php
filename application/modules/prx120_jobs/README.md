# Очередь фоновых задач (prx120_jobs)

Асинхронный механизм платформы: PHP-модули ставят задачи, python-воркеры
(`workers/`) их выполняют. Подробный гайд — `docs/WORKERS.md`.

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
bin/console Prx120:RequeueStale    # cron * * * * *
bin/console db:migrate             # создание таблиц (миграция 120_001)
```
