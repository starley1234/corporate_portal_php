"""Тесты nj641_imagegen: проверка видеопамяти и разбор ответа внешнего API.

Запуск из каталога workers/:
    python3 -m pytest tests/test_nj641_imagegen.py -q

Проверяем точечно (без сети/LLM):
  * _parse_vram: достаточно/недостаточно памяти, нечитаемый ответ,
    единицы (MB/GB/байты) и вложенность нового /vram;
  * _final_image: ответ OpenAI Images (data[0].b64_json/url) и старый формат;
  * _do_generate: POST /v1/images/generations и /v1/images/edits
    (сеть подменена — патчим _ensure_vram и _call_api).
"""

import pytest

import n2j_workers.handlers.nj641_imagegen as mod


# --------------------------------------------------------------------------
# _parse_vram
# --------------------------------------------------------------------------

def test_parse_vram_enough():
    ok, free, _status = mod._parse_vram({"free_mb": "8192"})
    assert ok is True and free == 8192


def test_parse_vram_not_enough():
    ok, free, _status = mod._parse_vram({"free_mb": 512})
    assert ok is False and free == 512


def test_parse_vram_nested_memory():
    ok, free, _status = mod._parse_vram({"memory": {"free_mb": 4096}})
    assert ok is True and free == 4096


def test_parse_vram_garbage():
    ok, free, _status = mod._parse_vram("boom")
    assert ok is False and free == 0


def test_parse_vram_no_number_but_ok_flag():
    ok, _free, _status = mod._parse_vram({"ok": True})
    assert ok is True


def test_parse_vram_gb_field():
    # Новый /vram может отдавать гигабайты, а не мегабайты.
    ok, free, _status = mod._parse_vram({"free_gb": 12})
    assert ok is True and free == 12 * 1024


def test_parse_vram_string_units():
    ok, free, _status = mod._parse_vram({"vram": {"available": "20 GB"}})
    assert ok is True and free == 20 * 1024


def test_parse_vram_bytes_without_suffix():
    ok, free, _status = mod._parse_vram({"free": 8 * 1024 ** 3})
    assert ok is True and free == 8192


def test_parse_vram_gpu_list():
    ok, free, _status = mod._parse_vram(
        {"gpus": [{"name": "0", "memory_free_mb": 6000}]})
    assert ok is True and free == 6000


def test_parse_vram_model_loaded_in_status():
    _ok, _free, status = mod._parse_vram({"free_mb": 8192, "model_loaded": False})
    assert "модель не загружена" in status


def test_parse_vram_bare_values_in_gb():
    # Без суффиксов, но рядом «всего 24» — это гигабайты.
    ok, free, _status = mod._parse_vram({"total": 24, "free": 20})
    assert ok is True and free == 20 * 1024


def test_parse_vram_mb_not_mistaken_for_gb():
    ok, free, _status = mod._parse_vram({"total_mb": 24576, "free_mb": 512})
    assert ok is False and free == 512


# --------------------------------------------------------------------------
# _final_image
# --------------------------------------------------------------------------

def test_final_image_binary():
    out = mod._final_image({"image_bytes": b"\x89PNG", "media_type": "image/png"}, None)
    import base64
    assert out["image_b64"] == base64.b64encode(b"\x89PNG").decode()
    assert out["image_info"]["media_type"] == "image/png"


def test_final_image_json_b64():
    out = mod._final_image(
        {"json": {"image": "QUJD", "media_type": "image/png", "model": "flux", "seed": 7}},
        None)
    assert out["image_b64"] == "QUJD"
    assert out["model"] == "flux"
    assert out["image_info"]["seed"] == 7


def test_final_image_data_url():
    out = mod._final_image({"json": {"image": "data:image/jpeg;base64,QUJD"}}, None)
    assert out["image_b64"] == "QUJD"
    assert out["image_info"]["media_type"] == "image/jpeg"


def test_final_image_http_url():
    out = mod._final_image({"json": {"image": "https://cdn.example/x.png"}}, None)
    assert out["image_b64"] == ""
    assert out["image_info"]["url"] == "https://cdn.example/x.png"


def test_final_image_openai_b64_json():
    """Новый контракт: {"data": [{"b64_json": …, "revised_prompt": …}]}."""
    import base64
    png = b"\x89PNG\r\n\x1a\n" + b"0" * 32
    out = mod._final_image(
        {"json": {"created": 1, "data": [
            {"b64_json": base64.b64encode(png).decode(),
             "revised_prompt": "a cat"}]}},
        None, "black-forest-labs/FLUX.2-klein-4B")

    assert out["image_b64"] == base64.b64encode(png).decode()
    assert out["image_info"]["media_type"] == "image/png"  # тип по магии файла
    assert out["image_info"]["revised_prompt"] == "a cat"
    assert out["model"] == "black-forest-labs/FLUX.2-klein-4B"


def test_final_image_openai_url():
    out = mod._final_image(
        {"json": {"data": [{"url": "https://img.example/x.png"}]}}, None)
    assert out["image_b64"] == ""
    assert out["image_info"]["url"] == "https://img.example/x.png"


def test_final_image_openai_error():
    with pytest.raises(mod._ApiError):
        mod._final_image({"json": {"error": {"message": "boom"}}}, None)


# --------------------------------------------------------------------------
# _do_generate (формирование запроса) — внешний API подменён
# --------------------------------------------------------------------------

class _FakeCtx:
    def __init__(self):
        self.beats = 0
        self.steps = []

    async def heartbeat(self):
        self.beats += 1

    # Обработчики рапортуют этапы через ctx.progress (живой статус-ряд на
    # странице) — заглушка обязана это уметь, иначе тесты падают на AttributeError.
    async def progress(self, stage=None, note=None):
        self.steps.append((stage, note))


async def _run_generate(payload, api_reply):
    ctx = _FakeCtx()
    calls = {}

    async def fake_ensure(ctx, timeout):
        return "ok"

    async def fake_api(path, **kw):
        calls["path"] = path
        calls["data"] = kw.get("data")
        calls["files"] = kw.get("files")
        calls["json_body"] = kw.get("json_body")
        return api_reply

    orig_ensure, orig_api = mod._ensure_vram, mod._call_api
    mod._ensure_vram, mod._call_api = fake_ensure, fake_api
    try:
        return await mod._do_generate(ctx, payload), calls
    finally:
        mod._ensure_vram, mod._call_api = orig_ensure, orig_api


@pytest.mark.asyncio
async def test_generate_request():
    res, calls = await _run_generate(
        {"mode": "generate", "prompt": "кот", "width": 512, "height": 512},
        {"image_bytes": b"\xff\xd8"})

    assert calls["path"] == "/v1/images/generations"
    assert calls["data"] is None  # генерация идёт JSON-телом, не формой
    assert calls["json_body"]["prompt"] == "кот"
    assert calls["json_body"]["size"] == "512x512"
    assert calls["json_body"]["model"] == "black-forest-labs/FLUX.2-klein-4B"
    assert calls["json_body"]["response_format"] == "b64_json"
    assert calls["json_body"]["n"] == 1
    assert "seed" not in calls["json_body"]  # 0 = случайный, не передаём
    assert res["image_b64"]


@pytest.mark.asyncio
async def test_generate_request_seed_and_negative(monkeypatch):
    monkeypatch.setenv("NJ641_IMAGE_STEPS", "6")
    _res, calls = await _run_generate(
        {"mode": "generate", "prompt": "кот", "seed": 42,
         "negative_prompt": "текст, водяные знаки"},
        {"image_bytes": b"\x89PNG"})

    assert calls["json_body"]["seed"] == 42
    assert calls["json_body"]["steps"] == 6
    assert calls["json_body"]["prompt"].startswith("кот")
    assert "Avoid: текст, водяные знаки" in calls["json_body"]["prompt"]


@pytest.mark.asyncio
async def test_edit_request_single_image():
    import base64
    res, calls = await _run_generate(
        {"mode": "edit", "prompt": "ночь", "strength": 0.5,
         "images": [{"b64": base64.b64encode(b"PNGDATA").decode()}]},
        {"image_bytes": b"\x89PNG"})

    assert calls["path"] == "/v1/images/edits"
    assert calls["json_body"] is None
    assert len(calls["files"]) == 1
    assert calls["files"][0][0] == "image"  # OpenAI: поле image (можно массивом)
    assert calls["data"]["prompt"] == "ночь"
    assert calls["data"]["size"] == "1024x1024"
    assert calls["data"]["model"] == "black-forest-labs/FLUX.2-klein-4B"
    assert calls["data"]["response_format"] == "b64_json"
    assert "strength" not in calls["data"]  # схема нового API его не знает


@pytest.mark.asyncio
async def test_edit_request_keeps_media_type():
    import base64
    _res, calls = await _run_generate(
        {"mode": "edit", "prompt": "ночь",
         "images": [{"b64": base64.b64encode(b"\xff\xd8JPEG").decode(),
                     "media_type": "image/jpeg"}]},
        {"image_bytes": b"\x89PNG"})

    name, raw, media = calls["files"][0][1]
    assert media == "image/jpeg"
    assert name.endswith(".jpg")


@pytest.mark.asyncio
async def test_edit_request_rejects_missing_image():
    with pytest.raises(RuntimeError):
        await _run_generate({"mode": "edit", "prompt": "ночь"}, {"image_bytes": b"x"})


@pytest.mark.asyncio
async def test_multi_reference_caps_at_three():
    import base64
    images = [{"b64": base64.b64encode(f"img{i}".encode()).decode()} for i in range(5)]
    _res, calls = await _run_generate(
        {"mode": "multi-reference", "prompt": "стиль", "images": images},
        {"image_bytes": b"\x89PNG"})

    assert calls["path"] == "/v1/images/edits"
    assert len(calls["files"]) == 3
    assert all(part[0] == "image" for part in calls["files"])
    assert calls["data"]["prompt"] == "стиль"


# --------------------------------------------------------------------------
# Адрес image-API: человеческие форматы и неповторение префикса /v1
# --------------------------------------------------------------------------

def test_image_api_local_host_without_scheme(monkeypatch):
    monkeypatch.setenv("NJ641_IMAGE_API", "127.0.0.1:8000")
    assert mod._api_url("/vram") == "http://127.0.0.1:8000/vram"


def test_image_api_quotes(monkeypatch):
    monkeypatch.setenv("NJ641_IMAGE_API", '"http://192.168.1.5:8000"')
    assert mod._api_url("/v1/images/generations") == \
        "http://192.168.1.5:8000/v1/images/generations"


def test_image_api_self_hosted_with_port_gets_http(monkeypatch):
    # Явный порт — почти всегда свой сервер; публичному имени без порта https.
    monkeypatch.setenv("NJ641_IMAGE_API", "img-server:8000")
    assert mod._api_url("/vram") == "http://img-server:8000/vram"

    monkeypatch.setenv("NJ641_IMAGE_API", "img.example.com")
    assert mod._api_url("/vram") == "https://img.example.com/vram"


def test_image_api_v1_prefix_not_doubled(monkeypatch):
    monkeypatch.setenv("NJ641_IMAGE_API", "http://127.0.0.1:8000/v1")
    assert mod._api_url("/v1/images/generations") == \
        "http://127.0.0.1:8000/v1/images/generations"
    assert mod._api_url("/vram") == "http://127.0.0.1:8000/vram"


def test_image_api_full_endpoint_trimmed(monkeypatch):
    # Админ мог вписать полный эндпоинт вместо базы.
    monkeypatch.setenv("NJ641_IMAGE_API",
                       "https://img.example/api/v1/images/generations")
    assert mod._api_url("/v1/images/generations") == \
        "https://img.example/api/v1/images/generations"


# --------------------------------------------------------------------------
# _site_endpoint: единый URL эндпоинтов сайта (образец nj602._post_site)
# --------------------------------------------------------------------------
def test_site_endpoint_plain_base(monkeypatch):
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ641_API_BASE", "https://n2j.ru")
    assert mod._site_endpoint("/imagegen/api/worker-result") == "https://n2j.ru/imagegen/api/worker-result"


def test_site_endpoint_full_endpoint_kept(monkeypatch):
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ641_API_BASE", "https://n2j.ru/imagegen/api/worker-result")
    assert mod._site_endpoint("/imagegen/api/worker-result") == "https://n2j.ru/imagegen/api/worker-result"


def test_site_endpoint_upload_path(monkeypatch):
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ641_API_BASE", "https://n2j.ru")
    assert mod._site_endpoint("/imagegen/api/worker-upload") == "https://n2j.ru/imagegen/api/worker-upload"


def _clear_site_env(monkeypatch):
    for name in ("NJ641_API_BASE", "NJ120_API_URL", "WORKER_SITE_URL",
                 "N2J_SITE_URL", "WORKER_API_URL"):
        monkeypatch.delenv(name, raising=False)


def test_site_endpoint_empty(monkeypatch):
    _clear_site_env(monkeypatch)
    assert mod._site_endpoint("/imagegen/api/worker-result") == ""


def test_site_endpoint_falls_back_to_queue_url(monkeypatch):
    # Владелец: «NJ641_SITE_TOKEN = WORKER_API_TOKEN», а NJ641_API_BASE не задан —
    # адрес сайта берём из того, что уже есть у очереди (режим http).
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ120_API_URL", "https://n2j.ru/nj120/api")
    assert mod._site_endpoint("/imagegen/api/worker-upload") == "https://n2j.ru/imagegen/api/worker-upload"


def test_site_endpoint_queue_url_in_subdir(monkeypatch):
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ120_API_URL", "https://example.com/n2j/nj120/api")
    assert mod._site_endpoint("/imagegen/api/worker-result") == "https://example.com/n2j/imagegen/api/worker-result"


def test_site_endpoint_env_written_by_human(monkeypatch):
    """Владелец (26.09) прислал NJ641_API_BASE ссылкой из чата — адрес обязан
    разобраться в любом «человеческом» формате, иначе доставка теряется."""
    variants = [
        "https://n2j.ru/imagegen/api/worker-result",
        '"https://n2j.ru/imagegen/api/worker-result"',
        "https://n2j.ru/imagegen/api/worker-result/",
        "[https://n2j.ru](https://n2j.ru/imagegen/api/worker-result)",
        "<https://n2j.ru>",
        "https://n2j.ru/imagegen/api/worker-result?x=1#y",
        "n2j.ru/imagegen/api/worker-result",
        " https://n2j.ru ",
    ]

    for value in variants:
        _clear_site_env(monkeypatch)
        monkeypatch.setenv("NJ641_API_BASE", value)
        assert mod._site_endpoint("/imagegen/api/worker-upload") == \
            "https://n2j.ru/imagegen/api/worker-upload", value
        assert mod._site_endpoint("/imagegen/api/worker-result") == \
            "https://n2j.ru/imagegen/api/worker-result", value


def test_site_endpoint_subdir_kept(monkeypatch):
    # сайт может жить в подкаталоге — отрезаем только служебный хвост
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ641_API_BASE", "[https://example.com/n2j](https://example.com/n2j/nj120/api)")
    assert mod._site_endpoint("/imagegen/api/worker-upload") == \
        "https://example.com/n2j/imagegen/api/worker-upload"


def test_site_token_strips_quotes(monkeypatch):
    # токен в кавычках ломал заголовок X-Worker-Token (401 на сайте)
    for name in ("NJ641_SITE_TOKEN", "WORKER_SITE_TOKEN", "WORKER_API_TOKEN"):
        monkeypatch.delenv(name, raising=False)

    monkeypatch.setenv("NJ641_SITE_TOKEN", '"secret-token"')

    assert mod._site_token() == "secret-token"


# --------------------------------------------------------------------------
# Файлы: сжатие и запись картинки (жалоба «изображение теряется»)
# --------------------------------------------------------------------------
def test_store_local_file_writes_and_reports(tmp_path, monkeypatch):
    raw = b"\x89PNG\r\n\x1a\n" + b"0" * 200
    monkeypatch.setenv("NJ641_MEDIA_DIR", str(tmp_path))
    monkeypatch.delenv("NJ641_MEDIA_URL_BASE", raising=False)

    info = mod._store_local_file(77, raw, "image/png")

    assert info is not None
    assert info["url"].startswith("/imagegen/file/nj641-77-")
    assert info["bytes"] == len(raw)

    from pathlib import Path
    name = info["url"].rsplit("/", 1)[-1]
    assert (tmp_path / name).read_bytes() == raw


def test_store_local_file_off_without_dir(monkeypatch):
    monkeypatch.delenv("NJ641_MEDIA_DIR", raising=False)
    assert mod._store_local_file(1, b"x" * 100, "image/png") is None


def test_store_local_file_url_base(monkeypatch, tmp_path):
    monkeypatch.setenv("NJ641_MEDIA_DIR", str(tmp_path))
    monkeypatch.setenv("NJ641_MEDIA_URL_BASE", "https://n2j.ru/imagegen/file/")

    info = mod._store_local_file(5, b"y" * 100, "image/webp")

    assert info is not None
    assert info["url"].startswith("https://n2j.ru/imagegen/file/nj641-5-")
    assert info["url"].endswith(".webp")


def test_shrink_image_keeps_small(monkeypatch):
    small = b"\x89PNG\r\n\x1a\n" + b"0" * 1000
    raw, media = mod._shrink_image(small, "image/png")
    assert raw == small and media == "image/png"


def test_shrink_image_webp_when_pillow(monkeypatch):
    pytest.importorskip("PIL", reason="Pillow нет — сжатие пропускается штатно")

    from io import BytesIO

    from PIL import Image

    im = Image.new("RGB", (1200, 1200), (120, 40, 200))
    buf = BytesIO()
    im.save(buf, format="PNG")
    png = buf.getvalue()

    if len(png) < 900 * 1024:  # однотонная картинка сжимается сама — смысла нет
        pytest.skip("тестовый PNG слишком лёгкий")

    raw, media = mod._shrink_image(png, "image/png")

    assert media == "image/webp"
    assert len(raw) < len(png)



# --------------------------------------------------------------------------
# Доставка результата: ссылка без байтов (response_format=url)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_finalize_delivers_url_without_bytes(monkeypatch):
    ctx = _FakeCtx()
    ctx.pg = None
    sent = {}

    async def fake_post(run_id, res):
        sent["run_id"] = run_id
        sent["res"] = res
        return True

    monkeypatch.setattr(mod, "_post_result_to_site", fake_post)
    monkeypatch.setattr(mod, "_store_local_file", lambda *a, **k: None)

    source = await mod._finalize(ctx, 5, {
        "image_b64": "",
        "image_info": {"url": "https://img.example/x.png"},
        "model": "flux",
    })

    assert source == "json"
    assert sent["run_id"] == 5
    assert sent["res"]["image_info"]["url"] == "https://img.example/x.png"


# --------------------------------------------------------------------------
# Доставка картинки на сайт ФАЙЛОМ (multipart) — основной http-путь
# --------------------------------------------------------------------------
class _FakeResponse:
    def __init__(self, status=200, data=None, headers=None, content=b""):
        self.status_code = status
        self._data = {"ok": True} if data is None else data
        self.text = "ok"
        self.headers = headers if headers is not None else {"content-type": "application/json"}
        self.content = content

    def json(self):
        return self._data


class _FakeClient:
    """Заглушка httpx.AsyncClient: запоминает последний POST и заголовки."""

    last = {}
    init = {}

    def __init__(self, *a, **kw):
        _FakeClient.init = kw

    async def __aenter__(self):
        return self

    async def __aexit__(self, *a):
        return False

    async def post(self, url, **kw):
        _FakeClient.last = {"url": url, **kw}
        return _FakeResponse()


@pytest.mark.asyncio
async def test_call_api_posts_json_with_bearer(monkeypatch):
    """Генерация уходит JSON-телом на /v1/images/generations, токен — Bearer."""
    monkeypatch.setenv("NJ641_IMAGE_API", "http://127.0.0.1:8000")
    monkeypatch.setenv("NJ641_API_TOKEN", "secret")
    monkeypatch.setattr(mod.httpx, "AsyncClient", _FakeClient)

    out = await mod._call_api("/v1/images/generations",
                              json_body={"prompt": "кот", "size": "512x512"},
                              timeout=5)

    assert out == {"json": {"ok": True}}
    assert _FakeClient.last["url"] == "http://127.0.0.1:8000/v1/images/generations"
    assert _FakeClient.last["json"] == {"prompt": "кот", "size": "512x512"}
    assert _FakeClient.init["headers"]["Authorization"] == "Bearer secret"


@pytest.mark.asyncio
async def test_upload_image_to_site_multipart(monkeypatch):
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ641_API_BASE", "https://n2j.ru")
    monkeypatch.setenv("NJ641_SITE_TOKEN", "secret")
    monkeypatch.setattr(mod.httpx, "AsyncClient", _FakeClient)

    ok = await mod._upload_image_to_site(
        7, b"\x89PNG" + b"0" * 200, "image/png",
        {"model": "flux", "duration_ms": 1500})

    assert ok is True
    assert _FakeClient.last["url"] == "https://n2j.ru/imagegen/api/worker-upload"
    assert _FakeClient.last["files"]["image"][0] == "result.png"
    assert _FakeClient.last["data"]["run_id"] == "7"
    assert _FakeClient.last["headers"]["X-Worker-Token"] == "secret"


@pytest.mark.asyncio
async def test_upload_image_to_site_without_token(monkeypatch):
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ641_API_BASE", "https://n2j.ru")
    monkeypatch.delenv("NJ641_SITE_TOKEN", raising=False)
    monkeypatch.delenv("WORKER_SITE_TOKEN", raising=False)
    monkeypatch.delenv("WORKER_API_TOKEN", raising=False)

    assert await mod._upload_image_to_site(7, b"x" * 100, "image/png", {}) is False


@pytest.mark.asyncio
async def test_upload_image_to_site_strips_full_endpoint_base(monkeypatch):
    # Совместимость: базой мог быть задан полный адрес прежнего эндпоинта —
    # путь модуля всё равно должен получиться правильным.
    _clear_site_env(monkeypatch)
    monkeypatch.setenv("NJ641_API_BASE", "https://n2j.ru/imagegen/api/worker-result")
    monkeypatch.setenv("NJ641_SITE_TOKEN", "secret")
    monkeypatch.setattr(mod.httpx, "AsyncClient", _FakeClient)

    assert await mod._upload_image_to_site(7, b"x" * 100, "image/png", {}) is True
    assert _FakeClient.last["url"] == "https://n2j.ru/imagegen/api/worker-upload"


def test_store_local_file_relative_path_under_site(tmp_path, monkeypatch):
    # Каталог внутри сайта (…/storage/nj641) → путь в image_info относительный:
    # сайт должен уметь найти и удалить файл, не зная абсолютных путей воркера.
    media = tmp_path / "storage" / "nj641"
    media.mkdir(parents=True)
    monkeypatch.setenv("NJ641_MEDIA_DIR", str(media))

    info = mod._store_local_file(42, b"\x89PNG" + b"0" * 200, "image/png")

    assert info is not None
    assert info["file"].startswith("storage/nj641/nj641-42-")
    assert (media / info["file"].rsplit("/", 1)[-1]).exists()
