# JOURNAL.md — nj676_mcphub

> Журнал работ по датам (что сделано, почему, какие числа). Вынесен из
> `AGENTS.md` 25.10.2026, чтобы памятка оставалась «картой» ≤ 40 КБ.
> Читать по датам через `python3 .tools/context_pack.py section`, не целиком.
> Новые записи — сюда, в начало; в `AGENTS.md` → «Состояние» только текущий
> итог в 5–10 строк.

## Состояние (журнал)

- **Готово (24.10.2026, полный аудит и исправления):**
  - В `Service_Nj676_Analysis` исправлены несуществующие имена колонок (`health_error` → `health_note` в `_blocked()`, `slug` → `server_slug` в `_failing()`).
  - Во всех SQL-запросах к `tools`/`categories`/`packages`/`env_keys` (`Translate::candidates()`, `McpHub::find_by_tool()`, `McpHub::facets()`, `Registry::apply_one()`, `application/config/nj115.php`) добавлена защита `CASE WHEN jsonb_typeof(...) = 'array'`.
  - В `Controller_Nj676_McpHub::action_save()` исправлен рендер ошибки валидации под 5-вкладочный шаблон, а в форму и `McpHub::save()`/`validate_record()` добавлены `name_ru`, `description_ru`, `remote_url`, `featured` и переиндексация `Semantic::index_slugs()`.
  - На вкладке карточек (`/mcphub/admin?tab=cards`) добавлены поиск и фильтр состояния (`all_for_admin($limit, $filters)`), а на вкладке спроса (`/mcphub/admin?tab=demand`) — отображение реального текста запроса (`sample`) и пакетный запуск топ-3 тем (`all=1`).
  - В `views/676/index.php` пагинация сохраняет фильтр `ready=1`, а лимит на страницу приведён к потолку `60`.
  - В `Service_Nj676_Hub`, `action_server()`, `action_api_search()` и `views/676/detail.php` используется русский слой (`display_name`/`display_description`) и актуальный адрес шлюза `/mcphub/mcp`.
  - Словари `SYNONYMS` и `RU_TO_EN` объединены без дублирования циклов; удалены мёртвые методы `Gateway::http_json_rpc()`, `Registry::name_from()` и ветка `discover_candidates`.
  - В `config/nj_module.php` добавлены правила `cleanup` для всех 6 таблиц журналов (`nj_676_runs`, `nj_676_run_items`, `nj_676_search_log`, `nj_676_calls`, `nj_676_feedback`, `nj_676_analysis`).
  - Кнопка проверки переименована в «Опросить MCP-эндпоинты», добавлена пакетная постановка опроса до 300 эндпоинтов непересекающимися пачками по 60 (`Service_Nj676_McpHub::queue_probe()`), авто-продолжение опроса (`queue_probe_continuation()`), карантин мёртвых эндпоинтов (`probe_failures >= 10` на 30 дней), параллельный опрос внутри воркера `nj676.probe` (`asyncio.Semaphore`), сброс кэша готовности (`reset_ready_cache()`) и единая функция комплексного обслуживания каталога `Service_Nj676_McpHub::maintain()` (`POST /mcphub/admin/maintain`), запускающая одной кнопкой синхронизацию реестра (`nj676.registry`), проверку схем, опрос эндпоинтов, полную цепочку перевода (`Translate::queue_all()`) и векторное индексирование (`Semantic::index()`).
  - Интерфейс `/mcphub/admin` переведён на подход «простой режим по умолчанию + тонкая настройка под раскрывашками `<details>`»; выровнены элементы управления, отступы вкладок (`mt-5`) и стили `<select class="n2j-input n2j-select">`.
  - По QA-отчёту шлюза `/mcphub/mcp` (P0–P3):
    - **P0a (Индексация и точный поиск по слагу/имени):** `slug` и `registry_name` включены в `McpHub::search_text()` и `Registry::search_text()`; в `McpHub::_where()` и `_rank_sql()` добавлен поиск и приоритетное ранжирование по `lower(slug) = :exact_q`, `slug ILIKE :like` и `registry_name ILIKE :like`; в `Gateway::search_tools()` и `match_detail()` добавлено прямое включение карточек по точному `slug`/сегменту и обнуление шумового веса служебных слов (`mcp`, `server`) в составных запросах (`temporal-mcp` больше не проигрывает `amanmcp`); авто-переиндексация `Semantic::index_slugs()` подключена к `McpHub::upsert()`, `apply_discovery_item()` и `Registry::apply_batch()`/`apply()`, а `hub_status` отдаёт `unindexed_servers`.
    - **P0b (Пороги семантики и русская морфология):** `CONFIDENT_DISTANCE` откалиброван до `0.48`, добавлены фильтр бессмысленного набора букв (`Semantic::is_gibberish()`, например `xqzvbnmplkjhgf`) и отсечка плоского шумового плато в `Semantic::select()`, кросс-языковое расширение русскоязычных запросов в `Semantic::query_text()` и кумулятивный скоринг «имя + описание + инструменты» в `Gateway::match_detail()` («иконки» выводит `dashboard-icons` в топ-1).
    - **P1 (`ready_*` и честность ключей):** в `ready_overview()`, `ready_stats()` и `hub_status` разделены `ready_probe_ok`, `ready_anonymous`, `ready_keyed` и `ready_optional`; убран принудительный `auth = 'none'` из `search_filters(only_ready: true)`, из-за которого `hub_ready`/`hub_status` (60) расходились с `hub_search_tools(only_ready: true)` (50) и писали ложное `hidden: 10`; причина `why: «ключ настроен»` выдаётся только при реальном наличии серверного токена (`has_server_credentials()`).
    - **P2 (`auth: optional` и preview-квота):** добавлен режим `auth: optional` (`McpHub::is_optional_auth()`) для freemium-серверов, работающих без ключа в демо-режиме (`ai-echoloc-company-technographics`), и почасовая анонимная preview-квота в `Gateway::check_preview_quota()` (`NJ676_ANON_PREVIEW_LIMIT`).
    - **P4 (Калибровка анизотропного векторного пространства и >60 серверов, 24.10.2026):** `CONFIDENT_DISTANCE` ужесточён до `0.36`, в `Semantic::select()` добавлены проверки отрыва 1-го от 10-го (`MIN_GAP_10 = 0.04`), отрыва от медианы (`>= 0.05`), лимит плотного пучка (`< 12` хитов при отсутствии явного лидера) и флаг `grey_zone` (`approximate: true` в `Gateway::search_tools()` при дистанции `0.28..0.36` без лексического подтверждения); в `Semantic::is_gibberish()` добавлено распознавание клавиатурных свайпов (`asdfghjkl`, `йцукенгшщз`) и псевдослов вне словаря (`blargfnordqux`); `McpHub::ready_servers(0)` и `Gateway::search_tools()` приведены к единому полному списку `_ready_all()` без обрезки на 60; в `hub_status` счётчик `needs_key` платформы N2J отделён от `catalog_needs_key`/`catalog_needs_install`, а `broken`-серверы полностью исключены из семантического хвоста, фоллбэков и подсказок `next`.
    - **P5 (Точность ранжирования, отсечка хвоста 0.8, запрет ложных префиксов и расширение онлайн-источников, 24.10.2026):**
      - `Gateway::SCORE_CUTOFF` поднят с `0.6` до `0.8`, а при точном совпадении слага/имени (`exact`/`slug_match`) отсекаются чисто векторные соседи без лексического пересечения (`ai-huntboard-huntboard`, `huntboard`, `ai-echoloc-company-technographics` → `total: 1`, `truncated: 0`).
      - В `Gateway::text_has_variant()`, `Gateway::match_detail()` и `McpHub::_tsquery_string()` введена проверка границ токенов и запрет ложных однокоренных префиксов (`read` ≠ `readiness`/`ready`/`readme`/`thread`, `crypto` ≠ `cryptography`, `rate` ≠ `generate`/`accurate`/`ratelimit`) + требование совпадения предметного слова при наличии общего глагола/квалификатора (`read pdf` → `pdfmathtranslate` вместо `store-readiness`; `курс биткоина` отсекает `agent-cryptography`).
      - В `Gateway::search_tools()` добавлен тайбрейкер `tie_ready` при близком итоговом скоре (`<= 0.20`) и релевантности `>= 0.45`, а также сохранение лучшей готовой альтернативы при `only_ready: false` (`курс биткоина` → `dripmetrics` (`ready`) выше `funding-rates-mcp` (`needs_install`); `скриншот сайта` → `agenticplug` (`ready`) выше `firecrawl` (`needs_key`)).
      - Поле `next` и подсказки `→ за этим: hub_call_tool` формируются только для `ready`-серверов; если все найденные серверы имеют статус `needs_key`/`needs_install` (`распознать речь`), `next` честно сообщает об отсутствии готового без ключа/установки сервера и предлагает `/mcphub/<slug>` или `hub_ready {}`.
      - В ответ `hub_search_tools`/`hub_ready` добавлены поля `returned` и `truncated` (и строка `Выдача: returned=X, truncated=Y`), а при `only_ready: false` без запроса явно поясняется `Показаны первые N готовых, остальные — с cursor`.
      - В `workers/n2j_workers/handlers/nj676_registry.py` и `nj676_mcphub.py` добавлены дополнительные онлайн-источники MCP-серверов: каталоги живых удалённых эндпоинтов `punkpeye/awesome-remote-mcp-servers` (1400+ серверов, приоритет открытым `🔓`) и `jaw9c/awesome-remote-mcp-servers`, а также пакетный поиск `registry.npmjs.org` (`keywords:mcp-server`); лимит синхронизации по умолчанию увеличен до `2500`.
    - **P6 (Доверие `trust`, чтение отзывов агентов, сортировка `reliability`/`trust` и пагинация REST `/mcphub/api/search`, 24.10.2026):**
      - В выдачу `hub_search_tools`, `hub_ready`, `hub_describe_tool` (и с `tool`, и только с `server`), `mcp_catalog_search` и `mcp_server_plan` добавлены поля `trust` (0–100, с учётом живых вызовов и отзывов агентов), `stars`, `health_status`, `calls`, `success_rate`, `feedback_summary` и `recent_feedback`; в `McpHub::shape()` и `Registry::apply_one()` исправлен нулевой `trust` у карточек из реестра.
      - Исправлена сортировка `sort: "reliability"` и добавлено значение `sort: "trust"` в `Gateway::search_tools()`: кандидаты сначала ранжируются по эшелону работоспособности (`ready` > `needs_key`/`needs_install` > `stale` > `degraded` > `broken`), а внутри эшелона — по составному рейтингу надёжности и доверия (`trust`, `wilson`, баланс отзывов, `stars`), так что сбоящий `degraded`-сервер (`ai-pdfassistant`) никогда не встаёт выше рабочих.
      - Добавлено чтение отзывов агентов: поле `recent_feedback: [{call_id, server, tool, rating, outcome, tags, comment, at}]` и `feedback_summary` в `hub_describe_tool`, режим чтения в `hub_feedback {"server":"<slug>","limit":10}` (без `call_id`), а также REST-маршруты `/mcphub/api/feedback`, `/mcphub/api/reviews`, `/mcphub/api/server` и `/mcphub/api/server/<slug>/feedback`.
      - В `Controller_Nj676_McpHub::_filters()`, `action_api_search()` и `McpHub::search()`/`_order()` поддержаны параметры `limit`/`per_page`/`per` (от 1 до 60), `offset`/`cursor`, `sort`/`sort_by` (`trust`, `reliability`, `latency`, `stars`, `popular`, `relevance`, `new`, `name`) и `order` (`asc`/`desc`).
    - **P7 (Финальная полировка Round 10 QA Report до 10/10, 24.10.2026):**
      - В `Gateway::feedback_tool()` блокируется передача `server` вместе с пишущими полями (`rating`, `outcome`, `comment`, `tags`) без `call_id` — возвращается честный `INVALID_ARGUMENT` вместо молчаливого перехода в `mode: "read"`; пустой вызов `hub_feedback {}` объясняет оба режима работы (запись по `call_id` + `rating` и чтение по `server` + `limit`).
      - В `Gateway::search_tools()` при `sort: "reliability"` / `sort: "trust"` все не-сбоящие состояния (`ready`, `needs_key`, `needs_install`, `stale`) объединены в один эшелон исправности (`reliability_tier = 2` выше `degraded = 1` и `broken = 0`) и сортируются монотонно по `trust` по убыванию → `success_rate` → `tie_ready` → `stars` → `score` (на `weather` порядок `trust`: `80, 65, 15`).
      - В `Gateway::ready_tools()` по умолчанию включён `explain = TRUE` (поле `why` всегда заполнено в `hub_ready`) и `limit = 8` (синхронизировано с витриной `live_now(8)`), а в `inputSchema` инструмента `hub_ready` объявлены `explain` и `cursor`.
      - В `Gateway::status()` добавлены описание `trust (0–100)` и обоих режимов `hub_feedback`, секция `feedback` и счётчик `healthy_but_keyed` (`healthy_verified - ready_servers`); в `Gateway::validate_arguments()` убрано дублирование `description` в поле `expected` ошибок диапазона (`1…50`).
      - В `Gateway::validate_arguments()` при ошибке `enum` или диапазона поле `hint` возвращает конкретный список допустимых значений этого поля (`relevance | latency | reliability | trust`), а `why` при `explain: true` включает `доверие N/100` и `★ N` даже у серверов без вызовов.
    - **Round 7 (Калибровка анизотропии `qwen3-embedding-0.6b`, единство `hub_ready` >60 и защита от `broken` в `next`):** `CONFIDENT_DISTANCE` ужесточён до `0.36`, порог полной близости `$near` снижен с `0.35` до `0.22`, в `Semantic::select()` добавлено центрирование относительно 10-го/медианного кандидата (`MIN_GAP_10 = 0.04`, `gap_median >= 0.055`, лимит конуса `< 12`) и пометка серой зоны (`grey_zone` → `approximate: true`); `Semantic::is_gibberish()` дополнен детектором прохода по клавиатурным рядам QWERTY/ЙЦУКЕН (`qwertyuiopasdfgh`, `фывапролдж`) и словарём бессмыслицы (`gibberish`, `абракадабра`, `zzzqqq`); `McpHub::ready_servers(0)` и `Gateway::search_tools()` приведены к полному пулу готовых (`296` вместо `60`) и полному счёту каталога (`1091` с `ready`-серверами в топе при `only_ready: false`); в `hub_status` разведены закрытые инструменты платформы N2J и внешние серверы `catalog_needs_key`, а `broken`-серверы исключены из чистой семантики, fallback-выдачи и подсказки `next`.
    - **Round 11 (Полевой воркер-исследователь `nj676.audit` — «Алмазы каталога» — и рейтинг по отзывам агентов `agent_rating`, 24.10.2026):**
      - Добавлены миграция `017` (`nj_676_audits` + колонки `audit_score`, `safety_score`, `audit_verdict`, `is_gem`, `audit_summary`, `audit_report`, `audited_at`, `agent_rating`, `agent_reviews_count` в `nj_676_servers`), сервис `Service_Nj676_Audit` и воркер `workers/n2j_workers/handlers/nj676_audit.py` (канал `nj676.audit`).
      - Воркер-агент обходит открытые удалённые MCP-серверы: выполняет `initialize` + `tools/list`, сканирует описания и схемы инструментов на безопасность (prompt-injection, скрытые мутации), выбирает 1–2 безопасных read-only инструмента без обязательных секретов, делает живой пробный `tools/call` и составляет структурированный отчёт с оценками пользы (`utility_score` 0–100), безопасности (`safety_score` 0–100), вердиктом (`gem` / `useful` / `niche` / `questionable` / `broken`), сценариями применения (`use_cases`) и наблюдениями (`observations`).
      - Отчёты индексируются в `search_text`, лексическом скоринге `Gateway::match_detail()` и векторном документе `Semantic::document()`; добавлены сортировки `sort=utility` и `sort=agent_rating`, фильтр `only_gems=true` / `gems=1`, публичная витрина «Алмазы каталога» на `/mcphub`, подробная секция `#audit-report` на `/mcphub/<slug>`, REST-ручка `GET /mcphub/api/gems`, приёмка `POST /mcphub/api/audit-item`, запуск из админки (`POST /mcphub/admin/audit`), из комплексного обслуживания `McpHub::maintain(['with_audit' => TRUE])` и постепенный фоновый обход в `POST /mcphub/api/tick`.
      - Добавлен байесовский рейтинг на основании отзывов агентов `Service_Nj676_Telemetry::compute_agent_rating()` (`agent_rating` 1.00–5.00 и `agent_reviews_count`), автоматически пересчитываемый и сохраняемый в `nj_676_servers` при каждом `hub_feedback` и отдаваемый в UI, REST API и MCP-шлюзе (`hub_search_tools`, `hub_ready`, `hub_describe_tool`, `hub_feedback`).
- **Готово (23.10.2026, третий раунд — русский каталог и отчёт прогона):**
  - **Русский слой карточки** (`name_ru`, `description_ru`, `ru_source`,
    миграция 011) + канал `nj676.translate`: модель переводит имя и описание,
    оригинал автора остаётся рабочим текстом для шлюза и агента. Кнопка
    «Перевести всё» / «Порция 20» в `/mcphub/admin?tab=quality#translate`, раз в
    сутки порцию ставит тик (`NJ676_AUTO_TRANSLATE=0` выключает).
  - **Перевод не умирает на середине:** «Перевести всё» — цепочка независимых
    задач по 40 карточек (`Translate::queue_all`, `ALL_JOB`/`ALL_MAX_JOBS`),
    `timeout_sec = 1800` вместо 900 (прежние 900 с обрывали задачу на середине:
    23.10.2026 перевелись 5 карточек из 1037). У воркера свой бюджет времени
    (`NJ676_TRANSLATE_BUDGET`, 1500 с): он заканчивает работу сам и докладывает
    итог, а не погибает по таймауту. Воркер повторяет пачку, делит её
    пополам при сбое и докладывает итог на `/mcphub/api/translate-finish`;
  - **клейм карточки** (`ru_claimed_at`, миграция 013) — одну карточку берёт
    одна задача; клейм снимается в `apply()` или через `release()` по отчёту
    воркера, а если задача упала целиком — сам через 60 минут. Без клейма
    цепочка задач переводила один и тот же список по кругу;
  - клейм — оптимизация: если миграция ещё не применена, `candidates()`
    работает без него (catch + повторный запрос), перевод не встаёт.
  - **Отчёт перевода** — админка показывает «Последний прогон перевода»:
    сколько переведено/пропущено/осталось и ошибки модели или сайта.
  - **Отчёт прогона** — `nj_676_run_items` (миграция 012): добавлено /
    обновлено / пропущено и причина по каждой карточке; `#runs-log` ведёт к
    отчёту, а не к логу.
  - **Кнопка индексации** переехала в блок «Смысловой поиск» и называется
    «Проиндексировать новые карточки»; индексация идёт страницами по всему
    каталогу и сравнивает хеши текста (`pending_items`), поэтому хвост базы
    тоже получает векторы. Под раскрывашкой — «Пересобрать индекс целиком»
    (`force`, `Semantic::index($limit, TRUE)`): нужен после смены модели
    эмбеддингов и стоит токенов, поэтому не на первом плане.
- **Готово (24.10.2026, протокол и устойчивость вызова):**
  `DELETE /mcphub/mcp` → 204 (клиент закрывает сессию и не считает транспорт
  сломанным), ответ несёт `MCP-Protocol-Version`, согласованный на
  `initialize`. Аргументы принимаются и JSON-строкой (`arguments_of`).
  `hub_describe_server`-ветка отдаёт `tool_names`/`total_tools` — все имена,
  даже когда схемы показаны только для 20. Сетевой сбой читающего инструмента
  повторяется один раз (`retried` в ответе); пишущие не повторяются. Ответ
  удалённого `429` доносит `retry_after` и код `RATE_LIMITED`. Категории из
  `hub_configure` — предпочтение в ранжировании (`+0.08`), а не фильтр:
  раньше они молча сужали выдачу до одной категории. Выдача набирается по
  страницам, пока не наберётся запрошенное: после фильтров готовности не
  остаётся «пусто при живых серверах» (строгий режим листает до 10 страниц —
  готовые стоят далеко в ранжировании тысячной базы).
- **Готово (24.10.2026, третий тест агента — смысл и честность):**
  - **Смысловой поиск** `Service_Nj676_Semantic`: карточки индексируются
    векторами `EMBEDDING_API_URL` в nj115 (коллекция `nj676_mcphub`, источник
    в `config/nj115.php`, формула текста = `Semantic::document()`), индексация —
    очередь nj120 (тик раз в сутки + кнопка в админке). Поиск = max(смысл,
    слова), порог `RELEVANCE_FLOOR`; без эмбеддинг-сервера — только слова.
  - **Честная релевантность** `match_detail()`: имя > описание > имена
    инструментов, `why` перечисляет ТОЛЬКО совпавшие слова, `next` указывает
    на лучший инструмент (`best_tool`), а не на первый.
  - **Настройки анонима не сохраняются** (`hub_configure` → `AUTH_REQUIRED`):
    раньше хеш IP был «ключом», и один агент сужал выдачу соседям.
  - **Ошибки клиента ≠ сбой сервера**: `INVALID_ARGUMENT` не входит в
    надёжность и в `failing_servers`; проба воркера повторяется; `last_ok_at`
    (миграция 010) не даёт объявить сломанным сервер, который только что
    ответил (кроме демота по отзывам агентов).
  - **mutating/cost честные**: аннотации upstream (`readOnlyHint`,
    `destructiveHint`), иначе `null`/`unknown`; `sign_up` больше не «безопасное
    чтение», `get_blog_post` — не «изменяет данные».
  - **Контекст**: в поиске нет `properties`, `why` только по `explain`,
    `additionalProperties: false`, лишние аргументы и усечённый `limit`
    сообщаются в ответе; описания из чужих репозиториев проходят
    `clean_remote_text()` (инъекции, обрезка по предложению) и в выдаче агента
    помечены строкой «слова их авторов», на карточке — плашкой.
  - **Протокол**: parse error → 400, батчи отклоняются с 400, `Mcp-Session-Id`
    в ответе, SSE присылает `list_changed` только при реальном изменении
    каталога, `hub_feedback` на неизвестный `call_id` → `INVALID_ARGUMENT`,
    MCP-ответ снимает `Set-Cookie` (кука сайта агенту не нужна).
  - **Одни и те же цифры**: `ready_servers()`/`ready_stats()` идут через
    `_ready_rows()` + `readiness()`; витрина показывает `ready_stats.servers`
    («готовы к вызову»), как `hub_ready`/`hub_status`. Wording «доступно без
    ключа» заменено на «готовы к вызову» — в ready попадают и серверы, ключ
    которых лежит в окружении сайта.
- **Готово (24.10.2026, витрина):** галочка «Готовые к вызову» — фильтр
  `ready` в `_where()` (SQL, а не после выборки: иначе счётчик и пагинация
  врут), число готовых рядом с галочкой и отдельная подсказка в пустом
  состоянии. Условия строже карточки: сервер с ключом в окружении сайта
  готов, но человеку без ключа он не откроется.
- **Готово (24.10.2026, по итогам второго теста агента):**
  `McpHub::endpoint_check()` — https + публичный хост проверяются при импорте
  (`Registry::apply`, `apply_discovery_item`), при сохранении карточки и на
  каждой живой проверке; итог в `url_valid`/`url_note` (миграция 009),
  `readiness` = `broken`, а не «вызов сразу». Пустой `tools/list` при живом
  сервере → `degraded` с причиной. Новый инструмент `hub_ready` («что можно
  вызвать сейчас») и необязательный `query` у `hub_search_tools`; сортировка
  `latency`/`reliability`, `ignore` — слаг-и серверов, которые уже падали у
  агента; серверы, которые у него уже падали (2+ ошибки без успехов за 7 дней,
  `Telemetry::failing_servers()`), скрываются автоматически и возвращаются
  через `hub_configure {"ignore":[]}`. Поиск: морфология (`McpHub::stem()`),
  словарь `RU_TO_EN` («курс валют» → currency/exchange), OR-семантика,
  fallback на ближайшие категории (`CATEGORY_HINTS`). `stats()` — `records_updated` / `healthy_verified`
  вместо `fresh` / `operational` (старые ключи живы). `initialize` объявляет
  `tools.listChanged = true`, GET `/mcphub/mcp` с `Accept: text/event-stream`
  отдаёт `notifications/tools/list_changed`. `hub_describe_tool` описывает и
  инструменты платформы N2J (`kind: platform`); текст `hub_search_tools`
  показывает не больше 4 инструментов на сервер и дверь к остальным. Анонимные бюджеты раздельные
  (чтение/вызовы, час + минута), отказ — 429 + `Retry-After` + `RATE_LIMITED`.
- **Готово (23.10.2026, готовность и телеметрия):** в выдаче `auth`,
  `install`, `readiness`, `verified_at`, `latency_ms`; фильтры `only_ready`,
  `auth`, `install`, `readiness`, `max_latency_ms`, `transport`;
  `hub_describe_tool` (полная JSON Schema + пример вызова), `hub_feedback` по
  `call_id`; телеметрия вызовов, Wilson-надёжность, `why` в поиске; миграция
  008 (`nj_676_calls`, `nj_676_feedback`, `schema_valid`).
- **Готово (23.10.2026, качество выдачи):** синонимы в обе стороны
  (`SYNONYMS` RU→EN, `EN_TO_RU` — «wikipedia» находит «вики»), мягкий fallback
  на 3 ближайших сервера вместо пустого ответа, авто-демот сервера после
  `NJ676_DEMOTE_REPORTS` (3) РАЗНЫХ агентов с отрицательным отзывом + внеочередная
  проверка, `dry_run`, `no_install`.
- **Готово (23.10.2026, спрос агентов):** журнал запросов (`nj_676_search_log`),
  автопополнение каталога по спросу (мгновенно при пустом ответе агента и по
  ночному пинку), карточка «Спрос агентов» в админке, перевод темы в
  английские фразы в воркере, тип прогона (`kind`: discover/probe/registry).
- **Готово (23.10.2026, каталог):** импорт реестра (миграция 004), свой
  MCP-клиент с сессией и SSE, `/mcphub/mcp`, поиск по имени инструмента.
- **Проверки:** `.tools/nj676_mcphub_stand.php` (169 проверок), `bin/test`
  (`tests/Unit/McpSchemaTest.php`, `McpDemandTest.php`),
  `cd workers && python3 -m pytest tests/test_nj676_mcphub.py -q`,
  `python3 .tools/design_audit.py --module nj676_mcphub`.
- **Следующий шаг:** песочница (Docker/gVisor) для stdio-серверов — это
  единственное, что осталось из «подключить любой сервер»; stdio не запускается
  нигде. Пагинация выдачи (`cursor`/`total`), группировка похожих серверов
  (`variants`) и `Mcp-Session-Id` уже сделаны.

