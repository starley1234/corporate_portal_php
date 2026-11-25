import json

import httpx
import pytest
from pypdf import PdfWriter

from prx_workers.base import HandlerContext, PermanentJobError
from prx_workers.handlers.prx396_pdf_chunks import handler
from prx_workers.ocr.errors import DocumentJobError


class Ctx:
    def __init__(self):
        self.config = type("C", (), {"api_token": "tok"})()
        self._current_job = {"id": 7, "attempts": 1}
        self.events = []

    async def progress(self, phase, note=""):
        self.events.append((phase, note))


@pytest.fixture
def pdf_path(tmp_path):
    path = tmp_path / "doc.pdf"
    w = PdfWriter()
    for _ in range(5):
        w.add_blank_page(width=200, height=200)
    with path.open("wb") as fh:
        w.write(fh)
    return path


def _pdf_bytes(path):
    return path.read_bytes()


async def _fake_probe(path, local, limits, text=None, page_from=None, page_to=None, **_kw):
    """Проба без слоёв — структура; со слоями — только страницы диапазона."""
    if text:
        return {"pages": 5, "text_layers": [f"t-{page_from}-{page_to}"] * (page_to - page_from + 1)}
    return {"pages": 5}


def _client(monkeypatch, transport):
    real_ac = httpx.AsyncClient

    def fake_client(*args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(transport)
        return real_ac(*args, **kwargs)

    monkeypatch.setattr(handler.httpx, "AsyncClient", fake_client)


@pytest.mark.asyncio
async def test_resume_processes_only_pending_parts(monkeypatch, pdf_path):
    calls = []

    def transport(request: httpx.Request):
        calls.append((request.method, request.url.path, request.headers.get("X-PRX-Worker-Attempt")))
        if request.url.path.endswith("/file"):
            return httpx.Response(200, content=_pdf_bytes(pdf_path), headers={"Content-Type": "application/pdf"})
        if request.url.path.endswith("/plan"):
            body = json.loads(request.content.decode() or "{}")
            # resume: plan already exists, return parts with one done
            return httpx.Response(200, json={
                "batch_id": 1, "status": "running", "pages_total": 5,
                "parts": [
                    {"part_no": 1, "page_from": 1, "page_to": 2, "status": "done"},
                    {"part_no": 2, "page_from": 3, "page_to": 4, "status": "pending"},
                    {"part_no": 3, "page_from": 5, "page_to": 5, "status": "failed"},
                ],
            })
        if request.url.path.endswith("/part"):
            body = json.loads(request.content.decode())
            return httpx.Response(200, json={"batch_id": 1, "part_no": body["part_no"], "parts_done": 2, "parts_total": 3, "batch_status": "running"})
        if request.url.path.endswith("/complete"):
            return httpx.Response(200, json={"batch_id": 1, "status": "done"})
        raise AssertionError(request.url)

    monkeypatch.setenv("PRX396_PDF_CHUNKS_API_BASE", "http://php.test/prx396_pdf_chunks/worker")
    monkeypatch.setattr(handler, "provider_name", lambda: "tesseract")
    monkeypatch.setattr(handler, "tesseract_available", lambda: True)
    monkeypatch.setattr(handler, "probe_pdf", _fake_probe)
    _client(monkeypatch, transport)

    ctx = Ctx()
    result = await handler.handle(ctx, {"batch_id": 1})

    assert result["batch_id"] == 1
    part_paths = [c[1] for c in calls if c[1].endswith("/part")]
    assert len(part_paths) == 2  # parts 2 and 3 only, part 1 already done
    assert ("extract_part", "2/3 pages=3-4") in ctx.events
    assert ("extract_part", "3/3 pages=5-5") in ctx.events


@pytest.mark.asyncio
async def test_short_last_part_processes_only_its_pages(monkeypatch, pdf_path):
    """Последняя часть короче chunk_pages: ровно её страницы, pages_processed = 1."""
    created = {"done": False}
    part_bodies = []

    def three_parts_plan():
        return {
            "batch_id": 1, "status": "running", "pages_total": 5,
            "parts": [
                {"part_no": 1, "page_from": 1, "page_to": 2, "status": "pending"},
                {"part_no": 2, "page_from": 3, "page_to": 4, "status": "pending"},
                {"part_no": 3, "page_from": 5, "page_to": 5, "status": "pending"},
            ],
        }

    def transport(request: httpx.Request):
        if request.url.path.endswith("/file"):
            return httpx.Response(200, content=_pdf_bytes(pdf_path), headers={"Content-Type": "application/pdf"})
        if request.url.path.endswith("/plan"):
            body = json.loads(request.content.decode() or "{}")
            if body == {}:
                if created["done"]:
                    return httpx.Response(200, json=three_parts_plan())
                return httpx.Response(400, json={"error": {"code": "invalid_plan"}})
            assert body == {"pages_total": 5}
            created["done"] = True
            return httpx.Response(200, json=three_parts_plan())
        if request.url.path.endswith("/part"):
            part_bodies.append(json.loads(request.content.decode()))
            body = part_bodies[-1]
            return httpx.Response(200, json={"batch_id": 1, "part_no": body["part_no"],
                                             "parts_done": body["part_no"], "parts_total": 3,
                                             "batch_status": "running" if body["part_no"] < 3 else "done"})
        if request.url.path.endswith("/complete"):
            return httpx.Response(200, json={"batch_id": 1, "status": "done"})
        raise AssertionError(request.url)

    monkeypatch.setenv("PRX396_PDF_CHUNKS_API_BASE", "http://php.test/prx396_pdf_chunks/worker")
    monkeypatch.setattr(handler, "provider_name", lambda: "tesseract")
    monkeypatch.setattr(handler, "tesseract_available", lambda: True)
    monkeypatch.setattr(handler, "probe_pdf", _fake_probe)
    _client(monkeypatch, transport)

    ctx = Ctx()
    result = await handler.handle(ctx, {"batch_id": 1})

    assert result == {"batch_id": 1, "pages_total": 5, "parts_total": 3}
    assert [b["part_no"] for b in part_bodies] == [1, 2, 3]
    short = part_bodies[2]
    assert short["pages_processed"] == 1          # 5-5, а не размер chunk
    assert short["text"] == "t-5-5"
    assert ("extract_part", "3/3 pages=5-5") in ctx.events


def _parts_plan():
    return {
        "batch_id": 1, "status": "running", "pages_total": 5,
        "parts": [
            {"part_no": 1, "page_from": 1, "page_to": 5, "status": "pending"},
        ],
    }


@pytest.mark.asyncio
async def test_lost_part_response_resends_part_without_duplicates(monkeypatch, pdf_path):
    """Ответ на /part потерян: повторная отправка той же части проходит,
    полный дубль не возникает — PHP отвечает part_ack и на повтор (идемпотентность)."""
    state = {"created": False, "part1_posts": 0, "completes": 0}

    def transport(request: httpx.Request):
        if request.url.path.endswith("/file"):
            return httpx.Response(200, content=_pdf_bytes(pdf_path), headers={"Content-Type": "application/pdf"})
        if request.url.path.endswith("/plan"):
            body = json.loads(request.content.decode() or "{}")
            if body == {}:
                if state["created"]:
                    return httpx.Response(200, json=_parts_plan())  # resume: часть не сохранена
                return httpx.Response(400, json={"error": {"code": "invalid_plan"}})
            state["created"] = True
            return httpx.Response(200, json=_parts_plan())
        if request.url.path.endswith("/part"):
            body = json.loads(request.content.decode())
            assert body["part_no"] == 1
            state["part1_posts"] += 1
            if state["part1_posts"] == 1:
                # сервер мог сохранить часть, а ответ потерялся — воркер не знает
                raise httpx.ConnectError("response lost", request=request)
            return httpx.Response(200, json={"batch_id": 1, "part_no": 1, "parts_done": 1,
                                             "parts_total": 1, "batch_status": "running"})
        if request.url.path.endswith("/complete"):
            state["completes"] += 1
            return httpx.Response(200, json={"batch_id": 1, "status": "done"})
        raise AssertionError(request.url)

    monkeypatch.setenv("PRX396_PDF_CHUNKS_API_BASE", "http://php.test/prx396_pdf_chunks/worker")
    monkeypatch.setattr(handler, "provider_name", lambda: "tesseract")
    monkeypatch.setattr(handler, "tesseract_available", lambda: True)
    monkeypatch.setattr(handler, "probe_pdf", _fake_probe)
    _client(monkeypatch, transport)

    with pytest.raises(DocumentJobError, match="worker_endpoint_unavailable"):
        await handler.handle(Ctx(), {"batch_id": 1})
    assert state == {"created": True, "part1_posts": 1, "completes": 0}

    result = await handler.handle(Ctx(), {"batch_id": 1})
    assert result["batch_id"] == 1
    # одна и та же часть ушла дважды (сбой ответа + повтор), complete — один
    assert state["part1_posts"] == 2
    assert state["completes"] == 1


@pytest.mark.asyncio
async def test_permanent_probe_error_reports_batch_failed(monkeypatch, pdf_path):
    posted = []

    def transport(request: httpx.Request):
        if request.url.path.endswith("/file"):
            return httpx.Response(200, content=_pdf_bytes(pdf_path), headers={"Content-Type": "application/pdf"})
        if request.url.path.endswith("/plan"):
            body = json.loads(request.content.decode() or "{}")
            if body == {}:
                return httpx.Response(400, json={"error": {"code": "invalid_plan"}})
            posted.append(("plan", body))
            return httpx.Response(200, json={"batch_id": 1, "status": "running", "pages_total": 5, "parts": []})
        if request.url.path.endswith("/complete"):
            posted.append(("complete", json.loads(request.content.decode())))
            return httpx.Response(200, json={"batch_id": 1, "status": "failed"})
        return httpx.Response(404)

    monkeypatch.setenv("PRX396_PDF_CHUNKS_API_BASE", "http://php.test/prx396_pdf_chunks/worker")
    monkeypatch.setattr(handler, "provider_name", lambda: "tesseract")

    async def bad_probe(*_args, **_kw):
        raise PermanentJobError("encrypted_pdf")

    monkeypatch.setattr(handler, "probe_pdf", bad_probe)
    _client(monkeypatch, transport)

    ctx = Ctx()
    with pytest.raises(PermanentJobError, match="encrypted_pdf"):
        await handler.handle(ctx, {"batch_id": 1})
    assert ("complete", {"error_code": "encrypted_pdf"}) in posted


@pytest.mark.asyncio
async def test_part_413_reports_result_too_large_not_endpoint(monkeypatch, pdf_path):
    """413 от /part (тело не влезло) — терминальный result_too_large, а не
    worker_endpoint_unavailable: batch валится через complete(error_code)."""
    posted = []

    def transport(request: httpx.Request):
        if request.url.path.endswith("/file"):
            return httpx.Response(200, content=_pdf_bytes(pdf_path), headers={"Content-Type": "application/pdf"})
        if request.url.path.endswith("/plan"):
            body = json.loads(request.content.decode() or "{}")
            if body == {}:
                return httpx.Response(400, json={"error": {"code": "invalid_plan"}})
            return httpx.Response(200, json=_parts_plan())
        if request.url.path.endswith("/part"):
            return httpx.Response(413, json={"error": {"code": "result_too_large"}})
        if request.url.path.endswith("/complete"):
            posted.append(json.loads(request.content.decode()))
            return httpx.Response(200, json={"batch_id": 1, "status": "failed"})
        raise AssertionError(request.url)

    monkeypatch.setenv("PRX396_PDF_CHUNKS_API_BASE", "http://php.test/prx396_pdf_chunks/worker")
    monkeypatch.setattr(handler, "provider_name", lambda: "tesseract")
    monkeypatch.setattr(handler, "tesseract_available", lambda: True)
    monkeypatch.setattr(handler, "probe_pdf", _fake_probe)
    _client(monkeypatch, transport)

    with pytest.raises(PermanentJobError, match="^result_too_large$"):
        await handler.handle(Ctx(), {"batch_id": 1})
    assert [{"error_code": "result_too_large"}] == posted
