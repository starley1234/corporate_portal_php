# Очередь фоновых задач (nj120_jobs)

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
| `Service_Nj120_JobQueue` | постановка/захват/завершение задач, статистика, возврат зависших |
| `Controller_Nj120_Api` | HTTP-API для воркеров без доступа к БД (`/nj120/api/*`, токен `WORKER_API_TOKEN`) |
| `Model_Nj120_Job` | ORM-модель задачи (для админок) |
| `Task_Nj120_RequeueStale` | Minion-задача обслуживания (cron) |

## Таблицы

* `nj_120_jobs` — задачи: `channel`, `payload` (jsonb), `status`
  (`pending → processing → done|failed|cancelled`), `priority`, `attempts`,
  `timeout_sec`, `result`, `callback_url` и т.д.
* `nj_120_job_events` — журнал жизни задачи.

## Использование (PHP)

```php
$id = Service_Nj120_JobQueue::push('embeddings.index', ['ids' => [1,2]], [
    'module' => 'nj401',
]);

// результат задачи:
$job = Service_Nj120_JobQueue::instance()->get($id);
```

События шины: `nj120.job_done`, `nj120.job_failed`.

## HTTP API (для воркеров)

```
POST /nj120/api/claim           {"channels":["..."], "worker":"w1"}  → {"ok":true,"job":{...}|null}
POST /nj120/api/heartbeat/<id>  {"worker":"w1"}
POST /nj120/api/complete/<id>   {"worker":"w1","result":{...}}
POST /nj120/api/fail/<id>       {"worker":"w1","error":"...","retry":true}
GET  /nj120/api/job/<id>
GET  /nj120/api/stats
```

Аутентификация: заголовок `X-Worker-Token: <WORKER_API_TOKEN>`.
Если `WORKER_API_TOKEN` пуст — API разрешён только в KOHANA_ENV=development.

## Обслуживание

```bash
bin/console Nj120:RequeueStale    # cron * * * * *
bin/console db:migrate             # создание таблиц (миграция 120_001)
```
