"""Новая деталь: bounded repair, проверка референса/ракурсов, доставка и отказ без потери истории."""
import asyncio
import base64
import io
import json
import struct
from pathlib import Path

import pytest
from PIL import Image

from n2j_workers import glb, site, solid
from n2j_workers.handlers import nj669_solid3d as mod
from n2j_workers.usage import Usage


@pytest.fixture(autouse=True)
def fresh_usage():
    """Расход токенов берётся из текущего учёта задачи: каждый тест начинает с чистого."""
    Usage.begin()


def png():
    out = io.BytesIO()
    Image.new('RGB', (64, 64), '#4d98db').save(out, 'PNG')
    return out.getvalue()


def stl():
    return bytes(80) + struct.pack('<I12fH', 1, 0, 0, 1, 0, 0, 0, 10, 0, 0, 0, 10, 0, 0)


def built():
    return {'stl': stl(), 'glb': glb.stl_to_glb(stl()), 'png': png(),
            'views': [png(), png(), png()], 'color_note': '', 'info': {'triangles': 1}}


class LLM:
    def __init__(self, replies):
        self.replies = list(replies)
        self.calls = []

    async def chat(self, messages, model=None, **kw):
        self.calls.append((messages, model, kw))
        reply = self.replies.pop(0)
        if isinstance(reply, Exception):
            raise reply
        return reply


class Context:
    def __init__(self, replies):
        self.llm = LLM(replies)
        self.steps = []

    async def progress(self, phase, note=''):
        self.steps.append(phase)


PASS = json.dumps({'ok': True, 'summary': 'Кронштейн соответствует описанию.', 'issues': []})
FAIL = json.dumps({'ok': False, 'summary': 'Нужно исправить.', 'issues': ['Добавь отверстие 5 мм.']})
PAYLOAD = {'revision_id': 12, 'run_token': 'a' * 48, 'repairs': 2}
VERIFY_PAYLOAD = {'revision_id': 12, 'run_token': 'b' * 48}


@pytest.fixture
def api(monkeypatch):
    sent = []
    uploads = []
    context = {'ok': True, 'prompt': 'Кронштейн 30 мм', 'description': 'Деталь для полки',
               'source': '', 'model': 'code::m', 'vision_model': 'vision::m', 'photo': None}
    verify_context = {'ok': True, 'prompt': 'Кронштейн 30 мм', 'description': 'Деталь для полки',
                      'source': 'cube(30);', 'vision_model': 'vision::m', 'photo': None,
                      'views': [base64.b64encode(png()).decode()] * 3}
    replies = {'/context': context, '/verify_context': verify_context}

    async def post(prefix, path, data, **kw):
        sent.append((path, data, kw))
        return True, '', dict(replies[path]) if path in replies else {'ok': True}

    async def upload(prefix, path, data, name, raw, **kw):
        uploads.append((data.copy(), name, raw))
        return True, '', {'ok': True}

    monkeypatch.setattr(site, 'post_json', post)
    monkeypatch.setattr(site, 'post_file', upload)
    monkeypatch.setattr(solid, 'render', lambda code: built())
    return context, sent, uploads


def test_generate_writes_code_without_any_vision_review(api):
    context, sent, uploads = api
    ctx = Context(['width=30; cube(width);'])
    result = asyncio.run(mod.handle(ctx, PAYLOAD))
    assert result == {'revision_id': 12, 'attempts': 1, 'triangles': 1}
    assert [x[0] for x in sent] == ['/context', '/result']
    accepted = sent[-1][1]
    assert accepted['source'] == 'width=30; cube(width);'
    assert accepted['run_token'] == PAYLOAD['run_token']
    assert 'visual_ok' not in accepted and 'review' not in accepted
    assert [x[0]['kind'] for x in uploads] == ['stl', 'glb', 'png', 'png2', 'png3']
    assert uploads[0][2] == stl()
    # Ни одного изображения в запросах генерации: автоматической проверки ракурсов больше нет.
    assert len(ctx.llm.calls) == 1
    assert all(isinstance(c[0][1]['content'], str) for c in ctx.llm.calls)
    assert ctx.llm.calls[0][1] == 'code::m'
    assert 'Проверяю три ракурса' not in ctx.steps


def test_photo_is_described_once_by_vision_then_code_uses_text(api):
    context, sent, uploads = api
    context['photo'] = {'b64': base64.b64encode(png()).decode(), 'media_type': 'image/png'}
    ctx = Context(['Пластина с двумя отверстиями.', 'cube(30);'])
    asyncio.run(mod.handle(ctx, PAYLOAD))
    assert len(ctx.llm.calls) == 2
    assert ctx.llm.calls[0][1] == 'vision::m'
    assert ctx.llm.calls[1][1] == 'code::m'
    assert 'Пластина с двумя отверстиями.' in ctx.llm.calls[1][0][1]['content']
    photo_content = ctx.llm.calls[0][0][1]['content']
    assert sum(x['type'] == 'image_url' for x in photo_content) == 1
    assert isinstance(ctx.llm.calls[1][0][1]['content'], str)  # код получает только текст
    assert all('photo' not in x[1] for x in sent)  # фото — из context, не из payload


def test_render_error_repairs_with_the_actual_error_and_current_code(api, monkeypatch):
    context, sent, uploads = api
    context['source'] = 'diameter=4; cube(30);'
    def render(code):
        if code == 'diameter=4; cube(31);':
            raise ValueError('ERROR: Parser error in line 1')
        return built()
    monkeypatch.setattr(solid, 'render', render)
    ctx = Context(['diameter=4; cube(31);', 'diameter=4; cube(32);'])
    result = asyncio.run(mod.handle(ctx, PAYLOAD))
    assert result['attempts'] == 2
    first, second = ctx.llm.calls[0][0][1]['content'], ctx.llm.calls[1][0][1]['content']
    assert 'Текущий код:\ndiameter=4; cube(30);' in first
    assert 'Сборка не прошла' in second and 'Parser error in line 1' in second
    assert 'diameter=4; cube(31);' in second
    assert sent[-1][1]['source'] == 'diameter=4; cube(32);'


def test_repair_budget_is_spent_on_compile_errors_only(api, monkeypatch):
    context, sent, uploads = api
    monkeypatch.setattr(solid, 'render', lambda code: (_ for _ in ()).throw(ValueError('ERROR: line 3')))
    ctx = Context(['cube(0);'] * 3)
    with pytest.raises(ValueError, match='Модель не собралась'):
        asyncio.run(mod.handle(ctx, PAYLOAD))
    assert len(ctx.llm.calls) == 3
    assert sent[-1][0] == '/fail' and uploads == []


def test_repairs_zero_means_single_attempt(api, monkeypatch):
    context, sent, uploads = api
    monkeypatch.setattr(solid, 'render', lambda code: (_ for _ in ()).throw(ValueError('ERROR')))
    ctx = Context(['cube(0);'])
    with pytest.raises(ValueError):
        asyncio.run(mod.handle(ctx, dict(PAYLOAD, repairs=0)))
    assert len(ctx.llm.calls) == 1


def test_manual_render_skips_llm_and_does_not_call_vision(api):
    context, sent, uploads = api
    context['source'] = 'cube(30);'
    ctx = Context([])
    asyncio.run(mod.handle(ctx, dict(PAYLOAD, code_only=True)))
    assert ctx.llm.calls == []
    assert [x[0] for x in sent] == ['/context', '/result']


def test_manual_broken_code_gets_one_llm_repair(api, monkeypatch):
    context, sent, uploads = api
    context['source'] = 'cube(;'
    def render(code):
        if code == 'cube(;':
            raise ValueError('Syntax error')
        return built()
    monkeypatch.setattr(solid, 'render', render)
    ctx = Context(['cube(30);'])
    result = asyncio.run(mod.handle(ctx, dict(PAYLOAD, code_only=True)))
    assert result['attempts'] == 2 and len(ctx.llm.calls) == 1
    assert 'Syntax error' in ctx.llm.calls[0][0][1]['content']


def test_no_engine_delivers_fail_and_raises(api, monkeypatch):
    context, sent, uploads = api
    monkeypatch.setattr(solid, 'render', lambda code: (_ for _ in ()).throw(RuntimeError('OpenSCAD не найден')))
    with pytest.raises(RuntimeError, match='OpenSCAD не найден'):
        asyncio.run(mod.handle(Context(['cube(30);']), PAYLOAD))
    assert sent[-1][0] == '/fail' and uploads == []


def test_upload_refusal_never_marks_result_done(api, monkeypatch):
    context, sent, uploads = api
    async def refuse(*args, **kw):
        return False, 'HTTP 413', {}
    monkeypatch.setattr(site, 'post_file', refuse)
    with pytest.raises(RuntimeError, match='413'):
        asyncio.run(mod.handle(Context(['cube(30);']), PAYLOAD))
    assert [x[0] for x in sent] == ['/context', '/fail']


def test_already_delivered_is_idempotent(api):
    context, sent, uploads = api
    context['already_done'] = True
    ctx = Context([])
    result = asyncio.run(mod.handle(ctx, PAYLOAD))
    assert result['already_done'] and not ctx.llm.calls and not uploads


def test_verify_sends_three_views_and_stores_verdict_only(api):
    context, sent, uploads = api
    ctx = Context([PASS])
    result = asyncio.run(mod.handle_verify(ctx, VERIFY_PAYLOAD))
    assert result == {'revision_id': 12, 'visual_ok': True}
    assert [x[0] for x in sent] == ['/verify_context', '/verify_result']
    assert sent[-1][1] == {'revision_id': 12, 'run_token': 'b' * 48, 'visual_ok': True,
                           'review': 'Кронштейн соответствует описанию.', 'tokens': {'in': 0, 'out': 0, 'calls': 0}}
    assert uploads == []  # проверка ничего не загружает и не меняет геометрию
    assert ctx.llm.calls[0][1] == 'vision::m'
    content = ctx.llm.calls[0][0][1]['content']
    assert sum(x['type'] == 'image_url' for x in content) == 3
    assert 'Проверяю три ракурса' in ctx.steps


def test_verify_failure_is_reported_with_issues(api):
    context, sent, uploads = api
    result = asyncio.run(mod.handle_verify(Context([FAIL]), VERIFY_PAYLOAD))
    assert result['visual_ok'] is False
    assert 'Добавь отверстие 5 мм.' in sent[-1][1]['review']


def test_verify_broken_model_answer_is_delivered_as_error(api):
    context, sent, uploads = api
    with pytest.raises(ValueError, match='однозначный'):
        asyncio.run(mod.handle_verify(Context(['не JSON']), VERIFY_PAYLOAD))
    assert [x[0] for x in sent] == ['/verify_context', '/verify_fail']
    assert sent[-1][1]['error']


def test_verify_needs_exactly_three_views(api, monkeypatch):
    context, sent, uploads = api
    async def post(prefix, path, data, **kw):
        sent.append((path, data, kw))
        return True, '', {'ok': True, 'views': ['AAAA']}
    monkeypatch.setattr(site, 'post_json', post)
    with pytest.raises(ValueError, match='три ракурса'):
        asyncio.run(mod.handle_verify(Context([PASS]), VERIFY_PAYLOAD))
    assert sent[-1][0] == '/verify_fail'


def test_verify_cannot_exceed_its_own_budget(api, monkeypatch):
    context, sent, uploads = api
    async def slow(*args, **kw):
        await asyncio.Event().wait()
    monkeypatch.setattr(mod, 'verify', slow)
    monkeypatch.setattr(mod, 'VERIFY_BUDGET', 0.01)
    with pytest.raises(asyncio.TimeoutError):
        asyncio.run(mod.handle_verify(Context([]), VERIFY_PAYLOAD))
    assert sent[-1][0] == '/verify_fail' and sent[-1][1]['error']


@pytest.mark.parametrize('code', [
    'include </etc/passwd> cube(1);', 'cube(1);use <../../x.scad>',
    'module x(){ import("/etc/passwd"); } x();', 'surface(file="/etc/passwd");',
    '/*ok*/ include/*comment*/<secrets.scad>', 'a=1; use\n<x.scad>', '', ' ' * 60001,
])
def test_unsafe_or_oversized_source_rejected(code):
    with pytest.raises(ValueError):
        solid.validate_source(code)


@pytest.mark.parametrize('code', [
    'cube(1); // import("x");', '/* include <x> */ cube(1);',
    'text("include import surface use");', 'width=30;\ndifference(){cube(width); cylinder(h=40,r=2);}',
])
def test_comment_and_string_are_not_file_access(code):
    assert solid.validate_source(code) == code


@pytest.mark.parametrize('value', ['{}', '{"ok":"true","summary":"x","issues":[]}', '{"ok":true}', '{"ok":true,"summary":"x","issues":[1]}'])
def test_review_schema_requires_real_boolean_and_messages(value):
    with pytest.raises(ValueError):
        mod._review(value)


def test_normalize_ascii_binary_and_nonfinite():
    ascii_stl = b'solid a\nfacet normal 0 0 1\nouter loop\nvertex 0 0 0\nvertex 10 0 0\nvertex 0 10 0\nendloop\nendfacet\nendsolid a'
    normalized = glb.normalize_stl(ascii_stl)
    assert len(normalized) == 134
    assert glb.normalize_stl(normalized) == normalized
    bad = bytearray(stl())
    struct.pack_into('<f', bad, 96, float('nan'))
    with pytest.raises(glb.GlbError, match='конечны'):
        glb.normalize_stl(bytes(bad))


def test_real_export_command_contract_and_distinct_cameras(monkeypatch):
    calls = []
    monkeypatch.setattr(solid.scad, 'openscad_bin', lambda: '/fake/openscad')
    monkeypatch.setattr(solid.shutil, 'which', lambda command: None)
    def fake_run(cmd, output, timeout):
        calls.append((cmd, timeout))
        path = Path(output)
        if path.suffix == '.stl':
            path.write_bytes(stl())
        elif path.suffix == '.wrl':
            return False, 'unknown export format'
        else:
            path.write_bytes(png())
        return True, ''
    monkeypatch.setattr(solid.scad, '_run', fake_run)
    result = solid.render('cube(30);')
    assert result['stl'] == stl() and result['glb'].startswith(b'glTF')
    assert 'одним цветом' in result['color_note']
    cameras = [arg for cmd, _ in calls for arg in cmd if arg.startswith('--camera=')]
    assert len(cameras) == len(set(cameras)) == 3
    assert all(timeout <= 150 for _, timeout in calls)


def test_common_multipart_client_token_and_bytes(monkeypatch):
    monkeypatch.setenv('NJ669_API_BASE', 'https://example.test/solid3d/w')
    monkeypatch.setenv('NJ669_SITE_TOKEN', 'test-token')
    captured = {}
    class Response:
        is_success = True
        status_code = 200
        def json(self): return {'ok': True}
    class Client:
        def __init__(self, **kw): pass
        async def __aenter__(self): return self
        async def __aexit__(self, *args): pass
        async def post(self, url, **kw): captured.update(url=url, **kw); return Response()
    monkeypatch.setattr(site.httpx, 'AsyncClient', Client)
    ok, _, _ = asyncio.run(site.post_file('NJ669', '/upload', {'kind':'stl'}, 'part.stl', stl(), base=site.module_api_base('NJ669', '/solid3d/w')))
    assert ok and captured['url'] == 'https://example.test/solid3d/w/upload'
    assert captured['headers'] == {'X-Worker-Token': 'test-token'}
    assert captured['files']['file'][1] == stl()
