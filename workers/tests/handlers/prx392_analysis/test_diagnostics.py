import json
from prx_workers.handlers.prx392_analysis import diagnostics


def test_reply_is_private_bounded_and_retained(tmp_path, monkeypatch):
    folder = tmp_path / 'private'
    monkeypatch.setattr(diagnostics, 'DIRECTORY', folder)
    diagnostics.save_reply(3, 'local::test', 'я' * 70000, 'delivery_failed', 'HTTP 403')
    target = folder / 'run-3.json'
    data = json.loads(target.read_text())
    assert data['run_id'] == 3 and data['status'] == 'delivery_failed'
    assert data['error'] == 'HTTP 403' and data['truncated']
    assert len(data['response'].encode()) <= diagnostics.MAX_BYTES
    assert target.stat().st_mode & 0o777 == 0o600
    assert folder.stat().st_mode & 0o777 == 0o700
    for n in range(25):
        diagnostics.save_reply(100 + n, 'local::test', '{}', 'delivered')
    assert len(list(folder.glob('run-*.json'))) == 20
    assert not list(folder.glob('tmp*'))


def test_diagnostic_io_failure_does_not_fail_analysis(tmp_path, monkeypatch):
    file = tmp_path / 'file'
    file.touch()
    monkeypatch.setattr(diagnostics, 'DIRECTORY', file)
    diagnostics.save_reply(1, 'local::test', '{}', 'received')
