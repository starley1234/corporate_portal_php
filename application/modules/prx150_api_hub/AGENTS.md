# prx150_api_hub — API-доступ

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx150_api_hub/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 14 | 0,5 КБ |
|   Контракты | 21 | 1,6 КБ |
|   Состояние | 41 | 0,4 КБ |
|   Карта | 47 | 0,6 КБ |
|   Как добавить сервис | 61 | 0,5 КБ |
|   Проверки | 69 | 0,2 КБ |
<!-- context-index:end -->
## Назначение

Единая точка выдачи внешним потребителям ключей, прав и лимитов. Модуль не
отдаёт бизнес-данных: он проверяет `Authorization: Bearer`, scope, мягкий лимит
и пишет использование. Сервис-модули наследуют `Controller_Prx150_Machine` и
объявляют права в `api_scopes` своего манифеста.

## Контракты

- Таблицы: `prx_150_api_clients`, `prx_150_api_usage` — только через
  `Service_Prx150_Keys` и `Service_Prx150_Usage`.
- Ключ: `prx_` + 64 hex; в базе только SHA-256. Полный ключ показывается один раз.
- Scope: `prxNNN.<action>`; объявляет модуль-владелец, префикс обязан совпадать
  с его ID. `*` — все scope (только для внутренних задач).
- Ошибки: `Prx150_Error` → JSON `{"error":{"code":"…","details":{…}}}`.
- Организация ключа (`organization_id`, NULL) — скаляр без FK: модуль не читает
  чужие таблицы. При выдаче существование проверяет `Service_Prx300_Directory`;
  без подключённого prx300 допустим только NULL.
- Машинный principal: `Controller_Prx150_Machine::principal()` → `Prx150_Principal`
  (`id`, `name`, `organization_id`, `scopes`). Реализует `Prx_Principal`, поэтому
  одна объектная политика (`Prx_Policy`) работает и для человека, и для ключа.
  Ролей и `can()` у машины нет.
- Машинный контроллер: `Controller_Prx150_Machine::respond()` — ключ, scope,
  лимит, журнал в `finally`, заголовок `X-Request-Id`.
- Страница: `/prx150_api_hub` (ключи), `/usage`, `/docs`.
- Чистка: `prx_150_api_usage` старше 90 дней — манифест `cleanup`.

## Состояние

Каркас готов: ключи, scope, лимит, журнал, админка и машинный базовый
контроллер. Не проверено на PHP-стенде: создание/отзыв ключа, 401/403/429,
журнал, организация ключа. Миграции `prx_150_001` и `prx_150_002` — `make migrate`.

## Карта

| что | где |
|---|---|
| маршруты | `init.php` |
| манифест | `config/prx_module.php` |
| ключи и лимиты | `classes/Service/Prx150/Keys.php` |
| машинный principal | `classes/Prx150/Principal.php` |
| журнал | `classes/Service/Prx150/Usage.php` |
| реестр scope | `classes/Service/Prx150/Services.php` |
| машинный базовый контроллер | `classes/Controller/Prx150/Machine.php` |
| админка | `classes/Controller/Prx150/Admin.php`, `views/prx150/*` |
| схема | `migrations_pg/pgMigration_Prx_150_001_ApiHub.php`, `…_002_KeyOrganization.php` |

## Как добавить сервис

1. В `config/prx_module.php` модуля:
   `'api_scopes' => ['prx450.parse' => ['title' => '…', 'rate_limit' => 60]]`.
2. Контроллер машинного API наследует `Controller_Prx150_Machine` и задаёт
   `protected const SCOPE = 'prx450.parse';`; действие оборачивает в `respond()`.
3. Маршрут — `/api/v1/<service>/…`; страница — `/prxNNN_slug`.

## Проверки

`make check` (манифест, ACL, маршруты, ссылки), `bash .tools/php_check_all.sh`.
Ключ и журнал требуют PostgreSQL: на стенде проверять создание/отзыв и 401/403/429.
