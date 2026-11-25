# prx103_platform — инструменты и эксплуатация

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx103_platform/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние работ | 12 | 0,4 КБ |
|   Публичные контракты | 17 | 0,9 КБ |
|   Проверки | 27 | 0,2 КБ |
|   Чтение очереди через владельца (23.11.2026) | 31 | 1,2 КБ |
<!-- context-index:end -->
## Состояние работ
Перенесён в PRX. Удалены прикладные виджеты/ссылки старых сервисов.
Scaffold создаёт закрытый модуль, проверяет ID из portal.php, не подключает его.
Добавлена CLI-команда обслуживания очереди/outbox после удаления прикладного cron.

## Публичные контракты
- `module:list`, `module:scaffold`, `dev:doctor`, `portal:maintain`.
  `dev:doctor` проверяет приватный storage и runtime без создания каталогов; права
  и владельца задаёт `bin/setup.sh`. Каталоги архивного docflow не проверяются.
- `Service_Prx103_Health`, `Service_Prx103_Ratelimit`, `Service_Prx103_Doctor` — общие помощники.
- `/admin`, `/dashboard`, `/prx100/errors`, `/prx100/logs`, `/prx100/cleanup`, `/prx100/update`, `/prx100/selfcheck`.
- `/prx100/logs` — «Логи платформы» (`Service_Prx103_Logs`): журнал сайта (Log_Plain) и
  php-лог Kohana по дням/уровням, панель «где что пишется». Только чтение.
- `/prx100/selfcheck` — «Проверка сервера»: `Service_Prx103_Doctor::run(TRUE)` кнопкой
  из браузера (то же, что `dev:doctor`, плюс php -l по всему коду через один
  дочерний процесс; при запрещённом proc_open — WARN с подсказкой make lint).
  POST + CSRF, право `prx100.admin`, ничего не меняет. Только чтение.
- Реестр подключения: application/config/modules.php. Новый ID не переиспользовать.

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
