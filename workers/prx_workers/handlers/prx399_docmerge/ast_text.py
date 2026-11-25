"""Программное построение AST договора из текста — БЕЗ LLM.

Договор раскладывается в «плоский» список узлов: [{path, title, text, status,
source, history}]. Якоря узлов сравнимы с инструкциями дельт:
  '4.2.1'      — пункты (номерная нумерация любой глубины);
  'раздел-4'   — словесные заголовки «Раздел 4»;
  'ст-1'       — «Статья 1»;
  'прил-1'     — «Приложение 1»;
  '0'          — преамбула (всё до первого заголовка).
Тот же модуль рендерит узлы обратно в текст (экспорт, вход риск-аудита).
"""
from __future__ import annotations

import re

# Заголовки: сначала словесные (Раздел/Статья/Приложение), затем номерные.
_RE_SECTION = re.compile(r"^\s*(?:Раздел|РАЗДЕЛ)\s+(\d{1,3})[.:)]?\s*(.*)$", re.M)
_RE_ARTICLE = re.compile(r"^\s*(?:Статья|СТАТЬЯ|Ст\.)\s*(\d{1,3})[.:)]?\s*(.*)$", re.M)
_RE_APPENDIX = re.compile(r"^\s*(?:Приложение|ПРИЛОЖЕНИЕ)\s*(?:№\s*)?(\d{1,3})[.:)]?\s*(.*)$", re.M)
_RE_NUMBERED = re.compile(r"^\s*(\d{1,3}(?:\.\d{1,3}){0,4})[.)]?\s+(.+)$", re.M)

MAX_TITLE = 300


def parse(text: str) -> list[dict]:
    """Текст → плоские узлы. Один проход, детерминированно."""
    text = (text or "").replace("\r\n", "\n").replace("\r", "\n")
    marks: list[tuple[int, str, str]] = []  # (позиция, path, title)

    def add(match: re.Match, path: str, title: str) -> None:
        marks.append((match.start(), path, title.strip()[:MAX_TITLE]))

    for match in _RE_SECTION.finditer(text):
        add(match, f"раздел-{match.group(1)}", f"Раздел {match.group(1)}. {match.group(2)}")
    for match in _RE_ARTICLE.finditer(text):
        add(match, f"ст-{match.group(1)}", f"Статья {match.group(1)}. {match.group(2)}")
    for match in _RE_APPENDIX.finditer(text):
        add(match, f"прил-{match.group(1)}", f"Приложение {match.group(1)}. {match.group(2)}")
    for match in _RE_NUMBERED.finditer(text):
        add(match, match.group(1), f"Пункт {match.group(1)}.")

    # Внутри одного места побеждает самый длинный (самый глубокий) якорь,
    # узлы сортируются по позиции в тексте — порядок документа сохраняется.
    marks.sort(key=lambda m: (m[0], -len(m[1])))
    deduped: list[tuple[int, str, str]] = []
    last_pos = -1
    for mark in marks:
        if mark[0] == last_pos:
            continue
        deduped.append(mark)
        last_pos = mark[0]

    nodes: list[dict] = []
    if not deduped:
        body = text.strip()
        if body:
            nodes.append(_node("0", "Преамбула", body))
        return nodes

    preamble = text[: deduped[0][0]].strip()
    if preamble:
        nodes.append(_node("0", "Преамбула", preamble))
    for i, (pos, path, title) in enumerate(deduped):
        line_end = pos + len(text[pos:].split("\n")[0])
        end = deduped[i + 1][0] if i + 1 < len(deduped) else len(text)
        # Номерной пункт: остаток строки заголовка — ЭТО контент («2.1.1. Оплата
        # — в течение 10 дней» → text = «Оплата — в течение 10 дней»); у
        # словесных заголовков остаток строки — название, остаётся в title.
        if path[0].isdigit():
            title = title.rsplit(".", 1)[0] + "."
            body = (text[pos + len(text[pos:].split(" ")[0]): line_end].strip()
                    + "\n" + text[line_end:end].strip()).strip()
            body = body.lstrip(". ").strip()
        else:
            body = text[line_end:end].strip()
        nodes.append(_node(path, title, body))

    return nodes


def _node(path: str, title: str, body: str) -> dict:
    return {
        "path": path,
        "title": title,
        "text": body,
        "status": "base",
        "source": "base",
        "history": [],
    }


def render(nodes: list[dict], skip_annulled: bool = True) -> str:
    """Узлы → текст итоговой редакции (экспорт, вход аудита)."""
    lines: list[str] = []
    for node in nodes:
        if skip_annulled and node.get("status") == "annulled":
            continue
        title = (node.get("title") or "").strip()
        body = (node.get("text") or "").strip()
        if title:
            lines.append(title)
        if body:
            lines.append(body)
        lines.append("")
    return "\n".join(lines).strip() + "\n"


def references(text: str) -> list[str]:
    """Ссылки на пункты/разделы/статьи внутри текста — для проверки битых ссылок
    (программная валидация коллизий; PHP делает то же независимо — двойной контур)."""
    found: list[str] = []
    pattern = re.compile(
        r"(?:пункт|п\.|пп\.)\s*(\d{1,3}(?:\.\d{1,3}){0,4})"
        r"|(?:раздел\w*)\s*(\d{1,3})"
        r"|(?:стать\w*)\s*(\d{1,3})",
        re.I,
    )
    for match in pattern.finditer(text):
        if match.group(1):
            found.append(match.group(1))
        elif match.group(2):
            found.append(f"раздел-{match.group(2)}")
        else:
            found.append(f"ст-{match.group(3)}")
    return found
