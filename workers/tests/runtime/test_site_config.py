import logging

import httpx
import pytest

from prx_workers import site
from prx_workers.config import _pg_dsn, load_environment
from prx_workers.site_check import check_site


def test_worker_reads_only_shared_settings_and_keeps_overrides(tmp_path, monkeypatch):
    keys = ('APP_URL', 'PRX120_API_BASE', 'WORKER_API_TOKEN', 'WORKER_NAME', 'AUTH_HASH_KEY',
            'DB_DEFAULT_HOST', 'DB_DEFAULT_PORT', 'DB_DEFAULT_NAME', 'DB_DEFAULT_USER', 'DB_DEFAULT_PASS',
            'DB_DEFAULT_SCHEMA', 'WORKER_PG_DSN', 'PRX120_API_URL', 'WORKER_API_URL')
    for key in keys:
        monkeypatch.delenv(key, raising=False)
        # Register the unset initial state for cleanup even when the key wasn't present.
        monkeypatch.setenv(key, '')
        monkeypatch.delenv(key)
    monkeypatch.setenv('WORKER_NAME', 'systemd-worker')
    (tmp_path / '.env').write_text('APP_URL=https://portal.toolforge.ru # canonical\n'
                                 "WORKER_API_TOKEN='token-with#hash' # php\n"
                                 'AUTH_HASH_KEY=php-only\nDB_DEFAULT_NAME=prx\nDB_DEFAULT_USER=prx\n'
                                 "DB_DEFAULT_HOST=::1\nDB_DEFAULT_PASS='@:/#'\nDB_DEFAULT_SCHEMA=portal\n")
    worker = tmp_path / 'workers'
    worker.mkdir()
    (worker / '.env').write_text('WORKER_NAME=in-file\n')
    load_environment(worker)
    import os
    assert os.environ['WORKER_NAME'] == 'systemd-worker'
    assert os.environ['WORKER_API_TOKEN'] == 'token-with#hash'
    assert 'AUTH_HASH_KEY' not in os.environ
    assert _pg_dsn() == 'postgresql://prx:%40%3A%2F%23@[::1]:5432/prx?search_path=portal'
    assert site.site_root() == ('https://portal.toolforge.ru', 'APP_URL')
    assert site.queue_api_url() == 'https://portal.toolforge.ru/prx120/api'
    monkeypatch.setenv('PRX120_API_BASE', 'http://nginx')
    assert site.module_api_config('PRX390', '/prx390/api') == ('http://nginx/prx390/api', 'PRX120_API_BASE')


@pytest.mark.asyncio
async def test_site_checks_both_real_paths_without_showing_tokens(monkeypatch, caplog):
    caplog.set_level(logging.INFO, logger='prx.site')
    monkeypatch.setenv('PRX120_API_BASE', 'https://portal.toolforge.ru')
    monkeypatch.delenv('PRX390_API_BASE', raising=False)
    monkeypatch.delenv('PRX395_DOCUMENT_EXTRACT_API_BASE', raising=False)
    monkeypatch.delenv('PRX396_PDF_CHUNKS_API_BASE', raising=False)
    monkeypatch.setenv('PRX390_SITE_TOKEN', 'legacy-token')

    def handler(request):
        if request.url.path == '/prx395/worker/health':
            assert request.headers['X-Worker-Token'] == 'secret-probe-token'
            return httpx.Response(200, json={'ok': True, 'module': 'prx395'})
        if request.url.path == '/prx396/worker/health':
            assert request.headers['X-Worker-Token'] == 'secret-probe-token'
            return httpx.Response(200, json={'ok': True, 'module': 'prx396'})
        assert request.url.path == '/prx390/api/health'
        assert request.headers['X-Worker-Token'] == 'legacy-token'
        raise httpx.ConnectError('secret-probe-token') from ConnectionRefusedError(111, 'legacy-token')

    assert await check_site('secret-probe-token', legacy=True, transport=httpx.MockTransport(handler)) == 1
    assert 'PHP принял токен' in caplog.text and 'connection_refused' in caplog.text
    assert 'secret-probe-token' not in caplog.text and 'legacy-token' not in caplog.text


@pytest.mark.asyncio
@pytest.mark.parametrize('response', [httpx.Response(200, text='<html>Login</html>'),
                                       httpx.Response(302, headers={'Location': 'https://other.example/private-token'}),
                                       httpx.Response(401, text='private-token')])
async def test_check_site_rejects_login_wrong_token_and_redirect(monkeypatch, caplog, response):
    monkeypatch.setenv('PRX395_DOCUMENT_EXTRACT_API_BASE', 'https://portal.toolforge.ru/prx395/worker')
    assert await check_site('secret', legacy=False, transport=httpx.MockTransport(lambda _: response)) == 1
    assert f'HTTP {response.status_code}' in caplog.text
    assert 'private-token' not in caplog.text


@pytest.mark.asyncio
@pytest.mark.parametrize('enabled', [True, False])
async def test_legacy_state_comes_from_authenticated_php_route(monkeypatch, enabled):
    from prx_workers.handlers import enabled as docflow_enabled

    monkeypatch.setenv('PRX395_DOCUMENT_EXTRACT_API_BASE', 'https://portal.toolforge.ru/prx395/worker')

    def handler(request):
        assert request.headers['X-Worker-Token'] == 'test-token'
        assert request.url.path == '/prx395/worker/health'
        return httpx.Response(200, json={'ok': True, 'module': 'prx395', 'docflow_enabled': enabled})

    assert await docflow_enabled('test-token', transport=httpx.MockTransport(handler)) is enabled
