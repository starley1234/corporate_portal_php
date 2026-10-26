# N2J.ru / N2J

**Микромодульный монолит**: один деплой, модули-«мини-сервисы» `njXXX_slug`
со своими таблицами (`nj_<id>_*`; модули `erpXXX_*` — общая база с ERP-проектом,
статус каждого см. в [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) §2:
erp208 (шина событий) и erp100 (Auth/RBAC) активно используются,
erp118/erp212/erp218 — в сопровождении, развиваем их nj-дублёры,
а не сами erp; erp217 снесён — вместо него nj597),
асинхронные задачи — в python-воркерах,
LLM и эмбеддинги — через отдельные эндпоинты.

* Стек: PHP 8.4 (Kohana 3.x) · PostgreSQL 17 (+pgvector) · Redis · Python 3.11+ (asyncio)
* Архитектура и правила: **[AGENTS.md](AGENTS.md)** (кратко) и **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** (подробно)
* Аудит платформы (модули, тех долг, план): **[docs/AUDIT-2026-09-21.md](docs/AUDIT-2026-09-21.md)**
* Разработка по модулям без чтения всего репозитория: **[docs/CONTEXT.md](docs/CONTEXT.md)**
* План развития (витрина, новые модули, деньги): **[docs/PLAN-2026-10-01.md](docs/PLAN-2026-10-01.md)**
* Деплой — `bash bin/deploy.sh --migrate`; какую ветку тянет прод, задаётся только на сервере (`DEPLOY_BRANCH` в `/etc/default/n2j-deploy`) и в репозитории не хранится

---

## Генератор QR-кодов

`/qr` — один код или до 200 кодов в ZIP. Свои цвета, формы и логотип по центру,
ссылка/Wi-Fi/визитка/текст, PNG/SVG/JPEG. Всё локально, без регистрации.
Памятка и тесты: [nj510_qr/AGENTS.md](application/modules/nj510_qr/AGENTS.md).

## Быстрый старт

### Docker (рекомендуется)

```bash
cp .env.example .env
docker compose up -d                                  # nginx + php-fpm 8.4 + postgres + redis
docker compose exec php bin/console db:migrate        # миграции
docker compose exec php bin/console auth:user --email=admin@n2j.ru --password=admin123 --username=admin --admin
# сайт: http://localhost:8080 · adminer: :8081 · mailpit: :8025 (make up-tools)
```

### Нативно (Debian/Ubuntu)

```bash
bash bin/restore-env.sh --serve   # PHP 8.4 + PostgreSQL + .env + миграции + админ + сервер :8080
```

Идемпотентно: можно запускать повторно. Только часть окружения — флаги
`--pg-only / --php-only / --app-only` (подробности: docs/ENVIRONMENT.md).

### Закрытая песочница (только GitHub и PyPI доступны)

```bash
bash bin/setup-sandbox.sh --serve   # PHP 8.4 из исходников + pgserver + БД + тесты
```

Пошаговый гайд с граблями и чек-листом: **docs/SANDBOX-SETUP.md** (для агентов:
читать ПЕРВЫМ, разворачивание занимает минуты, а не квест).

### Закрытая сеть (apt и packagist недоступны, есть GitHub и PyPI)

```bash
bash bin/setup-sandbox.sh --serve
# соберёт PHP 8.4 из исходников (codeload.github.com) + переносимый PostgreSQL
# (PyPI pgserver), накатит миграции, создаст админа, погонит дымовые тесты
# (tests/mini_runner.php — без composer/phpunit)
```

### Python-воркеры (асинхронные задачи)

```bash
cd workers && pip install -r requirements.txt && cp .env.example .env
python -m n2j_workers
# или в docker: make up-worker
```

---

## Экономная разработка по модулям

Задача почти всегда живёт в одном модуле, и читать под неё весь репозиторий не
нужно. Вход в задачу — контекст-пак модуля (≤ 8 КБ: назначение, состояние,
таблицы, каналы очереди, env, классы с публичными методами, маршруты,
зависимости, правила), собранный из файлов самого модуля:

```bash
make brief m=nj643_cad        # → .context/nj643_cad.md (в git не хранится)
python3 .tools/context_pack.py pack nj643_cad          # то же в stdout
python3 .tools/context_pack.py section "Правила" --module nj643_cad   # один раздел памятки
python3 .tools/context_pack.py backlog                 # что осталось открытым в модулях
python3 .tools/doc_budget_lint.py                      # потолки документации
```

Почему так, какие потолки и что писать обратно в модуль — **[docs/CONTEXT.md](docs/CONTEXT.md)**
(краткая версия — `AGENTS.md` §0.1).

---

## Команды

```bash
make help        # все команды
make up / down   # docker-стек
make serve       # нативный dev-сервер (php -S :8080)
make migrate     # миграции БД (bin/console db:migrate)
make seed        # админ из .env
make fresh       # пересоздать схему БД с нуля
make test        # phpunit (tests/ — дымовые)
make lint        # php -l по всему проекту
make doctor      # диагностика окружения
make new-module id=401 slug=tasks name="Задачи"   # скелет нового модуля
make brief m=nj643_cad                            # контекст-пак модуля для агента
make backlog [m=nj643_cad]                        # открытые пункты модулей
make doc-index f=AGENTS.md                        # индекс разделов в документе
```

## Структура

| Каталог | Что там |
|---|---|
| `application/modules/njXXX_*` | модули платформы (99% работы; legacy `erpXXX_*` — там же) |
| `application/{classes,config,views}` | приложение: роуты, переопределения, layout |
| `modules/`, `system/` | файлы фреймворка **Konine** (konine.dev) — никогда не редактировать |
| `workers/` | python-воркеры очереди `nj120_jobs` (LLM, эмбеддинги) |
| `docker/` | Dockerfile'ы php/worker, nginx, init postgres |
| `bin/` | console, serve, setup.sh, restore-env.sh, test, deploy, backup |
| `docs/` | архитектура, гайды (модули/воркеры/фронтенд/окружение), протокол помодульной работы |
| `.tools/` | проверки перед пушем и инструменты разработки (контекст-пак, потолки доков) |
| `public_html/` | docroot: index.php, assets/ (css/js, vendor) |

## Ключевые принципы (полностью — AGENTS.md)

1. Модуль владеет своими таблицами (`nj_<id>_<имя>`) и не лезет в чужие;
   общие таблицы ядра (`users`, `roles`, `sessions`, `llm_*`) — без префикса.
2. Бизнес-логика — в `Service_NjXXX_*`; наружу — только сервисы, события,
   HMVC-виджеты и очередь.
3. Долгие операции (LLM, эмбеддинги, файлы) — в очередь `nj120_jobs`,
   выполняют python-воркеры; результат — событием/колбэком.
4. Миграции — в `migrations_pg/` модуля, идемпотентные, применённые не менять.
5. Секреты — только `.env` (`.env.example` — шаблон).
