#!/usr/bin/env python3
"""Мок эмбеддинг-сервера для разработки без GPU (OpenAI-совместимый).

   python3 workers/mock/mock_embed_server.py            # :1235
   MOCK_DIMS=1024 python3 workers/mock/mock_embed_server.py

POST /v1/embeddings  {"input": "текст" | ["т1", "т2"], "model": "..."}
  → {"data": [{"object": "embedding", "index": 0, "embedding": [...]}, ...]}
Векторы детерминированные (hash текста) — удобно для повторяемых тестов.
"""

import hashlib
import json
import os
import random
from http.server import BaseHTTPRequestHandler, HTTPServer

DIMS = int(os.environ.get("MOCK_DIMS", "64"))
PORT = int(os.environ.get("MOCK_PORT", "1235"))


def embed_one(text: str) -> list[float]:
    rng = random.Random(hashlib.md5(text.encode("utf-8")).hexdigest())
    vec = [rng.uniform(-1.0, 1.0) for _ in range(DIMS)]
    norm = sum(v * v for v in vec) ** 0.5 or 1.0
    return [round(v / norm, 7) for v in vec]


class Handler(BaseHTTPRequestHandler):
    def _send(self, code: int, payload: dict):
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.endswith("/health"):
            self._send(200, {"ok": True, "mock": "embeddings", "dims": DIMS})
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self):
        if not self.path.endswith("/embeddings"):
            return self._send(404, {"error": "not found"})
        length = int(self.headers.get("Content-Length", 0))
        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return self._send(400, {"error": "bad json"})

        raw = payload.get("input", "")
        texts = [raw] if isinstance(raw, str) else list(raw)
        data = [
            {"object": "embedding", "index": i, "embedding": embed_one(t)}
            for i, t in enumerate(texts)
        ]
        self._send(200, {
            "object": "list",
            "data": data,
            "model": payload.get("model", "mock-embedding"),
            "usage": {"prompt_tokens": sum(len(t) for t in texts), "total_tokens": 0},
        })

    def log_message(self, fmt, *args):
        print("[mock-embed]", fmt % args)


if __name__ == "__main__":
    print(f"mock embedding server: http://0.0.0.0:{PORT}/v1/embeddings (dims={DIMS})")
    HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
