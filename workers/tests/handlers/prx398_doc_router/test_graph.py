import pytest
from langgraph.checkpoint.memory import MemorySaver

from prx_workers.handlers.prx398_doc_router import graph as g


class FakeLLM:
    def __init__(self, responses):
        self.responses = list(responses)
        self.calls = []

    async def __call__(self, schema, system, user, timeout=120):
        self.calls.append({"schema": schema.__name__, "system": system})
        if not self.responses:
            raise RuntimeError("no more responses")
        item = self.responses.pop(0)
        if isinstance(item, Exception):
            raise item
        return item, {"provider": "local", "model": "fake", "prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2}


async def _ocr_node(state):
    return {"text": state.get("text") or "Contract agreement between A and B, amount 100. Date 2026-01-01"}


@pytest.mark.asyncio
async def test_routes_contract_to_legal(monkeypatch):
    fake = FakeLLM([
        g.ClassifyOut(doc_type="contract", confidence=0.95),
        g.ExtractOut(title="Contract", counterparty="A/B", date="2026-01-01", amount=100.0, summary="Agreement"),
    ])
    monkeypatch.setattr(g, "arun_structured", fake)
    saver = MemorySaver()
    app = g.build_graph(ocr_node=_ocr_node).compile(checkpointer=saver)
    out = await app.ainvoke({"file_path": "/tmp/fake.pdf", "_confidence_min": 0.8, "_max_retries": 2, "attempts": 0, "reasons": []},
                           config={"configurable": {"thread_id": "job-1"}})
    assert out["doc_type"] == "contract"
    assert out["route"] == "legal"
    assert out["confidence"] == 0.95
    assert out["attempts"] == 1


@pytest.mark.asyncio
async def test_resume_after_classify(monkeypatch):
    # First run: classify succeeds, extract fails.
    fake1 = FakeLLM([
        g.ClassifyOut(doc_type="invoice", confidence=0.9),
        RuntimeError("llm temporary failure"),
    ])
    monkeypatch.setattr(g, "arun_structured", fake1)
    saver = MemorySaver()
    app = g.build_graph(ocr_node=_ocr_node).compile(checkpointer=saver)
    config = {"configurable": {"thread_id": "job-2"}}
    with pytest.raises(RuntimeError):
        await app.ainvoke({"file_path": "/tmp/fake.pdf", "_confidence_min": 0.8, "_max_retries": 2, "attempts": 0, "reasons": []}, config=config)

    # Resume: classify must not be called again; extract succeeds.
    fake2 = FakeLLM([
        g.ExtractOut(title="Invoice 1", counterparty="X", date="2026-01-01", amount=10.0, summary="Inv"),
    ])
    monkeypatch.setattr(g, "arun_structured", fake2)
    out = await app.ainvoke(None, config=config)
    assert out["doc_type"] == "invoice"
    assert out["route"] == "erp"
    assert [c["schema"] for c in fake2.calls] == ["ExtractOut"]  # no ClassifyOut on resume


@pytest.mark.asyncio
async def test_low_confidence_retries_then_manual(monkeypatch):
    # retry ведёт обратно в classify: классификация повторяется вместе с
    # извлечением (уверенность даёт именно classify).
    fake = FakeLLM([
        g.ClassifyOut(doc_type="other", confidence=0.2),
        g.ExtractOut(title="", counterparty="", date="", amount=None, summary=""),
        g.ClassifyOut(doc_type="other", confidence=0.2),
        g.ExtractOut(title="", counterparty="", date="", amount=None, summary=""),
    ])
    monkeypatch.setattr(g, "arun_structured", fake)
    saver = MemorySaver()
    app = g.build_graph(ocr_node=_ocr_node).compile(checkpointer=saver)
    out = await app.ainvoke({"file_path": "/tmp/fake.pdf", "_confidence_min": 0.8, "_max_retries": 2, "attempts": 0, "reasons": []},
                           config={"configurable": {"thread_id": "job-3"}})
    assert out["route"] == ""
    assert out["attempts"] == 2
    assert "manual_review" in out["reasons"] or len(out["reasons"]) > 0
