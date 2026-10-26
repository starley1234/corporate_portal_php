"""VLM — общая половина работы сервисов «фото → vision-модель».

Зачем модуль. Первым фото к модели научился отправлять nj646 («Разбор
состава»), и механизм проверен вживую на /label. Дальше владелец (04.10.2026)
заказал ещё три таких сервиса и предупредил: «таких сервисов будет много».
Копировать в каждый обработчик выбор модели, сжатие кадра, разбор JSON и
доставку результата нельзя — это четыре места, где чинить одну ошибку
(правило AGENTS §17: общие инструменты воркеров не дублируются).

Что здесь:

* **ВЫБОР МОДЕЛИ С ОБЩИМ ДЕФОЛТОМ.** `model("NJ650")` ищет по порядку
  `NJ650_VISION_MODEL` → `VLM_MODEL` → пусто (= модель по умолчанию у
  провайдера). Поэтому новый VLM-сервис НЕ требует своей переменной: хватает
  одной общей `VLM_MODEL` в `workers/.env`; свой ключ нужен, только если этот
  сервис должен ходить к другой модели. Так же устроены потолок ответа
  (`VLM_MAX_TOKENS`) и ожидание (`VLM_TIMEOUT`).
* сжатие кадра до разумной ширины (только УМЕНЬШАЯ: апскейл деталей не
  создаёт, а платных патчей vision ест вчетверо больше — урок nj646);
* честная пометка «фото мелкое» вместо молчаливых выдумок модели;
* забор кадра с сайта (`/photo_file`) и доставка результата (`/worker_result`);
* готовый конвейер `run_photo_job()`: кадр → модель → строгий JSON → сайт.

Env (читать ТОЛЬКО внутри функций — правило воркеров):

  VLM_MODEL         общая vision-модель всех таких сервисов («провайдер::модель»)
  VLM_MAX_TOKENS    общий потолок ответа (по умолчанию 1200)
  VLM_TIMEOUT       общее ожидание модели, секунд (по умолчанию 180)
  <PREFIX>_VISION_MODEL / <PREFIX>_MAX_TOKENS / <PREFIX>_LLM_TIMEOUT —
                    исключения для конкретного сервиса, они главнее общих
  <PREFIX>_API_BASE / <PREFIX>_SITE_TOKEN — адрес и токен сайта (см. site.py)
"""

from __future__ import annotations

import asyncio
import base64
import logging
import os
import time
from typing import Callable

from . import site, webread
from .base import HandlerContext

log = logging.getLogger("n2j.vlm")

#: Ширина, до которой ужимаем большие кадры (мелкие не трогаем).
IMAGE_MAX_W = 1400

#: Меньше этого по большей стороне — модель уже не читает, а угадывает.
SMALL_PHOTO_PX = 800


# --------------------------------------------------------------------------
# Окружение: свой ключ сервиса → общий дефолт → дефолт провайдера
# --------------------------------------------------------------------------

def _first_env(*names: str) -> str:
    for name in names:
        value = (os.getenv(name) or "").strip()
        if value:
            return value
    return ""


def model(prefix: str, *fallback_prefixes: str) -> str:
    """Спека vision-модели для сервиса: «провайдер::модель» или «» (дефолт).

    Порядок: <PREFIX>_VISION_MODEL → <FALLBACK>_VISION_MODEL… → VLM_MODEL.
    Пустая строка — осознанный ответ «бери модель по умолчанию у провайдера»:
    так сервис работает сразу после установки, без единой настройки.
    """
    names = [p.upper() + "_VISION_MODEL" for p in (prefix, *fallback_prefixes) if p]
    return _first_env(*names, "VLM_MODEL")


def max_tokens(prefix: str, default: int = 1200) -> int:
    """Потолок ответа: <PREFIX>_MAX_TOKENS → VLM_MAX_TOKENS → default."""
    raw = _first_env(prefix.upper() + "_MAX_TOKENS", "VLM_MAX_TOKENS")

    try:
        return max(200, min(8000, int(raw))) if raw else default
    except ValueError:
        return default


def timeout(prefix: str, default: float = 180.0) -> float:
    """Ожидание модели, секунд: <PREFIX>_LLM_TIMEOUT → VLM_TIMEOUT → default.

    ВАЖНО: должно быть МЕНЬШЕ timeout_sec задачи (его ставит сайт), иначе
    воркера снимут ровно в момент ответа модели и на сайт не уедет ничего —
    ни результат, ни внятная ошибка (урок nj646: вечное «Распознаю…»).
    """
    raw = _first_env(prefix.upper() + "_LLM_TIMEOUT", "VLM_TIMEOUT")

    try:
        return max(30.0, min(1800.0, float(raw))) if raw else default
    except ValueError:
        return default


def photo_max_w(prefix: str, default: int = IMAGE_MAX_W) -> int:
    """До какого размера уменьшать фото по большей стороне, пикселей.

    <PREFIX>_PHOTO_MAX_PX → VLM_PHOTO_MAX_PX → default (1400 у VLM-сервисов,
    512 у nj570/nj640 — там меньше по ТЗ). Кадр меньше — vision-модель
    отвечает быстрее и дешевле (запрос владельца 06.10.2026: экономим время
    и токены); больше 1400 не нужно — браузер и так уменьшает кадр до 1400.
    """
    raw = _first_env(prefix.upper() + "_PHOTO_MAX_PX", "VLM_PHOTO_MAX_PX")

    try:
        return max(200, min(4000, int(raw))) if raw else default
    except ValueError:
        return default


# --------------------------------------------------------------------------
# Кадр
# --------------------------------------------------------------------------

def _prepare_sync(raw: bytes, max_w: int) -> tuple[bytes, dict]:
    """Привести кадр к «читаемому для модели» виду — только УМЕНЬШАЯ."""
    meta: dict = {"src": "", "out": ""}

    try:
        import io

        from PIL import Image

        img = Image.open(io.BytesIO(raw)).convert("RGB")
        w, h = img.size
        meta["src"] = f"{w}×{h}"

        if w > max_w:
            img = img.resize((max_w, max(1, round(h * max_w / w))), Image.LANCZOS)
            w, h = img.size

        meta["out"] = f"{w}×{h}"
        out = io.BytesIO()
        img.save(out, "JPEG", quality=88, optimize=True)
        return out.getvalue(), meta
    except Exception:  # noqa: BLE001 — нет Pillow или битый кадр: шлём как есть
        return raw, meta


async def prepare_b64(b64: str, max_w: int = IMAGE_MAX_W) -> tuple[str, dict]:
    """base64 кадра → (base64 сжатого, meta). Пустая строка — кадр не разобрался."""
    try:
        raw = base64.b64decode(b64)
    except Exception:  # noqa: BLE001
        return "", {}

    if not raw:
        return "", {}

    out, meta = await asyncio.to_thread(_prepare_sync, raw, max_w)

    return base64.b64encode(out).decode(), meta


def small_photo_warning(meta: dict) -> str:
    """Пометка «кадр мелкий» — или пустая строка.

    Честнее сказать человеку «снимите крупнее», чем выдать догадки модели за
    прочитанное (на мелком кадре она дорисовывает то, чего на фото нет).
    """
    try:
        w, h = (int(x) for x in str(meta.get("src") or "").split("×"))
    except ValueError:
        return ""

    if max(w, h) >= SMALL_PHOTO_PX:
        return ""

    return (f"кадр мелкий ({w}×{h}) — мелкие надписи в него не влезают, "
            f"модель могла угадывать; снимите крупнее")


def img_msg(b64: str, text: str, media_type: str = "image/jpeg") -> dict:
    """Сообщение «текст + картинка» в формате OpenAI-совместимого chat API."""
    mime = media_type if str(media_type).startswith("image/") else "image/jpeg"

    return {"role": "user", "content": [
        {"type": "text", "text": text},
        {"type": "image_url", "image_url": {"url": f"data:{mime};base64,{b64}"}},
    ]}


# --------------------------------------------------------------------------
# Сайт: кадр оттуда, результат туда
# --------------------------------------------------------------------------

async def fetch_photo(ctx: HandlerContext, prefix: str, run_id: int) -> tuple[str, str]:
    """Забрать кадр прогона с сайта: (base64, media_type).

    Кадр лежит файлом на сайте, в задаче едет только id прогона: base64 в
    jsonb ОБЩЕЙ очереди — это десяток мегабайт на каждую задачу, которые тянет
    каждый опрос воркера (урок nj646).
    """
    ok, why, data = await site.post_json(prefix, "/photo_file", {"run_id": int(run_id)})

    if not ok or not data.get("image_b64"):
        raise RuntimeError(f"не удалось забрать кадр прогона {run_id} с сайта: "
                           f"{why or 'пустой ответ'}")

    await ctx.progress("Кадр с сайта", f"{int(data.get('kb') or 0)} КБ")

    return str(data.get("image_b64") or ""), str(data.get("media_type") or "image/jpeg")


async def deliver(prefix: str, payload: dict) -> tuple[bool, str]:
    """Отдать результат прогона сайту. Возвращает (принят, причина отказа).

    Причина обязана быть видна: молчаливый отказ доставки — это и есть
    «модель ответила, а на сайте ничего» (нет токена, 403, сеть).
    """
    ok, reason, _ = await site.post_json(prefix, "/worker_result", payload,
                                         timeout=45, retries=2, json_body=True)

    if not ok:
        log.warning("vlm[%s]: сайт не принял результат прогона %s: %s",
                    prefix, payload.get("run_id"), reason)

    return ok, reason


# --------------------------------------------------------------------------
# Конвейер целиком
# --------------------------------------------------------------------------

async def run_photo_job(ctx: HandlerContext, payload: dict, *,
                        prefix: str,
                        channel: str,
                        system: str,
                        question: str,
                        shape: Callable[[dict, dict], dict],
                        stage: str = "Смотрю фото…",
                        default_tokens: int = 1200,
                        default_timeout: float = 180.0) -> dict:
    """Кадр → vision-модель → строгий JSON → сайт. Общий путь всех VLM-сервисов.

    Обработчику сервиса остаются только три своих вещи: системный промпт,
    вопрос к кадру и `shape(data, payload) -> dict` — проверка и приведение
    ответа модели к тому виду, который рисует страница. Всё остальное
    (кадр, модель, таймауты, разбор обрыва, доставка, рапорты в монитор)
    одинаково и живёт здесь.

    `shape` обязан падать с ValueError, если модель ответила бессмыслицей:
    тогда прогон закроется ошибкой с внятной причиной, а не «готово с пустым
    результатом».
    """
    run_id = int(payload.get("run_id") or 0)

    if not run_id:
        raise ValueError(f"{channel}: payload должен содержать run_id")
    if ctx.llm is None:
        raise RuntimeError(f"{channel}: LLM-провайдеры не настроены (workers/.env)")

    t0 = time.monotonic()
    spec = model(prefix)
    wait = timeout(prefix, default_timeout)
    tokens = max_tokens(prefix, default_tokens)

    b64 = str(payload.get("image_b64") or "")
    media = str(payload.get("media_type") or "image/jpeg")

    if not b64:
        b64, media_from_site = await fetch_photo(ctx, prefix, run_id)

        if not payload.get("media_type"):
            media = media_from_site

    if not b64:
        raise ValueError(f"{channel}: пришёл пустой кадр")

    await ctx.progress("Готовлю кадр…")
    await ctx.heartbeat()

    shrunk, meta = await prepare_b64(b64, photo_max_w(prefix))
    shrunk = shrunk or b64

    if meta.get("src"):
        size = str(meta["src"])

        if meta.get("out") and meta["out"] != size:
            size += " → " + str(meta["out"])

        await ctx.progress("Кадр к модели", size)

    await ctx.progress(stage, f"{spec or 'модель по умолчанию'} · до {int(wait)} с")
    await ctx.heartbeat()

    try:
        text, usage = await asyncio.wait_for(
            ctx.llm.chat_with_usage(
                [{"role": "system", "content": system},
                 img_msg(shrunk, question, media)],
                model=spec or None,
                max_tokens=tokens,
                temperature=0.1,
                request_timeout=wait,
            ),
            timeout=wait + 30,
        )
    except (asyncio.TimeoutError, TimeoutError):
        error = (f"Модель не ответила за {int(wait)} с "
                 f"(VLM_TIMEOUT или {prefix}_LLM_TIMEOUT).")
        await _fail(ctx, prefix, run_id, error, spec)
        raise RuntimeError(error)
    except Exception as e:  # noqa: BLE001 — прогон обязан стать терминальным
        error = f"Модель недоступна: {type(e).__name__}: {str(e)[:250]}"
        await _fail(ctx, prefix, run_id, error, spec)
        raise

    await ctx.progress("Модель ответила", f"{len(str(text or ''))} знаков")
    await ctx.heartbeat()

    data = webread.json_from_text(str(text or ""))

    if not isinstance(data, dict):
        hint = ""

        if str((usage or {}).get("_llm_finish_reason") or "") == "length":
            hint = (f" Ответ оборвался по лимиту токенов — поднимите "
                    f"{prefix}_MAX_TOKENS или общий VLM_MAX_TOKENS (сейчас {tokens}).")

        error = f"модель вернула не-JSON: {str(text)[:200]}{hint}"
        await _fail(ctx, prefix, run_id, error, spec)
        raise RuntimeError(error)

    try:
        result = dict(shape(data, payload))
    except ValueError as e:
        error = f"модель ответила мимо задачи: {e}"
        await _fail(ctx, prefix, run_id, error, spec)
        raise RuntimeError(error) from e

    warning = small_photo_warning(meta)

    if warning:
        note = str(result.get("note") or "")
        result["note"] = (note + " · " if note else "") + warning

    result.update({
        "run_id": run_id,
        "status": "done",
        "error": "",
        "model": spec or "default",
        "elapsed_ms": int((time.monotonic() - t0) * 1000),
        "usage": usage if isinstance(usage, dict) else {},
    })

    await ctx.progress("Отправляю результат на сайт…")
    delivered, reason = await deliver(prefix, result)
    result["delivered"] = delivered

    if delivered:
        await ctx.progress("Результат передан на сайт")
    else:
        # Результат остаётся в job.result — сайт заберёт его дожимом,
        # но причина отказа доставки обязана быть видна в мониторе.
        await ctx.progress("Сайт не принял результат", reason[:200])

    log.info("%s: прогон %s готов за %d мс, доставлено=%s",
             channel, run_id, result["elapsed_ms"], delivered)

    return result


async def _fail(ctx: HandlerContext, prefix: str, run_id: int,
                error: str, spec: str) -> None:
    """Закрыть прогон ошибкой на сайте — иначе страница «думает» вечно."""
    await ctx.progress("Не получилось", error[:150])
    await deliver(prefix, {"run_id": run_id, "status": "error",
                           "error": error, "model": spec or "default"})
