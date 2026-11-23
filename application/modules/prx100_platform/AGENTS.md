# prx100_platform — инструменты и эксплуатация

<!-- context-index:begin -->
<!-- Индекс разделов. Генерируется: python3 .tools/context_pack.py index application/modules/prx100_platform/AGENTS.md --write. Руками не править. -->
| Раздел | Строка | Размер |
|---|---|---|
|   Состояние работ | 11 | 0,4 КБ |
|   Публичные контракты | 16 | 0,9 КБ |
|   Проверки | 26 | 0,2 КБ |
<!-- context-index:end -->
## Состояние работ
Перенесён в PRX. Удалены прикладные виджеты/ссылки старых сервисов.
Scaffold создаёт закрытый модуль, проверяет ID из portal.php, не подключает его.
Добавлена CLI-команда обслуживания очереди/outbox после удаления прикладного cron.

## Публичные контракты
- `module:list`, `module:scaffold`, `dev:doctor`, `portal:maintain`.
  `dev:doctor` проверяет приватный storage и runtime без создания каталогов; права
  и владельца задаёт `bin/setup.sh`. Каталоги архивного docflow не проверяются.
- `Service_Prx100_Health`, `Service_Prx100_Ratelimit` — общие помощники.
- `/admin`, `/dashboard`, `/prx100/errors`, `/prx100/logs`, `/prx100/cleanup`, `/prx100/update`.
- `/prx100/logs` — «Логи платформы» (`Service_Prx100_Logs`): журнал сайта (Log_Plain) и
  php-лог Kohana по дням/уровням, панель «где что пишется». Только чтение.
- Реестр подключения: application/config/modules.php. Новый ID не переиспользовать.

## Проверки
`make check`, `bash bin/check-fresh.sh`, HTTP smoke (см. ENVIRONMENT.md).
Scaffold тестировать во временном каталоге и удалять созданный тестовый модуль.
