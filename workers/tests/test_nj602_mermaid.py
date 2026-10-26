"""Тесты mermaid-санитайзера nj602 (качество схемы без регрессий).

Запуск из каталога workers/:
    python3 -m pytest tests/test_nj602_mermaid.py -q

До фикса модели иногда возвращали схему внутри ```mermaid … ```, с вводной
строкой поверх объявления или вообще не-диаграмму — mermaid.js падал и вид
статьи ломался. Санитайзер приводит ответ к чистому flowchart/graph.
"""

import re
from pathlib import Path

import n2j_workers.handlers.nj602_article as mod


def test_keep_clean_flowchart():
    src = 'flowchart TD\n    A["Сбор"] --> B["Анализ"]'
    assert mod._validate_mermaid(src) == src


def test_strip_mermaid_fence():
    src = "```mermaid\nflowchart TD\nA-->B\n```"
    # вокруг стрелки появляется ровно один пробел — построчный вид схемы
    assert mod._validate_mermaid(src) == "flowchart TD\nA --> B"


def test_cut_preamble_before_declaration():
    src = "Вот подходящая схема процесса:\nflowchart TD\nA-->B"
    assert mod._validate_mermaid(src) == "flowchart TD\nA --> B"


def test_reject_non_diagram():
    assert mod._validate_mermaid("classDiagram\nA <|-- B") == ""
    assert mod._validate_mermaid("sequenceDiagram\nA->>B: hi") == ""


def test_empty_and_plain_text():
    assert mod._validate_mermaid("") == ""
    assert mod._validate_mermaid("просто текст без диаграммы") == ""


def test_keep_graph_and_identifiers_case():
    src = 'graph LR\nPT["проверка"] --> OK["готово"]'
    assert mod._validate_mermaid(src) == src


# ---------------------------------------------------------------------------
# _fallback_mermaid: детерминированный каркас при пустой схеме прохода 2
# ---------------------------------------------------------------------------

def test_fallback_mermaid_from_headings():
    body = "## Сбор данных\n\nтекст\n\n## Анализ\n\nтекст\n"
    mm = mod._fallback_mermaid(body)
    assert mm.startswith("flowchart TD")
    assert "N0" in mm and "N1" in mm
    assert mod._validate_mermaid(mm) == mm


def test_fallback_mermaid_when_no_headings():
    mm = mod._fallback_mermaid("Просто текст без заголовков.\n")
    assert mm.startswith("flowchart TD")
    assert mod._validate_mermaid(mm) == mm


def test_parse_extras_substitutes_empty_mermaid():
    body = "## Сбор данных\n\nтекст\n\n## Анализ\n\nтекст\n"
    extras = mod._parse_extras(
        '{"title":"T","summary":"S","tags":["a"],"mermaid":"","image_prompt":""}',
        body, "тема")
    assert extras["mermaid"].startswith("flowchart TD")


# ---------------------------------------------------------------------------
# Живой журнал прогона (trace) — метка фаз едет в result задачи и на сайт
# ---------------------------------------------------------------------------

def test_mark_appends_trace_entry():
    trace = []
    mod._mark(trace, "sources", "найдено 5")
    assert trace == [{"t": trace[0]["t"], "phase": "sources", "note": "найдено 5"}]
    assert trace[0]["phase"] == "sources"


def test_handle_result_carries_trace(monkeypatch):
    """Успешный (короткий) прогон — result должен вернуть trace со стартовой
    меткой, а не падать на незаданных API/токенах (важно: handle() на этом
    этапе должен завершиться с RuntimeError с УКАЗАНИЕМ места, а не тихо)."""
    import asyncio

    # обрываем конвейер сразу после брифинга контролируемой ошибкой темы
    calls = {}

    async def fake_brief(client):
        calls["brief"] = True
        return {}

    monkeypatch.setattr(mod, "_brief", fake_brief)

    class Ctx:
        llm = None
        config = None
        pg = None  # handle не доходит до RAG/LLM — останавливаемся на пустой теме

    async def run():
        try:
            await mod.handle(Ctx(), {"article_id": 1, "code": "cnttest", "topic": ""})
            return None
        except RuntimeError as e:
            return str(e)

    err = asyncio.run(run())
    assert err is not None, "пустая тема должна прерывать прогон честной ошибкой"
    assert "Пустая тема" in err


# ---------------------------------------------------------------------------
# Построчная нормализация: «много схем не отрисовывается» (владелец, 26.09)
# ---------------------------------------------------------------------------

def test_single_line_flowchart_gets_broken_into_lines():
    """Пример владельца: вся схема одной строкой — mermaid такое не рисует."""
    out = mod._validate_mermaid('flowchart TD A["Сбор данных"] --> B{"Данные полные?"} --> C["Анализ"]')

    lines = out.splitlines()
    assert lines[0] == "flowchart TD"
    assert len(lines) == 3, out
    assert 'A["Сбор данных"] --> B{"Данные полные?"}' in lines[1]
    assert 'B{"Данные полные?"} --> C["Анализ"]' in lines[2]


def test_ampersand_chain_is_expanded():
    """Пример владельца: 'C & D & E --> F' — расклеиваем в три связи."""
    out = mod._validate_mermaid("flowchart TD\n    C & D & E --> F")

    assert out.splitlines() == ["flowchart TD", "    C --> F", "    D --> F", "    E --> F"]


def test_semicolons_become_separate_lines():
    out = mod._validate_mermaid("flowchart TD\n    A --> B; C --> D")

    assert out.splitlines() == ["flowchart TD", "    A --> B", "    C --> D"]


def test_labels_with_arrows_and_semicolons_survive():
    """Кавычки — не синтаксис: тире и ';' внутри подписи узла не режем."""
    src = 'flowchart TD\nA["Пункт — тире; и точка"] --> B["Готово"]'
    assert mod._validate_mermaid(src) == src


def test_arrow_label_keeps_tight_spacing():
    src = 'flowchart LR\n  B -->|"да"| C["Публикация"]'
    assert mod._validate_mermaid(src) == src


def test_normalization_is_idempotent():
    src = 'flowchart TD\n    A["Сбор"] --> B["Анализ"]\n    B["Анализ"] --> C["Итог"]'
    once = mod._validate_mermaid(src)
    assert mod._validate_mermaid(once) == once


def test_subgraph_is_not_rewritten():
    src = 'flowchart TD\n  subgraph Этап\n    A --> B\n  end'
    assert mod._validate_mermaid(src) == src


# ---------------------------------------------------------------------------
# Кнопка «Перегенерировать схему» (блок «Модерация»): режим mode=mermaid
# ---------------------------------------------------------------------------

class _Llm:
    def __init__(self, payload: str):
        self.payload = payload
        self.messages = []

    async def chat_with_usage(self, messages, **kw):
        self.messages = messages
        return self.payload, {"_llm_model": "test-model", "completion_tokens": 12}


class _Ctx:
    def __init__(self, llm):
        self.llm = llm
        self.steps = []

    async def progress(self, stage=None, note=None):
        self.steps.append(stage)

    async def heartbeat(self):
        return True


def _patch_regen(monkeypatch, body_md: str, reply: str):
    import json as _json

    posts: list[tuple[str, dict]] = []
    ctx = _Ctx(_Llm(reply))

    async def fake_brief(client):
        return {"enabled": True}

    async def fake_get(client, path):
        return True, "", {"body_md": body_md, "topic": "тема статьи"}

    async def fake_post(client, path, data):
        posts.append((path, data))
        return True, "", _json.loads("{}")

    monkeypatch.setattr(mod, "_brief", fake_brief)
    monkeypatch.setattr(mod, "_get_site", fake_get)
    monkeypatch.setattr(mod, "_post_site", fake_post)

    return ctx, posts


def test_regen_mode_sends_only_mermaid(monkeypatch):
    """Перегенерация НЕ трогает текст: уходит ровно один POST со схемой."""
    import asyncio
    import json

    ctx, posts = _patch_regen(
        monkeypatch,
        "## Сбор\n\n" + "текст статьи " * 40,
        json.dumps({"title": "T", "summary": "S", "tags": ["a"], "image_prompt": "",
                    "mermaid": 'flowchart TD A["Сбор"] --> B["Итог"]'}),
    )

    res = asyncio.run(mod.handle(ctx, {"code": "cntregen", "mode": "mermaid"}))

    assert res["ok"] is True and res["code"] == "cntregen"
    assert res["mermaid_chars"] > 0

    sent = dict(posts)
    assert "/mermaid/cntregen" in sent, [p for p, _ in posts]
    assert not [p for p, _ in posts if p.startswith("/item/")]

    mm = sent["/mermaid/cntregen"]["mermaid"]
    assert mm.splitlines() == ["flowchart TD", 'A["Сбор"] --> B["Итог"]']


def test_regen_mode_without_body_fails_clearly(monkeypatch):
    import asyncio

    import pytest

    ctx, _ = _patch_regen(monkeypatch, "", "{}")

    with pytest.raises(RuntimeError) as e:
        asyncio.run(mod.handle(ctx, {"code": "cntempty", "mode": "mermaid"}))

    assert "нет тела" in str(e.value)


def test_regen_mode_falls_back_to_headings(monkeypatch):
    """Модель не дала схему — собираем каркас из разделов статьи."""
    import asyncio

    body = ("## Сбор данных\n\n" + "текст раздела " * 12 + "\n\n"
            + "## Анализ\n\n" + "текст раздела " * 12 + "\n")

    ctx, posts = _patch_regen(
        monkeypatch,
        body,
        '{"title":"T","summary":"S","tags":["a"],"mermaid":"","image_prompt":""}',
    )

    res = asyncio.run(mod.handle(ctx, {"code": "cntfb", "mode": "mermaid"}))

    assert res["ok"] is True
    mm = dict(posts)["/mermaid/cntfb"]["mermaid"]
    assert mm.startswith("flowchart TD")
    assert 'N0["Сбор данных"]' in mm


# ---------------------------------------------------------------------------
# Примеры владельца (26.09): «много схем не отрисовываются»
# ---------------------------------------------------------------------------

def test_owner_single_line_run_on():
    """Однострочник: связи не должны «слипаться» — раньше выходил мусор."""
    src = ('flowchart TD A["Выбор бизнес-идеи"] --> B{"Оценка ресурсов"} '
           'B -->|"Бюджет 0 ₽"| C["Биржи и контент"] '
           'B -->|"До 50 000 ₽"| D["Маркетплейсы и TG-каналы"] '
           'B -->|"300 000+ ₽"| E["E-commerce и B2B агентства"] '
           'C & D & E --> F["Выбор технологического стека"] '
           'F --> G["Разработка веб-приложения"]')

    out = mod._validate_mermaid(src)
    lines = out.splitlines()

    assert lines[0] == "flowchart TD"
    assert 'A["Выбор бизнес-идеи"] --> B{"Оценка ресурсов"}' in lines
    assert 'B -->|"Бюджет 0 ₽"| C["Биржи и контент"]' in lines
    assert 'C --> F["Выбор технологического стека"]' in lines
    assert 'D --> F["Выбор технологического стека"]' in lines
    assert 'E --> F["Выбор технологического стека"]' in lines
    # ни одной склейки вида «…} B» (это и был сломанный разбор)
    assert not [ln for ln in lines if ln.rstrip().endswith(" B")]
    assert mod._validate_mermaid(out) == out


def test_owner_two_diagrams_in_one_answer():
    """Второй 'flowchart TD' в теле — синтаксическая ошибка: оставляем одну схему."""
    src = ('flowchart TD\n'
           '    A["Начало обучения"]\n'
           '    A --> B{"Выбор направления"}\n'
           '    B -->|"Цифровое творчество"| C["3D-моделирование и анимация"]\n'
           '    C --> E["Участие в конкурсах (Пиксель, Дивный мир)"]\n'
           '    E --> G["Интеграция в рынок"]\n'
           '\t\n'
           'flowchart TD\n'
           '    A["Расширение альянсов (Артемида)"] --> B["Создание правовой базы"]\n'
           '    B --> C["Развитие инфраструктуры"]\n'
           '    D -->|Технологии| E["Российские и европейские разработки"]\n'
           '    F --> H["Коммерческое использование орбиты"]')

    out = mod._validate_mermaid(src)

    assert out.count("flowchart") == 1, out
    assert "Артемида" not in out  # вторая схема отброшена целиком
    assert '-->|"Технологии"|' not in out
    assert mod._validate_mermaid(out) == out


def test_owner_unquoted_edge_label_gets_quoted():
    src = ('flowchart TD\n'
           '    D -->|Технологии| E["Российские разработки"]\n'
           '    D -->|Наука| G["Сигналы извне"]')

    out = mod._validate_mermaid(src)

    assert 'D -->|"Технологии"| E["Российские разработки"]' in out
    assert 'D -->|"Наука"| G["Сигналы извне"]' in out


def test_owner_mid_arrow_label_is_converted():
    """«A -- текст --> B» mermaid ломает — переводим в канон -->|\"текст\"|."""
    out = mod._validate_mermaid('flowchart TD\n  A["Старт"] -- развитие --> B["Итог"]')

    assert out.splitlines() == ["flowchart TD", '  A["Старт"] -->|"развитие"| B["Итог"]']


def test_owner_stray_second_declaration_without_edges():
    """Первый кусок без связей (огрызок), второй — рабочая схема: берём рабочую."""
    src = ('flowchart TD\n\nflowchart TD\n'
           '    A["Первый шаг"] --> B["Второй шаг"]')

    out = mod._validate_mermaid(src)

    assert out.splitlines() == ["flowchart TD", '    A["Первый шаг"] --> B["Второй шаг"]']


def test_owner_chain_single_line_keeps_chain():
    out = mod._validate_mermaid('flowchart TD A["Сбор"] --> B{"Полно?"} --> C["Анализ"] --> D["Итог"]')

    assert out.splitlines() == [
        "flowchart TD",
        'A["Сбор"] --> B{"Полно?"}',
        'B{"Полно?"} --> C["Анализ"]',
        'C["Анализ"] --> D["Итог"]',
    ]


# ---------------------------------------------------------------------------
# Режим mermaid_fix: пакетная починка уже сохранённых схем (без ИИ)
# ---------------------------------------------------------------------------

BROKEN = ('flowchart TD A["Старт"] --> B["Финиш"] C & D --> E["Итог"] '
          'flowchart TD\nA["Вторая схема"] --> B["Лишняя"]')
CLEAN = 'flowchart TD\n    A["Сбор"] --> B["Анализ"]'


def _patch_fix(monkeypatch, articles: dict[str, str]):
    posts: list[tuple[str, dict]] = []

    async def fake_brief(client):
        return {"enabled": True}

    async def fake_get(client, path):
        code = path.rsplit("/", 1)[-1]
        if code not in articles:
            return False, "404", {}
        return True, "", {"body_md": "т" * 400, "topic": "тема", "mermaid": articles[code]}

    async def fake_post(client, path, data):
        posts.append((path, data))
        return True, "", {}

    monkeypatch.setattr(mod, "_brief", fake_brief)
    monkeypatch.setattr(mod, "_get_site", fake_get)
    monkeypatch.setattr(mod, "_post_site", fake_post)

    return posts


def test_fix_mode_repairs_broken_and_skips_clean(monkeypatch):
    import asyncio

    ctx = _Ctx(_Llm("{}"))
    posts = _patch_fix(monkeypatch, {"cntbad": BROKEN, "cntok": CLEAN})

    res = asyncio.run(mod.handle(ctx, {"mode": "mermaid_fix", "codes": ["cntbad", "cntok"]}))

    assert res["ok"] is True
    assert (res["total"], res["fixed"], res["skipped"], res["failed"]) == (2, 1, 1, 0)

    sent = dict(posts)
    assert "/mermaid/cntbad" in sent and "/mermaid/cntok" not in sent

    mmd = sent["/mermaid/cntbad"]["mermaid"]
    assert mmd.count("flowchart") == 1          # вторая диаграмма отброшена
    assert "C --> E" in mmd and "D --> E" in mmd  # «&»-склейка раскрыта
    assert mod._validate_mermaid(mmd) == mmd      # повторный прогон ничего не меняет


def test_fix_mode_requires_codes():
    import asyncio

    import pytest

    ctx = _Ctx(_Llm("{}"))

    with pytest.raises(RuntimeError) as e:
        asyncio.run(mod.handle(ctx, {"mode": "mermaid_fix"}))

    assert "кода статьи" in str(e.value)


def test_fix_mode_counts_unreachable_article(monkeypatch):
    import asyncio

    ctx = _Ctx(_Llm("{}"))
    _patch_fix(monkeypatch, {"cntok": CLEAN})

    res = asyncio.run(mod.handle(ctx, {"mode": "mermaid_fix", "codes": ["cntmissing"]}))

    assert res["failed"] == 1 and res["fixed"] == 0


# ---------------------------------------------------------------------------
# Кавычка после закрывающей скобки: «D{"текст}"» — mermaid падает
# (живая статья 26.09: «Syntax error in text» вместо схемы)
# ---------------------------------------------------------------------------

def test_rhombus_quote_after_brace():
    out = mod._validate_mermaid('flowchart TD\n    C --> D{"Направления развития}"]'[0:-1])

    assert out.splitlines() == ["flowchart TD", '    C --> D{"Направления развития"}']


def test_rhombus_missing_closing_quote():
    out = mod._validate_mermaid('flowchart TD\n    C --> D{"Направления развития}')

    assert '"Направления развития"' in out


def test_square_and_round_shapes_quote_after_bracket():
    src = ('flowchart TD\n'
           '    A["Старт]" --> B("Финиш)")\n'
           '    B --> C["Итог"]')

    out = mod._validate_mermaid(src)

    assert 'A["Старт"] --> B("Финиш")' in out
    assert mod._validate_mermaid(out) == out


def test_clean_shapes_untouched():
    src = ('flowchart TD\n'
           '    A["Скобки (внутри) и — тире; точка"] --> B{"Вопрос?"}\n'
           '    B --> C("Круглый [узел]")')
    assert mod._validate_mermaid(src) == src


def test_tabs_do_not_break_shape_repair():
    # табы mermaid переносит (проверено на 10.9.3), но фикс кавычки и при них обязан сработать
    src = 'flowchart TD\n\tC --> D{"Развитие}"\t\n\tD --> E["Итог"]'

    out = mod._validate_mermaid(src)

    assert out.splitlines()[1].strip() == 'C --> D{"Развитие"}'
    assert out.splitlines()[2].strip() == 'D --> E["Итог"]'

def test_two_diagrams_with_broken_shape_in_second():
    # склейка двух схем: ломаная форма во второй не должна ломать выбор диаграммы
    src = ('flowchart TD\n'
           '    A["Обучение"] --> B["Практика"]\n'
           '    B --> C["Работа"]\n'
           'flowchart TD\n'
           '    A["Альянсы"] --> B{"Развитие}"}\n'
           '    B --> C["Китай"]\n'
           '    B --> D["Наука"]')

    out = mod._validate_mermaid(src)

    # правило выбора: одна диаграмма с максимумом связей (здесь — вторая),
    # и её ломаная форма обязана быть починена
    assert out.count("flowchart") == 1
    assert out.splitlines()[0] == "flowchart TD"
    assert 'B{"Развитие"}' in out


# ---------------------------------------------------------------------------
# Детектор сайта (Service_Nj602_Content::mermaid_looks_broken) обязан отбирать
# те же схемы, что воркер умеет починить: иначе кнопка «Починить схемы» живую
# статью с «D{"текст}"» просто не увидит.
# ---------------------------------------------------------------------------

PHP_SERVICE = (Path(__file__).resolve().parents[2]
               / "application" / "modules" / "nj602_content"
               / "classes" / "Service" / "Nj602" / "Content.php")

# зеркало воркерных _MM_SHAPE_FIXES: закрывающая скобка фигуры ПЕРЕД кавычкой
SHAPE_QUOTE_RES = (
    re.compile(r'\{"[^"{}]*\}"'),
    re.compile(r'\["[^"\[\]]*\]"'),
    re.compile(r'\("[^"()]*\)"'),
)


def test_site_detector_has_shape_quote_rule():
    src = PHP_SERVICE.read_text(encoding="utf-8")

    assert r'~\{"[^"{}]*\}"~u' in src, "сервис nj602: пропало правило «кавычка после }»"
    assert r'~\["[^"\[\]]*\]"~u' in src, "сервис nj602: пропало правило «кавычка после ]»"
    assert r'~\("[^"()]*\)"~u' in src, "сервис nj602: пропало правило «кавычка после )»"
    assert r'[{]\"[^\"{}]*[}]\"' in src, "бейдж: пропало правило «кавычка после }»"
    assert r'[(]\"[^\"()]*[)]\"' in src, "бейдж: пропало правило «кавычка после )»"


def test_shape_quote_rule_catches_live_article_and_spares_clean_ones():
    live = ('flowchart TD\n'
            '    C --> D{"Направления развития}"\t\n'
            '    D --> E["Итог"]')
    assert any(rx.search(live) for rx in SHAPE_QUOTE_RES), \
        "живая схема 26.09 не попадёт в список на починку"

    clean = ('flowchart TD\n'
             '    A["Сотворение (2026)"] --> B{"Рост [в %]"}\n'
             '    B --> C("Финиш")\n'
             '    C --> D["Итог"]')
    assert not any(rx.search(clean) for rx in SHAPE_QUOTE_RES), \
        "здоровая схема попадёт в «сломанные» (ложное срабатывание)"

    # то же правило должно ловить формы [] и () с кавычкой не на месте
    assert any(rx.search('flowchart TD\n    A["Старт]"') for rx in SHAPE_QUOTE_RES)
    assert any(rx.search('flowchart TD\n    A("Старт)"') for rx in SHAPE_QUOTE_RES)
