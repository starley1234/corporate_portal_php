"""OCR-провайдеры и выбор pypdf/OCR для doc.normalize."""
from __future__ import annotations

import asyncio
import subprocess
from pathlib import Path
import httpx
import pytest

from prl_workers import ocr_client
from prl_workers.handlers import doc_normalize


def _pdf(tmp_path: Path) -> Path:
    path = tmp_path / "letter.pdf"
    path.write_bytes(b"%PDF-1.4\n% test\n")
    return path


def test_default_provider_is_tesseract(monkeypatch):
    monkeypatch.delenv("OCR_PROVIDER", raising=False)
    assert ocr_client.provider_name() == "tesseract"
    assert isinstance(ocr_client.get_provider(), ocr_client.TesseractOcrProvider)


def test_unknown_provider_is_a_config_error(monkeypatch):
    monkeypatch.setenv("OCR_PROVIDER", "docling")
    with pytest.raises(RuntimeError, match="OCR_PROVIDER"):
        ocr_client.provider_name()


def test_remote_posts_multipart_and_parses_contract(tmp_path, monkeypatch):
    monkeypatch.setenv("OCR_PROVIDER", "remote")
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["method"] = request.method
        seen["url"] = str(request.url)
        seen["body"] = request.content
        return httpx.Response(200, json={
            "text": "# Письмо\n\nТекст",
            "pages": 2,
            "engine": "docling",
            "confidence": 0.93,
        })

    provider = ocr_client.RemoteOcrProvider(
        url="http://ocr.internal:8000/v1/extract",
        timeout=7,
        transport=httpx.MockTransport(handler),
    )
    result = asyncio.run(provider.extract(_pdf(tmp_path)))

    assert seen["method"] == "POST"
    assert seen["url"] == "http://ocr.internal:8000/v1/extract"
    assert b'name="file"' in seen["body"]
    assert b"application/pdf" in seen["body"]
    assert result.text == "# Письмо\n\nТекст"
    assert result.pages == 2
    assert result.engine == "docling"
    assert result.confidence == 0.93


def test_remote_5xx_returns_failed(tmp_path, caplog):
    provider = ocr_client.RemoteOcrProvider(
        url="http://ocr.internal/extract",
        timeout=1,
        transport=httpx.MockTransport(lambda _request: httpx.Response(503)),
    )
    with caplog.at_level("ERROR", logger="prl.ocr"):
        result = asyncio.run(provider.extract(_pdf(tmp_path)))
    assert result.engine == "failed"
    assert result.text == ""
    assert "503" in caplog.text


def test_remote_network_error_returns_failed(tmp_path):
    def broken(_request: httpx.Request) -> httpx.Response:
        raise httpx.ConnectError("connection refused")

    provider = ocr_client.RemoteOcrProvider(
        url="http://ocr.internal/extract",
        timeout=1,
        transport=httpx.MockTransport(broken),
    )
    result = asyncio.run(provider.extract(_pdf(tmp_path)))
    assert (result.engine, result.text, result.pages) == ("failed", "", 0)


def test_remote_invalid_json_returns_failed(tmp_path, caplog):
    provider = ocr_client.RemoteOcrProvider(
        url="http://ocr.internal/extract",
        timeout=1,
        transport=httpx.MockTransport(
            lambda _request: httpx.Response(200, content=b"not json")
        ),
    )
    with caplog.at_level("ERROR", logger="prl.ocr"):
        result = asyncio.run(provider.extract(_pdf(tmp_path)))
    assert result.engine == "failed"
    assert "не JSON" in caplog.text


def test_remote_missing_url_returns_failed(tmp_path, monkeypatch):
    monkeypatch.delenv("OCR_SERVICE_URL", raising=False)
    provider = ocr_client.RemoteOcrProvider(url="", timeout=1)
    result = asyncio.run(provider.extract(_pdf(tmp_path)))
    assert result.engine == "failed"


def test_remote_confidence_is_clamped():
    assert ocr_client._as_confidence(1.5) == 1.0
    assert ocr_client._as_confidence(-1) == 0.0
    assert ocr_client._as_confidence("bad") is None
    assert ocr_client._as_confidence(None) is None


def test_timeout_env_validation(monkeypatch):
    monkeypatch.setenv("OCR_TIMEOUT", "15")
    assert ocr_client._timeout() == 15
    monkeypatch.setenv("OCR_TIMEOUT", "abc")
    with pytest.raises(RuntimeError, match="OCR_TIMEOUT"):
        ocr_client._timeout()


def test_tesseract_sets_omp_thread_limit_and_cleans_temp(tmp_path, monkeypatch):
    seen = {}
    prefix_holder = {}
    pdf = _pdf(tmp_path)

    def fake_run(command, **kwargs):
        if command[0] == "pdftoppm":
            prefix = Path(command[-1])
            prefix_holder["dir"] = prefix.parent
            (prefix.parent / (prefix.name + "-1.png")).write_bytes(b"png")
            return subprocess.CompletedProcess(command, 0, stdout=b"", stderr=b"")
        if command[0] == "tesseract":
            seen["env"] = kwargs.get("env") or {}
            return subprocess.CompletedProcess(command, 0, stdout="Текст письма".encode(), stderr=b"")
        raise AssertionError(command)

    monkeypatch.setattr(ocr_client, "tesseract_available", lambda: True)
    monkeypatch.setattr(ocr_client.subprocess, "run", fake_run)

    result = ocr_client.TesseractOcrProvider().extract_sync(pdf, max_pages=1)

    assert result.text == "Текст письма"
    assert result.pages == 1
    assert result.engine == "tesseract"
    assert seen["env"]["OMP_THREAD_LIMIT"] == "1"
    assert not prefix_holder["dir"].exists()


def test_tesseract_without_binaries_returns_empty(tmp_path, monkeypatch):
    monkeypatch.setattr(ocr_client, "tesseract_available", lambda: False)
    result = ocr_client.TesseractOcrProvider().extract_sync(_pdf(tmp_path))
    assert result.text == ""
    assert result.pages == 0
    assert result.engine == "tesseract"


def test_usable_text_threshold_and_garbage():
    assert not doc_normalize.is_usable_text("а" * 99)
    assert doc_normalize.is_usable_text("а" * 100)
    assert not doc_normalize.is_usable_text("\ufffd" * 200)
    assert not doc_normalize.looks_like_garbage("Нормальный текст письма на ста страницах")


def test_extract_text_skips_ocr_when_pypdf_is_enough(tmp_path, monkeypatch):
    def fake_pypdf(_path):
        return "Письмо " * 30, 2

    def fail_ocr(*_args, **_kwargs):
        raise AssertionError("OCR не должен вызываться")

    monkeypatch.setattr(doc_normalize, "_extract_pypdf", fake_pypdf)
    monkeypatch.setattr(ocr_client, "extract", fail_ocr)

    result = asyncio.run(doc_normalize.extract_text(_pdf(tmp_path)))
    assert result["engine"] == "pypdf"
    assert result["pages"] == 2
    assert result["confidence"] is None


def test_extract_text_falls_back_to_active_ocr(tmp_path, monkeypatch):
    monkeypatch.setattr(doc_normalize, "_extract_pypdf", lambda _path: ("мусор", 1))

    async def fake_ocr(_path, max_pages=5):
        assert max_pages == 5
        return ocr_client.OcrResult(
            text="Распознанный текст письма",
            pages=3,
            engine="docling",
            confidence=0.88,
        )

    monkeypatch.setattr(ocr_client, "extract", fake_ocr)

    result = asyncio.run(doc_normalize.extract_text(_pdf(tmp_path)))
    assert result == {
        "text": "Распознанный текст письма",
        "pages": 3,
        "engine": "docling",
        "confidence": 0.88,
    }


def test_extract_text_keeps_short_pypdf_text_if_ocr_failed(tmp_path, monkeypatch):
    monkeypatch.setattr(doc_normalize, "_extract_pypdf", lambda _path: ("короткий слой", 1))

    async def failed_ocr(_path, max_pages=5):
        return ocr_client.OcrResult.failed()

    monkeypatch.setattr(ocr_client, "extract", failed_ocr)

    result = asyncio.run(doc_normalize.extract_text(_pdf(tmp_path)))
    assert result["engine"] == "pypdf"
    assert result["text"] == "короткий слой"


def test_extract_text_propagates_pypdf_error_when_ocr_is_empty(tmp_path, monkeypatch):
    def broken(_path):
        raise RuntimeError("pypdf сломан")

    monkeypatch.setattr(doc_normalize, "_extract_pypdf", broken)

    async def failed_ocr(_path, max_pages=5):
        return ocr_client.OcrResult.failed()

    monkeypatch.setattr(ocr_client, "extract", failed_ocr)

    with pytest.raises(RuntimeError, match="pypdf сломан"):
        asyncio.run(doc_normalize.extract_text(_pdf(tmp_path)))


def test_normalize_handle_posts_confidence(tmp_path, monkeypatch):
    pdf = _pdf(tmp_path)
    sent = {}

    async def fake_download(file_id, dest):
        dest.write_bytes(pdf.read_bytes())

    async def fake_get(prefix, path, **kwargs):
        return True, "ok", {"files": [{"file_id": 7, "filename": "letter.pdf"}]}

    async def fake_post(prefix, path, body, **kwargs):
        sent.update(body)
        return True, "ok", {"saved": True}

    async def fake_extract(_path, max_pages=5):
        return {
            "text": "Распознанный текст",
            "pages": 2,
            "engine": "paddleocr",
            "confidence": 0.77,
        }

    monkeypatch.setattr(doc_normalize, "_download", fake_download)
    monkeypatch.setattr(doc_normalize.site, "get_json", fake_get)
    monkeypatch.setattr(doc_normalize.site, "post_json", fake_post)
    monkeypatch.setattr(doc_normalize, "extract_text", fake_extract)

    class Ctx:
        async def progress(self, *_args, **_kwargs):
            return None

    result = asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))

    assert sent == {
        "file_id": 7,
        "text": "Распознанный текст",
        "pages": 2,
        "engine": "paddleocr",
        "confidence": 0.77,
    }
    assert result["confidence"] == 0.77
