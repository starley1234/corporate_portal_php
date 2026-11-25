import re
import sys
from pathlib import Path

import httpx
import pytest
from pypdf import PdfWriter

from prx_workers.base import PermanentJobError
from prx_workers.config import load_environment
from prx_workers.handlers.prx395_document_extract import document_extract, handler, pdf_probe
from prx_workers.handlers.prx395_document_extract.limits import MIB, limits


ROOT = next(path for path in Path(__file__).resolve().parents if (path / 'application').is_dir())


def documented_limits():
    return dict(re.findall(r'^# (PRX395_[A-Z_]+)=([0-9]+)$', (ROOT / '.env.example').read_text(), re.M))


def test_worker_defaults_match_shared_env_example(monkeypatch):
    documented = documented_limits()
    assert len(documented) == 7
    for name in documented:
        monkeypatch.delenv(name, raising=False)
    assert limits() == {name.removeprefix('PRX395_').lower(): int(value) for name, value in documented.items()}


def test_worker_loads_root_limits_and_honors_overrides(tmp_path, monkeypatch):
    configured = {
        'PRX395_MAX_PDF_BYTES': 12 * MIB, 'PRX395_MAX_PAGES': 40,
        'PRX395_MAX_TEXT_BYTES': 2 * MIB, 'PRX395_RESULT_TTL_HOURS': 36,
        'PRX395_TASK_TIMEOUT_SEC': 420, 'PRX395_MAX_ATTEMPTS': 4, 'PRX395_PDF_MEMORY_MB': 512,
    }
    assert configured.keys() == documented_limits().keys()
    for name in configured:
        monkeypatch.setenv(name, '')  # Register the original value for test cleanup.
        monkeypatch.delenv(name)
    worker = tmp_path / 'workers'
    worker.mkdir()
    (tmp_path / '.env').write_text('\n'.join(f'{name}={value}' for name, value in configured.items()))
    monkeypatch.setenv('PRX395_MAX_ATTEMPTS', '7')
    (worker / '.env').write_text('PRX395_MAX_PAGES=33\nPRX395_MAX_ATTEMPTS=5\n')
    load_environment(worker)
    expected = {name.removeprefix('PRX395_').lower(): value for name, value in configured.items()}
    assert limits() == {**expected, 'max_pages': 33, 'max_attempts': 7}


@pytest.mark.parametrize('bad', ['0', '201', '1.5', '01', '99999999999999999999'])
def test_invalid_or_out_of_range_values_fall_back(monkeypatch, bad):
    monkeypatch.setenv('PRX395_MAX_PAGES', bad)
    assert limits()['max_pages'] == 20
    monkeypatch.setenv('PRX395_MAX_PAGES', ' 200 ')
    assert limits()['max_pages'] == 200


@pytest.mark.asyncio
async def test_probe_subprocess_reads_configured_page_limit(tmp_path, monkeypatch):
    path = tmp_path / 'two-pages.pdf'
    writer = PdfWriter()
    for _ in range(2):
        writer.add_blank_page(width=595, height=842)
    writer.write(path)
    monkeypatch.setenv('PRX395_MAX_PAGES', '1')
    with pytest.raises(PermanentJobError, match='^page_limit$'):
        await document_extract.probe_pdf(path, local=False)
    monkeypatch.setenv('PRX395_MAX_PAGES', '2')
    assert await document_extract.probe_pdf(path, local=False) == {'pages': 2}


@pytest.mark.skipif(not sys.platform.startswith('linux'), reason='RLIMIT_AS is set on Linux only')
def test_probe_uses_memory_limit_without_changing_hard_limit(monkeypatch, capsys):
    import resource

    monkeypatch.setenv('PRX395_PDF_MEMORY_MB', '512')
    monkeypatch.setattr(pdf_probe, 'inspect', lambda path, text: {'pages': 1})
    monkeypatch.setattr(pdf_probe.logging, 'disable', lambda level: None)
    monkeypatch.setattr(sys, 'argv', ['pdf_probe.py', 'document.pdf', 'remote'])
    monkeypatch.setattr(resource, 'getrlimit', lambda kind: (0, resource.RLIM_INFINITY))
    caps = []
    monkeypatch.setattr(resource, 'setrlimit', lambda kind, cap: caps.append((kind, cap)))
    pdf_probe.main()
    assert caps == [(resource.RLIMIT_AS, (512 * MIB, resource.RLIM_INFINITY))]
    assert capsys.readouterr().out.strip() == '{"pages": 1}'


def test_text_limit_counts_utf8_bytes(monkeypatch):
    monkeypatch.setenv('PRX395_MAX_TEXT_BYTES', '1024')
    assert document_extract._bounded('Я' * 512) == 'Я' * 512
    with pytest.raises(PermanentJobError, match='^result_too_large$'):
        document_extract._bounded('Я' * 513)


@pytest.mark.asyncio
async def test_worker_download_uses_configured_pdf_limit(tmp_path, monkeypatch):
    monkeypatch.setenv('PRX395_MAX_PDF_BYTES', str(MIB))
    body = b'x' * MIB
    transport = httpx.MockTransport(lambda request: httpx.Response(
        200, content=body + (b'x' if request.url.path == '/too-large' else b''),
        headers={'Content-Type': 'application/pdf'},
    ))
    from prx_workers import job_api
    async with httpx.AsyncClient(base_url='http://example.test', transport=transport) as client:
        path = tmp_path / 'fits.pdf'
        await job_api.download_pdf(client, '/fits', path, limits()['max_pdf_bytes'])
        assert path.stat().st_size == MIB
        with pytest.raises(PermanentJobError, match='^file_unavailable$'):
            await job_api.download_pdf(client, '/too-large', tmp_path / 'oversized.pdf', limits()['max_pdf_bytes'])
