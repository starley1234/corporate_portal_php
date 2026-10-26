"""Пульс воркера для ProcessHub (nj670).

Зачем отдельный канал: сайт решает, отдавать ли синтез воркеру, по признаку
«воркер жив». Раньше этим признаком был свежий heartbeat *взятой задачи*, а у
простаивающего воркера задач нет — сайт считал его выключенным и на каждое
создание процесса отвечал «воркер не в сети», хотя процесс работал.

Теперь воркер раз в минуту (и сразу после старта) шлёт POST `/process/w/ping`.
Это не задача очереди, а отдельный таск базового цикла: он не мешает claim,
не держит соединение и молча пропускает сетевые ошибки.

Настройка — только окружение (всё опционально, без него пульса нет):

  NJ670_API_BASE      адрес приёмной, например https://n2j.ru/process/w;
                      если задан корень сайта, /process/w допишется.
                      Не задан — берём сайт из NJ120_API_URL / NJ120_API_BASE
                      (http-режим очереди уже знает адрес).
  NJ670_SITE_TOKEN    токен (иначе WORKER_SITE_TOKEN, иначе WORKER_API_TOKEN)
  NJ670_PING_INTERVAL секунды между пульсами, по умолчанию 60
  NJ670_PING=0        выключить пульс

Модуль намеренно не зависит от базового цикла: его можно позвать из теста с
поддельным HTTP-клиентом.
"""

from __future__ import annotations

import asyncio
import logging
import os
from dataclasses import dataclass, field
from typing import Any, Mapping, Protocol

log = logging.getLogger("n2j.presence")

DEFAULT_INTERVAL = 60.0
MIN_INTERVAL = 5.0
ENDPOINT_SUFFIX = "/process/w"


class HttpClient(Protocol):
    async def post(self, url: str, **kwargs: Any) -> Any: ...
    async def aclose(self) -> None: ...


@dataclass
class PresenceConfig:
    """Куда и как часто стучаться."""

    base: str
    token: str
    worker: str
    channels: list[str] = field(default_factory=list)
    interval: float = DEFAULT_INTERVAL
    version: str = ""

    @property
    def url(self) -> str:
        return self.base.rstrip("/") + "/ping"

    def payload(self) -> dict[str, Any]:
        return {
            "worker": self.worker,
            "version": self.version,
            "channels": list(self.channels),
        }


def _first(env: Mapping[str, str], names: tuple[str, ...]) -> str:
    for name in names:
        value = (env.get(name) or "").strip()
        if value:
            return value
    return ""


def normalize_base(raw: str) -> str:
    """Привести адрес приёмной к виду «.../process/w».

    В `workers/.env.example` NJ670_API_BASE уже содержит /process/w, но люди
    часто пишут корень сайта — это не должно тихо ломать пульс.
    """
    base = (raw or "").strip().rstrip("/")

    if base == "":
        return ""

    if not base.endswith(ENDPOINT_SUFFIX):
        # если это похоже на полный адрес модуля с другим хвостом — не трогаем
        if "/process/w" not in base:
            base += ENDPOINT_SUFFIX

    return base


def _site_base(env: Mapping[str, str]) -> str:
    """Адрес приёмной из окружения.

    Явный NJ670_API_BASE главнее. Если его нет, сайт уже известен очереди:
    NJ120_API_URL в http-режиме (обычно `https://сайт/nj120/api`) или
    NJ120_API_BASE. Из них берём origin и дописываем /process/w — так пульс
    работает без новой строки в конфиге.
    """
    explicit = (env.get("NJ670_API_BASE") or "").strip()

    if explicit:
        return normalize_base(explicit)

    for name in ("NJ120_API_BASE", "NJ120_API_URL"):
        raw = (env.get(name) or "").strip().rstrip("/")

        if not raw:
            continue

        for suffix in ("/nj120/api", "/nj120", "/process/w"):
            if raw.endswith(suffix):
                raw = raw[: -len(suffix)]
                break

        return normalize_base(raw)

    return ""


def config_from_env(
    worker_name: str,
    channels: Any = (),
    env: Mapping[str, str] | None = None,
    version: str = "",
) -> PresenceConfig | None:
    """Собрать настройки пульса. None — пульс не настроен или выключен."""
    env = os.environ if env is None else env

    if (env.get("NJ670_PING") or "").strip().lower() in {"0", "false", "no", "off"}:
        return None

    base = _site_base(env)

    if base == "":
        # Без адреса сайта молчим: угадывать домен нельзя.
        return None

    token = _first(env, ("NJ670_SITE_TOKEN", "WORKER_SITE_TOKEN", "WORKER_API_TOKEN"))

    if token == "":
        log.warning(
            "NJ670_API_BASE задан, но токен не найден: пульс ProcessHub выключен. "
            "Задайте NJ670_SITE_TOKEN (тот же WORKER_API_TOKEN, что у сайта)."
        )
        return None

    try:
        interval = float(env.get("NJ670_PING_INTERVAL") or DEFAULT_INTERVAL)
    except (TypeError, ValueError):
        interval = DEFAULT_INTERVAL

    return PresenceConfig(
        base=base,
        token=token,
        worker=(worker_name or "worker")[:120],
        channels=[str(c)[:80] for c in (channels or [])],
        interval=max(MIN_INTERVAL, interval),
        version=(version or "")[:40],
    )


async def ping_once(config: PresenceConfig, client: HttpClient) -> bool:
    """Один пульс. Ошибки не пробрасываются: пульс не должен ронять воркер."""
    try:
        response = await client.post(
            config.url,
            json=config.payload(),
            headers={"X-Worker-Token": config.token, "Content-Type": "application/json"},
            timeout=15.0,
        )
    except Exception as e:  # noqa: BLE001 — сеть, DNS, таймаут
        log.debug("пульс ProcessHub не ушёл: %s", e)
        return False

    status = getattr(response, "status_code", 0) or 0

    if status >= 400:
        if status in (401, 403):
            log.warning(
                "сайт отверг пульс ProcessHub (%s): сверьте NJ670_SITE_TOKEN "
                "в workers/.env со значением WORKER_API_TOKEN в .env сайта",
                status,
            )
        else:
            log.debug("пульс ProcessHub: HTTP %s", status)
        return False

    return True


async def presence_loop(
    config: PresenceConfig,
    client: HttpClient | None = None,
    stop: asyncio.Event | None = None,
) -> None:
    """Пульс с интервалом, пока не попросят остановиться.

    `client` и `stop` в параметрах — для тестов: боевой вызов их не передаёт,
    и цикл сам создаёт httpx-клиент и живёт до отмены задачи.
    """
    owns_client = client is None

    if client is None:
        import httpx  # локальный импорт: тестам httpx не нужен

        client = httpx.AsyncClient(timeout=15.0)

    try:
        while True:
            if stop is not None and stop.is_set():
                return

            await ping_once(config, client)

            try:
                if stop is None:
                    await asyncio.sleep(config.interval)
                else:
                    await asyncio.wait_for(stop.wait(), timeout=config.interval)
                    return
            except asyncio.TimeoutError:
                continue
    except asyncio.CancelledError:
        raise
    finally:
        if owns_client:
            try:
                await client.aclose()
            except Exception:  # noqa: BLE001
                pass
