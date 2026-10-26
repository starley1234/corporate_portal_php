"""Клиент OmniVoice v1 для воркера (тот же контракт, что Service_Nj665_Voice).

Зачем отдельный модуль. /voice (nj665) синтезирует коротко: браузер → PHP →
OmniVoice, nginx успевает. /multivoice озвучивает главы: воркер просил сайт
сделать весь блок за один POST /synth, php-fpm сидел на curl, nginx резал
fastcgi_read_timeout (300 с). TTS-сервер при этом спокойно писал звук — ответ
просто не доходил. Поэтому длинный синтез делает воркер сам, а сайт только
кладёт готовые байты на диск (владелец, 11.10.2026).

Внешний контракт — POST /api/v1/audio/speech. Формат ответа в v1 не зафиксирован:
поток байт, JSON с base64 или JSON со ссылкой. Разбор тот же, что у сайта.

Env (читать ТОЛЬКО внутри функций):

  NJ665_API_URL   адрес без слэша в конце
  NJ665_API_KEY   Authorization: Bearer …
  NJ665_TIMEOUT   секунд на один запрос синтеза (у воркера дефолт 600:
                  кусок до NJ665_MAX_CHARS — это минуты речи, 120 с мало)
  NJ666_SYNTH_TIMEOUT  перекрывает NJ665_TIMEOUT, если задан
"""

from __future__ import annotations

import base64
import json
import logging
import os
import re
from typing import Any

import httpx

log = logging.getLogger("n2j.omnivoice")

_B64_KEYS = {
    "audio_base64", "audio", "data", "content", "audio_content", "wav",
    "mp3", "file_bytes", "bytes", "base64", "b64", "result", "output",
    "speech", "audio_data",
}

_URL_KEYS = {
    "url", "audio_url", "file_url", "link", "download_url", "href", "file",
}

_DATA_URL = re.compile(r"^data:[^;]*;base64,", re.I)
_B64_ALPHABET = re.compile(r"^[A-Za-z0-9+/]+={0,2}$")


def api_base() -> str:
    return (os.environ.get("NJ665_API_URL") or "").strip().rstrip("/")


def api_key() -> str:
    return (os.environ.get("NJ665_API_KEY") or "").strip()


def enabled() -> bool:
    return bool(api_base() and api_key())


def default_model() -> str:
    """Модель синтеза: NJ665_MODEL, иначе тот же fallback, что у /voice.

    OmniVoice v1 поле model обязателен (422 «model: Field required»).
    У книги модель часто пустая — «как в /voice» — поэтому пустое не шлём.
    """
    return (os.environ.get("NJ665_MODEL") or "").strip() or "k2-fsa/omnivoice"


def timeout() -> float:
    """Секунд на один запрос синтеза.

    У сайта дефолт 120 — хватает превью. У воркера кусок до 4000 знаков это
    несколько минут речи, поэтому без явного NJ665_TIMEOUT берём 600.
    NJ666_SYNTH_TIMEOUT главнее: можно не трогать таймаут /voice.
    """
    for name in ("NJ666_SYNTH_TIMEOUT", "NJ665_TIMEOUT"):
        raw = (os.environ.get(name) or "").strip()
        if not raw:
            continue
        try:
            return max(30.0, min(3600.0, float(raw)))
        except ValueError:
            continue
    return 600.0


async def synth(*, turns: list | None = None, text: str = "",
                model: str = "", voice: str = "", format: str = "mp3",
                speed: float = 1.0, language: str = "",
                instructions: str = "",
                timeout_sec: float | None = None) -> dict:
    """Один запрос к /api/v1/audio/speech. Байты, без файла и журнала."""
    out = {
        "ok": False,
        "error": "",
        "bytes": b"",
        "format": (format or "mp3").strip() or "mp3",
        "chars": 0,
        "model": (model or "").strip() or default_model(),
        "voice": (voice or "").strip(),
    }

    if not enabled():
        out["error"] = ("сервис не настроен: задайте NJ665_API_URL и "
                        "NJ665_API_KEY в workers/.env")
        return out

    # model всегда: API не принимает пустое (владелец, 11.10.2026).
    payload: dict[str, Any] = {
        "model": out["model"],
        "response_format": out["format"],
        "speed": float(speed) if speed else 1.0,
    }

    if out["voice"]:
        payload["voice"] = out["voice"]

    if (language or "").strip():
        payload["language"] = language.strip()

    if (instructions or "").strip():
        payload["instructions"] = instructions.strip()[:500]

    clean_turns = _clean_turns(turns or [])

    if clean_turns:
        payload["input"] = clean_turns
        out["chars"] = sum(len(str(t.get("text") or "")) for t in clean_turns)
    else:
        piece = (text or "").strip()
        if not piece:
            out["error"] = "Введите текст для озвучивания."
            return out
        payload["input"] = piece
        out["chars"] = len(piece)

    wait = float(timeout_sec) if timeout_sec else timeout()
    url = api_base() + "/api/v1/audio/speech"
    headers = {
        "Authorization": "Bearer " + api_key(),
        "Accept": "*/*",
        "User-Agent": "n2j.ru/multivoice (nj666 worker)",
    }

    try:
        async with httpx.AsyncClient(
                timeout=httpx.Timeout(wait, connect=10.0),
                follow_redirects=True) as client:
            r = await client.post(url, json=payload, headers=headers)
    except Exception as e:  # noqa: BLE001 — сеть не должна ронять задачу
        out["error"] = f"Сервис озвучивания недоступен: {e.__class__.__name__}"
        return out

    if r.status_code < 200 or r.status_code >= 300:
        out["error"] = _error_text(r, "синтез не удался")
        return out

    audio = audio_from_response(r.content, r.headers.get("content-type"),
                                out["format"])

    if audio.get("url") and not audio.get("bytes"):
        fetched = await fetch_url(str(audio["url"]), wait)
        if fetched.get("error"):
            out["error"] = ("Сервис вернул ссылку на файл, но скачать его "
                            f"не удалось: {fetched['error']}")
            return out
        audio["bytes"] = fetched.get("bytes") or b""
        audio["format"] = sniff_format(audio["bytes"]) or audio.get("format")

    raw = audio.get("bytes") or b""

    if not raw:
        out["error"] = audio.get("error") or "Сервис вернул пустой ответ."
        return out

    real = sniff_format(raw)
    if real:
        out["format"] = real

    out["ok"] = True
    out["bytes"] = raw
    return out


def _clean_turns(turns: list) -> list[dict]:
    out: list[dict] = []

    for turn in turns:
        if isinstance(turn, str):
            turn = {"text": turn}
        if not isinstance(turn, dict):
            continue
        piece = str(turn.get("text") or "").strip()
        if not piece:
            continue
        item: dict[str, str] = {"text": piece}
        voice = str(turn.get("voice") or "").strip()
        if voice:
            item["voice"] = voice
        note = str(turn.get("instructions") or "").strip()
        if note:
            item["instructions"] = note[:500]
        out.append(item)

    return out


def audio_from_response(body: bytes | str, content_type: str = "",
                        format: str = "mp3") -> dict:
    """Поток / JSON base64 / JSON-ссылка → байты или url."""
    out = {"bytes": b"", "url": "", "format": format or "mp3", "error": ""}

    if isinstance(body, str):
        raw = body.encode("utf-8", "replace")
    else:
        raw = body or b""

    if not raw:
        out["error"] = "Сервис вернул пустой ответ."
        return out

    stripped = raw.lstrip()
    head = stripped[:1]

    if head in (b"{", b"["):
        try:
            data = json.loads(stripped.decode("utf-8", "replace"))
        except Exception:  # noqa: BLE001 — не JSON, идём как с сырыми байтами
            data = None

        if isinstance(data, (dict, list)):
            found = audio_from_json(data)
            if found["bytes"]:
                out["bytes"] = found["bytes"]
                out["format"] = sniff_format(found["bytes"]) or out["format"]
                return out
            if found["url"]:
                out["url"] = found["url"]
                return out
            out["error"] = "Ответ сервиса не похож на аудио: " + (
                stripped[:120].decode("utf-8", "replace"))
            return out

    sniffed = sniff_format(raw)
    ctype = (content_type or "").split(";")[0].strip().lower()

    if sniffed is None and "json" in ctype:
        out["error"] = "Сервис вернул JSON, но без аудио."
        return out

    out["bytes"] = raw
    out["format"] = sniffed or out["format"]
    return out


def audio_from_json(data) -> dict:
    """Поиск base64 или ссылки внутри JSON (обход в ширину, до 4 уровней)."""
    out = {"bytes": b"", "url": ""}
    level: list = [data]

    for depth in range(4):
        nxt: list = []
        for node in level:
            items = _json_items(node)
            if items is None:
                continue
            for key, value in items:
                if isinstance(value, (dict, list)):
                    nxt.append(value)
                    continue
                if not isinstance(value, str):
                    continue
                value = value.strip()
                if not value:
                    continue
                key_l = str(key).lower()
                if (not out["url"] and re.match(r"^https?://", value, re.I)
                        and (key_l in _URL_KEYS or "/audio/" in value.lower())):
                    out["url"] = value
                    continue
                if (not out["bytes"] and looks_like_base64(value)
                        and (key_l in _B64_KEYS or depth > 0)):
                    decoded = decode_b64(value)
                    if decoded:
                        out["bytes"] = decoded
        if out["bytes"] or out["url"]:
            break
        level = nxt

    return out


def _json_items(node):
    if isinstance(node, dict):
        return node.items()
    if isinstance(node, list):
        return ((str(i), v) for i, v in enumerate(node))
    return None


def looks_like_base64(value: str) -> bool:
    value = re.sub(r"\s+", "", (value or "").strip())
    value = _DATA_URL.sub("", value)
    return len(value) >= 64 and bool(_B64_ALPHABET.match(value))


def decode_b64(value: str) -> bytes:
    value = re.sub(r"\s+", "", (value or "").strip())
    value = _DATA_URL.sub("", value)
    if not value:
        return b""
    try:
        return base64.b64decode(value, validate=True)
    except Exception:  # noqa: BLE001
        try:
            return base64.b64decode(value)
        except Exception:  # noqa: BLE001
            return b""


def sniff_format(data: bytes | str | None) -> str | None:
    """Формат по магическим байтам: mp3, wav, flac, ogg, opus, aac."""
    raw = data if isinstance(data, (bytes, bytearray)) else b""
    if len(raw) < 4:
        return None
    head = bytes(raw[:12])
    if head[:3] == b"ID3":
        return "mp3"
    if head[:4] == b"RIFF" and head[8:12] == b"WAVE":
        return "wav"
    if head[:4] == b"fLaC":
        return "flac"
    if head[:4] == b"OggS":
        return "opus" if b"OpusHead" in bytes(raw[:512]) else "ogg"
    if len(head) >= 8 and head[4:8] == b"ftyp":
        return "aac"
    if head[0] == 0xFF and (head[1] & 0xE0) == 0xE0:
        return "mp3"
    return None


async def fetch_url(url: str, timeout_sec: float) -> dict:
    out = {"bytes": b"", "error": ""}
    if not re.match(r"^https?://", url or "", re.I):
        out["error"] = "ссылка не на http(s)"
        return out
    try:
        async with httpx.AsyncClient(
                timeout=httpx.Timeout(max(10.0, timeout_sec), connect=10.0),
                follow_redirects=True) as client:
            r = await client.get(url, headers={
                "User-Agent": "n2j.ru/multivoice (nj666 worker)",
            })
    except Exception as e:  # noqa: BLE001
        out["error"] = e.__class__.__name__
        return out
    if r.status_code < 200 or r.status_code >= 300:
        out["error"] = f"HTTP {r.status_code}"
        return out
    out["bytes"] = r.content or b""
    if not out["bytes"]:
        out["error"] = "пустой файл"
    return out


def _error_text(response: httpx.Response, what: str) -> str:
    text = ""
    try:
        data = response.json()
        if isinstance(data, dict):
            for key in ("error", "message", "detail"):
                val = data.get(key)
                if isinstance(val, str) and val.strip():
                    text = val.strip()
                    break
                if isinstance(val, dict) and isinstance(val.get("message"), str):
                    text = val["message"].strip()
                    break
    except Exception:  # noqa: BLE001
        text = (response.text or "")[:160].strip()
    if not text:
        text = f"HTTP {response.status_code}"
    return f"Сервис озвучивания: {what} ({text[:200]})"
