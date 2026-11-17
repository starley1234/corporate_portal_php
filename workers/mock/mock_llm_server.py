#!/usr/bin/env python3
"""Мок LLM-шлюза для разработки без GPU (OpenAI-совместимый).

   python3 workers/mock/mock_llm_server.py              # :1234

POST /v1/chat/completions  {"messages": [...], "model": "..."}
  → OpenAI-совместимый ответ. Ответ детерминированный: пересказывает
    последнее сообщение — достаточно для сквозных тестов агентов.

Переменные окружения (кому какой ответ отдавать):
  MOCK_LLM_REPLY_FILE   — один ответ на всё;
  MOCK_LLM_REPLY_FILES  — очередь ответов (проход 1, проход 2, …);
  MOCK_LLM_ROUTES       — «файл=подстрока;…»: если подстрока (без учёта
                          регистра) есть в промпте, ответ берётся из файла.
                          Нужна, когда в одном моке живут РАЗНЫЕ каналы.
                          В файле-шаблоне `%NAME%` заменяется названием
                          вещества из строки «Вещество: …» промпта.
"""

import json
import os
import re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

PORT = int(os.environ.get("MOCK_PORT", "1234"))
MODEL = os.environ.get("MOCK_MODEL", "mock-llm")
# MOCK_LLM_REPLY_FILE=/path/reply.txt — отвечать содержимым файла verbatim
# (для строгих JSON-схем конвейеров: prx598/prx591/prx592/prx602 в оффлайн-е2е).
REPLY_FILE = os.environ.get("MOCK_LLM_REPLY_FILE", "")
# MOCK_LLM_REPLY_FILES=/a.json,/b.json — ОЧЕРЕДЬ ответов: первый запрос получает
# a.json, второй b.json, дальше повторяется последний. Нужна каналам, которые
# ходят к модели несколько раз подряд: у prx646.label это двухпроходный разбор
# (проход 1 — строки этикетки, проход 2 — состав из них). С одним REPLY_FILE
# такой прогон в песочнице не проверить: обе просьбы получили бы один ответ.
REPLY_FILES = [p.strip() for p in os.environ.get("MOCK_LLM_REPLY_FILES", "").split(",") if p.strip()]
# MOCK_LLM_ROUTES=/a.json,признак;… — ответ ПО СОДЕРЖАНИЮ промпта. Нужен, когда
# в одном процессе мока живут разные каналы воркера: очередь REPLY_FILES общая,
# и запрос «изучи вещество» мог получить файл, заготовленный для разбора фото
# (03.10.2026 этап 7: prx646.label и prx646.study ходят в один мок). Формат пары —
# «файл=подстрока», подстрока ищется без учёта регистра в тексте всех сообщений.
ROUTES = []
for _pair in os.environ.get("MOCK_LLM_ROUTES", "").split(";"):
    if "=" in _pair:
        _file, _needle = _pair.split("=", 1)
        if _file.strip() and _needle.strip():
            ROUTES.append((_file.strip(), _needle.strip().lower()))
_reply_seq = {"n": 0}


def _substance_name(prompt_text: str) -> str:
    """Название вещества из строки «Вещество: …» (канал prx646.study)."""
    m = re.search(r"(?mi)^\s*вещество:\s*(.+?)\s*$", prompt_text)

    return m.group(1) if m else ""


def _route_reply(prompt_text: str) -> str:
    """Ответ по подсказке в промпте (MOCK_LLM_ROUTES) — до очереди файлов.

    В файле-шаблоне подстановки `%NAME%` (название вещества из промпта) —
    без них мок отвечает карточкой с чужим названием, и сквозной прогон
    «изучили → вещество узнали» не сходится: сайт привязывает карточку к
    незнакомому веществу по имени.
    """
    haystack = prompt_text.lower()

    for path, needle in ROUTES:
        if needle in haystack:
            try:
                text = Path(path).read_text(encoding="utf-8")
            except OSError as e:
                return f"[MOCK-LLM] route file unreadable: {e}"

            return text.replace("%NAME%", _substance_name(prompt_text))

    return ""


def _next_reply() -> str:
    """Ответ мока на очередной запрос: ROUTES, затем очередь REPLY_FILES, иначе REPLY_FILE."""
    if ROUTES and _last_prompt:
        routed = _route_reply(_last_prompt)

        if routed:
            return routed

    if REPLY_FILES:
        i = min(_reply_seq["n"], len(REPLY_FILES) - 1)
        _reply_seq["n"] += 1
        try:
            return Path(REPLY_FILES[i]).read_text(encoding="utf-8")
        except OSError as e:
            return f"[MOCK-LLM] reply file unreadable: {e}"

    if REPLY_FILE:
        try:
            return Path(REPLY_FILE).read_text(encoding="utf-8")
        except OSError as e:
            return f"[MOCK-LLM] reply file unreadable: {e}"

    return ""


def _content_text(content) -> str:
    """Текст сообщения, в том числе МУЛЬТИМОДАЛЬНОГО (vision-каналы).

    У OpenAI-совместимых запросов с картинкой content — это СПИСОК частей
    ([{"type": "text", ...}, {"type": "image_url", ...}]). Раньше мок брал
    content как строку и падал на `hash(list)`: `TypeError: unhashable type:
    'list'` — то есть ЛЮБОЙ прогон фото (prx639/prx640/prx646) в песочнице
    заканчивался «Server disconnected without sending a response». Список
    разбираем: текст склеиваем, картинку помечаем, чтобы ответ было видно.
    """
    if isinstance(content, str):
        return content

    if not isinstance(content, list):
        return ""

    parts = []

    for part in content:
        if not isinstance(part, dict):
            continue

        kind = part.get("type")

        if kind in ("text", "input_text"):
            parts.append(str(part.get("text") or ""))
        elif kind in ("image_url", "input_image"):
            url = part.get("image_url") or {}
            size = len(str(url.get("url") if isinstance(url, dict) else url))
            parts.append(f"[картинка, base64 {size} знаков]")

    return " ".join(p for p in parts if p)


# Текст последнего запроса целиком (system + user) — для MOCK_LLM_ROUTES.
_last_prompt = ""


def _prompt_text(messages: list) -> str:
    """Все сообщения запроса одним текстом: системный промпт — лучший признак канала."""
    return "\n".join(_content_text(m.get("content", "")) for m in messages if isinstance(m, dict))


def _last_user_text(messages: list) -> str:
    """Текст последнего сообщения пользователя (см. _content_text)."""
    for m in reversed(messages):
        if isinstance(m, dict) and m.get("role") == "user":
            return _content_text(m.get("content", ""))

    return ""


class Handler(BaseHTTPRequestHandler):
    def _send(self, code: int, payload: dict):
        body = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.endswith("/health"):
            self._send(200, {"ok": True, "mock": "llm", "model": MODEL})
        elif self.path.endswith("/models"):
            self._send(200, {"object": "list", "data": [{"id": MODEL, "object": "model"}]})
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self):
        if not self.path.endswith("/chat/completions"):
            return self._send(404, {"error": "not found"})
        length = int(self.headers.get("Content-Length", 0))
        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return self._send(400, {"error": "bad json"})

        messages = payload.get("messages", [])
        global _last_prompt
        _last_prompt = _prompt_text(messages)
        reply = (
            f"[MOCK-LLM] Принял: «{_last_user_text(messages)[:200]}». "
            "Это детерминированный тестовый ответ — в бою замените TOOLFORGE_API_URL на настоящий шлюз."
        )
        queued = _next_reply()

        if queued:
            reply = queued

        # Токены считаем по тексту частей: у vision-сообщений content — список.
        tokens = sum(len(_content_text(m.get("content", ""))) for m in messages) // 4

        self._send(200, {
            "id": "mockchat-" + str(abs(hash(_last_user_text(messages))) % 10**8),
            "object": "chat.completion",
            "model": payload.get("model", MODEL),
            "choices": [{
                "index": 0,
                "message": {"role": "assistant", "content": reply},
                "finish_reason": "stop",
            }],
            "usage": {"prompt_tokens": tokens, "completion_tokens": len(reply) // 4, "total_tokens": tokens + len(reply) // 4},
        })

    def log_message(self, fmt, *args):
        print("[mock-llm]", fmt % args)


if __name__ == "__main__":
    print(f"mock LLM server: http://0.0.0.0:{PORT}/v1/chat/completions")
    # ThreadingHTTPServer: клиенты могут рвать соединения — сервер обязан выжить
    ThreadingHTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
