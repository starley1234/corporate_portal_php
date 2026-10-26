# Асинхронные задачи и python-воркеры

> Всё долгое (LLM, эмбеддинги, файлы, рассылки, парсинг) — не в HTTP-запросе.
> PHP ставит задачу в очередь, воркер её выполняет, результат возвращается
> событием/колбэком.

---

## 1. Топология

```
PHP (монолит)                          Python (воркеры, отдельный сервер)
─────────────                          ──────────────────────────────────
Service_Nj120_JobQueue::push
        │
        ▼
nj_120_jobs (PostgreSQL)  ◄─── прямое подключение (asyncpg) ───┐
        │  pg_notify('nj120_jobs')                             │
        └──► HTTP /nj120/api/*  ◄── без доступа к БД, токен ───┤
                                                                │
                       ┌────────────────────────────────────────┘
                       ▼
              n2j_workers (asyncio)
                ├── LLM-эндпоинт      (TOOLFORGE_API_URL / OPENROUTER_API_KEY)
                ├── Embedding-эндпоинт (EMBEDDING_API_URL — отдельный сервис!)
                └── результат: update job + Event 'nj120.job_done' + колбэк
```

Почему таблица, а не RabbitMQ/Redis: задачи атомарны с бизнес-данными
(одна транзакция), конкурентный захват — `FOR UPDATE SKIP LOCKED`, нулевая
дополнительная инфраструктура, история задач видна в админке. Redis остаётся
для кэша и счётчиков.

## 1.1 Встроенные каналы

| Канал | Что делает | Режим воркера |
|---|---|---|
| `echo` | самопроверка конвейера | pg или http |
| `embeddings.index` | векторы текстов → в result задачи | pg или http |
| `llm.summarize` | один LLM-запрос | pg или http |
| `semantic.index` | векторы → запись в `nj_115_vectors` (модуль nj115) | **только pg** |
| `agent.run` | stateless-исполнение агента (nj130) → reply | pg или http |
| `nj540.reply` | «Отзыв 2 Ответ»: публичный ответ на отзыв клиента (LangChain LCEL: анализ → генерация). pg — пишет в `nj_540_replies`; http — результат в job.result, PHP дозапишет | pg или http |
| `nj550.jobs` | «Работа 2 Оффер»: вакансии города по направлению — ddgs с паузами (бережный режим) + LangChain-ранжирование. pg — пишет в `nj_550_results`; http — результат в job.result | pg или http |
| `nj671.bench` | «Стенд эмбеддингов»: один материал через два пути получить вектор (текстовый `EMBEDDING_API_URL` и мультимодальный `EMBEDDING_MM_API_URL`) — recall@1/@3, MRR, повторяемость, цена входа, проба «картинка ↔ слова»; отчёт с вердиктом уходит на сайт `/embedbench/api/worker_result`. pg или http |

Мок-серверы AI для разработки без GPU: `workers/mock/` (`make mock-llm`, `make mock-embed`).

## 2. PHP: как поставить задачу

```php
// Простая задача
$job_id = Service_Nj120_JobQueue::push('embeddings.index', [
    'entity' => 'nj401_task',      // что индексируем
    'ids'    => [10, 11, 12],
]);

// С параметрами: задержка, приоритет, дедлайн
$job_id = Service_Nj120_JobQueue::push('llm.summarize', $payload, [
    'delay_sec'    => 60,      // выполнить не раньше, чем через минуту
    'priority'     => 10,      // меньше = раньше
    'max_attempts' => 5,
    'timeout_sec'  => 300,
    'module'       => 'nj401',
    'callback_url' => URL::site('nj401/api/job_callback', TRUE),
]);

// Результат придёт событием: nj120.job_done / nj120.job_failed
Service_Erp208_EventBus::instance()->listen('nj120.job_done', function ($p) {
    // $p['job_id'], $p['channel'], $p['result']
});
```

## 3. Python: как устроен воркер

```
workers/
├── requirements.txt            # asyncio-стек: asyncpg, httpx
├── n2j_workers/
│   ├── __main__.py             # точка входа: python -m n2j_workers
│   ├── config.py               # конфиг из переменных окружения (.env)
│   ├── queue.py                # клиент очереди (asyncpg ИЛИ HTTP API)
│   ├── base.py                 # BaseWorker: цикл, ретраи, таймауты, graceful
│   ├── llm.py                  # HTTP-клиенты LLM и эмбеддинг-сервера
│   └── handlers/               # ОБРАБОТЧИКИ каналов (тут основная работа)
│       ├── __init__.py         # реестр HANDLERS: channel -> coroutine
│       ├── echo.py             # пример/самопроверка
│       ├── embeddings.py       # индексация текстов в эмбеддинг-сервере
│       └── llm_task.py         # пример LLM-задачи (summarize)
└── .env.example
```

Канал = строка вида `embeddings.index`. Обработчик — async-функция:

```python
# workers/n2j_workers/handlers/my_channel.py
async def handle(ctx, payload: dict) -> dict:
    """ctx: httpx-клиенты llm/embed, конфиг, соединение PG. Возврат = result задачи."""
    text = payload["text"]
    vector = await ctx.embed(text)          # эмбеддинг-сервер
    return {"dim": len(vector)}
```

Зарегистрируйте в `handlers/__init__.py` и перезапустите воркер.

Запуск:

```bash
cd workers
cp .env.example .env      # настроить DSN/токены
python -m n2j_workers                      # все каналы из WORKER_CHANNELS
WORKER_CHANNELS='embeddings.index' python -m n2j_workers
```

Docker: `docker compose --profile workers up -d` (образ `docker/worker/Dockerfile`).

## 4. Отдельный сервер воркеров

Воркеры не обязаны видеть БД. Два режима клиента очереди (`WORKER_QUEUE_MODE`):

* `pg` (по умолчанию) — прямое подключение по `WORKER_PG_DSN`; ниже задержки,
  есть `LISTEN/NOTIFY`. Серверу БД открывают порт или SSH-туннель.
* `http` — очередь через PHP API: `NJ120_API_URL` (например,
  `https://n2j.ru/nj120/api`) + `WORKER_API_TOKEN`. БД наружу не светится.

Миграции на сервере воркеров НЕ запускаются — схему меняет только монолит.

### Как воркер отдаёт результаты сайту (режим `http`)

В режиме `pg` каналы пишут в таблицы сами. В режиме `http` базы у воркера нет,
поэтому результат (векторы, находки, отчёты) уходит на сайт HTTP-ом:

* адрес — `<КАНАЛ>_API_BASE` (напр. `NJ605_RADAR_API_BASE=https://n2j.ru/radar/api`),
  запасной общий — `NJ120_API_BASE` (КОРЕНЬ сайта);
* токен — `<КАНАЛ>_SITE_TOKEN` → `WORKER_SITE_TOKEN` → `WORKER_API_TOKEN`
  (значение одно — `WORKER_API_TOKEN` из `.env` сайта);
* канал, который сам формирует полный путь (`semantic.index` →
  `/nj115/api/vectors`), работает и от одного `NJ120_API_URL`: корень сайта
  берётся из него (`workers/n2j_workers/site.py: origin_of`). Так доставка
  векторов не требует второй записи того же адреса.
* модульный канал с короткими путями (`nj661_airadar` → `/items`, `/analysis`)
  тоже не требует своей записи: адрес собирается из общего корня
  (`NJ120_API_BASE`, в http-режиме — корень `NJ120_API_URL`) и пути модуля —
  `site.module_api_base("NJ661_AIRADAR", "/airadar/api")`. Свой
  `<КАНАЛ>_API_BASE` — только если канал живёт на ДРУГОМ сайте. Новые
  модульные каналы обязаны так делать (06.10.2026, «не раздувать .env»).

Полный список переменных с путями — `workers/.env.example`. Соответствие
«канал в коде ↔ переменная в примере» проверяет тест
`workers/tests/test_env_docs.py`: новый канал, который ходит на сайт, обязан
появиться в примере (28.09.2026 владелец получил ошибку с советом «проверьте
NJ115_API_BASE», а в примерах такой переменной не было).

## 5. Правила написания обработчиков

1. **Идемпотентность**: задача может повториться после сбоя (at-least-once).
   Проверяйте, не сделана ли работа, прежде чем делать.
2. **Таймауты**: у задачи есть `timeout_sec`; длинные операции внутри — тоже
   с таймаутами (httpx timeout).
3. **Ретраи**: исключение = повтор задачи (до `max_attempts`), дальше `failed`.
   Не «глотайте» исключения — воркер пометит задачу сам.
4. **Прогресс**: `await ctx.heartbeat()` в цикле обработки — воркер сообщает,
   что жив (задачи без heartbeat дольше timeout помечаются зависшими).
5. **Ограничения LLM**: батчи, задержки, соблюдение лимитов провайдера;
   логируйте модель и число токенов в result задачи.
6. **Новые каналы**: называйте `<домен>.<действие>` (`embeddings.index`,
   `llm.summarize`, `nj501.recalc`). Каналы реестра — в `handlers/__init__.py`.

## 6. Диагностика

```sql
-- очередь глазами
SELECT id, channel, status, attempts, priority, claimed_by, created_at
FROM nj_120_jobs ORDER BY id DESC LIMIT 20;
-- зависшие
SELECT * FROM nj_120_jobs WHERE status='processing' AND heartbeat_at < now() - interval '10 min';
```

Админ-эндпоинты PHP: `GET /nj120/api/stats` (токен). Логи воркера — stdout
(`docker compose logs -f worker`).


---

## Учёт ресурсов задач (usage) — с 21.09.2026

Каждая задача получает отчёт о ресурсах автоматически:

* `n2j_workers/usage.py` — трекер в contextvar: LLM-модели (провайдер::модель,
  вызовы, prompt/completion токены), веб-чтения, поисковые запросы, ошибки, время.
* `llm.chat()/chat_with_usage()` и `webread.fetch()/ddg_text()` пишут в трекер сами
  (обработчику делать ничего не нужно).
* `base.py` подмешивает `usage` в result (и передаёт в queue.fail при падении —
  включая таймауты).
* Сайт: `Service_Nj120_JobQueue` кладёт отчёт в `nj_120_usage` (миграция nj120/004,
  upsert по job_id; прямой режим pg пишет таблицу сам, HTTP-режим — через API).
* Агрегаты для владельца: `/nj120/usage` (право nj120.usage.admin) — по модулям,
  моделям, пользователям + последние ошибки воркеров для анализа.

## Монитор воркеров — /nj120/monitor (право nj120.monitor, с 21.09.2026)

Живое состояние очереди: ожидают / исполняются / последние 50 задач, пульс воркера
(heartbeat исполняющихся, «застрявшие» = нет пульса 120 с), имена воркеров за 24 ч,
готово/ошибок за 24 ч, среднее время. По задаче: кто поставил (пользователь/visitor),
попытки, ошибка целиком, payload (кнопка «Параметры»), ссылка на результат.
Действия: **Отменить** (pending), **Прервать** (processing — воркеров complete/fail
не применится, это штатно), **Удалить** (завершённые; usage уйдёт каскадом).
Все действия two-click. Автообновление страницы 15 с (переключатель).
