"""OCR-провайдеры: локальные зависимости и HTTP-контракт."""
from __future__ import annotations

import asyncio
import subprocess
from pathlib import Path
import httpx
import pytest

from prx_workers import ocr_client


@pytest.fixture(autouse=True)
def _clean_ocr_env(monkeypatch):
    """Не наследовать OCR-настройки из окружения разработчика."""
    for name in (
        "OCR_PROVIDER", "OCR_SERVICE_URL", "OCR_TIMEOUT",
        "OCR_API_KEY", "OCR_AUTH_HEADER_NAME",
        "OCR_EXTRA_PARAMS", "OCR_EXTRA_HEADERS",
    ):
        monkeypatch.delenv(name, raising=False)


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
    assert b'filename="document.pdf"' in seen["body"]
    assert b"application/pdf" in seen["body"]
    assert result.text == "# Письмо\n\nТекст"
    assert result.pages == 2
    assert result.engine == "docling"
    assert result.confidence == 0.93


def test_remote_auth_params_and_headers_from_env(tmp_path, monkeypatch):
    monkeypatch.setenv("OCR_API_KEY", "secret-token")
    monkeypatch.setenv(
        "OCR_EXTRA_PARAMS",
        '{"output_format": "markdown", "lang": "rus", "extract_tables": "true"}',
    )
    monkeypatch.setenv(
        "OCR_EXTRA_HEADERS",
        '{"X-Tenant": "demo", "X-Request-Source": "doc-normalize"}',
    )
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["headers"] = request.headers
        seen["body"] = request.content
        return httpx.Response(200, json={
            "text": "ok", "pages": 1, "engine": "docling", "confidence": None,
        })

    provider = ocr_client.RemoteOcrProvider(
        url="http://ocr.internal/extract",
        timeout=1,
        transport=httpx.MockTransport(handler),
    )
    result = asyncio.run(provider.extract(_pdf(tmp_path)))

    assert result.text == "ok"
    assert seen["headers"]["authorization"] == "Bearer secret-token"
    assert seen["headers"]["x-tenant"] == "demo"
    assert seen["headers"]["x-request-source"] == "doc-normalize"
    for token in (
        b'name="output_format"', b"markdown",
        b'name="lang"', b"rus",
        b'name="extract_tables"', b"true",
    ):
        assert token in seen["body"], token


def test_remote_custom_auth_header_uses_raw_key(tmp_path, monkeypatch):
    monkeypatch.setenv("OCR_API_KEY", "secret-token")
    monkeypatch.setenv("OCR_AUTH_HEADER_NAME", "X-API-Key")
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["headers"] = request.headers
        return httpx.Response(200, json={"text": "ok", "pages": 1, "engine": "mineru"})

    provider = ocr_client.RemoteOcrProvider(
        url="http://ocr.internal/extract",
        timeout=1,
        transport=httpx.MockTransport(handler),
    )
    asyncio.run(provider.extract(_pdf(tmp_path)))

    assert seen["headers"]["x-api-key"] == "secret-token"
    assert "authorization" not in seen["headers"]


def test_remote_invalid_extra_json_is_a_config_error(monkeypatch):
    monkeypatch.setenv("OCR_EXTRA_PARAMS", "not json")
    with pytest.raises(RuntimeError, match="OCR_EXTRA_PARAMS"):
        ocr_client.RemoteOcrProvider(url="http://ocr.internal/extract", timeout=1)

    monkeypatch.delenv("OCR_EXTRA_PARAMS")
    monkeypatch.setenv("OCR_EXTRA_HEADERS", "[]")
    with pytest.raises(RuntimeError, match="OCR_EXTRA_HEADERS"):
        ocr_client.RemoteOcrProvider(url="http://ocr.internal/extract", timeout=1)


def test_remote_5xx_returns_failed(tmp_path, caplog):
    provider = ocr_client.RemoteOcrProvider(
        url="http://ocr.internal/extract",
        timeout=1,
        transport=httpx.MockTransport(lambda _request: httpx.Response(503)),
    )
    with caplog.at_level("ERROR", logger="prx.ocr"):
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
    with caplog.at_level("ERROR", logger="prx.ocr"):
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
    assert ocr_client._timeout_value("15") == 15.0
    with pytest.raises(RuntimeError, match="OCR_TIMEOUT"):
        ocr_client._timeout_value("abc")
    with pytest.raises(RuntimeError, match="OCR_TIMEOUT"):
        ocr_client._timeout_value("0")


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
