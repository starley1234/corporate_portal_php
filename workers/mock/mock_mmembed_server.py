#!/usr/bin/env python3
"""Мок МУЛЬТИМОДАЛЬНОГО эмбеддинг-сервера: разработка без GPU и без LM Studio.

    python3 workers/mock/mock_mmembed_server.py                   # :1236
    MOCK_MM_DIALECT=oai_content python3 workers/mock/mock_mmembed_server.py
    MOCK_MM_DIMS=2048 MOCK_MM_PORT=1236 python3 workers/mock/mock_mmembed_server.py
    MOCK_MM_FAULT=no_models python3 workers/mock/mock_mmembed_server.py  # «сломанный» сервер

Отличие от `mock_embed_server.py`: тот принимает только строки. Этот понимает
все формы запроса, которые перебирает `n2j_workers/mmembed.py`, и — главное —
по переменной MOCK_MM_DIALECT делает вид, что понимает ТОЛЬКО ОДНУ из них
(остальным отвечает 400). Ровно так ведут себя настоящие серверы, и именно это
проверяет автоопределение диалекта.

MOCK_MM_FAULT (`no_models`, `no_token`, `bad_model`) играет вторую беду: сервер
отвечает одинаковым отказом на ЛЮБОЕ тело запроса. Так ведёт себя LM Studio без
загруженной модели, и клиент обязан сказать «дело не в формате запроса», а не
перебирать шесть диалектов впустую.

Векторы детерминированные: одинаковый вход — одинаковый вектор. Смысл в них
зашит грубо, «по словарю понятий»: тексты и картинки с одним понятием
(круг/квадрат, кот/диван) оказываются рядом. Этого хватает, чтобы страница
/veclab и `nj653_doctor.py` показали живой результат; за настоящим качеством —
к настоящей модели.
"""

from __future__ import annotations

import base64
import hashlib
import json
import math
import os
import random
import re
from http.server import BaseHTTPRequestHandler, HTTPServer

DIMS = int(os.environ.get("MOCK_MM_DIMS", "256"))
PORT = int(os.environ.get("MOCK_MM_PORT", "1236"))
#: Какой формат запроса этот «сервер» согласен понимать (пусто = любой).
DIALECT = (os.environ.get("MOCK_MM_DIALECT") or "").strip()

#: Сыграть «сломанный сервер»: MOCK_MM_FAULT=no_models | no_token | bad_model.
#: Нужен, чтобы проверить ДИАГНОЗ клиента: при одинаковом отказе на любое тело
#: он обязан сказать, что дело не в формате запроса (см. mmembed.SERVER_FAULTS).
FAULT = (os.environ.get("MOCK_MM_FAULT") or "").strip()

#: MOCK_MM_OMNI=1 — играть свой мультимодальный сервис (OmniEmbed): /healthz,
#: пачка чат-сообщений в /v1/embeddings и Cohere-вход /v2/embed.
OMNI = (os.environ.get("MOCK_MM_OMNI") or "").strip() not in ("", "0", "no", "false")

#: MOCK_MM_KEY=oes_… — требовать Authorization: Bearer, как настоящий сервис.
NEED_KEY = (os.environ.get("MOCK_MM_KEY") or "").strip()

FAULTS = {
    "no_models": (400, "No models loaded. Please load a model in the developer "
                       "page or use the 'lms load' command."),
    "no_token": (400, "An LM Studio API token is required to access this endpoint"),
    "bad_model": (404, "model_not_found: такой модели на сервере нет"),
    # «Модель не того типа»: ровно случай владельца — GGUF Qwen3-VL-Embedding
    # LM Studio числит как llm, и /v1/embeddings её не поднимает. Текстовые
    # запросы при этом обслуживаются текстовой эмбеддинг-моделью.
    "wrong_type": (400, "No models loaded. Please load a model in the developer "
                        "page or use the 'lms load' command."),
}

#: Что отдаёт родной REST LM Studio (`GET /api/v0/models`) — с типами.
TEXT_EMBED_MODEL = "text-embedding-qwen3-embedding-0.6b"

#: Понятия, по которым мок раскладывает входы рядом друг с другом.
CONCEPTS = ("круг", "квадрат", "красн", "син", "кот", "кошк", "диван", "налог", "деклараци")


def _axis(name: str) -> list[float]:
    """Устойчивое направление понятия в пространстве."""
    rng = random.Random(hashlib.md5(name.encode("utf-8")).hexdigest())

    return [rng.uniform(-1.0, 1.0) for _ in range(DIMS)]


def _normalize(vec: list[float]) -> list[float]:
    norm = math.sqrt(sum(v * v for v in vec)) or 1.0

    return [round(v / norm, 7) for v in vec]


def _image_concepts(raw_b64: str) -> list[str]:
    """Грубый «разбор картинки»: по среднему цвету и доле закрашенного.

    Настоящая модель смотрит на содержимое; моку достаточно отличить красный
    круг от синего квадрата — без Pillow, по пикселям PNG/JPEG мы этого не
    сделаем, поэтому опираемся на размер и хеш. Если Pillow есть — смотрим
    цвет честно.
    """
    found: list[str] = []

    try:
        import io

        from PIL import Image

        img = Image.open(io.BytesIO(base64.b64decode(raw_b64))).convert("RGB")
        small = img.resize((32, 32))
        pixels = [p for p in small.getdata() if sum(p) < 720]       # не белый фон

        if pixels:
            r = sum(p[0] for p in pixels) / len(pixels)
            g = sum(p[1] for p in pixels) / len(pixels)
            b = sum(p[2] for p in pixels) / len(pixels)

            if r > b + 30 and r > g + 30:
                found.append("красн")
            if b > r + 30 and b > g + 30:
                found.append("син")

            # Круг не достаёт до углов, квадрат достаёт.
            corners = [small.getpixel(xy) for xy in ((2, 2), (29, 2), (2, 29), (29, 29))]
            mid = small.getpixel((16, 16))
            filled_corner = sum(1 for c in corners if sum(c) < 720)

            if sum(mid) < 720:
                found.append("квадрат" if filled_corner >= 2 else "круг")
    except Exception:                                   # noqa: BLE001 — мок не падает
        pass

    return found


def embed_one(text: str, images: list[str]) -> list[float]:
    """Вектор входа: шум от содержимого + направления найденных понятий."""
    key = hashlib.md5((text + "|" + "".join(i[:64] for i in images)).encode()).hexdigest()
    rng = random.Random(key)
    vec = [rng.uniform(-0.25, 0.25) for _ in range(DIMS)]

    concepts = [c for c in CONCEPTS if c in text.lower()]

    for image in images:
        concepts += _image_concepts(image)

    for concept in concepts:
        axis = _axis(concept)
        vec = [v + a for v, a in zip(vec, axis)]

    return _normalize(vec)


# --------------------------------------------------------------------------
# Разбор всех известных форм запроса
# --------------------------------------------------------------------------

def _strip_data_url(value: str) -> str:
    return re.sub(r"^data:[^,]*,", "", str(value or ""))


def _from_parts(parts: list) -> tuple[str, list[str]]:
    text, images = [], []

    for part in parts:
        if not isinstance(part, dict):
            continue

        kind = part.get("type")

        if kind == "text":
            text.append(str(part.get("text") or ""))
        elif kind == "image_url":
            url = (part.get("image_url") or {}).get("url", "")
            images.append(_strip_data_url(url))

    return " ".join(t for t in text if t), images


def _from_messages(chat: list) -> tuple[str, list[str]]:
    """Список чат-сообщений → (текст, картинки).

    Считаем только user: system — инструкция (она не содержимое запроса), а
    пустой assistant в конце нужен лишь для pooling. Иначе одинаковая
    инструкция сделала бы все мок-векторы похожими.
    """
    texts: list[str] = []
    images: list[str] = []

    for message in chat or []:
        if (message or {}).get("role") not in (None, "user"):
            continue

        content = (message or {}).get("content")

        if isinstance(content, str):
            texts.append(content)
            continue

        text, imgs = _from_parts(list(content or []))

        if text:
            texts.append(text)

        images += imgs

    return " ".join(t for t in texts if t).strip(), images


def parse_request(payload: dict) -> tuple[str, list[tuple[str, list[str]]]]:
    """Тело запроса → (диалект, список входов). ValueError — форма не наша."""
    if isinstance(payload.get("inputs"), list) and payload["inputs"]:
        # Cohere Embed v2: inputs=[{content:[…]}] — пачкой.
        return "cohere_inputs", [_from_parts(list((i or {}).get("content") or []))
                                 for i in payload["inputs"]]

    if isinstance(payload.get("messages"), list) and payload["messages"]:
        head = payload["messages"][0]

        if isinstance(head, list):
            # Пачка: каждый элемент — свой список сообщений, свой вектор.
            return "omni_messages", [_from_messages(chat) for chat in payload["messages"]]

        return "chat_messages", [_from_parts(list(head.get("content") or []))]

    raw = payload.get("input")

    if isinstance(raw, dict) and "prompt_string" in raw:
        images = [_strip_data_url(i) for i in (raw.get("multimodal_data") or [])]

        return "media_prompt", [(str(raw.get("prompt_string") or "").replace("<__media__>", ""),
                                 images)]

    if isinstance(raw, str):
        return "plain_text", [(raw, [])]

    if isinstance(raw, list) and raw:
        head = raw[0]

        if isinstance(head, str):
            return "plain_text", [(item, []) for item in raw]

        if isinstance(head, dict) and "content" in head:
            return "oai_content", [_from_parts(list(i.get("content") or [])) for i in raw]

        if isinstance(head, dict) and head.get("type") in ("text", "image_url"):
            return "oai_parts", [_from_parts(raw)]

        if isinstance(head, dict) and ("text" in head or "image" in head):
            return "qwen_objects", [
                (" ".join(x for x in [str(i.get("instruction") or ""),
                                      str(i.get("text") or "")] if x).strip(),
                 [_strip_data_url(i["image"])] if i.get("image") else [])
                for i in raw
            ]

    raise ValueError("форма запроса не распознана")


class Handler(BaseHTTPRequestHandler):

    def _send(self, code: int, payload: dict) -> None:
        body = json.dumps(payload).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _unauthorized(self) -> bool:
        """Нет нужного Bearer — отвечаем так же, как настоящий сервис."""
        if not NEED_KEY:
            return False

        if (self.headers.get("Authorization") or "").strip() == f"Bearer {NEED_KEY}":
            return False

        self._send(401, {"error": {"message": "Invalid or missing API key",
                                   "type": "authentication_error",
                                   "param": None, "code": None}})

        return True

    def do_GET(self):                                   # noqa: N802 — имя из http.server
        if self._unauthorized():
            return

        if OMNI and self.path.endswith("/healthz"):
            self._send(200, {
                "status": "ok", "service": "OmniEmbed Service", "version": "1.0.0",
                "backend": "llama.cpp", "device": "cuda:0", "loaded": True,
                "on_device": True,
                "model": os.environ.get("MOCK_MM_MODEL", "qwen3-vl-embedding-2b"),
            })
        elif self.path.endswith("/health"):
            self._send(200, {"ok": True, "mock": "mm-embeddings",
                             "dims": DIMS, "dialect": DIALECT or "любой"})
        elif self.path.endswith("/api/v0/models"):
            mine = os.environ.get("MOCK_MM_MODEL", "qwen3-vl-embedding-2b-mock")
            self._send(200, {"object": "list", "data": [
                {"id": mine, "object": "model", "state": "not-loaded",
                 "arch": "qwen3vl", "compatibility_type": "gguf",
                 "type": "llm" if FAULT == "wrong_type" else "embeddings"},
                {"id": TEXT_EMBED_MODEL, "object": "model", "type": "embeddings",
                 "arch": "qwen3", "state": "not-loaded", "compatibility_type": "gguf"},
            ]})
        elif self.path.endswith("/models") and FAULT == "no_token":
            self._send(400, {"error": {"message": FAULTS[FAULT][1],
                                       "code": "invalid_api_key"}})
        elif self.path.endswith("/models"):
            self._send(200, {"object": "list", "data": [
                {"id": os.environ.get("MOCK_MM_MODEL", "qwen3-vl-embedding-2b-mock")},
                {"id": TEXT_EMBED_MODEL}]})
        else:
            self._send(404, {"error": "not found"})

    def do_POST(self):                                  # noqa: N802 — имя из http.server
        if self._unauthorized():
            return

        if OMNI and self.path.endswith("/v2/embed"):
            return self._embed(cohere=True)

        if self.path.endswith("/chat/completions"):
            return self._chat()

        if FAULT == "wrong_type":
            # Картинку не берём ни в каком виде (эмбеддинг-модель текстовая),
            # а строки обслуживаем как обычно — так же ведёт себя LM Studio.
            length = int(self.headers.get("Content-Length", 0))
            raw = self.rfile.read(length) or b"{}"

            try:
                payload = json.loads(raw)
                _, items = parse_request(payload)
            except (json.JSONDecodeError, ValueError):
                return self._send(*((FAULTS[FAULT][0], {"error": FAULTS[FAULT][1]})))

            if any(images for _, images in items):
                return self._send(FAULTS[FAULT][0], {"error": FAULTS[FAULT][1]})

            return self._send(200, {"object": "list", "model": TEXT_EMBED_MODEL, "data": [
                {"object": "embedding", "index": n, "embedding": embed_one(text, [])}
                for n, (text, _) in enumerate(items)]})

        if FAULT in FAULTS:
            code, text = FAULTS[FAULT]

            return self._send(code, {"error": text})

        if not self.path.endswith("/embeddings"):
            return self._send(404, {"error": "not found"})

        return self._embed()

    def _embed(self, cohere: bool = False) -> None:
        """Общий счёт векторов для /v1/embeddings и /v2/embed."""
        length = int(self.headers.get("Content-Length", 0))

        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return self._send(400, {"error": "bad json"})

        try:
            dialect, items = parse_request(payload)
        except ValueError as e:
            return self._send(400, {"error": str(e)})

        if DIALECT and dialect != DIALECT:
            return self._send(400, {"error": f"сервер понимает только формат {DIALECT}, "
                                             f"а пришёл {dialect}"})

        # Matryoshka: сервис умеет отдать вектор нужной ширины сам.
        width = int(payload.get("output_dimension") or payload.get("dimensions") or 0)
        vectors = [embed_one(text, images) for text, images in items]

        if 0 < width < DIMS:
            vectors = [_normalize(v[:width]) for v in vectors]

        if cohere:
            return self._send(200, {
                "id": "mock-cohere",
                "response_type": "embeddings_by_type",
                "embeddings": {"float": vectors},
                "meta": {"api_version": {"version": "2"},
                         "billed_units": {"input_tokens": 0, "image_tokens": 0}},
            })

        self._send(200, {
            "object": "list",
            "data": [{"object": "embedding", "index": n, "embedding": v}
                     for n, v in enumerate(vectors)],
            "model": payload.get("model") or "qwen3-vl-embedding-2b-mock",
            "usage": {"prompt_tokens": sum(len(t) for t, _ in items), "total_tokens": 0},
        })

    def _chat(self):
        """Минимальный /v1/chat/completions: описывает картинку словами.

        Нужен для режима описания (caption): воркер идёт к vision-модели тем
        же путём, что /meal, и мок обязан на этом пути отвечать.
        """
        length = int(self.headers.get("Content-Length", 0))

        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except json.JSONDecodeError:
            return self._send(400, {"error": "bad json"})

        images: list[str] = []

        for message in payload.get("messages") or []:
            content = message.get("content")

            if isinstance(content, list):
                for part in content:
                    url = ((part or {}).get("image_url") or {}).get("url", "")

                    if url.startswith("data:"):
                        images.append(url.split(",", 1)[-1])

        concepts: list[str] = []

        for image in images:
            concepts += _image_concepts(image)

        words = {"красн": "красный", "син": "синий", "круг": "круг", "квадрат": "квадрат"}
        said = " ".join(words.get(c, c) for c in concepts) or "непонятная картинка"

        self._send(200, {
            "id": "mock-chat", "object": "chat.completion",
            "model": payload.get("model") or "mock-vlm",
            "choices": [{"index": 0, "finish_reason": "stop",
                         "message": {"role": "assistant",
                                     "content": f"На изображении {said} на белом фоне."}}],
            "usage": {"prompt_tokens": 10, "completion_tokens": 10, "total_tokens": 20},
        })

    def log_message(self, fmt, *args):
        print("[mock-mmembed]", fmt % args)


if __name__ == "__main__":
    print(f"мок мультимодальных эмбеддингов: http://0.0.0.0:{PORT}/v1/embeddings "
          f"(dims={DIMS}, формат={DIALECT or 'любой'})")
    HTTPServer(("0.0.0.0", PORT), Handler).serve_forever()
