"""Поиск обязан уметь работать, когда библиотека ddgs молчит.

Владелец видел в этапах «найдено площадок: 0» на всех формулировках: ddgs
(библиотека) молча отдавал пустоту, а конвейер считал, что спроса нет. Проверяем
резервный канал — разбор HTML-выдачи DuckDuckGo (обычной и lite).
"""
import n2j_workers.webread as webread


HTML_LAYOUT = """
<html><body>
  <div class="result">
    <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Frts-tender.ru%2Ftender-1&amp;rut=abc">
      Закупка реле РЭС-9
    </a>
    <a class="result__snippet">Предмет закупки: поставка реле</a>
  </div>
  <div class="result">
    <a class="result__a" href="https://zakupki.gov.ru/purchase/2">Поставка реле РП-21</a>
    <a class="result__snippet">Заказчик АО «Прибор»</a>
  </div>
</body></html>
"""

LITE_LAYOUT = """
<html><body><table>
  <tr><td class="result-link"><a href="https://itmash.ru/catalog">Завод ИТМАШ</a></td></tr>
  <tr><td class="result-snippet">Производство реле и контакторов</td></tr>
</table></body></html>
"""


def _patch(monkeypatch, html_by_host):
    def fake_get(url, *a, **kw):
        for host, html in html_by_host.items():
            if host in url:
                return 200, html
        raise RuntimeError("нет такого хоста")

    monkeypatch.setattr(webread, "_curl_cffi_get", fake_get)
    monkeypatch.setattr(webread, "_httpx_get", fake_get)


def test_html_layout_and_uddg(monkeypatch):
    _patch(monkeypatch, {"html.duckduckgo.com": HTML_LAYOUT})
    rows = webread.ddg_html_sync("реле закупка", 5)

    assert [r["url"] for r in rows] == [
        "https://rts-tender.ru/tender-1",
        "https://zakupki.gov.ru/purchase/2",
    ]
    assert rows[0]["title"].startswith("Закупка реле")
    assert rows[0]["body"] == "Предмет закупки: поставка реле"


def test_lite_layout(monkeypatch):
    _patch(monkeypatch, {"lite.duckduckgo.com": LITE_LAYOUT})
    rows = webread.ddg_html_sync("заводы реле", 5)

    assert rows == [{"url": "https://itmash.ru/catalog", "title": "Завод ИТМАШ",
                     "body": "Производство реле и контакторов"}]


def test_first_host_empty_second_used(monkeypatch):
    _patch(monkeypatch, {"html.duckduckgo.com": "<html><body>капча</body></html>",
                         "lite.duckduckgo.com": LITE_LAYOUT})
    assert len(webread.ddg_html_sync("реле", 5)) == 1


def test_no_results(monkeypatch):
    _patch(monkeypatch, {"html.duckduckgo.com": "<html></html>",
                         "lite.duckduckgo.com": "<html></html>"})
    assert webread.ddg_html_sync("ничего", 5) == []
