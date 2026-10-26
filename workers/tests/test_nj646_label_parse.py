"""nj646.label: ответ модели обязан разбираться, даже если он грязный.

Владелец (03.10.2026) на живой этикетке получил

    не удалось разобрать ответ модели: {"composition": "Вода, Экстракт
    масличного жожоба, Экстракт масла семян винограда, … Экстракт масл

— состав из десятка экстрактов, модель оборвалась на нём по max_tokens, а
свой наивный json.loads этого не понял. Здесь закрепляем три вещи:

* разбор через общий webread.json_from_text (обрыв по токенам, перенос строки
  внутри строки JSON, markdown-обёртка, хвостовая проза) — примеры ровно те,
  что ломали живые ответы;
* страховка: из неразборного ответа состав всё равно вынимается, пометка об
  обрыве едет в result.note, и прогон доходит до сайта со статусом done;
* если состава в ответе нет вовсе — честная ошибка, но с указанием, что
  поднимать (NJ646_MAX_TOKENS), а не глухое «не удалось разобрать».
"""

from __future__ import annotations

import asyncio
import json

import pytest

import n2j_workers.handlers.nj646_label as mod


# Обрыв ровно как у владельца: строка состава не закрылась (лимит токенов).
TRUNCATED = ('{"composition": "Вода, Экстракт масличного жожоба, Экстракт масла '
             'семян винограда, Экстракт масла грецкого ореха, Экстракт масла '
             'кокоса, Экстракт масла авокадо, Экстракт масла шиповника, '
             'Экстракт масл')

GOOD = ('{"composition": "Вода, Глицерин, Экстракт жожоба", "kind": "cosmetic", '
        '"confidence": 88, "note": "часть списка ушла под блик"}')


class _Ctx:
    """Минимальный HandlerContext: то, что реально зовёт обработчик.

    replies — очередь ответов модели: первый вызов (чтение этикетки), второй и
    дальше (дописывание оборванного списка). Последний ответ повторяется, если
    вызовов больше, чем заготовок.
    """

    def __init__(self, reply: str, finish: str = "stop", replies: list | None = None):
        self.steps: list[tuple] = []
        self.reply = reply
        self.finish = finish
        self.replies = list(replies or [])
        self.calls: list[list] = []
        self.llm = self

    async def progress(self, stage=None, note=None):
        self.steps.append((stage, note))

    async def heartbeat(self):
        return True

    async def chat_with_usage(self, messages, **kw):
        self.calls.append(messages)

        # call 1 — чтение этикетки (self.reply), дальше — заготовки допроса
        if len(self.calls) == 1 or not self.replies:
            text = self.reply
        else:
            text = self.replies.pop(0)

        if isinstance(text, Exception):
            raise text

        return text, {"prompt_tokens": 900, "completion_tokens": 1600,
                      "_llm_finish_reason": self.finish}


def _run(monkeypatch, reply: str, finish: str = "stop",
         replies: list | None = None, transcribe: bool = False) -> tuple[dict, list, _Ctx]:
    """Прогнать handle() без сети: _post подменяем, ответ задаём.

    transcribe=False выключает двухпроходный режим (NJ646_TRANSCRIBE=0): тогда
    первый и единственный ответ модели — это сразу состав. Двухпроходные тесты
    включают его и передают в replies ответ на сборку состава из строк.
    """
    posted: list[dict] = []

    async def fake_post(_ctx, payload):
        posted.append(dict(payload))
        return (True, "")

    monkeypatch.setattr(mod, "_post", fake_post)
    monkeypatch.setenv("NJ646_TRANSCRIBE", "1" if transcribe else "0")

    ctx = _Ctx(reply, finish, replies)
    payload = {"run_id": 42, "image_b64": "AAAA", "media_type": "image/jpeg"}
    result = asyncio.run(mod.handle(ctx, payload))

    return result, posted, ctx


# ---------------------------------------------------------------- разбор JSON

def test_truncated_answer_is_salvaged_not_lost(monkeypatch):
    result, posted, _ctx = _run(monkeypatch, TRUNCATED, finish="length")

    assert result["status"] == "done"
    assert result["text"].startswith("Вода, Экстракт масличного жожоба")
    assert "Экстракт масла шиповника" in result["text"]
    assert posted and posted[0]["status"] == "done"
    assert posted[0]["text"] == result["text"]


def test_truncated_answer_warns_owner_to_check_the_tail(monkeypatch):
    result, _posted, _ctx = _run(monkeypatch, TRUNCATED, finish="length")

    assert "оборвал" in result["note"]
    assert "конец списка" in result["note"]


def test_owner_note_from_model_is_kept(monkeypatch):
    result, _posted, _ctx = _run(monkeypatch, GOOD)

    assert result["text"] == "Вода, Глицерин, Экстракт жожоба"
    assert result["kind"] == "cosmetic"
    assert result["confidence"] == 88
    assert result["note"] == "часть списка ушла под блик"


def test_raw_newline_inside_json_string_is_repaired(monkeypatch):
    reply = ('{"composition": "Вода,\nЭкстракт жожоба,\nГлицерин", '
             '"kind": "cosmetic", "confidence": 80, "note": ""}')

    result, _posted, _ctx = _run(monkeypatch, reply)

    assert result["status"] == "done"
    assert result["text"] == "Вода, Экстракт жожоба, Глицерин"


def test_markdown_fence_and_trailing_prose_are_tolerated(monkeypatch):
    reply = ("```json\n" + GOOD + "\n```\nНадеюсь, помог! Если шрифт мелкий — "
             "сфотографируйте ближе.")

    result, _posted, _ctx = _run(monkeypatch, reply)

    assert result["text"] == "Вода, Глицерин, Экстракт жожоба"


def test_missing_composition_is_honest_error_with_hint(monkeypatch):
    posted: list[dict] = []

    async def fake_post(_ctx, payload):
        posted.append(dict(payload))
        return (True, "")

    monkeypatch.setattr(mod, "_post", fake_post)

    ctx = _Ctx("На фото вижу только блик, состав не читается.", finish="length")

    with pytest.raises(RuntimeError):
        asyncio.run(mod.handle(ctx, {"run_id": 7, "image_b64": "AAAA"}))

    assert posted[0]["status"] == "error"
    assert "NJ646_MAX_TOKENS" in posted[0]["error"]
    # сырой ответ остаётся в ошибке: без него дежурный не поймёт, что случилось
    assert "блик" in posted[0]["error"]


def test_plain_prose_without_key_is_not_taken_as_composition(monkeypatch):
    """Рассуждение модели — не состав: выдумывать ингредиенты нельзя."""
    assert mod._extract_json("Состав: вода, сахар") is None
    assert mod._salvage_composition("Состав: вода, сахар") == ("", False)


# ------------------------------------------------------------- детали страховки

def test_partial_tail_is_shown_not_silently_cut():
    """Огрызок «Экстракт масл» оставляем: отрезать его — потерять ингредиент."""
    text, cut = mod._salvage_composition(TRUNCATED)

    assert cut is True
    assert text.endswith("Экстракт масл")


def test_closed_string_is_not_marked_as_cut():
    assert mod._salvage_composition(GOOD) == ("Вода, Глицерин, Экстракт жожоба", False)


def test_escaped_newlines_become_spaces():
    text, _cut = mod._salvage_composition('{"composition": "Вода,\\nСахар,\\nСоль"')

    assert text == "Вода, Сахар, Соль"


def test_join_note_deduplicates_and_truncates():
    assert mod._join_note("", "  блик ", "блик", "проверьте") == "блик. проверьте"
    assert len(mod._join_note("х" * 400)) == 300


# ------------------------------------------------- вырождение модели: циклы

# Реальный ответ на фото «Пампас» (03.10.2026): прочитав первые строки, модель
# ушла в цикл и гнала «Экстракт корня …» до потолка токенов — список из
# десятка позиций выглядел как список из трёхсот, и он же обрывался.
LOOP_BLOCK = [
    "Вода", "Экстракт эхинацеи", "Экстракт мёда", "Экстракт корня женьшеня",
    "Экстракт листьев гикго билоба", "Экстракт коры женьшеня",
    "Экстракт листвы сосны густоцветной", "Экстракт прополиса",
    "Масло лаванды", "Пантенол", "Лаурет сульфат натрия",
    "Кокамидопропил бетаин", "Поликватерниум-7", "Лимонен",
]


def test_repeated_block_is_cut_to_one_copy():
    composition = ", ".join(LOOP_BLOCK * 8)

    cleaned, looped, dups = mod._clean_composition(composition)

    assert cleaned == ", ".join(LOOP_BLOCK)
    assert looped > 0
    assert dups == 0


def test_list_repeated_twice_is_deduplicated():
    """Две копии подряд _loop_cut не режет (нужно три) — их ловит дедупликация."""
    composition = ", ".join(LOOP_BLOCK * 2)

    cleaned, looped, dups = mod._clean_composition(composition)

    assert cleaned == ", ".join(LOOP_BLOCK)
    assert dups == len(LOOP_BLOCK)


def test_single_positions_repeated_are_cut():
    cleaned, looped, _dups = mod._clean_composition("Вода, Вода, Вода")

    assert cleaned == "Вода"
    assert looped == 2


def test_honest_list_is_untouched():
    composition = ", ".join(LOOP_BLOCK)

    cleaned, looped, dups = mod._clean_composition(composition)

    assert cleaned == composition
    assert (looped, dups) == (0, 0)


def test_short_and_single_item_compositions_untouched():
    assert mod._clean_composition("Вода, Сахар") == ("Вода, Сахар", 0, 0)
    assert mod._clean_composition("Вода") == ("Вода", 0, 0)


def test_loop_is_cut_and_reported_on_the_page(monkeypatch):
    """Цикл модели: на страницу едет один блок ингредиентов + честная пометка."""
    reply = ('{"composition": "' + ", ".join(LOOP_BLOCK * 8) + '", '
             '"kind": "cosmetic", "confidence": 70, "note": ""}')

    result, posted, _ctx = _run(monkeypatch, reply, finish="length")

    assert result["status"] == "done"
    assert result["text"] == ", ".join(LOOP_BLOCK)
    assert "по кругу" in result["note"]
    assert "проверьте список по этикетке" in result["note"]
    assert "оборвал" in result["note"]  # обрыв ответа тоже виден владельцу
    assert posted[0]["text"] == result["text"]


def test_many_same_head_items_raise_suspicion_note():
    """«Экстракт корня …» двадцать раз — это не этикетка, а фантазия модели."""
    composition = ", ".join(f"Экстракт корня {name}" for name in
                            ["туи", "хмеля", "чеснока", "нима", "лотоса", "мака",
                             "мяты", "пижмы", "полыни", "ревеня"] * 2)

    note = mod._suspicion_note(composition)

    assert "выдумк" in note
    assert "Экстракт корня" in note


def test_suspicion_not_raised_for_normal_label():
    # цикл (повторы одного и того же) — не «выдумка»: о нём отдельная пометка
    assert mod._suspicion_note(", ".join(LOOP_BLOCK * 8)) == ""
    assert mod._suspicion_note(", ".join(LOOP_BLOCK)) == ""
    assert mod._suspicion_note("Вода, Сахар, Соль") == ""


def test_hallucination_warning_reaches_the_page(monkeypatch):
    names = ["туи", "хмеля", "чеснока", "нима", "лотоса", "мака", "мяты",
             "пижмы", "полыни", "ревеня"]
    reply = ('{"composition": "' + ", ".join(f"Экстракт корня {n}" for n in names) + '", '
             '"kind": "cosmetic", "confidence": 40, "note": ""}')

    result, _posted, _ctx = _run(monkeypatch, reply)

    assert "выдумк" in result["note"]
    # состав при этом не переписываем и не выкидываем — только предупреждаем
    assert result["text"].startswith("Экстракт корня туи")
    assert result["text"].count("Экстракт корня") == len(names)


# Ответ владельца на фото «Пампас»: цикл оборвался прямо внутри очередного
# круга («…Экстракт корня»), поэтому период в хвосте неполный.
LOOPED_RAW = ('{"composition": "' + ", ".join(
    ["Вода", "Экстракт маслины оливковой", "Экстракт лимона", "Экстракт алоэ вера",
     "Экстракт листьев фикуса", "Экстракт корня лотоса", "Экстракт корня женьшеня",
     "Экстракт корня имбиря", "Экстракт корня солодки"] * 8)
    + ", Экстракт корня туи, Экстракт корня хмеля, Экстракт корня чеснока, Экстракт корня")


def test_cycle_cut_off_mid_period_is_still_detected():
    """Оборванный последний круг не должен прятать цикл от детектора."""
    composition, cut = mod._salvage_composition(LOOPED_RAW)

    assert cut is True  # ответ оборвался по токенам

    cleaned, looped, dups = mod._clean_composition(composition)

    assert cleaned == ("Вода, Экстракт маслины оливковой, Экстракт лимона, "
                       "Экстракт алоэ вера, Экстракт листьев фикуса, "
                       "Экстракт корня лотоса, Экстракт корня женьшеня, "
                       "Экстракт корня имбиря, Экстракт корня солодки")
    assert looped > 0
    assert dups == 0


def test_owner_answer_reports_loop_and_doubt_on_page(monkeypatch):
    """На странице — один блок состава, пометка о цикле и о возможной выдумке."""
    result, posted, ctx = _run(monkeypatch, LOOPED_RAW, finish="length")

    assert result["status"] == "done"
    assert result["text"].count("Экстракт корня лотоса") == 1
    assert "по кругу" in result["note"]
    assert "выдумк" in result["note"]
    assert any(stage == "Модель зациклилась" for stage, _note in ctx.steps)
    assert posted[0]["text"] == result["text"]


def test_warnings_come_before_the_model_note(monkeypatch):
    """Предупреждения важнее слов модели: у note потолок 300 знаков."""
    long_note = "мелкий шрифт и блик, часть строк под углом, " * 5
    reply = ('{"composition": "' + ", ".join(LOOP_BLOCK * 8) + '", '
             '"kind": "cosmetic", "confidence": 30, "note": "' + long_note + '"}')

    result, _posted, _ctx = _run(monkeypatch, reply)

    assert result["note"].startswith("модель пошла по кругу")
    assert len(result["note"]) <= 300


# ---------------------------------------------------- канал изучения (study)

def test_study_json_with_markdown_fence_is_parsed(monkeypatch):
    """Тот же разбор кормит канал nj646.study: обёртка ```json не должна ломать."""
    reply = ("```json\n"
             '{"name_ru": "Экстракт корня женьшеня", "name_int": "Panax ginseng root extract", '
             '"kind": "cosmetic", "risk": 1, "verdict": "neutral", "category": "экстракт", '
             '"e_code": "", "summary": "Успокаивающий экстракт.", '
             '"details": "Используется в косметике как успокаивающий компонент.", '
             '"synonyms": ["женьшень"], "avoid": ["allergy"], "sources": ["CIR"], '
             '"confidence": 72}\n'
             "```")

    posted: list[dict] = []

    async def fake_post(_ctx, payload):
        posted.append(dict(payload))
        return (True, "")

    monkeypatch.setattr(mod, "_post", fake_post)

    ctx = _Ctx(reply)
    result = asyncio.run(mod.handle_study(
        ctx, {"run_id": 5, "name": "Экстракт корня женьшеня", "kind": "cosmetic", "unknown_id": 9}))

    assert result["status"] == "done"
    assert result["ingredient"]["name_ru"] == "Экстракт корня женьшеня"
    assert result["ingredient"]["risk"] == 1
    assert result["ingredient"]["avoid"] == ["allergy"]
    assert posted[0]["ingredient"]["name_int"] == "Panax ginseng root extract"


# ------------------------------------------- дописывание оборванного ответа

# Длинная этикетка (INCI): реальный список не влезает в 1600 токенов ответа,
# JSON обрывается на середине. Владелец: «ответ большой обрезается».
LONG_LABEL = ("Вода, Экстракт эхинацеи, Экстракт мёда, Экстракт корня женьшеня, "
              "Экстракт листьев гикго билоба, Экстракт коры женьшеня, "
              "Экстракт листвы сосны густоцветной, Экстракт прополиса, "
              "Масло лаванды, Пантенол, Лаурет сульфат натрия, "
              "Аммоний лаурил сульфат, Кокамидопропил бетаин, Пропилен гликоль")
TRUNCATED_LONG = ('{"composition": "' + LONG_LABEL[:120])
TAIL = ("Кокамид МЕА, ПЭГ-12 Диметикон, Хлорид натрия, Гуар гидроксипропилтримоний "
        "хлорид, Нитрат магния, Метилизотиазолинон, Диоксид титана")


def test_truncated_answer_is_completed_by_a_second_request(monkeypatch):
    result, posted, ctx = _run(monkeypatch, TRUNCATED_LONG, finish="length",
                               replies=[TAIL])

    assert result["status"] == "done"
    assert "Диоксид титана" in result["text"]      # хвост дописан
    assert result["text"].startswith("Вода, Экстракт эхинацеи")
    assert "дописан вторым запросом" in result["note"]
    assert len(ctx.calls) == 2                     # ровно один допрос
    assert posted[0]["text"] == result["text"]


def test_continuation_does_not_duplicate_positions(monkeypatch):
    """Модель в продолжении повторяет уже названное — дублей быть не должно."""
    reply = '{"composition": "' + LONG_LABEL[:80]
    result, _posted, _ctx = _run(monkeypatch, reply, finish="length",
                                 replies=["Экстракт мёда, Масло лаванды, " + TAIL])

    assert result["text"].count("Экстракт мёда") == 1
    assert "Масло лаванды" in result["text"]
    assert "Диоксид титана" in result["text"]


def test_failed_continuation_keeps_partial_composition(monkeypatch):
    """Продолжение не удалось — состав остаётся, но с честной пометкой об обрыве."""
    result, posted, ctx = _run(monkeypatch, TRUNCATED_LONG, finish="length",
                               replies=[TimeoutError("модель молчит")])

    assert result["status"] == "done"
    assert "оборвал" in result["note"]
    assert "дописан" not in result["note"]
    assert posted[0]["status"] == "done"
    assert len(ctx.calls) == 2


def test_continuation_is_not_asked_when_there_is_no_truncation(monkeypatch):
    result, _posted, ctx = _run(monkeypatch, GOOD)

    assert len(ctx.calls) == 1
    assert "оборвал" not in result["note"]


def test_continuation_is_not_asked_for_a_looped_list(monkeypatch):
    """Продолжать выдумку нельзя: при цикле/шаблоне второй запрос не уходит."""
    reply = '{"composition": "' + ", ".join(LOOP_BLOCK * 8)

    result, _posted, ctx = _run(monkeypatch, reply, finish="length",
                                replies=["Экстракт корня туи"])

    assert len(ctx.calls) == 1
    assert "по кругу" in result["note"]


def test_continuation_items_are_parsed_from_any_shape():
    assert mod._continuation_items("Масло лаванды, Пантенол") == ["Масло лаванды", "Пантенол"]
    assert mod._continuation_items('{"composition": "Пантенол, Лимонен", "kind": "cosmetic"}') == \
        ["Пантенол", "Лимонен"]
    assert mod._continuation_items('"Пантенол"') == ["Пантенол"]
    assert mod._continuation_items("") == []
    assert mod._continuation_items("Состав закончился.") == []


def test_merge_items_skips_junk_and_limits_size():
    merged, added = mod._merge_items(["Вода"], ["вода", "  ", "Соль", "х" * 200])

    assert merged == ["Вода", "Соль"]
    assert added == 1


# ------------------------------- двухпроходный режим: строки → состав

# Реальные строки с фото «Пампас» (то, что модель в состоянии переписать):
TRANSCRIPT_LINES = [
    "НАТУРАЛЬНЫЙ ШАМПУНЬ ПАМПАС (PAMPAS Natural Scalp Shampoo)",
    "Способ применения: нанесите небольшое количество шампуня",
    "Состав: Вода, Экстракт эхинацеи, Экстракт мёда, Экстракт корня женьшеня,",
    "Экстракт листьев гикго билоба, Экстракт коры женьшеня, Экстракт прополиса,",
    "Масло лаванды, Пантенол, Лаурет сульфат натрия, Аммоний лаурил сульфат,",
    "Кокамидопропил бетаин, Пропилен гликоль, Поликватерниум-7, Лимонен",
    "Объём: 170мл",
    "Срок годности: 3 года с даты изготовления",
    "Производитель: CT Cosmetics Co., Ltd",
]
TRANSCRIPT_JSON = ('{"lines": ' + json.dumps(TRANSCRIPT_LINES, ensure_ascii=False)
                   + ', "kind": "cosmetic", "note": "часть строк под бликом"}')


def test_transcribed_lines_are_turned_into_composition(monkeypatch):
    """Проход 1 — строки, проход 2 — состав из них (никакого угадывания по фото)."""
    compose = ('{"composition": "Вода, Экстракт эхинацеи, Экстракт мёда, Экстракт корня '
               'женьшеня, Экстракт листьев гикго билоба, Экстракт коры женьшеня, '
               'Экстракт прополиса, Масло лаванды, Пантенол, Лаурет сульфат натрия, '
               'Аммоний лаурил сульфат, Кокамидопропил бетаин, Пропилен гликоль, '
               'Поликватерниум-7, Лимонен", "kind": "cosmetic", "confidence": 82, "note": ""}')

    result, posted, ctx = _run(monkeypatch, TRANSCRIPT_JSON, replies=[compose], transcribe=True)

    assert result["status"] == "done"
    assert result["text"].startswith("Вода, Экстракт эхинацеи")
    assert "Лимонен" in result["text"]
    assert "Объём" not in result["text"]            # служебные строки не уехали в состав
    assert "распознанных строк" in result["note"]
    assert result["transcript"].count("Объём") == 1  # переписанный текст сохранён
    assert posted[0]["transcript"] == result["transcript"]
    assert len(ctx.calls) == 2


def test_composition_is_assembled_locally_when_model_fails(monkeypatch):
    """Второй проход не удался — блок «Состав:» берём из строк без модели."""
    result, _posted, ctx = _run(monkeypatch, TRANSCRIPT_JSON,
                                replies=[RuntimeError("модель недоступна")], transcribe=True)

    assert result["status"] == "done"
    assert result["text"].startswith("Вода, Экстракт эхинацеи")
    assert "Лимонен" in result["text"]
    assert "без модели" in result["note"]
    assert len(ctx.calls) == 2


def test_empty_compose_reply_falls_back_to_local_extraction(monkeypatch):
    compose = '{"composition": "", "kind": "cosmetic", "confidence": 40, "note": "не нашёл блок"}'

    result, _posted, _ctx = _run(monkeypatch, TRANSCRIPT_JSON, replies=[compose], transcribe=True)

    assert result["status"] == "done"
    assert "Экстракт мёда" in result["text"]
    assert "без модели" in result["note"]


def test_transcription_failure_falls_back_to_single_pass(monkeypatch):
    """OCR-проход не удался — читаем этикетку одним запросом, как раньше."""
    result, _posted, ctx = _run(monkeypatch, RuntimeError("нет соединения"),
                                replies=[TRUNCATED_LONG, TAIL], transcribe=True)

    assert result["status"] == "done"
    assert result["text"].startswith("Вода, Экстракт эхинацеи")
    assert len(ctx.calls) >= 2
    # первый вызов — попытка транскрибации (OCR-промпт), потом одношаговое чтение
    assert "OCR" in ctx.calls[0][0]["content"]


def test_transcript_lines_parser_survives_truncated_json():
    lines = mod._lines_from_reply('{"lines": ["Вода, сахар", "Соль, E211", "Объём: 170мл')

    assert lines == ["Вода, сахар", "Соль, E211", "Объём: 170мл"]


def test_local_extraction_stops_at_service_lines():
    composition = mod._composition_from_lines_local(TRANSCRIPT_LINES)

    assert composition.startswith("Вода, Экстракт эхинацеи")
    assert "Производитель" not in composition
    assert "170" not in composition
    assert mod._composition_from_lines_local(["Объём: 170мл", "Срок годности: 3 года"]) == ""


def test_two_pass_can_be_switched_off(monkeypatch):
    """NJ646_TRANSCRIBE=0 — старый одношаговый путь (один запрос к модели)."""
    result, _posted, ctx = _run(monkeypatch, GOOD)

    assert result["status"] == "done"
    assert len(ctx.calls) == 1
    assert "распознанных строк" not in result["note"]


def test_plain_text_transcription_is_accepted():
    """Модель может проигнорировать JSON — просто переписать строки. Это годится."""
    plain = ("НАТУРАЛЬНЫЙ ШАМПУНЬ ПАМПАС\n"
             "Состав: Вода, Экстракт мёда, Пантенол, Лимонен\n"
             "Объём: 170мл")

    lines = mod._lines_from_reply(plain)

    assert lines == ["НАТУРАЛЬНЫЙ ШАМПУНЬ ПАМПАС",
                     "Состав: Вода, Экстракт мёда, Пантенол, Лимонен",
                     "Объём: 170мл"]
    assert mod._composition_from_lines_local(lines) == "Вода, Экстракт мёда, Пантенол, Лимонен"


def test_single_line_answer_is_not_taken_as_transcription():
    assert mod._lines_from_reply("Мне не удалось прочитать этикетку.") == []


def test_plain_transcription_end_to_end(monkeypatch):
    """Прогон целиком: модель переписала строки текстом, состав собран из них."""
    plain = ("Состав: Вода, Экстракт эхинацеи, Экстракт мёда, Масло лаванды,\n"
             "Пантенол, Лаурет сульфат натрия, Поликватерниум-7, Лимонен\n"
             "Объём: 170мл")

    result, _posted, _ctx = _run(monkeypatch, plain, transcribe=True)

    assert result["status"] == "done"
    assert result["text"] == ("Вода, Экстракт эхинацеи, Экстракт мёда, Масло лаванды, "
                              "Пантенол, Лаурет сульфат натрия, Поликватерниум-7, Лимонен")
    assert "Объём" not in result["text"]
    assert result["transcript"].startswith("Состав: Вода")


# ------------------------------------------------- русский текст остаётся русским

def test_prompts_forbid_translation_and_transliteration():
    """Промпт обязан прямо требовать алфавит оригинала.

    Повод (03.10.2026): владелец получил по русской этикетке английский
    состав. Просьба «не переводи» в LABEL_SYSTEM была и раньше — её читали
    как «не пересказывай», поэтому теперь требование про КИРИЛЛИЦУ стоит
    отдельным абзацем в каждом промпте, который возвращает текст этикетки.
    """
    for prompt in (mod.TRANSCRIBE_SYSTEM, mod.COMPOSE_SYSTEM, mod.LABEL_SYSTEM):
        low = prompt.lower()

        assert "кириллиц" in low
        assert "транслитер" in low


def test_latin_drift_catches_translated_composition():
    russian_lines = "\n".join(TRANSCRIPT_LINES)

    assert mod._latin_drift(russian_lines,
                            "Water, Echinacea extract, Honey extract, Ginseng root extract, "
                            "Lavender oil, Panthenol, Sodium laureth sulfate") is True


def test_latin_drift_is_quiet_on_honest_answers():
    russian_lines = "\n".join(TRANSCRIPT_LINES)

    # Нормальный русский состав.
    assert mod._latin_drift(russian_lines,
                            "Вода, Экстракт эхинацеи, Экстракт мёда, Пантенол, Лимонен") is False

    # Русский список с латинскими INCI в скобках — это не перевод.
    assert mod._latin_drift(russian_lines,
                            "Вода (Aqua), Глицерин (Glycerin), Пантенол (Panthenol), "
                            "Лимонен (Limonene)") is False

    # Этикетка изначально латинская — переводить нечего.
    assert mod._latin_drift("Ingredients: Aqua, Glycerin, Panthenol, Limonene",
                            "Aqua, Glycerin, Panthenol, Limonene") is False

    # Слишком короткий ответ: судить не о чем, тревогу не поднимаем.
    assert mod._latin_drift(russian_lines, "Aqua") is False


def test_translated_composition_is_replaced_by_label_lines(monkeypatch):
    """Модель перевела состав — на сайт уходит список с этикетки, не перевод."""
    translated = ('{"composition": "Water, Echinacea extract, Honey extract, Ginseng root '
                  'extract, Ginkgo biloba leaf extract, Propolis extract, Lavender oil, '
                  'Panthenol, Sodium laureth sulfate, Ammonium lauryl sulfate, '
                  'Cocamidopropyl betaine, Propylene glycol, Polyquaternium-7, Limonene", '
                  '"kind": "cosmetic", "confidence": 90, "note": ""}')

    result, _posted, _ctx = _run(monkeypatch, TRANSCRIPT_JSON, replies=[translated],
                                 transcribe=True)

    assert result["status"] == "done"
    assert result["text"].startswith("Вода, Экстракт эхинацеи")
    assert "Water" not in result["text"]
    # Человеку объясняем, почему список собран мимо модели.
    assert "латиницей" in result["note"]


def test_honest_russian_answer_is_not_touched_by_the_guard(monkeypatch):
    """Страховка не должна мешать нормальному разбору."""
    compose = ('{"composition": "Вода, Экстракт эхинацеи, Экстракт мёда, Масло лаванды, '
               'Пантенол, Поликватерниум-7, Лимонен", "kind": "cosmetic", '
               '"confidence": 85, "note": ""}')

    result, _posted, _ctx = _run(monkeypatch, TRANSCRIPT_JSON, replies=[compose], transcribe=True)

    assert result["text"] == ("Вода, Экстракт эхинацеи, Экстракт мёда, Масло лаванды, "
                              "Пантенол, Поликватерниум-7, Лимонен")
    assert "латиницей" not in result["note"]
