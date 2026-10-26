"""nj646.label: подготовка фото к модели (только уменьшение).

Владелец (03.10.2026): «Фото к модели 384×520 → 768×1040 — ты зачем
увеличиваешь, чтобы потратить больше токенов?» Раньше мелкие снимки
апскейлились до 1600 px «ради патчей»: деталей это не создаёт, а платных
токенов vision ест вчетверо больше. Теперь фото только УЖИМАЕТСЯ (крупные до
1400 px), мелкое не трогаем вовсе, а на мелкий кадр честно указывает
`_small_photo_warning` — переснять блок «Состав» крупно.
"""

from __future__ import annotations

import io

import pytest

# Pillow в CI НЕ ставится (тяжёлая зависимость, см. шапку .github/workflows/ci.yml):
# без него тесты подготовки фото штатно пропускаются — как у соседа
# test_nj641_imagegen.py. Импорт `from PIL import Image` на уровне модуля ронял
# СБОР тестов целиком (pytest выходит с кодом 2 — «collection error»), а не только
# этот файл: джоба «Python-воркеры» была красной из-за одной строки импорта.
pytest.importorskip("PIL", reason="Pillow нет — подготовка фото пропускается штатно")

from PIL import Image  # noqa: E402  (после importorskip)

import n2j_workers.handlers.nj646_label as mod  # noqa: E402


def _jpeg(w: int, h: int) -> bytes:
    out = io.BytesIO()
    Image.new("RGB", (w, h), (240, 240, 240)).save(out, "JPEG", quality=80)
    return out.getvalue()


def _size(data: bytes) -> tuple[int, int]:
    return Image.open(io.BytesIO(data)).size


def test_small_photo_is_not_upscaled():
    """384×520 → 384×520: кадр не раздуваем, токены не тратим впустую."""
    data, meta = mod._prepare_sync(_jpeg(384, 520), max_w=1400)
    w, h = _size(data)

    assert (w, h) == (384, 520)
    assert meta["src"] == "384×520"
    assert meta["out"] == "384×520"


def test_big_photo_is_still_shrunk():
    data, meta = mod._prepare_sync(_jpeg(3000, 2000), max_w=1400)
    w, h = _size(data)

    assert w == 1400
    assert h == round(2000 * 1400 / 3000)
    assert meta["src"] == "3000×2000"


def test_medium_photo_is_only_shrunk():
    """Фото крупнее лимита — ужимаем; меньше лимита — не трогаем и не раздуваем."""
    data, _meta = mod._prepare_sync(_jpeg(1600, 1200), max_w=1400)
    w, h = _size(data)

    assert w == 1400 and h == round(1200 * 1400 / 1600)

    data, meta = mod._prepare_sync(_jpeg(1200, 900), max_w=1400)
    assert _size(data) == (1200, 900)
    assert meta["out"] == "1200×900"


def test_small_photo_is_reported_to_the_owner():
    """400×600 — это и есть причина выдумок: про это надо сказать прямо."""
    _data, meta = mod._prepare_sync(_jpeg(400, 600), max_w=1400)
    warning = mod._small_photo_warning(meta)

    assert "фото мелкое" in warning
    assert "400×600" in warning
    assert "крупнее" in warning


def test_normal_photo_is_not_nagged_about():
    _data, meta = mod._prepare_sync(_jpeg(1600, 1200), max_w=1400)

    assert mod._small_photo_warning(meta) == ""
    assert mod._small_photo_warning({}) == ""


def test_small_photo_warning_reaches_the_page(monkeypatch):
    """Сквозная проверка: мелкое фото → в result.note есть пометка."""
    import asyncio
    import n2j_workers.handlers.nj646_label as label

    posted: list[dict] = []

    async def fake_post(_ctx, payload):
        posted.append(dict(payload))
        return (True, "")

    monkeypatch.setattr(label, "_post", fake_post)

    class _Ctx:
        def __init__(self):
            self.steps = []
            self.llm = self

        async def progress(self, stage=None, note=None):
            self.steps.append((stage, note))

        async def heartbeat(self):
            return True

        async def chat_with_usage(self, messages, **kw):
            return ('{"composition": "Вода, Пантенол", "kind": "cosmetic", '
                    '"confidence": 70, "note": ""}',
                    {"_llm_finish_reason": "stop"})

    import base64
    b64 = base64.b64encode(_jpeg(400, 600)).decode()
    result = asyncio.run(label.handle(_Ctx(), {"run_id": 1, "image_b64": b64}))

    assert "фото мелкое" in result["note"]
    assert posted[0]["note"] == result["note"]
