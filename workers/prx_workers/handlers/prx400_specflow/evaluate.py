"""Оценка качества парсера prx400 на размеченном корпусе.

Корпус — пары `<имя>.txt` (текст как после OCR/текстового слоя, `\\f` —
граница страниц) и `<имя>.expected.json`:

    {"template_id": "spec_generic_v1",
     "rows": [{"pos_path": "1.2", "name": "…", "qty": 4, "unit": "шт", "level": 1, "page": 2}]}

Шаблон определяется так же, как в бою (detect_template по библиотеке
`application/modules/prx400_specflow/config/templates`). Строки сопоставляются
по номеру позиции; метрики — полнота/точность строк и доля верных полей
(наименование — после нормализации пробелов и регистра).

    python3 -m prx_workers.handlers.prx400_specflow.evaluate <каталог корпуса> [--templates DIR] [--json]

Код выхода 1 — хотя бы один документ не разобран или F1 строк < --min-f1.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

from . import parse

FIELDS = ("name", "qty", "unit", "level", "page")
DEFAULT_TEMPLATES = Path(__file__).resolve().parents[4] / "application/modules/prx400_specflow/config/templates"


def load_templates(directory: Path) -> list[dict]:
    return [json.loads(p.read_text(encoding="utf-8")) for p in sorted(directory.glob("*.json"))]


def _norm(value) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip().lower()


def _same(field: str, want, got) -> bool:
    if field == "qty":
        try:
            return abs(float(want) - float(got)) < 1e-6
        except (TypeError, ValueError):
            return False
    if field in ("level", "page"):
        return int(want) == int(got or 0)
    return _norm(want) == _norm(got)


def got_rows(text: str, templates: list[dict]) -> tuple[str, list[dict]]:
    tpl, _ = parse.detect_template(text, templates)
    if tpl is None:
        raise ValueError("template_not_detected")
    result = parse.parse(text, tpl)
    rows = [{
        "pos_path": str(r.get("pos_path") or r.get("pos") or ""),
        "name": r.get("raw_name", ""),
        "qty": r.get("qty"),
        "unit": r.get("unit", ""),
        # Уровень по номеру «1.2.3» сервер выводит сам (Pipeline::link_tree);
        # воркер присылает только явный (колонка «Уровень»). Оцениваем итог.
        "level": r["level"] if isinstance(r.get("level"), int) else str(r.get("pos_path") or "").count("."),
        "page": r.get("page"),
    } for r in result["rows"]]

    return str(tpl.get("template_id", "")), rows


def score(expected: list[dict], got: list[dict]) -> dict:
    """Сопоставление по pos_path: полнота, точность, F1 и точность по полям."""
    by_pos = {}
    for r in got:
        by_pos.setdefault(r["pos_path"], r)
    matched = 0
    field_ok = {f: 0 for f in FIELDS}
    field_n = {f: 0 for f in FIELDS}
    misses = []
    for want in expected:
        have = by_pos.get(str(want["pos_path"]))
        if have is None:
            misses.append({"pos_path": want["pos_path"], "field": "row", "want": want.get("name"), "got": None})
            continue
        matched += 1
        for f in FIELDS:
            if f not in want:
                continue
            field_n[f] += 1
            if _same(f, want[f], have.get(f)):
                field_ok[f] += 1
            else:
                misses.append({"pos_path": want["pos_path"], "field": f, "want": want[f], "got": have.get(f)})
    recall = matched / len(expected) if expected else 1.0
    precision = matched / len(got) if got else (1.0 if not expected else 0.0)
    f1 = 2 * precision * recall / (precision + recall) if precision + recall else 0.0

    return {
        "expected": len(expected), "got": len(got), "matched": matched,
        "recall": round(recall, 4), "precision": round(precision, 4), "f1": round(f1, 4),
        "fields": {f: round(field_ok[f] / field_n[f], 4) for f in FIELDS if field_n[f]},
        "misses": misses[:50],
    }


def evaluate(corpus: Path, templates: list[dict]) -> dict:
    docs = []
    for text_path in sorted(corpus.glob("*.txt")):
        expected_path = text_path.with_name(text_path.stem + ".expected.json")
        if not expected_path.exists():
            continue
        expected = json.loads(expected_path.read_text(encoding="utf-8"))
        doc = {"doc": text_path.stem}
        try:
            template_id, rows = got_rows(text_path.read_text(encoding="utf-8"), templates)
        except ValueError as e:
            doc.update({"error": str(e), "f1": 0.0})
            docs.append(doc)
            continue
        doc["template_id"] = template_id
        doc["template_ok"] = "template_id" not in expected or expected["template_id"] == template_id
        doc.update(score(expected["rows"], rows))
        docs.append(doc)

    total_expected = sum(d.get("expected", 0) for d in docs)
    total_matched = sum(d.get("matched", 0) for d in docs)
    total_got = sum(d.get("got", 0) for d in docs)
    recall = total_matched / total_expected if total_expected else 0.0
    precision = total_matched / total_got if total_got else 0.0
    field_scores: dict[str, list[float]] = {}
    for d in docs:
        for f, v in d.get("fields", {}).items():
            field_scores.setdefault(f, []).append(v)

    return {
        "documents": len(docs),
        "failed": [d["doc"] for d in docs if "error" in d],
        "template_mismatch": [d["doc"] for d in docs if d.get("template_ok") is False],
        "recall": round(recall, 4),
        "precision": round(precision, 4),
        "f1": round(2 * precision * recall / (precision + recall), 4) if precision + recall else 0.0,
        "fields": {f: round(sum(v) / len(v), 4) for f, v in field_scores.items()},
        "docs": docs,
    }


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("corpus", type=Path)
    ap.add_argument("--templates", type=Path, default=DEFAULT_TEMPLATES)
    ap.add_argument("--min-f1", type=float, default=0.95)
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args(argv)

    report = evaluate(args.corpus, load_templates(args.templates))
    if args.json:
        print(json.dumps(report, ensure_ascii=False, indent=1))
    else:
        print(f"документов: {report['documents']}  F1 строк: {report['f1']:.3f}  "
              f"полнота: {report['recall']:.3f}  точность: {report['precision']:.3f}")
        print("поля: " + ", ".join(f"{f} {v:.3f}" for f, v in report["fields"].items()))
        for d in report["docs"]:
            status = d.get("error") or f"F1 {d['f1']:.3f}, шаблон {d['template_id']}{'' if d['template_ok'] else ' (ожидался другой)'}"
            print(f"  {d['doc']}: {status}")
            for m in d.get("misses", [])[:5]:
                print(f"    поз. {m['pos_path']} {m['field']}: ожидалось {m['want']!r}, получено {m['got']!r}")
    ok = not report["failed"] and not report["template_mismatch"] and report["f1"] >= args.min_f1

    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
