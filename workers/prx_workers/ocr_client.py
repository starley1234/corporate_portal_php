"""Провайдеры OCR для канала `doc.normalize`.

Зачем отдельный модуль. Tesseract удобен локально, но на production его место
занимает внешний сервис (Docling / PaddleOCR / MinerU). Воркеру не нужно знать,
кто именно распознал страницы: он получает единый `ExtractedText`, а выбор
провайдера задаётся окружением.

    OCR_PROVIDER=tesseract   # dev, по умолчанию
    OCR_PROVIDER=remote      # prod, OCR_SERVICE_URL обязателен
    OCR_SERVICE_URL=http://ocr.internal:8000/v1/extract
    OCR_TIMEOUT=120
    OCR_API_KEY=...          # опционально; Authorization: Bearer <key>
    OCR_AUTH_HEADER_NAME=    # опционально; например X-API-Key (сырой ключ)
    OCR_EXTRA_PARAMS={}      # JSON-объект; уходит в multipart data=
    OCR_EXTRA_HEADERS={}     # JSON-объект; дополнительные HTTP-заголовки

Remote-провайдер отправляет PDF как `multipart/form-data` (поле `file`,
filename `document.pdf`) и ждёт JSON:

    {"text": "...", "pages": 3, "engine": "docling", "confidence": 0.93}

Сетевая ошибка, таймаут, 4xx/5xx и невалидный JSON — это инцидент: пишем его
в лог и возвращаем `engine="failed", text=""`, чтобы вызывающий код мог
попробовать следующий файл или честно завершить задачу ошибкой. Тело ответа
и текст документа в лог не попадают.
"""

from __future__ import annotations

import asyncio
import json
import logging
import math
import os
import re
import shutil
import subprocess
import tempfile
from dataclasses import dataclass, field, replace
from pathlib import Path
from typing import Any, Mapping

import httpx

log = logging.getLogger("prx.ocr")

DEFAULT_PROVIDER = "tesseract"
DEFAULT_TIMEOUT = 120.0
SUPPORTED_PROVIDERS = ("tesseract", "remote")
TESSERACT_LANGS = "rus+eng"


def _env(name: str, default: str = "") -> str:
    value = os.environ.get(name, "")
    return value.strip() if value.strip() else default


def clean_text(text: str) -> str:
    """Убрать мягкие переносы и лишние пробелы, сохранив абзацы."""
    text = (text or "").replace("\xad", "").replace("\ufeff", "")
    text = re.sub(r"[ \t]+", " ", text)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip()


def _json_object(name: str) -> dict[str, Any]:
    """Прочитать JSON-объект из env; пустое значение означает {}."""
    raw = _env(name, "{}")
    try:
        value = json.loads(raw)
    except (TypeError, ValueError) as exc:
        raise RuntimeError(f"{name} должен быть JSON-объектом: {exc}") from None
    if not isinstance(value, dict):
        raise RuntimeError(
            f"{name} должен быть JSON-объектом, получено {type(value).__name__}"
        )
    return value


def _timeout() -> float:
    raw = _env("OCR_TIMEOUT", "")
    if not raw:
        return DEFAULT_TIMEOUT
    try:
        value = float(raw)
    except ValueError:
        raise RuntimeError(
            f"OCR_TIMEOUT должен быть числом секунд, получено {raw!r}"
        ) from None
    if not math.isfinite(value) or value <= 0:
        raise RuntimeError("OCR_TIMEOUT должен быть положительным числом секунд")
    return value


@dataclass(frozen=True)
class OcrSettings:
    """Настройки OCR, собранные из окружения в одном месте.

    `from_env()` — единственный вход для production-пути. Тесты и точечные
    вызовы создают настройки напрямую (`replace()` / аргументы конструктора),
    поэтому провайдер не читает env повторно и не зависит от порядка вызовов.
    """

    service_url: str = ""
    timeout: float = DEFAULT_TIMEOUT
    api_key: str = ""
    auth_header_name: str = ""
    extra_params: Mapping[str, Any] = field(default_factory=dict)
    extra_headers: Mapping[str, str] = field(default_factory=dict)

    @classmethod
    def from_env(cls) -> "OcrSettings":
        return cls(
            service_url=_env("OCR_SERVICE_URL").strip(),
            timeout=_timeout(),
            api_key=_env("OCR_API_KEY").strip(),
            auth_header_name=_env("OCR_AUTH_HEADER_NAME").strip(),
            extra_params=_json_object("OCR_EXTRA_PARAMS"),
            extra_headers={
                str(key): str(value)
                for key, value in _json_object("OCR_EXTRA_HEADERS").items()
            },
        )

    @property
    def url(self) -> str:
        """Совместимый псевдоним: код и тесты исторически зовут это `url`."""
        return self.service_url

    def headers(self) -> dict[str, str]:
        """HTTP-заголовки remote-запроса; OCR_API_KEY главнее extra_headers."""
        headers = {"Accept": "application/json"}
        headers.update(self.extra_headers)

        if self.api_key:
            header_name = self.auth_header_name or "Authorization"
            # Не допускаем два заголовка авторизации в разном регистре.
            for existing in list(headers):
                if existing.lower() == header_name.lower():
                    del headers[existing]

            if header_name.lower() == "authorization":
                headers[header_name] = f"Bearer {self.api_key}"
            else:
                headers[header_name] = self.api_key

        return headers


@dataclass(frozen=True)
class ExtractedText:
    """Единый результат pypdf/любого OCR-провайдера.

    `as_payload(file_id)` — контракт с сайтом (`POST /prx390/api/text/<id>`):
    одно место, где перечислены поля, которые нельзя потерять.
    """

    text: str = ""
    pages: int = 0
    engine: str = ""
    confidence: float | None = None

    @classmethod
    def failed(cls) -> "ExtractedText":
        return cls(text="", pages=0, engine="failed", confidence=None)

    def as_payload(self, file_id: int) -> dict[str, Any]:
        return {
            "file_id": int(file_id),
            "text": self.text,
            "pages": self.pages,
            "engine": self.engine,
            "confidence": self.confidence,
        }

    def as_dict(self) -> dict[str, Any]:
        return {
            "text": self.text,
            "pages": self.pages,
            "engine": self.engine,
            "confidence": self.confidence,
        }


#: Старое имя результата. Оставлено, чтобы внешние вызовы не ломались.
OcrResult = ExtractedText


def _as_int(value: Any, default: int = 0) -> int:
    try:
        result = int(value)
    except (TypeError, ValueError, OverflowError):
        return default
    return max(0, result)


def _as_confidence(value: Any) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        result = float(value)
    except (TypeError, ValueError, OverflowError):
        return None
    if not math.isfinite(result):
        return None
    return max(0.0, min(1.0, result))


def tesseract_available() -> bool:
    return bool(shutil.which("pdftoppm") and shutil.which("tesseract"))


class BaseOcrProvider:
    """Общий интерфейс: асинхронный, чтобы remote не блокировал event loop."""

    name = ""

    async def extract(self, path: Path, max_pages: int = 5) -> ExtractedText:
        raise NotImplementedError


class TesseractOcrProvider(BaseOcrProvider):
    """Локальный fallback: pdftoppm → PNG → tesseract.

    `OMP_THREAD_LIMIT=1` обязателен: на слабой dev-машине tesseract иначе
    забирает все ядра, а воркер параллельно обслуживает другие задачи.
    `TemporaryDirectory` удаляет PNG даже при исключении.
    """

    name = "tesseract"

    async def extract(self, path: Path, max_pages: int = 5) -> ExtractedText:
        return await asyncio.to_thread(self.extract_sync, path, max_pages)

    def extract_sync(self, path: Path, max_pages: int = 5) -> ExtractedText:
        if not tesseract_available():
            return ExtractedText(text="", pages=0, engine=self.name, confidence=None)

        with tempfile.TemporaryDirectory(prefix="prx390_ocr_") as tmp:
            prefix = str(Path(tmp) / "page")
            try:
                subprocess.run(
                    [
                        "pdftoppm", "-r", "200", "-png",
                        "-f", "1", "-l", str(max(1, int(max_pages))),
                        str(path), prefix,
                    ],
                    check=True,
                    capture_output=True,
                    timeout=180,
                )
            except Exception as exc:  # noqa: BLE001 — OCR не должен ронять канал
                log.warning("ocr: pdftoppm не смог обработать %s: %s", getattr(path, "name", path), exc)
                return ExtractedText(text="", pages=0, engine=self.name, confidence=None)

            pages = sorted(Path(tmp).glob("page-*.png"))
            texts: list[str] = []
            env = os.environ.copy()
            env["OMP_THREAD_LIMIT"] = "1"

            for png in pages:
                try:
                    out = subprocess.run(
                        ["tesseract", str(png), "stdout", "-l", TESSERACT_LANGS, "--psm", "3"],
                        check=True,
                        capture_output=True,
                        timeout=120,
                        env=env,
                    )
                    texts.append(out.stdout.decode("utf-8", errors="replace"))
                except Exception as exc:  # noqa: BLE001 — одна страница не должна убить документ
                    log.warning("ocr: tesseract не смог обработать страницу %s: %s", png.name, exc)
                    continue

            return ExtractedText(
                text=clean_text("\n\n".join(texts)),
                pages=len(pages),
                engine=self.name,
                confidence=None,
            )


class RemoteOcrProvider(BaseOcrProvider):
    """Внешний OCR-микросервис с JSON-контрактом."""

    name = "remote"

    def __init__(
        self,
        settings: OcrSettings | str | None = None,
        *,
        transport: httpx.AsyncBaseTransport | None = None,
        url: str | None = None,
        timeout: float | None = None,
        api_key: str | None = None,
        auth_header_name: str | None = None,
        extra_params: Mapping[str, Any] | None = None,
        extra_headers: Mapping[str, Any] | None = None,
    ):
        # Совместимость: старый код передавал URL первым позиционным аргументом.
        if isinstance(settings, str):
            url = settings
            settings = None

        base = settings or OcrSettings.from_env()
        changes: dict[str, Any] = {}
        if url is not None:
            changes["service_url"] = str(url).strip()
        if timeout is not None:
            changes["timeout"] = timeout
        if api_key is not None:
            changes["api_key"] = str(api_key).strip()
        if auth_header_name is not None:
            changes["auth_header_name"] = str(auth_header_name).strip()
        if extra_params is not None:
            changes["extra_params"] = dict(extra_params)
        if extra_headers is not None:
            changes["extra_headers"] = {
                str(key): str(value) for key, value in extra_headers.items()
            }

        self.settings = replace(base, **changes) if changes else base
        self._transport = transport

    @property
    def url(self) -> str:
        return self.settings.service_url

    @property
    def timeout(self) -> float:
        return self.settings.timeout

    def _headers(self) -> dict[str, str]:
        return self.settings.headers()

    async def extract(self, path: Path, max_pages: int = 5) -> ExtractedText:
        if not self.settings.service_url:
            log.error("ocr: OCR_PROVIDER=remote, но OCR_SERVICE_URL не задан")
            return ExtractedText.failed()

        try:
            client_kwargs = {
                "timeout": self.settings.timeout,
                "follow_redirects": True,
            }
            if self._transport is not None:
                client_kwargs["transport"] = self._transport

            async with httpx.AsyncClient(**client_kwargs) as client:
                with Path(path).open("rb") as pdf:
                    response = await client.post(
                        self.settings.service_url,
                        files={"file": ("document.pdf", pdf, "application/pdf")},
                        data=self.settings.extra_params,
                        headers=self._headers(),
                    )
                response.raise_for_status()
                data = response.json()
        except (httpx.HTTPError, OSError) as exc:
            log.error(
                "ocr: remote-сервис %s недоступен: %s: %s",
                self.settings.service_url, exc.__class__.__name__, exc,
            )
            return ExtractedText.failed()
        except ValueError as exc:
            # response.json() на невалидном JSON
            log.error("ocr: remote-сервис %s вернул не JSON: %s",
                      self.settings.service_url, exc.__class__.__name__)
            return ExtractedText.failed()

        if not isinstance(data, dict):
            log.error("ocr: remote-сервис %s вернул JSON не объектом", self.settings.service_url)
            return ExtractedText.failed()

        text = data.get("text")
        if not isinstance(text, str):
            log.error("ocr: remote-сервис %s вернул поле text не строкой", self.settings.service_url)
            return ExtractedText.failed()

        engine = str(data.get("engine") or "remote").strip()[:64] or "remote"
        return ExtractedText(
            text=text.strip(),
            pages=_as_int(data.get("pages"), 0),
            engine=engine,
            confidence=_as_confidence(data.get("confidence")),
        )


def provider_name() -> str:
    """Имя активного провайдера; неизвестное значение — ошибка настройки."""
    name = _env("OCR_PROVIDER", DEFAULT_PROVIDER).lower()
    if name not in SUPPORTED_PROVIDERS:
        raise RuntimeError(
            f"OCR_PROVIDER должен быть одним из {', '.join(SUPPORTED_PROVIDERS)}, "
            f"получено {name!r}"
        )
    return name


def get_provider(name: str | None = None) -> BaseOcrProvider:
    provider = (name or provider_name()).strip().lower()
    if provider == "tesseract":
        return TesseractOcrProvider()
    if provider == "remote":
        return RemoteOcrProvider(OcrSettings.from_env())
    raise RuntimeError(
        f"OCR_PROVIDER должен быть одним из {', '.join(SUPPORTED_PROVIDERS)}, "
        f"получено {provider!r}"
    )


async def extract(path: Path, max_pages: int = 5) -> ExtractedText:
    """Распознать PDF активным провайдером. Ошибки remote не бросаются."""
    return await get_provider().extract(Path(path), max_pages=max_pages)


def extract_sync(path: Path, max_pages: int = 5) -> ExtractedText:
    """Синхронная обёртка для unit-тестов и legacy-вызовов."""
    return asyncio.run(extract(path, max_pages=max_pages))
