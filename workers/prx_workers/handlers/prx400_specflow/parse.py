"""Парсер таблицы спецификации (prx400).

Детерминированный, без LLM: строки текста → заголовок по синонимам колонок
шаблона → позиции (многострочные наименования, «Раздел N» → категория,
«Итого» → контрольная сумма) → математический аудит.

Иерархия BOM: номер позиции «1.2.3» (pos_path; уровень = число точек) или
колонка «Уровень» шаблона (level: «2», «..2», «.2» — SAP-стиль; минимальный
уровень документа = корень). Страница строки — по разделителю \f в тексте
(OCR отдаёт страницы через \f, см. handler.paged_text).

Текст приходит из общего OCR-слоя (текстовый слой PDF или шлюз). Колонки
текстового слоя разделяются пробелом-пропуском (2+), вертикальной чертой
или табуляцией — типовые выходы pdftotext/-layout и OCR-таблиц.
"""
from __future__ import annotations

import hashlib
import re
from decimal import Decimal, InvalidOperation

_WS_SPLIT = re.compile(r"\s{2,}|\t+")
_NUM_RE = re.compile(r"-?\d[\d\s.,]*")
_FLOAT_RE = re.compile(r"-?\d+(?:[.,]\d+)?")
# Номер позиции: «12», «№12», «12.», «1.2.3» (до 8 уровней) — иерархия BOM.
_INT_RE = re.compile(r"^\s*№?\s*(\d{1,4}(?:\.\d{1,4}){0,7})\s*[.)]?\s*$")
_LEVEL_RE = re.compile(r"^\s*(\.*)\s*(\d{1,2})?\s*$")
_NUM_HEADER_RE = re.compile(r"^\s*\d{1,2}(?:\s+\d{1,2})+\s*$")

# Единицы: дробное количество для штучных — предупреждение
COUNT_UNITS = {"шт", "компл", "комплект", "пар"}


def _norm(s: str) -> str:
    """Нормализация для сравнения с синонимами: нижний регистр, ё→е, пунктуация → пробел.

    № — легитимный символ заголовков («№ п/п»), оставляем.
    """
    s = (s or "").lower().replace("ё", "е")
    s = re.sub(r"[^\w\s/№]", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def to_number(raw: str):
    """RU-число: «1 234,56» → 1234.56; мусор → None."""
    if raw is None:
        return None
    s = str(raw).replace("\xa0", "").replace(" ", "").strip()
    if not s:
        return None
    # обе точки-разделители: последняя — десятичная
    if "," in s and "." in s:
        sep = "." if s.rfind(".") > s.rfind(",") else ","
        s = s.replace(",", "").replace(".", "") if False else s.replace(
            "." if sep == "," else ",", "").replace(sep, ".")
    elif "," in s:
        s = s.replace(",", ".")
    try:
        value = Decimal(s)
    except (InvalidOperation, ValueError):
        return None
    return float(value)


def _cells(line: str) -> list[str]:
    """Строка → ячейки: «|»-таблица OCR, иначе разрыв 2+ пробела / таб."""
    if line.count("|") >= 2:
        parts = [c.strip() for c in line.strip().strip("|").split("|")]
        return [c for c in parts if c != ""]
    parts = [c.strip() for c in _WS_SPLIT.split(line.strip())]
    return [c for c in parts if c != ""]


def _spans(line: str) -> list[tuple[int, int]]:
    """Границы ячеек (как у _cells) в колонках строки; пусто — у «|»-таблиц и строк с табами."""
    if "\t" in line or line.count("|") >= 2:
        return []
    return [(m.start(), m.end()) for m in re.finditer(r"\S+(?: \S+)*", line)]


def align_cells(line: str, header_spans: list[tuple[int, int]]) -> list[str]:
    """Ячейки строки данных по колонкам шапки, когда части ячеек нет.

    «3   Насос К 80-50-200            1   шт.» под шапкой с колонкой
    «Обозначение» — без выравнивания «1» попало бы в обозначение, а
    количество пропало. Ячейка относится к колонке, в чью полосу (до середины
    промежутка к соседней колонке) попадает её центр; если порядок колонок
    при этом ломается — раскладка неоднозначна, остаются ячейки по порядку.
    """
    cells = _cells(line)
    spans = _spans(line)
    if not header_spans or len(spans) != len(cells) or len(cells) >= len(header_spans):
        return cells
    bounds = [(header_spans[j][1] + header_spans[j + 1][0]) / 2 for j in range(len(header_spans) - 1)]
    out = [""] * len(header_spans)
    last = -1
    for (start, end), cell in zip(spans, cells):
        center = (start + end) / 2
        col = next((j for j, b in enumerate(bounds) if center < b), len(header_spans) - 1)
        if col <= last:
            return cells
        out[col] = cell
        last = col
    return out


def _matches(cell: str, synonyms: list[str]) -> bool:
    cell_norm = _norm(cell)
    if not cell_norm:
        return False
    for syn in synonyms:
        syn_norm = _norm(syn)
        if not syn_norm:
            continue
        # короткие метки («№») — только точное совпадение, остальные — подстрока
        if cell_norm == syn_norm or (len(syn_norm) >= 2 and syn_norm in cell_norm):
            return True
    return False


def detect_template(text: str, templates: list[dict]) -> tuple[dict | None, list[str]]:
    """Шаблон по семантическим якорям anchor_keywords.

    Побеждает наибольшая доля совпавших якорей шаблона, при равенстве — их
    число: узкий шаблон («форма 7-ПТО», 5 из 5) обходит универсальный с
    широкими якорями («наименование», «кол-во»… 6 из 9), хотя совпадений у
    универсального больше.
    """
    body = _norm(text[:8000])
    best, best_hits, best_score = None, [], (0.0, 0)
    for tpl in templates or []:
        policy = (tpl.get("detection_policy") or {})
        need = int(policy.get("min_anchors_required") or 2)
        anchors = [kw for kw in (policy.get("anchor_keywords") or []) if _norm(kw)]
        hits = [kw for kw in anchors if _norm(kw) in body]
        score = (len(hits) / len(anchors), len(hits)) if anchors else (0.0, 0)
        if len(hits) >= need and score > best_score:
            best, best_hits, best_score = tpl, hits, score
    return best, best_hits


def _find_header(lines: list[str], tpl: dict) -> tuple[int, dict]:
    """Строка заголовка + отображение {колонка: индекс ячейки}."""
    rules = tpl.get("table_rules") or {}
    depth = max(1, int(rules.get("header_search_depth_lines") or 15))
    columns = rules.get("columns") or {}
    best_i, best_map, best_hits = -1, {}, 0

    for i in range(min(depth, len(lines))):
        cells = _cells(lines[i])
        if len(cells) < 2:
            continue
        mapping, hits = {}, 0
        for key, meta in columns.items():
            for idx, cell in enumerate(cells):
                if _matches(cell, meta.get("match") or []):
                    mapping[key] = idx
                    hits += 1
                    break
        # требуем минимум pos+qty или 3 любые колонки — «шапка», не строка данных
        if hits > best_hits and len(mapping) >= 2:
            best_i, best_map, best_hits = i, mapping, hits

    return best_i, best_map


def _level(cell: str):
    """Колонка уровня BOM: «2» → 2, «..2» → 2, «..» → 2; иначе None."""
    m = _LEVEL_RE.match(cell or "")
    if not m or not (m.group(1) or m.group(2)):
        return None
    return int(m.group(2)) if m.group(2) else len(m.group(1))


def _row_from_cells(cells: list[str], mapping: dict) -> dict:
    row = {}
    for key, idx in mapping.items():
        row[key] = cells[idx] if idx < len(cells) else ""
    return row


def parse(text: str, tpl: dict) -> dict:
    """Текст документа → {rows, totals, math, stats}. Чистая функция — тестируется напрямую."""
    rules = tpl.get("table_rules") or {}
    columns = rules.get("columns") or {}
    skip_pats = [re.compile(p, re.I) for p in (rules.get("skip_line_patterns") or [])]
    totals_pats = [re.compile(p, re.I) for p in (rules.get("totals_patterns") or [])]
    section_pats = [re.compile(p, re.I) for p in (rules.get("section_patterns") or [])]
    tolerance = 0.01

    # (страница, строка): \f — граница страницы; splitlines сам режет и по \f.
    paged: list[tuple[int, str]] = []
    for page_no, page_text in enumerate(text.split("\f"), 1):
        paged += [(page_no, line) for line in page_text.splitlines()]
    lines = [line for _, line in paged]
    header_i, mapping = _find_header(lines, tpl)
    if header_i < 0 or "pos" not in mapping:
        raise ValueError("header_not_found")
    multi_page = "\f" in text
    header_spans = _spans(lines[header_i])

    rows: list[dict] = []
    totals: dict = {}
    section: str | None = None

    for page_no, line in paged[header_i + 1:]:
        stripped = line.strip()
        if not stripped:
            continue
        if any(p.search(_norm(stripped)) for p in skip_pats):
            continue
        if any(p.search(_norm(stripped)) for p in section_pats):
            section = re.sub(r"\s+", " ", stripped)[:255]
            continue
        if any(p.search(_norm(stripped)) for p in totals_pats):
            values = [to_number(m.group(0)) for m in _NUM_RE.finditer(stripped)]
            totals.setdefault("labels", []).append(stripped[:120])
            totals["total_price"] = next((v for v in reversed(values) if v is not None), None)
            continue
        # эхо-шапка на следующем листе
        hcells = _cells(stripped)
        hits = sum(1 for key, meta in columns.items()
                   if any(_matches(c, meta.get("match") or []) for c in hcells))
        if hits >= len(mapping) - 1 and hits >= 2 and all(
                not _INT_RE.match(c) for c in hcells):
            remap_i, remap = _find_header([stripped], tpl)
            if remap:
                mapping = remap
                header_spans = _spans(line)
            continue
        # шапка «1 2 3» (номера колонок вместо названий)
        if _NUM_HEADER_RE.match(stripped) and len(_cells(stripped)) >= len(mapping) - 1:
            continue

        first = align_cells(line, header_spans)
        pos_idx = mapping.get("pos", -1)
        first_cell = str(first[pos_idx]) if 0 <= pos_idx < len(first) else stripped
        pos_match = _INT_RE.match(first_cell)
        starts_row = bool(pos_match) and pos_idx >= 0
        if starts_row and first:
            row_cells = _row_from_cells(first, mapping)
            path = pos_match.group(1)
            row = {
                "pos": int(path.rsplit(".", 1)[-1]),
                "pos_path": path,
                "section": section,
                "raw_name": str(row_cells.get("item_name") or ""),
                "code_doc": str(row_cells.get("code_doc") or ""),
                "qty": to_number(row_cells.get("qty")),
                "unit": str(row_cells.get("unit") or columns.get("unit", {}).get("default", "")),
                "unit_price": to_number(row_cells.get("unit_price")),
                "total_price": to_number(row_cells.get("total_price")),
                "flags": [],
            }
            if "level" in mapping:
                level = _level(str(row_cells.get("level") or ""))
                if level is not None:
                    row["level"] = level
            if multi_page:
                row["page"] = page_no
            rows.append(row)
            continue

        # многострочное наименование: строка без номера — продолжение предыдущей
        if rows:
            rows[-1]["raw_name"] = (rows[-1]["raw_name"] + " " + stripped).strip()[:4000]

    rows = rows[: max(1, int(tpl.get("_max_rows") or 2000))]
    normalize_levels(rows)
    math = run_math_checks(rows, totals, rules)
    stats = {
        "rows": len(rows),
        "sections": len({r["section"] for r in rows if r["section"]}),
        "header_line": header_i + 1,
    }
    return {"rows": rows, "totals": totals, "math": math, "stats": stats}


def normalize_levels(rows: list[dict]) -> None:
    """Явные уровни BOM от минимального в документе (0 = корень), предел 7."""
    levels = [r["level"] for r in rows if isinstance(r.get("level"), int)]
    if not levels:
        return
    base = min(levels)
    for r in rows:
        if isinstance(r.get("level"), int):
            r["level"] = min(7, r["level"] - base)


def _parent_path(path: str) -> str:
    return path.rsplit(".", 1)[0] if "." in path else ""


def run_math_checks(rows: list[dict], totals: dict, rules: dict) -> dict:
    """Формульный контроль, непрерывность нумерации, единицы измерения."""
    checks = rules.get("math_checks") or []
    want_row = any("qty * unit_price" in str(c.get("rule", "")) for c in checks)
    want_totals = any("sum(total_price)" in str(c.get("rule", "")) for c in checks)
    want_seq = any("is_continuous_sequence" in str(c.get("rule", "")) for c in checks)

    row_errors = []
    for r in rows:
        if want_row and r["qty"] is not None and r["unit_price"] is not None and r["total_price"] is not None:
            expected = round(r["qty"] * r["unit_price"], 2)
            if abs(expected - r["total_price"]) > 0.01:
                r["flags"].append({"type": "math_row", "expected": expected})
                row_errors.append({
                    "pos": r["pos"], "expected": expected, "actual": r["total_price"]})
        # непрерывность нумерации — одним проходом ниже

    # Непрерывность — среди «братьев» (тот же родитель «1.2» для «1.2.N»);
    # плоская нумерация — частный случай (родитель — корень).
    gaps: list[dict] = []
    prev: dict[str, tuple[int, str]] = {}
    for r in rows:
        if r["pos"] is None:
            continue
        path = str(r.get("pos_path") or r["pos"])
        parent = _parent_path(path)
        last = prev.get(parent)
        if last is not None and r["pos"] > last[0] + 1:
            after = last[0] if parent == "" else last[1]
            gaps.append({"after_pos": after, "next_pos": r["pos"] if parent == "" else path})
            r["flags"].append({"type": "gap", "after_pos": after})
        prev[parent] = (r["pos"], path)

    # единицы: дробное количество штучных изделий
    for r in rows:
        unit = (r["unit"] or "").strip().lower().rstrip(".,")
        if r["qty"] is not None and unit in COUNT_UNITS and float(r["qty"]) != int(r["qty"]):
            r["flags"].append({"type": "unit_fractional", "unit": r["unit"]})

    computed = None
    if rows and all(r["total_price"] is not None for r in rows):
        computed = round(sum(float(r["total_price"]) for r in rows), 2)
    declared = totals.get("total_price") if isinstance(totals, dict) else None
    totals_ok = None
    if want_totals and computed is not None and declared is not None:
        totals_ok = abs(computed - float(declared)) <= 0.01
        if not totals_ok:
            totals["flags"] = (totals.get("flags") or []) + [{"type": "totals_mismatch"}]

    summary_parts = []
    if row_errors:
        summary_parts.append(f"арифметика строк: {len(row_errors)} расхожд.")
    if gaps:
        summary_parts.append(f"пропуски нумерации: {len(gaps)}")
    if totals_ok is False:
        summary_parts.append("итог не сходится")
    if computed is not None and totals_ok is not False:
        summary_parts.append("итог по строкам сходится")
    if not summary_parts:
        summary_parts.append("математика сходится")

    return {
        "row_errors": row_errors,
        "gaps": gaps,
        "totals": {"declared": declared, "computed": computed, "ok": totals_ok},
        "summary": "; ".join(summary_parts),
    }


def text_sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()
