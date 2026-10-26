"""nj671.bench: стенд эмбеддингов — материал, метрики и решение.

Что здесь доказывается:

  * материал корпуса цел (индексы ответов существуют, тексты не повторяются);
  * recall@1 / MRR / разделение считаются правильно — на «идеальном» пути
    единица, на «бессмысленном» ноль;
  * стенд НЕ подменяет адреса: пустой EMBEDDING_MM_API_URL — это «путь не
    настроен», а не «возьми EMBEDDING_API_URL» (иначе сравнивали бы сервер
    сам с собой);
  * решение объяснимо: при равном качестве побеждает быстрый путь, а картинки
    остаются за мультимодальным;
  * векторы не уезжают в отчёт.

Модели поддельные: качество настоящих эмбеддингов тестом не проверяется —
проверяется арифметика стенда и то, что он не врёт о конфигурации.
"""

from __future__ import annotations

import math

import pytest

from n2j_workers import embbench

#: Оси «смысла»: тема A, тема B, дальше — своя ось у каждого описания.
AX_A, AX_B = 0, 1
N_DOCS = len(embbench.CORPORA["catalog"]["docs"])
DIMS = 2 + N_DOCS
#: Оси для пробы «картинка ↔ слова». Это те же оси тем: пробе важно лишь то,
#: совпадает ли картинка со своей подписью.
AX_RED, AX_BLUE = AX_A, AX_B


def blind() -> list[float]:
    """Вектор «слепой» модели: картинка и подпись неразличимы по смыслу."""
    return [0.5 ** 0.5, 0.5 ** 0.5] + [0.0] * (DIMS - 2)


def axis(i: int) -> list[float]:
    vec = [0.0] * DIMS
    vec[i] = 1.0
    return vec


def unit(i: int) -> list[float]:
    """Своя ось описания: без неё все ответы одной темы неразличимы."""
    return axis(2 + i)


def _theme(i: int) -> int:
    """Первая половина каталога — тема A, вторая — тема B."""
    return AX_A if i < 7 else AX_B


def _doc(i: int, theme: int) -> list[float]:
    vec = axis(theme)

    for k in range(DIMS):
        vec[k] += 0.15 * unit(i)[k]

    return vec


def _mean(vectors: list[list[float]]) -> list[float]:
    return [sum(v[k] for v in vectors) / len(vectors) for k in range(DIMS)]


def good_map() -> dict[str, list[float]]:
    """Правильный путь: запрос смотрит в ответ, ответы одной темы рядом."""
    corpus = embbench.CORPORA["catalog"]
    docs = [_doc(i, _theme(i)) for i in range(len(corpus["docs"]))]
    mapping = {text: docs[i] for i, text in enumerate(corpus["docs"])}
    mapping.update({query: _mean([docs[i] for i in relevant])
                    for query, relevant in corpus["queries"]})

    return mapping


def bad_map() -> dict[str, list[float]]:
    """Бессмысленный путь: темы перепутаны местами.

    Запросы остаются «настоящими» (по правильным описаниям), а описания
    сдвинуты на чужую тему. Именно так выглядит модель, которая не различает
    смысл: вектор есть, поиск есть, ответа нет.
    """
    corpus = embbench.CORPORA["catalog"]
    good = [_doc(i, _theme(i)) for i in range(len(corpus["docs"]))]
    docs = [_doc(i, AX_B if _theme(i) == AX_A else AX_A)
            for i in range(len(corpus["docs"]))]
    mapping = {text: docs[i] for i, text in enumerate(corpus["docs"])}
    mapping.update({query: _mean([good[i] for i in relevant])
                    for query, relevant in corpus["queries"]})

    return mapping


def fake_engine(key: str, mapping: dict[str, list[float]], *,
                images: bool = False, image_map: dict[str, list[float]] | None = None,
                close_counter: list[int] | None = None) -> embbench.Engine:
    async def embed_texts(texts):
        return [mapping.get(t, blind()) for t in texts]

    async def embed_image(b64: str, media_type: str, caption: str):
        return (image_map or {}).get(b64, blind())

    async def close():
        if close_counter is not None:
            close_counter.append(1)

    return embbench.Engine(
        key=key,
        label=("Текстовый путь" if key == "text" else "Мультимодальный путь"),
        base_url=f"http://{key}.test/v1",
        model=f"{key}-model",
        expected_dims=DIMS,
        supports_images=images,
        embed_texts=embed_texts,
        embed_image=embed_image if images else None,
        close=close,
    )


# --------------------------------------------------------------------------
# Материал
# --------------------------------------------------------------------------

def test_corpus_is_consistent():
    corpus = embbench.CORPORA["catalog"]
    docs = corpus["docs"]

    assert len(docs) >= 10, "материала должно хватать хотя бы на дымовую пробу"
    assert len(set(docs)) == len(docs), "один и тот же текст дважды — не материал"

    queries = corpus["queries"]
    assert len(queries) >= 5
    assert len({q for q, _ in queries}) == len(queries), "запросы повторяются"

    for query, relevant in queries:
        assert query.strip()
        assert relevant, f"у запроса {query!r} нет ни одного ответа"
        assert all(0 <= i < len(docs) for i in relevant), (
            f"запрос {query!r} ссылается на несуществующее описание")


def test_php_form_offers_exactly_the_corpora_worker_knows():
    """Ключи наборов в форме /embedbench и в воркере — один и тот же список.

    Материал живёт в воркере (единственный источник правды), а сайт держит
    только ключи и подписи для выпадающего списка. Разъедутся — владелец
    выберет набор, которого нет, и получит ошибку уже из очереди.
    """
    from pathlib import Path
    import re

    config = (Path(__file__).resolve().parents[2]
              / "application/modules/nj671_embedbench/config/nj671_embedbench.php").read_text(encoding="utf-8")
    block = config.split("'corpora' => [", 1)[1].split("\n    ],", 1)[0]
    php_keys = set(re.findall(r"^\s*'([a-z0-9_]+)'\s*=>\s*\[", block, re.M))

    assert php_keys == set(embbench.CORPORA), (
        "форма обещает другие наборы, чем знает воркер: "
        f"сайт {sorted(php_keys)}, воркер {sorted(embbench.CORPORA)}"
    )


def test_every_query_has_a_plausible_answer():
    """Запросы не должны быть «ни о чём»: ответ обязан делиться словами с темой."""
    corpus = embbench.CORPORA["catalog"]

    for query, relevant in corpus["queries"]:
        words = {w.strip(".,").lower() for w in query.split() if len(w) > 3}
        text = corpus["docs"][relevant[0]].lower()
        assert any(w[:5] in text for w in words), (
            f"запрос {query!r} и его ответ не связаны словами — материал врёт")


# --------------------------------------------------------------------------
# Метрики
# --------------------------------------------------------------------------

def test_retrieval_separates_perfect_from_useless():
    corpus = embbench.CORPORA["catalog"]
    queries = [(q, list(rel)) for q, rel in corpus["queries"]]
    docs_good = [_doc(i, _theme(i)) for i in range(len(corpus["docs"]))]
    query_good = [_mean([docs_good[i] for i in rel]) for _, rel in queries]

    perfect = embbench.retrieval(docs_good, queries, query_good)

    assert perfect["recall1"] == 1.0
    assert perfect["recall3"] == 1.0
    assert perfect["mrr"] == 1.0
    # «Чужое» здесь — соседние услуги той же темы (трудные отрицательные):
    # большой отрыв не гарантирован даже идеальному пути.
    assert perfect["margin"] > 0.3, "своё должно быть заметно ближе чужого"

    docs_bad = [_doc(i, AX_B if _theme(i) == AX_A else AX_A)
                for i in range(len(corpus["docs"]))]

    useless = embbench.retrieval(docs_bad, queries, query_good)

    assert useless["recall1"] == 0.0
    assert useless["mrr"] < 0.2
    assert useless["margin"] <= 0


def test_retrieval_rows_explain_the_mistake():
    corpus = embbench.CORPORA["catalog"]
    queries = [(q, list(rel)) for q, rel in corpus["queries"]]
    docs = [_doc(i, _theme(i)) for i in range(len(corpus["docs"]))]
    query_vec = [_mean([docs[i] for i in rel]) for _, rel in queries]

    out = embbench.retrieval(docs, queries, query_vec)

    assert len(out["rows"]) == len(queries)
    assert out["rows"][0]["ok"] is True
    assert out["rows"][0]["rank"] == 1
    assert out["rows"][0]["query"] == queries[0][0]


def test_pairwise_and_pearson():
    vectors = [axis(AX_A), axis(AX_A), axis(AX_B), axis(AX_B)]

    pairs = embbench.pairwise(vectors)

    assert len(pairs) == 6
    assert pairs[0] == pytest.approx(1.0)      # A-A
    assert pairs[1] == pytest.approx(0.0)      # A-B

    assert embbench._pearson([1, 1, 0, 0], [1, 1, 0, 0]) == pytest.approx(1.0)
    assert embbench._pearson([1, 1, 0, 0], [0, 0, 1, 1]) == pytest.approx(-1.0)
    assert embbench._pearson([1.0], [1.0]) is None, "на одном значении корреляции нет"


# --------------------------------------------------------------------------
# Решение
# --------------------------------------------------------------------------

def _side(**kwargs) -> dict:
    side = {
        "ok": True,
        "error": "",
        "dims": 1024,
        "repeat": 1.0,
        "latency": {"per_item_ms": 10.0},
        "quality": {"recall1": 0.8, "mrr": 0.85},
        "images": {"supported": False, "checked": False, "ok": None},
    }
    side.update(kwargs)

    return side


def test_decision_prefers_text_when_quality_is_equal_and_mm_is_slow():
    text = _side()
    mm = _side(supports_images=True, latency={"per_item_ms": 60.0},
               images={"supported": True, "checked": True, "ok": True, "margin": 0.2})

    out = embbench.decide(text, mm)

    assert out["winner"] == "both", "картинки остаются за мультимодальным путём"
    picks = {t["task"]: t["pick"] for t in out["tasks"]}
    assert picks["text_index"] == "text"
    assert picks["cross_modal"] == "mm"
    assert any(r["code"] == "text_faster" for r in out["reasons"])


def test_decision_moves_text_index_to_mm_when_quality_is_clearly_better():
    text = _side(quality={"recall1": 0.5, "mrr": 0.6})
    mm = _side(quality={"recall1": 0.9, "mrr": 0.92},
               images={"supported": True, "checked": True, "ok": True})

    out = embbench.decide(text, mm)

    picks = {t["task"]: t["pick"] for t in out["tasks"]}
    assert picks["text_index"] == "mm"
    assert out["winner"] == "mm"
    assert any(r["code"] == "mm_better" for r in out["reasons"])


def test_decision_reports_single_working_path():
    out = embbench.decide(_side(ok=False, error="HTTP 401"), _side())

    assert out["winner"] == "mm"
    assert out["reasons"][0]["code"] == "only_one"
    assert "HTTP 401" in out["reasons"][0]["error"]


def test_decision_reports_both_off():
    out = embbench.decide(_side(ok=False, error="нет адреса"),
                          _side(ok=False, error="нет адреса"))

    assert out["winner"] == "none"
    assert out["reasons"][0]["code"] == "both_off"


# --------------------------------------------------------------------------
# Полный прогон
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_run_compares_two_paths(monkeypatch):
    closed: list[int] = []

    monkeypatch.setattr(embbench, "text_engine_from_env",
                        lambda: fake_engine("text", good_map(), close_counter=closed))
    monkeypatch.setattr(embbench, "mm_engine_from_env",
                        lambda: fake_engine("mm", bad_map(), images=True,
                                            close_counter=closed))

    out = await embbench.run()

    text, mm = out["engines"]["text"], out["engines"]["mm"]

    assert text["ok"] and mm["ok"]
    assert text["quality"]["recall1"] == 1.0
    assert mm["quality"]["recall1"] == 0.0
    assert text["repeat"] == pytest.approx(1.0)
    assert text["dims"] == DIMS
    assert out["decision"]["winner"] == "mm"
    assert out["corpus"]["docs"] == len(embbench.CORPORA["catalog"]["docs"])  # 14
    assert closed, "клиенты обязаны закрываться даже при успехе"

    # Векторы не уезжают в отчёт: это jsonb в базе и мегабайты в очереди.
    assert "_docs_vec" not in text and "_docs_vec" not in mm
    assert "vectors" not in out


@pytest.mark.asyncio
async def test_run_quick_mode_shrinks_material(monkeypatch):
    monkeypatch.setattr(embbench, "text_engine_from_env",
                        lambda: fake_engine("text", good_map()))
    monkeypatch.setattr(embbench, "mm_engine_from_env", lambda: None)

    out = await embbench.run(quick=True)
    corpus = out["corpus"]

    assert corpus["quick"] is True
    assert corpus["docs"] == 6
    assert corpus["queries"] == 3
    assert out["engines"]["mm"]["ok"] is False
    assert "EMBEDDING_MM_API_URL" in out["engines"]["mm"]["error"]


@pytest.mark.asyncio
async def test_run_survives_one_broken_engine(monkeypatch):
    async def explode(texts):
        raise RuntimeError("HTTP 400: No models loaded")

    broken = fake_engine("mm", {})
    broken.embed_texts = explode

    monkeypatch.setattr(embbench, "text_engine_from_env",
                        lambda: fake_engine("text", good_map()))
    monkeypatch.setattr(embbench, "mm_engine_from_env", lambda: broken)

    out = await embbench.run()

    assert out["engines"]["text"]["ok"] is True
    assert out["engines"]["mm"]["ok"] is False
    assert "No models loaded" in out["engines"]["mm"]["error"]
    assert out["decision"]["winner"] == "text"


@pytest.mark.asyncio
async def test_progress_is_reported_per_engine(monkeypatch):
    steps: list[str] = []

    async def progress(phase, note=""):
        steps.append(phase)

    monkeypatch.setattr(embbench, "text_engine_from_env",
                        lambda: fake_engine("text", good_map()))
    monkeypatch.setattr(embbench, "mm_engine_from_env", lambda: None)

    await embbench.run(progress=progress)

    assert any("документы" in s for s in steps)
    assert any("запросы" in s for s in steps)


# --------------------------------------------------------------------------
# Никакой подмены адресов
# --------------------------------------------------------------------------

def _clear_embedding_env(monkeypatch):
    for name in ("EMBEDDING_API_URL", "EMBEDDING_API_KEY", "EMBEDDING_MODEL",
                 "EMBEDDING_DIMENSIONS", "EMBEDDING_BATCH_SIZE", "EMBEDDING_TIMEOUT",
                 "EMBEDDING_MM_API_URL", "EMBEDDING_MM_API_KEY", "EMBEDDING_MM_MODEL",
                 "EMBEDDING_MM_DIMENSIONS", "EMBEDDING_MM_DIALECT",
                 "EMBEDDING_MM_TIMEOUT", "EMBEDDING_MM_BATCH", "EMBEDDING_MM_FALLBACK",
                 "LM_API_TOKEN"):
        monkeypatch.delenv(name, raising=False)


def test_text_engine_requires_its_own_url(monkeypatch):
    _clear_embedding_env(monkeypatch)

    assert embbench.text_engine_from_env() is None

    monkeypatch.setenv("EMBEDDING_MM_API_URL", "http://mm.test/v1")
    assert embbench.text_engine_from_env() is None, (
        "мультимодальный адрес не должен становиться текстовым путём")


def test_mm_engine_requires_its_own_url(monkeypatch):
    _clear_embedding_env(monkeypatch)

    assert embbench.mm_engine_from_env() is None, (
        "без EMBEDDING_MM_API_URL путь не настроен — подставлять общий адрес нельзя")

    monkeypatch.setenv("EMBEDDING_API_URL", "http://text.test/v1")
    assert embbench.mm_engine_from_env() is None


def test_text_engine_reads_model_and_batch(monkeypatch):
    _clear_embedding_env(monkeypatch)
    monkeypatch.setenv("EMBEDDING_API_URL", "http://text.test/v1")
    monkeypatch.setenv("EMBEDDING_MODEL", "text-embedding-qwen3-embedding-0.6b")
    monkeypatch.setenv("EMBEDDING_DIMENSIONS", "1024")
    monkeypatch.setenv("EMBEDDING_BATCH_SIZE", "16")

    engine = embbench.text_engine_from_env()

    assert engine is not None
    assert engine.key == "text"
    assert engine.model.endswith("0.6b")
    assert engine.expected_dims == 1024
    assert engine.supports_images is False
    assert any("16" in note for note in engine.notes)


# --------------------------------------------------------------------------
# Картинка ↔ слова
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_image_probe_sees_the_difference():
    pytest.importorskip("PIL")

    red = embbench.draw_case("circle", (220, 38, 38))
    blue = embbench.draw_case("square", (37, 99, 235))

    mapping = {
        "красный круг на белом фоне": axis(AX_RED),
        "синий квадрат на белом фоне": axis(AX_BLUE),
    }

    engine = fake_engine("mm", mapping, images=True,
                         image_map={red: axis(AX_RED), blue: axis(AX_BLUE)})

    out = await embbench._image_probe(engine)

    assert out["checked"] is True
    assert out["ok"] is True
    assert out["margin"] == pytest.approx(1.0)


@pytest.mark.asyncio
async def test_image_probe_counts_a_blind_model_as_failure():
    pytest.importorskip("PIL")

    red = embbench.draw_case("circle", (220, 38, 38))
    blue = embbench.draw_case("square", (37, 99, 235))

    mapping = {
        "красный круг на белом фоне": axis(AX_RED),
        "синий квадрат на белом фоне": axis(AX_BLUE),
    }

    engine = fake_engine("mm", mapping, images=True,
                         image_map={red: blind(), blue: blind()})

    out = await embbench._image_probe(engine)

    assert out["checked"] is True
    assert out["ok"] is False
    assert out["margin"] <= 0


# --------------------------------------------------------------------------
# Отчёт для человека
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_handler_keeps_the_full_report_in_the_queue(monkeypatch):
    """В result задачи остаётся ПОЛНЫЙ отчёт, а не сводка.

    22.10.2026 сайт перезаписал доставленный отчёт коротким словарём из
    очереди (там не было `engines`) и показал владельцу «Ни один путь не
    ответил — причина не записана» при работающих серверах. Полный отчёт весит
    единицы килобайт (векторов в нём нет), поэтому именно он и должен лежать в
    задаче: тогда дожим восстанавливает результат, а не подменяет его.
    """
    import json

    from n2j_workers.handlers import nj671_embedbench

    async def fake_run(corpus_key="catalog", quick=False, progress=None):
        return {
            "corpus": {"key": corpus_key, "title": "Каталог"},
            "engines": {"text": {"ok": True}, "mm": {"ok": True}},
            "decision": {"winner": "both"},
            "agreement": 0.5,
        }

    sent = {}

    async def fake_deliver(payload):
        sent["payload"] = payload
        return True, ""

    monkeypatch.setattr(nj671_embedbench.embbench, "run", fake_run)
    monkeypatch.setattr(nj671_embedbench, "deliver", fake_deliver)

    out = await nj671_embedbench.handle(None, {"run_id": 5, "corpus": "catalog"})

    assert sent["payload"]["engines"], "на сайт обязан уйти полный отчёт"
    assert out["summary_only"] is False
    assert out["engines"]["text"]["ok"] is True, "дожим должен суметь восстановить отчёт"
    assert out["winner"] == "both" and out["text_ok"] and out["mm_ok"]
    assert len(json.dumps(out, ensure_ascii=False).encode()) < 64_000, (
        "в задачу не должны попасть векторы: отчёт — это цифры и строки запросов"
    )


@pytest.mark.asyncio
async def test_report_mentions_both_paths_and_decision(monkeypatch):
    monkeypatch.setattr(embbench, "text_engine_from_env",
                        lambda: fake_engine("text", good_map()))
    monkeypatch.setattr(embbench, "mm_engine_from_env", lambda: None)

    text = embbench.report(await embbench.run())

    assert "ТЕКСТОВЫЙ" in text and "МУЛЬТИМОДАЛЬНЫЙ" in text
    assert "РЕШЕНИЕ" in text
    assert "EMBEDDING_MM_API_URL" in text, "у выключенного пути должна быть причина"
    assert math.isfinite(1.0)      # отчёт не зависит от случайности


# --------------------------------------------------------------------------
# Диагностика отказа: тело ответа, шаг, пачка, ключ
# --------------------------------------------------------------------------

class _ServerSaid(Exception):
    """Подделка EmbeddingServerError: важно лишь то, что видит _embed."""

    def __init__(self, status: int, body: str):
        self.status = status
        self.body = body
        super().__init__(f"Сервер эмбеддингов ответил HTTP {status}. Ответ сервера: {body}")


def _one_shot_engine(fail_batch: bool, fail_single: bool = False):
    calls = {"batch": 0, "single": 0}

    async def embed_texts(texts):
        calls["batch"] += 1
        if fail_batch and len(texts) > 1:
            raise _ServerSaid(400, '{"message":"batch size 14 > max_batch_size 8"}')
        return [blind() for _ in texts]

    async def embed_texts_one(text):
        calls["single"] += 1
        if fail_single:
            raise _ServerSaid(400, '{"message":"model not found: qwen3-vl-embedding-2b"}')
        return blind()

    engine = embbench.Engine(
        key="text", label="Текстовый путь", base_url="http://text.test/v1",
        model="qwen3-vl-embedding-2b", embed_texts=embed_texts,
        embed_texts_one=embed_texts_one,
    )

    return engine, calls


@pytest.mark.asyncio
async def test_server_refusing_a_batch_falls_back_to_single_inputs():
    """«batch too large» не должен обнулять сравнение.

    Владелец 22.10.2026 получил HTTP 400 на 14 текстах. Причину («слишком
    большая пачка» или «модель не найдена») не различить по коду, поэтому стенд
    повторяет те же входы по одному: если так работает, он всё равно меряет, а
    в отчёт кладёт вывод «поставьте EMBEDDING_BATCH_SIZE=1».
    """
    engine, calls = _one_shot_engine(fail_batch=True)
    corpus = {"docs": ["a", "b", "c"], "queries": [("a", [0])]}

    side = await embbench._measure(engine, corpus, with_images=False)

    assert side["ok"] is True, side["error"]
    assert calls["single"] >= 3
    assert any("EMBEDDING_BATCH_SIZE=1" in note for note in side["notes"]), side["notes"]


@pytest.mark.asyncio
async def test_real_refusal_keeps_the_server_words_and_the_step():
    """Если не работает и по одному — причина остаётся дословной и назван шаг."""
    engine, _ = _one_shot_engine(fail_batch=True, fail_single=True)
    corpus = {"docs": ["a", "b", "c"], "queries": [("a", [0])]}

    side = await embbench._measure(engine, corpus, with_images=False)

    assert side["ok"] is False
    assert side["error"].startswith("документы:"), side["error"]
    assert "model not found" in side["error"], "тело ответа сервера терять нельзя"


@pytest.mark.asyncio
async def test_server_words_survive_auth_errors_without_single_retries():
    """401 — не повод долбить сервер по одному входу: причина и так ясна."""
    calls = {"single": 0}

    async def embed_texts(texts):
        raise _ServerSaid(401, '{"message":"invalid api key"}')

    async def embed_texts_one(text):
        calls["single"] += 1
        return blind()

    engine = embbench.Engine(
        key="text", label="Текстовый путь", base_url="http://text.test/v1",
        model="m", embed_texts=embed_texts, embed_texts_one=embed_texts_one)

    side = await embbench._measure(engine, {"docs": ["a", "b"], "queries": [("a", [0])]},
                                   with_images=False)

    assert side["ok"] is False
    assert calls["single"] == 0, "на 401 одиночные повторы только жгут время"
    assert "invalid api key" in side["error"]


def test_passport_says_whether_the_key_was_sent(monkeypatch):
    """«А ключ вы использовали?» — ответ должен быть в самом отчёте.

    Токен никогда не печатаем: только факт отправки и имя переменной.
    """
    for name in ("EMBEDDING_API_KEY", "EMBEDDING_API_TOKEN", "EMBEDDING_TOKEN",
                 "LM_API_TOKEN", "EMBEDDING_MM_API_URL"):
        monkeypatch.delenv(name, raising=False)

    monkeypatch.setenv("EMBEDDING_API_URL", "https://llm.example/v1")
    monkeypatch.setenv("EMBEDDING_MODEL", "qwen3-vl-embedding-2b")

    engine = embbench.text_engine_from_env()

    assert engine is not None
    assert any("токен НЕ отправляется" in note for note in engine.notes), engine.notes

    monkeypatch.setenv("EMBEDDING_API_KEY", "secret-token-value")
    engine = embbench.text_engine_from_env()

    assert any("EMBEDDING_API_KEY" in note for note in engine.notes)
    assert not any("secret-token-value" in note for note in engine.notes), (
        "значение токена не должно попадать в отчёт")


@pytest.mark.asyncio
async def test_missing_model_is_caught_before_any_request():
    """Имя модели сверяется со списком сервера ДО запросов.

    Живой случай 22.10.2026: на llm.toolforge.ru (LM Studio) в
    `EMBEDDING_MODEL` попало имя мультимодальной модели. Сервер ответил «No
    models loaded», стенд показал HTTP 400, и владелец пошёл проверять ключ.
    Один GET /models отвечает на этот вопрос заранее — и без единого запроса
    к /embeddings.
    """
    calls = {"embed": 0}

    async def embed_texts(texts):
        calls["embed"] += 1
        raise AssertionError("до сверки имени запросов быть не должно")

    async def models():
        return ["text-embedding-qwen3-embedding-0.6b"]

    engine = embbench.Engine(
        key="text", label="Текстовый путь", base_url="http://lm.test/v1",
        model="qwen3-vl-embedding-2b", embed_texts=embed_texts, probe_models=models)

    side = await embbench._measure(engine, {"docs": ["a"], "queries": [("a", [0])]},
                                   with_images=False)

    assert calls["embed"] == 0
    assert side["ok"] is False
    assert "text-embedding-qwen3-embedding-0.6b" in side["error"], side["error"]
    assert "EMBEDDING_MODEL=" in side["error"], "нужна готовая строка для .env"
    assert "EMBEDDING_MM_API_URL" in side["error"], "VL-модель живёт на другом пути"


@pytest.mark.asyncio
async def test_same_model_under_another_name_is_fixed_and_measured():
    """Очевидное расхождение в имени — не повод валить сравнение.

    Такая же починка уже работает в мультимодальном клиенте: имя из .env
    сверяется со списком, и «кто-то/модель» приводится к имени сервера.
    """
    seen = {}

    async def embed_texts(texts):
        return [blind() for _ in texts]

    async def models():
        return ["Qwen/Qwen3-Embedding-0.6B"]

    engine = embbench.Engine(
        key="text", label="Текстовый путь", base_url="http://lm.test/v1",
        model="qwen3-embedding-0.6b", embed_texts=embed_texts, probe_models=models)

    side = await embbench._measure(engine, {"docs": ["a"], "queries": [("a", [0])]},
                                   with_images=False)

    assert side["ok"] is True, side["error"]
    assert engine.model == "Qwen/Qwen3-Embedding-0.6B"
    assert any("поправьте EMBEDDING_MODEL" in note for note in side["notes"]), side["notes"]



@pytest.mark.asyncio
async def test_same_model_on_both_paths_is_flagged(monkeypatch):
    """Одна модель на двух путях — не сравнение подходов, и это надо сказать.

    Владелец 22.10.2026 поставил `EMBEDDING_MODEL=qwen3-vl-embedding-2b` — имя
    мультимодальной модели — на текстовый путь. Стенд обязан заметить, что
    сравнивает одну и ту же модель сама с собой.
    """
    text = fake_engine("text", good_map())
    mm = fake_engine("mm", good_map(), images=True)
    text.model = mm.model = "qwen3-vl-embedding-2b"

    monkeypatch.setattr(embbench, "text_engine_from_env", lambda: text)
    monkeypatch.setattr(embbench, "mm_engine_from_env", lambda: mm)

    result = await embbench.run(quick=True)

    joined = " | ".join(result["engines"]["text"]["notes"])
    assert "одну модель" in joined and "EMBEDDING_MODEL" in joined, joined


def test_rejected_request_is_not_retried():
    """400 «model not found» не лечится повтором — задача не должна крутиться.

    Иначе воркер дважды отправляет заведомо неверный запрос, а в журнале
    провайдера появляется пара ошибок вместо одной понятной.
    """
    from n2j_workers.llm import EmbeddingServerError

    error = EmbeddingServerError(base_url="https://h/v1", model="m", status=400,
                                 body='{"message":"model not found"}')

    assert error.status in EmbeddingServerError.FATAL
    assert "model not found" in str(error), "тело ответа обязано попасть в сообщение"
    assert "это не про токен" in str(error), "подсказка снимает вопрос про ключ"
    assert "GET https://h/v1/models" in str(error)
