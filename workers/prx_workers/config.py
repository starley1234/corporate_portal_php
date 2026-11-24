"""Конфигурация воркеров: всё из переменных окружения (файл .env опционален).

Приоритет: окружение процесса > workers/.env > настройки воркера из корневого .env.
"""

from __future__ import annotations

import os
import re
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import quote, urlencode


# Из корневого файла берём только параметры воркера, не соли, почту и ключи клиентов.
SHARED_KEYS = re.compile(
    r"(?:APP_URL|DB_DEFAULT_(?:HOST|PORT|NAME|USER|PASS|SCHEMA)|"
    r"WORKER_[A-Z0-9_]+|PRX(?:115|120|390|392|395_DOCUMENT_EXTRACT|396_PDF_CHUNKS)_(?:API_BASE|API_URL|SITE_TOKEN)|"
    r"PRX395_(?:MAX_PDF_BYTES|MAX_PAGES|MAX_TEXT_BYTES|RESULT_TTL_HOURS|TASK_TIMEOUT_SEC|MAX_ATTEMPTS|PDF_MEMORY_MB)|"
    r"PRX396_(?:MAX_PDF_BYTES|MAX_PAGES|CHUNK_PAGES|MAX_TEXT_BYTES|TTL_HOURS|RESULT_TTL_HOURS|TASK_TIMEOUT_SEC|MAX_ATTEMPTS|PDF_MEMORY_MB|PARALLEL_PARTS)|"
    r"OCR_[A-Z0-9_]+|EMBEDDING_[A-Z0-9_]+|LLM_DEFAULT_PROVIDER|DEFAULT_LLM_MODEL|"
    r"(?:LOCAL|OPENROUTER|TOOLFORGE)_(?:API_URL|API_KEY|DEFAULT_MODEL))"
)


def _dotenv_value(value: str) -> str:
    value = value.lstrip()
    if not value or value.startswith("#"):
        return ""
    if value[0] in ("'", '"') and value.rfind(value[0]) > 0:
        quote_char = value[0]
        inner = value[1:value.rfind(quote_char)]
        if quote_char == '"':
            for before, after in ((r"\n", "\n"), (r"\t", "\t"), (r'\"', '"'), (r"\\", "\\")):
                inner = inner.replace(before, after)
        return inner
    return value.split(" #", 1)[0].rstrip()


def _load_dotenv(path: Path, *, shared: bool = False) -> None:
    try:
        lines = path.read_text(encoding="utf-8").splitlines()
    except FileNotFoundError:
        return
    except PermissionError:
        if shared:
            return  # Отдельный системный пользователь может иметь только workers/.env.
        raise RuntimeError("Нет доступа на чтение workers/.env") from None
    values = {}
    for raw in lines:
        line = raw.strip().removeprefix("export ")
        key, sep, value = line.partition("=")
        key = key.strip()
        if sep and re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", key) and (not shared or SHARED_KEYS.fullmatch(key)):
            values[key] = _dotenv_value(value)
    for key, value in values.items():
        os.environ.setdefault(key, value)


def load_environment(worker_root: Path) -> None:
    _load_dotenv(worker_root / ".env")
    _load_dotenv(worker_root.parent / ".env", shared=True)


def _pg_dsn() -> str:
    explicit = _env("WORKER_PG_DSN")
    if explicit:
        return explicit
    host, name, user = _env("DB_DEFAULT_HOST"), _env("DB_DEFAULT_NAME"), _env("DB_DEFAULT_USER")
    if not (host and name and user):
        return ""
    # Локальный TCP/IPv6. Для сокета или особых параметров можно задать DSN явно.
    port = _env("DB_DEFAULT_PORT", "5432")
    if not re.fullmatch(r"[A-Za-z0-9_.:\[\]-]+", host) or not port.isascii() or not port.isdecimal():
        raise RuntimeError("Укажите явный WORKER_PG_DSN для нестандартного DB_DEFAULT_HOST/PORT")
    if ":" in host and not host.startswith("["):
        host = f"[{host}]"
    password = quote(os.getenv("DB_DEFAULT_PASS", ""), safe="")
    dsn = f"postgresql://{quote(user, safe='')}:{password}@{host}:{port}/{quote(name, safe='')}"
    schema = _env("DB_DEFAULT_SCHEMA")
    if schema and schema != "public":
        dsn += "?" + urlencode({"search_path": schema})
    return dsn


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
    # DEFAULT_LLM_MODEL целиком ("провайдер::модель"). Пусто — провайдер
    # отвечает своей <ИМЯ>_DEFAULT_MODEL.
    platform_model: str = ""
    llm_providers: dict = field(default_factory=dict)
    llm_default_provider: str = ""

    # --- эмбеддинг-сервер (отдельный эндпоинт) ------------------------------
    embed_base_url: str = ""
    embed_api_key: str = ""
    embed_model: str = ""
    embed_dimensions: int = 1024

    @classmethod
    def load(cls) -> "Config":
        load_environment(Path(__file__).resolve().parent.parent)
        from .site import queue_api_url

        mode = _env("WORKER_QUEUE_MODE", "pg").lower()

        channels = [c.strip() for c in _env("WORKER_CHANNELS").split(",") if c.strip()]

        from .providers import default_provider_name, scan_providers

        providers = scan_providers()
        default_name = default_provider_name(providers)
        default_p = providers.get(default_name)

        # DEFAULT_LLM_MODEL — общая модель платформы. Храним спеку целиком;
        # llm_model — только имя для исторического фолбэка без провайдеров.
        platform_model = _env("DEFAULT_LLM_MODEL")
        model_name = platform_model.split("::", 1)[-1].strip()

        return cls(
            queue_mode=mode,
            pg_dsn=_pg_dsn() if mode == "pg" else "",
            api_url=queue_api_url(),
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
                       else model_name
                       or _env("LOCAL_DEFAULT_MODEL", "qwen3-coder-30b-a3b-instruct")),
            platform_model=platform_model,
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
            problems.append("Для pg задайте DB_DEFAULT_HOST/NAME/USER или WORKER_PG_DSN")
        if self.queue_mode == "http":
            if not self.api_url:
                problems.append("Для http задайте APP_URL или PRX120_API_URL")
            if not self.api_token:
                problems.append("WORKER_API_TOKEN не задан (режим http)")
        return problems
