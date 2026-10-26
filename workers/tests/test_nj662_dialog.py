"""Тесты nj662.dialog / nj662.quick — «Диалог двух ИИ».

Модель и сеть поддельные: проверяем ЛОГИКУ хода, а не качество текстов реплик.

Что обязано держаться (иначе владелец увидит молчащий или зацикленный диалог):

  * `_agent_messages`: реплики раскладываются по ролям чата (свои — assistant,
    чужие — user с именем автора), материалы и найденное в интернете едут перед
    заданием, свежая реплика собеседника не дублируется;
  * `_materials_block` / `_web_block`: агент обязан опираться на факты, значит
    названия, адреса и признак отбора обязаны попадать в подсказку;
  * `_search_query`: пустой или мусорный ответ модели не теряет поиск — уходит
    запасной запрос из слов темы;
  * бюджет поиска — это счётчик поисков на диалог: 0 поисков в контексте = НИ
    одного обращения к «утке» (иначе поиск идёт против настройки владельца);
  * `handle`: поиск идёт ДО реплик, обмен отдаётся сайту одним POST /turn (сайт
    сам ставит следующий ход), а пауза закрывает задачу миром, а не падением;
  * отказ модели закрывает диалог причиной (`/fail`) — страница обязана сказать,
    что случилось, а не застыть в «идёт»;
  * `handle_quick`: подбор участников понимает и плоский JSON, и вложенный
    (агенты массивом), и ограду ```json — порт генератора из наработок;
  * `_service_model`: служебные вызовы (запрос поиска, сводка) слушают NJ662_MODEL.
"""

from __future__ import annotations

import json

import pytest

import n2j_workers.handlers.nj662_dialog as mod


# --------------------------------------------------------------------------
# Поддельные модель, контекст задачи и сайт
# --------------------------------------------------------------------------

class FakeLlm:
    """Модель-пустышка: отвечает по очереди заготовленными текстами."""

    model = "local::fake-model"

    def __init__(self, answers=None):
        self.answers = list(answers or [])
        self.calls: list[dict] = []

    async def chat_with_usage(self, messages, model=None, **options):
        self.calls.append({"messages": messages, "model": model, "options": options})
        text = self.answers.pop(0) if self.answers else "Ответ модели."

        return text, {"total_tokens": 11}


class FakeCtx:
    def __init__(self, llm=None):
        self.llm = llm if llm is not None else FakeLlm()
        self.steps: list[tuple[str, str]] = []

    async def progress(self, stage: str, note: str = ""):
        self.steps.append((stage, note))


class FakeSite:
    """Подменяет `_api` и `_api_soft`: собирает запросы воркера к сайту."""

    def __init__(self, context: dict, turn_ok: bool = True, quick: dict | None = None):
        self.context = context
        self.turn_ok = turn_ok
        self.quick = quick or {"ok": True, "a_name": "Структура", "b_name": "Импульс",
                               "started": True}
        self.calls: list[tuple[str, dict]] = []

    async def __call__(self, ctx, path, data, timeout=120.0):
        self.calls.append((path, data))

        if path == "/context":
            return dict(self.context)
        if path == "/turn":
            return {"ok": self.turn_ok, "done": True, "error": "" if self.turn_ok else "нет"}
        if path == "/fail":
            return {"ok": True}
        if path == "/progress":
            return {"ok": True}
        if path == "/quick":
            return dict(self.quick)

        raise AssertionError(f"неожиданный запрос к сайту: {path}")

    def path(self, name: str):
        for p, data in self.calls:
            if p == name:
                return data

        return None


def _context(**over) -> dict:
    context = {
        "ok": True,
        "dialog_id": 7,
        "turn": 1,
        "pairs": 5,
        "topic": "Как ускорить выдачу заказов на складе",
        "brief": "Склад растёт, ошибки комплектации участились",
        "agents": {
            "a": {"name": "Структура", "system": "Ты раскладываешь на шаги.",
                  "model": "openrouter::google/gemini-2.5-flash-lite"},
            "b": {"name": "Импульс", "system": "Ты предлагаешь ходы.",
                  "model": "TOOLFORGE::unsloth/qwen3.5-9b"},
        },
        "history": [{"who": "a", "author": "Структура", "content": "Первая реплика."},
                    {"who": "b", "author": "Импульс", "content": "Вторая реплика."}],
        "summary": "Договорились взять замеры.",
        "knowledge": [],
        "search": {"left": 0, "used": 0},
        "limits": {"sources_max": 4, "pages_max": 2, "page_chars": 1200},
        "summarize": True,
    }
    context.update(over)

    return context


@pytest.fixture(autouse=True)
def _clean_env(monkeypatch):
    for name in ("NJ662_SEARCH", "NJ662_SEARCH_TIMEOUT", "NJ662_MODEL", "NJ662_CONTEXT"):
        monkeypatch.delenv(name, raising=False)


# --------------------------------------------------------------------------
# Чистые функции: тексты в подсказке
# --------------------------------------------------------------------------

def test_clean_trims_and_cuts():
    got = mod._clean("  Первая\r\n\r\n\r\n\r\nВторая   ", 20)

    assert got == "Первая\n\nВторая"
    assert mod._clean("слово " * 20, 20).endswith("…")


def test_query_from_takes_long_words():
    """Запасной запрос набирается из слов темы, а не из пустоты."""
    got = mod._query_from("Как ускорить выдачу заказов", "ошибки комплектации")

    assert got.split()[0] == "ускорить"
    assert "как" not in got.split(), "предлог короче четырёх букв в запрос не идёт"
    assert len(got.split()) <= 8


def test_search_query_falls_back_on_noise():
    """Модель ответила точкой или простыней — поиск не теряется."""
    assert mod._search_query(".", "Тема про склады", "") == "тема склады"
    assert mod._search_query("", "Тема про склады", "") == "тема склады"
    assert mod._search_query("Запрос:  ускорение   склада ", "т", "") == "ускорение склада"
    assert len(mod._search_query("я" * 400, "Склад", "")) < 200


def test_materials_block_names_source():
    got = mod._materials_block([
        {"title": "Регламент 2026", "text": "Текст регламента.", "how": "подбор по смыслу"},
        {"title": "", "text": "Без названия.", "how": "совпадение слов"},
    ])

    assert "«Регламент 2026»" in got and "подбор по смыслу" in got
    assert "материал 2" in got, "безымянный материал получает номер"
    assert got.startswith("Материалы диалога")
    assert mod._materials_block([]) == "", "без материалов блока в подсказке нет"


def test_materials_block_cuts_long_text():
    got = mod._materials_block([{"title": "Длинный", "text": "я" * 5000, "how": ""}],
                               feed_left=100)

    assert "…" in got and len(got) < 600


def test_web_block_lists_findings_and_pages():
    got = mod._web_block({
        "query": "складские ошибки комплектации",
        "results": [{"title": "Разбор ошибок", "url": "https://example.org/a", "body": "кратко"}],
        "pages": [{"title": "Разбор ошибок", "url": "https://example.org/a", "text": "подробно"}],
    })

    assert "складские ошибки комплектации" in got
    assert "https://example.org/a" in got
    assert "Прочитанные страницы" in got and "подробно" in got
    assert mod._web_block(None) == ""
    assert mod._web_block({"results": [], "pages": []}) == ""


def test_agent_messages_maps_history_to_roles():
    """Реплики — история чата: своя речь бывает только в роли assistant."""
    messages = mod._agent_messages("a", _context(), "Ответь собеседнику.")

    roles = [m["role"] for m in messages]
    assert roles[0] == "system" and messages[0]["content"] == "Ты раскладываешь на шаги."
    assert messages[1]["role"] == "system" and "Договорились взять замеры." in messages[1]["content"]
    assert roles[2] == "assistant", "своя реплика — assistant"
    assert roles[3] == "user" and messages[3]["content"].startswith("Импульс: ")
    assert messages[-1]["role"] == "user" and messages[-1]["content"] == "Ответь собеседнику."


def test_agent_messages_marks_owner_reply_as_instruction():
    """Своя реплика владельца — указание, а не реплика собеседника (09.10.2026).

    Без пояснения модель принимает её за мнение второго агента и спорит с ней,
    хотя владелец репликой правит НАПРАВЛЕНИЕ разговора.
    """
    context = _context()
    context["history"] = list(context["history"]) + [
        {"who": "user", "author": "Владелец", "content": "Посчитайте деньги."},
    ]

    messages = mod._agent_messages("a", context, "Ответь собеседнику.")

    assert any(
        m["role"] == "system" and "владельца разговора" in m["content"]
        for m in messages
    ), "модель обязана знать, что реплика владельца — указание"

    assert any(
        m["role"] == "user" and m["content"].startswith("Владелец: ")
        for m in messages
    ), "реплика владельца доезжает до модели с именем"

    # Без реплики владельца пояснения нет: лишний системный текст не нужен.
    plain = mod._agent_messages("a", _context(), "Ответь собеседнику.")

    assert not any("владельца разговора" in m["content"] for m in plain)


def test_agent_messages_carries_extra_and_fresh():
    """Материалы едут перед заданием, а свежая реплика — в конец истории."""
    messages = mod._agent_messages(
        "b", _context(), "Твой ход.",
        extra="Найдено в интернете: ...",
        fresh=[{"who": "a", "author": "Структура", "content": "Свежая мысль."}])

    assert "Найдено в интернете" in messages[-1]["content"]
    assert messages[-2]["role"] == "user"
    assert messages[-2]["content"] == "Структура: Свежая мысль."
    assert messages[-1]["content"].endswith("Твой ход.")


def test_tokens_reads_total_then_parts():
    assert mod._tokens({"total_tokens": "42"}) == 42
    assert mod._tokens({"prompt_tokens": 10, "completion_tokens": 5}) == 15
    assert mod._tokens(None) == 0
    assert mod._tokens({"total_tokens": "мусор"}) == 0


# --------------------------------------------------------------------------
# Настройки канала
# --------------------------------------------------------------------------

def test_service_model_prefers_env(monkeypatch):
    assert mod._service_model("openrouter::дешёвая") == "openrouter::дешёвая"
    assert mod._service_model("  ") is None, "пустая модель участника — значит модель воркера"

    monkeypatch.setenv("NJ662_MODEL", "local::служебная")

    assert mod._service_model("openrouter::дешёвая") == "local::служебная"
    assert mod._service_model(None) == "local::служебная"


def test_search_switch_and_timeout(monkeypatch):
    assert mod._search_enabled() is True, "по умолчанию канал искать умеет; бюджет задаёт диалог"

    monkeypatch.setenv("NJ662_SEARCH", "0")
    assert mod._search_enabled() is False

    monkeypatch.setenv("NJ662_SEARCH_TIMEOUT", "3")
    assert mod._search_timeout() == 5.0, "минимальный таймаут поиска — пять секунд"
    monkeypatch.setenv("NJ662_SEARCH_TIMEOUT", "мусор")
    assert mod._search_timeout() == 45.0


def test_tokens_of_context():
    assert mod._tokens_of_context(None) == 8000
    assert mod._tokens_of_context(None, 4000) == 4000
    assert mod._tokens_of_context(None) == 8000, "нижняя граница не поднимает дефолт"


# --------------------------------------------------------------------------
# Быстрый старт: разбор ответа модели
# --------------------------------------------------------------------------

def test_parse_quick_flat_json():
    got = mod._parse_quick(json.dumps({
        "agent_1_name": "Структура",
        "agent_2_name": "Импульс",
        "agent_1_system_text": "Ты раскладываешь на шаги.",
        "agent_2_system_text": "Ты предлагаешь ходы.",
        "note": "вместе ищем реализуемое",
    }, ensure_ascii=False))

    assert got["a_name"] == "Структура" and got["b_system"] == "Ты предлагаешь ходы."
    assert got["note"] == "вместе ищем реализуемое"


def test_parse_quick_agents_array_and_fence():
    got = mod._parse_quick("```json\n" + json.dumps({
        "agents": [
            {"name": "Структура", "system_text": "Шаги и критерии."},
            {"name": "Импульс", "system_text": "Неожиданные ходы."},
        ],
    }, ensure_ascii=False) + "\n```")

    assert got["a_name"] == "Структура" and got["b_system"] == "Неожиданные ходы."


def test_parse_quick_raises_without_agents():
    """Молча пустых участников не берём: иначе диалог начнётся ни с кем."""
    with pytest.raises(RuntimeError, match="участник"):
        mod._parse_quick("Не JSON вовсе.")

    with pytest.raises(RuntimeError):
        mod._parse_quick('{"note": "только заметка"}')


# --------------------------------------------------------------------------
# Канал обмена: handle
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_handle_without_budget_does_not_search(monkeypatch):
    """Бюджет поиска исчерпан — «утка» не дёргается ни разу."""
    async def no_search(*a, **kw):
        raise AssertionError("поиск не должен вызываться: бюджет диалога равен нулю")

    monkeypatch.setattr(mod.webread, "ddg_text", no_search)

    ctx = FakeCtx(FakeLlm(["Реплика первого.", "Реплика второго.", "Сводка обмена."]))
    site = FakeSite(_context())
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    res = await mod.handle(ctx, {"dialog_id": 7, "turn": 1})

    assert res["done"] is True and res["search_used"] == 0
    assert site.path("/turn")["turn"] == 1
    data = json.loads(site.path("/turn")["data"])
    assert [m["who"] for m in data["messages"]] == ["a", "b"]
    assert data["summary"] == "Сводка обмена."
    assert data["messages"][0]["model"] == "openrouter::google/gemini-2.5-flash-lite"
    assert data["messages"][1]["model"] == "TOOLFORGE::unsloth/qwen3.5-9b"
    assert data["search"] is None
    assert any("Структура" in stage for stage, _ in ctx.steps), "этапы видны на странице"


@pytest.mark.asyncio
async def test_handle_searches_before_replies(monkeypatch):
    """Есть бюджет — поиск идёт до реплик, находки едут агентам."""
    asked: list[str] = []

    async def fake_search(query, max_results=5, timeout_sec=60.0):
        asked.append(query)
        return [{"title": "Обзор складов", "url": "https://example.org/w",
                 "body": "снижение ошибок на треть"}]

    async def fake_fetch(url):
        return "<html>текст</html>", ""

    async def fake_md(html, cap=None):
        return "Подробный разбор: ошибки комплектации падают после сканера. " * 5, "Обзор складов"

    monkeypatch.setattr(mod.webread, "ddg_text", fake_search)
    monkeypatch.setattr(mod.webread, "fetch", fake_fetch)
    monkeypatch.setattr(mod.webread, "to_markdown", fake_md)

    llm = FakeLlm([
        "ошибки комплектации склада",       # запрос поиска
        "Реплика первого.",                 # реплика a
        "Реплика второго.",                 # реплика b
        "Сводка.",                          # сводка
    ])
    ctx = FakeCtx(llm)
    site = FakeSite(_context(search={"left": 1, "used": 0}))
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    res = await mod.handle(ctx, {"dialog_id": 7, "turn": 1})

    assert asked == ["ошибки комплектации склада"]
    assert res["search_used"] == 1

    search = json.loads(site.path("/turn")["data"])["search"]
    assert search["results"][0]["url"] == "https://example.org/w"
    assert search["pages"], "страницы по ссылкам читаются в том же поиске"
    assert search["agent"] == "a"

    reply_a = llm.calls[1]["messages"][-1]["content"]
    assert "Найдено в интернете" in reply_a and "Обзор складов" in reply_a
    assert "не выдумывай" in reply_a, "без правила агент сочинит цифры"


@pytest.mark.asyncio
async def test_handle_summary_only_when_enabled(monkeypatch):
    ctx = FakeCtx(FakeLlm(["Реплика первого.", "Реплика второго."]))
    site = FakeSite(_context(summarize=False))
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    await mod.handle(ctx, {"dialog_id": 7, "turn": 1})

    data = json.loads(site.path("/turn")["data"])
    assert data["summary"] == ""
    assert len(ctx.llm.calls) == 2, "суммаризация выключена — третьего вызова нет"


@pytest.mark.asyncio
async def test_handle_stops_peacefully(monkeypatch):
    """Пауза или отмена — не падение задачи: очередь не должна краснеть."""
    ctx = FakeCtx()
    site = FakeSite({"ok": False, "error": "диалог не идёт: пауза"})
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    res = await mod.handle(ctx, {"dialog_id": 7, "turn": 3})

    assert res["stopped"] is True
    assert "пауза" in res["reason"]
    assert site.path("/turn") is None


@pytest.mark.asyncio
async def test_handle_reports_model_failure(monkeypatch):
    """Отказ модели обязан закрыть диалог причиной, а не оставить «идёт»."""
    class Broken(FakeLlm):
        async def chat_with_usage(self, messages, model=None, **options):
            raise RuntimeError("провайдер недоступен")

    ctx = FakeCtx(Broken())
    site = FakeSite(_context())
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    with pytest.raises(RuntimeError):
        await mod.handle(ctx, {"dialog_id": 7, "turn": 1})

    reason = site.path("/fail")["error"]
    assert "Реплика" in reason
    assert "openrouter::google/gemini-2.5-flash-lite" in reason, \
        "в причине названа модель участника: без неё владелец не поймёт, кто не ответил"
    assert site.path("/turn") is None


@pytest.mark.asyncio
async def test_handle_rejects_empty_task():
    with pytest.raises(RuntimeError, match="dialog_id"):
        await mod.handle(FakeCtx(), {"turn": 1})


@pytest.mark.asyncio
async def test_handle_survives_dead_search_engine(monkeypatch):
    """Поисковик молчит — диалог всё равно идёт на материалах."""
    async def dead(query, max_results=5, timeout_sec=60.0):
        raise RuntimeError("поисковик недоступен")

    monkeypatch.setattr(mod.webread, "ddg_text", dead)

    ctx = FakeCtx(FakeLlm(["запрос", "Реплика первого.", "Реплика второго.", "Сводка."]))
    site = FakeSite(_context(search={"left": 2, "used": 0}))
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    res = await mod.handle(ctx, {"dialog_id": 7, "turn": 1})

    assert res["search_used"] == 0 and res["done"] is True
    assert json.loads(site.path("/turn")["data"])["search"] is None,\
        "пустая выдача не тратит бюджет: в ленте нет блока поиска"


# --------------------------------------------------------------------------
# Быстрый старт: handle_quick
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_handle_quick_hands_agents_to_site(monkeypatch):
    payload_json = json.dumps({
        "agent_1_name": "Структура",
        "agent_2_name": "Импульс",
        "agent_1_system_text": "Шаги.",
        "agent_2_system_text": "Ходы.",
    }, ensure_ascii=False)

    llm = FakeLlm([payload_json])
    ctx = FakeCtx(llm)
    site = FakeSite({}, quick={"ok": True, "a_name": "Структура", "b_name": "Импульс",
                               "started": True})
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    res = await mod.handle_quick(ctx, {
        "dialog_id": 7, "topic": "Учёт заявок", "brief": "Отдел из трёх человек",
        "autostart": True,
    })

    agents = json.loads(site.path("/quick")["agents"])
    assert agents["a_name"] == "Структура" and agents["b_system"] == "Ходы."
    assert agents["autostart"] is True
    assert res["started"] is True and res["a_name"] == "Структура"
    assert "Учёт заявок" in llm.calls[0]["messages"][-1]["content"]
    assert any("Подбираю агентов" == stage for stage, _ in ctx.steps)


@pytest.mark.asyncio
async def test_handle_quick_stops_when_dialog_no_longer_waits(monkeypatch):
    """Диалог удалён или уже не ждёт подбора — не красная задача, а мирный стоп."""
    ctx = FakeCtx(FakeLlm(['{"agent_1_name": "Структура", "agent_2_name": "Импульс",'
                           ' "agent_1_system_text": "Шаги.", "agent_2_system_text": "Ходы."}']))
    site = FakeSite({}, quick={"ok": False, "error": "Этот диалог уже не ждёт подбора участников"})
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    res = await mod.handle_quick(ctx, {"dialog_id": 7, "topic": "Учёт заявок"})

    assert res["stopped"] is True and "не ждёт" in res["reason"]


def test_model_note_names_the_model():
    """Подпись модели в причине отказа: «модель … не ответила»."""
    assert mod._model_note("openrouter::google/gemini-3.1-flash-lite") \
        == "модель openrouter::google/gemini-3.1-flash-lite не ответила: "
    assert mod._model_note("  ") == "модель воркера не ответила: "


@pytest.mark.asyncio
async def test_handle_quick_reports_model_failure(monkeypatch):
    """Отказ модели при подборе — причина на страницу, а не вечное «подбираю»."""
    class Broken(FakeLlm):
        async def chat_with_usage(self, messages, model=None, **options):
            raise RuntimeError("провайдер недоступен")

    ctx = FakeCtx(Broken())
    site = FakeSite({})
    monkeypatch.setattr(mod, "_api", site)
    monkeypatch.setattr(mod, "_api_soft", site)

    with pytest.raises(RuntimeError):
        await mod.handle_quick(ctx, {"dialog_id": 7, "topic": "Учёт заявок",
                                     "model": "openrouter::google/gemini-3.1-flash-lite"})

    reason = site.path("/fail")["error"]
    assert "Подбор участников не удался" in reason
    assert "gemini-3.1-flash-lite" in reason
    assert site.path("/quick") is None


@pytest.mark.asyncio
async def test_handle_quick_requires_dialog():
    with pytest.raises(RuntimeError, match="dialog_id"):
        await mod.handle_quick(FakeCtx(), {})
