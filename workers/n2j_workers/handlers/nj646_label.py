"""nj646.label и nj646.study — «Разбор состава» (nj646_label).

Два канала одного сервиса:

* nj646.label — фото этикетки → текст состава.
  Сайт сжимает фото, кладёт кадр файлом в приватный каталог и ставит задачу
  {run_id, photo, media_type, kind}; воркер забирает кадр с сайта отдельным
  запросом /label/api/photo_file (X-Worker-Token). Старые задачи с image_b64
  в payload поддерживаются: ветка с файлом просто не выполняется;
  воркер спрашивает vision-модель и возвращает ЧЕСТНЫЙ текст состава
  (и подсказку «еда/косметика»), не додумывая ингредиенты.

* nj646.study — незнакомое вещество → карточка знаний.
  Задача {run_id, unknown_id, name, kind}; воркер просит модель вернуть
  строгий JSON (риск 0..4, категория, пояснение, синонимы, коды риска) —
  карточка уходит на сайт черновиком и появляется в базе после модерации.

Результат POST-ится на /label/api/worker_result (X-Worker-Token). Если сайт
не принял — результат всё равно возвращается в job.result: сайт догоняет
через reconcile (условие — статус прогона в nj_646_runs).

Env (читать ТОЛЬКО внутри функций, на уровне модуля нельзя):
  NJ646_VISION_MODEL — спека vision-модели («провайдер::модель» | «модель»)
  NJ646_MODEL        — модель изучения вещества (пусто — дефолт провайдера)
  NJ646_MAX_TOKENS   — потолок ответа (по умолчанию 1600)
  NJ646_LLM_TIMEOUT  — сколько секунд ждать модель (по умолчанию 240; должно
                       быть МЕНЬШЕ timeout_sec задачи, иначе результат не успеет
                       уехать на сайт — владелец видел вечное «Распознаю…»)
  NJ646_API_BASE     — https://<сайт>/label/api (фолбэк NJ120_API_BASE)
  NJ646_SITE_TOKEN   — токен сайта (фолбэк WORKER_SITE_TOKEN/WORKER_API_TOKEN)

Разбор ответа модели. Разбирает общий webread.json_from_text: он чинит обрыв
по max_tokens (finish_reason=length), переносы строк внутри строки JSON,
markdown-обёртку и хвостовую прозу после объекта. Свой наивный json.loads тут
уже падал на живых этикетках («не удалось разобрать ответ модели: {"composition":
"Вода, Экстракт масличного жожоба, …» — состав из десятка экстрактов длинный,
модель обрывалась на нём). Если JSON всё равно битый, но текст состава виден —
его вынимает _salvage_composition, а в result.note уезжает честная пометка об
обрыве: страница показывает её рядом с «Текст распознан», владелец проверяет
конец списка (там же подсказка поднять NJ646_MAX_TOKENS).

ВАЖНО про доставку. Страница /label опрашивает прогон в nj_646_runs, а тот
заполняется ТОЛЬКО приёмкой сайта (/label/api/worker_result). Если токен не
задан/не совпал или сеть не дошла, прогон остаётся «queued» и владелец видит
вечное «Распознаю этикетку…». Поэтому каждый этап после ответа модели виден в
мониторе (/nj120/monitor) как ctx.progress, а сайт умеет «дожимать» результат
из nj_120_jobs.result (Service_Nj646_Store::run_catchup) — но причина отказа
доставки обязана быть в журнале воркера, а не только в нём.
"""

from __future__ import annotations

import asyncio
import base64
import logging
import os
import re
import time

from ..base import HandlerContext
from .. import site, vlm, webread

log = logging.getLogger("n2j.nj646")

CHANNEL = "nj646.label"
CHANNEL_STUDY = "nj646.study"

# Ширина, до которой ужимаем большие фото. Мелкие НЕ увеличиваем: владелец
# (03.10.2026): «Фото к модели 384×520 → 768×1040 — ты зачем увеличиваешь, чтобы
# потратить больше токенов?» — апскейл деталей не создаёт, а патчей vision ест
# вчетверо больше; честнее сказать в разборе, что фото мелкое (см.
# _small_photo_warning), чем раздувать кадр.
# Кадр для модели: NJ646_PHOTO_MAX_PX → VLM_PHOTO_MAX_PX → 1400.
IMAGE_MAX_W = vlm.photo_max_w("NJ646")


# --------------------------------------------------------------------------
# Env
# --------------------------------------------------------------------------

def _vision_model() -> str:
    """Своя модель сервиса → соседний nj639 → общая VLM_MODEL → дефолт провайдера.

    Резолвер общий (`vlm.model`): одной строки VLM_MODEL в workers/.env должно
    хватать на все vision-сервисы, иначе каждый новый приходится настраивать
    отдельно и половина из них молча уезжает на чужую модель.
    """
    return vlm.model("NJ646", "NJ639")


def _study_model() -> str:
    return os.getenv("NJ646_MODEL", "").strip()


def _max_tokens() -> int:
    try:
        return max(200, min(4000, int(os.getenv("NJ646_MAX_TOKENS", "1600"))))
    except ValueError:
        return 1600


def _transcribe_enabled() -> bool:
    """Двухпроходный режим: сначала строки этикетки, потом состав из них.

    Включён по умолчанию: на мелком шрифте модель, которую просят сразу собрать
    состав, додумывает ингредиенты (владелец видел «Экстракт корня туи, хмеля,
    чеснока…», которых на этикетке нет). Переписывание строк — задача, в которой
    та же модель ошибается заметно реже, а состав собирается уже из её текста
    (и, если возможно, локально: _composition_from_lines_local).

    Выключается `NJ646_TRANSCRIBE=0` — на случай, когда важнее один запрос к
    модели (быстрый ответ, слабый текст-модели нет).
    """
    return os.getenv("NJ646_TRANSCRIBE", "1").strip().lower() not in ("0", "no", "false", "off")


def _transcribe_timeout() -> float:
    """Предел ожидания OCR-прохода, секунд.

    Меньше общего: после переписывания строк ещё нужно собрать состав (текстовый
    запрос), при необходимости — дописать оборванный список, и всё вместе должно
    уложиться в timeout_sec задачи (360 с на фото).
    """
    return max(60.0, min(180.0, _llm_timeout()))


def _llm_timeout() -> float:
    """Предел ожидания модели, секунд.

    Должен быть МЕНЬШЕ timeout_sec задачи (сайт ставит её из /label/api/photo:
    360 с на фото, 300 с на изучение) — иначе воркер снимут по таймауту в
    момент ответа модели, и на сайт не уедет ни состав, ни внятная ошибка.
    """
    try:
        return max(60.0, min(1800.0, float(os.getenv("NJ646_LLM_TIMEOUT", "240"))))
    except ValueError:
        return 240.0


# --------------------------------------------------------------------------
# Изображения: мягкое сжатие через Pillow (как в nj639), без падения
# --------------------------------------------------------------------------

def _prepare_sync(raw: bytes, max_w: int) -> tuple[bytes, dict]:
    """Привести фото к «читаемому для модели» размеру — только УМЕНЬШАЯ.

    Большие фото ужимаем до max_w по ширине (трафик, время и токены модели).
    Мелкие НЕ увеличиваем: владелец (03.10.2026): «Фото к модели 384×520 →
    768×1040 — ты зачем увеличиваешь, чтобы потратить больше токенов?» —
    апскейл новых деталей не создаёт, а платных патчей vision ест вчетверо
    больше. Если кадр мелкий, об этом честно скажет `_small_photo_warning`:
    переснять блок «Состав» крупно — единственное, что реально помогает.

    Возвращает (jpeg, meta): meta нужна, чтобы сказать владельцу, каким фото
    на самом деле работала модель, — «400×600» в разборе объясняет выдумки
    лучше любых догадок (мелкий шрифт этикетки в такой кадр не влезает).
    """
    meta: dict = {"src": "", "out": ""}

    try:
        from PIL import Image
        import io
        img = Image.open(io.BytesIO(raw)).convert("RGB")
        w, h = img.size
        meta["src"] = f"{w}×{h}"

        if w > max_w:
            img = img.resize((max_w, max(1, round(h * max_w / w))), Image.LANCZOS)
            w, h = img.size

        meta["out"] = f"{w}×{h}"
        out = io.BytesIO()
        img.save(out, "JPEG", quality=88, optimize=True)
        return out.getvalue(), meta
    except Exception:  # noqa: BLE001 — нет Pillow или битое фото: шлём как есть
        return raw, meta


async def _prepare_b64(b64: str) -> tuple[str, dict]:
    """Фото к модели: (base64, meta). Пустой base64 — фото не разобралось."""
    try:
        raw = base64.b64decode(b64)
    except Exception:  # noqa: BLE001
        return "", {}
    if not raw:
        return "", {}

    out, meta = await asyncio.to_thread(_prepare_sync, raw, IMAGE_MAX_W)

    return base64.b64encode(out).decode(), meta


def _small_photo_warning(meta: dict) -> str:
    """Пометка «фото мелкое» — или пустая строка.

    Порог 800 px по большей стороне: в такой кадр блок «Состав» на этикетке
    влезает парой десятков пикселей по высоте, и модель читает не буквы, а
    угадывает слова (владельцу прилетали «Экстракт корня туи, хмеля, чеснока…»,
    которых на этикетке нет). Скажем об этом прямо: сфотографировать блок
    состава крупнее — единственное, что реально помогает.
    """
    try:
        w, h = (int(x) for x in str(meta.get("src") or "").split("×"))
    except ValueError:
        return ""

    if max(w, h) >= 800:
        return ""

    return (f"фото мелкое ({w}×{h}) — мелкий шрифт состава в такой кадр не влезает, "
            f"модель догадывается; сфотографируйте блок «Состав» крупнее")


def _img_msg(b64: str, text: str, media_type: str = "image/jpeg") -> dict:
    mime = media_type if media_type.startswith("image/") else "image/jpeg"
    return {"role": "user", "content": [
        {"type": "text", "text": text},
        {"type": "image_url", "image_url": {"url": f"data:{mime};base64,{b64}"}},
    ]}


# --------------------------------------------------------------------------
# Промпты
# --------------------------------------------------------------------------

LABEL_SYSTEM = (
    "Ты — OCR-ассистент на упаковке. Прочитай ФОТО этикетки и выпиши состав "
    "ДОСЛОВНО, как напечатано: ингредиенты через запятую, без переводов и "
    "добавлений. Не придумывай позиции, которых не видно.\n"
    "ЯЗЫК ОТВЕТА — ЯЗЫК ЭТИКЕТКИ. Русская надпись остаётся русской и "
    "КИРИЛЛИЦЕЙ: «Вода» — это «Вода», а не «Water» и не «Voda». Перевод на "
    "английский и транслитерация — ГРУБАЯ ОШИБКА: человек сверяет список с "
    "упаковкой глазами, а по базе веществ ищут по написанию с этикетки. Если "
    "на этикетке два языка, бери русский блок; поле note тоже пиши по-русски.\n"
    "Правила, на которых модель спотыкается чаще всего:\n"
    "1) Переписывай ТОЛЬКО читаемые на фото строки. Неразборчивое слово — "
    "пропусти и напиши об этом в note, но НЕ заменяй похожим по смыслу и не "
    "досочиняй список «по аналогии» (в косметике это обычно экстракты — их и "
    "тянет выдумать).\n"
    "2) Каждую позицию пиши РОВНО ОДИН РАЗ, в порядке этикетки: повтор одного "
    "и того же ингредиента — ошибка.\n"
    "3) Список кончился — сразу закрывай JSON: не продолжай по инерции, не "
    "повторяй уже написанное, не дописывай «и т. д.».\n"
    "4) Если состав не читается (мелкий шрифт, блик, смазано) — верни пустой "
    "composition и напиши причину в note. Пустой ответ честнее выдуманного: "
    "тебе заплатят вниманием, а не длиной списка.\n"
    "Верни ТОЛЬКО JSON без markdown:\n"
    '{"composition": "текст состава одной строкой", '
    '"kind": "food|cosmetic|unknown", '
    '"confidence": 0-100, '
    '"note": "что мешало прочитать (блик, мелкий шрифт) — или пустая строка"}'
)

STUDY_SYSTEM = (
    "Ты — справочник по ингредиентам еды и косметики. Дай осторожную, "
    "проверяемую карточку вещества по его названию. Опирайся на EFSA, FDA, "
    "ВОЗ, SCCS, CIR, IARC; если данных нет — так и скажи и ставь confidence ниже.\n"
    "Верни ТОЛЬКО JSON без markdown:\n"
    '{"name_ru": "название по-русски", "name_int": "международное/INCI или пусто", '
    '"kind": "food|cosmetic|both", "risk": 0, "verdict": "useful|neutral|caution|risky|avoid", '
    '"category": "категория", "e_code": "E-код или пусто", '
    '"summary": "одно предложение", "details": "2-4 предложения", '
    '"synonyms": ["варианты написания"], "avoid": ["allergy","kids"], '
    '"sources": ["EFSA"], "confidence": 0-100}\n'
    "Коды avoid — только из списка: allergy, pregnancy, vegan, vegetarian, "
    "diabetes, hypertension, kidney, pku, asthma, kids, sensitive, acne, dry, oily."
)


TITLE_SYSTEM = (
    "Ты даёшь короткое рабочее название товара по строкам его этикетки. "
    "Сначала поищи настоящее название в тексте — оно обычно стоит над составом "
    "(пометка «Наименование», название продукта, торговая марка). "
    "Если названия в тексте нет — придумай его сам по составу и обязательно "
    "начни с «Неопределено_» (например: Неопределено_шоколадный_батончик). "
    "Ответ — ТОЛЬКО название: без кавычек, пояснений и точки в конце, до 60 знаков."
)


COMPOSE_SYSTEM = (
    "Ты — редактор состава. Тебе дают СТРОКИ, распознанные с этикетки (текст, не фото). "
    "Найди блок состава (обычно строка «Состав: …» / «Ingredients» / «INCI») и выпиши "
    "ингредиенты через запятую — дословно, как в строках, каждый РОВНО ОДИН РАЗ и в том "
    "порядке, как он напечатан.\n"
    "ПИШИ ТЕМИ ЖЕ БУКВАМИ, ЧТО В СТРОКАХ. Строки на русском — ответ на русском "
    "КИРИЛЛИЦЕЙ, слово в слово («Экстракт мёда» остаётся «Экстракт мёда», а не "
    "«Honey extract» и не «Ekstrakt myoda»). Перевод, транслитерация и замена на "
    "международное название (INCI) — ошибка: это копирование, а не перевод. Если в "
    "строках и русское, и латинское написание — бери русское, латинское опусти.\n"
    "Чего нет в строках — того нет в составе: не переводи, не переименовывай, не "
    "досочиняй «по аналогии» и не дополняй список похожими веществами. Если блок состава "
    "в строках не читается — верни пустой composition и напиши причину в note.\n"
    "Верни ТОЛЬКО JSON без markdown:\n"
    '{"composition": "ингредиенты через запятую", "kind": "food|cosmetic|unknown", '
    '"confidence": 0-100, "note": "что мешало (строки обрезаны, состав не найден) — или пусто"}'
)

TRANSCRIBE_SYSTEM = (
    "Ты — OCR-переписчик. Перепиши ТЕКСТ с фото этикетки построчно, ДОСЛОВНО, как "
    "напечатано: каждая строка — отдельный элемент списка, в том порядке, как в кадре.\n"
    "ГЛАВНОЕ ПРАВИЛО — АЛФАВИТ ОРИГИНАЛА. Кириллица остаётся кириллицей, латиница — "
    "латиницей: «Состав: Вода, Глицерин» нельзя отдавать как «Ingredients: Water, "
    "Glycerin» или «Sostav: Voda». Перевод и транслитерация запрещены: ответ на "
    "английском по русской этикетке считается неудачей всей задачи. Поле note тоже "
    "по-русски.\n"
    "Правила:\n"
    "1) Ничего не переводи, не сокращай и не пересказывай. Это переписывание, а не разбор.\n"
    "2) Строку, которую не удалось разобрать, помечай [?] прямо в тексте — не заменяй "
    "её похожим по смыслу.\n"
    "3) НЕ ИНТЕРПРЕТИРУЙ: не дополняй список, не повторяй строки, не продолжайте текст "
    "по инерции, когда строки на фото кончились.\n"
    "4) Пиши ТОЛЬКО то, что видно. Пустая строка честнее выдуманной.\n"
    "Верни ТОЛЬКО JSON без markdown:\n"
    '{"lines": ["строка 1", "строка 2", "..."], '
    '"kind": "food|cosmetic|unknown", '
    '"note": "что мешало читать (блик, мелкий шрифт) — или пусто"}'
)


def _extract_json(text: str) -> dict | None:
    """Достать JSON из ответа модели.

    Свой разбор здесь был наивным (`json.loads` + жадный regex) и честно падал
    на живых ответах владельца (03.10.2026): «не удалось разобрать ответ
    модели: {"composition": "Вода, Экстракт масличного жожоба, …».

    Ломается он на трёх вещах, которые для этикетки — норма, а не редкость:
      * ответ обрывается по max_tokens (`finish_reason=length`), и JSON
        приходит незакрытым — состав из десятка экстрактов как раз такой;
      * модель кладёт ПЕРЕНОС СТРОКИ внутрь строки JSON (список длинный — его
        тянет писать в столбик), а это невалидный control-символ;
      * после объекта модель дописывает прозу («Надеюсь, помог!»), и жадный
        `\\{.*\\}` захватывает её вместе с JSON.

    Для всего этого в репозитории есть общий webread.json_from_text (им уже
    пользуются nj591, nj592, nj593, nj598, nj602, nj605 и др.): он чинит обрыв
    по токенам, экранирует управляющие символы, снимает хвостовые запятые и
    markdown-обёртку, вырезает сбалансированный объект из текста вокруг.
    Второй парсер в проекте не нужен — он и разошёлся с остальными.

    Если и он не справился, состав всё равно можно вытащить: _salvage_composition.
    """
    return webread.json_from_text(text or "")


def _composition_span(text: str) -> tuple[int, int, bool] | None:
    """Границы ЗНАЧЕНИЯ ключа composition в сыром ответе модели.

    Возвращает (начало, конец, закрыта_ли_строка). «Не закрыта» — верный
    признак того, что модель оборвалась ВНУТРИ значения (лимит токенов):
    только по нему и можно отличить полный состав от огрызка «…, Экстракт
    масл», потому что отремонтированный JSON в обоих случаях разбирается.
    """
    m = re.search(r'"composition"\s*:\s*"', text or "")
    if not m:
        return None

    start = m.end()
    i = start

    while i < len(text):
        ch = text[i]
        if ch == "\\":
            i += 2
            continue
        if ch == '"':
            return (start, i, True)
        i += 1

    return (start, len(text), False)


def _tidy(composition: str) -> str:
    """Состав — одна строка через запятую: переносы строк и двойные пробелы вон.

    Список ингредиентов модель любит писать в столбик (и в JSON-строке это
    ломает ответ либо приезжает экранированным \\n) — на сайте состав живёт
    в однострочном поле, так что переносы там смысла не несут.
    """
    composition = re.sub(r"[\r\n\t]+", " ", composition or "")
    composition = re.sub(r"[ \u00a0]{2,}", " ", composition)

    return composition.strip().strip(",").strip()


def _clean_raw_text(raw: str) -> str:
    """Сырое значение из JSON-подобного ответа → читаемая строка состава."""
    raw = raw.replace('\\"', '"').replace("\\n", " ").replace("\\r", " ")
    raw = raw.replace("\\t", " ").replace("\\\\", "\\")

    return _tidy(raw)


def _salvage_composition(text: str) -> tuple[str, bool]:
    """Состав из ответа, который не удалось разобрать как JSON: (текст, обрыв).

    Страховка на случай «модель прочитала этикетку, но JSON испортила»: текст
    в ответе виден — отдать его с честной пометкой лучше, чем показать
    владельцу «не удалось разобрать ответ модели» и потерять распознавание
    (ровно это он и увидел 03.10.2026).

    Работает ТОЛЬКО когда ключ composition в ответе есть: иначе непонятно, что
    в тексте — состав или рассуждение модели, и выдумывать нечего.

    Второй элемент — «модель оборвалась внутри значения». Огрызок последней
    позиции («Экстракт масл») мы НЕ отрезаем: без запятой-разделителя не
    отличить недописанное слово от короткого настоящего («Вода», «Соль»),
    а молча удалить настоящий ингредиент хуже, чем показать его же с
    пометкой «проверьте конец списка».
    """
    span = _composition_span(text)

    if span is None:
        return ("", False)

    start, end, closed = span

    return (_clean_raw_text(text[start:end]), not closed)


def _join_note(*parts: str) -> str:
    """Склеить пояснения без повторов и пустых кусков (потолок — 300 знаков)."""
    seen: list[str] = []

    for part in parts:
        part = " ".join(str(part or "").split())
        if part and part not in seen:
            seen.append(part)

    return ". ".join(seen)[:300]


def _merge_items(base: list[str], extra: list[str], limit: int = 400) -> tuple[list[str], int]:
    """Дописать позиции к составу без повторов. Возвращает (позиции, сколько добавлено).

    Сравнение — по нормализованному тексту (регистр и пробелы не в счёт): модель,
    дописывая остаток, легко повторяет уже названную позицию, и дубль на
    странице выглядел бы как два разных ингредиента.
    """
    seen = {" ".join(item.split()).lower() for item in base}
    added: list[str] = []

    for item in extra:
        item = " ".join(item.split()).strip(" .;")

        if not item or len(item) > 120:
            continue

        key = item.lower()

        if key in seen:
            continue

        seen.add(key)
        added.append(item)

    return (base + added)[:limit], len(added)


def _continuation_items(text: str) -> list[str]:
    """Позиции из ответа модели на просьбу «продолжи список».

    Модель может ответить по-разному: чистым списком через запятую, куском
    JSON, строкой в кавычках или с префиксом «composition:». Берём всё, что
    похоже на список, и чистим служебные обломки.
    """
    text = (text or "").strip()

    if not text:
        return []

    data = webread.json_from_text(text)

    if isinstance(data, dict) and data.get("composition"):
        text = str(data["composition"])
    else:
        # JSON не собрался: берём значение ключа composition, если он есть,
        # иначе сам ответ.
        salvaged, _cut = _salvage_composition(text)
        text = salvaged or text

    text = _tidy(text).strip().strip('"').strip()

    if text.startswith("{") or text.startswith("["):
        return []

    items = _split_items(text)

    # «Продолжения нет» модель обозначает пустотой, словами или фигурными
    # скобками — всё это не позиции состава.
    words = " ".join(text.split()).lower()
    if len(items) == 1 and (words in {"пусто", "нет", "конец", "готово"}
                            or words.startswith("состав закончился")):
        return []

    return items


def _split_items(composition: str) -> list[str]:
    """Состав строкой → позиции списка (как их видит владелец на странице)."""
    return [part.strip() for part in (composition or "").split(",") if part.strip()]


def _loop_cut(items: list[str], max_period: int = 60, min_cycles: int = 3) -> int:
    """Где обрывается вырождение модели: индекс сразу за ПЕРВОЙ копией цикла.

    Малая VLM на длинном списке уходит в цикл и гонит один и тот же блок
    ингредиентов до потолка токенов. На живой этикетке (03.10.2026) так
    приехало «Экстракт корня туи, … Экстракт корня тиса» много раз подряд:
    состав из восьми строк выглядел списком из трёхсот, и он же обрывался по
    лимиту токенов. Человек такое читать не станет, а «модель не додумывает
    состав» — правило модуля, поэтому цикл режем.

    Ищем хвост, периодичный с периодом p (p от 1 до max_period: одинаковые
    позиции через p). Если период повторился min_cycles раз, весь хвост — один
    и тот же блок, и мы оставляем его ПЕРВУЮ копию (первые позиции этикетки
    модель обычно читает вернее остальных). 0 — цикла нет, трогать нечего.

    Последний период может быть НЕДОПИСАН: ответ владельцу оборвался на
    «…Экстракт корня» прямо внутри очередного круга, и без поправки на это
    цикл не находился (обрезала только дедупликация — и без честной пометки
    «пошла по кругу»). Поэтому дополнительно перебираем skip — сколько
    последних позиций считать огрызком оборвавшегося периода.

    Порядок p — от короткого к длинному, skip — от нуля: короткий цикл дешевле
    проверить и безопаснее (меньше срезаем). Ложное срабатывание возможно
    только если одна и та же позиция повторяется ровно тем же словом три
    периода подряд — честная этикетка так не пишет.
    """
    keys = [" ".join(item.split()).lower() for item in items]
    n = len(keys)

    for p in range(1, max_period + 1):
        # skip ограничен периодом: дальше начинается уже предыдущий круг
        for skip in range(0, min(p, n)):
            end = n - skip
            i = end - 1

            # сколько позиций назад текст совпадает сам с собой через p
            while i - p >= 0 and keys[i] == keys[i - p]:
                i -= 1

            # периодичный хвост начинается на p позиций раньше совпадения
            start = max(0, i + 1 - p)

            if (end - start) // p >= min_cycles:
                return start + p

    return 0


def _dedupe_items(items: list[str]) -> tuple[list[str], int]:
    """Убрать повторы позиций, оставив первое вхождение (регистр/пробелы — не в счёт).

    Страховка на случай, когда модель повторила список РОВНО дважды: цикл из
    двух копий _loop_cut не режет (нужно три), а на странице состав-дубль
    выглядит поломкой. Возвращает (позиции, сколько убрано).
    """
    seen: set[str] = set()
    kept: list[str] = []

    for item in items:
        key = " ".join(item.split()).lower()

        if key in seen:
            continue

        seen.add(key)
        kept.append(item)

    return (kept, len(items) - len(kept))


def _suspicion_note(composition: str, min_same: int = 8) -> str:
    """Пометка «список похож на додуманный» — или пустая строка.

    Владелец (03.10.2026): «vlm сильно галлюцинирует». Прочитать фото мы за
    модель не можем, но вырождение видно по тексту: живая этикетка не пишет
    восемь и больше позиций с одинаковым началом («Экстракт корня …», «Масло
    …» — там разные растения, а не один шаблон). Ничего не удаляем и не
    переписываем: состав уезжает на страницу как есть, но владелец видит
    предупреждение и проверяет его по этикетке — это честнее, чем молча
    показывать выдумку как прочитанный состав.
    """
    # Считаем по РАЗНЫМ позициям: точные повторы — это цикл, о нём отдельная
    # пометка, а здесь важно, сколько РАЗНЫХ «Экстракт корня …» выдумано.
    items, _dups = _dedupe_items(_split_items(composition))
    heads: dict[str, tuple[str, int]] = {}

    for item in items:
        words = " ".join(item.split()).split()

        if len(words) >= 2:
            key = " ".join(words[:2]).lower()
            display, count = heads.get(key, (" ".join(words[:2]), 0))
            heads[key] = (display, count + 1)

    if not heads:
        return ""

    head, count = max(heads.values(), key=lambda dc: dc[1])

    if count < min_same:
        return ""

    return (f"похоже на выдумку: {count} позиций начинаются одинаково "
            f"(«{head} …») — сверьте список с этикеткой")


def _clean_composition(composition: str) -> tuple[str, int, int]:
    """Состав от модели → чистый список. (текст, срезано циклом, убрано дублей).

    Две разные беды и две разные меры: ЦИКЛ (модель гонит блок по кругу —
    режем на первой копии) и ДУБЛИ (список повторился целиком — убираем
    вторые вхождения). Всё это не «улучшение текста», а защита страницы от
    вырождения модели: остальное в составе не трогаем.
    """
    items = _split_items(composition)

    if len(items) < 2:
        return (composition, 0, 0)

    cut = _loop_cut(items)
    looped = len(items) - cut if cut else 0
    items = items[:cut] if cut else items

    items, dups = _dedupe_items(items)

    return (", ".join(items) or composition, looped, dups)


def _lines_from_reply(text: str) -> list[str]:
    """Строки этикетки из ответа OCR-прохода.

    Сначала честный JSON (`lines`), а если он оборвался по токенам — вынимаем
    строки регуляркой прямо из сырого ответа: переписанный текст этикетки
    ценен сам по себе, терять его из-за незакрытой скобки нельзя.
    """
    data = webread.json_from_text(text or "")

    if isinstance(data, dict):
        lines = data.get("lines")

        if isinstance(lines, list):
            cleaned = [" ".join(str(x).split()) for x in lines if str(x).strip()]
            if cleaned:
                return cleaned

    # JSON не собрался/оборвался: строки из его элементов
    raw = text or ""
    start = raw.find('"lines"')

    if start >= 0:
        raw = raw[start:]

    lines = [m.group(1) for m in re.finditer(r'"((?:[^"\\]|\\.)*)"', raw)]
    out: list[str] = []

    for item in lines:
        if item.strip().lower() in ("lines", "kind", "note", "food", "cosmetic", "unknown"):
            continue
        item = " ".join(item.replace('\\n', ' ').replace('\\"', '"').split())
        if item:
            out.append(item)

    if out:
        return out

    # Модель проигнорировала JSON и просто переписала строки — это тоже годится:
    # текст этикетки у нас есть, а состав из него собирается уже без картинки.
    plain = [" ".join(line.split()) for line in (text or "").splitlines()]
    plain = [line for line in plain
             if line and not line.startswith(("{", "[", "```")) and "[MOCK-LLM]" not in line]

    # Одна строка — не транскрипция: непонятно, текст этикетки это или ответ
    # модели о составе. В таком случае честнее вернуться к одношаговому чтению.
    return plain if len(plain) >= 2 else []


async def _transcribe(ctx, image_b64: str, media: str, started_at: float,
                      ) -> tuple[list[str], dict, str, str]:
    """OCR-проход: строки этикетки текстом. (строки, usage, finish, сырой ответ).

    Ошибку не глотаем молча: обработчик по пустому результату вернётся к
    одношаговому чтению (vision + сборка состава сразу).
    """
    budget = _transcribe_timeout()
    timeout = max(20.0, budget - max(0.0, time.monotonic() - started_at))

    try:
        text, usage = await asyncio.wait_for(
            ctx.llm.chat_with_usage(
                [{"role": "system", "content": TRANSCRIBE_SYSTEM},
                 _img_msg(image_b64, "Перепиши текст этикетки построчно, как напечатано, на языке оригинала (русские строки — кириллицей).", media)],
                model=_vision_model() or None,
                max_tokens=_max_tokens(),
                temperature=0,
                request_timeout=timeout,
            ),
            timeout=timeout + 20,
        )
    except Exception as e:  # noqa: BLE001 — вернёмся к одношаговому чтению
        log.warning("nj646: OCR-проход не удался: %s: %s", type(e).__name__, str(e)[:200])
        return ([], {}, "", "")

    usage = usage if isinstance(usage, dict) else {}
    finish = str(usage.get("_llm_finish_reason") or "")

    return (_lines_from_reply(text), usage, finish, text or "")


_STOP_LINE = (
    "объ", "срок", "годност", "производител", "поставщик", "адрес", "тел", "сайт",
    "e-mail", "изготовител", "дата", "штрих", "хранен", "масса", "нетто", "брутто",
    "страна", "партия", "условия", "способ применен", "противопоказан",
)


def _composition_from_lines_local(lines: list[str]) -> str:
    """Собрать состав из строк этикетки БЕЗ модели: блок «Состав: …».

    Самый надёжный путь: если строка состава в переписанном тексте есть, она и
    берётся — никакая модель тут ничего не додумает. Продолжением блока считаем
    следующие строки до служебной («Объём», «Срок годности», «Производитель»…)
    или до пустой строки — так работает свёрстанная этикетка, где состав идёт
    абзацем в несколько строк.
    """
    start = -1

    for i, line in enumerate(lines):
        low = line.lower()
        if re.search(r"(^|[^a-zа-яё])(состав|ингредиенты|ingredients|inci)\b", low):
            start = i
            break

    if start < 0:
        return ""

    block = [lines[start]]

    for line in lines[start + 1:start + 13]:
        low = " ".join(line.lower().split())

        if not low or low.startswith(_STOP_LINE):
            break

        block.append(line)

    text = " ".join(block)
    # убрать заголовок «Состав:» / «Ingredients:» — оставляем только сам список
    text = re.sub(r"^.*?(состав|ингредиенты|ingredients|inci)\s*:?\s*", "", text,
                  count=1, flags=re.I)

    cleaned, _looped, _dups = _clean_composition(_tidy(text))

    return cleaned


def _alphabet(text: str) -> tuple[int, int]:
    """Сколько в тексте кириллических и латинских букв."""
    cyr = sum(1 for ch in text if "\u0400" <= ch <= "\u04FF")
    lat = sum(1 for ch in text if ("a" <= ch <= "z") or ("A" <= ch <= "Z"))

    return cyr, lat


def _latin_drift(source: str, composition: str) -> bool:
    """Русская этикетка — английский состав: модель перевела вместо чтения.

    Повод (03.10.2026, владелец): «при разборе картинки стал русский текст
    распознавать так, что на выходе английский текст». Для сервиса это не
    мелочь: человек сверяет список с упаковкой глазами, а база веществ ищет по
    написанию с этикетки — «Water» вместо «Вода» и не находится, и не читается.

    Промпты теперь требуют алфавит оригинала, но полагаться только на просьбу
    нельзя: проверяем ответ. Признак сноса — в ИСХОДНИКЕ (строки этикетки)
    кириллица преобладает, а в составе её почти нет.

    Порог намеренно грубый: смешанные этикетки («Вода / Aqua») и латинские
    названия внутри русского списка (INCI в скобках) не должны считаться
    сносом — ошибочная тревога стоит дороже, чем пропущенный случай.
    """
    src_cyr, src_lat = _alphabet(source or "")
    out_cyr, out_lat = _alphabet(composition or "")

    if src_cyr < 20 or src_cyr < src_lat:
        return False            # этикетка не русская — переводить нечего

    if out_cyr + out_lat < 10:
        return False            # ответ слишком короткий, чтобы судить

    return out_lat > out_cyr * 4


async def _compose_from_transcript(ctx, transcript: str, started_at: float,
                                   ) -> tuple[dict | None, dict, str]:
    """Собрать состав из переписанных строк (текстовый запрос, без картинки)."""
    used = max(0.0, time.monotonic() - started_at)
    budget = max(20.0, min(90.0, _llm_timeout() - used))

    messages = [
        {"role": "system", "content": COMPOSE_SYSTEM},
        {"role": "user", "content": ("Строки этикетки, распознанные с фото. Выпиши состав "
                                     "ТЕМИ ЖЕ словами и буквами, что в строках:\n")
                                    + transcript[:6000]},
    ]

    try:
        text, usage = await asyncio.wait_for(
            ctx.llm.chat_with_usage(
                messages,
                model=_study_model() or None,
                max_tokens=_max_tokens(),
                temperature=0,
                request_timeout=budget,
            ),
            timeout=budget + 20,
        )
    except Exception as e:  # noqa: BLE001 — состав соберём локально
        log.warning("nj646: сборка состава из строк не удалась: %s: %s",
                    type(e).__name__, str(e)[:200])
        return (None, {}, "")

    return (_extract_json(text), usage if isinstance(usage, dict) else {}, text or "")


async def _continue_composition(ctx, image_b64: str, media: str, composition: str,
                                started_at: float, usage: dict) -> tuple[list[str], dict]:
    """Допросить модель на остаток оборванного списка.

    Зачем: у длинной этикетки (INCI на 30–40 позиций) потолка ответа не хватает,
    JSON обрывается посреди значения — владелец видит состав без конца и жалуется,
    что «ответ большой обрезается» (03.10.2026). Совет «поднимите
    NJ646_MAX_TOKENS» всё равно требует человека, поэтому просим модель дописать:
    показываем ей уже прочитанное (после запятой) и просим продолжить ровно с
    места обрыва — вернуть ТОЛЬКО оставшиеся позиции.

    Бюджет времени — остаток от общего: вызывается ПОСЛЕ основного ответа, и
    вместе они не должны выйти за timeout_sec задачи (иначе прогон снимут ровно
    в момент, когда состав готов). Ошибка продолжения не критична: возвращаем
    пустой список, состав уедет обрезанным с честной пометкой.
    """
    used = max(0.0, time.monotonic() - started_at)
    budget = max(20.0, _llm_timeout() - used)

    messages = [
        {"role": "system", "content": LABEL_SYSTEM},
        _img_msg(image_b64, "Прочитай состав с этой этикетки и выпиши его на языке этикетки (русский — кириллицей).", media),
        # Черновик модели в её собственном формате: она продолжает свой же JSON.
        {"role": "assistant", "content": '{"composition": "' + composition},
        {"role": "user", "content": (
            "Список оборвался на лимите. Продолжи его РОВНО с места обрыва: "
            "верни только оставшиеся позиции через запятую, без повторов уже "
            "названного, без JSON и без пояснений. Если состав на этикетке "
            "закончился — верни пустую строку.")},
    ]

    try:
        text, extra_usage = await asyncio.wait_for(
            ctx.llm.chat_with_usage(
                messages,
                model=_vision_model() or None,
                max_tokens=_max_tokens(),
                temperature=0,
                request_timeout=budget,
            ),
            timeout=budget + 20,
        )
    except Exception as e:  # noqa: BLE001 — обрыв ответа не повод терять состав
        log.warning("nj646: продолжение обрезанного состава не удалось: %s: %s",
                    type(e).__name__, str(e)[:200])
        return [], {}

    return _continuation_items(text), (extra_usage if isinstance(extra_usage, dict) else {})


async def _post(ctx, payload: dict) -> tuple[bool, str]:
    """Отдать результат прогона на сайт. Возвращает (принят, причина отказа).

    Причину ОБЯЗАТЕЛЬНО показываем в мониторе: молчаливый отказ доставки —
    это и есть «модель ответила, а на сайте ничего» (нет токена, 403, сеть).
    """
    ok, reason, _ = await site.post_json("NJ646", "/worker_result", payload,
                                         timeout=45, retries=2, json_body=True)

    if not ok:
        log.warning("nj646: сайт не принял результат прогона %s: %s",
                    payload.get("run_id"), reason)

    return ok, reason


# --------------------------------------------------------------------------
# nj646.label — фото этикетки
# --------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    # Канал nj646.label обслуживает ДВА вида работы: фото этикетки и рабочее
    # название разбора (mode=title). Разделяем по payload, а не отдельным
    # каналом: WORKER_CHANNELS на развёрнутых стендах уже подписан на
    # nj646.label, и новая настройка вместо правки кода — лишний риск.
    if str(payload.get("mode") or "").strip() == "title":
        return await handle_title(ctx, payload)

    run_id = int(payload.get("run_id") or 0)
    b64 = str(payload.get("image_b64") or "")

    if not run_id:
        raise ValueError("nj646.label: payload должен содержать run_id")
    if ctx.llm is None:
        # Проверяем модель ДО запроса кадра: без неё работа бессмысленна, а
        # лишний запрос на сайт — это шум в логах и трафик.
        raise RuntimeError("nj646.label: LLM-провайдеры не настроены (workers/.env)")

    # Кадр едет ФАЙЛОМ на сайте (payload несёт только флаг `photo`): base64 в
    # jsonb очереди — это 10 МБ на каждую задачу, а очередь общая для проекта.
    # Забираем кадр по токену воркера; старые задачи с `image_b64` (поставленные
    # до этой правки) работают как раньше — ветка ниже просто не выполняется.
    if not b64 and payload.get("photo"):
        ok, why, data = await site.post_json("NJ646", "/photo_file",
                                            {"run_id": run_id})

        if not ok or not data.get("image_b64"):
            raise RuntimeError(
                "nj646.label: не удалось забрать фото прогона "
                f"{run_id} с сайта: {why or 'пустой ответ'}")

        b64 = str(data.get("image_b64") or "")

        if not payload.get("media_type"):
            payload["media_type"] = str(data.get("media_type") or "image/jpeg")

        await ctx.progress("Фото с сайта", f"{int(data.get('kb') or 0)} КБ")

    if not b64:
        raise ValueError("nj646.label: пришло пустое изображение (нет ни файла, ни image_b64)")

    t0 = time.monotonic()
    await ctx.progress("Готовлю фото…")
    await ctx.heartbeat()

    shrunk, image_meta = await _prepare_b64(b64)
    shrunk = shrunk or b64
    media = str(payload.get("media_type") or "image/jpeg")

    if image_meta.get("src"):
        # Владелец должен видеть, с каким кадром работала модель: «400×600»
        # в мониторе объясняет выдумки лучше любых догадок. Стрелку показываем
        # только когда кадр правда изменился — мелкий уезжает как есть.
        size = str(image_meta["src"])

        if image_meta.get("out") and image_meta["out"] != size:
            size += " → " + str(image_meta["out"])

        await ctx.progress("Фото к модели", size)
        await ctx.heartbeat()

    model_spec = _vision_model() or "модель по умолчанию"
    llm_timeout = _llm_timeout()
    text = ""
    usage: dict = {}
    data = None
    transcript = ""
    transcript_cut = False
    two_pass = ""
    # Состав выписан из переписанных строк БЕЗ модели: блок кончается служебной
    # строкой этикетки, то есть он полный — допрашивать модель о продолжении
    # тут нечего (в отличие от обрыва ответа по токенам).
    composition_from_lines = False
    # Пометка «модель перевела состав»: ставится, когда спасти ответ строками
    # не удалось (см. _latin_drift) — человек должен знать, почему список
    # выглядит не так, как упаковка.
    latin_drift_note = ""

    # --- Проход 1 (двухпроходный режим): переписать СТРОКИ этикетки ---------
    # Модель, которую сразу просят «собери состав», на мелком шрифте угадывает
    # слова; та же модель в роли переписчика ошибается заметно реже, а состав
    # собирается из её же текста — и по возможности без модели вовсе.
    if _transcribe_enabled():
        await ctx.progress("Читаю строки этикетки…",
                           f"{model_spec}, до {int(_transcribe_timeout())} с")
        await ctx.heartbeat()

        lines, tr_usage, tr_finish, tr_raw = await _transcribe(ctx, shrunk, media, t0)

        if lines:
            transcript = "\n".join(lines)[:8000]
            transcript_cut = tr_finish == "length"
            await ctx.progress("Строки этикетки прочитаны", f"{len(lines)} строк")
            await ctx.heartbeat()

            # --- Проход 2: состав из переписанных строк --------------------
            composed, comp_usage, comp_raw = await _compose_from_transcript(
                ctx, transcript, t0)

            composition_txt = str((composed or {}).get("composition") or "").strip()

            # Русские строки → английский состав: модель перевела, а не
            # переписала. Доверять такому ответу нельзя — собираем состав из
            # строк сами (там слова этикетки, слово в слово).
            if composition_txt and _latin_drift(transcript, composition_txt):
                log.warning("nj646: модель перевела состав на латиницу — "
                            "беру блок «Состав» из распознанных строк")
                await ctx.progress("Модель перевела состав",
                                   "русская этикетка, ответ латиницей — беру строки как есть")
                composition_txt = ""
                composed = None
                latin_drift_note = ("модель вернула состав латиницей, хотя этикетка "
                                    "русская — список взят из распознанных строк, "
                                    "сверьте его с упаковкой")

            if not composition_txt:
                # Модель не справилась (или перевела) — берём блок «Состав:» из
                # строк как есть: там слова этикетки, слово в слово.
                local = _composition_from_lines_local(lines)

                if local:
                    composed = {"composition": local, "kind": (composed or {}).get("kind") or "unknown",
                                "confidence": 70,
                                "note": (composed or {}).get("note") or ""}
                    composition_txt = local
                    composition_from_lines = True
                    two_pass = "состав выписан из распознанных строк без модели"
                    await ctx.progress("Состав собран из строк", f"{len(local)} знаков")
            if composition_txt:
                data = composed
                text = comp_raw or '{"composition": "' + composition_txt
                usage = comp_usage or tr_usage
                if not two_pass:
                    two_pass = "состав собран из распознанных строк этикетки"
        else:
            log.info("nj646: OCR-проход пуст — читаю этикетку одним запросом")

    # --- Одношаговое чтение: двухпроходный режим выключен или не сложился ---
    if data is None:
        await ctx.progress("Читаю этикетку…", f"{model_spec}, до {int(llm_timeout)} с")
        await ctx.heartbeat()

        try:
            # Свой предел ожидания МЕНЬШЕ таймаута задачи (см. _llm_timeout): после
            # ответа модели нужно ещё успеть разобрать её и доставить состав на сайт.
            text, usage = await asyncio.wait_for(
                ctx.llm.chat_with_usage(
                    [{"role": "system", "content": LABEL_SYSTEM},
                     _img_msg(shrunk, "Прочитай состав с этой этикетки и выпиши его на языке этикетки (русский — кириллицей).", media)],
                    model=_vision_model() or None,
                    max_tokens=_max_tokens(),
                    temperature=0,
                    request_timeout=llm_timeout,
                ),
                timeout=llm_timeout + 30,
            )
        except (asyncio.TimeoutError, TimeoutError):
            error = (f"Модель не ответила за {int(llm_timeout)} с. Для больших фото "
                     f"это мало: поднимите NJ646_LLM_TIMEOUT и timeout_sec задачи "
                     f"или уменьшите фото.")
            await ctx.progress("Модель не ответила", f"{int(llm_timeout)} с")
            await _post(ctx, {"run_id": run_id, "mode": "photo", "status": "error",
                              "error": error, "model": model_spec})
            raise RuntimeError(error)
        except Exception as e:  # noqa: BLE001 — 401/сеть/сбой провайдера: прогон
            # обязан стать терминальным, иначе страница «Распознаёт» бесконечно.
            error = f"Модель недоступна: {type(e).__name__}: {str(e)[:300]}"
            await ctx.progress("Модель не ответила", error[:150])
            await _post(ctx, {"run_id": run_id, "mode": "photo", "status": "error",
                              "error": error, "model": model_spec})
            raise

    if data is None:
        await ctx.progress("Модель ответила", f"{str(text or '')[:60]!r}")
        await ctx.heartbeat()

        data = _extract_json(text)

    elapsed = int((time.monotonic() - t0) * 1000)
    model_used = _vision_model() or "default"

    finish = str((usage or {}).get("_llm_finish_reason") or "")
    over_limit = finish == "length"
    span = _composition_span(text)
    cut = bool(span and not span[2])  # модель оборвалась внутри значения

    composition = _tidy(str((data or {}).get("composition") or ""))
    model_note = str((data or {}).get("note") or "").strip()[:200]

    if not composition and span:
        # JSON не разобрался, но сам текст состава в ответе виден: отдаём
        # прочитанное с пометкой вместо «не удалось разобрать ответ модели».
        composition, cut = _salvage_composition(text)
        if composition:
            await ctx.progress("Ответ разобран частично",
                               f"{len(composition)} знаков, JSON оборван")

    # Свои пометки собираем ОТДЕЛЬНО и ставим их ПЕРЕД note модели: у note
    # потолок 300 знаков, и слово модели «часть списка под бликом» не должно
    # вытеснять предупреждение о том, что список мог быть выдуман.
    warnings: list[str] = []

    # Модель могла уйти в цикл, повторить список или додумать его — защищаем
    # страницу от вырождения здесь, а не в шаблоне (см. _clean_composition).
    if composition:
        # Подозрение считаем по СЫРОМУ списку: обрезка цикла убирает как раз
        # те повторы, по которым оно и видно.
        suspicion = _suspicion_note(composition)
        composition, looped, dups = _clean_composition(composition)

        if looped:
            warnings.append(
                f"модель пошла по кругу и повторяла один блок ингредиентов — "
                f"лишние {looped} позиций убраны, проверьте список по этикетке")
            await ctx.progress("Модель зациклилась", f"убрано позиций: {looped}")
        elif dups:
            warnings.append(f"повторы позиций убраны ({dups})")

        if suspicion:
            warnings.append(suspicion)

    # Оборванный список: просим модель дописать остаток (см. _continue_composition).
    if composition and (cut or over_limit) and not composition_from_lines:
        trunc_note = ("ответ модели оборвался — проверьте конец списка "
                      "ингредиентов, последняя позиция могла не дописаться")
        added = 0

        # Не допрашиваем, если список уже выглядит выдуманным (цикл/шаблон):
        # у фантазии продолжение такое же — плодить её не надо.
        if not looped and not suspicion:
            await ctx.progress("Список оборвался", "прошу модель дописать остаток")
            extra_items, extra_usage = await _continue_composition(
                ctx, shrunk, media, composition, t0, usage)

            if extra_items:
                merged, added = _merge_items(_split_items(composition), extra_items)

                if added:
                    composition = ", ".join(merged)
                    # Продолжение тоже может зациклиться или повториться.
                    composition, looped2, dups2 = _clean_composition(composition)
                    looped += looped2
                    dups += dups2
                    usage = extra_usage or usage

        if added:
            warnings.append(
                f"ответ модели обрывался — список дописан вторым запросом "
                f"(+{added} позиц.)")
            await ctx.progress("Состав дописан", f"+{added} позиц.")
        else:
            warnings.append(trunc_note)
            await ctx.progress("Состав прочитан не до конца", trunc_note[:150])

    if image_meta.get("src"):
        warnings.append(_small_photo_warning(image_meta))

    if two_pass:
        warnings.append(two_pass)

    if latin_drift_note:
        warnings.append(latin_drift_note)

    if transcript_cut:
        warnings.append(
            "строк этикетки на фото больше, чем влезло в один ответ модели — "
            "конец списка строк не переписан, проверьте состав по этикетке")

    note = _join_note(*warnings, model_note)

    if not composition:
        hint = (f" Ответ оборвался по лимиту токенов — поднимите "
                f"NJ646_MAX_TOKENS (сейчас {_max_tokens()})." if over_limit else "")
        result = {"run_id": run_id, "mode": "photo", "status": "error",
                  "error": f"не удалось разобрать ответ модели: {str(text)[:200]}{hint}",
                  "model": model_used, "elapsed_ms": elapsed,
                  "usage": usage if isinstance(usage, dict) else {}}
        await _post(ctx, result)
        raise RuntimeError(result["error"])

    result = {
        "run_id": run_id, "mode": "photo", "status": "done",
        "text": composition[:8000],
        "kind": str((data or {}).get("kind") or "unknown"),
        "confidence": int((data or {}).get("confidence") or 0),
        "note": note[:300],
        # Переписанный текст этикетки: владелец видит, ИЗ ЧЕГО собран состав,
        # и может поймать выдумку модели, сверив строки с фото.
        "transcript": transcript[:6000],
        "model": model_used,
        "elapsed_ms": elapsed,
        "usage": usage if isinstance(usage, dict) else {},
        "error": "",
    }

    await ctx.progress("Отправляю состав на сайт…", f"{len(composition)} знаков")
    delivered, reason = await _post(ctx, result)
    result["delivered"] = delivered

    if delivered:
        await ctx.progress("Состав передан на сайт", f"{len(composition)} знаков")
    else:
        # Результат всё равно уезжает в job.result — сайт заберёт его дожимом
        # (Service_Nj646_Store::run_catchup), но причину видно в мониторе.
        await ctx.progress("Сайт не принял результат", reason[:200])

    log.info("nj646[label] run=%s %d знаков, %d мс, доставлено=%s",
             run_id, len(composition), elapsed, delivered)
    return result


# --------------------------------------------------------------------------
# nj646.study — изучение неизвестного вещества
# --------------------------------------------------------------------------

def _clean_title(reply: str) -> str:
    """Название из ответа модели: одна строка, без кавычек и знаков на концах.

    Модель любит вернуть «Название: Кефир.» или обернуть в кавычки — в списке
    разборов это выглядит неряшливо, поэтому чистим здесь, а не на странице.
    """
    text = str(reply or "").strip()

    if not text:
        return ""

    # Иногда модель отвечает JSON-ом («{"title": "..."}») — вынимаем значение.
    try:
        data = webread.json_from_text(text)
    except Exception:  # noqa: BLE001 — не JSON, это норма: чистим как текст
        data = None

    if isinstance(data, dict):
        text = str(data.get("title") or data.get("name") or "").strip()

    line = text.splitlines()[0].strip() if text.strip() else ""
    line = re.sub(r"^(название|наименование|title)\s*[:\-]\s*", "", line, flags=re.IGNORECASE)
    line = line.strip().strip(".,;:—-–— ")

    # Кавычки снимаем ПАРОЙ: «Алёнка» → Алёнка, но «Алёнка» (молоко) не ломаем.
    for left, right in (("«", "»"), ("\u201c", "\u201d"), ('"', '"'), ("'", "'")):
        if line.startswith(left) and line.endswith(right) and len(line) > 1:
            line = line[len(left):len(line) - len(right)]

    # Финальная чистка — только знаки и пробелы: кавычки уже разобраны парой,
    # одиночную „Алёнка“ оставляем как есть (это название, а не мусор).
    line = line.strip().strip(".,;:—-–— ")
    line = re.sub(r"\s+", " ", line)

    # Рабочее имя приводим к одному виду: «Неопределено шоколадный батончик» и
    # «неопределено-шоколадный» — это одно и то же имя с префиксом.
    if re.match(r"^неопределено\b", line, flags=re.IGNORECASE):
        line = re.sub(r"^неопределено[\s_\-]*", "Неопределено_", line, flags=re.IGNORECASE)
        line = line.replace(" ", "_")

    return line[:60]


async def handle_title(ctx: HandlerContext, payload: dict) -> dict:
    """Рабочее название разбора по строкам этикетки (mode=title).

    Нужно списку разборов: по составу строку не узнать, а название товара на
    этикетке есть почти всегда — если его не нашли регулярками сайта, просим
    модель (владелец: «если нет названия — то LLM пускай даёт рабочее название
    с префиксом Неопределено_»). Задача короткая: один запрос, потолок ответа
    в несколько десятков токенов.
    """
    run_id = int(payload.get("run_id") or 0)
    analysis_id = int(payload.get("analysis_id") or 0)
    text = str(payload.get("text") or "").strip()[:4000]

    if not run_id:
        raise ValueError("nj646.label(title): payload должен содержать run_id")
    if not analysis_id:
        raise ValueError("nj646.label(title): payload должен содержать analysis_id")
    if len(text) < 20:
        raise ValueError("nj646.label(title): слишком короткий текст этикетки")
    if ctx.llm is None:
        raise RuntimeError("nj646.label(title): LLM-провайдеры не настроены (workers/.env)")

    t0 = time.monotonic()
    timeout = min(_llm_timeout(), 90.0)
    await ctx.progress("Придумываю рабочее название…", f"{len(text)} знаков этикетки")
    await ctx.heartbeat()

    try:
        reply, usage = await asyncio.wait_for(
            ctx.llm.chat_with_usage(
                [{"role": "system", "content": TITLE_SYSTEM},
                 {"role": "user", "content": text}],
                model=_study_model() or None,
                max_tokens=80,
                temperature=0.2,
                request_timeout=timeout,
            ),
            timeout=timeout + 20,
        )
    except (asyncio.TimeoutError, TimeoutError):
        error = f"Модель не ответила за {int(timeout)} с."
        await ctx.progress("Название не получено", error)
        await _post(ctx, {"run_id": run_id, "mode": "title", "status": "error",
                          "error": error, "analysis_id": analysis_id,
                          "model": _study_model() or "default"})
        raise RuntimeError(error)
    except Exception as e:  # noqa: BLE001 — прогон обязан стать терминальным
        error = f"Модель недоступна: {type(e).__name__}: {str(e)[:200]}"
        await ctx.progress("Название не получено", error[:150])
        await _post(ctx, {"run_id": run_id, "mode": "title", "status": "error",
                          "error": error, "analysis_id": analysis_id,
                          "model": _study_model() or "default"})
        raise

    title = _clean_title(reply)
    elapsed = int((time.monotonic() - t0) * 1000)
    model_used = _study_model() or "default"

    if not title:
        error = f"модель не дала название: {str(reply)[:120]!r}"
        await ctx.progress("Название не получено", error[:150])
        result = {"run_id": run_id, "mode": "title", "status": "error", "error": error,
                  "analysis_id": analysis_id, "model": model_used, "elapsed_ms": elapsed}
        await _post(ctx, result)
        raise RuntimeError(error)

    result = {
        "run_id": run_id, "mode": "title", "status": "done",
        "analysis_id": analysis_id, "title": title,
        "model": model_used, "elapsed_ms": elapsed,
        "usage": usage if isinstance(usage, dict) else {},
        "error": "",
    }

    await ctx.progress("Отправляю название на сайт…", f"«{title}»")
    delivered, reason = await _post(ctx, result)
    result["delivered"] = delivered

    if delivered:
        await ctx.progress("Название передано на сайт", f"«{title}»")
    else:
        await ctx.progress("Сайт не принял название", reason[:200])

    log.info("nj646[title] run=%s analysis=%s «%s», %d мс, доставлено=%s",
             run_id, analysis_id, title, elapsed, delivered)
    return result


async def handle_study(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    name = str(payload.get("name") or "").strip()[:200]
    kind = str(payload.get("kind") or "both")
    unknown_id = int(payload.get("unknown_id") or 0)

    if not run_id:
        raise ValueError("nj646.study: payload должен содержать run_id")
    if not name:
        raise ValueError("nj646.study: пустое название вещества")
    if ctx.llm is None:
        raise RuntimeError("nj646.study: LLM-провайдеры не настроены (workers/.env)")

    t0 = time.monotonic()
    llm_timeout = _llm_timeout()
    await ctx.progress("Изучаю вещество", f"{name} · до {int(llm_timeout)} с")
    await ctx.heartbeat()

    try:
        text, usage = await asyncio.wait_for(
            ctx.llm.chat_with_usage(
                [{"role": "system", "content": STUDY_SYSTEM},
                 {"role": "user", "content": f"Вещество: {name}\nПродукт: {kind}"}],
                model=_study_model() or None,
                max_tokens=_max_tokens(),
                temperature=0.2,
                request_timeout=llm_timeout,
            ),
            timeout=llm_timeout + 30,
        )
    except (asyncio.TimeoutError, TimeoutError):
        error = f"Модель не ответила за {int(llm_timeout)} с (NJ646_LLM_TIMEOUT)."
        await ctx.progress("Модель не ответила", f"{int(llm_timeout)} с")
        await _post(ctx, {"run_id": run_id, "mode": "study", "status": "error",
                          "error": error, "unknown_id": unknown_id,
                          "model": _study_model() or "default"})
        raise RuntimeError(error)
    except Exception as e:  # noqa: BLE001 — прогон обязан стать терминальным
        error = f"Модель недоступна: {type(e).__name__}: {str(e)[:300]}"
        await ctx.progress("Модель не ответила", error[:150])
        await _post(ctx, {"run_id": run_id, "mode": "study", "status": "error",
                          "error": error, "unknown_id": unknown_id,
                          "model": _study_model() or "default"})
        raise

    await ctx.progress("Модель ответила", f"{str(text or '')[:60]!r}")
    await ctx.heartbeat()

    data = _extract_json(text)
    elapsed = int((time.monotonic() - t0) * 1000)
    model_used = _study_model() or "default"

    if data is None or not str(data.get("name_ru") or "").strip():
        hint = ""
        if str((usage or {}).get("_llm_finish_reason") or "") == "length":
            hint = (f" Ответ оборвался по лимиту токенов — поднимите "
                    f"NJ646_MAX_TOKENS (сейчас {_max_tokens()}).")
        result = {"run_id": run_id, "mode": "study", "status": "error",
                  "error": f"модель вернула не-JSON: {str(text)[:200]}{hint}",
                  "unknown_id": unknown_id, "model": model_used, "elapsed_ms": elapsed}
        await _post(ctx, result)
        raise RuntimeError(result["error"])

    risk = data.get("risk", 2)
    try:
        risk = max(0, min(4, int(risk)))
    except (TypeError, ValueError):
        risk = 2

    verdict = str(data.get("verdict") or "")
    if verdict not in ("useful", "neutral", "caution", "risky", "avoid"):
        verdict = {0: "useful", 1: "neutral", 2: "caution", 3: "risky", 4: "avoid"}[risk]

    def _list(key: str, cap: int) -> list:
        value = data.get(key)
        if not isinstance(value, list):
            return []
        return [str(x)[:80] for x in value[:cap] if str(x).strip()]

    ingredient = {
        "name_ru": str(data.get("name_ru"))[:200],
        "name_int": str(data.get("name_int") or "")[:200],
        "kind": str(data.get("kind") or "both"),
        "risk": risk,
        "verdict": verdict,
        "category": str(data.get("category") or "")[:60],
        "e_code": str(data.get("e_code") or "")[:16],
        "summary": str(data.get("summary") or "")[:400],
        "details": str(data.get("details") or "")[:4000],
        "synonyms": _list("synonyms", 12),
        "avoid": [c for c in _list("avoid", 12) if c in {
            "allergy", "pregnancy", "vegan", "vegetarian", "diabetes", "hypertension",
            "kidney", "pku", "asthma", "kids", "sensitive", "acne", "dry", "oily"}],
        "sources": _list("sources", 8),
    }

    try:
        confidence = max(0, min(100, int(data.get("confidence") or 60)))
    except (TypeError, ValueError):
        confidence = 60

    result = {
        "run_id": run_id, "mode": "study", "status": "done",
        "unknown_id": unknown_id,
        "name": name,
        "ingredient": ingredient,
        "confidence": confidence,
        "model": model_used,
        "elapsed_ms": elapsed,
        "usage": usage if isinstance(usage, dict) else {},
        "error": "",
        # Переизучение из справочника: сайт заменит карточку ИИ новой. Флаг
        # приходит в задаче, поэтому воркер обязан вернуть его обратно —
        # иначе приёмка не отличит повторное изучение от первого.
        "refresh": bool(payload.get("refresh")),
    }

    await ctx.progress("Отправляю карточку на сайт…", f"«{name}»")
    delivered, reason = await _post(ctx, result)
    result["delivered"] = delivered

    if delivered:
        await ctx.progress("Карточка передана на сайт", f"«{name}»")
    else:
        # Результат остаётся в job.result — сайт заберёт его дожимом
        # (Service_Nj646_Store::run_catchup), но причину видно в мониторе.
        await ctx.progress("Сайт не принял результат", reason[:200])

    log.info("nj646[study] run=%s «%s» risk=%d, %d мс, доставлено=%s",
             run_id, name, risk, elapsed, delivered)
    return result
