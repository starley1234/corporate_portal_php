# Окружение и разворачивание

## 1. Быстрый старт

### Вариант А — Docker (рекомендуется)

```bash
cp .env.example .env          # при необходимости поправить порты/пароли
docker compose up -d          # postgres 17 + redis + php 8.4-fpm + nginx
docker compose --profile tools up -d     # + adminer (:8081), mailpit (:8025)
docker compose exec php bin/console db:migrate
docker compose exec php bin/console auth:user --email=admin@n2j.ru --password=admin123 --username=admin --admin
# сайт: http://localhost:8080
```

### Вариант Б — нативно (Debian/Ubuntu)

```bash
bash bin/restore-env.sh --serve
# поставит PHP 8.4 (Sury) и PostgreSQL (apt или переносимый pgserver),
# создаст .env с секретами, накатит миграции, создаст админа, поднимет :8080
```

Повторный запуск безопасен (идемпотентно). Только отдельные части:

```bash
bash bin/restore-env.sh --pg-only     # только БД
bash bin/restore-env.sh --php-only    # только PHP
bash bin/restore-env.sh --app-only    # .env + каталоги + миграции + админ
```

## 2. Состав стека

| Сервис | Порт (по умолчанию) | Назначение |
|---|---|---|
| nginx | 8080 | входная точка, docroot `public_html` |
| php-fpm 8.4 | внутренний 9000 | приложение (образ собирается из `docker/php/Dockerfile`) |
| postgres 17 + pgvector | 127.0.0.1:5432 | БД; расширения vector/pg_trgm/unaccent/uuid-ossp |
| redis 7 | 127.0.0.1:6379 | кэш/счётчики (очередь — в PostgreSQL) |
| worker (python) | — | профиль `workers`: очередь `nj120_jobs` |
| adminer | 127.0.0.1:8081 | профиль `tools`: GUI БД |
| mailpit | 127.0.0.1:8025 | профиль `tools`: ловушка писем |

## 3. Команды разработки

```bash
make up / down / ps / logs      # стек docker
make serve                      # нативный dev-сервер (php -S, :8080)
make shell                      # bash внутри php-контейнера
make migrate / migrate-status   # миграции
make seed                       # админ из .env
make fresh                      # ПЕРЕСОЗДАТЬ схему с нуля (данные стираются!)
make test / lint / doctor       # тесты, синтаксис, диагностика
make composer                   # composer install (локально или в контейнере)
make worker / worker-install    # python-воркеры
```

## 4. Переменные окружения

Полный список с комментариями — `.env.example`. Ключевые:

| Переменная | Смысл |
|---|---|
| `KOHANA_ENV` | development/production (режим ошибок Kohana) |
| `STRICT_DEPRECATIONS` | 1 = депрекейшены PHP 8.4 рвут запрос (для чистки кода) |
| `DB_DEFAULT_*` | подключение к PostgreSQL (host/port/name/user/pass/schema) |
| `COOKIE_SALT`, `AUTH_HASH_KEY` | секреты (генерирует `bin/setup.sh`) |
| `TOOLFORGE_API_URL/KEY` | основной LLM-эндпоинт (OpenAI-совместимый) |
| `OPENROUTER_API_KEY` | внешний LLM-провайдер |
| `EMBEDDING_API_URL/KEY/MODEL/DIMENSIONS` | **отдельный сервер эмбеддингов** |
| `WORKER_API_TOKEN` | токен HTTP-API очереди `/nj120/api/*` |
| `WORKER_PG_DSN`, `WORKER_QUEUE_MODE` | как воркер ходит в очередь (pg/http) |
| `ADMIN_*` | параметры `make seed` |
| `MIGRATIONS_TOKEN` | токен веб-контроллера миграций |

Приоритет источников: переменные окружения сервера > `.env` (см. `application/env.php`).

## 5. PHP 8.4 — особенности

* Проект переведён на PHP 8.4; legacy-код Kohana приглушает депрекейшены
  (`STRICT_DEPRECATIONS=0` по умолчанию). Периодически включайте `=1` и чистите
  код: `null` в строковых функциях, динамические свойства, неявные nullable.
* Проверка синтаксиса: `make lint` (php -l по всему проекту, кроме vendor/system).

## 6. Диагностика

```bash
make doctor                        # PHP, БД, конфиги, модули
bin/console db:migrate --status    # состояние миграций
bin/console module:list            # реестр модулей
```
