# prx100_platform — инструменты и эксплуатация

## Состояние работ
Перенесён в PRX. Удалены прикладные виджеты/ссылки старых сервисов.
Scaffold создаёт закрытый модуль, проверяет ID из portal.php, не подключает его.
Добавлена CLI-команда обслуживания очереди/outbox после удаления прикладного cron.

## Публичные контракты
- `module:list`, `module:scaffold`, `dev:doctor`, `portal:maintain`.
  `dev:doctor` проверяет приватный storage и runtime без создания каталогов; права
  и владельца задаёт `bin/setup.sh`.
- `Service_Prx100_Health`, `Service_Prx100_Ratelimit` — общие помощники.
- `/admin`, `/dashboard`, `/prx100/errors`, `/prx100/cleanup`, `/prx100/update`.
- Реестр подключения: application/config/modules.php. Новый ID не переиспользовать.

## Проверки
`make check`, `bash bin/check-fresh.sh`, HTTP smoke (см. ENVIRONMENT.md).
Scaffold тестировать во временном каталоге и удалять созданный тестовый модуль.
