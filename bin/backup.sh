#!/usr/bin/env bash
# =============================================================================
# PRX — резервная копия: база данных И файлы модулей.
#
#   bash bin/backup.sh              создать копию
#   bash bin/backup.sh --list       что уже лежит
#   bash bin/backup.sh --check      проверить последнюю копию на читаемость
#
# Зачем скрипт, а не строка в cron. Раньше бэкапилась только БД (одна строка
# в docs/DEPLOY-UBUNTU.md), а карточки, 3D-модели и досье живут файлами на
# диске. Восстановление из такой копии дало бы записи в БД со ссылками на
# несуществующие файлы — то есть битые карточки у пользователей.
#
# Список файловых хранилищ — тот же DEPLOY_MODULE_STORAGE, что использует
# bin/deploy.sh: один список, два потребителя. Добавили модулю хранилище —
# вписали один раз в /etc/default/prx-deploy, и деплой и бэкап узнали оба.
#
# Настройки (переменные окружения или /etc/default/prx-deploy):
#   BACKUP_DIR              куда класть            (по умолчанию /var/backups/prx)
#   BACKUP_KEEP_DAYS        сколько хранить дней   (14)
#   DEPLOY_MODULE_STORAGE   хранилища модулей      (storage)
#   BACKUP_PUBLIC_DIRS      публичные каталоги     (public_html/uploads
#                                                   public_html/avatar
#                                                   public_html/storage)
# =============================================================================
set -euo pipefail
# Бэкапы содержат БД и личные файлы, поэтому новые файлы доступны только владельцу.
umask 077

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=bin/_env.sh
source "$ROOT/bin/_env.sh"

# Настройки сервера главнее .env проекта, .env главнее умолчаний.
[ -f /etc/default/prx-deploy ] && . /etc/default/prx-deploy
prx_load_env "$ROOT/.env"

: "${BACKUP_DIR:=/var/backups/prx}"
: "${BACKUP_KEEP_DAYS:=14}"
: "${DEPLOY_MODULE_STORAGE:=storage}"
: "${BACKUP_PUBLIC_DIRS:=public_html/uploads public_html/avatar public_html/storage}"

: "${DB_DEFAULT_HOST:=127.0.0.1}"
: "${DB_DEFAULT_PORT:=5432}"
: "${DB_DEFAULT_USER:=prx}"
: "${DB_DEFAULT_NAME:=prx}"
: "${DB_DEFAULT_PASS:=}"

STAMP="$(date +%F_%H%M)"
SQL="$BACKUP_DIR/prx-db-$STAMP.sql.gz"
TAR="$BACKUP_DIR/prx-files-$STAMP.tar.gz"

say()  { echo "[$(date '+%F %T')] $*"; }
fail() { echo "ОШИБКА: $*" >&2; exit 1; }

# chmod применяется к каталогу бэкапа целиком: запрещаем очевидные опечатки,
# которые могли бы закрыть репозиторий/системный корень или пройти через .. .
case "$BACKUP_DIR" in
    /|//|//*|.|./|..|../|"$ROOT"|"$ROOT/"|"$ROOT/."|*"/../"*|*"/.."|*"/./"*|*"/.")
        fail "BACKUP_DIR должен быть отдельным каталогом, не корнем или путём с ..: $BACKUP_DIR" ;;
esac
[ ! -L "$BACKUP_DIR" ] || fail "BACKUP_DIR не может быть symlink: $BACKUP_DIR"

# --- --list -----------------------------------------------------------------
if [ "${1:-}" = "--list" ]; then
    [ -d "$BACKUP_DIR" ] || fail "нет каталога $BACKUP_DIR"
    chmod 0700 "$BACKUP_DIR" || fail "не закрыть права на $BACKUP_DIR"
    ls -lh "$BACKUP_DIR" | tail -n +2
    exit 0
fi

# --- --check: копия, которую нельзя прочитать, копией не является -----------
if [ "${1:-}" = "--check" ]; then
    [ -d "$BACKUP_DIR" ] || fail "нет каталога $BACKUP_DIR"
    chmod 0700 "$BACKUP_DIR" || fail "не закрыть права на $BACKUP_DIR"
    bad=0

    for f in "$BACKUP_DIR"/prx-db-*.sql.gz "$BACKUP_DIR"/prx-files-*.tar.gz; do
        [ -e "$f" ] || continue

        if ! gzip -t "$f" 2>/dev/null; then
            echo "битый архив: $f" >&2
            bad=1
            continue
        fi

        # пустой дамп gzip-тест проходит, а толку от него нет
        size=$(gzip -l "$f" 2>/dev/null | awk 'NR==2 {print $2}')
        [ -n "$size" ] && [ "$size" -gt 1024 ] || { echo "подозрительно пустой: $f" >&2; bad=1; }
    done

    [ "$bad" = "0" ] || fail "проверка не пройдена"
    say "все копии в $BACKUP_DIR читаются"
    exit 0
fi

# --- создание ----------------------------------------------------------------
mkdir -p "$BACKUP_DIR" || fail "не создать $BACKUP_DIR (нужен sudo?)"
chmod 0700 "$BACKUP_DIR" || fail "не закрыть права на $BACKUP_DIR"

PG_DUMP="$(command -v pg_dump || true)"
[ -n "$PG_DUMP" ] || fail "pg_dump не найден (нужен postgresql-client)"

say "база $DB_DEFAULT_NAME -> $SQL"
PGPASSWORD="$DB_DEFAULT_PASS" "$PG_DUMP" \
    -h "$DB_DEFAULT_HOST" -p "$DB_DEFAULT_PORT" -U "$DB_DEFAULT_USER" \
    --no-owner --no-privileges "$DB_DEFAULT_NAME" \
    | gzip -9 > "$SQL" || fail "pg_dump не отработал"
chmod 0600 "$SQL" || fail "не закрыть права на $SQL"

# Что архивировать: хранилища модулей + публичные каталоги с файлами
# пользователей. Каталоги перечисляем относительно корня проекта; отсутствующие
# молча пропускаем — не у каждой установки есть все модули.
DIRS=""
for d in $DEPLOY_MODULE_STORAGE $BACKUP_PUBLIC_DIRS; do
    case "$d" in
        /*) p="$d" ;;
        *)  p="$ROOT/$d" ;;
    esac

    if [ -d "$p" ]; then
        DIRS="$DIRS $d"
    else
        say "пропускаю (нет каталога): $d"
    fi
done

if [ -n "$DIRS" ]; then
    say "файлы ->$DIRS"
    # shellcheck disable=SC2086
    tar -czf "$TAR" -C "$ROOT" $DIRS || fail "tar не отработал"
    chmod 0600 "$TAR" || fail "не закрыть права на $TAR"
else
    say "файловых хранилищ нет — архив не создаю"
fi

# --- ротация -----------------------------------------------------------------
find "$BACKUP_DIR" -maxdepth 1 -name 'prx-db-*.sql.gz'    -mtime "+$BACKUP_KEEP_DAYS" -delete 2>/dev/null || true
find "$BACKUP_DIR" -maxdepth 1 -name 'prx-files-*.tar.gz' -mtime "+$BACKUP_KEEP_DAYS" -delete 2>/dev/null || true

say "готово: $(du -h "$SQL" | cut -f1) база$([ -f "$TAR" ] && echo ", $(du -h "$TAR" | cut -f1) файлы")"
say "хранить дней: $BACKUP_KEEP_DAYS | каталог: $BACKUP_DIR"
