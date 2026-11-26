"""Схема дельт prx399: строгая валидация инструкций + разбор JSON модели."""
import pytest
from prx_workers.handlers.prx399_docmerge import delta
from prx_workers.handlers.prx399_docmerge.ast_text import parse


def _php_like_validate(op):
    """Зеркало Service_Prx399_Merge::validate_op — контур воркера и контур
    PHP проверяют одинаково, тест держит их в согласии через одну схему."""
    import re

    anchor_re = r"\A(?:\d{1,3}(?:\.\d{1,3}){0,4}|раздел-\d{1,3}|ст-\d{1,3}|прил-\d{1,3})\Z"
    if not isinstance(op, dict) or "op" not in op or "anchor" not in op:
        raise ValueError("op/anchor")
    if op["op"] not in ("replace", "add", "annul"):
        raise ValueError("op")
    anchor = str(op["anchor"]).strip().lower()
    if re.match(anchor_re, anchor) is None:
        raise ValueError("anchor")
    body = str(op.get("body") or "")
    if op["op"] in ("replace", "add") and not body.strip():
        raise ValueError("body")
    return anchor


GOOD_OPS = [
    {"op": "replace", "anchor": "4.2", "body": "Новая редакция пункта.", "confidence": 0.93},
    {"op": "add", "anchor": "4", "body": "Текст нового пункта."},
    {"op": "annul", "anchor": "6.1", "note": "исключён"},
]


def test_ops_from_realistic_reply_validate():
    reply = """Вот инструкции.
```json
{"ops": [
  {"op": "replace", "anchor": "4.2", "body": "…", "note": "срок увеличен", "confidence": 0.9},
  {"op": "annul", "anchor": "РАЗДЕЛ-6", "note": "раздел утратил силу"}
]}
```"""
    data = delta.parse_json_reply(reply)
    assert [op["op"] for op in data["ops"]] == ["replace", "annul"]
    for op in data["ops"]:
        op["anchor"] = op["anchor"].lower()
        _php_like_validate(op)


@pytest.mark.parametrize("bad", [
    {"op": "rewrite", "anchor": "4.2", "body": "x"},
    {"op": "replace", "anchor": "пункт 4.2", "body": "x"},
    {"op": "replace", "anchor": "4.2"},
    {"op": "annul", "anchor": "999.1.1.1.1.1"},  # глубже 5 уровней
    "не словарь",
])
def test_invalid_ops_rejected(bad):
    with pytest.raises(ValueError):
        _php_like_validate(bad)


def test_anchor_targets_resolve_in_base_ast():
    """Инструкции из ДС бьют по существующим пунктам/семействам базового договора:
    replace/annul — точный якорь; add — родитель, чьё семейство уже существует."""
    base = parse("4.1. Поставщик обязуется поставить товар.\n4.2. Срок — до 30.06.2026.\n6.1. Приёмка по акту.\n")
    paths = {node["path"] for node in base}
    assert {"4.1", "4.2", "6.1"} <= paths
    for op in GOOD_OPS:
        anchor = _php_like_validate(op)
        if op["op"] == "add":
            root = anchor.split(".")[0]
            assert any(p == anchor or p.startswith(root + ".") for p in paths), anchor
        else:
            assert anchor in paths, anchor


def test_parse_json_reply_rejects_garbage():
    with pytest.raises(ValueError):
        delta.parse_json_reply("   ")
    with pytest.raises(ValueError):
        delta.parse_json_reply("модель передумала и ответила словами")


def test_mark_bodies_verbatim_vs_paraphrase():
    ops = [
        {"op": "replace", "anchor": "4.2", "body": "Цена составляет 100 рублей."},
        {"op": "add", "anchor": "4", "body": "Совсем другой текст, которого нет в ДС."},
        {"op": "annul", "anchor": "6.1"},
    ]
    ds = "Пункт 4.2 изложить в редакции:  цена  составляет 100 РУБЛЕЙ."
    delta.mark_bodies(ops, ds)
    assert ops[0]["body_in_ds"] == "yes"   # нормализация пробелов/регистра
    assert ops[1]["body_in_ds"] == "no"    # пересказ — бейдж на карточке
    assert "body_in_ds" not in ops[2]      # annul не помечается


# ---------------------------------------------------------------- self-check

def test_delta_prompt_has_fewshot_examples():
    prompt = delta._DELTA_PROMPT
    assert "Примеры типовых формулировок" in prompt
    for fragment in ('"op": "replace"', '"op": "add"', '"op": "annul"'):
        assert fragment in prompt, fragment


def test_needs_selfcheck_flags_paraphrase_and_low_confidence():
    assert delta._needs_selfcheck([{"op": "annul", "anchor": "2.2", "body_in_ds": "no"}]) is True
    assert delta._needs_selfcheck([{"op": "add", "anchor": "2", "body": "x", "body_in_ds": "yes", "confidence": 0.55}]) is True
    assert delta._needs_selfcheck([{"op": "add", "anchor": "2", "body": "x", "body_in_ds": "yes"}]) is True  # confidence нет
    assert delta._needs_selfcheck([{"op": "add", "anchor": "2", "body": "x", "body_in_ds": "yes", "confidence": 0.9}]) is False


def test_apply_selfcheck_fix_and_drop_by_position():
    ops = [
        {"op": "replace", "anchor": "4.2", "body": "пересказ", "body_in_ds": "no", "confidence": 0.4},
        {"op": "add", "anchor": "4", "body": "норм"},
        {"op": "annul", "anchor": "6.1"},
    ]
    reply = {"ops": [
        {"op": "replace", "anchor": "4.2", "body": "точный фрагмент", "confidence": 0.8, "verdict": "fix"},
        {"verdict": "drop"},                      # позиция 2: вычеркнута
        {"verdict": "keep"},                      # позиция 3: без изменений
    ]}
    out = delta.apply_selfcheck(ops, reply)
    assert out[0]["body"] == "точный фрагмент" and out[0]["confidence"] == 0.8
    assert out[0]["body_in_ds"] == "no"            # исходные поля сохранены, потом перемаркируются
    assert len(out) == 2 and out[1]["anchor"] == "6.1"


def test_apply_selfcheck_bad_reply_returns_original():
    ops = [{"op": "annul", "anchor": "2.2"}]
    for reply in (None, {}, {"ops": "нет"}, {"ops": [{"verdict": "keep"}, {"verdict": "keep"}]}):
        assert delta.apply_selfcheck(ops, reply) == ops


def test_apply_selfcheck_accepts_all_drop():
    ops = [{"op": "add", "anchor": "9", "body": "выдумано"}]
    reply = {"ops": [{"verdict": "drop"}]}
    assert delta.apply_selfcheck(ops, reply) == []
