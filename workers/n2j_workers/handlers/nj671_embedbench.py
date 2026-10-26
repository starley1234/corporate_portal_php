"""nj671.bench — «Стенд эмбеддингов» (nj671_embedbench): сравнение двух путей.

Канал гоняет ОДИН И ТОТ ЖЕ материал через два эмбеддинг-сервера и отдаёт сайту
готовый отчёт: метрики каждого пути, согласие пространств и код решения
(какой путь для какой задачи). Сайт ничего не считает — он показывает.

  payload = {run_id, corpus, quick}

Пути (оба задаются в workers/.env, друг друга не подменяют):

  текстовый       EMBEDDING_API_URL + EMBEDDING_MODEL  (OpenAI /v1/embeddings);
  мультимодальный EMBEDDING_MM_API_URL + EMBEDDING_MM_MODEL (mmembed, диалекты).

Доставка — `POST /embedbench/api/worker_result` с X-Worker-Token. Адрес берётся
из NJ671_API_BASE, иначе из общего корня сайта + `/embedbench/api`
(`site.module_api_base`), токен — NJ671_SITE_TOKEN → WORKER_SITE_TOKEN →
WORKER_API_TOKEN. Молчаливый отказ доставки запрещён: если сайт не принял
отчёт, обработчик поднимает ошибку с подсказкой, что проверить.

Пайплайн: материал корпуса → текстовый путь (документы, запросы, повтор) →
мультимодальный путь (то же + картинка↔слова) → согласие пространств →
решение → приёмка на сайте.
"""

from __future__ import annotations

import logging
import time

from .. import embbench, site
from ..base import HandlerContext

log = logging.getLogger("n2j.nj671")

CHANNEL = "nj671.bench"
PREFIX = "NJ671"

#: Куда идёт приёмка результата (путь от корня API модуля).
DELIVER_PATH = "/worker_result"


async def deliver(payload: dict) -> tuple[bool, str]:
    """Отдать отчёт сайту. Молчаливый отказ доставки запрещён."""
    base = site.module_api_base(PREFIX, "/embedbench/api")

    ok, why, _ = await site.post_json(PREFIX, DELIVER_PATH, payload,
                                      timeout=90, retries=2, json_body=True,
                                      base=base or None)

    if not ok:
        log.warning("nj671: сайт не принял отчёт прогона %s: %s",
                    payload.get("run_id"), why)

    return ok, why


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    corpus = str(payload.get("corpus") or "catalog").strip().lower()
    quick = bool(payload.get("quick"))
    started = time.monotonic()

    if run_id <= 0:
        raise ValueError(f"{CHANNEL}: в задаче нет run_id — результат некуда принять")

    async def progress(phase: str, note: str = "") -> None:
        await ctx.progress(phase, note)

    try:
        result = await embbench.run(corpus_key=corpus, quick=quick, progress=progress)
    except Exception as e:                            # noqa: BLE001 — прогон обязан закрыться причиной
        reason = f"{type(e).__name__}: {str(e)[:600]}"
        log.warning("nj671: прогон %s не удался: %s", run_id, reason)

        await deliver({"run_id": run_id, "status": "error", "error": reason,
                       "elapsed_ms": int((time.monotonic() - started) * 1000)})
        raise

    result["run_id"] = run_id
    result["elapsed_ms"] = int((time.monotonic() - started) * 1000)

    ok, why = await deliver(result)

    if not ok:
        raise RuntimeError(
            "отчёт посчитан, но сайт его не принял: "
            f"{why or 'пустой ответ'}. Проверьте NJ671_API_BASE (или общий "
            "NJ120_API_BASE) и NJ671_SITE_TOKEN (= WORKER_API_TOKEN сайта) "
            "в workers/.env")

    # В отчёт задачи идёт ПОЛНЫЙ отчёт — но без векторов и строк запросов он
    # весит ~5 КБ, а не десятки: это доказано замером, а не на глаз. Так дожим
    # (`Vision::_catch_up`) может восстановить результат, если доставка не
    # дошла, и страница не останется с одной сводкой.
    #
    # `summary_only` — контракт с сайтом. Раньше сюда клали только
    # {winner, text_ok, mm_ok}, и дожим перезаписывал ЭТИМ уже доставленный
    # отчёт: владелец 22.10.2026 на настроенном стенде читал «Ни один путь не
    # ответил — причина не записана». Флаг говорит прямо, лежит ли в записи
    # отчёт с метриками.
    decision = result.get("decision") or {}
    flat = dict(result)
    flat.update({
        "summary_only": False,
        "run_id": run_id,
        "winner": decision.get("winner"),
        # Плоские поля — для монитора очереди и для страницы истории: им нужен
        # вывод одной строкой, а не разбор engines.
        "text_ok": bool((result.get("engines") or {}).get("text", {}).get("ok")),
        "mm_ok": bool((result.get("engines") or {}).get("mm", {}).get("ok")),
        "agreement": result.get("agreement"),
    })

    return flat
