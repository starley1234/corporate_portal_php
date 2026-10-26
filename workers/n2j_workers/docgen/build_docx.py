"""DOCX-сборка по ГОСТ Р 7.0.97-2016 (python-docx).

Шрифт в файле ссылается на «Times New Roman» — на машине пользователя Word
подставит собственную гарнитуру, поэтому серверу шрифты не нужны.
"""
from __future__ import annotations

import base64
import datetime
import io

from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml.ns import qn
from docx.shared import Cm, Mm, Pt

from . import gost

_ALIGN = {
    "left": WD_ALIGN_PARAGRAPH.LEFT,
    "center": WD_ALIGN_PARAGRAPH.CENTER,
    "right": WD_ALIGN_PARAGRAPH.RIGHT,
    "justify": WD_ALIGN_PARAGRAPH.JUSTIFY,
}


def _set_font(run, name: str = gost.FONT_MAIN, size: int = gost.FONT_SIZE_PT,
              bold: bool = False) -> None:
    run.font.name = name
    run.font.size = Pt(size)
    run.font.bold = bold
    # кириллица: продублировать гарнитуру в rFonts/cs
    rpr = run._element.get_or_add_rPr()
    rfonts = rpr.find(qn("w:rFonts"))
    if rfonts is None:
        rfonts = rpr.makeelement(qn("w:rFonts"), {})
        rpr.append(rfonts)
    for attr in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
        rfonts.set(qn(attr), name)


def _page_number_header(doc: Document, skip_first: bool = False) -> None:
    """Номер страницы — посередине верхнего поля (ГОСТ Р 7.0.97 п. 6.7).
    При титульном листе первый лист не нумеруется, но считается в сквозной
    нумерации (стр. 2 — вторая страница)."""
    sec = doc.sections[0]
    header = sec.header
    p = header.paragraphs[0] if header.paragraphs else header.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    fld = p._p.makeelement(qn("w:fldSimple"), {qn("w:instr"): " PAGE \\* MERGEFORMAT"})
    run = p.add_run()
    _set_font(run, size=gost.FONT_SIZE_FOOTER_PT)
    p._p.append(fld)
    if skip_first:
        # отдельный (пустой) колонтитул первой страницы — номер не ставится.
        # Обращение к first_page_header СОЗДАЁТ ссылку w:headerReference
        # type="first" — иначе просмотрщики могут унаследовать общий колонтитул.
        sec.different_first_page_header_footer = True
        fh = sec.first_page_header
        fp = fh.paragraphs[0] if fh.paragraphs else fh.add_paragraph()
        fp.text = ""


def _setup_page(doc: Document) -> None:
    sec = doc.sections[0]
    sec.page_width = Mm(210)
    sec.page_height = Mm(297)
    sec.left_margin = Mm(gost.MARGIN_LEFT_MM)
    sec.right_margin = Mm(gost.MARGIN_RIGHT_MM)
    sec.top_margin = Mm(gost.MARGIN_TOP_MM)
    sec.bottom_margin = Mm(gost.MARGIN_BOTTOM_MM)


def _para(doc: Document, text: str, *, size: int = gost.FONT_SIZE_PT, bold: bool = False,
          align="justify", indent: bool = True, space_after: int = 6,
          keep_next: bool = False) -> None:
    p = doc.add_paragraph()
    p.alignment = _ALIGN[align]
    if indent:
        p.paragraph_format.first_line_indent = Cm(gost.INDENT_FIRST_CM)
    p.paragraph_format.space_after = Pt(space_after)
    p.paragraph_format.line_spacing = gost.LINE_SPACING
    if keep_next:
        # заголовок не отрывается от следующего текста (разрыв раздела —
        # не в низу страницы, а вместе с содержимым)
        p.paragraph_format.keep_with_next = True
    run = p.add_run(text)
    _set_font(run, size=size, bold=bold)


def _title_page(doc: Document, meta: dict, approve: str = "") -> None:
    """Титульный лист: гриф утверждения — правый верхний угол первого листа
    (ГОСТ п. 5.16); внизу — место составления и год (ГОСТ рис. А.1)."""
    org = (meta.get("org_name") or "").strip()
    title = (meta.get("title") or "").strip()
    subtitle = (meta.get("subtitle") or "").strip()

    # гриф утверждения — первым (правый верхний угол первого листа)
    for line in gost.approve_lines(approve):
        _para(doc, line, size=12, align="right", indent=False, space_after=0)

    _para(doc, org, size=16, bold=True, align="center", indent=False, space_after=0)
    for _ in range(8):
        _para(doc, "", indent=False, space_after=0)
    if subtitle:
        _para(doc, subtitle.upper(), size=16, align="center", indent=False, space_after=6)
    _para(doc, title, size=20, bold=True, align="center", indent=False, space_after=0)
    for _ in range(14):
        _para(doc, "", indent=False, space_after=0)
    city_year = (meta.get("city_year") or "").strip() or str(datetime.date.today().year)
    _para(doc, city_year, size=12, align="center", indent=False, space_after=0)
    doc.add_page_break()


def _approve_block(doc: Document, approve: str) -> None:
    """Гриф утверждения — в правом верхнем углу (ГОСТ п. 5.16)."""
    for line in gost.approve_lines(approve):
        _para(doc, line, size=12, align="right", indent=False, space_after=0)
    _para(doc, "", indent=False, space_after=6)


def _table(doc: Document, block: dict) -> None:
    columns = [str(c) for c in (block.get("columns") or [])]
    rows = [[str(c) for c in (r or [])] for r in (block.get("rows") or [])]

    if block.get("caption"):
        _para(doc, str(block["caption"]), size=gost.FONT_SIZE_TABLE_PT, bold=True,
              align="center", indent=False, space_after=4)

    if not columns:
        return

    table = doc.add_table(rows=1, cols=len(columns))
    table.style = "Table Grid"
    table.alignment = WD_TABLE_ALIGNMENT.CENTER

    hdr = table.rows[0].cells
    for i, name in enumerate(columns):
        if i < len(hdr):
            hdr[i].text = ""
            run = hdr[i].paragraphs[0].add_run(name)
            _set_font(run, size=gost.FONT_SIZE_TABLE_PT, bold=True)

    for row in rows:
        cells = table.add_row().cells
        for i, val in enumerate(row):
            if i < len(cells):
                cells[i].text = ""
                run = cells[i].paragraphs[0].add_run(val)
                _set_font(run, size=gost.FONT_SIZE_TABLE_PT)

    _para(doc, "", indent=False, space_after=4)


def _service_sheets(doc: Document) -> None:
    """Лист согласования и лист внесения изменений — каждый с новой страницы,
    таблицы с пустыми местами под подписи (без вымышленных данных)."""
    doc.add_page_break()
    _para(doc, "ЛИСТ СОГЛАСОВАНИЯ", size=14, bold=True, align="center",
          indent=False, space_after=10, keep_next=True)
    _table(doc, {
        "columns": ["Должность", "Фамилия, имя, отчество", "Подпись", "Дата"],
        "rows": [["Разработал", "", "", ""],
                 ["Согласовал", "", "", ""],
                 ["Согласовал", "", "", ""],
                 ["Утвердил", "", "", ""]],
    })

    doc.add_page_break()
    _para(doc, "ЛИСТ ВНЕСЕНИЯ ИЗМЕНЕНИЙ", size=14, bold=True, align="center",
          indent=False, space_after=6, keep_next=True)
    _para(doc, "К данному документу. Основание для внесения изменения оформляется "
               "отдельным приказом.", size=11, align="center", indent=False, space_after=10)
    _table(doc, {
        "columns": ["Изм.", "Номера листов (страниц)", "Дата", "Сведения об изменении", "Подпись"],
        "rows": [["1", "", "", "", ""],
                 ["2", "", "", "", ""],
                 ["3", "", "", "", ""],
                 ["4", "", "", "", ""]],
    })


def build_docx(doc_json: dict) -> bytes:
    """Структура документа (JSON из воркера) → bytes DOCX по ГОСТ."""
    sections = list(doc_json.get("sections") or [])
    meta = dict(doc_json)

    doc = Document()
    _setup_page(doc)
    _page_number_header(doc, skip_first=bool(doc_json.get("needs_title_page")))

    # базовый стиль Normal — на случай параграфов вне наших хелперов
    normal = doc.styles["Normal"]
    normal.font.name = gost.FONT_MAIN
    normal.font.size = Pt(gost.FONT_SIZE_PT)

    approve = str(doc_json.get("approve_line") or "")

    if doc_json.get("needs_title_page"):
        # гриф — на титульном листе (правый верхний угол)
        _title_page(doc, meta, approve)
    else:
        # без титула гриф — в правом верхнем углу первой страницы
        if approve:
            _approve_block(doc, approve)

    # заголовок документа (если нет титульного)
    if not doc_json.get("needs_title_page") and doc_json.get("title"):
        _para(doc, str(doc_json["title"]), size=16, bold=True, align="center",
              indent=False, space_after=10)

    for sec in sections:
        heading = str(sec.get("heading") or "").strip()

        if heading:
            _para(doc, heading, size=gost.FONT_SIZE_PT, bold=True, align="left",
                  indent=False, space_after=6, keep_next=True)

        for block in (sec.get("blocks") or []):
            btype = str(block.get("type") or "p")

            if btype == "p":
                text = str(block.get("text") or "").strip()
                if text:
                    _para(doc, text)
            elif btype == "ul":
                for item in (block.get("items") or []):
                    text = str(item or "").strip()
                    if text:
                        p = doc.add_paragraph()
                        p.alignment = WD_ALIGN_PARAGRAPH.JUSTIFY
                        p.paragraph_format.left_indent = Cm(1.0)
                        p.paragraph_format.first_line_indent = Cm(0.25)
                        p.paragraph_format.line_spacing = gost.LINE_SPACING
                        p.paragraph_format.space_after = Pt(2)
                        run = p.add_run("– " + text)
                        _set_font(run)
            elif btype == "table":
                _table(doc, block)
            elif btype == "image":
                raw = block.get("bytes") or b""
                if not raw and block.get("b64"):
                    try:
                        raw = base64.b64decode(str(block["b64"]))
                    except Exception:  # noqa: BLE001 — битая картинка не роняет документ
                        raw = b""
                if raw:
                    width_mm = float(block.get("width_mm") or 165)
                    try:
                        doc.add_picture(io.BytesIO(raw), width=Mm(width_mm))
                    except Exception:  # noqa: BLE001
                        _para(doc, "[схема не встроена: формат изображения не принят]",
                              size=10, align="center", indent=False)
                    caption = str(block.get("caption") or "").strip()
                    if caption:
                        _para(doc, caption, size=11, align="center", indent=False, space_after=8)
            elif btype == "note":
                text = str(block.get("text") or "").strip()
                if text:
                    _para(doc, "Примечание — " + text, size=12, align="justify",
                          indent=False, space_after=6)

    # служебные листы — неотъемлемая часть (ГОСТ п. 5.20 допускает
    # лист согласования; лист изменений — стандартная практика ЛНА).
    # Данные НЕ синтезируем — только места для заполнения.
    _service_sheets(doc)

    buf = io.BytesIO()
    doc.save(buf)
    return buf.getvalue()
