from types import SimpleNamespace

import pytest

from prx_workers.langchain_llm import run_structured
from prx_workers.usage import Usage


class FakeStructured:
    def __init__(self, parsed, usage):
        self._parsed = parsed
        self._usage = usage

    def _result(self):
        return {
            "parsed": self._parsed,
            "raw": SimpleNamespace(usage_metadata=self._usage),
        }

    def invoke(self, messages):
        assert len(messages) == 2
        assert messages[0].content == "sys"
        assert messages[1].content == "usr"
        return self._result()

    async def ainvoke(self, messages):
        return self.invoke(messages)


class FakeLLM:
    def __init__(self, parsed, usage):
        self._parsed = parsed
        self._usage = usage

    def with_structured_output(self, schema, include_raw=False):
        assert schema is dict
        assert include_raw is True
        return FakeStructured(self._parsed, self._usage)


def test_run_structured_returns_object_and_records_usage(monkeypatch):
    monkeypatch.setenv("LOCAL_API_URL", "http://llm.local/v1")
    monkeypatch.setenv("LOCAL_API_KEY", "test-key")
    monkeypatch.setenv("DEFAULT_LLM_MODEL", "local::fake-model")

    import prx_workers.langchain_llm as mod

    monkeypatch.setattr(mod, "build_chat_model", lambda timeout=120: FakeLLM({"ok": True}, {"input_tokens": 10, "output_tokens": 5}))

    Usage.begin()
    parsed, usage = mod.run_structured(dict, "sys", "usr", timeout=30)

    assert parsed == {"ok": True}
    assert usage == {"prompt_tokens": 10, "completion_tokens": 5}
    current = Usage.current().to_dict()
    assert current["llm_calls"] == 1
    assert current["prompt_tokens"] == 10
    assert current["completion_tokens"] == 5


@pytest.mark.asyncio
async def test_arun_structured_is_awaitable_and_records_usage(monkeypatch):
    """Обработчики prx397/prx398 вызывают arun_structured через await:
    синхронный run_structured там падал бы TypeError."""
    monkeypatch.setenv("LOCAL_API_URL", "http://llm.local/v1")
    monkeypatch.setenv("LOCAL_API_KEY", "test-key")
    monkeypatch.setenv("DEFAULT_LLM_MODEL", "local::fake-model")

    import prx_workers.langchain_llm as mod

    monkeypatch.setattr(mod, "build_chat_model", lambda timeout=120: FakeLLM({"ok": True}, {"input_tokens": 3, "output_tokens": 4}))

    Usage.begin()
    parsed, usage = await mod.arun_structured(dict, "sys", "usr", timeout=30)

    assert parsed == {"ok": True}
    assert usage == {"prompt_tokens": 3, "completion_tokens": 4}
    assert Usage.current().to_dict()["llm_calls"] == 1
