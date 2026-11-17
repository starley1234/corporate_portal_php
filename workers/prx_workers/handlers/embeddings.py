"""Канал 'embeddings.index': получение векторов через эмбеддинг-сервер.

Пайплайн: батч текстов из payload → эмбеддинг-сервер → векторы в результате задачи.

Ожидаемый payload:
    {"texts": ["текст 1", "текст 2"], "entity": "prx401_task", "ids": [1, 2]}

Результат задачи (попадает в prx_120_jobs.result):
    {"count": 2, "dimensions": 1024, "model": "...", "vectors_sample": [[...], ...]}

ВАЖНО: сохранение векторов в таблицы конкретного модуля — ответственность
отдельного специализированного канала/модуля (например prx115_semantic):
этот обработчик возвращает векторы в результат задачи. Для больших объёмов
сделайте свой обработчик, который пишет вектора в pgvector напрямую через
ctx (см. WORKER_PG_DSN в конфиге).
"""

from __future__ import annotations

from ..base import HandlerContext

CHANNEL = "embeddings.index"

# Сколько чисел вектора кладём в result задачи (для отладки; векторы — большие)
SAMPLE_DIMS = 8


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    texts = payload.get("texts") or []
    if isinstance(texts, str):
        texts = [texts]
    if not texts:
        raise ValueError("payload.texts обязателен (строка или список строк)")

    if ctx.embed is None:
        raise RuntimeError("EMBEDDING_API_URL не настроен — эмбеддинг-сервер недоступен")

    vectors = await ctx.embed.embed(texts)

    return {
        "count": len(vectors),
        "dimensions": len(vectors[0]) if vectors else ctx.embed.dimensions,
        "model": ctx.embed.model,
        "entity": payload.get("entity"),
        "ids": payload.get("ids"),
        "vectors_sample": [v[:SAMPLE_DIMS] for v in vectors],
    }
