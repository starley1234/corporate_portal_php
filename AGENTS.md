# PRX — правила разработки платформы ИИ-сервисов

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Проект | 14 | 0,3 КБ |
|   Вход в задачу | 19 | 1,6 КБ |
|   Обязательные инварианты | 36 | 5,5 КБ |
|   Словарь кода | 87 | 0,6 КБ |
|   Окружение | 94 | 0,6 КБ |
|   Проверки перед завершением | 102 | 1,1 КБ |
<!-- context-index:end -->
## Проект
PHP 8.4, Konine/Kohana HMVC, PostgreSQL, PHP views + локальный Tailwind/jQuery,
асинхронные Python 3.11+ воркеры. Модульный монолит, не набор микросервисов.
Ядро фреймворка `system/` и `modules/` не менять.

## Вход в задачу
- Реестр подключённых модулей: `application/config/modules.php`.
- Карта сервисов: `application/config/portal.php`, `docs/ROADMAP.md` — 50 из
  отложенного портального плана плюс старый документооборот. `prx390`–`prx393`
  подключаются только на стенде одним блоком в `application/bootstrap.php`. Новый фокус —
  API-пайплайны для корпоративных систем: `docs/AI-DOCUMENT-PIPELINES.md`.
- Архитектура: `docs/ARCHITECTURE.md`, модель и схемы — `docs/architecture/model`
  (`make architecture`, страница `/architecture`); создание: `docs/MODULE_GUIDE.md`.
  Наглядная карта для человека — `/architecture/guide` (философия, запрос, анатомия
  модуля, имена); она ссылается на документы, а не заменяет их.
- Фундамент (actor/policy, `Prx_Db`, `Prx_Event`, CSRF, page-kit):
  `docs/FOUNDATION.md` — читать перед новым модулем.
- Дизайн: `docs/DESIGN-SYSTEM.md`, `.tools/tailwind/input.css`, `app.js`.
- Перед правкой читать `AGENTS.md` целевого модуля; при изменении контракта
  обновлять его. Исторические примеры в памятках не являются зависимостями.
- Контекст-пак: `make brief m=prx120_jobs` (генерируется, не коммитить).

## Обязательные инварианты
1. Единый префикс: папка `prxXXX_slug`, классы `Service_PrxXXX_*`,
   таблицы `prx_<id>_*`, env `PRXXXX_<SLUG>_*`, манифест `config/prx_module.php`.
   Общие Auth/LLM-таблицы и `migrations_pg` сохраняют платформенные имена.
   ID 100–299 и 560/595/597/599 заняты ядром; бизнес-план — 300–424 с резервами.
   URL страницы — `/prxXXX_slug` (префикс + слаг), а не короткий `/slug`;
   внешний машинный контракт — `/api/v1/<domain>/…` (ключи и scope — prx150).
2. Модуль владеет своей схемой, миграциями, бизнес-логикой и представлениями.
   Чужие таблицы/view не читать. Межмодульные контракты: публичный Service,
   Event/EventBus, HMVC-виджет, очередь. Агрегаторы используют Manifest.
3. Манифест — контракт модуля: `id`, `slug`, `title`, `short`, `status`,
   `audience`, `acl`, `href` (или `kind: platform|library`). Проверка:
   `python3 .tools/manifest_check.py`. Манифест объявляет меню, каналы,
   recent/search/cleanup/health/schedule. Не добавлять списки бизнес-модулей
   в ядро. `schedule` — описание, а не планировщик: настройте cron/службу.
4. Deny by default. Контроллеры приложений наследуют `Controller_Base`.
   «Кто действует» — `$this->actor()` (`Prx_Actor`), «можно ли запись» —
   политика модуля (`Prx_Policy`); массивы прав в сервис не передавать.
   Скрытая кнопка не является защитой. Чужие записи — 403/404; гостевой API —
   401 JSON. Worker callback проверяет токен отдельно и не принимает его из UI.
   Внешние потребители API — ключ `prx150` + scope (`Controller_Prx150_Machine`),
   не сессия и не ACL сотрудника; журнал использования — в prx150.
5. Персональные, кадровые и производственные документы не складывать в общий
   технический RAG-кэш `prx560`. Для корпоративного RAG нужны ACL-aware
   коллекции, фильтрация и проверка доступа перед ответом. LLM не определяет права.
6. Изменения состояния — POST + CSRF. Проверяет `Prx_PostGuard` глобально:
   токен `csrf`/`X-CSRF-Token`, машинный канал — заголовок `Authorization`
   или `X-Worker-Token`, вебхуки — `config/post_guard.php`. Ручные проверки
   CSRF в контроллерах не дублировать. Секреты только в `.env`, не в БД/логах/Git.
   Саморегистрация закрыта; пользователей создаёт администратор/интеграция.
7. Миграции идемпотентны; после первого релиза не менять применённые миграции.
   Текущая основа предназначена для новой БД. Не запускать на старой БД без
   согласованного переноса; не добавлять массовый DROP старых таблиц.
   Миграция prx110_004 создаёт admin/admin только на пустой базе; на production
   задайте `PRX_DEFAULT_ADMIN=0` и создайте администратора через `make seed`.
8. LLM/эмбеддинги и тяжёлые операции — очередь `prx120_jobs`; общий код воркера
   `workers/prx_workers/{base,queue,llm,llmwatch,usage,ocr_client}.py` не дублировать.
   Heartbeat, retry, cancel, usage сохраняются. Новый канал регистрируется в
   Python HANDLERS и в манифесте PHP-владельца.
9. Внешние JS/CSS/шрифты в HTML запрещены: только локальные ресурсы
   (`/assets/`). View не использует `$this`; данные передаются переменными. Меню — через
   `set_menu`, чипы — `set_module_nav`. Используйте `prx-*` и `PRX.*` UI API.
   Деструктивные действия — `data-prx-arm`/`data-prx-confirm`, не window.confirm.
10. Добавили Tailwind-классы — `npm run build:css`; собранный app.css коммитится.
    Не конструировать имена классов из частей. Поддерживать dark mode и mobile.
11. Ошибки не глушить: `Log_Plain::event()` пишет на диск сразу. Не логировать
    секреты, приватный контент и идентификаторы анонимных обращений.
12. Изменения коммитить и пушить в текущую рабочую ветку после проверок.
    Не зашивать ветку production/upstream в deploy. Не публиковать локальные
    конфиги, зависимости, дампы и runtime.

## Словарь кода
- `payload` — входящее задание канала; `body` — HTTP JSON; `result` — результат
  канала; `settings` — конфигурация; `file_id`/`message_id`/`run_id`/`draft_id` —
  идентификаторы сущностей. Не смешивать эти слова в именах.
- Публичный фасад модуля сохраняет старые методы; новые швы (`Registry`, `Card`,
  `Runs`) — внутренняя структура, а не второй контракт.

## Окружение
- Канонические имена: `LLM_DEFAULT_PROVIDER`, `DEFAULT_LLM_MODEL`,
  `LOCAL_API_URL/KEY/DEFAULT_MODEL`, `TOOLFORGE_API_URL/KEY`. Устаревшие
  `LM_API_TOKEN`, `TOOLFORGE_DEFAULT_MODEL`, `DEFAULT_VLM_MODEL` в
  `.env.example` не возвращаются (`env-check` считает их ошибкой).
- `.env.example` — человекочитаемый контракт: секции с владельцем и
  обязательностью, без «лаконичности ради строк». Сверка: `make env-check`.

## Проверки перед завершением
```bash
make check                         # статические контракты основы
make env-check                     # .env.example ↔ getenv/os.environ
make architecture                  # YAML -> HTML/JSON, проверка модели
python3 .tools/php_parse_lint.py application
make lint                          # настоящий PHP -l (когда PHP установлен)
bash bin/test                      # PHP unit/smoke, нужна новая БД с миграциями
PYTHONPATH=workers python3 -m pytest workers/tests -q
npm ci && npm run build:css && npm test
bash bin/check-fresh.sh             # HTTP smoke, работающий сайт
```
Результаты и ограничения записать в `docs/VALIDATION.md`, не объявлять
непроверенную функциональность готовой. Адрес сервера preview — 0.0.0.0,
браузерные запросы — относительные; production APP_URL задаётся оператором.
