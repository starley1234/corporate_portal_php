# Очередь фоновых задач (prl120_jobs)

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
| `Service_Prl120_JobQueue` | постановка/захват/завершение задач, статистика, возврат зависших |
| `Controller_Prl120_Api` | HTTP-API для воркеров без доступа к БД (`/prl120/api/*`, токен `WORKER_API_TOKEN`) |
| `Model_Prl120_Job` | ORM-модель задачи (для админок) |
| `Task_Prl120_RequeueStale` | Minion-задача обслуживания (cron) |

## Таблицы

* `prl_120_jobs` — задачи: `channel`, `payload` (jsonb), `status`
  (`pending → processing → done|failed|cancelled`), `priority`, `attempts`,
  `timeout_sec`, `result`, `callback_url` и т.д.
* `prl_120_job_events` — журнал жизни задачи.

## Использование (PHP)

```php
$id = Service_Prl120_JobQueue::push('embeddings.index', ['ids' => [1,2]], [
    'module' => 'prl401',
]);

// результат задачи:
$job = Service_Prl120_JobQueue::instance()->get($id);
```

События шины: `prl120.job_done`, `prl120.job_failed`.

## HTTP API (для воркеров)

```
POST /prl120/api/claim           {"channels":["..."], "worker":"w1"}  → {"ok":true,"job":{...}|null}
POST /prl120/api/heartbeat/<id>  {"worker":"w1"}
POST /prl120/api/complete/<id>   {"worker":"w1","result":{...}}
POST /prl120/api/fail/<id>       {"worker":"w1","error":"...","retry":true}
GET  /prl120/api/job/<id>
GET  /prl120/api/stats
```

Аутентификация: заголовок `X-Worker-Token: <WORKER_API_TOKEN>`.
Если `WORKER_API_TOKEN` пуст — API разрешён только в KOHANA_ENV=development.

## Обслуживание

```bash
bin/console Prl120:RequeueStale    # cron * * * * *
bin/console db:migrate             # создание таблиц (миграция 120_001)
```
