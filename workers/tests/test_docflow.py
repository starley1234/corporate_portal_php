"""Документооборот prl390–prl394: чистые функции без сайта и модели."""
from __future__ import annotations

import json
from pathlib import Path

import pytest

from prl_workers.handlers.doc_analyze import parse_json_reply
from prl_workers.handlers.doc_normalize import _clean, _extract_pypdf

ROOT = Path(__file__).resolve().parents[2]
DEMO = ROOT / "examples" / "docflow"


def test_demo_pdfs_have_text_layer():
    files = sorted(DEMO.glob("*.pdf"))
    assert len(files) >= 3
    for path in files:
        text, pages = _extract_pypdf(path)
        assert pages == 1
        assert len(text) > 300, path.name
        assert "2026" in text


def test_clean_collapses_soft_hyphens_and_blank_lines():
    raw = "Первая\xad строка\n\n\n\nВторая   строка"
    assert _clean(raw) == "Первая строка\n\nВторая строка"


def test_parse_json_reply_accepts_markdown_fence_and_chatter():
    raw = 'Вот результат:\n```json\n{"type": "claim", "fields": {}}\n```\nГотово.'
    assert parse_json_reply(raw)["type"] == "claim"


def test_parse_json_reply_rejects_non_json():
    with pytest.raises(ValueError):
        parse_json_reply("я не смог разобрать документ")


def test_mock_replies_are_valid_doc_card():
    required = {"type", "type_confidence", "fields", "summary", "risk"}
    for name in ("claim", "court", "tax"):
        data = json.loads((DEMO / "mock" / f"{name}.json").read_text(encoding="utf-8"))
        assert required <= set(data), name
        assert data["risk"]["level"] in {"low", "medium", "high"}


def test_mock_draft_has_body_and_citations():
    data = json.loads((DEMO / "mock" / "draft.json").read_text(encoding="utf-8"))
    assert len(data["body"]) > 40
    assert isinstance(data["citations"], list)
