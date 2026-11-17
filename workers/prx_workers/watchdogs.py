"""Сторожевые механизмы воркера: лимит задачи, заморозка цикла, этапы.

WatchdogMixin живёт отдельно от BaseWorker, чтобы базовый цикл оставался
читаемым. Миксин ожидает поля, которые создаёт BaseWorker.__init__:
_stage_of, _deadline_of, _limit_of, _hard_killed, _current_jobs, _hard_stop,
_loop, _loop_tick, _last_lag_log, _stopping, queue и config.

Побочные эффекты: сторожевой поток пишет failed в очередь и отменяет asyncio-
задачи; при смерти event loop процесс завершается через os._exit(9), чтобы
systemd перезапустил воркер.
"""

from __future__ import annotations

import asyncio
import logging
import os
import threading
import time

log = logging.getLogger("prx.worker")


class WatchdogMixin:
    """Методы BaseWorker, которые следят за зависшими задачами и циклом."""

    @staticmethod
    def _hard_grace() -> float:
        """Запас к таймауту задачи: сколько сторож ждёт ПОСЛЕ истечения лимита.

        Даём обработчику договорить: complete()/fail() — это ещё пара запросов,
        и снимать задачу ровно в timeout нельзя (получим ложные «повис»).
        """
        try:
            return max(30.0, float(os.getenv("PRX_JOB_HARD_GRACE", "180") or 180))
        except ValueError:
            return 180.0

    def _note_stage(self, job_id: int, stage: str) -> None:
        """Что делает задача прямо сейчас (для сторожевого журнала)."""
        if job_id in self._stage_of:
            self._stage_of[job_id] = stage

    async def _lag_watchdog(self, step: float = 1.0) -> None:
        """Следит за отзывчивостью event loop и НАЗЫВАЕТ виновника заморозки.

        Почему это важно: пока цикл занят (тяжёлый HTML, C-расширение), не
        тикают ни heartbeat, ни asyncio-таймауты — снаружи это выглядит как
        «воркер завис», а в журнале нет ни строки о том, ЧТО делалось.
        Здесь мы измеряем разницу между плановым и фактическим временем шага и
        пишем: сколько цикл стоял и какие задачи держали слот.
        """
        try:
            warn = max(1.0, float(os.getenv("PRX_LOOP_LAG_WARN", "5") or 5))
        except ValueError:
            warn = 5.0

        while not self._stopping:
            t0 = time.monotonic()

            try:
                await asyncio.sleep(step)
            except asyncio.CancelledError:
                break

            self._loop_tick = time.monotonic()
            lag = (self._loop_tick - t0) - step

            if lag < warn:
                continue

            now = time.monotonic()

            # Не спамим: одна запись на заморозку (не чаще раза в 30 с).
            if now - self._last_lag_log < 30:
                continue

            self._last_lag_log = now
            busy = "; ".join(f"#{jid} {self._stage_of.get(jid, '?')}"
                              for jid in sorted(self._stage_of)) or "нет активных задач"

            (log.error if lag >= 30 else log.warning)(
                "Event loop воркера был занят %.1f с (плановый шаг %.0f с). "
                "Это замораживает пульс и таймауты задач. Активные задачи: %s",
                lag + step, step, busy)

    def _start_hard_watchdog(self) -> None:
        """Поднять сторожевой поток (жёсткий лимит на задачу)."""
        if self._hard_thread is not None:
            return

        self._hard_thread = threading.Thread(
            target=self._hard_watchdog_loop, name="prx-hard-watchdog", daemon=True)
        self._hard_thread.start()

    def _hard_watchdog_loop(self) -> None:
        """Сторож ЖЁСТКИХ лимитов — в отдельном потоке, вне event loop.

        Работает даже тогда, когда цикл заморожен (именно так выглядят
        «зависшие» задачи): проваливает строку в очереди синхронным запросом и
        снимает asyncio-задачу, освобождая слот воркера. Так задача в любом
        случае уходит из «Выполняется» — с внятной ошибкой вместо тишины.
        """
        try:
            step = max(1.0, float(os.getenv("PRX_HARD_WATCHDOG_SEC", "5") or 5))
        except ValueError:
            step = 5.0

        while not self._hard_stop.wait(step):
            self.hard_watchdog_pass()
            self.stalled_loop_pass()

    def hard_watchdog_pass(self) -> int:
        """Один проход сторожа: снять задачи, вышедшие за жёсткий лимит.

        Возвращает число снятых задач. Отдельным методом — чтобы это можно
        было проверить тестом без ожидания потока (см. tests/test_watchdogs.py).
        """
        now = time.monotonic()
        killed = 0

        for job_id, deadline in list(self._deadline_of.items()):
            if now < deadline or job_id in self._hard_killed:
                continue

            self._hard_killed.add(job_id)
            killed += 1
            stage = self._stage_of.get(job_id, "?")
            limit = self._limit_of.get(job_id, 0)
            err = (f"Задача не уложилась в {int(limit)} с (сторожевой таймер воркера) — "
                   f"снята на этапе «{stage}». Обработчик завис или воркер был "
                   f"заблокирован; запустите заново.")

            log.error("Сторож задач #%s [%s]: %s", job_id, stage, err)

            fn = getattr(self.queue, "fail_blocking", None)

            if fn is not None:
                try:
                    fn(job_id, self.config.worker_name, err)
                except Exception:  # noqa: BLE001 — сторож не падает
                    log.warning("Сторож задач: сбой закрытия #%s", job_id, exc_info=True)

            task = self._current_jobs.get(job_id)
            loop = self._loop

            if task is not None and loop is not None and not loop.is_closed():
                try:
                    loop.call_soon_threadsafe(task.cancel)
                except RuntimeError:
                    pass

        return killed

    def stalled_loop_pass(self) -> bool:
        """Цикл встал НАСМЕРТЬ (не тикал дольше лимита) → перезапуск процесса.

        Почему это нужно: если обработчик заблокировал event loop навсегда
        (C-расширение без отпускания GIL, бесконечный цикл), то НИ asyncio-таймаут,
        НИ снятие задачи через loop.call_soon_threadsafe не сработают — задача
        останется «Выполняется», а воркер перестанет брать новые задачи.
        Единственный честный выход — завершить процесс: systemd (Restart=always)
        поднимет воркер заново, а на старте BaseWorker._release_orphans вернёт
        задачи в очередь.

        Безопасность: ждём очень долго (по умолчанию 900 с непрерывной
        заморозки) и только при наличии активных задач — то есть когда воркер
        действительно мёртв, а не «медленно считает».

        Возвращает True, если процесс инициировал выход (для тестов).
        """
        if self._stopping or not self._loop_tick or not self._stage_of:
            return False

        try:
            limit = float(os.getenv("PRX_LOOP_STALL_EXIT", "900") or 900)
        except ValueError:
            limit = 900.0

        if limit <= 0:
            return False

        stalled = time.monotonic() - self._loop_tick

        if stalled < limit:
            return False

        busy = "; ".join(f"#{jid} {self._stage_of.get(jid, '?')}"
                          for jid in sorted(self._stage_of))
        log.critical("Event loop воркера заморожен %.0f с — перезапускаю процесс, "
                     "чтобы задачи не висели. Активные задачи: %s", stalled, busy)

        # Закрываем задачи ДО выхода: иначе после рестарта они вернутся как
        # сироты и начнутся заново (а так — честный failed с причиной).
        fn = getattr(self.queue, "fail_blocking", None)
        if fn is not None:
            for job_id in list(self._stage_of):
                try:
                    fn(job_id, self.config.worker_name,
                       f"Event loop воркера заморожен {stalled:.0f} с — процесс перезапущен")
                except Exception:  # noqa: BLE001 — уходим в любом случае
                    pass

        log.critical("Выход из процесса воркера: цикл не отвечает %.0f с", stalled)
        self._stopping = True
        os._exit(9)                     # noqa: SLF001 — намеренно: только systemd-рестарт
        return True

