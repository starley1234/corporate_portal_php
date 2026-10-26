"""Regression tests for the trimmed corporate platform runtime."""
from prl_workers.handlers import HANDLERS
from prl_workers.llm import scan_providers


def test_only_foundation_handlers_are_registered():
    assert set(HANDLERS) == {"echo", "embeddings.index", "llm.summarize", "semantic.index"}


def test_worker_transport_is_not_an_llm_provider(monkeypatch):
    monkeypatch.setenv("WORKER_API_URL", "http://portal.example.test/prl120/api")
    monkeypatch.setenv("PRL120_API_URL", "http://portal.example.test/prl120/api")
    monkeypatch.setenv("EMBEDDING_API_URL", "http://embed.example.test/v1")
    monkeypatch.setenv("TOOLFORGE_API_URL", "http://llm.example.test/v1")
    providers = scan_providers()
    assert "WORKER" not in providers
    assert "PRL120" not in providers
    assert "EMBEDDING" not in providers
    assert "TOOLFORGE" in providers


def test_pg_mode_may_keep_http_token_for_callbacks():
    from prl_workers.config import Config
    assert Config(queue_mode="pg", pg_dsn="postgres://example", api_token="unused-in-pg").validate() == []
