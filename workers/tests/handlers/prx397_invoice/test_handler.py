from prx_workers.handlers.prx397_invoice import handler as h
from prx_workers.handlers.prx397_invoice.limits import limits


def test_limits_defaults(monkeypatch):
    for k in ["PRX397_MAX_PDF_BYTES","PRX397_MAX_PAGES","PRX397_MAX_LLM_CHARS"]:
        monkeypatch.delenv(k, raising=False)
    l = limits()
    assert l["max_pdf_bytes"] == 10 * 1024 * 1024
    assert l["max_pages"] == 20
    assert l["max_llm_chars"] == 60000


def test_invoice_fields_schema():
    f = h.InvoiceFields(number="1", date="2026-01-01", seller=h.Party(name="A", inn="7707083893"),
                        buyer=h.Party(name="B", inn="7814598458"), total=100.0, vat=20.0, currency="RUB",
                        lines=[h.Line(name="x", qty=1, price=100.0, sum=100.0)])
    d = f.model_dump()
    assert d["seller"]["inn"] == "7707083893"
    assert d["lines"][0]["sum"] == 100.0
