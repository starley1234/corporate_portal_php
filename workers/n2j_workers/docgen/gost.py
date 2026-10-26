"""Константы оформления документов по ГОСТ Р 7.0.97-2016.

Ключевые требования стандарта (методические рекомендации Росархива 2019):
  · поля не менее: левое 20 мм (для документов длительных (свыше 10 лет)
    сроков хранения — не менее 30 мм), правое 10 мм, верхнее 20 мм, нижнее 20 мм;
  · гарнитуры Times New Roman № 12–14 (допустимы Arial 12–13, Verdana 12–13,
    Calibri 13–14); текст выравнивается по ширине; абзацный отступ 1,25 см;
  · интервал между строками — полуторный (методические рекомендации);
  · номер страницы — посередине верхнего поля, не менее 10 мм от края;
    нумеруют ВТОРУЮ и последующие страницы (п. 3.2 и 6.7) — первая страница
    (в т.ч. титульный лист) не нумеруется, но входит в сквозную нумерацию;
  · многостраничные документы длительного хранения — с титульным листом;
  · гриф утверждения (п. 5.16) — правый верхний угол ПЕРВОГО листа
    (если есть титульный — на титульном листе);
  · гриф согласования (п. 5.20) допускает отдельный ЛИСТ СОГЛАСОВАНИЯ —
    неотъемлемая часть документа; вместе с листом внесения изменений
    оформляются последними страницами (места под заполнение — без вымысла);
  · курсив и подчёркивание не рекомендуются; полужирный — для заголовков
    и выделения реквизитов.
"""

# Поля страницы, мм
MARGIN_LEFT_MM = 30      # документы длительного хранения
MARGIN_RIGHT_MM = 15     # норма не менее 10 мм
MARGIN_TOP_MM = 20
MARGIN_BOTTOM_MM = 20

# Шрифты
FONT_MAIN = "Times New Roman"
FONT_SIZE_PT = 14        # основной текст
FONT_SIZE_TABLE_PT = 11  # таблицы
FONT_SIZE_FOOTER_PT = 9

# Абзац
INDENT_FIRST_CM = 1.25   # абзацный отступ
LINE_SPACING = 1.5       # полуторный

# Формат листа
PAGE_FORMAT = "A4"

# Палитра таблиц (нейтральная, печатная)
TABLE_HEADER_FILL = "D9E2F3"   # светло-синяя заливка шапки

# Поиск TTF на сервере воркера (для PDF): сначала метрически совместимый
# с Times New Roman Liberation Serif, затем DejaVu.
FONT_DIRS = [
    "/usr/share/fonts/truetype/liberation",
    "/usr/share/fonts/truetype/dejavu",
    "/usr/share/fonts/truetype/liberation2",
]
FONT_CANDIDATES = {
    "regular": ["LiberationSerif-Regular.ttf", "DejaVuSerif.ttf"],
    "bold": ["LiberationSerif-Bold.ttf", "DejaVuSerif-Bold.ttf"],
    "italic": ["LiberationSerif-Italic.ttf", "DejaVuSerif-Italic.ttf"],
    "bold_italic": ["LiberationSerif-BoldItalic.ttf", "DejaVuSerif-BoldItalic.ttf"],
}


def approve_lines(approve: str) -> list:
    """Гриф утверждения → список строк. LLM нередко присылает весь гриф ОДНОЙ
    строкой («УТВЕРЖДАЮ Генеральный директор ООО «Х» ____ Иванов «___» ... г.»)
    — раскладываем по канону ГОСТ п. 5.16:
        УТВЕРЖДАЮ
        Генеральный директор ООО «Х»
        ____________ И.И. Иванов
        «___» ____________ 2026 г.
    Уже многострочный гриф возвращается как есть (пустые строки — долой).
    """
    import re

    raw_lines = [l.strip() for l in str(approve or "").splitlines() if l.strip()]
    if len(raw_lines) > 1:
        return raw_lines

    text = " ".join(raw_lines).strip()
    if not text:
        return []

    out = []

    # 1) служебное слово — всегда первая строка
    m = re.match(r"^(УТВЕРЖДАЮ|УТВЕРЖДЕНО|УТВЕРЖДЁН|УТВЕРЖДЕН(?:Ы|А|О)?)\b\s*(.*)$", text, re.IGNORECASE)
    if m:
        out.append(m.group(1).upper())
        text = m.group(2).strip()

    # 2) дата в хвосте («___» ________ 2026 г. | 05.04.2026 | 05 апреля 2026 г.) — последняя строка
    date_m = re.search(r"(«[^»]{0,12}»\s*_{2,}[^«]*?г?\.?\s*|\d{2}\.\d{2}\.\d{4}\s*г?\.?\s*|"
                       r"«\d{1,2}»\s+\w+\s+\d{4}\s*г?\.?)$", text)
    date_line = ""
    if date_m:
        date_line = date_m.group(1).strip()
        text = text[:date_m.start()].strip()

    # 3) линия подписи (____) с Ф.И.О. после неё
    sign_m = re.search(r"(_{3,})\s*(?:/\s*)?([^_]*?)\s*$", text)
    sign_line = ""
    if sign_m:
        fio = sign_m.group(2).strip()
        sign_line = sign_m.group(1) + ((" " + fio) if fio else "")
        text = text[:sign_m.start()].strip()

    # 4) остаток — должность и организация: одна-две строки по запятым/предлогам
    if text:
        parts = re.split(r",\s*", text)
        buf = ""
        for part in parts:
            buf = (buf + ", " + part) if buf else part
            if len(buf) >= 40:
                out.append(buf.strip())
                buf = ""
        if buf:
            out.append(buf.strip())

    if sign_line:
        out.append(sign_line)
    if date_line:
        out.append(date_line)

    return out


def find_fonts() -> dict:
    """Найти TTF-файлы четырёх начертаний; вернуть {style: path} и поднять
    понятную ошибку, если шрифтов нет (на воркере: apt install fonts-liberation
    fonts-dejavu-core)."""
    import os

    out = {}
    for style, names in FONT_CANDIDATES.items():
        for d in FONT_DIRS:
            for n in names:
                p = os.path.join(d, n)
                if os.path.isfile(p):
                    out[style] = p
                    break
            if style in out:
                break
    missing = [s for s in ("regular", "bold") if s not in out]
    if missing:
        raise RuntimeError(
            "Не найдены TTF-шрифты для PDF ("
            + ", ".join(missing)
            + "). Установите на сервере воркера: apt install fonts-liberation fonts-dejavu-core"
        )
    return out
