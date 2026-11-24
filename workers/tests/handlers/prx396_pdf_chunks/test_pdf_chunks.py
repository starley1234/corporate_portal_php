import json

import httpx
import pytest
from pypdf import PdfWriter

from prx_workers.base import HandlerContext, PermanentJobError
from prx_workers.handlers.prx396_pdf_chunks import handler


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

    monkeypatch.setenv("PRX396_PDF_CHUNKS_API_BASE", "http://php.test/prx396/worker")
    monkeypatch.setattr(handler, "provider_name", lambda: "tesseract")
    monkeypatch.setattr(handler, "tesseract_available", lambda: True)
    async def fake_probe(path, local, limits):
        return {"pages": 5, "text_layers": ["t1", "t2", "t3", "t4", "t5"]}
    monkeypatch.setattr(handler, "probe_pdf", fake_probe)

    real_ac = httpx.AsyncClient

    def fake_client(*args, **kwargs):
        kwargs["transport"] = httpx.MockTransport(transport)
        return real_ac(*args, **kwargs)

    monkeypatch.setattr(handler.httpx, "AsyncClient", fake_client)

    ctx = Ctx()
    result = await handler.handle(ctx, {"batch_id": 1})

    part_posts = [json.loads(c[1] and "{}") for c in calls if c[1].endswith("/part")]
    # extract part bodies from calls by reissuing? easier: capture separately
    assert result["batch_id"] == 1
    part_paths = [c[1] for c in calls if c[1].endswith("/part")]
    assert len(part_paths) == 2  # parts 2 and 3 only, part 1 already done
    assert ("extract_part", "2/3 pages=3-4") in ctx.events
    assert ("extract_part", "3/3 pages=5-5") in ctx.events


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

    monkeypatch.setenv("PRX396_PDF_CHUNKS_API_BASE", "http://php.test/prx396/worker")
    monkeypatch.setattr(handler, "provider_name", lambda: "tesseract")

    async def bad_probe(path, local, limits):
        raise PermanentJobError("encrypted_pdf")

    monkeypatch.setattr(handler, "probe_pdf", bad_probe)

    real_ac = httpx.AsyncClient
    monkeypatch.setattr(handler.httpx, "AsyncClient", lambda *a, **k: real_ac(*a, **{**k, "transport": httpx.MockTransport(transport)}))

    ctx = Ctx()
    with pytest.raises(PermanentJobError, match="encrypted_pdf"):
        await handler.handle(ctx, {"batch_id": 1})
    assert ("complete", {"error_code": "encrypted_pdf"}) in posted
