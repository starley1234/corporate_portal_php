"""nj653.embed: мультимодальные эмбеддинги — формат запроса и разбор ответа.

Главное, что здесь проверяется, — автоопределение диалекта. Единого формата
мультимодального `/v1/embeddings` у локальных серверов нет (llama.cpp, vLLM,
LM Studio и mlx-serve ждут json разной формы), поэтому клиент перебирает
известные варианты и запоминает первый рабочий. Если перебор сломается,
модуль /veclab молча превратится в текстовый поиск — картинка просто не
доедет до модели. Такое ловится только тестом.

Сервер здесь поддельный (httpx.MockTransport): ни модели, ни сети не нужно.
"""

from __future__ import annotations

import json

import httpx
import pytest

from n2j_workers import mmembed
from n2j_workers.mmembed import (
    DEFAULT_INSTRUCTION,
    DIALECTS,
    EmbedInput,
    MmEmbedError,
    MmEmbeddingClient,
    build_body,
    cosine,
    fit_dims,
    l2_normalize,
    parse_vectors,
)

PIX = mmembed.PIXEL_PNG_B64


# --------------------------------------------------------------------------
# Вход
# --------------------------------------------------------------------------

def test_input_kinds():
    assert EmbedInput(text="кот").kind == "text"
    assert EmbedInput(image_b64=PIX).kind == "image"
    assert EmbedInput(text="кот", image_b64=PIX).kind == "both"
    assert EmbedInput(image_b64=PIX, media_type="image/png").data_url().startswith(
        "data:image/png;base64,")


def test_empty_input_is_rejected():
    """Пустой вход — ошибка данных: иначе в коллекцию уедет вектор ни о чём."""
    with pytest.raises(ValueError):
        EmbedInput(text="   ")


# --------------------------------------------------------------------------
# Тело запроса
# --------------------------------------------------------------------------

def test_qwen_objects_body():
    body = build_body("qwen_objects", [EmbedInput(text="кот", image_b64=PIX,
                                                  media_type="image/png",
                                                  instruction="найди похожее")],
                      "qwen3-vl-embedding-2b")

    assert body["model"] == "qwen3-vl-embedding-2b"
    assert body["input"][0]["text"] == "кот"
    assert body["input"][0]["image"].startswith("data:image/png;base64,")
    assert body["input"][0]["instruction"] == "найди похожее"


def test_oai_content_wraps_parts_image_first():
    """llama.cpp принимает ТОЛЬКО обёрнутые части, картинка идёт первой."""
    body = build_body("oai_content", [EmbedInput(text="кот", image_b64=PIX)], "m")
    parts = body["input"][0]["content"]

    assert list(body["input"][0].keys()) == ["content"]
    assert parts[0]["type"] == "image_url"
    assert parts[1] == {"type": "text", "text": "кот"}


def test_single_input_dialects_refuse_batches():
    pair = [EmbedInput(text="раз"), EmbedInput(text="два")]

    for dialect in ("oai_parts", "chat_messages", "media_prompt"):
        with pytest.raises(ValueError):
            build_body(dialect, pair, "m")


def test_plain_text_refuses_images():
    """Молчаливая потеря картинки хуже отказа: текстовый диалект обязан падать."""
    with pytest.raises(ValueError):
        build_body("plain_text", [EmbedInput(text="кот", image_b64=PIX)], "m")

    body = build_body("plain_text", [EmbedInput(text="кот", instruction="найди")], "m")

    assert body["input"] == ["найди\nкот"]


def test_media_prompt_marks_place_of_image():
    body = build_body("media_prompt", [EmbedInput(text="кот", image_b64=PIX)], "m")

    assert body["input"]["prompt_string"].endswith("<__media__>")
    assert body["input"]["multimodal_data"] == [PIX]


def test_unknown_dialect_is_an_error():
    with pytest.raises(ValueError):
        build_body("ollama_magic", [EmbedInput(text="кот")], "m")


# --------------------------------------------------------------------------
# Разбор ответа
# --------------------------------------------------------------------------

def test_parse_vectors_sorts_by_index():
    data = {"data": [{"index": 1, "embedding": [0.0, 1.0]},
                     {"index": 0, "embedding": [1.0, 0.0]}]}

    assert parse_vectors(data) == [[1.0, 0.0], [0.0, 1.0]]


def test_parse_vectors_accepts_bare_forms():
    assert parse_vectors([0.5, 0.5]) == [[0.5, 0.5]]
    assert parse_vectors([[1.0], [2.0]]) == [[1.0], [2.0]]
    assert parse_vectors({"embedding": [1.0, 2.0]}) == [[1.0, 2.0]]


def test_parse_vectors_rejects_garbage():
    with pytest.raises(MmEmbedError):
        parse_vectors({"error": "no model loaded"})

    with pytest.raises(MmEmbedError):
        parse_vectors({"data": [{"index": 0}]})


# --------------------------------------------------------------------------
# Векторная арифметика
# --------------------------------------------------------------------------

def test_normalize_and_cosine():
    assert cosine([1, 2, 3], [2, 4, 6]) == pytest.approx(1.0)
    assert cosine([1, 0], [0, 1]) == pytest.approx(0.0)
    assert sum(x * x for x in l2_normalize([3.0, 4.0])) == pytest.approx(1.0)
    assert l2_normalize([0.0, 0.0]) == [0.0, 0.0]


def test_cosine_refuses_different_lengths():
    """Разная длина — это рассинхрон хранилища и модели, а не «0.0 похожести»."""
    with pytest.raises(ValueError):
        cosine([1.0, 2.0], [1.0])


def test_fit_dims_truncates_and_renormalizes():
    """Matryoshka: первые N координат — самостоятельный вектор меньшей длины."""
    out = fit_dims([3.0, 4.0, 10.0, 10.0], 2)

    assert len(out) == 2
    assert sum(x * x for x in out) == pytest.approx(1.0)


def test_fit_dims_refuses_short_vector():
    with pytest.raises(MmEmbedError):
        fit_dims([1.0, 2.0], 2048)


# --------------------------------------------------------------------------
# Автоопределение диалекта (поддельный сервер)
# --------------------------------------------------------------------------

def _client(handler, **kw) -> MmEmbeddingClient:
    client = MmEmbeddingClient(base_url="http://fake/v1", api_key="k",
                               model="qwen3-vl-embedding-2b", **kw)
    client._client = httpx.AsyncClient(transport=httpx.MockTransport(handler))

    return client


def _models_reply(*names: str) -> httpx.Response:
    return httpx.Response(200, json={
        "object": "list",
        "data": [{"id": name, "object": "model"} for name in names],
    })


def _vector_reply(count: int, dims: int = 4) -> httpx.Response:
    return httpx.Response(200, json={
        "data": [{"index": i, "embedding": [1.0] + [0.0] * (dims - 1)} for i in range(count)],
    })


@pytest.mark.asyncio
async def test_detect_skips_rejected_dialects():
    """Сервер понимает только oai_content — клиент обязан дойти до него сам."""
    seen: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.method == "GET":                 # сверка имени модели
            return _models_reply("qwen3-vl-embedding-2b")

        body = request.read().decode()
        seen.append(body)

        # Сервер старый: берёт только input=[{content:[…]}] (llama.cpp),
        # ни пачку чат-сообщений, ни Cohere-вход он не понимает.
        if '"input"' not in body or '"content"' not in body:
            return httpx.Response(400, text="unsupported input format")

        return _vector_reply(1)

    client = _client(handler)

    try:
        attempts = await client.detect()
    finally:
        await client.close()

    assert client.dialect == "oai_content"
    assert [a.dialect for a in attempts] == ["omni_messages", "cohere_inputs",
                                             "qwen_objects", "oai_content"]
    assert attempts[0].ok is False and "400" in attempts[0].error
    assert attempts[-1].ok is True and attempts[-1].dims == 4
    assert len(seen) == 4, "лишних запросов к модели быть не должно"


@pytest.mark.asyncio
async def test_detect_reports_every_attempt_when_nothing_works():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(500, text="no multimodal model loaded")

    client = _client(handler)

    try:
        with pytest.raises(MmEmbedError) as e:
            await client.detect()
    finally:
        await client.close()

    # В причине обязаны быть все пробы: иначе владельцу нечего чинить.
    for dialect in DIALECTS:
        assert dialect in str(e.value)


@pytest.mark.asyncio
async def test_text_only_does_not_probe_dialects():
    """Чистый текст берёт любой сервер — пробы тут только тратят время."""
    calls: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request.read().decode())

        return _vector_reply(2)

    client = _client(handler, batch_size=2)      # пачка только по явной просьбе

    try:
        vectors = await client.embed([EmbedInput(text="раз"), EmbedInput(text="два")])
    finally:
        await client.close()

    assert client.dialect == "plain_text"
    assert len(calls) == 1, "пакетный диалект обязан уложиться в один запрос"
    assert len(vectors) == 2


@pytest.mark.asyncio
async def test_image_after_text_switches_dialect():
    """Сначала текст, потом картинка: диалект переопределяется, кадр не теряется."""
    def handler(request: httpx.Request) -> httpx.Response:
        body = request.read().decode()

        if "image_url" in body or '"image"' in body:
            return _vector_reply(1)

        if "data:image" in body:
            return _vector_reply(1)

        return _vector_reply(1)

    client = _client(handler)

    try:
        await client.embed_one(EmbedInput(text="кот"))
        assert client.dialect == "plain_text"

        await client.embed_one(EmbedInput(text="кот", image_b64=PIX))
    finally:
        await client.close()

    assert client.dialect != "plain_text", "картинка не может уехать текстовым диалектом"


@pytest.mark.asyncio
async def test_forced_text_dialect_fails_loudly_on_image():
    client = _client(lambda r: _vector_reply(1), dialect="plain_text")

    try:
        with pytest.raises(MmEmbedError) as e:
            await client.embed_one(EmbedInput(text="кот", image_b64=PIX))
    finally:
        await client.close()

    assert "EMBEDDING_MM_DIALECT" in str(e.value)


@pytest.mark.asyncio
async def test_missing_token_is_explained():
    """401 от LM Studio — самая частая беда: ответ обязан называть переменную."""
    client = _client(lambda r: httpx.Response(401, text="unauthorized"))

    try:
        with pytest.raises(MmEmbedError) as e:
            await client.embed_one(EmbedInput(text="кот"))
    finally:
        await client.close()

    assert "токен" in str(e.value)


@pytest.mark.asyncio
async def test_vectors_are_normalized_and_fitted():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(200, json={"data": [{"index": 0, "embedding": [3.0, 4.0, 99.0]}]})

    client = _client(handler, dimensions=2)

    try:
        vector = await client.embed_one(EmbedInput(text="кот"))
    finally:
        await client.close()

    assert len(vector) == 2
    assert sum(x * x for x in vector) == pytest.approx(1.0)


@pytest.mark.asyncio
async def test_wrong_vector_count_is_an_error():
    """Сервер вернул один вектор на два входа — сопоставлять их нельзя."""
    client = _client(lambda r: _vector_reply(1), batch_size=2)

    try:
        with pytest.raises(MmEmbedError):
            await client.embed([EmbedInput(text="раз"), EmbedInput(text="два")])
    finally:
        await client.close()


# --------------------------------------------------------------------------
# Паспорт подключения
# --------------------------------------------------------------------------

def test_describe_lists_attempts():
    client = MmEmbeddingClient(base_url="http://fake/v1/", api_key="k", model="m",
                               api_key_src="EMBEDDING_API_KEY")
    client.attempts = [mmembed.Attempt("qwen_objects", False, error="HTTP 400"),
                       mmembed.Attempt("oai_content", True, ms=34, dims=2048)]
    client.dialect = "oai_content"

    passport = client.describe()

    assert passport["base_url"] == "http://fake/v1"
    assert passport["token_src"] == "EMBEDDING_API_KEY"
    assert passport["forced"] is False
    assert passport["attempts"][1]["title"].startswith("OpenAI-обёртка")


def test_unknown_forced_dialect_is_rejected():
    with pytest.raises(ValueError):
        MmEmbeddingClient(base_url="http://fake/v1", api_key="", model="m",
                          dialect="ollama_magic")


# --------------------------------------------------------------------------
# Диагноз, когда виноват сервер, а не формат запроса
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_no_models_loaded_is_named_instead_of_blaming_format():
    """LM Studio без загруженной модели отвечает одинаково на любое тело.

    Владелец 05.10.2026 получил шесть строк «HTTP 400: No models loaded» и
    совет «проверьте, что модель мультимодальная» — совет не по делу. Теперь
    перебор обязан остановиться на первой пробе и назвать настоящую причину.
    """
    posts: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/models") and request.method == "GET":
            return _models_reply("qwen3-vl-embedding-2b", "nomic-embed-text")

        if request.method != "POST":
            return httpx.Response(404, text="not found")

        posts.append(request.read().decode())

        return httpx.Response(400, json={
            "error": "No models loaded. Please load a model in the developer "
                     "page or use the 'lms load' command."})

    client = _client(handler, fallback="off")

    try:
        with pytest.raises(MmEmbedError) as e:
            await client.detect()
    finally:
        await client.close()

    text = str(e.value)

    assert "дело не в формате запроса" in text
    assert "НЕ ЗАГРУЖЕНА" in text
    assert "lms load qwen3-vl-embedding-2b" in text
    assert len(posts) == 1, "после отказа сервера остальные пробы бессмысленны"

    # Журнал проб всё равно полон: страница /veclab показывает все строки.
    assert [a.dialect for a in client.attempts] == list(DIALECTS)
    assert client.attempts[-1].error == "не поддерживает картинки"


@pytest.mark.asyncio
async def test_detect_error_tells_that_model_name_is_unknown():
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/models") and request.method == "GET":
            return _models_reply("nomic-embed-text")

        return httpx.Response(400, text="unsupported input format")

    client = _client(handler, fallback="off")

    try:
        with pytest.raises(MmEmbedError) as e:
            await client.detect()
    finally:
        await client.close()

    text = str(e.value)

    assert "nomic-embed-text" in text
    assert "qwen3-vl-embedding-2b" in text and "среди них нет" in text


@pytest.mark.asyncio
async def test_detect_error_hints_token_when_models_need_one():
    def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(400, json={"error": {
            "message": "An LM Studio API token is required",
            "code": "invalid_api_key"}})

    client = _client(handler)
    client.api_key = ""
    client.api_key_src = ""

    try:
        with pytest.raises(MmEmbedError) as e:
            await client.detect()
    finally:
        await client.close()

    text = str(e.value)

    assert "токен" in text
    assert "EMBEDDING_API_KEY" in text


@pytest.mark.asyncio
async def test_list_models_reads_ids():
    def handler(request: httpx.Request) -> httpx.Response:
        return _models_reply("a", "b")

    client = _client(handler)

    try:
        names, why = await client.list_models()
    finally:
        await client.close()

    assert names == ["a", "b"] and why == ""


# --------------------------------------------------------------------------
# Подключение «как у чата» (/meal) и самозагрузка модели (JIT)
# --------------------------------------------------------------------------

def test_from_env_takes_token_and_proxy_from_chat_provider(monkeypatch):
    """Сервер один, а переменные разные: токен чата годится и эмбеддингам.

    У владельца /meal работал, а /veclab — нет: чат шёл на llm.toolforge.ru с
    TOOLFORGE_API_KEY, эмбеддинги — на тот же адрес без заголовка вовсе.
    """
    for var in ("EMBEDDING_MM_API_URL", "EMBEDDING_MM_API_KEY", "EMBEDDING_MM_MODEL",
                "EMBEDDING_API_KEY", "LM_API_TOKEN", "EMBEDDING_MM_DIALECT"):
        monkeypatch.delenv(var, raising=False)

    monkeypatch.setenv("TOOLFORGE_API_URL", "https://llm.example.ru/v1/")
    monkeypatch.setenv("TOOLFORGE_API_KEY", "tok-chat")
    monkeypatch.setenv("TOOLFORGE_PROXY", "http://127.0.0.1:3128")
    monkeypatch.setenv("EMBEDDING_API_URL", "https://llm.example.ru/v1/")
    monkeypatch.setenv("EMBEDDING_MODEL", "qwen3-vl-embedding-2b")

    client = MmEmbeddingClient.from_env()

    assert client is not None
    assert client.base_url == "https://llm.example.ru/v1"
    assert client.api_key == "tok-chat"
    assert client.api_key_src == "TOOLFORGE_API_KEY"
    assert client.proxy == "http://127.0.0.1:3128"
    assert client.provider == "TOOLFORGE"


def test_from_env_understands_provider_spec(monkeypatch):
    """EMBEDDING_MODEL=«TOOLFORGE::модель» — та же спека, что у моделей модулей."""
    for var in ("EMBEDDING_MM_API_URL", "EMBEDDING_API_URL", "EMBEDDING_MM_MODEL",
                "EMBEDDING_MM_API_KEY", "EMBEDDING_API_KEY", "LM_API_TOKEN"):
        monkeypatch.delenv(var, raising=False)

    monkeypatch.setenv("TOOLFORGE_API_URL", "https://llm.example.ru/v1")
    monkeypatch.setenv("TOOLFORGE_API_KEY", "tok-chat")
    monkeypatch.setenv("EMBEDDING_MODEL", "toolforge::qwen3-vl-embedding-2b")

    client = MmEmbeddingClient.from_env()

    assert client is not None
    assert client.model == "qwen3-vl-embedding-2b"
    assert client.base_url == "https://llm.example.ru/v1"
    assert client.api_key == "tok-chat"


@pytest.mark.parametrize("wanted,names,best", [
    ("qwen3-vl-embedding-2b", ["Qwen3-VL-Embedding-2B"], "Qwen3-VL-Embedding-2B"),
    ("qwen3-vl-embedding-2b", ["qwen/qwen3-vl-embedding-2b"], "qwen/qwen3-vl-embedding-2b"),
    ("qwen3-vl-embedding-2b", ["qwen3-vl-embedding-2b-gguf", "gemma-3-12b"],
     "qwen3-vl-embedding-2b-gguf"),
    ("", ["text-embedding-qwen3", "gemma-3-12b"], "text-embedding-qwen3"),
    ("qwen3-vl-embedding-2b", ["gemma-3-12b", "llama-3-8b"], ""),
])
def test_best_name_picks_what_the_owner_meant(wanted, names, best):
    assert MmEmbeddingClient._best_name(wanted, names) == best


@pytest.mark.asyncio
async def test_detect_substitutes_exact_model_name_so_lmstudio_loads_it():
    """Имя из .env не совпало с id на сервере — шлём точное, модель поднимется сама."""
    models_used: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.method == "GET":
            return _models_reply("qwen/qwen3-vl-embedding-2b")

        models_used.append(json.loads(request.read())["model"])

        return _vector_reply(1)

    client = _client(handler)

    try:
        await client.detect()
    finally:
        await client.close()

    assert models_used == ["qwen/qwen3-vl-embedding-2b"]
    assert "подставлено имя" in client.model_note


@pytest.mark.asyncio
async def test_embed_retries_once_with_a_name_the_server_knows():
    """«No models loaded» на рабочем диалекте — чиним имя и повторяем (как чат)."""
    seen: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.method == "GET":
            return _models_reply("qwen3-vl-embedding-2b-mlx")

        name = json.loads(request.read())["model"]
        seen.append(name)

        if name != "qwen3-vl-embedding-2b-mlx":
            return httpx.Response(400, json={"error": "No models loaded. Please load a model"})

        return _vector_reply(1)

    client = _client(handler)
    client.dialect = "qwen_objects"          # диалект уже известен
    client._model_checked = True             # и имя когда-то считалось верным

    try:
        vectors = await client.embed([EmbedInput(text="кот")])
    finally:
        await client.close()

    assert len(vectors) == 1
    assert seen == ["qwen3-vl-embedding-2b", "qwen3-vl-embedding-2b-mlx"]


# --------------------------------------------------------------------------
# LM Studio: тип модели и режим описания
# --------------------------------------------------------------------------

def _lms_reply(rows: list[dict]) -> httpx.Response:
    return httpx.Response(200, json={"object": "list", "data": rows})


LMS_ROWS = [
    {"id": "qwen3-vl-embedding-2b", "type": "llm", "arch": "qwen3vl", "state": "not-loaded"},
    {"id": "text-embedding-qwen3-embedding-0.6b", "type": "embeddings",
     "arch": "qwen3", "state": "not-loaded"},
]


@pytest.mark.asyncio
async def test_model_of_wrong_type_is_explained_not_blamed_on_jit():
    """Главная засада владельца: модель есть, имя точное, JIT включён — а эмбеддингов нет.

    LM Studio грузит по типу эндпоинта: /v1/chat/completions берёт llm и vlm,
    /v1/embeddings — только embeddings. GGUF Qwen3-VL-Embedding числится llm,
    поэтому «No models loaded» приходит даже на верное имя.
    """
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/api/v0/models"):
            return _lms_reply(LMS_ROWS)

        if request.method == "GET":
            return _models_reply(*[r["id"] for r in LMS_ROWS])

        return httpx.Response(400, json={"error": "No models loaded. Please load a model"})

    client = _client(handler, fallback="off")

    try:
        with pytest.raises(MmEmbedError) as e:
            await client.detect()
    finally:
        await client.close()

    text = str(e.value)

    assert "типа llm" in text and "/v1/embeddings" in text
    assert "text-embedding-qwen3-embedding-0.6b" in text
    assert "vLLM" in text                      # куда идти за настоящей мультимодальностью


@pytest.mark.asyncio
async def test_caption_mode_turns_images_into_text_vectors(monkeypatch):
    """Сервер не умеет картинки — модуль всё равно работает: описание + текст."""
    bodies: list[dict] = []

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/api/v0/models"):
            return _lms_reply(LMS_ROWS)

        if request.method == "GET":
            return _models_reply(*[r["id"] for r in LMS_ROWS])

        body = json.loads(request.read())
        bodies.append(body)

        # Картинку сервер не принимает ни в каком виде — только строки.
        if not all(isinstance(i, str) for i in body.get("input") or []):
            return httpx.Response(400, json={"error": "No models loaded. Please load a model"})

        return _vector_reply(len(body["input"]))

    client = _client(handler)

    async def fake_caption(item):
        return "красный круг на белом фоне"

    monkeypatch.setattr(client, "caption", fake_caption)

    try:
        vectors = await client.embed([EmbedInput(text="метка", image_b64=PIX)])
    finally:
        await client.close()

    assert len(vectors) == 1
    assert client.mode == "caption"
    assert client.model == "text-embedding-qwen3-embedding-0.6b"
    assert bodies[-1]["input"] == ["метка. красный круг на белом фоне"]

    passport = client.describe()

    assert passport["mode"] == "caption"
    assert "vision-модель" in passport["mode_note"]


@pytest.mark.asyncio
async def test_caption_mode_is_refused_when_owner_turned_it_off():
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/api/v0/models"):
            return _lms_reply(LMS_ROWS)

        if request.method == "GET":
            return _models_reply(*[r["id"] for r in LMS_ROWS])

        return httpx.Response(400, text="unsupported input format")

    client = _client(handler, fallback="off")

    try:
        with pytest.raises(MmEmbedError):
            await client.detect()
    finally:
        await client.close()

    assert client.mode == "native"


# --------------------------------------------------------------------------
# Какой сервер на том конце и его маркер картинки (llama.cpp)
# --------------------------------------------------------------------------

def test_media_marker_goes_into_the_prompt():
    """llama.cpp подставляет картинку туда, где стоит ЕГО маркер.

    Маркер случайный при каждом старте, если не задан LLAMA_MEDIA_MARKER:
    с чужим маркером сервер отвечает 500 «Failed to tokenize prompt».
    """
    body = build_body("media_prompt", [EmbedInput(text="кот", image_b64=PIX)], "m",
                      media_marker="<<IMG_7f3>>")

    assert body["input"]["prompt_string"] == "кот<<IMG_7f3>>"
    assert body["input"]["multimodal_data"] == [PIX]


@pytest.mark.asyncio
async def test_server_probe_learns_llamacpp_marker():
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/api/v0/models"):
            return httpx.Response(404, text="not found")

        if request.url.path.endswith("/props"):
            return httpx.Response(200, json={
                "default_generation_settings": {"media_marker": "<<IMG_42>>"}})

        return httpx.Response(404, text="not found")

    client = _client(handler)

    try:
        kind = await client.server_probe()
    finally:
        await client.close()

    assert kind == "llamacpp"
    assert client.media_marker == "<<IMG_42>>"


@pytest.mark.asyncio
async def test_server_probe_recognizes_lmstudio():
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path.endswith("/api/v0/models"):
            return httpx.Response(200, json={"data": [
                {"id": "m", "type": "llm", "state": "not-loaded"}]})

        return httpx.Response(404, text="not found")

    client = _client(handler)

    try:
        assert await client.server_probe() == "lmstudio"
    finally:
        await client.close()


# --------------------------------------------------------------------------
# Свой сервис эмбеддингов (OmniEmbed на Qwen3-VL-Embedding)
# --------------------------------------------------------------------------

def test_omni_messages_body_keeps_instruction_apart():
    """Инструкция уходит отдельным system-сообщением, картинка — перед текстом.

    Qwen3-VL-Embedding учитывает инструкцию (instruction-aware), и подмешивать
    её в текст запроса нельзя: тогда она попадёт в сам вектор как содержимое.
    """
    body = build_body("omni_messages",
                      [EmbedInput(text="кот", image_b64=PIX, instruction="найди фото")],
                      "qwen3-vl-embedding-2b", dimensions=512)

    chat = body["messages"][0]

    assert body["encoding_format"] == "float"
    assert body["dimensions"] == 512, "матрёшку режет сам сервис"
    assert body["continue_final_message"] is True, "иначе вектор снимут не с того токена"
    assert body["add_special_tokens"] is True
    assert chat[0] == {"role": "system",
                       "content": [{"type": "text", "text": "найди фото"}]}
    assert chat[1]["role"] == "user"
    assert [p["type"] for p in chat[1]["content"]] == ["image_url", "text"]
    assert chat[1]["content"][0]["image_url"]["url"].startswith("data:image/")
    assert chat[2] == {"role": "assistant", "content": [{"type": "text", "text": ""}]}


def test_omni_messages_is_batched():
    """Пачка входов — пачка списков сообщений: один запрос, много векторов."""
    body = build_body("omni_messages",
                      [EmbedInput(text="раз"), EmbedInput(text="два")], "m")

    assert len(body["messages"]) == 2
    assert body["messages"][0][1]["content"][0]["text"] == "раз"
    assert body["messages"][1][1]["content"][0]["text"] == "два"
    assert "dimensions" not in body, "без EMBEDDING_MM_DIMENSIONS не навязываем ширину"


def test_cohere_inputs_body_and_its_own_endpoint():
    """Cohere Embed v2: своё тело и свой путь /v2/embed вместо /v1/embeddings."""
    body = build_body("cohere_inputs", [EmbedInput(text="кот", image_b64=PIX)], "m",
                      dimensions=256)

    assert body["embedding_types"] == ["float"]
    assert body["output_dimension"] == 256
    assert [p["type"] for p in body["inputs"][0]["content"]] == ["image_url", "text"]

    client = _client(lambda r: httpx.Response(404))

    assert client.endpoint("cohere_inputs") == "http://fake/v2/embed"
    assert client.endpoint("omni_messages") == "http://fake/v1/embeddings"


def test_parse_vectors_understands_cohere_answer():
    """Ответ Cohere — embeddings.float, а не data[].embedding."""
    assert parse_vectors({"embeddings": {"float": [[1.0, 2.0]]}}) == [[1.0, 2.0]]
    assert parse_vectors({"embeddings": [[3.0]]}) == [[3.0]]


@pytest.mark.asyncio
async def test_server_probe_recognizes_own_service():
    """GET /healthz опознаёт свой сервис и рассказывает, что в памяти."""
    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/healthz":
            return httpx.Response(200, json={
                "status": "ok", "service": "OmniEmbed Service", "version": "1.0.0",
                "backend": "llama.cpp", "model": "qwen3-vl-embedding-2b",
                "device": "cuda:0", "loaded": True, "on_device": True})

        return httpx.Response(404, text="not found")

    client = _client(handler)

    try:
        kind = await client.server_probe()
        health = await client.health()
    finally:
        await client.close()

    assert kind == "omniembed", "своё — узнаём раньше LM Studio и llama.cpp"
    assert health["model"] == "qwen3-vl-embedding-2b"
    assert client.describe()["health"]["device"] == "cuda:0"


@pytest.mark.asyncio
async def test_health_is_empty_on_a_foreign_server():
    """Чужой сервер на /healthz даёт что угодно — это не паспорт сервиса."""
    client = _client(lambda r: httpx.Response(200, json={"ok": True}))

    try:
        assert await client.health() == {}
    finally:
        await client.close()


def test_omni_messages_adds_default_instruction():
    """Без своей инструкции шлём штатную: модель instruction-aware."""
    chat = build_body("omni_messages", [EmbedInput(text="кот")], "m")["messages"][0]

    assert chat[0]["content"][0]["text"] == DEFAULT_INSTRUCTION


def test_dimensions_outside_matryoshka_are_not_sent():
    """Сервис принимает 64…2048; на остальное он отвечает 422.

    Лучше посчитать вектор полной ширины и подрезать его у себя (fit_dims),
    чем получить отказ из-за опечатки в EMBEDDING_MM_DIMENSIONS.
    """
    for bad in (8, 4096):
        assert "dimensions" not in build_body("omni_messages", [EmbedInput(text="к")],
                                              "m", dimensions=bad)
        assert "output_dimension" not in build_body("cohere_inputs", [EmbedInput(text="к")],
                                                    "m", dimensions=bad)

    assert build_body("omni_messages", [EmbedInput(text="к")], "m",
                      dimensions=2048)["dimensions"] == 2048


# --------------------------------------------------------------------------
# Пачка меняет вектор: картинки шлём по одной
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_images_are_never_sent_together():
    """Каждая картинка — отдельный запрос, даже когда пачка разрешена.

    У сервера пачка conversations считается одним батчем, и вектор входа
    зависит от соседей: одна и та же картинка, посланная вдвоём, давала
    косинус 0,77 сама с собой.
    """
    bodies: list[dict] = []

    def handler(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.read().decode())
        bodies.append(body)

        return _vector_reply(len(body.get("messages") or body.get("input") or [1]))

    client = _client(handler, batch_size=8, dialect="omni_messages")

    try:
        vectors = await client.embed([
            EmbedInput(text="раз", image_b64=PIX),
            EmbedInput(text="два", image_b64=PIX),
            EmbedInput(text="три"),
        ])
    finally:
        await client.close()

    assert len(vectors) == 3
    assert len(bodies) == 3, "две картинки и текст — три отдельных запроса"
    assert all(len(b["messages"]) == 1 for b in bodies[:2])


@pytest.mark.asyncio
async def test_texts_go_one_by_one_until_asked_otherwise():
    """По умолчанию (EMBEDDING_MM_BATCH=1) даже тексты идут поодиночке."""
    calls: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request.read().decode())

        return _vector_reply(1)

    client = _client(handler, dialect="plain_text")

    try:
        await client.embed([EmbedInput(text="раз"), EmbedInput(text="два")])
    finally:
        await client.close()

    assert len(calls) == 2


def test_order_of_results_survives_the_split():
    """Разбиение на запросы не имеет права перемешать входы."""
    client = MmEmbeddingClient(base_url="http://fake/v1", api_key="k", model="m",
                               batch_size=4, dialect="omni_messages")
    items = [EmbedInput(text="1"), EmbedInput(text="2", image_b64=PIX),
             EmbedInput(text="3"), EmbedInput(text="4")]
    chunks = client._chunks(items)

    assert [[i.text for i in c] for c in chunks] == [["1"], ["2"], ["3", "4"]]
