"""Local servers need not support OpenAI json_object mode."""
import asyncio

import httpx
import pytest

from prx_workers.handlers.prx392_analysis import analyze
from prx_workers.llm import LlmConfigError


class Context:
    def __init__(self, replies):
        self.llm = self
        self.replies = iter(replies)
        self.calls = []

    async def progress(self, *args):
        pass

    async def chat_with_usage(self, messages, **options):
        self.calls.append(options.copy())
        result = next(self.replies)
        if isinstance(result, Exception):
            raise result
        return result, {}


def failure(body, status=400):
    response = httpx.Response(status, text=body, request=httpx.Request('POST', 'https://llm.example/v1/chat/completions'))
    return httpx.HTTPStatusError('bad request', request=response.request, response=response)


def setup_api(monkeypatch, response_format=None):
    posted = []

    async def get(*args, **kwargs):
        return True, '', {'model': 'local::test', 'messages': [{'role': 'user', 'content': 'document'}],
                          'response_format': response_format}

    async def post(*args, **kwargs):
        posted.append(args)
        return True, '', {}

    monkeypatch.setattr(analyze.site, 'get_json', get)
    monkeypatch.setattr(analyze.site, 'post_json', post)
    return posted


def test_no_forced_response_format(monkeypatch):
    posted = setup_api(monkeypatch)
    ctx = Context(['{"type":"other"}'])
    asyncio.run(analyze.handle(ctx, {'run_id': 3}))
    assert 'response_format' not in ctx.calls[0]
    assert len(posted) == 1


def test_legacy_json_object_rejected_then_plain_json_succeeds(monkeypatch):
    posted = setup_api(monkeypatch, {'type': 'json_object'})
    ctx = Context([failure('response_format json_object unsupported; use json_schema'), '{"type":"other"}'])
    asyncio.run(analyze.handle(ctx, {'run_id': 3}))
    assert ctx.calls[0]['response_format'] == {'type': 'json_object'}
    assert 'response_format' not in ctx.calls[1]
    assert len(posted) == 1


def test_unrelated_bad_request_is_not_retried_and_keeps_server_reason(monkeypatch):
    posted = setup_api(monkeypatch)
    ctx = Context([failure('context length exceeded')])
    with pytest.raises(LlmConfigError, match='context length exceeded'):
        asyncio.run(analyze.handle(ctx, {'run_id': 3}))
    assert len(ctx.calls) == 1
    assert not posted


def test_invalid_json_is_never_saved(monkeypatch):
    posted = setup_api(monkeypatch)
    with pytest.raises(ValueError, match='JSON'):
        asyncio.run(analyze.handle(Context(['not json']), {'run_id': 3}))
    assert not posted
