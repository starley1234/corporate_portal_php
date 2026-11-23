# AGENTS.md — prx120_jobs (очередь фоновых задач)

> Памятка агенту. Общие правила платформы — в корневом `/AGENTS.md`.
> Развёрнутый гайд по асинхронщине — `docs/WORKERS.md`.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx120_jobs/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 23 | 0,4 КБ |
|   Состояние | 28 | 0,2 КБ |
|   Ключевые классы | 32 | 0,8 КБ |
|   Таблицы (полное владение) | 42 | 1,4 КБ |
|   Бюджеты токенов (prx120/006) | 58 | 2,0 КБ |
|   Маршруты и API | 80 | 0,6 КБ |
|   События | 88 | 0,1 КБ |
|   Как ставить задачи из модуля (единственный способ) | 92 | 0,3 КБ |
|   Порог авто-остановки «воркер молчит» | 100 | 1,0 КБ |
|   Нюансы / не трогать | 111 | 1,1 КБ |
|   Долги и идеи (TODO) | 125 | 0,5 КБ |
|   «Мои задачи» (/tasks) и visitor_id | 130 | 1,0 КБ |
<!-- context-index:end -->
## Назначение
Единая очередь фоновых задач монолита: PHP ставит задачи (`push`), python-воркеры
из `workers/` выполняют (LLM, эмбеддинги, файлы). Таблица PostgreSQL, а не
отдельный брокер — атомарность с бизнес-данными и ноль лишней инфраструктуры.

## Состояние
- Готовность: **рабочий** (миграция, сервис, HTTP-API, minion-задача).
- Python-сторона: `workers/prx_workers/` (queue.py, base.py, handlers/).

## Ключевые классы
| Класс | Роль |
|---|---|
| `Service_Prx120_JobQueue` | Контракт очереди из PHP: `push/claim/heartbeat/complete/fail/cancel/get/stats` |
| `Service_Prx120_JobQueue_Maintenance` | Трейт обслуживания: `reconcile_outcomes/requeue_stale/release_mine/auto_stop_*/kill_stale` |
| `Service_Prx120_JobQueue_Monitor` | Трейт монитора: `monitor_health/diagnose` (только SELECT) |
| `Controller_Prx120_Api` | HTTP-API `/prx120/api/*` для воркеров на чужом сервере (токен `WORKER_API_TOKEN`, заголовок `X-Worker-Token`) |
| `Model_Prx120_Job` | ORM-модель `prx_120_jobs` (jsonb-поля — строками) |
| `Task_Prx120_RequeueStale` | cron: вернуть в pending задачи без heartbeat дольше timeout |

## Таблицы (полное владение)
- `prx_120_jobs` — задачи. Статусы: `pending → processing → done|failed|cancelled`.
  Ключевые поля: `channel`, `payload` jsonb, `result` jsonb, `priority` (меньше =
  раньше), `attempts/max_attempts`, `available_at` (отложка/backoff),
  `heartbeat_at` (живость воркера), `callback_url`.
- `prx_120_job_events` — журнал: created/claimed/heartbeat/done/failed/cancelled.
- `prx_120_usage` — учёт токенов/моделей по задаче. С миграции 005 (25.10.2026)
  **без каскада от `prx_120_jobs`**: уборка монитора и правило 60 дней не стирают
  историю (раньше `/graph3d/usage` показывал только последние задачи).
  Колонки `payload_brief` (скалярный срез payload: prompt, project_id…) и `status`
  — чтобы отчёт читался без задачи. Своя чистка `prx120_usage`, 400 дней.

- `prx_120_budgets`, `prx_120_budget_alerts` (миграция 006, 25.10.2026) — бюджеты
  токенов: индивидуальные лимиты день/месяц на `visitor_id` (0 — по роли,
  −1 — безлимит) и отметки «уже предупредили» (владелец × период × порог).

## Бюджеты токенов (prx120/006)
- `Service_Prx120_Budget`: `limits()` (роль из `config/prx120_budget.php` →
  индивидуальная строка сильнее), `spent()`/`state()` по `prx_120_usage` за
  календарные день и месяц, `check()`, `after_usage()` (90 %/100 % →
  колокольчик prx597 `system`, один раз на период), `save()`, `overrides()`,
  `top_today()`.
- `JobQueue::push()` с `visitor_id` при исчерпанном бюджете бросает
  `Prx120_Budget_Exception` (код 429, текст для человека). Отключить проверку
  вызову — `'budget' => FALSE`; глобально — `PRX120_BUDGET_ENFORCE=0` (учёт и
  предупреждения остаются). Служебные задачи без `visitor_id` не считаются.
- Страницы: `/prx120/budgets` (админ, `prx120.usage.admin`) — умолчания, форма,
  «кто ближе к потолку», выданные; `/tasks` и центр контроля prx600 показывают
  карточку `120/_budget` (доля числом и точками, полос нет).
- Умолчания: anon 30 тыс./день, login 150 тыс., vip 600 тыс., admin ∞; месяц
  ×10–20. Меняются env `PRX120_BUDGET_DAILY_<ROLE>` / `..._MONTHLY_<ROLE>`.
- Служебные ключи `s:<модуль>` (`s:prx602` — контент, `s:prx600`) — роль
  `system`, по умолчанию ∞ (`PRX120_BUDGET_DAILY_SYSTEM`). 25.10.2026 они
  считались `anon` и /content встал на 30 тыс. молча — отказ был только в
  логе. Теперь `create_draft/retry` при отказе очереди переводят статью в
  `error` с причиной, а /prx602/admin показывает карточку бюджета `s:prx602`
  со ссылкой «Изменить лимит» (`/prx120/budgets?key=s:prx602`).

## Маршруты и API
- `prx120:probe` / кнопка «Проверить воркер» в `/prx120/monitor`: ставит
  настоящую задачу `echo`, CLI проверяет результат Python по nonce.
- `GET|POST /prx120/budgets` — бюджеты токенов (админ, `prx120.usage.admin`)
- `POST /prx120/api/claim|heartbeat/<id>|complete/<id>|fail/<id>`
- `GET /prx120/api/job/<id>`, `GET /prx120/api/stats`
- Аутентификация токеном; пустой токен = API закрыт (кроме development).

## События
- Публикует: `prx120.job_done`, `prx120.job_failed` (через `Event::fire`).
- Слушает: ничего.

## Как ставить задачи из модуля (единственный способ)
```php
Service_Prx120_JobQueue::push('embeddings.index', $payload, [
    'module' => 'prx401', 'priority' => 10, 'callback_url' => ...,
]);
```
В таблицу `prx_120_jobs` руками (SQL/ORM) — НЕ писать.

## Порог авто-остановки «воркер молчит»
- Общий: env `PRX_SILENT_STOP_SEC` (по умолчанию 240 с) — пульс идёт каждые
  30 с, молчание дольше нескольких минут = мёртвый воркер.
- Поканальный (06.10.2026): config `prx120_jobs.silent_stop_channels`
  (канал => секунды); эффективный порог = max(общий, канал). Понадобилось
  для prx661.airadar — конвейер с локальной LLM, генерация занимает минуты,
  общий порог снимал живую задачу. Одиночная задача (`auto_stop_silent($id)`)
  сначала узнаёт свой канал и останавливается её порогом.
- Проверяет `.tools/prx120_silent_stand.php` (резолюция порогов, разбор
  свипа на проходы, минуты в причине).

## Нюансы / не трогать
- SQL для pg собирается PHP-склейкой ВНЕ строки: `error = ".$this->_db->quote($msg)."`
  — точка-склейка ВНУТРИ двойной кавычки уезжает в PostgreSQL как текст и
  роняет запрос «ошибка синтаксиса (".")» (release_mine, 06.10.2026; ловит
  стенд prx120_silent_stand).
- Драйвер PostgreSQL возвращает для UPDATE — число строк, для INSERT — массив;
  поэтому все RETURNING-запросы выполняются с типом `Database::SELECT` (см.
  комментарий в коде). Не «исправляйте» это, не понимая драйвер
  `application/modules/PostgreSQL/`.
- Параметры в SQL не биндятся (pg_query без prepare) — только `$db->quote()`.
- `claim()` использует `FOR UPDATE SKIP LOCKED` — не убирать, иначе задачи
  будут дублироваться между воркерами.
- Backoff при retry: `15 * attempts²` секунд.

## Долги и идеи (TODO)
- Отчёт «токены → рубли» на /prx120/usage: цены моделей в конфиге, себестоимость видна владельцу (docs/ARCHITECTURE.md §6)
- Админ-страница очереди (список/перезапуск задач) — сделать виджетом/страницей.
- Rate-limit на каналы (интервал между задачами канала).

## «Мои задачи» (/tasks) и visitor_id

- Миграция 002: колонка `visitor_id` (Visitor::uid()); push-опция `visitor_id`.
- `/tasks` — задачи текущего посетителя из всех модулей; UI каналов —
  `config/prx120_jobs.php: channels_ui` (label + шаблон url с `<code>`).
- Новые модули с очередью обязаны передавать `'visitor_id' => Visitor::uid()`.
- Настройки уведомлений — prx597 (`/prx597?tab=settings`); события
  `prx120.job_done` / `prx120.job_failed` публикуются через Event::fire.
- 24.10.2026: `visitor_jobs($visitor_id, $limit)` — последние задачи одного
  посетителя для карточки предпочтений prx600 (`/prx600/admin/owner/<key>`);
  чужим модулям читать `prx_120_jobs` напрямую не нужно — зовут этот метод.

История разборов инцидентов — `docs/history/prx120-jobs.md`.
