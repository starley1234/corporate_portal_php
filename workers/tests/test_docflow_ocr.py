"""Выбор pypdf/OCR в архивном doc.normalize."""
from __future__ import annotations

import asyncio
from pathlib import Path
import pytest

from prx_workers import ocr_client
from prx_workers.handlers import doc_normalize


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


def test_settings_are_collected_in_one_place(monkeypatch):
    monkeypatch.setenv("OCR_SERVICE_URL", "http://ocr.internal/extract")
    monkeypatch.setenv("OCR_TIMEOUT", "15")
    monkeypatch.setenv("OCR_API_KEY", "secret")
    monkeypatch.setenv("OCR_EXTRA_PARAMS", '{"lang": "rus"}')

    settings = ocr_client.OcrSettings.from_env()

    assert settings.url == "http://ocr.internal/extract"
    assert settings.timeout == 15
    assert settings.headers()["Authorization"] == "Bearer secret"
    assert settings.extra_params == {"lang": "rus"}


def test_extracted_text_is_the_site_payload():
    text = ocr_client.ExtractedText(
        text="Письмо", pages=2, engine="docling", confidence=0.91,
    )

    assert text.as_payload(42) == {
        "file_id": 42,
        "text": "Письмо",
        "pages": 2,
        "engine": "docling",
        "confidence": 0.91,
    }
