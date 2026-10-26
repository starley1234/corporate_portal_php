"""nj639.analyze — подготовка кадра и адрес приёмки вердикта.

    cd workers && python3 -m pytest tests/test_nj639_qc.py -q

Почему именно это. Контроль качества «работал» на бумаге, а вживую терял
вердикт в двух местах сразу: кадр уезжал в модель уменьшенным до 512 px
(дефект на таком кадре не разглядеть), а результат уходил по адресу из
NJ639_API_BASE, где уже был указан полный путь /worker_result — добавленный
сверху путь давал 404. Обе ловушки закрыты, тест держит их закрытыми.
"""
import asyncio
import base64
import io
import os
import sys

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from n2j_workers.handlers import nj639_qc  # noqa: E402


@pytest.fixture(autouse=True)
def _clean_env(monkeypatch):
    for name in ("NJ639_API_BASE", "NJ120_API_BASE", "NJ639_MAX_TOKENS",
                 "VLM_MAX_TOKENS", "NJ639_LLM_TIMEOUT", "VLM_TIMEOUT"):
        monkeypatch.delenv(name, raising=False)


# --------------------------------------------------------------------------
# Адрес приёмки
# --------------------------------------------------------------------------

def test_api_base_with_full_path_is_not_doubled(monkeypatch):
    """Историческая форма из .env.example: путь уже внутри адреса."""
    monkeypatch.setenv("NJ639_API_BASE", "https://n2j.ru/qc/api/worker_result")

    assert nj639_qc._result_path() == ""


def test_api_base_of_the_family_gets_the_path(monkeypatch):
    """Семейная форма (как у NJ650/NJ651/NJ652) — путь добавляет воркер."""
    monkeypatch.setenv("NJ639_API_BASE", "https://n2j.ru/qc/api")

    assert nj639_qc._result_path() == "/worker_result"


def test_api_base_with_dash_is_recognised(monkeypatch):
    monkeypatch.setenv("NJ639_API_BASE", "https://n2j.ru/qc/api/worker-result/")

    assert nj639_qc._result_path() == ""


# --------------------------------------------------------------------------
# Настройки модели — общие с остальными VLM-сервисами
# --------------------------------------------------------------------------

def test_tokens_and_timeout_fall_back_to_shared(monkeypatch):
    assert nj639_qc._max_tokens() == 1500
    assert nj639_qc._llm_timeout() == 240.0

    monkeypatch.setenv("VLM_MAX_TOKENS", "900")
    monkeypatch.setenv("VLM_TIMEOUT", "150")

    assert nj639_qc._max_tokens() == 900
    assert nj639_qc._llm_timeout() == 150.0

    monkeypatch.setenv("NJ639_MAX_TOKENS", "2000")
    monkeypatch.setenv("NJ639_LLM_TIMEOUT", "200")

    assert nj639_qc._max_tokens() == 2000
    assert nj639_qc._llm_timeout() == 200.0


def test_timeout_is_smaller_than_job_timeout():
    """timeout_sec задачи сайт ставит 1200: ожидание модели обязано быть меньше."""
    assert nj639_qc._llm_timeout() < 1200


# --------------------------------------------------------------------------
# Кадр для модели
# --------------------------------------------------------------------------

def _png(width: int, height: int) -> str:
    from PIL import Image

    buf = io.BytesIO()
    Image.new("RGB", (width, height), (200, 30, 30)).save(buf, "PNG")

    return base64.b64encode(buf.getvalue()).decode()


def _size_of(b64: str) -> tuple[int, int]:
    from PIL import Image

    return Image.open(io.BytesIO(base64.b64decode(b64))).size


def test_big_frame_is_shrunk_to_the_shared_width():
    pytest.importorskip("PIL")

    ready = asyncio.run(nj639_qc._prepare_image({"b64": _png(2400, 1200),
                                                 "media_type": "image/png"}))

    assert ready is not None
    assert _size_of(ready["b64"])[0] == nj639_qc.IMAGE_MAX_W == 1400
    # Pillow отдаёт JPEG — mime обязан это отражать, иначе часть провайдеров 400
    assert ready["media_type"] == "image/jpeg"


def test_small_frame_keeps_its_pixels():
    """Апскейла нет: деталей он не добавит, а патчей vision съест вчетверо."""
    pytest.importorskip("PIL")

    ready = asyncio.run(nj639_qc._prepare_image({"b64": _png(640, 480)}))

    assert _size_of(ready["b64"]) == (640, 480)


def test_tiny_frame_is_honestly_marked():
    pytest.importorskip("PIL")

    ready = asyncio.run(nj639_qc._prepare_image({"b64": _png(320, 240)}))

    from n2j_workers import vlm

    assert "кадр мелкий" in vlm.small_photo_warning(ready["meta"])


def test_broken_frame_is_dropped():
    assert asyncio.run(nj639_qc._prepare_image({"b64": "не base64"})) is None
    assert asyncio.run(nj639_qc._prepare_image({"b64": ""})) is None
    assert asyncio.run(nj639_qc._prepare_image("")) is None


# --------------------------------------------------------------------------
# Сообщение модели
# --------------------------------------------------------------------------

def test_messages_carry_rule_schema_reference_and_frame():
    msgs = nj639_qc._build_messages(
        "ищи сколы", ["годен", "брак"],
        {"type": "object", "required": ["verdict"]},
        [{"b64": "ЭТАЛОН", "media_type": "image/jpeg"}],
        [{"b64": "КАДР", "media_type": "image/jpeg"}],
        note="кадр мелкий (320×240)")

    assert msgs[0]["role"] == "system"

    parts = msgs[1]["content"]
    text = " ".join(p.get("text", "") for p in parts)
    images = [p for p in parts if p["type"] == "image_url"]

    assert "ищи сколы" in text
    assert "годен; брак" in text
    assert "кадр мелкий" in text, "мелкий кадр — предупреждение уходит и модели"
    assert len(images) == 2, "эталон и проверяемый кадр"
    assert images[0]["image_url"]["url"].startswith("data:image/jpeg;base64,ЭТАЛОН")
