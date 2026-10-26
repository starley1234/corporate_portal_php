#!/usr/bin/env bash
# Проверка приватных runtime/storage прав и режима создания файловых бэкапов.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT="$REPO"
# shellcheck source=bin/_storage_permissions.sh
source "$REPO/bin/_storage_permissions.sh"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() { printf 'ФЕЙЛ: %s\n' "$*" >&2; exit 1; }
mode() { stat -c '%a' "$1"; }
expect_mode() {
    local expected="$1" path="$2" got
    got="$(mode "$path")"
    [[ "$got" = "$expected" ]] || fail "$path: ожидали chmod $expected, получили $got"
}

# Перевод старых открытых деревьев в owner-only и запрет обработки корня/ссылок.
PRIVATE="$TMP/private"
OUTSIDE="$TMP/outside"
mkdir -p "$PRIVATE/nested" "$OUTSIDE"
printf 'secret' > "$PRIVATE/nested/photo.jpg"
printf 'outside' > "$OUTSIDE/keep.txt"
chmod 0777 "$PRIVATE" "$PRIVATE/nested" "$PRIVATE/nested/photo.jpg" "$OUTSIDE" "$OUTSIDE/keep.txt"
ln -s "$OUTSIDE" "$PRIVATE/external-link"

n2j_secure_private_tree "$PRIVATE"
expect_mode 700 "$PRIVATE"
expect_mode 700 "$PRIVATE/nested"
expect_mode 600 "$PRIVATE/nested/photo.jpg"
expect_mode 777 "$OUTSIDE"
expect_mode 777 "$OUTSIDE/keep.txt"

if n2j_secure_private_tree / >/dev/null 2>&1; then
    fail 'общий корень / нельзя принимать за каталог приложения'
fi
if n2j_secure_private_tree "$REPO" >/dev/null 2>&1; then
    fail 'корень репозитория нельзя принимать за хранилище'
fi

# Запускаем настоящий backup.sh на временной копии репозитория с поддельным pg_dump.
TEST_REPO="$TMP/repo"
BACKUPS="$TMP/backups"
FAKE_BIN="$TMP/fake-bin"
mkdir -p "$TEST_REPO/bin" "$TEST_REPO/storage/nj651/previews" \
    "$TEST_REPO/public_html/uploads" "$FAKE_BIN"
cp "$REPO/bin/backup.sh" "$REPO/bin/_env.sh" "$TEST_REPO/bin/"
printf 'private-preview' > "$TEST_REPO/storage/nj651/previews/1.jpg"
printf 'public-upload' > "$TEST_REPO/public_html/uploads/demo.txt"

cat > "$FAKE_BIN/pg_dump" <<'SH'
#!/usr/bin/env bash
head -c 2048 /dev/zero
SH
chmod 0755 "$FAKE_BIN/pg_dump"

(
    umask 000
    PATH="$FAKE_BIN:$PATH" \
    BACKUP_DIR="$BACKUPS" \
    DEPLOY_MODULE_STORAGE='storage/nj651' \
    BACKUP_PUBLIC_DIRS='public_html/uploads' \
    DB_DEFAULT_PASS='' \
    bash "$TEST_REPO/bin/backup.sh"
)

expect_mode 700 "$BACKUPS"
SQL_FILE="$(find "$BACKUPS" -maxdepth 1 -type f -name 'n2j-db-*.sql.gz' -print -quit)"
TAR_FILE="$(find "$BACKUPS" -maxdepth 1 -type f -name 'n2j-files-*.tar.gz' -print -quit)"
[[ -n "$SQL_FILE" && -n "$TAR_FILE" ]] || fail 'backup.sh не создал оба архива'
expect_mode 600 "$SQL_FILE"
expect_mode 600 "$TAR_FILE"
tar -tzf "$TAR_FILE" | grep -Fxq 'storage/nj651/previews/1.jpg' \
    || fail 'приватное хранилище NJ651 не попало в архив'

ln -s "$BACKUPS" "$TMP/backup-link"
if PATH="$FAKE_BIN:$PATH" BACKUP_DIR="$TMP/backup-link" \
    bash "$TEST_REPO/bin/backup.sh" --list >/dev/null 2>&1; then
    fail 'backup.sh не должен chmod-ить каталог через symlink'
fi

# Старый открытый backup-каталог должен закрываться и при проверке.
chmod 0777 "$BACKUPS"
PATH="$FAKE_BIN:$PATH" BACKUP_DIR="$BACKUPS" bash "$TEST_REPO/bin/backup.sh" --check
expect_mode 700 "$BACKUPS"
PATH="$FAKE_BIN:$PATH" BACKUP_DIR="$BACKUPS" bash "$TEST_REPO/bin/backup.sh" --list >/dev/null

printf 'ВСЁ ОК: приватные права runtime-хранилищ и закрытые архивы бэкапа\n'
