#!/usr/bin/env bash
# =============================================================================
# PRX — регрессия «с нуля» (fresh-install check).
#
#   bash bin/check-fresh.sh            # полная проверка на временной БД
#   bash bin/check-fresh.sh --keep     # не удалять временную БД (разбор полётов)
#
# Что делает:
#   1. Создаёт одноразовую БД prx_check_<метка> в том же PostgreSQL;
#   2. Прогоняет ВСЕ миграции с нуля (порядок модулей, idempotentность);
#   3. Прогоняет тесты (bin/test) против этой БД;
#   4. Проверяет CLI обслуживания; опциональный SMOKE_URL — HTTP уже работающего сайта;
#   5. Повторный db:migrate (должно быть «применено 0»);
#   6. Удаляет временную БД (--keep оставляет для разбора).
#
# Зачем: почти все исторические баги среды ловились именно свежим
# разворачиванием (порядок миграций prx208<prx120, отравленные токены .env,
# BOOLEAN 't'/'f', SQL в сервисах, завязанный на runtime-DDL). Держать в cron:
#   0 4 * * * cd /path/to/prx.ru && bash bin/check-fresh.sh >> deploy/check-fresh.log 2>&1
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
KEEP=0; [[ "${1:-}" == "--keep" ]] && KEEP=1

# --- окружение и PHP ---------------------------------------------------------
# shellcheck source=bin/_env.sh
source "$(dirname "${BASH_SOURCE[0]}")/_env.sh"
prx_load_env .env
: "${DB_DEFAULT_HOST:=127.0.0.1}"; : "${DB_DEFAULT_PORT:=5432}"
: "${DB_DEFAULT_USER:=prx}";       : "${DB_DEFAULT_PASS:=}"

PHP_BIN="$(prx_php_bin)" || { echo "ERROR: PHP не найден (bin/restore-env.sh)" >&2; exit 1; }

# --- psql: системный или из pgserver ----------------------------------------
PSQL="$(command -v psql || true)"
if [[ -z "$PSQL" ]]; then
    PSQL="$(python3 -c 'import pgserver,os;print(os.path.join(os.path.dirname(pgserver.__file__),"pginstall","bin","psql"))' 2>/dev/null || true)"
fi
[[ -n "$PSQL" ]] || { echo "ERROR: psql не найден (нужен PostgreSQL-клиент)" >&2; exit 1; }

export PGPASSWORD="${DB_DEFAULT_PASS}"

CHECK_DB="prx_check_$(date +%Y%m%d_%H%M%S)"
fail() { echo "✘ ПАДЕНИЕ: $*" >&2; [[ $KEEP -eq 1 ]] || drop_db || true; exit 1; }

# Вывод инструмента показываем ЦЕЛИКОМ, если шаг упал. Раньше здесь всюду стоял
# `| tail -1`, и от упавших тестов оставалась ровно одна строка — в CI это была
# последняя строка стектрейса («#10 {main}»), по которой причина не
# восстанавливается вообще (28.09.2026). Успешный шаг по-прежнему печатает
# одну итоговую строку: краткость нужна, пока всё хорошо.
step() { # step <заголовок> <команда…>
    local title="$1"; shift
    local out
    echo "==> $title"

    if ! out="$("$@" 2>&1)"; then
        printf '%s\n' "$out" >&2
        fail "$title"
    fi

    printf '%s\n' "$out" | tail -1 | sed 's/^/    /'
    LAST_STEP_OUT="$out"
}
drop_db() {
    "$PSQL" -h "$DB_DEFAULT_HOST" -p "$DB_DEFAULT_PORT" -U "$DB_DEFAULT_USER" -d postgres \
        -q -c "DROP DATABASE IF EXISTS $CHECK_DB" >/dev/null 2>&1
}
trap '[[ $KEEP -eq 1 ]] || drop_db || true' EXIT

echo "==> Временная БД: $CHECK_DB"
"$PSQL" -h "$DB_DEFAULT_HOST" -p "$DB_DEFAULT_PORT" -U "$DB_DEFAULT_USER" -d postgres -q \
    -c "CREATE DATABASE $CHECK_DB" >/dev/null || fail "не удалось создать БД (права $DB_DEFAULT_USER?)"

export DB_DEFAULT_NAME="$CHECK_DB"

step "Миграции с нуля" "$PHP_BIN" "$ROOT/public_html/index.php" db:migrate
printf '%s\n' "$LAST_STEP_OUT" | grep -q "Готово" \
    || { printf '%s\n' "$LAST_STEP_OUT" >&2; fail "миграции не завершились"; }

step "Миграции повторно (идемпотентность)" "$PHP_BIN" "$ROOT/public_html/index.php" db:migrate
printf '%s\n' "$LAST_STEP_OUT" | grep -qE "применено 0" \
    || { printf '%s\n' "$LAST_STEP_OUT" >&2; fail "повторный прогон что-то применяет — миграции не идемпотентны"; }

step "Тесты против чистой БД" bash bin/test

step "Обслуживание очереди и outbox" bash bin/console portal:maintain

# Сайт запускается отдельно, чтобы скрипт не оставлял фоновых серверов.
# SMOKE_URL проверяет уже работающий сайт, а не временную БД выше.
if [[ -n "${SMOKE_URL:-}" ]]; then
    step "HTTP smoke работающего сайта" python3 tests/E2e/http_smoke.py
fi

echo "✔ FRESH: миграции с нуля, повтор без изменений, PHP-тесты, maintenance"
if [[ $KEEP -eq 1 ]]; then echo "БД $CHECK_DB оставлена для разбора"; fi
