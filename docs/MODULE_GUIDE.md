# Новый бизнес-модуль PRX

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index docs/MODULE_GUIDE.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   1. Выберите ID и границы | 12 | 2,5 КБ |
|   2. Реализуйте вертикальный MVP | 54 | 1,9 КБ |
|   3. Подключите | 74 | 2,0 КБ |
|   4. Дизайн и проверка | 111 | 0,7 КБ |
<!-- context-index:end -->
## 1. Выберите ID и границы

Сверьтесь с `application/config/portal.php`: зарезервированы 50 возможностей.
Соседние сервисы одного домена могут позднее объединяться, но не создавайте
второй профиль пользователя или вторую очередь. Если новый ID вне плана —
сначала обновите план, не занимайте ID существующего модуля.

```bash
make new-module id=301 slug=onboarding name="Онбординг-трек" entity=Step
```

Scaffold v2 не перезаписывает каталоги и не принимает занятый/зарезервированный
другому slug ID. Создаёт:

```
prx301_onboarding/
  AGENTS.md
  init.php
  config/prx_module.php                        # полный манифест
  config/prx301_onboarding.php
  migrations_pg/pgMigration_Prx_301_001_Initial.php
  classes/Controller/Prx301/Page.php           # actor + policy
  classes/Controller/Prx301/Api.php            # внутренний JSON API
  classes/Policy/Prx301/Step.php               # объектные правила
  classes/Service/Prx301/Store.php             # SQL через Prx_Db
  views/prx301/index.php            # page-kit
tests/Unit/Prx301PolicyTest.php                # гость и чужая организация
```

Обязательны `AGENTS.md` и `config/prx_module.php`. По необходимости: `init.php`
для маршрутов, `migrations_pg/` для таблиц, `views/prxNNN/` для UI (NNN — id модуля), `i18n/`,
`messages/`, `fixtures/`. Для prx-модулей миграции — только `migrations_pg/`.
У `kind: library` каталог views называется по имени библиотеки, например
`llm/views/llm/` или `cookie_consent/views/cookie_consent/`. PostgreSQL-миграции
`llm` лежат в `migrations_pg/`. Служебные библиотеки остаются в
`application/modules/`, отдельного `application/libraries/` нет.

Каркас закрыт: `status draft`, `audience admin`, права `prx301.view` и
`prx301.manage` добавляются в `application/config/acl.php` генератором.
Миграция создаёт таблицу `prx_301_steps` — замените её на свою схему.
Это не готовый сервис: нужна бизнес-логика в Store и правила в Policy.
Контракты фундамента — `docs/FOUNDATION.md`.

## 2. Реализуйте вертикальный MVP

1. Опишите владельца данных и интеграционный источник истины.
2. Создайте `prx_301_*` идемпотентной миграцией. Определите индексы, ссылки,
   retention, down/восстановление и ограничения персональных данных.
3. Реализуйте сервисные методы с object policy/оргконтекстом и валидацией.
   Никакого массового `values($_POST)` и доступа к чужим таблицам. SQL — только
   через `Prx_Db` (именованные параметры, `Prx_Db::transaction`).
4. Контроллер тонкий: `$this->actor()->require(...)`, вызов Service, View/JSON.
   CSRF уже проверен `Prx_PostGuard`; идентификатор пользователя — из сессии.
5. Для долгой задачи используйте `Service_Prx120_JobQueue::instance()`;
   публичный контракт очереди уточняйте в её сервисе/README. Добавьте handler
   в `workers/prx_workers/handlers/<модуль>/`, реестр `handlers/__init__.py`
   и `worker.channels` манифеста (docs/WORKERS.md, «Структура»).
6. Уведомления — через `prx597`, доменные события — `Event::publish('prxNNN.entity.action',
   [...], $initiator_id)` (только ID и скаляры); слушатели — `Event::listen()` в `init.php`.
   Ссылки на приватные объекты не должны раскрывать их данные.
7. Добавьте test cases: свой/чужой сотрудник и организация, нет роли,
   нет сессии, нет CSRF, дубль интеграционного события, отказ зависимости.

## 3. Подключите

Добавьте путь в `application/config/modules.php` после зависимостей и до
зависимых модулей. Явно выдайте права в `application/config/acl.php`.
Выполните `make migrate`. После готовности добавьте манифесту:

```php
return [
    'id' => 301,
    'slug' => 'prx301_onboarding',
    'title' => 'Онбординг-трек',
    'short' => 'Адаптация новых сотрудников',
    'icon' => 'fas fa-user',
    'href' => '/prx301_onboarding',
    'group' => 'HR и развитие персонала',
    'sort' => 301,
    'created_at' => date('Y-m-d'),
    'status' => 'stable',
    'audience' => 'auth',
    'acl' => 'prx301.view',
];
```

Схема манифеста проверяется статически: `python3 .tools/manifest_check.py`.
`audience` определяет видимость каталога, не заменяет ACL маршрута.
В `portal.php` статус меняйте только по фактической готовности; меню строится
из подключённых манифестов, не из плана.

**URL по умолчанию — префикс и слаг:** `/prx301_onboarding`, а не `/onboarding`.
Так разработчик сразу видит владельца и находит модуль в коде. Подстраницы —
`/prx301_onboarding/<action>`, админские — `/prx301_onboarding/admin`,
внутренний API — `/prx301_onboarding/api/<action>`. Внешний машинный контракт —
`/api/v1/<domain>/…`; ключ, scope и журнал — модуль `prx150_api_hub`.
Короткие адреса без префикса (`/onboarding`, `/documents`) — legacy; новые
модули так не называются. `route_registry.py` проверяет это для модулей с
`created_at` от 23.11.2026.

## 4. Дизайн и проверка

Наследуйте `Controller_Base`. Каркас страницы собирайте из page-kit:
`partials/page_header` → `partials/filters` → таблица или карточки →
`partials/empty_state` / `partials/pagination` → `partials/flash`.
Используйте `prx-*` компоненты DESIGN-SYSTEM.md, не подключайте CDN.
Разметка адаптивная, dark mode обязателен. `PRX.*` и `data-prx-*` — общий JS.
Новые классы Tailwind требуют `npm run build:css` и коммита app.css.
Перед пушем: `make check` и `bash .tools/php_check_all.sh`.
