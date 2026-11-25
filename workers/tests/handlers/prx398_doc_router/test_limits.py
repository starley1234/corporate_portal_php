import pytest

from prx_workers.handlers.prx398_doc_router import graph as g
from prx_workers.handlers.prx398_doc_router.limits import limits


def test_limits_defaults(monkeypatch):
    for k in ["PRX398_MAX_PDF_BYTES", "PRX398_MAX_PAGES", "PRX398_MAX_TEXT_BYTES",
              "PRX398_PDF_MEMORY_MB", "PRX398_CONFIDENCE_MIN", "PRX398_MAX_RETRIES",
              "PRX398_CHECKPOINT", "PRX398_CHECKPOINT_DSN"]:
        monkeypatch.delenv(k, raising=False)
    lim = limits()
    assert lim["max_pdf_bytes"] == 10 * 1024 * 1024
    assert lim["max_pages"] == 20
    assert lim["max_text_bytes"] == 5 * 1024 * 1024
    assert lim["pdf_memory_mb"] == 512
    assert lim["confidence_min"] == 0.8
    assert lim["max_retries"] == 2
    assert lim["checkpoint"] == "postgres"
    assert lim["checkpoint_dsn"] == ""


def test_checkpoint_dsn_override(monkeypatch):
    # В режиме WORKER_QUEUE_MODE=http у воркера нет ctx.config.pg_dsn, поэтому
    # чекпойнт нужен через явный DSN.
    monkeypatch.setenv("PRX398_CHECKPOINT_DSN", "postgresql://prx:pw@db:5432/prx")
    assert limits()["checkpoint_dsn"] == "postgresql://prx:pw@db:5432/prx"


def test_unknown_checkpoint_mode_falls_back_to_postgres(monkeypatch):
    monkeypatch.setenv("PRX398_CHECKPOINT", "weird")
    assert limits()["checkpoint"] == "postgres"


class _FakeSaver:
    def __init__(self):
        self.deleted = []

    async def adelete_thread(self, thread_id):
        self.deleted.append(thread_id)


@pytest.mark.asyncio
async def test_forget_thread_deletes_checkpoint():
    """Чекпойнт хранит полный OCR-текст: после терминального результата его
    нужно удалять, а не держать до TTL."""
    saver = _FakeSaver()
    await g.forget_thread(saver, 42)
    assert saver.deleted == ["42"]


@pytest.mark.asyncio
async def test_forget_thread_is_best_effort():
    class Broken:
        async def adelete_thread(self, thread_id):
            raise RuntimeError("db down")

    # Уборка не должна ронять уже сохранённый результат.
    await g.forget_thread(Broken(), "1")
    await g.forget_thread(object(), "1")  # saver без adelete_thread
