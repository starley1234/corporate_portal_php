#!/usr/bin/env bash
# Проверка MCP-эндпоинта MCP Hub «снаружи» — так, как это делает агент.
#
#   bash .tools/mcphub_smoke.sh                       # боевой https://n2j.ru
#   bash .tools/mcphub_smoke.sh http://127.0.0.1:8080  # локальный стенд
#   MCPHUB_TOKEN=... bash .tools/mcphub_smoke.sh       # с ключом (инструменты N2J)
#
# Скрипт ничего не меняет: initialize → tools/list → hub_status →
# hub_search_tools → (необязательно) hub_call_tool для удалённого сервера без
# ключа. На каждом шаге проверяется JSON-RPC конверт, а не только HTTP-код:
# 200 с error внутри — это провал, и молча его пропускать нельзя.
#
# Отдельно проверяется гигиена транспорта из отчёта 24.10.2026: ошибка разбора
# и батчи дают 400, ответ несёт Mcp-Session-Id, но не ставит браузерных cookie,
# а GET-поток не присылает list_changed на каждое подключение.
set -uo pipefail

BASE="${1:-https://n2j.ru}"
BASE="${BASE%/}"
URL="$BASE/mcphub/mcp"
TOKEN="${MCPHUB_TOKEN:-}"
fails=0
HDR="$(mktemp)"
trap 'rm -f "$HDR"' EXIT

say()  { printf '%s\n' "$*"; }
ok()   { printf '  ok   %s\n' "$*"; }
bad()  { printf '  FAIL %s\n' "$*"; fails=$((fails + 1)); }

rpc() { # rpc <json>
    local body="$1"
    if [ -n "$TOKEN" ]; then
        curl -sS -D "$HDR" --max-time 45 -X POST "$URL" \
            -H 'Content-Type: application/json' \
            -H "Authorization: Bearer $TOKEN" \
            --data "$body"
    else
        curl -sS -D "$HDR" --max-time 45 -X POST "$URL" \
            -H 'Content-Type: application/json' \
            --data "$body"
    fi
}

# jget <json> <python-выражение над d>
jget() {
    python3 -c 'import json,sys
try:
    d = json.loads(sys.stdin.read())
except Exception:
    print(""); raise SystemExit
print(eval(sys.argv[1]))' "$2" <<<"$1" 2>/dev/null
}

header() { # header <имя> — значение из последнего ответа (регистр не важен)
    grep -i "^$1:" "$HDR" 2>/dev/null | tail -1 | cut -d: -f2- | tr -d '\r' | sed 's/^ *//'
}

say "MCP Hub · проверка эндпоинта"
say "  адрес: $URL"
say "  ключ:  ${TOKEN:+есть}${TOKEN:-нет (анонимный режим)}"
say

say "1. initialize"
out="$(rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"mcphub-smoke","version":"1.0"}}}')"
name="$(jget "$out" 'd.get("result",{}).get("serverInfo",{}).get("name","")')"
proto="$(jget "$out" 'd.get("result",{}).get("protocolVersion","")')"
[ -n "$name" ] && ok "сервер: $name, протокол: $proto" || bad "нет ответа initialize: $(printf '%.200s' "$out")"

session="$(header 'Mcp-Session-Id')"
[ -n "$session" ] && ok "Mcp-Session-Id: ${session:0:20}…" || bad "нет заголовка Mcp-Session-Id"

if grep -qi '^Set-Cookie:' "$HDR"; then
    bad "эндпоинт ставит cookie при Access-Control-Allow-Origin: *"
else
    ok "cookie не ставится (заголовок Allow-Origin: *)"
fi

say "2. tools/list"
out="$(rpc '{"jsonrpc":"2.0","id":2,"method":"tools/list"}')"
tools="$(jget "$out" '",".join(t["name"] for t in d.get("result",{}).get("tools",[]))')"
case "$tools" in
    *hub_search_tools*hub_ready*hub_call_tool*hub_status*) ok "инструменты: $tools" ;;
    *) bad "неожиданный список инструментов: ${tools:-пусто}" ;;
esac

say "3. hub_status"
out="$(rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"hub_status","arguments":{}}}')"
txt="$(jget "$out" 'd.get("result",{}).get("content",[{}])[0].get("text","")')"
if [ -n "$txt" ]; then
    ok "$(printf '%.160s' "$(printf '%s' "$txt" | tr '\n' ' ')")"
else
    bad "hub_status без текста: $(printf '%.200s' "$out")"
fi

say "4. hub_search_tools «погода»"
out="$(rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"hub_search_tools","arguments":{"query":"погода","limit":3}}}')"
txt="$(jget "$out" 'd.get("result",{}).get("content",[{}])[0].get("text","")')"
servers="$(jget "$out" '" ".join(s.get("slug","") for s in d.get("result",{}).get("structuredContent",{}).get("servers",[]))')"
[ -n "$txt" ] && ok "найдено: ${servers:-см. текст}" || bad "поиск ничего не вернул"

why="$(jget "$out" 'd.get("result",{}).get("structuredContent",{}).get("servers",[{}])[0].get("why","НЕТ")')"
[ "$why" = "НЕТ" ] && ok "why не приходит без explain" || bad "why пришёл без explain: $why"

say "5. notifications/initialized"
code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -X POST "$URL" \
    -H 'Content-Type: application/json' \
    ${TOKEN:+-H "Authorization: Bearer $TOKEN"} \
    --data '{"jsonrpc":"2.0","method":"notifications/initialized"}')"
[ "$code" = "202" ] && ok "202 Accepted" || bad "ожидался 202, получен $code"

say "6. ошибка разбора → 400"
code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -X POST "$URL" \
    -H 'Content-Type: application/json' \
    ${TOKEN:+-H "Authorization: Bearer $TOKEN"} \
    --data '{не json')"
[ "$code" = "400" ] && ok "400 Bad Request" || bad "ожидался 400, получен $code"

say "7. батч → 400"
code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -X POST "$URL" \
    -H 'Content-Type: application/json' \
    ${TOKEN:+-H "Authorization: Bearer $TOKEN"} \
    --data '[{"jsonrpc":"2.0","id":1,"method":"ping"}]')"
[ "$code" = "400" ] && ok "400 (батчи удалены из 2025-06-18)" || bad "ожидался 400, получен $code"

say "8. GET на эндпоинт без Accept: text/event-stream"
code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$URL")"
[ "$code" = "405" ] && ok "405 (POST обязателен) " || bad "ожидался 405, получен $code"

say "9. GET-поток SSE"
stream="$(curl -sS -N --max-time 10 -H 'Accept: text/event-stream' "$URL" 2>/dev/null | head -c 400)"
case "$stream" in
    *notifications/tools/list_changed*)
        ok "SSE отдаёт list_changed (каталог менялся с прошлого подключения)" ;;
    *retry:*|*:*)
        ok "SSE живой, list_changed не нужен: каталог не менялся" ;;
    *) bad "в потоке нет SSE: $(printf '%.120s' "${stream:-пусто}")" ;;
esac

say "10. DELETE (закрытие сессии)"
code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -X DELETE "$URL")"
[ "$code" = "204" ] && ok "204 No Content" || bad "ожидался 204, получен $code"

say
if [ "$fails" -eq 0 ]; then
    say "Эндпоинт в порядке."
    say
    say "Подключение в клиенте (Claude Desktop / Cursor / Windsurf):"
    say '  {"mcpServers": {"n2j-mcphub": {"type": "http", "url": "'"$URL"'"}}}'
else
    say "Провалено проверок: $fails"
    exit 1
fi
