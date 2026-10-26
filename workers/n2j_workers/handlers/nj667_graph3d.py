"""Каналы nj667.* — «Графовый 3D-конструктор» (модуль nj667_graph3d).

Идея: граф, а не картинка — единственный источник правды. Поэтому воркер
никогда не просит модель нарисовать SVG: только узлы, связи и их координаты в
пространстве. Шесть каналов:

  nj667.generate  описание и/или фото изделия → граф целиком. Кадры лежат
                  на сайте (storage/nj667/<code>), в задачу очереди не едут
                  (урок nj646). В контекст — правила пространства, фото
                  для vision (жмём до photo_max_px, дефолт 512) и few-shot
                  из каталога ТОЛЬКО если проект пустой и RAG включён.
                  Если граф уже есть — в модель уезжает ОН, а не первый
                  стул из каталога (владелец, 10.10.2026);
  nj667.inpaint   достройка ВЫДЕЛЕННОГО: «сделай спортивный руль-баран».
                  В модель уезжает не весь проект, а окрестность выбранного
                  узла (сам узел + соседи, /graph3d/w/context с mode=inpaint).
                  Ответ модели — новые узлы и связи, которые САЙТ приклеивает
                  к существующему графу по id (merge), а не заменяет его;
  nj667.build     .scad сборки → STL в OpenSCAD. НЕ про модель: здесь воркер
                  работает как цех — считает CSG и отдаёт файл обратно
                  (multipart). Нет OpenSCAD — честный ответ no_engine, а не
                  тишина: страница тогда предлагает скачать .scad и собрать
                  самому;
  nj667.summary   текстовое саммари графа (для поиска и для вектора);
  nj667.embed     вектор саммари (эмбеддинг-модель из workers/.env) — дальше
                  сайт ищет по нему pgvector'ом;
  nj667.moderate  пре-модерация: проверка текста (подписи, название, описание)
                  на токсичность/мусор плюс словесная оценка структуры.
                  Структуру по-настоящему проверяет САЙТ (AI-Guard, graph_validate):
                  у него есть граф целиком и лимиты.
  nj667.ideas     тема → 30 коротких описаний изделий для кнопок на
                  /graph3d/create. Без проекта: копилка сервиса.
  nj667.caption   название, описание и теги карточки (дешёвая модель).

Контекст и приёмка — через сайт: воркер не ходит в базу NJ667, весь доступ и
все права живут там же, где данные.

Env: NJ667_API_BASE (или общий NJ120_API_BASE), NJ667_SITE_TOKEN (или
WORKER_SITE_TOKEN / WORKER_API_TOKEN), NJ667_MODEL (сборка графа),
NJ667_LIGHT_MODEL (название, описание, теги, идеи, саммари, пре-модерация),
EMBEDDING_MM_* (см. `n2j_workers/mmembed.py`).
"""

from __future__ import annotations

import asyncio
import base64
import json
import logging
import os
import re
import struct
import subprocess
import tempfile
import time

from .. import glb, mmembed, scad, site, vlm
from ..base import HandlerContext
from ..mmembed import EmbedInput, MmEmbeddingClient

log = logging.getLogger("n2j.nj667")

PREFIX = "NJ667"
API_PATH = "/graph3d/w"

CHANNEL = "nj667.generate"
INPAINT_CHANNEL = "nj667.inpaint"
SUMMARY_CHANNEL = "nj667.summary"
EMBED_CHANNEL = "nj667.embed"
MODERATE_CHANNEL = "nj667.moderate"
IDEAS_CHANNEL = "nj667.ideas"
CAPTION_CHANNEL = "nj667.caption"
BUILD_CHANNEL = "nj667.build"

# Подсказка эмбеддинг-модели: нам нужен поиск по смыслу описания, а не
# «похожий документ» — без неё мультимодальные модели часто уходят в картинку.
INSTRUCTION = "Represent the graph description for semantic construction search."

# ---------------------------------------------------------------- подсказки

#: Правила пространства. Владелец, 10.10.2026: без них модель ставит всё в
#: кучу у начала координат, и «трёхколёсный велосипед» превращается в ёжика.
SPACE_RULES = (
    "Правила пространства (нарушать нельзя):\n"
    "1. Сцена — куб со стороной 2*R с центром в (0, 0, 0); R = {radius}. "
    "Ни одна координата не выходит за пределы куба.\n"
    "2. Ось Y — ВВЕРХ. Всё, что стоит на земле (колёса, ножки, опоры), имеет "
    "y < -R/3. Всё, что сверху (крыша, спинка, руль) — y > R/3. Середина "
    "(рама, сиденье, корпус) — между ними.\n"
    "3. НЕ ставь два узла в одну точку: минимальное расстояние между узлами — "
    "R/10.\n"
    "4. СИММЕТРИЯ: парные детали (колёса, ножки, ручки, подлокотники) ставь "
    "зеркально: одинаковый y и z, противоположный x ({x} и -{x}). Это важнее "
    "красоты подписей.\n"
    "5. Рёбра — только между существующими узлами. Ребро без конца или из "
    "узла в себя саму — ошибка.\n"
    "6. Каждая деталь соединена с конструкцией: узел без рёбер — мусор.\n"
)

GENERATE_SYSTEM = (
    "Ты — инженер-конструктор. По описанию и/или фото ты строишь "
    "ПРОСТРАНСТВЕННЫЙ ГРАФ изделия: детали — узлы, соединения — рёбра.\n\n"
    "{space}\n"
    "Правила графа:\n"
    "7. Узлов: от 4 до {max_nodes}. Рёбер: не больше {max_edges}.\n"
    "8. У каждого узла: id (латиница, коротко: n1, n2), type всегда "
    "«connector» (разные типы узлов не нужны — не выбирай их), x/y/z "
    "(числа), label — пустая строка, data (объект, можно пустой).\n"
    "9. У каждого ребра: id (e1, e2), source и target — id УЖЕ СУЩЕСТВУЮЩИХ "
    "узлов, type всегда «rigid», label — пустая строка, style — «line».\n"
    "10. Если в сообщении есть «ТЕКУЩИЙ граф» — это уже собранное изделие. "
    "Правь ЕГО по просьбе: сохрани каркас, не подменяй стулом или "
    "велосипедом из примеров. Верни полный граф после правки.\n"
    "11. Отвечай ТОЛЬКО валидным JSON без пояснений и без markdown-обёртки:\n"
    "{{\"meta\":{{\"title\":\"...\",\"units\":\"unit\"}},"
    "\"nodes\":[{{\"id\":\"n1\",\"type\":\"connector\",\"x\":0,\"y\":0,\"z\":0,"
    "\"label\":\"\",\"data\":{{}}}}],"
    "\"edges\":[{{\"id\":\"e1\",\"source\":\"n1\",\"target\":\"n2\","
    "\"type\":\"rigid\",\"label\":\"\",\"style\":\"line\"}}]}}\n"
)

INPAINT_SYSTEM = (
    "Ты — инженер-конструктор. Тебе дали часть графа изделия: выбранный узел "
    "и его соседей. Нужно достроить или ПЕРЕДЕЛАТЬ эту часть по просьбе "
    "пользователя, не ломая остальное.\n\n"
    "{space}\n"
    "Правила:\n"
    "7. В ответе — ТОЛЬКО те узлы и рёбра, которые относятся к правке: новые "
    "или изменённые. id НОВЫХ узлов не должны совпадать с существующими "
    "(приписывай суффикс: n2a, n2b).\n"
    "8. Если узел переделан целиком — верни его с тем же id и новыми "
    "координатами (label пустой, type — connector); сайт заменит его по id, а не добавит второго.\n"
    "9. Новые узлы соединяй с существующими: рёбра на «ничто» не пройдут "
    "проверку.\n"
    "10. Координаты считай ОТНОСИТЕЛЬНО сцены: выбранный узел стоит там, где "
    "приехал, а новое ставится рядом с ним.\n"
    "11. Отвечай ТОЛЬКО валидным JSON без пояснений и без markdown-обёртки:\n"
    "{{\"nodes\":[...],\"edges\":[...]}}\n"
)

SUMMARY_SYSTEM = (
    "Ты — техписатель. Тебе дали граф изделия: узлы-детали и рёбра-связи.\n"
    "Напиши 1–3 абзаца (до 2500 символов) — что это за конструкция, из каких "
    "частей состоит и как они соединены. Без воды, без маркированных списков, "
    "без заголовка, без кавычек. Нужен текст для поиска по каталогу: человек "
    "ищет «трёхколёсный велосипед с корзиной», твоё саммари должно содержать "
    "эти слова.\n"
)

MODERATE_SYSTEM = (
    "Ты — пре-модератор каталога конструкций. Тебе дали название, описание, "
    "теги и подписи узлов графа.\n"
    "Оцени ТОЛЬКО текст: есть ли оскорбления, нецензурная лексика, "
    "пропаганда, реклама, бессмысленный набор символов, обман (описание не "
    "соответствует названию).\n"
    "Ответь строго JSON без пояснений:\n"
    "{{\"verdict\":\"ok|review|reject\",\"score\":0.0,"
    "\"reason\":\"коротко по-русски\",\"bad\":[\"фраза1\"]}}\n"
    "verdict: ok — публиковать можно, review — человеку стоит взглянуть, "
    "reject — явная дрянь. score — уверенность от 0 до 1.\n"
)


# ------------------------------------------------------------------- helpers

def _clean(text: str, limit: int = 0) -> str:
    """Убрать markdown-обёртку, которую модели лепят вокруг JSON."""
    text = (text or "").strip()

    if text.startswith("```"):
        text = re.sub(r"^```[A-Za-z0-9_+-]*\s*", "", text)
        text = re.sub(r"```\s*$", "", text).strip()

    if limit and len(text) > limit:
        text = text[:limit]

    return text.strip()


def _bool_env(name: str, default: bool = True) -> bool:
    raw = (os.environ.get(name) or "").strip().lower()

    if not raw:
        return default

    return raw not in ("0", "false", "no", "off")


def _int_env(name: str, default: int) -> int:
    try:
        return max(1, int((os.environ.get(name) or "").strip() or default))
    except ValueError:
        return default


def _service_model(fallback: str | None = None) -> str | None:
    own = (os.environ.get("NJ667_MODEL") or "").strip()

    if own:
        return own

    return (str(fallback).strip() or None) if fallback else None


def _model_of(payload: dict, light: bool = False) -> str | None:
    """Модель задачи.

    NJ667_MODEL — сборка графа. NJ667_LIGHT_MODEL — название, описание,
    теги, идеи, саммари, пре-модерация (владелец, 11.10.2026).
    """
    payload = payload or {}

    if light:
        cheap = (os.environ.get("NJ667_LIGHT_MODEL") or "").strip()
        if cheap:
            return cheap
        sent = str(payload.get("model") or "").strip()
        if sent:
            return sent

    own = (os.environ.get("NJ667_MODEL") or "").strip()
    if own:
        return own

    return _service_model(str(payload.get("model") or "").strip() or None)


# ------------------------------------------------------------------- JSON

def _parse_graph(text: str) -> dict:
    """JSON графа из ответа модели.

    Модели регулярно отвечают с преамбулой («Вот граф:») или оборачивают ответ
    в ```json. Поэтому сначала чистим, потом ищем первую { и последнюю }.
    """
    raw = _clean(text)

    if not raw:
        raise ValueError("модель вернула пустой ответ")

    start = raw.find("{")
    stop = raw.rfind("}")

    if start < 0 or stop <= start:
        raise ValueError(f"в ответе нет JSON: {raw[:200]!r}")

    try:
        data = json.loads(raw[start:stop + 1])
    except json.JSONDecodeError as e:
        raise ValueError(f"ответ не JSON ({e}): {raw[:200]!r}") from e

    if not isinstance(data, dict):
        raise ValueError("ответ не объект")

    return data


def _as_graph(data: dict) -> dict:
    """Привести ответ к виду {meta, nodes, edges} с проверкой на мусор."""
    nodes = data.get("nodes")

    if not isinstance(nodes, list) or not nodes:
        raise ValueError("в ответе нет nodes (или они пустые)")

    edges = data.get("edges")

    if not isinstance(edges, list):
        edges = []

    meta = data.get("meta")

    if not isinstance(meta, dict):
        meta = {}

    clean_nodes = [n for n in nodes if isinstance(n, dict)]
    clean_edges = [e for e in edges if isinstance(e, dict)]

    if not clean_nodes:
        raise ValueError("ни один узел не похож на объект")

    return {"meta": meta, "nodes": clean_nodes, "edges": clean_edges}


def _graph_part(context: dict) -> dict:
    """Текущий граф проекта, обрезанный для подсказки. Пустой — узлов нет."""
    graph = context.get("graph") or {}

    if not isinstance(graph, dict):
        return {"nodes": [], "edges": []}

    nodes = [n for n in (graph.get("nodes") or []) if isinstance(n, dict)]
    edges = [e for e in (graph.get("edges") or []) if isinstance(e, dict)]

    if not nodes:
        return {"nodes": [], "edges": []}

    return _compact(nodes, edges)


def _compact(nodes: list[dict], edges: list[dict], limit: int = 4000) -> dict:
    """Обрезать граф до разумного размера перед отправкой в подсказку.

    Тысяча узлов в подсказке — это и дорого, и бесполезно: модель всё равно
    не удержит их в голове. Узлы режем по числу, связи — те, что выжили.
    """
    nodes = [n for n in nodes if isinstance(n, dict)][:400]
    keep = {str(n.get("id") or "") for n in nodes}

    edges = [e for e in edges
             if isinstance(e, dict)
             and str(e.get("source") or "") in keep
             and str(e.get("target") or "") in keep][:600]

    out = {"nodes": nodes, "edges": edges}
    raw = json.dumps(out, ensure_ascii=False)

    if len(raw) <= limit:
        return out

    # Слишком много — убираем data у узлов (модели он почти не нужен).
    for n in nodes:
        n.pop("data", None)

    raw = json.dumps(out, ensure_ascii=False)

    if len(raw) <= limit:
        return out

    while len(nodes) > 8 and len(raw) > limit:
        nodes = nodes[:max(8, len(nodes) // 2)]
        keep = {str(n.get("id") or "") for n in nodes}
        edges = [e for e in edges
                 if str(e.get("source") or "") in keep
                 and str(e.get("target") or "") in keep]
        raw = json.dumps({"nodes": nodes, "edges": edges}, ensure_ascii=False)

    return {"nodes": nodes, "edges": edges}


# --------------------------------------------------------------------- сайт

async def _api(ctx: HandlerContext, path: str, data: dict, timeout: float = 180.0) -> dict:
    ok, reason, res = await site.post_json(
        PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(PREFIX, API_PATH))

    if not ok:
        raise RuntimeError(f"nj667: сайт {path} → {reason}")

    return dict(res or {})


async def _api_soft(ctx: HandlerContext, path: str, data: dict, timeout: float = 180.0) -> dict:
    ok, reason, res = await site.post_json(
        PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(PREFIX, API_PATH))

    body = dict(res or {})

    if not ok and not str(body.get("error") or "").strip():
        body["error"] = reason

    body.setdefault("ok", ok)

    return body


async def _ask(ctx: HandlerContext, messages: list[dict], model: str | None,
               max_tokens: int = 4000, timeout: int = 300) -> str:
    if ctx.llm is None:
        raise RuntimeError("LLM-шлюз недоступен: проверьте провайдера в workers/.env")

    text, _usage = await ctx.llm.chat_with_usage(
        messages, model=model or None, max_tokens=max_tokens, request_timeout=timeout)

    return _clean(text)


async def _context(ctx: HandlerContext, project_id: int, mode: str,
                   prompt: str = "", node_ids: list[str] | None = None,
                   depth: int = 1, rag: bool | None = None,
                   revision_id: int = 0) -> dict:
    body = {
        "project_id": int(project_id),
        "mode": mode,
        "prompt": _clean(prompt, 1000),
        "node_ids": [str(i) for i in (node_ids or [])][:64],
        "depth": max(1, min(3, int(depth))),
    }

    if int(revision_id or 0) > 0:
        body["revision_id"] = int(revision_id)

    if rag is not None:
        body["rag"] = bool(rag)

    body = await _api(ctx, "/context", body, timeout=120)

    if not body.get("ok"):
        raise RuntimeError(f"сайт не отдал контекст: {body.get('error') or 'пустой ответ'}")

    return body


# ------------------------------------------------------------------ few-shot

def _few_shot(context: dict) -> str:
    """Похожие графы из каталога текстом для подсказки (RAG).

    Сайт кладёт в examples сначала конструкции с тем же топологическим
    отпечатком (why=topology), потом близкие по вектору саммари. Модели
    полезно знать, какой образец — «тот же каркас», а какой — «похожий текст».
    """
    examples = [e for e in (context.get("examples") or []) if isinstance(e, dict)]

    if not examples:
        return ""

    blocks = []

    for example in examples[:max(1, _int_env("NJ667_FEW_SHOT", 2))]:
        graph = example.get("graph") or {}

        if not isinstance(graph, dict):
            continue

        nodes = [n for n in (graph.get("nodes") or []) if isinstance(n, dict)][:24]
        edges = [e for e in (graph.get("edges") or []) if isinstance(e, dict)][:32]

        if not nodes:
            continue

        why = str(example.get("why") or "")
        label = (
            "та же конструкция (топология)"
            if why == "topology"
            else "похожий по описанию"
        )

        blocks.append(
            "Пример ({label}) — «{title}» ({summary}):\n{json}".format(
                label=label,
                title=_clean(str(example.get("title") or ""), 80),
                summary=_clean(str(example.get("summary") or ""), 240),
                json=json.dumps({"nodes": nodes, "edges": edges}, ensure_ascii=False)))

    if not blocks:
        return ""

    return ("\nВот похожие конструкции из каталога (сначала те же по "
            "топологии, потом близкие по описанию). Это ОБРАЗЕЦ масштаба "
            "и подробности, НЕ готовое изделие: не копируй их, если "
            "просьба про другое. Построй свою по просьбе:\n\n"
            + "\n\n".join(blocks) + "\n")


def _photo_max_px(context: dict) -> int:
    """До какой длинной стороны жать кадр: контекст сайта → NJ667_PHOTO_MAX_PX → 512."""
    raw = context.get("photo_max_px") if isinstance(context, dict) else None

    try:
        n = int(raw) if raw not in (None, "") else 0
    except (TypeError, ValueError):
        n = 0

    if n <= 0:
        n = vlm.photo_max_w("NJ667", 512)

    return max(128, min(2048, n))


def _shrink_bytes(raw: bytes, max_px: int) -> bytes:
    """Уменьшить кадр по длинной стороне. Нет Pillow — как есть."""
    try:
        import io

        from PIL import Image

        img = Image.open(io.BytesIO(raw)).convert("RGB")
        w, h = img.size
        long = max(w, h)

        if long > max_px:
            scale = max_px / float(long)
            img = img.resize((max(1, round(w * scale)), max(1, round(h * scale))),
                             Image.LANCZOS)

        out = io.BytesIO()
        img.save(out, "JPEG", quality=85, optimize=True)
        return out.getvalue()
    except Exception:  # noqa: BLE001
        return raw


def _photos_of(context: dict) -> list[dict]:
    """Кадры из контекста сайта: base64, не из payload задачи.

    Если кадр больше photo_max_px (дефолт 512) — уменьшаем по длинной
    стороне. Сайт уже мог ужать; это страховка воркера.
    """
    out: list[dict] = []
    max_px = _photo_max_px(context)

    for row in (context.get("photos") or []):
        if not isinstance(row, dict):
            continue

        b64 = str(row.get("b64") or row.get("image_b64") or "").strip()

        if not b64:
            continue

        mime = str(row.get("media_type") or "image/jpeg").strip() or "image/jpeg"

        if not mime.startswith("image/"):
            mime = "image/jpeg"

        try:
            raw = base64.b64decode(b64)

            if raw:
                shrunk = _shrink_bytes(raw, max_px)

                if shrunk and shrunk is not raw:
                    b64 = base64.b64encode(shrunk).decode("ascii")
                    mime = "image/jpeg"
        except Exception:  # noqa: BLE001
            pass

        out.append({"b64": b64, "media_type": mime})

        if len(out) >= 4:
            break

    return out


def _rag_on(payload: dict, context: dict) -> bool:
    """RAG: поле задачи, иначе флаг из контекста сайта, иначе включён."""
    if isinstance(payload, dict) and "rag" in payload:
        return bool(payload.get("rag"))

    if isinstance(context, dict) and "rag" in context:
        return bool(context.get("rag"))

    return True


def _user_msg(text: str, photos: list[dict]) -> dict:
    """Сообщение пользователя: текст или текст + кадры (OpenAI image_url)."""
    if not photos:
        return {"role": "user", "content": text}

    if len(photos) == 1:
        return vlm.img_msg(photos[0]["b64"], text, photos[0]["media_type"])

    content: list[dict] = [{"type": "text", "text": text}]

    for photo in photos:
        content.append({
            "type": "image_url",
            "image_url": {
                "url": f"data:{photo['media_type']};base64,{photo['b64']}",
            },
        })

    return {"role": "user", "content": content}


# ------------------------------------------------------------------ генерация

def _space_block(context: dict) -> str:
    canvas = context.get("canvas") or {}
    radius = float(canvas.get("radius") or 24.0)
    half = round(radius / 2.0, 1)

    return SPACE_RULES.format(radius=round(radius, 1), x=half)


async def run_generate(ctx: HandlerContext, payload: dict) -> dict:
    project_id = int(payload.get("project_id") or 0)

    if not project_id:
        raise ValueError(f"{CHANNEL}: в задаче нет project_id")

    prompt = _clean(str(payload.get("prompt") or ""), 1000)

    await ctx.progress("Готовлю контекст", _clean(prompt, 80) or "по фото")

    model = _model_of(payload)
    rag = payload["rag"] if "rag" in payload else None
    context = await _context(ctx, project_id, "generate", prompt=prompt, rag=rag)
    photos = _photos_of(context)

    if not prompt and not photos:
        raise ValueError(f"{CHANNEL}: нет ни описания, ни фото")

    limits = context.get("limits") or {}
    system = GENERATE_SYSTEM.format(
        space=_space_block(context),
        max_nodes=int(limits.get("max_nodes") or 500),
        max_edges=int(limits.get("max_edges") or 1200),
        node_types=", ".join(context.get("node_types") or ["hub", "connector", "endpoint", "panel"]),
        edge_types=", ".join(context.get("edge_types") or ["rigid", "structural", "hinge", "soft"]),
    )

    current = _graph_part(context)
    few = "" if current["nodes"] or not _rag_on(payload, context) else _few_shot(context)
    if bool(payload.get("simple_pipeline")):
        tail = (
            "Это одношаговый пользовательский режим: результат сразу пойдёт в "
            "сборку из плоских палочек для мороженого и печатных узлов. Сделай "
            "реалистичный, понятный и компактный каркас: примерно 6–18 узлов, "
            "без декоративной мелочи и без очень коротких рёбер; оставь запас "
            "между соединениями, соблюдай симметрию и свяжи все детали с несущей "
            "конструкцией. Для длинных/тонких форм добавь треугольные раскосы. "
            "Ответ — только JSON."
        )
    else:
        tail = (
            "Деталей должно хватать, чтобы изделие узнавалось: у велосипеда — два "
            "или три колеса, рама, руль, сиденье, педали. Ответ — только JSON."
        )

    if current["nodes"]:
        extra = " и по фото" if photos else ""
        user = (
            "Это ТЕКУЩИЙ граф проекта «{title}». Правь ЕГО по просьбе{extra}. "
            "Не подменяй другой конструкцией из каталога (стул, велосипед "
            "и т.п.), если просьба не про неё. Сохрани узнаваемый каркас, "
            "меняй то, о чём просят.\n\n"
            "Просьба: «{prompt}»\n\n"
            "Текущий граф:\n{graph}\n\n"
            "Верни ПОЛНЫЙ граф после правки (все узлы и рёбра, не только "
            "новые). Ответ — только JSON."
        ).format(
            title=_clean(str(context.get("title") or ""), 120),
            extra=extra,
            prompt=prompt or "по фото",
            graph=json.dumps(current, ensure_ascii=False),
        )
    elif photos and prompt:
        user = (
            "Построй граф изделия по фото и описанию:\n\n«{prompt}»\n\n{few}{tail}"
        ).format(prompt=prompt, few=few, tail=tail)
    elif photos:
        user = (
            "Построй граф изделия по фото: узлы-детали и рёбра-связи, как на кадре.\n\n"
            "{few}{tail}"
        ).format(few=few, tail=tail)
    else:
        user = (
            "Построй граф изделия по описанию:\n\n«{prompt}»\n\n{few}{tail}"
        ).format(prompt=prompt, few=few, tail=tail)

    await ctx.progress("Собираю конструкцию", "модель строит граф")

    messages = [
        {"role": "system", "content": system},
        _user_msg(user, photos),
    ]

    for attempt in (1, 2):
        raw = await _ask(ctx, messages, model, max_tokens=_int_env("NJ667_MAX_TOKENS", 4000),
                         timeout=_int_env("NJ667_LLM_TIMEOUT", 300))

        try:
            graph = _as_graph(_parse_graph(raw))
            break
        except ValueError as e:
            log.warning("nj667: попытка %s, ответ не годится: %s", attempt, e)

            if attempt == 2:
                raise

            # Вторая попытка с прямым упоминанием промаха: модели проще
            # исправить себя, если показать, что именно не так.
            user = user + f"\n\nПредыдущий ответ не принят: {e}. Ответь ещё раз, строго JSON."
            messages[1] = _user_msg(user, photos)

    delivered = await _api(ctx, "/graph", {
        "project_id": project_id,
        "graph": graph,
        "merge": False,
        "author_kind": "ai",
        "label": (
            (("правка: " if current["nodes"] else "по описанию: ") + _clean(prompt, 120))
            if prompt else ("правка по фото" if current["nodes"] else "по фото")
        ),
        "model": model or "",
    }, timeout=180)

    if not delivered.get("ok"):
        raise RuntimeError(f"сайт не принял граф: {delivered.get('error') or 'пустой ответ'}")

    nodes = len(graph.get("nodes") or [])
    edges = len(graph.get("edges") or [])

    await ctx.progress("Готово", f"узлов {nodes}, связей {edges}")

    return {"project_id": project_id, "nodes": nodes, "edges": edges,
            "model": model or "", "fixed": int(delivered.get("fixed") or 0),
            "guard": delivered.get("guard") or {}}


async def run_inpaint(ctx: HandlerContext, payload: dict) -> dict:
    project_id = int(payload.get("project_id") or 0)

    if not project_id:
        raise ValueError(f"{INPAINT_CHANNEL}: в задаче нет project_id")

    prompt = _clean(str(payload.get("prompt") or ""), 1000)

    if not prompt:
        raise ValueError(f"{INPAINT_CHANNEL}: пустой prompt")

    node_ids = [str(i) for i in (payload.get("node_ids") or [])][:16]

    if not node_ids:
        raise ValueError(f"{INPAINT_CHANNEL}: не выбран ни один узел")

    await ctx.progress("Готовлю окрестность", f"узлов выбрано: {len(node_ids)}")

    model = _model_of(payload)
    depth = max(1, min(3, int(payload.get("depth") or 1)))
    context = await _context(ctx, project_id, "inpaint", prompt=prompt,
                             node_ids=node_ids, depth=depth)

    graph = context.get("graph") or {}
    part = _compact(graph.get("nodes") or [], graph.get("edges") or [])

    system = INPAINT_SYSTEM.format(space=_space_block(context))

    user = (
        "Проект: «{title}».\n"
        "Выбранные узлы: {ids}.\n"
        "Просьба: «{prompt}»\n\n"
        "Вот часть графа вокруг выбранного (включая соседей на {depth} шаг(а/ов)):\n"
        "{part}\n\n"
        "Верни только новые и изменённые узлы и рёбра. Ответ — только JSON."
    ).format(
        title=_clean(str(context.get("title") or ""), 120),
        ids=", ".join(node_ids[:8]),
        prompt=prompt,
        depth=depth,
        part=json.dumps(part, ensure_ascii=False),
    )

    await ctx.progress("Достраиваю", _clean(prompt, 80))

    for attempt in (1, 2):
        raw = await _ask(ctx, [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ], model, max_tokens=_int_env("NJ667_MAX_TOKENS", 4000),
            timeout=_int_env("NJ667_LLM_TIMEOUT", 300))

        try:
            data = _parse_graph(raw)
        except ValueError as e:
            log.warning("nj667.inpaint: попытка %s, ответ не годится: %s", attempt, e)

            if attempt == 2:
                raise

            user = user + f"\n\nПредыдущий ответ не принят: {e}. Ответь ещё раз, строго JSON."

            continue

        # Достройка может вернуть вообще пустой список узлов: значит, модель
        # меняла только связи. Узлы обязательны только при первой генерации.
        nodes = [n for n in (data.get("nodes") or []) if isinstance(n, dict)]
        edges = [e for e in (data.get("edges") or []) if isinstance(e, dict)]

        if not nodes and not edges:
            log.warning("nj667.inpaint: попытка %s, пустой ответ", attempt)

            if attempt == 2:
                raise ValueError("модель вернула пустую достройку")

            continue

        break

    patch = {"nodes": nodes, "edges": edges}

    if data.get("meta"):
        patch["meta"] = data["meta"]

    delivered = await _api(ctx, "/graph", {
        "project_id": project_id,
        "graph": patch,
        "merge": True,
        "author_kind": "ai",
        "label": "достройка: " + _clean(prompt, 120),
        "model": model or "",
    }, timeout=180)

    if not delivered.get("ok"):
        raise RuntimeError(f"сайт не принял достройку: {delivered.get('error') or 'пустой ответ'}")

    await ctx.progress("Готово", f"узлов {len(nodes)}, связей {len(edges)}")

    return {"project_id": project_id, "nodes": len(nodes), "edges": len(edges),
            "model": model or "", "guard": delivered.get("guard") or {}}


# ------------------------------------------------------------------- саммари

async def run_summary(ctx: HandlerContext, payload: dict) -> dict:
    project_id = int(payload.get("project_id") or 0)

    if not project_id:
        raise ValueError(f"{SUMMARY_CHANNEL}: в задаче нет project_id")

    model = _model_of(payload, light=True)
    revision_id = int(payload.get("revision_id") or 0)
    context = await _context(ctx, project_id, "summary", revision_id=revision_id)
    graph = context.get("graph") or {}
    part = _compact(graph.get("nodes") or [], graph.get("edges") or [],
                    limit=_int_env("NJ667_CONTEXT", 8000))

    await ctx.progress("Пишу саммари", "для поиска по каталогу")

    text = await _ask(ctx, [
        {"role": "system", "content": SUMMARY_SYSTEM},
        {"role": "user", "content": "Название: «{title}»\nГраф:\n{part}".format(
            title=_clean(str(context.get("title") or ""), 120),
            part=json.dumps(part, ensure_ascii=False))},
    ], model, max_tokens=1600, timeout=_int_env("NJ667_LLM_TIMEOUT", 300))

    summary = _clean(re.sub(r"\s+", " ", text), _int_env("NJ667_MAX_SUMMARY", 4000))

    if not summary:
        raise ValueError("модель не написала саммари")

    body = {
        "project_id": project_id,
        "summary": summary,
        "model": model or "",
    }

    if revision_id > 0:
        body["revision_id"] = revision_id

    delivered = await _api(ctx, "/summary", body, timeout=120)

    if not delivered.get("ok"):
        raise RuntimeError(f"сайт не принял саммари: {delivered.get('error') or 'пустой ответ'}")

    return {"project_id": project_id, "summary": summary, "model": model or ""}


# -------------------------------------------------------------------- вектор

async def run_embed(ctx: HandlerContext, payload: dict) -> dict:
    project_id = int(payload.get("project_id") or 0)

    if not project_id:
        raise ValueError(f"{EMBED_CHANNEL}: в задаче нет project_id")

    client = MmEmbeddingClient.from_env()

    if client is None:
        raise RuntimeError(
            "эмбеддинг-сервер не настроен: задайте EMBEDDING_MM_API_URL "
            "(или общий EMBEDDING_API_URL) в workers/.env и перезапустите воркер")

    revision_id = int(payload.get("revision_id") or 0)
    context = await _context(ctx, project_id, "summary", revision_id=revision_id)
    text = _clean(str(context.get("summary") or ""), 1000)

    if not text:
        text = _clean(str(context.get("title") or ""), 200)

    if not text:
        raise ValueError(f"{EMBED_CHANNEL}: у проекта {project_id} нет ни саммари, ни названия")

    await ctx.progress("Считаю вектор", f"{len(text)} символов")

    started = time.monotonic()
    vector = await client.embed_one(EmbedInput(
        text=text, image_b64="", media_type="text/plain", instruction=INSTRUCTION))

    if not vector:
        raise ValueError("эмбеддинг-модель вернула пустой вектор")

    delivered = await _api(ctx, "/embed", {
        "project_id": project_id,
        "vector": vector,
        "model": client.model,
        "text": text[:400],
    }, timeout=120)

    if not delivered.get("ok"):
        raise RuntimeError(f"сайт не принял вектор: {delivered.get('error') or 'пустой ответ'}")

    return {"project_id": project_id, "dims": len(vector), "model": client.model,
            "ms": int((time.monotonic() - started) * 1000)}


# ----------------------------------------------------------------- модерация

async def run_moderate(ctx: HandlerContext, payload: dict) -> dict:
    project_id = int(payload.get("project_id") or 0)

    if not project_id:
        raise ValueError(f"{MODERATE_CHANNEL}: в задаче нет project_id")

    model = _model_of(payload, light=True)
    revision_id = int(payload.get("revision_id") or 0)
    context = await _context(ctx, project_id, "summary", revision_id=revision_id)
    graph = context.get("graph") or {}

    labels = [str(n.get("label") or "") for n in (graph.get("nodes") or [])
              if isinstance(n, dict) and str(n.get("label") or "").strip()][:120]

    await ctx.progress("Проверяю текст", "пре-модерация")

    raw = await _ask(ctx, [
        {"role": "system", "content": MODERATE_SYSTEM},
        {"role": "user", "content": (
            "Название: «{title}»\n"
            "Описание: {summary}\n"
            "Теги: {tags}\n"
            "Подписи узлов: {labels}\n"
            "Узлов: {nodes}, связей: {edges}, без связей: {isolated}"
        ).format(
            title=_clean(str(context.get("title") or ""), 200),
            summary=_clean(str(context.get("summary") or ""), 600) or "нет",
            tags=_clean(str(context.get("tags") or ""), 200) or "нет",
            labels=", ".join(labels) or "нет",
            nodes=len(graph.get("nodes") or []),
            edges=len(graph.get("edges") or []),
            isolated=int(context.get("isolated") or 0),
        )},
    ], model, max_tokens=600, timeout=_int_env("NJ667_LLM_TIMEOUT", 300))

    try:
        data = _parse_graph(raw)
    except ValueError:
        log.warning("nj667.moderate: ответ не JSON, считаем «на review»: %s", raw[:200])
        data = {"verdict": "review", "score": 0.0, "reason": "ответ модели не разобран", "bad": []}

    verdict = str(data.get("verdict") or "review").strip().lower()

    if verdict not in ("ok", "review", "reject"):
        verdict = "review"

    try:
        score = max(0.0, min(1.0, float(data.get("score") or 0.0)))
    except (TypeError, ValueError):
        score = 0.0

    bad = [str(b) for b in (data.get("bad") or []) if str(b).strip()][:12]

    delivered = await _api(ctx, "/moderation", {
        "project_id": project_id,
        "verdict": verdict,
        "score": score,
        "reason": _clean(str(data.get("reason") or ""), 400),
        "bad": bad,
        "model": model or "",
    }, timeout=120)

    if not delivered.get("ok"):
        raise RuntimeError(f"сайт не принял вердикт: {delivered.get('error') or 'пустой ответ'}")

    return {"project_id": project_id, "verdict": verdict, "score": score,
            "bad": len(bad), "model": model or ""}



IDEAS_SYSTEM = (
    "Ты придумываешь идеи изделий для пространственного 3D-конструктора. "
    "По теме пользователя верни РОВНО 30 коротких описаний конструкций. "
    "Каждое — одно-два предложения, 8–28 слов: конкретное изделие и его "
    "главные детали (ножки, колёса, ручки, крышка). Без нумерации внутри "
    "строк, без markdown. Ответ — только JSON:\n"
    "{\"ideas\":[\"...\", \"...\"]}\n"
)



CAPTION_SYSTEM = (
    "Ты — редактор каталога конструкций. По графу изделия придумай "
    "название, короткое описание и теги для карточки каталога.\n"
    "Ответ — только JSON без markdown:\n"
    "{\"title\":\"...\",\"summary\":\"...\",\"tags\":\"тег1, тег2\"}\n"
    "title — до 80 символов, без кавычек, узнаваемое изделие по-русски.\n"
    "summary — 1-3 предложения, до 600 символов: что это и из каких частей.\n"
    "tags — 3-8 слов через запятую, строчными, по-русски.\n"
)

def _parse_ideas(text: str, need: int = 30) -> list[str]:
    """JSON-массив идей из ответа модели. Запасной путь — нумерованный список."""
    raw = _clean(text)

    if not raw:
        raise ValueError("модель вернула пустой ответ")

    ideas: list = []
    start = raw.find("{")
    stop = raw.rfind("}")
    data = None

    if start >= 0 and stop > start:
        try:
            data = json.loads(raw[start:stop + 1])
        except json.JSONDecodeError:
            data = None

    if isinstance(data, dict):
        blob = data.get("ideas") or data.get("items") or data.get("presets") or []
        if isinstance(blob, list):
            ideas = blob
    elif isinstance(data, list):
        ideas = data

    if not ideas:
        bracket = raw.find("[")
        end = raw.rfind("]")
        if bracket >= 0 and end > bracket:
            try:
                blob = json.loads(raw[bracket:end + 1])
                if isinstance(blob, list):
                    ideas = blob
            except json.JSONDecodeError:
                pass

    if not ideas:
        for line in raw.splitlines():
            line = re.sub(r"^\s*(?:[-*]|\d+[.)])\s*", "", line).strip().strip("«»\"'")
            if 8 <= len(line) <= 280:
                ideas.append(line)

    out: list[str] = []
    seen: set[str] = set()

    for item in ideas:
        if isinstance(item, dict):
            item = item.get("text") or item.get("idea") or item.get("prompt") or ""
        s = _clean(str(item), 280)
        key = s.lower()
        if not s or key in seen:
            continue
        seen.add(key)
        out.append(s)
        if len(out) >= need:
            break

    if len(out) < 3:
        raise ValueError(f"модель вернула мало идей: {len(out)}")

    return out


async def run_ideas(ctx: HandlerContext, payload: dict) -> dict:
    theme = _clean(str(payload.get("theme") or ""), 200)

    if not theme:
        raise ValueError(f"{IDEAS_CHANNEL}: пустая тема")

    need = max(8, min(30, int(payload.get("count") or 30)))
    model = _model_of(payload, light=True)

    await ctx.progress("Придумываю идеи", theme)

    messages = [
        {"role": "system", "content": IDEAS_SYSTEM},
        {"role": "user", "content": (
            f"Тема: «{theme}».\n\n"
            f"Верни JSON с ровно {need} разными идеями изделий по этой теме."
        )},
    ]

    last_error = "пусто"
    ideas: list[str] = []

    for attempt in (1, 2):
        raw = await _ask(ctx, messages, model, max_tokens=_int_env("NJ667_IDEAS_MAX_TOKENS", 2500),
                         timeout=_int_env("NJ667_LLM_TIMEOUT", 180))
        try:
            ideas = _parse_ideas(raw, need)
            break
        except ValueError as e:
            last_error = str(e)
            log.warning("nj667.ideas: попытка %s, ответ не годится: %s", attempt, e)
            if attempt == 2:
                raise ValueError(f"{IDEAS_CHANNEL}: {last_error}") from e
            messages.append({"role": "assistant", "content": raw[:1500]})
            messages.append({
                "role": "user",
                "content": f"Предыдущий ответ не принят: {e}. Верни строго JSON {{\"ideas\":[...]}} из {need} строк.",
            })

    delivered = await _api(ctx, "/ideas", {
        "theme": theme,
        "ideas": ideas,
        "model": model or "",
    }, timeout=60)

    if not delivered.get("ok"):
        raise RuntimeError(f"сайт не принял идеи: {delivered.get('error') or 'пустой ответ'}")

    return {"theme": theme, "added": int(delivered.get("added") or len(ideas)),
            "count": len(ideas), "model": model or ""}




def _parse_caption(text: str) -> dict:
    """JSON карточки: title, summary, tags."""
    data = _parse_graph(text)
    title = _clean(str(data.get("title") or data.get("name") or ""), 200)
    summary = _clean(re.sub(r"\s+", " ", str(data.get("summary") or data.get("description") or "")),
                     _int_env("NJ667_MAX_SUMMARY", 4000))
    tags_raw = data.get("tags")
    if isinstance(tags_raw, list):
        bits = [_clean(str(t), 40) for t in tags_raw if str(t).strip()]
        tags = _clean(", ".join(bits), 300)
    else:
        tags = _clean(str(tags_raw or ""), 300)
    if not title or not summary:
        raise ValueError("нет названия или описания")
    return {"title": title, "summary": summary, "tags": tags}


async def run_caption(ctx: HandlerContext, payload: dict) -> dict:
    project_id = int(payload.get("project_id") or 0)

    if not project_id:
        raise ValueError(f"{CAPTION_CHANNEL}: в задаче нет project_id")

    model = _model_of(payload, light=True)
    revision_id = int(payload.get("revision_id") or 0)
    context = await _context(ctx, project_id, "summary", revision_id=revision_id)
    graph = context.get("graph") or {}
    part = _compact(graph.get("nodes") or [], graph.get("edges") or [],
                    limit=_int_env("NJ667_CONTEXT", 8000))

    await ctx.progress("Пишу название", "карточка каталога")

    messages = [
        {"role": "system", "content": CAPTION_SYSTEM},
        {"role": "user", "content": (
            "Сейчас карточка:\n"
            "Название: «{title}»\n"
            "Описание: {summary}\n"
            "Теги: {tags}\n\n"
            "Граф:\n{part}\n\n"
            "Верни JSON с новым title, summary и tags."
        ).format(
            title=_clean(str(context.get("title") or ""), 120) or "нет",
            summary=_clean(str(context.get("summary") or ""), 600) or "нет",
            tags=_clean(str(context.get("tags") or ""), 200) or "нет",
            part=json.dumps(part, ensure_ascii=False),
        )},
    ]

    last_error = "пусто"
    caption = None

    for attempt in (1, 2):
        raw = await _ask(ctx, messages, model, max_tokens=800,
                         timeout=_int_env("NJ667_LLM_TIMEOUT", 300))
        try:
            caption = _parse_caption(raw)
            break
        except ValueError as e:
            last_error = str(e)
            log.warning("nj667.caption: попытка %s, ответ не годится: %s", attempt, e)
            if attempt == 2:
                raise ValueError(f"{CAPTION_CHANNEL}: {last_error}") from e
            messages = list(messages)
            messages.append({"role": "user", "content": (
                f"Предыдущий ответ не принят: {e}. Ответь ещё раз, строго JSON."
            )})

    if not caption:
        raise ValueError(f"{CAPTION_CHANNEL}: {last_error}")

    body = {
        "project_id": project_id,
        "title": caption["title"],
        "summary": caption["summary"],
        "tags": caption["tags"],
    }

    if revision_id > 0:
        body["revision_id"] = revision_id

    delivered = await _api(ctx, "/caption", body, timeout=120)

    if not delivered.get("ok"):
        raise RuntimeError(f"сайт не принял карточку: {delivered.get('error') or 'пустой ответ'}")

    return {"project_id": project_id, "title": caption["title"],
            "summary": caption["summary"], "tags": caption["tags"],
            "model": model or ""}


# ------------------------------------------------------------------ каналы

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    return await run_generate(ctx, payload)


async def handle_inpaint(ctx: HandlerContext, payload: dict) -> dict:
    return await run_inpaint(ctx, payload)


async def handle_summary(ctx: HandlerContext, payload: dict) -> dict:
    return await run_summary(ctx, payload)


async def handle_embed(ctx: HandlerContext, payload: dict) -> dict:
    return await run_embed(ctx, payload)


async def handle_moderate(ctx: HandlerContext, payload: dict) -> dict:
    return await run_moderate(ctx, payload)


async def handle_ideas(ctx: HandlerContext, payload: dict) -> dict:
    return await run_ideas(ctx, payload)


async def handle_caption(ctx: HandlerContext, payload: dict) -> dict:
    return await run_caption(ctx, payload)
# ------------------------------------------------------------- сборка в STL
#
# Здесь нет ни одной модели: OpenSCAD делает то, что делает хорошо, а LLM не
# тратится на геометрию, которую сайт уже посчитал (спецификацию пишет PHP).
# Рендер живёт в scad.py (тот же запуск, что и у nj570), но просим ТОЛЬКО STL:
# картинка сборке не нужна, а проверка BOSL2 в общем render() отсекла бы наш
# случай — наши .scad ничего не подключают. Команду собираем по возможностям
# бинаря: 2015.03 и 2021.01 понимают разное (см. _OPTIONAL_FLAGS).

#: Флаги, которые знает не каждый openscad: оба появились в 2021.01. Старая
#: сборка на «--export-format=binary» отвечает «Unknown --export-format option
#: 'binary'» и РЕНДЕР НЕ НАЧИНАЕТСЯ вовсе — поэтому их наличие спрашиваем у
#: справки бинаря. Теряем мы при этом немного: бинарный STL — дефолт и без
#: флага (а ASCII мы допиливаем сами), а --hardwarnings лишь превращает
#: предупреждения модели в ошибку.
_OPTIONAL_FLAGS = ("--export-format=binary", "--hardwarnings")
_PROBE_CACHE: dict = {}
_BAD_FLAG_RE = re.compile(r"unknown\s+\S*\s*option|unrecognized option", re.I)

#: Пол возраста важнее полноты флагов: `let()` и `norm()` (core/math.scad) —
#: язык 2019.05+. До него парсер спотыкается на первой строке, и это не баг
#: сборки, а отсутствие движка: говорим прямо и отдаём человеку .scad.
_MIN_SCAD = (2019, 5)
_VERSION_RE = re.compile(r"(\d+)\.(\d+)(?:\.\d+)?")

def _build_timeout() -> int:
    """Свой лимит: купол из сотни узлов считается минуты, а не секунды."""
    try:
        return max(30, int(os.environ.get("NJ667_BUILD_TIMEOUT", "900") or 900))
    except ValueError:
        return 900


def _bin_says(bin_path: str, arg: str) -> str:
    """Вывод `openscad <arg>` — и пусто, если бинарь спросить не удалось."""
    try:
        proc = subprocess.run([bin_path, arg], capture_output=True, text=True, timeout=15)
    except (OSError, subprocess.SubprocessError):
        return ""

    return ((proc.stdout or "") + "\n" + (proc.stderr or "")).strip()


def _help_text(bin_path: str) -> str:
    return _bin_says(bin_path, "--help")


def _version_text(bin_path: str) -> str:
    return _bin_says(bin_path, "-v")


def _version_too_old(text: str) -> bool:
    """Календарные версии (2019.05+) меряем по годам; legacy (0.15, 2015 год) — старье.

    Не поняли, что за версия → False: не блокируем сборку догадками.
    """
    hit = _VERSION_RE.search(text or "")

    if not hit:
        return False

    major, minor = int(hit.group(1)), int(hit.group(2))

    return (major, minor) < _MIN_SCAD if major >= 1900 else major < 1


def _probe(bin_path: str) -> tuple:
    """(необязательные флаги, почему этим бинарем не стоит рендерить) — раз на бинарь.

    Справка и версия — не причина ронять сборку: не спросили или не поняли →
    работаем минимальной командой, `openscad -o out.stl in.scad` умеет любой.
    """
    if bin_path not in _PROBE_CACHE:
        flags = [f for f in _OPTIONAL_FLAGS if f.split("=")[0] in _help_text(bin_path)]
        said = _version_text(bin_path)
        first = (said.splitlines() or [""])[0][:60]
        problem = ""

        if _version_too_old(said):
            problem = ("no_engine: нужен OpenSCAD 2019.05+ (модель говорит на let() "
                       "и norm()), а тут: %s" % (first or "неизвестно какой"))

        _PROBE_CACHE[bin_path] = (flags, problem)

    return _PROBE_CACHE[bin_path]


def _openscad_flags(bin_path: str) -> list:
    """Какие необязательные флаги принимает ЭТОТ openscad (см. _probe)."""
    return _probe(bin_path)[0]


def _stl_is_binary(data: bytes) -> bool:
    """84 + 50·N байт и N > 0 — та самая разметка, что ждёт и сайт (stl_stats)."""
    if len(data) < 84:
        return False

    count = int.from_bytes(data[80:84], "little")

    return count > 0 and len(data) == 84 + 50 * count


def _ascii_stl_to_binary(data: bytes) -> bytes:
    """ASCII solid → бинарный STL. Пусто — если это не STL вовсе.

    Без --export-format старые сборки выкладывают текст: в 6 раз тяжелее и
    непонятен браузеру (у нашего просмотрщика свой парсер бинарных граней).
    """
    if b"facet" not in data:
        return b""
    try:
        converted = glb.normalize_stl(data)
        return b"nj667: ascii -> binary".ljust(80, b" ") + converted[80:]
    except (glb.GlbError, ValueError, UnicodeError, struct.error):
        return b""


def _files_of(code: str, timeout: int, exts: tuple = ("stl",)) -> tuple[dict, str]:
    """.scad → {'stl': байты, 'wrl': байты} за ОДИН запуск OpenSCAD.

    Две команды — это два просчёта CSG: сборка из сотни узлов ждалась бы вдвое
    дольше, а «долго» на странице и выглядит как зависание (вопрос владельца,
    13.10.2026). OpenSCAD принимает несколько `-o` и выкладывает их со одного
    просчёта — этим и пользуемся.

    Зачем вообще VRML: цвет доезжает только до него. В STL материалов не бывает
    в природе, поэтому витрину (GLB для браузера) пишем из .wrl, а в цех — STL,
    как и прежде: печать остаётся истиной, GLB — витриной.
    """
    # Свой env — чтобы «нет движка» на странице и в воркере означали одно и то
    # же; иначе сайт смотрит на NJ667_OPENSCAD_BIN, а воркер — на NJ570_*.
    bin_path = (os.environ.get("NJ667_OPENSCAD_BIN") or "").strip() or scad.openscad_bin()

    if bin_path is None:
        return {}, "no_engine: openscad не найден (NJ667_OPENSCAD_BIN/NJ570_OPENSCAD_BIN/PATH)"

    bad = scad.check_includes(code)

    if bad:
        return {}, bad

    flags, problem = _probe(bin_path)

    if problem:
        return {}, problem

    exts = tuple(exts) or ("stl",)

    with tempfile.TemporaryDirectory(prefix="nj667_scad_") as tmp:
        src = os.path.join(tmp, "model.scad")

        with open(src, "w", encoding="utf-8") as handle:
            handle.write(code)

        outs = {ext: os.path.join(tmp, "model." + ext) for ext in exts}

        def command(remaining, use_flags):
            cmd = [bin_path]

            for ext in remaining:
                cmd += ["-o", outs[ext]]

            return cmd + list(use_flags) + [src]

        def run(remaining, use_flags):
            return scad._run(command(remaining, use_flags), outs[remaining[0]], timeout)

        def read(ext):
            try:
                with open(outs[ext], "rb") as handle:
                    return handle.read()
            except OSError:
                return b""

        ok, log_text = run(list(exts), flags)

        if not ok and flags and _BAD_FLAG_RE.search(log_text or ""):
            # Справка пообещала флаг, а бинарь его отверг (или openscad подменили
            # под нами). Повторяем без необязательного и запоминаем: дешевле,
            # чем каждый раз спотыкаться о ту же строку.
            log.warning("nj667: openscad не принял %s — повторяем без него: %s",
                        " ".join(flags), (log_text or "")[:200])
            _PROBE_CACHE[bin_path] = ([], problem)
            ok, log_text = run(list(exts), [])

        if not ok:
            return {}, "openscad: " + (log_text or "пустой лог")[:900]

        files = {ext: read(ext) for ext in exts}

        # Старый бинарь на нескольких `-o` иной раз пишет первый файл и молчит
        # про второй. Добирать приходится отдельным запуском — это ещё один
        # просчёт CSG, поэтому держим его как исключение, а не как норму: молча
        # потерять цвет хуже. --export-format при этом убираем: он про STL, для
        # VRML только мешает.
        missing = [ext for ext in exts if not files.get(ext)]

        for ext in missing:
            ok2, _ = run([ext], [])

            if ok2:
                files[ext] = read(ext)

        files = {ext: raw for ext, raw in files.items() if raw}

    data = files.get("stl") or b""
    out: dict = {}

    if data:
        if len(data) < 84:
            return {}, "STL пуст (меньше заголовка): CSG не собралась"

        if not _stl_is_binary(data):
            converted = _ascii_stl_to_binary(data)

            if not converted:
                return {}, "openscad вернул не STL (ни бинарный 84+50n, ни ASCII solid)"

            log.info("nj667: STL был ASCII (%d байт) — переписали в бинарный (%d)",
                     len(data), len(converted))
            data = converted

        out["stl"] = data

    wrl = files.get("wrl") or b""

    if wrl.startswith(b"#VRML"):
        out["wrl"] = wrl
    elif "wrl" in exts:
        # Памятка админу: это не баг разбора, а возможности движка. Цвет несёт
        # только .wrl, а его в стабильном 2021.01 нет вовсе (в .off/.amf/.3mf
        # цветов нет тоже) — значит GLB будет одноцветным, сколько ни клей.
        log.warning("nj667: VRML не выгрузился (%d байт) — цвета в браузере не будет; "
                    "цвет несёт только экспорт .wrl, а его в OpenSCAD 2021.01 нет — "
                    "нужен development- snapshot новее; проверьте NJ667_OPENSCAD_BIN "
                    "(`openscad --version`)", len(wrl))

    if "stl" not in out:
        return {}, "openscad не выложил STL (%s)" % (", ".join(sorted(files)) or "пусто")

    return out, ""


def _stl_of(code: str, timeout: int) -> tuple[bytes | None, str]:
    """.scad → бинарный STL. Тонкая обёртка над _files_of: STL — единственное,
    что просят узлы к печати (им цвет не нужен, слайсеру всё равно)."""
    files, error = _files_of(code, timeout, ("stl",))

    return files.get("stl"), error


PREVIEW_PX = 128


# Цвет превью — по номеру узла (как строки в таблице): соседние узлы не сливаются.
# Те же цвета, что PAL в scad/generators/assembly.scad (имена OpenSCAD → RGB).
# Деталь «№ t» в общей модели красится PAL[t % 8] (CTYPE кластера = номер типа,
# он же part в плане), значит превью берёт PAL[part % 8] — тот же цвет.
# tests/test_nj667_build.py сверяет имена с файлом .scad.
PREVIEW_PALETTE_NAMES = ["SteelBlue", "IndianRed", "DarkSeaGreen", "Goldenrod",
                         "MediumPurple", "Teal", "Salmon", "SlateGray"]
PREVIEW_PALETTE = [
    (70, 130, 180),   # SteelBlue
    (205, 92, 92),    # IndianRed
    (143, 188, 143),  # DarkSeaGreen
    (218, 165, 32),   # Goldenrod
    (147, 112, 219),  # MediumPurple
    (0, 128, 128),    # Teal
    (250, 128, 114),  # Salmon
    (112, 128, 144),  # SlateGray
]


def _preview_png(stl: bytes, size: int = PREVIEW_PX, color: tuple = PREVIEW_PALETTE[0]) -> bytes:
    """Превью узла: PNG size×size, изометрия, плоское освещение, без GL.

    Растеризуем сами (z-buffer на чистом Python): у воркера нет дисплея, а
    OpenSCAD в PNG требует OpenGL. Для картинки 128 px этого хватает: каждый
    треугольник проецируется и закрашивается по bbox, пиксель проверяется по
    барицентрическим координатам. Пустой или битый STL — b"" (превью не будет,
    сам узел от этого не падает).
    """
    import io
    import math

    from PIL import Image

    if not stl:
        return b""

    if not _stl_is_binary(stl):
        stl = _ascii_stl_to_binary(stl)

    if len(stl) < 84:
        return b""

    count = struct.unpack_from("<I", stl, 80)[0]

    # Огромная сетка растеризовалась бы минутами на Python — превью для неё не нужно.
    if count <= 0 or count > 400000 or len(stl) < 84 + 50 * count:
        return b""

    # Камера сверху-сбоку: поворот вокруг вертикали и наклон 25°.
    yaw, pitch = math.radians(-35.0), math.radians(25.0)
    cy_, sy_ = math.cos(yaw), math.sin(yaw)
    cp_, sp_ = math.cos(pitch), math.sin(pitch)

    def view(p):
        x, y, z = p
        x1 = x * cy_ - y * sy_
        y1 = x * sy_ + y * cy_

        # u — вправо, v — вверх на экране, w — глубина (больше = ближе к камере).
        return x1, y1 * sp_ + z * cp_, -y1 * cp_ + z * sp_

    tris = []

    for rec in struct.iter_unpack("<12f2x", memoryview(stl)[84:84 + 50 * count]):
        tris.append((view(rec[3:6]), view(rec[6:9]), view(rec[9:12])))

    us = [p[0] for t in tris for p in t]
    vs = [p[1] for t in tris for p in t]
    umin, umax, vmin, vmax = min(us), max(us), min(vs), max(vs)
    span = max(umax - umin, vmax - vmin, 1e-9)
    margin = 6.0
    scale = (size - 2 * margin) / span
    ox = (size - (umax - umin) * scale) / 2.0
    oy = (size - (vmax - vmin) * scale) / 2.0

    light = (-0.35, 0.45, 0.82)
    ln = math.sqrt(sum(c * c for c in light))
    light = tuple(c / ln for c in light)
    base = (float(color[0]), float(color[1]), float(color[2]))

    faces = []

    for (p0, p1, p2) in tris:
        e1 = (p1[0] - p0[0], p1[1] - p0[1], p1[2] - p0[2])
        e2 = (p2[0] - p0[0], p2[1] - p0[1], p2[2] - p0[2])
        n = (e1[1] * e2[2] - e1[2] * e2[1], e1[2] * e2[0] - e1[0] * e2[2], e1[0] * e2[1] - e1[1] * e2[0])
        nl = math.sqrt(n[0] ** 2 + n[1] ** 2 + n[2] ** 2)

        if nl < 1e-12:
            continue

        # Модуль скалярного произведения: нормали STL часто врут по знаку.
        k = abs(sum(n[i] * light[i] for i in range(3))) / nl
        # Не темнее 60 % цвета: освещённая грань совпадает с цветом в 3D, а не глушится.
        shade = 0.6 + 0.4 * min(1.0, k)
        col = (min(255, int(base[0] * shade)), min(255, int(base[1] * shade)), min(255, int(base[2] * shade)))

        pts = []

        for (u, v, w) in (p0, p1, p2):
            pts.append((ox + (u - umin) * scale, size - (oy + (v - vmin) * scale), w))

        faces.append((pts, col))

    depth = [-1e30] * (size * size)
    # Фон прозрачный (альфа 0): картинка одинаково смотрится и днём, и ночью.
    pix = [(0, 0, 0, 0)] * (size * size)

    for pts, col in faces:
        (x0, y0, w0), (x1, y1, w1), (x2, y2, w2) = pts
        den = (y1 - y2) * (x0 - x2) + (x2 - x1) * (y0 - y2)

        if abs(den) < 1e-12:
            continue

        minx = max(0, int(math.floor(min(x0, x1, x2))))
        maxx = min(size - 1, int(math.ceil(max(x0, x1, x2))))
        miny = max(0, int(math.floor(min(y0, y1, y2))))
        maxy = min(size - 1, int(math.ceil(max(y0, y1, y2))))

        for py in range(miny, maxy + 1):
            cy = py + 0.5
            row = py * size

            for px in range(minx, maxx + 1):
                cx = px + 0.5
                l0 = ((y1 - y2) * (cx - x2) + (x2 - x1) * (cy - y2)) / den
                l1 = ((y2 - y0) * (cx - x2) + (x0 - x2) * (cy - y2)) / den
                l2 = 1.0 - l0 - l1

                if l0 < -1e-9 or l1 < -1e-9 or l2 < -1e-9:
                    continue

                d = l0 * w0 + l1 * w1 + l2 * w2
                i = row + px

                if d > depth[i]:
                    depth[i] = d
                    pix[i] = (col[0], col[1], col[2], 255)

    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    img.putdata(pix)
    buf = io.BytesIO()
    img.save(buf, "PNG", optimize=True)

    return buf.getvalue()


async def _build_upload(build_id: int, data: bytes, engine: str, limit_mb: int,
                        part: int | None = None, kind: str = "stl",
                        parts_run_id: int | None = None) -> str:
    """Отдать сайту файл сборки (multipart): kind="stl" или "glb".

    Возвращает текст проблемы или "".

    Сайт, а не воркер, решает, куда положить файл и как его назвать: у сайта
    есть проект, права и диск. Пустой адрес выгрузки — не ошибка воркера, а
    настроенный режим «скачиваем сами»: об этом пишем в лог явно (урок nj570:
    потерянные молча байты искали неделю).
    """
    base = site.module_api_base(PREFIX, API_PATH)
    token, _ = site.site_token(PREFIX)

    if not (base and token and data):
        log.warning("nj667: выгрузка ВЫКЛЮЧЕНА (нет %s или токена) — %d байт STL потеряны",
                    base or "NJ667_API_BASE", len(data))
        return "выгрузка не настроена"

    if limit_mb and len(data) > limit_mb * 1024 * 1024:
        return f"STL больше {limit_mb} МБ — не отправляем"

    import httpx

    try:
        async with httpx.AsyncClient(timeout=300.0) as client:
            # preview — PNG-картинка узла (превью строки деталей), не модель.
            kind = kind if kind in ("glb", "preview") else "stl"
            form = {"build_id": str(build_id), "engine": engine, "kind": kind}

            if part is not None:
                # Узел к печати: у сайта по этому числу своя строка, свой путь
                # и своя ошибка. None — файл всей сборки.
                form["part"] = str(part)
                if parts_run_id is not None and int(parts_run_id) > 0:
                    form["parts_run_id"] = str(int(parts_run_id))

            ext = {"glb": "glb", "preview": "png"}.get(kind, "stl")
            name = f"nj667-{build_id}" + ("" if part is None else f"-part-{part}") + "." + ext
            mime = {"glb": "model/gltf-binary", "preview": "image/png"}.get(kind, "model/stl")

            res = await client.post(
                base.rstrip("/") + "/build_file",
                data=form,
                files={"file": (name, data, mime)},
                headers={"X-Worker-Token": token})

        if res.status_code != 200:
            return f"сайт принял STL с кодом {res.status_code}: {res.text[:300]}"
    except Exception as exc:  # noqa: BLE001
        log.warning("nj667: upload failed:\n%s", __import__("traceback").format_exc())
        return f"выгрузка не удалась: {exc}"

    return ""


async def handle_build_parts(ctx: HandlerContext, payload: dict) -> dict:
    """nj667.build (kind=parts): узлы к печати — по STL на каждый тип детали.

    Узел — это ОДИН рендер в `mode="single"` (плашмя на столе, без соседей):
    в цех несут деталь, а не «всё сразу». Пачка идёт одной задачей нарочно:
    отдельная очередь на каждый тип создала бы конкурирующие рендеры и не дала
    бы одного сводного ответа на вопрос «что из этого встало».

    Бюджет (budget_s) назначает сайт, мы только считаем время: не успели —
    бросаем на середине, а не тянем до kill'а, иначе человек не получил бы и
    готового. Недобранное доделывается той же кнопкой (страница это и пишет).
    """
    build_id = int(payload.get("build_id") or 0)
    run_id = int(payload.get("parts_run_id") or 0)

    if build_id <= 0:
        return {"ok": False, "error": "nj667.build: нет build_id"}

    try:
        plan = await _api(ctx, "/build", {
            "build_id": build_id, "kind": "parts", "parts_run_id": run_id})
        run_id = int(plan.get("parts_run_id") or run_id)
    except Exception as exc:  # noqa: BLE001
        await _api_soft(ctx, "/build_result", {
            "build_id": build_id, "parts_run_id": run_id, "ok": False, "parts": [],
            "error": f"план узлов не пришёл: {exc}"})

        return {"ok": False, "error": str(exc)}

    specs = [p for p in (plan.get("parts") or []) if isinstance(p, dict)]

    if not plan.get("ok") or not specs:
        error = str(plan.get("error") or "список узлов пуст")

        await _api_soft(ctx, "/build_result", {"build_id": build_id, "parts_run_id": run_id,
                                               "ok": False, "parts": [], "error": error})

        return {"ok": False, "error": error, "no_engine": error.startswith("no_engine")}

    budget = int(plan.get("budget_s") or 0) or _build_timeout()
    limit_mb = int(plan.get("max_stl_mb") or 0)
    per_render = _build_timeout()
    started = time.monotonic()
    results: list[dict] = []
    total_ms = 0

    log.info("nj667: сборка #%s — пачка узлов: %d шт., бюджет %d с",
             build_id, len(specs), budget)

    for spec in specs:
        part = int(spec.get("part") if spec.get("part") is not None else -1)
        left = budget - (time.monotonic() - started)

        if results and left < min(per_render, 120):
            log.info("nj667: сборка #%s — бюджет кончился, узел %d не начинаем", build_id, part)
            break

        try:
            context = await _api(ctx, "/build", {
                "build_id": build_id, "part": part, "parts_run_id": run_id})
        except Exception as exc:  # noqa: BLE001
            results.append({"part": part, "ok": False, "render_ms": 0,
                            "error": f".scad узла не пришёл: {exc}"})
            continue

        code = str(context.get("scad") or "")

        if not context.get("ok") or not code.strip():
            results.append({"part": part, "ok": False, "render_ms": 0,
                            "error": str(context.get("error") or "пустой .scad")})
            continue

        began = time.monotonic()

        # Семафор на каждый узел, а не на всю пачку: иначе nj570 ждал бы
        # получаса ради чужих деталей.
        async with scad._render_sem():
            data, error = await asyncio.to_thread(
                _stl_of, code, max(30, int(min(per_render, left if left > 0 else per_render))))

        render_ms = int((time.monotonic() - began) * 1000)
        total_ms += render_ms

        if error:
            results.append({"part": part, "ok": False, "error": error, "render_ms": render_ms})
            await ctx.heartbeat()
            continue

        problem = await _build_upload(build_id, data, "openscad", limit_mb, part=part,
                                      parts_run_id=run_id)
        results.append({"part": part, "ok": not problem, "error": problem,
                        "render_ms": render_ms, "bytes": len(data)})

        # Превью 128 px рядом с STL: строка деталей показывает картинку без
        # разбора модели в браузере. Ошибка превью узел не валит: STL важнее.
        try:
            color = PREVIEW_PALETTE[int(part) % len(PREVIEW_PALETTE)]
            png = await asyncio.to_thread(_preview_png, data, PREVIEW_PX, color)
        except Exception:  # noqa: BLE001
            log.warning("nj667: превью узла %s не нарисовалось:\n%s", part,
                        __import__("traceback").format_exc())
            png = b""

        if png:
            prev = await _build_upload(build_id, png, "openscad", limit_mb, part=part,
                                        kind="preview", parts_run_id=run_id)

            if prev:
                log.warning("nj667: превью узла %s не принято сайтом: %s", part, prev)

        await ctx.heartbeat()

    done = sum(1 for r in results if r.get("ok"))
    failed = [r for r in results if not r.get("ok")]
    ok = done > 0 and not failed

    await _api_soft(ctx, "/build_result", {
        "build_id": build_id,
        "parts_run_id": run_id,
        "ok": ok,
        "parts": results,
        "render_ms": total_ms,
        "error": (failed[0].get("error") or "") if failed else "",
    })

    log.info("nj667: сборка #%s — узлы: %d готово, %d сбой, %d мс",
             build_id, done, len(failed), total_ms)

    return {"ok": ok, "build_id": build_id, "done": done, "failed": len(failed),
            "skipped": max(0, len(specs) - len(results)), "render_ms": total_ms}


async def handle_build(ctx: HandlerContext, payload: dict) -> dict:
    """nj667.build: взять .scad сборки, отрендерить STL, вернуть её сайту.

    Ответ задачи — только статистика: файл уезжает на сайт сразу (временный
    каталог воркера живёт минуты), а состояние сборки пишет сайт: только он
    знает, принял ли он байты.
    """
    build_id = int(payload.get("build_id") or 0)

    if build_id <= 0:
        return {"ok": False, "error": "nj667.build: нет build_id"}

    if str(payload.get("kind") or "") == "parts":
        return await handle_build_parts(ctx, payload)

    log.info("nj667: сборка #%s — рендер STL", build_id)

    try:
        context = await _api(ctx, "/build", {"build_id": build_id})
    except Exception as exc:  # noqa: BLE001
        await _api_soft(ctx, "/build_result", {
            "build_id": build_id, "ok": False, "error": f"контекст не пришёл: {exc}"})
        return {"ok": False, "error": str(exc)}

    code = str(context.get("scad") or "")

    if not context.get("ok") or not code.strip():
        error = str(context.get("error") or "пустой .scad")
        await _api_soft(ctx, "/build_result", {"build_id": build_id, "ok": False, "error": error})
        return {"ok": False, "error": error, "no_engine": error.startswith("no_engine")}

    render_kind = str(context.get("render_kind") or "legacy").strip().lower()

    if render_kind not in {"glb", "stl", "legacy"}:
        render_kind = "legacy"

    want_glb = render_kind != "stl" and bool(context.get("glb", True))

    if render_kind == "glb" and not want_glb:
        error = "GLB-рендер недоступен: сайт не подтвердил поддержку GLB."
        await _api_soft(ctx, "/build_result", {"build_id": build_id, "ok": False, "error": error})
        return {"ok": False, "error": error}

    log.info("nj667: сборка #%s — рендер %s", build_id, render_kind)
    timeout = _build_timeout()
    started = time.monotonic()

    # Для GLB OpenSCAD пишет STL во временный каталог для конвертации, но сайт
    # получает только GLB. VRML нужен лишь цветной модели; STL-режим — только STL.
    async with scad._render_sem():
        files, error = await asyncio.to_thread(_files_of, code, timeout,
                                               ("stl", "wrl") if want_glb else ("stl",))

    render_ms = int((time.monotonic() - started) * 1000)
    engine = "openscad"
    data = files.get("stl") or b""

    if error:
        await _api_soft(ctx, "/build_result", {
            "build_id": build_id, "ok": False, "error": error, "render_ms": render_ms})

        return {"ok": False, "error": error, "render_ms": render_ms,
                "no_engine": error.startswith("no_engine")}

    limit_mb = int(context.get("max_stl_mb") or 0)
    glb_bytes = b""
    glb_note = ""

    if want_glb:
        name = str(context.get("name") or f"nj667-build-{build_id}")

        try:
            if files.get("wrl"):
                # Цветная сборка: материал на каждый `color()` из OpenSCAD.
                glb_bytes = glb.wrl_to_glb(files["wrl"], name)
            else:
                glb_bytes = glb.stl_to_glb(data, name)
                glb_note = ("VRML не выгрузился — GLB собран из STL, цвета в нём нет "
                            "(цвет выносит только .wrl: в OpenSCAD 2021.01 его нет, нужен "
                            "snapshot новее)")
        except glb.GlbError as exc:
            glb_note = f"GLB из VRML не собрался ({exc})"
            log.warning("nj667: сборка #%s: %s — пробуем собрать GLB из STL", build_id, glb_note)

            try:
                glb_bytes = glb.stl_to_glb(data, name)
            except Exception as exc2:  # noqa: BLE001
                glb_note = f"GLB не собран ({exc2})"
        except Exception as exc:  # noqa: BLE001 — конвертер не имеет права валить задачу
            glb_note = f"GLB не собран ({exc})"
            log.warning("nj667: сборка #%s: GLB упал — %s", build_id, exc)

    glb_colored = bool(glb_bytes) and bool(files.get("wrl"))
    notes = [glb_note] if glb_note else []
    sent = 0
    glb_ok = False
    stl_ok = False

    if render_kind == "stl":
        problem = await _build_upload(build_id, data, engine, limit_mb)

        if problem:
            notes.append("stl: " + problem)
        else:
            sent = len(data)
            stl_ok = True
    elif glb_bytes:
        problem = await _build_upload(build_id, glb_bytes, engine, limit_mb, kind="glb")

        if problem:
            notes.append("glb: " + problem)
        else:
            sent = len(glb_bytes)
            glb_ok = True

    # Старые очереди до render_kind продолжают старый контракт. Только legacy
    # допускает хранение STL рядом с GLB и STL-fallback, если GLB не доехал.
    if render_kind == "legacy" and (bool(context.get("keep_stl", True)) or not glb_ok):
        problem = await _build_upload(build_id, data, engine, limit_mb)

        if problem:
            notes.append("stl: " + problem)
        elif not sent:
            sent = len(data)
            stl_ok = True

    error = "; ".join(notes)

    if render_kind == "glb":
        ok = glb_ok
    elif render_kind == "stl":
        ok = stl_ok
    else:
        ok = bool(sent)

    await _api_soft(ctx, "/build_result", {
        "build_id": build_id,
        "ok": ok,
        "render_ms": render_ms,
        "bytes": len(data),
        "glb_bytes": len(glb_bytes),
        "glb_colored": glb_colored,
        "error": error,
    })

    log.info("nj667: сборка #%s: STL %d байт, GLB %d байт, тип %s, рендер %d мс",
             build_id, len(data), len(glb_bytes), render_kind, render_ms)

    return {"ok": ok, "build_id": build_id, "bytes": len(data),
            "glb_bytes": len(glb_bytes), "render_ms": render_ms,
            "glb_colored": glb_colored, "render_kind": render_kind,
            "error": error or None}
