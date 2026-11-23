# Новый бизнес-модуль PRX

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
  views/prx301_onboarding/index.php            # page-kit
tests/Unit/Prx301PolicyTest.php                # гость и чужая организация
```

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
   в `workers/prx_workers/handlers/`, HANDLERS и `worker.channels` манифеста.
6. Уведомления — через `prx597`, доменные события — `Prx_Event::publish()`.
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
    'href' => '/onboarding',
    'group' => 'HR и развитие персонала',
    'sort' => 301,
    'status' => 'stable',
    'audience' => 'auth',
    'acl' => 'prx301.view',
];
```

Схема манифеста проверяется статически: `python3 .tools/manifest_check.py`.
`audience` определяет видимость каталога, не заменяет ACL маршрута.
В `portal.php` статус меняйте только по фактической готовности; меню строится
из подключённых манифестов, не из плана. Публичные URL — существительные,
админские — `/prxXXX/admin`, API — `/<service>/api/<action>`.

## 4. Дизайн и проверка

Наследуйте `Controller_Base`. Каркас страницы собирайте из page-kit:
`partials/page_header` → `partials/filters` → таблица или карточки →
`partials/empty_state` / `partials/pagination` → `partials/flash`.
Используйте `prx-*` компоненты DESIGN-SYSTEM.md, не подключайте CDN.
Разметка адаптивная, dark mode обязателен. `PRX.*` и `data-prx-*` — общий JS.
Новые классы Tailwind требуют `npm run build:css` и коммита app.css.
Перед пушем: `make check` и `bash .tools/php_check_all.sh`.
