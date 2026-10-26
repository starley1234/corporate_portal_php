"""nj640.analyze — «Камеры цеха»: кадр + сценарий → VLM → JSON-схема → вердикт.

Аналог nj639_qc, но для видеонаблюдения цеха/офиса (темы: discipline —
трудовая дисциплина, ppe — СИЗ, equipment — загрузка оборудования).

Пайплайн:
  1. кадр из payload (base64) или захват из источника камеры
     (snapshot / onvif / rtsp / http);
  2. сжатие кадра (Pillow до 512px) — копия приёма nj639;
  3. VLM с текстом сценария + вариантами + выходной JSON-схемой;
  4. валидация ответа JSON-схемой (jsonschema с fallback-валидатором);
  5. запись результата в nj_640_runs (pg) + POST /cameras/api/worker_result.

Env:
  NJ640_VISION_MODEL, NJ640_MAX_TOKENS, NJ640_API_BASE, NJ640_SITE_TOKEN,
  NJ640_ALLOW_LAN (локальные камеры).

Безопасность:
  - SSRF-запрет `_assert_capture_host`: link-local/169.254.169.254 (метаданные
    облака) ВСЕГДА; private/loopback/reserved — только при NJ640_ALLOW_LAN=1;
  - единственный subprocess — `ffmpeg` (RTSP), список аргументов без shell;
  - пароли/креды только в памяти воркера из настроек камеры.
"""

from __future__ import annotations

import asyncio
import base64
import io
import ipaddress
import json
import logging
import os
import re
import socket
import subprocess
import tempfile
import time

from ..base import HandlerContext
from .. import site, vlm

log = logging.getLogger("n2j.nj640")

CHANNEL = "nj640.analyze"

#: Кадр для модели: NJ640_CAMERAS_PHOTO_MAX_PX → VLM_PHOTO_MAX_PX → 512 (ТЗ).
IMAGE_MAX_W = vlm.photo_max_w("NJ640_CAMERAS", 512)
CAPTURE_TIMEOUT = float(os.getenv("NJ640_CAPTURE_TIMEOUT", "15") or "15")

# Промпт vision-модели: ответ строгим JSON по выходной схеме (см. handle).
SYSTEM_VISION = (
    "Ты — ассистент видеонаблюдения. Отвечай только JSON-объектом без пояснений."
)


# --------------------------------------------------------------------------
# Env
# --------------------------------------------------------------------------

def _vision_model() -> str:
    """NJ640_VISION_MODEL → NJ570_VISION_MODEL → общая VLM_MODEL → дефолт."""
    return vlm.model("NJ640", "NJ570")


def _max_tokens() -> int:
    try:
        return max(200, int(os.getenv("NJ640_MAX_TOKENS", "1500")))
    except ValueError:
        return 1500



def _allow_lan() -> bool:
    return os.getenv("NJ640_ALLOW_LAN", "").strip().lower() in ("1", "true", "yes")


# --------------------------------------------------------------------------
# Изображения (Pillow мягко, как nj570/nj639)
# --------------------------------------------------------------------------

def _shrink_sync(png_bytes: bytes, max_w: int) -> bytes | None:
    try:
        from PIL import Image  # noqa: PLC0415
        img = Image.open(io.BytesIO(png_bytes)).convert("RGB")
        w, _h = img.size
        if w > max_w:
            img = img.resize((max_w, max(1, round(_h * max_w / w))), Image.LANCZOS)
        out = io.BytesIO()
        img.save(out, "PNG", optimize=True)
        return out.getvalue()
    except Exception:  # noqa: BLE001
        return None


async def _shrink_b64(b64: str) -> str:
    """base64 → компактный PNG-base64 (экономия токенов vision)."""
    if not b64:
        return ""
    try:
        raw = base64.b64decode(b64, validate=False)
    except Exception:  # noqa: BLE001
        return ""
    png = await asyncio.to_thread(_shrink_sync, raw, IMAGE_MAX_W)
    return base64.b64encode(png).decode() if png else b64


def _sniff(data: bytes) -> str:
    head = data[:16]
    if head.startswith(b"\xFF\xD8\xFF"):
        return "image/jpeg"
    if head.startswith(b"\x89PNG"):
        return "image/png"
    if head.startswith(b"GIF8"):
        return "image/gif"
    if head.startswith(b"RIFF") and head[8:12] == b"WEBP":
        return "image/webp"
    return "application/octet-stream"


# --------------------------------------------------------------------------
# Захват кадра из источника
# --------------------------------------------------------------------------

def _assert_capture_host(url: str) -> None:
    """SSRF-запрет: метаданные облака всегда; private/loopback — по флагу."""
    from urllib.parse import urlparse  # noqa: PLC0415

    host = urlparse(url).hostname or ""
    if not host:
        raise RuntimeError("пустой адрес хоста")

    try:
        infos = socket.getaddrinfo(host, None)
    except OSError as e:
        raise RuntimeError(f"не удалось определить адрес {host}: {e}") from None

    for info in infos:
        try:
            ip = ipaddress.ip_address(info[4][0])
        except ValueError:
            continue

        if ip.is_link_local or str(ip).startswith("169.254."):
            raise RuntimeError("адреса метаданных облака запрещены")

        if not _allow_lan() and (ip.is_private or ip.is_loopback or ip.is_reserved or ip.is_unspecified):
            raise RuntimeError(
                "внутренний/локальный адрес закрыт (для своих камер задайте NJ640_ALLOW_LAN=1)")


async def _fetch_http(url: str, login: str = "", password: str = "") -> bytes:
    import httpx  # noqa: PLC0415

    _assert_capture_host(url)
    auth = (login, password) if login else None

    async with httpx.AsyncClient(follow_redirects=True, timeout=CAPTURE_TIMEOUT,
                                 auth=auth,
                                 headers={"User-Agent": "n2j-nj640-cameras/1.0"}) as client:
        r = await client.get(url)
        r.raise_for_status()
        return r.content


async def _capture_snapshot(cfg: dict) -> list[dict]:
    url = str(cfg.get("snapshot_url") or cfg.get("url") or "").strip()
    if not url:
        raise RuntimeError("нет snapshot_url в настройках камеры")
    data = await _fetch_http(url, str(cfg.get("login") or ""), str(cfg.get("password") or ""))
    return [{"b64": base64.b64encode(data).decode(), "media_type": _sniff(data)}]


async def _capture_onvif(cfg: dict) -> list[dict]:
    host = str(cfg.get("host") or "").strip().rstrip("/")
    if not host:
        raise RuntimeError("нет host в настройках ONVIF-камеры")
    if not host.startswith("http"):
        host = "http://" + host

    login = str(cfg.get("login") or "")
    password = str(cfg.get("password") or "")

    paths = ["/onvif/snapshot.jpg", "/snapshot.jpg", "/onvif/snapshot.jpeg",
             "/ISAPI/Streaming/channels/101/picture", "/cgi-bin/snapshot.cgi"]
    last_err = "нет путей снимка"

    for path in paths:
        try:
            data = await _fetch_http(host + path, login, password)
            return [{"b64": base64.b64encode(data).decode(), "media_type": _sniff(data)}]
        except Exception as e:  # noqa: BLE001
            last_err = f"{host}{path}: {e}"

    raise RuntimeError(f"не удалось получить снимок ONVIF ({last_err})")


def _capture_rtsp(cfg: dict) -> list[dict]:
    """RTSP: один кадр через ffmpeg (бинарник обязателен)."""
    url = str(cfg.get("rtsp_url") or "").strip()
    if not url:
        raise RuntimeError("нет rtsp_url в настройках камеры")

    _assert_capture_host(url)

    with tempfile.TemporaryDirectory() as td:
        out = os.path.join(td, "frame.jpg")
        cmd = ["ffmpeg", "-y", "-rtsp_transport", "tcp", "-i", url,
               "-frames:v", "1", "-q:v", "3", out]
        try:
            subprocess.run(cmd, capture_output=True, timeout=CAPTURE_TIMEOUT + 5, check=True)  # noqa: S603
        except FileNotFoundError:
            raise RuntimeError("ffmpeg не установлен на воркере — RTSP недоступен") from None
        except subprocess.TimeoutExpired:
            raise RuntimeError("таймаут захвата RTSP (ffmpeg)") from None
        except subprocess.CalledProcessError as e:
            raise RuntimeError(f"ffmpeg не взял кадр RTSP: {e.stderr.decode()[:200]}") from None

        with open(out, "rb") as f:
            data = f.read()

        if not data:
            raise RuntimeError("ffmpeg вернул пустой кадр RTSP")

    return [{"b64": base64.b64encode(data).decode(), "media_type": "image/jpeg"}]


async def _capture_http(cfg: dict) -> list[dict]:
    """Объектное хранилище / HTTP-кадр: как snapshot, без особой логики."""
    url = str(cfg.get("url") or cfg.get("snapshot_url") or "").strip()
    if not url:
        raise RuntimeError("нет url в настройках HTTP-источника")
    data = await _fetch_http(url, str(cfg.get("login") or ""), str(cfg.get("password") or ""))
    return [{"b64": base64.b64encode(data).decode(), "media_type": _sniff(data)}]


async def capture_source(kind: str, cfg: dict) -> list[dict]:
    if kind == "snapshot":
        return await _capture_snapshot(cfg)
    if kind == "onvif":
        return await _capture_onvif(cfg)
    if kind == "http":
        return await _capture_http(cfg)
    if kind == "rtsp":
        return await asyncio.to_thread(_capture_rtsp, cfg)
    raise RuntimeError(f"неизвестный тип камеры: {kind}")


# --------------------------------------------------------------------------
# JSON / схема
# --------------------------------------------------------------------------

def _extract_json(text: str) -> dict | list | None:
    """Извлечь первый JSON-объект из ответа VLM (аккуратно, без обрезки)."""
    t = (text or "").strip()
    t = t.lstrip("`")
    if t.startswith("json"):
        t = t[4:]
    t = t.strip().lstrip("`").strip()

    try:
        return json.loads(t)
    except Exception:  # noqa: BLE001
        pass

    m = re.search(r"\{.*\}", t, re.S)
    if m:
        try:
            return json.loads(m.group(0))
        except Exception:  # noqa: BLE001
            pass

    m = re.search(r"\[.*\]", t, re.S)
    if m:
        try:
            return json.loads(m.group(0))
        except Exception:  # noqa: BLE001
            return None

    return None


def _type_ok(value, ptype: str) -> bool:
    if ptype == "string":
        return isinstance(value, str)
    if ptype == "number":
        return isinstance(value, (int, float)) and not isinstance(value, bool)
    if ptype == "integer":
        return isinstance(value, int) and not isinstance(value, bool)
    if ptype == "boolean":
        return isinstance(value, bool)
    if ptype == "array":
        return isinstance(value, list)
    if ptype == "object":
        return isinstance(value, dict)
    return True


def _validate_json_schema(data, schema) -> tuple[bool | None, list[str]]:
    """Валидация ответа VLM. True — прошёл, False — не прошёл, None — нет схемы."""
    if not isinstance(schema, dict) or not schema:
        return None, []

    try:
        import jsonschema  # noqa: PLC0415
    except ImportError:
        jsonschema = None

    if jsonschema is not None:
        try:
            jsonschema.validate(instance=data, schema=schema)
            return True, []
        except jsonschema.ValidationError as e:
            return False, [e.message[:200]]
        except Exception as e:  # noqa: BLE001
            log.warning("nj640: jsonschema упал, fallback: %s", e)

    # fallback — своё подмножество
    errors: list[str] = []
    stype = str(schema.get("type") or "object")

    if stype == "object":
        if not isinstance(data, dict):
            return False, ["ожидался объект"]
        for req in schema.get("required") or []:
            if req not in data:
                errors.append(f"нет обязательного поля {req}")
        props = schema.get("properties") or {}
        for name, ps in props.items():
            if name in data and data[name] is not None and not _type_ok(data[name], str(ps.get("type") or "string")):
                errors.append(f"{name}: ожидался {ps.get('type') or 'string'}")
        if schema.get("additionalProperties") is False:
            for key in data:
                if key not in props:
                    errors.append(f"лишнее поле {key}")
    elif stype == "array":
        if not isinstance(data, list):
            errors.append("ожидался массив")
    else:
        if not _type_ok(data, stype):
            errors.append(f"ожидался {stype}")

    return (False, errors) if errors else (True, [])


# --------------------------------------------------------------------------
# Сообщения VLM
# --------------------------------------------------------------------------

def _vision_schema(schema) -> str | None:
    if not isinstance(schema, dict):
        return None
    return json.dumps(schema, ensure_ascii=False)


def _build_messages(rule: str, variants: list[str], schema, capture: list[dict]) -> list[dict]:
    text = ("Ты — система видеонаблюдения цеха/офиса. Посмотри на кадр и ответь строго по заданию.\n\n"
            f"Сценарий проверки: {rule}\n" if rule else
            "Ты — система видеонаблюдения цеха/офиса. Проверь кадр по общему сценарию.\n\n")

    if variants:
        text += "Варианты вердикта (используй только их): " + "; ".join(str(v) for v in variants) + "\n"

    schema_json = _vision_schema(schema)
    if schema_json:
        text += ("ВЫХОДНАЯ JSON-СХЕМА ответа (верни объект строго по ней):\n"
                 + schema_json + "\n")
    else:
        text += ('Верни JSON вида {"verdict":"...", "violations":[...], "confidence":0.0} без пояснений.\n')

    parts: list[dict] = [{"type": "text", "text": text}]

    for i, cap in enumerate(capture, 1):
        b64 = cap.get("b64") if isinstance(cap, dict) else ""
        if not b64:
            continue
        mt = cap.get("media_type") if isinstance(cap, dict) else ""
        mt = mt or "image/jpeg"
        parts.append({"type": "text", "text": f"Кадр камеры {i}:"})
        parts.append({"type": "image_url", "image_url": {"url": f"data:{mt};base64,{b64}"}})

    parts.append({"type": "text", "text": "Выполни проверку и верни JSON строго по схеме."})

    return [
        {"role": "system", "content": SYSTEM_VISION},
        {"role": "user", "content": parts},
    ]


# --------------------------------------------------------------------------
# Выгрузка результата
# --------------------------------------------------------------------------

async def _post_result_to_site(ctx: HandlerContext, run_id: int, res: dict) -> None:
    ok, reason, j = await site.post_json("NJ640", "", {"run_id": run_id, "result": res},
                                         timeout=30, retries=1, json_body=True)

    if ok:
        return

    if "_API_BASE не задан" in reason or "токен сайта не задан" in reason:
        log.info("nj640: выгрузка результата ВЫКЛЮЧЕНА (NJ640_API_BASE/NJ640_SITE_TOKEN пусты)")
        return

    if "сеть:" in reason:
        log.warning("nj640: worker-result недоступен: %s", reason)
    else:
        log.warning("nj640: worker-result -> %s", reason)


async def _apply_pg(ctx: HandlerContext, run_id: int, res: dict) -> None:
    if ctx.pg is None:
        return

    try:
        async with ctx.pg.acquire() as conn:
            await conn.execute(
                "UPDATE nj_640_runs SET "
                "status = $2, verdict = $3::jsonb, schema_ok = $4, model = $5, "
                "prompt_tokens = $6, completion_tokens = $7, duration_ms = $8, "
                "error = $9, finished_at = now() WHERE id = $1",
                run_id,
                str(res.get("status") or "done"),
                json.dumps(res.get("verdict") or {}, ensure_ascii=False),
                res.get("schema_ok"),
                str(res.get("model") or "")[:160],
                int(res.get("prompt_tokens") or 0),
                int(res.get("completion_tokens") or 0),
                int(res.get("duration_ms") or 0),
                (str(res.get("error"))[:900] if res.get("error") else None),
            )
    except Exception as e:  # noqa: BLE001
        log.warning("nj640: pg-запись результата: %s", e)


# --------------------------------------------------------------------------
# Главный обработчик канала
# --------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    mode = str(payload.get("mode") or "manual")
    rule = str(payload.get("rule") or "")
    variants = [v for v in (payload.get("variants") or []) if str(v).strip()]
    schema = payload.get("schema") or None
    capture = [c for c in (payload.get("capture") or [])
               if (isinstance(c, dict) and c.get("b64")) or isinstance(c, str)]
    source = payload.get("source") or {}

    t0 = time.monotonic()
    result: dict = {"run_id": run_id, "ok": True, "status": "done",
                    "verdict": None, "schema_ok": None, "model": "", "error": ""}

    try:
        await ctx.progress("Готовлю кадр", "из задачи или источника")

        # --- кадр: из payload или захват из источника ---
        if not capture and source:
            kind = str(source.get("kind") or "")
            cfg = source.get("config") or {}

            try:
                captured = await capture_source(kind, cfg)
            except Exception as e:  # noqa: BLE001
                result.update({"ok": False, "status": "error",
                               "error": f"захват кадра не удался: {e}"})
                await _finalize(ctx, run_id, result)
                return result

            capture = captured

        if not capture:
            raise RuntimeError("нет кадра: ни изображения в задаче, ни источника для захвата")

        # --- сжатие кадров ---
        caps = []
        for c in capture:
            b64 = c.get("b64") if isinstance(c, dict) else str(c)
            mt = (c.get("media_type") or "") if isinstance(c, dict) else ""
            shrunk = await _shrink_b64(str(b64))
            if shrunk:
                caps.append({"b64": shrunk, "media_type": mt or "image/jpeg"})

        if not caps:
            raise RuntimeError("кадры не читаются (битые изображения)")

        await ctx.progress("Спрашиваю VLM", f"кадров: {len(caps)}")

        # --- VLM ---
        msgs = _build_messages(rule, variants, schema, caps)

        try:
            text, usage = await ctx.llm.chat_with_usage(
                msgs, model=_vision_model() or None,
                max_tokens=_max_tokens(), temperature=0,
                request_timeout=300)
        except Exception as e:  # noqa: BLE001
            raise RuntimeError(f"VLM не ответила: {e}") from None

        result["model"] = _vision_model() or "default"
        result["prompt_tokens"] = int(usage.get("prompt_tokens") or 0)
        result["completion_tokens"] = int(usage.get("completion_tokens") or 0)

        data = _extract_json(text)

        if data is None:
            result.update({"ok": False, "status": "error",
                           "error": f"VLM вернула не-JSON: {text[:300]}"})
            await _finalize(ctx, run_id, result)
            return result

        # --- валидация схемой ---
        schema_ok, errs = _validate_json_schema(data, schema)

        if schema_ok is False:
            result["schema_ok"] = False
            result["verdict"] = data if isinstance(data, dict) else {"raw": data}
            result["error"] = "ответ не прошёл JSON-схему: " + "; ".join(errs[:4])
            result["status"] = "error"
        else:
            result["schema_ok"] = schema_ok  # True | None
            result["verdict"] = data if isinstance(data, dict) else {"result": data}
            result["error"] = ""

        if result["status"] != "error":
            result["status"] = "done"

        result["duration_ms"] = int((time.monotonic() - t0) * 1000)
        await _finalize(ctx, run_id, result)
        return result

    except Exception as e:  # noqa: BLE001 — задача честно падает с причиной
        result.update({"ok": False, "status": "error", "error": str(e)[:900],
                       "duration_ms": int((time.monotonic() - t0) * 1000)})
        try:
            await _finalize(ctx, run_id, result)
        except Exception:  # noqa: BLE001
            log.warning("nj640: не удалось записать ошибку прогона %s", run_id)
        raise


async def _finalize(ctx: HandlerContext, run_id: int, result: dict) -> None:
    """pg — напрямую в БД; всегда дублируем POST на сайт (для http-режима)."""
    if run_id > 0:
        await _apply_pg(ctx, run_id, result)
        await _post_result_to_site(ctx, run_id, result)
