"""Базовый цикл воркера.

BaseWorker:
  * цикл: очередь.claim() -> задача -> asyncio-таска с обработчиком канала;
  * heartbeat отдельной таской (задача не «зависает» по мнению PHP);
  * таймаут задачи (timeout_sec из очереди);
  * исключение -> queue.fail() с ретраем и backoff (логика на стороне очереди);
  * graceful shutdown по SIGINT/SIGTERM.

Обработчики каналов живут в prx_workers/handlers/ и регистрируются там же.
"""

from __future__ import annotations

import asyncio
import contextlib
import contextvars
import logging
import os
import signal
import threading
import time
import traceback
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Awaitable, Callable

from .llm import EmbeddingClient, EmbeddingServerError, LlmClient, LlmConfigError
from .queue import QueueClient
from .watchdogs import WatchdogMixin
from .usage import Usage

log = logging.getLogger("prx.worker")

# Текущая задача обработчика. Конкурентоспособный аналог старого
# ctx._current_job: тот был ОДНИМ слотом на весь воркер, и при concurrency>1
# параллельные задачи перезаписывали его друг у друга — ctx.heartbeat() и
# чтение `ctx._current_job['id']` в обработчиках могли бить «пульс» чужой
# задаче. contextvar изолирует значение per-task (как Usage).
_CURRENT_JOB: contextvars.ContextVar["dict | None"] = contextvars.ContextVar(
    "prx_current_job", default=None)

# Промежуточный журнал задачи: обработчик зовёт ctx.progress() → метки копятся
# сюда → base._process кладёт их в result['_progress'] и дублирует событиями
# очереди (prx_120_job_events) в pg-режиме. Per-task, как _CURRENT_JOB.
_JOB_PROGRESS: contextvars.ContextVar["list | None"] = contextvars.ContextVar(
    "prx_job_progress", default=None)


@dataclass
class HandlerContext:
    """Всё, что нужно обработчику задачи (инъекция зависимостей)."""

    llm: LlmClient | None
    embed: EmbeddingClient | None
    config: Any
    # Пул PostgreSQL (только в режиме pg; None в режиме http)
    pg: Any = None

    async def heartbeat(self) -> None:
        """Обработчик может звать в долгих циклах, чтобы задача не считалась зависшей."""
        if self._queue is not None:
            job = _CURRENT_JOB.get()
            if job is not None:
                await self._queue.heartbeat(int(job.get("id") or 0), self._worker)

    async def progress(self, phase: str, note: str = "") -> None:
        """Рапорт промежуточного шага — ЖИВОЙ (виден на сайте во время работы).

        Двойная доставка:
          * копится в контексте задачи (уезжает в result['_progress']);
          * сразу пишется событием в очередь (prx_120_job_events['progress']) —
            это и есть «статус-ряд» на странице модуля: /trends/<code>,
            /radar, /leads, /industry, /tasks/<...>.
        Раньше метки уходили в очередь только в finally (после задачи), поэтому
        на сайте прогресс не был виден во время работы, а монитор показывал
        «пульс» без этапа (владелец: «нет готово и непонятно, что делает»).
        """
        steps = _JOB_PROGRESS.get()

        if steps is not None:
            steps.append({
                "t": int(time.time()),
                "phase": str(phase)[:300],
                "note": str(note)[:300],
            })

        # ЖИВОЙ рапорт в очередь: пульс + событие 'progress'. Работает в обоих
        # режимах (pg — прямая запись, http — POST /prx120/api/progress/<id>).
        # Сбой рапорта не должен ронять задачу — глотаем с логом.
        if self._queue is not None:
            job = _CURRENT_JOB.get()

            if job is not None and int(job.get("id") or 0) > 0:
                if self._on_progress is not None:
                    try:
                        self._on_progress(int(job["id"]), f"{phase} {note}".strip()[:200])
                    except Exception:  # noqa: BLE001 — журнал не важнее прогресса
                        pass

                try:
                    await self._queue.progress(
                        int(job["id"]), self._worker,
                        f"{phase} {note}".strip()[:500])
                except Exception:  # noqa: BLE001 — прогресс не роняет задачу
                    log.debug("progress %s: %s", job.get("id"), traceback.format_exc())

    # Подставляются воркером перед вызовом обработчика:
    _queue: QueueClient | None = None
    _worker: str = ""
    # Колбэк воркера: «этот этап сейчас у задачи N» — нужен сторожевому
    # журналу, чтобы при заморозке цикла назвать виновника, а не молчать.
    _on_progress: Any = None

    # Обратная совместимость: обработчики читают ctx._current_job (dict|None)
    # напрямую (prx570/prx580/prx590/prx591/prx592). Property отдаёт задачу именно
    # ЭТОГО asyncio-контекста, а не общий слот (раньше параллельные задачи
    # перезаписывали его друг у друга).
    @property
    def _current_job(self) -> "dict | None":
        return _CURRENT_JOB.get()

    @_current_job.setter
    def _current_job(self, value: "dict | None") -> None:
        _CURRENT_JOB.set(value)


Handler = Callable[[HandlerContext, dict], Awaitable[dict | None]]


class BaseWorker(WatchdogMixin):
    # Значения по умолчанию: воркер собирают и через __new__ (тесты контракта
    # complete/fail) — сторожевые структуры обязаны существовать в любом случае.
    _stage_of: dict[int, str] = {}
    _deadline_of: dict[int, float] = {}
    _limit_of: dict[int, float] = {}
    _hard_killed: set[int] = set()
    _site_killed: set[int] = set()
    _loop: "asyncio.AbstractEventLoop | None" = None
    _hard_thread: "threading.Thread | None" = None
    _lag_task: "asyncio.Task | None" = None
    _last_lag_log: float = 0.0
    _loop_tick: float = 0.0

    def __init__(self, config, queue: QueueClient, handlers: dict[str, Handler]):
        self.config = config
        self.queue = queue
        self.handlers = handlers
        self._tasks: set[asyncio.Task] = set()
        self._stopping = False
        self._inflight = 0
        self._current_jobs: dict[int, asyncio.Task | None] = {}

        # Точный реестр активных задач: id → asyncio.Task. Заполняется в _process
        # до запуска обработчика и чистится в finally. Разница с _tasks/_inflight —
        # тут известен job_id, поэтому фоновый heartbeat может ставить «пульс»
        # КАЖДОЙ задаче, а не только последней заклейменной (старого бага
        # «молчащих» параллельных задач, см. _heartbeat_loop).

        ctx = HandlerContext(
            llm=LlmClient(config.llm_providers, config.llm_default_provider,
                          legacy_base_url=config.llm_base_url,
                          legacy_api_key=config.llm_api_key,
                          legacy_model=config.llm_model,
                          platform_model=getattr(config, "platform_model", "")),
            embed=EmbeddingClient(config.embed_base_url, config.embed_api_key,
                                  config.embed_model, config.embed_dimensions)
            if config.embed_base_url else None,
            config=config,
        )
        ctx._queue = self.queue
        ctx._worker = config.worker_name
        self.ctx = ctx

        # Фоновый поток жизни: проставляет heartbeat_at для ВСЕХ долгих задач,
        # которые не зовут ctx.heartbeat() вручную. Иначе PgQueue.claim()
        # (_sweep_stale) вернёт живую задачу в pending после timeout_sec.
        self._hb_task: asyncio.Task | None = None

        # Фоновая самоочистка очереди (pg-режим): в PgQueue разбор зависших строк
        # висел на claim(), то есть шёл ТОЛЬКО когда у воркера есть свободный слот.
        # Занятый (или замороженный) воркер свипер не запускал, и «Выполняется,
        # пульс 18 мин» жило до захода админа в монитор или крона.
        self._sweep_task: asyncio.Task | None = None

        # --- Сторожевые структуры (см. _hard_watchdog_loop/_lag_watchdog) -------
        # Что делает каждая задача прямо сейчас: job_id → "channel: этап".
        # Нужно, чтобы при заморозке event loop журнал назвал виновника, а не
        # просто «пульс пропал» (владелец: «некоторые зависают»).
        self._stage_of: dict[int, str] = {}
        # Абсолютный (monotonic) срок задачи и её лимит В СЕКУНДАХ.
        self._deadline_of: dict[int, float] = {}
        self._limit_of: dict[int, float] = {}
        # Задачи, снятые сторожевым ПОТОКОМ (их уже нет смысла complete/fail).
        self._hard_killed: set[int] = set()
        # Задачи, которые снял САМ САЙТ (владелец нажал «Прервать», свипер
        # вернул задачу в очередь, задачу перехватил другой воркер). Работу
        # продолжаем ровно до этого момента — дальше она была бы лишней.
        self._site_killed: set[int] = set()
        self._loop: asyncio.AbstractEventLoop | None = None
        self._hard_stop = threading.Event()
        self._hard_thread: threading.Thread | None = None
        self._lag_task: asyncio.Task | None = None
        self._presence_task: asyncio.Task | None = None
        self._last_lag_log = 0.0
        # Метка последнего тика event loop: сторожевой поток по ней понимает,
        # что цикл встал НАСМЕРТЬ (тогда задача сама себя не спасёт).
        self._loop_tick = 0.0

        # Воркер подписывается на этапы: обработчик зовёт ctx.progress() →
        # BaseWorker._note_stage() → _stage_of[job_id].
        self.ctx._on_progress = self._note_stage

    # ------------------------------------------------------------------
    async def run(self) -> None:
        self._install_signal_handlers()
        self._loop = asyncio.get_running_loop()
        # Очередь может быть временно недоступна (сеть, неверный токен, рестарт
        # сайта) — не падаем, а повторяем с нарастающей паузой: systemd не должен
        # гонять цикл перезапусков, воркер сам дождётся, пока всё починят.
        backoff = 5.0
        while True:
            try:
                await self.queue.connect()
                break
            except asyncio.CancelledError:
                raise
            except Exception as e:  # noqa: BLE001 — переподключение вместо падения
                hint = ""
                status = getattr(getattr(e, "response", None), "status_code", None)
                if status in (401, 403):
                    hint = (" — сайт отверг токен: сверьте WORKER_API_TOKEN "
                            "в workers/.env со значением в .env САЙТА (корень репозитория)")
                log.error("Очередь недоступна (%s%s). Повтор через %.0f с", e, hint, backoff)
                await asyncio.sleep(backoff)
                backoff = min(backoff * 1.5, 60.0)

        # Пул PG доступен обработчикам только в режиме pg. Это ОТДЕЛЬНЫЙ пул
        # (handler_pool), а не тот, что использует сама очередь: иначе долгие
        # обработчики (semantic.index держит соединение на время внешнего
        # embedding-вызова) исчерпали бы пул и заблокировали complete/fail/
        # heartbeat — задача висела бы processing при доставленном результате.
        self.ctx.pg = getattr(self.queue, "handler_pool", None)

        channels = self.config.channels or list(self.handlers.keys())
        log.info("Воркер '%s' запущен: режим=%s, каналы=%s",
                 self.config.worker_name, self.config.queue_mode, channels or "(все)")

        # Задачи ПРЕДЫДУЩЕГО запуска этого воркера: процесс мог быть убит
        # (OOM, рестарт деплоя) — строки оставались processing с мёртвым пульсом,
        # и владелец видел «Выполняется» часами. Свежий процесс их закрывает
        # сразу, до первого захвата.
        await self._release_orphans()

        # Пульс «воркер жив» для модулей, которым мало heartbeat задач:
        # у простаивающего воркера задач нет, и сайт считал его выключенным.
        # Включается только окружением (PRX670_API_BASE + токен), по умолчанию молчит.
        self._presence_task = None
        try:
            from .presence import config_from_env, presence_loop

            presence = config_from_env(self.config.worker_name, channels)
        except Exception:  # noqa: BLE001 — пульс не должен мешать старту
            presence = None
            log.debug("пульс воркера не настроен:\n%s", traceback.format_exc())

        if presence is not None:
            self._presence_task = asyncio.create_task(presence_loop(presence))
            log.info("Пульс ProcessHub: %s каждые %.0f с", presence.url, presence.interval)

        # Фоновый heartbeat: не даёт длинным задачам «молчать» дольше timeout_sec
        interval = max(1.0, float(getattr(self.config, "heartbeat_interval", 30.0) or 30.0))
        self._hb_task = asyncio.create_task(self._heartbeat_loop(interval))
        # Самоочистка очереди по таймеру: не зависит от того, свободен ли слот.
        self._sweep_task = asyncio.create_task(self._sweep_loop())
        # Диагностика «пульс пропал, а задача жива»: следим за отзывчивостью цикла
        self._lag_task = asyncio.create_task(self._lag_watchdog())
        # Жёсткий сторож — В ПОТОКЕ: снимает задачи, которые не уложились в лимит,
        # даже если event loop заморожен и asyncio-таймауты не тикают.
        self._start_hard_watchdog()

        idle = 0

        while not self._stopping:
            try:
                if self._inflight >= self.config.concurrency:
                    await asyncio.sleep(0.2)
                    continue

                job = await self.queue.claim(channels, self.config.worker_name)
                if job is None:
                    # Очередь пуста — затихаем: пауза удваивается от
                    # WORKER_POLL_INTERVAL до WORKER_IDLE_MAX_SEC. Это не только
                    # экономит запросы, но и убирает главный источник шума в
                    # логе: строка httpx на КАЖДЫЙ /prx120/api/claim. Появилась
                    # задача — пауза сразу возвращается к обычной.
                    idle += 1
                    delay = min(
                        self.config.poll_interval * (2 ** min(idle - 1, 6)),
                        self.config.idle_max_interval,
                    )
                    await self.queue.wait_for_wake(delay)
                    continue

                idle = 0

                task = asyncio.create_task(self._process(job))
                self._tasks.add(task)
                task.add_done_callback(self._tasks.discard)
            except asyncio.CancelledError:
                break
            except Exception:  # noqa: BLE001 — цикл воркера не должен падать
                log.error("Ошибка цикла воркера:\n%s", traceback.format_exc())
                await asyncio.sleep(2.0)

        # ВАЖНО (порядок): фоновый heartbeat гасится ПОСЛЕ _drain(), а не до.
        # Пока текущие задачи дожимаются (prx602/prx607 идут по 10-20 минут),
        # свипер очереди считает молчащую задачу зависшей и отказывает её —
        # результат доставлялся на сайт уже «после ошибки», и «Готово» не
        # приходило никому (монитор показывал 8 ошибок против 4 готовых).
        await self._drain()

        if self._hb_task is not None:
            self._hb_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await self._hb_task
        if self._sweep_task is not None:
            self._sweep_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await self._sweep_task
        if self._lag_task is not None:
            self._lag_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await self._lag_task
        if getattr(self, "_presence_task", None) is not None:
            self._presence_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await self._presence_task
        self._hard_stop.set()
        if self._hard_thread is not None:
            self._hard_thread.join(timeout=5.0)
        await self.queue.close()
        for client in (self.ctx.llm, self.ctx.embed):
            if client is not None:
                await client.close()
        log.info("Воркер остановлен")

    # ------------------------------------------------------------------
    def _track(self, job_id: int, task: asyncio.Task | None = None) -> None:
        """Вести реестр активных задач для фонового heartbeat.

        Словарь _current_jobs хранит ТОЛЬКО id (плоский): задачу можно найти
        по id, а heartbeat() из асинхронного цикла вызывается по id, а не по
        объекту задачи. Старый одиночный ctx._current_job перезаписывался
        каждой новой задачей и не умел пульсировать параллельные — те
        «молчали», монитор рисовал «застряли: N», и _sweep_stale мог вернуть
        живую задачу в pending (двойной прогон, рост очереди).
        """
        self._current_jobs[job_id] = task

    # ------------------------------------------------------------------
    async def _process(self, job: dict) -> None:
        channel = job["channel"]
        handler = self.handlers.get(channel)
        job_id = job["id"]
        started = time.monotonic()

        self._inflight += 1
        self.ctx._current_job = job  # contextvar: виден только этой asyncio-задаче
        _JOB_PROGRESS.set([])  # промежуточный журнал этой задачи (ctx.progress)
        # В реестре храним и саму задачу: при остановке по ней видно, какие
        # обработчики НЕ успели завершиться, и им ставится понятный финал
        # (см. _drain/_fail_interrupted).
        self._track(job_id, asyncio.current_task())
        usage = Usage.begin()  # учёт ресурсов задачи (модели/токены/чтение/поиск)

        # Лимит для сторожевого потока: таймаут задачи + запас на доставку
        # результата (complete/fail). Сторож — последняя линия обороны: он
        # работает в ОТДЕЛЬНОМ потоке и снимает задачу, даже если event loop
        # заморожен тяжёлым парсингом и asyncio-таймауты не тикают.
        try:
            limit = float(job.get("timeout_sec") or 600) + self._hard_grace()
        except (TypeError, ValueError):
            limit = 600.0 + self._hard_grace()

        self._limit_of[job_id] = limit
        self._deadline_of[job_id] = time.monotonic() + limit
        self._stage_of[job_id] = f"{channel}: запуск"

        try:
            if handler is None:
                await self.queue.fail(job_id, self.config.worker_name,
                                      f"Нет обработчика канала '{channel}'", retry=False)
                log.error("Задача %s: нет обработчика канала %s", job_id, channel)
                return

            log.info("Задача %s [%s] начата (попытка %s/%s)",
                     job_id, channel, job.get("attempts"), job.get("max_attempts"))

            timeout = float(job.get("timeout_sec") or 600)
            try:
                # asyncio.wait_for вместо asyncio.timeout(): работает и на
                # Python 3.10 (asyncio.timeout появился только в 3.11)
                result = await asyncio.wait_for(
                    handler(self.ctx, job.get("payload") or {}), timeout=timeout
                )
            except (asyncio.TimeoutError, TimeoutError):
                await self.queue.fail(job_id, self.config.worker_name,
                                      f"Превышен таймаут задачи ({timeout:.0f}s)",
                                      usage=usage.to_dict())
                log.warning("Задача %s: таймаут", job_id)
                return

            # отчёт о ресурсах — в result (сайт сложит в prx_120_usage);
            # если обработчик уже положил свой 'usage' — не трогаем
            if isinstance(result, dict) and "usage" not in result:
                result["usage"] = usage.to_dict()

            # промежуточный журнал задачи (ctx.progress) — в result, если
            # обработчик его не вернул сам под другим именем
            steps = (_JOB_PROGRESS.get() or [])
            if steps and isinstance(result, dict) and "_progress" not in result:
                result["_progress"] = steps

            # ВАЖНО: complete() возвращает False, если строка уже не в
            # processing у этого воркера (свипер вернул в очередь, задачу
            # перехватили, отменили, транспорт отказал). Раньше возврат
            # игнорировался и воркер логировал «выполнена», после чего
            # выкидывал задачу из реестра пульса — монитор показывал вечно
            # «Выполняется» при уже доставленном на сайт результате.
            ok = await self.queue.complete(job_id, self.config.worker_name, result,
                                           usage=usage.to_dict())
            if not ok:
                # одна мягкая повторная попытка (транзиентный сбой очереди)
                await asyncio.sleep(1.0)
                ok = await self.queue.complete(job_id, self.config.worker_name, result,
                                               usage=usage.to_dict())
            if not ok:
                # Добиваем до terminal-статуса: результат обработчик уже сам
                # доставил на сайт (образец prx602/prx606), но строка не должна
                # навсегда остаться processing. retry=False — реальная работа
                # уже сделана, повторный прогон удвоил бы артефакт.
                err = ("Результат получен, но очередь не приняла complete() "
                       "(задача вышла из processing у этого воркера)")
                log.warning("Задача %s: complete() не принят очередью — финальный fail()", job_id)
                try:
                    await self.queue.fail(job_id, self.config.worker_name, err,
                                          retry=False, usage=usage.to_dict())
                except Exception:  # noqa: BLE001 — не даём сбою fail() потерять задачу
                    log.error("Не удалось ни complete, ни fail задачу %s:\n%s",
                              job_id, traceback.format_exc())
                return
            log.info("Задача %s [%s] выполнена за %.1fs",
                     job_id, channel, time.monotonic() - started)
        except Exception as e:  # noqa: BLE001 — любая ошибка -> retry/fail
            brief = f"{type(e).__name__}: {e}"[:500]
            usage.add_error(brief)

            # Ошибка НАСТРОЙКИ LLM (нет токена/провайдера, 401/403 от сервера)
            # повтором не лечится: три попытки подряд = три отказа и лавина
            # ошибок в журнале провайдера (владелец видел такое от LM Studio).
            # 400/404/413/422 от сервера эмбеддингов — это «запрос неверный»
            # (модель не обслуживается, пачка велика, путь не тот). Повтор
            # отправит ровно тот же запрос и получит ровно тот же отказ;
            # владелец 22.10.2026 видел такой 400 и не понимал, что чинить.
            rejected = (isinstance(e, EmbeddingServerError)
                        and e.status in EmbeddingServerError.FATAL)

            config_error = (isinstance(e, LlmConfigError) or rejected
                            or "LLM-провайдер" in str(e)
                            or "LLM-провайдеры не настроены" in str(e))

            if config_error:
                log.error("Задача %s: ошибка, которую повтор не лечит (%s) — "
                          "повтор отменён. %s",
                          job_id, "запрос отклонён сервером" if rejected else "настройка LLM",
                          brief)

            try:
                await self.queue.fail(job_id, self.config.worker_name, brief,
                                      retry=not config_error, usage=usage.to_dict())
            except Exception:  # noqa: BLE001 — не дадим сбою fail() потерять задачу
                log.error("Не удалось пометить задачу %s как failed:\n%s",
                          job_id, traceback.format_exc())
            log.error("Задача %s упала: %s", job_id, brief)
            log.debug("%s", traceback.format_exc())
        except asyncio.CancelledError:
            # Снятие сторожевым таймером, отмена сайтом (кнопка «Прервать»)
            # или остановка воркера. Статус в очереди уже не наш — своей
            # записью не мешаем.
            if job_id in self._site_killed:
                log.warning("Задача %s [%s] отменена на сайте — воркер прекратил работу",
                            job_id, channel)
            elif job_id in self._hard_killed:
                log.warning("Задача %s [%s] снята сторожевым таймером — слот освобождён",
                            job_id, channel)
            raise
        finally:
            self._inflight -= 1
            self._current_jobs.pop(job_id, None)
            self._stage_of.pop(job_id, None)
            self._deadline_of.pop(job_id, None)
            self._limit_of.pop(job_id, None)
            self._hard_killed.discard(job_id)
            self._site_killed.discard(job_id)
            # Метки ctx.progress() уже ушли в очередь ЖИВЬЁМ (см. HandlerContext.progress).
            # Дублировать их здесь не нужно: повторные события засоряли бы журнал
            # задачи, а «статус-ряд» на сайте строится по последнему событию.
            # contextvar этой asyncio-задачи умирает вместе с таской; сброс
            # на None безопасен (не заденет параллельные задачи воркера)
            _CURRENT_JOB.set(None)
            _JOB_PROGRESS.set(None)

    # ------------------------------------------------------------------
    # Сторожевые механизмы
    # ------------------------------------------------------------------

    async def _release_orphans(self) -> None:
        """Закрыть задачи ПРЕДЫДУЩЕГО запуска этого же воркера.

        Без этого убитый процесс (OOM/деплой) оставлял строки в processing —
        владелец видел «Выполняется, пульс N мин» без конца. Отключается
        переменной PRX_RELEASE_ORPHANS=0 (если кто-то сознательно делит имя
        воркера между двумя живыми процессами).
        """
        if os.getenv("PRX_RELEASE_ORPHANS", "1").strip().lower() in ("0", "false", "no", "off"):
            return

        fn = getattr(self.queue, "release_own_orphans", None)

        if fn is None:
            return

        try:
            n = await fn(self.config.worker_name)

            if n:
                log.warning("Задач от прошлого запуска воркера '%s' закрыто: %d "
                            "(процесс был убит или перезапущен)", self.config.worker_name, n)
        except Exception as e:  # noqa: BLE001 — старт важнее уборки
            log.warning("Не удалось закрыть задачи прошлого запуска: %s", e)

    # ------------------------------------------------------------------
    async def _heartbeat_loop(self, interval: float) -> None:
        """Регулярно проставляет heartbeat_at всем активным задачам воркера.

        Пульс ставится КАЖДОЙ задаче из реестра _current_jobs (а не одной
        «текущей»): при конкурентности > 1 остальные задачи иначе молчат,
        монитор показывает «застряли: N», а _sweep_stale может вернуть живую
        задачу в pending (двойной прогон заметно дует очередь). Проставление
        асинхронно и не блокирует тик: heartbeat_at — это «жив/не жив»,
        точность до секунды не важна.
        """
        # Отказы heartbeat ПО ПОДРЯД: сайт может не принять пульс, когда задача
        # уже не наша — её отменил владелец («Прервать»), забрал свипер или
        # перехватил другой воркер. Одиночный отказ ничего не значит (гонка на
        # границе секунд), два подряд — задача потеряна, работу прекращаем.
        misses: dict[int, int] = {}

        while not self._stopping:
            try:
                await asyncio.sleep(interval)
                if self._stopping:
                    break
                for job_id in list(self._current_jobs):
                    try:
                        ok = await self.queue.heartbeat(job_id, self.config.worker_name)

                        if ok:
                            misses.pop(job_id, None)
                            continue

                        misses[job_id] = misses.get(job_id, 0) + 1
                        log.warning("heartbeat задачи %s не принят (%d подряд) — "
                                    "вероятно, задача отменена или перехвачена",
                                    job_id, misses[job_id])

                        if misses[job_id] >= 2:
                            misses.pop(job_id, None)
                            self._cancel_lost_job(job_id)
                    except Exception:  # noqa: BLE001 — один сбой не должен ронять остальные
                        log.debug("heartbeat %s: %s", job_id, traceback.format_exc())
            except asyncio.CancelledError:
                break
            except Exception:  # noqa: BLE001 — heartbeat не должен ронять цикл
                log.debug("heartbeat: %s", traceback.format_exc())

    async def _sweep_loop(self, interval: float | None = None) -> None:
        """Разбирать зависшие processing по таймеру, а не по заходу в монитор.

        Зачем: в pg-режиме PgQueue._sweep_stale() вызывается внутри claim(), то
        есть ровно тогда, когда у воркера ЕСТЬ свободный слот. Пока воркер занят
        (или его цикл заморожен), никто не закрывает задачи, чей процесс умер:
        владелец видит «Выполняется, пульс 18 мин, попытка 3/3» и не понимает,
        почему авто-остановка (240 с) не сработала — её просто никто не звал.

        Пороги те же, что у сайтового свипера («воркер молчит» → cancelled,
        попытки исчерпаны → failed), поэтому решения php- и python-путей не
        расходятся.
        """
        fn = getattr(self.queue, "sweep_stale_standalone", None)

        if fn is None:
            return

        if interval is None:
            try:
                interval = max(15.0, float(os.getenv("PRX_SWEEP_INTERVAL_SEC") or 60))
            except ValueError:
                interval = 60.0

        while not self._stopping:
            try:
                await asyncio.sleep(interval)
                if self._stopping:
                    break
                await fn()
            except asyncio.CancelledError:
                break
            except Exception:  # noqa: BLE001 — уборка не важнее работы воркера
                log.debug("sweep: %s", traceback.format_exc())

    def _cancel_lost_job(self, job_id: int) -> None:
        """Задача больше не наша: мягко прервать обработчик.

        Сайт уже поставил свой статус (cancelled — кнопка «Прервать» / авто-стоп,
        pending — свипер вернул задачу). Продолжать работу нельзя: результат
        всё равно не примут, а воркер занят слотом. Отменяем asyncio-задачу —
        LLM- и HTTP-вызовы прерываются сразу, `_process` увидит CancelledError
        и просто освободит слот.
        """
        task = self._current_jobs.get(job_id)

        if task is None or task.done():
            return

        self._site_killed.add(job_id)
        log.warning("Задача %s снята на сайте — прерываю работу воркера по ней", job_id)
        task.cancel()

    # ------------------------------------------------------------------
    def _stop_grace(self) -> float:
        """Сколько секунд ждём текущие задачи при остановке (env).

        systemd убивает процесс по TimeoutStopSec (по умолчанию 90 с) — если
        ждать «до победного», задача обрывается на середине и остаётся
        processing с мёртвым пульсом. Сайт закрывает такие строки только через
        ~30 минут и словами «воркер перестал подавать признаки жизни»: владелец,
        26.09, /leads — «запустил — не отработало», этапы дошли до «ИИ извлекает
        компании источников: 6» и тишина. Лучше честный финал сразу.
        """
        try:
            return max(5.0, float(os.environ.get("PRX_STOP_GRACE_SEC", "60") or 60))
        except ValueError:
            return 60.0

    async def _drain(self) -> None:
        """Дождаться текущих задач при остановке — но с дедлайном.

        Задачи, которые не успели за grace-секунды, закрываем сами: понятная
        причина в статусе сразу («воркер остановлен — задача прервана»), а не
        30 минут «Выполняется» с последующим отказом по мёртвому пульсу.
        """
        if not self._tasks:
            return

        grace = self._stop_grace()
        log.info("Ожидаю завершения %d текущих задач (не дольше %.0f с)...",
                 len(self._tasks), grace)

        done, pending = await asyncio.wait(set(self._tasks), timeout=grace)

        if not pending:
            return

        log.warning("При остановке не завершились задачи: %d — закрываю с понятной причиной",
                    len(pending))

        for job_id, task in list(self._current_jobs.items()):
            if task in pending:
                await self._fail_interrupted(job_id)

        for task in pending:
            task.cancel()

        await asyncio.gather(*pending, return_exceptions=True)

    async def _fail_interrupted(self, job_id: int) -> None:
        """Понятный финал задаче, оборванной остановкой воркера.

        Сначала обычный асинхронный fail() — цикл ещё жив; если очередь его не
        приняла, пробуем синхронный fail_blocking() (он работает и когда цикл
        занят). Совсем не вышло — строку закроет сайтовый свипер по пульсу.
        """
        err = ("Воркер остановлен (перезапуск или обновление сайта) — задача прервана "
               "до результата. Запустите её заново.")
        worker = self.config.worker_name

        try:
            if await self.queue.fail(job_id, worker, err, retry=False):
                return
        except Exception:  # noqa: BLE001 — не даём сбою fail() уронить остановку
            log.warning("Задача %s: fail() при остановке не принят", job_id)

        fn = getattr(self.queue, "fail_blocking", None)

        if fn is None:
            return

        try:
            fn(job_id, worker, err)
        except Exception:  # noqa: BLE001 — сторож не должен падать
            log.warning("Задача %s: fail_blocking() при остановке не сработал", job_id)

    def _install_signal_handlers(self) -> None:
        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            with contextlib.suppress(NotImplementedError):
                loop.add_signal_handler(sig, self._handle_signal, sig)

    def _handle_signal(self, sig) -> None:
        log.info("Получен сигнал %s — останавливаюсь (текущие задачи доделаются)", sig)
        self._stopping = True
        # Пульс текущим задачам — сразу и вне цикла _heartbeat_loop: цикл спит
        # interval (30 с), а задача, у которой на момент остановки heartbeat_at
        # вот-вот протухнет, должна дожить до своего complete() без «отказа
        # свипера». Best-effort: цикл события закрыт после сигнала, поэтому
        # только ставим задачи в очередь на пробуждение.
        try:
            loop = asyncio.get_running_loop()
        except RuntimeError:
            loop = None

        if loop is None:
            return

        for job_id in list(self._current_jobs):
            loop.create_task(self._heartbeat_once(job_id))

        self.queue.wake()

    async def _heartbeat_once(self, job_id: int) -> None:
        """Одиночный пульс задачи (не роняет воркер при сбое очереди)."""
        try:
            await self.queue.heartbeat(job_id, self.config.worker_name)
        except Exception:  # noqa: BLE001 — пульс best-effort
            log.debug("heartbeat %s при остановке: %s", job_id, traceback.format_exc())


def utcnow_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")
