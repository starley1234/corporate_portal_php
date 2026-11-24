"""Regression tests for the trimmed corporate platform runtime."""
from prx_workers.handlers import HANDLERS
from prx_workers.llm import scan_providers


def test_only_active_handlers_are_registered():
    assert set(HANDLERS) == {
        "document.extract",
        "pdf.chunks",
        "echo",
        "embeddings.index",
        "llm.summarize",
        "semantic.index",
    }


def test_disabled_legacy_channels_do_not_stop_pdf_worker(monkeypatch, caplog):
    from prx_workers import __main__ as worker
    from prx_workers.config import Config

    config = Config(queue_mode="pg", pg_dsn="postgres://example", channels=["echo", "document.extract", "doc.normalize"])
    monkeypatch.setattr(worker.Config, "load", lambda: config)
    monkeypatch.setattr(worker, "setup_logging", lambda: None)
    monkeypatch.setattr(worker, "make_queue", lambda _: None)

    async def off(_token):
        return False

    class Worker:
        def __init__(self, config, _queue, handlers):
            assert config.channels == ["echo", "document.extract"]
            assert handlers == HANDLERS

        async def run(self):
            pass

    monkeypatch.setattr(worker, "trial_enabled", off)
    monkeypatch.setattr(worker, "BaseWorker", Worker)
    assert worker.main() == 0
    assert "Пропущены выключенные каналы: doc.normalize" in caplog.text


def test_archived_draft_channel_is_unknown():
    """doc.draft жил в удалённом archive/: теперь это просто неизвестный канал."""
    from prx_workers.config import Config
    from prx_workers.handlers import active_handlers

    config = Config(queue_mode="pg", pg_dsn="postgres://example", channels=["echo", "doc.draft"])
    problems = config.validate()
    handlers = active_handlers()
    unknown = sorted(set(config.channels) - handlers.keys())
    assert unknown == ["doc.draft"] and problems == []


def test_only_requested_legacy_channels_return_with_switch():
    from prx_workers.handlers import active_handlers

    assert set(active_handlers(True)) - set(HANDLERS) == {"doc.normalize", "doc.analyze"}
    assert active_handlers(False) == HANDLERS


def test_worker_transport_is_not_an_llm_provider(monkeypatch):
    monkeypatch.setenv("WORKER_API_URL", "http://portal.example.test/prx120/api")
    monkeypatch.setenv("PRX120_API_URL", "http://portal.example.test/prx120/api")
    monkeypatch.setenv("EMBEDDING_API_URL", "http://embed.example.test/v1")
    monkeypatch.setenv("TOOLFORGE_API_URL", "http://llm.example.test/v1")
    providers = scan_providers()
    assert "WORKER" not in providers
    assert "PRX120" not in providers
    assert "EMBEDDING" not in providers
    assert "TOOLFORGE" in providers


def test_pg_mode_may_keep_http_token_for_callbacks():
    from prx_workers.config import Config
    assert Config(queue_mode="pg", pg_dsn="postgres://example", api_token="unused-in-pg").validate() == []


def test_platform_model_falls_back_to_default_llm_model(monkeypatch):
    from prx_workers import providers

    monkeypatch.setattr(providers, "scan_providers", lambda: {})
    monkeypatch.setenv("DEFAULT_LLM_MODEL", "local::platform-27b")
    from prx_workers.config import Config

    assert Config.load().llm_model == "platform-27b"


def test_resolve_model_uses_platform_default():
    from prx_workers.llm import Provider, resolve_model

    local = Provider(name="LOCAL", base_url="http://llm.test/v1", model="qwen")
    provider, model = resolve_model({"LOCAL": local}, "LOCAL", "",
                                    "local::unsloth/qwen3.8-27b")

    assert provider is local
    assert model == "unsloth/qwen3.8-27b"
