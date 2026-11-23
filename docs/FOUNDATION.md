# Фундамент платформы

Что уже есть и как этим пользоваться. Коротко: классы `Prx_*` (actor, policy,
SQL, идемпотентность), фасады `Event::publish()` и `Storage::`, один guard и один
набор партиалов. Всё остальное — поверх них.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index docs/FOUNDATION.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   1. Доступ: actor → policy → сервис | 35 | 2,3 КБ |
|   2. SQL: именованные параметры и транзакции | 69 | 2,1 КБ |
|     Идемпотентность | 85 | 1,2 КБ |
|   3. События | 104 | 1,8 КБ |
|   5. Файлы: фасад `Storage::` | 133 | 1,7 КБ |
|   4. POST и CSRF | 158 | 1,0 КБ |
|   6. Манифест — контракт модуля | 173 | 0,5 КБ |
|   7. Страница: page-kit | 186 | 0,7 КБ |
|   8. Новый модуль | 201 | 0,4 КБ |
|   9. Гейты | 211 | 1,0 КБ |
<!-- context-index:end -->
| нужен | берём | где |
|---|---|---|
| «кто действует» | `Prx_Actor` | `application/classes/Prx/Actor.php` |
| «кто действует» (машина) | `Prx150_Principal` | `application/modules/prx150_api_hub/classes/Prx150/Principal.php` |
| «можно ли ему эту запись» | `Prx_Policy` + политика модуля | `application/classes/Prx/Policy.php` |
| SQL и транзакции | `Prx_Db` | `application/classes/Prx/Db.php` |
| доменное событие | `Event` | `application/classes/Event.php` |
| файл | `Storage` | `application/classes/Storage.php` |
| защита POST | `Prx_PostGuard` | `application/classes/Prx/PostGuard.php` |
| идемпотентный ключ | `Prx_Idempotency` | `application/classes/Prx/Idempotency.php` |
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

Три независимые оси: ability — `$actor->can()` (RBAC через `Prx_Acl`);
доступ к записи — политика модуля на базе `Prx_Policy`; гейт запроса или
машины — `Prx_Gate` (worker-токен, auth-wall и allowlist). Гейт не является
проверкой прав на объект. Токен проверяют только точки входа; их явный список
и запрет прямого вызова ACL из модулей проверяет `.tools/classes_lint.py`.

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
- Политика принимает `Prx_Principal`, а не только `Prx_Actor`: одно правило
  проверяет и человека, и машинный ключ `prx150`. Роли и `can()` есть только
  у `Prx_Actor`, поэтому в `scope()` их вызывают под `instanceof` — у машины
  это всегда FALSE.

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

### Идемпотентность

`Prx_Idempotency` — платформенный ключ повтора для внешних операций: тот же
ключ и то же тело → тот же результат; другое тело → `conflict`; истёкший TTL
(`PRX_IDEMPOTENCY_TTL_HOURS`, по умолчанию 24) → `expired`. Тело не хранится,
только sha256.

```php
$idem = Prx_Idempotency::begin('prx395.extract', (string) $client_id, $key, $sha, $db);
if ($idem['state'] !== Prx_Idempotency::STATE_NEW) { /* replay | conflict | expired */ }
// ... работа, которая должна быть в одной транзакции с ключом
Prx_Idempotency::finish($idem['id'], ['job_id' => $job_id], $db);
```

`begin()` открывает транзакцию и берёт advisory-lock, `finish()` пишет
результат и подтверждает, `abort()` откатывает. Отдельное соединение —
обязательно то же, что у остальных записей транзакции. Для простых случаев
есть `Prx_Idempotency::run(...)`.

## 3. События

```php
// сервис модуля-издателя
Event::publish('prx312.claim.approved', ['claim_id' => $id], $actor->id());

// init.php модуля-слушателя
Event::listen('prx312.claim.approved', function ($payload, $event_name, $initiator_id) {
    // перечитать объект через публичный сервис владельца, проверить свои права
});
```

- Имя — `prxNNN.<entity>.<action>` (`Event::NAME_PATTERN`); иначе
  `InvalidArgumentException`.
- Payload — ID и скаляры (массивы скаляров и NULL допустимы), не ORM-объекты;
  иначе `InvalidArgumentException` до журнала.
- Инициатор — явный третий аргумент; сессия не читается, `NULL` — система.
- Подписчик отвечает за идемпотентность; шина не гарантирует доставку.
- Если `prx208_events` не подключён, событие пропускается с предупреждением,
  а не роняет запрос.

Именование: `Event` и `Storage` — канонические фасады без префикса. `Prx_*` —
только классы фундамента (`Actor`, `Acl`, `Gate`, `Policy`, `Db`, `PostGuard`, `Idempotency`,
`Principal`, `Exception`); код модулей — `Service_/Controller_/Policy_/Task_/
Model_PrxNNN_*`; `Service_Prx212_*` и другие сервисы — внутренности своих модулей.
Публикация мимо `Event` и обращение к `Service_Prx212_*`/`Model_Prx212_*`/
`ORM::factory('Prx212…')` вне prx212 запрещены — `.tools/portal_check.py`
(в `make check`) ловит это в `application/**`.

## 5. Файлы: фасад `Storage::`

```php
// элемент $_FILES или ['tmp_name', 'name', 'size', 'type']; кто действует — явный
$file_id = Storage::save($_FILES['doc'], 'prx312_claims', $actor->id());
$file_id = Storage::put($text, 'result.txt', 'prx312_claims');   // строка → файл

$bytes = Storage::bytes($file_id);        // содержимое целиком: локальный диск или S3
$meta  = Storage::metadata($file_id);     // id, filename, extension, mime_type, size, hash,
                                          // is_public, module, created_at, url
$url   = Storage::url($file_id, $actor->id());
Storage::delete($file_id, $actor->id());
```

- Сессия не читается: `$user_id` — явный аргумент, `NULL` — гость/система.
- Фасад не решает права на объект: `file_id` берётся из своей авторизованной
  записи (документ, письмо), права проверяет вызывающий модуль. Файловые правила
  (владелец, публичность) применяет только `delete()`.
- Приватные артефакты внутренних пайплайнов (файл без пользователя, не публичный):
  `read_owned`, `delete_owned`, `owned_ids_before` с префиксом своего модуля.
- `prx212_file_storage` не подключён: `save`/`put`/`delete*` бросают
  `Storage_Exception`, чтение возвращает `NULL`/`FALSE`/`[]`.
- Наружу не отдаются `storage_path`, `backend_id` и ORM — замена prx212 не
  меняет вызывающие модули.

## 4. POST и CSRF

Ничего не нужно писать в контроллере: `Controller_Prx102_Base::before()`
вызывает `Prx_PostGuard::assert_safe()`. Проходят:

- валидный токен — поле `csrf` или заголовок `X-CSRF-Token`;
- машинный канал — заголовок `Authorization` (Bearer) или `X-Worker-Token`;
- входящий вебхук с собственной подписью — `application/config/post_guard.php`.

Клиентский токен ставит один хук в `public_html/assets/js/app.js`
(`PRX.csrfToken()` + `X-CSRF-Token` для fetch/XHR), meta — в
`application/views/partials/head.php`. В форму достаточно добавить
`<input type="hidden" name="csrf" value="<?= HTML::chars(Security::token()) ?>">`.
Проверка: `python3 .tools/js_csrf_lint.py` + `tests/Js/prx_csrf.test.mjs` (fetch, XHR, Request с body, гость, чужой origin).

## 6. Манифест — контракт модуля

`config/prx_module.php` обязателен и проверяется статически:

| kind | что требует |
|---|---|
| `module` | `id`, `slug`, `title`, `short`, `status`, `audience`, `href`, `acl` |
| `platform` | `id`, `slug`, `title`, `short` (без карточки витрины) |
| `library` | `title`, `short` (драйвер БД, фасад LLM) |

`status`: draft|beta|stable|deprecated. `audience`: public|auth|admin|agent.
`python3 .tools/manifest_check.py`.

## 7. Страница: page-kit

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

## 8. Новый модуль

```bash
make new-module id=312 slug=claims name="Заявки" entity=Claim
```

Создаёт манифест, маршруты, контроллер, политику, сервис, миграцию, view,
тест политики и `AGENTS.md`. Модуль не подключается сам: включите его в
`application/config/modules.php`, затем `make migrate && make check`.

## 9. Гейты

`make check` — одна команда перед пушем:

| проверка | что ловит |
|---|---|
| `portal_check.py` | контракты основы, каналы воркеров, RAG, публикация событий только через `Event::publish()` |
| `manifest_check.py` | схему манифестов |
| `acl_lint.py` | необъявленные и мёртвые ключи прав |
| `route_registry.py` | маршруты → контроллеры, реестр в `docs/architecture/dist/routes.json` |
| `doc_links_check.py` | мёртвые ссылки в документации |
| `js_csrf_lint.py` | CSRF на клиенте |
| `test_*.py` | файловое хранилище; правило «события — только через фасад» (`tests/test_event_lint.py`) |

`bash .tools/php_check_all.sh` — PHP-синтаксис и линтеры (без PHP в окружении
работает tree-sitter-разбор).
