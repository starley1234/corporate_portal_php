#!/usr/bin/env bash
# =============================================================================
# N2J — разворачивание среды в ЗАКРЫТОЙ песочнице (apt/packagist недоступны,
# доступны только github.com/codeload и PyPI).
#
#   bash bin/setup-sandbox.sh            # всё: тулчейн + PHP 8.4 + PG + приложение
#   bash bin/setup-sandbox.sh --serve    # ... и поднять dev-сервер :8080
#   bash bin/setup-sandbox.sh --skip-build   # PHP уже собран, только БД/приложение
#
# Что делает:
#   1. Собирает из исходников (codeload.github.com) цепочку:
#      re2c (PyPI wheel) → m4 → autoconf → bison → pkgconf → zlib → openssl
#      → libxml2 → curl → PHP 8.4 (cli: pgsql, pdo_pgsql, mbstring, dom/xml,
#      curl, openssl, zlib, opcache...).
#      Префикс: /tmp/n2j-opt (перезапуск песочницы = пересборка скриптом).
#   2. PostgreSQL — переносимый, из PyPI-пакета pgserver (как в restore-env.sh),
#      порт 5432, роль/база n2j.
#   3. Приложение: .env (bin/setup.sh) + vendor-заглушка + миграции + админ.
#   4. Тесты: php tests/mini_runner.php (phpunit в такой сети недоступен).
#
# Проверено 21.09.2026 на Debian 12 (bookworm), gcc 12, PHP 8.4.25.
# Системные требования: gcc/g++, make, python3+pip, perl, curl, git.
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

OPT=/tmp/n2j-opt
SRC=/tmp/n2j-src
BUILD=/tmp/n2j-build
PHP_TAG=php-8.4.25
SERVE=0; SKIP_BUILD=0
for arg in "$@"; do
    case "$arg" in
        --serve) SERVE=1 ;;
        --skip-build) SKIP_BUILD=1 ;;
    esac
done

msg()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m OK\033[0m %s\n' "$*"; }

dl() { # dl <url> <file>
    curl -fsSL -m 600 -o "$2" "$1" || { echo "Не скачалось: $1" >&2; exit 1; }
}

# ---------------------------------------------------------------------------
# 1. Тулчейн и PHP
# ---------------------------------------------------------------------------
if [[ $SKIP_BUILD -eq 0 ]]; then
    mkdir -p "$OPT/bin" "$SRC" "$BUILD"
    export PATH="$OPT/bin:$PATH"
    export PKG_CONFIG_PATH="$OPT/lib/pkgconfig"

    # pgserver нужен ДО сборки ext/pgsql: ставим на самом старте (иначе на
    # чистой песочнице PG_PREFIX пуст и PHP собирается без pgsql — см. SANDBOX-SETUP).
    pip install -q --break-system-packages pgserver 2>/dev/null || pip install -q pgserver
    PG_PREFIX="$(python3 -c 'import pgserver,os;print(os.path.join(os.path.dirname(pgserver.__file__),"pginstall"))')"

    php_ok() { [[ -x "$OPT/php/bin/php" ]] && "$OPT/php/bin/php" -v >/dev/null 2>&1; }
    if php_ok; then
        ok "PHP уже собран: $("$OPT/php/bin/php" -r 'echo PHP_VERSION;')"
    else
        msg "Скачиваю исходники (codeload.github.com)"
        dl "https://codeload.github.com/php/php-src/tar.gz/refs/tags/$PHP_TAG" "$SRC/php.tar.gz" &
        dl "https://codeload.github.com/tar-mirror/gnu-m4/tar.gz/refs/tags/v1.4.18"        "$SRC/m4.tar.gz" &
        dl "https://codeload.github.com/tar-mirror/gnu-autoconf/tar.gz/refs/tags/v2.69"    "$SRC/autoconf.tar.gz" &
        dl "https://codeload.github.com/tar-mirror/gnu-bison/tar.gz/refs/tags/v3.0.4"      "$SRC/bison.tar.gz" &
        dl "https://codeload.github.com/madler/zlib/tar.gz/refs/tags/v1.3.1"               "$SRC/zlib.tar.gz" &
        dl "https://codeload.github.com/openssl/openssl/tar.gz/refs/tags/openssl-3.5.8"    "$SRC/openssl.tar.gz" &
        dl "https://codeload.github.com/GNOME/libxml2/tar.gz/refs/tags/v2.13.9"            "$SRC/libxml2.tar.gz" &
        dl "https://codeload.github.com/curl/curl/tar.gz/refs/tags/curl-8_22_0"            "$SRC/curl.tar.gz" &
        dl "https://codeload.github.com/pkgconf/pkgconf/tar.gz/refs/tags/pkgconf-2.4.3"    "$SRC/pkgconf.tar.gz" &
        wait

        msg "re2c — из PyPI-wheel (бинарник manylinux)"
        pip install -q --break-system-packages re2c cmake ninja meson 2>/dev/null || pip install -q re2c cmake ninja meson
        WHEEL=$(python3 -c 'import importlib.metadata as m; print(m.distribution("re2c")._path if hasattr(m.distribution("re2c"),"_path") else "")' 2>/dev/null || true)
        WHEEL=$(python3 - <<'PY'
import glob, re, subprocess, sys
out = subprocess.run([sys.executable, "-m", "pip", "download", "re2c", "--no-deps",
                      "-d", "/tmp/n2j-src/wheels", "-q"], capture_output=True, text=True)
w = glob.glob("/tmp/n2j-src/wheels/re2c-*.whl")
print(w[0] if w else "")
PY
)
        python3 -m zipfile -e "$WHEEL" "$BUILD/re2c-wheel/"
        cp "$BUILD"/re2c-wheel/re2c/data/bin/re2c "$OPT/bin/" && chmod +x "$OPT/bin/re2c"

        msg "m4 1.4.18 (патчи: SIGSTKSZ/_IO_EOF_SEEN для glibc >= 2.28)"
        tar xzf "$SRC/m4.tar.gz" -C "$BUILD"
        (
            cd "$BUILD"/gnu-m4-1.4.18
            sed -i 's/#elif HAVE_LIBSIGSEGV && SIGSTKSZ < 16384/#elif 0/' lib/c-stack.c
            for f in lib/*.c; do
                if grep -q "_IO_ftrylockfile" "$f" 2>/dev/null; then
                    sed -i 's/defined _IO_ftrylockfile/(defined _IO_EOF_SEEN || defined _IO_ftrylockfile)/' "$f"
                fi
            done
            cat >> lib/stdio-impl.h <<'P'

#ifndef _IO_IN_BACKUP
# define _IO_IN_BACKUP 0x100
#endif
P
            ./configure --prefix="$OPT" >/dev/null && make -j2 >/dev/null && make install >/dev/null
        )
        ok "m4: $("$OPT/bin/m4" --version | head -1)"

        msg "autoconf 2.69"
        tar xzf "$SRC/autoconf.tar.gz" -C "$BUILD"
        ( cd "$BUILD"/gnu-autoconf-2.69 && ./configure --prefix="$OPT" >/dev/null \
          && make -j2 >/dev/null && make install >/dev/null )

        msg "bison 3.0.4 (патч fseterr для glibc >= 2.28)"
        tar xzf "$SRC/bison.tar.gz" -C "$BUILD"
        ( cd "$BUILD"/gnu-bison-3.0.4 \
          && for f in lib/*.c lib/*.h; do \
               if grep -q "_IO_ftrylockfile" "$f" 2>/dev/null; then \
                 sed -i 's/defined _IO_ftrylockfile/(defined _IO_EOF_SEEN || defined _IO_ftrylockfile)/' "$f"; \
               fi; done \
          && ./configure --prefix="$OPT" >/dev/null && make -j2 >/dev/null && make install >/dev/null )
        ok "bison: $("$OPT/bin/bison" --version | head -1)"

        msg "pkgconf 2.4.3 (+ симлинк pkg-config; ставит .so в lib64/x86_64-linux-gnu — разворачиваем в lib/)"
        tar xzf "$SRC/pkgconf.tar.gz" -C "$BUILD"
        ( cd "$BUILD" && meson setup pc-build "$BUILD"/pkgconf-pkgconf-2.4.3 -Dtests=disabled \
            --prefix="$OPT" >/dev/null && ninja -C pc-build >/dev/null && ninja -C pc-build install >/dev/null )
        cp -n "$OPT"/lib64/*.so* "$OPT/lib/" 2>/dev/null || true
        cp -n "$OPT"/lib/x86_64-linux-gnu/libpkgconf.so* "$OPT/lib/" 2>/dev/null || true
        cp -n "$OPT"/lib64/pkgconfig/*.pc "$OPT/lib/pkgconfig/" 2>/dev/null || true
        cp -n "$OPT"/lib/x86_64-linux-gnu/pkgconfig/*.pc "$OPT/lib/pkgconfig/" 2>/dev/null || true
        [[ -f "$OPT/bin/pkgconf.bin" ]] || mv "$OPT/bin/pkgconf" "$OPT/bin/pkgconf.bin"
        printf '#!/bin/sh\nLD_LIBRARY_PATH=%s/lib exec %s/bin/pkgconf.bin "$@"\n' "$OPT" "$OPT" > "$OPT/bin/pkgconf"
        chmod +x "$OPT/bin/pkgconf"; ln -sf "$OPT/bin/pkgconf" "$OPT/bin/pkg-config"
        ok "pkgconf: $("$OPT/bin/pkgconf" --version)"

        msg "zlib 1.3.1 (static)"
        tar xzf "$SRC/zlib.tar.gz" -C "$BUILD"
        ( cd "$BUILD"/zlib-1.3.1 && ./configure --prefix="$OPT" --static >/dev/null \
          && make -j2 >/dev/null && make install >/dev/null )

        msg "OpenSSL 3.5.8 (static, без zlib; ставит .a в lib64 — выравниваем)"
        tar xzf "$SRC/openssl.tar.gz" -C "$BUILD"
        ( cd "$BUILD"/openssl-openssl-3.5.8 \
          && ./Configure --prefix="$OPT" --openssldir="$OPT/ssl" no-shared no-zlib no-tests linux-x86_64 >/dev/null \
          && make -j2 >/dev/null && make install_sw install_ssldirs >/dev/null )
        cp -n "$OPT"/lib64/*.a "$OPT/lib/" 2>/dev/null || true
        cp -n "$OPT"/lib64/pkgconfig/*.pc "$OPT/lib/pkgconfig/" 2>/dev/null || true
        ok "openssl: $("$OPT/bin/openssl" version)"

        msg "libxml2 2.13.9 (static, cmake)"
        tar xzf "$SRC/libxml2.tar.gz" -C "$BUILD"
        ( cd "$BUILD" && cmake -S libxml2-2.13.9 -B lx-build -G Ninja -DCMAKE_BUILD_TYPE=Release \
            -DCMAKE_INSTALL_PREFIX="$OPT" -DBUILD_SHARED_LIBS=OFF -DLIBXML2_WITH_ZLIB=ON \
            -DZLIB_ROOT="$OPT" -DLIBXML2_WITH_ICONV=ON -DLIBXML2_WITH_LZMA=OFF \
            -DLIBXML2_WITH_PYTHON=OFF -DLIBXML2_WITH_TESTS=OFF -DLIBXML2_WITH_HTTP=OFF \
            -DLIBXML2_WITH_PROGRAMS=OFF >/dev/null \
          && ninja -C lx-build >/dev/null && ninja -C lx-build install >/dev/null )
        ok "libxml2: $(ls "$OPT/lib"/libxml2.a)"

        msg "curl 8.22.0 (static, openssl+zlib, без psl/nghttp2)"
        tar xzf "$SRC/curl.tar.gz" -C "$BUILD"
        ( cd "$BUILD" && cmake -S curl-curl-8_22_0 -B c-build -G Ninja -DCMAKE_BUILD_TYPE=Release \
            -DCMAKE_INSTALL_PREFIX="$OPT" -DBUILD_SHARED_LIBS=OFF -DBUILD_STATIC_LIBS=ON \
            -DBUILD_CURL_EXE=OFF -DBUILD_LIBCURL_DOCS=OFF -DBUILD_MISC_DOCS=OFF \
            -DCURL_USE_OPENSSL=ON -DOPENSSL_ROOT_DIR="$OPT" -DCURL_ZLIB=ON -DZLIB_ROOT="$OPT" \
            -DCURL_BROTLI=OFF -DCURL_ZSTD=OFF -DCURL_USE_LIBSSH2=OFF -DCURL_USE_LIBPSL=OFF \
            -DCURL_DISABLE_LDAP=ON -DCURL_DISABLE_LDAPS=ON -DBUILD_TESTING=OFF >/dev/null \
          && ninja -C c-build >/dev/null && ninja -C c-build install >/dev/null )
        ok "curl: $(ls "$OPT/lib"/libcurl.a)"

        msg "PHP $PHP_TAG (cli: pgsql, pdo_pgsql от pgserver, mbstring без mbregex, dom/xml, curl, openssl, zlib, opcache)"
        tar xzf "$SRC/php.tar.gz" -C "$BUILD"
        # SIGPIPE от head убивал сборку при set -o pipefail — отключаем его локально
        PHPDIR=$(set +o pipefail; tar tzf "$SRC/php.tar.gz" | head -1 | cut -d/ -f1)
        if [[ "$PG_PREFIX" == "" ]]; then
            echo "Сначала установите pgserver (шаг 2) и повторите с --skip-build" >&2; exit 1
        fi
        # Грабля (SANDBOX-SETUP §2.5): заголовки/библиотеки из $OPT не попадают
        # в INCLUDES/LDFLAGS Makefile — даём их явно CPPFLAGS/LDFLAGS и корнями
        # --with-* (openssl/local headers собирались статически в $OPT).
        ( cd "$BUILD/$PHPDIR" \
          && export CPPFLAGS="-I$OPT/include" \
          && export LDFLAGS="-L$OPT/lib -L$OPT/lib64" \
          && ./buildconf --force >/dev/null \
          && ./configure --prefix="$OPT/php" \
               --disable-all --enable-cli --disable-cgi --disable-phpdbg \
               --enable-pdo --with-pdo-pgsql="$PG_PREFIX" --with-pgsql="$PG_PREFIX" \
               --with-zlib="$OPT" --with-openssl="$OPT" --with-libxml="$OPT" --with-curl="$OPT" \
               --enable-dom --enable-xml --enable-simplexml --enable-xmlwriter --enable-xmlreader \
               --enable-mbstring --disable-mbregex \
               --enable-session --enable-ctype --enable-tokenizer --enable-filter \
               --enable-fileinfo --enable-calendar --enable-bcmath --enable-sockets \
               --enable-pcntl --enable-opcache --enable-phar --with-iconv >/dev/null \
          && make -j2 >/dev/null && make install >/dev/null )
        # opcache собран как zend-extension
        cat > "$OPT/php/lib/php.ini" <<EOF
; N2J sandbox (dev)
memory_limit = 512M
post_max_size = 32M
upload_max_filesize = 32M
max_execution_time = 120
date.timezone = Europe/Moscow
openssl.cafile = /etc/ssl/certs/ca-certificates.crt
curl.cainfo = /etc/ssl/certs/ca-certificates.crt
display_errors = 1
error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED
zend_extension = opcache.so
EOF
        sudo ln -sf "$OPT/php/bin/php" /usr/local/bin/php 2>/dev/null || ln -sf "$OPT/php/bin/php" "$OPT/bin/php"
        ok "PHP: $(php -r 'echo PHP_VERSION;') · ext: $(php -m | tr '\n' ' ' | cut -c1-140)..."
    fi
fi

# ---------------------------------------------------------------------------
# 2. PostgreSQL (pgserver, без root)
# ---------------------------------------------------------------------------
msg "PostgreSQL (PyPI pgserver): initdb/старт + роль и база n2j"
pip install -q --break-system-packages pgserver 2>/dev/null || pip install -q pgserver
# Нюансы pgserver 0.1.4:
#   - get_server(cleanup_mode='stop') по умолчанию убивает сервер при выходе
#     python (atexit) — нужен cleanup_mode=None;
#   - ensure_postgres_running() слушает только unix-сокет, TCP нет — поэтому
#     старт через pg_ctl -o "-h 127.0.0.1 -p 5432".
python3 - <<'PY'
import os
import subprocess
from pathlib import Path
import pgserver

pgdata = Path(os.environ.get("N2J_PGDATA", Path.home()/".n2j/pgdata"))
pgdata.parent.mkdir(parents=True, exist_ok=True)
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
srv.ensure_pgdata_inited()                 # initdb при необходимости, без старта

tcp_ok = subprocess.run([str(pgbin/"psql"), "-h", "127.0.0.1", "-p", "5432",
                         "-U", "postgres", "-d", "postgres", "-tAc", "SELECT 1"],
                        capture_output=True).returncode == 0
if pg_ctl("status").returncode != 0:
    pg_ctl("-l", str(pgdata/"pg.log"), "-o", PGOPTS, "-w", "start")
elif not tcp_ok:                           # жив, но только на сокете — рестарт с TCP
    pg_ctl("-m", "fast", "stop")
    pg_ctl("-l", str(pgdata/"pg.log"), "-o", PGOPTS, "-w", "start")

if psql("SELECT 1 FROM pg_roles WHERE rolname='n2j'") != "1":
    psql("CREATE ROLE n2j LOGIN PASSWORD 'n2j' CREATEDB;")
if psql("SELECT 1 FROM pg_database WHERE datname='n2j'") != "1":
    psql("CREATE DATABASE n2j OWNER n2j")
print("PostgreSQL TCP 127.0.0.1:5432 готов (данные: %s)" % pgdata)
PY
ok "PostgreSQL поднят"

# ---------------------------------------------------------------------------
# 3. Приложение: .env, vendor-заглушка, миграции, админ
# ---------------------------------------------------------------------------
[[ -f .env ]] || bash bin/setup.sh
msg "Миграции"
bash bin/console db:migrate | tail -1
msg "Админ из .env (ADMIN_*)"
bash bin/console auth:user --email="${ADMIN_EMAIL:-admin@n2j.ru}" \
    --password="${ADMIN_PASSWORD:-admin123}" \
    --username="${ADMIN_USERNAME:-admin}" --admin || true

msg "Дымовые тесты (мини-раннер, phpunit в закрытой сети недоступен)"
php tests/mini_runner.php | tail -1

if [[ $SERVE -eq 1 ]]; then
    PORT="${PORT:-8080}" bash bin/serve
else
    echo
    msg "Среда готова. Запуск сайта: PORT=8080 bash bin/serve (или bash bin/setup-sandbox.sh --serve)"
fi
