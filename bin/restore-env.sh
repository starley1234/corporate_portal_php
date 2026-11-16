#!/usr/bin/env bash
# =============================================================================
# PRL — разворачивание среды с нуля (идемпотентно, можно запускать
# повторно). Восстанавливает всё: PHP 8.4 + PostgreSQL + конфиги + миграции.
#
#   bash bin/restore-env.sh                 # всё и сразу
#   bash bin/restore-env.sh --serve         # ... и поднять dev-сервер
#   bash bin/restore-env.sh --pg-only       # только PostgreSQL
#   bash bin/restore-env.sh --php-only      # только PHP
#   bash bin/restore-env.sh --app-only      # конфиги + .env + миграции + админ
#
# Стратегии:
#   * если есть docker/docker compose — основной путь (docker compose up);
#   * иначе нативно: PHP 8.4 из репозитория packages.sury.org (Debian/Ubuntu),
#     PostgreSQL из apt, а при недоступности apt — переносимый PostgreSQL
#     из PyPI-пакета pgserver (без root).
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DO_PG=1; DO_PHP=1; DO_APP=1; DO_SERVE=0
for arg in "$@"; do
    case "$arg" in
        --pg-only)  DO_PHP=0; DO_APP=0 ;;
        --php-only) DO_PG=0; DO_APP=0 ;;
        --app-only) DO_PG=0; DO_PHP=0 ;;
        --serve)    DO_SERVE=1 ;;
        *) echo "Неизвестный флаг: $arg" >&2; exit 1 ;;
    esac
done

msg()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[33m !!\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m OK\033[0m %s\n' "$*"; }

SUDO=""
if [[ "$(id -u)" != "0" ]] && command -v sudo >/dev/null 2>&1; then SUDO="sudo"; fi

have() { command -v "$1" >/dev/null 2>&1; }

php_ok() {
    local p
    for p in php8.4 php84; do
        if have "$p"; then "$p" -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);' && return 0; fi
    done
    return 1
}

any_php() {
    local p
    for p in php8.4 php8.3 php php84; do have "$p" && { echo "$p"; return 0; }; done
    return 1
}

# =============================================================================
# 1. PHP 8.4
# =============================================================================
install_php() {
    if php_ok; then ok "PHP 8.4 уже установлен: $(php8.4 -r 'echo PHP_VERSION;' 2>/dev/null || php84 -r 'echo PHP_VERSION;')"; return 0; fi

    if have docker && docker info >/dev/null 2>&1; then
        msg "PHP не найден локально, но есть docker — PHP будет в контейнере (make up). Пропускаю нативную установку."
        return 0
    fi

    msg "Ставлю PHP 8.4 (Sury, Debian/Ubuntu)"
    if ! have apt-get; then
        warn "apt-get недоступен — поставьте PHP 8.4 вручную (https://packages.sury.org/php/)"; return 1
    fi

    export DEBIAN_FRONTEND=noninteractive
    $SUDO apt-get update -y
    $SUDO apt-get install -y ca-certificates curl gnupg2 lsb-release

    local codename
    codename="$($SUDO lsb_release -sc 2>/dev/null || echo bookworm)"

    if have gpg2 || have gpg; then
        curl -fsSL https://packages.sury.org/php/apt.gpg | $SUDO gpg --dearmor -o /usr/share/keyrings/sury-php.gpg 2>/dev/null \
            || curl -fsSL https://packages.sury.org/php/apt.gpg | $SUDO tee /usr/share/keyrings/sury-php.gpg >/dev/null
        echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${codename} main" \
            | $SUDO tee /etc/apt/sources.list.d/sury-php.list >/dev/null
        $SUDO apt-get update -y
    fi

    $SUDO apt-get install -y \
        php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml php8.4-curl \
        php8.4-intl php8.4-zip php8.4-bcmath php8.4-gd php8.4-sqlite3

    php_ok && ok "PHP 8.4 установлен" || { warn "PHP 8.4 не установился"; return 1; }
}

# =============================================================================
# 2. PostgreSQL
# =============================================================================
install_pg() {
    # Уже есть живой сервер на порту из .env(.example)?
    local port host
    port="$(grep -E '^DB_DEFAULT_PORT=' .env 2>/dev/null | cut -d= -f2 || true)"
    port="${port:-5432}"
    host="$(grep -E '^DB_DEFAULT_HOST=' .env 2>/dev/null | cut -d= -f2 || true)"
    host="${host:-127.0.0.1}"

    if have pg_isready && pg_isready -h "$host" -p "$port" >/dev/null 2>&1; then
        ok "PostgreSQL уже отвечает на ${host}:${port}"; return 0
    fi

    if have docker && docker info >/dev/null 2>&1; then
        msg "Поднимаю PostgreSQL в docker compose (сервис postgres)"
        docker compose up -d postgres
        return 0
    fi

    # Нативно: сначала apt, затем переносимый pgserver из PyPI (без root)
    if have apt-get && $SUDO apt-get install -y postgresql postgresql-contrib >/dev/null 2>&1; then
        $SUDO service postgresql start || true
        sleep 2
        if pg_isready -h "$host" -p "$port" >/dev/null 2>&1; then
            ok "PostgreSQL (apt) запущен"
            $SUDO -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='prl'" | grep -q 1 || \
                $SUDO -u postgres psql -c "CREATE ROLE prl LOGIN PASSWORD 'prl' CREATEDB;"
            $SUDO -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='prl'" | grep -q 1 || \
                $SUDO -u postgres psql -c "CREATE DATABASE prl OWNER prl;"
            return 0
        fi
    fi

    msg "Ставлю переносимый PostgreSQL (PyPI: pgserver, без root)"
    have python3 || { warn "python3 не найден — некому ставить pgserver"; return 1; }
    python3 -m pip install --quiet --user --break-system-packages pgserver 2>/dev/null \
        || python3 -m pip install --quiet --user pgserver
    python3 - "$ROOT" <<'PYEOF'
import sys, os
import subprocess
from pathlib import Path
import pgserver
root = sys.argv[1]
pgdata = Path(os.environ.get("PRL_PGDATA", os.path.expanduser("~/.prl/pgdata")))
pgdata.parent.mkdir(parents=True, exist_ok=True)
# Нюансы pgserver 0.1.4: cleanup_mode='stop' по умолчанию убивает сервер при
# выходе python (atexit), а ensure_postgres_running() слушает только unix-сокет.
# Поэтому: cleanup_mode=None, initdb без старта, запуск через pg_ctl с TCP.
pgbin = Path(pgserver.__file__).parent / "pginstall" / "bin"
PGOPTS = "-h 127.0.0.1 -p 5432 -k /tmp"

def pg_ctl(*args):
    return subprocess.run([str(pgbin/"pg_ctl"), "-D", str(pgdata), *args],
                          capture_output=True, text=True)

def psql(sql):
    r = subprocess.run([str(pgbin/"psql"), "-h", "127.0.0.1", "-p", "5432",
                        "-U", "postgres", "-d", "postgres", "-tAc", sql],
                       capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(r.stderr.strip())
    return r.stdout.strip()

srv = pgserver.get_server(pgdata, cleanup_mode=None)
srv.ensure_pgdata_inited()

tcp_ok = subprocess.run([str(pgbin/"psql"), "-h", "127.0.0.1", "-p", "5432",
                         "-U", "postgres", "-d", "postgres", "-tAc", "SELECT 1"],
                        capture_output=True).returncode == 0
if pg_ctl("status").returncode != 0:
    pg_ctl("-l", str(pgdata/"pg.log"), "-o", PGOPTS, "-w", "start")
elif not tcp_ok:
    pg_ctl("-m", "fast", "stop")
    pg_ctl("-l", str(pgdata/"pg.log"), "-o", PGOPTS, "-w", "start")

if psql("SELECT 1 FROM pg_roles WHERE rolname='prl'") != "1":
    psql("CREATE ROLE prl LOGIN PASSWORD 'prl' CREATEDB;")
if psql("SELECT 1 FROM pg_database WHERE datname='prl'") != "1":
    psql("CREATE DATABASE prl OWNER prl")
print("PostgreSQL (pgserver) готов: TCP 127.0.0.1:5432, данные", pgdata)
PYEOF
    ok "Переносимый PostgreSQL запущен (данные: ~/.prl/pgdata)"
}

# =============================================================================
# 3. Приложение: .env, vendor, миграции, админ
# =============================================================================
install_app() {
    bash bin/setup.sh

    # composer: локально или внутри контейнера
    if [[ ! -f vendor/autoload.php || -f vendor/README.shim ]]; then
        if have composer; then
            msg "composer install"
            composer install --no-interaction --no-progress || warn "composer install не удался"
        elif have docker && docker compose ps php >/dev/null 2>&1; then
            msg "composer install (в контейнере php)"
            docker compose exec -T php composer install --no-interaction --no-progress || warn "composer в контейнере не удался"
        else
            warn "composer недоступен — работаем на заглушке автозагрузчика (см. bin/setup.sh)"
        fi
    fi

    msg "Накатываю миграции"
    if bash -c 'command -v bin/console' && bin/console db:migrate; then
        ok "Миграции применены"
    else
        warn "Миграции не выполнены (нет БД/PHP?) — запустите позже: make migrate"
    fi

    source bin/_env.sh
    prl_load_env "$ROOT/.env"
    msg "Создаю администратора"
    bin/console auth:user --email="${ADMIN_EMAIL:-admin@example.test}" \
        --password="${ADMIN_PASSWORD:?Задайте ADMIN_PASSWORD в .env}" \
        --username="${ADMIN_USERNAME:-admin}" --admin || warn "Админ не создан (возможно, уже есть)"
}

# =============================================================================
# main
# =============================================================================
[[ $DO_PHP -eq 1 ]] && install_php || true
[[ $DO_PG  -eq 1 ]] && install_pg || true
[[ $DO_APP -eq 1 ]] && install_app || true

if [[ $DO_SERVE -eq 1 ]]; then
    PORT="${PORT:-8080}" bash bin/serve
else
    echo
    msg "Среда готова. Полезные команды:"
    echo "    make up        — docker-стек (nginx+php-fpm+pg)"
    echo "    make serve     — нативный dev-сервер"
    echo "    make migrate   — миграции БД"
    echo "    make doctor    — диагностика"
fi
