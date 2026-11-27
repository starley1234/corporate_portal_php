"""Корпус качества парсера prx400 (eval/*.txt + *.expected.json): регресс-гейт.

Новый тип спецификации, на котором парсер ошибся, — сюда же: текст + разметка.
Отчёт вручную: python3 -m prx_workers.handlers.prx400_specflow.evaluate tests/handlers/prx400_specflow/eval
"""
from pathlib import Path

from prx_workers.handlers.prx400_specflow import evaluate, parse

CORPUS = Path(__file__).parent / "eval"


def test_corpus_quality_gate():
    report = evaluate.evaluate(CORPUS, evaluate.load_templates(evaluate.DEFAULT_TEMPLATES))
    assert report["documents"] >= 5
    assert report["failed"] == [] and report["template_mismatch"] == []
    misses = {d["doc"]: d.get("misses") for d in report["docs"] if d.get("misses")}
    assert report["f1"] == 1.0 and all(v == 1.0 for v in report["fields"].values()), misses


def test_score_counts_missing_extra_and_wrong_fields():
    want = [{"pos_path": "1", "name": "Болт", "qty": 4}, {"pos_path": "2", "name": "Гайка", "qty": 4}]
    got = [{"pos_path": "1", "name": "болт ", "qty": 4.0}, {"pos_path": "3", "name": "Шайба", "qty": 1}]
    s = evaluate.score(want, got)
    assert (s["recall"], s["precision"], s["fields"]["name"]) == (0.5, 0.5, 1.0)
    assert s["misses"][0]["field"] == "row"


def test_empty_cell_does_not_shift_columns():
    header = "Поз.     Наименование          Обозначение     Кол-во     Ед. изм."
    row = "3        Насос К 80-50-200                      1          шт."
    assert parse.align_cells(row, parse._spans(header)) == ["3", "Насос К 80-50-200", "", "1", "шт."]
    # полная строка и «|»-таблица — как раньше, по порядку
    full = "1        Рама                  КБ20.01         1          шт."
    assert parse.align_cells(full, parse._spans(header)) == ["1", "Рама", "КБ20.01", "1", "шт."]
    assert parse.align_cells("| 1 | Рама | 1 |", parse._spans(header)) == ["1", "Рама", "1"]


def test_specific_template_beats_generic_with_more_raw_hits():
    generic = {"template_id": "g", "detection_policy": {"anchor_keywords": ["наименование", "кол-во", "ед. изм.", "цена", "сумма", "спецификация"], "min_anchors_required": 2}}
    narrow = {"template_id": "n", "detection_policy": {"anchor_keywords": ["форма 7-пто", "заказчик"], "min_anchors_required": 2}}
    text = "Форма 7-ПТО. Заказчик: …\nНаименование  Кол-во  Ед. изм.  Цена  Сумма"
    assert parse.detect_template(text, [generic, narrow])[0]["template_id"] == "n"
    assert parse.detect_template("Спецификация\nНаименование  Кол-во", [generic, narrow])[0]["template_id"] == "g"
