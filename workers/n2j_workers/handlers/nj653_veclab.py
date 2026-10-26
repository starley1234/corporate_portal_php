"""nj653.embed — «Лаборатория векторов» (nj653_veclab): проверка и показ
мультимодальной эмбеддинг-модели (текст и картинки в одном пространстве).

Четыре режима (payload['mode']):

  probe    самопроверка модели: какой формат запроса она понимает, какая
           размерность, сколько миллисекунд на вектор, связывает ли она текст
           с картинкой ВООБЩЕ (на двух нарисованных прямо здесь картинках);
  add      текст и/или картинка → вектор → сайт кладёт его в коллекцию;
  search   запрос (текст и/или картинка) → вектор → сайт ищет по коллекции;
  compare  два входа → два вектора и косинус между ними.

Сам поиск по векторам делает САЙТ (pgvector, `Service_Nj653_Store`): воркер
считает векторы и отдаёт их на приёмку. Так в одном месте и хранилище, и
права доступа, а воркер остаётся без состояния.

Кадры в payload не едут: как и у VLM-сервисов, воркер забирает их отдельным
запросом `/veclab/api/photo_file` (base64 в jsonb общей очереди — это
мегабайты на каждый опрос очереди).

Env: EMBEDDING_MM_* (см. `n2j_workers/mmembed.py`), NJ653_API_BASE (или общий
NJ120_API_BASE) и NJ653_SITE_TOKEN (или WORKER_SITE_TOKEN / WORKER_API_TOKEN).
"""

from __future__ import annotations

import base64
import io
import logging
import time

from .. import mmembed, site
from ..base import HandlerContext
from ..mmembed import EmbedInput, MmEmbedError, MmEmbeddingClient, cosine

log = logging.getLogger("n2j.nj653")

CHANNEL = "nj653.embed"
PREFIX = "NJ653"

MODES = ("probe", "add", "search", "compare")

#: Тексты самопроверки: две близкие по смыслу фразы и одна чужая.
PROBE_TEXTS = {
    "a": "рыжий кот спит на диване у окна",
    "b": "кошка дремлет на диване рядом с окном",
    "far": "декларация по налогу на добавленную стоимость за третий квартал",
}

#: Что рисуем для кросс-модальной пробы: подпись → (фигура, цвет).
PROBE_SHAPES = {
    "красный круг на белом фоне": ("circle", (220, 38, 38)),
    "синий квадрат на белом фоне": ("square", (37, 99, 235)),
}


# --------------------------------------------------------------------------
# Картинки самопроверки
# --------------------------------------------------------------------------

def draw_shape(shape: str, color: tuple[int, int, int], size: int = 224) -> str:
    """Нарисовать простую фигуру и вернуть её base64 (PNG).

    Зачем рисовать, а не брать файл: проба должна работать на голом сервере
    воркера, без картинок в репозитории и без похода в сеть. Фигура нарочно
    примитивная — если модель не отличает красный круг от синего квадрата,
    дальше проверять нечего.
    """
    from PIL import Image, ImageDraw          # noqa: PLC0415 — опциональная зависимость

    img = Image.new("RGB", (size, size), (255, 255, 255))
    pen = ImageDraw.Draw(img)
    pad = size // 6

    if shape == "circle":
        pen.ellipse([pad, pad, size - pad, size - pad], fill=color)
    else:
        pen.rectangle([pad, pad, size - pad, size - pad], fill=color)

    buf = io.BytesIO()
    img.save(buf, format="PNG")

    return base64.b64encode(buf.getvalue()).decode("ascii")


# --------------------------------------------------------------------------
# Сайт
# --------------------------------------------------------------------------

async def fetch_photo(ctx: HandlerContext, run_id: int, slot: int) -> tuple[str, str]:
    """Кадр прогона с сайта: (base64, media_type)."""
    ok, why, data = await site.post_json(
        PREFIX, "/photo_file", {"run_id": int(run_id), "slot": int(slot)})

    if not ok or not data.get("image_b64"):
        raise RuntimeError(f"не удалось забрать картинку {slot} прогона {run_id}: "
                           f"{why or 'пустой ответ сайта'}")

    await ctx.progress("Картинка с сайта", f"слот {slot}, {int(data.get('kb') or 0)} КБ")

    return str(data.get("image_b64") or ""), str(data.get("media_type") or "image/jpeg")


async def deliver(payload: dict) -> tuple[bool, str]:
    """Отдать результат сайту. Молчаливый отказ доставки запрещён."""
    ok, why, _ = await site.post_json(PREFIX, "/worker_result", payload,
                                      timeout=60, retries=2, json_body=True)

    if not ok:
        log.warning("nj653: сайт не принял результат прогона %s: %s",
                    payload.get("run_id"), why)

    return ok, why


# --------------------------------------------------------------------------
# Входы прогона
# --------------------------------------------------------------------------

async def _input_of(ctx: HandlerContext, run_id: int, spec: dict) -> EmbedInput:
    """Описание входа из payload → EmbedInput (картинка подтягивается с сайта)."""
    text = str(spec.get("text") or "")
    image_b64 = ""
    media = "image/jpeg"

    if spec.get("has_image"):
        image_b64, media = await fetch_photo(ctx, run_id, int(spec.get("slot") or 1))

    return EmbedInput(text=text, image_b64=image_b64, media_type=media,
                      instruction=str(spec.get("instruction") or ""))


def _client() -> MmEmbeddingClient:
    client = MmEmbeddingClient.from_env()

    if client is None:
        raise RuntimeError(
            "эмбеддинг-сервер не настроен: задайте EMBEDDING_MM_API_URL "
            "(или общий EMBEDDING_API_URL) в workers/.env и перезапустите воркер")

    return client


# --------------------------------------------------------------------------
# Режимы
# --------------------------------------------------------------------------

# Пороги повторяемости. Единицы не бывает даже у одного и того же входа:
# квантованная GGUF-сборка считает матрицы с разным разбиением на батчи, и
# вектор воспроизводится с точностью около четвёртого знака. Беда — это не
# 0,998, а 0,9 и ниже: тогда вход действительно считается вместе с соседями
# по запросу (мы такое уже ловили) или сервер подменяет модель.
REPEAT_SAME = 0.9995     # бит-в-бит или почти
REPEAT_CLOSE = 0.99      # нормальный разброс квантования


def _repeat_check(key: str, title: str, value: float, what: str) -> dict:
    """Проверка повторяемости с тремя исходами: точно, почти, беда."""
    if value >= REPEAT_SAME:
        level, note = "ok", f"один и тот же {what} даёт один и тот же вектор"
    elif value >= REPEAT_CLOSE:
        level, note = "warn", (
            f"один и тот же {what} даёт ПОЧТИ один и тот же вектор — обычное дело "
            f"для квантованной сборки (разное разбиение на батчи). На поиск это не "
            f"влияет; если нужна точная воспроизводимость, запускайте сервер с "
            f"одним слотом (--parallel 1) и фиксированным dimensions")
    else:
        level, note = "fail", (
            f"один и тот же {what} дал РАЗНЫЕ векторы — сервер считает вход вместе "
            f"с соседями по запросу; проверьте EMBEDDING_MM_BATCH=1 и --parallel 1")

    return {
        "key": key,
        "title": title,
        "value": round(value, 4),
        # ok=False только для настоящей беды: жёлтая оговорка не должна
        # превращать рабочую модель в «непригодную».
        "ok": level != "fail",
        "level": level,
        "note": note,
    }


async def run_probe(ctx: HandlerContext, client: MmEmbeddingClient) -> dict:
    """Самопроверка модели: формат, размерность, скорость, смысл."""
    await ctx.progress("Проверяю формат запроса", "перебираю диалекты")

    # Сначала спрашиваем сервер, что у него вообще загружено: без этого
    # «ни один формат не подошёл» звучит как вина формата, хотя чаще всего
    # на сервере просто не загружена модель.
    models, models_error = await client.list_models()

    attempts = await client.detect()
    checks: list[dict] = []

    # 1. Повторяемость: один и тот же текст обязан дать один и тот же вектор.
    t0 = time.monotonic()
    first = await client.embed_one(EmbedInput(text=PROBE_TEXTS["a"]))
    text_ms = int((time.monotonic() - t0) * 1000)
    again = await client.embed_one(EmbedInput(text=PROBE_TEXTS["a"]))
    same = cosine(first, again)

    checks.append(_repeat_check(
        "repeat", "Повторяемость: один текст дважды", same, "текст"))

    # 2. Смысл в тексте: близкие фразы ближе, чем чужая.
    await ctx.progress("Проверяю смысл", "три текста")
    near = cosine(first, await client.embed_one(EmbedInput(text=PROBE_TEXTS["b"])))
    far = cosine(first, await client.embed_one(EmbedInput(text=PROBE_TEXTS["far"])))

    checks.append({
        "key": "text",
        "title": "Смысл текста: «кот на диване» против «декларации по НДС»",
        "value": round(near - far, 4),
        "ok": near > far,
        "note": f"близкая фраза {near:.3f}, чужая {far:.3f} — разрыв должен быть в плюс",
    })

    # 3. Кросс-модальность: текст и картинка в одном пространстве.
    cross: dict = {"ok": False, "note": "", "pairs": []}

    try:
        await ctx.progress("Рисую картинки", "красный круг и синий квадрат")
        labels = list(PROBE_SHAPES)
        images = [draw_shape(*PROBE_SHAPES[label]) for label in labels]
        image_vectors = [
            await client.embed_one(EmbedInput(image_b64=b64, media_type="image/png"))
            for b64 in images
        ]
        text_vectors = [await client.embed_one(EmbedInput(text=label)) for label in labels]

        # Повторяемость картинки. Текст прощает серверу мелкие вольности, а
        # картинка — нет: пока входы ездили пачкой, один и тот же кадр давал
        # 0,77 сам с собой. Проверку видно на странице, чтобы такая беда
        # больше не выглядела «особенностью модели».
        again_image = await client.embed_one(
            EmbedInput(image_b64=images[0], media_type="image/png"))
        same_image = cosine(image_vectors[0], again_image)

        checks.append(_repeat_check(
            "repeat_image", "Повторяемость: одна картинка дважды", same_image, "кадр"))

        grid = [[round(cosine(t, i), 4) for i in image_vectors] for t in text_vectors]
        hits = sum(1 for n, row in enumerate(grid) if row.index(max(row)) == n)

        cross = {
            "ok": hits == len(labels),
            "labels": labels,
            "grid": grid,
            "note": (f"каждая подпись выбрала свою картинку ({hits} из {len(labels)})"
                     if hits == len(labels)
                     else f"подпись нашла свою картинку только {hits} раз из {len(labels)} — "
                          f"модель, похоже, не связывает текст и изображение"),
            "pairs": [{"label": labels[n], "own": grid[n][n],
                       "other": max(v for m, v in enumerate(grid[n]) if m != n)}
                      for n in range(len(labels))],
        }

        checks.append({
            "key": "cross",
            "title": "Текст и картинка в одном пространстве",
            "value": round(min(grid[n][n] for n in range(len(labels))), 4),
            "ok": cross["ok"],
            "note": cross["note"],
        })
    except ImportError:
        cross["note"] = ("Pillow не установлен на сервере воркера — кросс-модальную пробу "
                         "пропустили (pip install -r workers/requirements.txt)")
        checks.append({"key": "cross", "title": "Текст и картинка в одном пространстве",
                       "value": None, "ok": None, "note": cross["note"]})
    except (MmEmbedError, ValueError) as e:
        cross["note"] = f"картинки модель не приняла: {e}"
        checks.append({"key": "cross", "title": "Текст и картинка в одном пространстве",
                       "value": None, "ok": False, "note": cross["note"]})

    passport = client.describe()
    passport["models"] = models
    passport["models_error"] = models_error
    passport["model_known"] = bool(models) and (not client.model or client.model in models)
    passport["attempts"] = [a.as_dict() for a in attempts]
    passport["dims_real"] = len(first)
    passport["text_ms"] = text_ms

    ok = all(c["ok"] for c in checks if c["ok"] is not None)

    return {
        "mode": "probe",
        "title": "Проверка модели " + (client.model or "без имени"),
        "passport": passport,
        "checks": checks,
        "cross": cross,
        "model": client.model,
        "dims": len(first),
        # Диалект кладём и сюда: страница читает его из верхнего уровня
        # результата, и без этой строки она писала «формат не определён».
        "dialect": client.dialect,
        "elapsed_ms": text_ms,
        "ok": ok,
    }


async def run_vectors(ctx: HandlerContext, client: MmEmbeddingClient,
                      run_id: int, specs: list[dict], mode: str) -> dict:
    """Общий путь режимов add/search/compare: входы → векторы → результат."""
    items = [await _input_of(ctx, run_id, spec) for spec in specs]

    await ctx.progress("Считаю векторы", f"входов: {len(items)}")

    t0 = time.monotonic()
    vectors = await client.embed(items)
    elapsed = int((time.monotonic() - t0) * 1000)

    out: dict = {
        "mode": mode,
        "model": client.model,
        "dims": len(vectors[0]) if vectors else 0,
        "dialect": client.dialect,
        "elapsed_ms": elapsed,
        "kinds": [i.kind for i in items],
        "vectors": vectors,
    }

    if mode == "compare" and len(vectors) == 2:
        out["cosine"] = round(cosine(vectors[0], vectors[1]), 4)
        out["title"] = "Сравнение пары"
    elif mode == "search":
        out["title"] = "Поиск по смыслу"
        out["top"] = int(specs[0].get("top") or 5) if specs else 5
    else:
        out["title"] = (specs[0].get("label") if specs else "") or "Элемент коллекции"

    return out


# --------------------------------------------------------------------------
# Точка входа канала
# --------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    mode = str(payload.get("mode") or "probe").strip().lower()

    if not run_id:
        raise ValueError(f"{CHANNEL}: payload должен содержать run_id")

    if mode not in MODES:
        raise ValueError(f"{CHANNEL}: неизвестный режим {mode!r} (ожидали {', '.join(MODES)})")

    client = _client()
    t0 = time.monotonic()

    try:
        if mode == "probe":
            result = await run_probe(ctx, client)
        else:
            specs = [s for s in (payload.get("inputs") or []) if isinstance(s, dict)]

            if not specs:
                raise ValueError(f"{CHANNEL}: в режиме {mode} нужен хотя бы один вход")

            result = await run_vectors(ctx, client, run_id, specs, mode)
    except Exception as e:  # noqa: BLE001 — прогон обязан закрыться причиной, а не молчанием
        reason = f"{e.__class__.__name__}: {e}"
        log.warning("nj653: прогон %s (%s) не удался: %s", run_id, mode, reason)

        await deliver({"run_id": run_id, "status": "error",
                       "error": str(e)[:900], "mode": mode})
        raise
    finally:
        await client.close()

    result["run_id"] = run_id
    result["status"] = "done"
    result.setdefault("elapsed_ms", int((time.monotonic() - t0) * 1000))

    await ctx.progress("Отдаю результат сайту", f"режим {mode}")

    ok, why = await deliver(result)

    if not ok:
        raise RuntimeError(
            f"векторы посчитаны, но сайт их не принял: {why}. Проверьте "
            f"{PREFIX}_API_BASE (или общий NJ120_API_BASE) и {PREFIX}_SITE_TOKEN "
            f"(= WORKER_API_TOKEN сайта) в workers/.env")

    # В result задачи векторы не кладём: это мегабайты чисел в общей очереди,
    # а на сайте они уже лежат в своей таблице. Для монитора хватит сводки.
    summary = {k: v for k, v in result.items() if k != "vectors"}
    summary["vectors_count"] = len(result.get("vectors") or [])
    summary["ready"] = mmembed.ready()

    return summary
