"""Токен локального LLM (LM Studio) и честная ошибка вместо «сырого» 401.

Владелец видел поток ошибок LM Studio: «An LM Studio API token is required…
none was provided using the Authorization header». Здесь проверяем, что
  * токен берётся из <ИМЯ>_API_KEY / <ИМЯ>_API_TOKEN / LM_API_TOKEN (для локальных
    серверов) и НЕ подставляется удалённым провайдерам;
  * 401/403 превращается в LlmConfigError с инструкцией, а такой сбой не
    повторяется (иначе три попытки = три отказа подряд).
"""
import httpx
import pytest

from prl_workers import llm


def test_looks_local():
    assert llm._looks_local("http://127.0.0.1:1234/v1")
    assert llm._looks_local("http://192.168.1.5:1234/v1")
    assert llm._looks_local("http://toolforge:8080/v1")
    assert llm._looks_local("http://host.docker.internal:1234/v1")
    assert not llm._looks_local("https://openrouter.ai/api/v1")


def test_key_variants(monkeypatch):
    for var in ("TOOLFORGE_API_KEY", "TOOLFORGE_API_TOKEN", "TOOLFORGE_TOKEN",
                "LM_API_TOKEN", "LMSTUDIO_API_TOKEN", "OPENROUTER_API_KEY"):
        monkeypatch.delenv(var, raising=False)

    monkeypatch.setenv("LM_API_TOKEN", "lm-secret")
    # локальный сервер: подхватываем токен LM Studio
    assert llm._resolve_key("TOOLFORGE", "http://127.0.0.1:1234/v1") == "lm-secret"
    # удалённый провайдер: чужой токен не подставляем
    assert llm._resolve_key("OPENROUTER", "https://openrouter.ai/api/v1") == ""

    monkeypatch.setenv("OPENROUTER_API_KEY", "or-secret")
    assert llm._resolve_key("OPENROUTER", "https://openrouter.ai/api/v1") == "or-secret"

    monkeypatch.setenv("TOOLFORGE_API_TOKEN", "tf-token")
    assert llm._resolve_key("TOOLFORGE", "http://127.0.0.1:1234/v1") == "tf-token"


class _Resp:
    def __init__(self, status, text="", payload=None):
        self.status_code = status
        self.text = text
        self._payload = payload or {}

    def json(self):
        return self._payload

    def raise_for_status(self):
        if self.status_code >= 400:
            raise httpx.HTTPStatusError("error", request=None, response=None)


class _Client:
    """Заглушка httpx-клиента: /models недоступен, чат отвечает 401."""

    async def get(self, *a, **kw):
        raise RuntimeError("нет справочника моделей")

    async def post(self, *a, **kw):
        return _Resp(401, '{"error":{"code":"invalid_api_key","message":"An LM Studio API token is required"}}')


@pytest.mark.asyncio
async def test_401_is_config_error(monkeypatch):
    monkeypatch.setenv("TOOLFORGE_API_URL", "http://127.0.0.1:1234/v1")
    client = llm.LlmClient({"TOOLFORGE": llm.Provider(
        name="TOOLFORGE", base_url="http://127.0.0.1:1234/v1", model="qwen")})
    monkeypatch.setattr(client, "_client_for", lambda provider: _Client())

    with pytest.raises(llm.LlmConfigError) as e:
        await client.chat_with_usage([{"role": "user", "content": "привет"}])

    msg = str(e.value)
    assert "LM_API_TOKEN" in msg          # подсказка, что именно задать
    assert "401" in msg


# ---------------------------------------------------------------------------
# Источник токена в диагнозе: «неправильный ключ» ≠ «ключа нет»
# ---------------------------------------------------------------------------

def test_key_source_is_named(monkeypatch):
    """_resolve_key_src отдаёт ИМЯ переменной, откуда взят токен."""
    for var in ("TOOLFORGE_API_KEY", "TOOLFORGE_API_TOKEN", "LM_API_TOKEN"):
        monkeypatch.delenv(var, raising=False)

    monkeypatch.setenv("TOOLFORGE_API_TOKEN", "tf-token")
    assert llm._resolve_key_src("TOOLFORGE", "http://127.0.0.1:1234/v1") == \
        ("tf-token", "TOOLFORGE_API_TOKEN")

    monkeypatch.delenv("TOOLFORGE_API_TOKEN")
    assert llm._resolve_key_src("TOOLFORGE", "http://127.0.0.1:1234/v1") == ("", "")


def test_401_names_the_suspect_variable(monkeypatch):
    """Если токен ОТПРАВЛЯЛИ, текст ошибки называет переменную-источник.

    Владелец: «есть подозрение, что оттуда идёт запрос на LLM с неправильным
    ключом». Ответ на это подозрение должен быть в самом тексте ошибки, а не
    только в голове разработчика.
    """
    provider = llm.Provider(name="TOOLFORGE", base_url="http://127.0.0.1:1234/v1",
                            api_key="stale-key", api_key_src="TOOLFORGE_API_KEY")

    msg = str(llm.LlmClient._auth_error(provider, 401, "invalid_api_key"))

    assert "TOOLFORGE_API_KEY" in msg
    assert "устарел" in msg                    # это «ключ не тот», а не «ключа нет»
    assert "LM_API_TOKEN" in msg               # эталон для локального сервера

    no_key = llm.Provider(name="TOOLFORGE", base_url="http://127.0.0.1:1234/v1")
    msg2 = str(llm.LlmClient._auth_error(no_key, 401, "none was provided"))

    assert "не отправляли" in msg2


def test_embedding_401_names_source(monkeypatch):
    """Тот же разбор у сервера эмбеддингов (отдельный эндпоинт)."""
    for var in ("EMBEDDING_API_KEY", "LM_API_TOKEN"):
        monkeypatch.delenv(var, raising=False)

    monkeypatch.setenv("LM_API_TOKEN", "lm-secret")
    c = llm.EmbeddingClient("http://127.0.0.1:1235/v1", "", "emb", 8)
    assert c.api_key_src == "LM_API_TOKEN"

    # токена нет вовсе — клиент честно об этом скажет
    monkeypatch.delenv("LM_API_TOKEN")
    c2 = llm.EmbeddingClient("http://127.0.0.1:1235/v1", "", "emb", 8)
    assert c2.api_key_src == ""


def test_call_log_names_token_source(caplog, monkeypatch):
    """Каждый вызов LLM логируется с ИМЕНЕМ переменной-источника токена.

    Грепом `journalctl -u prl-worker | grep "LLM ->"` видно, кто и с каким
    токеном ходил во внешний сервер — без значений секретов.
    """
    import asyncio, logging

    monkeypatch.setenv("TOOLFORGE_API_URL", "http://127.0.0.1:1234/v1")
    monkeypatch.setenv("LM_API_TOKEN", "lm-secret")

    client = llm.LlmClient({"TOOLFORGE": llm.Provider(
        name="TOOLFORGE", base_url="http://127.0.0.1:1234/v1",
        api_key="lm-secret", api_key_src="LM_API_TOKEN", model="qwen")})

    async def fake_models(provider):
        return None

    async def fake_post(provider, payload, timeout=None):
        return "привет", {}

    monkeypatch.setattr(client, "_provider_models", fake_models)
    monkeypatch.setattr(client, "_post_chat", fake_post)

    with caplog.at_level(logging.INFO, logger="prl.llm"):
        asyncio.get_event_loop_policy().new_event_loop().run_until_complete(
            client.chat_with_usage([{"role": "user", "content": "привет"}]))

    logs = "\n".join(r.getMessage() for r in caplog.records)
    assert "LM_API_TOKEN" in logs       # откуда токен
    assert "lm-secret" not in logs      # сами значения не логируем


class _CountingClient:
    """Считает запросы к /models: справочник НЕ должен спрашиваться лишний раз."""

    def __init__(self):
        self.gets = []

    async def get(self, url, **kw):
        self.gets.append(url)

        class _R:
            status_code = 401
            text = "invalid_api_key"

            def json(self):
                return {}

        return _R()

    async def post(self, *a, **kw):
        class _R:
            status_code = 200

            def json(self):
                return {"choices": [{"message": {"content": "ок"}, "finish_reason": "stop"}],
                        "usage": {}}

            def raise_for_status(self):
                return None

        return _R()


@pytest.mark.asyncio
async def test_single_provider_does_not_probe_models(monkeypatch):
    """Один провайдер → GET /models вообще не нужен (некуда перенаправлять).

    Регрессия-контроль: этот запрос светился в логе LM Studio как «кто-то без
    конца опрашивает справочник моделей».
    """
    monkeypatch.setenv("TOOLFORGE_API_URL", "http://127.0.0.1:1234/api/v1")
    client = llm.LlmClient({"TOOLFORGE": llm.Provider(
        name="TOOLFORGE", base_url="http://127.0.0.1:1234/api/v1",
        api_key="lm-secret", api_key_src="LM_API_TOKEN", model="qwen")})

    counting = _CountingClient()
    monkeypatch.setattr(client, "_client_for", lambda provider: counting)

    await client.chat_with_usage([{"role": "user", "content": "привет"}])

    assert counting.gets == [], f"лишний опрос справочника: {counting.gets}"


@pytest.mark.asyncio
async def test_local_provider_without_token_is_not_probed(monkeypatch):
    """Локальный сервер без токена не спрашиваем: он всё равно ответит 401."""
    client = llm.LlmClient({"TOOLFORGE": llm.Provider(
        name="TOOLFORGE", base_url="http://127.0.0.1:1234/api/v1", model="qwen")})

    counting = _CountingClient()
    monkeypatch.setattr(client, "_client_for", lambda provider: counting)

    assert await client._provider_models(client._providers["TOOLFORGE"]) is None
    assert counting.gets == []


@pytest.mark.asyncio
async def test_probe_happens_once_with_two_providers(monkeypatch):
    """Два провайдера → справочник спрашивается, но ровно один раз (кэш)."""
    providers = {
        "TOOLFORGE": llm.Provider(name="TOOLFORGE", base_url="http://127.0.0.1:1234/v1",
                                  api_key="t", api_key_src="LM_API_TOKEN", model="qwen"),
        "OPENROUTER": llm.Provider(name="OPENROUTER", base_url="https://openrouter.ai/api/v1",
                                   api_key="o", api_key_src="OPENROUTER_API_KEY",
                                   model="google/gemini-2.5-flash-lite"),
    }
    client = llm.LlmClient(providers)

    counting = _CountingClient()
    monkeypatch.setattr(client, "_client_for", lambda provider: counting)

    await client.chat_with_usage([{"role": "user", "content": "привет"}])
    await client.chat_with_usage([{"role": "user", "content": "ещё раз"}])

    assert counting.gets == ["http://127.0.0.1:1234/v1/models"]
