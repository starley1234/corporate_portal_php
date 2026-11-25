"""Общий HTTP-протокол PHP↔воркер для документных заданий.

Модуль объявляет канал и лимиты, а проверку ответов, скачивание PDF и
best-effort доставку терминального кода берёт отсюда. Протокол (коды статусов,
`X-Worker-Token`, `X-PRX-Worker-Attempt`, тело ошибки) меняется в одном месте —
docs/SKELETON-ADR.md Д6.

Соответствие кодов ответа и ошибок:
  401/403 → worker_auth (повтор не поможет);
  409     → lease_lost (задание перехвачено/истекло);
  404/410 → file_unavailable;
  422     → код из тела или job_failed;
  408/429/5xx → worker_endpoint_unavailable (временный сбой, повторяем);
  прочие 4xx  → код из тела, иначе result_too_large (413) или job_failed.
"""
from __future__ import annotations

import logging
from pathlib import Path

import httpx

from .base import PermanentJobError
from .ocr.errors import DocumentJobError

log = logging.getLogger("prx.job_api")


def error_code(response: httpx.Response) -> str:
    """Код ошибки из тела {"error":{"code": …}} или пустая строка."""
    try:
        data = response.json()
        code = data.get("error", {}).get("code")
        return code if isinstance(code, str) else ""
    except Exception:  # noqa: BLE001
        return ""


def check_status(response: httpx.Response) -> None:
    """Проверить статус ответа БЕЗ чтения тела.

    Нужно для стриминговых ответов (`client.stream(...)`): там `.content`
    бросает `ResponseNotRead`, поэтому парсить тело нельзя.
    """
    code = response.status_code
    if code in (401, 403):
        raise PermanentJobError("worker_auth")
    if code == 409:
        raise PermanentJobError("lease_lost")
    if code in (404, 410):
        raise PermanentJobError("file_unavailable")
    if code in (408, 429) or code >= 500:
        raise DocumentJobError("worker_endpoint_unavailable")
    if not response.is_success:
        raise PermanentJobError("file_unavailable")


def check(response: httpx.Response) -> dict | None:
    """Проверить статус и вернуть JSON-тело (для не-стриминговых ответов).

    PermanentJobError — повтор не поможет; DocumentJobError — временный сбой.
    """
    code = response.status_code
    if code in (401, 403):
        raise PermanentJobError("worker_auth")
    if code == 409:
        raise PermanentJobError("lease_lost")
    if code in (404, 410):
        raise PermanentJobError("file_unavailable")
    if code in (408, 429) or code >= 500:
        raise DocumentJobError("worker_endpoint_unavailable")
    if not response.is_success:
        # 4xx — настоящий код из тела (invalid_part, result_too_large, …),
        # а не «недоступен». 413 без тела (отрезал прокси) — тоже result_too_large.
        detail = error_code(response)
        raise PermanentJobError(detail or ("result_too_large" if code == 413 else "job_failed"))
    if response.content:
        try:
            return response.json()
        except ValueError:
            return None
    return None


async def download_pdf(client: httpx.AsyncClient, url: str, path: Path, max_pdf_bytes: int) -> None:
    """Скачать PDF в path; проверить тип и размер. Бросает ошибку протокола."""
    async with client.stream("GET", url) as response:
        check_status(response)
        ctype = response.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        if ctype != "application/pdf":
            raise PermanentJobError("file_unavailable")
        size = 0
        with path.open("xb") as pdf:
            async for block in response.aiter_bytes(chunk_size=65536):
                size += len(block)
                if size > max_pdf_bytes:
                    raise PermanentJobError("file_unavailable")
                pdf.write(block)
        if not size:
            raise PermanentJobError("file_unavailable")


async def post_result(client: httpx.AsyncClient, url: str, body: dict) -> dict:
    """Отправить результат на PHP и вернуть JSON-ответ."""
    data = check(await client.post(url, json=body))
    return data if isinstance(data, dict) else {}


async def report_failure(headers: dict, url: str, code: str, **extra) -> None:
    """Best-effort: сообщить PHP терминальный код, чтобы запись стала failed.

    Без этого задача молча уходит в повтор, а UI показывает «В очереди», пока
    не истечёт TTL. Ошибку доставки глушим — исходная ошибка важнее.
    """
    body = {"error_code": code, "status": "failed"}
    body.update(extra)
    try:
        async with httpx.AsyncClient(headers=headers, timeout=30, follow_redirects=False) as client:
            await post_result(client, url + "/result", body)
    except Exception:  # noqa: BLE001
        pass
