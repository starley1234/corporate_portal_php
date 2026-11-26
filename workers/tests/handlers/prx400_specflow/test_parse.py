"""Парсер спецификаций prx400: шаблоны, синонимы, многострочность, математика.

Золотой корпус — реалистичная «болезненная» спецификация: многострочные
наименования, «Раздел N», эхо-шапка, пропуск нумерации, расхождение арифметики,
итог не сходится.
"""
import json

import pytest

from prx_workers.handlers.prx400_specflow import parse

TPL = json.loads("""
{
  "template_id": "spec_generic_v1",
  "template_name": "Спецификация (универсальная)",
  "detection_policy": {
    "mode": "auto",
    "anchor_keywords": ["спецификация", "кол-во"],
    "min_anchors_required": 2
  },
  "table_rules": {
    "header_search_depth_lines": 15,
    "columns": {
      "pos":         {"match": ["№", "поз.", "п/п"], "type": "integer"},
      "item_name":   {"match": ["наименование и техническая характеристика", "наименование"], "type": "text", "multiline": true},
      "code_doc":    {"match": ["тип, марка", "чертеж/гост"], "type": "text"},
      "qty":         {"match": ["кол-во", "кол.", "к-во"], "type": "decimal"},
      "unit":        {"match": ["ед. изм.", "ед."], "type": "text", "default": "шт."},
      "unit_price":  {"match": ["цена за ед., руб.", "цена"], "type": "decimal"},
      "total_price": {"match": ["всего, руб.", "сумма"], "type": "decimal"}
    },
    "skip_line_patterns": ["^лист\\\\s", "^продолжение"],
    "totals_patterns": ["^итого", "^всего по документу"],
    "section_patterns": ["^раздел\\\\s+\\\\d"],
    "math_checks": [
      {"rule": "qty * unit_price == total_price", "tolerance": 0.01},
      {"rule": "sum(total_price) == totals.total_price", "tolerance": 0.01},
      {"rule": "is_continuous_sequence(pos)"}
    ]
  }
}
""")

SPEC_TEXT = """\
ООО «Промсервис»

СПЕЦИФИКАЦИЯ № 41-ПЗ

№      Наименование и техническая характеристика      Тип, марка      Кол-во      Ед. изм.      Цена      Сумма
1      Гайка М12-6Н.5 (S18) ГОСТ 5915-70 цинк          Гайка М12        500         шт.           4,50      2 250,00
       класс прочности 6Н, оцинкованная
2      Болт М16х60 ГОСТ 7798-70                        Болт М16         1 200       шт.           12,30     14 760,00
3      Кран шаровой Ду50                               Кран 11с67п       10,5       шт.           1 850,00  19 425,00

Раздел 3. Запорная арматура

14     Вентиль запорный Ду80                           Вентиль 15кч18п  6           шт.           3 400,00  20 400,00
16     Задвижка Ду100 чугунная                         Задвижка 30ч6бр  4           шт.           7 200,00  28 800,00

Итого  85 635,00
Лист 2 из 5
Продолжение таблицы
"""


def test_numbers_ru():
    assert parse.to_number("1 234,56") == 1234.56
    assert parse.to_number("2 250,00") == 2250.0
    assert parse.to_number("10,5") == 10.5
    assert parse.to_number("1,234.56") == 1234.56
    assert parse.to_number("12.30") == 12.3
    assert parse.to_number("") is None
    assert parse.to_number("шт.") is None


def test_template_detect_by_anchors():
    tpl, hits = parse.detect_template(SPEC_TEXT, [TPL])
    assert tpl is not None and hits == ["спецификация", "кол-во"]
    assert parse.detect_template("совсем другой текст", [TPL])[0] is None


def test_parse_golden_spec():
    result = parse.parse(SPEC_TEXT, TPL)
    rows = result["rows"]

    assert [r["pos"] for r in rows] == [1, 2, 3, 14, 16]
    # синоним «Кол-во» распознан, RU-числа прочитаны
    assert rows[0]["qty"] == 500 and rows[0]["unit"] == "шт."
    assert rows[0]["unit_price"] == 4.5 and rows[0]["total_price"] == 2250.0
    # многострочное наименование склеено smart_concat
    assert "класс прочности 6Н" in rows[0]["raw_name"]
    # «Раздел 3» — категория последующих позиций
    assert rows[3]["section"] == "Раздел 3. Запорная арматура"
    assert rows[2]["section"] is None
    # «Лист 2», «Продолжение» — пропущены, не попали в наименование
    assert all("Лист" not in r["raw_name"] and "Продолжение" not in r["raw_name"] for r in rows)


def test_math_checks_gaps_and_totals():
    result = parse.parse(SPEC_TEXT, TPL)
    math = result["math"]

    # нумерация 3 → 14: пропуск (возможно замятие страницы)
    assert {"after_pos": 3, "next_pos": 14} in math["gaps"]
    assert any(f["type"] == "gap" for f in result["rows"][3]["flags"])

    # арифметика строк сходится (в золотом корпусе всё честно)
    assert math["row_errors"] == []

    # итог по строкам: 2250 + 14760 + 19425 + 20400 + 28800 = 85635 — сходится
    assert math["totals"]["computed"] == 85635.0
    assert math["totals"]["declared"] == 85635.0
    assert math["totals"]["ok"] is True
    assert "пропуски нумерации: 2" in math["summary"]
    assert "итог по строкам сходится" in math["summary"]


def test_math_row_error_detected():
    text = SPEC_TEXT.replace("20 400,00", "24 400,00")  # вентиль: 6*3400 ≠ 24400
    result = parse.parse(text, TPL)
    math = result["math"]

    assert len(math["row_errors"]) == 1
    assert math["row_errors"][0]["pos"] == 14
    assert math["row_errors"][0]["expected"] == 20400.0
    assert any(f["type"] == "math_row" for f in result["rows"][3]["flags"])


def test_fractional_unit_warning():
    text = SPEC_TEXT.replace("10,5       шт.", "10,5       шт.")
    result = parse.parse(text, TPL)
    flags = [f["type"] for f in result["rows"][2]["flags"]]
    assert "unit_fractional" in flags


def test_header_not_found_raises():
    with pytest.raises(ValueError):
        parse.parse("тут нет таблицы вообще", TPL)


def test_row_cap():
    tpl = dict(TPL)
    tpl["_max_rows"] = 3
    result = parse.parse(SPEC_TEXT, tpl)
    assert len(result["rows"]) == 3
