"""Мультимодальные эмбеддинги: текст, картинка и «текст + картинка» — один вектор.

ЗАЧЕМ ОТДЕЛЬНЫЙ МОДУЛЬ. `llm.EmbeddingClient` умеет ровно одно: список СТРОК →
список векторов (`{"input": ["текст"]}`). Мультимодальная модель
(Qwen3-VL-Embedding и родня) принимает ещё и картинку, причём единого формата
запроса у серверов пока НЕТ: llama.cpp, LM Studio, vLLM и mlx-serve ждут
json-тело разной формы. Ломать текстовый клиент под это нельзя — на нём висит
nj115 (поиск по смыслу), поэтому мультимодальный путь живёт здесь.

ГЛАВНАЯ ИДЕЯ — ДИАЛЕКТЫ. Клиент не угадывает сервер по версии и не требует
настройки: он по очереди пробует известные формы запроса, запоминает первую
рабочую и дальше ходит только ею. Диалект можно зафиксировать руками
(`EMBEDDING_MM_DIALECT`), если сервер отвечает 200 на «неправильную» форму.

Известные диалекты (порядок проб — от самого распространённого):

  omni_messages  {"messages": [[{"role":"user","content":[…]}], …], "dimensions": N}
                 пачка чат-сообщений: один список сообщений = один вектор.
                 Так говорит свой сервис мультимодальных эмбеддингов
                 (OmniEmbed на Qwen3-VL-Embedding) и vLLM
  cohere_inputs  POST /v2/embed {"inputs": [{"content": [{"type":"text",…},
                                                         {"type":"image_url",…}]}]}
                 Cohere Embed v2 — второй вход того же сервиса, пачкой
  qwen_objects   {"input": [{"text": "...", "image": "data:image/jpeg;base64,..."}]}
                 родной формат Qwen3-VL-Embedding, его же понимает
                 llama-vl-embedding и часть самодельных OpenAI-обёрток
  oai_content    {"input": [{"content": [{"type":"image_url","image_url":{"url":…}},
                                         {"type":"text","text":"…"}]}]}
                 llama.cpp (server, мультимодальные эмбеддинги)
  oai_parts      {"input": [{"type":"image_url",…}, {"type":"text",…}]}
                 плоский вариант тех же частей (mlx-serve)
  chat_messages  {"messages": [{"role":"user","content":[…]}]}  — vLLM
  media_prompt   {"input": {"prompt_string":"…<__media__>","multimodal_data":[b64]}}
                 старый обходной путь llama.cpp
  plain_text     {"input": ["текст"]} — обычный OpenAI; картинки не поддерживает

ПОДКЛЮЧАЕМСЯ ТАК ЖЕ, КАК ЧАТ. Сервер у владельца один (LM Studio), но
переменные исторически разные: чат (/meal) ходит через реестр провайдеров
(`<ИМЯ>_API_URL` + токен + прокси), а эмбеддинги — через EMBEDDING_*. Пока
EMBEDDING_API_KEY был пуст, /meal работал, а /veclab получал отказ. Поэтому
клиент ищет в реестре провайдера с ТЕМ ЖЕ адресом и берёт его токен и прокси,
понимает спеку «TOOLFORGE::модель» и, если адрес не задан вовсе, идёт на
сервер чата.

ПОЧЕМУ ЧАТ ГРУЗИТ МОДЕЛЬ, А ЭМБЕДДИНГИ — НЕТ. LM Studio делит модели на типы
(`GET /api/v0/models`: llm, vlm, embeddings) и JIT-загрузка смотрит на ТИП
эндпоинта: `/v1/chat/completions` поднимает llm и vlm, `/v1/embeddings` —
только embeddings. GGUF-сборка Qwen3-VL-Embedding числится у него как `llm`
(видно в «My Models»: модель стоит в разделе LLM, а не Text Embedding),
поэтому на `/v1/embeddings` приходит «No models loaded» — даже когда имя
точное, JIT включён, а /meal с той же моделью работает. Руками её загрузить
тоже мало: эндпоинт эмбеддингов такую модель не отдаст.

ОБХОДНОЙ ПУТЬ — РЕЖИМ ОПИСАНИЯ (`caption`). Когда сервер не умеет
мультимодальные эмбеддинги, картинка идёт тем же путём, что в /meal: её
описывает vision-модель через `/v1/chat/completions`, а описание вместе с
подписью превращает в вектор ОБЫЧНАЯ текстовая эмбеддинг-модель (на сервере
она есть и грузится сама). Это не одно пространство «картинка-текст», а
«картинка → слова → вектор»: поиск словами работает, качество зависит от
описания. Режим виден в паспорте и на странице — молча подменять механизм
нельзя. Выключается `EMBEDDING_MM_FALLBACK=off`.

ИМЯ МОДЕЛИ СВЕРЯЕТСЯ С СЕРВЕРОМ. LM Studio поднимает модель сам (JIT), но
только когда имя в запросе совпадает с id скачанной модели; иначе отвечает
«No models loaded», будто виноват формат. `ensure_model()` берёт список из
`GET /models` и подставляет точное имя — ровно как чат повторяет запрос на
модели, которая у провайдера есть.

Env (читается ТОЛЬКО внутри функций — правило воркеров):

  EMBEDDING_MM_API_URL      адрес сервера (пусто → общий EMBEDDING_API_URL →
                            адрес LLM-провайдера по умолчанию)
  EMBEDDING_MM_API_KEY      токен (пусто → EMBEDDING_API_KEY → токен
                            LLM-провайдера с тем же адресом → LM_API_TOKEN)
  EMBEDDING_MM_MODEL        имя модели (пусто → EMBEDDING_MODEL)
  EMBEDDING_MM_DIMENSIONS   ожидаемая размерность (пусто → EMBEDDING_DIMENSIONS)
  EMBEDDING_MM_DIALECT      зафиксировать диалект (пусто = автоопределение)
  EMBEDDING_MM_TIMEOUT      ожидание ответа, секунд (по умолчанию 120)
  EMBEDDING_MM_BATCH        сколько входов в одном запросе (по умолчанию 8)
  EMBEDDING_MM_FALLBACK     off — запретить режим описания (по умолчанию auto)
  EMBEDDING_MM_CAPTION_MODEL  vision-модель для описаний (пусто → NJ653_VISION_MODEL
                            → VLM_MODEL → модель провайдера по умолчанию)
  EMBEDDING_TEXT_MODEL      текстовая эмбеддинг-модель для режима описания
                            (пусто → первая модель типа embeddings на сервере)

Векторы наружу всегда L2-нормированы: косинус = скалярное произведение, и
сравнение не зависит от того, нормирует ли модель сама.
"""

from __future__ import annotations

import asyncio
import logging
import math
import os
import re
import time
from dataclasses import dataclass
from typing import Any, Iterable, Sequence

import httpx

from .llm import (
    Provider,
    _looks_local,
    _resolve_key_src,
    default_provider_name,
    provider_for_url,
    resolve_model,
    scan_providers,
)

log = logging.getLogger("n2j.mmembed")

#: Порядок проб диалектов. plain_text последний: он всегда «работает», но
#: картинку молча выбрасывает — поставь его раньше, и мультимодальность
#: превратилась бы в текстовый поиск без единого сообщения об ошибке.
DIALECTS: tuple[str, ...] = (
    "omni_messages",
    "cohere_inputs",
    "qwen_objects",
    "oai_content",
    "oai_parts",
    "chat_messages",
    "media_prompt",
    "plain_text",
)

#: Диалекты, которые умеют отдать НЕСКОЛЬКО векторов за один запрос.
BATCHABLE = {"omni_messages", "cohere_inputs", "qwen_objects", "oai_content", "plain_text"}

#: Диалекты, которые ходят НЕ в <base>/embeddings. Ключ → путь от корня
#: сервера (base_url без хвостового /v1).
DIALECT_PATHS = {"cohere_inputs": "/v2/embed"}

#: Человеческие подписи — их показывает страница /veclab и nj653_doctor.
DIALECT_TITLES = {
    "omni_messages": "Чат-сообщения пачкой: messages=[[{role, content}]] (OmniEmbed, vLLM)",
    "cohere_inputs": "Cohere Embed v2: /v2/embed inputs=[{content:[…]}]",
    "qwen_objects": "Qwen-объекты: input=[{text, image}]",
    "oai_content": "OpenAI-обёртка: input=[{content:[…]}] (llama.cpp)",
    "oai_parts": "OpenAI-части: input=[{type:image_url}, {type:text}]",
    "chat_messages": "Чат-сообщения: messages=[{role, content}] (vLLM)",
    "media_prompt": "llama.cpp: prompt_string + multimodal_data",
    "plain_text": "Обычный текстовый OpenAI: input=[«строка»]",
}

#: Границы матрёшки Qwen3-VL-Embedding: сервис принимает dimensions 64…2048.
MIN_DIMENSIONS = 64
MAX_DIMENSIONS = 2048

#: Куда в тексте подставляется картинка у llama.cpp (диалект media_prompt).
#: ВАЖНО: без переменной окружения LLAMA_MEDIA_MARKER сервер выбирает маркер
#: СЛУЧАЙНО при старте, и зашитый «<__media__>» не совпадёт — каждый запрос
#: с картинкой вернёт 500 «Failed to tokenize prompt». Настоящий маркер
#: сервер показывает в GET /props → media_marker, поэтому клиент его там и
#: спрашивает (см. MmEmbeddingClient.server_probe).
MEDIA_MARKER = "<__media__>"

#: 1×1 PNG — достаточно, чтобы узнать, принимает ли сервер картинку вообще.
#: Для проверки СМЫСЛА он не годится (на нём ничего не изображено), поэтому
#: проба на смысл делает свою картинку сама (см. nj653_doctor / handler).
PIXEL_PNG_B64 = (
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmM"
    "IQAAAABJRU5ErkJggg=="
)


class MmEmbedError(RuntimeError):
    """Эмбеддинг-сервер не смог ответить — с причиной, пригодной для человека."""


#: Что сервер говорит о СЕБЕ, а не о нашем формате запроса. Пока такая беда не
#: устранена, перебор диалектов бессмысленен: ответ на все шесть будет один и
#: тот же (владелец 05.10.2026 увидел шесть строк «HTTP 400: No models loaded»
#: и совет «проверьте, что модель мультимодальная» — совет был не по делу).
SERVER_FAULTS: tuple[tuple[str, str, str], ...] = (
    (
        "no models loaded",
        "на сервере НЕ ЗАГРУЖЕНА ни одна модель",
        "Загрузите модель: в LM Studio — вкладка Developer → Load model, "
        "или командой «lms load {model}». Чтобы сервер поднимал её сам по "
        "первому запросу, включите Just-In-Time model loading "
        "(Developer → Settings) — иначе после перезапуска LM Studio всё "
        "повторится.",
    ),
    (
        "сеть:",
        "сервер эмбеддингов недоступен по сети",
        "Проверьте EMBEDDING_API_URL и то, что сервер запущен и слушает "
        "снаружи (в LM Studio — Developer → Settings → Serve on local network).",
    ),
    (
        "model_not_found",
        "сервер не знает модель с таким именем",
        "Возьмите имя из списка ниже (оно же — вывод «lms ls») и впишите его "
        "в EMBEDDING_MODEL без изменений.",
    ),
    (
        "model not found",
        "сервер не знает модель с таким именем",
        "Возьмите имя из списка ниже (оно же — вывод «lms ls») и впишите его "
        "в EMBEDDING_MODEL без изменений.",
    ),
    (
        "api token is required",
        "сервер требует токен доступа",
        "Задайте EMBEDDING_MM_API_KEY в workers/.env: у своего сервиса "
        "эмбеддингов это ключ вида «oes_…», у LM Studio — токен из "
        "Developer → Settings → API tokens (там же подойдёт LM_API_TOKEN).",
    ),
    (
        "invalid_api_key",
        "сервер не принял токен доступа",
        "Проверьте EMBEDDING_API_KEY (или LM_API_TOKEN) в workers/.env.",
    ),
    (
        "authentication_error",
        "сервер не принял токен доступа",
        "Ключ сервиса эмбеддингов (он начинается с «oes_») кладётся в "
        "EMBEDDING_MM_API_KEY в workers/.env и уходит заголовком "
        "Authorization: Bearer.",
    ),
    (
        "missing api key",
        "сервер требует токен доступа",
        "Ключ сервиса эмбеддингов (он начинается с «oes_») кладётся в "
        "EMBEDDING_MM_API_KEY в workers/.env и уходит заголовком "
        "Authorization: Bearer.",
    ),
    (
        "требует токен",
        "сервер требует токен доступа",
        "Задайте EMBEDDING_MM_API_KEY в workers/.env: у своего сервиса "
        "эмбеддингов это ключ вида «oes_…», у LM Studio — токен из "
        "Developer → Settings → API tokens (там же подойдёт LM_API_TOKEN).",
    ),
    (
        "is not an embedding model",
        "эта модель не умеет эмбеддинги (загружена чат-модель)",
        "Загрузите именно embedding-модель; чат-модель вектора не отдаёт.",
    ),
    (
        "unexpected endpoint",
        "адрес сервера указывает не на OpenAI-совместимый /v1",
        "EMBEDDING_API_URL должен оканчиваться на /v1 (без /embeddings).",
    ),
    (
        "404",
        "сервер не отдаёт /v1/embeddings по этому адресу",
        "Проверьте EMBEDDING_API_URL: он должен оканчиваться на /v1.",
    ),
)


@dataclass
class EmbedInput:
    """Один вход модели: текст, картинка или и то и другое вместе.

    `instruction` — подсказка задачи («Найди похожие изображения»). Модели
    серии Qwen3-VL-Embedding instruction-aware: та же картинка с разной
    инструкцией даёт разные векторы. Сервер может её игнорировать — это не
    ошибка.
    """

    text: str = ""
    image_b64: str = ""            # base64 БЕЗ префикса data:
    media_type: str = "image/jpeg"
    instruction: str = ""

    def __post_init__(self) -> None:
        self.text = (self.text or "").strip()
        self.image_b64 = (self.image_b64 or "").strip()
        self.instruction = (self.instruction or "").strip()

        if not self.text and not self.image_b64:
            raise ValueError("вход эмбеддинга пуст: нужен текст, картинка или оба")

    @property
    def has_image(self) -> bool:
        return bool(self.image_b64)

    @property
    def kind(self) -> str:
        """text | image | both — подпись модальности для журналов и страницы."""
        if self.text and self.image_b64:
            return "both"

        return "image" if self.image_b64 else "text"

    def data_url(self) -> str:
        mime = self.media_type if str(self.media_type).startswith("image/") else "image/jpeg"

        return f"data:{mime};base64,{self.image_b64}"


# --------------------------------------------------------------------------
# Сборка тела запроса под каждый диалект
# --------------------------------------------------------------------------

def _one_qwen(item: EmbedInput) -> dict:
    out: dict[str, Any] = {}

    if item.text:
        out["text"] = item.text
    if item.image_b64:
        out["image"] = item.data_url()
    if item.instruction:
        out["instruction"] = item.instruction

    return out


def _parts(item: EmbedInput) -> list[dict]:
    """Части содержимого в терминах OpenAI: сначала картинка, потом текст.

    Порядок не случаен: у vision-моделей картинка перед текстом даёт заметно
    более устойчивый результат, так же собирает сообщение `vlm.img_msg`.
    """
    parts: list[dict] = []

    if item.image_b64:
        parts.append({"type": "image_url", "image_url": {"url": item.data_url()}})

    text = (item.instruction + "\n" + item.text).strip() if item.instruction else item.text

    if text:
        parts.append({"type": "text", "text": text})

    return parts


def _find_key(data: Any, key: str) -> Any:
    """Найти значение по имени ключа где угодно внутри JSON-ответа.

    `/props` у llama.cpp менялся от версии к версии: media_marker лежит то в
    корне, то внутри default_generation_settings. Ищем по имени, а не по
    месту — иначе «работает только на моей сборке».
    """
    if isinstance(data, dict):
        if key in data:
            return data[key]

        for value in data.values():
            found = _find_key(value, key)

            if found is not None:
                return found

    elif isinstance(data, list):
        for value in data:
            found = _find_key(value, key)

            if found is not None:
                return found

    return None


#: Инструкция по умолчанию для диалекта omni_messages.
#: Qwen3-VL-Embedding instruction-aware: без инструкции вектор получается
#: «средним», а с нею — заточенным под поиск. Строка из документации сервиса.
DEFAULT_INSTRUCTION = "Represent the user input for image retrieval."


def _messages(item: EmbedInput) -> list[dict]:
    """Один вход как разговор из трёх сообщений: system → user → assistant.

    Форма взята из документации сервиса (vLLM-совместимый вход):

    * инструкция — ОТДЕЛЬНОЕ system-сообщение, а не приклеенный к тексту
      префикс: модели серии Qwen3-VL-Embedding instruction-aware, и ровно так
      инструкцию ждёт их шаблон чата;
    * содержимое сообщений — всегда список частей (`{"type": "text"…}`), даже
      когда часть одна: со строкой шаблон чата ведёт себя иначе;
    * последним идёт ПУСТОЕ assistant-сообщение, и вместе с
      `continue_final_message` оно говорит серверу «разговор не начинай
      заново, дай вектор последнего токена». Без него pooling берёт не ту
      позицию, и вектор получается мусорным.
    """
    out: list[dict] = [{
        "role": "system",
        "content": [{"type": "text", "text": item.instruction or DEFAULT_INSTRUCTION}],
    }]

    parts: list[dict] = []

    if item.image_b64:
        parts.append({"type": "image_url", "image_url": {"url": item.data_url()}})

    if item.text:
        parts.append({"type": "text", "text": item.text})

    out.append({"role": "user", "content": parts or [{"type": "text", "text": item.text}]})
    out.append({"role": "assistant", "content": [{"type": "text", "text": ""}]})

    return out


def _clamp_dims(dimensions: int) -> int:
    """Ширина вектора в границах матрёшки сервиса: 64…2048, иначе 0 (не слать).

    Сервис отвечает 422 на значение вне диапазона, а молча получить 422 из-за
    опечатки в EMBEDDING_MM_DIMENSIONS — худший способ это узнать.
    """
    dims = int(dimensions or 0)

    if dims <= 0:
        return 0

    if dims < MIN_DIMENSIONS or dims > MAX_DIMENSIONS:
        log.warning("mmembed: dimensions=%s вне диапазона %s…%s — не навязываем "
                    "ширину серверу, вектор подрежем у себя",
                    dims, MIN_DIMENSIONS, MAX_DIMENSIONS)

        return 0

    return dims


def build_body(dialect: str, items: Sequence[EmbedInput], model: str,
               media_marker: str = MEDIA_MARKER, dimensions: int = 0) -> dict:
    """Тело POST /embeddings для выбранного диалекта.

    Вынесено отдельно от сети: именно это проверяют тесты
    (`workers/tests/test_nj653_mmembed.py`) — без живого сервера.
    """
    if not items:
        raise ValueError("нечего отправлять: пустой список входов")

    body: dict[str, Any] = {"model": model} if model else {}

    if dialect == "omni_messages":
        # Пачка: каждый элемент — свой список сообщений, свой вектор.
        body["messages"] = [_messages(i) for i in items]
        body["encoding_format"] = "float"
        # Пара флагов из документации сервиса: разговор не начинать заново,
        # спецтокены шаблона проставить. Без них вектор снимается не с того
        # токена (см. _messages).
        body["continue_final_message"] = True
        body["add_special_tokens"] = True

        if _clamp_dims(dimensions):
            # Matryoshka прямо на сервере: меньше трафика и та же точность,
            # что при усечении у нас (fit_dims остаётся страховкой).
            body["dimensions"] = _clamp_dims(dimensions)

    elif dialect == "cohere_inputs":
        body["inputs"] = [{"content": _parts(i)} for i in items]
        body["embedding_types"] = ["float"]

        if _clamp_dims(dimensions):
            body["output_dimension"] = _clamp_dims(dimensions)

    elif dialect == "qwen_objects":
        body["input"] = [_one_qwen(i) for i in items]

    elif dialect == "oai_content":
        body["input"] = [{"content": _parts(i)} for i in items]

    elif dialect == "oai_parts":
        if len(items) > 1:
            raise ValueError("диалект oai_parts принимает один вход за запрос")

        body["input"] = _parts(items[0])

    elif dialect == "chat_messages":
        if len(items) > 1:
            raise ValueError("диалект chat_messages принимает один вход за запрос")

        body["messages"] = [{"role": "user", "content": _parts(items[0])}]
        body["encoding_format"] = "float"

    elif dialect == "media_prompt":
        if len(items) > 1:
            raise ValueError("диалект media_prompt принимает один вход за запрос")

        item = items[0]
        text = (item.instruction + "\n" + item.text).strip() if item.instruction else item.text
        marker = media_marker or MEDIA_MARKER
        prompt = (text + marker) if item.image_b64 else text
        payload: dict[str, Any] = {"prompt_string": prompt or marker}

        if item.image_b64:
            payload["multimodal_data"] = [item.image_b64]

        body["input"] = payload

    elif dialect == "plain_text":
        # Картинка сюда не влезает физически: честно говорим об этом вызову,
        # а не отправляем «текстовую часть» и не выдаём её за мультимодальность.
        if any(i.has_image for i in items):
            raise ValueError("диалект plain_text не умеет картинки")

        body["input"] = [
            (i.instruction + "\n" + i.text).strip() if i.instruction else i.text
            for i in items
        ]

    else:
        raise ValueError(f"неизвестный диалект эмбеддингов: {dialect!r}")

    return body


def parse_vectors(data: Any) -> list[list[float]]:
    """Ответ сервера → список векторов (в порядке входов).

    Понимает обе формы: OpenAI (`data[].embedding` с `index`) и «голый»
    список чисел, который отдают некоторые самодельные обёртки.
    """
    if isinstance(data, dict):
        # Cohere Embed v2: {"embeddings": {"float": [[…], …]}}
        groups = data.get("embeddings")

        if isinstance(groups, dict):
            floats = groups.get("float")

            if isinstance(floats, list) and floats and isinstance(floats[0], list):
                return [[float(x) for x in row] for row in floats]

        if isinstance(groups, list) and groups and isinstance(groups[0], list):
            return [[float(x) for x in row] for row in groups]

        rows = data.get("data")

        if isinstance(rows, list) and rows:
            try:
                rows = sorted(rows, key=lambda r: int(r.get("index", 0)))
            except (TypeError, ValueError):
                pass

            out: list[list[float]] = []

            for row in rows:
                vec = row.get("embedding") if isinstance(row, dict) else None

                if not isinstance(vec, list) or not vec:
                    raise MmEmbedError("в ответе сервера нет поля embedding")

                out.append([float(x) for x in vec])

            return out

        if isinstance(data.get("embedding"), list):
            return [[float(x) for x in data["embedding"]]]

    if isinstance(data, list) and data and isinstance(data[0], (int, float)):
        return [[float(x) for x in data]]

    if isinstance(data, list) and data and isinstance(data[0], list):
        return [[float(x) for x in row] for row in data]

    raise MmEmbedError("ответ сервера не похож на эмбеддинги")


# --------------------------------------------------------------------------
# Векторная арифметика (общая для воркера и тестов)
# --------------------------------------------------------------------------

def l2_normalize(vector: Sequence[float]) -> list[float]:
    """Привести вектор к единичной длине. Нулевой вектор возвращаем как есть."""
    norm = math.sqrt(sum(float(x) * float(x) for x in vector))

    if norm <= 0:
        return [float(x) for x in vector]

    return [float(x) / norm for x in vector]


def cosine(a: Sequence[float], b: Sequence[float]) -> float:
    """Косинусная близость. Разные размерности — это ошибка, а не «0.0»."""
    if len(a) != len(b):
        raise ValueError(f"векторы разной длины: {len(a)} и {len(b)}")

    na = math.sqrt(sum(float(x) * float(x) for x in a))
    nb = math.sqrt(sum(float(x) * float(x) for x in b))

    if na <= 0 or nb <= 0:
        return 0.0

    dot = sum(float(x) * float(y) for x, y in zip(a, b))

    return max(-1.0, min(1.0, dot / (na * nb)))


def fit_dims(vector: Sequence[float], dims: int) -> list[float]:
    """Подогнать вектор под размерность хранилища (Matryoshka-усечение).

    Модели серии Qwen3-VL-Embedding обучены так, что первые N координат —
    самостоятельный вектор меньшей размерности (MRL). Поэтому «лишние»
    координаты можно отрезать и перенормировать: это штатный режим, а не
    порча данных. Если же модель отдала МЕНЬШЕ, чем ждёт база, молча дополнять
    нулями нельзя — вызов обязан увидеть ошибку.
    """
    dims = int(dims or 0)
    out = [float(x) for x in vector]

    if dims <= 0 or len(out) == dims:
        return l2_normalize(out)

    if len(out) > dims:
        return l2_normalize(out[:dims])

    raise MmEmbedError(
        f"модель вернула вектор на {len(out)} чисел, а хранилище ждёт {dims}. "
        f"Поправьте EMBEDDING_DIMENSIONS (и миграцию nj653) под реальную модель")


# --------------------------------------------------------------------------
# Клиент
# --------------------------------------------------------------------------

@dataclass
class Attempt:
    """Результат одной пробы диалекта — для таблицы «проверка модели»."""

    dialect: str
    ok: bool
    ms: int = 0
    dims: int = 0
    error: str = ""

    def as_dict(self) -> dict:
        return {
            "dialect": self.dialect,
            "title": DIALECT_TITLES.get(self.dialect, self.dialect),
            "ok": self.ok,
            "ms": self.ms,
            "dims": self.dims,
            "error": self.error,
        }


class MmEmbeddingClient:
    """Клиент мультимодального эмбеддинг-сервера с автоопределением диалекта."""

    def __init__(self, base_url: str, api_key: str, model: str,
                 dimensions: int = 0, batch_size: int = 1,
                 timeout: float = 120.0, dialect: str = "",
                 api_key_src: str = "", proxy: str = "", provider: str = "",
                 fallback: str = "auto", caption_model: str = "",
                 text_model: str = ""):
        self.base_url = (base_url or "").rstrip("/")
        #: Прокси и имя LLM-провайдера — чтобы ходить к серверу ровно так же,
        #: как ходит чат (/meal): тот же адрес, тот же токен, тот же прокси.
        self.proxy = (proxy or "").strip()
        self.provider = (provider or "").strip()
        #: Чем закончилась сверка имени модели со списком сервера (для паспорта).
        self.model_note = ""
        self._model_checked = False
        self._models: tuple[list[str], str] | None = None
        #: Сведения LM Studio о моделях: id → {type, state, arch}.
        self._kinds: dict[str, dict] | None = None
        #: Что за сервер на том конце и его маркер картинки (llama.cpp).
        self.software = ""
        self._health: dict | None = None
        self.media_marker = MEDIA_MARKER

        #: «native» — сервер считает вектор прямо по картинке;
        #: «caption» — картинку описывает vision-модель (как /meal), а вектор
        #: даёт текстовая эмбеддинг-модель. Режим виден в паспорте.
        self.mode = "native"
        self.caption_note = ""
        self.model = (model or "").strip()
        #: Имя, которое просил владелец: в режиме описания self.model меняется
        #: на текстовую модель, а диагноз должен говорить про исходную.
        self.native_model = self.model
        self.dimensions = int(dimensions or 0)
        self.batch_size = max(1, int(batch_size or 1))
        self.timeout = float(timeout or 120.0)
        self.api_key = (api_key or "").strip()
        self.api_key_src = api_key_src

        #: Разрешён ли режим описания и чем его делать.
        self.fallback = (fallback or "auto").strip().lower()
        self.caption_model = (caption_model or "").strip()
        self.text_model = (text_model or "").strip()

        # Зафиксированный владельцем диалект главнее автоопределения.
        self.forced_dialect = (dialect or "").strip()

        if self.forced_dialect and self.forced_dialect not in DIALECTS:
            raise ValueError(
                f"EMBEDDING_MM_DIALECT={self.forced_dialect!r} — такого диалекта нет. "
                f"Доступны: {', '.join(DIALECTS)}")

        self.dialect = self.forced_dialect
        #: Журнал последнего автоопределения (его показывает страница модуля).
        self.attempts: list[Attempt] = []
        self._client: httpx.AsyncClient | None = None
        self._lock = asyncio.Lock()

    # ------------------------------------------------------------- фабрика

    @classmethod
    def from_env(cls) -> "MmEmbeddingClient | None":
        """Клиент из окружения или None, если сервер эмбеддингов не настроен.

        Подключение собирается ТАК ЖЕ, как у чата (`llm.LlmClient`, на нём
        работает /meal): адрес, токен и прокси берутся у провайдера из реестра
        `<ИМЯ>_API_URL`, если он ходит на тот же сервер. Иначе получалось
        глупо — /meal идёт на llm.toolforge.ru с токеном из TOOLFORGE_API_KEY,
        а эмбеддинги на тот же адрес без заголовка Authorization.
        """
        spec = (os.getenv("EMBEDDING_MM_MODEL") or os.getenv("EMBEDDING_MODEL") or "").strip()
        base = (os.getenv("EMBEDDING_MM_API_URL") or os.getenv("EMBEDDING_API_URL") or "").strip()
        providers = scan_providers()
        model = spec
        twin: Provider | None = None

        if "::" in spec:
            # «TOOLFORGE::qwen3-vl-embedding-2b» — ровно та же спека, что у
            # моделей модулей (NJ651_MODEL и родня).
            twin, model = resolve_model(providers, default_provider_name(providers), spec)
            base = (twin.base_url if twin else base) or base

        if not base:
            # Адрес не задан вовсе — берём сервер чата: у владельца он один.
            twin = twin or providers.get(default_provider_name(providers))
            base = twin.base_url if twin else ""

        if not base:
            return None

        key = (os.getenv("EMBEDDING_MM_API_KEY") or os.getenv("EMBEDDING_API_KEY") or "").strip()
        src = "EMBEDDING_MM_API_KEY" if os.getenv("EMBEDDING_MM_API_KEY") else "EMBEDDING_API_KEY"

        if not key and (os.getenv("OMNI_EMBED_KEY") or "").strip():
            # Имя переменной из документации сервиса — пусть работает и оно.
            key, src = os.environ["OMNI_EMBED_KEY"].strip(), "OMNI_EMBED_KEY"

        if not key:
            # LM Studio 0.3.x требует Bearer даже локально — та же логика, что
            # у текстового клиента (llm.EmbeddingClient), поэтому берём её, а
            # не пишем второй раз (AGENTS §17).
            key, src = _resolve_key_src("EMBEDDING", base)

        twin = twin or provider_for_url(base, providers)

        if not key and twin and twin.api_key:
            # Тот же сервер, что у чата, — значит, и токен тот же.
            key, src = twin.api_key, (twin.api_key_src or twin.name + "_API_KEY")
            log.info("mmembed: токен для %s взят у LLM-провайдера %s (%s)",
                     base, twin.name, src)

        if not key:
            log.warning("Мультимодальный эмбеддинг-сервер (%s) настроен БЕЗ токена: "
                        "свой сервис эмбеддингов и LM Studio ответят 401. Задайте "
                        "EMBEDDING_MM_API_KEY в workers/.env (ключ сервиса начинается "
                        "с «oes_»)", base)

        return cls(
            base_url=base,
            api_key=key,
            api_key_src=src if key else "",
            model=model,
            dimensions=_env_int("EMBEDDING_MM_DIMENSIONS", _env_int("EMBEDDING_DIMENSIONS", 0)),
            # По умолчанию 1: пачка меняет вектор каждого входа (см. _chunks).
            batch_size=_env_int("EMBEDDING_MM_BATCH", 1),
            timeout=_env_float("EMBEDDING_MM_TIMEOUT", 120.0),
            dialect=(os.getenv("EMBEDDING_MM_DIALECT") or "").strip(),
            # Свой прокси важнее провайдерского: сервис эмбеддингов живёт на
            # своём домене, и наружу воркер может ходить иначе, чем в чат.
            proxy=((os.getenv("EMBEDDING_MM_PROXY") or "").strip()
                   or (twin.proxy if twin else "")),
            provider=(twin.name if twin else ""),
            fallback=(os.getenv("EMBEDDING_MM_FALLBACK") or "auto").strip(),
            caption_model=(os.getenv("EMBEDDING_MM_CAPTION_MODEL")
                           or os.getenv("NJ653_VISION_MODEL")
                           or os.getenv("VLM_MODEL") or "").strip(),
            text_model=(os.getenv("EMBEDDING_TEXT_MODEL") or "").strip(),
        )

    # ------------------------------------------------------------- сеть

    def root(self) -> str:
        """Корень сервера: base_url без хвостового /v1 (там живёт /v2 и /healthz)."""
        return self.base_url[:-3].rstrip("/") if self.base_url.endswith("/v1") else self.base_url

    def endpoint(self, dialect: str) -> str:
        """Куда стучаться этим диалектом. Почти всегда <base>/embeddings."""
        path = DIALECT_PATHS.get(dialect)

        return (self.root() + path) if path else (self.base_url + "/embeddings")

    def _http(self) -> httpx.AsyncClient:
        if self._client is None:
            headers = {"Authorization": f"Bearer {self.api_key}"} if self.api_key else {}
            self._client = httpx.AsyncClient(
                timeout=self.timeout, headers=headers,
                **({"proxy": self.proxy} if self.proxy else {}))

        return self._client

    async def close(self) -> None:
        if self._client is not None:
            await self._client.aclose()
            self._client = None

    async def _post(self, dialect: str, items: Sequence[EmbedInput]) -> list[list[float]]:
        """Один запрос выбранным диалектом. Любая беда — MmEmbedError с причиной."""
        body = build_body(dialect, items, self.model, self.media_marker, self.dimensions)

        try:
            r = await self._http().post(self.endpoint(dialect), json=body)
        except Exception as e:  # noqa: BLE001 — сеть не должна ронять воркер молча
            raise MmEmbedError(f"сеть: {e.__class__.__name__}: {e}") from e

        if r.status_code in (401, 403):
            hint = "LM_API_TOKEN" if _looks_local(self.base_url) else "EMBEDDING_MM_API_KEY"
            whence = (f"токен отправляли из {self.api_key_src}" if self.api_key_src
                      else "токен не отправляли вовсе")

            raise MmEmbedError(
                f"сервер эмбеддингов ({self.base_url}) требует токен: HTTP {r.status_code}, "
                f"{whence}. Задайте {hint} в workers/.env и перезапустите воркер")

        if r.status_code != 200:
            raise MmEmbedError(f"HTTP {r.status_code}: {(r.text or '').strip()[:300]}")

        try:
            data = r.json()
        except Exception as e:  # noqa: BLE001
            raise MmEmbedError(f"ответ сервера не JSON: {(r.text or '')[:200]}") from e

        vectors = parse_vectors(data)

        if len(vectors) != len(items):
            raise MmEmbedError(
                f"сервер вернул {len(vectors)} векторов на {len(items)} входов")

        return vectors

    async def list_models(self, force: bool = False) -> tuple[list[str], str]:
        """Что сервер сам считает доступным: (список имён, причина отказа).

        Нужен ровно для одного вопроса — «имя модели в .env вообще существует
        на этом сервере?». Без него диагноз выглядит как гадание, а владелец
        видит шесть одинаковых HTTP 400 и не знает, куда смотреть.
        """
        if not self.base_url:
            return [], "адрес сервера не задан"

        if self._models is not None and not force:
            # Один прогон = один GET /models: лишние запросы светятся в логе
            # LM Studio, и владелец уже спрашивал, кто его опрашивает.
            return self._models

        try:
            r = await self._http().get(f"{self.base_url}/models")
        except Exception as e:  # noqa: BLE001
            return self._remember([], f"сеть: {e.__class__.__name__}: {e}")

        if r.status_code != 200:
            return self._remember([], f"HTTP {r.status_code}: {(r.text or '').strip()[:200]}")

        try:
            data = r.json()
        except Exception:  # noqa: BLE001
            return self._remember([], f"ответ не JSON: {(r.text or '')[:150]}")

        rows = data.get("data") if isinstance(data, dict) else None
        names = []

        for row in rows or []:
            name = (row or {}).get("id") if isinstance(row, dict) else None

            if isinstance(name, str) and name.strip():
                names.append(name.strip())

        return self._remember(names, "" if names else "сервер вернул пустой список моделей")

    def _remember(self, names: list[str], why: str) -> tuple[list[str], str]:
        self._models = (names, why)

        return self._models

    @staticmethod
    def _best_name(wanted: str, names: Sequence[str]) -> str:
        """Какое из имён сервера имел в виду владелец. "" — непонятно.

        LM Studio грузит модель сама (JIT), но ТОЛЬКО когда имя в запросе
        совпадает с id скачанной модели; при промахе отвечает «No models
        loaded», как будто дело в сервере. Поэтому имя из .env сверяем со
        списком и, если промах очевиден, чиним его сами — так же, как чат
        повторяет запрос на DEFAULT_MODEL провайдера.
        """
        pool = [n for n in names if n]

        if not pool:
            return ""

        if not wanted:
            # Имя не задано: берём эмбеддинг-модель, если она одна такая.
            embeds = [n for n in pool if "embed" in n.lower()]

            return embeds[0] if len(embeds) == 1 else (pool[0] if len(pool) == 1 else "")

        low = wanted.lower()

        for name in pool:                       # регистр и «владелец/модель»
            if name.lower() == low or name.lower().endswith("/" + low):
                return name

        near = [n for n in pool if low in n.lower() or n.lower() in low]

        if len(near) == 1:
            return near[0]

        # По словам: «qwen3-vl-embedding-2b» ↔ «qwen3-vl-embedding-2b-gguf».
        words = {w for w in re.split(r"[^a-z0-9]+", low) if len(w) > 1}
        scored = sorted(
            ((len(words & {w for w in re.split(r"[^a-z0-9]+", n.lower()) if len(w) > 1}), n)
             for n in pool), reverse=True)

        if scored and scored[0][0] >= max(2, len(words) - 1):
            return scored[0][1]

        return ""

    async def ensure_model(self, force: bool = False) -> str:
        """Сверить имя модели со списком сервера. Возвращает заметку о правке.

        Запрос уходит с ТОЧНЫМ именем — тогда LM Studio поднимает модель сам
        (Just-In-Time loading) и грузить руками ничего не нужно.
        """
        if self._model_checked and not force:
            return self.model_note

        self._model_checked = True
        names, why = await self.list_models(force=force)

        if not names:
            self.model_note = f"список моделей сервера недоступен ({why})" if why else ""

            return self.model_note

        if self.model and self.model in names:
            self.model_note = ""

            return ""

        best = self._best_name(self.model, names)

        if best and best != self.model:
            log.warning("mmembed: модели '%s' на сервере нет — беру '%s' из его списка "
                        "(LM Studio загрузит её сам)", self.model or "(не задана)", best)
            self.model_note = (f"в запрос подставлено имя «{best}» со списка сервера "
                               f"(в настройках было «{self.model or 'пусто'}»)")
            self.model = best
        elif self.model:
            self.model_note = (f"имени «{self.model}» нет среди моделей сервера "
                               f"({', '.join(names[:8])}) — шлём как есть")

        return self.model_note

    async def lmstudio_kinds(self) -> dict[str, dict]:
        """Типы моделей по LM Studio: id → {type, state, arch}. {} — не LM Studio.

        Это тот самый ответ на вопрос «почему чат грузит модель, а эмбеддинги
        нет»: у LM Studio JIT-загрузка привязана к ТИПУ модели, а
        `/v1/embeddings` поднимает только тип `embeddings`. Qwen3-VL-Embedding
        в GGUF числится как `llm`, и эндпоинт эмбеддингов его не берёт.
        Справочник живёт в родном REST LM Studio (`/api/v0/models`), OpenAI-
        совместимый `/v1/models` типов не отдаёт.
        """
        if self._kinds is not None:
            return self._kinds

        self._kinds = {}
        root = self.root()

        try:
            r = await self._http().get(f"{root}/api/v0/models")

            if r.status_code == 200:
                for row in (r.json() or {}).get("data") or []:
                    name = str((row or {}).get("id") or "").strip()

                    if name:
                        self._kinds[name] = {
                            "type": str(row.get("type") or ""),
                            "state": str(row.get("state") or ""),
                            "arch": str(row.get("arch") or ""),
                        }
        except Exception as e:  # noqa: BLE001 — справочник не обязателен
            log.debug("mmembed: /api/v0/models недоступен: %s", e)

        return self._kinds

    async def health(self) -> dict:
        """Паспорт своего сервиса эмбеддингов: GET /healthz. {} — не он.

        Свой сервис (OmniEmbed на Qwen3-VL-Embedding) честно отвечает, какая
        модель поднята, на каком устройстве и загружена ли она — это ровно те
        вопросы, на которые у LM Studio пришлось отвечать догадками.
        """
        if self._health is not None:
            return self._health

        self._health = {}

        try:
            r = await self._http().get(f"{self.root()}/healthz")

            if r.status_code == 200:
                data = r.json()

                if isinstance(data, dict) and data.get("service") and "model" in data:
                    self._health = data
        except Exception as e:  # noqa: BLE001 — опрос не обязателен
            log.debug("mmembed: /healthz недоступен: %s", e)

        return self._health

    async def server_probe(self) -> str:
        """Что за сервер на том конце: lmstudio | llamacpp | vllm | "".

        Узнаём заодно маркер картинки llama.cpp (GET /props → media_marker):
        он случайный при каждом старте, если владелец не задал
        LLAMA_MEDIA_MARKER, и с чужим маркером картинка не доедет.
        """
        if self.software:
            return self.software

        root = self.root()

        health = await self.health()

        if health:
            self.software = "omniembed"

            return self.software

        if await self.lmstudio_kinds():
            self.software = "lmstudio"

            return self.software

        try:
            r = await self._http().get(f"{root}/props")

            if r.status_code == 200:
                data = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
                marker = _find_key(data, "media_marker")

                if isinstance(marker, str) and marker.strip():
                    self.media_marker = marker.strip()

                self.software = "llamacpp"

                return self.software
        except Exception as e:  # noqa: BLE001 — опрос не обязателен
            log.debug("mmembed: /props недоступен: %s", e)

        try:
            r = await self._http().get(f"{root}/version")

            if r.status_code == 200 and "vllm" in (r.text or "").lower():
                self.software = "vllm"
        except Exception:  # noqa: BLE001
            pass

        return self.software

    async def _kind_verdict(self) -> str:
        """Почему именно этот сервер не берёт нашу модель в /v1/embeddings."""
        kinds = await self.lmstudio_kinds()

        if not kinds:
            return ""

        mine = kinds.get(self.native_model or self.model) or {}
        kind = mine.get("type") or ""
        embedders = [n for n, v in kinds.items() if v.get("type") == "embeddings"]

        if kind and kind != "embeddings":
            return (f"LM Studio числит «{self.native_model or self.model}» моделью типа "
                    f"{kind} (раздел LLM, а не Text Embedding), а эндпоинт /v1/embeddings "
                    f"поднимает только тип embeddings — потому чат с этой моделью работает, "
                    f"а эмбеддинги нет. Загрузка руками не поможет: тип у модели тот же. "
                    + (f"Эмбеддинг-модели на этом сервере: {', '.join(embedders[:6])}. "
                       if embedders else "Эмбеддинг-моделей на сервере нет вовсе. "))

        return ""

    async def _enable_caption(self) -> bool:
        """Включить режим описания: картинка → слова (чат) → вектор (текст).

        Возвращает False, если режим запрещён или текстовой эмбеддинг-модели
        на сервере нет: тогда честнее упасть с диагнозом, чем притворяться.
        """
        if self.fallback in ("off", "0", "no", "false") or self.mode == "caption":
            return False

        text_model = self.text_model

        if not text_model:
            kinds = await self.lmstudio_kinds()
            embedders = [n for n, v in kinds.items() if v.get("type") == "embeddings"]

            if not embedders:
                names, _ = await self.list_models()
                # Без типов (не LM Studio) берём по имени: «*embed*», но не наша.
                embedders = [n for n in names
                             if "embed" in n.lower() and n != (self.native_model or self.model)]

            text_model = embedders[0] if embedders else ""

        if not text_model:
            return False

        self.mode = "caption"
        self.text_model = text_model
        self.model = text_model
        self.dialect = "plain_text"
        self.caption_note = (
            f"картинки описывает vision-модель через /v1/chat/completions "
            f"(как в /meal), описание превращает в вектор «{text_model}». "
            f"Это не единое пространство «картинка-текст»: поиск идёт по словам "
            f"из описания")

        log.warning("mmembed: сервер не умеет мультимодальные эмбеддинги — "
                    "включён режим описания (vision-чат + текстовая модель «%s»)",
                    text_model)

        return True

    async def caption(self, item: EmbedInput) -> str:
        """Описание картинки vision-моделью — ровно тем же путём, что /meal."""
        from . import vlm                                  # ленивый импорт: циклы
        from .llm import LlmClient

        prompt = (item.instruction or
                  "Опиши, что на изображении: объект, цвет, форма, материал, фон, "
                  "надписи. Одним абзацем, по-русски, без вступлений.")
        chat = LlmClient(timeout=self.timeout)

        try:
            text = await chat.chat(
                [vlm.img_msg(item.image_b64, prompt, item.media_type)],
                model=self.caption_model or None,
                max_tokens=300, temperature=0.1)
        finally:
            await chat.close()

        return (text or "").strip()

    def _server_fault(self, attempts: Sequence[Attempt]) -> tuple[str, str]:
        """Общая серверная причина всех проб: (что случилось, что делать).

        Диалекты различаются только телом запроса. Если на все формы пришёл
        один и тот же отказ сервера, виноват не формат — и писать «проверьте,
        что модель мультимодальная» нечестно.
        """
        errors = [a.error.lower() for a in attempts if a.error and not a.ok]
        real = [e for e in errors if "не поддерживает картинки" not in e]

        if not real:
            return "", ""

        for marker, what, how in SERVER_FAULTS:
            if all(marker in e for e in real):
                return what, how.replace("{model}", self.model or "<модель>")

        return "", ""

    # ------------------------------------------------------- автоопределение

    async def detect(self, probe: EmbedInput | None = None,
                     force: bool = False) -> list[Attempt]:
        """Найти рабочий диалект, пробуя их по очереди. Возвращает журнал проб.

        Пробуем КАРТИНКОЙ: текстовый запрос принимают все серверы, и по нему
        мультимодальность не проверишь. Диалект запоминается — дальше запросы
        идут только им.
        """
        async with self._lock:
            if self.dialect and not force:
                return self.attempts

            item = probe or EmbedInput(text="проверка связи", image_b64=PIXEL_PNG_B64,
                                       media_type="image/png")
            # Сначала — что за сервер (и его маркер картинки), затем точное
            # имя модели: с ним LM Studio поднимет её сам, и перебор диалектов
            # будет проверять ФОРМАТ, а не наличие модели.
            await self.server_probe()
            await self.ensure_model()
            attempts: list[Attempt] = []

            for dialect in DIALECTS:
                if dialect == "plain_text" and item.has_image:
                    # Текстовый диалект картинку не возьмёт — не позорим журнал
                    # «ошибкой», а честно помечаем, что он не про это.
                    attempts.append(Attempt(dialect, False, error="не поддерживает картинки"))
                    continue

                t0 = time.monotonic()

                try:
                    vectors = await self._post(dialect, [item])
                except (MmEmbedError, ValueError) as e:
                    attempts.append(Attempt(dialect, False,
                                            ms=int((time.monotonic() - t0) * 1000),
                                            error=str(e)[:300]))

                    if self._server_fault(attempts[-1:])[0]:
                        # Сервер жалуется на себя (модель не загружена, нет
                        # токена) — остальные формы запроса получат тот же
                        # отказ. Не мучаем его ещё пятью бесполезными POST.
                        cause = attempts[-1].error

                        for rest in DIALECTS[DIALECTS.index(dialect) + 1:]:
                            skip = (f"пропущено: {cause}"[:300]
                                    if not (rest == "plain_text" and item.has_image)
                                    else "не поддерживает картинки")
                            attempts.append(Attempt(rest, False, error=skip))

                        break

                    continue

                attempts.append(Attempt(dialect, True,
                                        ms=int((time.monotonic() - t0) * 1000),
                                        dims=len(vectors[0])))
                self.dialect = dialect
                self.attempts = attempts

                log.info("mmembed: диалект %s подошёл (%d чисел в векторе, %d мс)",
                         dialect, len(vectors[0]), attempts[-1].ms)

                return attempts

            self.attempts = attempts

            # Сервер не умеет мультимодальные эмбеддинги. Прежде чем падать,
            # пробуем путь /meal: описание картинки чатом + текстовый вектор.
            if await self._enable_caption():
                return attempts

            raise MmEmbedError(await self._detect_error(attempts))

    async def _detect_error(self, attempts: Sequence[Attempt]) -> str:
        """Человеческий диагноз после неудачного перебора диалектов."""
        what, how = self._server_fault(attempts)
        kind = await self._kind_verdict()
        names, why = await self.list_models()

        if kind:
            # Самая частая и самая неочевидная причина: модель есть, имя верное,
            # JIT включён — но тип модели не тот, какой ждёт /v1/embeddings.
            return (kind + "Варианты: (1) включить режим описания — уберите "
                    "EMBEDDING_MM_FALLBACK=off, тогда картинку опишет vision-модель "
                    "и вектор даст текстовая эмбеддинг-модель; (2) поднять "
                    "Qwen3-VL-Embedding там, где мультимодальные эмбеддинги "
                    "поддержаны (vLLM или родной сервер модели) и указать его в "
                    "EMBEDDING_MM_API_URL.")

        if names:
            known = self.model and self.model in names
            catalog = ("Модели на сервере: " + ", ".join(names[:12])
                       + ("…" if len(names) > 12 else "") + ". ")

            if self.model and not known:
                catalog += (f"Имени «{self.model}» среди них нет — именно его мы "
                            "и шлём в запросе. ")
        else:
            catalog = f"Список моделей получить не удалось ({why}). " if why else ""

            if ("EMBEDDING_API_KEY" not in how
                    and any(mark in why.lower()
                            for mark in ("api_key", "api key", "token", "401", "403"))):
                catalog += ("Даже GET /models сервер отдаёт только по токену, а мы "
                            f"{'шлём его из ' + self.api_key_src if self.api_key_src else 'токен не шлём вовсе'}: "
                            "заполните EMBEDDING_API_KEY (или LM_API_TOKEN) в workers/.env. ")

        if what:
            # Все пробы упёрлись в одну и ту же беду сервера — так и говорим,
            # не сваливая вину на формат запроса.
            return (f"дело не в формате запроса: {what}. Все пробы "
                    f"({len(attempts)} шт.) к {self.base_url} упёрлись в одно и то же. "
                    f"{catalog}{how}")

        return (
            "ни один известный формат запроса не подошёл. Проверьте, что модель "
            f"{self.model or '(имя не задано)'} на {self.base_url} действительно "
            f"мультимодальная и загружена. {catalog}Пробы: "
            + "; ".join(f"{a.dialect} — {a.error}" for a in attempts))

    # --------------------------------------------------------------- работа

    async def embed(self, items: Iterable[EmbedInput] | EmbedInput) -> list[list[float]]:
        """Векторы для входов. Всегда L2-нормированы и подогнаны под dimensions."""
        batch = [items] if isinstance(items, EmbedInput) else list(items)

        if not batch:
            return []

        if not self.base_url:
            raise MmEmbedError("EMBEDDING_MM_API_URL/EMBEDDING_API_URL не задан — "
                               "эмбеддинг-сервер не настроен")

        has_image = any(i.has_image for i in batch)

        if has_image and self.mode != "caption" and not self.dialect:
            # Пробы могут закончиться включением режима описания — тогда
            # картинок в запросе уже не будет.
            await self.detect()

        if has_image and self.mode == "caption":
            batch = [await self._as_text(i) for i in batch]
            has_image = False

        if not self.dialect:
            # Чистый текст умеет любой сервер — не тратим время на пробы.
            self.dialect = "plain_text"

        # Текстовый диалект + картинка = мы определились на тексте, а теперь
        # пришла картинка: переопределяемся, иначе картинка молча пропадёт.
        if has_image and self.dialect == "plain_text":
            if self.forced_dialect == "plain_text":
                raise MmEmbedError(
                    "EMBEDDING_MM_DIALECT=plain_text — этот формат не передаёт картинки. "
                    "Уберите переменную (включится автоопределение) или укажите "
                    "мультимодальный диалект: " + ", ".join(d for d in DIALECTS if d != "plain_text"))

            self.dialect = ""
            await self.detect(force=True)

        out: list[list[float]] = []

        for chunk in self._chunks(batch):
            vectors = await self._post_fixing_model(chunk)
            out.extend(fit_dims(v, self.dimensions) for v in vectors)

        return out

    def _chunks(self, batch: Sequence[EmbedInput]) -> list[list[EmbedInput]]:
        """Разбить входы на запросы, сохраняя порядок.

        Картинки НИКОГДА не едут пачкой. У сервера пачка conversations
        считается одним батчем, и вектор каждого входа тогда зависит от
        соседей: одна и та же картинка, посланная вдвоём, давала косинус
        0,77 вместо 1,0 (а один и тот же текст — 0,9989 вместо 1,0). Это
        ловушка не только для «сравнения пары»: элемент коллекции, добавленный
        пачкой, и тот же запрос, посланный в одиночку, оказывались разными
        точками пространства, и поиск врал. Поэтому:

        * вход с картинкой — всегда отдельный запрос;
        * тексты пачкой — только если владелец сам поднял EMBEDDING_MM_BATCH.
        """
        size = self.batch_size if self.dialect in BATCHABLE else 1
        out: list[list[EmbedInput]] = []
        run: list[EmbedInput] = []

        for item in batch:
            if item.has_image:
                if run:
                    out.append(run)
                    run = []

                out.append([item])
                continue

            run.append(item)

            if len(run) >= size:
                out.append(run)
                run = []

        if run:
            out.append(run)

        return out

    async def _post_fixing_model(self, chunk: Sequence[EmbedInput]) -> list[list[float]]:
        """Запрос с одной попыткой починить ИМЯ модели.

        Так же ведёт себя чат (/meal): получив от провайдера «model not found»,
        он повторяет запрос на модели, которая у провайдера есть. Здесь имя
        берётся из `GET /models`, и после этого LM Studio грузит модель сам.
        """
        try:
            return await self._post(self.dialect, chunk)
        except MmEmbedError as e:
            fault = self._server_fault([Attempt(self.dialect, False, error=str(e))])[0]

            if not fault or "модел" not in fault.lower():
                raise

            was = self.model
            note = await self.ensure_model(force=True)

            if self.model == was:
                raise MmEmbedError(f"{e} {note}".strip()) from e

            return await self._post(self.dialect, chunk)

    async def _as_text(self, item: EmbedInput) -> EmbedInput:
        """Вход с картинкой → чисто текстовый вход (режим описания)."""
        if not item.has_image:
            return item

        words = await self.caption(item)

        if not words:
            raise MmEmbedError("vision-модель вернула пустое описание картинки — "
                               "вектор строить не из чего")

        return EmbedInput(text=(item.text + ". " + words).strip(". ").strip(),
                          instruction=item.instruction)

    async def embed_one(self, item: EmbedInput) -> list[float]:
        vectors = await self.embed([item])

        if not vectors:
            raise MmEmbedError("сервер не вернул вектор")

        return vectors[0]

    def describe(self) -> dict:
        """Паспорт подключения — его показывает страница и doctor."""
        return {
            "base_url": self.base_url,
            "model": self.model,
            "dimensions": self.dimensions,
            "dialect": self.dialect,
            "dialect_title": DIALECT_TITLES.get(self.dialect, ""),
            "forced": bool(self.forced_dialect),
            "token_src": self.api_key_src,
            "provider": self.provider,
            "software": self.software,
            "health": {k: v for k, v in (self._health or {}).items()
                       if k in ("service", "version", "backend", "model", "device",
                                "loaded", "on_device")},
            "proxy": bool(self.proxy),
            "model_note": self.model_note,
            "mode": self.mode,
            "mode_note": self.caption_note,
            "caption_model": self.caption_model,
            "text_model": self.text_model,
            "attempts": [a.as_dict() for a in self.attempts],
        }


# --------------------------------------------------------------------------
# Мелочи окружения
# --------------------------------------------------------------------------

def _env_int(name: str, default: int) -> int:
    raw = (os.getenv(name) or "").strip()

    try:
        return int(raw) if raw else int(default)
    except ValueError:
        log.warning("mmembed: %s=%r — не целое число, беру %s", name, raw, default)
        return int(default)


def _env_float(name: str, default: float) -> float:
    raw = (os.getenv(name) or "").strip()

    try:
        return float(raw) if raw else float(default)
    except ValueError:
        log.warning("mmembed: %s=%r — не число, беру %s", name, raw, default)
        return float(default)


def ready() -> bool:
    """Настроен ли мультимодальный эмбеддинг-сервер (адрес есть)."""
    return bool((os.getenv("EMBEDDING_MM_API_URL") or os.getenv("EMBEDDING_API_URL") or "").strip())
