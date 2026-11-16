"""Клиент очереди prl_120_jobs.

Два режима:
  * PgQueue  — прямое подключение к PostgreSQL (asyncpg):
               захват через FOR UPDATE SKIP LOCKED, пробуждение по LISTEN/NOTIFY;
  * HttpQueue — через PHP API модуля prl120 (httpx + токен WORKER_API_TOKEN),
               для воркеров на отдельном сервере без доступа к БД.

Таблицу схемы воркеры НЕ создают и не меняют — это дело монолита (миграции).
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
from typing import Any, Iterable, Protocol

import httpx

log = logging.getLogger("prl.queue")

NOTIFY_CHANNEL = "prl120_jobs"

# Таймаут взятия соединения из пула операции очереди (claim/heartbeat/
# complete/fail/usage). Бесконечное ожидание acquire() (значение asyncpg по
# умолчанию) — это и есть «вечный processing»: когда обработчики исчерпывают
# ОБЩИЙ пул, complete()/fail()/heartbeat() застревают навсегда. С таймаутом
# операция очереди не может стать точкой зависания — воркер уходит в ретрай,
# а терминальную уборку доведёт _sweep_stale / следующий проход.
_ACQUIRE_TIMEOUT = 30.0

# «Пульс» задачи — ОДНО выражение на все проверки живости (свипер, стоп молчащих,
# счётчики). Раньше свипер сравнивал голый heartbeat_at, и строка с ПУСТЫМ
# пульсом (NULL — легаси-задача, ручная правка, старый воркер) была для него
# невидима: `NULL < now() - ...` = NULL = «не подходит». Такая задача висела в
# processing вечно, а монитор при этом считал её застрявшей — владелец видел
# «зависший процесс» в мониторе всегда. Фолбэки на claimed_at → started_at →
# created_at (NOT NULL по схеме) гарантируют, что строка не останется без
# присмотра ни в одном случае.
PULSE_SQL = "coalesce(heartbeat_at, claimed_at, started_at, created_at)"


def max_age_sec(timeout_sec: int) -> int:
    """Абсолютный предел жизни задачи в processing (секунд, 0 = без предела).

    Зачем: если пульс идёт (старый/чужой воркер, зацикленный обработчик), ни
    одно правило по пульсу не срабатывает — задача живёт в очереди вечно и
    монитор «всегда показывает зависший процесс». Предел — ТРИ таймаута самой
    задачи, но не меньше часа: нормальная работа никогда в него не упирается
    (свой hard-watchdog воркера срабатывает раньше).
    """
    try:
        value = int(os.environ.get("PRL_JOB_MAX_AGE_SEC") or 0)
    except ValueError:
        value = 0

    if value > 0:
        return value
    if value < 0:
        return 0

    return max(int(timeout_sec) * 3, 3600)


class QueueClient(Protocol):
    async def connect(self) -> None: ...
    async def close(self) -> None: ...
    async def claim(self, channels: Iterable[str], worker: str) -> dict[str, Any] | None: ...
    async def heartbeat(self, job_id: int, worker: str, note: str | None = None) -> bool: ...
    async def progress(self, job_id: int, worker: str, note: str) -> bool: ...
    async def complete(self, job_id: int, worker: str, result: Any = None,
                       usage: dict | None = None) -> bool: ...
    async def fail(self, job_id: int, worker: str, error: str, retry: bool = True,
                   usage: dict | None = None) -> bool: ...


# =============================================================================
# Прямой доступ к PostgreSQL
# =============================================================================
class PgQueue:
    def __init__(self, dsn: str):
        self._dsn = dsn
        self._pool = None
        self._handler_pool = None
        self._listener = None
        self._wake = asyncio.Event()

    async def connect(self) -> None:
        import asyncpg  # локальный импорт: режим http не требует asyncpg

        # ДВА пула: один — для операций очереди (claim/heartbeat/complete/fail),
        # другой — abрабатываемый обработчикам как ctx.pg. Раньше был ОДИН пул,
        # и долгие обработчики (semantic.index держит соединение на время
        # внешнего embedding-вызова) исчерпывали его: complete()/heartbeat()
        # становились в очередь за теми же слотами и зависали навсегда —
        # задача оставалась processing при уже доставленном на сайт результате.
        pool_kwargs = dict(min_size=1, max_size=4)
        self._pool = await asyncpg.create_pool(self._dsn, **pool_kwargs)
        self._handler_pool = await asyncpg.create_pool(self._dsn, **pool_kwargs)
        # Слушатель NOTIFY: мгновенное пробуждение цикла опроса
        self._listener = await asyncpg.connect(self._dsn)
        await self._listener.add_listener(NOTIFY_CHANNEL, self._on_notify)
        log.info("Подключено к PostgreSQL, LISTEN %s", NOTIFY_CHANNEL)

    def _on_notify(self, *_args) -> None:
        self._wake.set()

    def wake(self) -> None:
        self._wake.set()

    async def wait_for_wake(self, timeout: float) -> None:
        try:
            await asyncio.wait_for(self._wake.wait(), timeout=timeout)
        except asyncio.TimeoutError:
            pass
        self._wake.clear()

    async def close(self) -> None:
        for obj in (self._listener, self._handler_pool, self._pool):
            if obj is not None:
                await obj.close()
        self._listener = self._pool = self._handler_pool = None

    @property
    def handler_pool(self):
        """Отдельный пул для обработчиков (ctx.pg): длинная работа обработчика
        не должна блокировать операции очереди (см. connect())."""
        return self._handler_pool

    async def claim(self, channels: Iterable[str], worker: str) -> dict[str, Any] | None:
        channels = [c for c in channels]
        where_extra = ""
        select_args: list[Any] = []
        if channels:
            where_extra = " AND channel = ANY($1::text[])"
            select_args.append(channels)

        async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
            # Самолечение: в pg-режиме кроме воркера некому разруливать зависшие
            # задачи (мертвый воркер оставлял их в processing навсегда).
            await self._sweep_stale(conn)
            async with conn.transaction():
                row = await conn.fetchrow(
                    f"""
                    SELECT id FROM prl_120_jobs
                    WHERE status = 'pending' AND available_at <= now()
                      AND attempts < max_attempts{where_extra}
                    ORDER BY priority ASC, id ASC
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                    """,
                    *select_args,
                )
                if row is None:
                    return None
                full = await conn.fetchrow(
                    """
                    UPDATE prl_120_jobs SET
                        status = 'processing',
                        claimed_by = $1,
                        claimed_at = now(),
                        heartbeat_at = now(),
                        started_at = COALESCE(started_at, now()),
                        error = NULL,
                        attempts = attempts + 1
                    WHERE id = $2
                    RETURNING id, channel, payload, result, status, priority,
                              attempts, max_attempts, timeout_sec, module,
                              visitor_id,
                              created_by, callback_url, error, claimed_by,
                              claimed_at, heartbeat_at, available_at, created_at,
                              started_at, finished_at
                    """,
                    worker,
                    row["id"],
                )
        if full is None:
            return None
        return _row_to_job(full)

    async def sweep_stale_standalone(self) -> None:
        """Разобрать зависшие processing ПО ТАЙМЕРУ (не только внутри claim).

        Зовётся фоновым циклом BaseWorker._sweep_loop. Нужен потому, что в
        pg-режиме другого разборщика нет: сайтовый свипер срабатывает при
        заходе админа в монитор или по крону, а claim() — лишь когда у воркера
        есть свободный слот. Занятый воркер не запускал ни то, ни другое, и
        «Выполняется, пульс 18 мин» держалось до ручного визита в монитор.
        """
        async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
            await self._sweep_stale(conn)

    @staticmethod
    async def _sweep_stale(conn) -> None:
        """Разрулить зависшие processing: попытки исчерпаны или воркер молчит
        очень долго → failed; иначе (ещё есть попытки) → назад в pending.
        Без этого мёртвый воркер оставлял задачи в processing навсегда.

        Первым делом — авто-остановка молчащего воркера (как в PHP-свипере
        auto_stop_silent): heartbeat идёт раз в 30 с, поэтому молчание дольше
        нескольких минут означает мёртвый/заблокированный обработчик. Такой
        сбор закрываем в 'cancelled' с понятной причиной — страница гасит ряд,
        найденное сохраняется, сбор можно запустить заново.

        Порог — РОВНО PRL_SILENT_STOP_SEC (по умолчанию 240 с), без прежнего
        «GREATEST(floor, timeout_sec / 4)»: у каналов с длинным таймаутом
        (prl602/prl607/prl641: 1800-3600 с) вторая половина отодвигала остановку
        до 15-25 минут, и владелец видел «нет пульса 10 мин» с живым спиннером,
        хотя воркер давно молчал. Пульс не зависит от длины задачи."""
        floor = int(os.environ.get("PRL_SILENT_STOP_SEC") or 240)
        await conn.execute(
            f"""
            UPDATE prl_120_jobs SET
                status = 'cancelled',
                error = 'Воркер не отвечал больше {max(1, round(floor / 60))} мин — сбор остановлен автоматически. Найденное сохранено, запустите заново.',
                finished_at = now(),
                claimed_by = NULL
            WHERE status = 'processing'
              AND {PULSE_SQL} < now() - {floor} * interval '1 second'
            """
        )
        # 0-бис) абсолютный предел возраста: пульс может идти (чужой/старый
        #        воркер, зацикленный обработчик), а задача всё равно не
        #        заканчивается. Такую строку закрываем по возрасту.
        floor_age = max_age_sec(600)
        await conn.execute(
            f"""
            UPDATE prl_120_jobs SET
                status = 'cancelled',
                error = 'Задача шла дольше ' || GREATEST({floor_age}, timeout_sec * 3) / 60
                        || ' мин — закрыта автоматически, чтобы не висела вечно. Запустите заново.',
                finished_at = now(),
                claimed_by = NULL
            WHERE status = 'processing'
              AND coalesce(started_at, claimed_at, created_at)
                  < now() - GREATEST({floor_age}, timeout_sec * 3) * interval '1 second'
            """
        )
        # 1) провал ТОЛЬКО при реальном молчании воркера.
        #    ВАЖНО: было `attempts >= max_attempts OR ...` — для пинков с
        #    max_attempts=1 (prl603 и др.) это истинно сразу после claim
        #    (attempts=1>=1), т.е. любая задача с одной попыткой «сгорала»
        #    при первом же следующем claim любого воркера. Живой воркер сам
        #    зовёт fail(), когда попытки по-настоящему исчерпаны, — свипер
        #    нужен только против МЁРТВЫХ воркеров.
        await conn.execute(
            f"""
            UPDATE prl_120_jobs SET
                status = 'failed',
                error = CASE WHEN attempts >= max_attempts
                             THEN 'Воркер молчал дольше timeout — попытки исчерпаны'
                             ELSE 'Воркер молчал слишком долго — задача провалена' END,
                finished_at = now(),
                claimed_by = NULL
            WHERE status = 'processing'
              AND {PULSE_SQL} < now()
                  - GREATEST(timeout_sec * 4, LEAST(timeout_sec * 8, 21600)) * interval '1 second'
            """
        )
        # 2) свежезависшие с запасом попыток — назад в очередь.
        #    Нижний множитель timeout*2: генерация (prl641 timeout=1800 с) может
        #    до 4 минут ждать видеопамять и временно «молчать» — живую задачу
        #    нельзя возвращать в очередь по голому timeout_sec (двойной прогон).
        await conn.execute(
            f"""
            UPDATE prl_120_jobs SET
                status = 'pending',
                claimed_by = NULL,
                claimed_at = NULL,
                available_at = now()
            WHERE status = 'processing'
              AND attempts < max_attempts
              AND {PULSE_SQL} < now() - (GREATEST(timeout_sec * 2, 900) * interval '1 second')
            """
        )

        # 2а) «живой» пульс, но попытки исчерпаны: claim не возьмёт строку
        #     (attempts < max_attempts), и она висела бы processing вечно —
        #     ровно то, что видел владелец как «Выполняется» без конца.
        #     Критерий — только пробел в пульсе САМОЙ задачи: работающий
        #     обработчик подаёт пульс чаще порога, поэтому под правило не попадает.
        await conn.execute(
            f"""
            UPDATE prl_120_jobs SET
                status = 'failed',
                error = 'Воркер перестал подавать признаки жизни — задача отказана (попытки исчерпаны)',
                finished_at = now(),
                claimed_by = NULL
            WHERE status = 'processing'
              AND attempts >= max_attempts
              AND {PULSE_SQL} < now() - (GREATEST(timeout_sec * 2, 900) * interval '1 second')
            """
        )

        # 3) pending-тупик: строку невозможно взять (попытки исчерпаны) или
        #    воркер не забрал её часами — иначе монитор вечно рисует ожидание.
        await conn.execute(
            """
            UPDATE prl_120_jobs SET
                status = 'failed',
                error = CASE WHEN attempts >= max_attempts
                             THEN 'Попытки исчерпаны — задача не может быть взята воркером'
                             ELSE 'Воркер не забрал задачу из очереди (очередь недоступна?) — запустите заново' END,
                finished_at = now(),
                claimed_by = NULL
            WHERE status = 'pending'
              AND coalesce(available_at, created_at) < now() - (CASE WHEN attempts >= max_attempts
                                               THEN GREATEST(timeout_sec * 2, 1800)
                                               ELSE 43200 END) * interval '1 second'
            """
        )

    async def heartbeat(self, job_id: int, worker: str, note: str | None = None) -> bool:
        async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
            status = await conn.fetchval(
                """
                UPDATE prl_120_jobs SET heartbeat_at = now()
                WHERE id = $1 AND claimed_by = $2 AND status = 'processing'
                RETURNING 1
                """,
                job_id,
                worker,
            )
        return status is not None

    def fail_blocking(self, job_id: int, worker: str, error: str) -> bool:
        """СИНХРОННО провалить задачу — из сторожевого ПОТОКА, не из event loop.

        Обычный fail() асинхронный: если event loop занят (тяжёлый парсинг,
        C-расширение без отпускания GIL), он не выполнится никогда, и задача
        останется «Выполняется» навсегда. Здесь — отдельное соединение в
        стороевом потоке, которому основной цикл не указ.

        Порядок попыток:
          1) синхронный драйвер psycopg2/psycopg (быстро и без циклов);
          2) свежий event loop с asyncpg в ЭТОМ ЖЕ потоке — новых зависимостей
             не требует (asyncpg и так есть у pg-режима);
          3) ничего не вышло — честно False, задачу закроет сайтовый свипер
             и авто-остановка по пульсу.

        ВАЖНО: без этого сторож в pg-режиме молча ничего не делал, и задача
        «висела» до срабатывания сайтовых 240 с (а до них — сколько угодно,
        если сайт по каким-то причинам сам не зовёт requeue_stale).
        """
        selectors = []

        try:
            import psycopg2  # noqa: F401

            def _pg2(dsn):
                import psycopg2 as _m
                return _m.connect(dsn)

            selectors.append(_pg2)
        except Exception:  # noqa: BLE001 — нет драйвера, пробуем следующий
            pass

        try:
            import psycopg  # noqa: F401

            def _pg3(dsn):
                import psycopg as _m
                return _m.connect(dsn)

            selectors.append(_pg3)
        except Exception:  # noqa: BLE001
            pass

        for connect in selectors:
            try:
                if self._fail_sync_direct(connect, job_id, error):
                    return True
            except Exception:  # noqa: BLE001 — сторож не роняет поток
                log.warning("Сторож задач: sync-закрытие #%s не удалось", job_id,
                            exc_info=True)

        return self._fail_sync_asyncpg(job_id, error)

    def _fail_sync_direct(self, connect, job_id: int, error: str) -> bool:
        """Закрыть задачу синхронным драйвером (psycopg2/psycopg)."""
        with connect(self._dsn) as conn:
            with conn.cursor() as cur:
                cur.execute(
                    """
                    UPDATE prl_120_jobs SET
                        status = 'failed',
                        error = %s,
                        finished_at = now(),
                        heartbeat_at = now()
                    WHERE id = %s AND status = 'processing'
                    """,
                    (error[:500], int(job_id)),
                )
                done = cur.rowcount or 0

                if done:
                    cur.execute(
                        "INSERT INTO prl_120_job_events (job_id, event, message) "
                        "VALUES (%s, 'failed', %s)",
                        (int(job_id), "Сторожевой таймер воркера: " + error[:400]),
                    )

        return done > 0

    def _fail_sync_asyncpg(self, job_id: int, error: str) -> bool:
        """Последний шанс сторожа: asyncpg в НОВОМ цикле внутри этого потока.

        Основной event loop воркера может быть заморожен — здесь у нас свой,
        поэтому ожидание не зависит от него. Новых зависимостей нет.
        """
        try:
            import asyncpg

            async def _run() -> bool:
                conn = await asyncpg.connect(self._dsn, timeout=15.0)

                try:
                    status = await conn.execute(
                        """
                        UPDATE prl_120_jobs SET
                            status = 'failed',
                            error = $2,
                            finished_at = now(),
                            heartbeat_at = now()
                        WHERE id = $1 AND status = 'processing'
                        """,
                        int(job_id), error[:500],
                    )
                    done = str(status).endswith(" 1")

                    if done:
                        await conn.execute(
                            "INSERT INTO prl_120_job_events (job_id, event, message) "
                            "VALUES ($1, 'failed', $2)",
                            int(job_id), "Сторожевой таймер воркера: " + error[:400],
                        )

                    return done
                finally:
                    await conn.close()

            return asyncio.run(_run())
        except Exception:  # noqa: BLE001 — сторож не роняет поток
            log.info("Сторож задач: задачу #%s не удалось закрыть напрямую "
                     "(нет sync-драйвера и asyncpg-путь не сработал) — "
                     "её закроет сайтовый свипер", job_id)
            return False

        try:
            with connect(self._dsn) as conn:
                with conn.cursor() as cur:
                    cur.execute(
                        """
                        UPDATE prl_120_jobs SET
                            status = 'failed',
                            error = %s,
                            finished_at = now(),
                            heartbeat_at = now()
                        WHERE id = %s AND status = 'processing'
                        """,
                        (error[:500], int(job_id)),
                    )
                    done = cur.rowcount or 0

                    if done:
                        cur.execute(
                            "INSERT INTO prl_120_job_events (job_id, event, message) "
                            "VALUES (%s, 'failed', %s)",
                            (int(job_id), "Сторожевой таймер воркера: " + error[:400]),
                        )
            return done > 0
        except Exception:  # noqa: BLE001 — сторож не должен ронять поток
            log.warning("Сторож задач: не удалось закрыть задачу %s напрямую", job_id,
                        exc_info=True)
            return False

    async def release_own_orphans(self, worker: str) -> int:
        """Задачи, числящиеся за ЭТИМ воркером как processing, — освободить.

        Зовётся один раз при старте процесса: если прошлый воркер был убит
        (OOM, kill, рестарт деплоя), его задачи навсегда оставались
        processing с мёртвым пульсом — владелец видел «Выполняется» сутками.
        Свежий процесс заведомо не ведёт ни одной задачи, поэтому забирать
        их у себя безопасно.

        Поведение совпадает с серверным Prl120::JobQueue::release_mine():
          - попытки остались → 'pending' (задача честно перезапустится);
          - попытки исчерпаны → 'failed' (иначе получился бы вечный цикл
            «воркер упал → задача вернулась → снова упал»).
        """
        message = ("Воркер перезапущен — задача возвращена в очередь сразу "
                   "(без ожидания таймаута)")

        # ВАЖНО: «осиротевшей» считаем только задачу с МЁРТВЫМ пульсом. Если
        # пульс свежий, значит эту задачу прямо сейчас ведёт другой живой
        # процесс с тем же именем воркера (второй запуск, забытый терминал,
        # systemd + ручной старт). Забирать её нельзя: живой обработчик
        # получал бы отказ heartbeat, бросал работу, а задача уходила по кругу
        # «перезапуск → возврат в очередь → снова перезапуск» — ровно то, что
        # владелец видел как «Выполняется, пульс 18 мин, попытка 3/3».
        fresh_sec = 120

        async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
            alive = await conn.fetch(
                f"""
                SELECT id FROM prl_120_jobs
                WHERE status = 'processing' AND claimed_by = $1
                  AND {PULSE_SQL} >= now() - ($2 || ' seconds')::interval
                """,
                worker, str(fresh_sec),
            )

            if alive:
                log.warning(
                    "Под именем воркера '%s' работают ДРУГИЕ живые задачи (%d шт.): %s. "
                    "Похоже, запущено больше одной копии воркера с этим именем — "
                    "остановите лишнюю, иначе задачи будут перекидываться между ними.",
                    worker, len(alive), ", ".join("#%s" % r["id"] for r in alive[:10]),
                )

            requeued = await conn.fetch(
                f"""
                UPDATE prl_120_jobs SET
                    status = 'pending',
                    claimed_by = NULL,
                    claimed_at = NULL,
                    available_at = now(),
                    heartbeat_at = now(),
                    error = $2
                WHERE status = 'processing' AND claimed_by = $1
                  AND attempts < max_attempts
                  AND {PULSE_SQL} < now() - ($3 || ' seconds')::interval
                RETURNING id
                """,
                worker, message, str(fresh_sec),
            )
            exhausted = await conn.fetch(
                f"""
                UPDATE prl_120_jobs SET
                    status = 'failed',
                    error = 'Воркер перезапускался на этой задаче, попытки исчерпаны. '
                            'Смотрите журнал задачи и запустите заново.',
                    finished_at = now(),
                    heartbeat_at = now(),
                    claimed_by = NULL
                WHERE status = 'processing' AND claimed_by = $1
                  AND attempts >= max_attempts
                  AND {PULSE_SQL} < now() - ($2 || ' seconds')::interval
                RETURNING id
                """,
                worker, str(fresh_sec),
            )

            for row in requeued:
                await conn.execute(
                    """
                    INSERT INTO prl_120_job_events (job_id, event, message)
                    VALUES ($1, 'requeued', $2)
                    """,
                    int(row["id"]), message,
                )

            for row in exhausted:
                await conn.execute(
                    """
                    INSERT INTO prl_120_job_events (job_id, event, message)
                    VALUES ($1, 'failed', $2)
                    """,
                    int(row["id"]),
                    "Задача снята с прошлого запуска воркера: попытки исчерпаны",
                )

            return len(requeued) + len(exhausted)

    async def progress(self, job_id: int, worker: str, note: str) -> bool:
        """Промежуточный рапорт → журнал задачи (prl_120_job_events['progress']).

        ВАЖНО: рапорт обязан обновлять и heartbeat_at — иначе в pg-режиме этап
        уходил в журнал, а пульс «молчал» до следующего витка heartbeat-цикла, и
        свипер мог отказать ЖИВУЮ задачу (владелец: «нет готово»). HTTP-режим
        обновляет пульс на стороне сайта (Controller_Prl120_Api::action_progress).
        """
        try:
            async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
                await conn.execute(
                    """
                    UPDATE prl_120_jobs SET heartbeat_at = now()
                    WHERE id = $1 AND status = 'processing'
                    """,
                    job_id,
                )
                await conn.execute(
                    """
                    INSERT INTO prl_120_job_events (job_id, event, message)
                    VALUES ($1, 'progress', $2)
                    """,
                    job_id,
                    note[:500],
                )
            return True
        except Exception:  # noqa: BLE001 — прогресс не должен ронять задачу
            log.warning("progress задачи %s не записан", job_id, exc_info=True)
            return False

    async def _usage_write(self, job_id: int, usage: dict | None,
                           had_error: bool) -> None:
        """Прямая запись отчёта о ресурсах (режим pg: PHP-хуков нет).
        Таблицу создаёт сайт (миграция prl120/004); нет таблицы — молча пропускаем."""
        if not usage:
            return
        try:
            async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
                row = await conn.fetchrow(
                    "SELECT channel, visitor_id FROM prl_120_jobs WHERE id = $1", job_id)
                if row is None:
                    return
                await conn.execute(
                    """
                    INSERT INTO prl_120_usage (job_id, channel, visitor_id, models,
                        llm_calls, prompt_tokens, completion_tokens, web_fetches,
                        search_queries, errors, had_error, elapsed_ms)
                    VALUES ($1, $2, $3, $4::jsonb, $5, $6, $7, $8, $9, $10::jsonb,
                            $11, $12)
                    ON CONFLICT (job_id) DO UPDATE SET
                        models = EXCLUDED.models, llm_calls = EXCLUDED.llm_calls,
                        prompt_tokens = EXCLUDED.prompt_tokens,
                        completion_tokens = EXCLUDED.completion_tokens,
                        web_fetches = EXCLUDED.web_fetches,
                        search_queries = EXCLUDED.search_queries,
                        errors = EXCLUDED.errors, had_error = EXCLUDED.had_error,
                        elapsed_ms = EXCLUDED.elapsed_ms, updated_at = now()
                    """,
                    job_id, row["channel"], row["visitor_id"] or "",
                    __import__("json").dumps(usage.get("models") or []),
                    int(usage.get("llm_calls") or 0),
                    int(usage.get("prompt_tokens") or 0),
                    int(usage.get("completion_tokens") or 0),
                    int(usage.get("web_fetches") or 0),
                    int(usage.get("search_queries") or 0),
                    __import__("json").dumps(usage.get("errors") or []),
                    had_error, int(usage.get("elapsed_ms") or 0))
        except Exception:  # noqa: BLE001 — учёт не должен влиять на задачу
            log.warning("prl_120_usage: не записал usage задачи %s", job_id,
                        exc_info=True)

    async def complete(self, job_id: int, worker: str, result: Any = None,
                       usage: dict | None = None) -> bool:
        await self._usage_write(job_id, usage, had_error=False)
        async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
            async with conn.transaction():
                status = await conn.fetchval(
                    """
                    UPDATE prl_120_jobs SET
                        status = 'done',
                        result = $3::jsonb,
                        finished_at = now(),
                        error = NULL,
                        heartbeat_at = now()
                    WHERE id = $1 AND claimed_by = $2 AND status = 'processing'
                    RETURNING 1
                    """,
                    job_id,
                    worker,
                    json.dumps(result, ensure_ascii=False),
                )
                if status is None:
                    # Строка вышла из processing у этого воркера (свипер вернул
                    # в очередь, задача перехвачена/отменена, retry ушёл в
                    # pending). Не роняем результат: если реальная работа уже
                    # сделана (обработчик вернул итог — образец prl602/prl606
                    # доставил артефакт на сайт), фиксируем terminal-статус
                    # идемпотентно, чтобы задача не висела processing вечно.
                    state = await conn.fetchrow(
                        "SELECT status, claimed_by FROM prl_120_jobs WHERE id = $1 FOR UPDATE",
                        job_id,
                    )
                    if state is None:
                        return False
                    if state["status"] in ("done", "failed", "cancelled"):
                        return state["status"] == "done"
                    # Если строку уже перехватил ДРУГОЙ воркер и он реально
                    # работает — не мешаем (его собственный complete() доведёт).
                    if state["status"] == "processing" and state["claimed_by"] and state["claimed_by"] != worker:
                        return False
                    # pending или processing у нас же (claim мог перезаписаться):
                    # результат уже получен, повторный прогон дал бы дубль артефакта.
                    if result is None:
                        await conn.execute(
                            """
                            UPDATE prl_120_jobs SET
                                status = 'done',
                                finished_at = now(),
                                error = NULL,
                                heartbeat_at = now(),
                                claimed_by = COALESCE(claimed_by, $2)
                            WHERE id = $1
                            """,
                            job_id,
                            worker,
                        )
                    else:
                        await conn.execute(
                            """
                            UPDATE prl_120_jobs SET
                                status = 'done',
                                result = $3::jsonb,
                                finished_at = now(),
                                error = NULL,
                                heartbeat_at = now(),
                                claimed_by = COALESCE(claimed_by, $2)
                            WHERE id = $1
                            """,
                            job_id,
                            worker,
                            json.dumps(result, ensure_ascii=False),
                        )
                    log.warning("prl120: задача %s завершена принудительно (была '%s')",
                                job_id, state["status"])
                    status = 1
                if status is not None:
                    await conn.execute(
                        "INSERT INTO prl_120_job_events (job_id, event, message) VALUES ($1, 'done', 'Выполнено python-воркером ' || $2)",
                        job_id,
                        worker,
                    )
        return status is not None

    async def fail(self, job_id: int, worker: str, error: str, retry: bool = True,
                   usage: dict | None = None) -> bool:
        await self._usage_write(job_id, usage, had_error=True)
        async with self._pool.acquire(timeout=_ACQUIRE_TIMEOUT) as conn:
            async with conn.transaction():
                row = await conn.fetchrow(
                    "SELECT attempts, max_attempts, status FROM prl_120_jobs "
                    "WHERE id = $1 AND claimed_by = $2 AND status = 'processing' FOR UPDATE",
                    job_id,
                    worker,
                )
                if row is None:
                    # Строка уже не в processing у этого воркера — не должен
                    # случиться «зависший processing»: либо уже terminal, либо
                    # ушла в pending (тогда пусть ретраится штатно).
                    state = await conn.fetchrow(
                        "SELECT status, claimed_by FROM prl_120_jobs WHERE id = $1 FOR UPDATE",
                        job_id,
                    )
                    if state is None or state["status"] in ("done", "failed", "cancelled"):
                        return False
                    # processing чужого воркера — не трогаем
                    if state["status"] == "processing":
                        return False
                    # pending (ранее вернулась в очередь) — назад в processing,
                    # чтобы из неё корректно уйти в terminal ниже по коду
                    if state["status"] == "pending":
                        await conn.execute(
                            "UPDATE prl_120_jobs SET status = 'processing', "
                            "claimed_by = $2, heartbeat_at = now() WHERE id = $1",
                            job_id, worker,
                        )
                        row = await conn.fetchrow(
                            "SELECT attempts, max_attempts, status FROM prl_120_jobs "
                            "WHERE id = $1 FOR UPDATE", job_id,
                        )
                        if row is None:
                            return False
                    else:
                        return False
                will_retry = retry and int(row["attempts"]) < int(row["max_attempts"])
                if will_retry:
                    delay = 15 * int(row["attempts"]) ** 2
                    await conn.execute(
                        """
                        UPDATE prl_120_jobs SET
                            status = 'pending',
                            error = $2,
                            available_at = now() + ($3 || ' seconds')::interval,
                            heartbeat_at = now(),
                            claimed_by = NULL, claimed_at = NULL
                        WHERE id = $1
                        """,
                        job_id,
                        error,
                        str(delay),
                    )
                    event = f"Попытка {row['attempts']}/{row['max_attempts']} не удалась, retry через {delay}s"
                else:
                    # ВАЖНО: asyncpg требует, чтобы все параметры $1..$N
                    # присутствовали в тексте запроса — лишний $2 (worker)
                    # давал IndeterminateDatatypeError и задача навсегда
                    # залипала в 'processing'.
                    await conn.execute(
                        """
                        UPDATE prl_120_jobs SET
                            status = 'failed',
                            error = $2,
                            finished_at = now(),
                            heartbeat_at = now()
                        WHERE id = $1
                        """,
                        job_id,
                        error,
                    )
                    event = "Попытки исчерпаны"
                await conn.execute(
                    "INSERT INTO prl_120_job_events (job_id, event, message) VALUES ($1, 'failed', $2)",
                    job_id,
                    event,
                )
        return True


# =============================================================================
# Через HTTP API (воркеры на отдельном сервере)
# =============================================================================
class HttpQueue:
    def __init__(self, base_url: str, token: str):
        self._base = base_url.rstrip("/")
        self._headers = {"X-Worker-Token": token, "Content-Type": "application/json"}
        self._client = httpx.AsyncClient(timeout=60.0)
        self._wake = asyncio.Event()

    async def connect(self) -> None:
        r = await self._client.get(f"{self._base}/stats", headers=self._headers)
        if r.is_error:
            # тело ответа содержит диагноз сайта (например, причину отказа токена)
            detail = (r.text or "").strip()[:300]
            raise RuntimeError(
                f"HTTP {r.status_code} от очереди{': ' + detail if detail else ''}"
            )
        log.info("HTTP-очередь доступна: %s", r.json())

    def wake(self) -> None:
        self._wake.set()

    async def wait_for_wake(self, timeout: float) -> None:
        try:
            await asyncio.wait_for(self._wake.wait(), timeout=timeout)
        except asyncio.TimeoutError:
            pass
        self._wake.clear()

    async def close(self) -> None:
        await self._client.aclose()

    async def _post(self, path: str, payload: dict) -> dict:
        r = await self._client.post(
            f"{self._base}{path}", json=payload, headers=self._headers
        )
        if r.is_error:
            # тело содержит диагноз сайта (наш prl120 API отвечает JSON-ошибкой)
            detail = (r.text or "").strip()[:300]
            raise RuntimeError(
                f"HTTP {r.status_code} от очереди {path}{': ' + detail if detail else ''}"
            )
        return r.json()

    async def claim(self, channels: Iterable[str], worker: str) -> dict[str, Any] | None:
        data = await self._post("/claim", {"channels": list(channels), "worker": worker})
        return data.get("job") if data.get("ok") else None

    def fail_blocking(self, job_id: int, worker: str, error: str) -> bool:
        """СИНХРОННО провалить задачу на сайте — из сторожевого ПОТОКА.

        Отдельный httpx.Client (не AsyncClient): работает, даже когда event
        loop воркера заморожен, и задача уходит из «Выполняется» без его
        участия. Ровно тот случай, про который владелец говорит «зависает».
        """
        try:
            with httpx.Client(timeout=15.0) as client:
                r = client.post(
                    f"{self._base}/fail/{int(job_id)}",
                    json={"worker": worker, "error": f"Сторожевой таймер воркера: {error}"[:500],
                          "retry": False},
                    headers=self._headers,
                )
            return not r.is_error and bool((r.json() or {}).get("ok"))
        except Exception:  # noqa: BLE001 — сторож не должен падать
            log.warning("Сторож задач: не удалось провалить задачу %s на сайте", job_id,
                        exc_info=True)
            return False

    async def release_own_orphans(self, worker: str) -> int:
        """Закрыть на сайте задачи, оставшиеся от ПРЕДЫДУЩЕГО запуска воркера.

        Владелец видел «Выполняется» с мёртвым пульсом: процесс убили (OOM,
        рестарт деплоя), и в HTTP-режиме никто эти строки не закрывает, пока
        воркер снова не начнёт захватывать задачи. Свежий процесс забирает
        только СВОИ строки (claimed_by = его имя) — чужие не трогает.
        """
        data = await self._post("/release_mine", {"worker": worker})
        return int(data.get("released") or 0)

    async def heartbeat(self, job_id: int, worker: str, note: str | None = None) -> bool:
        data = await self._post(f"/heartbeat/{job_id}", {"worker": worker, "note": note})
        return bool(data.get("ok"))

    async def progress(self, job_id: int, worker: str, note: str) -> bool:
        try:
            data = await self._post(f"/progress/{job_id}", {"worker": worker, "note": note})
            return bool(data.get("ok"))
        except Exception:  # noqa: BLE001 — прогресс не должен ронять задачу
            return False

    async def complete(self, job_id: int, worker: str, result: Any = None,
                       usage: dict | None = None) -> bool:
        body: dict[str, Any] = {"worker": worker, "result": result}
        if usage:
            body["usage"] = usage
        data = await self._post(f"/complete/{job_id}", body)
        return bool(data.get("ok"))

    async def fail(self, job_id: int, worker: str, error: str, retry: bool = True,
                   usage: dict | None = None) -> bool:
        body: dict[str, Any] = {"worker": worker, "error": error, "retry": retry}
        if usage:
            body["usage"] = usage
        data = await self._post(f"/fail/{job_id}", body)
        return bool(data.get("ok"))


def make_queue(config) -> QueueClient:
    """Фабрика клиента очереди по WORKER_QUEUE_MODE."""
    if config.queue_mode == "http":
        return HttpQueue(config.api_url, config.api_token)
    return PgQueue(config.pg_dsn)


def _row_to_job(row: Any) -> dict[str, Any]:
    job = dict(row)
    for col in ("payload", "result"):
        value = job.get(col)
        if isinstance(value, str):
            try:
                job[col] = json.loads(value)
            except (TypeError, ValueError):
                job[col] = {}
        elif value is None:
            job[col] = {} if col == "payload" else None
    job["id"] = int(job["id"])
    return job
