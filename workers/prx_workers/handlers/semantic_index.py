"""Канал 'semantic.index': запись векторов в prx_115_vectors (модуль prx115_semantic).

Пайплайн: тексты и векторы из payload → запись в prx_115_vectors → счётчики в результате задачи.

Два режима, и оба ДОВОДЯТ работу до конца:

  * pg-режим (у воркера есть доступ к PostgreSQL) — пишем векторы сами, одной
    транзакцией: не гоняем большие массивы чисел через HTTP;
  * http-режим (воркер на отдельном сервере, базы у него нет) — мост: считаем
    векторы локально и отправляем их пачками на сайт, в POST /prx115/api/vectors,
    оттуда их пишет уже сайт (Service_Prx115_Semantic::store_vectors).

Раньше в http-режиме обработчик молча возвращал {"skipped": "http-mode"}: задача
формально «успешна», а векторов в базе нет — поиск незаметно деградировал до
ILIKE, и владелец не видел причины (задача 60, пункт 2). Молчаливого пропуска
больше нет: либо векторы записаны, либо задача падает с понятной причиной.

Ожидаемый payload (формирует Service_Prx115_Semantic::index_texts):
    {
      "collection": "prx401_tasks",
      "entity": "prx401_task",
      "items": [{"id": "12", "text": "...", "metadata": {...}}, ...]
    }

Поведение:
  * векторы получает эмбеддинг-сервер (ctx.embed);
  * тип колонки embedding определяется автоматически: vector (pgvector,
    запись '...]'::vector) или real[] (запрос списка float);
  * upsert по (collection_id, entity, entity_id);
  * в http-режиме тот же upsert делает сайт: пачки по SITE_BATCH штук уходят
    на POST /prx115/api/vectors с токеном воркера (X-Worker-Token).

Этапы, лимиты и почему так (владелец, 26.09: «процесс „Семантический индекс
(поиск)“ — на мониторе работ показано, что он всегда зависает, непонятно на
каком шаге и из-за чего»):

  1. «Проверяю коллекцию»  — тип колонки + id коллекции (короткое соединение,
     лимит ожидания пула 20 с: если он занят, честная ошибка, а не тишина);
  2. «Считаю векторы»      — вызов эмбеддинг-сервера ПОД ТИКЕРОМ: каждые 20 с
     в журнал уходит «модель думает N с»; по лимиту PRX115_EMBED_TIMEOUT
     (по умолчанию 180 с) вызов снимается с понятной причиной. Соединение с
     базой на время внешнего вызова НЕ держим: иначе пул обработчиков
     (4 соединения) выедается и «висит» уже соседняя задача;
  3. «Записываю векторы i/N» — одна транзакция, прогресс по ходу (в http-режиме
     сайт подтверждает каждую пачку, и строка выглядит как «i/N (через сайт)»);
  4. «Готово: N векторов»  — финальная строка, по ней видно успех в мониторе.

Отдельно: сообщение о нехватке эмбеддинг-сервера или таблицы — сразу с
подсказкой, что делать (EMBEDDING_API_URL / bin/console db:migrate), чтобы
владелец не искал причину по монитору.
"""

from __future__ import annotations

import asyncio
import hashlib
import json
import os

from .. import site
from ..base import HandlerContext
from ..llmwatch import wait_with_ticks

CHANNEL = "semantic.index"

# Сколько ждать свободное соединение пула обработчиков (секунд).
POOL_ACQUIRE_TIMEOUT = 20.0

# Префикс настроек канала: PRX115_API_BASE (+ PRX115_SITE_TOKEN → WORKER_SITE_TOKEN,
# → WORKER_API_TOKEN). Общий запасной адрес сайта — PRX120_API_BASE.
_PREFIX = "PRX115"

# Полный маршрут точки приёма векторов на сайте (POST /prx115/api/vectors).
_ROUTE = "/prx115/api/vectors"

# Мост в http-режиме: сколько векторов в одном запросе к сайту. Сайтовая точка
# принимает до 200 штук, но 1024 числа — это ~12 КБ на вектор, поэтому 50 штук
# держат запрос быстрым, а прогресс видимым.
SITE_BATCH = 50

# Лимит одного запроса к сайту с пачкой векторов: сеть плюс запись в базу на
# стороне сайта. Больше обычных post_json 60 с — пачке нужно время.
SITE_TIMEOUT = 120.0

# Прогресс записи: строка в журнал каждые N записанных векторов.
WRITE_PROGRESS_EVERY = 50


def _embed_timeout() -> float:
    """Лимит ожидания эмбеддинг-сервера (env PRX115_EMBED_TIMEOUT, секунд)."""
    try:
        value = float(os.environ.get("PRX115_EMBED_TIMEOUT") or 180)
    except ValueError:
        value = 180.0

    # Нижняя граница — 1 с: дефолт (180 с) задаёт прод, а не тесты и не
    # ручная настройка (владелец может поставить любой разумный лимит).
    return max(1.0, value)


def content_hash(text: str) -> str:
    """md5 текста вектора — тот же ключ, что считает сайт (PHP md5) и SQL (md5).

    Нужен, чтобы «текст не менялся» понимали все три стороны: сайт пропускает
    неизменившиеся тексты при повторной индексации, а панель /prx115 считает
    покрытие одним запросом, не выгружая тексты в PHP.
    """
    return hashlib.md5(str(text).encode("utf-8")).hexdigest()


async def _embed_texts(ctx: HandlerContext, items: list, total: int) -> list[list[float]]:
    """Считает векторы эмбеддинг-сервером ПОД ТИКЕРОМ и с жёстким лимитом времени.

    Соединение с базой на время внешнего вызова не держим: иначе пул обработчиков
    выедается и «висит» уже соседняя задача. Каждые 20 с в журнал уходит строка
    «модель думает N с» — по ней видно, что задача жива.
    """
    texts = [str(it.get("text", "")) for it in items]
    limit = _embed_timeout()

    async def _embed() -> list[list[float]]:
        return list(await ctx.embed.embed(texts))

    async def _tick(waited: int, limit_left: float | None) -> None:
        tail = f" (лимит {int(limit_left)} с)" if limit_left else ""
        await ctx.progress(
            "Считаю векторы",
            f"модель думает {waited} с{tail}… текстов: {total}",
        )

    # Этап в журнал ДО вызова: даже если сервер ответит мгновенно, в мониторе
    # видно, на чём задача стояла (а не только «Проверяю коллекцию» → «Готово»).
    await ctx.progress("Считаю векторы", f"{total} текстов через {ctx.embed.model}")

    try:
        vectors = list(await wait_with_ticks(
            _embed, limit=limit, on_tick=_tick, what="эмбеддинг-сервер"
        ))
    except asyncio.TimeoutError as e:
        await ctx.progress("Не получилось", f"эмбеддинг-сервер не ответил за {int(limit)} с")

        raise RuntimeError(
            f"Эмбеддинг-сервер не ответил за {int(limit)} с — векторы не посчитаны. "
            "Проверьте EMBEDDING_API_URL и модель на сервере эмбеддингов, затем повторите задачу."
        ) from e
    except Exception as e:  # noqa: BLE001 — сеть/токен/HTTP: причина должна быть в мониторе
        # CancelledError сюда НЕ попадает (это BaseException): отмену задачи
        # (кнопка «Прервать», сторож) по-прежнему пробрасываем как есть.
        await ctx.progress("Не получилось", f"эмбеддинг-сервер: {type(e).__name__}")

        raise RuntimeError(
            f"Эмбеддинг-сервер недоступен ({type(e).__name__}: {e}). "
            "Проверьте EMBEDDING_API_URL, токен (EMBEDDING_API_KEY/LM_API_TOKEN) "
            "и что сервер эмбеддингов запущен."
        ) from e

    if len(vectors) != total:
        raise RuntimeError(
            f"Эмбеддинг-сервер вернул {len(vectors)} векторов на {total} текстов — "
            "запись отменена, чтобы не разъехались текст и вектор"
        )

    return vectors


def _vectors_path(base: str) -> str:
    """Путь к точке приёма векторов для заданной базы сайта.

    Базу в workers/.env пишут по-разному, и оба варианта естественны:

        PRX115_API_BASE=https://prx.ru/prx115/api  → путь «/vectors»
        PRX115_API_BASE=https://prx.ru            → путь «/prx115/api/vectors»

    Раньше обработчик ВСЕГДА приклеивал полный путь: с модульной базой
    получался адрес /prx115/api/prx115/api/vectors, сайт отвечал 404, а владелец
    видел «Векторы не дошли до сайта» без понятной причины. Все остальные каналы
    (prx605/prx606/prx610/…) используют короткий путь + модульную базу, поэтому
    модульная база здесь — основной вариант, а корень сайта поддержан как
    запасной: писать «адрес сайта» вместо «адреса API модуля» тоже нормально.
    """
    clean = (base or "").rstrip("/")

    if clean.endswith("/prx115/api"):
        return "/vectors"

    if clean.endswith("/prx115"):
        return "/api/vectors"

    return _ROUTE


def _site_base() -> str:
    """Адрес сайта для моста: PRX115_API_BASE → PRX120_API_BASE → корень PRX120_API_URL.

    Третья ступень — рабочая настройка «из того, что уже есть»: в http-режиме
    PRX120_API_URL задан обязательно (иначе воркер не заберёт задачу), сайт тот
    же, а токен доставки — тот же WORKER_API_TOKEN. Так доставка векторов не
    требует ВТОРОЙ записи адреса того же сайта (задача 60: «в примерах нет этих
    переменных» — и настроить было нечего).
    """
    return site.api_base(_PREFIX) or site.origin_of(os.environ.get("PRX120_API_URL", ""))


def _html_403_hint(why: str) -> str:
    """Подсказка на самый частый отказ: сайт вернул HTML вместо JSON.

    HTML-страница «Access Denied» означает, что запрос отклонил шлюз входа
    сайта ДО проверки токена: у воркера нет сессии, а точка приёма не объявлена
    публичной (`$public_actions` контроллера). Именно это случилось 23.10.2026
    с задачей 2234 при верном токене. Токен и адрес тут не виноваты — правка на
    сайте, а не в workers/.env.
    """
    text = (why or "").lower()

    if "html" not in text and "403" not in text:
        return ""

    return ("\nПохоже на отказ шлюза входа сайта, а не на неверный токен: "
            "запрос отбит до API (HTML-страница вместо JSON). На сайте точка "
            "POST /prx115/api/vectors должна быть в $public_actions контроллера "
            "Prx115_Api — см. AGENTS.md модуля prx115_semantic.")


def _settings_hint() -> str:
    """«Чего не хватает» — по фактическому окружению, чтобы не искать наугад."""
    missing = []

    if not _site_base():
        missing.append(
            "    PRX115_API_BASE=https://<сайт>/prx115/api   "
            "(годится и корень сайта: https://<сайт>; общий вариант — PRX120_API_BASE, "
            "а в режиме очереди http хватает уже заданного PRX120_API_URL)"
        )

    if not site.site_token(_PREFIX)[0]:
        missing.append(
            "    PRX115_SITE_TOKEN=<WORKER_API_TOKEN из .env сайта>   "
            "(или общий WORKER_SITE_TOKEN)"
        )

    if not missing:
        return ("Настройки заданы, но сайт векторы не принял: смотрите причину выше "
                "(адрес, токен, размерность коллекции).")

    return "Не задано в workers/.env:\n" + "\n".join(missing)


def _site_items(items: list, vectors: list[list[float]]) -> list[dict]:
    """Пара «вектор + текст» в том виде, в каком её ждёт POST /prx115/api/vectors."""
    out = []

    for item, vector in zip(items, vectors):
        entity_id = str(item.get("id") or "").strip()

        if not entity_id:
            raise ValueError("payload: у каждого item обязателен id (он же entity_id)")

        out.append({
            "id": entity_id,
            "vector": [float(x) for x in vector],
            "text": str(item.get("text", "")),
            "metadata": item.get("metadata") or {},
        })

    return out


async def _store_via_site(ctx: HandlerContext, collection: str, entity: str,
                          items: list, vectors: list) -> dict:
    """Мост http-режима: отправляет векторы пачками на сайт (POST /prx115/api/vectors).

    Сайт валидирует размерность по коллекции и делает тот же upsert по
    (collection_id, entity, entity_id). Любая неудача — понятная причина в журнале
    задачи: тихого «skipped» здесь больше нет.
    """
    total = len(items)
    stored = 0
    pairs = _site_items(items, vectors)

    for start in range(0, total, SITE_BATCH):
        chunk = pairs[start:start + SITE_BATCH]

        ok, why, answer = await site.post_json(
            _PREFIX,
            _vectors_path(_site_base()),
            {
                "collection": collection,
                "entity": entity,
                "model": ctx.embed.model,
                "items": chunk,
            },
            timeout=SITE_TIMEOUT,
            json_body=True,
        )

        if not ok:
            await ctx.progress("Не получилось", f"сайт не принял векторы: {why}")

            raise RuntimeError(
                f"Векторы не дошли до сайта ({why}). Воркер работает в http-режиме "
                "(без доступа к базе), поэтому векторы пишет сайт.\n"
                + _settings_hint()
                + _html_403_hint(why)
                + "\nЗатем повторите задачу: /prx115 → «Догнать»."
            )

        stored += int(answer.get("stored") or 0)

        await ctx.progress(
            "Записываю векторы", f"{min(stored, total)}/{total} (через сайт)"
        )

    await ctx.progress(
        "Готово", f"{stored} векторов · {collection} / {entity} (через сайт)"
    )

    return {
        "collection": collection,
        "entity": entity,
        "count": stored,
        "mode": "http-bridge",
        "model": ctx.embed.model,
        "dims": len(vectors[0]) if vectors else 0,
    }


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    if ctx.embed is None:
        raise RuntimeError("EMBEDDING_API_URL не настроен — эмбеддинг-сервер недоступен")

    collection = str(payload.get("collection") or "").strip()
    entity = str(payload.get("entity") or "").strip()
    items = payload.get("items") or []

    if not collection or not entity or not items:
        raise ValueError("payload: collection, entity, items обязательны")

    total = len(items)

    # http-режим: у воркера нет базы, поэтому считаем векторы сами и отдаём их
    # сайту пачками (мост). Так «Семантический индекс» доходит до конца и на
    # воркере без PostgreSQL — вместо прежнего тихого «skipped: http-mode».
    if ctx.pg is None:
        await ctx.progress(
            "Проверяю коллекцию",
            f"{collection} / {entity}: {total} шт. (http-режим, пишет сайт)",
        )

        vectors = await _embed_texts(ctx, items, total)

        return await _store_via_site(ctx, collection, entity, items, vectors)

    # 1. Метаданные: тип колонки embedding (vector или real[]) и id коллекции.
    #    Пул обработчиков общий на воркер, поэтому ждём соединение с лимитом:
    #    иначе задача «висит» без единой строки в журнале — ровно та жалоба,
    #    из-за которой этот обработчик переписан.
    await ctx.progress("Проверяю коллекцию", f"{collection} / {entity}: {total} шт.")

    try:
        async with ctx.pg.acquire(timeout=POOL_ACQUIRE_TIMEOUT) as conn:
            col_type = await conn.fetchval(
                """
                SELECT data_type FROM information_schema.columns
                WHERE table_name = 'prx_115_vectors' AND column_name = 'embedding'
                """
            )

            if col_type is None:
                raise RuntimeError(
                    "таблица prx_115_vectors не найдена — выполните миграции (bin/console db:migrate)"
                )

            row = await conn.fetchrow(
                "SELECT id FROM prx_115_collections WHERE name = $1", collection
            )

            if row is None:
                raise ValueError(
                    f"коллекция '{collection}' не создана "
                    "(Service_Prx115_Semantic::ensure_collection)"
                )

            collection_id = row["id"]
    except asyncio.TimeoutError as e:  # asyncpg: пул занят дольше лимита
        raise RuntimeError(
            f"Пул соединений с базой занят дольше {POOL_ACQUIRE_TIMEOUT:.0f} с — "
            "векторы не записаны, повторите задачу"
        ) from e

    # 2. Векторы: общий шаг для обоих режимов (см. _embed_texts).
    vectors = await _embed_texts(ctx, items, total)

    is_array = str(col_type).upper() == "ARRAY"

    # 3. Запись: одна транзакция, прогресс по ходу (большие батчи видно живьём).
    written = 0

    async with ctx.pg.acquire(timeout=POOL_ACQUIRE_TIMEOUT) as conn:
        async with conn.transaction():
            for item, vector in zip(items, vectors):
                entity_id = str(item.get("id", ""))
                content = str(item.get("text", ""))
                metadata = json.dumps(item.get("metadata") or {}, ensure_ascii=False)
                dims = len(vector)

                if is_array:
                    embedding_param: object = [float(x) for x in vector]
                    cast = "::real[]"
                else:
                    embedding_param = "[" + ",".join(format(float(x), ".9g") for x in vector) + "]"
                    cast = "::vector"

                await conn.execute(
                    f"""
                    INSERT INTO prx_115_vectors
                        (collection_id, entity, entity_id, content, metadata,
                         embedding, model, dims, content_hash)
                    VALUES ($1, $2, $3, $4, $5::jsonb, $6{cast}, $7, $8, $9)
                    ON CONFLICT (collection_id, entity, entity_id) DO UPDATE SET
                        content = EXCLUDED.content,
                        metadata = EXCLUDED.metadata,
                        embedding = EXCLUDED.embedding,
                        model = EXCLUDED.model,
                        dims = EXCLUDED.dims,
                        content_hash = EXCLUDED.content_hash,
                        updated_at = now()
                    """,
                    collection_id,
                    entity,
                    entity_id,
                    content,
                    metadata,
                    embedding_param,
                    ctx.embed.model,
                    dims,
                    content_hash(content),
                )

                written += 1

                if written % WRITE_PROGRESS_EVERY == 0 and written < total:
                    await ctx.progress("Записываю векторы", f"{written}/{total}")

    await ctx.progress("Готово", f"{written} векторов · {collection} / {entity}")

    return {
        "collection": collection,
        "entity": entity,
        "count": written,
        "column_type": str(col_type),
        "model": ctx.embed.model,
        "dims": len(vectors[0]) if vectors else 0,
    }
