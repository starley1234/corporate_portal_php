# PRX — платформа ИИ-сервисов

Модульный монолит на PHP 8.4 и PostgreSQL, с Python-воркерами для длительных задач.
Основные потребители новых ИИ-пайплайнов — корпоративные информационные системы.
Первый пайплайн: [асинхронный PDF → текст (API и запуск)](docs/integrations/document-extractions.md).
Воркеры проверяются через `/prx120/monitor` → «Проверить воркер» или `bin/console prx120:probe`.

Корпоративный портал отложен; `prx300_employee` остаётся доступным как HR-MVP.
Старый контур `prx390`–`prx394` [отключён и отправлен в архив](archive/docflow/README.md)
с пометкой «Требует доработки». VLM-демо удалено. Остальные 49 портальных
сервисов — отложенные позиции [карты](docs/ROADMAP.md), а не работающие модули.

Рабочие страницы портала приведены к эталону
`/pages/required_design_sample` и общей дизайн-системе v6. Отдельная
публичная витрина `/design-lab` сохранена как набор альтернатив: десять
вариантов одного демонстрационного портала с дневной/ночной темой,
мобильным видом и тремя акцентами компаний холдинга. Витрина автономна и
не использует общий CSS рабочего портала.

## Что сохранено

- Auth, пользователи, роли, ACL, профили/настройки, 2FA/TOTP и контроль сессий.
- Идемпотентные миграции PostgreSQL, CLI, диагностика, генератор модулей.
- Манифесты, каталог, навигация, общий поиск и административные страницы.
- Шина событий, файловое хранилище, менеджер модулей.
- Уведомления: in-app, outbox, email и опциональные мессенджерные адаптеры.
- Очередь PostgreSQL, PG/HTTP-воркеры, heartbeat/retry/cancel, учёт LLM-ресурсов.
- LLM-фасад, embeddings, семантический поиск и технический RAG-кэш.
- Первый HR-MVP `prx300_employee` с оргконтекстом для следующих сервисов:
  `organization_id`, `department_id`, `site_id`, `employee_id`.
- Локальная дизайн-система: адаптивная оболочка, светлая/тёмная темы,
  компоненты PHP/Tailwind, общий JS API `PRX.*`.
- Публичная изолированная витрина `prx140_design_lab` для сравнения
  десяти направлений дизайна, включая четыре варианта по эталону v6 и
  точную адаптацию `required_design_sample`, без Auth, БД и общего CSS
  рабочего портала.
- Архитектура как код: `docs/architecture/model/*.yaml` → страница `/architecture`
  и JSON `/architecture/data.json` (`make architecture`), с проверкой схем и ADR.

## Запуск с новой БД

> Не подключайте эту версию к старой БД автоматически. Переименованы таблицы,
> классы миграций, каналы и настройки. Это новая платформа с новой схемой,
> а не совместимое обновление старого сайта. [План перехода](docs/MIGRATION.md).

```bash
bash bin/setup.sh                 # .env, случайные секреты, каталоги
# Проверьте APP_URL, DB_DEFAULT_* и ADMIN_* в .env.
docker compose up -d --build
# Дайте контейнеру PostgreSQL пройти healthcheck.
# Для bind-mount: выделенные runtime-каталоги должны принадлежать PHP-FPM.
docker compose exec php chown -R www-data:www-data application/cache application/logs application/sessions storage
docker compose exec php composer install --no-dev --no-interaction
docker compose exec php php public_html/index.php db:migrate
```

Вход: `/auth/login`. Главная портала закрыта для гостя; исключение —
демонстрационная витрина `/design-lab`, где нет рабочих данных и действий.
Учётные записи создаёт администратор; саморегистрация выключена. На чистой базе
миграция создаёт **admin / admin** — смените пароль после первого входа. Для
production: `PRX_DEFAULT_ADMIN=0` в `.env` и `make seed` с сильным паролем.
Перед production нужны собственные пароли БД, HTTPS, утверждённые политики
доступа, почты, резервирования и обработки данных.

Нативная разработка: `bash bin/restore-env.sh --serve`. В ограниченной сети:
`bash bin/setup-sandbox.sh`, затем `PORT=8080 bash bin/serve`.
Docker-стек не запускался в среде с закрытым Docker registry — см. результаты
реальных проверок в [docs/VALIDATION.md](docs/VALIDATION.md).

### Файлы

`storage/` — вне docroot; старые файлы автоматически не копируются.
[Порядок переноса](docs/ENVIRONMENT.md#перенос-существующего-public_htmlstorage).

## Воркеры и доставка уведомлений

```bash
python3 -m pip install -r workers/requirements.txt
# Проверьте workers/.env (setup.sh создаёт его), адреса и общий токен сайта.
cd workers && python3 -m prx_workers
# Альтернатива: docker compose --profile workers up -d --build
```

Каналы: `echo`, `document.extract`, `embeddings.index`, `llm.summarize`, `semantic.index`.
Сетевые LLM/embedding-адреса в шаблонах пусты: задайте разрешённые внутренние
эндпоинты. Для разработки без GPU есть `workers/mock/`.

После удаления старой «пиналки» обработку outbox и периодическое обслуживание
надо запускать явно: [docs/WORKERS.md](docs/WORKERS.md). Одного манифеста
`schedule` для выполнения cron недостаточно. По умолчанию email выключен.

## Новый модуль

```bash
make new-module id=301 slug=onboarding name="Онбординг-трек"
```

Генератор создаёт закрытый по умолчанию каркас с манифестом, пустой миграцией,
сервисом, контроллером, view и памяткой. Он **не подключается автоматически**,
не выдаёт права и не создаёт фиктивный CRUD. После реализации добавьте модуль
в `application/config/modules.php`, policy/ACL, тесты и выполните миграции.

Контракты — [MODULE_GUIDE](docs/MODULE_GUIDE.md), архитектура —
[ARCHITECTURE](docs/ARCHITECTURE.md), карта 55 сервисов —
[ROADMAP](docs/ROADMAP.md), правила разработки — [AGENTS.md](AGENTS.md).

## Проверки

```bash
make check
make lint
bash bin/test
python3 -m pip install -r workers/requirements-dev.txt
PYTHONPATH=workers python3 -m pytest workers/tests -q
npm ci && npm run build:css && npm test
bash bin/check-fresh.sh
```

`vendor/`, `.env`, хранилища, логи и зависимости не коммитятся.
Реальная отправка email требует `composer install` (symfony/mailer).
