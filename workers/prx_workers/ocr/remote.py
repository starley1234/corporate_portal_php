"""Вызов внешнего OCR /parse для PDF."""

from __future__ import annotations

from pathlib import Path
from urllib.parse import urlsplit

import httpx

from ..base import PermanentJobError
from ..ocr_client import OcrSettings
from .errors import DocumentJobError
from .text import bounded

MIB = 1024 ** 2


async def extract_remote(path: Path, pages: int, *, max_text_bytes: int, transport=None) -> dict:
    try:
        settings = OcrSettings.from_env()
        url = urlsplit(settings.url)
        if (url.scheme not in ("http", "https") or not url.hostname or url.username is not None
                or url.fragment or not url.path.rstrip("/").endswith("/parse")):
            raise ValueError
        # URL может содержать свои query-параметры; обязательные параметры
        # профиля переопределяются здесь, а не отправляются в multipart data.
        target = httpx.URL(settings.url).copy_merge_params({
            **settings.extra_params, "engine": "auto", "output_format": "md", "do_ocr": "true",
        })
        headers = settings.headers()
        headers.setdefault("Accept-Encoding", "identity")
    except (RuntimeError, TypeError, ValueError, httpx.InvalidURL):
        raise PermanentJobError("ocr_config") from None

    # Keep the existing envelope at defaults; allow room for JSON escaping and metadata
    # when the configured text limit grows. bounded still validates the decoded text.
    max_response = max(8 * MIB, 8 * max_text_bytes)
    try:
        async with httpx.AsyncClient(timeout=settings.timeout, follow_redirects=False, transport=transport) as client:
            with path.open("rb") as pdf:
                async with client.stream("POST", target, headers=headers,
                                         files={"file": ("document.pdf", pdf, "application/pdf")}) as response:
                    status = response.status_code
                    if status in (408, 504):
                        raise DocumentJobError("ocr_timeout")
                    if status == 429 or status >= 500:
                        raise DocumentJobError("ocr_unavailable")
                    if status in (401, 403):
                        raise PermanentJobError("ocr_auth")
                    if status == 404 or 300 <= status < 400:
                        raise PermanentJobError("ocr_config")
                    if not response.is_success:
                        raise PermanentJobError("ocr_rejected")
                    raw = bytearray()
                    async for chunk in response.aiter_bytes(chunk_size=65536):
                        raw.extend(chunk)
                        if len(raw) > max_response:
                            raise PermanentJobError("result_too_large")
        data = __import__("json").loads(raw)
    except httpx.TimeoutException:
        raise DocumentJobError("ocr_timeout") from None
    except httpx.InvalidURL:
        raise PermanentJobError("ocr_config") from None
    except httpx.HTTPError:
        raise DocumentJobError("ocr_unavailable") from None
    except (ValueError, RecursionError):
        raise PermanentJobError("ocr_invalid_response") from None

    if not isinstance(data, dict) or not isinstance(data.get("content"), str):
        raise PermanentJobError("ocr_invalid_response")
    # По контракту прототипа гарантировано только content. Не придумываем
    # число обработанных страниц, когда шлюз его явно не указал числом.
    processed = data.get("pages")
    processed = processed if type(processed) is int else None
    if processed is not None and processed != pages:
        raise PermanentJobError("ocr_incomplete")
    return {"text": bounded(data["content"], max_text_bytes), "format": "markdown", "provider": "remote",
            "pages_total": pages, "pages_processed": processed, "warnings": []}
