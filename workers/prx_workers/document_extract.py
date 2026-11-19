"""OCR для прогона PDF → текст: отдельный /parse-контракт и постраничный local."""
from __future__ import annotations

import asyncio
import json
import os
import sys
from pathlib import Path
from urllib.parse import urlsplit

import httpx

from .base import PermanentJobError
from .ocr_client import OcrSettings, TESSERACT_LANGS, provider_name, tesseract_available
from .pdf_probe import MAX_TEXT

MAX_PDF = 10 * 1024 * 1024
MAX_REMOTE_RESPONSE = 8 * 1024 * 1024


class DocumentJobError(RuntimeError):
    """Временный отказ: сообщение — только стабильный код, подходит для очереди."""


async def _run(*args: str, timeout: float, env: dict | None = None) -> tuple[int, bytes]:
    proc = await asyncio.create_subprocess_exec(
        *args, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL, env=env,
    )
    try:
        output, _ = await asyncio.wait_for(proc.communicate(), timeout)
        return proc.returncode, output
    finally:
        if proc.returncode is None:
            try:
                proc.kill()
            except ProcessLookupError:
                pass
            await proc.communicate()


async def probe_pdf(path: Path, local: bool) -> dict:
    try:
        code, output = await _run(sys.executable, str(Path(__file__).with_name("pdf_probe.py")), str(path),
                                  "local" if local else "remote", timeout=25)
        if code:
            raise PermanentJobError("invalid_pdf")
        data = json.loads(output)
    except (ValueError, TimeoutError):
        raise PermanentJobError("invalid_pdf") from None
    if "error" in data:
        raise PermanentJobError(data["error"])
    return data


def _bounded(text: str) -> str:
    text = text.replace("\x00", "").strip()
    if not text:
        raise PermanentJobError("no_text")
    try:
        size = len(text.encode("utf-8"))
    except UnicodeError:
        raise PermanentJobError("ocr_invalid_response") from None
    if size > MAX_TEXT:
        raise PermanentJobError("result_too_large")
    return text


async def _tesseract_page(path: Path, page: int) -> str:
    prefix = path.parent / "page"
    image = prefix.with_suffix(".png")
    try:
        rc, _ = await _run("pdftoppm", "-f", str(page), "-l", str(page), "-singlefile",
                           "-scale-to", "2200", "-png", str(path), str(prefix), timeout=20)
        if rc or not image.is_file():
            raise PermanentJobError("ocr_page_failed")
        rc, output = await _run("tesseract", str(image), "stdout", "-l", TESSERACT_LANGS,
                                "--psm", "3", timeout=35, env={**os.environ, "OMP_THREAD_LIMIT": "1"})
        if rc:
            raise PermanentJobError("ocr_page_failed")
        return output.decode("utf-8", errors="replace").strip()
    except FileNotFoundError:
        raise PermanentJobError("ocr_unavailable") from None
    except TimeoutError:
        raise DocumentJobError("ocr_timeout") from None
    finally:
        image.unlink(missing_ok=True)


async def extract_local(path: Path, probe: dict, progress) -> dict:
    values = probe["text_layers"]
    total = probe["pages"]
    if not all(values) and not tesseract_available():
        raise PermanentJobError("ocr_unavailable")
    for page in range(total):
        if not values[page]:
            await progress("ocr_page", f"{page + 1}/{total}")
            values[page] = await _tesseract_page(path, page + 1)
    return {
        "text": _bounded("\n\n".join(values)), "format": "plain", "provider": "tesseract",
        "pages_total": total, "pages_processed": total, "warnings": [] if all(values) else ["empty_page"],
    }


async def extract_remote(path: Path, pages: int, *, transport=None) -> dict:
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
                        if len(raw) > MAX_REMOTE_RESPONSE:
                            raise PermanentJobError("result_too_large")
        data = json.loads(raw)
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
    return {"text": _bounded(data["content"]), "format": "markdown", "provider": "remote",
            "pages_total": pages, "pages_processed": processed, "warnings": []}


async def extract(path: Path, progress) -> dict:
    try:
        provider = provider_name()
    except RuntimeError:
        raise PermanentJobError("ocr_config") from None
    await progress("validate_pdf")
    probe = await probe_pdf(path, local=provider == "tesseract")
    if provider == "tesseract":
        return await extract_local(path, probe, progress)
    await progress("ocr_remote")
    return await extract_remote(path, probe["pages"])
