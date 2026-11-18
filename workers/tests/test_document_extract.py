import httpx
import pytest
from pypdf import PdfWriter

from prx_workers import document_extract as ocr
from prx_workers.base import PermanentJobError


@pytest.fixture
def blank_pdf(tmp_path):
    path = tmp_path / "blank.pdf"
    writer = PdfWriter()
    writer.add_blank_page(width=595, height=842)
    writer.write(path)
    return path


@pytest.fixture
def remote(monkeypatch):
    for name in ("OCR_TIMEOUT", "OCR_EXTRA_PARAMS", "OCR_EXTRA_HEADERS", "OCR_AUTH_HEADER_NAME", "OCR_API_KEY"):
        monkeypatch.delenv(name, raising=False)
    monkeypatch.setenv("OCR_SERVICE_URL", "http://ocr.example/parse?lang=ru")


@pytest.mark.asyncio
async def test_remote_multipart_query_and_content_contract(blank_pdf, remote, monkeypatch):
    monkeypatch.setenv("OCR_EXTRA_PARAMS", '{"profile":"test","do_ocr":"false"}')
    monkeypatch.setenv("OCR_API_KEY", "test-only")

    def adapter(req):
        assert req.method == "POST" and req.url.path == "/parse"
        assert dict(req.url.params) == {"lang": "ru", "engine": "auto", "output_format": "md", "do_ocr": "true", "profile": "test"}
        assert req.headers["Authorization"] == "Bearer test-only"
        assert b'name="file"; filename="document.pdf"' in req.content
        assert blank_pdf.read_bytes() in req.content
        assert b'name="profile"' not in req.content
        return httpx.Response(200, json={"content": "# Письмо\n\nТекст"})

    result = await ocr.extract_remote(blank_pdf, 1, transport=httpx.MockTransport(adapter))
    assert result == {"text": "# Письмо\n\nТекст", "provider": "remote", "format": "markdown", "pages_total": 1,
                      "pages_processed": None, "warnings": []}


@pytest.mark.parametrize(("status", "body", "error", "kind"), [
    (200, {"text": "legacy"}, "ocr_invalid_response", PermanentJobError),
    (200, {"content": "   "}, "no_text", PermanentJobError),
    (200, {"content": "Текст", "pages": 0}, "ocr_incomplete", PermanentJobError),
    (401, {}, "ocr_auth", PermanentJobError),
    (303, {}, "ocr_config", PermanentJobError),
    (429, {}, "ocr_unavailable", ocr.DocumentJobError),
    (503, {}, "ocr_unavailable", ocr.DocumentJobError),
    (504, {}, "ocr_timeout", ocr.DocumentJobError),
])
@pytest.mark.asyncio
async def test_remote_failure_codes(blank_pdf, remote, status, body, error, kind):
    def adapter(req):
        assert req.url.host == "ocr.example", "Never follow redirects with OCR credentials"
        return httpx.Response(status, json=body, headers={"Location": "http://redirect.example/parse"})

    with pytest.raises(kind, match=f"^{error}$"):
        await ocr.extract_remote(blank_pdf, 1, transport=httpx.MockTransport(adapter))


@pytest.mark.asyncio
async def test_timeout_does_not_echo_private_url(blank_pdf, remote):
    def adapter(req):
        raise httpx.ReadTimeout("secret-url-document", request=req)

    with pytest.raises(ocr.DocumentJobError, match="^ocr_timeout$"):
        await ocr.extract_remote(blank_pdf, 1, transport=httpx.MockTransport(adapter))


@pytest.mark.asyncio
async def test_real_pdf_probe_detects_encrypted_corrupt_and_page_limit(tmp_path):
    path = tmp_path / "test.pdf"
    path.write_bytes(b"%PDF-1.4 broken")
    with pytest.raises(PermanentJobError, match="invalid_pdf"):
        await ocr.probe_pdf(path, local=False)
    writer = PdfWriter()
    for _ in range(21):
        writer.add_blank_page(width=200, height=200)
    writer.write(path)
    with pytest.raises(PermanentJobError, match="page_limit"):
        await ocr.probe_pdf(path, local=False)
    writer = PdfWriter()
    writer.add_blank_page(width=200, height=200)
    writer.encrypt("secret")
    writer.write(path)
    with pytest.raises(PermanentJobError, match="encrypted_pdf"):
        await ocr.probe_pdf(path, local=True)


@pytest.mark.asyncio
async def test_local_ocr_only_missing_pages_and_never_claim_blank_success(blank_pdf, monkeypatch):
    assert await ocr.probe_pdf(blank_pdf, local=True) == {"pages": 1, "text_layers": [""]}
    called = []

    async def page(_path, number):
        called.append(number)
        return "Вторая страница" if number == 2 else ""

    async def progress(*_args):
        pass

    monkeypatch.setattr(ocr, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr, "_tesseract_page", page)
    result = await ocr.extract_local(blank_pdf, {"pages": 3, "text_layers": ["First", "", ""]}, progress)
    assert called == [2, 3]
    assert result["text"] == "First\n\nВторая страница"
    assert result["pages_processed"] == 3 and result["warnings"] == ["empty_page"]
    with pytest.raises(PermanentJobError, match="no_text"):
        await ocr.extract_local(blank_pdf, {"pages": 1, "text_layers": [""]}, progress)
