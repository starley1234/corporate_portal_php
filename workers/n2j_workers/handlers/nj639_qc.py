"""nj639.analyze — контроль качества изделия через VLM.

Конвейер «событие → кадр → VLM → JSON-схема → ответ API + запись в БД»:
  1. сайт кладёт задачу в канал nj639.analyze (ручной прогон или источник);
  2. воркер захватывает КАДР из источника, если он ещё не передан в payload:
       - IP-камера (Snapshot URL/HTTP, ONVIF getSnapshot, RTSP через ffmpeg);
       - FTP / Hot-Folder (file drop — качаем новейший файл по маске);
       - очередь сообщений (MQTT/RabbitMQ/Kafka — один консьюм base64 или URL);
       - локальная папка (агент на том же сервере);
  3. кадр + эталоны + правило + выходная JSON-схема уходят в vision-модель
     (NJ639_VISION_MODEL; кадры готовит общий vlm.prepare_b64 — до 1400 px
     по ширине, как у проверенных /label, /meal, /plant: на 512 px модель
     перестаёт видеть царапину и начинает угадывать);
  4. ответ валидируется JSON-схемой — результат возвращается в job.result
     (http-режим очереди), а в pg-режиме сразу пишется в nj_639_runs + дублируется
     POST-ом /qc/api/worker_result (X-Worker-Token), чтобы сайт не ждал reconcile.

Если VLM/кадр недоступны — задача честно падает с понятной ошибкой (ретраи по
очереди). Пакетов cv2/ffmpeg в песочнице может не быть: захват деградирует
мягко, по каждому источнику — отдельное внятное сообщение.

Env (читать ТОЛЬКО в функциях, на уровне модуля нельзя):
  NJ639_VISION_MODEL    — спека vision-модели ("провайдер::модель"|"модель"|"");
                          пусто → общая VLM_MODEL → модель по умолчанию провайдера
  NJ639_MAX_TOKENS      — потолок ответа VLM (пусто → VLM_MAX_TOKENS → 1500)
  NJ639_LLM_TIMEOUT     — ожидание модели, секунд (пусто → VLM_TIMEOUT → 240);
                          обязано быть МЕНЬШЕ timeout_sec задачи (сайт ставит 1200)
  NJ639_API_BASE        — https://<сайт>/qc/api (как у остальных VLM-сервисов;
                          старая форма с полным .../qc/api/worker_result тоже понимается)
  NJ639_SITE_TOKEN      — токен для POST на сайт (фолбэк WORKER_SITE_TOKEN/WORKER_API_TOKEN)
  NJ639_ALLOW_LAN      — "1" разрешает private-адреса СНАПШОТ/ONVIF (камеры внутри сети)
"""
from __future__ import annotations

import asyncio
import base64
import io
import json
import logging
import os
import re
import socket
import time

from ..base import HandlerContext
from .. import site, vlm

log = logging.getLogger("n2j.nj639")

CHANNEL = "nj639.analyze"

#: Ширина кадра для модели: NJ639_PHOTO_MAX_PX → VLM_PHOTO_MAX_PX → 1400.
IMAGE_MAX_W = vlm.photo_max_w("NJ639")
CAPTURE_TIMEOUT = 12.0


# --------------------------------------------------------------------------
# Env (только внутри функций)
# --------------------------------------------------------------------------

def _vision_model() -> str:
    """NJ639_VISION_MODEL → NJ570_VISION_MODEL → общая VLM_MODEL → дефолт."""
    return vlm.model("NJ639", "NJ570")


def _max_tokens() -> int:
    """NJ639_MAX_TOKENS → общий VLM_MAX_TOKENS → 1500."""
    return vlm.max_tokens("NJ639", 1500)


def _llm_timeout() -> float:
    """NJ639_LLM_TIMEOUT → общий VLM_TIMEOUT → 240 с (< timeout_sec задачи)."""
    return vlm.timeout("NJ639", 240.0)


def _allow_lan() -> bool:
    return os.getenv("NJ639_ALLOW_LAN", "").strip().lower() in ("1", "true", "yes")


# --------------------------------------------------------------------------
# Изображения — общий подготовщик кадров (vlm.prepare_b64)
# --------------------------------------------------------------------------

async def _prepare_image(item) -> dict | None:
    """Кадр/эталон задачи → {"b64", "media_type", "meta"} для сообщения модели.

    Раньше здесь жило своё сжатие в PNG до 512 px. Это ровно та ошибка, из-за
    которой контроль качества «не видел» дефект: на 512 px царапина и скол
    превращаются в шум, а модель начинает угадывать (урок nj646). Теперь кадр
    готовит общий vlm.prepare_b64 — 1400 px, JPEG, как у /label, /meal, /plant.
    """
    b64 = item.get("b64") if isinstance(item, dict) else item
    media_type = (item.get("media_type") or "") if isinstance(item, dict) else ""

    if not b64:
        return None

    ready, meta = await vlm.prepare_b64(str(b64), IMAGE_MAX_W)

    if not ready:
        return None

    # Pillow сработал — на выходе всегда JPEG; иначе кадр ушёл как есть и
    # mime обязан остаться исходным (сказать «png» про jpeg — это 400 у части
    # провайдеров).
    mime = "image/jpeg" if meta.get("out") else mime_for_b64(str(ready), str(media_type))

    return {"b64": ready, "media_type": mime, "meta": meta}


def _msg(role: str, content: str) -> dict:
    return {"role": role, "content": content}


def mime_for_b64(b64: str, media_type: str = "") -> str:
    if media_type and re.match(r"^image/[\w.+-]+$", media_type):
        return media_type
    return "image/jpeg"


# --------------------------------------------------------------------------
# Захват кадра
# --------------------------------------------------------------------------

def _assert_capture_host(url: str) -> None:
    """Запрет облачных метаданных всегда; private — по NJ639_ALLOW_LAN."""
    from urllib.parse import urlparse
    import ipaddress

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
                "внутренний/локальный адрес закрыт (для своих камер задайте NJ639_ALLOW_LAN=1)")


async def _fetch_http(url: str, login: str = "", password: str = "",
                      headers: dict | None = None) -> bytes:
    import httpx

    _assert_capture_host(url)
    auth = (login, password) if login else None
    hdrs = {"User-Agent": "n2j-nj639-qc/1.0"}

    if headers:
        hdrs.update({str(k): str(v) for k, v in headers.items()})

    async with httpx.AsyncClient(follow_redirects=True, timeout=CAPTURE_TIMEOUT,
                                 auth=auth, headers=hdrs) as client:
        r = await client.get(url)
        r.raise_for_status()
        return r.content


async def _capture_snapshot(cfg: dict) -> list[dict]:
    url = str(cfg.get("snapshot_url") or cfg.get("url") or "").strip()

    if not url:
        # тип источника на сайте один («IP-камера: Snapshot URL / RTSP»), и
        # человек мог заполнить только rtsp_url — берём кадр через ffmpeg,
        # а не отвечаем «нет snapshot_url» на заполненную форму.
        if str(cfg.get("rtsp_url") or "").strip():
            return await asyncio.to_thread(_capture_rtsp, cfg)

        raise RuntimeError("нет snapshot_url (или rtsp_url) в настройках источника")

    data = await _fetch_http(url, str(cfg.get("login") or ""),
                             str(cfg.get("password") or ""))
    return [{"b64": base64.b64encode(data).decode(),
             "media_type": _sniff(data)}]


async def _capture_onvif(cfg: dict) -> list[dict]:
    host = str(cfg.get("host") or "").strip().rstrip("/")

    if not host:
        raise RuntimeError("нет host в настройках ONVIF-источника")

    if not host.startswith("http"):
        host = "http://" + host

    if cfg.get("port"):
        from urllib.parse import urlparse
        p = urlparse(host)
        netloc = p.hostname + ("" if p.port else f":{int(cfg['port'])}")
        host = f"{p.scheme}://{netloc}"

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
    import subprocess
    import tempfile
    import os as _os

    url = str(cfg.get("rtsp_url") or "").strip()

    if not url:
        raise RuntimeError("нет rtsp_url в настройках источника")

    # SSRF-запрет как у snapshot/onvif/http: rtsp-адрес уходит в ffmpeg напрямую,
    # поэтому проверяем ДО запуска субпроцесса (иначе rtsp://localhost или
    # метаданные облака можно было сканировать камерой-источником).
    _assert_capture_host(url)

    with tempfile.TemporaryDirectory() as td:
        out = _os.path.join(td, "frame.jpg")
        cmd = ["ffmpeg", "-y", "-rtsp_transport", "tcp", "-i", url,
               "-frames:v", "1", "-q:v", "3", out]
        try:
            subprocess.run(cmd, capture_output=True, timeout=CAPTURE_TIMEOUT + 5, check=True)
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


def _ftp_latest(cfg: dict) -> tuple[bytes, str]:
    import ftplib

    host = str(cfg.get("host") or "").strip()

    if not host:
        raise RuntimeError("нет host в настройках FTP")

    login = str(cfg.get("login") or "anonymous")
    password = str(cfg.get("password") or "anonymous@")
    remote_dir = str(cfg.get("remote_dir") or ".").strip() or "."
    pattern = str(cfg.get("pattern") or "*").strip() or "*"

    ftp = ftplib.FTP()
    try:
        ftp.connect(host, int(cfg.get("port") or 21), timeout=CAPTURE_TIMEOUT)
        ftp.login(login, password)
        ftp.cwd(remote_dir)

        names = []
        ftp.retrlines("NLST", names.append)

        if not names:
            raise RuntimeError(f"в {remote_dir} нет файлов")

        import fnmatch
        matched = [n for n in names if fnmatch.fnmatch(n, pattern) and n not in (".", "..")]

        if not matched:
            raise RuntimeError(f"по маске {pattern} файлов не найдено")

        # новейший по mtime
        def mtime(n):
            try:
                return ftp.sendcmd("MDTM " + n)
            except Exception:  # noqa: BLE001
                return ""

        matched.sort(key=lambda n: mtime(n), reverse=True)
        target = matched[0]

        buf = io.BytesIO()
        ftp.retrbinary("RETR " + target, buf.write)
        return buf.getvalue(), _sniff(buf.getvalue())

    finally:
        try:
            ftp.quit()
        except Exception:  # noqa: BLE001
            try:
                ftp.close()
            except Exception:  # noqa: BLE001
                pass


async def _capture_ftp(cfg: dict) -> list[dict]:
    data, mt = await asyncio.to_thread(_ftp_latest, cfg)
    return [{"b64": base64.b64encode(data).decode(), "media_type": mt}]


def _folder_latest(cfg: dict) -> tuple[bytes, str]:
    import glob
    import os as _os

    path = str(cfg.get("path") or cfg.get("dir") or "").strip()

    if not path:
        raise RuntimeError("нет path в настройках папки")

    pattern = str(cfg.get("pattern") or "*").strip() or "*"
    full = _os.path.join(path, pattern)
    files = [f for f in glob.glob(full) if _os.path.isfile(f)]

    if not files:
        raise RuntimeError(f"в папке {path} нет файлов по маске {pattern}")

    target = max(files, key=_os.path.getmtime)

    with open(target, "rb") as f:
        data = f.read()

    return data, _sniff(data)


async def _capture_folder(cfg: dict) -> list[dict]:
    data, mt = await asyncio.to_thread(_folder_latest, cfg)
    return [{"b64": base64.b64encode(data).decode(), "media_type": mt}]


async def _capture_queue(cfg: dict) -> list[dict]:
    """Один консьюм сообщения: MQTT (paho), RabbitMQ (stomp.py), Kafka (kafka-python).
       Сообщение: {'url': ...} | {'image': ...}| {'b64': ...} | голый base64."""
    protocol = str(cfg.get("protocol") or "mqtt").lower()
    host = str(cfg.get("host") or "").strip()

    if not host:
        raise RuntimeError("нет host в настройках очереди")

    topic = str(cfg.get("topic") or "").strip()
    login = str(cfg.get("login") or "")
    password = str(cfg.get("password") or "")
    port = int(cfg.get("port") or 0)

    item = None

    if protocol == "mqtt":
        item = await asyncio.to_thread(_mqtt_once, host, port or 1883, login, password, topic)
    elif protocol in ("rabbitmq", "amqp", "stomp"):
        item = await asyncio.to_thread(_rabbit_once, host, port or 61613, login, password, topic)
    elif protocol in ("kafka",):
        item = await asyncio.to_thread(_kafka_once, host, port or 9092, topic)
    else:
        raise RuntimeError(f"протокол очереди {protocol} не поддерживается (mqtt|rabbitmq|kafka)")

    if not item:
        return []

    # сообщение → кадр: url или base64
    if isinstance(item, (bytes, bytearray)):
        item = bytes(item).decode("utf-8", "replace")

    if isinstance(item, str):
        s = item.strip()
        if s.startswith("{"):
            try:
                obj = json.loads(s)
            except ValueError:
                obj = None
            if isinstance(obj, dict):
                item = obj
        if isinstance(item, str):
            if re.match(r"^https?://", item):
                data = await _fetch_http(item)
                return [{"b64": base64.b64encode(data).decode(), "media_type": _sniff(data)}]
            # голый base64
            item = {"b64": item}

    if isinstance(item, dict):
        if item.get("url"):
            data = await _fetch_http(str(item["url"]))
            return [{"b64": base64.b64encode(data).decode(), "media_type": _sniff(data)}]
        if item.get("image"):
            data = await _fetch_http(str(item["image"]))
            return [{"b64": base64.b64encode(data).decode(), "media_type": _sniff(data)}]
        if item.get("b64") or item.get("image_b64") or item.get("data"):
            return [{"b64": str(item.get("b64") or item.get("image_b64") or item.get("data")),
                     "media_type": str(item.get("media_type") or "image/jpeg")}]

    raise RuntimeError("сообщение очереди не содержит url/base64 изображения")


def _mqtt_once(host, port, login, password, topic):
    try:
        import paho.mqtt.client as mqtt
    except ImportError:
        raise RuntimeError("paho-mqtt не установлен на воркере (pip install paho-mqtt)") from None

    got = []

    def on_message(_, __, msg):
        got.append(msg.payload)

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, protocol=mqtt.MQTTv311)
    client.on_message = on_message
    client.username_pw_set(login, password) if login else None
    client.connect(host, port, keepalive=10)
    client.loop_start()
    client.subscribe(topic or "#", qos=1)
    client.loop_stop() if False else None

    import time as _t
    _t.sleep(4)
    client.loop_stop()
    client.disconnect()

    return got[0] if got else None


def _rabbit_once(host, port, login, password, topic):
    try:
        import stomp
    except ImportError:
        raise RuntimeError("stomp.py не установлен на воркере (pip install stomp.py)") from None

    conn = stomp.Connection([(host, port)], heartbeats=(4000, 4000))
    conn.connect(login, password, wait=True) if login else conn.connect(wait=True)
    got = []

    class Listener(stomp.ConnectionListener):
        def on_message(self, frame):
            got.append(frame.body)
            conn.disconnect()

    conn.set_listener("nj639", Listener())
    conn.subscribe(destination=(topic or "nj639.qc"), id=1, ack="auto")

    import time as _t
    _t.sleep(4)

    try:
        conn.disconnect()
    except Exception:  # noqa: BLE001
        pass

    return got[0] if got else None


def _kafka_once(host, port, topic):
    try:
        from kafka import KafkaConsumer
    except ImportError:
        raise RuntimeError("kafka-python не установлен на воркере (pip install kafka-python)") from None

    consumer = KafkaConsumer(topic or "nj639.qc",
                             bootstrap_servers=f"{host}:{port}",
                             auto_offset_reset="latest",
                             consumer_timeout_ms=6000,
                             value_deserializer=lambda m: m.decode("utf-8", "replace"))
    for msg in consumer:
        consumer.close()
        return msg.value

    consumer.close()
    return None


def _sniff(data: bytes) -> str:
    b = data[:8]
    if b[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if b[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if b[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if b[:4] == b"RIFF" and b[8:12] == b"WEBP":
        return "image/webp"
    if b[:2] == b"BM":
        return "image/bmp"
    return "image/jpeg"


async def capture_source(cfg_kind: str, cfg: dict) -> list[dict]:
    """Захватить кадр(ы) по описанию источника."""
    if cfg_kind == "snapshot":
        return await _capture_snapshot(cfg)
    if cfg_kind == "onvif":
        return await _capture_onvif(cfg)
    if cfg_kind == "rtsp":
        return await asyncio.to_thread(_capture_rtsp, cfg)
    if cfg_kind == "ftp":
        return await _capture_ftp(cfg)
    if cfg_kind == "folder":
        return await _capture_folder(cfg)
    if cfg_kind == "http":
        url = str(cfg.get("url") or cfg.get("snapshot_url") or "").strip()
        if not url:
            raise RuntimeError("нет url в настройках HTTP-источника")
        hdrs = None
        raw_hdrs = cfg.get("headers") or ""
        if isinstance(raw_hdrs, str) and raw_hdrs.strip().startswith("{"):
            try:
                hdrs = json.loads(raw_hdrs)
            except ValueError:
                hdrs = None
        data = await _fetch_http(url, str(cfg.get("login") or ""),
                                 str(cfg.get("password") or ""), hdrs)
        return [{"b64": base64.b64encode(data).decode(), "media_type": _sniff(data)}]
    if cfg_kind == "queue":
        return await _capture_queue(cfg)

    raise RuntimeError(f"неизвестный тип источника {cfg_kind}")


# --------------------------------------------------------------------------
# JSON-валидация ответа VLM
# --------------------------------------------------------------------------

def _extract_json(text: str) -> dict | list | None:
    text = (text or "").strip()
    m = re.search(r"```(?:json)?\s*(.*?)```", text, re.S)
    if m:
        text = m.group(1).strip()
    m = re.search(r"\{.*\}", text, re.S)
    if not m:
        return None
    try:
        return json.loads(m.group(0))
    except ValueError:
        return None


def _validate_json_schema(data, schema: dict | None) -> tuple[bool | None, list[str]]:
    """ok=True/False/None(нет схемы), список ошибок."""
    if not schema:
        return None, []

    try:
        import jsonschema
    except ImportError:
        jsonschema = None

    if jsonschema is not None:
        try:
            jsonschema.validate(instance=data, schema=schema)
            return True, []
        except jsonschema.ValidationError as e:
            return False, [str(e.message)[:200]]

    # минимальный fallback без jsonschema
    errors = []
    if not isinstance(data, dict):
        return False, ["ожидался объект"]

    required = schema.get("required") or []
    props = schema.get("properties") or {}

    for name in required:
        if name not in data:
            errors.append(f"нет обязательного поля '{name}'")

    for name, spec in props.items():
        if not isinstance(spec, dict) or name not in data:
            continue
        t = str(spec.get("type") or "any")
        v = data[name]
        ok = {
            "string": lambda x: isinstance(x, str),
            "number": lambda x: isinstance(x, (int, float)) and not isinstance(x, bool),
            "integer": lambda x: isinstance(x, int) and not isinstance(x, bool),
            "boolean": lambda x: isinstance(x, bool),
            "array": lambda x: isinstance(x, list),
            "object": lambda x: isinstance(x, dict),
            "null": lambda x: x is None,
        }.get(t, lambda x: True)(v)
        if not ok:
            errors.append(f"поле '{name}': ожидался {t}")

    if schema.get("additionalProperties") is False:
        for name in data:
            if name not in props:
                errors.append(f"неожиданное поле '{name}'")

    return (not errors), errors


# --------------------------------------------------------------------------
# VLM-анализ
# --------------------------------------------------------------------------

SYSTEM_QC = (
    "Ты инспектор отдела технического контроля (ОТК) промышленного предприятия. "
    "Твоя задача — сравнить кадр (кадры) изделия с эталонными изображениями по "
    "заданному правилу и вернуть вердикт о качестве. Рассуждай строго по фактам "
    "на изображениях: трещины, сколы, царапины, деформации, несоответствие цвета/"
    "формы/размеров, посторонние включения, нарушения сборки. "
    "Отвечай ТОЛЬКО валидным JSON-объектом, точно соответствующим выходной "
    "JSON-схеме из задания. Без markdown, без пояснений вокруг JSON."
)


def _build_messages(rule: str, variants: list, schema: dict | None,
                    refs: list[dict], capture: list[dict], note: str = "") -> list[dict]:
    msgs = [_msg("system", SYSTEM_QC)]

    text = "ПРАВИЛО контроля: " + (rule or "проверь соответствие изделия эталону") + "\n"

    if note:
        text += ("ВНИМАНИЕ: " + note + ". Если дефект не виден однозначно — так и "
                 "скажи, не выдумывай.\n")

    if variants:
        text += "Варианты вердикта (используй только их): " + "; ".join(str(v) for v in variants) + "\n"

    if schema:
        text += ("ВЫХОДНАЯ JSON-СХЕМА ответа (верни объект строго по ней):\n"
                 + json.dumps(schema, ensure_ascii=False) + "\n")
    else:
        text += ('Верни JSON вида {"ok": true|false, "verdict": "краткий вердикт", '
                 '"defects": ["..."], "confidence": 0.0..1.0}\n')

    parts: list[dict] = [{"type": "text", "text": text}]
    msg = {"role": "user", "content": parts}

    for i, ref in enumerate(refs, 1):
        b64 = ref.get("b64") if isinstance(ref, dict) else ""
        if not b64:
            continue
        mt = mime_for_b64(str(b64), (ref.get("media_type") or "") if isinstance(ref, dict) else "")
        parts.append({"type": "text", "text": f"Эталонное изображение {i} (дефектов быть не должно):"})
        parts.append({"type": "image_url", "image_url": {"url": f"data:{mt};base64,{b64}"}})

    for i, cap in enumerate(capture, 1):
        b64 = cap.get("b64") if isinstance(cap, dict) else cap
        if not b64:
            continue
        mt = mime_for_b64(str(b64), (cap.get("media_type") or "") if isinstance(cap, dict) else "")
        parts.append({"type": "text", "text": f"Проверяемый кадр изделия {i}:"})
        parts.append({"type": "image_url", "image_url": {"url": f"data:{mt};base64,{b64}"}})

    parts.append({"type": "text", "text": "Выполни контроль и верни JSON строго по схеме."})
    msgs.append(msg)
    return msgs


# --------------------------------------------------------------------------
# Доставка результата
# --------------------------------------------------------------------------

def _result_path() -> str:
    """Путь приёмки относительно NJ639_API_BASE.

    В .env может стоять обе формы: семейная «…/qc/api» (как у NJ650/NJ651/…)
    и историческая «…/qc/api/worker_result» (так было написано в примере).
    Вторая форма с добавленным путём давала бы /worker_result/worker_result —
    молчаливый 404 и «вердикта на сайте нет».
    """
    base = site.api_base("NJ639").rstrip("/")

    return "" if base.endswith(("/worker_result", "/worker-result")) else "/worker_result"


async def _post_result_to_site(ctx: HandlerContext, run_id: int, res: dict) -> None:
    ok, reason, j = await site.post_json("NJ639", _result_path(),
                                         {"run_id": run_id, "result": res},
                                         timeout=30, retries=1, json_body=True)

    if ok:
        return

    if "_API_BASE не задан" in reason or "токен сайта не задан" in reason:
        log.info("nj639: выгрузка результата ВЫКЛЮЧЕНА (NJ639_API_BASE/NJ639_SITE_TOKEN пусты)")
        return

    if "сеть:" in reason:
        log.warning("nj639: worker-result недоступен: %s", reason)
    else:
        log.warning("nj639: worker-result -> %s", reason)


async def _apply_pg(ctx: HandlerContext, run_id: int, res: dict) -> None:
    if ctx.pg is None:
        return

    try:
        async with ctx.pg.acquire() as conn:
            await conn.execute(
                "UPDATE nj_639_runs SET "
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
        log.warning("nj639: pg-запись результата: %s", e)


# --------------------------------------------------------------------------
# Главный обработчик канала
# --------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    mode = str(payload.get("mode") or "manual")
    rule = str(payload.get("rule") or "")
    variants = [v for v in (payload.get("variants") or []) if str(v).strip()]
    schema = payload.get("schema") or None
    refs = [r for r in (payload.get("refs") or []) if isinstance(r, dict) and r.get("b64")]
    capture = [c for c in (payload.get("capture") or []) if (isinstance(c, dict) and c.get("b64")) or isinstance(c, str)]
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
            raise RuntimeError("нет кадра: ни фото в задаче, ни источника для захвата")

        # --- подготовка кадров и эталонов (общий vlm.prepare_b64, 1400 px) ---
        caps = []
        for c in capture:
            ready = await _prepare_image(c)
            if ready:
                caps.append(ready)

        if not caps:
            raise RuntimeError("кадры не читаются (битые изображения)")

        refs_ok = []
        for r in refs:
            ready = await _prepare_image(r)
            if ready:
                refs_ok.append(ready)

        # «кадр мелкий» — честная пометка вместо догадок модели (общая с /label)
        note = vlm.small_photo_warning(caps[0].get("meta") or {})

        await ctx.progress("Спрашиваю VLM", f"кадров: {len(caps)}, эталонов: {len(refs_ok)}")

        # --- VLM ---
        msgs = _build_messages(rule, variants, schema, refs_ok, caps, note)

        try:
            text, usage = await ctx.llm.chat_with_usage(
                msgs, model=_vision_model() or None,
                max_tokens=_max_tokens(), temperature=0,
                request_timeout=_llm_timeout())
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
            result["schema_ok"] = True if schema_ok else None
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
            log.warning("nj639: не удалось записать ошибку прогона %s", run_id)
        raise


async def _finalize(ctx: HandlerContext, run_id: int, result: dict) -> None:
    """pg — напрямую в БД; всегда дублируем POST на сайт (для http-режима)."""
    if run_id > 0:
        await _apply_pg(ctx, run_id, result)
        await _post_result_to_site(ctx, run_id, result)
