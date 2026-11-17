"""Конфигурация воркеров: всё из переменных окружения (файл .env опционален).

Порядок приоритета: переменные окружения процесса > workers/.env > значения
по умолчанию. Файл .env в git не хранится — только workers/.env.example.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path


def _load_dotenv(path: Path) -> None:
    """Мини-загрузчик .env: переменные окружения главнее файла."""
    if not path.is_file():
        return
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        if line.startswith("export "):
            line = line[len("export "):]
        key, _, value = line.partition("=")
        key, value = key.strip(), value.strip().strip("'\"")
        if key and key not in os.environ:
            os.environ[key] = value


def _env(name: str, default: str = "") -> str:
    value = os.environ.get(name, "")
    return value if value.strip() else default


def _env_int(name: str, default: int) -> int:
    raw = _env(name, "")
    if not raw:
        return default
    try:
        return int(raw)
    except ValueError:
        raise RuntimeError(
            f"WORKERS: переменная {name} должна быть целым числом, получено {raw!r}"
        ) from None


def _env_float(name: str, default: float) -> float:
    raw = _env(name, "")
    if not raw:
        return default
    try:
        return float(raw)
    except ValueError:
        raise RuntimeError(
            f"WORKERS: переменная {name} должна быть числом, получено {raw!r}"
        ) from None


@dataclass
class Config:
    # --- очередь ---------------------------------------------------------
    queue_mode: str = "pg"                 # pg | http
    pg_dsn: str = ""
    api_url: str = ""                      # https://host/prx120/api
    api_token: str = ""
    worker_name: str = "worker-1"
    channels: list[str] = field(default_factory=list)   # пусто = все
    concurrency: int = 4
    poll_interval: float = 5.0
    # Потолок «затихания» опроса: пока очередь пуста, пауза удваивается от
    # poll_interval до idle_max_interval, а на первой же задаче сбрасывается.
    # Зачем: /prx120/api/claim дёргался каждые 5 с и засорял и лог, и сайт.
    #
    # ВАЖНО про задержку: в pg-режиме цикл будит LISTEN/NOTIFY (задержки нет),
    # а в http-режиме (воркеры на отдельном сервере) уведомлений нет — и этот
    # потолок становится ЗАДЕРЖКОЙ старта новой задачи. Поэтому по умолчанию
    # 10 с, а не 30: фото этикетки ждёт ответа в интерфейсе, и «воркер молчит
    # полминуты» выглядит как поломка. Нужна экономия запросов важнее скорости
    # — поднимайте ключом WORKER_IDLE_MAX_SEC.
    idle_max_interval: float = 10.0
    heartbeat_interval: float = 30.0

    # --- LLM --------------------------------------------------------------
    # Провайдеры (<ИМЯ>_API_URL/...) собираются в llm.scan_providers();
    # llm_base_url/llm_api_key/llm_model — эндпоинт провайдера ПО УМОЛЧАНИЮ
    # (исторические поля, используются langchain-путями как фолбэк).
    llm_base_url: str = ""
    llm_api_key: str = ""
    llm_model: str = ""
    llm_providers: dict = field(default_factory=dict)
    llm_default_provider: str = ""

    # --- эмбеддинг-сервер (отдельный эндпоинт) ------------------------------
    embed_base_url: str = ""
    embed_api_key: str = ""
    embed_model: str = ""
    embed_dimensions: int = 1024

    @classmethod
    def load(cls) -> "Config":
        _load_dotenv(Path(__file__).resolve().parent.parent / ".env")

        channels = [c.strip() for c in _env("WORKER_CHANNELS").split(",") if c.strip()]

        from .llm import default_provider_name, scan_providers

        providers = scan_providers()
        default_name = default_provider_name(providers)
        default_p = providers.get(default_name)

        return cls(
            queue_mode=_env("WORKER_QUEUE_MODE", "pg").lower(),
            pg_dsn=_env("WORKER_PG_DSN"),
            api_url=_env("PRX120_API_URL", _env("WORKER_API_URL")).rstrip("/"),
            api_token=_env("WORKER_API_TOKEN"),
            worker_name=_env("WORKER_NAME", "worker-1"),
            channels=channels,
            concurrency=max(1, _env_int("WORKER_CONCURRENCY", 4)),
            poll_interval=_env_float("WORKER_POLL_INTERVAL", 5.0),
            idle_max_interval=max(
                _env_float("WORKER_POLL_INTERVAL", 5.0),
                _env_float("WORKER_IDLE_MAX_SEC", 10.0),
            ),
            heartbeat_interval=_env_float("WORKER_HEARTBEAT_INTERVAL", 30.0),
            llm_base_url=default_p.base_url if default_p else _env("TOOLFORGE_API_URL").rstrip("/"),
            llm_api_key=default_p.api_key if default_p else _env("TOOLFORGE_API_KEY"),
            llm_model=(default_p.model if default_p
                       else _env("TOOLFORGE_DEFAULT_MODEL", "qwen3-coder-30b-a3b-instruct")),
            llm_providers=providers,
            llm_default_provider=default_name,
            embed_base_url=_env("EMBEDDING_API_URL").rstrip("/"),
            embed_api_key=_env("EMBEDDING_API_KEY"),
            embed_model=_env("EMBEDDING_MODEL", "text-embedding-qwen3-embedding-0.6b"),
            embed_dimensions=_env_int("EMBEDDING_DIMENSIONS", 1024),
        )

    def validate(self) -> list[str]:
        """Список проблем конфигурации (пустой = всё хорошо)."""
        problems: list[str] = []
        if self.queue_mode not in ("pg", "http"):
            problems.append(f"WORKER_QUEUE_MODE должен быть pg|http, а не '{self.queue_mode}'")
        if self.queue_mode == "pg" and not self.pg_dsn:
            problems.append("WORKER_PG_DSN не задан (режим pg)")
        if self.queue_mode == "http":
            if not self.api_url:
                problems.append("PRX120_API_URL не задан (режим http)")
            if not self.api_token:
                problems.append("WORKER_API_TOKEN не задан (режим http)")
        return problems
