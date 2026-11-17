"""Реестр LLM-провайдеров из env и разбор спек моделей.

Провайдер = <ИМЯ>_API_URL/API_KEY/DEFAULT_MODEL/PROXY; имя — любой заголовок
env (LOCAL, OPENROUTER, TOOLFORGE). Спека модели: "провайдер::модель",
"модель" или "" (DEFAULT_LLM_MODEL платформы, затем DEFAULT_MODEL провайдера).

Побочных эффектов нет: env читается в момент вызова, сеть не трогается.
"""

from __future__ import annotations

import logging
import os
import re
from dataclasses import dataclass
from urllib.parse import urlparse

log = logging.getLogger("prx.llm")


# --------------------------------------------------------------------------
# Реестр провайдеров (env сканируется в момент вызова — .env грузится до)
# --------------------------------------------------------------------------

# LM Studio (и другие локальные серверы) требуют Bearer-токен. Имя переменной у
# каждого своё: у локального сервера — LOCAL_API_KEY, у нашего шлюза —
# <ИМЯ>_API_KEY. Читаем все варианты, чтобы «нет заголовка» не
# превращалось в лавину 401.
_LOCAL_TOKEN_VARS = ("LOCAL_API_KEY", "LM_API_TOKEN", "LMSTUDIO_API_TOKEN", "LM_STUDIO_API_TOKEN")


def _looks_local(base_url: str) -> bool:
    """Адрес похож на локальный сервер (LM Studio, шлюз в LAN)? """
    try:
        host = (urlparse(base_url).hostname or "").lower()
    except ValueError:
        return False

    if not host:
        return False

    if host in ("localhost", "127.0.0.1", "::1", "0.0.0.0", "host.docker.internal"):
        return True

    if host.endswith((".local", ".lan", ".home", ".internal")):
        return True

    # имя хоста без точки — машина в локальной сети
    if "." not in host:
        return True

    if host.startswith(("10.", "192.168.", "169.254.")):
        return True

    return bool(re.match(r"^172\.(1[6-9]|2\d|3[01])\.", host))


def _resolve_key_src(name: str, base_url: str) -> tuple[str, str]:
    """Токен провайдера и ИМЯ переменной, откуда он взят: (значение, источник).

    Порядок: <ИМЯ>_API_KEY → <ИМЯ>_API_TOKEN → <ИМЯ>_TOKEN, а для локального
    сервера — ещё и LOCAL_API_KEY (LM_API_TOKEN оставлен для совместимости
    со старыми стендами LM Studio). Источник нужен
    в журнале и в тексте 401: владелец должен видеть, какую ИМЕННО переменную
    (со старым/чужим токеном) отправил воркер — «неправильный ключ» иначе не
    отличить от «ключа нет вовсе».
    """
    for suffix in ("_API_KEY", "_API_TOKEN", "_TOKEN"):
        var = name + suffix
        val = os.getenv(var, "").strip()

        if val:
            return val, var

    if _looks_local(base_url):
        for var in _LOCAL_TOKEN_VARS:
            val = os.getenv(var, "").strip()

            if val:
                return val, var

    return "", ""


def _resolve_key(name: str, base_url: str) -> str:
    """Значение токена провайдера (см. _resolve_key_src)."""
    return _resolve_key_src(name, base_url)[0]

@dataclass
class Provider:
    name: str
    base_url: str
    api_key: str = ""
    model: str = ""       # <ИМЯ>_DEFAULT_MODEL
    proxy: str = ""       # <ИМЯ>_PROXY (опционально)
    is_default: bool = False
    api_key_src: str = ""  # имя env-переменной, откуда взят токен ("" = токена нет)


#: Эти <ИМЯ>_API_URL — НЕ чат-провайдеры: это сервисы эмбеддингов (см.
#: docstring модуля). Без исключения они попадали в реестр и ворчали в журнале
#: «у локального провайдера EMBEDDING нет токена», а чат мог уйти на сервер,
#: который умеет только векторы.
NOT_CHAT_PROVIDERS = frozenset({"EMBEDDING", "EMBEDDING_MM", "WORKER"})


def scan_providers() -> dict[str, Provider]:
    """Собрать провайдеров из env: все <ИМЯ>_API_URL (+ сопутствующие переменные)."""
    out: dict[str, Provider] = {}

    for key in os.environ:
        m = re.match(r"^([A-Z][A-Z0-9_]{1,30})_API_URL$", key)

        if not m:
            continue

        name = m.group(1)

        if name in NOT_CHAT_PROVIDERS or re.fullmatch(r"PRX[0-9]+(?:_.*)?", name):
            continue
        base_url = os.environ[key].strip().rstrip("/")

        if not base_url:
            continue

        key, key_src = _resolve_key_src(name, base_url)

        out[name] = Provider(
            name=name,
            base_url=base_url,
            api_key=key,
            api_key_src=key_src,
            model=os.getenv(name + "_DEFAULT_MODEL", "").strip(),
            proxy=os.getenv(name + "_PROXY", "").strip(),
            is_default=os.getenv(name + "_DEFAULT", "").strip().lower() in ("1", "true", "yes"),
        )

    return out


def default_provider_name(providers: dict[str, Provider]) -> str:
    """Имя провайдера по умолчанию (см. docstring модуля)."""
    if not providers:
        return ""
    explicit = (os.getenv("LLM_DEFAULT_PROVIDER", "").strip()
                or os.getenv("LLM_PROVIDER", "").strip()).upper()
    if explicit in providers:
        return explicit
    marked = [p for p in providers.values() if p.is_default]
    if len(marked) == 1:
        return marked[0].name
    if "LOCAL" in providers:
        return "LOCAL"
    if "TOOLFORGE" in providers:
        return "TOOLFORGE"
    return sorted(providers.keys())[0]


def resolve_model(providers: dict[str, Provider], default_name: str,
                  spec: str | None,
                  platform_model: str = "") -> tuple[Provider | None, str]:
    """Спека модели → (провайдер, модель).

    "name::model" → провайдер name; "model" → провайдер по умолчанию;
    "" → DEFAULT_LLM_MODEL платформы, иначе DEFAULT_MODEL провайдера.
    Неизвестный провайдер — WARN и провайдер по умолчанию (задачу не роняем).
    """
    if not providers:
        return None, ""

    spec = (spec or "").strip() or (platform_model or "").strip()

    if "::" in spec:
        name, model = spec.split("::", 1)
        name = name.strip().upper()
        model = model.strip()
        provider = providers.get(name)

        if provider is None:
            log.warning("LLM: провайдер '%s' не описан в env (<%s>_API_URL) — "
                        "использую провайдер по умолчанию '%s'", name, name, default_name)
            provider = providers.get(default_name)

        return provider, model or (provider.model if provider else "")

    provider = providers.get(default_name)

    if provider is None:
        return None, spec

    return provider, spec or provider.model


def _origin(url: str) -> str:
    """scheme://host:port — по нему узнаём «это тот же сервер»."""
    try:
        p = urlparse(url if "://" in url else "http://" + url)
    except ValueError:
        return ""

    return f"{(p.scheme or 'http').lower()}://{(p.hostname or '').lower()}:{p.port or ''}"


def provider_for_url(base_url: str,
                     providers: dict[str, Provider] | None = None) -> Provider | None:
    """Провайдер из реестра, который ходит на ТОТ ЖЕ сервер, что и base_url.

    Зачем. У LLM и у эмбеддингов исторически разные переменные окружения, но
    сервер у владельца один (LM Studio на llm.toolforge.ru): чат (/meal) ходит
    туда с токеном из TOOLFORGE_API_KEY и через TOOLFORGE_PROXY, а эмбеддинги —
    с пустым EMBEDDING_API_KEY и без прокси. Результат: /meal работает, а
    /veclab получает «No models loaded». Нашли провайдера по адресу — значит,
    можно взять его токен и прокси и разговаривать ровно так же.
    """
    origin = _origin(base_url)

    if not origin:
        return None

    pool = providers if providers is not None else scan_providers()
    same = [p for p in pool.values() if _origin(p.base_url) == origin]

    if not same:
        return None

    # С токеном — полезнее: ради него всё и затевалось.
    same.sort(key=lambda p: (not p.api_key, p.name))

    return same[0]


def resolve_endpoint(config, spec: str | None) -> tuple[str, str, str, str | None]:
    """Спека → (base_url, api_key, model, proxy). Для langchain-путей."""
    provider, model = resolve_model(config.llm_providers, config.llm_default_provider,
                                    spec, getattr(config, "platform_model", ""))

    if provider is None:
        # ни одного провайдера: историческое поведение — config.llm_base_url
        return config.llm_base_url, config.llm_api_key, model or config.llm_model, None

    return provider.base_url, provider.api_key, model, (provider.proxy or None)


