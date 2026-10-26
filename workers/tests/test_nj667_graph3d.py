"""nj667.* — «Графовый 3D-конструктор»: сборка графа, достройка, саммари,
вектор и пре-модерация.

Проверяем ровно то, ради чего каналы и написаны:

  * в модель уезжают ПРАВИЛА ПРОСТРАНСТВА (центр, верх/низ, симметрия,
    «нет связей без узлов») — без них модель ставит всё в кучу;
  * ответ модели чистится от ```json и от преамбулы: это не теория, модели
    так отвечают регулярно;
  * достройка (inpaint) отправляет на сайт merge=True: новые узлы
    приклеиваются к существующим по id, а не заменяют весь проект;
  * при достройке в подсказку уезжает ОКРЕСТНОСТЬ выбранного узла, а не весь
    граф — контекст модели стоит денег;
  * похожие графы из каталога попадают в подсказку (few-shot RAG)
    только если проект пустой; если граф уже есть — в модель уезжает
    ОН, а не первый стул из каталога;
  * сайт НЕ принял граф — задача падает с причиной, а не «тихо успешно»;
  * саммари и вектор уезжают на свои точки; вердикт пре-модерации приводит
    «плохие» фразы к понятной причине.

Сайт и модель поддельные: важна логика канала, а не качество LLM.
"""

from __future__ import annotations

import json

import pytest

import n2j_workers.handlers.nj667_graph3d as mod


# --------------------------------------------------------------------------
# Подделки
# --------------------------------------------------------------------------

class FakeLLM:
    """Модель-пустышка: отдаёт заготовленные ответы по очереди."""

    def __init__(self, replies: list[str] | None = None):
        self.replies = list(replies or [])
        self.calls = 0
        self.last_messages: list[dict] = []

    async def chat_with_usage(self, messages, model=None, **options):
        self.calls += 1
        self.last_messages = list(messages)

        if self.replies:
            return self.replies.pop(0), {"total_tokens": 100}

        return "{}", {}

    @property
    def system(self) -> str:
        return "\n".join(m["content"] for m in self.last_messages if m["role"] == "system")

    @property
    def user(self) -> str:
        parts = []

        for m in self.last_messages:
            if m.get("role") != "user":
                continue

            content = m.get("content")

            if isinstance(content, str):
                parts.append(content)
            elif isinstance(content, list):
                for item in content:
                    if isinstance(item, dict) and item.get("type") == "text":
                        parts.append(str(item.get("text") or ""))

        return "\n".join(parts)

    @property
    def has_image(self) -> bool:
        for m in self.last_messages:
            content = m.get("content")

            if not isinstance(content, list):
                continue

            for item in content:
                if isinstance(item, dict) and item.get("type") == "image_url":
                    return True

        return False


class FakeCtx:
    def __init__(self, llm: FakeLLM | None = None):
        self.steps: list[tuple[str, str]] = []
        self.llm = llm

    async def progress(self, stage: str, note: str = ""):
        self.steps.append((stage, note))


class FakeSite:
    """Сайт-пустышка: помнит всё, что воркер прислал."""

    def __init__(self, context: dict | None = None, accept: bool = True):
        self.context = context or {}
        self.accept = accept
        self.posts: list[tuple[str, dict]] = []

    async def post_json(self, prefix, path, data, json_body=False, timeout=60.0,
                        retries=2, base=None):
        self.posts.append((path, data))

        if path == "/context":
            if not self.context:
                return False, "нет контекста", {"ok": False, "error": "нет контекста"}

            return True, "", dict(self.context)

        if not self.accept:
            return False, "сайт не принял", {"ok": False, "error": "сайт не принял"}

        return True, "", {"ok": True}

    def of(self, path: str) -> list[dict]:
        return [data for p, data in self.posts if p == path]


GRAPH = {
    "meta": {"title": "Трёхколёсный велосипед", "units": "unit"},
    "nodes": [
        {"id": "n1", "type": "hub", "x": 0, "y": 0, "z": 0, "label": "Рама"},
        {"id": "n2", "type": "endpoint", "x": -6, "y": -8, "z": 4, "label": "Заднее колесо левое"},
        {"id": "n3", "type": "endpoint", "x": 6, "y": -8, "z": 4, "label": "Заднее колесо правое"},
        {"id": "n6", "type": "endpoint", "x": 0, "y": 8, "z": -5, "label": "Руль"},
    ],
    "edges": [
        {"id": "e1", "source": "n1", "target": "n2", "type": "structural", "label": "ось"},
        {"id": "e2", "source": "n1", "target": "n3", "type": "structural", "label": "ось"},
        {"id": "e5", "source": "n1", "target": "n6", "type": "hinge", "label": "колонка"},
    ],
}

CONTEXT = {
    "ok": True,
    "project_id": 7,
    "code": "ab34cd56ef78",
    "title": "Трёхколёсный велосипед",
    "summary": "Три колеса, рама, руль, сиденье и корзина.",
    "tags": "велосипед",
    "mode": "generate",
    "prompt": "",
    "node_ids": [],
    "graph": GRAPH,
    "text": "Рама, колесо, руль.",
    "isolated": 0,
    "limits": {"max_nodes": 500, "max_edges": 1200, "max_prompt": 1000},
    "canvas": {"radius": 24, "snap": 0.5, "snap_px": 8},
    "node_types": ["hub", "connector", "endpoint", "panel"],
    "edge_types": ["rigid", "structural", "hinge", "soft"],
    "examples": [],
}


@pytest.fixture()
def site(monkeypatch) -> FakeSite:
    box = FakeSite(context=dict(CONTEXT))

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)

    return box


def graph_reply(nodes: int = 4, edges: int | None = None) -> str:
    """Ответ модели: ровно такой JSON, какой мы ждём."""
    out = {
        "meta": {"title": "Велосипед", "units": "unit"},
        "nodes": [
            {"id": "n%d" % (i + 1), "type": "connector", "x": i, "y": i, "z": 0,
             "label": "Деталь %d" % (i + 1), "data": {}}
            for i in range(nodes)
        ],
        "edges": [
            {"id": "e%d" % (i + 1), "source": "n%d" % (i + 1), "target": "n%d" % (i + 2),
             "type": "rigid", "label": "связь", "style": "line"}
            for i in range(nodes - 1 if edges is None else edges)
        ],
    }

    return json.dumps(out, ensure_ascii=False)


# --------------------------------------------------------------------------
# Чистые helpers
# --------------------------------------------------------------------------

def test_clean_strips_markdown_fence():
    """Модель регулярно оборачивает ответ в ```json — это надо пережить."""
    raw = "```json\n" + graph_reply(3) + "\n```"

    data = mod._parse_graph(raw)

    assert len(data["nodes"]) == 3


def test_clean_strips_preamble():
    raw = "Вот граф, который вы просили:\n\n" + graph_reply(3) + "\n\nНадеюсь, помогло!"

    data = mod._parse_graph(raw)

    assert len(data["nodes"]) == 3, "до и после JSON может быть любой текст"


def test_empty_and_broken_replies():
    for raw in ("", "   ", "ни одного узла", "{", "{\"nodes\": []}"):
        with pytest.raises(ValueError):
            mod._as_graph(mod._parse_graph(raw))


def test_compact_cuts_graph_to_size():
    """Тысяча узлов в подсказку не едет: модель их всё равно не удержит."""
    nodes = [{"id": "n%d" % i, "type": "connector", "x": i, "y": 0, "z": 0,
              "label": "деталь", "data": {"w": "x" * 200}} for i in range(600)]
    edges = [{"id": "e%d" % i, "source": "n%d" % i, "target": "n%d" % (i + 1)}
             for i in range(599)]

    part = mod._compact(nodes, edges, limit=20000)

    assert len(json.dumps(part, ensure_ascii=False)) <= 20000, "подсказка обрезана по бюджету"
    assert part["nodes"], "что-то да осталось"

    for edge in part["edges"]:
        ids = {n["id"] for n in part["nodes"]}
        assert edge["source"] in ids and edge["target"] in ids, "связей без концов не бывает"


def test_space_rules_mention_axes_and_limits():
    block = mod._space_block({"canvas": {"radius": 24}})

    # Пороги заданы относительно R (модель считает их сама), а не числами:
    # при другом радиусе сцены правила не должны переписываться.
    assert "Y" in block and "-R/3" in block and "R/3" in block, "верх и низ заданы правилом"
    assert "R = 24.0" in block, "радиус сцены подставлен"
    assert "СИММЕТРИЯ" in block, "про симметрию модель забывает первой"


# --------------------------------------------------------------------------
# nj667.generate
# --------------------------------------------------------------------------

@pytest.mark.asyncio()
async def test_generate_sends_rules_and_takes_graph(site, monkeypatch):
    llm = FakeLLM(replies=[graph_reply(4)])
    ctx = FakeCtx(llm=llm)

    result = await mod.handle(ctx, {"project_id": 7, "prompt": "трёхколёсный велосипед"})

    assert result["nodes"] == 4 and result["edges"] == 3

    sent = site.of("/graph")
    assert len(sent) == 1, "граф отправлен ровно один раз"
    assert sent[0]["merge"] is False, "сборка заменяет граф, а не приклеивается"
    assert sent[0]["author_kind"] == "ai"
    assert sent[0]["project_id"] == 7
    assert sent[0]["label"].startswith("правка:"), "правка существующего — не новая сборка"

    assert "500" in llm.system, "лимит узлов доехал до модели"
    assert "трёхколёсный велосипед" in llm.user, "просьба пользователя в подсказке"
    assert "ТЕКУЩИЙ граф" in llm.user, "модель видит уже собранное изделие"
    assert "n1" in llm.user, "узлы текущего графа уехали в подсказку"


@pytest.mark.asyncio()
async def test_simple_pipeline_prompt_targets_buildable_stick_graph(site, monkeypatch):
    """Одношаговый режим просит небольшой связный граф для реальной сборки."""
    context = dict(CONTEXT)
    context["graph"] = {"meta": {}, "nodes": [], "edges": []}
    box = FakeSite(context=context)

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)
    llm = FakeLLM(replies=[graph_reply(4)])

    result = await mod.handle(FakeCtx(llm=llm), {
        "project_id": 7,
        "prompt": "небольшая кормушка для птиц",
        "simple_pipeline": True,
    })

    assert result["nodes"] == 4
    assert "плоских палочек" in llm.user
    assert "6–18 узлов" in llm.user
    assert "треугольные раскосы" in llm.user


@pytest.mark.asyncio()
async def test_generate_retries_bad_json(site, monkeypatch):
    """Первый ответ — мусор, второй годный: переспрашиваем, а не падаем."""
    llm = FakeLLM(replies=["не сегодня", graph_reply(3)])
    ctx = FakeCtx(llm=llm)

    result = await mod.handle(ctx, {"project_id": 7, "prompt": "стул"})

    assert llm.calls == 2, "вторая попытка была"
    assert result["nodes"] == 3


@pytest.mark.asyncio()
async def test_generate_fails_loudly_when_site_rejects(monkeypatch):
    box = FakeSite(context=dict(CONTEXT), accept=False)

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)

    llm = FakeLLM(replies=[graph_reply(3)])

    with pytest.raises(RuntimeError) as exc:
        await mod.handle(FakeCtx(llm=llm), {"project_id": 7, "prompt": "стул"})

    assert "сайт не принял" in str(exc.value), "причина отказа видна в ошибке задачи"


@pytest.mark.asyncio()
async def test_generate_without_prompt(site, monkeypatch):
    with pytest.raises(ValueError, match="нет ни описания"):
        await mod.handle(FakeCtx(llm=FakeLLM()), {"project_id": 7, "prompt": "   "})

    with pytest.raises(ValueError, match="project_id"):
        await mod.handle(FakeCtx(llm=FakeLLM()), {"prompt": "стул"})


@pytest.mark.asyncio()
async def test_generate_photos_without_prompt(site, monkeypatch):
    """Фото без текста: в модель уезжает image_url, не base64 в payload задачи."""
    context = dict(CONTEXT)
    context["graph"] = {"meta": {}, "nodes": [], "edges": []}
    context["photos"] = [{"b64": "aaa", "media_type": "image/jpeg"}]
    box = FakeSite(context=context)

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)

    llm = FakeLLM(replies=[graph_reply(3)])
    result = await mod.handle(FakeCtx(llm=llm), {"project_id": 7, "prompt": ""})

    assert result["nodes"] == 3
    assert llm.has_image, "кадр доехал до модели как image_url"
    assert "по фото" in llm.user
    sent = box.of("/graph")
    assert sent and sent[0]["label"] == "по фото"


@pytest.mark.asyncio()
async def test_few_shot_examples_get_into_prompt(site, monkeypatch):
    """RAG: похожие графы из каталога доезжают до модели образцом."""
    context = dict(CONTEXT)
    context["graph"] = {"meta": {}, "nodes": [], "edges": []}
    context["examples"] = [{
        "title": "Стул",
        "summary": "Сиденье на четырёх ножках.",
        "why": "topology",
        "graph": {"nodes": [{"id": "n1", "type": "panel", "x": 0, "y": 0, "z": 0,
                             "label": "Сиденье"}],
                  "edges": []},
    }]

    box = FakeSite(context=context)

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)

    llm = FakeLLM(replies=[graph_reply(4)])

    await mod.handle(FakeCtx(llm=llm), {"project_id": 7, "prompt": "стул"})

    assert "Сиденье" in llm.user, "пример из каталога уехал в подсказку"
    assert "ОБРАЗЕЦ" in llm.user
    assert "топологи" in llm.user, "модель видит, что образец — та же конструкция"


@pytest.mark.asyncio()
async def test_generate_edits_current_graph_not_catalog(site, monkeypatch):
    """Правка промптом: текущий граф, не первый стул из каталога."""
    context = dict(CONTEXT)
    context["examples"] = [{
        "title": "Стул",
        "summary": "Сиденье на четырёх ножках.",
        "why": "text",
        "graph": {"nodes": [{"id": "chair1", "type": "connector", "x": 0, "y": 0, "z": 0,
                             "label": "Сиденье"}],
                  "edges": []},
    }]

    box = FakeSite(context=context)

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)

    llm = FakeLLM(replies=[graph_reply(4)])

    await mod.handle(FakeCtx(llm=llm), {"project_id": 7, "prompt": "добавь антенну"})

    assert "ТЕКУЩИЙ граф" in llm.user
    assert "добавь антенну" in llm.user
    assert "n1" in llm.user, "текущие узлы уехали в подсказку"
    assert "chair1" not in llm.user, "каталожный стул не подменяет изделие"
    assert "Сиденье" not in llm.user
    sent = box.of("/graph")
    assert sent and sent[0]["label"].startswith("правка:")


@pytest.mark.asyncio()
async def test_generate_skips_few_shot_when_rag_off(site, monkeypatch):
    """Выключенный RAG: каталожный стул не едет в подсказку даже на пустом проекте."""
    context = dict(CONTEXT)
    context["graph"] = {"meta": {}, "nodes": [], "edges": []}
    context["examples"] = [{
        "title": "Стул",
        "summary": "Сиденье на четырёх ножках.",
        "why": "topology",
        "graph": {"nodes": [{"id": "n1", "type": "panel", "x": 0, "y": 0, "z": 0,
                             "label": "Сиденье"}],
                  "edges": []},
    }]

    box = FakeSite(context=context)

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)

    llm = FakeLLM(replies=[graph_reply(4)])

    await mod.handle(FakeCtx(llm=llm), {"project_id": 7, "prompt": "планетоход", "rag": False})

    assert "Сиденье" not in llm.user
    assert "ОБРАЗЕЦ" not in llm.user
    assert "планетоход" in llm.user
    sent = box.of("/context")
    assert sent and sent[0].get("rag") is False


def test_photo_max_px_defaults_to_512(monkeypatch):
    monkeypatch.delenv("NJ667_PHOTO_MAX_PX", raising=False)
    monkeypatch.delenv("VLM_PHOTO_MAX_PX", raising=False)
    assert mod._photo_max_px({}) == 512
    assert mod._photo_max_px({"photo_max_px": 256}) == 256
    assert mod._photo_max_px({"photo_max_px": 9999}) == 2048


# --------------------------------------------------------------------------
# nj667.inpaint
# --------------------------------------------------------------------------

@pytest.mark.asyncio()
async def test_inpaint_merges_and_sends_neighborhood(site, monkeypatch):
    llm = FakeLLM(replies=[json.dumps({
        "nodes": [{"id": "n6", "type": "endpoint", "x": 0, "y": 9, "z": -7,
                   "label": "Руль-баран"},
                  {"id": "n9", "type": "endpoint", "x": -2, "y": 9, "z": -7,
                   "label": "Ручка левая"}],
        "edges": [{"id": "e8", "source": "n6", "target": "n9", "type": "rigid"}],
    }, ensure_ascii=False)])

    ctx = FakeCtx(llm=llm)

    result = await mod.handle_inpaint(
        ctx, {"project_id": 7, "prompt": "сделай спортивный руль-баран", "node_ids": ["n6"]})

    assert result["nodes"] == 2 and result["edges"] == 1

    sent = site.of("/graph")
    assert sent[0]["merge"] is True, "достройка приклеивается по id, а не заменяет проект"

    # В модель уехала окрестность выбранного узла: сайт должен был отдать её
    # по mode=inpaint, а мы — положить в подсказку.
    asked = site.of("/context")
    assert asked[0]["mode"] == "inpaint"
    assert asked[0]["node_ids"] == ["n6"]
    assert "Руль-баран" not in llm.user, "в подсказку едет ИСХОДНЫЙ граф, а не ответ"


@pytest.mark.asyncio()
async def test_inpaint_requires_selection_and_prompt(site, monkeypatch):
    with pytest.raises(ValueError, match="узел"):
        await mod.handle_inpaint(FakeCtx(llm=FakeLLM()),
                                 {"project_id": 7, "prompt": "сделай руль", "node_ids": []})

    with pytest.raises(ValueError, match="prompt"):
        await mod.handle_inpaint(FakeCtx(llm=FakeLLM()),
                                 {"project_id": 7, "prompt": "  ", "node_ids": ["n6"]})


@pytest.mark.asyncio()
async def test_inpaint_empty_answer_is_retried(site, monkeypatch):
    """Пустая достройка — не успех: модель обязана что-то вернуть."""
    llm = FakeLLM(replies=['{"nodes": [], "edges": []}',
                           json.dumps({"nodes": [{"id": "n9", "type": "endpoint",
                                                  "x": 1, "y": 1, "z": 1,
                                                  "label": "Фара"}], "edges": []})])
    ctx = FakeCtx(llm=llm)

    result = await mod.handle_inpaint(
        ctx, {"project_id": 7, "prompt": "добавь фару", "node_ids": ["n1"]})

    assert llm.calls == 2
    assert result["nodes"] == 1


# --------------------------------------------------------------------------
# nj667.summary / nj667.embed / nj667.moderate
# --------------------------------------------------------------------------

@pytest.mark.asyncio()
async def test_summary_delivered(site, monkeypatch):
    llm = FakeLLM(replies=["  Трёхколёсный велосипед: рама, три колеса, руль "
                           "и корзина над задней осью.   "])
    ctx = FakeCtx(llm=llm)

    result = await mod.handle_summary(ctx, {"project_id": 7})

    assert result["summary"].startswith("Трёхколёсный")
    assert site.of("/summary")[0]["project_id"] == 7


@pytest.mark.asyncio()
async def test_summary_passes_revision_id(site, monkeypatch):
    """Саммари заявки считает снимок ревизии, не живой граф."""
    llm = FakeLLM(replies=["Снимок ревизии: рама и три колеса."])
    ctx = FakeCtx(llm=llm)

    await mod.handle_summary(ctx, {"project_id": 7, "revision_id": 42})

    asked = site.of("/context")
    assert asked and asked[0].get("revision_id") == 42
    sent = site.of("/summary")
    assert sent and sent[0].get("revision_id") == 42


@pytest.mark.asyncio()
async def test_summary_empty_is_error(site, monkeypatch):
    with pytest.raises(ValueError, match="саммари"):
        await mod.handle_summary(FakeCtx(llm=FakeLLM(replies=["   "])), {"project_id": 7})


@pytest.mark.asyncio()
async def test_embed_sends_vector(site, monkeypatch):
    class FakeEmbed:
        model = "test-embed"

        @staticmethod
        def from_env():
            return FakeEmbed()

        async def embed_one(self, item):
            return [0.1, 0.2, 0.3]

    monkeypatch.setattr(mod, "MmEmbeddingClient", FakeEmbed)
    monkeypatch.setattr(mod.mmembed, "MmEmbeddingClient", FakeEmbed)

    result = await mod.handle_embed(FakeCtx(), {"project_id": 7})

    assert result["dims"] == 3

    sent = site.of("/embed")
    assert sent and sent[0]["vector"] == [0.1, 0.2, 0.3]
    assert "колеса" in sent[0]["text"], "вектор считался по саммари, а не по пустоте"


@pytest.mark.asyncio()
async def test_embed_without_model_configured(site, monkeypatch):
    class NoEmbed:
        @staticmethod
        def from_env():
            return None

    monkeypatch.setattr(mod, "MmEmbeddingClient", NoEmbed)
    monkeypatch.setattr(mod.mmembed, "MmEmbeddingClient", NoEmbed)

    with pytest.raises(RuntimeError, match="EMBEDDING"):
        await mod.handle_embed(FakeCtx(), {"project_id": 7})


@pytest.mark.asyncio()
async def test_moderate_verdict_and_reason(site, monkeypatch):
    llm = FakeLLM(replies=[json.dumps({
        "verdict": "reject", "score": 0.9,
        "reason": "в подписях узлов мат",
        "bad": ["колесо ***"],
    }, ensure_ascii=False)])

    result = await mod.handle_moderate(FakeCtx(llm=llm), {"project_id": 7})

    assert result["verdict"] == "reject"
    assert result["bad"] == 1

    sent = site.of("/moderation")
    assert sent[0]["verdict"] == "reject"
    assert sent[0]["bad"] == ["колесо ***"]


@pytest.mark.asyncio()
async def test_moderate_bad_json_becomes_review(site, monkeypatch):
    """Неразобранный ответ модели — повод посмотреть, а не вердикт."""
    llm = FakeLLM(replies=["не понял задачу"])

    result = await mod.handle_moderate(FakeCtx(llm=llm), {"project_id": 7})

    assert result["verdict"] == "review"
    assert site.of("/moderation")[0]["verdict"] == "review"


@pytest.mark.asyncio()
async def test_moderate_unknown_verdict_normalised(site, monkeypatch):
    llm = FakeLLM(replies=[json.dumps({"verdict": "maybe", "score": 5})])

    result = await mod.handle_moderate(FakeCtx(llm=llm), {"project_id": 7})

    assert result["verdict"] == "review"
    assert result["score"] == 1.0, "score режется по диапазону 0…1"


# --------------------------------------------------------------------------
# nj667.ideas
# --------------------------------------------------------------------------

def test_parse_ideas_json():
    raw = json.dumps({
        "ideas": ["Изделие %d с рамой, ножками и крышкой" % i for i in range(30)],
    }, ensure_ascii=False)
    out = mod._parse_ideas(raw, 30)
    assert len(out) == 30
    assert out[0].startswith("Изделие")


def test_parse_ideas_numbered_list():
    raw = "\n".join("%d. Садовый стул %d на четырёх ножках" % (i, i) for i in range(1, 12))
    out = mod._parse_ideas(raw, 30)
    assert len(out) >= 8


@pytest.mark.asyncio()
async def test_ideas_delivered(site, monkeypatch):
    ideas = ["Садовый стул номер %d на четырёх ножках со спинкой" % i for i in range(30)]
    llm = FakeLLM(replies=[json.dumps({"ideas": ideas}, ensure_ascii=False)])

    result = await mod.handle_ideas(FakeCtx(llm=llm), {"theme": "дачная мебель"})

    assert result["count"] == 30
    sent = site.of("/ideas")
    assert sent and sent[0]["theme"] == "дачная мебель"
    assert len(sent[0]["ideas"]) == 30


@pytest.mark.asyncio()
async def test_ideas_need_theme(site):
    with pytest.raises(ValueError, match="тема"):
        await mod.handle_ideas(FakeCtx(llm=FakeLLM()), {"theme": "  "})


# --------------------------------------------------------------------------
# nj667.caption и дешёвая модель
# --------------------------------------------------------------------------

def test_model_of_graph_prefers_nj667_model(monkeypatch):
    monkeypatch.setenv("NJ667_MODEL", "heavy::graph")
    monkeypatch.setenv("NJ667_LIGHT_MODEL", "cheap::text")
    assert mod._model_of({"model": "payload::x"}) == "heavy::graph"


def test_model_of_light_prefers_light_env(monkeypatch):
    monkeypatch.setenv("NJ667_MODEL", "heavy::graph")
    monkeypatch.setenv("NJ667_LIGHT_MODEL", "cheap::text")
    assert mod._model_of({"model": "payload::x"}, light=True) == "cheap::text"


def test_model_of_light_payload_before_main(monkeypatch):
    monkeypatch.setenv("NJ667_MODEL", "heavy::graph")
    monkeypatch.delenv("NJ667_LIGHT_MODEL", raising=False)
    assert mod._model_of({"model": "site::cheap"}, light=True) == "site::cheap"


def test_parse_caption_json():
    raw = json.dumps({
        "title": "Садовый стул",
        "summary": "Четыре ножки, сиденье и спинка.",
        "tags": "стул, сад, дерево",
    }, ensure_ascii=False)
    out = mod._parse_caption(raw)
    assert out["title"] == "Садовый стул"
    assert "ножк" in out["summary"]
    assert "стул" in out["tags"]


def test_parse_caption_tags_list():
    raw = json.dumps({
        "title": "Велосипед",
        "summary": "Рама, три колеса и корзина.",
        "tags": ["велосипед", "корзина"],
    }, ensure_ascii=False)
    out = mod._parse_caption(raw)
    assert out["tags"] == "велосипед, корзина"


def test_parse_caption_empty_is_error():
    with pytest.raises(ValueError, match="назван"):
        mod._parse_caption(json.dumps({"title": "", "summary": ""}))


@pytest.mark.asyncio()
async def test_caption_delivered(site, monkeypatch):
    llm = FakeLLM(replies=[json.dumps({
        "title": "Трёхколёсный велосипед",
        "summary": "Рама, три колеса, руль и корзина.",
        "tags": "велосипед, корзина",
    }, ensure_ascii=False)])

    result = await mod.handle_caption(FakeCtx(llm=llm), {"project_id": 7, "revision_id": 3})

    assert result["title"] == "Трёхколёсный велосипед"
    sent = site.of("/caption")
    assert sent and sent[0]["project_id"] == 7
    assert sent[0]["revision_id"] == 3
    assert sent[0]["summary"].startswith("Рама")


@pytest.mark.asyncio()
async def test_caption_need_project(site):
    with pytest.raises(ValueError, match="project_id"):
        await mod.handle_caption(FakeCtx(llm=FakeLLM()), {"project_id": 0})
