"""Диагностика doc.normalize: корреляция, настоящие сетевые ошибки и приватность."""
from __future__ import annotations

import asyncio
import errno
import logging
import shutil
import socket
import ssl
import subprocess
import threading
from urllib.parse import parse_qs

import httpx
import pytest

from prx_workers import ocr_client, site
from prx_workers.docflow_trace import NORMALIZE_TRACE
from prx_workers.handlers.trial.prx390_intake import normalize as doc_normalize

SECRET = "private-secret-and-document-content-42"


class Ctx:
    def __init__(self, job_id=17):
        self._current_job = {"id": job_id, "attempts": 2, "max_attempts": 3}
        self.steps = []

    async def progress(self, *args):
        self.steps.append(args)
        await asyncio.sleep(0)


@pytest.fixture(autouse=True)
def clean_env(monkeypatch, caplog):
    for name in ("PRX390_API_BASE", "PRX120_API_BASE", "PRX120_API_URL",
                 "PRX390_SITE_TOKEN", "WORKER_SITE_TOKEN", "WORKER_API_TOKEN",
                 "HTTP_PROXY", "http_proxy", "HTTPS_PROXY", "https_proxy",
                 "ALL_PROXY", "all_proxy", "NO_PROXY", "no_proxy", "OCR_PROVIDER"):
        monkeypatch.delenv(name, raising=False)
    monkeypatch.setenv("PRX390_SITE_TOKEN", SECRET)
    # Так же работает стандартная настройка WORKER_LOG_QUIET=httpx.
    caplog.set_level(logging.WARNING, logger="httpx")
    caplog.set_level(logging.INFO, logger="prx.docflow")
    caplog.set_level(logging.INFO, logger="prx.ocr")
    caplog.set_level(logging.INFO, logger="prx.site")


def mock_http(monkeypatch, handler):
    original = httpx.AsyncClient
    transport = httpx.MockTransport(handler)

    def client(*args, **kwargs):
        kwargs["transport"] = transport
        kwargs["trust_env"] = False
        return original(*args, **kwargs)

    monkeypatch.setattr(httpx, "AsyncClient", client)


def diagnostic_text(caplog):
    return "\n".join(record.getMessage() for record in caplog.records
                     if record.name in {"prx.docflow", "prx.ocr", "prx.site", "prx.worker"})


def test_safe_origin_hides_userinfo_path_query_fragment_and_malformed_url():
    url = f"https://user:{SECRET}@[::1]:8443/{SECRET}?token={SECRET}#{SECRET}"
    assert site.safe_origin(url) == "https://[::1]:8443"
    assert site.safe_base_details(url, "/prx390/api") == ("other", "yes", "yes", "yes")
    assert site.safe_origin(f"http://example.com:bad/{SECRET}") == "(адрес не задан или некорректен)"
    assert SECRET not in site.safe_origin(f"{SECRET}://invalid")


def test_module_api_source_and_override_precedence(monkeypatch):
    monkeypatch.setenv("PRX120_API_URL", "http://queue.example/prx120/api")
    assert site.module_api_config("PRX390", "/prx390/api") == (
        "http://queue.example/prx390/api", "PRX120_API_URL")
    monkeypatch.setenv("PRX120_API_BASE", "https://portal.example")
    assert site.module_api_config("PRX390", "/prx390/api") == (
        "https://portal.example/prx390/api", "PRX120_API_BASE")
    monkeypatch.setenv("PRX390_API_BASE", "http://other.example/custom")
    assert site.module_api_config("PRX390", "/prx390/api") == (
        "http://other.example/custom", "PRX390_API_BASE")


@pytest.mark.parametrize("cause,expected", [
    (ConnectionRefusedError(errno.ECONNREFUSED, SECRET), "connection_refused, errno=ECONNREFUSED"),
    (socket.gaierror(-2, SECRET), "dns, dns_code=-2"),
    (ssl.SSLCertVerificationError(SECRET), "tls_certificate"),
    (httpx.ConnectTimeout(SECRET), "timeout"),
    (httpx.ProxyError(SECRET), "proxy"),
    (httpx.InvalidURL(SECRET), "invalid_url"),
])
def test_network_diagnostic_classifies_nested_errors_without_their_text(cause, expected):
    outer = httpx.ConnectError(SECRET)
    outer.__cause__ = cause
    reason = site.network_error(outer)
    assert expected in reason
    assert SECRET not in reason


def test_invalid_payload_is_correlated_without_logging_its_value(caplog):
    with pytest.raises(ValueError) as error:
        asyncio.run(doc_normalize.handle(Ctx(), {"message_id": SECRET}))
    logs = diagnostic_text(caplog)
    assert "job_id=17 message_id=0 file_id=0 stage=failed" in logs
    assert "reason=invalid_message_id" in logs
    assert SECRET not in logs + str(error.value)
    assert NORMALIZE_TRACE.get() == (0, 0, 0)


def test_real_connection_refused_reports_actual_cause(monkeypatch, caplog):
    monkeypatch.setenv("NO_PROXY", "127.0.0.1")
    # Занятый, но не слушающий сокет не позволяет случайно занять порт тесту.
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
        monkeypatch.setenv("PRX120_API_BASE", f"http://127.0.0.1:{port}")
        with pytest.raises(RuntimeError, match="connection_refused"):
            asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))
    logs = diagnostic_text(caplog)
    assert f"origin=http://127.0.0.1:{port}" in logs
    assert "job_id=17 message_id=12" in logs
    assert "stage=envelope_get result=error" in logs
    assert "errno=ECONNREFUSED" in logs
    assert SECRET not in logs


def test_real_tls_certificate_failure_is_classified(tmp_path, monkeypatch):
    if not shutil.which("openssl"):
        pytest.skip("для локального TLS-стенда нужен openssl")
    key = tmp_path / "key.pem"
    cert = tmp_path / "cert.pem"
    subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                    "-keyout", str(key), "-out", str(cert), "-days", "1", "-subj", "/CN=localhost"],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(str(cert), str(key))
    monkeypatch.setenv("NO_PROXY", "127.0.0.1")
    monkeypatch.delenv("SSL_CERT_FILE", raising=False)
    monkeypatch.delenv("SSL_CERT_DIR", raising=False)
    with socket.socket() as server:
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        server.settimeout(5)
        port = server.getsockname()[1]

        def serve_one():
            try:
                client, _ = server.accept()
                with context.wrap_socket(client, server_side=True) as connection:
                    connection.recv(4096)
            except (OSError, ssl.SSLError):
                pass  # клиент ожидаемо отклоняет самоподписанный сертификат

        thread = threading.Thread(target=serve_one, daemon=True)
        thread.start()
        ok, reason, _ = asyncio.run(site.get_json(
            "PRX390", "/text/1", base=f"https://127.0.0.1:{port}/prx390/api", safe_errors=True))
        thread.join(timeout=5)
    assert not thread.is_alive()
    assert not ok and "tls_certificate" in reason and "verify_code=" in reason
    assert SECRET not in reason


@pytest.mark.parametrize("status", [403, 404, 500])
def test_envelope_http_failure_never_logs_response_or_url_secrets(status, monkeypatch, caplog):
    monkeypatch.setenv("PRX390_API_BASE", f"https://user:{SECRET}@portal.example/prx390/api")
    mock_http(monkeypatch, lambda request: httpx.Response(status, json={"error": SECRET}))
    with pytest.raises(RuntimeError) as error:
        asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))
    logs = diagnostic_text(caplog)
    assert f"HTTP {status}" in str(error.value) and f"HTTP {status}" in logs
    assert "origin=https://portal.example" in logs
    assert "base_source=PRX390_API_BASE base_path=expected" in logs
    assert "userinfo=yes" in logs
    assert SECRET not in str(error.value) + logs


def test_envelope_html_with_status_200_is_not_misreported_as_missing_attachment(monkeypatch, caplog):
    monkeypatch.setenv("PRX120_API_BASE", "https://portal.example")
    mock_http(monkeypatch, lambda request: httpx.Response(200, text=f"<html>{SECRET}</html>"))
    with pytest.raises(RuntimeError, match="не JSON-объект"):
        asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))
    assert SECRET not in diagnostic_text(caplog)


def test_normalize_logs_each_step_but_not_filename_document_or_token(monkeypatch, caplog):
    monkeypatch.setenv("PRX120_API_BASE", "http://portal.example")
    seen = {}

    def response(request):
        assert request.headers["x-worker-token"] == SECRET
        if request.method == "GET" and request.url.path.endswith("/text/12"):
            return httpx.Response(200, json={"files": [{"file_id": 7, "filename": SECRET + ".pdf"}]})
        if request.method == "GET" and request.url.path.endswith("/file/7"):
            return httpx.Response(200, content=b"%PDF-1.4", headers={"content-type": "application/pdf"})
        if request.method == "POST":
            seen.update(parse_qs(request.content.decode()))
            return httpx.Response(200, json={"ok": True, "status": "text_ready"})
        raise AssertionError("unexpected request")

    async def extract(_dest, max_pages=5):
        return {"text": SECRET, "pages": 2, "engine": "pypdf", "confidence": None}

    mock_http(monkeypatch, response)
    monkeypatch.setattr(doc_normalize, "extract_text", extract)
    ctx = Ctx()
    result = asyncio.run(doc_normalize.handle(ctx, {"message_id": 12}))
    logs = diagnostic_text(caplog)
    assert result["file_id"] == 7 and seen["text"] == [SECRET]
    for stage in ("start", "site_config", "envelope_get", "file_get", "file_response", "extract", "text_post", "complete"):
        assert "stage=" + stage in logs
    assert "file_id=7 stage=file_response http=200" in logs
    assert "stage=text_post result=ok http=200 status=text_ready" in logs
    assert "attempt=2/3" in logs
    assert SECRET not in logs
    assert SECRET not in str(ctx.steps)
    assert NORMALIZE_TRACE.get() == (0, 0, 0)


def test_download_failure_does_not_leak_filename_or_exception(monkeypatch, caplog):
    monkeypatch.setenv("PRX120_API_BASE", "http://portal.example")

    async def get(*_args, **_kwargs):
        return True, "", {"files": [{"file_id": 7, "filename": SECRET + ".pdf"}]}

    async def download(_file_id, _dest):
        raise RuntimeError(SECRET)

    monkeypatch.setattr(site, "get_json", get)
    monkeypatch.setattr(doc_normalize, "_download", download)
    with pytest.raises(RuntimeError) as error:
        asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))
    logs = diagnostic_text(caplog)
    assert "file_id=7 stage=file_get result=error reason=RuntimeError" in logs
    assert SECRET not in logs + str(error.value)


def test_file_http_failure_is_correlated_and_never_logs_response(monkeypatch, caplog):
    monkeypatch.setenv("PRX120_API_BASE", "http://portal.example")

    def response(request):
        if request.url.path.endswith("/text/12"):
            return httpx.Response(200, json={"files": [{"file_id": 7, "filename": SECRET}]})
        return httpx.Response(404, text=SECRET)

    mock_http(monkeypatch, response)
    with pytest.raises(RuntimeError, match="HTTP 404") as error:
        asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))
    logs = diagnostic_text(caplog)
    assert "file_id=7 stage=file_response http=404" in logs
    assert "file_id=7 stage=file_get result=error reason=HTTP 404" in logs
    assert SECRET not in logs + str(error.value)


def test_extraction_errors_do_not_leak_document_or_exception(monkeypatch, caplog):
    monkeypatch.setenv("PRX120_API_BASE", "http://portal.example")

    async def get(*_args, **_kwargs):
        return True, "", {"files": [{"file_id": 7, "filename": SECRET}]}

    async def download(_file_id, dest):
        dest.write_bytes(b"%PDF")

    def bad_pypdf(_path):
        raise RuntimeError(SECRET)

    async def bad_ocr(*_args, **_kwargs):
        raise ValueError(SECRET)

    monkeypatch.setattr(site, "get_json", get)
    monkeypatch.setattr(doc_normalize, "_download", download)
    monkeypatch.setattr(doc_normalize, "_extract_pypdf", bad_pypdf)
    monkeypatch.setattr(ocr_client, "extract", bad_ocr)
    with pytest.raises(RuntimeError) as error:
        asyncio.run(doc_normalize.handle(Ctx(), {"message_id": 12}))
    logs = diagnostic_text(caplog)
    assert "file_id=7 stage=pypdf result=error error_type=RuntimeError" in logs
    assert "file_id=7 stage=ocr result=error error_type=ValueError" in logs
    assert SECRET not in logs + str(error.value)


def test_private_post_errors_and_tracebacks_do_not_include_response(monkeypatch, caplog):
    monkeypatch.setenv("PRX120_API_BASE", "http://portal.example")
    mock_http(monkeypatch, lambda request: httpx.Response(403, json={"error": SECRET}))
    ok, reason, response = asyncio.run(site.post_json(
        "PRX390", "/text/12", {"text": SECRET}, base="http://portal.example/prx390/api",
        retries=1, safe_errors=True))
    assert not ok and "HTTP 403" in reason and response == {}
    assert SECRET not in reason + diagnostic_text(caplog)
    assert not any(record.exc_info for record in caplog.records if record.name == "prx.site")


def test_private_post_network_error_has_no_raw_exception_or_traceback(monkeypatch, caplog):
    def fail(_request):
        raise httpx.ConnectError(SECRET) from ConnectionRefusedError(errno.ECONNREFUSED, SECRET)

    mock_http(monkeypatch, fail)
    ok, reason, _ = asyncio.run(site.post_json(
        "PRX390", "/text/12", {"text": SECRET}, base="http://portal.example/prx390/api",
        retries=1, safe_errors=True))
    assert not ok and "connection_refused" in reason
    assert SECRET not in reason + diagnostic_text(caplog)
    assert not any(record.exc_info for record in caplog.records if record.name == "prx.site")


def test_ocr_logs_keep_trace_and_hide_service_url_and_exception(tmp_path, caplog):
    path = tmp_path / (SECRET + ".pdf")
    path.write_bytes(b"%PDF")

    def fail(_request):
        raise httpx.ConnectError(SECRET) from ConnectionRefusedError(errno.ECONNREFUSED, SECRET)

    provider = ocr_client.RemoteOcrProvider(
        url=f"http://user:{SECRET}@ocr.example/{SECRET}?token={SECRET}",
        timeout=1, transport=httpx.MockTransport(fail))
    token = NORMALIZE_TRACE.set((17, 12, 7))
    try:
        result = asyncio.run(provider.extract(path))
    finally:
        NORMALIZE_TRACE.reset(token)
    logs = diagnostic_text(caplog)
    assert result.engine == "failed"
    assert "job_id=17 message_id=12 file_id=7 stage=ocr_remote" in logs
    assert "origin=http://ocr.example" in logs and "connection_refused" in logs
    assert SECRET not in logs


def test_concurrent_documents_keep_job_message_and_file_ids_together(monkeypatch, caplog):
    monkeypatch.setenv("PRX120_API_BASE", "http://portal.example")

    async def get(_prefix, path, **_kwargs):
        message = int(path.rsplit("/", 1)[-1])
        await asyncio.sleep(0)
        return True, "", {"files": [{"file_id": message + 100}]}

    async def download(_file_id, dest):
        await asyncio.sleep(0)
        dest.write_bytes(b"%PDF")

    async def extract(_path, max_pages=5):
        await asyncio.sleep(0)
        return {"text": SECRET, "pages": 1, "engine": "pypdf", "confidence": None}

    async def post(*_args, **_kwargs):
        await asyncio.sleep(0)
        return True, "", {"status": "text_ready"}

    monkeypatch.setattr(site, "get_json", get)
    monkeypatch.setattr(site, "post_json", post)
    monkeypatch.setattr(doc_normalize, "_download", download)
    monkeypatch.setattr(doc_normalize, "extract_text", extract)

    async def run():
        await asyncio.gather(doc_normalize.handle(Ctx(101), {"message_id": 11}),
                             doc_normalize.handle(Ctx(202), {"message_id": 22}))
    asyncio.run(run())
    records = [r.getMessage() for r in caplog.records if r.name == "prx.docflow"]
    assert any("job_id=101 message_id=11 file_id=111 stage=extract" in line for line in records)
    assert any("job_id=202 message_id=22 file_id=122 stage=extract" in line for line in records)
    assert all("job_id=101 message_id=22" not in line and "job_id=202 message_id=11" not in line
               for line in records)
    assert SECRET not in "\n".join(records)
    assert NORMALIZE_TRACE.get() == (0, 0, 0)
