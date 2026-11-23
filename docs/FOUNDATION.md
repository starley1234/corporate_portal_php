# Фундамент платформы

Что уже есть и как этим пользоваться. Коротко: пять классов, один guard,
один набор партиалов. Всё остальное — поверх них.

| нужен | берём | где |
|---|---|---|
| «кто действует» | `Prx_Actor` | `application/classes/Prx/Actor.php` |
| «можно ли ему эту запись» | `Prx_Policy` + политика модуля | `application/classes/Prx/Policy.php` |
| SQL и транзакции | `Prx_Db` | `application/classes/Prx/Db.php` |
| доменное событие | `Prx_Event` | `application/classes/Prx/Event.php` |
| защита POST | `Prx_PostGuard` | `application/classes/Prx/PostGuard.php` |
| каркас страницы | page-kit | `application/views/partials/{page_header,filters,empty_state,flash}.php` |
| новый модуль | scaffold v2 | `make new-module id=312 slug=claims name="Заявки" entity=Claim` |

## 1. Доступ: actor → policy → сервис

```php
// контроллер
$this->actor()->require('prx312.view');            // RBAC: ключ из acl.php
$rows = (new Service_Prx312_Store)->list($this->actor());

// сервис
$policy = new Policy_Prx312_Claim;
$row = Prx_Db::one('SELECT * FROM prx_312_claims WHERE id = :id', [':id' => $id]);
$policy->requireView($actor, $row);                // объектная часть: 404/403
```

Правила:
- `Prx_Actor` создаёт только сервер (`$this->actor()`), из сессии и ACL.
  В сервис нельзя передавать массив прав из контроллера — только actor.
- RBAC (`Prx_Actor::can()`) отвечает на «есть ли ключ», политика — на
  «можно ли эту запись». Дублировать одно другим нельзя.
- `require*()` отвечает 403, если нет права на модуль, и 404, если запись
  чужая (существование не раскрываем).
- Все ключи прав объявлены в `application/config/acl.php`; маска `prxNNN.*`
  допустима для реестра модуля. `python3 .tools/acl_lint.py` проверяет обе
  стороны: объявлен и используется.

## 2. SQL: именованные параметры и транзакции

```php
Prx_Db::select('SELECT * FROM prx_312_claims WHERE organization_id = :org', [':org' => $org]);
Prx_Db::one('SELECT ... WHERE id = :id', [':id' => $id]);          // строка или NULL
Prx_Db::execute('UPDATE ... WHERE id = :id', [':id' => $id]);       // число строк
Prx_Db::insert('INSERT ... RETURNING id', [':name' => $name]);      // [id, rows]

Prx_Db::transaction(function () use ($data) { ... return $id; });   // откат при исключении
Prx_Db::identifier('prx_312_claims');                               // только идентификатор
```

Склейка значений в SQL запрещена; имена таблиц и колонок — только через
`Prx_Db::identifier()`. Чужие таблицы не читаются: только публичный сервис
модуля-владельца.

## 3. События

```php
Prx_Event::publish('prx312.claim.approved', ['claim_id' => $id], $actor->id());
```

Имя — `prxNNN.<entity>.<action>`, payload — ID и скаляры, не ORM-объекты.
Подписчик отвечает за идемпотентность. Если `prx208_events` не подключён,
событие пропускается с предупреждением, а не роняет запрос.

## 4. POST и CSRF

Ничего не нужно писать в контроллере: `Controller_Prx100_Base::before()`
вызывает `Prx_PostGuard::assert_safe()`. Проходят:

- валидный токен — поле `csrf` или заголовок `X-CSRF-Token`;
- машинный канал — заголовок `Authorization` (Bearer) или `X-Worker-Token`;
- входящий вебхук с собственной подписью — `application/config/post_guard.php`.

Клиентский токен ставит один хук в `public_html/assets/js/app.js`
(`PRX.csrfToken()` + `X-CSRF-Token` для fetch/XHR), meta — в
`application/views/partials/head.php`. В форму достаточно добавить
`<input type="hidden" name="csrf" value="<?= HTML::chars(Security::token()) ?>">`.
Проверка: `python3 .tools/js_csrf_lint.py` + `tests/Js/prx_csrf.test.mjs` (fetch, XHR, Request с body, гость, чужой origin).

## 5. Манифест — контракт модуля

`config/prx_module.php` обязателен и проверяется статически:

| kind | что требует |
|---|---|
| `module` | `id`, `slug`, `title`, `short`, `status`, `audience`, `href`, `acl` |
| `platform` | `id`, `slug`, `title`, `short` (без карточки витрины) |
| `library` | `title`, `short` (драйвер БД, фасад LLM) |

`status`: draft|beta|stable|deprecated. `audience`: public|auth|admin|agent.
`python3 .tools/manifest_check.py`.

## 6. Страница: page-kit

Собирается из четырёх партиалов, в этом порядке:

```php
echo View::factory('partials/page_header', [...]);   // крошки, H1, действия
echo View::factory('partials/filters', [...]);       // GET-фильтры
echo View::factory('partials/empty_state', [...]);   // если пусто
echo View::factory('partials/pagination', [...]);    // если много
echo View::factory('partials/flash', ['flash' => $flash]);
```

Разметку фильтров, пустых состояний и flash не копировать в модуль.
`python3 .tools/design_audit.py --module prxXXX` проверяет вёрстку.

## 7. Новый модуль

```bash
make new-module id=312 slug=claims name="Заявки" entity=Claim
```

Создаёт манифест, маршруты, контроллер, политику, сервис, миграцию, view,
тест политики и `AGENTS.md`. Модуль не подключается сам: включите его в
`application/config/modules.php`, затем `make migrate && make check`.

## 8. Гейты

`make check` — одна команда перед пушем:

| проверка | что ловит |
|---|---|
| `portal_check.py` | контракты основы, каналы воркеров, RAG |
| `manifest_check.py` | схему манифестов |
| `acl_lint.py` | необъявленные и мёртвые ключи прав |
| `route_registry.py` | маршруты → контроллеры, реестр в `docs/architecture/dist/routes.json` |
| `doc_links_check.py` | мёртвые ссылки в документации |
| `js_csrf_lint.py` | CSRF на клиенте |
| `test_storage_ops.py` | файловое хранилище |

`bash .tools/php_check_all.sh` — PHP-синтаксис и линтеры (без PHP в окружении
работает tree-sitter-разбор).
