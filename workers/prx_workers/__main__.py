"""Точка входа воркеров: python -m prx_workers"""

from __future__ import annotations

import argparse
import asyncio
import logging
import os
import sys
from logging.handlers import RotatingFileHandler
from pathlib import Path

from .base import BaseWorker
from .config import Config
from .handlers import CONDITIONAL_CHANNELS, active_handlers
from .handlers import enabled as trial_enabled
from .site_check import targets
from .queue import make_queue
from .site import module_api_config, safe_origin

# Корень воркеров (…/workers) — рядом лежат .env, README и каталог логов.
WORKERS_ROOT = Path(__file__).resolve().parents[1]

LOG_FORMAT = "%(asctime)s %(levelname)s %(name)s: %(message)s"

# Шумные логгеры по умолчанию. httpx пишет INFO на КАЖДЫЙ HTTP-запрос — а
# воркер опрашивает очередь постоянно, и файл лога заполнялся строками
# «HTTP Request: POST https://…/prx120/api/claim "HTTP/1.1 200 OK"» быстрее,
# чем полезными событиями. Владелец (03.10.2026): «много мусорной записи…
# может, в логи добавить конфиг какого-то игнорирования и добавить туда
# /prx120/api/claim?» Настраивается без правки кода:
#   WORKER_LOG_QUIET=httpx,httpcore        — чьи INFO/DEBUG не пишем совсем
#   WORKER_LOG_SKIP=/prx120/api/claim       — подстроки сообщений, которые режем
# Пустое значение выключает соответствующую приглушалку.
DEFAULT_QUIET_LOGGERS = "httpx"
DEFAULT_SKIP_SUBSTRINGS = "/prx120/api/claim"


def _quiet_loggers() -> list[str]:
    raw = os.getenv("WORKER_LOG_QUIET", DEFAULT_QUIET_LOGGERS)

    return [name.strip() for name in raw.split(",") if name.strip()]


def _skip_substrings() -> list[str]:
    raw = os.getenv("WORKER_LOG_SKIP", DEFAULT_SKIP_SUBSTRINGS)

    return [part.strip() for part in raw.split(",") if part.strip()]


class DropNoise(logging.Filter):
    """Не пропустить в лог сообщения, которые владелец просил не писать.

    Режем по ПОДСТРОКЕ в тексте записи (`WORKER_LOG_SKIP`), а не по целой
    строке: у httpx сообщение склеено из метода, URL и статуса, и совпадения
    по «/prx120/api/claim» достаточно. Фильтр ставится на обработчики, поэтому
    не зависит от того, какой логгер писал.

    ВАЖНО: записи уровня WARNING и выше пропускаем всегда. Игнорировать надо
    РУТИНУ (успешный опрос очереди), а не беду: «claim вернул 500» обязан
    попасть в лог, иначе владелец не узнает, почему воркер не берёт задачи.
    """

    def __init__(self, needles: list[str]) -> None:
        super().__init__()
        self._needles = [n.lower() for n in needles if n]

    def filter(self, record: logging.LogRecord) -> bool:
        if not self._needles or record.levelno >= logging.WARNING:
            return True

        try:
            text = record.getMessage().lower()
        except Exception:  # noqa: BLE001 — фильтр не должен ронять логирование
            return True

        return not any(needle in text for needle in self._needles)


def _logging_disabled() -> bool:
    """WORKER_LOG_ENABLED=0 — логи только в stderr (systemd/journalctl)."""
    return os.getenv("WORKER_LOG_ENABLED", "1").strip().lower() in ("0", "no", "false", "off")


def _log_file() -> Path:
    """Путь к файлу лога: WORKER_LOG_FILE или workers/logs/prx-worker.log."""
    path = os.getenv("WORKER_LOG_FILE", "").strip()

    return Path(path) if path else WORKERS_ROOT / "logs" / "prx-worker.log"


def setup_logging() -> str:
    """Логи воркера: консоль (journalctl) + плоский ротируемый файл.

    Зачем файл, если есть journalctl: инцидент 03.10.2026 («фото уходит,
    результата нет») разбирали перепиской — от воркера нужен был журнал, а
    журнала в journalctl владелец не видел и прислать не мог. Файл в корне
    воркеров (`workers/logs/prx-worker.log`) можно открыть и прислать целиком.

    Ротация — по размеру (stdlib, без зависимостей): `WORKER_LOG_MAX_MB` (5) на
    файл, `WORKER_LOG_BACKUPS` (5) старых файлов `.1 … .N`, `WORKER_LOG_LEVEL`
    (INFO). Файловый лог — необязательный: если каталог недоступен на запись,
    воркер работает дальше и пишет только в stderr.

    Возвращает путь к файлу лога (или пустую строку, если файл не подключён).
    """
    level_name = os.getenv("WORKER_LOG_LEVEL", "INFO").strip().upper()
    handlers: list[logging.Handler] = [logging.StreamHandler(sys.stderr)]
    path = ""

    # Приглушённые логгеры (по умолчанию httpx): их INFO/DEBUG не пишем вообще.
    for name in _quiet_loggers():
        logging.getLogger(name).setLevel(max(logging.WARNING, logging.getLogger(name).level))

    if not _logging_disabled():
        file = _log_file()

        try:
            file.parent.mkdir(parents=True, exist_ok=True)

            max_mb = float(os.getenv("WORKER_LOG_MAX_MB", "5") or 5)
            backups = int(float(os.getenv("WORKER_LOG_BACKUPS", "5") or 5))

            handler = RotatingFileHandler(
                file,
                maxBytes=max(0, int(max_mb * 1048576)),
                backupCount=max(0, backups),
                encoding="utf-8",
            )
            handler.setFormatter(logging.Formatter(LOG_FORMAT))
            handlers.append(handler)
            path = str(file)
        except OSError as e:
            # Лог не критичен: без файла воркер обязан работать (в бою его
            # запускают systemd — stderr уезжает в journalctl).
            sys.stderr.write(f"[prx] файловый лог недоступен ({e}); "
                             f"логи только в stderr\n")

    # Фильтр «не писать это» — на КАЖДЫЙ обработчик, включая файловый: строка
    # про /prx120/api/claim не должна оседать ни в stderr, ни в файле.
    drop = DropNoise(_skip_substrings())

    for handler in handlers:
        handler.addFilter(drop)

    logging.basicConfig(
        level=getattr(logging, level_name, logging.INFO),
        format=LOG_FORMAT,
        handlers=handlers,
        force=True,
    )

    return path


def main() -> int:
    # .env читает Config.load() (переменные окружения процесса главнее), поэтому
    # логи настраиваются ПОСЛЕ него: иначе WORKER_LOG_FILE/WORKER_LOG_LEVEL из
    # workers/.env не увидели бы своё окружение.
    try:
        config = Config.load()
    except RuntimeError as e:
        # Опечатка в .env (например, WORKER_CONCURRENCY=три) — показать её надо
        # в лог, а лог ещё не настроен: сначала консоль, потом сообщение.
        setup_logging()
        logging.error("Конфигурация: %s", e)
        return 2

    log_file = setup_logging()

    problems = config.validate()
    requested = set(config.channels)
    handlers = active_handlers()
    unknown_channels = sorted(requested - handlers.keys() - CONDITIONAL_CHANNELS)
    if unknown_channels:
        problems.append("WORKER_CHANNELS: неизвестные каналы: " + ", ".join(unknown_channels)
                        + ". Уберите их или оставьте WORKER_CHANNELS пустым.")
    if problems:
        for p in problems:
            logging.error("Конфигурация: %s", p)
        return 2

    if (not requested or requested & CONDITIONAL_CHANNELS) and asyncio.run(trial_enabled(config.api_token)):
        handlers = active_handlers(True)
    disabled = requested - handlers.keys()
    if disabled:
        logging.warning("Пропущены выключенные каналы: %s", ", ".join(sorted(disabled)))
        config.channels = [channel for channel in config.channels if channel in handlers]
        if not config.channels:
            logging.error("Нет разрешённых каналов. Уберите WORKER_CHANNELS или укажите echo,document.extract.")
            return 2

    # Стартовая строка: по ней видно, с чем воркер подняли — и куда прислать
    # лог, а заодно чем приглушён шум (иначе «почему нет запросов httpx» —
    # первый вопрос при разборе чужого лога).
    skip = _skip_substrings()
    logging.info("Логи воркера: %s (тихие логгеры: %s; пропуск: %s)",
                 log_file or "(только stderr)",
                 ", ".join(_quiet_loggers()) or "—",
                 ", ".join(skip) or "—")
    logging.info("Очередь: %s · каналы: %s", config.queue_mode, ",".join(config.channels) or "все")
    for channel, (prefix, path, _module, _token_kind) in targets(handlers).items():
        if channel in (config.channels or handlers):
            base, source = module_api_config(prefix, path)
            logging.info("PHP для %s: %s (%s)", channel, safe_origin(base), source)

    worker = BaseWorker(config, make_queue(config), handlers)
    try:
        asyncio.run(worker.run())
    except KeyboardInterrupt:
        pass
    return 0


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="PRX Python worker")
    parser.add_argument("--check-site", action="store_true", help="проверить HTTP-связь с PHP, не забирая задачи")
    args = parser.parse_args()
    if args.check_site:
        from .config import load_environment
        from .site_check import check_site

        try:
            load_environment(WORKERS_ROOT)
        except RuntimeError as exc:
            print(exc, file=sys.stderr)
            sys.exit(2)
        logging.basicConfig(level=logging.INFO, format=LOG_FORMAT)
        logging.getLogger("httpx").setLevel(logging.WARNING)
        sys.exit(asyncio.run(check_site(os.getenv("WORKER_API_TOKEN", "").strip(),
                                         legacy=None)))
    sys.exit(main())
