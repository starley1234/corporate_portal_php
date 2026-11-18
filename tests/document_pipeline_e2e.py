#!/usr/bin/env python3
"""Опциональный smoke: настоящий PHP + PostgreSQL + Python-воркер.

Против выделенного стенда, с двумя тестовыми ключами. Нужны зависимости
workers/requirements.txt. Для OCR-скана можно передать --pdf и --contains.
"""
from __future__ import annotations

import argparse
import asyncio
import io
import os
import time
import uuid
from pathlib import Path

import httpx
from pypdf import PdfWriter
from pypdf.generic import DecodedStreamObject, DictionaryObject, NameObject

API = "/api/v1/document-extractions"
MARKER = "PRX Document Pipeline"


def pdf_bytes(marker: str = MARKER, *, encrypted: bool = False) -> bytes:
    writer = PdfWriter()
    page = writer.add_blank_page(width=595, height=842)
    font = DictionaryObject({NameObject("/Type"): NameObject("/Font"), NameObject("/Subtype"): NameObject("/Type1"),
                             NameObject("/BaseFont"): NameObject("/Helvetica")})
    page[NameObject("/Resources")] = DictionaryObject({NameObject("/Font"): DictionaryObject({NameObject("/F1"): font})})
    stream = DecodedStreamObject()
    stream.set_data(f"BT /F1 24 Tf 50 700 Td ({marker}) Tj ET".encode("ascii"))
    page.replace_contents(stream)
    if encrypted:
        writer.encrypt("fixture-password")
    output = io.BytesIO()
    writer.write(output)
    return output.getvalue()


def assert_code(response: httpx.Response, status: int, code: str) -> None:
    assert response.status_code == status, (response.request.method, response.request.url.path, response.status_code)
    assert response.json() == {"error": {"code": code}}, (status, code)


async def main(args) -> None:
    owner = {"Authorization": "Bearer " + os.environ["PRX_TEST_CLIENT_TOKEN"]}
    other = {"Authorization": "Bearer " + os.environ["PRX_TEST_OTHER_CLIENT_TOKEN"]}
    async with httpx.AsyncClient(base_url=args.url, timeout=15, follow_redirects=False) as client:
        assert_code(await client.post(API), 401, "unauthorized")
        assert_code(await client.get(API, headers=owner), 405, "method_not_allowed")

        async def submit(data: bytes, key: str, headers: dict = owner) -> httpx.Response:
            return await client.post(API, files={"file": ("fixture.pdf", data, "application/pdf")},
                                     headers={**headers, "Idempotency-Key": key})

        async def poll(url: str) -> dict:
            deadline, previous = time.monotonic() + args.wait, None
            while time.monotonic() < deadline:
                response = await client.get(url, headers=owner)
                assert response.status_code == 200 and response.headers.get("Cache-Control") == "no-store", response.status_code
                data = response.json()
                if data["status"] != previous:
                    print(f"#{data['job_id']} {data['status']} attempt={data['attempt']}", flush=True)
                    previous = data["status"]
                if data["status"] in ("succeeded", "failed", "canceled"):
                    return data
                await asyncio.sleep(0.35)
            raise AssertionError(f"{url} не завершился; последний статус {previous}")

        assert_code(await submit(b"not a PDF", uuid.uuid4().hex), 415, "pdf_required")
        data = args.pdf.read_bytes() if args.pdf else pdf_bytes()
        key = uuid.uuid4().hex
        first, replay = await asyncio.gather(submit(data, key), submit(data, key))
        assert first.status_code == replay.status_code == 202, (first.status_code, replay.status_code)
        assert first.json() == replay.json(), "Idempotency raced"
        job = first.json()
        url = job["status_url"]
        assert url == f"{API}/{job['job_id']}"
        assert_code(await submit(pdf_bytes("Different bytes"), key), 409, "idempotency_conflict")
        assert_code(await client.get(url, headers=other), 404, "not_found")
        assert_code(await client.get(url), 401, "unauthorized")
        assert_code(await client.get(f"/prx395/worker/{job['job_id']}/file", headers=owner), 401, "unauthorized")
        assert_code(await client.post(f"/prx395/worker/{job['job_id']}/result", headers=owner), 401, "unauthorized")
        result = await poll(url)
        assert result["status"] == "succeeded", result.get("error")
        assert result["result"]["provider"] == args.expect_provider
        assert args.contains in result["result"]["text"], "Expected marker absent"
        assert 1 <= result["result"]["pages_total"] <= 20
        assert_code(await client.get(url, headers=other), 404, "not_found")
        client2 = await submit(data, key, headers=other)
        assert client2.status_code == 202 and client2.json()["job_id"] != job["job_id"]
        response = await submit(pdf_bytes(encrypted=True), uuid.uuid4().hex)
        assert response.status_code == 202
        rejected = await poll(response.json()["status_url"])
        assert rejected["status"] == "failed" and rejected.get("error") == {"code": "encrypted_pdf"}
        assert rejected["attempt"] == 1, "Permanent error was retried"
        print("OK: API → queue → worker → API; idempotency; client isolation; permanent failure")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", default="http://127.0.0.1:8080")
    parser.add_argument("--expect-provider", choices=("tesseract", "remote"), default="tesseract")
    parser.add_argument("--wait", type=float, default=45)
    parser.add_argument("--pdf", type=Path)
    parser.add_argument("--contains", default=MARKER)
    asyncio.run(main(parser.parse_args()))
