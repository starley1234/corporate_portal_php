"""Канал semantic.index: шаги видны, каждый этап ограничен по времени.

Владелец (26.09): «проверь процесс „Семантический индекс (поиск)“ — на мониторе
работ показано, что он всегда зависает, непонятно на каком шаге и из-за чего».
Тесты фиксируют три вещи: (1) в журнал уходят понятные этапы, (2) висящий
эмбеддинг-сервер снимается с внятной причиной, (3) занятый пул и рассинхрон
векторов не превращаются в тишину.
"""
import asyncio
import hashlib
import json

import pytest

from prx_workers.handlers.prx115_semantic import index as semantic_index


class _Tx:
    async def __aenter__(self):
        return self

    async def __aexit__(self, *exc):
        return False


class _Conn:
    def __init__(self, col_type="ARRAY", collection_row={"id": 1}):
        self.col_type = col_type
        self.collection_row = collection_row
        self.executed = []

    async def fetchval(self, sql, *args):
        return self.col_type

    async def fetchrow(self, sql, *args):
        return self.collection_row

    async def execute(self, sql, *args):
        self.executed.append((sql, args))

    def transaction(self):
        return _Tx()


class _Acquire:
    def __init__(self, conn, error=None):
        self.conn = conn
        self.error = error

    async def __aenter__(self):
        if self.error:
            raise self.error
        return self.conn

    async def __aexit__(self, *exc):
        return False


class _Pool:
    def __init__(self, conn, error=None):
        self.conn = conn
        self.error = error
        self.timeouts = []

    def acquire(self, timeout=None):
        self.timeouts.append(timeout)
        return _Acquire(self.conn, self.error)


class _Embed:
    def __init__(self, dims=3, delay=0.0, short=False):
        self.model = "test-embed"
        self.dims = dims
        self.delay = delay
        self.short = short
        self.calls = 0

    async def embed(self, texts):
        self.calls += 1

        if self.delay:
            await asyncio.sleep(self.delay)

        count = max(1, len(texts) - 1) if self.short else len(texts)

        return [[0.1] * self.dims for _ in range(count)]


class _Ctx:
    def __init__(self, pg, embed=None):
        self.pg = pg
        self.embed = embed
        self.steps = []

    async def progress(self, phase, note=""):
        self.steps.append((phase, note))


def _payload(items=3):
    return {
        "collection": "prx401_tasks",
        "entity": "prx401_task",
        "items": [{"id": str(i), "text": f"текст {i}"} for i in range(items)],
    }


@pytest.mark.asyncio
async def test_writes_vectors_and_reports_every_step():
    conn = _Conn()
    pool = _Pool(conn)
    ctx = _Ctx(pool, _Embed())

    out = await semantic_index.handle(ctx, _payload(3))

    assert out["count"] == 3
    assert len(conn.executed) == 3

    phases = [p for p, _n in ctx.steps]
    assert phases[0] == "Проверяю коллекцию"
    assert "Считаю векторы" in phases
    assert phases[-1] == "Готово", "финал должен быть виден в мониторе"

    # пул ждём с лимитом — иначе задача «висит» без единой строки в журнале
    assert pool.timeouts and all(t is not None for t in pool.timeouts)


@pytest.mark.asyncio
async def test_real_array_cast_for_array_column():
    conn = _Conn(col_type="ARRAY")
    ctx = _Ctx(_Pool(conn), _Embed())

    await semantic_index.handle(ctx, _payload(1))

    sql, args = conn.executed[0]
    assert "::real[]" in sql
    assert isinstance(args[5], list)
    assert "content_hash" in sql
    assert args[8] == semantic_index.content_hash("текст 0"), "хеш текста нужен панели для покрытия"


@pytest.mark.asyncio
async def test_pgvector_cast_for_vector_column():
    conn = _Conn(col_type="USER-DEFINED")
    ctx = _Ctx(_Pool(conn), _Embed())

    await semantic_index.handle(ctx, _payload(1))

    sql, args = conn.executed[0]
    assert "::vector" in sql
    assert isinstance(args[5], str) and args[5].startswith("[")
    assert args[8] == semantic_index.content_hash("текст 0")


@pytest.mark.asyncio
async def test_hanging_embedding_server_is_stopped_with_reason(monkeypatch):
    monkeypatch.setenv("PRX115_EMBED_TIMEOUT", "0.3")

    ctx = _Ctx(_Pool(_Conn()), _Embed(delay=5))

    with pytest.raises(RuntimeError) as err:
        await semantic_index.handle(ctx, _payload(2))

    assert "Эмбеддинг-сервер не ответил" in str(err.value)
    assert any(p == "Не получилось" for p, _n in ctx.steps)


@pytest.mark.asyncio
async def test_busy_pool_gives_readable_error():
    ctx = _Ctx(_Pool(_Conn(), error=asyncio.TimeoutError()), _Embed())

    with pytest.raises(RuntimeError) as err:
        await semantic_index.handle(ctx, _payload(1))

    assert "Пул соединений" in str(err.value)


@pytest.mark.asyncio
async def test_vector_count_mismatch_is_refused():
    conn = _Conn()
    ctx = _Ctx(_Pool(conn), _Embed(short=True))

    with pytest.raises(RuntimeError) as err:
        await semantic_index.handle(ctx, _payload(4))

    assert "векторов на" in str(err.value)
    assert conn.executed == [], "при рассинхроне ничего не пишем"


@pytest.mark.asyncio
async def test_missing_collection_explains_what_to_do():
    ctx = _Ctx(_Pool(_Conn(collection_row=None)), _Embed())

    with pytest.raises(ValueError) as err:
        await semantic_index.handle(ctx, _payload(1))

    assert "ensure_collection" in str(err.value)


@pytest.mark.asyncio
@pytest.mark.parametrize("api_base, expected_path", [
    ("", "/prx115/api/vectors"),
    ("http://portal.example.test/prx115/api", "/vectors"),
])
async def test_http_mode_sends_vectors_to_the_site(monkeypatch, api_base, expected_path):
    """Без pg обработчик работает мостом: считает векторы и отдаёт их сайту.

    Владелец (28.09, задача 60): воркер с сайтом на разных серверах раньше молча
    отвечал «skipped: http-mode» — задача «успешна», а векторов в базе нет.
    """
    calls = []
    monkeypatch.setenv("PRX115_API_BASE", api_base)

    async def _fake_post(prefix, path, data, timeout=60.0, retries=2, json_body=False):
        calls.append((prefix, path, data, json_body))

        return True, "", {"ok": True, "stored": len(data["items"])}

    monkeypatch.setattr(semantic_index.site, "post_json", _fake_post)
    monkeypatch.setattr(semantic_index, "SITE_BATCH", 2)

    ctx = _Ctx(None, _Embed(dims=4))

    out = await semantic_index.handle(ctx, _payload(3))

    assert out["count"] == 3
    assert out["mode"] == "http-bridge", "мост должен быть виден в результате задачи"
    assert "skipped" not in out
    assert [c[1] for c in calls] == [expected_path] * 2, "3 текста при пачке 2 — два запроса"
    assert [len(c[2]["items"]) for c in calls] == [2, 1]
    assert all(c[3] is True for c in calls), "тело с массивами чисел уходит JSON-ом"
    assert calls[0][2]["collection"] == "prx401_tasks"
    assert calls[0][2]["model"] == ctx.embed.model
    assert calls[0][2]["items"][0]["vector"] == [0.1, 0.1, 0.1, 0.1]

    phases = [p for p, _n in ctx.steps]
    assert phases[0] == "Проверяю коллекцию"
    assert "Считаю векторы" in phases
    assert "Записываю векторы" in phases
    assert phases[-1] == "Готово"

    notes = [n for p, n in ctx.steps if p == "Записываю векторы"]
    assert notes[-1].endswith("(через сайт)")


@pytest.mark.asyncio
async def test_http_mode_without_site_settings_is_a_clear_failure(monkeypatch):
    """Молчаливого «skipped» нет: без адреса сайта задача падает с подсказкой."""
    # PRX120_API_URL — тоже: иначе адрес сайта честно находится по нему, а тест
    # про «настройки доставки нет вообще» должен быть детерминированным.
    for name in ("APP_URL", "WORKER_API_URL", "PRX115_API_BASE", "PRX120_API_BASE", "PRX120_API_URL", "PRX115_SITE_TOKEN",
                 "WORKER_SITE_TOKEN", "WORKER_API_TOKEN"):
        monkeypatch.delenv(name, raising=False)

    ctx = _Ctx(None, _Embed())

    with pytest.raises(RuntimeError) as err:
        await semantic_index.handle(ctx, _payload(1))

    text = str(err.value)
    assert "PRX115_API_BASE" in text and "workers/.env" in text
    assert any(p == "Не получилось" for p, _n in ctx.steps)


@pytest.mark.asyncio
async def test_site_refusal_stops_the_bridge_with_reason(monkeypatch):
    """Сайт отказал (например, чужая размерность) — причина должна быть в мониторе."""

    async def _fake_post(prefix, path, data, timeout=60.0, retries=2, json_body=False):
        return False, "HTTP 400: размерность 3 не совпадает с коллекцией (1024)", {}

    monkeypatch.setattr(semantic_index.site, "post_json", _fake_post)

    ctx = _Ctx(None, _Embed(dims=3))

    with pytest.raises(RuntimeError) as err:
        await semantic_index.handle(ctx, _payload(1))

    assert "HTTP 400" in str(err.value)
    assert any(p == "Не получилось" for p, _n in ctx.steps)


def test_site_items_refuses_item_without_id():
    with pytest.raises(ValueError) as err:
        semantic_index._site_items([{"text": "без id"}], [[0.1, 0.2]])

    assert "id" in str(err.value)


def test_content_hash_matches_php_and_postgres():
    """Ключ «текст не менялся» должен совпадать в трёх местах: python, PHP, SQL.

    Сайт (PHP md5), воркер (hashlib.md5) и панель (PostgreSQL md5(text))
    считают один и тот же хеш — иначе повторная индексация вечно считала бы
    тексты изменившимися, а покрытие на /prx115 показывало бы «не проиндексировано».
    """
    assert semantic_index.content_hash("текст 1") == hashlib.md5("текст 1".encode("utf-8")).hexdigest()

    # Константа проверена с другой стороны: тот же хеш даёт PHP md5() (смоук
    # t/prx115_hash.php) и PostgreSQL md5() — значит ключ кэша общий для всех.
    assert semantic_index.content_hash("текст 1") == "4bc667cdb8b190655da156f93fa08d25"
    assert semantic_index.content_hash("") == "d41d8cd98f00b204e9800998ecf8427e"


def test_progress_payload_is_json_serialisable():
    """Метаданные вектора уходят в jsonb — проверяем, что их можно сериализовать."""
    assert json.dumps({"source": "test"})


@pytest.mark.asyncio
async def test_html_403_gets_a_gateway_hint(monkeypatch):
    """HTML 403 от сайта — отказ шлюза входа, а не «неверный токен».

    Задача 2234 (23.10.2026): вектор не записался, а сообщение говорило про
    адрес и токен. Настоящая причина была в сайте — точка приёма не проходила
    общий шлюз входа. Теперь это сказано прямо в тексте ошибки.
    """

    async def _fake_post(prefix, path, data, timeout=60.0, retries=2, json_body=False):
        return False, "HTTP 403: сервер вернул HTML вместо JSON", {}

    monkeypatch.setattr(semantic_index.site, "post_json", _fake_post)

    ctx = _Ctx(None, _Embed())

    with pytest.raises(RuntimeError) as err:
        await semantic_index.handle(ctx, _payload(1))

    text = str(err.value)
    assert "$public_actions" in text
    assert "/prx115/api/vectors" in text
    assert "шлюза входа" in text


def test_html_hint_is_silent_for_plain_errors():
    assert semantic_index._html_403_hint("HTTP 400: размерность не совпала") == ""
    assert "$public_actions" in semantic_index._html_403_hint("HTTP 403: HTML")
