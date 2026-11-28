# prx103_platform — инструменты и эксплуатация

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx103_platform/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние работ | 12 | 0,7 КБ |
|   Публичные контракты | 20 | 3,6 КБ |
|   Проверки | 55 | 0,2 КБ |
|   Чтение очереди через владельца (23.11.2026) | 59 | 1,2 КБ |
<!-- context-index:end -->
## Состояние работ
Перенесён в PRX. Удалены прикладные виджеты/ссылки старых сервисов.
Scaffold создаёт закрытый модуль, проверяет ID из portal.php, не подключает его.
27.11.2026: `portal:maintain` исполняет секции `schedule` всех манифестов
(раньше — зашитый список prx120/prx597, уборки модулей не запускал никто).
27.11.2026: журнал администратора — `Audit::log()` (был заглушкой в лог) пишет в
`prx_103_audit_log` через `Service_Prx103_Audit`; страница `/prx100/audit`.

## Публичные контракты
- `module:list`, `module:scaffold`, `dev:doctor`, `portal:maintain`
  (= `schedule:run`), `schedule:list`. Исполнитель расписаний манифестов —
  `Service_Prx103_Schedule`: только `command: bin/console <задача>` (без shell,
  задача должна существовать), процесс на запись с таймаутом, advisory lock на
  ключ, состояние в `prx_103_schedule_runs`; `dev:doctor` → «Расписания».
  `dev:doctor` проверяет приватный storage и runtime без создания каталогов; права
  и владельца задаёт `bin/setup.sh`. Каталоги архивного docflow не проверяются.
- `Service_Prx103_Health`, `Service_Prx103_Ratelimit`, `Service_Prx103_Doctor` — общие помощники.
- `/admin`, `/dashboard`, `/prx100/errors`, `/prx100/logs`, `/prx100/cleanup`, `/prx100/update`, `/prx100/selfcheck`.
- `/prx100/logs` — «Логи платформы» (`Service_Prx103_Logs`): журнал сайта (Log_Plain) и
  php-лог Kohana по дням/уровням, панель «где что пишется». Только чтение.
- `/prx100/selfcheck` — «Проверка сервера»: `Service_Prx103_Doctor` кнопкой
  из браузера. Быстрые проверки (среда/БД/миграции/очередь/каталоги/доки) —
  сразу POST-ом; компиляция php-кода — ПОРЦИЯМИ из браузера (`action_lint`,
  offset/count; список файлов пересчитывается в каждом запросе —
  детерминированный sorted-скан, клиент путей не присылает). 750+ файлов
  одним запросом не влезают в таймауты веб-хостинга (прод 25.11.2026:
  Broken pipe — PHP_BINARY под fpm/cgi не CLI, зонд `_cli_binary()`;
  затем 502 — лимит одного запроса; затем HTTP-500 — Controller_Prx102_Base
  НЕ наследует Controller_Base и не имеет `_json_response`, JSON отвечается
  напрямую через `$this->response`). CLI-версия (`dev:doctor`) гонит весь
  список одним вызовом `lint_chunk()`. Нет CLI или запрещён proc_open —
  WARN с подсказкой make lint, страница не падает.
  POST + CSRF, право `prx100.admin`, ничего не меняет. Только чтение.
- Реестр подключения: application/config/modules.php. Новый ID не переиспользовать.
- Журнал администратора: `Audit::log($category, $action, $object_id, $data)` — кто
  (сессия; CLI — `cli`), что, над чем. Только добавление (UPDATE — триггер-отказ),
  срок 365 дней (`cleanup`). Ключи data с pass/token/secret/hash/authorization → `***`
  (bool сохраняется: `password_changed: true`). Таблицы нет / сбой — строка в
  системный лог, действие не падает. Пишут: prx150 (ключи), prx595 (пользователи,
  роли), prx120 (лимиты, повторы, монитор), prx103 (чистки, действия /admin), prx110
  (публикация), prx218 (реестр), prx212 (`security/file_infected`). Автоматику
  (LLM-анализ prx218) сюда не писать — только действия людей и события безопасности.

## Проверки
`make check`, `bash bin/check-fresh.sh`, HTTP smoke (см. ENVIRONMENT.md).
Scaffold тестировать во временном каталоге и удалять созданный тестовый модуль.

## Чтение очереди через владельца (23.11.2026)

Прямой SQL этого модуля к `prx_120_*` удалён. Публичные методы
`Service_Prx120_JobQueue` используют настроенное соединение очереди и только
читают данные; диагностический fallback остаётся у экрана. Исключения этого
модуля удалены из `test_queue_table_debt.py`, возвращать SQL-зависимость нельзя.
Контракты/пороги/ограничения — `docs/WORKERS.md`, PostgreSQL-регрессии —
`QueueReportsIntegrationTest`. Наличие административного метода не заменяет ACL
страницы; общий пользовательский read API здесь не добавлен.
Health использует platform_metrics/worker_activity/usage_today; Errors —
platform_metrics; Dev Doctor — существующий stats. Пороги Health/Errors пока
различаются по NULL-пульсу, как до переноса; это не parity с обслуживанием.
