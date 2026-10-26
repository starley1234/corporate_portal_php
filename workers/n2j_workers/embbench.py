"""nj671.bench — стенд эмбеддингов: два подхода на ОДНИХ И ТЕХ ЖЕ текстах.

ЗАЧЕМ. У платформы два независимых пути получить вектор, и до сих пор выбор
между ними делался на веру:

  * **текстовый** — `EMBEDDING_API_URL` + `EMBEDDING_MODEL` (OpenAI-совместимый
    `POST /v1/embeddings`, обычно `text-embedding-qwen3-embedding-0.6b`).
    На нём висит nj115 (семантический индекс) и старые вызовы `Llm::get_embedding`;
  * **мультимодальный** — `EMBEDDING_MM_API_URL` (свой сервис OmniEmbed на
    Qwen3-VL-Embedding, диалекты `mmembed`). На нём nj653 (лаборатория векторов),
    nj654 (похожие картинки) и `nj667.embed`.

Разница не в «лучше/хуже», а в свойствах: текстовый путь — 0.6B-модель, один
формат запроса, пачка из 32 текстов за запрос, работает где угодно локально.
Мультимодальный — 2B-модель, единое пространство «текст ↔ картинка», но каждый
вход отдельным запросом (пачка меняет вектор) и сетевой хоп до своего сервиса.
Сравнивать их «на глаз» бессмысленно, поэтому здесь один и тот же набор
текстов прогоняется через ОБА пути и меряется то, что важно для решения.

ЧТО МЕРЯЕТСЯ (по каждому пути отдельно):

  recall@1, recall@3, MRR  — поиск: запрос → своё описание в каталоге;
  related / unrelated, margin — косинус «по смыслу» против «чужого»: насколько
                              путь вообще разделяет близкое и далёкое;
  repeat                    — повторяемость: тот же текст дважды → косинус;
  ms/item                   — цена одного входа (с учётом его способа батчить);
  images                    — связывает ли путь картинку со словами (только
                              мультимодальный; текст рисует «не умею»);
  agreement                 — корреляция попарных косинусов двух путей на одних
                              документах. Это НЕ качество: разные модели живут
                              в разных пространствах. Показывает лишь, насколько
                              похоже они упорядочивают материал.

ПОЧЕМУ ВЫВОД СЧИТАЕТСЯ ЗДЕСЬ, А НЕ НА САЙТЕ. Решение — часть измерения: CLI
(`workers/nj671_bench.py`) печатает то же, что страница `/embedbench`, и оба
обязаны говорить одно и то же. Поэтому метрики и коды решения
(`decision.winner`, `decision.tasks[].pick`) рождаются здесь, а сайт только
переводит коды в человеческий текст (`Service_Nj671_Bench`).

Env (читается ТОЛЬКО внутри функций — правило воркеров):

  EMBEDDING_API_URL / _KEY / _MODEL / _DIMENSIONS / _BATCH_SIZE — текстовый путь;
  EMBEDDING_MM_API_URL / _KEY / _MODEL / _DIMENSIONS / _DIALECT / _TIMEOUT /
  _BATCH / _FALLBACK / _CAPTION_MODEL / EMBEDDING_TEXT_MODEL — мультимодальный.

Оба адреса обязательны и не подменяют друг друга: если `EMBEDDING_MM_API_URL`
пуст, стенд честно говорит «мультимодальный путь не настроен», а не меряет два
раза один и тот же сервер (иначе `mmembed.MmEmbeddingClient.from_env()` тихо
возьмёт общий `EMBEDDING_API_URL` — сравнение «двух подходов» превратится в
сравнение сервера с самим собой).
"""

from __future__ import annotations

import functools
import logging
import math
import os
import statistics
import time
from dataclasses import dataclass, field
from typing import Awaitable, Callable, Sequence

from .llm import EmbeddingClient
from .mmembed import EmbedInput, MmEmbeddingClient, cosine, l2_normalize

log = logging.getLogger("n2j.embbench")

#: Сколько знаков после запятой едет на сайт. Векторы сюда не едут вовсе.
ROUND = 4


# --------------------------------------------------------------------------
# Материал: каталог услуг и запросы людей
# --------------------------------------------------------------------------

#: Наборы текстов. Один и тот же материал уходит в оба пути, иначе сравнивать
#: нечего. Запросы ссылаются на индексы документов (0-based).
#:
#: Материал нарочно бытовой и русский: он проверяет не «энциклопедичность»
#: модели, а то, чем платформа пользуется каждый день, — поиск по описаниям
#: статей и услуг. Десять запросов — это дымовая проба, а не бенчмарк: она
#: отвечает «есть ли разница на нашем материале», а не «кто умнее на MTEB».
CORPORA: dict[str, dict] = {
    "catalog": {
        "title": "Каталог бытовых услуг: описание ↔ запрос",
        "docs": [
            "Замена смесителя на кухне: снимаем старый кран, ставим новый, проверяем соединения на протечки",
            "Установка посудомоечной машины: врезка в водопровод и канализацию, подключение к столешнице",
            "Прочистка засора канализации тросом и гидродинамической машиной, промывка стояка",
            "Сборка кухонного гарнитура: навеска шкафов по уровню, подгонка фасадов, установка столешницы",
            "Сборка шкафа-купе: монтаж направляющих, навеска дверей с доводчиками, регулировка зазоров",
            "Поклейка обоев: подготовка стен, грунтовка, стыковка рисунка, подрезка полотен",
            "Укладка ламината с подложкой, замковое соединение, плинтус и порожки",
            "Замена проводки в квартире: штробление стен, прокладка кабеля, сборка электрощитка",
            "Установка люстры, розеток и выключателей, подключение автоматов защиты",
            "Настройка Wi-Fi роутера: смена пароля, гостевая сеть, усиление сигнала в дальней комнате",
            "Монтаж видеонаблюдения: камеры, регистратор, удалённый просмотр с телефона",
            "Ремонт ноутбука: чистка кулера, замена термопасты, установка SSD и переустановка системы",
            "Установка Windows и драйверов, перенос файлов со старого компьютера",
            "Восстановление данных с жёсткого диска и флешки после сбоя",
        ],
        "queries": [
            ("течёт кран на кухне, нужно заменить смеситель", [0]),
            ("посудомойка не сливает воду, нужен мастер", [1, 2]),
            ("повесить кухонные шкафы и подогнать дверцы", [3]),
            ("поклеить обои в спальне, стены кривые", [5]),
            ("положить ламинат и прикрутить плинтус", [6]),
            ("розетки и автоматы в квартире, заменить проводку", [7, 8]),
            ("плохо ловит вай-фай в дальней комнате", [9]),
            ("камера во дворе, смотреть с телефона", [10]),
            ("ноутбук шумит и греется, стал тормозить", [11]),
            ("не включается компьютер, потерялись файлы", [12, 13]),
        ],
    },
}

#: Что рисуем для проверки «картинка ↔ слова». Фигура нарочно грубая: если
#: путь не отличает красный круг от синего квадрата, дальше проверять нечего.
IMAGE_CASES: tuple[tuple[str, str, tuple[int, int, int]], ...] = (
    ("красный круг на белом фоне", "circle", (220, 38, 38)),
    ("синий квадрат на белом фоне", "square", (37, 99, 235)),
)


# --------------------------------------------------------------------------
# Движки: одинаковый договор у обоих путей
# --------------------------------------------------------------------------

@dataclass
class Engine:
    """Один путь получить вектор. Реальный клиент или подделка в тестах."""

    key: str                       # "text" | "mm"
    label: str                     # человеческая подпись пути
    base_url: str
    model: str
    expected_dims: int = 0
    supports_images: bool = False
    #: native — сервер считает вектор по картинке сам; caption — картинку
    #: описывает vision-модель (режим мультимодального клиента).
    mode: str = "native"
    notes: list[str] = field(default_factory=list)

    embed_texts: Callable[[Sequence[str]], Awaitable[list[list[float]]]] | None = None
    embed_image: Callable[[str, str, str], Awaitable[list[float]]] | None = None
    close: Callable[[], Awaitable[None]] | None = None
    #: По одному входу за запрос. Нужен, когда сервер отказывает пачке: так
    #: стенд отличает «не умеет пачку» от «не умеет модель» и всё равно меряет.
    embed_texts_one: Callable[[str], Awaitable[list[float]]] | None = None
    #: Список моделей сервера — для разбора 400 «model not found».
    probe_models: Callable[[], Awaitable[list[str]]] | None = None
    #: Подменить имя модели у клиента (когда сервер знает её под другим именем).
    set_model: Callable[[str], None] | None = None

    def passport(self) -> dict:
        return {
            "key": self.key,
            "label": self.label,
            "base_url": self.base_url,
            "model": self.model,
            "expected_dims": int(self.expected_dims or 0),
            "images": bool(self.supports_images),
            "notes": list(self.notes),
        }


def _env(name: str) -> str:
    return (os.getenv(name) or "").strip()


def text_engine_from_env() -> Engine | None:
    """Текстовый путь: ровно то, чем пользуется nj115.

    Адрес берём ТОЛЬКО из `EMBEDDING_API_URL`: подставлять сюда сервер чата или
    мультимодальный адрес нельзя — стенд должен мерить боевой путь, а не то,
    что случайно оказалось рядом в окружении.
    """
    base = _env("EMBEDDING_API_URL")

    if not base:
        return None

    model = _env("EMBEDDING_MODEL") or "text-embedding-qwen3-embedding-0.6b"
    dims = int(_env("EMBEDDING_DIMENSIONS") or 1024)
    batch = int(_env("EMBEDDING_BATCH_SIZE") or 32)

    client = EmbeddingClient(
        base_url=base,
        api_key=_env("EMBEDDING_API_KEY") or _env("LM_API_TOKEN"),
        model=model,
        dimensions=dims,
        batch_size=batch,
        timeout=float(_env("EMBEDDING_TIMEOUT") or 120),
    )

    notes = [f"пачка до {batch} текстов одним запросом (EMBEDDING_BATCH_SIZE)"]

    if client.api_key_src:
        notes.append(f"токен отправляется: {client.api_key_src}")
    else:
        # Владелец 22.10.2026 спросил «а ключ вы использовали?» — теперь это
        # видно прямо в отчёте, а не только в чате поддержки. Значение токена
        # не показываем никогда: только факт и имя переменной.
        notes.append("токен НЕ отправляется: EMBEDDING_API_KEY пуст "
                     "(удалённый сервер не получает заголовок Authorization)")

    async def embed_texts(texts: Sequence[str]) -> list[list[float]]:
        vectors = await client.embed(list(texts))

        return [l2_normalize(v) for v in vectors]

    async def embed_texts_one(text: str) -> list[float]:
        vector = await client.embed(text)

        return l2_normalize(vector)

    return Engine(
        key="text",
        label="Текстовый путь · EMBEDDING_API_URL",
        base_url=base,
        model=model,
        expected_dims=dims,
        supports_images=False,
        notes=notes,
        embed_texts=embed_texts,
        embed_texts_one=embed_texts_one,
        probe_models=client.models,
        set_model=functools.partial(_set_client_model, client),
        close=client.close,
    )


def mm_engine_from_env() -> Engine | None:
    """Мультимодальный путь: ровно то, чем пользуются nj653/nj654/nj667.

    Требуем именно `EMBEDDING_MM_API_URL`. `MmEmbeddingClient.from_env()` умеет
    падать на общий `EMBEDDING_API_URL` и на сервер чата — это правильно для
    боевых каналов (им нужен хоть какой-то сервер), но для стенда означало бы
    «сравнили текстовый путь сам с собой».
    """
    base = _env("EMBEDDING_MM_API_URL")

    if not base:
        return None

    client = MmEmbeddingClient.from_env()

    if client is None:
        return None

    if client.base_url.rstrip("/") != base.rstrip("/"):
        # Адрес перекрыт реестром провайдеров — это стоит показать, но мерить
        # будем то, куда клиент реально ходит.
        log.info("embbench: мультимодальный клиент ходит на %s (в env %s)",
                 client.base_url, base)

    notes = [f"диалект: {client.dialect or 'автоопределение'}"]

    if client.batch_size > 1:
        notes.append(
            f"EMBEDDING_MM_BATCH={client.batch_size}: пачка меняет вектор — "
            "стенд всё равно шлёт входы по одному")

    async def embed_texts(texts: Sequence[str]) -> list[list[float]]:
        vectors: list[list[float]] = []

        for text in texts:
            vectors.append(await client.embed_one(EmbedInput(text=text)))

        return vectors

    async def embed_image(image_b64: str, media_type: str, caption: str) -> list[float]:
        item = EmbedInput(text=caption, image_b64=image_b64, media_type=media_type)

        return await client.embed_one(item)

    return Engine(
        key="mm",
        label="Мультимодальный путь · EMBEDDING_MM_API_URL",
        base_url=client.base_url or base,
        model=client.model or _env("EMBEDDING_MM_MODEL") or _env("EMBEDDING_MODEL"),
        expected_dims=client.dimensions,
        supports_images=True,
        mode=client.mode,
        notes=notes,
        embed_texts=embed_texts,
        embed_image=embed_image,
        close=client.close,
    )


# --------------------------------------------------------------------------
# Картинки для пробы «картинка ↔ слова»
# --------------------------------------------------------------------------

def draw_case(shape: str, color: tuple[int, int, int], size: int = 224) -> str | None:
    """Нарисовать фигуру и вернуть base64 PNG. None — Pillow нет.

    Рисуем, а не берём файл из репозитория: стенд должен работать на голом
    сервере воркера, без картинок в поставке и без похода в сеть.
    """
    try:
        import base64                                # noqa: PLC0415
        import io                                    # noqa: PLC0415

        from PIL import Image, ImageDraw             # noqa: PLC0415
    except Exception:                                # noqa: BLE001 — опциональная зависимость
        return None

    img = Image.new("RGB", (size, size), (255, 255, 255))
    pen = ImageDraw.Draw(img)
    pad = size // 6

    if shape == "circle":
        pen.ellipse([pad, pad, size - pad, size - pad], fill=color)
    else:
        pen.rectangle([pad, pad, size - pad, size - pad], fill=color)

    buf = io.BytesIO()
    img.save(buf, format="PNG")

    return base64.b64encode(buf.getvalue()).decode("ascii")


# --------------------------------------------------------------------------
# Метрики
# --------------------------------------------------------------------------

def _snap(value: float) -> float:
    return round(float(value), ROUND)


def _pearson(a: Sequence[float], b: Sequence[float]) -> float | None:
    if len(a) < 3 or len(a) != len(b):
        return None

    ma, mb = statistics.fmean(a), statistics.fmean(b)
    da = [x - ma for x in a]
    db = [y - mb for y in b]
    denom = math.sqrt(sum(x * x for x in da) * sum(y * y for y in db))

    if denom <= 0:
        return None

    return sum(x * y for x, y in zip(da, db)) / denom


def pairwise(vectors: Sequence[Sequence[float]]) -> list[float]:
    """Косинусы верхнего треугольника — материал для `agreement`."""
    out: list[float] = []

    for i in range(len(vectors)):
        for j in range(i + 1, len(vectors)):
            out.append(cosine(vectors[i], vectors[j]))

    return out


def retrieval(docs: Sequence[Sequence[float]],
              queries: Sequence[tuple[str, Sequence[int]]],
              query_vectors: Sequence[Sequence[float]]) -> dict:
    """Качество поиска на материале: recall@1, recall@3, MRR и разделение.

    «Чужое» здесь — не случайные словари, а соседние услуги каталога. Это
    честнее: модель, которая путает «собрать шкаф» и «собрать кухню», не
    получает поблажку за то, что оба текста про мебель.

    Возвращает и `rows` — по строке на запрос: их показывает страница, чтобы
    человек видел, ГДЕ путь ошибся, а не только итоговую цифру.
    """
    hits1 = hits3 = 0
    rr = 0.0
    related: list[float] = []
    unrelated: list[float] = []
    rows: list[dict] = []

    for (query, relevant), qv in zip(queries, query_vectors):
        sims = [cosine(qv, dv) for dv in docs]
        order = sorted(range(len(sims)), key=lambda i: -sims[i])
        top = order[0]
        rel = list(relevant)
        place = next((n for n, idx in enumerate(order) if idx in rel), None)

        hits1 += 1 if top in rel else 0
        hits3 += 1 if any(idx in rel for idx in order[:3]) else 0

        if place is not None:
            rr += 1.0 / (place + 1)

        related.extend(sims[i] for i in rel)
        unrelated.extend(sims[i] for i in range(len(docs)) if i not in rel)

        rows.append({
            "query": query,
            "top": top,
            "top_cos": _snap(sims[top]),
            "ok": top in rel,
            "rank": (place + 1) if place is not None else 0,
        })

    n = max(1, len(queries))

    return {
        "recall1": _snap(hits1 / n),
        "recall3": _snap(hits3 / n),
        "mrr": _snap(rr / n),
        "related": _snap(statistics.fmean(related)) if related else 0.0,
        "unrelated": _snap(statistics.fmean(unrelated)) if unrelated else 0.0,
        "margin": _snap((statistics.fmean(related) - statistics.fmean(unrelated))
                        if related and unrelated else 0.0),
        "rows": rows,
    }


def _set_client_model(client: EmbeddingClient, model: str) -> None:
    """Задать клиенту другое имя модели (после сверки со списком сервера)."""
    client.model = model


def _looks_multimodal(name: str) -> bool:
    """Имя похоже на мультимодальную (VL) модель, а не на текстовую."""
    low = (name or "").lower()

    return any(mark in low for mark in ("-vl", "vl-", "vision", "visual", "mm-embed"))


def _preflight_model(engine: Engine, names: Sequence[str]) -> str:
    """Сверить имя модели со списком сервера ДО запросов. '' — всё в порядке.

    Зачем ДО. LM Studio на неизвестное имя отвечает «No models loaded. Please
    load a model in the developer page…» — то есть выглядит как «сервер пуст»,
    хотя он жив и модель у него есть, просто другая. Владелец 22.10.2026
    поставил в `EMBEDDING_MODEL` имя мультимодальной модели
    (`qwen3-vl-embedding-2b`), получил HTTP 400 и пошёл проверять ключ. Один
    GET /models отвечает на этот вопрос заранее и точно.

    Имя чиним только когда оно очевидно то же самое (регистр, «владелец/имя»,
    однозначное совпадение слов) — логика уже написана для мультимодального
    клиента, второй копии не заводим. Разные семейства моделей не подменяем:
    подставить вместо VL текстовую модель значило бы соврать в сравнении.
    """
    wanted = (engine.model or "").strip()

    if not wanted or not names:
        return ""

    if wanted in names:
        return ""

    near = [n for n in names if n.lower() == wanted.lower() or n.endswith("/" + wanted)]

    if near:
        fixed = near[0]
    else:
        fixed = MmEmbeddingClient._best_name(wanted, list(names))

    if fixed and fixed != wanted:
        engine.notes.append(
            f"модели {wanted!r} на сервере нет; он отдаёт {fixed!r} — мерим её "
            f"(поправьте EMBEDDING_MODEL на сервере воркера)")
        engine.model = fixed

        if engine.set_model:
            engine.set_model(fixed)

        return ""

    available = ", ".join(repr(n) for n in list(names)[:8])
    text = (f"модели {wanted!r} на этом сервере нет. Сервер отдаёт: {available}. "
            f"Впишите в workers/.env ту модель, которая есть, например "
            f"EMBEDDING_MODEL={list(names)[0]}")

    if _looks_multimodal(wanted):
        text += (". Имя похоже на мультимодальную модель: такие OpenAI-совместимые "
                 "серверы (в том числе LM Studio) по /v1/embeddings не отдают — "
                 "мультимодальный путь задаётся отдельно, EMBEDDING_MM_API_URL")

    return text


class _StepError(RuntimeError):
    """Шаг прогона упал — и мы знаем, на каком именно.

    «HTTP 400» без указания шага бесполезен: 400 приходит и на документы, и на
    запросы, и на повтор, а причины у них разные (длина входа, число входов).
    Владелец 22.10.2026 видел именно голый 400 — по нему нельзя было понять
    даже, что именно отправили.
    """

    def __init__(self, step: str, cause: BaseException):
        self.step = step
        self.cause = cause
        super().__init__(f"{step}: {cause}")


def _is_request_rejected(error: BaseException) -> bool:
    """Сервер отклонил САМ ЗАПРОС (400/404/413/422), а не сеть и не токен.

    Только в этом случае имеет смысл повторять по одному входу: у 401 и у
    таймаута одиночные запросы дадут тот же отказ, а время съедят.
    """
    status = getattr(error, "status", None)

    if isinstance(status, int):
        return status in (400, 404, 413, 422)

    text = str(error)
    return any(f"HTTP {code}" in text for code in (400, 404, 413, 422))


async def _embed(engine: Engine, texts: Sequence[str], step: str,
                 allow_single: bool = True) -> list[list[float]]:
    """Векторы пачкой; если сервер не принял пачку — по одному, с пометкой.

    Зачем: «batch too large» и «model not found» выглядят одинаково (оба 400).
    Отличает их ровно один опыт — тот же вход поодиночке. Если поодиночке
    работает, значит сервер жив и модель знает, и стенд продолжает мерить, а в
    отчёт попадает вывод «поставьте EMBEDDING_BATCH_SIZE=1». Если не работает
    и поодиночке — причина настоящая, и она уходит на страницу как есть.
    """
    texts = list(texts)

    try:
        return await engine.embed_texts(texts)     # type: ignore[misc]
    except Exception as first:                     # noqa: BLE001 — решаем по коду
        if (not allow_single or len(texts) < 2 or engine.embed_texts_one is None
                or not _is_request_rejected(first)):
            raise _StepError(step, first) from first

        try:
            one_by_one = [await engine.embed_texts_one(text) for text in texts]
        except Exception as single:                # noqa: BLE001 — настоящая причина
            raise _StepError(step, single) from single

        # Одна пометка на весь путь, а не по одной на каждый шаг: пачка падает
        # и на документах, и на запросах, и три одинаковых строки в отчёте
        # только мешают. Причину сервера показываем коротко — её полный текст
        # всё равно виден в ошибке, если поодиночке тоже не выйдет.
        engine.notes = [n for n in engine.notes if not n.startswith("сервер отказал пачке")]
        engine.notes.append(
            f"сервер отказал пачке из {len(texts)} входов "
            f"({str(first)[:160]}), но принял те же тексты по одному — "
            "поставьте EMBEDDING_BATCH_SIZE=1")

        return one_by_one


async def _measure(engine: Engine, corpus: dict, with_images: bool,
                   progress=None) -> dict:
    """Прогнать один путь по материалу и собрать его паспорт с метриками."""
    started = time.monotonic()
    docs_text: list[str] = list(corpus["docs"])
    queries: list[tuple[str, list[int]]] = [(q, list(rel)) for q, rel in corpus["queries"]]

    side = engine.passport()
    side.update({
        "ok": False,
        "error": "",
        "notes": list(engine.notes),
        "dims": 0,
        "latency": {},
        "quality": {},
        "repeat": None,
        "images": {"supported": bool(engine.supports_images), "checked": False},
        "rows": [],
    })

    if engine.embed_texts is None:
        side["error"] = "движок не собран"
        return side

    try:
        # Один GET /models до запросов: дешевле, чем 14 отказов и вопрос
        # «а ключ вы использовали?».
        if engine.probe_models:
            try:
                names = await engine.probe_models()
            except Exception:                       # noqa: BLE001 — сверка не обязательна
                names = []

            problem = _preflight_model(engine, names)

            if problem:
                side["error"] = problem
                side["notes"] = list(dict.fromkeys(engine.notes))

                return side

        if progress:
            await progress(f"{engine.label}: документы", f"{len(docs_text)} текстов")

        docs_vec = await _embed(engine, docs_text, "документы")
        elapsed_docs = time.monotonic() - started

        if progress:
            await progress(f"{engine.label}: запросы", f"{len(queries)} запросов")

        query_vec = await _embed(engine, [q for q, _ in queries], "запросы")

        if progress:
            await progress(f"{engine.label}: повторяемость", "тот же текст дважды")

        again = await _embed(engine, [docs_text[0]], "повторяемость", allow_single=False)
        total = time.monotonic() - started

        dims = {len(v) for v in docs_vec} | {len(v) for v in query_vec}

        if len(dims) != 1:
            raise ValueError(f"векторы разной длины: {sorted(dims)} — сервер режет размерность по-разному")

        side["dims"] = dims.pop()
        side["quality"] = retrieval(docs_vec, queries, query_vec)
        side["rows"] = side["quality"].pop("rows")
        side["repeat"] = _snap(cosine(docs_vec[0], again[0]))
        side["latency"] = {
            "docs_ms": int(elapsed_docs * 1000),
            "total_ms": int(total * 1000),
            "per_item_ms": _snap(total * 1000 / max(1, len(docs_text) + len(queries) + 1)),
            "calls": len(docs_text) + len(queries) + 1 if engine.key == "mm" else None,
        }
        side["_docs_vec"] = docs_vec

        if side["expected_dims"] and side["dims"] != side["expected_dims"]:
            side["notes"].append(
                f"сервер вернул {side['dims']} измерений вместо ожидаемых "
                f"{side['expected_dims']} — колонка vector(N) в базе это не примет")

        if with_images and engine.supports_images and engine.embed_image:
            side["images"] = await _image_probe(engine)

        side["ok"] = True
    except Exception as e:                            # noqa: BLE001 — сторона обязана закрыться причиной
        if isinstance(e, _StepError):
            cause = e.cause
            side["error"] = f"{e.step}: {type(cause).__name__}: {str(cause)[:1200]}"
        else:
            cause = e
            side["error"] = f"{type(cause).__name__}: {str(cause)[:1200]}"

        # Догадка «модель не обслуживается» проверяется одним запросом.
        if engine.probe_models and _is_request_rejected(cause):
            try:
                models = await engine.probe_models()
            except Exception:                       # noqa: BLE001 — диагностика не обязана работать
                models = []

            # Пишем в engine.notes, а не в side["notes"]: в конце замера
            # замечания синхронизируются из движка, и запись в side потерялась
            # бы — ровно так первая версия и «съела» подсказку про модели.
            if models:
                engine.notes.append("сервер отдаёт модели: " + ", ".join(models[:8]))

                if engine.model and engine.model not in models:
                    engine.notes.append(
                        f"запрошенной модели {engine.model!r} в этом списке НЕТ — "
                        "проверьте EMBEDDING_MODEL (на 400 сервер обычно пишет "
                        "именно это, но не всегда показывает список)")
            else:
                engine.notes.append(
                    f"список моделей получить не удалось: GET {engine.base_url}/models")

        log.warning("embbench: путь %s не отработал: %s", engine.key, side["error"])

    # Замечания могли появиться ПО ХОДУ замера (например «сервер не принял
    # пачку»). Паспорт копирует их в начале, поэтому синхронизируем в конце —
    # иначе самое полезное наблюдение не доедет до страницы.
    side["notes"] = list(dict.fromkeys(engine.notes))

    return side


async def _image_probe(engine: Engine) -> dict:
    """Различает ли путь картинку по смыслу: своя подпись ближе чужой.

    Проверка на двух фигурах, а не на одной: с одной «похожесть» не отличить
    от вырожденного пространства, где все векторы почти совпадают.
    """
    out = {"supported": True, "checked": False, "ok": None, "margin": None,
           "mode": "", "note": ""}

    images: list[tuple[str, str]] = []

    for caption, shape, color in IMAGE_CASES:
        b64 = draw_case(shape, color)

        if not b64:
            out["note"] = "Pillow не установлен — картинки не нарисовать"
            return out

        images.append((caption, b64))

    vectors = [await engine.embed_image(b64, "image/png", "")
               for _, b64 in images]

    captions = [caption for caption, _ in images]
    caption_vectors = await engine.embed_texts(captions)

    own = [cosine(vectors[i], caption_vectors[i]) for i in range(len(images))]
    alien = [cosine(vectors[i], caption_vectors[j])
             for i in range(len(images)) for j in range(len(images)) if i != j]

    out.update({
        "checked": True,
        "ok": bool(min(own) > max(alien)),
        "margin": _snap(statistics.fmean(own) - statistics.fmean(alien)),
        "mode": str(getattr(engine, "mode", "") or ""),
    })

    return out


# --------------------------------------------------------------------------
# Решение: какой путь брать
# --------------------------------------------------------------------------

def decide(text: dict, mm: dict) -> dict:
    """Коды решения по двум сторонам. Тексты подставляет сайт/CLI.

    Правила нарочно грубые и объяснимые: материал — дымовая проба, точность
    десятых долей recall@1 на десяти запросах ничего не значит, а вот
    «мультимодальный хуже текстового на 20 пунктов» и «в 5 раз медленнее» —
    значит. Порог качества — 2 пункта recall@1 (2 запроса из 100), порог
    скорости — 1.5×: ниже этого выбирать «по цифре» нельзя, и решает простота.
    """
    reasons: list[dict] = []
    tasks: list[dict] = []
    text_ok, mm_ok = bool(text.get("ok")), bool(mm.get("ok"))

    if not text_ok and not mm_ok:
        return {
            "winner": "none",
            "reasons": [{"code": "both_off",
                         "text_error": text.get("error", ""),
                         "mm_error": mm.get("error", "")}],
            "tasks": [],
            "summary": "Ни один путь не ответил — сравнивать нечего.",
        }

    if text_ok != mm_ok:
        winner = "text" if text_ok else "mm"
        loser = mm if text_ok else text

        reasons.append({"code": "only_one", "side": winner,
                        "error": loser.get("error", "")})
        tasks.append({"task": "any", "pick": winner})

        return {
            "winner": winner,
            "reasons": reasons,
            "tasks": tasks,
            "summary": ("Отвечает только один путь — его и берите, второй сейчас "
                        "нерабочий."),
        }

    tq, mq = text.get("quality", {}), mm.get("quality", {})
    t_ms = float(text.get("latency", {}).get("per_item_ms") or 0)
    m_ms = float(mm.get("latency", {}).get("per_item_ms") or 0)

    delta = float(mq.get("recall1", 0)) - float(tq.get("recall1", 0))
    speed = (m_ms / t_ms) if t_ms > 0 else 0.0

    if delta >= 0.02:
        reasons.append({"code": "mm_better", "value": _snap(delta)})
    elif delta <= -0.02:
        reasons.append({"code": "text_better", "value": _snap(-delta)})
    else:
        reasons.append({"code": "same_quality", "value": _snap(abs(delta))})

    if speed >= 1.5:
        reasons.append({"code": "text_faster", "value": _snap(speed)})

    if mm.get("images", {}).get("ok") is True:
        reasons.append({"code": "images_ok",
                        "value": mm["images"].get("margin")})
    elif mm.get("images", {}).get("checked"):
        reasons.append({"code": "images_weak",
                        "value": mm["images"].get("margin")})

    if text.get("repeat") is not None and float(text["repeat"]) < 0.999:
        reasons.append({"code": "text_unstable", "value": text["repeat"]})

    if mm.get("repeat") is not None and float(mm["repeat"]) < 0.999:
        reasons.append({"code": "mm_unstable", "value": mm["repeat"]})

    if text.get("dims") and mm.get("dims") and text["dims"] != mm["dims"]:
        reasons.append({"code": "dims_differ",
                        "text": text["dims"], "mm": mm["dims"]})

    # 1. Текстовые индексы и поиск по тексту. Здесь важны скорость и цена:
    #    мультимодальный 2B на каждый текст — дорогое удовольствие без выгоды,
    #    если качество то же.
    if delta > 0.02:
        text_pick = "mm"
    elif speed >= 1.5 or delta >= 0:
        text_pick = "text"
    else:
        text_pick = "mm"

    # 2. Картинки и смешанные коллекции. Альтернативы нет: текстовый путь
    #    картинку не видит вовсе.
    image_pick = "mm" if mm.get("images", {}).get("supported") else "none"

    # 3. Одно пространство на всё (текст и картинки в одной таблице). Переходить
    #    на него стоит только если текст не просел сильнее 2 пунктов и цена
    #    входа не выросла больше чем втрое.
    one_space = "mm" if delta >= -0.02 and (speed == 0 or speed <= 3) else "text"

    tasks.extend([
        {"task": "text_index", "pick": text_pick, "delta": _snap(delta),
         "speed": _snap(speed)},
        {"task": "cross_modal", "pick": image_pick},
        {"task": "one_space", "pick": one_space},
    ])

    picks = {t["task"]: t["pick"] for t in tasks}

    if picks.get("text_index") == "text" and picks.get("cross_modal") == "mm":
        winner = "both"
        summary = ("Оба пути нужны и делят работу: текстовый — для поиска по "
                   "тексту, мультимодальный — там, где есть картинки.")
    elif picks.get("text_index") == "mm" and picks.get("cross_modal") == "mm":
        winner = "mm"
        summary = ("Мультимодальный путь не хуже текстового и на тексте — можно "
                   "держать одно пространство на всё.")
    else:
        winner = "text"
        summary = ("Текстовый путь остаётся основным: на нашем материале он не "
                   "хуже, а картинки нужны только отдельным сервисам.")

    return {"winner": winner, "reasons": reasons, "tasks": tasks, "summary": summary}


# --------------------------------------------------------------------------
# Общий прогон
# --------------------------------------------------------------------------

async def run(corpus_key: str = "catalog", quick: bool = False,
              progress=None) -> dict:
    """Прогнать оба пути и вернуть отчёт (его принимает сайт и печатает CLI).

    `quick` режет материал до 6 документов и 3 запросов — чтобы проверить
    настройку за секунды, а не за минуту.
    """
    key = (corpus_key or "catalog").strip().lower()

    if key not in CORPORA:
        raise ValueError(f"неизвестный набор {corpus_key!r}; есть: {', '.join(CORPORA)}")

    base = CORPORA[key]
    docs = list(base["docs"])
    queries = [(q, list(rel)) for q, rel in base["queries"]]

    if quick:
        docs = docs[:6]
        # Запросы оставляем только те, чьи ответы попали в урезанный набор.
        queries = [(q, [i for i in rel if i < len(docs)]) for q, rel in queries]
        queries = [pair for pair in queries if pair[1]][:3]

    corpus = {"key": key, "title": base["title"], "docs": docs, "queries": queries}
    started = time.monotonic()

    engines = [e for e in (text_engine_from_env(), mm_engine_from_env()) if e]

    sides: dict[str, dict] = {}

    for prefix in ("text", "mm"):
        engine = next((e for e in engines if e.key == prefix), None)
        if engine is None:
            sides[prefix] = _off_side(prefix)
            continue

        sides[prefix] = await _measure(engine, corpus, with_images=(prefix == "mm"),
                                       progress=progress)

    for engine in engines:
        if engine.close:
            try:
                await engine.close()
            except Exception:                        # noqa: BLE001 — закрытие не должно ронять отчёт
                pass

    # Согласие пространств: только когда размерности совпали. Это не качество,
    # а «насколько похоже упорядочивают материал» — иначе цифру поймут неверно.
    # Один и тот же сервер с обеих сторон — сравнение сервера с самим собой.
    # Владелец 22.10.2026 как раз поставил мультимодальную модель в
    # EMBEDDING_MODEL, и без этой проверки отчёт молча сравнил бы её саму с
    # собой, а вывод «оба пути хороши» читался бы как подтверждение.
    if sides["text"].get("base_url") and (
            sides["text"]["base_url"].rstrip("/") == sides["mm"].get("base_url", "").rstrip("/")):
        note = ("оба пути ходят на один и тот же адрес "
                f"({sides['text']['base_url']}) — это сравнение сервера с самим собой")

        if sides["text"].get("model") and sides["text"]["model"] == sides["mm"].get("model"):
            note += f", да ещё и с одной моделью ({sides['text']['model']})"

        sides["text"]["notes"].append(note)
        sides["mm"]["notes"].append(note)

    # Та же модель с обеих сторон, но разные адреса: почти наверняка в
    # EMBEDDING_MODEL попала мультимодальная модель (владелец 22.10.2026:
    # EMBEDDING_MODEL=qwen3-vl-embedding-2b). Это не запрещено — бывает, что
    # один сервер отдаёт её и по /v1/embeddings, — но сравнение тогда не про
    # «два подхода», и сказать об этом надо.
    text_model = (sides["text"].get("model") or "").strip()
    mm_model = (sides["mm"].get("model") or "").strip()

    if text_model and text_model == mm_model and \
            (sides["text"].get("base_url") or "") != (sides["mm"].get("base_url") or ""):
        note = (f"обе стороны используют одну модель ({text_model}) — если это "
                "мультимодальная модель, текстовый путь настроен не туда: "
                "EMBEDDING_MODEL должен называть текстовую модель")

        sides["text"]["notes"].append(note)
        sides["mm"]["notes"].append(note)

    agreement = None

    if (sides["text"].get("ok") and sides["mm"].get("ok")
            and sides["text"].get("dims") == sides["mm"].get("dims")):
        agreement = _pearson(pairwise(sides["text"].pop("_docs_vec", [])),
                             pairwise(sides["mm"].pop("_docs_vec", [])))

    sides["text"].pop("_docs_vec", None)
    sides["mm"].pop("_docs_vec", None)

    if agreement is not None:
        agreement = _snap(agreement)

    decision = decide(sides["text"], sides["mm"])

    quality = sides["text"].get("quality", {}).get("recall1")

    return {
        "status": "done",
        "channel": "nj671.bench",
        "corpus": {"key": key, "title": base["title"],
                   "docs": len(docs), "queries": len(queries),
                   "quick": bool(quick)},
        "engines": sides,
        "agreement": agreement,
        "decision": decision,
        "elapsed_ms": int((time.monotonic() - started) * 1000),
        "model": (sides["mm"].get("model") or sides["text"].get("model") or ""),
        "title": f"Стенд эмбеддингов · {base['title']}"
                 + (" (быстрый)" if quick else ""),
        "quality_hint": quality,
    }


def _off_side(key: str) -> dict:
    """Сторона, которой нет: честная причина, а не пустая строка."""
    title = ("EMBEDDING_API_URL" if key == "text" else "EMBEDDING_MM_API_URL")

    return {
        "key": key,
        "label": ("Текстовый путь · EMBEDDING_API_URL" if key == "text"
                  else "Мультимодальный путь · EMBEDDING_MM_API_URL"),
        "base_url": "",
        "model": "",
        "expected_dims": 0,
        "images": {"supported": key == "mm", "checked": False},
        "notes": [],
        "ok": False,
        "error": f"{title} не задан в workers/.env — этот путь не настроен",
        "dims": 0,
        "latency": {},
        "quality": {},
        "repeat": None,
        "rows": [],
    }


# --------------------------------------------------------------------------
# CLI-отчёт
# --------------------------------------------------------------------------

def _pct(value) -> str:
    return "—" if value is None else f"{float(value) * 100:.0f}%"


def _num(value, digits: int = 2) -> str:
    return "—" if value is None else f"{float(value):.{digits}f}"


def report(result: dict) -> str:
    """Человеческий отчёт для `python3 workers/nj671_bench.py`."""
    lines: list[str] = []
    corpus = result.get("corpus", {})
    sides = result.get("engines", {})
    text, mm = sides.get("text", {}), sides.get("mm", {})

    lines.append("")
    lines.append("СТЕНД ЭМБЕДДИНГОВ · " + str(corpus.get("title", "")))
    lines.append(f"материал: {corpus.get('docs', 0)} описаний, "
                 f"{corpus.get('queries', 0)} запросов"
                 + (" (быстрый режим)" if corpus.get("quick") else ""))
    lines.append("")

    header = f"{'':22}{'ТЕКСТОВЫЙ':>16}{'МУЛЬТИМОДАЛЬНЫЙ':>20}"

    def row(name: str, a, b) -> str:
        return f"{name:22}{str(a):>16}{str(b):>20}"

    lines.append(header)
    lines.append("-" * len(header))
    lines.append(row("состояние",
                     "работает" if text.get("ok") else "ошибка",
                     "работает" if mm.get("ok") else "ошибка"))
    lines.append(row("модель", text.get("model", "—"), mm.get("model", "—")))
    lines.append(row("размерность", text.get("dims") or "—", mm.get("dims") or "—"))
    lines.append(row("recall@1", _pct(text.get("quality", {}).get("recall1")),
                     _pct(mm.get("quality", {}).get("recall1"))))
    lines.append(row("recall@3", _pct(text.get("quality", {}).get("recall3")),
                     _pct(mm.get("quality", {}).get("recall3"))))
    lines.append(row("MRR", _num(text.get("quality", {}).get("mrr")),
                     _num(mm.get("quality", {}).get("mrr"))))
    lines.append(row("своё − чужое", _num(text.get("quality", {}).get("margin")),
                     _num(mm.get("quality", {}).get("margin"))))
    lines.append(row("повторяемость", _num(text.get("repeat"), 4),
                     _num(mm.get("repeat"), 4)))
    lines.append(row("мс на вход",
                     _num(text.get("latency", {}).get("per_item_ms"), 1),
                     _num(mm.get("latency", {}).get("per_item_ms"), 1)))
    lines.append(row("картинка ↔ слова",
                     "не умеет",
                     "не проверено" if mm.get("images", {}).get("checked") is False
                     else ("да" if mm["images"].get("ok") else "нет")))
    lines.append(row("согласие пространств", _num(result.get("agreement")), ""))

    for key, side in (("text", text), ("mm", mm)):
        if not side.get("ok"):
            lines.append("")
            lines.append(f"{side.get('label', key)}: {side.get('error', 'не ответил')}")

    decision = result.get("decision", {})
    lines.append("")
    lines.append("РЕШЕНИЕ: " + str(decision.get("summary", "")))
    lines.append(f"держать: {decision.get('winner')}")

    for task in decision.get("tasks", []):
        lines.append(f"  · {task.get('task')}: {task.get('pick')}")

    for reason in decision.get("reasons", []):
        value = reason.get("value")
        lines.append(f"  причина: {reason.get('code')}"
                     + (f" = {value}" if value is not None else ""))

    lines.append("")
    lines.append("Подробно — /embedbench (страница модуля nj671_embedbench).")

    return "\n".join(lines)
