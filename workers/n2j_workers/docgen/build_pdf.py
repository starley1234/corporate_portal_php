"""PDF-сборка по ГОСТ Р 7.0.97-2016 (reportlab platypus).

Шрифты — Liberation Serif (метрически совместим с Times New Roman) или
DejaVu Serif с сервера воркера (gost.find_fonts). Оформление зеркалит
DOCX-билдер: те же поля, заголовки, перечисления «–», таблицы с шапкой.
"""
from __future__ import annotations

import base64
import datetime
import io

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY, TA_LEFT, TA_RIGHT
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm as _MM_UNIT


def Mm(value: float) -> float:
    """Миллиметры в точки (в некоторых сборках reportlab класса Mm нет)."""
    return value * _MM_UNIT
from reportlab.platypus import (
    BaseDocTemplate,
    Frame,
    KeepTogether,
    PageBreak,
    PageTemplate,
    Paragraph,
    Spacer,
    Table,
    TableStyle,
)

from . import gost

_HEADER_FILL = colors.HexColor("#" + gost.TABLE_HEADER_FILL)
_FONTS = None


def _fonts() -> dict:
    global _FONTS
    if _FONTS is None:
        from reportlab.pdfbase import pdfmetrics
        from reportlab.pdfbase.ttfonts import TTFont

        found = gost.find_fonts()
        registered = {}
        for style, path in found.items():
            name = "GSerif-" + style
            pdfmetrics.registerFont(TTFont(name, path))
            registered[style] = name

        from reportlab.pdfbase.pdfmetrics import registerFontFamily

        registerFontFamily(
            registered["regular"],
            normal=registered["regular"],
            bold=registered.get("bold", registered["regular"]),
            italic=registered.get("italic", registered["regular"]),
            boldItalic=registered.get("bold_italic", registered.get("bold", registered["regular"])),
        )
        _FONTS = registered
    return _FONTS


def _styles() -> dict:
    f = _fonts()
    base = f["regular"]

    def st(name, **kw):
        defaults = dict(fontName=base, fontSize=13, leading=13 * 1.45, alignment=TA_JUSTIFY)
        defaults.update(kw)
        return ParagraphStyle(name, **defaults)

    return {
        # абзацный отступ 1,25 см (INDENT_FIRST_CM в см → мм: ×10)
        "body": st("body", firstLineIndent=Mm(gost.INDENT_FIRST_CM * 10), spaceAfter=4),
        "item": st("item", leftIndent=Mm(10), firstLineIndent=Mm(2.5), spaceAfter=2),
        "h1": st("h1", fontName=f.get("bold", base), spaceBefore=12, spaceAfter=6,
                 alignment=TA_LEFT, keepWithNext=True),
        "title": ParagraphStyle("title", fontName=f.get("bold", base), fontSize=22,
                                leading=28, alignment=TA_CENTER, spaceAfter=8),
        "subtitle": st("subtitle", fontSize=16, leading=20, alignment=TA_CENTER, spaceAfter=8),
        "org": ParagraphStyle("org", fontName=f.get("bold", base), fontSize=16, leading=20,
                              alignment=TA_CENTER),
        "small": st("small", fontSize=11, leading=14),
        "right": st("right", fontSize=11, leading=14, alignment=TA_RIGHT),
        "center_b": ParagraphStyle("center_b", fontName=f.get("bold", base), fontSize=11,
                                   leading=14, alignment=TA_CENTER, spaceAfter=3),
        "cell": ParagraphStyle("cell", fontName=base, fontSize=10, leading=12.5),
        "cell_b": ParagraphStyle("cell_b", fontName=f.get("bold", base), fontSize=10,
                                 leading=12.5),
        "toc": st("toc", alignment=TA_LEFT, spaceAfter=2),
    }


def _esc(t: str) -> str:
    return (str(t).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


def _on_page(canvas, doc) -> None:
    """Номер страницы — посередине верхнего поля (ГОСТ п. 6.7); при титульном
    листе первая страница не нумеруется, но считается в сквозной нумерации."""
    if doc.page <= 1 and getattr(doc, "_gost_title_page", False):
        return
    f = _fonts()
    canvas.saveState()
    canvas.setFont(f["regular"], gost.FONT_SIZE_FOOTER_PT + 1)
    canvas.drawCentredString(Mm(105), Mm(gost.MARGIN_TOP_MM) - Mm(8), str(doc.page))
    canvas.restoreState()


def _table_flow(block: dict, styles: dict, avail: float) -> Table:
    columns = [str(c) for c in (block.get("columns") or [])]
    rows = [[str(c) for c in (r or [])] for r in (block.get("rows") or [])]

    flow = []
    if block.get("caption"):
        flow.append(Paragraph(_esc(block["caption"]), styles["center_b"]))
    if not columns:
        return flow

    n = len(columns)
    data = [[Paragraph(_esc(c), styles["cell_b"]) for c in columns]]
    for row in rows:
        vals = [_esc(row[i]) if i < len(row) else "" for i in range(n)]
        data.append([Paragraph(v, styles["cell"]) for v in vals])

    col_w = avail / n
    table = Table(data, colWidths=[col_w] * n, repeatRows=1, hAlign="CENTER")
    table.setStyle(TableStyle([
        ("GRID", (0, 0), (-1, -1), 0.5, colors.HexColor("#4B5563")),
        ("BACKGROUND", (0, 0), (-1, 0), _HEADER_FILL),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("TOPPADDING", (0, 0), (-1, -1), 3),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
        ("LEFTPADDING", (0, 0), (-1, -1), 4),
        ("RIGHTPADDING", (0, 0), (-1, -1), 4),
    ]))
    flow.append(table)
    flow.append(Spacer(1, 6))
    return flow


def build_pdf(doc_json: dict) -> bytes:
    """Структура документа (JSON) → bytes PDF по ГОСТ."""
    f = _fonts()
    styles = _styles()

    buf = io.BytesIO()
    doc = BaseDocTemplate(
        buf,
        pagesize=(Mm(210), Mm(297)),
        leftMargin=Mm(gost.MARGIN_LEFT_MM),
        rightMargin=Mm(gost.MARGIN_RIGHT_MM),
        topMargin=Mm(gost.MARGIN_TOP_MM),
        bottomMargin=Mm(gost.MARGIN_BOTTOM_MM),
        title=str(doc_json.get("title") or "Документ"),
        author=str(doc_json.get("org_name") or ""),
    )
    frame = Frame(doc.leftMargin, doc.bottomMargin, doc.width, doc.height, id="main")
    doc.addPageTemplates([PageTemplate(id="gost", frames=[frame], onPage=_on_page)])

    avail = doc.width
    flow = []
    approve = str(doc_json.get("approve_line") or "")
    approve_lines = gost.approve_lines(approve)
    has_title = bool(doc_json.get("needs_title_page"))

    # флаг «первая страница — титульная»: номер на ней не ставится
    doc._gost_title_page = has_title

    if has_title:
        # гриф утверждения — правый верхний угол титульного листа (ГОСТ п. 5.16)
        for line in approve_lines:
            flow.append(Paragraph(_esc(line), styles["right"]))
        if approve_lines:
            flow.append(Spacer(1, Mm(10)))
        if doc_json.get("org_name"):
            flow.append(Spacer(1, Mm(15)))
            flow.append(Paragraph(_esc(doc_json["org_name"]), styles["org"]))
            flow.append(Spacer(1, Mm(65)))
        if doc_json.get("subtitle"):
            flow.append(Paragraph(_esc(str(doc_json["subtitle"]).upper()), styles["subtitle"]))
        flow.append(Paragraph(_esc(doc_json.get("title") or ""), styles["title"]))
        flow.append(Spacer(1, Mm(80)))
        city_year = (doc_json.get("city_year") or "").strip() or str(datetime.date.today().year)
        flow.append(Paragraph(_esc(city_year), styles["small"]))
        flow.append(PageBreak())
    elif approve_lines:
        # без титула — правый верхний угол первой страницы
        for line in approve_lines:
            flow.append(Paragraph(_esc(line), styles["right"]))
        flow.append(Spacer(1, 8))

    if not doc_json.get("needs_title_page") and doc_json.get("title"):
        flow.append(Paragraph(_esc(doc_json["title"]), styles["subtitle"]))
        flow.append(Spacer(1, 4))

    sections = list(doc_json.get("sections") or [])

    # содержание для длинных документов (названия разделов)
    if len(sections) > 5:
        flow.append(Paragraph("Содержание", styles["h1"]))
        for sec in sections:
            heading = str(sec.get("heading") or "").strip()
            if heading:
                flow.append(Paragraph(_esc(heading), styles["toc"]))
        flow.append(PageBreak())

    for sec in sections:
        heading = str(sec.get("heading") or "").strip()
        blocks = list(sec.get("blocks") or [])
        group = []

        if heading:
            group.append(Paragraph(_esc(heading), styles["h1"]))

        for j, block in enumerate(blocks):
            btype = str(block.get("type") or "p")
            node = None

            if btype == "p":
                text = str(block.get("text") or "").strip()
                if text:
                    node = Paragraph(_esc(text), styles["body"])
            elif btype == "ul":
                items = [str(i or "").strip() for i in (block.get("items") or []) if str(i or "").strip()]
                if items:
                    group.append(KeepTogether([Paragraph("– " + _esc(items[0]), styles["item"])]))
                    for item in items[1:]:
                        flow.append(Paragraph("– " + _esc(item), styles["item"]))
            elif btype == "table":
                flow.extend(_table_flow(block, styles, avail))
            elif btype == "image":
                raw = block.get("bytes") or b""
                if not raw and block.get("b64"):
                    try:
                        raw = base64.b64decode(str(block["b64"]))
                    except Exception:  # noqa: BLE001
                        raw = b""
                if raw:
                    try:
                        from reportlab.platypus import Image as RLImage
                        img = RLImage(__import__("io").BytesIO(raw))
                        max_w = avail
                        max_h = Mm(120)
                        k = min(max_w / float(img.imageWidth or 1),
                                max_h / float(img.imageHeight or 1), 1.0)
                        img.drawWidth = (img.imageWidth or max_w) * k
                        img.drawHeight = (img.imageHeight or max_h) * k
                        flow.append(KeepTogether([img]))
                        caption = str(block.get("caption") or "").strip()
                        if caption:
                            flow.append(Paragraph(_esc(caption), styles["small"]))
                    except Exception:  # noqa: BLE001
                        flow.append(Paragraph("[схема не встроена]", styles["small"]))
            elif btype == "note":
                text = str(block.get("text") or "").strip()
                if text:
                    node = Paragraph("Примечание — " + _esc(text), styles["small"])

            if node is not None:
                if j == 0 and group:
                    group.append(node)
                    flow.append(KeepTogether(group))
                    group = []
                else:
                    flow.append(node)

        if group:
            flow.append(KeepTogether(group))

    # служебные листы — каждый с новой страницы, места под заполнение
    flow.append(PageBreak())
    flow.append(Paragraph("ЛИСТ СОГЛАСОВАНИЯ", styles["h1"]))
    flow.extend(_table_flow({
        "columns": ["Должность", "Фамилия, имя, отчество", "Подпись", "Дата"],
        "rows": [["Разработал", "", "", ""],
                 ["Согласовал", "", "", ""],
                 ["Согласовал", "", "", ""],
                 ["Утвердил", "", "", ""]],
    }, styles, avail))
    flow.append(PageBreak())
    flow.append(Paragraph("ЛИСТ ВНЕСЕНИЯ ИЗМЕНЕНИЙ", styles["h1"]))
    flow.append(Paragraph("К данному документу. Основание для внесения изменения "
                          "оформляется отдельным приказом.", styles["small"]))
    flow.append(Spacer(1, 6))
    flow.extend(_table_flow({
        "columns": ["Изм.", "Номера листов (страниц)", "Дата", "Сведения об изменении", "Подпись"],
        "rows": [["1", "", "", "", ""],
                 ["2", "", "", "", ""],
                 ["3", "", "", "", ""],
                 ["4", "", "", "", ""]],
    }, styles, avail))

    doc.build(flow)
    return buf.getvalue()
