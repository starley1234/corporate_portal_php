# AGENTS.md — nj676_mcphub (MCP Hub)

> Памятка агенту: назначение, состояние, контракты и ловушки модуля.
> Правила платформы — в корневом `AGENTS.md`.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/nj676_mcphub/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Назначение | 17 | 1,4 КБ |
|   Состояние | 35 | 1,0 КБ |
|   Данные | 47 | 1,9 КБ |
|   Контракты | 60 | 3,4 КБ |
|   Правила модуля | 105 | 2,8 КБ |
|   Ловушки | 136 | 7,3 КБ |
<!-- context-index:end -->
## Назначение

Публичный **глобальный каталог MCP-серверов** (`/mcphub`) и **Meta-MCP шлюз**
(`POST /mcphub/mcp`, старый адрес `/mcphub/api/rpc`) для ИИ-агентов.

Каталог собирается сам двумя путями:
1. **официальный реестр и онлайн-каталоги** (`registry.modelcontextprotocol.io`,
   `punkpeye/awesome-remote-mcp-servers`, `jaw9c/awesome-remote-mcp-servers`,
   `registry.npmjs.org` по `keywords:mcp-server`, канал `nj676.registry`) —
   машинные карточки и живые удалённые MCP-эндпоинты без модели (до 2500–5000 за прогон);
2. **поиск** (канал `nj676.discover`) — GitHub (`topic:mcp-server`,
   `topic:model-context-protocol`, `topic:mcp-servers`) + npm + DuckDuckGo +
   README + LLM, когда администратор нажал «Найти и добавить» (n = 12, тема необязательна).

**Не путать с `/mcp`** (nj160_mcp): там инструменты *этого* сервиса, здесь —
каталог чужих серверов и шлюз к ним. Задачи и права разные, поэтому модули не
сливаются: страницы ссылаются друг на друга и объясняют разницу.

## Состояние

- **Готово к работе (25.10.2026).** Каталог MCP-серверов с реестром, опросом
  эндпоинтов, русским слоем, семантическим поиском и шлюзом `/mcphub/mcp`
  (7 инструментов `hub_*`); админка `/mcphub/admin` на 5 вкладках
  (сборка, карточки, качество, спрос, прогоны); полевой аудит серверов
  воркером `nj676.audit` («алмазы»). QA-раунды шлюза P0–P7 закрыты.
- Последнее: тёмная тема и телефон во всех вьюхах `views/676/`, форма
  «Исследовать серверы агентом» не вылезает (25.10.2026).
- Подробная история по датам, числа калибровок семантики и QA-отчёты —
  `JOURNAL.md` рядом (читать по разделам).

## Данные

| Таблица | Что |
|---|---|
| `nj_676_servers` | карточки: метаданные GitHub, пакеты, удалённый адрес, схемы tools, звёзды, доверие, происхождение (`manual`/`auto`/`registry`), `registry_name`, `featured`, `url_valid`/`url_note` (приёмка адреса, миграция 009), русский слой `name_ru`/`description_ru`/`ru_source`/`ru_at` (миграция 011) |
| `nj_676_runs` | прогоны: тип (`discover`/`probe`/`registry`), тема, счётчики, прогресс, ошибка (чистится манифестом, 180 дней) |
| `nj_676_run_items` | строки прогона (миграция 012): `run_id`, `slug`, `name`, `status` (`added`/`updated`/`skipped`), `reason` — отчёт «что и почему» для `/mcphub/admin?tab=runs&run=N#runs-log` |
| `nj_676_search_log` | журнал спроса: запрос, `norm` для группировки, сколько нашлось, sha256 ключа; хранится `NJ676_DEMAND_KEEP_DAYS` (180 дней) |
| `nj_676_agent_profiles` | фильтры `hub_configure` за конкретным ключом агента (sha256 ключа, не ключ) |
| `nj_676_calls` | телеметрия вызовов: `call_id`, `token_hash`, `server_slug`, `tool`, `ok`, `latency_ms`, `error_code` — источник `call_id` и Wilson |
| `nj_676_feedback` | отзывы по `call_id` (один на вызов): рейтинг, `outcome`, теги, комментарий |
| `nj_676_audits` | журнал полевых исследований открытых MCP-серверов воркером-агентом (`nj676.audit`, миграция 017): `utility_score`, `safety_score`, `verdict` (`gem`/`useful`/`niche`/`questionable`/`broken`), `is_gem`, `summary`, `use_cases`, `safety_notes`, `observations`, `tested_tools`, `latency_ms`, `model` |

## Контракты

- Публично: `/mcphub`, `/mcphub/<slug>`, `/mcphub/export/<slug>`,
  `GET /mcphub/api/search`, `GET /mcphub/api/tools/<slug>`,
  `POST /mcphub/mcp` (MCP Streamable HTTP; анонимно — каталог и серверы без
  ключей, по `Bearer` — инструменты N2J). `POST /mcphub/api/rpc` — тот же код.
- Самопроверка: `GET /mcphub/api/selfcheck` (+`?http=1`). Шаги и аргументы
  зашиты в код — ручка не должна становиться прокси; лимит
  `NJ676_SELFCHECK_LIMIT` (12/час на IP).
- Перевод: `GET /mcphub/api/translate-candidates?limit=N` (карточки без
  `name_ru`, только `X-Worker-Token`) и `POST /mcphub/api/translate-item`
  (`{items:[{slug,name_ru,description_ru}]}`, канал `nj676.translate`).
  Кнопка «Перевести карточки на русский» — `POST /mcphub/admin/translate`
  (CSRF, `limit` ≤ 100), результат виден в блоке `#translate`.
- Воркер (`X-Worker-Token` = `WORKER_API_TOKEN`):
  `POST /mcphub/api/discover-{state,candidates,item,progress,finish}`,
  `registry-{batch,finish}`, `probe`, `tick` (таймер; автосборка при
  `NJ676_AUTO_DISCOVER=1`).
- Админ (`nj676.admin` + CSRF): `/mcphub/admin`, `POST /mcphub/admin/discover`,
  `POST /mcphub/admin/schemas` (перепроверка JSON Schema сохранённых tools без сети),
  `POST /mcphub/admin/registry`, `POST /mcphub/admin/probe`,
  `POST /mcphub/admin/cancel`, `GET /mcphub/api/status/<id>`.
- Каналы воркера: `nj676.discover` (поиск), `nj676.registry` (реестр),
  `nj676.probe` (живость). Неудача проверки: 1 → `degraded`, 2 подряд →
  `offline`, успех сбрасывает `probe_failures`. Тик ставит её не чаще
  `NJ676_PROBE_EVERY_SEC`; `probe_targets()` отдаёт только https-карточки.

**Шлюз** — JSON-RPC 2.0, stateless, POST. `tools/list` отдаёт РОВНО семь
инструментов — константа `Service_Nj676_Gateway::ROOT_TOOLS`:
`hub_search_tools`, `hub_ready`, `hub_describe_tool`, `hub_call_tool`,
`hub_feedback`, `hub_status`, `hub_configure`. Контроллер сверяется с той же константой, а не со
своим списком. Ответы несут `structuredContent`; ошибки — стабильные `code`
(INVALID_ARGUMENT, TOOL_NOT_FOUND, AUTH_REQUIRED, UPSTREAM_TIMEOUT,
UPSTREAM_ERROR, RATE_LIMITED, SCHEMA_INVALID), поля `field`, `hint`,
`retryable`, `request_id` (`req_mcp_…`). Успешный вызов отдаёт `call_id` —
единственный ключ к `hub_feedback`; вызовы и отзывы считаются в
`Service_Nj676_Telemetry`.

`hub_search_tools` отдаёт готовность (`auth`, `install`, `readiness`,
`verified_at`, `latency_ms`), сигнатуры инструментов и строку `next`; фильтры
`only_ready`, `auth`, `install`, `readiness`, `max_latency_ms`, `transport`,
`no_install` (агент без прав установки). `hub_call_tool` принимает `dry_run`:
план вызова без исполнения (готовность, аргументы, `mutating`, `cost`).
Пагинация — `cursor`/`total`/`next_cursor` по уже отранжированной выдаче.

## Правила модуля

- **Эндпоинт один и по протоколу.** `/mcphub/mcp` — Streamable HTTP:
  `initialize`, `notifications/*` → 202, `tools/list`, `tools/call`. Stateless,
  CORS открыт.
- **Ничего не исполняется на сервере.** stdio не запускается, в конфиг — только
  закреплённые версии (`npx -y pkg@1.2.3`). Удалённый вызов — только HTTPS из
  опубликованной карточки, после проверки DNS (приватные/loopback/metadata
  запрещены), без редиректов, с таймаутом и лимитом ответа.
- **Ключи не хранятся.** В карточке — имена env, в конфиге — `SET_LOCALLY`.
  Токен удалённого сервера — из окружения `NJ676_REMOTE_TOKEN_<SLUG>`.
- **Ручное сильнее авто.** Автосборка не перезаписывает `name`/`description`
  карточки с `origin = 'manual'` и не стирает уже импортированные tools, если
  новых схем нет.
- **Оригинал не теряется.** Русский текст живёт в `name_ru`/`description_ru`,
  рядом с авторским `name`/`description`. Витрина показывает `display_*`
  (`shape()`), оригинал — в `<details>` и подписью. `ru_source = 'manual'`
  модель не перезаписывает никогда; пустой перевод не затирает сохранённый.
- **Формула текста карточки — в двух местах.** `Semantic::document()` и
  SQL-источник в `application/config/nj115.php` (nj115_semantic): имя,
  описание, оба русских поля, категории, инструменты. Правишь одно — правь
  второе, иначе сайт и воркер дают разные векторы.
- **Журнал прогона — в базе, не в логе.** `McpHub::record_run_item()` пишет
  строку на каждой карточке; для прогонов до миграции 012 `run_items()`
  собирает список по каталогу и честно помечает `reconstructed`.
- **Категории — один список:** `McpHub::CATEGORIES`, копия в воркере.
- **Поиск русский.** `SYNONYMS` (поиск→search…), ранжирование в SQL: имя
  важнее описания, фраза сильнее; `to_tsquery` — только из очищенных слов.
- **Экранирование:** slug — `rawurlencode`, текст — `HTML::chars`; сбой
  счётчика не роняет страницу (try/catch + `Log_Plain`).

## Ловушки

- **Тёмная тема и телефон (25.10.2026).** Все вьюхи `views/676/` держат пары
  `text-gray-700 dark:text-gray-200`, `border-gray-200 dark:border-gray-700`,
  `bg-*-50 dark:bg-*-950/40`; новый цветной класс без `dark:` — регресс ночного
  режима. Формы-действия в шапках карточек (например, «Исследовать серверы
  агентом») — `w-full lg:w-auto` + кнопка `w-full sm:w-auto`, не `shrink-0`.
- `shape()` зовёт `export_config()`; `export_config()` **не должен** звать
  `shape()` — рекурсия. Новые колонки миграций читаются вьюхами, дефолты в
  `shape()` обязательны: иначе старые карточки роняют страницу.
- `search_text` заполняет сервис при сохранении: прямой `UPDATE` в обход
  сервиса оставляет карточку невидимой для поиска.
- **`readiness` — не колонка.** Считается в `shape()`/`readiness()` из
  `auth_mode`, `health_status`, `health_checked_at`, `url_valid` и `tools`,
  поэтому в SQL её не отфильтровать: фильтр — после выборки
  (`Gateway::passes_filters`), и выборку под строгий фильтр надо брать с
  запасом: для «покажи готовое» есть `McpHub::ready_servers()` и
  `ready_stats()` — они смотрят всю базу, а не первую страницу выдачи.
- **Адрес проверяется до публикации.** `Registry::apply()` и
  `apply_discovery_item()` зовут `endpoint_check()`: небезопасный адрес
  остаётся в карточке как улика, но с `url_valid = false` и
  `health_status = 'offline'`. Молча стирать адрес нельзя — агент снова
  получит «готово» на пустом месте.
- **Отзыв — только по своему `call_id`.** `Telemetry::feedback()` сверяет
  `call_id` с `nj_676_calls` и отбивает чужой `token_hash`; у сервисов
  платформы slug пуст и в сообщении он называется `N2J platform`.
- `action_admin` делегирует `/mcphub/admin/save` → `action_save()`,
  `/mcphub/admin/demand` → `action_demand()`: старая форма и новая кнопка
  должны работать обе.
- **Админка — пять вкладок.** Оболочка `views/676/admin.php` (шапка, вкладки,
  показатели) + тело вкладки в `views/676/admin/<tab>.php` (`build`, `cards`,
  `quality`, `demand`, `runs`). Тело подключается обычным `include`, чтобы
  стенд рендерил вкладки без платформы. Данные каждой вкладки собирает
  `Controller_Nj676_McpHub::_admin_data()` — новый блок вьюхи требует своего
  ключа там, иначе вкладка отрисуется пустой. Старые ссылки `?run=N` и
  `?slug=...` сами переключают вкладку (`runs` / `cards`), их не ломать.
- `McpClient` обязан вернуть `Mcp-Session-Id` в следующем запросе и послать
  `notifications/initialized`, иначе `tools/call` получает 400 у большинства
  публичных серверов. Ответ бывает SSE — `McpClient::parse_sse`.
- `NJ676_ALLOW_LOCAL_PROBE` открывает `http://127.0.0.1` только вне production:
  это стенд `.tools/nj676_mcp_stub.py`, а не боевой прокси.
- **`properties => []` — невалидная JSON Schema.** Пустой PHP-массив кодируется
  как `[]`, спецификация требует объект; один такой инструмент валит весь
  `tools/list` у строгих клиентов (OpenCode: «expected record, received array»).
  Схемы прогонять через `McpHub::schemas_as_objects()` (в `/mcp` —
  `Service_Nj160_Registry::mcp_list()`). Тест: `tests/Unit/McpSchemaTest.php`.
- **`AND`/`OR` слабее `=`.** `$x = $a AND $b` присваивает только `$a`. 23.10.2026
  это дало вечную перезагрузку `/mcphub/admin?run=N`. Скобки или `&&`; ловится
  `python3 .tools/php_ternary_lint.py application` (есть в `bin/test`).
- Live-ряд (`partials/live_status`) перезагружает страницу на терминальном
  статусе: не отдавать ему «готово» там, где нарисован «идёт» — будет петля.
- **Тип прогона обязателен.** `nj_676_runs.kind` = `discover` / `probe` /
  `registry`: без него ночной тик принимал свою же проверку живости за
  «идущую сборку». «Идёт сборка» — только `latest_run_of_kind('discover')`.
- **Спрос не открывает кошелёк.** `Demand::log()` зовётся на каждом поиске,
  включая анонимный. Мгновенный прогон — только `kind = 'agent'`, при
  `NJ676_AUTO_DISCOVER=1`, с паузой, суточным потолком и запретом повторять
  тему 14 дней. Ограничители не убирать: бот займёт очередь на сутки. Сам
  `log()` мягкий: ошибка вставки не превращает найденный ответ в ошибку.
- Расписания в манифесте нет: триггеры собирает nj603, его `_fire()` — не наша
  правка. Добор идёт по внешнему таймеру.
- **`readiness` — не колонка.** Считается на лету из `auth_mode`,
  `health_status`, `probe_tools_at`, `schema_valid`; в SQL её нет — фильтруй
  после выборки (`Gateway::passes_filters`), иначе `only_ready` вернёт сервер
  с обязательным ключом.
- **Телеметрия мягкая.** `Telemetry::record()`/`feedback()` в try/catch: без
  таблиц 008 вызов работает, просто без статистики. Отзыв принимается только
  по `call_id` из `nj_676_calls`, чужой `token_hash` отклоняется, повтор —
  `ON CONFLICT DO NOTHING`.
- **Демот не путается со «stale».** `demote_by_feedback()` ставит
  `health_checked_at = NULL`, чтобы проверка шла вне очереди; поэтому
  `readiness()` смотрит на `health_note` с префиксом «агенты» ДО проверки
  возраста — иначе демотированный сервер выглядел бы «давно не проверялся».
- **Отзывы считаются по уникальным ключам.** `negative_agents()` — distinct
  `token_hash`, пустой хеш не считается: один агент не роняет сервер.
