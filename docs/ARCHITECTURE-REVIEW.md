# Архитектура PRX: решения и целевой контракт модуля

Документ фиксирует два вопроса: **какие решения приняты** (23.11.2026) и
**каким обязан быть модуль**, чтобы серийная разработка не расползалась.
Аудит, из которого выросли эти решения, выполнен и закрыт; его находки либо
исправлены, либо перечислены в §5.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index docs/ARCHITECTURE-REVIEW.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   1. Целевой контракт модуля | 37 | 4,5 КБ |
|     1.1 Состав | 39 | 1,1 КБ |
|     1.2 Слои и правила | 61 | 1,0 КБ |
|     1.3 HTTP/API | 75 | 1,0 КБ |
|     1.4 Манифест | 90 | 0,5 КБ |
|     1.5 События | 98 | 0,5 КБ |
|     1.6 Тесты | 107 | 0,4 КБ |
|   2. Стиль | 116 | 1,3 КБ |
|   3. Решения владельца (23.11.2026) | 134 | 1,4 КБ |
|   4. Что реализовано | 149 | 1,7 КБ |
|   5. Что осталось (осознанно) | 168 | 1,2 КБ |
|   6. Что проверено (23.11.2026, песочница без PHP) | 184 | 1,1 КБ |
<!-- context-index:end -->
Где что читать:

| нужен | где |
|---|---|
| doctrine проекта, инварианты | `AGENTS.md` |
| API фундамента (actor, policy, SQL, события, CSRF, page-kit) | `docs/FOUNDATION.md` |
| создание модуля | `docs/MODULE_GUIDE.md` |
| дизайн и вёрстка | `docs/DESIGN-SYSTEM.md` |
| карта сервисов | `application/config/portal.php`, `docs/ROADMAP.md` |

---

## 1. Целевой контракт модуля

### 1.1 Состав

```
prxNNN_slug/
  AGENTS.md                  # состояние, границы, маршруты, права, сервисы, данные, проверки
  init.php                   # только маршруты; без логики
  config/
    prx_module.php           # манифест по схеме (make manifest-check)
    prxNNN_slug.php          # настройки модуля
  migrations_pg/
    pgMigration_Prx_NNN_001_*.php
  classes/
    Controller/PrxNNN/*.php  # тонкие: actor -> service -> view/JSON
    Policy/PrxNNN/*.php      # объектные правила доступа (Prx_Policy)
    Service/PrxNNN/*.php     # публичный контракт + SQL через Prx_Db
    Model/PrxNNN/*.php       # только простые CRUD/ORM
  views/prxNNN_slug/*.php    # только представление; без SQL и решений о доступе
tests/Unit/PrxNNN*Test.php   # политика: гость и чужая организация
```

Генератор: `make new-module id=312 slug=claims name="Заявки" entity=Claim`.

### 1.2 Слои и правила

- **Controller** — без SQL, бизнес-правил и решений о доступе к объекту.
  Только: метод запроса, `$this->actor()->require(...)`, вызов сервиса,
  выбор view/JSON, flash/redirect.
- **Policy** — RBAC + ownership + оргконтекст. Возвращает 403 (нет права на
  модуль) или 404 (чужая запись).
- **Service** — единственное место бизнес-логики и SQL. Публичный метод —
  стабильный контракт. Чужие таблицы не читаются: только публичный сервис
  владельца.
- **View** — получает готовые данные и флаги; не знает ACL, не делает SQL.
- **Manifest** — метаданные для каталога/поиска/health/cleanup/schedule.
  **Не является проверкой доступа.**

### 1.3 HTTP/API

| Тип | Префикс | Auth | CSRF | Ответ |
|---|---|---|---|---|
| Внешний machine API | `/api/v1/<domain>/<resource>` | Bearer integration token | нет | JSON `{data, error, meta}`, 401/403/409/413/422 |
| Внутренний AJAX | `/<module>/api/<action>` | session | да | JSON envelope, 401 JSON для гостя |
| Worker API | `/prxNNN/worker/<action>` | `X-Worker-Token` | нет | JSON, отдельный токен |
| Webhook | `/prxNNN/hook/<provider>` | подпись провайдера | нет | 200 после проверки |
| Страница | `/<noun>` или `/prxNNN/admin` | session + ACL | да на POST | HTML |

- `Idempotency-Key` — для всех создающих внешних операций.
- `Cache-Control: no-store` — для приватных данных.
- Rate limit — на внешние API и чувствительные операции.
- OpenAPI/JSON Schema — на каждый внешний API; contract tests.

### 1.4 Манифест

Обязательные поля зависят от `kind` (`module` | `platform` | `library`) —
таблица в `docs/FOUNDATION.md` §5. Инварианты: `id` = номер папки (кроме
семейства ядра `prx100_*`), `slug` = имя папки, `acl` объявлен в
`application/config/acl.php`, `href` не повторяется. Проверка —
`python3 .tools/manifest_check.py`. Списков модулей в ядре нет.

### 1.5 События

- Доменные события — `Prx_Event::publish('prxNNN.entity.action', [...])`:
  payload — ID и скаляры, не ORM-объекты. Подписчик сам обеспечивает
  идемпотентность.
- Пользовательские уведомления — `Service_Prx597_Notify`; событие объявлено
  в `notify.events` манифеста.
- `Kohana Event::fire` — legacy; новые модули его не используют.

### 1.6 Тесты

Минимум на модуль: **unit** (валидация, policy, идемпотентность),
**integration** (миграции + сервис на чистой схеме), **HTTP-smoke** (гость
401, чужая организация 403, своя запись 200, CSRF 403, invalid 400),
**design** (`design_audit.py --module prxNNN`).

---

## 2. Стиль

- **PHP:** PSR-12 + Kohana-конвенции, массивы `[]`, `<?php echo`, имена
  `Controller|Service|Policy|Task_PrxNNN_*`, таблицы `prx_NNN_name`
  (множественное число), колонки `snake_case`. Запрещены `$_POST`/`$_GET` в
  view и service. Стандартные колонки: `id bigserial`, `created_at`,
  `updated_at`, `created_by`, `organization_id` для org-scoped таблиц.
- **Views:** 120 символов soft limit; `HTML::chars()` для пользовательского
  текста; без `<style>` и инлайн-скриптов, кроме JSON-конфига и `data-*`.
  Повторяющиеся блоки — в `partials/` (page-kit).
- **JS/CSS:** общее поведение — только `PRX.*`; токены и компоненты — в
  `.tools/tailwind/input.css`; имена классов не конструировать из частей;
  после новых классов — `npm run build:css` и коммит `app.css`.
- **Документация:** один модуль — одна памятка `AGENTS.md`; правила не
  дублируются, каждое — в одном источнике; битые ссылки — ошибка линтера.

---

## 3. Решения владельца (23.11.2026)

| Решение | Принято |
|---|---|
| Дизайн-baseline | **v6 exact** (`required_design_sample`); витрина альтернатив удалена |
| Каркас навигации | **Текущий shell** |
| Гранулярность | **Гибрид**: доменные модули + общие page-kit/контракты; отдельный модуль — только если нужен свой lifecycle/permissions. Цена over-fragmentation известна: 110 модулей удалено (`docs/REMOVED-MODULES.md`) |
| Внешний API | **`/api/v1/<domain>/...`** для machine, `/<module>/api/...` для внутреннего AJAX |
| Доступ к БД | **`Prx_Db` + именованные параметры** для новых модулей; legacy — как есть |
| CI | **Без CI-файла**: проект уходит с GitHub. Гейты локальные — `make check`, `bash .tools/php_check_all.sh` |
| PHPStan | **Отложено** до стабилизации фонда; сейчас работает tree-sitter-разбор и 10 PHP-линтеров |
| Документооборот и ИИ-сервисы | **Проба пера**: не расширять и не зависеть от `prx390`–`prx393`; будут удалены или переделаны |

---

## 4. Что реализовано

| было в аудите | стало |
|---|---|
| Ручной CSRF в каждом контроллере, 36 POST без токена | `Prx_PostGuard` — одна глобальная проверка; хук в `app.js` ставит `X-CSRF-Token`; `.tools/js_csrf_lint.py` + `tests/Js/prx_csrf.test.mjs` |
| Манифест без схемы: 13 из 25 без `id`/`slug` | Схема `kind: module\|platform\|library`; `.tools/manifest_check.py` — 24/24 чисто |
| 53 из 68 ключей ACL не объявлены | `.tools/acl_lint.py`: 36 объявлено, 78 используется, 0 ошибок |
| Нет реестра маршрутов | `.tools/route_registry.py`: 87 маршрутов, проверка controller/action, `docs/architecture/dist/routes.json` |
| Массив «actor» и склейка SQL | `Prx_Actor`, `Prx_Policy`, `Prx_Db` (`:name`, `transaction()`, `identifier()`) |
| Две шины событий | `Prx_Event::publish()` — единый вход в `prx208` |
| Нет page-kit | `partials/page_header`, `filters`, `empty_state`, `flash`, `pagination`; prx300 переведён на них |
| Scaffold v1: полуфабрикат | Scaffold v2: манифест, маршруты, контроллер+API, политика, сервис, миграция, тест, `AGENTS.md` |
| Мёртвые ссылки в документации | `.tools/doc_links_check.py` — 0 мёртвых; `docs/FOUNDATION.md` как вход для агента |
| Лишние сущности | Удалены `prx140_design_lab`, `archive/`, перенос старых браузерных закладок в Избранное |

Все гейты собраны в `make check`.

---

## 5. Что осталось (осознанно)

- SQL в манифесте (`count_sql`/`delete_sql` в `cleanup`): переносить вместе с
  рефакторингом очистки; сейчас контракт работает и проверяется
  `portal_check.py`.
- God-сервисы legacy (`prx120`, `prx597`): новые модули на них не растут,
  разделение — отдельная задача.
- Контрактные тесты платформенных модулей — следующий шаг (нужен PHP-стенд).
- Аудит ассетов и удаление мёртвых — отдельно, на новые модули не влияет.
- `prx390`–`prx393` — проба пера: удалить или переделать по решению владельца.
- `workers/`: `ocr_client.py`/`docflow_trace.py` несут диагностический контекст
  `doc.normalize` — обобщить при удалении/переделке пробы пера; разбиение
  `base.py`/`queue.py` — отдельный шаг (`docs/WORKERS.md`, приоритеты).

---

## 6. Что проверено (23.11.2026, песочница без PHP)

- `make check` — 31 модуль, 226 классов, 24 манифеста (0 ошибок), 36
  объявленных и 78 используемых ключей ACL (0 ошибок), 87 маршрутов
  (0 ошибок), 0 мёртвых ссылок, 0 замечаний CSRF, 10 storage-тестов.
- `bash .tools/php_check_all.sh` — 436 PHP-файлов, 0 синтаксических ошибок
  (tree-sitter), 33 миграции без замечаний, дизайн-аудит чист.
- `npm test` — 35/35; `make architecture`, `make env-check` — чисто.
- `.tools/route_registry.py` нашёл реальный дефект: маршрут `prx391/api`
  ссылался на несуществующий `Prx391_Api` — исправлен на общий `Prx39x_Api`.
- Не проверено (нет PHP): phpunit с `tests/Unit/PrxFoundationTest.php`,
  `make new-module` вживую, HTTP smoke. Первый запуск на стенде — обязательный.
