"""Стриминговые ответы не должны читать тело (прод 25.11.2026: ResponseNotRead).

`client.stream(...)` даёт ответ, у которого `.content`/`.json()` бросают
`httpx.ResponseNotRead`, пока не вызван `read()`/`aiter_bytes()`. Все
помощники job_api и ocr.remote обязаны работать в обоих режимах: по статусу
на streamed-ответе, по телу — на обычном. Регрессия: helpers.py-гвард
`check()` и статусы download_pdf/extract_remote.
"""

from __future__ import annotations

import asyncio
from pathlib import Path

import httpx
import pytest

from prx_workers import job_api
from prx_workers.base import PermanentJobError
from prx_workers.ocr.errors import DocumentJobError
from prx_workers.ocr.remote import extract_remote

PDF = b"%PDF-1.4 regression"


def _client(handler) -> httpx.AsyncClient:
    return httpx.AsyncClient(transport=httpx.MockTransport(handler), follow_redirects=False)


def test_download_pdf_success(tmp_path):
    async def handler(request):
        return httpx.Response(200, headers={"Content-Type": "application/pdf"}, content=PDF)

    target = tmp_path / "doc.pdf"

    async def run():
        async with _client(handler) as client:
            await job_api.download_pdf(client, "http://php/file", target, 10 * 1024 * 1024)

    asyncio.run(run())
    assert target.read_bytes() == PDF


@pytest.mark.parametrize(("status", "expected"), [
    (409, "lease_lost"),
    (404, "file_unavailable"),
    (500, None),  # DocumentJobError, код не проверяем
])
def test_download_pdf_error_statuses(tmp_path, status, expected):
    """Ошибка при streamed-ответе не должна превращаться в ResponseNotRead."""

    async def handler(request):
        return httpx.Response(status, json={"error": {"code": "whatever"}})

    async def run():
        async with _client(handler) as client:
            await job_api.download_pdf(client, "http://php/file", tmp_path / "x.pdf", 10 * 1024 * 1024)

    with pytest.raises((PermanentJobError, DocumentJobError)) as exc:
        asyncio.run(run())
    message = str(exc.value)
    assert "ResponseNotRead" not in message
    if expected:
        assert message == expected


def test_check_guard_on_streamed_response():
    """check() на streamed-ответе: тело недоступно → None, без исключения."""

    async def chunks():
        yield b'{"ok": true}'

    async def handler(request):
        # Честно стриминговый ответ: async-итератор, тело не прочитано
        # transport'ом заранее — как из реальной сети.
        return httpx.Response(200, content=chunks())

    async def run():
        async with _client(handler) as client:
            async with client.stream("POST", "http://php/result", json={}) as response:
                return job_api.check(response)

    assert asyncio.run(run()) is None


def test_extract_remote_success(tmp_path, monkeypatch):
    pdf = tmp_path / "doc.pdf"
    pdf.write_bytes(PDF)
    monkeypatch.setenv("OCR_SERVICE_URL", "http://ocr.test/v1/parse")
    monkeypatch.setenv("OCR_TIMEOUT", "5")
    seen: dict = {}

    async def handler(request):
        seen["url"] = str(request.url)
        await request.aread()
        return httpx.Response(200, json={"content": "текст", "pages_processed": 1})

    async def run():
        async with _client(handler) as client:
            return await extract_remote(pdf, 1, max_text_bytes=100000,
                                        transport=httpx.MockTransport(handler))

    result = asyncio.run(run())
    assert result["text"] == "текст"
    assert seen["url"].startswith("http://ocr.test/v1/parse")


@pytest.mark.parametrize(("status", "expected_type", "expected_code"), [
    (500, DocumentJobError, "ocr_unavailable"),
    (302, PermanentJobError, "ocr_config"),
    (401, PermanentJobError, "ocr_auth"),
])
def test_extract_remote_error_statuses(tmp_path, status, expected_type, expected_code, monkeypatch):
    pdf = tmp_path / "doc.pdf"
    pdf.write_bytes(PDF)
    monkeypatch.setenv("OCR_SERVICE_URL", "http://ocr.test/v1/parse")
    monkeypatch.setenv("OCR_TIMEOUT", "5")

    async def handler(request):
        return httpx.Response(status, json={"detail": "x"})

    async def run():
        async with _client(handler) as client:
            await extract_remote(pdf, 1, max_text_bytes=100000,
                                 transport=httpx.MockTransport(handler))

    with pytest.raises(expected_type) as exc:
        asyncio.run(run())
    assert str(exc.value) == expected_code
    assert "ResponseNotRead" not in str(exc.value)


def test_from_env_module_override(monkeypatch):
    """По-модульный OCR-эндпоинт с фолбэком на общий (прод 25.11.2026)."""

    from prx_workers.ocr_client import OcrSettings

    monkeypatch.setenv("OCR_SERVICE_URL", "http://general.test/v1/parse")
    monkeypatch.setenv("OCR_SERVICE_URL_PRX396", "http://prx396.test/parse")
    monkeypatch.setenv("OCR_API_KEY", "general-key")

    general = OcrSettings.from_env()
    own = OcrSettings.from_env("PRX396")
    other = OcrSettings.from_env("PRX397")

    assert general.service_url == "http://general.test/v1/parse"
    assert own.service_url == "http://prx396.test/parse"
    assert other.service_url == "http://general.test/v1/parse"
    assert other.api_key == "general-key"
