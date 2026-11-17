#!/usr/bin/env python3
"""Пересобрать демонстрационные PDF для prl390.

Нужен reportlab и шрифт DejaVu (есть в большинстве Linux). Сами PDF уже
лежат рядом и в git — скрипт нужен, если захочется поменять тексты.

    python3 application/modules/prl390_intake/demo/make_pdfs.py
"""
from pathlib import Path

from reportlab.lib.pagesizes import A4
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

HERE = Path(__file__).resolve().parent
FONT = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"

LETTERS = [
    (
        "01_claim_romashka.pdf",
        "ООО «Ромашка»",
        "Исх. № 118 от 12.11.2026",
        [
            "ПРЕТЕНЗИЯ",
            "по договору поставки № 47/2026 от 03.02.2026",
            "",
            "Уважаемый руководитель!",
            "",
            "В связи с нарушением сроков поставки по договору № 47/2026 требуем",
            "уплатить неустойку в размере 1 245 000,00 руб. в течение 10 календарных",
            "дней с даты получения настоящей претензии.",
            "",
            "Основание: п. 7.2 договора, товарная накладная № 312 от 01.10.2026.",
            "Ответ просим направить в письменной форме по адресу: г. Москва,",
            "ул. Лесная, д. 10.",
            "",
            "Директор ООО «Ромашка»            / И. И. Петров /",
        ],
    ),
    (
        "02_court_notice.pdf",
        "Арбитражный суд города Москвы",
        "Дело № А40-123456/2026",
        [
            "СУДЕБНОЕ ИЗВЕЩЕНИЕ",
            "о назначении дела к судебному разбирательству",
            "",
            "Истец: ООО «Ромашка».",
            "Ответчик: ООО «Компания».",
            "",
            "Арбитражный суд города Москвы извещает, что судебное заседание",
            "по делу № А40-123456/2026 назначено на 25.11.2026 в 11:30",
            "в помещении суда по адресу: г. Москва, ул. Большая Тульская, д. 17.",
            "",
            "Явка представителя обязательна. Отзыв на исковое заявление",
            "необходимо представить не позднее чем за пять дней до заседания.",
            "",
            "Судья Арбитражного суда г. Москвы            / А. С. Иванов /",
        ],
    ),
    (
        "03_tax_requirement.pdf",
        "ИФНС России № 12 по г. Москве",
        "Исх. № 45-16/9876 от 10.11.2026",
        [
            "ТРЕБОВАНИЕ",
            "о представлении документов (информации)",
            "",
            "На основании статьи 93 Налогового кодекса Российской Федерации",
            "требуем в течение пяти рабочих дней со дня получения настоящего",
            "требования представить следующие документы:",
            "",
            "1. Договор с ООО «Ромашка» № 47/2026 и все дополнения к нему.",
            "2. Товарные накладные за период с 01.01.2026 по 30.09.2026.",
            "3. Акты сверки расчётов с контрагентом за 2026 год.",
            "",
            "Непредставление документов влечёт ответственность по ст. 126 НК РФ.",
            "",
            "Начальник отдела камеральных проверок            / С. В. Смирнова /",
        ],
    ),
]


def build() -> None:
    pdfmetrics.registerFont(TTFont("DejaVu", FONT))
    pdfmetrics.registerFont(TTFont("DejaVu-Bold", BOLD))

    for filename, sender, outgoing, lines in LETTERS:
        path = HERE / filename
        c = canvas.Canvas(str(path), pagesize=A4)
        width, height = A4
        c.setFont("DejaVu-Bold", 11)
        c.drawString(50, height - 50, sender)
        c.setFont("DejaVu", 9)
        c.drawString(50, height - 66, outgoing)
        c.line(50, height - 76, width - 50, height - 76)

        y = height - 110
        for i, line in enumerate(lines):
            if i == 0:
                c.setFont("DejaVu-Bold", 14)
            elif i == 1:
                c.setFont("DejaVu", 10)
            else:
                c.setFont("DejaVu", 10.5)
            c.drawString(55, y, line)
            y -= 20 if i > 1 else 24

        c.setFont("DejaVu", 8)
        c.drawString(50, 60, "Демонстрационный документ для prl390. Любые совпадения случайны.")
        c.showPage()
        c.save()
        print("создан", path)


if __name__ == "__main__":
    build()
