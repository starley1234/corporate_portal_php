"""nj669.export: временный STL под размеры пользователя. Без LLM, без версии, без квоты."""
import asyncio
import struct

import pytest

from n2j_workers import site, solid
from n2j_workers.handlers import nj669_solid3d as mod


def stl():
    return bytes(80) + struct.pack('<I12fH', 1, 0, 0, 1, 0, 0, 0, 10, 0, 0, 0, 10, 0, 0)


class Context:
    def __init__(self):
        self.steps = []

    async def progress(self, phase, note=''):
        self.steps.append(phase)


PAYLOAD = {'export_id': 7, 'revision_id': 12, 'run_token': 'e' * 48}


@pytest.fixture
def wire(monkeypatch):
    sent = []
    uploads = []
    state = {'context': {'ok': True, 'source': 'width=30; cube(width);'}, 'upload': (True, '', {'ok': True})}

    async def post_json(prefix, path, data, **kw):
        sent.append((path, data))
        if path == '/export_context':
            return True, '', dict(state['context'])
        return True, '', {'ok': True}

    async def post_file(prefix, path, data, name, raw, **kw):
        uploads.append((path, dict(data), name, raw))
        return state['upload']

    monkeypatch.setattr(site, 'post_json', post_json)
    monkeypatch.setattr(site, 'post_file', post_file)
    monkeypatch.setattr(solid, 'render', lambda code: {'stl': stl(), 'info': {'triangles': 2}})
    return state, sent, uploads


def test_export_renders_user_source_and_uploads_stl_without_llm(wire):
    state, sent, uploads = wire
    ctx = Context()
    result = asyncio.run(mod.handle_export(ctx, PAYLOAD))
    assert result == {'export_id': 7, 'triangles': 2}
    assert [path for path, _ in sent] == ['/export_context', '/export_result']
    assert sent[0][1]['export_id'] == 7 and sent[0][1]['run_token'] == PAYLOAD['run_token']
    path, data, name, raw = uploads[0]
    assert path == '/export_upload' and name == 'part.stl' and raw == stl()
    assert data['export_id'] == '7' and data['revision_id'] == '12'
    assert ctx.steps[0].startswith('Собираю')


def test_export_rejects_incomplete_payload(wire):
    with pytest.raises(ValueError):
        asyncio.run(mod.handle_export(Context(), {'export_id': 0, 'revision_id': 12, 'run_token': 'x'}))
    with pytest.raises(ValueError):
        asyncio.run(mod.handle_export(Context(), {'export_id': 7, 'revision_id': 12, 'run_token': ''}))


def test_render_error_is_reported_to_user_with_technical_detail(wire, monkeypatch):
    state, sent, uploads = wire

    def broken(code):
        raise ValueError('ERROR: Parser error in file, line 3')

    monkeypatch.setattr(solid, 'render', broken)
    with pytest.raises(ValueError):
        asyncio.run(mod.handle_export(Context(), PAYLOAD))
    assert uploads == []
    fail = [data for path, data in sent if path == '/export_fail']
    assert len(fail) == 1
    assert fail[0]['export_id'] == 7
    assert fail[0]['error'] == mod.MSG_EXPORT
    assert 'Parser error' in fail[0]['detail']
    assert 'OpenSCAD' not in fail[0]['error']


def test_failed_delivery_is_not_reported_as_done(wire):
    state, sent, uploads = wire
    state['upload'] = (False, 'HTTP 500', {})
    with pytest.raises(RuntimeError):
        asyncio.run(mod.handle_export(Context(), PAYLOAD))
    paths = [path for path, _ in sent]
    assert '/export_result' not in paths
    assert '/export_fail' in paths

