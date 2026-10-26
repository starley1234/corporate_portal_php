"""Помощники деталей: строгий формат, ограниченный ремонт ответа, доставка/повторы."""
import asyncio
import json

import pytest

from n2j_workers import site
from n2j_workers.handlers import HANDLERS, nj669_assist as mod
from n2j_workers.usage import Usage

PAYLOAD = {'task_id': 5, 'run_token': 'a' * 48}
CARD = {'title': 'Кронштейн 30 мм', 'summary': 'Крепление для полки', 'tags': 'полка, крепление'}


class Context:
    def __init__(self, replies, finish='stop'):
        self.llm = self
        self.replies = iter(replies)
        self.calls = []
        self.steps = []
        self.finish = finish

    async def chat_with_usage(self, messages, **kwargs):
        self.calls.append((messages, kwargs))
        value = next(self.replies)
        if isinstance(value, Exception):
            raise value
        # Как настоящий LLM-клиент: расход уходит в Usage задачи, finish_reason — в usage.
        if Usage.current() is not None:
            Usage.current().add_llm('p', 'm', {'prompt_tokens': 100, 'completion_tokens': 20})
        return value, {'_llm_finish_reason': self.finish}

    async def progress(self, phase, note=''):
        self.steps.append((phase, note))


@pytest.fixture
def api(monkeypatch):
    Usage.begin()
    context = {'ok': True, 'kind': 'caption', 'model': 'cheap::model', 'context': {'source': 'cube(30);'}}
    sent = []

    async def post(prefix, path, data, **kwargs):
        sent.append((prefix, path, data, kwargs))
        return True, '', context if path == '/assist_context' else {'ok': True}

    monkeypatch.setattr(site, 'post_json', post)
    return context, sent


def test_registry():
    assert HANDLERS['nj669.ideas'] is mod.handle_ideas
    assert HANDLERS['nj669.caption'] is mod.handle_caption


def test_caption_delivery_and_model_snapshot(api):
    context, sent = api
    ctx = Context([json.dumps(CARD)])
    result = asyncio.run(mod.handle_caption(ctx, PAYLOAD))
    assert result == {'task_id': 5, 'kind': 'caption'}
    assert [call[1] for call in sent] == ['/assist_context', '/assist_result']
    assert sent[-1][2] == dict(PAYLOAD, result=CARD, tokens={'in': 100, 'out': 20, 'calls': 1})
    assert sent[-1][3]['json_body'] is True
    assert ctx.calls[0][1]['model'] == 'cheap::model'
    assert ctx.calls[0][1]['request_timeout'] == 180
    assert 'cube(30);' in ctx.calls[0][0][1]['content']


def test_ideas_repair_once_and_deduplicate(api):
    context, sent = api
    context['kind'] = 'ideas'
    context['context'] = {'theme': 'Кухня'}
    ctx = Context(['не JSON', json.dumps({'ideas': ['Крючок 30 мм', ' Крючок 30 мм ', 'Заглушка']})])
    asyncio.run(mod.handle_ideas(ctx, PAYLOAD))
    assert len(ctx.calls) == 2
    assert 'Исправь формат' in ctx.calls[-1][0][1]['content']
    assert sent[-1][2]['result']['ideas'] == ['Крючок 30 мм', 'Заглушка']


def test_two_invalid_answers_fail_without_result(api):
    context, sent = api
    ctx = Context(['не JSON', '{}'])
    with pytest.raises(ValueError):
        asyncio.run(mod.handle_caption(ctx, PAYLOAD))
    assert len(ctx.calls) == 2
    assert [x[1] for x in sent] == ['/assist_context', '/assist_fail']
    assert sent[-1][2]['error']


def test_duplicate_already_done_does_not_call_llm(api):
    context, sent = api
    context.update(already_done=True)
    ctx = Context([])
    assert asyncio.run(mod.handle_caption(ctx, PAYLOAD))['already_done'] is True
    assert not ctx.calls and len(sent) == 1


@pytest.mark.parametrize('payload', [{}, {'task_id': 1}, {'run_token': 'x'}])
def test_bad_payload(payload):
    with pytest.raises(ValueError):
        asyncio.run(mod.handle_caption(Context([]), payload))


@pytest.mark.parametrize('failure', [RuntimeError('провайдер недоступен'), TimeoutError('таймаут')])
def test_llm_error_delivers_fail_and_reraises(api, failure):
    _, sent = api
    with pytest.raises(type(failure)):
        asyncio.run(mod.handle_caption(Context([failure]), PAYLOAD))
    assert sent[-1][1] == '/assist_fail'


def test_wrong_channel_does_not_call_model(api):
    context, sent = api
    context['kind'] = 'ideas'
    ctx = Context([])
    with pytest.raises(ValueError, match='Канал'):
        asyncio.run(mod.handle_caption(ctx, PAYLOAD))
    assert not ctx.calls and sent[-1][1] == '/assist_fail'


def test_no_llm_delivers_failure(api):
    _, sent = api
    ctx = Context([])
    ctx.llm = None
    with pytest.raises(RuntimeError, match='не настроена'):
        asyncio.run(mod.handle_caption(ctx, PAYLOAD))
    assert sent[-1][1] == '/assist_fail'


def test_site_refusal_cannot_be_reported_as_success(monkeypatch):
    async def post(*args, **kwargs):
        return True, '', {'ok': False, 'error': 'токен неверен'}
    monkeypatch.setattr(site, 'post_json', post)
    with pytest.raises(RuntimeError, match='токен неверен'):
        asyncio.run(mod.handle_caption(Context([]), PAYLOAD))


def test_ideas_accept_plain_list_and_numbered_text(api):
    context, sent = api
    context['kind'] = 'ideas'
    lines = '\n'.join(f'{n}. Крючок для полки номер {n} на 30 мм' for n in range(1, 6))
    ctx = Context([lines])
    asyncio.run(mod.handle_ideas(ctx, PAYLOAD))
    assert len(ctx.calls) == 1 and len(sent[-1][2]['result']['ideas']) == 5


def test_ideas_too_many_or_too_long_are_cut_not_rejected(api):
    context, sent = api
    context['kind'] = 'ideas'
    ideas = ['Идея ' + str(n) + ' ' + 'а' * 700 for n in range(40)]
    ctx = Context([json.dumps({'ideas': ideas})])
    asyncio.run(mod.handle_ideas(ctx, PAYLOAD))
    result = sent[-1][2]['result']['ideas']
    assert len(result) == 30 and max(len(x) for x in result) == 600


def test_empty_answer_is_repaired_once_and_reason_is_visible(api):
    context, sent = api
    context['kind'] = 'ideas'
    ctx = Context(['', ''], finish='length')
    with pytest.raises(ValueError, match='обрезан'):
        asyncio.run(mod.handle_ideas(ctx, PAYLOAD))
    assert len(ctx.calls) == 2
    assert sent[-1][1] == '/assist_fail' and 'обрезан' in sent[-1][2]['error']
    assert sent[-1][2]['tokens'] == {'in': 200, 'out': 40, 'calls': 2}


@pytest.mark.parametrize('data,kind', [
    ([], 'caption'), ({}, 'caption'), (dict(CARD, title=''), 'caption'),
    (dict(CARD, title=['x']), 'caption'), (dict(CARD, tags='я' * 301), 'caption'),
    (dict(CARD, summary='a' * 2001), 'caption'),
    ({'ideas': []}, 'ideas'), ({'ideas': [None]}, 'ideas'), ({'ideas': ['']}, 'ideas'),
    ({'ideas': {'a': 'b'}}, 'ideas'),
])
def test_strict_schema(data, kind):
    with pytest.raises(ValueError):
        mod.parse_result(json.dumps(data), kind)
