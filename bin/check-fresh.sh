#!/usr/bin/env bash
# =============================================================================
# N2J — регрессия «с нуля» (fresh-install check).
#
#   bash bin/check-fresh.sh            # полная проверка на временной БД
#   bash bin/check-fresh.sh --keep     # не удалять временную БД (разбор полётов)
#
# Что делает:
#   1. Создаёт одноразовую БД n2j_check_<метка> в том же PostgreSQL;
#   2. Прогоняет ВСЕ миграции с нуля (порядок модулей, idempotentность);
#   3. Прогоняет тесты (bin/test) против этой БД;
#   4. Поднимает сайт на :8099 и делает HTTP-дым по ключевым страницам,
#      включая страницы-замки и их API (см. ниже);
#   5. Повторный db:migrate (должно быть «применено 0»);
#   6. Удаляет временную БД (--keep оставляет для разбора).
#
# Зачем: почти все исторические баги среды ловились именно свежим
# разворачиванием (порядок миграций erp208<nj120, отравленные токены .env,
# BOOLEAN 't'/'f', SQL в сервисах, завязанный на runtime-DDL). Держать в cron:
#   0 4 * * * cd /path/to/n2j.ru && bash bin/check-fresh.sh >> deploy/check-fresh.log 2>&1
# =============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
KEEP=0; [[ "${1:-}" == "--keep" ]] && KEEP=1

# --- окружение и PHP ---------------------------------------------------------
# shellcheck source=bin/_env.sh
source "$(dirname "${BASH_SOURCE[0]}")/_env.sh"
n2j_load_env .env
: "${DB_DEFAULT_HOST:=127.0.0.1}"; : "${DB_DEFAULT_PORT:=5432}"
: "${DB_DEFAULT_USER:=n2j}";       : "${DB_DEFAULT_PASS:=}"

PHP_BIN="$(n2j_php_bin)" || { echo "ERROR: PHP не найден (bin/restore-env.sh)" >&2; exit 1; }

# --- psql: системный или из pgserver ----------------------------------------
PSQL="$(command -v psql || true)"
if [[ -z "$PSQL" ]]; then
    PSQL="$(python3 -c 'import pgserver,os;print(os.path.join(os.path.dirname(pgserver.__file__),"pginstall","bin","psql"))' 2>/dev/null || true)"
fi
[[ -n "$PSQL" ]] || { echo "ERROR: psql не найден (нужен PostgreSQL-клиент)" >&2; exit 1; }

export PGPASSWORD="${DB_DEFAULT_PASS}"

CHECK_DB="n2j_check_$(date +%Y%m%d_%H%M%S)"
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

step "Миграции с нуля" "$PHP_BIN" "$ROOT/public_html/index.php" --task=db:migrate
printf '%s\n' "$LAST_STEP_OUT" | grep -q "Готово" \
    || { printf '%s\n' "$LAST_STEP_OUT" >&2; fail "миграции не завершились"; }

step "Миграции повторно (идемпотентность)" "$PHP_BIN" "$ROOT/public_html/index.php" --task=db:migrate
printf '%s\n' "$LAST_STEP_OUT" | grep -qE "применено 0" \
    || { printf '%s\n' "$LAST_STEP_OUT" >&2; fail "повторный прогон что-то применяет — миграции не идемпотентны"; }

step "Тесты против чистой БД" bash bin/test

echo "==> HTTP-дым (:8099)"
"$PHP_BIN" -S 127.0.0.1:8099 -t "$ROOT/public_html" "$ROOT/bin/router.php" >/dev/null 2>&1 &
HTTP_PID=$!
trap 'kill $HTTP_PID 2>/dev/null || true; [[ $KEEP -eq 1 ]] || drop_db || true' EXIT
for _ in $(seq 1 20); do curl -s -o /dev/null "http://127.0.0.1:8099/vat" && break; sleep 0.3; done

# Набор страниц подобран так, чтобы ловить поломки НЕ в одном модуле:
#   /              — главная: карточки сервисов из манифестов;
#   /services      — каталог: группы, семейства доменов, свёртка «Ещё N →»;
#   /pdf           — глава семейства pdf (28.09: четыре номера = один раздел);
#   /cad           — самый большой сервис-класс проекта (2 635 строк);
#   /roman         — типовая dev-утилита;
#   /qr            — публичный генератор: подключение нового модуля и его вьюха;
#   /vat /content  — витринные страницы с расчётом и списком;
#   /units /cooking /cooking/wheat-flour /size — бытовые конвертеры и SEO-страница;
#   /date-words /translit /inn — документы и безопасная публичная проверка ИНН;
#   /s/company /s/docs /s/meri /s/daty — посадочные B2B, документов, мер и календаря;
#   /llmlab /label — новые сервисы с ИИ и своей базой знаний (02.10.2026):
#   /dialog/list   — каталог опубликованных разговоров двух ИИ (nj662, 08.10.2026):
#                    публичная витрина личного сервиса;
#   /sitemap.xml   — генерация вне шаблона;
#   /auth/login    — страница без своего меню (с 28.09 меню ей даёт база).
# Любая из них рендерится через Controller_Erp100_Base, поэтому дым заодно
# проверяет общий шаблон, меню и каталог — то, что чинили 28.09.2026.
for path in / /services /pdf /cad /roman /vat /qr /content \
            /units /cooking /cooking/wheat-flour /size /date-words /translit /inn \
            /llmlab /label /dialog/list /mcphub \
            /s/company /s/docs /s/meri /s/daty /sitemap.xml /auth/login; do
    code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8099$path")"
    [[ "$code" == "200" ]] || fail "GET $path → $code (ожидался 200)"
    echo "    GET $path → 200"
done

#   Замки 08.10.2026 (AGENTS.md §24): восемь страниц — «только по аккаунту»,
#   девять публичных получили гостевой экран. Гостю (куки нет) ВСЕ они отдают
#   200: страница сервиса с плашкой входа, а не редирект в никуда и не 500.
#   /dialog (nj662) — рабочий стол диалогов: обмен репликами тратит вызовы
#   модели и поиск в интернете, поэтому тоже по аккаунту.
#   Отдельно проверяем, что API закрытых сервисов отвечает 401 JSON — клиент
#   (JS страницы) умеет понять только JSON и уводит человека на /auth/login.
for path in /veclab /imagegen /qc /cameras /similar /review /scout /dossier \
            /vacancies /touches /watchdog /leads /company /coldmail /radar /docs /design \
            /dialog /embedbench /talk; do
    code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8099$path")"
    [[ "$code" == "200" ]] || fail "GET $path → $code (ожидался 200: страница с плашкой входа)"
    echo "    GET $path → 200 (гость: плашка входа)"
done

for path in /veclab/api/items /imagegen/api/runs /qc/api/status /cameras/api/status /similar/api/status \
            "/embedbench/api/status?id=1"; do
    body="$(curl -s -w ' %{http_code}' "http://127.0.0.1:8099$path")"
    code="${body##* }"
    [[ "$code" == "401" ]] || fail "GET $path → $code (ожидался 401 JSON: сервис только по аккаунту)"
    [[ "$body" == *'"error":"auth"'* ]] || fail "GET $path → не JSON-замок: $body"
    echo "    GET $path → 401 JSON (замок «только по аккаунту»)"
done

# Точки разговора (nj677) принимают только POST: гостю они обязаны ответить
# 401 JSON с адресом входа, иначе страница /talk не поймёт, что делать.
for path in /talk/api/hear /talk/api/reply /talk/api/say; do
    body="$(curl -s -X POST -H 'Content-Type: application/json' -d '{}' \
        -w ' %{http_code}' "http://127.0.0.1:8099$path")"
    code="${body##* }"
    [[ "$code" == "401" ]] || fail "POST $path → $code (ожидался 401 JSON: разговор только по аккаунту)"
    [[ "$body" == *'"error":"auth"'* ]] || fail "POST $path → не JSON-замок: $body"
    echo "    POST $path → 401 JSON (замок «только по аккаунту»)"
done

code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8099/nj517/api/lookup")"
[[ "$code" == "405" ]] || fail "GET /nj517/api/lookup → $code (ожидался 405)"
echo "    GET /nj517/api/lookup → 405"

# Каталог MCP (nj676): публичный JSON-поиск обязан отвечать JSON, а не HTML
# страницы (рендер шаблона поверх JSON уже случался в этом модуле).
body="$(curl -s -w ' %{http_code}' "http://127.0.0.1:8099/mcphub/api/search?q=search")"
code="${body##* }"
[[ "$code" == "200" ]] || fail "GET /mcphub/api/search → $code (ожидался 200)"
[[ "$body" == *'"ok":true'* ]] || fail "GET /mcphub/api/search → не JSON: ${body:0:120}"
echo "    GET /mcphub/api/search → 200 JSON"

# MCP-эндпоинт (nj676): tools/list обязан отдать ровно четыре инструмента.
tools="$(curl -s -X POST "http://127.0.0.1:8099/mcphub/mcp" \
    -H 'Content-Type: application/json' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')"
[[ "$tools" == *'"hub_search_tools"'* && "$tools" == *'"hub_call_tool"'* ]] \
    || fail "POST /mcphub/mcp tools/list → нет корневых инструментов: ${tools:0:160}"
[[ "$tools" == *'"hub_status"'* && "$tools" == *'"hub_configure"'* ]] \
    || fail "POST /mcphub/mcp tools/list → неполный набор инструментов: ${tools:0:160}"
echo "    POST /mcphub/mcp → tools/list: 4 инструмента"

# GET на MCP-эндпоинт — подсказка, а не HTML (405 + JSON).
info="$(curl -s -w ' %{http_code}' "http://127.0.0.1:8099/mcphub/mcp")"
[[ "${info##* }" == "405" ]] || fail "GET /mcphub/mcp → ${info##* } (ожидался 405)"
echo "    GET /mcphub/mcp → 405 + подсказка"

kill $HTTP_PID 2>/dev/null || true

echo
echo "✔ FRESH-чек пройден: миграции с нуля + идемпотентность + тесты + HTTP-дым"
[[ $KEEP -eq 1 ]] && echo "  (БД $CHECK_DB оставлена для разбора — не забудь удалить)"
exit 0
