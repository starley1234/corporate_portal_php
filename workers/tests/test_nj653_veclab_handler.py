"""nj653.embed: обработчик канала «Лаборатория векторов».

Проверяем не модель, а ЛОГИКУ прогона: что самопроверка честно отличает
мультимодальную модель от текстовой, что картинки едут с сайта отдельным
запросом (а не в payload очереди) и что отказ всегда доезжает до сайта —
иначе прогон навсегда повиснет в «идёт».

Модель поддельная, но осмысленная: слова превращаются в разреженный вектор,
а картинка — в слова по своему цвету. Такая «модель» ведёт себя как
настоящая мультимодальная (подпись ближе к своей картинке), и на ней видно,
что проба меряет именно это.
"""

from __future__ import annotations

import base64
import io
import re
import zlib

import pytest

import n2j_workers.handlers.nj653_veclab as mod
from n2j_workers.mmembed import EmbedInput, MmEmbedError

DIMS = 1024


def _vector(words: list[str]) -> list[float]:
    """Слова → разреженный вектор фиксированной длины (хеширование признаков)."""
    vec = [0.0] * DIMS

    for word in words:
        vec[zlib.crc32(word.encode()) % DIMS] += 1.0

    return vec


def _words(text: str) -> list[str]:
    # Короткие слова («на», «по», «за») выбрасываем: иначе «мешок слов»
    # считает близкими любые две русские фразы и проба на смысл теряет смысл.
    return [w for w in re.split(r"\W+", text.lower()) if len(w) > 2]


class FakeModel:
    """Мультимодальная модель-пустышка: текст и цвет картинки в одном пространстве."""

    model = "fake-vl-embedding"
    dialect = "oai_content"

    def __init__(self, blind: bool = False):
        #: blind=True — «текстовая модель, притворяющаяся мультимодальной»:
        #: картинку принимает, но вектор выдаёт одинаковый и бессмысленный.
        self.blind = blind
        self.calls: list[str] = []

    async def detect(self, probe=None, force=False):
        return []

    async def list_models(self):
        """Что сервер считает загруженным — паспорт показывает это на странице."""
        return [self.model], ""

    def _one(self, item: EmbedInput) -> list[float]:
        self.calls.append(item.kind)

        if item.has_image:
            if self.blind:
                return _vector(["картинка"])

            return _vector(_words(self._describe(item.image_b64)))

        return _vector(_words(item.text))

    @staticmethod
    def _describe(image_b64: str) -> str:
        from PIL import Image

        try:
            img = Image.open(io.BytesIO(base64.b64decode(image_b64))).convert("RGB")
        except Exception:
            # Не картинка (в тестах режимов кадр подменён заглушкой) — модель
            # всё равно обязана вернуть вектор, а не упасть.
            return "неизвестная картинка"

        r, g, b = img.getpixel((img.width // 2, img.height // 2))

        return ("красный круг на белом фоне" if r > b
                else "синий квадрат на белом фоне")

    async def embed(self, items):
        return [self._one(i) for i in items]

    async def embed_one(self, item):
        return self._one(item)

    def describe(self) -> dict:
        return {"base_url": "http://fake/v1", "model": self.model, "dimensions": DIMS,
                "dialect": self.dialect, "forced": False, "token_src": "", "attempts": []}

    async def close(self):
        return None


class FakeCtx:
    """Контекст задачи: нас интересуют только этапы (они видны в статус-ряде)."""

    def __init__(self):
        self.steps: list[tuple[str, str]] = []

    async def progress(self, stage: str, note: str = ""):
        self.steps.append((stage, note))


# --------------------------------------------------------------------------
# Самопроверка модели
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_probe_passes_for_multimodal_model():
    pytest.importorskip("PIL", reason="Pillow нет — кросс-модальную пробу негде рисовать")

    ctx = FakeCtx()
    result = await mod.run_probe(ctx, FakeModel())
    checks = {c["key"]: c for c in result["checks"]}

    assert result["ok"] is True
    assert checks["repeat"]["ok"] is True, "один текст дважды — один и тот же вектор"
    assert checks["text"]["ok"] is True, "близкая фраза обязана быть ближе чужой"
    assert checks["cross"]["ok"] is True, "подпись обязана находить свою картинку"
    assert result["cross"]["grid"][0][0] > result["cross"]["grid"][0][1]
    assert result["dims"] == DIMS
    assert result["dialect"] == "oai_content", "формат запроса обязан доезжать до страницы"
    assert checks["repeat"]["level"] == "ok"
    assert ctx.steps, "этапы обязаны появляться: иначе статус-ряд молчит"


def test_repeat_check_levels():
    """Повторяемость читается тремя исходами, а не «да/нет».

    У квантованной GGUF-сборки один и тот же текст даёт 0,997 — это норма,
    и красный крест на рабочей модели только пугает владельца сайта. Беда
    начинается ниже 0,99: там вход считается вместе с соседями по запросу.
    """
    exact = mod._repeat_check("repeat", "т", 0.99999, "текст")
    close = mod._repeat_check("repeat", "т", 0.9976, "текст")
    broken = mod._repeat_check("repeat", "т", 0.77, "текст")

    assert exact["level"] == "ok" and exact["ok"] is True
    assert close["level"] == "warn" and close["ok"] is True, "0,9976 — не провал"
    assert "квантованной" in close["note"], "человеку надо объяснить, почему не единица"
    assert broken["level"] == "fail" and broken["ok"] is False
    assert "EMBEDDING_MM_BATCH=1" in broken["note"], "у настоящей беды есть лечение"


@pytest.mark.asyncio
async def test_probe_catches_text_only_model():
    """Главный случай ради которого всё затеяно: модель «не видит» картинку."""
    pytest.importorskip("PIL", reason="Pillow нет — кросс-модальную пробу негде рисовать")

    result = await mod.run_probe(FakeCtx(), FakeModel(blind=True))
    checks = {c["key"]: c for c in result["checks"]}

    assert checks["cross"]["ok"] is False
    assert result["ok"] is False
    assert "не связывает" in checks["cross"]["note"] or "только" in checks["cross"]["note"]


@pytest.mark.asyncio
async def test_probe_survives_model_that_rejects_images(monkeypatch):
    """Сервер не принял картинку — это отказ пробы, а не падение прогона."""
    pytest.importorskip("PIL", reason="Pillow нет — кросс-модальную пробу негде рисовать")

    class Picky(FakeModel):
        async def embed_one(self, item):
            if item.has_image:
                raise MmEmbedError("HTTP 400: image input is not supported")

            return self._one(item)

    result = await mod.run_probe(FakeCtx(), Picky())
    cross = {c["key"]: c for c in result["checks"]}["cross"]

    assert cross["ok"] is False
    assert cross["value"] is None
    assert "не приняла" in cross["note"]
    assert result["ok"] is False


def test_draw_shape_makes_a_real_png():
    pytest.importorskip("PIL", reason="Pillow нет")

    from PIL import Image

    data = base64.b64decode(mod.draw_shape("circle", (220, 38, 38), size=64))
    img = Image.open(io.BytesIO(data))

    assert img.size == (64, 64)
    assert img.convert("RGB").getpixel((32, 32)) == (220, 38, 38)
    assert img.convert("RGB").getpixel((1, 1)) == (255, 255, 255), "фон белый"


# --------------------------------------------------------------------------
# Рабочие режимы
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_vectors_mode_pulls_photo_from_site(monkeypatch):
    """Кадр едет отдельным запросом: в payload очереди картинок быть не должно."""
    # Поддельная модель читает картинку настоящим Pillow (как настоящая), а он
    # в тестовом наборе CI не ставится: без него тест пропускается, а не падает.
    pytest.importorskip("PIL", reason="Pillow нет — картинку запроса негде разобрать")
    asked: list[tuple[int, int]] = []

    async def fake_fetch(ctx, run_id, slot):
        asked.append((run_id, slot))

        return base64.b64encode(b"fake-bytes").decode(), "image/png"

    monkeypatch.setattr(mod, "fetch_photo", fake_fetch)

    model = FakeModel()
    result = await mod.run_vectors(FakeCtx(), model, 42, [
        {"slot": 1, "text": "рыжий кот", "has_image": True},
        {"slot": 2, "text": "просто текст", "has_image": False},
    ], "compare")

    assert asked == [(42, 1)], "кадр запрашивается только для слота с картинкой"
    assert model.calls == ["both", "text"]
    assert result["kinds"] == ["both", "text"]
    assert len(result["vectors"]) == 2
    assert "cosine" in result, "в сравнении косинус считает сам воркер"


@pytest.mark.asyncio
async def test_search_mode_keeps_top(monkeypatch):
    result = await mod.run_vectors(FakeCtx(), FakeModel(), 7,
                                   [{"slot": 1, "text": "кот", "top": 3}], "search")

    assert result["top"] == 3
    assert result["mode"] == "search"
    assert len(result["vectors"]) == 1


# --------------------------------------------------------------------------
# Точка входа канала
# --------------------------------------------------------------------------

def _catch_delivery(monkeypatch) -> list[dict]:
    sent: list[dict] = []

    async def fake_deliver(payload):
        sent.append(payload)

        return True, ""

    monkeypatch.setattr(mod, "deliver", fake_deliver)

    return sent


@pytest.mark.asyncio
async def test_handle_strips_vectors_from_queue_result(monkeypatch):
    """В очереди остаётся сводка: 2048 чисел в jsonb задачи — это мусор."""
    sent = _catch_delivery(monkeypatch)
    monkeypatch.setattr(mod, "_client", lambda: FakeModel())

    summary = await mod.handle(FakeCtx(), {"run_id": 5, "mode": "add",
                                           "inputs": [{"slot": 1, "text": "кот"}]})

    assert "vectors" not in summary
    assert summary["vectors_count"] == 1
    assert sent[0]["run_id"] == 5
    assert sent[0]["status"] == "done"
    assert len(sent[0]["vectors"]) == 1, "сайту векторы нужны — он их хранит"


@pytest.mark.asyncio
async def test_handle_reports_failure_to_site(monkeypatch):
    """Отказ обязан доехать до сайта: иначе прогон висит в «идёт» навсегда."""
    sent = _catch_delivery(monkeypatch)

    class Broken(FakeModel):
        async def embed(self, items):
            raise MmEmbedError("сеть: ConnectError")

    monkeypatch.setattr(mod, "_client", lambda: Broken())

    with pytest.raises(MmEmbedError):
        await mod.handle(FakeCtx(), {"run_id": 6, "mode": "add",
                                     "inputs": [{"slot": 1, "text": "кот"}]})

    assert sent[0] == {"run_id": 6, "status": "error",
                       "error": "сеть: ConnectError", "mode": "add"}


@pytest.mark.asyncio
async def test_handle_rejects_unknown_mode(monkeypatch):
    _catch_delivery(monkeypatch)
    monkeypatch.setattr(mod, "_client", lambda: FakeModel())

    with pytest.raises(ValueError):
        await mod.handle(FakeCtx(), {"run_id": 7, "mode": "потанцуем"})


@pytest.mark.asyncio
async def test_handle_requires_inputs(monkeypatch):
    sent = _catch_delivery(monkeypatch)
    monkeypatch.setattr(mod, "_client", lambda: FakeModel())

    with pytest.raises(ValueError):
        await mod.handle(FakeCtx(), {"run_id": 8, "mode": "search", "inputs": []})

    assert sent and sent[0]["status"] == "error", "причина обязана доехать до сайта"
