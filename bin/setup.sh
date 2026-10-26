#!/usr/bin/env bash
# =============================================================================
# N2J — подготовка локального окружения (идемпотентно).
#
#   bash bin/setup.sh
#
# Что делает:
#   1. создаёт .env из .env.example (секреты COOKIE_SALT/AUTH_HASH_KEY — новые);
#   2. создаёт каталоги для записи (runtime/private — только владельцу,
#      публичные uploads/img — с доступом веб-сервера);
#   3. если нет vendor/autoload.php и composer недоступен (закрытая сеть) —
#      создаёт минимальный автозагрузчик-заглушку, чтобы приложение грузилось.
#
# Реальные зависимости ставит composer: `make composer` или docker compose.
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=bin/_storage_permissions.sh
source "$ROOT/bin/_storage_permissions.sh"

msg() { printf '\033[36m==>\033[0m %s\n' "$*"; }

# --- вспомогательное: чтение и запись переменных .env --------------------------
# Значения в .env.example снабжены inline-комментариями («TOKEN=  # пояснение»),
# а сами комментарии и значения содержат / и & — то есть `sed "s/…/${val}/"` для
# них не годится: значение попадает в шаблон замены. 28.09.2026 setup.sh на этом
# и падал («sed: unknown option to `s'»), а до того молча вписывал текст
# комментария в workers/.env как токен. Обе операции сделаны без regexp.

gen_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32
    else
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

env_value() { # env_value <файл> <ключ> — значение без комментария и пробелов
    local line
    line="$(grep -m1 -E "^$2=" "$1" 2>/dev/null || true)"
    line="${line#*=}"
    line="${line%%#*}"
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    printf '%s' "$line"
}

env_set() { # env_set <файл> <ключ> <значение> — заменить строку целиком
    # ENVIRON вместо -v: awk -v раскрывает \n и прочие escape-последовательности
    _ENV_KEY="$2" _ENV_VAL="$3" awk '
        BEGIN { key = ENVIRON["_ENV_KEY"]; val = ENVIRON["_ENV_VAL"]; done = 0 }
        !done && substr($0, 1, length(key) + 1) == key "=" {
            print key "=" val; done = 1; next
        }
        { print }
        END { if (!done) print key "=" val }
    ' "$1" > "$1.tmp" && mv "$1.tmp" "$1"
}

# --- 1. .env ------------------------------------------------------------------
if [[ ! -f .env ]]; then
    msg "Создаю .env из .env.example"
    cp .env.example .env

    # Токены тоже генерируем: в .env.example у них пустое значение И
    # inline-комментарий. Если оставить строку как есть, парсер .env раньше
    # превращал комментарий в ЗНАЧЕНИЕ (см. application/env.php) — воркеры
    # получали 401 на /nj120/api/*, /nj597/api/outbox, /nj602/api/*.
    for key in COOKIE_SALT AUTH_HASH_KEY MIGRATIONS_TOKEN WORKER_API_TOKEN SITE_UPDATE_TOKEN; do
        env_set .env "$key" "$(gen_secret)"
    done

    msg "Секреты COOKIE_SALT/AUTH_HASH_KEY и токены (MIGRATIONS/WORKER_API/SITE_UPDATE) сгенерированы"
else
    msg ".env уже существует — пропускаю"
fi

# --- 1b. workers/.env -----------------------------------------------------------
# Воркерные секреты в git не хранятся (workers/.gitignore), поэтому при
# пересоздании клона/песочницы workers/.env «пропадает». Если его нет —
# создаём из шаблона и сразу вписываем общий токен сайта (значение
# WORKER_API_TOKEN сайта): воркеры смогут сдавать результаты без ручной правки.
# Токен может быть пустым (например, .env просто скопирован из .env.example) —
# тогда ничего не вписываем: пустой WORKER_SITE_TOKEN честнее мусора.
if [[ -f workers/.env.example && ! -f workers/.env ]]; then
    msg "Создаю workers/.env из workers/.env.example"
    cp workers/.env.example workers/.env

    site_token="$(env_value .env WORKER_API_TOKEN)"

    if [[ -n "$site_token" ]]; then
        env_set workers/.env WORKER_SITE_TOKEN "$site_token"
        msg "workers/.env создан (WORKER_SITE_TOKEN = токен сайта)"
    else
        msg "workers/.env создан (WORKER_API_TOKEN пуст — WORKER_SITE_TOKEN оставлен как есть)"
    fi
else
    msg "workers/.env уже существует — пропускаю"
fi

# --- 2. Каталоги для записи ----------------------------------------------------
msg "Создаю каталоги приложения"
mkdir -p application/logs application/cache application/sessions
mkdir -p public_html/uploads public_html/img
mkdir -p storage/nj580 storage/nj590 storage/nj651 storage/nj669

# Сессии, временные кадры VLM, логи и файлы модулей — не публичные данные.
# Не выдаём к ним доступ всем локальным пользователям: в частности, setup
# запускается при каждом деплое, а application/cache содержит временные фото.
storage_owner="$(n2j_storage_owner)"
for private_dir in "$ROOT/application/logs" "$ROOT/application/cache" \
                   "$ROOT/application/sessions" "$ROOT/storage"; do
    if ! n2j_secure_private_tree "$private_dir" "$storage_owner"; then
        msg "Не удалось ограничить доступ к $private_dir"
        exit 1
    fi
done

# Эти два каталога предназначены для публичных загрузок/изображений сайта.
chmod -R a+rwX public_html/uploads public_html/img 2>/dev/null || true

# --- 3. Composer / заглушка автозагрузчика --------------------------------------
need_vendor_shim=0
if [[ ! -f vendor/autoload.php ]]; then
    need_vendor_shim=1
fi

if [[ $need_vendor_shim -eq 1 ]]; then
    if command -v composer >/dev/null 2>&1; then
        msg "Запускаю composer install"
        composer install --no-interaction --no-progress || \
            msg "composer завершился с ошибкой — ставлю заглушку автозагрузчика"
    else
        msg "composer недоступен (закрытая сеть?) — ставлю заглушку автозагрузчика"
    fi
fi

if [[ ! -f vendor/autoload.php ]]; then
    mkdir -p vendor
    cat > vendor/autoload.php <<'PHP'
<?php
/**
 * ЗАГЛУШКА автозагрузчика (создана bin/setup.sh).
 *
 * Настоящие зависимости ставятся командой `composer install`
 * (нужен доступ к packagist.org) либо берутся из docker-образа php
 * (docker compose build php).
 *
 * Заглушка только позволяет приложению загружаться без vendor/
 * и подхватывает PSR-4 пакеты, если они появятся в vendor/<пакет>/src.
 */
if (is_file(__DIR__ . '/composer/autoload_real.php')) {
    require_once __DIR__ . '/composer/autoload_real.php';
    return \ComposerAutoloaderInit::getLoader();
}

spl_autoload_register(function ($class) {
    // Единственная зависимость проекта — symfony/mailer + symfony/mime
    // (Service_Nj597_Notify). Карта префиксов держится в composer.json;
    // раньше здесь висели Guzzle/Monolog/Carbon/PhpOffice/Dompdf — пакеты,
    // удалённые 28.09.2026 за отсутствием вызовов.
    $prefix = 'Symfony\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $path = __DIR__ . '/symfony/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require $path;
    }
}, true, false);
PHP
    msg "vendor/autoload.php (заглушка) создан"
fi

msg "Готово. Дальше: bin/restore-env.sh (полное разворачивание) или make up (docker)."
