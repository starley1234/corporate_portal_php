"""Тематический чанкинг собранного договора для риск-аудита (Map-Reduce).

Договор режется не «по 1000 символов», а по смыслу: узлы попадают в тематические
корзины по ключевым словам заголовка и текста. Каждая корзина — отдельный
Map-вызов модели с изолированным контекстом; Reduce объединяет отчёты.
Детерминированно и без расхода модели.
"""
from __future__ import annotations

from .limits import limits

THEMES: tuple[tuple[str, tuple[str, ...]], ...] = (
    ("Расчёты, цена и платежи", ("платеж", "оплат", "стоимост", "цена", "сумм", "тариф", "аванс", "зачет")),
    ("Ответственность и штрафы", ("ответственн", "неустойк", "штраф", "пени", "убытк", "возмещен")),
    ("Приёмка, поставка и сроки", ("приемк", "поставк", "сдач", "выполнен", "этап", "качеств")),
    ("Расторжение и форс-мажор", ("расторж", "прекращ", "форс-мажор", "непреодолим", "срок действия")),
)

FALLBACK = "Прочие условия"


def _theme_of(node: dict) -> str:
    haystack = (node.get("title", "") + "\n" + node.get("text", "")).lower()
    for theme, keywords in THEMES:
        if any(keyword in haystack for keyword in keywords):
            return theme
    return FALLBACK


def chunk(nodes: list[dict], max_chars: int | None = None) -> list[dict]:
    """Узлы → тематические чанки. Переполнение режется по абзацам, чанк
    несёт список путей (chunk_path) — риск знает, откуда он вычитан."""
    if max_chars is None:
        max_chars = limits()["max_chunk_chars"]

    buckets: dict[str, list[dict]] = {}
    for node in nodes:
        if node.get("status") == "annulled":
            continue
        buckets.setdefault(_theme_of(node), []).append(node)

    chunks: list[dict] = []
    for theme in [theme for theme, _ in THEMES] + [FALLBACK]:
        nodes_in_theme = buckets.get(theme) or []
        if not nodes_in_theme:
            continue
        buffer: list[str] = []
        paths: list[str] = []
        size = 0
        for node in nodes_in_theme:
            piece = (node.get("title", "") + "\n" + node.get("text", "")).strip()
            # Одиночный узел больше лимита (гигантский пункт) режется по абзацам,
            # иначе чанк не влезает в контекст модели.
            pieces = [part for part in piece.split("\n\n") if part.strip()] or [piece]
            pieces = [part[i:i + max_chars] for part in pieces for i in range(0, len(part), max_chars)]
            for part in pieces:
                if size + len(part) > max_chars and buffer:
                    chunks.append({"path": ";".join(paths), "title": theme, "text": "\n\n".join(buffer)})
                    buffer, paths, size = [], [], 0
                buffer.append(part)
                if str(node.get("path")) not in paths:
                    paths.append(str(node.get("path")))
                size += len(part)
        if buffer:
            chunks.append({"path": ";".join(paths), "title": theme, "text": "\n\n".join(buffer)})
    return chunks
