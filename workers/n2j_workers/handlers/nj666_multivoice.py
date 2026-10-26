"""Каналы 'nj666.plan' и 'nj666.voice': многоголосая озвучка книги (модуль nj666).

Пайплайн nj666.plan (разбор):

  1. POST /multivoice/w/context — сайт отдаёт текст книги, настройки (диапазон
     длины блока, режим, модель), уже известных персонажей и каталог голосов
     с характеристиками (пол, возраст, описание). Воркер в базу не ходит;
  2. режим «утка го» (mode=search): сначала ищем книгу в интернете (DuckDuckGo
     через webread) и просим модель составить список персонажей по находкам —
     так у известной книги персонажи будут верными с первого блока, а не
     «Пётр» и «Пётр Иванович» по отдельности;
  3. дальше идём по тексту ОКНАМИ (window_chars). Окно режется на
     пронумерованные куски (кавычки и тире — отдельно «речь»). Модели
     передаём: правила, список персонажей, хвост предыдущего окна
     (чтобы не повторяла начало) и куски с номерами;
  4. модель возвращает JSON assign: номер куска → speaker (id/ref/narrator).
     Текст кусков она не переписывает — иначе «начало» всплывает в следующем
     блоке, а автор и прямая речь слипаются. Старый формат turns ещё читаем;
  5. персонажи сливаются (POST /multivoice/w/characters), реплики копятся;
     когда набирается нужная длина И модель сказала «здесь логический конец» —
     блок закрывается (POST /multivoice/w/blocks). Номер блока на сайте —
     MAX(no)+1, пачки не сбрасывают счётчик.

Пайплайн nj666.voice (озвучка): идём по блокам, забираем реплики
(POST /multivoice/w/block) и синтезируем сами (OmniVoice v1, как /voice).
Готовые байты сдаём на сайт (POST /multivoice/w/audio). Так длинная глава
не сидит в php-fpm/nginx: TTS-сервер успевает отдать звук воркеру.

Env: NJ666_API_BASE (иначе общий NJ120_API_BASE + /multivoice/w),
NJ666_SITE_TOKEN (иначе WORKER_SITE_TOKEN / WORKER_API_TOKEN),
NJ666_MODEL («провайдер::модель», пусто — модель воркера),
NJ666_SEARCH=0 (запретить «утку го»), NJ666_SEARCH_TIMEOUT, NJ666_CONTEXT
(примерный контекст модели в токенах),
NJ665_API_URL / NJ665_API_KEY (синтез; без них озвучка отказывается),
NJ666_SYNTH_TIMEOUT или NJ665_TIMEOUT (секунд на кусок, дефолт 600).
"""

from __future__ import annotations

import base64
import json
import logging
import os
import re
import time

from .. import omnivoice, site, webread

log = logging.getLogger("nj666_multivoice")

_PREFIX = "NJ666"
API_PATH = "/multivoice/w"

CHANNEL = "nj666.plan"
VOICE_CHANNEL = "nj666.voice"

# ---------------------------------------------------------------- подсказки

PLAN_SYSTEM = (
    "Ты — режиссёр многоголосой озвучки. Тебе дают ПРОНУМЕРОВАННЫЕ КУСКИ "
    "исходного текста. Текст уже вырезан из книги — не переписывай его, "
    "не возвращай, не добавляй начало книги и не повторяй прошлые куски.\n"
    "Задача: для каждого номера сказать КТО говорит.\n\n"
    "Правила:\n"
    "1. Ответ — только JSON. Поля text в ответе быть не должно: сайт возьмёт "
    "исходный кусок по номеру.\n"
    "2. speaker — id из списка ИЗВЕСТНЫХ ПЕРСОНАЖЕЙ, или ref нового (n1, n2), "
    "или narrator. Узнал персонажа под другим именем («старик» = «Пётр "
    "Иванович») — пиши id из списка. Нового заводи ТОЛЬКО если его правда нет.\n"
    "3. Пометка «речь» — прямая речь (кавычки или тире). Это персонаж, не автор.\n"
    "4. Пометка «автор» — описание, действие, ремарка. Всегда narrator, даже "
    "если в куске есть имя персонажа.\n"
    "5. Пометка «текст» — целый абзац без кавычек: narrator или персонаж, "
    "как по смыслу.\n"
    "6. Не пропускай номера и не давай один номер дважды.\n"
    "7. Для нового персонажа: ref, имя, пол (male/female), возраст "
    "(child/young/adult/elderly), одно-два предложения как говорит, голос "
    "из СПИСКА ГОЛОСОВ (ровно id), если ясно.\n"
    "8. good_end=true, если фрагмент заканчивается логической паузой "
    "(сцена, глава, завершённый эпизод).\n"
    "9. Не заводи персонажа «Рассказчик», narrator или «Рассказчик (…)» — "
    "авторский текст это speaker=narrator, он уже в списке.\n"
    "10. Имя — как в книге, без «(голос)», «(голос 2)», «новая». Голос пиши "
    "в поле voice, не в имя. Того же человека — его id, не двойник.\n"
    "11. Кусок «речь» — персонаж, не narrator. Не знаешь кто — тот, кто "
    "только что «сказал/говорит/возговорит» в авторском куске перед ним.\n\n"
    "Формат:\n"
    "{\"characters\":[{\"ref\":\"n1\",\"name\":\"...\",\"gender\":\"male\","
    "\"age\":\"adult\",\"description\":\"...\",\"voice\":\"morfius\"}],"
    "\"assign\":[{\"n\":1,\"speaker\":\"narrator\"},{\"n\":2,\"speaker\":8}],"
    "\"good_end\":false}"
)

SEARCH_SYSTEM = (
    "Ты готовишь список персонажей книги для многоголосой озвучки. Тебе дают "
    "название книги, автора и выдержки из найденных страниц.\n"
    "Выпиши ДЕЙСТВУЮЩИХ ЛИЦ, которых правда стоит озвучивать отдельным голосом: "
    "главных и заметных второстепенных, 4–12 человек, без служебных списков и "
    "без повторов одного человека под разными именами.\n"
    "Для каждого: имя (как в книге, кратко), пол (male/female), возраст "
    "(child/young/adult/elderly), одно-два предложения — кто это и как говорит "
    "(тембр, темп, характер).\n"
    "Ответ — строго JSON: {\"characters\":[{\"name\":\"...\",\"gender\":\"...\","
    "\"age\":\"...\",\"description\":\"...\"}]}"
)

SEARCH_QUERY_SYSTEM = (
    "Ты готовишь поисковый запрос. Ответ — ОДНА строка: название книги, автор и "
    "слово «персонажи» (или «действующие лица»), без кавычек и пояснений."
)


# ==========================================================================
# Мелкие helpers
# ==========================================================================

def _clean(text: str, limit: int = 0) -> str:
    text = (text or "").strip()

    if limit and len(text) > limit:
        text = text[:limit].rstrip() + "…"

    return text


def _json_of(text: str) -> dict:
    """Достать JSON из ответа модели: бывают ```-блоки, массив assign и обрыв."""
    raw = (text or "").strip()

    if raw.startswith("```"):
        raw = re.sub(r"^```(?:json)?", "", raw).strip()
        raw = raw.split("```")[0].strip()

    start_obj = raw.find("{")
    start_arr = raw.find("[")

    if start_obj < 0 and start_arr < 0:
        raise RuntimeError("модель не вернула JSON")

    if start_obj < 0 or (0 <= start_arr < start_obj):
        start, end = start_arr, raw.rfind("]")
    else:
        start, end = start_obj, raw.rfind("}")

    if start < 0 or end <= start:
        raise RuntimeError("модель не вернула JSON")

    data = _parse_json(raw[start:end + 1])

    if data is None:
        raise RuntimeError("модель не вернула JSON")

    if isinstance(data, list):
        return {"assign": data}

    if not isinstance(data, dict):
        raise RuntimeError("модель не вернула JSON")

    return data


def _parse_json(blob: str):
    """json.loads плюс закрытие оборванного объекта (max_tokens срезал хвост)."""
    blob = (blob or "").strip()

    for extra in ("", "}", "]}", '"]}', "}}"):
        try:
            return json.loads(blob + extra)
        except json.JSONDecodeError:
            continue

    return None


def _as_list(value) -> list:
    return [x for x in (value or []) if isinstance(x, dict)]


def _windows(text: str, size: int) -> list[str]:
    """Режем текст на окна по абзацам: модель видит целые абзацы, а не огрызок."""
    size = max(1500, int(size or 6000))
    parts = [p.strip() for p in re.split(r"\n\s*\n", text or "") if p.strip()]

    if not parts:
        return [text] if text.strip() else []

    windows: list[str] = []
    cur = ""

    for part in parts:
        # Один абзац длиннее окна — режем по предложениям, иначе модель
        # не увидит его конец ни в каком окне.
        if len(part) > size:
            if cur:
                windows.append(cur)
                cur = ""

            for piece in _split_sentences(part, size):
                windows.append(piece)

            continue

        if cur and len(cur) + 2 + len(part) > size:
            windows.append(cur)
            cur = part
        else:
            cur = (cur + "\n\n" + part).strip() if cur else part

    if cur:
        windows.append(cur)

    return windows


def _split_sentences(text: str, size: int) -> list[str]:
    """Режем длинный абзац на куски по границам предложений."""
    pieces: list[str] = []
    cur = ""

    for sentence in re.split(r"(?<=[.!?…])\s+", text or ""):
        if cur and len(cur) + 1 + len(sentence) > size:
            pieces.append(cur.strip())
            cur = sentence
        else:
            cur = (cur + " " + sentence).strip() if cur else sentence

    if cur.strip():
        pieces.append(cur.strip())

    return pieces or ([text.strip()] if text.strip() else [])


_QUOTE_PAIRS = {
    "«": "»",
    '"': '"',
    "“": "”",
    "„": "“",
}


def _split_speech(text: str) -> list[dict]:
    """Абзац → куски kind=narrator|speech|plain. Кавычки и тире — речь.

    Без разметки возвращаем один plain: говорящего тогда решает модель,
    а не этот резак (иначе любая реплика без кавычек уедет рассказчику).
    """
    text = text or ""

    if not text.strip():
        return []

    dashed = _split_dash_speech(text)

    if dashed is not None:
        return dashed

    out: list[dict] = []
    buf: list[str] = []
    kind = "narrator"
    closer = None
    marked = False

    for ch in text:
        if closer is None and ch in _QUOTE_PAIRS:
            chunk = "".join(buf).strip()

            if chunk:
                out.append({"kind": kind, "text": chunk})

            buf = []
            kind = "speech"
            closer = _QUOTE_PAIRS[ch]
            marked = True
            continue

        if closer is not None and ch == closer:
            chunk = "".join(buf).strip()

            if chunk:
                out.append({"kind": "speech", "text": chunk})

            buf = []
            kind = "narrator"
            closer = None
            continue

        buf.append(ch)

    tail = "".join(buf).strip()

    if tail:
        out.append({"kind": kind, "text": tail})

    if not marked:
        cued = _split_cue_speech(text)

        if cued:
            return cued

        return [{"kind": "plain", "text": text.strip()}]

    return out or [{"kind": "plain", "text": text.strip()}]


def _split_dash_speech(text: str) -> list[dict] | None:
    """«— Здравствуй, — сказал он. — Как дела?» или None, если это не диалог."""
    raw = text or ""

    if not re.match(r"^[ \t]*[—–]\s+", raw):
        return None

    body = re.sub(r"^[ \t]*[—–]\s+", "", raw)
    bits = [b.strip() for b in re.split(r"\s+[—–]\s+", body) if b.strip()]
    out: list[dict] = []

    for i, bit in enumerate(bits):
        out.append({"kind": "speech" if i % 2 == 0 else "narrator", "text": bit})

    return out or None


def _chunks_of(window: str, start_n: int = 1) -> tuple[list[dict], int]:
    """Пронумерованные куски окна: исходный текст, который модель не переписывает."""
    n = max(1, int(start_n or 1))
    chunks: list[dict] = []
    parts = [p.strip() for p in re.split(r"\n\s*\n", window or "") if p.strip()]

    if not parts and (window or "").strip():
        parts = [window.strip()]

    prev_cue = False

    for part in parts:
        spans = _split_speech(part)

        # Сказка часто без кавычек: «говорит он дочерям:» / следующий абзац — речь.
        if prev_cue and len(spans) == 1 and spans[0].get("kind") == "plain":
            spans = [{"kind": "speech", "text": spans[0]["text"]}]

        for span in spans:
            text = (span.get("text") or "").strip()

            if not text:
                continue

            chunks.append({"n": n, "kind": span["kind"], "text": text})
            n += 1

        prev_cue = bool(spans) and _looks_like_cue(str(spans[-1].get("text") or ""))

    return chunks, n


def _format_chunks(chunks: list[dict]) -> str:
    """Нумерованный список кусков для подсказки модели."""
    labels = {"speech": "речь", "narrator": "автор", "plain": "текст"}
    lines = []

    for c in chunks:
        mark = labels.get(c.get("kind"), "текст")
        lines.append(f"[{c['n']} {mark}] {c['text']}")

    return "\n".join(lines) if lines else "(пусто)"


_SAY_RE = re.compile(
    r"(?:воз)?говор(?:ит|ил[аои]?|ят)|сказ(?:ал[аои]?|ывает)|"
    r"молв(?:ил[аои]?|ит)|восклик(?:нул[аои]?|ает)|отвеч(?:ал[аои]?|ает)|"
    r"спрос(?:ил[аои]?|ит)|промолв(?:ил[аои]?|ит)|произнес(?:ла|ли|ло|ёт|ет)?|"
    r"крикн(?:ул[аои]?|ет)|шепн(?:ул[аои]?|ет)|закрич(?:ал[аои]?|ит)|"
    r"проговор(?:ил[аои]?|ит)",
    re.I,
)

_CUE_SPLIT = re.compile(
    r"^(?P<head>.*(?:"
    r"(?:воз)?говор(?:ит|ил[аои]?|ят)|сказ(?:ал[аои]?|ывает)|"
    r"молв(?:ил[аои]?|ит)|восклик(?:нул[аои]?|ает)|отвеч(?:ал[аои]?|ает)|"
    r"спрос(?:ил[аои]?|ит)|промолв(?:ил[аои]?|ит)|произнес(?:ла|ли|ло|ёт|ет)?|"
    r"крикн(?:ул[аои]?|ет)|шепн(?:ул[аои]?|ет)|закрич(?:ал[аои]?|ит)|"
    r"проговор(?:ил[аои]?|ит)"
    r")[^\n:]{0,80})[:：]\s+(?P<tail>\S.+)$",
    re.I | re.S,
)

_NARRATOR_LABELS = frozenset({"narrator", "рассказчик", "автор", "author", "narration", "text", "текст"})


def _looks_like_cue(text: str) -> bool:
    """«…и говорит он своим дочерям:» — дальше прямая речь, часто без кавычек."""
    t = (text or "").strip()

    if not t.endswith(":") and not t.endswith("："):
        return False

    return bool(_SAY_RE.search(t.rstrip(":：").rstrip()))


def _split_cue_speech(text: str) -> list[dict] | None:
    """«говорит купец: Милые доченьки…» → автор + речь, даже без кавычек."""
    m = _CUE_SPLIT.match(text or "")

    if not m:
        return None

    head = (m.group("head") or "").strip()
    tail = (m.group("tail") or "").strip()

    if not head or len(tail) < 8:
        return None

    return [{"kind": "narrator", "text": head + ":"}, {"kind": "speech", "text": tail}]


def _speaker_field(row) -> object:
    if not isinstance(row, dict):
        return row

    for key in ("speaker", "who", "character", "name"):
        if row.get(key) not in (None, ""):
            return row[key]

    # {"n": 2, "id": 8} — id персонажа, не номер куска.
    if row.get("id") not in (None, "") and str(row.get("id")) != str(row.get("n") or ""):
        return row["id"]

    return None


def _assign_map(assign) -> dict[int, object]:
    """assign: список {n, speaker} или словарь {n: speaker}."""
    by_n: dict[int, object] = {}

    if isinstance(assign, dict):
        for key, value in assign.items():
            try:
                num = int(key)
            except (TypeError, ValueError):
                continue

            if num > 0 and num not in by_n:
                by_n[num] = _speaker_field(value) if isinstance(value, dict) else value

        return by_n

    if not isinstance(assign, list):
        return by_n

    for row in assign:
        if not isinstance(row, dict):
            continue

        try:
            num = int(row.get("n") or row.get("p") or 0)
        except (TypeError, ValueError):
            continue

        if num > 0 and num not in by_n:
            by_n[num] = _speaker_field(row)

    return by_n


def _index_characters(characters: list[dict]) -> tuple[dict[str, dict], dict[str, dict]]:
    """Имя → персонаж; уникальный токен (≥5 букв) → персонаж. Рассказчика нет."""
    by_name: dict[str, dict] = {}
    tokens: dict[str, list[dict]] = {}

    for row in characters or []:
        if str(row.get("kind") or "") == "narrator" or _is_narrator_name(str(row.get("name") or "")):
            continue

        name = _norm(str(row.get("name") or ""))

        if not name:
            continue

        by_name[name] = row

        for tok in name.split():
            if len(tok) >= 5:
                tokens.setdefault(tok, []).append(row)

    unique: dict[str, dict] = {}

    for tok, rows in tokens.items():
        ids = {str(r.get("id")) for r in rows}

        if len(ids) == 1:
            unique[tok] = rows[0]

    return by_name, unique


def _find_character_in_text(text: str, by_name: dict[str, dict],
                            unique_tokens: dict[str, dict]) -> dict | None:
    blob = _norm(text)

    if not blob:
        return None

    best = None
    best_len = 0

    for name, row in by_name.items():
        if name and name in blob and len(name) > best_len:
            best, best_len = row, len(name)

    if best is not None:
        return best

    best_tok = ""

    for tok, row in unique_tokens.items():
        if tok in blob and len(tok) > len(best_tok):
            best_tok = tok
            best = row

    return best


def _assigned_is_character(speaker) -> bool:
    if speaker is None:
        return False

    key = _norm(str(speaker))

    if not key or key in _NARRATOR_LABELS or _is_narrator_name(str(speaker)):
        return False

    return True


def _infer_turn_speakers(turns: list[dict], characters: list[dict],
                         seed=None) -> list[dict]:
    """Речь без говорящего — персонаж из ремарки, не narrator.

    Модель часто ставит narrator на все куски; сказка без кавычек тогда
    звучит одним голосом, а движок без voice сам выбирает случайный.
    """
    by_name, unique_tokens = _index_characters(characters)
    by_id = {str(c.get("id")): c for c in (characters or []) if c.get("id") not in (None, "")}
    last_attr = by_id.get(str(seed)) if seed not in (None, "") else None
    last_char = last_attr
    out: list[dict] = []

    for i, turn in enumerate(turns):
        kind = str(turn.get("kind") or "plain")
        text = str(turn.get("text") or "")
        speaker = turn.get("speaker")
        found = _find_character_in_text(text, by_name, unique_tokens)

        if kind != "speech" and i and _looks_like_cue(str(turns[i - 1].get("text") or "")):
            kind = "speech"

        if kind != "speech" and found is not None:
            last_attr = found

        if kind == "speech" and not _assigned_is_character(speaker):
            pick = last_attr or last_char

            if pick is None and out:
                blob = " ".join(str(p.get("text") or "") for p in out[-6:])
                pick = _find_character_in_text(blob, by_name, unique_tokens)

            if pick is not None:
                speaker = pick.get("id")

        item = dict(turn)
        item["speaker"] = speaker
        item["kind"] = kind
        out.append(item)

        if _assigned_is_character(item.get("speaker")):
            row = (by_id.get(str(item["speaker"]))
                   or _find_character_in_text(str(item["speaker"]), by_name, unique_tokens))

            if row is not None:
                last_char = row
        elif kind == "speech" and last_attr is not None:
            last_char = last_attr

    return out


def _split_quoted_turns(turns: list[dict]) -> list[dict]:
    """Запас: если модель всё же вернула текст, кавычки режем сами."""
    out: list[dict] = []

    for turn in turns:
        speaker = str(turn.get("speaker") or "narrator")
        text = str(turn.get("text") or "")
        para = turn.get("p")
        spans = _split_speech(text)

        if len(spans) <= 1 and (not spans or spans[0].get("kind") != "speech"):
            if text.strip():
                out.append(turn)
            continue

        for span in spans:
            chunk = (span.get("text") or "").strip()

            if not chunk:
                continue

            who = speaker if span.get("kind") == "speech" and speaker not in ("", "narrator") else "narrator"

            if out and str(out[-1].get("speaker")) == str(who):
                out[-1]["text"] = (out[-1]["text"] + " " + chunk).strip()
                continue

            row = {"speaker": who, "text": chunk}

            if para:
                row["p"] = para

            out.append(row)

    return out


def _turns_of_answer(data: dict, chunks: list[dict], characters: list[dict],
                     ref_map: dict[str, int], seed=None) -> list[dict]:
    """Собрать реплики: текст всегда из chunks, speaker — из assign/turns.

    Дыры (нет JSON, narrator на «речи», сказка без кавычек) закрывает
    _infer_turn_speakers: иначе вся книга уезжает рассказчику.
    """
    data = data or {}
    by_n = _assign_map(data.get("assign"))

    if not by_n:
        raw_turns = _as_list(data.get("turns"))

        if raw_turns and len(raw_turns) == len(chunks):
            for i, row in enumerate(raw_turns):
                by_n[int(chunks[i]["n"])] = row.get("speaker")
        else:
            for row in raw_turns:
                try:
                    num = int(row.get("n") or row.get("p") or 0)
                except (TypeError, ValueError):
                    num = 0

                if num > 0 and num not in by_n:
                    by_n[num] = row.get("speaker")

    fake_turns = []

    for chunk in chunks:
        speaker = by_n.get(int(chunk["n"]))

        if speaker is None:
            speaker = "narrator"

        fake_turns.append({
            "speaker": speaker,
            "text": chunk["text"],
            "p": chunk["n"],
            "kind": chunk.get("kind") or "plain",
        })

    return _resolve_speakers(_infer_turn_speakers(fake_turns, characters, seed=seed),
                             characters, ref_map)


def _characters_block(characters: list[dict], limit: int) -> str:
    """Список персонажей для подсказки: id, имя, пол/возраст, кто это."""
    rows = sorted(characters, key=lambda c: -int(c.get("mentions") or 0))[: max(1, limit)]
    lines = []

    for c in rows:
        cid = c.get("id")
        name = _clean(str(c.get("name") or ""), 60) or "без имени"
        mark = " [рассказчик]" if c.get("kind") == "narrator" else ""
        meta = "/".join(x for x in [str(c.get("gender") or ""), str(c.get("age") or "")] if x)
        desc = _clean(str(c.get("description") or ""), 120)

        # Пометку kind не клеим к имени: иначе модель заводит
        # «Рассказчик (рассказчик)» как нового персонажа (11.10.2026).
        lines.append(f"- id={cid}{mark}: {name}" + (f" [{meta}]" if meta else "")
                     + (f" — {desc}" if desc else ""))

    return "\n".join(lines) if lines else "пока никого"


def _voices_block(voices: list[dict], limit: int) -> str:
    """Каталог голосов — коротко, иначе съест весь контекст."""
    rows = voices[: max(1, limit)]
    lines = []

    for v in voices and rows:
        vid = _clean(str(v.get("id") or v.get("name") or ""), 40)
        meta = "/".join(x for x in [str(v.get("gender") or ""), str(v.get("age") or "")] if x)
        desc = _clean(str(v.get("description") or ""), 90)

        if not vid:
            continue

        lines.append(f"- {vid}" + (f" [{meta}]" if meta else "")
                     + (f": {desc}" if desc else ""))

    return "\n".join(lines) if lines else "список голосов недоступен — поле voice оставь пустым"


def _tokens_of_context(default: int = 16000) -> int:
    """Примерный контекст модели: NJ666_CONTEXT (по умолчанию 16000)."""
    try:
        return max(2000, int((os.environ.get("NJ666_CONTEXT") or "").strip() or default))
    except ValueError:
        return default


def _service_model(fallback: str | None = None) -> str | None:
    own = (os.environ.get("NJ666_MODEL") or "").strip()

    if own:
        return own

    return (str(fallback).strip() or None) if fallback else None


def _search_enabled() -> bool:
    return (os.environ.get("NJ666_SEARCH", "1") or "1").strip().lower() not in (
        "0", "false", "no", "off")


def _search_timeout() -> float:
    try:
        return max(5.0, float((os.environ.get("NJ666_SEARCH_TIMEOUT") or "45").strip()))
    except ValueError:
        return 45.0


# ==========================================================================
# Связь с сайтом
# ==========================================================================

async def _api(ctx, path: str, data: dict, timeout: float = 180.0) -> dict:
    ok, reason, res = await site.post_json(
        _PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(_PREFIX, API_PATH))

    if not ok:
        raise RuntimeError(f"nj666: сайт {path} → {reason}")

    return dict(res or {})


async def _api_soft(ctx, path: str, data: dict, timeout: float = 180.0,
                    retries: int = 2) -> dict:
    ok, reason, res = await site.post_json(
        _PREFIX, path, data, json_body=True, timeout=timeout, retries=retries,
        base=site.module_api_base(_PREFIX, API_PATH))

    body = dict(res or {})

    if not ok and not str(body.get("error") or "").strip():
        body["error"] = reason

    body.setdefault("ok", ok)

    return body


async def _refuse(ctx, book_id: int, error: str) -> None:
    """Книга в «не получилось», задача — fail. Иначе очередь «готово», страница «ждите».

    11.10.2026: nj666.plan закрывался {stopped:True}, если /context не ok
    (_api_soft не бросает; HTTP 404 ретраится ~2 с). Монитор зелёный, книга
    на «ждите: книга уйдёт в воркер». /stage и /blocks не вызывались.
    """
    error = _clean(str(error or ""), 300) or "сайт не отдал контекст книги"
    log.warning("nj666: книга %s отказана: %s", book_id, error)

    try:
        await _api(ctx, "/fail", {"book_id": book_id, "error": error})
    except Exception as e:  # noqa: BLE001 — задача всё равно упадёт ниже
        log.warning("nj666: /fail книги %s не принят: %s", book_id, e)

    raise RuntimeError(f"nj666: {error}")


async def _stage(ctx, book_id: int, stage: str, note: str = "", progress: int | None = None,
                 timeout: float = 60.0) -> None:
    try:
        await ctx.progress(stage, note)
    except Exception:  # noqa: BLE001 — журнал не важнее разбора
        pass

    try:
        await _api_soft(ctx, "/stage", {"book_id": book_id, "stage": stage,
                                        "note": note, "progress": progress}, timeout=timeout)
    except Exception:  # noqa: BLE001
        pass


async def _ask(ctx, messages: list[dict], model: str | None, max_tokens: int = 4000,
               timeout: int = 300) -> str:
    if ctx.llm is None:
        raise RuntimeError("LLM-шлюз недоступен: проверьте провайдера в workers/.env")

    text, _usage = await ctx.llm.chat_with_usage(
        messages, model=model or None, max_tokens=max_tokens, request_timeout=timeout)

    return _clean(text)


# ==========================================================================
# «Утка го»: персонажи известной книги из интернета
# ==========================================================================

async def _search_characters(ctx, context: dict, model: str | None) -> int:
    """Найти книгу в интернете и заранее взять список персонажей.

    Возвращает сколько персонажей добавили. Провал поиска не роняет разбор:
    тогда персонажей найдёт модель по ходу текста (как в режиме auto).
    """
    if not _search_enabled():
        return 0

    title = _clean(str(context.get("title") or ""), 120)
    author = _clean(str(context.get("author") or ""), 80)

    if not title:
        return 0

    book_id = int(context.get("book_id") or 0)
    query = f"{title} {author} персонажи".strip()

    try:
        raw = await _ask(ctx, [
            {"role": "system", "content": SEARCH_QUERY_SYSTEM},
            {"role": "user", "content": f"Книга: {title}. Автор: {author or 'не указан'}.\n"
                                        "Напиши поисковый запрос."},
        ], _service_model(model), max_tokens=80, timeout=120)
    except Exception as e:  # noqa: BLE001 — без запроса идём на запасной
        log.warning("nj666: запрос для поиска не вышел: %s", e)
        raw = ""

    if raw:
        query = _clean(raw.replace("\n", " "), 160) or query

    await _stage(ctx, book_id, "«Утка го» ищет книгу", query[:120])

    try:
        found = await webread.ddg_text(query, max_results=4, timeout_sec=_search_timeout())
    except Exception as e:  # noqa: BLE001 — поисковик не критичен
        log.warning("nj666: поиск не удался: %s", e)
        return 0

    pages: list[str] = []

    # Выдачи поиска самой по себе мало: в сниппетах обычно перечислены не те
    # люди. Читаем сами страницы — оттуда модель вытащит состав действующих
    # лиц с полом, возрастом и характером.
    for item in list(found or [])[:3]:
        url = str((item or {}).get("href") or (item or {}).get("url") or "").strip()

        if not url:
            continue

        try:
            html, title = await webread.fetch(url)
            text, _ = await webread.to_markdown(html, cap=6000)
        except Exception as e:  # noqa: BLE001
            log.info("nj666: страница %s не прочитана: %s", url[:60], e)
            continue

        # Сниппет выдачи — как подспорье, если страница отдала мало текста.
        body = _clean(str((item or {}).get("body") or ""), 400)

        if text:
            pages.append(f"{title or url}\n{_clean(text, 2500)}")
        elif body:
            pages.append(f"{title or url}\n{body}")

    if not pages:
        return 0

    try:
        raw = await _ask(ctx, [
            {"role": "system", "content": SEARCH_SYSTEM},
            {"role": "user", "content": f"Книга: {title}. Автор: {author or 'не указан'}.\n\n"
                                        + "\n\n---\n\n".join(pages)},
        ], _service_model(model), max_tokens=1500, timeout=300)
    except Exception as e:  # noqa: BLE001
        log.warning("nj666: модель не составила список персонажей: %s", e)
        return 0

    try:
        data = _json_of(raw)
    except Exception as e:  # noqa: BLE001
        log.warning("nj666: список персонажей не разобран: %s", e)
        return 0

    rows = []

    for item in _as_list(data.get("characters")):
        name = _clean_character_name(str(item.get("name") or ""))

        if not name or _is_narrator_name(name):
            continue

        rows.append({
            "ref": f"s{len(rows) + 1}",
            "name": name,
            "gender": _clean(str(item.get("gender") or ""), 16),
            "age": _clean(str(item.get("age") or ""), 16),
            "description": _clean(str(item.get("description") or ""), 300),
            "locked": 0,
        })

    if not rows:
        return 0

    res = await _api(ctx, "/characters", {"book_id": book_id, "characters": rows})
    added = int(res.get("added") or 0)

    await _stage(ctx, book_id, "Персонажи найдены в интернете",
                 f"добавлено {added} из {len(rows)}", progress=5)

    return added


# ==========================================================================
# nj666.plan
# ==========================================================================

async def handle(ctx, payload: dict) -> dict:
    book_id = int(payload.get("book_id") or 0)

    if book_id <= 0:
        raise RuntimeError("nj666: в задаче нет book_id")

    started = time.monotonic()

    context = await _api_soft(ctx, "/context", {"book_id": book_id})

    if not context.get("ok"):
        await _refuse(ctx, book_id, str(context.get("error") or "сайт не отдал контекст книги"))

    text = str(context.get("text") or "")
    mode = str(context.get("mode") or "auto")
    model = str(context.get("model") or "") or None
    plan = dict(context.get("plan") or {})
    limits = dict(context.get("limits") or {})
    targets = dict(context.get("targets") or {})

    min_chars = int(targets.get("min") or 7000)
    max_chars = int(targets.get("max") or 15000)
    single = bool(context.get("single_block"))
    window_chars = int(plan.get("window_chars") or 6000)

    characters = list(context.get("characters") or [])
    voices = list(context.get("voices") or [])

    # Контекст у модели маленький (владелец: «например 16000»), поэтому окно
    # текста ужимаем под него: текст + персонажи + голоса + правила должны
    # влезть целиком, иначе модель «забудет» начало списка персонажей и
    # начнёт плодить двойников.
    tokens = _tokens_of_context()
    if tokens < 20000:
        window_chars = min(window_chars, max(1500, int(tokens * 1.6)))

    if not text.strip():
        await _refuse(ctx, book_id, "текст книги пуст")

    await _stage(ctx, book_id, "Готовлю разбор", f"{len(text)} знаков", progress=2)

    if mode == "search" and len(characters) <= 1:
        try:
            await _search_characters(ctx, context, model)
        except Exception as e:  # noqa: BLE001
            log.warning("nj666: «утка го» не сработала: %s", e)

        # Список персонажей обновился — берём свежий.
        fresh = await _api_soft(ctx, "/context", {"book_id": book_id})

        if fresh.get("ok"):
            characters = list(fresh.get("characters") or [])

    windows = _windows(text, window_chars)

    if not windows:
        await _refuse(ctx, book_id, "текст не удалось разбить на окна")

    # Реплики текущего блока и его длина.
    pending: list[dict] = []
    pending_chars = 0
    blocks: list[dict] = []
    posted = 0
    ref_map: dict[str, int] = {}
    voices_lines = _voices_block(voices, int(plan.get("list_voices") or 24))
    next_n = 1
    last_tail = ""
    seen_chars = 0
    seed_speaker = None

    for i, window in enumerate(windows, start=1):
        await _stage(ctx, book_id, f"Разбираю фрагмент {i} из {len(windows)}",
                     f"персонажей: {len(characters)}, блоков: {len(blocks)}",
                     progress=min(95, 5 + int(i * 90 / len(windows))))

        chunks, next_n = _chunks_of(window, next_n)
        memory = (f"Предыдущий конец (уже размечен, НЕ повторяй):\n«{last_tail}»\n\n"
                  if last_tail else "")
        user = (
            f"Книга: {_clean(str(context.get('title') or ''), 80)}\n"
            f"Фрагмент {i} из {len(windows)}, знаки {seen_chars + 1}–"
            f"{seen_chars + len(window)} из {len(text)}. "
            f"Куски [{chunks[0]['n'] if chunks else '?'}…"
            f"{chunks[-1]['n'] if chunks else '?'}].\n\n"
            f"{memory}"
            f"ИЗВЕСТНЫЕ ПЕРСОНАЖИ (используй их id):\n"
            f"{_characters_block(characters, int(plan.get('list_chars') or 40))}\n\n"
            f"ДОСТУПНЫЕ ГОЛОСА (для новых персонажей бери id отсюда):\n{voices_lines}\n\n"
            f"КУСКИ (верни assign с этими n, текст не копируй):\n{_format_chunks(chunks)}"
        )

        data: dict = {}
        raw = ""

        try:
            raw = await _ask(ctx, [
                {"role": "system", "content": PLAN_SYSTEM},
                {"role": "user", "content": user},
            ], _service_model(model), max_tokens=4000, timeout=600)
        except Exception as e:  # noqa: BLE001
            log.warning("nj666: окно %d не разобрано: %s", i, e)

        if raw:
            try:
                data = _json_of(raw)
            except Exception as e:  # noqa: BLE001
                log.warning("nj666: ответ окна %d не разобран: %s", i, e)
                data = {}

        new_chars = [c for c in (_prepare_new_character(x)
                                 for x in _as_list(data.get("characters")))
                     if c]

        if new_chars:
            try:
                res = await _api(ctx, "/characters",
                                 {"book_id": book_id, "characters": new_chars})
                # Обновляем локальный список: дальше модель должна видеть
                # свежие id, иначе будет создавать тех же людей заново.
                ref_map.update({str(k): int(v) for k, v in (res.get("map") or {}).items()
                                if str(v).isdigit()})

                for row in new_chars:
                    cid = (res.get("map") or {}).get(str(row.get("ref") or row.get("name") or ""))

                    if cid and not any(str(c.get("id")) == str(cid) for c in characters):
                        characters.append({
                            "id": cid,
                            "name": _clean(str(row.get("name") or ""), 80),
                            "kind": "character",
                            "gender": _clean(str(row.get("gender") or ""), 16),
                            "age": _clean(str(row.get("age") or ""), 16),
                            "description": _clean(str(row.get("description") or ""), 400),
                            "mentions": 1,
                            "locked": False,
                        })
            except Exception as e:  # noqa: BLE001
                log.warning("nj666: персонажи окна %d не приняты: %s", i, e)

        # Текст берём из кусков исходника, не из модели: иначе «начало»
        # всплывает во втором блоке, а кавычки слипаются с автором.
        resolved = _turns_of_answer(data, chunks, characters, ref_map, seed=seed_speaker)

        for turn in resolved:
            pending.append(turn)
            pending_chars += len(str(turn.get("text") or ""))

            if turn.get("speaker") not in (None, "", "narrator"):
                seed_speaker = turn.get("speaker")

        last_tail = _clean(window, 240)
        seen_chars += len(window)

        good_end = bool(data.get("good_end"))

        # Закрываем блок: набрали минимум и модель сказала «тут можно
        # остановиться», либо уперлись в максимум — тогда закрываем как есть,
        # иначе блок распухнет и его неудобно слушать.
        while not single and pending and (
                pending_chars >= max_chars
                or (pending_chars >= min_chars and good_end)):
            blocks.append(_close_block(pending, pending_chars, len(blocks) + 1))
            pending, pending_chars = [], 0
            good_end = False

            if pending_chars < min_chars:
                break

        # Сайт держим в курсе порциями: страница видит блоки по ходу, а не в
        # конце часа работы.
        if len(blocks) - posted >= 5:
            await _post_blocks(ctx, book_id, blocks[posted:])
            posted = len(blocks)

    # Хвост.
    if pending:
        blocks.append(_close_block(pending, pending_chars, len(blocks) + 1))

    if blocks[posted:]:
        await _post_blocks(ctx, book_id, blocks[posted:])

    await _api(ctx, "/blocks", {"book_id": book_id, "blocks": [], "final": True})
    await _stage(ctx, book_id, "Книга разобрана",
                 f"блоков: {len(blocks)}, персонажей: {len(characters)}", progress=100)

    return {
        "book_id": book_id,
        "blocks": len(blocks),
        "characters": len(characters),
        "windows": len(windows),
        "seconds": round(time.monotonic() - started, 1),
    }


def _close_block(turns: list[dict], chars: int, no: int) -> dict:
    title = ""

    for turn in turns[:3]:
        text = _clean(str(turn.get("text") or ""), 80)

        if text:
            title = text
            break

    return {
        "no": no,
        "title": _clean(re.sub(r"\s+", " ", title), 60),
        "chars": int(chars or 0),
        "turns": turns,
    }


async def _post_blocks(ctx, book_id: int, blocks: list[dict]) -> None:
    if not blocks:
        return

    await _api(ctx, "/blocks", {"book_id": book_id, "blocks": blocks})


def _resolve_speakers(turns: list[dict], characters: list[dict],
                      ref_map: dict[str, int] | None = None) -> list[dict]:
    """Говорящий реплики → id персонажа или 'narrator'.

    Модель может вернуть id (число), ref нового персонажа («n1») или имя.
    ref → id приходит от сайта в ответе /characters (карта map): БЕЗ этой
    подстановки все реплики новых персонажей ушли бы рассказчику, и вся
    многоголосая затея превратилась бы в монотонное чтение.
    Имя ищем по нормализованному виду — последний рубеж против двойников:
    назвала модель персонажа словами — всё равно найдём его по имени.
    """
    ref_map = ref_map or {}
    by_id: dict[str, dict] = {}
    by_name: dict[str, dict] = {}
    fuzzy_names, fuzzy_tok = _index_characters(characters)

    for c in characters:
        by_id[str(c.get("id"))] = c
        name = _norm(str(c.get("name") or ""))

        if name:
            by_name[name] = c

    out: list[dict] = []

    for turn in turns:
        text = _clean(str(turn.get("text") or ""))

        if not text:
            continue

        speaker = turn.get("speaker")
        row = None
        raw_speaker = "" if speaker is None else str(speaker).strip()

        if (raw_speaker == "" or _is_narrator_name(raw_speaker)
                or _norm(raw_speaker) in _NARRATOR_LABELS):
            row = None
        else:
            key = str(ref_map.get(raw_speaker, raw_speaker))
            row = by_id.get(key) or by_name.get(_norm(key))

            if row is None:
                row = _find_character_in_text(key, fuzzy_names, fuzzy_tok)

            if row and (str(row.get("kind") or "") == "narrator"
                        or _is_narrator_name(str(row.get("name") or ""))):
                row = None

        item = {
            "speaker": str(row.get("id")) if row else "narrator",
            "text": text,
        }

        if turn.get("p"):
            try:
                item["p"] = int(turn["p"])
            except (TypeError, ValueError):
                pass

        out.append(item)

    return out


_CLONE_PAREN = re.compile(
    r"\s*\((?=[^)]*(?:голос|voice|нов(?:ая|ый|ое)|new))[^)]*\)\s*$",
    re.I,
)
_CLONE_TAIL = re.compile(
    r"(?:\s+голос(?:\s+\d+)?(?:\s+нов(?:ая|ый|ое))?|\s+voice(?:\s+\d+)?(?:\s+new)?)$",
    re.I,
)


def _clean_character_name(name: str) -> str:
    """«Принц (голос 2, новая)» → «Принц». Голос — не часть имени."""
    name = _clean(str(name or ""), 80)
    prev = None

    while name != prev:
        prev = name
        name = _CLONE_PAREN.sub("", name).strip()

    return name


def _norm(name: str) -> str:
    name = _clean_character_name(name)
    name = (name or "").lower().replace("ё", "е")
    name = re.sub(r"[^a-zа-я0-9 ]+", " ", name)
    name = re.sub(r"\s+", " ", name).strip()
    name = _CLONE_TAIL.sub("", name).strip()

    return name


def _prepare_new_character(row) -> dict | None:
    if not isinstance(row, dict):
        return None

    name = _clean_character_name(str(row.get("name") or ""))

    if not name or _is_narrator_name(name):
        return None

    out = dict(row)
    out["name"] = name

    return out


def _is_narrator_name(name: str) -> bool:
    """«Рассказчик», «Рассказчик (голос 1)», narrator — не персонаж."""
    key = _norm(name)

    if not key:
        return False

    if key in ("narrator", "рассказчик"):
        return True

    return key.startswith("рассказчик ") or key.startswith("narrator ")


# ==========================================================================
# nj666.voice
# ==========================================================================

async def handle_voice(ctx, payload: dict) -> dict:
    """Озвучить блоки книги: воркер синтезирует, сайт кладёт файл."""
    book_id = int(payload.get("book_id") or 0)

    if book_id <= 0:
        raise RuntimeError("nj666: в задаче нет book_id")

    started = time.monotonic()
    context = await _api_soft(ctx, "/context", {"book_id": book_id})

    if not context.get("ok"):
        await _refuse(ctx, book_id, str(context.get("error") or "сайт не отдал контекст книги"))

    ids = [int(x) for x in (payload.get("block_ids") or []) if str(x).isdigit()]

    if not ids:
        ids = [int(b.get("id")) for b in (context.get("blocks") or []) if b.get("id")]

    if not ids:
        await _refuse(ctx, book_id, "блоков нет: сначала разберите книгу")

    if not omnivoice.enabled():
        await _refuse(ctx, book_id,
                      "синтез не настроен: NJ665_API_URL и NJ665_API_KEY в workers/.env")

    done = 0
    failed = 0

    for i, block_id in enumerate(ids, start=1):
        await _stage(ctx, book_id, f"Озвучиваю блок {i} из {len(ids)}",
                     f"готово: {done}", progress=min(99, int(i * 100 / max(1, len(ids)))))

        res = await _voice_one(ctx, book_id, int(block_id),
                               index=i, total=len(ids), already=done)

        if res.get("ok"):
            done += 1
        else:
            failed += 1
            log.warning("nj666: блок %s не озвучен: %s", block_id, res.get("error"))

    if failed and not done:
        await _api(ctx, "/fail", {"book_id": book_id,
                                  "error": f"ни один блок не озвучен ({failed} из {len(ids)})"})
    else:
        await _api(ctx, "/done", {"book_id": book_id, "status": "done",
                                  "stage": "Книга озвучена",
                                  "note": f"блоков: {done}"
                                          + (f", не удалось: {failed}" if failed else "")})

    return {
        "book_id": book_id,
        "blocks": len(ids),
        "voiced": done,
        "failed": failed,
        "seconds": round(time.monotonic() - started, 1),
    }


async def _voice_one(ctx, book_id: int, block_id: int, *,
                     index: int = 1, total: int = 1, already: int = 0) -> dict:
    """Один блок: /block → OmniVoice по кускам → /audio."""
    spec = await _api_soft(ctx, "/block",
                           {"book_id": book_id, "block_id": block_id}, timeout=60)

    if not spec.get("ok"):
        return spec

    chunks = [c for c in (spec.get("chunks") or []) if c]

    if not chunks:
        err = str(spec.get("error") or "В блоке нет реплик.")
        await _api_soft(ctx, "/audio",
                        {"book_id": book_id, "block_id": block_id, "error": err})
        return {"ok": False, "error": err}

    n = len(chunks)
    chars = 0
    fmt = str(spec.get("format") or "mp3") or "mp3"
    wait = omnivoice.timeout()

    for i, chunk in enumerate(chunks):
        if n > 1:
            await _stage(ctx, book_id, f"Озвучиваю блок {index} из {total}",
                         f"готово: {already}, кусок {i + 1} из {n}",
                         progress=min(99, int(index * 100 / max(1, total))))

        res = await omnivoice.synth(
            turns=chunk,
            model=str(spec.get("model") or ""),
            voice=str(spec.get("voice") or ""),
            format=fmt,
            speed=float(spec.get("speed") or 1.0),
            language=str(spec.get("language") or ""),
            timeout_sec=wait,
        )

        if not res.get("ok"):
            err = str(res.get("error") or "синтез не удался")
            await _api_soft(ctx, "/audio",
                            {"book_id": book_id, "block_id": block_id, "error": err})
            return {"ok": False, "error": err}

        raw = res.get("bytes") or b""
        if isinstance(raw, str):
            raw = raw.encode("latin-1", "replace")
        if not raw:
            err = "Синтез вернул пустой звук."
            await _api_soft(ctx, "/audio",
                            {"book_id": book_id, "block_id": block_id, "error": err})
            return {"ok": False, "error": err}

        chars += int(res.get("chars") or 0)
        fmt = str(res.get("format") or fmt) or fmt

        # Один заход: повтор последнего куска после склейки собрал бы главу
        # без предыдущих part-файлов (их уже стерли).
        posted = await _api_soft(ctx, "/audio", {
            "book_id": book_id,
            "block_id": block_id,
            "part": i,
            "total": n,
            "format": fmt,
            "chars": chars,
            "audio": base64.b64encode(raw).decode("ascii"),
        }, timeout=120, retries=1)

        if not posted.get("ok"):
            return posted

    return {"ok": True, "chars": chars, "parts": n}
