"""Тематический чанкинг: корзины по смыслу, лимит размера, annulled не ходят."""
from prx_workers.handlers.prx399_docmerge import chunks


def _node(path, title, text, status="base"):
    return {"path": path, "title": title, "text": text, "status": status}


def test_thematic_buckets():
    nodes = [
        _node("1.1", "Пункт 1.1. Цена", "Цена договора 100 руб. Оплата в течение 10 дней."),
        _node("3.1", "Пункт 3.1. Ответственность", "За просрочку — неустойка 0,1% в день."),
        _node("5.1", "Пункт 5.1. Расторжение", "Договор может быть расторгнут уведомлением."),
        _node("9.1", "Пункт 9.1. Реквизиты", "Подписи сторон."),
    ]
    parts = chunks.chunk(nodes)
    titles = [part["title"] for part in parts]
    assert "Расчёты, цена и платежи" in titles
    assert "Ответственность и штрафы" in titles
    assert "Расторжение и форс-мажор" in titles
    assert "Прочие условия" in titles  # реквизиты без ключевых слов
    assert all(part["path"] for part in parts)


def test_annulled_nodes_are_excluded():
    parts = chunks.chunk([_node("1.1", "Цена", "Оплата.", status="annulled")])
    assert parts == []


def test_oversized_theme_is_split_by_cap():
    big = _node("1.1", "Цена и оплата", "оплата " * 4000)
    parts = chunks.chunk([big], max_chars=5000)
    assert len(parts) >= 2
    assert all(len(part["text"]) <= 5000 for part in parts)
