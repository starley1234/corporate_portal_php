"""Протокол prx400 воркер → PHP: один отчёт об ошибке, контракт тела результата,
предел размера, vision-предупреждения. Без сети, LLM и PDF-утилит."""
import re
from pathlib import Path
from types import SimpleNamespace

import pytest

from prx_workers.base import PermanentJobError
from prx_workers.handlers.prx400_specflow import handler as h
from prx_workers.handlers.prx400_specflow import vision
from prx_workers.handlers.prx400_specflow.limits import limits

ROOT = Path(__file__).resolve().parents[4]
SPECS_PHP = ROOT / "application/modules/prx400_specflow/classes/Service/Prx400/Specs.php"
PIPELINE_PHP = ROOT / "application/modules/prx400_specflow/classes/Service/Prx400/Pipeline.php"

TPL = {
    "template_id": "spec_generic_v1",
    "template_name": "Спецификация",
    "detection_policy": {"anchor_keywords": ["спецификация"]},
    "table_rules": {"columns": {"pos": {"match": ["поз"]}, "item_name": {"match": ["наименование"]}}},
}


def php_failed_codes() -> set[str]:
    src = SPECS_PHP.read_text(encoding="utf-8")
    block = re.search(r"const FAILED_CODES = \[(.*?)\];", src, re.S).group(1)
    return set(re.findall(r"'([a-z_]+)'", block))


def test_reported_codes_are_accepted_by_php():
    """PHP превращает неизвестный код в job_failed — оператор теряет причину."""
    assert h.REPORTED_CODES <= php_failed_codes()
    # протокольные ошибки PHP не сообщаются: запись не принадлежит задаче
    assert not {"lease_lost", "worker_auth", "invalid_attempt", "worker_config"} & h.REPORTED_CODES


def test_limits_default_and_shared_with_php(monkeypatch):
    for k in ("PRX400_MAX_RESULT_BYTES", "PRX400_MAX_ROWS", "PRX400_MAX_PDF_BYTES"):
        monkeypatch.delenv(k, raising=False)
    lim = limits()
    assert lim["max_result_bytes"] == 8 * 1024 ** 2
    assert lim["max_rows"] == 2000
    monkeypatch.setenv("PRX400_MAX_RESULT_BYTES", "999")  # ниже границы → умолчание
    assert limits()["max_result_bytes"] == 8 * 1024 ** 2


def test_result_body_matches_php_contract():
    result = {
        "rows": [{"pos": 1, "raw_name": "Гайка", "flags": []}],
        "math": {"row_errors": [], "gaps": [], "totals": {"declared": None, "computed": None, "ok": None}, "summary": ""},
        "stats": {"via": "vision", "pages_processed": 2},
    }
    body = h.result_body(TPL, [], "", {"spec_number": "КБ-1"}, 3, result, ["vision_pages_truncated", "empty_page", "empty_page"])
    php = PIPELINE_PHP.read_text(encoding="utf-8")
    for key in ("rows", "template_id", "template_title", "spec_number", "department", "pages", "text_sha256",
                "math", "warnings", "stats"):
        assert key in body
        assert f"'{key}'" in php, f"PHP не читает ключ {key}"
    assert body["warnings"] == ["empty_page", "vision_pages_truncated"]
    assert body["stats"] == {"via": "vision", "pages_processed": 2}
    assert body["spec_number"] == "КБ-1"
    for w in body["warnings"]:
        assert re.fullmatch(r"[a-z0-9_]{1,48}", w), "PHP принимает только коды предупреждений"


def test_result_size_limit(monkeypatch):
    lim = {"max_result_bytes": 1024}
    h.check_result_size({"rows": []}, lim)
    with pytest.raises(PermanentJobError, match="result_too_large"):
        h.check_result_size({"rows": ["я" * 600]}, lim)  # кириллица — 2 байта UTF-8


def test_columns_hint_reads_template_match():
    hint = vision._columns_hint(TPL)
    assert "наименование" in hint and "поз" in hint


def _ctx():
    async def progress(*_a, **_k):
        return None

    return SimpleNamespace(_current_job={"id": 7, "attempts": 1}, config=SimpleNamespace(api_token="t"),
                           progress=progress)


@pytest.fixture
def reports(monkeypatch):
    sent = []

    async def report_failure(headers, url, code, **extra):
        sent.append(code)

    monkeypatch.setattr(h.job_api, "report_failure", report_failure)
    monkeypatch.setenv("PRX400_SPECFLOW_API_BASE", "http://php.local/prx400_specflow/worker")
    return sent


@pytest.mark.asyncio
async def test_terminal_error_reported_exactly_once(reports):
    with pytest.raises(PermanentJobError, match="template_not_found"):
        await h.handle(_ctx(), {"templates": [TPL], "parse_mode": "ocr", "template_id": "нет_такого"})
    assert reports == ["template_not_found"]


@pytest.mark.asyncio
async def test_unknown_mode_is_bad_payload(reports):
    with pytest.raises(PermanentJobError, match="bad_payload"):
        await h.handle(_ctx(), {"templates": [TPL], "parse_mode": "magic"})
    assert reports == ["bad_payload"]


@pytest.mark.asyncio
async def test_protocol_errors_not_reported(reports, monkeypatch):
    async def lost(*_a, **_k):
        raise PermanentJobError("lease_lost")

    monkeypatch.setattr(h.job_api, "download_pdf", lost)
    with pytest.raises(PermanentJobError, match="lease_lost"):
        await h.handle(_ctx(), {"templates": [TPL], "parse_mode": "ocr"})
    assert reports == []


@pytest.mark.asyncio
async def test_vision_truncation_and_page_failures_become_warnings(monkeypatch, tmp_path):
    async def render(path, page, prefix):
        return b"png"

    calls = {"n": 0}

    async def llm(schema, messages, timeout):
        calls["n"] += 1
        if calls["n"] == 2:
            raise TimeoutError("model")
        return vision._Page(rows=[vision._Row(pos=calls["n"], raw_name="Гайка", qty=1, unit="шт")]), None

    async def progress(*_a):
        return None

    monkeypatch.setattr(vision, "_render_page", render)
    monkeypatch.setattr(vision, "arun_structured_messages", llm)
    out = await vision.extract(tmp_path / "x.pdf", 5, TPL, {"max_vision_pages": 3, "max_rows": 1}, progress)
    assert out["warnings"] == ["vision_pages_truncated", "vision_page_2_failed"]
    assert out["pages_processed"] == 2
    assert len(out["rows"]) == 2, "строки сверх max_rows не режутся молча — решает handler"


@pytest.mark.asyncio
async def test_vision_all_pages_failed_is_unavailable(monkeypatch, tmp_path):
    async def render(path, page, prefix):
        return b"png"

    async def llm(schema, messages, timeout):
        raise TimeoutError("model")

    async def progress(*_a):
        return None

    monkeypatch.setattr(vision, "_render_page", render)
    monkeypatch.setattr(vision, "arun_structured_messages", llm)
    with pytest.raises(PermanentJobError, match="vision_unavailable"):
        await vision.extract(tmp_path / "x.pdf", 2, TPL, {"max_vision_pages": 8, "max_rows": 100}, progress)
