"""AST-парсер prx399: нумерация любой глубины, словесные заголовки, рендер."""
from prx_workers.handlers.prx399_docmerge import ast_text

SAMPLE = """ДОГОВОР ПОСТАВКИ №17
г. Москва, 01.03.2026

ООО «Ромашка» и ООО «Василёк» заключили договор.

Раздел 1. Предмет договора
1.1. Поставщик обязуется поставить товар в срок до 30.06.2026.
1.2. Ассортимент — в Приложении 1.

Раздел 2. Порядок расчётов
2.1. Цена договора — 1 200 000 руб.
2.1.1. Оплата — в течение 10 рабочих дней.
2.2. НДС включён в цену.

Статья 5. Форс-мажор по применимому праву.
"""


def test_parse_numbering_and_word_headings():
    nodes = ast_text.parse(SAMPLE)
    paths = [node["path"] for node in nodes]
    assert paths[0] == "0"  # преамбула
    assert "раздел-1" in paths and "раздел-2" in paths
    assert "ст-5" in paths and "прил-1" not in paths  # Приложение 1 — только ссылка в тексте
    assert "1.1" in paths and "2.1.1" in paths

    node_211 = next(node for node in nodes if node["path"] == "2.1.1")
    assert node_211["text"].startswith("Оплата")
    assert node_211["status"] == "base" and node_211["source"] == "base"

    preamble = nodes[0]
    assert "ДОГОВОР ПОСТАВКИ" in preamble["title"] or "ДОГОВОР ПОСТАВКИ" in preamble["text"]


def test_render_skips_annulled():
    nodes = ast_text.parse(SAMPLE)
    for node in nodes:
        if node["path"] == "2.1.1":
            node["status"] = "annulled"
    text = ast_text.render(nodes)
    assert "2.1.1" not in text
    assert "2.2." in text
    assert ast_text.render(nodes, skip_annulled=False).find("2.1.1") >= 0


def test_references_extracts_all_families():
    refs = ast_text.references("согласно п. 4.2 и пп. 3.1.2, в разделе 2 и статье 5")
    assert refs == ["4.2", "3.1.2", "раздел-2", "ст-5"]


def test_text_without_headings_is_single_preamble_node():
    nodes = ast_text.parse("Просто текст без нумерации.")
    assert len(nodes) == 1 and nodes[0]["path"] == "0"


def test_stats_flags_unrecognized_numbering():
    # «2.9» похож на пункт, но узла нет (скан склеил строку) — в метрику;
    # даты вида 30.06.2026 кандидатами не считаются.
    text = SAMPLE + "\n2.9. Этот пункт скан склеил с абзацем.\n30.06.2026 подписано сторонами.\n"
    nodes = ast_text.parse(SAMPLE)
    report = ast_text.stats(text, nodes)
    assert report["nodes"] == len(nodes)
    assert "2.9" in report["unrecognized"]
    assert all(not u.startswith("30.") for u in report["unrecognized"])


def test_stats_flags_duplicate_anchors():
    text = SAMPLE + "\n\nРаздел 2. Повтор раздела из-за сбоя скана\n"
    nodes = ast_text.parse(text)
    report = ast_text.stats(text, nodes)
    assert "раздел-2" in report["duplicates"]


def test_stats_clean_document_is_silent():
    report = ast_text.stats(SAMPLE, ast_text.parse(SAMPLE))
    assert report["unrecognized"] == [] and report["duplicates"] == []
