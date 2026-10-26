# ---------------------------------------------------------------------------
# N2J — часто используемые команды разработки
#
#   make help        — список команд
#   make restore     — развернуть среду с нуля (PHP + PostgreSQL + конфиги + миграции)
#   make setup       — подготовить конфиги и каталоги
#   make up          — поднять docker compose
#   make migrate     — накатить миграции БД
#   make seed        — создать пользователя-администратора
#   make test        — phpunit
#   make lint        — проверка синтаксиса PHP
#   make doctor      — диагностика окружения
#   make new-module id=401 slug=tasks name="Задачи"
# ---------------------------------------------------------------------------

-include .env
export

DOCKER   ?= docker compose
CONSOLE  ?= bin/console
PHPUNIT  ?= bin/test

.DEFAULT_GOAL := help
.PHONY: help restore restore-pg restore-php restore-app setup up down restart logs shell ps \
        migrate migrate-status migrate-down seed test test-coverage lint doctor \
        modules new-module fresh cache-clear console composer worker worker-install \
        brief backlog doc-index \
        up-worker worker-logs mock-llm mock-embed stack up-tools backup backup-check

help: ## показать эту справку
	@grep -hE '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# --- Окружение --------------------------------------------------------------

restore: ## развернуть среду с нуля: PostgreSQL + PHP + конфиги + миграции
	bash bin/restore-env.sh $(if $(filter serve,$(MAKECMDGOALS)),--serve,)

restore-pg: ## только PostgreSQL (переносимые бинари из PyPI pgserver)
	bash bin/restore-env.sh --pg-only

restore-php: ## только PHP (apt или сборка из исходников)
	bash bin/restore-env.sh --php-only

restore-app: ## только конфиги, .env, миграции и админ (PHP и БД уже есть)
	bash bin/restore-env.sh --app-only

serve: ## поднять dev-сервер (в паре с restore: make restore serve)
	PORT=$(PORT) bash bin/serve

setup: ## создать конфиги, каталоги и .env
	bash bin/setup.sh

up: ## поднять стек (nginx, php-fpm, postgres, adminer, mailpit)
	$(DOCKER) up -d

down: ## остановить стек
	$(DOCKER) down

restart: down up ## перезапустить стек

logs: ## логи всех сервисов
	$(DOCKER) logs -f

ps: ## состояние сервисов
	$(DOCKER) ps

shell: ## shell внутри php-контейнера
	$(DOCKER) exec php bash

console: ## выполнить CLI-задачу: make console task=module:list
	$(CONSOLE) $(task)

cache-clear: ## очистить кэш Kohana
	rm -rf application/cache/*

# --- База данных ------------------------------------------------------------

migrate: ## накатить миграции
	$(CONSOLE) db:migrate

migrate-status: ## состояние миграций
	$(CONSOLE) db:migrate --status

migrate-dry: ## что будет накатано (без изменений)
	$(CONSOLE) db:migrate --dry-run

migrate-down: ## откатить миграцию: make migrate-down class=pgMigration_001_...
	$(CONSOLE) db:migrate --down=$(class)

seed: ## создать администратора (параметры из .env)
	$(CONSOLE) auth:user --email=$(ADMIN_EMAIL) --password=$(ADMIN_PASSWORD) --username=$(ADMIN_USERNAME) --admin

fresh: ## ПЕРЕСОЗДАТЬ схему БД с нуля (drop schema public + миграции)
	@echo "Внимание: все данные будут удалены. Прервите (Ctrl+C) в течение 5 секунд..."; sleep 5
	$(CONSOLE) db:migrate --status > /dev/null
	$(DOCKER) exec -T postgres psql -U $(DB_USERNAME) -d $(DB_DATABASE) -c "DROP SCHEMA public CASCADE; CREATE SCHEMA public;"
	$(CONSOLE) db:migrate
	$(MAKE) seed

# --- Качество кода ----------------------------------------------------------

lint: ## проверка синтаксиса всех PHP-файлов
	$(CONSOLE) dev:lint

backup: ## резервная копия: дамп БД + архив файловых хранилищ
	bash bin/backup.sh

backup-check: ## проверить, что снятые копии читаются
	bash bin/backup.sh --check

doctor: ## диагностика окружения (PHP, БД, конфиги, модули)
	$(CONSOLE) dev:doctor

modules: ## инвентаризация модулей
	$(CONSOLE) module:list

test: ## запустить тесты
	$(PHPUNIT) "$(filter)"

test-coverage: ## тесты с покрытием (нужен xdebug/pcov)
	vendor/bin/phpunit -c phpunit.xml.dist --coverage-html build/coverage

# --- Новые модули -----------------------------------------------------------

new-module: ## создать модуль nj401_tasks: make new-module id=401 slug=tasks name="Задачи"
	$(CONSOLE) module:scaffold --id=$(id) --slug=$(slug) --name="$(name)"

# --- Экономная разработка по модулям (docs/CONTEXT.md) ----------------------

brief: ## контекст-пак модуля для агента: make brief m=nj643_cad (→ .context/<модуль>.md)
	python3 .tools/context_pack.py pack $(m) --write

backlog: ## что осталось открытым в модулях: make backlog [m=nj643_cad]
	python3 .tools/context_pack.py backlog $(if $(m),--module $(m),)

doc-index: ## обновить индекс разделов в документе: make doc-index f=AGENTS.md
	python3 .tools/context_pack.py index $(f) --write

# --- Composer ----------------------------------------------------------------

composer: ## установить зависимости (локально, если есть composer)
	cd $(CURDIR) && composer install --no-interaction --no-progress

# --- Python-воркеры (workers/, очередь nj120_jobs) ---------------------------

worker-install: ## поставить зависимости python-воркеров
	cd workers && python3 -m pip install -r requirements.txt

worker: ## запустить воркеры локально (каналы: WORKER_CHANNELS=... make worker)
	cd workers && python3 -m n2j_workers

up-worker: ## поднять воркеры в docker (профиль workers)
	$(DOCKER) --profile workers up -d worker

worker-logs: ## логи воркеров в docker
	$(DOCKER) logs -f worker

mock-llm: ## мок LLM-шлюза (:1234) для разработки без GPU
	python3 workers/mock/mock_llm_server.py

mock-embed: ## мок эмбеддинг-сервера (:1235) для разработки без GPU
	python3 workers/mock/mock_embed_server.py

up-tools: ## поднять инструменты (adminer :8081, mailpit :8025)
	$(DOCKER) --profile tools up -d

stack: ## полный стек: ядро + инструменты + воркеры
	$(DOCKER) --profile tools --profile workers up -d
