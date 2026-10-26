"""nj654.embed — «Похожие картинки» (nj654_similar): векторы банка и запросов.

Канал простой до предела, задач ровно две:

  kind="items"  пачка элементов банка → по вектору на каждый;
  kind="query"  поисковый запрос (текст, картинка или и то и другое) → вектор.

Сам поиск делает САЙТ (pgvector, `Service_Nj654_Bank::search`): воркер только
считает векторы и отдаёт их на приёмку `/similar/api/worker_result`. Так и
хранилище, и права доступа остаются в одном месте, а воркер — без состояния.

Картинки в payload не едут: воркер забирает их отдельным запросом
`/similar/api/photo_file` (base64 в jsonb общей очереди — это мегабайты на
каждый опрос).

Отличие от nj653: пачка элементов НЕ падает целиком из-за одной плохой
картинки. Человек загрузил двадцать файлов, один оказался битым — остальные
девятнадцать обязаны попасть в банк, а битый получит свою строку ошибки.

Env: EMBEDDING_MM_* (см. `n2j_workers/mmembed.py`), NJ654_API_BASE (или общий
NJ120_API_BASE) и NJ654_SITE_TOKEN (или WORKER_SITE_TOKEN / WORKER_API_TOKEN).
"""

from __future__ import annotations

import logging
import time

from .. import site
from ..base import HandlerContext
from ..mmembed import EmbedInput, MmEmbeddingClient

log = logging.getLogger("n2j.nj654")

CHANNEL = "nj654.embed"
PREFIX = "NJ654"

#: Подсказка модели. У мультимодальных эмбеддингов она заметно влияет на
#: выдачу: без неё запрос «кот» ищет текст про кота, а не картинку с котом.
INSTRUCTION = "Represent the user input for image retrieval."


# --------------------------------------------------------------------------
# Сайт
# --------------------------------------------------------------------------

async def fetch_photo(ctx: HandlerContext, kind: str, row_id: int) -> tuple[str, str]:
    """Картинка элемента или запроса с сайта: (base64, media_type)."""
    ok, why, data = await site.post_json(
        PREFIX, "/photo_file", {"kind": kind, "id": int(row_id)})

    if not ok or not data.get("image_b64"):
        raise RuntimeError(f"картинку {kind} {row_id} забрать не вышло: "
                           f"{why or 'пустой ответ сайта'}")

    await ctx.progress("Картинка с сайта", f"{kind} {row_id}, {int(data.get('kb') or 0)} КБ")

    return str(data.get("image_b64") or ""), str(data.get("media_type") or "image/jpeg")


async def deliver(payload: dict) -> tuple[bool, str]:
    """Отдать результат сайту. Молчаливый отказ доставки запрещён."""
    ok, why, _ = await site.post_json(PREFIX, "/worker_result", payload,
                                      timeout=60, retries=2, json_body=True)

    if not ok:
        log.warning("nj654: сайт не принял результат (%s): %s", payload.get("kind"), why)

    return ok, why


def _client() -> MmEmbeddingClient:
    client = MmEmbeddingClient.from_env()

    if client is None:
        raise RuntimeError(
            "эмбеддинг-сервер не настроен: задайте EMBEDDING_MM_API_URL "
            "(или общий EMBEDDING_API_URL) в workers/.env и перезапустите воркер")

    return client


async def _vector(ctx: HandlerContext, client: MmEmbeddingClient,
                  kind: str, row_id: int, text: str, has_image: bool) -> list[float]:
    """Вектор одной строки: текст, картинка или и то и другое."""
    image_b64, media = "", "image/jpeg"

    if has_image:
        image_b64, media = await fetch_photo(ctx, kind, row_id)

    if not text and not image_b64:
        raise ValueError("нечего считать: ни текста, ни картинки")

    return await client.embed_one(EmbedInput(
        text=text, image_b64=image_b64, media_type=media, instruction=INSTRUCTION))


# --------------------------------------------------------------------------
# Задачи
# --------------------------------------------------------------------------

async def run_items(ctx: HandlerContext, client: MmEmbeddingClient, payload: dict) -> dict:
    """Пачка элементов банка: по вектору на каждый, по ошибке на провалившийся."""
    rows = [r for r in (payload.get("items") or []) if isinstance(r, dict)]

    if not rows:
        raise ValueError(f"{CHANNEL}: в задаче items нет ни одного элемента")

    done: list[dict] = []
    failed = 0

    for n, row in enumerate(rows, 1):
        row_id = int(row.get("id") or 0)

        if not row_id:
            continue

        await ctx.progress("Считаю векторы", f"{n} из {len(rows)}")

        try:
            vector = await _vector(ctx, client, "item", row_id,
                                   str(row.get("text") or ""), bool(row.get("has_image")))
            done.append({"id": row_id, "vector": vector})
        except Exception as e:  # noqa: BLE001 — одна плохая картинка не роняет пачку
            failed += 1
            log.warning("nj654: элемент %s не посчитан: %s", row_id, e)
            done.append({"id": row_id, "error": f"{e.__class__.__name__}: {e}"[:400]})

    return {"kind": "items", "model": client.model, "items": done,
            "ok_count": len(done) - failed, "failed": failed}


async def run_query(ctx: HandlerContext, client: MmEmbeddingClient, payload: dict) -> dict:
    """Поисковый запрос: один вектор, поиск по банку делает сайт."""
    row_id = int(payload.get("id") or 0)

    if not row_id:
        raise ValueError(f"{CHANNEL}: в задаче query нет id")

    await ctx.progress("Считаю вектор запроса", "ищем похожее")

    vector = await _vector(ctx, client, "query", row_id,
                           str(payload.get("text") or ""), bool(payload.get("has_image")))

    return {"kind": "query", "id": row_id, "model": client.model, "vector": vector}


# --------------------------------------------------------------------------
# Точка входа канала
# --------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    kind = str(payload.get("kind") or "").strip().lower()

    if kind not in ("items", "query"):
        raise ValueError(f"{CHANNEL}: неизвестный вид задачи {kind!r} (ожидали items или query)")

    client = _client()
    t0 = time.monotonic()

    try:
        result = await (run_items(ctx, client, payload) if kind == "items"
                        else run_query(ctx, client, payload))
    except Exception as e:  # noqa: BLE001 — строка обязана закрыться причиной, а не молчанием
        log.warning("nj654: задача %s не удалась: %s", kind, e)

        await deliver({"kind": kind, "status": "error", "error": str(e)[:900],
                       "id": int(payload.get("id") or 0),
                       "items": [{"id": int(r.get("id") or 0), "error": str(e)[:400]}
                                 for r in (payload.get("items") or []) if isinstance(r, dict)]})
        raise
    finally:
        await client.close()

    result["elapsed_ms"] = int((time.monotonic() - t0) * 1000)

    ok, why = await deliver(result)

    if not ok:
        raise RuntimeError(
            f"векторы посчитаны, но сайт их не принял: {why}. Проверьте "
            f"{PREFIX}_API_BASE (или общий NJ120_API_BASE) и {PREFIX}_SITE_TOKEN "
            f"(= WORKER_API_TOKEN сайта) в workers/.env")

    # Векторы в отчёт задачи не кладём: это мегабайты чисел в общей очереди,
    # а на сайте они уже лежат в своей таблице.
    summary = {k: v for k, v in result.items() if k not in ("vector", "items")}
    summary["count"] = len(result.get("items") or ([1] if result.get("vector") else []))

    return summary
