"""XLSX-сборка (openpyxl): табличные документы и реестр пакета.

Оформление: заголовок организации, название таблицы, шапка с заливкой и
границами, автоподбор ширин колонок, закрепление шапки.
"""
from __future__ import annotations

import io

from openpyxl import Workbook
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

from . import gost

_THIN = Side(style="thin", color="666666")
_BORDER = Border(left=_THIN, right=_THIN, top=_THIN, bottom=_THIN)
_FILL = PatternFill("solid", fgColor=gost.TABLE_HEADER_FILL)


def build_xlsx(title: str, columns: list, rows: list, org_name: str = "",
               sheet_name: str = "Таблица") -> bytes:
    wb = Workbook()
    ws = wb.active
    ws.title = (sheet_name or "Таблица")[:31]

    r = 1
    if org_name:
        ws.cell(row=1, column=1, value=str(org_name)).font = Font(bold=True, size=12)
        ws.merge_cells(start_row=1, start_column=1, end_row=1, end_column=max(1, len(columns)))
        r = 2

    ws.cell(row=r, column=1, value=str(title)).font = Font(bold=True, size=14)
    ws.merge_cells(start_row=r, start_column=1, end_row=r, end_column=max(1, len(columns)))
    ws.cell(row=r, column=1).alignment = Alignment(horizontal="center", wrap_text=True)
    r += 2  # строка-отделитель

    header_row = r
    for i, name in enumerate(columns or [], start=1):
        c = ws.cell(row=header_row, column=i, value=str(name))
        c.font = Font(bold=True, size=11)
        c.fill = _FILL
        c.border = _BORDER
        c.alignment = Alignment(horizontal="center", vertical="center", wrap_text=True)

    for j, row in enumerate(rows or [], start=header_row + 1):
        for i in range(1, len(columns or []) + 1):
            val = row[i - 1] if i - 1 < len(row or []) else ""
            c = ws.cell(row=j, column=i, value=str(val))
            c.border = _BORDER
            c.font = Font(size=11)
            c.alignment = Alignment(vertical="top", wrap_text=True)

    # автоподбор ширин (по 60 символам максимум)
    for i in range(1, len(columns or []) + 1):
        width = max(
            [len(str(columns[i - 1]))] + [
                len(str((rows or [])[j - header_row - 1][i - 1]))
                for j in range(header_row + 1, header_row + 1 + len(rows or []))
                if i - 1 < len((rows or [])[j - header_row - 1])
            ] or [10]
        )
        ws.column_dimensions[get_column_letter(i)].width = min(60, max(10, width + 2))

    if len(rows or []):
        ws.freeze_panes = ws.cell(row=header_row + 1, column=1)

    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()
