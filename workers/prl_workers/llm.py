"""HTTP-клиенты внешних AI-сервисов для воркеров.

Провайдеры LLM (OpenAI-совместимые чаты):
  * провайдер = набор env <ИМЯ>_API_URL, <ИМЯ>_API_KEY, <ИМЯ>_DEFAULT_MODEL,
    <ИМЯ>_PROXY (опционально), <ИМЯ>_DEFAULT=1 (провайдер по умолчанию, опционально);
    имя — любой заголовок env, напр. TOOLFORGE (LM Studio), OPENROUTER, LOCAL;
  * модели модулей (PRL540_MODEL, PRL550_MODEL, PRL570_CODE_MODEL, PRL580_DOC_MODEL, …)
    понимают спеки:
      "openrouter::google/gemini-3-flash-preview" — провайдер + модель;
      "unsloth/gemma-4-12b-it"                    — провайдер по умолчанию + модель;
      ""                                          — провайдер по умолчанию + его DEFAULT_MODEL;
    спека с несколькими "::" делится по ПЕРВОМУ ("openrouter::openrouter::stepfun/…"
    → провайдер openrouter, модель "openrouter::stepfun/…");
  * провайдер по умолчанию: LLM_PROVIDER → единственный <ИМЯ>_DEFAULT=1 →
    TOOLFORGE (исторический) → первый найденный;
  * EmbeddingClient — ОТДЕЛЬНЫЙ сервис эмбеддингов (EMBEDDING_API_URL),
    к провайдерам LLM не относится.

Все методы асинхронные, с таймаутами; ошибки httpx/HTTPStatus не глотаются —
воркер пометит задачу как failed и попробует повторить.
"""

from __future__ import annotations

import logging
import os
import re
from dataclasses import dataclass
from typing import Sequence
from urllib.parse import urlparse

import httpx

from .usage import Usage

log = logging.getLogger("prl.llm")


class LlmConfigError(RuntimeError):
    """Ошибка НАСТРОЙКИ LLM (нет токена/провайдера/сервер недоступен).

    Такой сбой не лечится повтором: воркер помечает задачу failed без retry,
    чтобы не долбить провайдера три раза подряд (владелец видел поток ошибок
    LM Studio «An LM Studio API token is required…» — это как раз этот случай).
    """


class EmbeddingServerError(RuntimeError):
    """Сервер эмбеддингов ответил ошибкой — и мы ЗНАЕМ, чем именно.

    Зачем отдельный класс. `httpx.raise_for_status()` оставляет от ответа одну
    строку «Client error '400 Bad Request'». Для 400 это худший из возможных
    текстов: причин много (модель не обслуживается на этом пути, пачка больше
    допустимой, вход длиннее контекста, не тот формат `input`), и все они
    лечатся по-разному. Владелец 22.10.2026 получил именно такую строку и
    справедливо спросил «а ключ вы вообще использовали?» — хотя сервер, скорее
    всего, говорил совсем другое.

    Поэтому в сообщении: адрес, модель, сколько входов и символов уехало,
    ТЕЛО ответа сервера и подсказка под конкретный код.
    """

    #: HTTP-коды, при которых виноват запрос, а не сеть: повтор не поможет.
    FATAL = (400, 404, 413, 422)

    def __init__(self, base_url: str, model: str, status: int, body: str = "",
                 inputs: int = 0, chars: int = 0, note: str = ""):
        self.base_url = base_url
        self.model = model
        self.status = int(status)
        self.body = (body or "").strip()
        self.inputs = int(inputs)
        self.chars = int(chars)

        where = f"{base_url}/embeddings"
        size = f"модель {model!r}"
        if inputs:
            size += f", входов {inputs}, символов {chars}"

        hint = ""

        if self.status == 400:
            hint = ("Сервер отклонил сам запрос (это не про токен — на плохой токен "
                    "он отвечает 401/403). Смотрите его текст выше: чаще всего там "
                    "«model not found» (модель не обслуживается на этом адресе), "
                    "«batch too large» (уменьшите EMBEDDING_BATCH_SIZE) или "
                    "«input too long». Реже — сервер ждёт `input` строкой, а не "
                    "списком. Список моделей сервера: "
                    f"GET {base_url}/models")
        elif self.status == 404:
            hint = ("Похоже, адрес не тот: у сервера нет POST /embeddings. "
                    "EMBEDDING_API_URL должен указывать на КОРЕНЬ API "
                    "(например https://host/v1), путь /embeddings добавляется сам.")
        elif self.status == 413:
            hint = "Слишком большой запрос: уменьшите EMBEDDING_BATCH_SIZE."
        elif "no models loaded" in self.body.lower() or "lms load" in self.body.lower():
            # LM Studio: имя модели не совпало ни с одной скачанной, и он
            # отвечает так, будто сервер пуст. Владелец 22.10.2026 прочитал это
            # как «сломались настройки» и пошёл проверять ключ.
            hint = ("Это LM Studio: он не нашёл модель с таким именем и ответил, "
                    "будто ничего не загружено. Посмотрите список "
                    f"(GET {base_url}/models) и впишите имя из него в "
                    "EMBEDDING_MODEL. Мультимодальные (VL) модели LM Studio по "
                    "/v1/embeddings не отдаёт — они подключаются отдельным "
                    "сервисом через EMBEDDING_MM_API_URL.")

        elif self.status == 422:
            hint = ("Формат запроса не принят. Если сервер мультимодальный, ему нужен "
                    "не /v1/embeddings с текстом, а свой путь — такой сервер "
                    "подключается через EMBEDDING_MM_API_URL.")
        elif self.status >= 500:
            hint = "Ошибка на стороне сервера: повторите позже или проверьте его журнал."

        message = f"Сервер эмбеддингов ответил HTTP {self.status} на {where} ({size})."
        if note:
            message += f" {note}."
        if self.body:
            message += f" Ответ сервера: {self.body}"
        else:
            message += " Тело ответа пустое — сервер не объяснил причину."
        if hint:
            message += f" {hint}"

        super().__init__(message)


# --------------------------------------------------------------------------
# Реестр провайдеров (env сканируется в момент вызова — .env грузится до)
# --------------------------------------------------------------------------

# LM Studio (и другие локальные серверы) требуют Bearer-токен. Имя переменной у
# каждого своё: у LM Studio это LM_API_TOKEN (см. https://lmstudio.ai/docs), у
# нашего шлюза — <ИМЯ>_API_KEY. Читаем все варианты, чтобы «нет заголовка» не
# превращалось в лавину 401.
_LOCAL_TOKEN_VARS = ("LM_API_TOKEN", "LMSTUDIO_API_TOKEN", "LM_STUDIO_API_TOKEN")


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
    сервера — ещё и LM_API_TOKEN (имя из документации LM Studio). Источник нужен
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

        if name in NOT_CHAT_PROVIDERS or re.fullmatch(r"PRL[0-9]+(?:_.*)?", name):
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
    explicit = os.getenv("LLM_PROVIDER", "").strip().upper()
    if explicit in providers:
        return explicit
    marked = [p for p in providers.values() if p.is_default]
    if len(marked) == 1:
        return marked[0].name
    if "TOOLFORGE" in providers:
        return "TOOLFORGE"
    return sorted(providers.keys())[0]


def resolve_model(providers: dict[str, Provider], default_name: str,
                  spec: str | None) -> tuple[Provider | None, str]:
    """Спека модели → (провайдер, модель).

    "name::model" → провайдер name; "model" → провайдер по умолчанию;
    "" → провайдер по умолчанию + его DEFAULT_MODEL. Неизвестный провайдер —
    WARN и провайдер по умолчанию (задачу не роняем).
    """
    if not providers:
        return None, ""

    spec = (spec or "").strip()

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
    provider, model = resolve_model(config.llm_providers, config.llm_default_provider, spec)

    if provider is None:
        # ни одного провайдера: историческое поведение — config.llm_base_url
        return config.llm_base_url, config.llm_api_key, model or config.llm_model, None

    return provider.base_url, provider.api_key, model, (provider.proxy or None)


# --------------------------------------------------------------------------
# Клиенты
# --------------------------------------------------------------------------

def _no_think_messages(messages: list[dict]) -> list[dict]:
    """Добавить «/no_think» (мягкий выключатель размышлений Qwen3) в системное
    сообщение — или первым системным, если его нет. Исходный список не меняем."""
    out = [dict(m) for m in messages]
    for m in out:
        if m.get("role") == "system" and isinstance(m.get("content"), str):
            if "/no_think" not in m["content"]:
                m["content"] = m["content"].rstrip() + " /no_think"
            return out
    return [{"role": "system", "content": "/no_think"}] + out


class LlmClient:
    """Chat Completions через ЛЮБОЙ из описанных провайдеров.

    model в chat()/chat_with_usage() — спека ("openrouter::…" / "модель" / "").
    httpx-клиент создаётся лениво на каждого провайдера (с его прокси).
    """

    def __init__(self, providers: dict[str, Provider] | None = None,
                 default_provider: str = "", timeout: float = 240.0,
                 legacy_base_url: str = "", legacy_api_key: str = "", legacy_model: str = ""):
        self._providers = providers if providers is not None else scan_providers()
        self._default = default_provider or default_provider_name(self._providers)

        # Исторический одиночный эндпоинт (если провайдеров в env нет вообще)
        if not self._providers and legacy_base_url:
            self._providers = {"DEFAULT": Provider(
                name="DEFAULT", base_url=legacy_base_url.rstrip("/"),
                api_key=legacy_api_key, model=legacy_model,
                api_key_src="LLM_API_KEY" if legacy_api_key else "")}
            self._default = "DEFAULT"

        self._timeout = timeout
        self._clients: dict[str, httpx.AsyncClient] = {}
        self._models_cache: dict[str, set[str] | None] = {}   # провайдер → его /models

        # Видно сразу в журнале воркера: у локального сервера нет токена, а он
        # его требует (LM Studio отвечает 401 «invalid_api_key»).
        for p in self._providers.values():
            if not p.api_key and _looks_local(p.base_url):
                log.warning(
                    "LLM: у локального провайдера '%s' (%s) НЕТ токена — если сервер требует "
                    "Authorization: Bearer (LM Studio), задайте %s_API_KEY или LM_API_TOKEN "
                    "в workers/.env и перезапустите воркер",
                    p.name, p.base_url, p.name)
            elif p.api_key:
                # Видно в журнале при старте: какая переменная дала токен. Если это
                # ОДНА переменная на два сервера (LM Studio и, скажем, шлюз) — тут
                # же видно, что токен мог достаться чужой стороне.
                log.info("LLM: провайдер '%s' (%s) — токен из %s",
                         p.name, p.base_url, p.api_key_src or "config")

    # -- совместимость со старыми обращениями ctx.llm.model / ctx.llm.base_url
    @property
    def model(self) -> str:
        p = self._providers.get(self._default)
        return p.model if p else ""

    @property
    def base_url(self) -> str:
        p = self._providers.get(self._default)
        return p.base_url if p else ""

    def _client_for(self, provider: Provider) -> httpx.AsyncClient:
        client = self._clients.get(provider.name)

        if client is None:
            client = httpx.AsyncClient(
                timeout=self._timeout,
                headers={"Authorization": f"Bearer {provider.api_key}"} if provider.api_key else {},
                **({"proxy": provider.proxy} if provider.proxy else {}),
            )
            self._clients[provider.name] = client

        return client

    async def close(self) -> None:
        for client in self._clients.values():
            await client.aclose()
        self._clients.clear()

    async def chat(
        self,
        messages: list[dict],
        model: str | None = None,
        **options,
    ) -> str:
        text, _usage = await self.chat_with_usage(messages, model=model, **options)
        return text

    async def _provider_models(self, provider: Provider) -> set[str] | None:
        """Список моделей провайдера (OpenAI-совместимый GET /models), кэшируется.

        None — эндпоинт недоступен или не отвечает списком (не считаем ошибкой).
        Справочник нужен ТОЛЬКО чтобы увести запрос к другому провайдеру, у
        которого заявленная модель есть. Локальный сервер БЕЗ токена (LM Studio
        0.3+) на этот запрос отвечает 401 — спрашивать нечего, а мусорить в его
        логе «invalid_api_key» нельзя: владелец видел именно эти строки и искал
        виновника. Поэтому такой провайдер пропускаем без запроса.
        """
        if provider.name in self._models_cache:
            return self._models_cache[provider.name]

        if not provider.api_key and _looks_local(provider.base_url):
            log.debug("LLM: справочник моделей '%s' не спрашиваю — локальный сервер "
                      "без токена (ответит 401)", provider.name)
            self._models_cache[provider.name] = None
            return None

        models: set[str] | None = None
        try:
            r = await self._client_for(provider).get(provider.base_url + "/models")

            if r.status_code in (401, 403):
                log.warning("LLM: провайдер '%s' (%s) требует токен (HTTP %s) — "
                            "справочник моделей недоступен; токен сейчас: %s",
                            provider.name, provider.base_url, r.status_code,
                            (f"из {provider.api_key_src}, и он отклонён" if provider.api_key_src
                             else "не задан (ни один из вариантов не найден)"))

            if r.status_code == 200:
                data = r.json().get("data") or []
                models = {str(m.get("id")) for m in data if m.get("id")} or None
        except Exception:  # noqa: BLE001 — справочник не обязателен
            models = None
        self._models_cache[provider.name] = models
        return models

    @staticmethod
    def _auth_error(provider: Provider, status: int, detail: str) -> LlmConfigError:
        """401/403 провайдера → инструкция, что именно поправить.

        Различаем два случая, иначе «неправильный ключ» неотличим от «ключа нет»:
          * токен НЕ отправляли (не задан) — просим задать переменную;
          * токен отправляли из <VAR> — значит, он устарел/чужой: правим ИМЕННО <VAR>.
        """
        hint = f"{provider.name}_API_KEY"

        if _looks_local(provider.base_url):
            hint += " (или LM_API_TOKEN — так называется токен в LM Studio)"

        if provider.api_key_src:
            what = (f"Воркер отправлял токен из {provider.api_key_src} — он устарел, "
                    f"скопирован от другого сервера или в нём лишние символы. "
                    f"Проверьте {provider.api_key_src} (эталон: {hint}).")
        else:
            what = f"Токен не отправляли вовсе. Задайте {hint} в workers/.env."

        return LlmConfigError(
            f"LLM-провайдер '{provider.name}' ({provider.base_url}) отклонил запрос: "
            f"HTTP {status}. {what} И перезапустите воркер. "
            f"Ответ сервера: {detail[:300]}")

    async def _post_chat(self, provider: Provider, payload: dict,
                         timeout: float | None = None) -> tuple[str, dict]:
        try:
            r = await self._client_for(provider).post(
                f"{provider.base_url}/chat/completions", json=payload,
                timeout=timeout)
        except httpx.TransportError as e:
            if _looks_local(provider.base_url):
                log.warning("LLM: локальный провайдер '%s' (%s) недоступен: %s — "
                            "проверьте, запущен ли сервер и его адрес в workers/.env",
                            provider.name, provider.base_url, e)
            raise

        if r.status_code in (401, 403):
            raise self._auth_error(provider, r.status_code, (r.text or "").strip())

        r.raise_for_status()
        data = r.json()
        msg = data["choices"][0]["message"] if data.get("choices") else {}
        # Контент: обычный текст + запасные поля рассуждающих моделей
        content = msg.get("content") if isinstance(msg, dict) else None
        if not content:
            # Некоторые шлюзы кладут размышления отдельно
            for alt in ("reasoning_content", "reasoning", "thought", "thinking"):
                if isinstance(msg, dict) and msg.get(alt):
                    # Если есть и content и reasoning — склеиваем, но приоритет content
                    rc = msg.get(alt)
                    if isinstance(rc, str) and rc.strip():
                        content = (content or "") + ("\n" + rc if content else rc)
                        break
        reasoning_only = False
        if not (msg.get("content") if isinstance(msg, dict) else None) and content:
            # content пуст, текст взят из reasoning_* — это НЕ ответ, а размышления.
            # Флаг нужен обработчикам, чтобы не принять их за результат.
            reasoning_only = True
        if content is None:
            content = ""
        usage = data.get("usage") or {}
        usage = usage if isinstance(usage, dict) else {}
        if reasoning_only:
            usage["_llm_reasoning_only"] = True
            log.warning("LLM: модель %s вернула только размышления (content пуст, %d знаков reasoning) — "
                        "выключите thinking: LLM_THINKING=off в workers/.env или non-thinking модель",
                        data.get("model") or "?", len(content))
        # финиш-реason «провайдера»: length = ответ обрезали по max_tokens
        # (статья/JSON недописаны). Кладём в usage — обработанчики (prl602)
        # доделывают обрез продолжением генерации.
        try:
            finish = data["choices"][0].get("finish_reason") or "stop"
        except (KeyError, IndexError, TypeError):
            finish = "stop"
        usage["_llm_finish_reason"] = finish
        # reasoning-токены иногда лежат в choices.message.reasoning_details
        if isinstance(msg, dict) and msg.get("reasoning_details") and not usage.get("completion_tokens_details"):
            usage["completion_tokens_details"] = {"reasoning_tokens": 0}
        return content, usage

    async def chat_with_usage(
        self,
        messages: list[dict],
        model: str | None = None,
        **options,
    ) -> tuple[str, dict]:
        """Текст ответа + usage. model — спека провайдера и модели.

        Живучесть (важно для «мне нужна работоспособность»):
          1) модель спекой не заявлена у выбранного провайдера (по GET /models),
             но есть у другого — запрос уходит туда (WARN в лог);
          2) провайдер ответил 400/404/422 (обычно «model not found») —
             один повтор на DEFAULT_MODEL этого провайдера (WARN в лог)."""
        # request_timeout — служебный параметр ТРАНСПОРТА (не уходит в API):
        # длинные генерации кода через медленный прокси требуют больше, чем
        # дефолтные 240 с
        options = dict(options)
        request_timeout = options.pop("request_timeout", None)
        # think=False — выключить «размышления» модели (Qwen3/3.5, DeepSeek-R1 и
        # т. п.): иначе 9B-модель тратит весь max_tokens на reasoning_content, а
        # content приходит пустым (prl602 25.10: «фактов: 0» при 4000 токенов).
        # Глобально — LLM_THINKING=off в workers/.env; явный think=... сильнее.
        think = options.pop("think", None)
        if think is None and os.getenv("LLM_THINKING", "").strip().lower() in ("off", "0", "false", "no"):
            think = False

        provider, resolved = resolve_model(self._providers, self._default, model)

        if provider is None:
            raise RuntimeError(
                "LLM-провайдеры не настроены: задайте в workers/.env хотя бы один "
                "<ИМЯ>_API_URL (напр. TOOLFORGE_API_URL / OPENROUTER_API_URL)")

        # (1) модель не заявлена у провайдера — ищем у других.
        # Справочник спрашиваем ТОЛЬКО когда провайдеров больше одного: уводить
        # запрос больше некуда, а лишний GET /models — ровно тот запрос, который
        # светился в логе LM Studio («кто без конца опрашивает?»).
        orig_name = provider.name
        rerouted = ""
        try:
            known = await self._provider_models(provider) if len(self._providers) > 1 else None
            if known and resolved and resolved not in known:
                for cand in sorted(self._providers.values(), key=lambda p: p.name):
                    if cand.name == provider.name:
                        continue
                    cmodels = await self._provider_models(cand)
                    if cmodels and resolved in cmodels:
                        log.warning("LLM: модели '%s' нет у провайдера '%s' — "
                                    "запрос уходит в '%s'", resolved, provider.name, cand.name)
                        rerouted = cand.name
                        provider = cand
                        break
        except Exception:  # noqa: BLE001 — справочник не должен ломать запрос
            pass

        if think is False:
            messages = _no_think_messages(messages)
        payload = {"model": resolved, "messages": messages, **options}
        if think is False and _looks_local(provider.base_url):
            # llama.cpp / vLLM / LM Studio понимают chat_template_kwargs; облачные
            # API (OpenAI) отвечают 400 на незнакомый параметр — им только /no_think
            payload["chat_template_kwargs"] = {"enable_thinking": False}
        # Каждый вызов виден в journalctl одной строкой и БЕЗ секретов: провайдер,
        # модель, и ИМЯ переменной, откуда взят токен. Владелец подозревал, «что
        # от /prl120/api/claim идёт запрос на LLM с неправильным ключом» — теперь
        # проверяется грепом, а не догадками:
        #   journalctl -u prl-worker | grep "LLM ->"
        log.info("LLM -> %s модель=%s сообщений=%d (токен: %s)",
                 provider.name, resolved or "(default)", len(messages),
                 provider.api_key_src or "не задан")

        try:
            try:
                text, usage = await self._post_chat(provider, payload, timeout=request_timeout)
            except httpx.HTTPStatusError as e0:
                if e0.response.status_code == 400 and "chat_template_kwargs" in payload:
                    # сервер не знает параметра — повтор без него (команда /no_think
                    # в сообщениях остаётся)
                    payload.pop("chat_template_kwargs", None)
                    text, usage = await self._post_chat(provider, payload, timeout=request_timeout)
                else:
                    raise
        except httpx.HTTPStatusError as e:
            status = e.response.status_code
            if status in (400, 404, 422) and provider.model and provider.model != resolved:
                # (2) «model not found» → повтор на дефолтной модели провайдера
                log.warning("LLM: HTTP %s на '%s' (модель '%s') — повтор на DEFAULT_MODEL '%s'",
                            status, provider.name, resolved, provider.model)
                payload["model"] = provider.model
                text, usage = await self._post_chat(provider, payload, timeout=request_timeout)
                # подмена модели не должна быть молчаливой: наверх уходят факт и причина
                usage["_llm_model"] = provider.model
                usage["_llm_provider"] = provider.name
                usage["_llm_note"] = (f"HTTP {status} на модели '{resolved}' — "
                                      f"ответила модель по умолчанию '{provider.model}'")
                _u = Usage.current()
                if _u is not None:
                    _u.add_llm(provider.name, str(usage.get("_llm_model") or resolved), usage)
                return text, usage
            raise

        # наверх: чем РЕАЛЬНО отвечали (после возможных обходов) и почему
        usage["_llm_model"] = resolved
        usage["_llm_provider"] = provider.name
        if rerouted:
            usage["_llm_note"] = (f"модели '{resolved}' нет у провайдера '{orig_name}' "
                                  f"— запрос ушёл в '{rerouted}'")
        _u = Usage.current()
        if _u is not None:
            _u.add_llm(provider.name, str(usage.get("_llm_model") or resolved), usage)
        return text, usage


class EmbeddingClient:
    """Клиент СЕРВЕРА ЭМБЕДДИНГОВ — у него свой эндпоинт, отличный от LLM."""

    def __init__(self, base_url: str, api_key: str, model: str,
                 dimensions: int, batch_size: int = 32, timeout: float = 120.0):
        # Тот же случай, что у LLM: локальный сервер (LM Studio) требует
        # Bearer-токен — если свой не задан, берём LM_API_TOKEN.
        api_key = (api_key or "").strip()
        self.api_key_src = ""

        if api_key:
            self.api_key_src = "EMBEDDING_API_KEY"
        elif base_url:
            api_key, self.api_key_src = _resolve_key_src("EMBEDDING", base_url)

        if base_url and not api_key and _looks_local(base_url):
            log.warning("Эмбеддинг-сервер (%s) локальный и БЕЗ токена: если он требует "
                        "Bearer (LM Studio), задайте EMBEDDING_API_KEY или LM_API_TOKEN "
                        "в workers/.env", base_url)
        elif api_key:
            log.info("Эмбеддинг-сервер (%s): токен из %s", base_url, self.api_key_src)

        self.base_url = base_url.rstrip("/")
        self.model = model
        self.dimensions = dimensions
        self.batch_size = max(1, batch_size)
        self._client = httpx.AsyncClient(
            timeout=timeout,
            headers={"Authorization": f"Bearer {api_key}"} if api_key else {},
        )

    async def close(self) -> None:
        await self._client.aclose()

    async def embed(self, texts: str | Sequence[str]) -> list[list[float]]:
        """Вектор(ы) для текста/списка текстов (батчами по batch_size)."""
        single = isinstance(texts, str)
        items = [texts] if single else list(texts)
        vectors: list[list[float]] = []

        for i in range(0, len(items), self.batch_size):
            batch = items[i:i + self.batch_size]
            r = await self._client.post(
                f"{self.base_url}/embeddings",
                json={"model": self.model, "input": batch},
            )

            if r.status_code in (401, 403):
                hint = "LM_API_TOKEN" if _looks_local(self.base_url) else "EMBEDDING_API_KEY"
                what = (f"Токен отправляли из {self.api_key_src} — он устарел или чужой; "
                        f"проверьте {self.api_key_src} (эталон: {hint})."
                        if self.api_key_src else
                        f"Токен не отправляли вовсе. Задайте {hint} в workers/.env.")

                raise LlmConfigError(
                    f"Сервер эмбеддингов ({self.base_url}) требует токен: HTTP {r.status_code}. "
                    f"{what} И перезапустите воркер. "
                    f"Ответ сервера: {(r.text or '').strip()[:300]}")

            # Любая другая ошибка — С ТЕЛОМ ОТВЕТА. `raise_for_status()` бросает
            # httpx.HTTPStatusError с одной строкой «Client error '400 Bad
            # Request'», и объяснение сервера («model not found», «batch too
            # large», «input too long») теряется. Ровно это случилось
            # 22.10.2026: стенд показал «HTTP 400» без единой подсказки, и
            # владелец пошёл искать причину в ключе, хотя сервер говорил другое.
            if r.status_code >= 400:
                raise EmbeddingServerError(
                    base_url=self.base_url,
                    model=self.model,
                    status=r.status_code,
                    body=(r.text or "").strip()[:600],
                    inputs=len(batch),
                    chars=sum(len(str(t)) for t in batch),
                )

            try:
                data = sorted(r.json()["data"], key=lambda d: d["index"])
            except (KeyError, TypeError, ValueError) as e:
                raise EmbeddingServerError(
                    base_url=self.base_url,
                    model=self.model,
                    status=r.status_code,
                    body=(r.text or "").strip()[:600],
                    inputs=len(batch),
                    chars=sum(len(str(t)) for t in batch),
                    note="ответ 200, но это не OpenAI-формат: нет data[].embedding",
                ) from e

            vectors.extend(d["embedding"] for d in data)

        return vectors[0] if single else vectors

    async def models(self) -> list[str]:
        """Список моделей сервера — для диагностики «model not found».

        На HTTP 400 сервер редко пишет, какие модели у него есть. Один GET на
        /models отвечает на вопрос точнее любых догадок: если запрошенной
        модели в списке нет, дело не в токене и не в пачке.
        """
        try:
            r = await self._client.get(f"{self.base_url}/models")
        except Exception as e:                        # noqa: BLE001 — диагностика
            log.info("Список моделей %s недоступен: %s", self.base_url, e)
            return []

        if r.status_code >= 400:
            return []

        try:
            payload = r.json()
        except ValueError:
            return []

        items = payload.get("data") if isinstance(payload, dict) else payload

        return [str(m.get("id")) for m in (items or []) if isinstance(m, dict) and m.get("id")]

    async def health(self) -> bool:
        """Жив ли эмбеддинг-сервер (пробный вектор)."""
        try:
            v = await self.embed("healthcheck")
            return len(v) > 0
        except Exception as e:  # noqa: BLE001 — диагностическая функция
            log.warning("Эмбеддинг-сервер недоступен: %s", e)
            return False
