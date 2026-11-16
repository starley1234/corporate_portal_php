# prl100_platform — инструменты и эксплуатация

## Состояние работ
Перенесён в PRL. Удалены прикладные виджеты/ссылки старых сервисов.
Scaffold создаёт закрытый модуль, проверяет ID из portal.php, не подключает его.
Добавлена CLI-команда обслуживания очереди/outbox после удаления прикладного cron.

## Публичные контракты
- `module:list`, `module:scaffold`, `dev:doctor`, `portal:maintain`.
- `Service_Prl100_Health`, `Service_Prl100_Ratelimit` — общие помощники.
- `/admin`, `/dashboard`, `/prl100/errors`, `/prl100/cleanup`, `/prl100/update`.
- Реестр подключения: application/config/modules.php. Новый ID не переиспользовать.

## Проверки
`make check`, `bash bin/check-fresh.sh`, HTTP smoke (см. ENVIRONMENT.md).
Scaffold тестировать во временном каталоге и удалять созданный тестовый модуль.
