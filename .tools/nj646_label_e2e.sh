#!/usr/bin/env bash
# =============================================================================
# nj646_label — сквозной прогон «фото этикетки → состав на сайте».
#
#   bash .tools/nj646_label_e2e.sh            # сайт на :8080
#   SITE=http://127.0.0.1:9090 bash .tools/nj646_label_e2e.sh
#
# Зачем: дефект 03.10.2026 жил РОВНО между «модель ответила» и «сайт показал» —
# юнит-тесты и логика разбора были зелёными, а приёмка результата молча падала
# с 500. Такой путь проверяется только сквозняком, поэтому здесь он собран
# целиком: постановка фото → опрос статуса → строки nj_646_runs и nj_120_jobs.
#
# Что должно быть поднято (см. docs/SANDBOX-SETUP.md):
#   1. сайт:      PORT=8080 bash bin/serve
#   2. мок модели:
#      * двухпроходный режим (по умолчанию у воркера): MOCK_LLM_REPLY_FILES="<строки>,<состав>" \
#          python3 workers/mock/mock_llm_server.py — первый запрос получит строки этикетки,
#          второй состав из них (см. REPLY_LINES/REPLY_COMPOSE ниже);
#      * одношаговый: MOCK_LLM_REPLY_FILE=<файл с JSON> ... (NJ646_TRANSCRIBE=0 у воркера).
#      Без файла ответов мок вернёт прозу, и канал честно уедет в ошибку разбора.
#   3. воркер:    с TOOLFORGE_API_URL на мок, каналом nj646.label и токеном
#                 WORKER_SITE_TOKEN = WORKER_API_TOKEN сайта (его пишет bin/setup.sh)
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SITE="${SITE:-http://127.0.0.1:8080}"
IMG="${IMG:-/tmp/nj646_label.jpg}"
REPLY="${REPLY:-/tmp/nj646_reply.json}"
# Проходы двухпроходного режима: строки этикетки → состав из них. Задаются
# через MOCK_LLM_REPLY_FILES; если очереди нет, мок отвечает одним REPLY.
REPLY_LINES="${REPLY_LINES:-/tmp/nj646_reply_lines.json}"
REPLY_COMPOSE="${REPLY_COMPOSE:-/tmp/nj646_reply_compose.json}"
COOKIE="${COOKIE:-/tmp/nj646_e2e_cookie.jar}"

env_value() { # env_value <файл> <ключ> — значение без inline-комментария
    local line
    line="$(grep -m1 -E "^$2=" "$1" 2>/dev/null || true)"
    line="${line#*=}"; line="${line%%#*}"
    line="${line#"${line%%[![:space:]]*}"}"; line="${line%"${line##*[![:space:]]}"}"
    printf '%s' "$line"
}

# psql: системный или из pgserver (закрытая песочница)
PSQL="$(command -v psql || true)"
if [[ -z "$PSQL" ]]; then
    PSQL="$(python3 -c 'import pgserver,os;print(os.path.join(os.path.dirname(pgserver.__file__),"pginstall","bin","psql"))' 2>/dev/null || true)"
fi
q() { "$PSQL" -h "${DB_HOST:-127.0.0.1}" -p "${DB_PORT:-5432}" -U "${DB_USER:-n2j}" \
         -d "${DB_NAME:-n2j}" -tAc "$1" 2>/dev/null | head -1 | tr -d ' '; }

# --- подготовка: картинка и «ответ модели» -----------------------------------
python3 - <<'PY'
from PIL import Image
Image.new("RGB", (900, 600), (250, 250, 250)).save("/tmp/nj646_label.jpg", "JPEG", quality=85)
PY
[[ -f "$REPLY" ]] || printf '%s' \
    '{"composition":"Состав: вода, сахар, соль, E211","kind":"food","confidence":88,"note":""}' > "$REPLY"

B64="$(base64 -w0 "$IMG")"
printf '{"image":"%s","kind":"auto"}' "$B64" > /tmp/nj646_e2e_body.json

echo "== сайт: $SITE · фото: $(stat -c%s "$IMG") байт · токен сайта: $(env_value .env WORKER_API_TOKEN | cut -c1-8)…"

# --- 1. постановка задачи ------------------------------------------------------
RESP="$(curl -sS -c "$COOKIE" --max-time 60 -X POST -H 'Content-Type: application/json' \
    --data-binary @/tmp/nj646_e2e_body.json "$SITE/label/api/photo")"
echo "== POST /label/api/photo → $RESP"

RUN_ID="$(python3 -c 'import json,sys;print(json.loads(sys.stdin.read()).get("run_id",""))' <<<"$RESP" 2>/dev/null)"
if [[ -z "$RUN_ID" ]]; then
    echo "!! фото не принято — проверьте лимиты (NJ646_PHOTO_PER_HOUR) и готовность сайта"
    exit 1
fi

# --- 2. опрос статуса ----------------------------------------------------------
echo "== опрос /label/api/status"
for i in $(seq 1 24); do
    ST="$(curl -sS -b "$COOKIE" --max-time 30 -X POST -H 'Content-Type: application/json' \
        -d "{\"id\": $RUN_ID}" "$SITE/label/api/status")"
    STATUS="$(python3 -c 'import json,sys;print(json.loads(sys.stdin.read()).get("status",""))' <<<"$ST" 2>/dev/null)"
    echo "   [$i] $STATUS"
    if [[ "$STATUS" != "queued" && "$STATUS" != "running" ]]; then
        echo "   $ST"
        break
    fi
    sleep 2.5
done

# --- 3. что в базе -------------------------------------------------------------
if [[ -n "$PSQL" ]]; then
    # note — пометка к составу («ответ оборвался — проверьте конец списка»):
    # её отсутствие в строке означало, что обрезанный состав выглядит полным.
    echo "== nj_646_runs: status | error | text | note | строк этикетки"
    q "SELECT id || ' | ' || status || ' | ' || coalesce(error,'') || ' | ' ||
              left(coalesce(result->>'text',''), 60) || ' | ' ||
              left(coalesce(result->>'note',''), 60) || ' | ' ||
              coalesce((length(result->>'transcript') - length(replace(result->>'transcript', chr(10), '')) + 1)::text, '—')
       FROM nj_646_runs WHERE id = $RUN_ID" | sed 's/^/   /'
    echo "== nj_120_jobs (run_id=$RUN_ID): status | attempts | delivered | error"
    q "SELECT id || ' | ' || status || ' | ' || attempts || ' | ' ||
              coalesce(result->>'delivered','—') || ' | ' || left(coalesce(error,''), 80)
       FROM nj_120_jobs WHERE payload->>'run_id' = '$RUN_ID' ORDER BY id DESC LIMIT 1" | sed 's/^/   /'
else
    echo "(psql не найден — строки БД не показать)"
fi

# --- 4. логи обеих сторон --------------------------------------------------------
# Владелец (03.10.2026): «не гадать, что произошло» — значит после прогона
# должно быть видно и на сайте, и на воркере. Файлы плоские и ротируемые.
SITE_LOG="${SITE_LOG:-application/logs/n2j-site.log}"
WORKER_LOG="${WORKER_LOG:-workers/logs/n2j-worker.log}"

show_log() { # show_log <файл> <подпись>
    if [[ -f "$1" ]]; then
        echo "== $2: $1 (последние строки про этот прогон)"
        # «прогон 12», «прогона 12» (приёмка), «run=12» (воркер) — по границе числа,
        # иначе grep цепляет чужие строки вроде config/nj508_calendar.php:252
        # (альтернация целыми словами, а не [ау]: grep в C-locale читает кириллицу
        # побайтово, и класс из одной буквы не совпадает)
        grep -E "(прогон|прогона|прогону) $RUN_ID([^0-9]|$)|run=$RUN_ID([^0-9]|$)" "$1" 2>/dev/null \
            | tail -6 | sed 's/^/   /' || true
        echo "   (всего строк: $(wc -l < "$1"))"
    else
        echo "!! $2: файла $1 нет — лог не пишется (см. N2J_LOG_*/WORKER_LOG_* в .env)"
    fi
}

show_log "$SITE_LOG" "лог сайта"
show_log "$WORKER_LOG" "лог воркера"
