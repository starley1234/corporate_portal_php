"""Last 20 document-analysis replies, private worker-side files (not web assets)."""
import contextlib
import json
import logging
import os
from pathlib import Path
import tempfile
from datetime import datetime, timezone

DIRECTORY = Path(__file__).resolve().parents[3] / 'logs' / 'prx392'
MAX_BYTES = 65536
log = logging.getLogger('prx.doc.analyze')


def save_reply(run_id: int, model: str, text: str, status: str, error: str = '') -> None:
    """Never persist prompts, headers or tokens; output can contain personal data."""
    temporary = None
    try:
        DIRECTORY.mkdir(parents=True, exist_ok=True, mode=0o700)
        os.chmod(DIRECTORY, 0o700)
        raw = text.encode('utf-8')
        data = {'run_id': int(run_id), 'model': model, 'status': status,
                'recorded_at': datetime.now(timezone.utc).isoformat(),
                'response': raw[:MAX_BYTES].decode('utf-8', errors='ignore'),
                'truncated': len(raw) > MAX_BYTES, 'error': error[:1500]}
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', dir=DIRECTORY, delete=False) as stream:
            temporary = Path(stream.name)
            json.dump(data, stream, ensure_ascii=False, indent=2)
        target = DIRECTORY / f'run-{int(run_id)}.json'
        temporary.replace(target)
        for stale in sorted(DIRECTORY.glob('run-*.json'), key=lambda p: p.stat().st_mtime, reverse=True)[20:]:
            stale.unlink(missing_ok=True)
        log.info('LLM reply saved: run=%s status=%s file=%s', run_id, status, target)
    except OSError:
        log.warning('Could not save LLM reply diagnostics for run=%s', run_id)
    finally:
        if temporary is not None:
            with contextlib.suppress(OSError):
                temporary.unlink(missing_ok=True)
