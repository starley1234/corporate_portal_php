from prx_workers.handlers.prx397_invoice import handler as h
from prx_workers.handlers.prx397_invoice.limits import limits


def test_limits_defaults(monkeypatch):
    for k in ["PRX397_MAX_PDF_BYTES", "PRX397_MAX_PAGES", "PRX397_MAX_LLM_CHARS", "PRX397_PDF_MEMORY_MB"]:
        monkeypatch.delenv(k, raising=False)
    l = limits()
    assert l["max_pdf_bytes"] == 10 * 1024 * 1024
    assert l["max_pages"] == 20
    assert l["max_llm_chars"] == 60000
    assert l["pdf_memory_mb"] == 512


def test_ocr_limits_carry_keys_required_by_probe(monkeypatch):
    """probe/extract_pdf требуют max_pages, max_text_bytes, pdf_memory_mb:
    без них задание падало сырым KeyError вместо ocr_config."""
    for k in ["PRX397_MAX_PDF_BYTES", "PRX397_MAX_PAGES", "PRX397_MAX_LLM_CHARS", "PRX397_PDF_MEMORY_MB"]:
        monkeypatch.delenv(k, raising=False)
    ocr = h.ocr_limits(limits())
    for key in ("max_pages", "max_text_bytes", "pdf_memory_mb"):
        assert key in ocr
    # Лимит LLM задан в символах, а bounded() считает байты UTF-8: даём запас.
    assert ocr["max_text_bytes"] == 60000 * 4 + 65536


def test_result_too_large_is_terminal(monkeypatch):
    # OCR-предохранитель по байтам должен приводить к терминальному коду, а не
    # к бесконечному повтору задачи.
    assert "result_too_large" in h.PERMANENT_CODES
    assert "text_too_large_for_llm" in h.PERMANENT_CODES


def test_invoice_fields_schema():
    f = h.InvoiceFields(number="1", date="2026-01-01", seller=h.Party(name="A", inn="7707083893"),
                        buyer=h.Party(name="B", inn="7814598458"), total=100.0, vat=20.0, currency="RUB",
                        lines=[h.Line(name="x", qty=1, price=100.0, sum=100.0)])
    d = f.model_dump()
    assert d["seller"]["inn"] == "7707083893"
    assert d["lines"][0]["sum"] == 100.0
