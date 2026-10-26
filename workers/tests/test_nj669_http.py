"""Фактический адрес приёмной, диагностика HTML-404 и отсутствие токенов в URL/ошибках."""
import asyncio

import httpx
import pytest

from n2j_workers import site
from n2j_workers.handlers import nj669_assist, nj669_solid3d


@pytest.fixture
def http(monkeypatch):
    for name in ('NJ669_API_BASE', 'NJ120_API_BASE', 'NJ120_API_URL'):
        monkeypatch.delenv(name, raising=False)
    monkeypatch.setenv('NJ669_SITE_TOKEN', 'worker-secret')
    captured = []
    response = {'status': 200, 'body': {'ok': True}}

    class Client:
        def __init__(self, **kwargs):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, *args):
            pass

        async def post(self, url, **kwargs):
            captured.append((url, kwargs))
            request = httpx.Request('POST', url)
            body = response['body']
            return (httpx.Response(response['status'], json=body, request=request)
                    if isinstance(body, dict) else
                    httpx.Response(response['status'], text=body, request=request))

    async def no_sleep(*args):
        pass

    monkeypatch.setattr(site.httpx, 'AsyncClient', Client)
    monkeypatch.setattr(site.asyncio, 'sleep', no_sleep)
    return captured, response


@pytest.mark.parametrize('env', [
    {'NJ669_API_BASE': 'https://n2j.ru/solid3d/w'},
    {'NJ669_API_BASE': 'https://n2j.ru/solid3d/w/'},
    {'NJ120_API_BASE': 'https://n2j.ru'},
    {'NJ120_API_URL': 'https://n2j.ru/nj120/api'},
])
@pytest.mark.parametrize('action', ['context', 'assist_context'])
def test_correct_endpoint_is_used_once(http, monkeypatch, env, action):
    captured, _ = http
    for key, value in env.items():
        monkeypatch.setenv(key, value)
    if action == 'context':
        asyncio.run(nj669_solid3d._api({'revision_id': 12, 'run_token': 'revision-secret'}, action))
    else:
        asyncio.run(nj669_assist._api({'task_id': 12, 'run_token': 'revision-secret'}, action))
    assert len(captured) == 1
    url, kwargs = captured[0]
    assert url == 'https://n2j.ru/solid3d/w/' + action
    assert kwargs['headers'] == {'X-Worker-Token': 'worker-secret'}
    assert kwargs['json']['run_token'] == 'revision-secret'
    assert 'secret' not in url


def test_html_404_reports_actual_endpoint_not_html_document(http, monkeypatch):
    _, response = http
    monkeypatch.setenv('NJ669_API_BASE', 'https://n2j.ru/solid3d/w')
    response.update(status=404, body='<!DOCTYPE html><html><title>Сайт</title></html>')
    with pytest.raises(RuntimeError) as caught:
        asyncio.run(nj669_solid3d._api({'revision_id': 12, 'run_token': 'revision-secret'}, 'context'))
    message = str(caught.value)
    assert 'HTTP 404' in message and 'HTML вместо JSON' in message
    assert 'https://n2j.ru/solid3d/w/context' in message
    assert '<html' not in message and '<!DOCTYPE' not in message and 'secret' not in message


def test_wrong_common_base_is_visible_as_duplicate_path(http, monkeypatch):
    _, response = http
    # Не меняем конвенцию общего клиента молча: диагностика показывает реальный сбой настройки.
    monkeypatch.setenv('NJ120_API_BASE', 'https://n2j.ru/solid3d/w')
    response.update(status=404, body='<html>404</html>')
    with pytest.raises(RuntimeError, match='/solid3d/w/solid3d/w/context'):
        asyncio.run(nj669_solid3d._api({'revision_id': 12, 'run_token': 'revision-secret'}, 'context'))


def test_json_error_preserved_but_url_secrets_redacted(http):
    _, response = http
    response.update(status=403, body={'ok': False, 'error': 'Неверный токен'})
    ok, reason, _ = asyncio.run(site.post_json('NJ669', '/context', {}, retries=1,
        base='https://user:password@n2j.ru/solid3d/w?token=url-secret'))
    assert not ok and 'Неверный токен' in reason
    assert 'https://n2j.ru/solid3d/w' in reason
    assert all(secret not in reason for secret in ('user:', 'password', 'url-secret', 'worker-secret'))


def test_multipart_error_also_has_safe_actual_address(http, monkeypatch):
    _, response = http
    monkeypatch.setenv('NJ669_API_BASE', 'https://n2j.ru/solid3d/w')
    response.update(status=404, body='<html>Нет маршрута</html>')
    ok, reason, _ = asyncio.run(site.post_file('NJ669', '/upload', {'run_token': 'revision-secret'},
        'part.stl', b'binary', base=site.module_api_base('NJ669', '/solid3d/w')))
    assert not ok and 'https://n2j.ru/solid3d/w/upload' in reason
    assert 'HTML вместо JSON' in reason and 'secret' not in reason
