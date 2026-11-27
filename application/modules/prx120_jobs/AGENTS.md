# AGENTS.md — prx120_jobs (очередь фоновых задач)

> Памятка агенту. Общие правила платформы — в корневом `/AGENTS.md`.
> Развёрнутый гайд по асинхронщине — `docs/WORKERS.md`.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx120_jobs/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 26 | 0,4 КБ |
|   Состояние | 31 | 0,5 КБ |
|   Ключевые классы | 38 | 1,2 КБ |
|   Таблицы (полное владение) | 50 | 1,7 КБ |
|   Бюджеты токенов (prx120/006) | 69 | 2,0 КБ |
|   Маршруты и API | 91 | 0,8 КБ |
|   События | 101 | 0,2 КБ |
|   Как ставить задачи из модуля (единственный способ) | 105 | 0,3 КБ |
|   Порог авто-остановки «воркер молчит» | 113 | 1,0 КБ |
|   Нюансы / не трогать | 124 | 1,7 КБ |
|   Долги и идеи (TODO) | 143 | 0,5 КБ |
|   «Мои задачи» (/tasks) и visitor_id | 149 | 1,3 КБ |
|   Владение и запоздавшие отчёты (23.11.2026) | 167 | 1,9 КБ |
|   Отчёты для административных потребителей | 189 | 1,0 КБ |
|   Сборщик уведомлений и владение marker | 202 | 1,3 КБ |
<!-- context-index:end -->
## Назначение
Единая очередь фоновых задач монолита: PHP ставит задачи (`push`), python-воркеры
из `workers/` выполняют (LLM, эмбеддинги, файлы). Таблица PostgreSQL, а не
отдельный брокер — атомарность с бизнес-данными и ноль лишней инфраструктуры.

## Состояние
- Готовность: **рабочий** (миграция, сервис, HTTP-API, minion-задача).
- Python-сторона: `workers/prx_workers/` (queue.py, base.py, handlers/prx120_jobs/).
- Ops-спринт (27.11.2026): лимиты каналов, `/prx120/channels` (сводка, лимиты,
  повтор упавших), «Повторить» и фильтр `?ch=` в мониторе, рубли на `/prx120/usage`,
  метрики очереди в `/health/metrics` (prx103).

## Ключевые классы
| Класс | Роль |
|---|---|
| `Service_Prx120_JobQueue` | Контракт очереди из PHP: `push/claim/heartbeat/complete/fail/cancel/get/stats` |
| `Service_Prx120_JobQueue_Maintenance` | Трейт обслуживания: `reconcile_outcomes/requeue_stale/release_mine/auto_stop_*/kill_stale` |
| `Service_Prx120_JobQueue_Monitor` | Трейт монитора: `monitor_health/diagnose` (только SELECT) |
| `Controller_Prx120_Api` | HTTP-API `/prx120/api/*` для воркеров на чужом сервере (токен `WORKER_API_TOKEN`, заголовок `X-Worker-Token`) |
| `Model_Prx120_Job` | ORM-модель `prx_120_jobs` (jsonb-поля — строками) |
| `Task_Prx120_RequeueStale` | cron: вернуть в pending задачи без heartbeat дольше timeout |
| `Service_Prx120_Channels` | Сводка каналов, лимиты (`set_limit/limits`), `retry_failed` (7 дн., ≤100, каждую один раз — событие `retried`) |
| `Service_Prx120_Cost` | Токены → рубли по `config/prx120_prices.php`; модель без цены — «не оценено», не 0 ₽ |

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
- `prx_120_channel_limits` (миграция 008) — лимиты каналов: `max_concurrent`,
  `min_interval_sec`; нет строки/нули — без лимита. Индекс
  `prx_120_jobs_channel_claimed_idx` (channel, claimed_at) — для интервала.

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
- `GET|POST /prx120/channels` — каналы: нагрузка, лимиты, «Повторить упавшие» (`prx120.monitor`)
- `POST /prx120/monitor act=retry` — повтор failed/cancelled новой задачей; `?ch=` — фильтр канала
- `POST /prx120/api/claim|heartbeat/<id>|complete/<id>|fail/<id>`
- `GET /prx120/api/job/<id>`, `GET /prx120/api/stats`
- Аутентификация токеном; пустой токен = API закрыт, включая development.

## События
- Публикует: `prx120.job_done`, `prx120.job_failed` (через `Event::publish`, инициатор — система).
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
- Лимиты каналов: фильтр выбора (`limit_blocked_sql`) — быстрый, но видит снимок
  начала запроса; строгость даёт перепроверка под `pg_advisory_lock` канала
  (`_keep_limited_claim`: лишний захват → pending, attempts−1). Python делает то
  же ДО UPDATE в своей транзакции (`pg_advisory_xact_lock`). Меняете условие —
  меняйте в обоих местах: паритет текста ловит `test_queue_channel_limits.py`.
- Backoff при retry: `15 * attempts²` секунд.

## Долги и идеи (TODO)
- Сделано 27.11.2026: «токены → рубли» (`config/prx120_prices.php`), админ-страница
  каналов с повтором, лимиты каналов (одновременно/интервал).
- Лимиты по умолчанию из манифеста канала (`worker.channels.<ch>.limits`) —
  сейчас только БД/страница; лимит «токенов в минуту» на провайдера LLM.

## «Мои задачи» (/tasks) и visitor_id

- Миграция 002: колонка `visitor_id` (Visitor::uid()); push-опция `visitor_id`.
- `/tasks` — задачи текущего посетителя из всех модулей; UI каналов —
  `config/prx120_jobs.php: channels_ui` (label + шаблон url с `<code>`).
- Новые модули с очередью обязаны передавать `'visitor_id' => Visitor::uid()`.
- Настройки уведомлений — prx597 (`/prx597?tab=settings`); события
  `prx120.job_done` / `prx120.job_failed` публикуются через `Event::publish`.
- 24.10.2026: `visitor_jobs($visitor_id, $limit)` — последние задачи одного
  посетителя для карточки предпочтений prx600 (`/prx600/admin/owner/<key>`);
  чужим модулям читать `prx_120_jobs` напрямую не нужно — зовут этот метод.

История разборов инцидентов — `docs/history/prx120-jobs.md`.

Обслуживание не привязано к посещению страниц: запускайте `portal:maintain`
раз в минуту по cron/таймеру (см. `docs/WORKERS.md`). Общий HTTP-контроллер
и semantic status не вызывают `maybe_maintain()`.

## Владение и запоздавшие отчёты (23.11.2026)

Транспортов два: PG напрямую из Python или HTTP API → PHP; файловой очереди
нет. Heartbeat/progress/fail обновляют только processing текущего владельца.
После retry обе реализации снимают claimed_by/claimed_at; повторный fail
не присваивает pending. PHP terminal-fallback ограничен текущим владельцем,
а fallback complete проверяет статус/владельца атомарно и учитывает число строк.
Нельзя возвращать UPDATE только по id: между чтением и записью возможен claim.
При отклонённом progress запись в журнал не создаётся; forced failed пишет
событие failed, не done.

Поздний complete ещё допускает pending → done (унаследованная сверка), но не
чужой processing, cancelled или failed. done идемпотентен. Не смешивать это с
доверенным mark_delivered при приёмке артефакта на стороне бизнес-сервиса.

Тесты: `JobOwnershipIntegrationTest` (настоящая PostgreSQL, управляемое
чередование чтения/нового claim/UPDATE), `test_queue_ownership.py` (Python,
SQL-заглушки). Детальный контракт/ограничения — `docs/WORKERS.md`.
По-прежнему нет lease-токена попытки (имя воркера не различает старую и новую
попытки с одинаковым именем); usage и side effects не атомарны со статусом.
Полная parity PG/HTTP и сетевой E2E пока не доказаны.

## Отчёты для административных потребителей

Публичный JobQueue через существующий Monitor-трейт предоставляет:
`platform_metrics`, `worker_activity`, `usage_today`, `channel_metrics($channel)`,
`admin_activity`; Dev Doctor использует прежний `stats`. SQL только к своим
таблицам через `$this->_db` (db_group очереди); без обслуживания/мутаций.
Агрегаты int, worker ago nullable; ошибки не маскируются внутри сервиса.

Это доверенные административные чтения, не actor-aware API. `admin_activity`
ограничен 12 каналами/120 задачами и не отдаёт payload/result/error/callback.
Подробные окна/сохранённые legacy-пороги — `docs/WORKERS.md`;
`QueueReportsIntegrationTest` проверяет границы данных на PostgreSQL.

## Сборщик уведомлений и владение marker

Публичный JobQueue предоставляет pending_notifications($limit) и
mark_notification_processed($id), делегирует внутреннему NotificationJobs.
Кандидаты done/failed с владельцем без marker; limit <= 0 пусто, порядок ID DESC.
Повторная отметка false, timestamp не меняется; anonymous/nonterminal не отмечаются.
Миграция 007 добавляет notified_at без сброса существующих отметок; down не
удаляет историческую колонку. Обязательна перед деплоем, runtime DDL убран.

Прямые SQL-ссылки четырёх потребителей prx103/115/595/597 устранены; ratchet
без исключений. Доставка остаётся в prx597; marker означает обработку сборщиком,
не exactly-once/факт доставки. Ограничения конкурентности — docs/WORKERS.md.
NotificationJobsIntegrationTest + существующий JobNotificationsIntegrationTest
проверяют marker/повторный сбор/владельца/миграцию на PostgreSQL.
