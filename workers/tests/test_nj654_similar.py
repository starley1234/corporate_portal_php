"""nj654.embed: обработчик канала «Похожие картинки».

Проверяем ровно то, ради чего канал и написан:

  * пачка элементов не падает целиком из-за одной битой картинки — человек
    загрузил двадцать файлов, девятнадцать обязаны попасть в банк;
  * картинки едут с сайта отдельным запросом, а не в payload очереди;
  * отказ всегда доезжает до сайта (иначе строка навсегда повиснет в
    «считается»);
  * векторы не возвращаются в отчёт задачи — это мегабайты чисел в общей
    очереди.

Модель поддельная: нам важна логика задачи, а не качество эмбеддингов.
"""

from __future__ import annotations

import pytest

import n2j_workers.handlers.nj654_similar as mod

DIMS = 8


class FakeModel:
    """Эмбеддер-пустышка: вектор зависит от текста и наличия картинки."""

    model = "fake-vl-embedding"

    def __init__(self, bad_text: str = ""):
        #: текст, на котором модель падает — так проверяем частичный отказ
        self.bad_text = bad_text
        self.seen: list[tuple[str, bool]] = []
        self.closed = False

    async def embed_one(self, item):
        self.seen.append((item.text, bool(item.image_b64)))

        if self.bad_text and item.text == self.bad_text:
            raise RuntimeError("модель подавилась")

        return [float(len(item.text) % 7), 1.0 if item.image_b64 else 0.0] + [0.0] * (DIMS - 2)

    async def close(self):
        self.closed = True


class FakeCtx:
    def __init__(self):
        self.steps: list[tuple[str, str]] = []

    async def progress(self, stage: str, note: str = ""):
        self.steps.append((stage, note))


@pytest.fixture()
def sent(monkeypatch) -> list[dict]:
    """Перехват доставки на сайт."""
    box: list[dict] = []

    async def fake_deliver(payload: dict):
        box.append(payload)

        return True, ""

    monkeypatch.setattr(mod, "deliver", fake_deliver)

    return box


@pytest.fixture()
def photos(monkeypatch) -> list[tuple[str, int]]:
    """Перехват похода за картинкой: в payload очереди её быть не должно."""
    box: list[tuple[str, int]] = []

    async def fake_fetch(ctx, kind, row_id):
        box.append((kind, int(row_id)))

        return "QUJD", "image/jpeg"

    monkeypatch.setattr(mod, "fetch_photo", fake_fetch)

    return box


# --------------------------------------------------------------------------
# Элементы банка
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_items_pull_photos_from_site(photos):
    model = FakeModel()
    result = await mod.run_items(FakeCtx(), model, {
        "kind": "items",
        "items": [
            {"id": 11, "text": "рыжий кот", "has_image": True},
            {"id": 12, "text": "просто текст", "has_image": False},
        ],
    })

    assert photos == [("item", 11)], "картинка берётся с сайта и только для того, у кого она есть"
    assert [r["id"] for r in result["items"]] == [11, 12]
    assert all("vector" in r for r in result["items"])
    assert result["failed"] == 0


@pytest.mark.asyncio
async def test_one_bad_item_does_not_kill_the_batch(photos):
    model = FakeModel(bad_text="битая")
    result = await mod.run_items(FakeCtx(), model, {
        "kind": "items",
        "items": [
            {"id": 1, "text": "хорошая", "has_image": False},
            {"id": 2, "text": "битая", "has_image": False},
            {"id": 3, "text": "ещё хорошая", "has_image": False},
        ],
    })

    by_id = {r["id"]: r for r in result["items"]}

    assert "vector" in by_id[1] and "vector" in by_id[3]
    assert "error" in by_id[2] and "vector" not in by_id[2]
    assert (result["ok_count"], result["failed"]) == (2, 1)


@pytest.mark.asyncio
async def test_items_without_rows_is_an_error():
    with pytest.raises(ValueError):
        await mod.run_items(FakeCtx(), FakeModel(), {"kind": "items", "items": []})


@pytest.mark.asyncio
async def test_empty_row_is_reported_not_skipped(photos):
    """Элемент без текста и без картинки — отказ со словами, а не тишина."""
    result = await mod.run_items(FakeCtx(), FakeModel(), {
        "kind": "items", "items": [{"id": 5, "text": "", "has_image": False}]})

    assert "error" in result["items"][0]


# --------------------------------------------------------------------------
# Поисковый запрос
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_query_returns_single_vector(photos):
    result = await mod.run_query(FakeCtx(), FakeModel(), {
        "kind": "query", "id": 77, "text": "кот на окне", "has_image": True})

    assert photos == [("query", 77)]
    assert result["kind"] == "query" and result["id"] == 77
    assert len(result["vector"]) == DIMS


@pytest.mark.asyncio
async def test_query_without_id_is_an_error():
    with pytest.raises(ValueError):
        await mod.run_query(FakeCtx(), FakeModel(), {"kind": "query", "text": "что-то"})


# --------------------------------------------------------------------------
# Точка входа
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_handle_strips_vectors_from_queue_result(monkeypatch, sent, photos):
    model = FakeModel()
    monkeypatch.setattr(mod, "_client", lambda: model)

    summary = await mod.handle(FakeCtx(), {
        "kind": "items", "items": [{"id": 9, "text": "кот", "has_image": False}]})

    assert "items" not in summary and "vector" not in summary
    assert summary["count"] == 1
    assert sent and sent[0]["items"][0]["vector"], "векторы обязаны доехать до сайта"
    assert model.closed, "соединение с моделью закрывается в любом случае"


@pytest.mark.asyncio
async def test_handle_reports_failure_to_site(monkeypatch, sent):
    class Broken(FakeModel):
        async def embed_one(self, item):
            raise RuntimeError("сервер эмбеддингов недоступен")

    monkeypatch.setattr(mod, "_client", lambda: Broken())

    with pytest.raises(RuntimeError):
        await mod.handle(FakeCtx(), {
            "kind": "query", "id": 42, "text": "кот", "has_image": False})

    assert sent and sent[0]["status"] == "error" and sent[0]["id"] == 42


@pytest.mark.asyncio
async def test_handle_marks_every_item_of_a_failed_batch(monkeypatch, sent):
    class Broken(FakeModel):
        async def embed_one(self, item):
            raise RuntimeError("всё плохо")

    monkeypatch.setattr(mod, "_client", lambda: Broken())

    # Даже когда падает сама пачка, каждая её строка обязана получить причину:
    # иначе элементы останутся в «считается» навсегда.
    result = await mod.handle(FakeCtx(), {
        "kind": "items",
        "items": [{"id": 1, "text": "a", "has_image": False},
                  {"id": 2, "text": "b", "has_image": False}]})

    assert result["failed"] == 2
    assert all("error" in r for r in sent[0]["items"])


@pytest.mark.asyncio
async def test_handle_rejects_unknown_kind(monkeypatch, sent):
    monkeypatch.setattr(mod, "_client", lambda: FakeModel())

    with pytest.raises(ValueError):
        await mod.handle(FakeCtx(), {"kind": "мусор"})

    assert sent == [], "непонятную задачу сайту не отдаём: нечего помечать"


@pytest.mark.asyncio
async def test_handle_raises_when_site_refuses_result(monkeypatch, photos):
    async def refuse(payload: dict):
        return False, "403"

    monkeypatch.setattr(mod, "deliver", refuse)
    monkeypatch.setattr(mod, "_client", lambda: FakeModel())

    with pytest.raises(RuntimeError):
        await mod.handle(FakeCtx(), {
            "kind": "query", "id": 3, "text": "кот", "has_image": False})
