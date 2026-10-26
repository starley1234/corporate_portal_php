"""nj666.plan / nj666.voice: разбор и озвучка книги многоголосым режимом.

Проверяем ровно то, ради чего канал и написан:

  * текст режется на ОКНА по абзацам, а не по символам — иначе модель
    получает половину фразы и размечает её как попало;
  * персонажи не плодятся: второй раз встреченный «Пётр Иванович» (пусть
    даже с опечаткой и под другим ref) уезжает в репликах тем же id;
  * замок персонажа — граница для модели (её honourит сайт, но воркер не
    должен слать правку замка в обход);
  * блок закрывается по логической паузе модели и по достигнутой длине;
  * битое окно не роняет книгу: остальной текст всё равно разберётся;
  * озвучка идёт по блокам: воркер синтезирует сам (OmniVoice) и сдаёт
    байты на сайт, а причину отказа видно в отчёте задачи.

Сайт и модель поддельные: нам важна логика разбора, а не качество LLM.
"""

from __future__ import annotations

import json

import pytest

import n2j_workers.handlers.nj666_multivoice as mod


# --------------------------------------------------------------------------
# Подделки
# --------------------------------------------------------------------------

class FakeLLM:
    """Модель-пустышка: отдаёт заранее заготовленные ответы по очереди."""

    def __init__(self, replies: list[str] | None = None, fail_on: set[int] | None = None):
        self.replies = list(replies or [])
        self.fail_on = fail_on or set()
        self.calls = 0

    async def chat_with_usage(self, messages, model=None, **options):
        self.calls += 1

        if self.calls in self.fail_on:
            raise RuntimeError("модель не отвечает")

        if self.replies:
            return self.replies.pop(0), {"total_tokens": 100}

        return json.dumps({"characters": [], "turns": [], "good_end": False}), {}


class FakeCtx:
    def __init__(self, llm: FakeLLM | None = None):
        self.steps: list[tuple[str, str]] = []
        self.llm = llm

    async def progress(self, stage: str, note: str = ""):
        self.steps.append((stage, note))


class FakeSite:
    """Сайт-пустышка: помнит всё, что воркер ему прислал."""

    def __init__(self, context: dict | None = None, synth_ok: bool = True):
        self.context = context or {}
        self.synth_ok = synth_ok
        self.block_chunks: list | None = None
        self.posts: list[tuple[str, dict]] = []
        self.next_id = 5
        #: «база» персонажей сайта: имя → id (сайт сливает тёзок по ключу)
        self.names: dict[str, int] = {}

    async def post_json(self, prefix, path, data, json_body=False, timeout=60.0,
                       retries=2, base=None):
        self.posts.append((path, data))

        if path == "/context":
            return True, "", dict(self.context)

        if path == "/characters":
            mapping: dict[str, int] = {}
            added = 0

            for row in data.get("characters") or []:
                ref = str(row.get("ref") or row.get("name") or "")
                name = mod._norm(str(row.get("name") or ""))

                if name and name in self.names:
                    cid = self.names[name]
                else:
                    cid = self.next_id
                    self.next_id += 1
                    self.names[name] = cid
                    added += 1

                if ref:
                    mapping[ref] = cid

                mapping[str(cid)] = cid

            return True, "", {"ok": True, "ids": sorted(set(mapping.values())),
                              "map": mapping, "added": added}

        if path == "/block":
            bid = int(data.get("block_id") or 0)
            return True, "", {
                "ok": True,
                "block_id": bid,
                "model": "",
                "voice": "narrator",
                "format": "mp3",
                "speed": 1.0,
                "language": "ru",
                "chunks": list(self.block_chunks or [[{"text": "Привет.", "voice": "narrator"}]]),
                "chars": 7,
            }

        if path == "/audio":
            if data.get("error"):
                return True, "", {"ok": False, "error": data["error"]}
            if not self.synth_ok:
                return False, "сайт не принял звук", {"ok": False, "error": "сайт не принял звук"}
            return True, "", {"ok": True, "block": int(data.get("block_id") or 0), "bytes": 4}

        if path == "/synth":
            if not self.synth_ok:
                return False, "синтез не удался", {"ok": False, "error": "синтез не удался"}

            return True, "", {"ok": True, "block": int(data.get("block_id") or 0), "bytes": 1024}

        return True, "", {"ok": True}

    # --- удобные выборки ---------------------------------------------------
    def of(self, path: str) -> list[dict]:
        return [data for p, data in self.posts if p == path]

    def blocks(self) -> list[dict]:
        rows: list[dict] = []

        for data in self.of("/blocks"):
            rows.extend(data.get("blocks") or [])

        return rows


@pytest.fixture()
def site(monkeypatch) -> FakeSite:
    box = FakeSite(context={
        "ok": True,
        "book_id": 1,
        "code": "ab34cd56ef78",
        "title": "Книга",
        "author": "Автор",
        "mode": "auto",
        "model": "",
        "language": "ru",
        "chars": 0,
        "single_block": False,
        "targets": {"min": 7000, "max": 15000},
        "plan": {"window_chars": 6000, "list_chars": 40, "list_voices": 24},
        "limits": {"max_chars": 600000},
        "characters": [],
        "blocks": [],
        "voices": [],
        "default_voice": "",
        "text": "",
    })

    async def fake_post_json(prefix, path, data, json_body=False, timeout=60.0,
                             retries=2, base=None):
        return await box.post_json(prefix, path, data, json_body=json_body,
                                   timeout=timeout, retries=retries, base=base)

    monkeypatch.setattr(mod.site, "post_json", fake_post_json)
    monkeypatch.setenv("NJ665_API_URL", "http://voice.test")
    monkeypatch.setenv("NJ665_API_KEY", "test-key")

    async def fake_synth(**kwargs):
        if not box.synth_ok:
            return {"ok": False, "bytes": b"", "format": "mp3", "chars": 0,
                    "error": "синтез не удался"}
        return {"ok": True, "bytes": b"ID3xxxx", "format": "mp3", "chars": 5, "error": ""}

    monkeypatch.setattr(mod.omnivoice, "synth", fake_synth)

    return box


# --------------------------------------------------------------------------
# Чистые helpers
# --------------------------------------------------------------------------

def test_windows_keep_paragraphs():
    """Короткие абзацы склеиваются в одно окно: модель видит связный кусок.

    Окно меньше 1500 знаков не имеет смысла (модель теряет нить), поэтому
    лимит окна снизу поджат — мелкие абзацы всё равно склеятся.
    """
    text = "Абзац раз.\n\nАбзац два.\n\nАбзац три."

    assert mod._windows(text, 6000) == ["Абзац раз.\n\nАбзац два.\n\nАбзац три."]
    assert mod._windows(text, 12) == ["Абзац раз.\n\nАбзац два.\n\nАбзац три."]

    big = "\n\n".join(["Абзац номер %d. " % i * 55 for i in range(1, 4)])

    assert len(mod._windows(big, 1500)) == 3, "абзацы по 900 знаков в одно окно не влезают"


def test_windows_join_small_paragraphs():
    text = "Первый.\n\nВторой.\n\n" + ("Длинный абзац. " * 500)

    parts = mod._windows(text, 6000)

    assert parts[0] == "Первый.\n\nВторой.", "короткие абзацы склеиваются в одно окно"
    assert all(len(p) <= 6000 for p in parts), "ни одно окно не длиннее лимита"
    assert len(parts) > 2, "длинный абзац порезан на несколько окон"


def test_windows_of_empty_text():
    assert mod._windows("", 6000) == []
    assert mod._windows("   \n\n  ", 6000) == []


def test_split_sentences_breaks_on_punctuation():
    text = "Первая фраза. Вторая фраза! Третья фраза?"

    parts = mod._split_sentences(text, 30)

    assert len(parts) >= 2
    assert "".join(parts).replace(" ", "") == text.replace(" ", "")


def test_json_of_reads_fenced_and_plain():
    assert mod._json_of('```json\n{"a": 1}\n```')["a"] == 1
    assert mod._json_of('{"a": 2}')["a"] == 2
    assert mod._json_of('вот ответ: {"a": 3} — и всё')["a"] == 3


def test_json_of_rejects_garbage():
    with pytest.raises(RuntimeError):
        mod._json_of("ничего похожего на JSON")


def test_norm_makes_names_comparable():
    assert mod._norm("Пётр Иванович!") == mod._norm("  петр иванович  ")


def test_resolve_speakers_matches_by_id_name_and_falls_back():
    characters = [
        {"id": 5, "name": "Маргарита"},
        {"id": 6, "name": "Рассказчик", "kind": "narrator"},
    ]

    turns = mod._resolve_speakers([
        {"speaker": "5", "text": "по id"},
        {"speaker": "маргарита", "text": "по имени"},
        {"speaker": "narrator", "text": "автор"},
        {"speaker": "n1", "text": "незнакомец"},
        {"speaker": "5", "text": "   "},
    ], characters)

    assert [t["speaker"] for t in turns] == ["5", "5", "narrator", "narrator"]
    assert turns[0]["text"] == "по id", "текст реплики не потерян"


def test_characters_block_marks_narrator_and_limits():
    rows = [
        {"id": 6, "name": "Рассказчик", "kind": "narrator", "gender": "", "age": "",
         "description": "", "mentions": 300},
        {"id": 5, "name": "Маргарита", "kind": "character", "gender": "female",
         "age": "adult", "description": "Тихая", "mentions": 40},
    ]

    text = mod._characters_block(rows, 40)

    assert "id=6" in text and "рассказчик" in text
    assert "female/adult" in text and "Тихая" in text
    assert mod._characters_block([], 10) == "пока никого"


def test_voices_block_is_short_and_safe():
    voices = [{"id": "morfius", "gender": "male", "age": "adult", "description": "Глубокий."}]

    assert "morfius" in mod._voices_block(voices, 24)
    assert "голосов недоступен" in mod._voices_block([], 24)


def test_split_speech_quotes_fairy_tale():
    """Автор и прямая речь в кавычках — два куска, кавычки сняты."""
    text = (
        "Рассказала она ему свой недоброй сон и стала просить у него позволения: "
        "повидать своего батюшку родимого; и возговорит к ней зверь лесной: "
        '"И зачем тебе мое позволенье? Золот перстень мой у тебя лежит."'
    )
    spans = mod._split_speech(text)

    assert [s["kind"] for s in spans] == ["narrator", "speech"]
    assert "Рассказала она ему" in spans[0]["text"]
    assert spans[1]["text"].startswith("И зачем тебе")
    assert '"' not in spans[1]["text"]


def test_split_speech_guillemets_and_plain():
    spans = mod._split_speech("Он сказал: «Здравствуй, мир». И ушёл.")

    assert [s["kind"] for s in spans] == ["narrator", "speech", "narrator"]
    assert spans[1]["text"] == "Здравствуй, мир"
    assert mod._split_speech("Просто абзац без речи.") == [
        {"kind": "plain", "text": "Просто абзац без речи."},
    ]


def test_split_speech_dash_dialogue():
    spans = mod._split_speech("— Здравствуй, — сказал он. — Как дела?")

    assert [s["kind"] for s in spans] == ["speech", "narrator", "speech"]
    assert spans[0]["text"].startswith("Здравствуй")
    assert "сказал он" in spans[1]["text"]
    assert "Как дела" in spans[2]["text"]


def test_chunks_of_numbers_paragraphs():
    chunks, nxt = mod._chunks_of('Первый абзац.\n\nОн сказал: "Привет."', 1)

    assert [c["n"] for c in chunks] == [1, 2, 3]
    assert nxt == 4
    assert chunks[0]["kind"] == "plain"
    assert chunks[1]["kind"] == "narrator"
    assert chunks[2]["kind"] == "speech"
    assert chunks[2]["text"] == "Привет."


def test_turns_of_answer_uses_source_chunks_not_model_text():
    chunks = [{"n": 1, "kind": "plain", "text": "Исходный абзац целиком."}]
    data = {"assign": [{"n": 1, "speaker": "narrator"}, {"n": 1, "speaker": "9"}],
            "turns": [{"speaker": "narrator", "text": "модель переписала начало"}]}
    turns = mod._turns_of_answer(data, chunks, [], {})

    assert [t["text"] for t in turns] == ["Исходный абзац целиком."]
    assert turns[0]["p"] == 1


def test_turns_of_answer_missing_json_keeps_source():
    chunks = [{"n": 5, "kind": "plain", "text": "Не потерять."}]
    turns = mod._turns_of_answer({}, chunks, [], {})

    assert turns == [{"speaker": "narrator", "text": "Не потерять.", "p": 5}]


def test_split_speech_colon_cue_without_quotes():
    text = ("и говорит он своим любезныим дочерям: "
            "Милые мои доченьки, дочери любезные, я отправляюсь.")
    spans = mod._split_speech(text)

    assert [s["kind"] for s in spans] == ["narrator", "speech"]
    assert spans[0]["text"].endswith(":")
    assert spans[1]["text"].startswith("Милые")


def test_chunks_of_next_paragraph_after_cue_is_speech():
    text = ("В некиим царстве жил-был богатый купец.\n\n"
            "и говорит он своим любезныим дочерям:\n\n"
            "Милые мои доченьки, дочери любезные, я отправляюсь за море.")
    chunks, _ = mod._chunks_of(text, 1)

    assert [c["kind"] for c in chunks] == ["plain", "plain", "speech"]
    assert chunks[2]["text"].startswith("Милые")


def test_turns_of_answer_infers_speech_from_merchant_cue():
    """Модель ставит narrator всем — речь после «говорит купец» всё равно ему."""
    chunks = [
        {"n": 1, "kind": "plain",
         "text": "В некиим царстве жил-был богатый купец, именитый человек, "
                 "и говорит он своим любезныим дочерям:"},
        {"n": 2, "kind": "speech",
         "text": "Милые мои доченьки, дочери любезные, я отправляюсь за море."},
    ]
    data = {"assign": [{"n": 1, "speaker": "narrator"},
                       {"n": 2, "speaker": "narrator"}]}
    chars = [{"id": 4, "name": "Купец", "kind": "character"},
             {"id": 7, "name": "Меньшая дочь", "kind": "character"}]
    turns = mod._turns_of_answer(data, chunks, chars, {})

    assert [t["speaker"] for t in turns] == ["narrator", "4"]
    assert turns[1]["text"].startswith("Милые")


def test_turns_of_answer_keeps_explicit_character():
    chunks = [
        {"n": 1, "kind": "narrator", "text": "сказала меньшая дочь:"},
        {"n": 2, "kind": "speech", "text": "Батюшка, не губи меня."},
    ]
    data = {"assign": [{"n": 1, "speaker": "narrator"}, {"n": 2, "speaker": "7"}]}
    chars = [{"id": 4, "name": "Купец", "kind": "character"},
             {"id": 7, "name": "Меньшая дочь", "kind": "character"}]
    turns = mod._turns_of_answer(data, chunks, chars, {})

    assert [t["speaker"] for t in turns] == ["narrator", "7"]


def test_assign_dict_and_json_array():
    chunks = [{"n": 1, "kind": "plain", "text": "Жил-был купец."}]
    data = {"assign": {"1": "narrator"}}
    turns = mod._turns_of_answer(data, chunks, [{"id": 4, "name": "Купец"}], {})

    assert turns[0]["speaker"] == "narrator"
    assert mod._json_of('[{"n": 1, "speaker": "narrator"}]')["assign"][0]["n"] == 1


def test_resolve_speakers_fuzzy_short_name():
    turns = mod._resolve_speakers(
        [{"speaker": "купец", "text": "Еду за море."}],
        [{"id": 4, "name": "Богатый купец", "kind": "character"}],
    )

    assert turns[0]["speaker"] == "4"


def test_split_quoted_turns_separates_author_and_speech():
    turns = mod._split_quoted_turns([{
        "speaker": "8",
        "text": 'Рассказала она ему сон; и возговорит зверь: "И зачем тебе мое позволенье?"',
    }])

    assert [t["speaker"] for t in turns] == ["narrator", "8"]
    assert "Рассказала она ему" in turns[0]["text"]
    assert turns[1]["text"].startswith("И зачем тебе")


def test_close_block_numbers_and_title():
    block = mod._close_block([{"speaker": "narrator", "text": "  "},
                              {"speaker": "narrator", "text": "Ночью пошёл дождь."}], 100, 3)

    assert block["no"] == 3
    assert block["chars"] == 100
    assert "дождь" in block["title"]


def test_norm_strips_voice_suffix_clones():
    assert mod._norm("Принц (голос)") == mod._norm("Принц")
    assert mod._norm("Меньшая дочь (голос 2)") == "меньшая дочь"
    assert mod._norm("Меньшая дочь (голос 2, новая)") == "меньшая дочь"
    assert mod._clean_character_name("Принц (голос 2, новая)") == "Принц"
    assert mod._prepare_new_character({"ref": "n1", "name": "Принц (голос)"})["name"] == "Принц"
    assert mod._prepare_new_character({"name": "Рассказчик (голос 1)"}) is None


def test_resolve_speakers_voice_suffix_is_same_person():
    turns = mod._resolve_speakers(
        [{"speaker": "Принц (голос)", "text": "Здравствуй."},
         {"speaker": "Меньшая дочь (голос 2, новая)", "text": "Батюшка!"}],
        [{"id": 8, "name": "Принц"}, {"id": 4, "name": "Меньшая дочь"}],
    )

    assert [t["speaker"] for t in turns] == ["8", "4"]


def test_is_narrator_name_aliases():
    assert mod._is_narrator_name("Рассказчик")
    assert mod._is_narrator_name("narrator")
    assert mod._is_narrator_name("Рассказчик (голос 1)")
    assert mod._is_narrator_name("Рассказчик (Рассказчик)")
    assert not mod._is_narrator_name("Купец")
    assert not mod._is_narrator_name("Настенька")
    assert not mod._is_narrator_name("")


def test_characters_block_does_not_glue_kind_to_name():
    text = mod._characters_block(
        [{"id": 5, "name": "Рассказчик", "kind": "narrator", "mentions": 3}], 40)

    assert "(рассказчик)" not in text
    assert "[рассказчик]" in text
    assert "id=5" in text
    assert "Рассказчик" in text


def test_resolve_speakers_narrator_aliases_not_new_person():
    turns = mod._resolve_speakers([
        {"speaker": "Рассказчик (голос 1)", "text": "Жил-был купец."},
        {"speaker": "8", "text": "Здравствуй."},
        {"speaker": "Рассказчик", "text": "И пошёл."},
    ], [{"id": 8, "name": "Зверь"}, {"id": 3, "name": "Рассказчик (Рассказчик)"}])

    assert [t["speaker"] for t in turns] == ["narrator", "8", "narrator"]


# --------------------------------------------------------------------------
# Разбор книги (nj666.plan)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_plan_assign_keeps_source_and_splits_quotes(site: FakeSite):
    """Модель ставит speaker на номер куска, текст — исходник, кавычки уже разрезаны."""
    site.context["text"] = 'Авторский ввод. "Реплика героя."'
    site.context["targets"] = {"min": 10 ** 9, "max": 10 ** 9}

    llm = FakeLLM(replies=[json.dumps({
        "characters": [{"ref": "n1", "name": "Зверь", "gender": "male", "age": "adult"}],
        "assign": [{"n": 1, "speaker": "narrator"}, {"n": 2, "speaker": "n1"}],
        "good_end": True,
    })])

    result = await mod.handle(FakeCtx(llm), {"book_id": 1})

    assert result["blocks"] == 1
    turns = [t for b in site.blocks() for t in b["turns"]]

    assert [t["text"] for t in turns] == ["Авторский ввод.", "Реплика героя."]
    assert [t["speaker"] for t in turns] == ["narrator", "5"]
    assert [t["p"] for t in turns] == [1, 2]


@pytest.mark.asyncio
async def test_plan_does_not_post_narrator_clones(site: FakeSite):
    """Модель часто заводит «Рассказчик (голос 1)» — на сайт это не уходит."""
    site.context["text"] = "Жил-был купец."
    site.context["targets"] = {"min": 10 ** 9, "max": 10 ** 9}

    llm = FakeLLM(replies=[json.dumps({
        "characters": [
            {"ref": "n1", "name": "Рассказчик (голос 1)"},
            {"ref": "n2", "name": "Купец"},
        ],
        "assign": [{"n": 1, "speaker": "n1"}],
        "good_end": True,
    })])

    await mod.handle(FakeCtx(llm), {"book_id": 1})

    names = [c["name"] for d in site.of("/characters") for c in d.get("characters") or []]

    assert "Купец" in names
    assert not any("Рассказчик" in n for n in names)
    turns = [t for b in site.blocks() for t in b["turns"]]
    assert turns and turns[0]["speaker"] == "narrator"


@pytest.mark.asyncio
async def test_plan_strips_voice_suffix_from_new_character(site: FakeSite):
    site.context["text"] = "Принц сказал."
    site.context["targets"] = {"min": 10 ** 9, "max": 10 ** 9}

    llm = FakeLLM(replies=[json.dumps({
        "characters": [{"ref": "n1", "name": "Принц (голос 2, новая)"}],
        "assign": [{"n": 1, "speaker": "n1"}],
        "good_end": True,
    })])

    await mod.handle(FakeCtx(llm), {"book_id": 1})

    names = [c["name"] for d in site.of("/characters") for c in d.get("characters") or []]

    assert names == ["Принц"]


@pytest.mark.asyncio
async def test_plan_reuses_character_found_earlier(site: FakeSite):
    """Второй раз встреченный персонаж — тот же id, а не новый человек."""
    site.context["text"] = "Глава первая.\n\n" + ("Длинный кусок текста. " * 400)
    site.context["targets"] = {"min": 10 ** 9, "max": 10 ** 9}  # один блок на всю книгу

    llm = FakeLLM(replies=[
        json.dumps({"characters": [{"ref": "n1", "name": "Пётр Иванович", "gender": "male"}],
                    "turns": [{"speaker": "n1", "text": "Здравствуйте."}],
                    "good_end": False}),
        json.dumps({"characters": [{"ref": "n2", "name": "пётр иванович!", "gender": "male"}],
                    "turns": [{"speaker": "n2", "text": "И вам здравствуйте."}],
                    "good_end": True}),
    ])

    result = await mod.handle(FakeCtx(llm), {"book_id": 1})

    assert result["blocks"] == 1
    assert result["characters"] == 1, "персонаж один — сайт слил тёзок по имени"

    turns = [t for b in site.blocks() for t in b["turns"]]

    assert [t["speaker"] for t in turns] == ["5", "5"], "в одном блоке один и тот же человек"


@pytest.mark.asyncio
async def test_plan_closes_block_on_logical_end(site: FakeSite):
    """Блок закрывается там, где модель сказала «тут пауза», а не по числу знаков."""
    # Абзац длиннее половины окна: иначе все восемь склеятся в одно окно и
    # пауз будет не две, а одна (окно — единица работы модели).
    site.context["text"] = "\n\n".join(["Абзац номер %d. " % i * 400 for i in range(1, 9)])
    site.context["targets"] = {"min": 10, "max": 10 ** 9}

    replies = []

    for i in range(1, 9):
        replies.append(json.dumps({
            "characters": [],
            "turns": [{"speaker": "narrator", "text": "Абзац номер %d." % i}],
            "good_end": i % 4 == 0,
        }))

    result = await mod.handle(FakeCtx(FakeLLM(replies)), {"book_id": 1})

    assert result["blocks"] == 2, "пауза была на 4-м и 8-м абзаце"
    assert sum(len(b["turns"]) for b in site.blocks()) == 8


@pytest.mark.asyncio
async def test_plan_survives_bad_window(site: FakeSite):
    """Одно битое окно не должно хоронить всю книгу."""
    site.context["text"] = "\n\n".join(["Раз " * 900, "Два " * 900, "Три " * 900])
    site.context["targets"] = {"min": 10 ** 9, "max": 10 ** 9}

    llm = FakeLLM(replies=[
        "модель вернула не JSON",
        json.dumps({"characters": [], "turns": [{"speaker": "narrator", "text": "Два."}],
                    "good_end": False}),
        json.dumps({"characters": [], "turns": [{"speaker": "narrator", "text": "Три."}],
                    "good_end": True}),
    ])

    result = await mod.handle(FakeCtx(llm), {"book_id": 1})

    assert result["windows"] == 3
    texts = [t["text"] for b in site.blocks() for t in b["turns"]]
    assert texts[0].startswith("Раз "), "битое окно уходит рассказчику, текст не теряем"
    assert texts[1:] == ["Два.", "Три."]


@pytest.mark.asyncio
async def test_plan_finalizes_book(site: FakeSite):
    site.context["text"] = "Один абзац текста."

    await mod.handle(FakeCtx(FakeLLM()), {"book_id": 1})

    finals = [d for d in site.of("/blocks") if d.get("final")]

    assert len(finals) == 1, "разбор закрывается ровно одним финальным вызовом"
    assert site.of("/blocks")[-1] is finals[0]


@pytest.mark.asyncio
async def test_plan_without_book_id_fails_loudly(site: FakeSite):
    with pytest.raises(RuntimeError):
        await mod.handle(FakeCtx(FakeLLM()), {})


@pytest.mark.asyncio
async def test_plan_fails_when_site_refuses_context(site: FakeSite):
    """Отказ /context — /fail и исключение, не «готово» в очереди (11.10.2026)."""
    site.context = {"ok": False, "error": "книга удалена"}

    with pytest.raises(RuntimeError, match="удалена"):
        await mod.handle(FakeCtx(FakeLLM()), {"book_id": 1})

    fails = site.of("/fail")

    assert fails and "удалена" in fails[0]["error"]
    assert not site.of("/stage") and not site.of("/blocks")


@pytest.mark.asyncio
async def test_plan_fails_when_text_empty(site: FakeSite):
    site.context["text"] = "   "

    with pytest.raises(RuntimeError, match="пуст"):
        await mod.handle(FakeCtx(FakeLLM()), {"book_id": 1})

    assert site.of("/fail") and "пуст" in site.of("/fail")[0]["error"]


@pytest.mark.asyncio
async def test_search_mode_asks_internet_first(site: FakeSite, monkeypatch):
    site.context["mode"] = "search"
    site.context["text"] = "Маленький отрывок."

    async def fake_ddg(query, max_results=5, timeout_sec=60.0):
        return [{"href": "https://example.ru/book", "title": "Книга", "body": "О героях."}]

    async def fake_fetch(url):
        return "<html><body>Пётр Иванович и Маргарита.</body></html>", "Книга"

    async def fake_to_markdown(html, cap=None):
        return "Пётр Иванович и Маргарита.", "Книга"

    monkeypatch.setattr(mod.webread, "ddg_text", fake_ddg)
    monkeypatch.setattr(mod.webread, "fetch", fake_fetch)
    monkeypatch.setattr(mod.webread, "to_markdown", fake_to_markdown)

    llm = FakeLLM(replies=[
        "Пётр Иванович персонажи",                       # запрос для поиска
        json.dumps({"characters": [{"name": "Маргарита", "gender": "female",
                                    "age": "adult", "description": "Тихая"}]}),
        json.dumps({"characters": [], "turns": [{"speaker": "narrator", "text": "Текст."}],
                    "good_end": True}),
    ])

    await mod.handle(FakeCtx(llm), {"book_id": 1})

    found = site.of("/characters")

    assert found and found[0]["characters"], "персонажи пришли из интернета до разбора"
    assert found[0]["characters"][0]["name"] == "Маргарита"


# --------------------------------------------------------------------------
# Озвучка книги (nj666.voice)
# --------------------------------------------------------------------------

@pytest.mark.asyncio
async def test_voice_runs_every_block_and_reports_done(site: FakeSite):
    site.context["blocks"] = [
        {"id": 11, "no": 1, "chars": 9000, "status": "new"},
        {"id": 12, "no": 2, "chars": 8000, "status": "new"},
    ]

    result = await mod.handle_voice(FakeCtx(), {"book_id": 1})

    assert [d["block_id"] for d in site.of("/block")] == [11, 12]
    assert [d["block_id"] for d in site.of("/audio")] == [11, 12]
    assert not site.of("/synth"), "длинный синтез больше не идёт через сайт"
    assert result["voiced"] == 2 and result["failed"] == 0
    assert site.of("/done"), "озвучка закрывается сообщением «готово»"


@pytest.mark.asyncio
async def test_voice_reports_failure_when_nothing_voiced(site: FakeSite):
    site.synth_ok = False
    site.context["blocks"] = [{"id": 11, "no": 1, "chars": 9000, "status": "new"}]

    result = await mod.handle_voice(FakeCtx(), {"book_id": 1})

    assert result["voiced"] == 0 and result["failed"] == 1

    fails = site.of("/fail")

    assert fails and "ни один блок" in fails[0]["error"]


@pytest.mark.asyncio
async def test_voice_without_blocks_fails_loudly(site: FakeSite):
    site.context["blocks"] = []

    with pytest.raises(RuntimeError, match="блоков нет"):
        await mod.handle_voice(FakeCtx(), {"book_id": 1})

    assert site.of("/fail") and "блоков нет" in site.of("/fail")[0]["error"]


@pytest.mark.asyncio
async def test_voice_fails_when_site_refuses_context(site: FakeSite):
    site.context = {"ok": False, "error": "книга удалена"}

    with pytest.raises(RuntimeError, match="удалена"):
        await mod.handle_voice(FakeCtx(), {"book_id": 1})

    assert site.of("/fail") and "удалена" in site.of("/fail")[0]["error"]


@pytest.mark.asyncio
async def test_voice_honours_explicit_block_list(site: FakeSite):
    """«Озвучить с этого места»: в задаче лежат номера конкретных блоков."""
    site.context["blocks"] = [
        {"id": 11, "no": 1, "chars": 9000, "status": "voiced"},
        {"id": 12, "no": 2, "chars": 8000, "status": "new"},
        {"id": 13, "no": 3, "chars": 7000, "status": "new"},
    ]

    await mod.handle_voice(FakeCtx(), {"book_id": 1, "block_ids": [12, 13]})

    assert [d["block_id"] for d in site.of("/block")] == [12, 13]
    assert [d["block_id"] for d in site.of("/audio")] == [12, 13]


@pytest.mark.asyncio
async def test_voice_sends_each_chunk_separately(site: FakeSite):
    """Глава режется на куски: nginx не ждёт весь синтез одним POST."""
    site.context["blocks"] = [{"id": 11, "no": 1, "chars": 9000, "status": "new"}]
    site.block_chunks = [
        [{"text": "Раз.", "voice": "anna"}],
        [{"text": "Два.", "voice": "morfius"}],
    ]

    await mod.handle_voice(FakeCtx(), {"book_id": 1})

    audio = site.of("/audio")
    assert [d.get("part") for d in audio] == [0, 1]
    assert [d.get("total") for d in audio] == [2, 2]
    assert all(d.get("audio") for d in audio)


@pytest.mark.asyncio
async def test_voice_refuses_without_tts_keys(site: FakeSite, monkeypatch):
    site.context["blocks"] = [{"id": 11, "no": 1, "chars": 100, "status": "new"}]
    monkeypatch.delenv("NJ665_API_URL", raising=False)
    monkeypatch.delenv("NJ665_API_KEY", raising=False)

    with pytest.raises(RuntimeError, match="не настроен"):
        await mod.handle_voice(FakeCtx(), {"book_id": 1})

    assert site.of("/fail") and "NJ665" in site.of("/fail")[0]["error"]
    assert not site.of("/audio")
