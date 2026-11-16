# Окружение PRL

## Требования
PHP 8.4 CLI/FPM с pgsql, pdo_pgsql, mbstring, curl, openssl, JSON, session;
PostgreSQL 16+ (Docker: 17 + pgvector), Python 3.11+, Composer 2.
Node нужен только для сборки Tailwind, UI-тестов и `make architecture`;
собранные HTML/JSON коммитятся, на production Node не требуется.

```bash
bash bin/setup.sh                  # .env, секреты, runtime
# Проверьте .env: APP_URL, DB_DEFAULT_*, ADMIN_*.
make up                           # Docker: nginx/php/postgres/redis
make migrate                      # на чистой базе создаёт admin/admin
make seed                         # только если PRL_DEFAULT_ADMIN=0
```

Команды внутри Docker — в README. Bind mount корня скрывает vendor образа:
установите Composer-зависимости в смонтированный проект (через
`docker compose exec php composer install`) перед использованием email.
В закрытой сети setup создаёт только shim autoload: это **не** установленный
Symfony Mailer. Для настоящей почты обязательно `composer install`.

Нативно: `bash bin/restore-env.sh --serve`. В ограниченной сети:
`bash bin/setup-sandbox.sh` (сборка PHP и переносимый PG), затем
`PORT=8080 bash bin/serve`. Подробнее: SANDBOX-SETUP.md.

## Конфиги

`application/env.php` читает .env до bootstrap; окружение процесса важнее файла.
`application/config/modules.php` задаёт подключение и порядок миграций.
`APP_URL` задаёт canonical, ссылки писем и доверенный production-хост.
В development разрешены localhost и preview `*.e2b.app`.
`APP_INTERNAL_HOSTS` — явный CSV внутренних DNS-имён (не wildcard); Docker
задаёт `nginx`, чтобы HTTP-очередь и callbacks воркера шли внутри сети Compose.
Canonical URL остаётся APP_URL; браузер использует публичный адрес.
Не подставляйте адрес production в шаблон .env и не храните ключи в Git.

Публичный docroot — только `public_html/`. Приватное хранилище — `storage/`
вне docroot, runtime — `application/{cache,logs,sessions}`.
Резервировать БД **и** `storage/`/пользовательские файлы; проверять восстановление.
Для воркеров есть отдельный workers/.env. Docker использует root .env.

## Проверки

- `make check` — структурные контракты без PHP/БД.
- `bash bin/check-fresh.sh` — временная БД, миграции дважды, PHP-тесты и CLI
  обслуживания. Требует у dev-роли право CREATE DATABASE; не для production.
- `SMOKE_URL=http://localhost:8080 bash bin/check-fresh.sh` — дополнительно HTTP
  уже работающего сайта с админом из локального .env (не временной БД).
- `source bin/_env.sh; prl_load_env .env; python3 tests/http_smoke.py` — только HTTP.
- `npm ci && npm run build:css && npm test` — локальные assets и общий UI.
- `PYTHONPATH=workers python3 -m pytest workers/tests -q` — worker regression.

Скрипт fresh не запускает и не оставляет фоновых HTTP-серверов.
Настройка maintenance timer описана в WORKERS.md. Сам worker не отправляет
PHP outbox без этого таймера.
