# nj670_processhub — памятка

Пользовательское описание, сценарий демо и таблица чисел — `docs/nj670_processhub.md`.

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/nj670_processhub/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние | 23 | 1,0 КБ |
|   Карта | 35 | 5,4 КБ |
|   Демо и проверки | 95 | 2,7 КБ |
|   API и MCP: как агент заполняет модуль | 128 | 2,2 КБ |
|   Холст: панорамирование и перетаскивание | 155 | 1,7 КБ |
|   Воркер: пульс и «онлайн» | 175 | 2,7 КБ |
|   Юнит-проверки без PHPUnit | 205 | 0,8 КБ |
|   Смоук вьюх без сервера | 220 | 1,6 КБ |
|   Миниатюры схем (серверный SVG) | 248 | 1,1 КБ |
|   Название процесса | 263 | 0,6 КБ |
|   Визуальный стиль | 271 | 2,9 КБ |
|   Инварианты | 305 | 4,6 КБ |
|   Импорт, иерархия, сравнение и паспорт | 352 | 5,6 КБ |
<!-- context-index:end -->
## Состояние

Новый самостоятельный сервис `/process` «ProcessHub»: онтологическая модель
бизнес-процессов, BPMN-схема «План (To-Be)» из текста/PDF/скана/аудио,
«Факт (As-Is)» по журналу событий, AI-аудит разрывов «план ↔ факт» и регламент
DOCX/PDF по ГОСТ Р 7.0.97-2016 с матрицей RACI. Всё работает end-to-end в
песочнице: демо `procdemo23` (план 3 раб. дн. / факт 14 раб. дн., потери
18 400 ₽, СБ 54 % срока, юрист обойдён в 35 % кейсов, Telegram 40/40),
DOCX и PDF собираются на сайте даже без воркера и без `ext/zip`; XLSX-журнал
читается без `ext/zip`. Реальная LLM/OCR-модель в песочнице не вызывалась
(нет провайдера): проверены контракты `/process/w/*` и правила-фолбэки.

## Карта

* `init.php` — маршруты `/process`, `/process/create|ontology|map|admin|demo`,
  `/process/<code>/{editor,fact,audit,report,approve,bpmn}`,
  `/process/r/<id>/<kind>`, `/process/api/<action>` (аккаунт + CSRF на мутациях
  либо персональный `Authorization: Bearer n2jmcp_…`),
  `/process/w/<context|graph|audit|report|fail|ontology|ontology_fail>`
  (строгий `WORKER_API_TOKEN`).
* `Controller_Nj670_Process` — страницы и POST; `Controller_Nj670_Api` — JSON
  холста и агента (`status/save/synth/audit/report/delete/ontology` +
  `create/graph/dictionary/ontology_save`); `Controller_Nj670_Worker
  extends Controller_Nj120_Api` — приёмная воркера.
* `Service_Nj670_Access` — роли/права по `user_id` без сессии (нужны Bearer-ключу
  API и MCP): `can_start()` = `ACL::check(roles, 'nj670.start')`.
* `Service_Nj670_Mcp` — обработчики MCP-инструментов; декларации —
  `config/mcp.php` (категория `processhub`).
* `Service_Nj670_Repo` — соединение с БД, конфиг, лимиты, словари, `minutes_text`
  (480-минутный рабочий день), `money`, `slug_code` (транслит), `_event/_notify`.
* `Service_Nj670_Ontology` — `nj_670_entities`: роли, системы, артефакты,
  события, правила, подразделения; `prompt_dictionary` отдаёт воркеру только
  коды/имена (+ `rate_per_hour` у ролей, чтобы правиловый аудит считал потери).
  `attributes_from` сводит плоские поля формы и вложенный `attributes`
  (вложенный главнее); `clean_attributes` — белый список ключей.
* `Service_Nj670_Ontologyfeed` — очередь разбора `nj_670_ontology_jobs`:
  `queue → context → store_result → accept`. `normalize_result` разрешает
  классы по алиасам, дедуплицирует `тип|код`, помечает `exists` по индексу;
  `accept` создаёт выбранные сущности и черновики процессов (линейный граф
  «начало → шаги → конец», `meta.source='ontology'`).
* `Service_Nj670_Map` — агрегаты карты: использование ролей/систем/артефактов
  по `nj_670_nodes`, матрица «процесс × система», пробелы и подсказки.
* `views/670/script-canvas.php` — холст: панорама/зум, drag, связи, Copilot,
  полноэкранный режим (`#nj670-full-btn`, `F`, `?full=1`) и живая проверка
  BPMN (`validate()` → `#nj670-issues`), она же `window.NJ670.validate` для тестов.
* `Service_Nj670_Graph` — `normalize` (типы, лимиты, дорожки, дедуп),
  `bpmn_xml` (BPMN 2.0: laneSet, задачи, шлюзы, sequenceFlow; без DI),
  `layout` (собственный Sugiyama-подобный слой: дорожка = роль), `metrics`
  (календарный `meta.elapsed_minutes` важнее суммы работ), `svg`, `raci`,
  `bottlenecks`, `severity_of_wait`. AntV X6/dagre не используем: CDN запрещены,
  а в `public_html/assets/libs` их нет — холст на своём SVG.
* `Service_Nj670_Synthesis` — `from_model`, `from_text` (правиловый разбор:
  роли/системы/артефакты по справочнику, сроки в минутах, «ожидание» в wait),
  `merge_copilot` (сохраняет ручные `x/y`, новым узлам ставит координаты).
* `Service_Nj670_Mining` — CSV/XLSX (`Case_ID/Activity/Timestamp/Resource`),
  XLSX читается и без `ext/zip`: локальные заголовки + `gzinflate`, inlineStr;
  частотная матрица переходов; коды активностей латиницей (транслит), паузы и
  срок — в минутах; теневая система помечается по словарю журнала.
* `Service_Nj670_Diff` — `analyze` и `run_fast` (правиловый аудит без очереди);
  находки `{severity,category,title,description,evidence,recommendation,impact_rub,node_id,metric}`.
* `Service_Nj670_Report` — `document()` (шаги, RACI, правила, аудит),
  `build_docx` (собственный ZIP-писатель `_zip` на zlib, если `ZipArchive` нет),
  `build_pdf` (через `Service_Nj634_Txt2pdf`, молча без шрифтов),
  `store_files/file` → `storage/nj670/reports/ГГГГММ/<id>-<hash>.{docx,pdf}`.
* `Service_Nj670_Process` — владение/пространства, версии (`plan|fact`),
  `create_version/activate_version/point_to/save_graph`, очереди `nj670.synthesize|mine|diff|export`,
  квоты, импорт, `store_source` (PDF/фото/аудио в `storage/nj670/sources`),
  `worker_online` (живой heartbeat в `nj_120_jobs` за 20 мин),
  `resolve_user_id` (id | email/username | первый пользователь).
* `Service_Nj670_Demo` — сценарий `procurement`, 5 шагов плана и 9 шагов факта,
  аудит с 6 находками, id процесса `procdemo23`, если код свободен.

## Демо и проверки

```bash
bin/console processhub:seeddemo --scenario=procurement --fresh=1
bin/console processhub:seeddemo --user=admin@n2j.ru
```

* Minion сам приводит класс к команде: `Task_Processhub_Seeddemo` →
  `processhub:seeddemo`. Дефис в имени тоже работает: `public_html/index.php`
  снимает его из первого аргумента (`processhub:seed-demo` → `processhub:seeddemo`),
  поэтому в документации допустим привычный вид команды. Файл обязан называться `Seeddemo.php` с маленькой `d`,
  иначе `Task_Processhub_Seeddemo` не загрузится (`convert_task_to_class_name`
  делает `ucfirst` от каждого сегмента).
* Страницы доступны владельцу процесса; `nj670.start` (vip/admin) — синтез,
  факт, аудит, отчёт; `nj670.admin` (admin) — `/process/admin`.
* `php tests/mini_runner.php Nj670ProcessHub` — 39 чистых проверок без БД:
  правила синтеза, copilot-раскладка, метрики, RACI, майнинг русского CSV,
  DOCX (включая распаковку собственного архива, титул «УТВЕРЖДАЮ» и маршрут
  по дорожкам), `minutes_text`.
* `python -m pytest tests/test_nj670_processhub.py` (из `workers/`) — 13 тестов
  воркерских каналов без сети: `_text_graph`, `_mine_graph`, `_rules_diff`,
  `_document_json`, `_diagram_png`. Модуль импортируется в обход
  `handlers/__init__.py`, чтобы не тянуть зависимости всех каналов.
* `npm run test:nj670:canvas` — холст в jsdom: панорамирование не зависит от
  перерисовки кадров, DOM во время панорамирования не пересобирается, узел
  едет на точную величину, отмена жеста не «залипает», стрелки выбирают
  грани по положению цели, обходят третий блок, а обычное колесо (без Ctrl)
  масштабирует и Shift+колесо сдвигает.
* `python -m pytest tests/test_nj670_presence.py` (из `workers/`) — пульс:
  адрес приёмной (включая вывод из `NJ120_API_URL`), порядок токенов,
  отключение, 401/403 и остановка цикла.
* `bash .tools/php_check_all.sh application` — синтаксис всего приложения.

## API и MCP: как агент заполняет модуль

* **Один ключ на всё.** Персональный токен хаба (`n2jmcp_…`, таблица
  `nj_160_tokens`) принимают и `POST /mcp/api/rpc`, и JSON API
  `/process/api/*` в заголовке `Authorization: Bearer n2jmcp_…`. Сессионному
  браузеру по-прежнему нужен CSRF; ключ — нет (он не уезжает в cookie).
  Владелец ключа получает свои права: `nj670.start` проверяется через
  `Service_Nj670_Access`, а не «ключ = админ».
* **MCP.** Хаб передаёт `user_id` владельца в `Service_Nj160_Registry::call()`
  четвёртым аргументом; реестр кладёт его в `args['_user_id']` и **всегда
  перезаписывает** — подделать аргументом нельзя. Legacy-ключ (`MCP_TOKEN`)
  пользователя не имеет, поэтому обработчик падает на `owner` или админа.
  Инструменты: `nj670_processes`, `nj670_process`, `nj670_create`,
  `nj670_save_graph`, `nj670_ontology`, `nj670_ontology_upsert`,
  `nj670_status`, `nj670_synthesize`.
* **API.** `POST /process/api/create` `{title?, text?, department?}` → процесс
  и схема (воркер или правила); `POST /process/api/graph`
  `{code, kind, nodes, edges, version_id?}` → новая версия или правка
  существующей; `POST /process/api/ontology_save` `{kind, name, code}` → upsert;
  `POST /process/api/dictionary` → справочник с кодами. Чтение — `status`,
  `dictionary`; ключ можно передавать и в GET.
* **Не дублировать логику.** «Создать + собрать» живёт в
  `Process::create_with_graph()`; upsert справочника — в `Ontology::upsert()`.
  Страница, API и MCP вызывают одно и то же.
* Ответы MCP — обычный JSON; доменная ошибка `{"error": "…"}` хаб превращает
  в `isError`, поэтому агент читает причину и повторяет вызов.

## Холст: панорамирование и перетаскивание

* Жест считается **от точки захвата в экранных пикселях** (`clientX/clientY`),
  а не от координат в системе viewBox. Масштаб (`screenScale()` из
  `getScreenCTM`) замеряется один раз на `pointerdown`: за время жеста
  `view.w/view.h` не меняются.
* `render()` не двигает `view`. Раньше ограничение окна (`clampView`) жило
  внутри кадра, кадр правил координаты, а следующий `pointermove` считал
  смещение от уже сдвинутого viewBox — схема «догоняла» курсор и дрожала при
  перетаскивании влево-вправо. Теперь `clampView()` вызывается только после
  явных изменений (зум, «Показать всю схему», конец панорамирования).
* Мёртвая зона 3 экранных px: микродрожание руки не двигает ни холст, ни узел
  и не создаёт запись undo.
* `pointercancel` завершает жест так же, как `pointerup`: Alt+Tab не оставляет
  холст «зажатым».
* Регрессия — `npm run test:nj670:canvas` (jsdom, 8 проверок, включая
  монотонность сдвига по кадрам; выбор граней, обход блоков и колесо).
  Тест падает на старой ревизии вьюхи:
  `NJ670_CANVAS=/tmp/old.php node --test tests/Js/nj670_canvas_pan.test.mjs`.

## Воркер: пульс и «онлайн»

* Признак жизни — **пульс воркера** `POST /process/w/ping` (воркер шлёт его
  раз в минуту из `workers/n2j_workers/presence.py`, даже без задач). Пульс
  пишется в `nj_670_events` (`kind = worker_ping`). `worker_status()` считает
  воркер живым, если пульс свежее `presence_minutes` (5), иначе смотрит
  heartbeat задач (60 мин, для воркеров без ping).
* Нельзя возвращать проверку «есть ли взятая задача» как единственный
  признак: у простаивающего воркера задач нет, и создание процесса уходило
  в правила с сообщением «воркер не в сети» при живом воркере.
* `NJ670_WORKER_PRESENCE_MINUTES` и `NJ670_WORKER_FORCE_ONLINE` — аварийные
  ручки сайта; `NJ670_PING` / `NJ670_PING_INTERVAL` — воркера.
* Диагностика — `bin/console processhub:doctor` (`db_tables`, `queue_snapshot`,
  `worker_status`) и блок на `/process/admin`; тот же статус в JSON
  `/process/api/status` (`worker`). Живой этап задачи — `jobs[].stage`/`elapsed_sec`;
  страницы показывают его точками и текстом, без полосы прогресса.
* Полоса работы — общий partial `views/670/_job.php` (`$job`, `$code`):
  этап, секундомер, `n2j-dots`, опрос `api/status` и перезагрузка после
  завершения. Он стоит на `fact`, `audit`, `report`, `versions`, `passport`.
  Новую страницу с фоновой задачей подключать так же, а не копировать
  разметку: `View::factory('670/_job')->set('job', $job)->set('code', $code)`.
  Данные даёт `Service_Nj670_Process::job_state($id)` или
  `first_running_job($rows)` (первая незавершённая задача из списка строк).
* Индикатор опрашивает `api/status` с `jobs_only: true`: ответ содержит
  только `jobs`, `worker` и `audit`. Полный статус отдаёт граф, метрики и
  словарь — на странице регламента или версий это лишние запросы каждые
  5 секунд.
* **Полосы прогресса запрещены** — уровень показывается числом, точками и
  текстом этапа. Тест `tests/Js/nj670_content_scale.test.mjs` это ловит.

## Юнит-проверки без PHPUnit

`tests/Unit/Nj670ProcessHubTest.php` — обычный PHPUnit-тест (39 проверок:
синтез, майнинг, BPMN, DOCX, метрики, фильтр реестра, MCP/API). В песочнице
Arena PHP нет, поэтому тот же файл гоняется мини-шимом:

```bash
cd /tmp/phpwasm && node phpwasm_stand.mjs .tools/nj670_unit_stand.php
# 39 прошло, 0 упало
```

Шим даёт `PHPUnit\Framework\TestCase`, заглушки `Arr`/`UTF8`/`HTML` и
`Kohana::$config` (читает `config/nj670_processhub.php`). Тесты обязаны
оставаться чистыми: без БД и сети, иначе стенд упадёт — и это правильно.

## Смоук вьюх без сервера

`.tools/nj670_views_stand.php` собирает вьюхи `index`, `create`, `editor`,
`fact`, `audit`, `versions`, `passport`, `report` с фикстурами и настоящими
сервисами модуля (без SQL) в PHP-WASM. Ловит опечатки в переменных и вызовы несуществующих
методов, которые синтаксический линтер не видит.

```bash
cd /tmp/phpwasm && node phpwasm_stand.mjs .tools/nj670_views_stand.php
```

Стенд также проверяет, что быстрые фильтры реестра ведут на `?state=…`, а
разрыв план/факт показан числом («факт ×3,0»), а не полосой.

Чтобы посмотреть вёрстку глазами без сайта, включите HTML-галерею: обёртка
монтирует каталог хоста в PHP, а стенд кладёт туда страницы с фикстурами.

```bash
rm -rf /tmp/nj670_preview
N2J_PREVIEW_DIR=/preview N2J_MOUNT=/tmp/nj670_preview:/preview \
  node phpwasm_stand.mjs .tools/nj670_views_stand.php
# открыть /tmp/nj670_preview/index.html через статический сервер:
# стили и шрифты лежат в public_html (ссылки /assets/...)
```

Галерея — инструмент проверки, а не часть продукта: данные фикстурные,
права и живые запросы не участвуют.

## Миниатюры схем (серверный SVG)

* `Service_Nj670_Graph::svg()` рисует превью для страниц аудита, факта и
  регламента. Маршрутизация обязана совпадать с холстом: порт выбирается по
  положению цели (`_svg_edge`), самопетля — дуга над шагом, петля назад —
  обход по нижней грани, третий блок в коридоре — обход сбоку
  (`_svg_blocked` проверяет саму линию по 12 замерам, а не широкий
  прямоугольник).
* Подпись условия — текст с обводкой (`paint-order="stroke"`), а не плашка:
  плашка 144 px закрывала соседние шаги на миниатюре.
* Контракт закреплён стендом `.tools/nj670_graph_stand.php`
  (`cd /tmp/phpwasm && node phpwasm_stand.mjs .tools/nj670_graph_stand.php`):
  шесть проверок формы пути. Без PHP в песочнице используйте
  `.tools/phpwasm_stand.mjs`.

## Название процесса

* Единственное обязательное поле — «Текст регламента» (или файл). Пустое
  название заполняется `Repo::title_from_text()`: первая осмысленная строка
  без нумерации, ≤ 90 знаков по границе слова; для файла — имя без
  расширения; запасное — «Процесс от ДД.ММ.ГГГГ». Нельзя снова делать
  `title` обязательным в форме или возвращать «Укажите название процесса».

## Визуальный стиль

* «Золотая середина»: деловой, но не монохром. Один акцент — `indigo`
  (`indigo-700` на первичных кнопках, `indigo-600` на ссылках и иконках
  заголовков): он ведёт взгляд и задаёт иерархию. Второго акцента нет:
  `sky`, `violet`, `blue`, `orange`, `green`, `red` не используются
  (=`grep -rnoE -- '-(sky|violet|blue|orange|green|red)-[0-9]{2,3}'`).
* Цвет — носитель смысла, а не украшение. Тонированные квадраты KPI:
  процессы — `indigo`, аудиты — `teal`, регламенты — `emerald`,
  потери — `rose`, ожидание — `amber`. Статусы: `active` — `emerald`,
  `in_review` — `amber`, `error` — `rose`, `draft/archived` — `slate`.
  Владелец — `indigo`-чип, «без владельца» — `amber`.
* Подписи план/факт: «План» — `indigo` (режим `indigo-700`), «Факт» —
  `teal` (режим `teal-700`). Это одна и та же пара цветов во всех вьюхах,
  регламенте и на холсте; факт больше не `slate-800`.
* Служебное (коды, роли, системы, справки, «ДЕМО») — серое. Но заголовки
  групп онтологии получают смысловую иконку: роли — `indigo`, системы —
  `teal`, артефакты — `amber`.
* Холст (`views/670/editor.php` CSS + `views/670/script-canvas.php`):
  заливки типов узлов приглушённые, но различимые — старт `#eef4f0/#5f8a72`,
  конец `#f8eeee/#a06a6a`, авто `#eef1f6/#7186a3`, ручной `#f8f3e9/#9c8660`,
  шлюзы `#f7f3e2/#99803c`, таймер `#edf3f5/#6b8b96`; выделение `#3730a3`,
  токен `#3730a3`, кольцо `#a5b4fc`. Связи: обычная `#94a3b8`, условие
  `#99803c` (6 4), петля `#a06a6a` (4 4), горячая `#a94b5e`. Ожидание —
  `#f8f3e9/#d8c8a6/#7a5a24`, подпроцесс `#7186a3`, тень IT `#99803c`.
* Метки аудита: риск `#be123c !`, контроль `#047857 ✓`, вопрос `#b45309 ?`,
  идея `#64748b i`; легенда в `editor.php` обязана совпадать по цвету.
* Серверные миниатюры `Service_Nj670_Graph::svg()` повторяют ту же палитру
  (факт: норма `#eef4f0/#5f8a72`, ожидание `#9a7b3f`, высокое `#a8703f`,
  критично `#a85252`; шлюзы — `#f7f3e2/#99803c`). Тесты, проверяющие hex,
  — `tests/Unit/Nj670ProcessHubTest.php` и
  `tests/Js/nj670_canvas_overlay.test.mjs`; при смене палитры обновлять их
  вместе с кодом.

## Инварианты

* Только `nj_670_*`, не короткие имена: `nj_670_nodes/edges` — индекс для
  запросов, источник истины — `versions.graph_topology` (JSONB).
* Воркер не ходит в PostgreSQL: контекст и результат — через `/process/w/*`.
  В `context` файл приходит только если сайт нашёл `stored_path`/`import_id`.
* Версии: `queued|draft|in_review|active|archived|error`; факт после приёмки
  становится `active` и активируется; план — `in_review`. Указатель
  `processes.plan_version_id` при этом уже смотрит на новую версию (её видно на
  холсте), а статус меняет только человек: POST `/process/<code>/approve`
  (`nj670.start`, CSRF) → `activate_version` → `active`, прежняя активная уходит
  в `archived`. Без этого шага собранная моделью схема навсегда оставалась
  «на рассмотрении». Версия из очереди (`worker_graph`) тоже переводит указатель
  на себя через `point_to`, но статус остаётся `in_review` до approve.
* `metrics.elapsed_minutes` из `meta` — календарный срок: у факта ветки идут
  параллельно, поэтому сумма работ/ожиданий не равна сроку. Доля узкого места
  считается от календарного срока (СБ 3600 / 6720 = 53,6 % ≈ 54 %). При
  сохранении графа срок пишется и в `meta`, и в колонку версии.
* В SVG факта толщина ребра — объём (`weight`), цвет — `avg_minutes` перехода:
  ≥ 480 мин рубиновый, ≥ 120 янтарный, > 0 голубой. Демо-журнал заполняет
  `avg_minutes` (СБ 3600, юрист 480), поэтому горячие переходы видны сразу.
* Все коды узлов/шагов — латиница: `Graph::_id_of` вырезает кириллицу, поэтому
  и PHP-майнинг, и python-воркер транслитерируют активности.
* Параметр маршрута `<action>` в Kohana подменяет вызываемый метод. Поэтому у
  `Controller_Nj670_Api` и `Controller_Nj670_Worker` объявлены настоящие
  `action_status/save/synth/audit/report/delete` и
  `action_context/graph/audit/report/fail`; разбор `param('action')` внутри
  одного `action_index` давал 404 на `/process/api/save` и `/process/w/context`.
  `action_index` — вежливый JSON-404, а не точка входа.
* `Arr::get` в этом проекте не понимает точечные пути — только `Arr::path`
  (иначе «54 %» превращаются в 0 и схема выглядит пустой).
* В PHP `AND` слабее `?:`: условие перед тернарником всегда в скобках
  `($a !== '' AND isset($b[$a])) ? ... : ...`, иначе в строку попадёт «1» (TRUE).
* Очередь принимает задачу и без слушателя, поэтому кнопки синтеза, LLM-аудита,
  copilot и экспорта сначала спрашивают `worker_online()`. Без воркера:
  текст собирается правилами, аудит идёт `Diff::run_fast`, DOCX/PDF собираются
  на сайте, а PDF/скан/аудио во входе честно отклоняются (разбирать некому).
* DOCX не должен зависеть от `ext/zip`: fallback `_zip` пишет ZIP вручную.
  PDF зависит от DejaVu-шрифтов на хосте; без них отчёт честно остаётся DOCX.
* Рабочий день — 480 минут. `minutes_text(3600)` = «7 раб. дн. 4 ч».
* Демо можно запускать повторно: `--fresh=1` удаляет прошлый демо-процесс;
  без флага возвращается существующий (`exists=true`).
* Экономика: ставки ролей в `attributes.rate_per_hour`, средняя —
  `economy.default_rate_per_hour`, простой — `wait_cost_share`.
* Приватные файлы — `storage/nj670`; при заданном `DEPLOY_MODULE_STORAGE`
  добавить в список (`bin/deploy.sh`, `docs/DEPLOY-UBUNTU.md`).

## Импорт, иерархия, сравнение и паспорт

* **Импорт BPMN 2.0** — `Service_Nj670_Bpmn::xml_to_graph($xml, $index)`:
  XMLReader, стек открытых `<lane>` для `flowNodeRef` (ссылка принадлежит
  ближайшей дорожке, а не последней увиденной), координаты из `bpmndi` через
  ограниченный regex, `callActivity@calledElement → properties.process_code`.
  `Bpmn::import($uid, $file)` принимает `.bpmn/.xml` 1 Б–5 МБ, создаёт процесс
  и план-версию `in_review` (`source_kind = bpmn-import`), событие
  `bpmn.imported`; форма создания редиректит на холст с `?ok=imported&warn=…`.
* **Аннотации** — `textAnnotation/bpmn:text` + `association` превращаются в
  `properties.comment` шага (склейка через пустую строку, лимит 500 символов;
  связь на неизвестный элемент даёт warning, а не падение). Экспорт
  `Graph::bpmn_xml()` пишет `Note_<node>`/`Assoc_<node>` обратно; есть тест
  round-trip.
* **PlantUML** — `Graph::plantuml($process, $graph, ['mode' => 'plan|fact'])`:
  `@startuml`, дорожки `|Название|`, шаги `:Текст\n<size:10>SLA</size>;`,
  шлюзы `if (…) then (да) / else (нет) / endif`, заметка об осиротевших шагах,
  ограничение глубины 24. Маршрут `/process/<code>/plantuml`.
* **Иерархия и вызовы** — `nj_670_processes.parent_id` (то же рабочее
  пространство), `Process::tree/children/calls`, `parent_code` в
  `create_process`. Вызов — `properties.process_code` у `subprocess`;
  `Graph::plantuml` рисует его как `<<вызов>>`, BPMN-экспорт — `callActivity`.
  Двойной клик по подпроцессу на холсте открывает связанную схему.
* **Сравнение версий** — `Compare::compare($before, $after)` не зависит от БД,
  ключ — `step_id`. Возвращает `summary` (`nodes_*`, `added`, `removed`,
  `changed`, `edges_*`, `minutes_*`, `wait_*`, `total_*`, `is_empty`) и списки
  шагов. Важно: `['fields' => []]` в PHP truthy, поэтому `_node_diff()`
  возвращает `[]`, когда полей нет, иначе каждый общий шаг попадает в
  «изменённые». Страница — `/process/<code>/versions`.
* **Оверлеи и комментарии** — `properties.overlay` (`risk|control|question|
  idea`) и `properties.comment` (≤500 символов). Белый список
  `_clean_props()` обязателен, иначе `normalize()` молча выбросит поля;
  неизвестный оверлей отбрасывается. Холст рисует цветную метку и «облачко»,
  инспектор — поля, регламент — раздел «Комментарии ревьюера»
  (`Report::comments_rows()`).
* **Паспорт** — `/process/<code>/passport`: `Process::architecture()` считает
  частоту ролей/систем/артефактов по план- и факт-схемам, `Process::coverage()`
  — долю шагов с ролью/системой, теневые места и оверлеи. Route объявлен
  **до** `process/<code>`, иначе Kohana отдаст редактор. Отдельного кэша нет —
  всё из текущих версий.
* **Фильтр реестра** — `Process::filter()` (текст по названию/коду/описанию/
  подразделению/владельцу, подразделение, `owner_role_id`, состояния
  `no_fact|no_owner|audited`) и `Process::facets()` для селектов. Пустой
  фильтр ничего не теряет; при нуле результатов страница предлагает сброс.
* **Токен-анимация** — кнопка `#nj670-play-btn` в тулбаре: `tokenPath()`
  выбирает старт (или первый узел без входящих) и идёт по первой исходящей
  связи без повторных узлов; `stepToken()` двигает точку со скоростью 260
  единиц схемы в секунду через rAF. Слой `#nj670-token-layer` создаётся
  отдельно и восстанавливается после `render()` (тот перезаписывает
  `svg.innerHTML`). Панорама/зум не влияют: токен в координатах графа.
  Через 2,6 с после финиша точка убирается.
* **«Что если»** — `Service_Nj670_Simulate::run($graph, $index, $input)`:
  детерминированная ёмкость по ролям (`volume`, `workdays`, `workday_minutes`,
  `people[role_code]`), очередь M/M/1 `service × ρ/(1−ρ)`, required people
  для 80 %, throughput. Не путать с DES: ветки не моделируются, шаги без роли
  только предупреждают. Страница `/process/<code>/simulate` (route до
  `process/<code>`), форма — GET `people[buyer]=2`.
* Проверки этого слоя: `npm run test:nj670:canvas` (18 тестов, включая
  оверлеи и токен), unit — `Nj670ProcessHub` (35 тестов).
