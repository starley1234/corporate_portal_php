#!/usr/bin/env python3
"""Локальный MCP-сервер для проверки шлюза n2j (НЕ для продакшена).

Зачем: в закрытой песочнице нет выхода в интернет, поэтому внешние MCP
(погода, калькулятор) недостижимы. Этот стенд отвечает по протоколу
MCP Streamable HTTP ровно так, как это делают настоящие серверы:
initialize → notifications/initialized → tools/list → tools/call,
заголовок Mcp-Session-Id, ответ может быть JSON или SSE.

Запуск:
    python3 .tools/nj676_mcp_stub.py 8765

Проверка шлюза (сайт должен быть в development, см. NJ676_ALLOW_LOCAL_PROBE):
    curl -s -X POST http://127.0.0.1:8080/mcphub/mcp -H 'Content-Type: application/json' \\
      -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":
           {"name":"hub_call_tool","arguments":{"server":"stub-weather","tool":"get_weather",
            "arguments":{"city":"Москва"}}}}'
"""

from __future__ import annotations

import json
import math
import sys
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

TOOLS = [
    {
        "name": "get_weather",
        "description": "Погода в городе на сейчас (стенд отвечает вымышленными, но правдоподобными числами).",
        "inputSchema": {
            "type": "object",
            "properties": {"city": {"type": "string", "description": "Название города"}},
            "required": ["city"],
        },
    },
    {
        "name": "calculate",
        "description": "Вычислить арифметическое выражение, например 2^10 + sqrt(144).",
        "inputSchema": {
            "type": "object",
            "properties": {"expression": {"type": "string", "description": "Выражение"}},
            "required": ["expression"],
        },
    },
]

SESSIONS: set[str] = set()


def _weather(city: str) -> dict:
    seed = sum(ord(ch) for ch in city) or 7
    return {
        "city": city,
        "temperature_c": round(-5 + seed % 30, 1),
        "conditions": ["ясно", "облачно", "дождь", "снег"][seed % 4],
        "wind_mps": round(1 + seed % 7, 1),
        "source": "stub",
    }


def _calculate(expression: str) -> dict:
    allowed = {name: getattr(math, name) for name in dir(math) if not name.startswith("_")}
    allowed.update({"abs": abs, "round": round, "min": min, "max": max, "sum": sum, "pow": pow})

    value = eval(expression.replace("^", "**"), {"__builtins__": {}}, allowed)  # noqa: S307 — стенд

    return {"expression": expression, "result": value}


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):  # тише в тестах
        if "--verbose" in sys.argv:
            super().log_message(fmt, *args)

    def _read_body(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(length) if length else b"{}"
        try:
            return json.loads(raw or b"{}")
        except ValueError:
            return {}

    def _send(self, status: int, payload: dict | None, session: str = "", sse: bool = False) -> None:
        body = b"" if payload is None else json.dumps(payload, ensure_ascii=False).encode()
        if sse and payload is not None:
            body = b"event: message\ndata: " + body + b"\n\n"

        self.send_response(status)
        self.send_header("Content-Type", "text/event-stream" if sse else "application/json")
        if session:
            self.send_header("Mcp-Session-Id", session)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):  # noqa: N802
        self._send(405, {"error": "только POST"})

    def do_DELETE(self):  # noqa: N802
        SESSIONS.discard(str(self.headers.get("Mcp-Session-Id") or ""))
        self._send(200, None)

    def do_POST(self):  # noqa: N802
        body = self._read_body()
        method = str(body.get("method") or "")
        rpc_id = body.get("id")
        session = str(self.headers.get("Mcp-Session-Id") or "")

        if method == "initialize":
            session = session or uuid.uuid4().hex
            SESSIONS.add(session)
            self._send(200, {
                "jsonrpc": "2.0",
                "id": rpc_id,
                "result": {
                    "protocolVersion": "2025-06-18",
                    "capabilities": {"tools": {"listChanged": False}},
                    "serverInfo": {"name": "nj676-stub", "version": "1.0"},
                },
            }, session=session)
            return

        if method.startswith("notifications/"):
            self._send(202, None)
            return

        if not session or session not in SESSIONS:
            self._send(400, {"jsonrpc": "2.0", "id": rpc_id,
                             "error": {"code": -32600, "message": "нужен Mcp-Session-Id"}})
            return

        if method == "tools/list":
            # Отвечаем SSE — так делают многие хостинги, и клиент обязан это понять.
            self._send(200, {"jsonrpc": "2.0", "id": rpc_id, "result": {"tools": TOOLS}},
                       session=session, sse=True)
            return

        if method == "tools/call":
            params = body.get("params") or {}
            name = params.get("name")
            arguments = params.get("arguments") or {}

            try:
                if name == "get_weather":
                    payload = _weather(str(arguments.get("city") or "Москва"))
                elif name == "calculate":
                    payload = _calculate(str(arguments.get("expression") or "2+2"))
                else:
                    self._send(200, {"jsonrpc": "2.0", "id": rpc_id, "error": {
                        "code": -32602, "message": f"неизвестный инструмент {name}"}}, session=session)
                    return

                self._send(200, {
                    "jsonrpc": "2.0",
                    "id": rpc_id,
                    "result": {"content": [{"type": "text", "text": json.dumps(payload, ensure_ascii=False)}]},
                }, session=session)
            except Exception as exc:  # noqa: BLE001 — стенд
                self._send(200, {"jsonrpc": "2.0", "id": rpc_id, "result": {
                    "content": [{"type": "text", "text": f"ошибка: {exc}"}], "isError": True}},
                    session=session)
            return

        self._send(200, {"jsonrpc": "2.0", "id": rpc_id, "error": {
            "code": -32601, "message": f"метод не найден: {method}"}}, session=session)


def main() -> None:
    port = int(sys.argv[1]) if len(sys.argv) > 1 and sys.argv[1].isdigit() else 8765
    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    print(f"nj676 stub MCP: http://127.0.0.1:{port}/mcp", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
