#!/usr/bin/env bash
# =============================================================================
# PRX — ЕДИНСТВЕННЫЙ путь обновления сервера из git.
#
#   bash bin/deploy.sh                 # разовая проверка + обновление
#   bash bin/deploy.sh --migrate       # + проверить/применить миграции
#                                      #   даже если обновлений нет (пинок)
#   bash bin/deploy.sh --rollback <sha>  # откат на конкретный коммит
#   bash bin/deploy.sh --status          # что сейчас: ветка, коммит, миграции
#
# Запускаться по кнопке в админке (/prx100/update) или по токен-ссылке
# (/update/kick/<SITE_UPDATE_TOKEN>) вместо крона каждую минуту: так GitHub
# не «пытается» каждые 60 секунд. systemd-таймер можно отключить.
# Безопасен для повторного запуска: flock не даёт двум копиям пересечься.
#
# Корневой update.sh — тонкая обёртка над этим скриптом (второго пути нет:
# раньше он тянул git сам и НЕ применял миграции, отсюда «схема отстала»).
#
# Переменные окружения (задаются в /etc/default/prx-deploy):
#   DEPLOY_BRANCH=<ветка>          ветка для слежения; если не задана — берётся
#                                  та, на которой сервер уже стоит. Имя ветки
#                                  НЕ хранится в репозитории (см. AGENTS.md §0).
#   DEPLOY_LOG=/var/log/prx-deploy.log
#   DEPLOY_WEB_USER=www-data       владелец записываемых каталогов
#   DEPLOY_AUTO_MIGRATE=0          1 = после pull запускать db:migrate
#   DEPLOY_MODULE_STORAGE=…        список хранилищ модулей вне public_html
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=bin/_storage_permissions.sh
source "$ROOT/bin/_storage_permissions.sh"

# Ветка берётся из окружения, иначе — та, на которой сервер уже стоит.
# В репозитории имя ветки не хранится: прод настраивается на месте.
BRANCH="${DEPLOY_BRANCH:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || true)}"
LOG_FILE="${DEPLOY_LOG:-/var/log/prx-deploy.log}"
LOCK="/tmp/prx-deploy.lock"

FORCE_MIGRATE=0
ROLLBACK_TO=""
WANT_ROLLBACK=0
STATUS_ONLY=0
while [ $# -gt 0 ]; do
    case "$1" in
        --migrate)  FORCE_MIGRATE=1 ;;
        --status)   STATUS_ONLY=1 ;;
        --rollback)
            WANT_ROLLBACK=1
            # sha — следующим аргументом; его может не быть (проверим ниже)
            if [ $# -ge 2 ]; then ROLLBACK_TO="$2"; shift; fi
            ;;
        *) ;;
    esac
    shift || break
done

# «--rollback» без коммита — это опечатка, а не «обнови как обычно»
if [ "$WANT_ROLLBACK" = "1" ] && [ -z "$ROLLBACK_TO" ]; then
    echo "deploy: --rollback требует коммит: bash bin/deploy.sh --rollback <sha>" >&2
    exit 1
fi

log() { echo "[$(date '+%F %T')] $*" >> "$LOG_FILE"; }
say() { echo "$*"; log "$*"; }

# параллельные запуски не нужны: второй просто выходит
exec 9>"$LOCK"
flock -n 9 || exit 0

mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true

# Ветку обязаны знать: либо DEPLOY_BRANCH, либо сервер стоит на нормальной ветке
# (не в detached HEAD). Молча тянуть «какую-нибудь» нельзя.
if [ -z "$BRANCH" ] || [ "$BRANCH" = "HEAD" ]; then
    log "ветка не определена: задайте DEPLOY_BRANCH в /etc/default/prx-deploy"
    echo "deploy: ветка не определена — задайте DEPLOY_BRANCH в /etc/default/prx-deploy" >&2
    exit 1
fi

# --- --status: ничего не меняем, только показываем ---------------------------
if [ "$STATUS_ONLY" = "1" ]; then
    echo "ветка:  $BRANCH"
    echo "коммит: $(git rev-parse --short HEAD 2>/dev/null || echo '?')"
    git fetch origin "$BRANCH" --quiet 2>/dev/null \
        && echo "origin: $(git rev-parse --short "origin/$BRANCH" 2>/dev/null || echo '?')"
    bin/console db:migrate --status 2>&1 || echo "миграции: статус недоступен"
    exit 0
fi

# =============================================================================
# Применение состояния: общая часть для обновления и отката
# =============================================================================
apply_state() { # apply_state <git-ref>
    local ref="$1"

    # Сервер обязан стоять НА ветке, а не в detached HEAD: иначе следующий
    # запуск не сможет определить ветку сам (раньше это чинил update.sh).
    if [ -z "$ROLLBACK_TO" ]; then
        git checkout -B "$BRANCH" "$ref" --quiet 2>/dev/null || git reset --hard "$ref" --quiet
    fi
    git reset --hard "$ref" --quiet

    # Производный кэш СТАРОГО кода. Манифесты модулей (пятый контракт) лежат
    # в Kohana::cache с TTL в час: без чистки новый модуль, изменённое
    # расписание или новая чистка подхватились бы только через час после
    # выкладки — а снятый с раздачи источник ещё час отвечал бы в поиске.
    # Каталог целиком производный (в .gitignore), терять там нечего.
    if [ -d application/cache ]; then
        find application/cache -type f -delete 2>/dev/null || true
    fi

    # composer-зависимости, если composer установлен
    if [ -f composer.json ] && command -v composer >/dev/null 2>&1; then
        composer install --no-dev --prefer-dist --no-interaction --quiet \
            >> "$LOG_FILE" 2>&1 || log "composer install: ОШИБКА (продолжаю)"
    fi

    # python-зависимости воркеров (раньше это делал только update.sh —
    # из-за чего после деплоя воркер мог не найти новый пакет)
    if [ -f workers/requirements.txt ] && [ -x workers/.venv/bin/pip ]; then
        workers/.venv/bin/pip install -r workers/requirements.txt --quiet \
            >> "$LOG_FILE" 2>&1 || log "pip install (workers): ОШИБКА (продолжаю)"
    fi

    # Каталоги и приватные права обязательны: при ошибке нельзя сообщать,
    # что деплой готов, и оставлять приложение без доступного storage.
    if ! bash bin/setup.sh >> "$LOG_FILE" 2>&1; then
        log "setup.sh: ОШИБКА — деплой остановлен"
        echo "deploy: не удалось подготовить каталоги; проверьте $LOG_FILE" >&2
        return 1
    fi

    # Что миграции собираются сделать — В ЛОГ ДО применения: если деплой потом
    # уронит сайт, в логе видно, какая схема поехала.
    if [ "${DEPLOY_AUTO_MIGRATE:-1}" = "1" ]; then
        log "миграции — состояние до применения:"
        bin/console db:migrate --status >> "$LOG_FILE" 2>&1 || log "db:migrate --status: недоступен"
        bin/console db:migrate >> "$LOG_FILE" 2>&1 || log "db:migrate: ОШИБКА"
    fi

    # Runtime/session/cache и хранилища модулей содержат непубличные данные
    # (в т.ч. временные фото VLM и превью PRX651). Всё дерево получает режим
    # 0700/0600 и владельца веб-процесса, а не world-writable права.
    storage_owner="$(prx_storage_owner)"
    for d in application/logs application/cache application/sessions \
             ${DEPLOY_MODULE_STORAGE:-storage}; do
        case "$d" in
            /*) p="$d" ;;
            *)  p="$ROOT/$d" ;;
        esac

        if ! prx_secure_private_tree "$p" "$storage_owner"; then
            log "приватные права хранилища не применены: $p"
            return 1
        fi
    done

    # Старые публичные uploads/avatar/img не меняем автоматически: новые
    # файлы модулей принадлежат приватному storage, старые данные мигрируют
    # по отдельному согласованному плану.

    # воркеры подхватят новый код; перезапуск срабатывает только под root —
    # из-под www-data (пинок) systemctl недоступен, это не ошибка:
    # fpm-пул обновит код через сброс opcache в контроллере пинка
    if [ "$(id -u)" = "0" ]; then
        systemctl restart prx-worker 2>/dev/null || true
        systemctl reload php8.4-fpm 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
    fi
}

# --- --rollback <sha>: вернуться на конкретный коммит ------------------------
if [ -n "$ROLLBACK_TO" ]; then
    git fetch origin "$BRANCH" --quiet || log "git fetch недоступен (откат из локальных объектов)"
    if ! git rev-parse --verify --quiet "${ROLLBACK_TO}^{commit}" >/dev/null; then
        say "откат невозможен: коммит $ROLLBACK_TO не найден"
        exit 1
    fi
    say "откат: $(git rev-parse --short HEAD) -> $(git rev-parse --short "$ROLLBACK_TO")"
    apply_state "$ROLLBACK_TO"
    say "откат готов: $(git rev-parse --short HEAD)"
    exit 0
fi

# --- обычный путь: сверка с origin и обновление -------------------------------
git fetch origin "$BRANCH" --quiet || { log "git fetch недоступен"; exit 1; }

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse "origin/$BRANCH")
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "")

# Совпали коммиты, но сервер стоит не на той ветке — это тоже рассинхрон
# (следующий запуск без DEPLOY_BRANCH определит ветку неверно).
if [ "$LOCAL" = "$REMOTE" ] && [ "$CURRENT_BRANCH" = "$BRANCH" ]; then
    # обновлений нет; по пинку (--migrate) всё равно проверяем миграции
    if [ "$FORCE_MIGRATE" = "1" ]; then
        log "пинок: обновлений нет, проверяю миграции"
        bin/console db:migrate --status >> "$LOG_FILE" 2>&1 || log "db:migrate --status: недоступен"
        bin/console db:migrate >> "$LOG_FILE" 2>&1 || log "db:migrate: ОШИБКА"
        log "пинок готов: $(git rev-parse --short HEAD)"
    fi
    exit 0
fi

log "обновление: ${LOCAL:0:8} -> ${REMOTE:0:8} ($BRANCH)"

# На сервере локальных правок быть не должно — ставим ровно состояние ветки
apply_state "origin/$BRANCH"

log "готово: $(git rev-parse --short HEAD)"
