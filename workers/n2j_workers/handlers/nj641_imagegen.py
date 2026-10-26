"""nj641.generate — «Генератор изображений»: описание/правка/референсы → картинка.

Серверный слой генерации изображений через внешний OpenAI-совместимый
image-API (OpenRouter/OpenAI, FLUX.2-klein). Модуль сайта nj641_imagegen ставит
задачу в очередь nj120 (канал nj641.generate); этот воркер:

Конвейер:
  1. проверяет видеопамять бекенда (GET /vram) — на сервере память может быть
     занята другими процессами, поэтому при нехватке ждёт и переспрашивает
     (включается/выключается флагами NJ641_VRAM_*);
  2. шлёт запрос в image-API (контракт OpenAI Images):
     POST /v1/images/generations — JSON (prompt, model, size, seed, steps);
     POST /v1/images/edits — multipart (image[] — файлы, prompt, model, size);
     ответ — JSON {"data": [{"b64_json": …} | {"url": …}]};
  3. доставляет картинку на сайт ТРЕМЯ путями (первый удавшийся — побеждает):
     pg-запись в nj_641_runs → multipart-загрузка файла на
     /imagegen/api/worker-upload → JSON base64 на /imagegen/api/worker-result
     (последний — только для мелких картинок: 2-4 МБ base64 не проходит лимиты
     nginx/PHP на тело запроса, и результат «терялся»).
  4. Крупные картинки сжимаются в WebP (Pillow, если установлен) — вес падает
     в 3-6 раз без видимой разницы.

Канал nj641.clear очищает кэш бекенда (POST /clear).

Env (воркер):
  NJ641_IMAGE_API       — адрес внешнего image-API (обязателен); можно задать
                          и с префиксом /v1 — путь не удвоится;
  NJ641_API_TOKEN       — токен внешнего image-API (Authorization: Bearer);
  NJ641_IMAGE_MODEL     — модель генерации (по умолчанию
                          black-forest-labs/FLUX.2-klein-4B);
  NJ641_IMAGE_STEPS     — число шагов (пусто — не передаём, решает сервер);
  NJ641_RESPONSE_FORMAT — b64_json (по умолчанию) или url;
  NJ641_API_BASE        — URL приёма результата на сайте: годится и база сайта
                          (`https://n2j.ru`), и полный адрес эндпоинта
                          (`https://n2j.ru/imagegen/api/worker-result`);
                          путь модуля дописывается воркером;
  NJ641_SITE_TOKEN      — токен для POST результата (X-Worker-Token);
  NJ641_MEDIA_DIR       — необязательно: каталог сайта для файлов картинок
                          (например, /var/www/n2j.ru/public_html/storage/nj641).
                          Если задан, воркер пишет файл сам, а в базе остаются
                          только метаданные и URL (режим pg);
  NJ641_MEDIA_URL_BASE  — префикс URL файлов (по умолчанию /imagegen/file);
  NJ641_VRAM_CHECK / NJ641_VRAM_WAIT / NJ641_VRAM_MIN_FREE_MB /
  NJ641_VRAM_WAIT_SEC / NJ641_VRAM_POLL_SEC — поведение проверки памяти.

Безопасность: адрес внешнего API только из .env; единственные сетевые вызовы —
в сконфигурированный image-API и на сайт; пользовательский ввод (промпты,
base64) передаётся как данные, а не как URL/команды.
"""

from __future__ import annotations

import asyncio
import base64
import json
import logging
import os
import re
import time

import httpx

from ..base import HandlerContext
from ..llmwatch import wait_with_ticks

log = logging.getLogger("n2j.nj641")

CHANNEL = "nj641.generate"
CHANNEL_CLEAR = "nj641.clear"

TIMEOUT_GEN = float(os.getenv("NJ641_GEN_TIMEOUT", "900") or "900")

# Модель и формат ответа OpenAI-совместимого image-API. Умолчания — как в
# OpenAPI сервера изображений: FLUX.2-klein-4B и b64_json.
DEFAULT_IMAGE_MODEL = "black-forest-labs/FLUX.2-klein-4B"
DEFAULT_RESPONSE_FORMAT = "b64_json"

# Как часто рапортовать «модель рисует N с» и сколько base64 пускать в
# результат очереди (больше — не проходит лимиты тела запроса).
TICK_SEC = float(os.getenv("NJ641_TICK_SEC", "15") or "15")
INLINE_MAX_BYTES = int(float(os.getenv("NJ641_INLINE_MAX_MB", "1.5")) * 1024 * 1024)


def _clean_url(raw: str) -> str:
    """Адрес из .env → чистый URL без «человеческих» обёрток.

    В .env адрес пишут по-разному: `https://n2j.ru`, в кавычках, в угловых
    скобках или ссылкой из чата — `[https://n2j.ru](https://n2j.ru/imagegen/api/worker-result)`.
    Раньше такая строка уезжала в запрос как есть, и доставка «терялась»
    (владелец, 26.09: «Изображения я не увидел»). Здесь: снимаем обёртки,
    берём адрес из markdown-ссылки (он рабочий), отбрасываем `?query#fragment`.
    """
    s = (raw or "").strip().strip('"').strip("'").strip()

    if not s:
        return ""

    md = re.search(r"\]\(\s*(https?://[^\s)]+)", s, re.I)

    if md:
        s = md.group(1)
    else:
        m = re.search(r"https?://[^\s>\]\)\"']+", s, re.I)

        if m:
            s = m.group(0)
        elif re.match(r"^[\w.-]+(:\d+)?(/|$)", s):
            # адрес без схемы («n2j.ru») — дописываем https, иначе запрос уйдёт «в никуда»
            s = "https://" + s

    return re.split(r"[?#]", s, 1)[0].rstrip("/")


def _site_base_raw() -> str:
    """База сайта для доставки результата.

    Владелец (26.09): «NJ641_SITE_TOKEN = WORKER_API_TOKEN», но адрес сайта
    (NJ641_API_BASE) в .env не задан — и вся доставка молча выключалась, хотя
    адрес давно есть у очереди. Поэтому берём первый доступный:
    NJ641_API_BASE → NJ120_API_URL (режим http) → WORKER_SITE_URL → N2J_SITE_URL.
    """
    for name in ("NJ641_API_BASE", "NJ120_API_URL", "WORKER_SITE_URL",
                 "N2J_SITE_URL", "WORKER_API_URL"):
        value = _clean_url(os.getenv(name, ""))

        if value:
            return value

    return ""


def _site_endpoint(path: str) -> str:
    """URL эндпоинта сайта: база из NJ641_API_BASE + путь модуля.

    Базу админ мог задать по-разному: и как сайт (`https://n2j.ru`), и как
    полный адрес прежнего эндпоинта (`https://n2j.ru/imagegen/api/worker-result`
    — так было в первых инструкциях). Во втором случае отрезаем всё от
    `/imagegen/`: иначе загрузка файла уходила на несуществующий
    `.../worker-result/imagegen/api/worker-upload` и «терялась» (владелец,
    26.09: «изображение не вижу, процесс висит»).
    """
    base = _site_base_raw()

    if not base:
        return ""

    # База могла быть задана адресом эндпоинта либо API очереди — отрезаем
    # служебные хвосты, чтобы путь модуля склеился правильно.
    for marker in ("/imagegen/", "/nj120/"):
        cut = base.find(marker)

        if cut != -1:
            base = base[:cut]

    return base + path


# --------------------------------------------------------------------------
# Env / конфигурация
# --------------------------------------------------------------------------

_LOCAL_HOST_RE = re.compile(
    r"^(localhost|127\.|0\.0\.0\.0|\[::1\]|10\.|192\.168\.|"
    r"172\.(1[6-9]|2\d|3[01])\.)", re.I)


def _image_api() -> str:
    """Адрес OpenAI-совместимого image-API из .env → чистая база.

    Админ пишет адрес по-разному: в кавычках/скобках, ссылкой из чата, без
    схемы («127.0.0.1:8000») или сразу полным эндпоинтом
    (`http://host:8000/v1/images/generations`). Локальному серверу нужен
    http, а не https; знакомый хвост эндпоинта отрезаем, чтобы путь не
    удвоился.
    """
    raw = os.getenv("NJ641_IMAGE_API", "").strip().strip('"').strip("'").strip()

    if not raw:
        return ""

    md = re.search(r"\]\(\s*(https?://[^\s)]+)", raw, re.I)

    if md:
        raw = md.group(1)
    else:
        m = re.search(r"https?://[^\s>)\]\"']+", raw, re.I)
        if m:
            raw = m.group(0)

    raw = re.split(r"[?#]", raw, 1)[0].rstrip("/")

    if not re.match(r"^https?://", raw, re.I):
        hostport = raw.split("/", 1)[0]
        host = hostport.split(":")[0]
        # Локальному серверу и адресу с явным портом (self-hosted image-API)
        # нужен http; публичному имени без порта — https.
        local = _LOCAL_HOST_RE.match(host) or re.search(r":\d+$", hostport)
        raw = ("http://" if local else "https://") + raw

    for tail in ("/v1/images/generations", "/v1/images/edits", "/v1/images",
                 "/v1/chat/completions", "/v1/models", "/vram", "/clear", "/v1"):
        if raw.endswith(tail):
            raw = raw[:-len(tail)]
            break

    return raw.rstrip("/")


def _api_url(path: str) -> str:
    """URL эндпоинта image-API без удвоения префикса /v1.

    Админ задаёт базу по-разному: `http://127.0.0.1:8000` или уже с
    OpenAI-префиксом `http://127.0.0.1:8000/v1` (так пишут адреса
    OpenAI-совместимых серверов). Во втором случае `/v1/...` не дописываем
    второй раз — иначе запрос уходит на несуществующий `/v1/v1/...`.
    """
    base = _image_api()

    if not base:
        return ""

    if base.endswith("/v1") and path.startswith("/v1/"):
        return base + path[len("/v1"):]

    return base + path


def _image_model(payload: dict | None = None) -> str:
    """Модель генерации: payload → NJ641_IMAGE_MODEL → умолчание OpenAPI."""
    for value in ((payload or {}).get("model"), os.getenv("NJ641_IMAGE_MODEL", "")):
        s = str(value or "").strip()
        if s:
            return s
    return DEFAULT_IMAGE_MODEL


def _image_steps(payload: dict | None = None) -> int | None:
    """Число шагов: payload → NJ641_IMAGE_STEPS; пусто — решает сервер."""
    for value in ((payload or {}).get("steps"), os.getenv("NJ641_IMAGE_STEPS", "")):
        s = str(value or "").strip()
        if s:
            try:
                steps = int(float(s))
            except ValueError:
                continue
            if steps > 0:
                return steps
    return None


def _response_format() -> str:
    value = os.getenv("NJ641_RESPONSE_FORMAT", "").strip().lower()
    return value if value in ("b64_json", "url") else DEFAULT_RESPONSE_FORMAT


def _api_token() -> str:
    for name in ("NJ641_API_TOKEN", "NJ641_IMAGE_TOKEN"):
        t = os.getenv(name, "").strip().strip('"').strip("'").strip()
        if t:
            return t
    return ""


def _site_token() -> str:
    for name in ("NJ641_SITE_TOKEN", "WORKER_SITE_TOKEN", "WORKER_API_TOKEN"):
        t = os.getenv(name, "").strip().strip('"').strip("'").strip()
        if t:
            return t
    return ""


def _vram_check() -> bool:
    return os.getenv("NJ641_VRAM_CHECK", "1").strip().lower() not in ("0", "false", "no", "off")


def _vram_wait() -> bool:
    return os.getenv("NJ641_VRAM_WAIT", "1").strip().lower() not in ("0", "false", "no", "off")


def _vram_min_free_mb() -> int:
    try:
        return max(0, int(os.getenv("NJ641_VRAM_MIN_FREE_MB", "4096")))
    except ValueError:
        return 4096


def _vram_wait_sec() -> int:
    try:
        return max(0, int(os.getenv("NJ641_VRAM_WAIT_SEC", "240")))
    except ValueError:
        return 240


def _vram_poll_sec() -> int:
    try:
        return max(1, int(os.getenv("NJ641_VRAM_POLL_SEC", "20")))
    except ValueError:
        return 20


# --------------------------------------------------------------------------
# HTTP к внешнему image-API (контракт OpenAI Images)
#
#   POST /v1/images/generations — JSON: prompt, model, n, size ("1024x1024"),
#                                  response_format, seed, steps;
#   POST /v1/images/edits       — multipart: image[] (файлы), prompt, model,
#                                  n, size, response_format, seed, steps;
#   POST /clear                 — очистка кэша (Bearer);
#   GET  /vram                  — видеопамять (публичный);
#   ответ — JSON {"data": [{"b64_json": …} | {"url": …}]}; старый бинарный
#   ответ (image/*) тоже принимаем — сервер мог отдать картинку напрямую.
# --------------------------------------------------------------------------

def _headers(extra: dict | None = None) -> dict:
    h = {"Accept": "application/json, image/*, */*"}
    if _api_token():
        h["Authorization"] = f"Bearer {_api_token()}"
    if extra:
        h.update(extra)
    return h


class _ApiError(RuntimeError):
    pass


async def _call_api_watched(ctx: HandlerContext, path: str, *,
                           data: dict | None = None, files: list | None = None,
                           json_body: dict | None = None,
                           timeout: float, label: str = "Модель рисует") -> dict:
    """Вызов внешнего image-API ПОД ТИКЕРОМ: каждые 15 с — строка в журнал.

    Без этого генерация выглядела «зависшей»: одна метка «модель рисует» и
    тишина на минуты (владелец: «процесс соответственно висит»). Тик — это и
    пульс задачи: сайтовый свипер не считает живую генерацию молчащей, а на
    странице видно «Модель рисует 45 с (лимит 900 с)».
    """
    async def _tick(waited: int, limit_left: float | None) -> None:
        tail = f" (лимит {int(limit_left)} с)" if limit_left else ""
        await ctx.progress(label, f"{waited} с{tail}")

    return await wait_with_ticks(
        lambda: _call_api(path, data=data, files=files, json_body=json_body,
                          timeout=timeout),
        every=TICK_SEC, limit=timeout, on_tick=_tick, what="image-API",
    )


async def _call_api(path: str, *, data: dict | None = None,
                    files: list | None = None, json_body: dict | None = None,
                    timeout: float) -> dict:
    """Один вызов внешнего image-API → {'image_bytes': bytes} или {'json': …}."""
    url = _api_url(path)

    if not url:
        raise _ApiError("NJ641_IMAGE_API не задан — внешний image-API недоступен")

    async with httpx.AsyncClient(timeout=timeout, headers=_headers()) as client:
        if files is not None:
            r = await client.post(url, data=data or {}, files=files)
        elif json_body is not None:
            r = await client.post(url, json=json_body)
        else:
            r = await client.post(url, data=data or {})

        if r.status_code >= 400:
            detail = r.text[:300]
            try:
                j = r.json()
                raw = j.get("detail") or j.get("error") or r.text[:300]
                detail = raw if isinstance(raw, str) else json.dumps(raw, ensure_ascii=False)[:300]
            except Exception:  # noqa: BLE001
                pass

            if r.status_code in (401, 403):
                raise _ApiError(
                    f"image-API: доступ запрещён ({r.status_code}) — проверьте "
                    f"NJ641_API_TOKEN (HTTPBearer). {detail}"[:400])

            raise _ApiError(f"image-API: {r.status_code} {detail}")

        ctype = (r.headers.get("content-type") or "").split(";")[0].strip().lower()

        if ctype.startswith("application/json") or r.content[:1] in (b"{", b"["):
            try:
                return {"json": r.json()}
            except Exception:  # noqa: BLE001
                raise _ApiError(f"image-API: нечитаемый JSON ({r.text[:200]})") from None

        if r.content and isinstance(r.content, (bytes, bytearray)):
            return {"image_bytes": bytes(r.content),
                    "media_type": ctype or _sniff_media_type(bytes(r.content[:16])) or "image/png"}

        return {"json": {}}



# --------------------------------------------------------------------------
# Проверка видеопамяти
# --------------------------------------------------------------------------

_FREE_VALUE_RE = re.compile(r"(^|_)(free|available)(_|$)", re.I)


def _to_mb(value, key: str = "") -> int | None:
    """Значение из /vram → мегабайты.

    Сервер может отдать `free_mb: 20480`, `free_gb: 20.0`, `free: "20 GB"` или
    даже байты. Единицу берём из суффикса ключа, из строки («20 GB») или из
    величины (число > 1e6 без суффикса — это почти наверняка байты).
    """
    if value is None or isinstance(value, bool):
        return None

    key = (key or "").lower()
    mult = 1.0

    if re.search(r"_(gb|gib)$", key):
        mult = 1024.0
    elif re.search(r"_(bytes|b)$", key):
        mult = 1.0 / (1024 * 1024)

    if isinstance(value, str):
        m = re.search(r"(-?\d+(?:[.,]\d+)?)\s*(gib|gb|mib|mb|bytes|b)?", value, re.I)

        if not m:
            return None

        value = float(m.group(1).replace(",", "."))
        unit = (m.group(2) or "").lower()

        if unit in ("gb", "gib"):
            mult = 1024.0
        elif unit in ("bytes", "b"):
            mult = 1.0 / (1024 * 1024)
        elif unit in ("mb", "mib"):
            mult = 1.0

    try:
        num = float(value)
    except (TypeError, ValueError):
        return None

    if mult == 1.0 and abs(num) > 1_000_000:
        mult = 1.0 / (1024 * 1024)  # голое число такого размера — байты

    return int(round(num * mult))


def _maybe_gb(obj: dict, key: str, found: int) -> int:
    """Голое `free: 20` рядом с `total: 24` — это гигабайты, а не мегабайты.

    Если единицы не написаны в ключе и значение подозрительно мало для
    видеокарты (< 1 ГБ), смотрим на соседний «всего»: у GPU он тоже мал
    только тогда, когда меряют в гигабайтах.
    """
    if re.search(r"_(mb|mib|gb|gib|bytes|b)$", (key or "").lower()):
        return found

    if found <= 0 or found >= 1024:
        return found

    for tkey, tval in obj.items():
        if re.match(r"^(total|capacity|size)", str(tkey), re.I):
            total = _to_mb(tval, str(tkey))

            if total is not None and 0 < total < 1024:
                return found * 1024

            break

    return found


def _free_from_dict(obj: dict) -> int | None:
    """Свободная память на одном уровне ответа /vram."""
    # 1) известные имена ключей
    for key in ("free_mb", "free_vram_mb", "available_mb", "free_memory_mb",
                "vram_free_mb", "gpu_free_mb", "free_gb", "available_gb",
                "free", "available", "free_vram", "vram_free", "free_memory"):
        if key in obj:
            found = _to_mb(obj.get(key), key)

            if found is not None:
                return _maybe_gb(obj, key, found)

    # 2) любое имя со словом free/available (physical_free_mb, …)
    for key, value in obj.items():
        if _FREE_VALUE_RE.search(str(key)):
            found = _to_mb(value, str(key))

            if found is not None:
                return _maybe_gb(obj, str(key), found)

    return None


def _find_free_mb(obj, depth: int = 0) -> int | None:
    """Найти свободную видеопамять в ответе /vram любой вложенности."""
    if depth > 3 or obj is None:
        return None

    if isinstance(obj, (list, tuple)):
        for item in obj:
            found = _find_free_mb(item, depth + 1)
            if found is not None:
                return found
        return None

    if not isinstance(obj, dict):
        return None

    found = _free_from_dict(obj)

    if found is not None:
        return found

    # вложенные блоки (memory / vram / gpu / gpus / devices / …)
    for key, value in obj.items():
        if isinstance(value, (dict, list, tuple)):
            found = _find_free_mb(value, depth + 1)

            if found is not None:
                return found

    return None


def _parse_vram(data) -> tuple[bool, int, str]:
    """(достаточно ли памяти, свободно МБ, текст состояния)."""
    if not isinstance(data, dict):
        return False, 0, "нечитаемый ответ /vram"

    free = _find_free_mb(data)

    if free is None:
        # API не отдал числа — считаем, что у него было мнение отдельным флагом
        if data.get("ok") is False or data.get("enough") is False:
            return False, 0, "память занята"
        return True, _vram_min_free_mb(), "нет данных /vram — пропускаем"

    need = _vram_min_free_mb()
    status = f"свободно {free} МБ (нужно ≥ {need} МБ)"

    loaded = data.get("model_loaded", data.get("loaded"))
    if isinstance(loaded, bool):
        status += ", модель загружена" if loaded else ", модель не загружена"

    return free >= need, free, status


async def _ensure_vram(ctx: HandlerContext, timeout: float) -> str:
    """Ждём свободную видеопамять. Возвращает 'ok' или описание штатной нехватки."""
    if not _vram_check():
        return "ok"

    vram_url = _api_url("/vram")
    if not vram_url:
        raise RuntimeError("NJ641_IMAGE_API не задан")

    deadline = time.monotonic() + _vram_wait_sec()
    first = True

    while True:
        try:
            async with httpx.AsyncClient(timeout=20, headers=_headers()) as client:
                r = await client.get(vram_url, follow_redirects=True)
                r.raise_for_status()
                data = r.json()
        except Exception as e:  # noqa: BLE001
            log.warning("nj641: /vram недоступен: %s", e)
            if first and not _vram_wait():
                return "ok"  # проверка включена, ожидание нет — пробуем как есть
            if time.monotonic() >= deadline:
                raise RuntimeError(f"бекенд не отвечает по /vram: {e}") from None
            await asyncio.sleep(_vram_poll_sec())
            first = False
            await ctx.heartbeat()
            continue

        enough, _free, status = _parse_vram(data)

        if enough:
            return "ok"

        if not _vram_wait() or time.monotonic() >= deadline:
            log.warning("nj641: памяти не хватает: %s", status)
            raise RuntimeError(f"на бекенде не хватает видеопамяти ({status})")

        log.info("nj641: ждём память — %s", status)

        # Этап виден в мониторе и на странице прогона; одновременно это пульс
        # задачи (важно: ожидание памяти длится минутами, свипер не должен
        # счесть живую задачу «молчащей»).
        if first:
            await ctx.progress("Жду видеопамять", str(status)[:80])

        await ctx.heartbeat()
        await asyncio.sleep(_vram_poll_sec())
        first = False


# --------------------------------------------------------------------------
# Сжатие и файлы
# --------------------------------------------------------------------------

def _shrink_image(raw: bytes, media_type: str) -> tuple[bytes, str]:
    """Уменьшить вес картинки, если она тяжёлая (Pillow опционален).

    1024×1024 PNG весит 1.5-4 МБ, в base64 — ещё на треть больше. Такое тело
    не проходит лимиты nginx/PHP на размер запроса, поэтому крупные картинки
    пересохраняем в WebP качества 88 (обычно в 3-6 раз меньше, визуально без
    разницы). Pillow в проде есть (нужен nj570); нет — отдаём как есть.
    """
    if len(raw) < 900 * 1024:
        return raw, media_type

    try:
        from io import BytesIO

        from PIL import Image
    except Exception:  # noqa: BLE001 — без Pillow работаем как раньше
        return raw, media_type

    try:
        im = Image.open(BytesIO(raw))
        im.load()

        if im.mode in ("RGBA", "LA", "P"):
            im = im.convert("RGB")

        out = BytesIO()
        im.save(out, format="WEBP", quality=88, method=4)
        webp = out.getvalue()

        if 0 < len(webp) < len(raw):
            return webp, "image/webp"
    except Exception as e:  # noqa: BLE001 — сжатие не важнее картинки
        log.warning("nj641: сжать картинку не удалось (%s) — отдаю как есть", e)

    return raw, media_type


def _media_dir() -> str:
    """Каталог сайта для файлов (NJ641_MEDIA_DIR) — необязательный режим."""
    return os.getenv("NJ641_MEDIA_DIR", "").strip()


def _store_local_file(run_id: int, raw: bytes, media_type: str) -> dict | None:
    """Записать картинку в каталог сайта (если NJ641_MEDIA_DIR задан).

    Возвращает метаданные для image_info: file/url/bytes/width/height/media_type.
    URL строится как NJ641_MEDIA_URL_BASE (по умолчанию /imagegen/file) + имя —
    так файл отдаёт сам сайт, а база хранит только метаданные.
    """
    base = _media_dir()

    if not base:
        return None

    try:
        import secrets
        from pathlib import Path

        d = Path(base)
        d.mkdir(parents=True, exist_ok=True)

        ext = {"image/png": "png", "image/jpeg": "jpg", "image/webp": "webp",
               "image/gif": "gif", "image/bmp": "bmp"}.get((media_type or "").lower(), "png")
        name = f"nj641-{int(run_id)}-{secrets.token_hex(5)}.{ext}"
        path = d / name
        path.write_bytes(raw)

        # Путь пишем ОТНОСИТЕЛЬНО корня сайта, если каталог — его часть
        # (storage/nj641): так сайт может найти и удалить файл, не зная
        # абсолютных путей воркера. Иначе — абсолютный путь.
        marker = "storage/nj641"
        rel    = str(path)
        cut    = rel.replace("\\", "/").rfind("/" + marker + "/")

        if cut != -1:
            rel = rel.replace("\\", "/")[cut + 1:]

        info = {
            "file": rel,
            "url": os.getenv("NJ641_MEDIA_URL_BASE", "/imagegen/file").rstrip("/") + "/" + name,
            "bytes": len(raw),
            "media_type": media_type or "image/png",
        }

        try:
            from PIL import Image  # type: ignore

            with Image.open(path) as im:
                info["width"], info["height"] = im.size
        except Exception:  # noqa: BLE001 — размеры не критичны
            pass

        return info
    except Exception as e:  # noqa: BLE001 — файл не важнее результата
        log.warning("nj641: записать файл в %s не удалось: %s", base, e)
        return None


async def _upload_image_to_site(run_id: int, raw: bytes, media_type: str, result: dict) -> bool:
    """Отдать картинку на сайт ФАЙЛОМ (multipart) — путь для http-режима.

    На треть легче base64 и не упирается в лимит длины JSON.
    """
    url = _site_endpoint("/imagegen/api/worker-upload")
    token = _site_token()

    if not url or not token:
        return False

    data = {
        "run_id": str(int(run_id)),
        "media_type": media_type or "image/png",
        "model": str(result.get("model") or "")[:160],
        "duration_ms": str(int(result.get("duration_ms") or 0)),
    }

    for attempt in (1, 2):
        try:
            async with httpx.AsyncClient(timeout=float(os.getenv("NJ641_UPLOAD_TIMEOUT", "25") or "25")) as client:
                r = await client.post(
                    url,
                    data=data,
                    files={"image": ("result.png", raw, media_type or "image/png")},
                    headers={"X-Worker-Token": token},
                )

            if r.status_code == 200:
                try:
                    j = r.json()
                except Exception:  # noqa: BLE001
                    j = {}

                if isinstance(j, dict) and j.get("ok"):
                    log.info("nj641: картинка #%d загружена файлом (%d байт)", run_id, len(raw))
                    return True

                log.warning("nj641: worker-upload -> сайт: %s", str(j)[:200])
            else:
                log.warning("nj641: worker-upload -> HTTP %s %s", r.status_code, r.text[:200])

            if r.status_code in (401, 403):
                return False
        except Exception as e:  # noqa: BLE001
            log.warning("nj641: worker-upload недоступен (попытка %d): %s",
                        attempt, e.__class__.__name__)

        await asyncio.sleep(1.5 * attempt)

    return False


# --------------------------------------------------------------------------
# Выгрузка результата
# --------------------------------------------------------------------------

async def _post_result_to_site(run_id: int, res: dict) -> bool:
    """Доставка результата на сайт (образец nj602._post_site).

    Возвращает True, если сайт подтвердил приём (HTTP 200 + ok). Две попытки
    на сетевой сбой. Даже если доставка не удалась, задача НЕ падает: итог
    остаётся в result очереди — сайт сам поднимет его через result_of().
    """
    url, token = _site_endpoint("/imagegen/api/worker-result"), _site_token()

    if not url:
        log.info("nj641: выгрузка результата ВЫКЛЮЧЕНА (нет NJ641_API_BASE / NJ120_API_URL)")
        return False

    if not token:
        log.info("nj641: выгрузка результата ВЫКЛЮЧЕНА (нет NJ641_SITE_TOKEN / WORKER_SITE_TOKEN / WORKER_API_TOKEN)")
        return False

    # Если картинка уже файлом (image_info.url) — мегабайты base64 в теле
    # запроса не нужны: сайту достаточно ссылки. Именно объём тела ломал
    # доставку и оставлял прогон без картинки.
    light = dict(res)
    info  = light.get("image_info") or {}

    if isinstance(info, dict) and info.get("url"):
        light["image_b64"] = ""

    payload = {"run_id": run_id, "result": {**light, "source": "json"}}

    for attempt in (1, 2):
        try:
            async with httpx.AsyncClient(timeout=float(os.getenv("NJ641_JSON_TIMEOUT", "20") or "20")) as client:
                r = await client.post(url, json=payload,
                                      headers={"X-Worker-Token": token})

            if r.status_code == 200:
                try:
                    j = r.json()
                except Exception:  # noqa: BLE001
                    j = {}

                if not (isinstance(j, dict) and j.get("error")):
                    log.info("nj641: worker-result #%d принят", run_id)
                    return True

                log.warning("nj641: worker-result -> сайт: %s",
                            str(j.get("error"))[:200])
            else:
                log.warning("nj641: worker-result -> HTTP %s %s",
                            r.status_code, r.text[:200])

            if r.status_code == 401:
                # токен точно неверный — ретраить бессмысленно
                return False
        except Exception as e:  # noqa: BLE001
            log.warning("nj641: worker-result недоступен (попытка %d): %s",
                        attempt, e.__class__.__name__)

        await asyncio.sleep(1.5 * attempt)

    return False


async def _apply_pg(ctx: HandlerContext, run_id: int, res: dict) -> bool:
    """Записать результат в nj_641_runs напрямую (pg-режим).

    Картинку пишем ФАЙЛОМ (NJ641_MEDIA_DIR), если каталог задан: база хранит
    только метаданные и URL, а сайт отдаёт файл fast-path'ом. Каталог не задан —
    кладём base64 в базу, как раньше (совместимость).
    """
    if ctx.pg is None:
        return False

    info = dict(res.get("image_info") or {})
    b64 = str(res.get("image_b64") or "")

    if b64 and not info.get("url"):
        raw = _decode_image_b64(b64)

        if raw:
            stored = _store_local_file(run_id, raw, str(info.get("media_type") or ""))

            if stored:
                info.update(stored)

    # Метаданные с URL кладём и в результат задачи: сайт поднимает прогон из
    # результата очереди (reconcile), и без url он счёл бы результат пустым.
    res["image_info"] = info

    keep_b64 = "" if info.get("url") else b64

    # Соединение берём С ТАЙМАУТОМ: если пул занят (другая долгая задача или
    # «утекшее» соединение), ждать его бесконечно нельзя — именно так прогон
    # застывал на «Сохраняю результат» и картинка не доходила. Не взяли за
    # PG_WAIT_SEC — идём HTTP-путём, задачу закрывает сайт через reconcile.
    pg_wait = float(os.getenv("NJ641_PG_WAIT_SEC", "10") or "10")

    try:
        conn = await asyncio.wait_for(ctx.pg.acquire(), timeout=pg_wait)
    except Exception as e:  # noqa: BLE001
        log.warning("nj641: соединение с БД не получил за %.0f с: %s — обхожусь без pg",
                    pg_wait, e.__class__.__name__)
        return False

    try:
        await asyncio.wait_for(conn.execute(
            "UPDATE nj_641_runs SET "
            "status = $2, image_b64 = $3, image_info = $4::jsonb, model = $5, "
            "duration_ms = $6, error = $7, finished_at = now() WHERE id = $1",
            run_id,
            str(res.get("status") or "done"),
            keep_b64 or None,
            json.dumps(info, ensure_ascii=False),
            str(res.get("model") or "")[:160] or None,
            int(res.get("duration_ms") or 0),
            (str(res.get("error"))[:900] if res.get("error") else None),
        ), timeout=20)
        return True
    except Exception as e:  # noqa: BLE001
        log.warning("nj641: pg-запись результата: %s", e)
        return False
    finally:
        try:
            await ctx.pg.release(conn)
        except Exception:  # noqa: BLE001
            pass


# --------------------------------------------------------------------------
# Генерация
# --------------------------------------------------------------------------

def _decode_image_b64(b64: str) -> bytes:
    try:
        return base64.b64decode(b64, validate=False)
    except Exception:  # noqa: BLE001
        return b""


def _sniff_media_type(raw: bytes) -> str:
    """Тип картинки по магии файла (OpenAI b64_json тип не сообщает)."""
    head = bytes(raw[:16])

    if head.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if head.startswith(b"\xff\xd8"):
        return "image/jpeg"
    if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "image/webp"
    if head[:6] in (b"GIF87a", b"GIF89a"):
        return "image/gif"
    if head.startswith(b"BM"):
        return "image/bmp"

    return ""


def _b64_media_type(b64: str) -> str:
    try:
        head = b64[:64]
        raw = base64.b64decode(head + "=" * (-len(head) % 4), validate=False)
    except Exception:  # noqa: BLE001
        return ""

    return _sniff_media_type(raw)


def _first_image_item(data: dict) -> tuple[str, dict]:
    """Ответ image-API → (строка картинки, элемент data[0]).

    Новый контракт OpenAI Images: {"data": [{"b64_json": "…"} | {"url": "…"}]}.
    Старые форматы ({image}, {images: []}, бинарный ответ) тоже принимаем —
    сервер мог остаться прежним.
    """
    item: dict = {}
    block = data.get("data")

    if isinstance(block, list) and block:
        first = block[0]

        if isinstance(first, dict):
            item = first
            image = first.get("b64_json") or first.get("url") or first.get("image") or ""
        elif isinstance(first, str):
            image = first
        else:
            image = ""

        if isinstance(image, str) and image:
            return image, item

    elif isinstance(block, dict):
        item = block
        image = block.get("b64_json") or block.get("url") or block.get("image") or ""

        if isinstance(image, str) and image:
            return image, item

    for key in ("image", "image_b64", "images", "image_url", "b64_json", "url"):
        value = data.get(key)

        if isinstance(value, list) and value:
            value = value[0]

            if isinstance(value, dict):
                item = item or value
                value = value.get("b64_json") or value.get("url") or value.get("image")

        if isinstance(value, str) and value:
            return value, item

    return "", item


def _final_image(resp: dict, fallback_seed, fallback_model: str = "") -> dict:
    """Привести ответ внешнего API к {'image_b64','image_info','model'}."""
    if resp.get("image_bytes"):
        raw = resp["image_bytes"]
        return {
            "image_b64": base64.b64encode(raw).decode(),
            "image_info": {
                "media_type": resp.get("media_type") or _sniff_media_type(raw) or "image/png",
                "width": None,
                "height": None,
            },
            "model": fallback_model,
        }

    data = resp.get("json") or {}

    if not isinstance(data, dict):
        raise _ApiError("внешний API вернул неожиданный ответ")

    image, item = _first_image_item(data)

    if not image:
        error = data.get("error") or data.get("detail") or "внешний API вернул пустую картинку"

        if isinstance(error, (dict, list)):
            error = json.dumps(error, ensure_ascii=False)

        raise _ApiError(str(error)[:300])

    media = str(item.get("media_type") or data.get("media_type") or "").strip()

    info = {
        "media_type": media or "image/png",
        "seed": (item.get("seed") if item.get("seed") is not None else
                 data.get("seed") if data.get("seed") is not None else fallback_seed),
        "width": item.get("width") if item.get("width") is not None else data.get("width"),
        "height": item.get("height") if item.get("height") is not None else data.get("height"),
    }

    # FLUX может вернуть переписанный промпт — сохраняем: по нему видно, что
    # именно модель поняла из описания.
    if isinstance(item.get("revised_prompt"), str) and item["revised_prompt"]:
        info["revised_prompt"] = item["revised_prompt"][:500]

    model = str(data.get("model") or item.get("model") or fallback_model or "")

    # строка может быть base64, data: URL или http(s) URL
    if image.startswith("data:"):
        body = image.split(",", 1)[-1] if "," in image else ""
        m = re.match(r"^data:([^;,]+)", image)

        if m:
            info["media_type"] = m.group(1)
        elif not media:
            info["media_type"] = _b64_media_type(body) or info["media_type"]

        return {"image_b64": body, "image_info": info, "model": model}

    if image.startswith(("http://", "https://")):
        return {"image_b64": "", "image_info": {**info, "url": image}, "model": model}

    if not media:
        info["media_type"] = _b64_media_type(image) or info["media_type"]

    return {"image_b64": image, "image_info": info, "model": model}


def _prompt_with_negative(prompt: str, negative: str) -> str:
    """Отрицательный промпт → хвост описания.

    В контракте OpenAI Images отдельного negative_prompt нет — схема сервера
    его не принимает. Чтобы поле «Что исключить» на странице не терялось
    молча, дописываем его к описанию явной строкой. Отдельный negative-канал
    модели T2I понимают лучше, но это честнее, чем выбросить ввод.
    """
    negative = (negative or "").strip()

    if not negative:
        return prompt

    return f"{prompt}\n\nAvoid: {negative}"


def _size_str(payload: dict) -> str:
    """Размер в формате OpenAI Images: «1024x1024»."""
    try:
        width = int(payload.get("width") or 1024)
        height = int(payload.get("height") or 1024)
    except (TypeError, ValueError):
        width = height = 1024

    width = max(64, min(width, 4096))
    height = max(64, min(height, 4096))

    return f"{width}x{height}"


def _seed_of(payload: dict) -> int:
    try:
        return max(0, int(payload.get("seed") or 0))
    except (TypeError, ValueError):
        return 0


def _image_files(images: list) -> list:
    """Изображения из payload → multipart-части `image` (OpenAI: image[])."""
    ext_by_type = {"image/png": "png", "image/jpeg": "jpg", "image/webp": "webp",
                   "image/gif": "gif", "image/bmp": "bmp"}
    files = []

    for n, img in enumerate(images):
        raw = _decode_image_b64(str(img.get("b64") or ""))

        if not raw:
            continue

        media = str(img.get("media_type") or "") or _sniff_media_type(raw) or "image/png"
        ext = ext_by_type.get(media.lower(), "png")
        files.append(("image", (f"image{n}.{ext}", raw, media)))

    return files


def _multipart_fields(payload: dict) -> dict:
    """Общие поля формы /v1/images/edits (все значения — строки)."""
    fields = {
        "prompt": _prompt_with_negative(str(payload.get("prompt") or ""),
                                        str(payload.get("negative_prompt") or "")),
        "model": _image_model(payload),
        "n": "1",
        "size": _size_str(payload),
        "response_format": _response_format(),
    }

    seed = _seed_of(payload)
    steps = _image_steps(payload)

    if seed:
        fields["seed"] = str(seed)
    if steps:
        fields["steps"] = str(steps)

    return fields


async def _do_generate(ctx: HandlerContext, payload: dict) -> dict:
    mode = str(payload.get("mode") or "generate")

    if mode == "edit":
        images = [i for i in (payload.get("images") or [])
                  if isinstance(i, dict) and i.get("b64")]
        if len(images) != 1:
            raise RuntimeError("для edit нужно ровно одно изображение")

        files = _image_files(images)
        if not files:
            raise RuntimeError("изображение для edit не читается")

        await ctx.progress("Правлю изображение", "проверяю видеопамять")
        await _ensure_vram(ctx, TIMEOUT_GEN)

        # Новый API правит картинку через OpenAI Images /v1/images/edits.
        # Параметр strength схемой не предусмотрен — сила правки теперь
        # задаётся самим описанием.
        resp = await _call_api_watched(ctx, "/v1/images/edits", timeout=TIMEOUT_GEN,
                                       files=files, data=_multipart_fields(payload))

    elif mode == "multi-reference":
        imgs = [i for i in (payload.get("images") or [])
                if isinstance(i, dict) and i.get("b64")][:3]
        if not imgs:
            raise RuntimeError("для multi-reference нет референсов")

        files = _image_files(imgs)
        if not files:
            raise RuntimeError("референсы не читаются")

        await ctx.progress("Собираю из референсов", f"картинок: {len(files)}")
        await _ensure_vram(ctx, TIMEOUT_GEN)

        # Референсы — тот же /v1/images/edits: OpenAI принимает массив image[].
        resp = await _call_api_watched(ctx, "/v1/images/edits", timeout=TIMEOUT_GEN,
                                       files=files, data=_multipart_fields(payload))

    else:
        seed = _seed_of(payload)
        steps = _image_steps(payload)

        await ctx.progress("Генерирую изображение", "проверяю видеопамять")
        await _ensure_vram(ctx, TIMEOUT_GEN)
        await ctx.progress("Генерирую изображение", "модель рисует")

        body = {
            "prompt": _prompt_with_negative(str(payload.get("prompt") or ""),
                                            str(payload.get("negative_prompt") or "")),
            "model": _image_model(payload),
            "n": 1,
            "size": _size_str(payload),
            "response_format": _response_format(),
        }

        if seed:
            body["seed"] = seed
        if steps:
            body["steps"] = steps

        resp = await _call_api_watched(ctx, "/v1/images/generations",
                                       timeout=TIMEOUT_GEN, json_body=body)

    log.info("nj641: %s готово", mode)

    return _final_image(resp, _seed_of(payload), _image_model(payload))


async def _do_clear() -> dict:
    # POST /clear — сброс кэша/выгрузка модели; тело не требуется.
    await _call_api("/clear", timeout=120)
    return {}



# --------------------------------------------------------------------------
# Главный обработчик канала
# --------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    t0 = time.monotonic()

    result: dict = {"run_id": run_id, "ok": True, "status": "done",
                    "image_b64": "", "image_info": {}, "model": "", "error": ""}

    try:
        await ctx.progress("Готовлю генерацию", f"прогон {run_id}")

        gen = await _do_generate(ctx, payload)

        # Сжимаем тяжёлую картинку ДО доставки: base64 на 2-4 МБ не проходит
        # через лимиты тела запроса — именно на этом результат «терялся».
        raw = _decode_image_b64(str(gen.get("image_b64") or ""))

        if raw:
            info  = dict(gen.get("image_info") or {})
            media = str(info.get("media_type") or "image/png")
            shrunk, media = _shrink_image(raw, media)

            if len(shrunk) < len(raw):
                gen["image_b64"] = base64.b64encode(shrunk).decode()
                info["media_type"] = media
                info["bytes"] = len(shrunk)
                gen["image_info"] = info

        result.update(gen)
        result["duration_ms"] = int((time.monotonic() - t0) * 1000)

        await ctx.progress("Сохраняю результат", f"{len(result.get('image_b64') or '')} знаков base64")

        source = await _finalize(ctx, run_id, result)
        result["source"] = source

        if source == "" and not result.get("ok"):
            result["error"] = result.get("error") or "Результат не доставлен на сайт"

        return result

    except Exception as e:  # noqa: BLE001 — задача падает с причиной
        result.update({"ok": False, "status": "error", "error": str(e)[:900],
                       "duration_ms": int((time.monotonic() - t0) * 1000)})
        try:
            await _finalize(ctx, run_id, result)
        except Exception:  # noqa: BLE001
            log.warning("nj641: не удалось записать ошибку прогона %s", run_id)
        raise

async def handle_clear(ctx: HandlerContext, payload: dict) -> dict:
    await ctx.heartbeat()
    await _do_clear()
    return {"ok": True, "status": "done"}


async def _finalize(ctx: HandlerContext, run_id: int, result: dict) -> str:
    """Зафиксировать результат прогона всеми доступными каналами.

    Порядок (первый удавшийся — побеждает):
      1. pg-режим: прямой UPDATE nj_641_runs (картинка файлом, если задан
         NJ641_MEDIA_DIR) — самый надёжный путь, лимитов тела запроса нет;
      2. multipart-загрузка файла на сайт (http-режим);
      3. JSON base64 на /worker-result — только для мелких картинок.

    Возвращает источник доставки: 'pg' | 'upload' | 'json' | ''.

    В результат задачи (queue.complete) большие байты не попадают: сайт уже
    получил картинку первым или вторым путём, а мегабайты в JSON ломали
    закрытие задачи — она оставалась processing, страница висела.
    """
    if run_id <= 0:
        return ""

    budget = float(os.getenv("NJ641_DELIVER_TIMEOUT", "90") or "90")

    async def _deliver() -> str:
        source = ""

        # Каждый шаг доставки — отдельный этап в статус-ряде: по нему сразу
        # видно, где встало (раньше после «Сохраняю результат» была тишина, и
        # задача выглядела зависшей без объяснений).
        b64   = str(result.get("image_b64") or "")
        info  = dict(result.get("image_info") or {})
        media = str(info.get("media_type") or "image/png")
        raw   = _decode_image_b64(b64) if b64 else b""

        await ctx.progress("Сохраняю результат", "раскладываю по каналам")

        # 1) Файл в папку сайта (NJ641_MEDIA_DIR) — работает В ЛЮБОМ режиме
        #    очереди. Раньше сохранение было внутри pg-пути, поэтому в режиме
        #    http папка оставалась пустой (владелец: «в storage/nj641 его нет,
        #    права 777»), а картинка «терялась».
        if raw and not info.get("url"):
            stored = _store_local_file(run_id, raw, media)

            if stored:
                info.update(stored)
                result["image_info"] = info
                source = "file"
                log.info("nj641: картинка прогона #%d — файл %s", run_id, stored.get("file"))

        # 2) pg-режим: прямой UPDATE метаданных (мгновенно видно в UI).
        if await _apply_pg(ctx, run_id, result):
            source = source + "+pg" if source else "pg"

        # 3) HTTP-доставка метаданных. Файл уже на диске — не грузим его
        #    повторно (upload), а отдаём ссылку лёгким JSON'ом.
        if source == "" and raw:
            await ctx.progress("Отправляю картинку на сайт", "файлом")

            if await _upload_image_to_site(run_id, raw, media, result):
                source = "upload"

        if source in ("file", "file+pg") and info.get("url"):
            await ctx.progress("Отправляю картинку на сайт", "ссылкой на файл")

            if await _post_result_to_site(run_id, result):
                source = source + "+json"
        elif source == "" and info.get("url") and not raw:
            # image-API ответил ссылкой (NJ641_RESPONSE_FORMAT=url): байтов у
            # нас нет, но ссылку сайт должен получить — лёгкий JSON проходит
            # любые лимиты тела.
            await ctx.progress("Отправляю картинку на сайт", "ссылкой")

            if await _post_result_to_site(run_id, result):
                source = "json"
        elif source == "" and b64:
            if len(b64) <= INLINE_MAX_BYTES:
                await ctx.progress("Отправляю картинку на сайт", "ответом задачи")

                if await _post_result_to_site(run_id, result):
                    source = "json"
            else:
                log.warning("nj641: base64 %d байт больше inline-лимита — доставка только "
                            "через очередь (NJ641_API_BASE/MEDIA_DIR не настроены?)", len(b64))

        # Картинку убираем из результата очереди, только если она уже
        # гарантированно лежит на сайте (ссылка дошла) — иначе оставляем как
        # последний канал: сайт поднимет её через reconcile().
        if source.endswith("json") or source == "upload":
            result["image_b64"] = ""

        return source

    try:
        source = await asyncio.wait_for(_deliver(), timeout=budget)
    except asyncio.TimeoutError:
        # Жёсткий предел: доставка не важнее того, чтобы задача ЗАВЕРШИЛАСЬ.
        # Не успели — base64 остаётся в результате очереди, сайт поднимет его
        # через reconcile() и покажет картинку.
        log.warning("nj641: доставка результата #%d не уложилась в %.0f с — "
                    "результат уйдёт через очередь", run_id, budget)
        return ""

    if source == "":
        await ctx.progress("Результат уйдёт через очередь", "сайт поднимет его сам")

    return source
