"""Каналы nj670.* — ProcessHub (модуль nj670_processhub).

Сайт остаётся единственным хозяином данных: воркер получает контекст через
`/process/w/context`, а результат отдаёт в `/process/w/graph|audit|report`.
В PostgreSQL воркер не ходит.

Каналы:

  nj670.synthesize  текст/PDF/скан/аудио → BPMN-схема (план) или AI-достройка
                    выбранного узла. Без LLM работает правиловый разбор текста
                    (честно помечается model='rules-engine').
  nj670.mine        CSV/XLSX журнала Case_ID/Activity/Timestamp/Resource →
                    фактическая схема по частотам переходов и паузам.
  nj670.diff        план + факт → находки аудита и оценка потерь. Если модель
                    недоступна или ответ не разобрался — детерминированные
                    правила, чтобы аудит не оставался пустым.
  nj670.export      структура регламента → PNG-схема → DOCX/PDF по ГОСТ.

Env (читаются только внутри функций):

  NJ670_API_BASE             адрес приёмной сайта (иначе NJ120_API_BASE,
                             путь /process/w добавляется сам)
  NJ670_SITE_TOKEN           токен приёмной (иначе WORKER_SITE_TOKEN,
                             затем WORKER_API_TOKEN)
  NJ670_MODEL                «провайдер::модель» для синтеза и аудита
  NJ670_LIGHT_MODEL          экономная модель для рутинных правок
  NJ670_ASR_URL, NJ670_ASR_KEY, NJ670_ASR_MODEL — распознавание речи
                             (OpenAI-совместимый /audio/transcriptions)

Требования: httpx, python-docx, reportlab, openpyxl, Pillow. pypdf — для
текстовых PDF; без него PDF можно передать только как скан (vision).
"""

from __future__ import annotations

import base64
import csv
import datetime as dt
import hashlib
import io
import json
import logging
import os
import re
import statistics
from typing import Any

import httpx

from .. import site, webread
from ..base import HandlerContext

log = logging.getLogger("n2j.nj670")

PREFIX = "NJ670"
API_PATH = "/process/w"

CHANNEL = "nj670.synthesize"
MINE_CHANNEL = "nj670.mine"
DIFF_CHANNEL = "nj670.diff"
EXPORT_CHANNEL = "nj670.export"
ONTOLOGY_CHANNEL = "nj670.ontology"

NODE_TYPES = {
    "start", "end", "user_task", "service_task", "manual_task",
    "gateway_xor", "gateway_and", "timer", "subprocess",
}
EDGE_TYPES = {"sequence", "conditional", "parallel", "loop"}

SYNTH_SYSTEM = """Ты — архитектор бизнес-процессов и эксперт по BPMN 2.0.
Твоя задача — превратить описание регламента в схему процесса.

Верни ТОЛЬКО валидный JSON без markdown и пояснений:
{
  "lanes": [{"id": "lane_cfo", "title": "Финансовый контролер", "role_code": "cfo"}],
  "nodes": [
    {"id": "start", "step_id": "start", "type": "start", "title": "Заявка создана", "lane": "lane_initiator"},
    {"id": "request", "step_id": "request", "type": "user_task", "title": "Создаёт заявку",
     "lane": "lane_initiator", "role_code": "initiator", "system_code": "1c",
     "input_code": "memo", "output_code": "memo",
     "duration_minutes": 60, "wait_time_minutes": 0,
     "properties": {"description": "что именно делает шаг"}},
    {"id": "end", "step_id": "end", "type": "end", "title": "Готово", "lane": "lane_accountant"}
  ],
  "edges": [
    {"from": "start", "to": "request", "type": "sequence", "condition": ""},
    {"from": "legal", "to": "approve", "type": "loop", "condition": "договор возвращён"}
  ],
  "meta": {"title": "Название схемы", "note": "что допущено и требует проверки"}
}

Жёсткие правила:
1. type только: start, end, user_task, service_task, manual_task, gateway_xor,
   gateway_and, timer, subprocess.
2. start ровно один, end ровно один. Первый шаг связан с start, последний — с end.
3. role_code / system_code / input_code / output_code — ТОЛЬКО коды из
   справочника компании. Если подходящей сущности нет — оставь пустую строку.
4. id и step_id — латиница/цифры/подчёркивания, уникальные, короткие.
5. Дорожек не больше 8; каждая дорожка соответствует роли.
6. Условия разветвления — в gateway_xor + type="conditional" у исходящих рёбер.
   Параллельные ветки — gateway_and + type="parallel".
7. duration_minutes — работа в минутах; wait_time_minutes — ожидание/согласование.
   Рабочий день = 480 минут.
8. Если дан ТЕКУЩИЙ граф и просьба — верни ПОЛНЫЙ граф после правки, сохранив
   id и координаты существующих узлов (x, y), если они были.
9. Никаких выдуманных систем и должностей. Никаких пояснений вне JSON.
"""

ONTOLOGY_SYSTEM = """Ты — бизнес-аналитик и архитектор онтологии предприятия.
На вход дан сырой документ (регламент, описание отдела, заметки, расшифровка
встречи). Нужно вытащить из него словарь компании и заготовки процессов.

Верни ТОЛЬКО валидный JSON без markdown и пояснений:
{
  "entities": [
    {"class_type": "role", "code": "lawyer", "name": "Юрист",
     "attributes": {"description": "проверяет договоры"},
     "source": "цитата из документа, откуда взята сущность"},
    {"class_type": "system", "code": "1c", "name": "1С:ERP", "attributes": {}},
    {"class_type": "artifact", "code": "contract", "name": "Договор", "attributes": {}},
    {"class_type": "department", "code": "sales", "name": "Отдел продаж", "attributes": {}}
  ],
  "processes": [
    {"title": "Согласование договора", "description": "как идёт процесс",
     "department": "Юридический отдел", "owner_role_code": "lawyer",
     "steps": [
       {"title": "Готовит проект договора", "type": "user_task",
        "role_code": "lawyer", "system_code": "word",
        "input_code": "request", "output_code": "contract",
        "duration_minutes": 60, "wait_time_minutes": 0, "condition": ""}
     ]}
  ],
  "note": "что осталось неясным"
}

Жёсткие правила:
1. class_type только: role, system, artifact, event, metric, rule, department.
2. Ничего не выдумывай: только то, что есть в тексте. Сомневаешься — не включай.
3. Если сущность уже есть в справочнике — используй её code и то же имя,
   не создавай дубль с другим написанием.
4. code — латиница, цифры и подчёркивания, короткий и стабильный.
5. processes — не больше 5; в каждом 3–20 шагов, начало и конец не нужны.
6. role_code/system_code/input_code/output_code в шагах — коды из справочника
   или из entities этого же ответа. Нет подходящего — пустая строка.
7. duration_minutes — работа, wait_time_minutes — ожидание/согласование.
   Если срок не назван, ставь 0 (не придумывай).
8. condition заполняй только для шагов с развилкой («если…», «в случае…»).
9. Никаких пояснений вне JSON.
"""

MARKER_COLORS = {
    "start": "#dcfce7",
    "end": "#fee2e2",
    "user_task": "#ffffff",
    "service_task": "#eef2ff",
    "manual_task": "#fff7ed",
    "gateway_xor": "#fef9c3",
    "gateway_and": "#fef9c3",
    "timer": "#f0f9ff",
    "subprocess": "#f5f3ff",
}


# --------------------------------------------------------------------------
# Общие помощники
# --------------------------------------------------------------------------

def _clean(value: Any, limit: int = 0) -> str:
    text = str(value or "").strip()
    return text[:limit] if limit else text


def _int(value: Any, default: int = 0) -> int:
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return default


def _float(value: Any, default: float = 0.0) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def _model(payload: dict, light: bool = False) -> str | None:
    if light:
        value = (os.getenv("NJ670_LIGHT_MODEL") or "").strip()
        if value:
            return value
    value = (os.getenv("NJ670_MODEL") or "").strip()
    if value:
        return value
    sent = _clean((payload or {}).get("model"))
    return sent or None


async def _api(path: str, data: dict, timeout: float = 180.0) -> dict:
    ok, reason, res = await site.post_json(
        PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(PREFIX, API_PATH))
    body = dict(res or {})

    if not ok and not _clean(body.get("error")):
        body["error"] = reason

    if not ok:
        raise RuntimeError(f"nj670: сайт {path} → {reason}")

    return body


async def _api_soft(path: str, data: dict, timeout: float = 180.0) -> dict:
    ok, reason, res = await site.post_json(
        PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(PREFIX, API_PATH))
    body = dict(res or {})

    if not ok and not _clean(body.get("error")):
        body["error"] = reason

    body.setdefault("ok", ok)
    return body


async def _refuse(kind: str, ids: dict, error: str) -> None:
    payload = dict(ids or {})
    payload["error"] = _clean(error, 500) or "не удалось выполнить задачу"
    log.warning("nj670 %s: отказ: %s", kind, payload["error"])

    try:
        await _api_soft("/fail", payload, timeout=60)
    except Exception as e:  # noqa: BLE001
        log.warning("nj670: /fail не принят: %s", e)


async def _ask(ctx: HandlerContext, messages: list[dict], model: str | None,
               max_tokens: int = 6000, timeout: int = 300) -> tuple[str, dict]:
    if ctx.llm is None:
        raise RuntimeError("LLM-шлюз недоступен: проверьте провайдера в workers/.env")

    text, usage = await ctx.llm.chat_with_usage(
        messages, model=model or None, max_tokens=max_tokens,
        request_timeout=timeout)
    return _clean(text), dict(usage or {})


def _json_of(text: str) -> dict:
    data = webread.json_from_text(text)

    if not isinstance(data, dict):
        raise ValueError(f"модель вернула не JSON: {_clean(text, 200)!r}")

    return data


# --------------------------------------------------------------------------
# Синтез
# --------------------------------------------------------------------------

async def handle_synthesize(ctx: HandlerContext, payload: dict) -> dict:
    version_id = _int(payload.get("version_id"))
    process_id = _int(payload.get("process_id"))
    ids = {"version_id": version_id, "process_id": process_id}

    if not version_id:
        raise ValueError("nj670.synthesize: в задаче нет version_id")

    try:
        await ctx.progress("Готовлю контекст", f"версия {version_id}")
        context = await _api("/context", {
            "version_id": version_id,
            "process_id": process_id,
            "mode": payload.get("mode") or "full",
            "node_id": payload.get("node_id") or "",
            "instruction": payload.get("instruction") or "",
            # Файл лежит на сайте (storage/nj670); воркер получает его байты
            # только вместе с контекстом — прямого доступа к хранилищу нет.
            "source": payload.get("source") or {},
        }, timeout=120)

        text = await _source_text(ctx, context, ids)
        mode = _clean(context.get("mode") or payload.get("mode") or "full")
        graph = context.get("current_graph") or {}

        if not text and mode != "copilot":
            raise ValueError("в источнике нет текста: приложите текстовый файл или настройте распознавание")

        await ctx.progress("Строю схему", "модель размечает шаги" if ctx.llm else "правила")

        try:
            raw = await _llm_graph(ctx, context, text, payload)
            graph = _clean_graph(raw, context)
            model = _model(payload) or "llm"
            note = _clean((raw or {}).get("meta", {}).get("note") if isinstance(raw, dict) else "", 500)
        except Exception as e:  # noqa: BLE001 — есть правиловый запас
            log.warning("nj670.synthesize: модель не справилась (%s), разбираю правилами", e)

            if mode == "copilot":
                raise RuntimeError(
                    "AI-достройка недоступна: модель не ответила. "
                    "Схему можно править вручную на холсте.") from e

            graph = _text_graph(text, context)
            model = "rules-engine"
            note = "Черновик по правилам: модель недоступна или ответ не разобрался."

        await ctx.progress("Сохраняю схему", f"{len(graph.get('nodes') or [])} шагов")
        result = await _api("/graph", {
            "version_id": version_id,
            "graph": graph,
            "model": model,
            "note": note,
            "mode": mode,
            "node_id": payload.get("node_id") or "",
            "rows": 0,
        }, timeout=120)

        return {"ok": True, "version_id": version_id, "model": model,
                "nodes": len(graph.get("nodes") or []), "saved": result.get("status")}
    except Exception as e:  # noqa: BLE001
        await _refuse("synthesize", ids, str(e))
        raise


async def _source_text(ctx: HandlerContext, context: dict, ids: dict) -> str:
    text = _clean((context.get("version") or {}).get("source_text"))
    source = context.get("source") or {}

    if not isinstance(source, dict) or not source.get("content_b64"):
        return text

    kind = _clean(source.get("kind") or "text").lower()

    try:
        raw = base64.b64decode(str(source.get("content_b64")))
    except Exception as e:  # noqa: BLE001
        raise ValueError(f"источник повреждён: {e}") from e

    if kind == "pdf":
        extracted = _pdf_text(raw)

        if extracted:
            return (text + "\n\n" + extracted).strip()

        if ctx.llm is None:
            raise ValueError("PDF без текстового слоя: нужен vision-провайдер")

        return await _vision_text(ctx, raw, "image/png", "PDF-скан") or text

    if kind == "image":
        mime = _image_mime(raw)

        if ctx.llm is None:
            raise ValueError("для фото/скана нужен vision-провайдер (LLM)")

        extracted = await _vision_text(ctx, raw, mime, _clean(source.get("filename")))
        return (text + "\n\n" + extracted).strip()

    if kind == "audio":
        extracted = await _transcribe(raw, _clean(source.get("filename")))
        return (text + "\n\n" + extracted).strip()

    return text


def _pdf_text(raw: bytes) -> str:
    try:
        from pypdf import PdfReader  # type: ignore
    except Exception:  # noqa: BLE001
        try:
            from PyPDF2 import PdfReader  # type: ignore
        except Exception:  # noqa: BLE001
            return ""

    try:
        reader = PdfReader(io.BytesIO(raw))
        pages = []

        for page in reader.pages[:40]:
            pages.append(page.extract_text() or "")

        return "\n".join(pages).strip()
    except Exception:  # noqa: BLE001
        return ""


def _image_mime(raw: bytes) -> str:
    if raw[:8] == b"\x89PNG\r\n\x1a\n":
        return "image/png"
    if raw[:3] == b"\xff\xd8\xff":
        return "image/jpeg"
    if raw[:4] == b"RIFF" and raw[8:12] == b"WEBP":
        return "image/webp"
    return "image/jpeg"


async def _vision_text(ctx: HandlerContext, raw: bytes, mime: str, name: str) -> str:
    try:
        from .. import vlm
        b64, _meta = await vlm.prepare_b64(base64.b64encode(raw).decode("ascii"),
                                          max_w=vlm.photo_max_w("NJ670", 1400))
    except Exception:  # noqa: BLE001
        b64 = base64.b64encode(raw).decode("ascii")

    message = vlm.img_msg(
        b64,
        "Извлеки из изображения весь текст регламента/документа по бизнес-процессу. "
        "Верни только текст, без комментариев. Сохрани нумерацию шагов и подписи ролей.",
        mime)

    text, _usage = await _ask(ctx, [
        {"role": "system", "content": "Ты точно распознаёшь русский текст документов."},
        message,
    ], _model({}), max_tokens=4000, timeout=240)

    log.info("nj670: распознан источник %s, знаков: %d", name or "изображение", len(text))
    return text


async def _transcribe(raw: bytes, filename: str) -> str:
    url = (os.getenv("NJ670_ASR_URL") or "").strip().rstrip("/")
    key = (os.getenv("NJ670_ASR_KEY") or "").strip()
    model = (os.getenv("NJ670_ASR_MODEL") or "whisper-1").strip()

    if not url:
        raise ValueError(
            "аудио требует ASR: задайте NJ670_ASR_URL и NJ670_ASR_KEY "
            "(OpenAI-совместимый /audio/transcriptions)")

    headers = {"Authorization": f"Bearer {key}"} if key else {}

    try:
        async with httpx.AsyncClient(timeout=300) as client:
            response = await client.post(
                url,
                headers=headers,
                files={"file": (filename or "audio.mp3", raw, "application/octet-stream")},
                data={"model": model, "language": "ru"},
            )
            response.raise_for_status()
            data = response.json()
    except Exception as e:  # noqa: BLE001
        raise ValueError(f"ASR не принял аудио: {e}") from e

    return _clean(data.get("text") if isinstance(data, dict) else data, 30000)


async def _llm_graph(ctx: HandlerContext, context: dict, text: str, payload: dict) -> dict:
    dictionary = context.get("ontology") or {}
    limits = context.get("limits") or {}
    mode = _clean(context.get("mode") or "full")
    current = context.get("current_graph") or {}

    user = {
        "название": _clean((context.get("process") or {}).get("title")),
        "описание": _clean((context.get("process") or {}).get("description")),
        "режим": mode,
        "просьба_пользователя": _clean(context.get("instruction")),
        "выбранный_узел": _clean(context.get("node_id")),
        "справочник": dictionary,
        "максимум_шагов": _int(limits.get("max_nodes"), 80),
        "текст_регламента": text[: _int(limits.get("max_text"), 20000)],
    }

    if mode == "copilot":
        user["текущий_граф"] = current
        user["правило"] = ("Верни полный граф после правки. Сохрани id, x и y существующих "
                           "узлов; новый фрагмент вставь логично рядом с выбранным узлом.")
    else:
        user["правило"] = "Собери граф с начала до конца по тексту регламента."

    raw, usage = await _ask(ctx, [
        {"role": "system", "content": SYNTH_SYSTEM},
        {"role": "user", "content": json.dumps(user, ensure_ascii=False)},
    ], _model(payload, light=(mode == "copilot")), max_tokens=_int(limits.get("out_tokens"), 6000))

    data = _json_of(raw)
    data.setdefault("meta", {})
    if isinstance(data["meta"], dict):
        data["meta"].setdefault("title", user["название"])
        data["meta"]["source"] = "llm"
        if usage.get("_llm_model"):
            data["meta"]["note"] = _clean(
                f"Модель: {usage.get('_llm_model')}. " + str(data["meta"].get("note") or ""), 500)

    return data


def _clean_graph(raw: dict, context: dict) -> dict:
    graph = raw.get("graph") if isinstance(raw.get("graph"), dict) else raw
    nodes_in = graph.get("nodes") or []
    edges_in = graph.get("edges") or graph.get("transitions") or []
    lanes_in = graph.get("lanes") or []
    dictionary = context.get("ontology") or {}
    limits = context.get("limits") or {}
    max_nodes = _int(limits.get("max_nodes"), 80)
    max_edges = _int(limits.get("max_edges"), 160)

    roles = {str(r.get("code")) for r in (dictionary.get("role") or []) if r.get("code")}
    systems = {str(r.get("code")) for r in (dictionary.get("system") or []) if r.get("code")}
    artifacts = {str(r.get("code")) for r in (dictionary.get("artifact") or []) if r.get("code")}

    nodes, seen = [], set()

    for row in nodes_in[:max_nodes]:
        if not isinstance(row, dict):
            continue

        node_id = _clean(row.get("id") or row.get("step_id"))
        node_id = re.sub(r"[^a-zA-Z0-9_\-.]", "_", node_id).strip("_-.")

        if not node_id or node_id in seen:
            continue

        seen.add(node_id)
        node_type = _clean(row.get("type") or row.get("node_type") or "user_task").lower()
        node_type = {
            "task": "user_task", "user": "user_task", "service": "service_task",
            "manual": "manual_task", "gateway": "gateway_xor", "xor": "gateway_xor",
            "and": "gateway_and", "parallel": "gateway_and", "begin": "start",
        }.get(node_type, node_type)

        if node_type not in NODE_TYPES:
            node_type = "user_task"

        role = _clean(row.get("role_code") or row.get("role"))
        system = _clean(row.get("system_code") or row.get("system"))
        inp = _clean(row.get("input_code") or row.get("input_artifact") or row.get("input"))
        out = _clean(row.get("output_code") or row.get("output_artifact") or row.get("output"))

        # Код не из справочника не выбрасываем молча: сохраняем как свободную
        # подпись, чтобы холст показал, что имел в виду человек.
        props = dict(row.get("properties") or {}) if isinstance(row.get("properties"), dict) else {}

        if role and role not in roles:
            props.setdefault("role_label", role)
            role = ""
        if system and system not in systems:
            props.setdefault("system_label", system)
            system = ""
        if inp and inp not in artifacts:
            props.setdefault("input_label", inp)
            inp = ""
        if out and out not in artifacts:
            props.setdefault("output_label", out)
            out = ""

        node = {
            "id": node_id,
            "step_id": _clean(row.get("step_id") or node_id),
            "type": node_type,
            "title": _clean(row.get("title") or row.get("name") or node_type, 255),
            "lane": _clean(row.get("lane") or row.get("lane_id")),
            "role_code": role,
            "system_code": system,
            "input_code": inp,
            "output_code": out,
            "duration_minutes": max(0, min(100000, _int(row.get("duration_minutes")))),
            "wait_time_minutes": max(0, min(1000000, _int(row.get("wait_time_minutes")))),
            "properties": props,
        }

        for key in ("x", "y"):
            if row.get(key) is not None:
                node[key] = max(0, _int(row.get(key)))

        nodes.append(node)

    lanes = []

    for lane in lanes_in:
        if isinstance(lane, dict) and _clean(lane.get("id")):
            lanes.append({
                "id": _clean(lane.get("id")),
                "title": _clean(lane.get("title") or lane.get("role_code")),
                "role_code": _clean(lane.get("role_code")),
            })

    # Дорожки по ролям, если модель их не вернула.
    if not lanes:
        for role in sorted({n.get("role_code") for n in nodes if n.get("role_code")}):
            lanes.append({"id": "lane_" + role, "title": role, "role_code": role})

    lane_ids = {lane["id"] for lane in lanes}

    for node in nodes:
        if not node.get("lane") or node["lane"] not in lane_ids:
            node["lane"] = "lane_" + node["role_code"] if node.get("role_code") else (
                lanes[0]["id"] if lanes else "")

    by_id = {n["id"]: n for n in nodes}
    edges, keys = [], set()

    for row in edges_in[:max_edges]:
        if not isinstance(row, dict):
            continue

        src = re.sub(r"[^a-zA-Z0-9_\-.]", "_", _clean(row.get("from") or row.get("source")))
        dst = re.sub(r"[^a-zA-Z0-9_\-.]", "_", _clean(row.get("to") or row.get("target")))

        if not src or not dst or src == dst or src not in by_id or dst not in by_id:
            continue

        key = (src, dst)

        if key in keys:
            continue

        keys.add(key)
        edge_type = _clean(row.get("type") or row.get("transition_type") or "sequence").lower()

        if edge_type not in EDGE_TYPES:
            edge_type = "sequence"

        edges.append({
            "id": _clean(row.get("id") or f"e{len(edges)}"),
            "from": src,
            "to": dst,
            "type": edge_type,
            "condition": _clean(row.get("condition") or row.get("label"), 300),
            "weight": max(0.0, _float(row.get("weight"), 1.0)),
            "avg_minutes": max(0, _int(row.get("avg_minutes"))),
            "properties": dict(row.get("properties") or {}) if isinstance(row.get("properties"), dict) else {},
        })

    # Гарантируем вход/выход: схема без них не читается.
    incoming = {e["to"] for e in edges}
    outgoing = {e["from"] for e in edges}
    work = [n for n in nodes if n["type"] not in ("start", "end")]

    if not any(n["type"] == "start" for n in nodes):
        nodes.insert(0, {"id": "start", "step_id": "start", "type": "start",
                         "title": "Начало", "lane": "", "properties": {}})

    if not any(n["type"] == "end" for n in nodes):
        nodes.append({"id": "end", "step_id": "end", "type": "end",
                      "title": "Конец", "lane": "", "properties": {}})

    start_id = next(n["id"] for n in nodes if n["type"] == "start")
    end_id = next(n["id"] for n in nodes if n["type"] == "end")

    for node in work:
        if node["id"] not in incoming:
            edges.append({"from": start_id, "to": node["id"], "type": "sequence",
                          "condition": "", "weight": 1, "avg_minutes": 0, "properties": {}})
        if node["id"] not in outgoing and not any(e["from"] == node["id"] for e in edges):
            edges.append({"from": node["id"], "to": end_id, "type": "sequence",
                          "condition": "", "weight": 1, "avg_minutes": 0, "properties": {}})

    meta = dict(graph.get("meta") or {}) if isinstance(graph.get("meta"), dict) else {}
    mode = _clean(context.get("mode") or "full")

    return {
        "lanes": lanes,
        "nodes": nodes,
        "edges": edges,
        "meta": {
            "title": _clean(meta.get("title") or (context.get("process") or {}).get("title"), 255),
            "source": _clean(meta.get("source") or "llm", 60),
            "mode": "fact" if mode == "fact" else "plan",
            "note": _clean(meta.get("note"), 500),
            "elapsed_minutes": max(0, _int(meta.get("elapsed_minutes"))),
        },
    }


def _text_graph(text: str, context: dict) -> dict:
    """Правиловый разбор: строки регламента → шаги, роли и системы из словаря."""
    dictionary = context.get("ontology") or {}
    roles = [(str(r.get("code")), str(r.get("name") or "").lower())
             for r in (dictionary.get("role") or []) if r.get("code")]
    systems = [(str(r.get("code")), str(r.get("name") or "").lower())
               for r in (dictionary.get("system") or []) if r.get("code")]

    lines = []

    for raw in str(text or "").splitlines():
        line = re.sub(r"^[\s\-*•\d.)]+", "", raw).strip()

        if len(line) < 8:
            continue
        if re.match(r"(?i)^(утверждаю|согласовано|регламент|приложение|версия|дата|подпись)", line):
            continue

        lines.append(line[:180])

        if len(lines) >= 40:
            break

    if len(lines) < 2:
        lines = [s.strip() for s in re.split(r"(?<=[.!?])\s+", str(text or ""))
                 if len(s.strip()) >= 25][:30]

    if len(lines) < 2:
        raise ValueError("не удалось выделить шаги: опишите процесс списком")

    nodes = [{"id": "start", "step_id": "start", "type": "start",
              "title": "Начало процесса", "lane": "", "properties": {}}]
    edges = []
    prev = "start"

    for index, line in enumerate(lines, start=1):
        node_id = f"s{index}"
        lower = line.lower()
        role = next((code for code, name in roles if name and name in lower), "")
        system = next((code for code, name in systems if name and name in lower), "")
        node_type = "service_task" if re.search(r"автоматич|система |api|робот|скрипт", lower) else (
            "manual_task" if re.search(r"вручную|бумажн|мессенджер", lower) else "user_task")
        duration = _duration_of(line)
        wait = duration if re.search(r"ожидан|согласован", lower) else 0

        if wait:
            duration = int(duration * 0.15)

        conditional = bool(re.match(r"(?i)^(если|в случае|при условии|когда|иначе)", line))

        if conditional:
            gateway = f"{node_id}_xor"
            nodes.append({"id": gateway, "step_id": gateway, "type": "gateway_xor",
                          "title": "Условие", "lane": "", "properties": {}})
            edges.append({"from": prev, "to": gateway, "type": "sequence",
                          "condition": "", "weight": 1, "avg_minutes": 0, "properties": {}})
            prev = gateway

        nodes.append({
            "id": node_id,
            "step_id": node_id,
            "type": node_type,
            "title": line.rstrip(".;"),
            "lane": ("lane_" + role) if role else "",
            "role_code": role,
            "system_code": system,
            "duration_minutes": duration,
            "wait_time_minutes": wait,
            "properties": {"description": line},
        })

        edges.append({
            "from": prev, "to": node_id,
            "type": "conditional" if conditional else "sequence",
            "condition": line[:200] if conditional else "",
            "weight": 1, "avg_minutes": 0, "properties": {},
        })
        prev = node_id

    nodes.append({"id": "end", "step_id": "end", "type": "end",
                  "title": "Конец процесса", "lane": "", "properties": {}})
    edges.append({"from": prev, "to": "end", "type": "sequence",
                  "condition": "", "weight": 1, "avg_minutes": 0, "properties": {}})

    lanes = [{"id": "lane_" + code, "title": name or code, "role_code": code}
             for code, name in roles if any(n.get("role_code") == code for n in nodes)]

    return _clean_graph({
        "lanes": lanes,
        "nodes": nodes,
        "edges": edges,
        "meta": {"title": (context.get("process") or {}).get("title") or "Схема из текста",
                 "source": "rules", "note": "Черновик по правилам: проверьте роли и сроки."},
    }, context)


def _duration_of(line: str) -> int:
    match = re.search(r"(\d+(?:[.,]\d+)?)\s*(раб\.?\s*)?(мин|час|ч\.?|дн|день|дня|дней|недел)", line, re.I)

    if not match:
        return 0

    value = float(match.group(1).replace(",", "."))
    unit = match.group(3).lower()
    k = 60 if unit in ("час", "ч", "ч.") else (
        480 if unit in ("дн", "день", "дня", "дней") else (2400 if unit == "недел" else 1))

    return max(0, min(100000, int(round(value * k))))


# --------------------------------------------------------------------------
# Онтология из сырых данных
# --------------------------------------------------------------------------

# Что ищем без модели. Это не «магия», а страховка: если LLM недоступна,
# документ всё равно даёт черновик справочника, который человек проверит.
_ROLE_HINTS = [
    ("директор", "Директор"), ("руководитель", "Руководитель"),
    ("юрист", "Юрист"), ("бухгалтер", "Бухгалтер"), ("снабженец", "Снабженец"),
    ("кладовщик", "Кладовщик"), ("менеджер", "Менеджер"), ("секретарь", "Секретарь"),
    ("инженер", "Инженер"), ("технолог", "Технолог"), ("кассир", "Кассир"),
    ("логист", "Логист"), ("экономист", "Экономист"), ("аналитик", "Аналитик"),
    ("администратор", "Администратор"), ("начальник", "Начальник"),
    ("специалист", "Специалист"), ("оператор", "Оператор"), ("мастер", "Мастер"),
    ("служба безопасности", "Служба безопасности"),
    ("кадр", "Кадровая служба"),
]

_SYSTEM_HINTS = [
    ("1с", "1С:ERP", "1c"), ("1c", "1С:ERP", "1c"), ("sap", "SAP", "sap"),
    ("excel", "Excel", "excel"), ("outlook", "Outlook", "outlook"),
    ("telegram", "Telegram", "telegram"), ("bitrix", "Bitrix24", "bitrix"),
    ("диадок", "Диадок", "diadok"), ("сбис", "СБИС", "sbis"),
    ("jira", "Jira", "jira"), ("confluence", "Confluence", "confluence"),
    ("word", "Word", "word"), ("whatsapp", "WhatsApp", "whatsapp"),
    ("zoom", "Zoom", "zoom"),
]

_ARTIFACT_HINTS = [
    ("заявка", "Заявка"), ("договор", "Договор"), ("счёт", "Счёт"), ("счет", "Счёт"),
    ("акт", "Акт"), ("накладная", "Накладная"), ("спецификация", "Спецификация"),
    ("коммерческое предложение", "Коммерческое предложение"),
    ("служебная записка", "Служебная записка"), ("приказ", "Приказ"),
    ("отчёт", "Отчёт"), ("отчет", "Отчёт"), ("протокол", "Протокол"),
    ("инструкция", "Инструкция"),
]

_DEPARTMENT_RE = re.compile(
    r"\b((?:отдел|департамент|служба|управление|группа|сектор|направление)"
    r"\s+[а-яёa-z0-9][а-яёa-z0-9 \-]{2,40})",
    re.I,
)


def _doc_title(text: str, context: dict) -> str:
    job = context.get("job") or {}
    name = _clean(job.get("source_name"))

    if name:
        base = re.sub(r"\.[a-z0-9]{2,5}$", "", name, flags=re.I)
        return _clean(base, 120) or "Онтология организации"

    for line in text.splitlines():
        line = _clean(line)
        line = re.sub(r"^[\s\-\*\u2022\d.)]+", "", line)
        if len(line) >= 8 and not re.match(r"^(утверждаю|согласовано|регламент|приложение)", line, re.I):
            return line[:120]

    return "Онтология организации"


def _action_lines(text: str) -> list[str]:
    lines = []

    for raw in text.splitlines():
        line = _clean(raw)
        line = re.sub(r"^[\s\-\*\u2022\d.)]+", "", line)

        if len(line) < 12 or line.endswith(":"):
            continue

        if re.match(r"^(утверждаю|согласовано|регламент|приложение|версия|дата|подпись|стр\.)",
                    line, re.I):
            continue

        lines.append(line)

    if len(lines) < 3:
        lines = []

        for sentence in re.split(r"(?<=[.!?])\s+", text):
            sentence = _clean(sentence)

            if len(sentence) >= 25:
                lines.append(sentence)

    return lines[:30]


def _find_known(line_low: str, pairs: list[tuple[str, str]]) -> str:
    for code, name in pairs:
        if name and name.lower() in line_low:
            return code
    return ""


_DEPARTMENT_STOP = {
    "в", "на", "по", "и", "с", "со", "для", "от", "до", "за", "при", "под",
    "над", "без", "о", "об", "проверяет", "согласует", "создаёт", "создает",
    "оплачивает", "утверждает", "формирует", "готовит", "направляет",
    "подписывает", "контролирует", "осуществляет", "обеспечивает",
    "принимает", "рассматривает", "ведёт", "ведет", "заключает",
    "планирует", "отвечает", "проводит", "организует", "разрабатывает",
    "обрабатывает", "выполняет", "консультирует", "согласовывает",
    "исполняет", "курирует", "координирует", "анализирует", "прогнозирует",
    "отчитывается", "взаимодействует", "занимается", "управляет",
}

# Глаголы 3-го лица, которых нет в списке: «планирует», «отвечает».
_DEPARTMENT_VERB_RE = re.compile(r"(?:ает|яет|ует|ирует|ают|яют|уют)$", re.I)


def _department_name(raw: str, known_names: set[str]) -> str:
    """«Служба безопасности проверяет контрагента» → «Служба безопасности».

    Регэксп ловит начало фразы, но не знает грамматики: режем по служебным
    словам и глаголам, оставляя не больше трёх слов. Если фраза совпала с
    известной ролью, подразделение не создаём — это не отдел.
    """
    words = _clean(raw).strip(" .,;:-").split()
    out: list[str] = []

    for word in words[:4]:
        low = word.lower()

        if low in _DEPARTMENT_STOP or _DEPARTMENT_VERB_RE.search(low):
            break
        out.append(word)
        if len(out) >= 3:
            break

    name = " ".join(out).strip(" .,;:-")

    if not name or name.lower() in known_names:
        return ""

    return name


def _rules_ontology(text: str, context: dict) -> dict:
    """Детерминированный разбор: известные словари + частые должности/системы.

    Результат честно помечается model='rules-engine' на сайте: человек видит,
    что это черновик, а не ответ модели.
    """
    low = text.lower()
    dictionary = context.get("ontology") or {}
    known = {
        "role": [(str(i.get("code") or ""), _clean(i.get("name")))
                 for i in (dictionary.get("role") or [])],
        "system": [(str(i.get("code") or ""), _clean(i.get("name")))
                   for i in (dictionary.get("system") or [])],
        "artifact": [(str(i.get("code") or ""), _clean(i.get("name")))
                     for i in (dictionary.get("artifact") or [])],
    }

    entities: list[dict] = []
    seen: set[tuple[str, str]] = set()

    def prefer_known(kind: str, word: str) -> tuple[str, str] | None:
        """Подсказка «договор» должна находить «Проект договора» из словаря.

        Иначе в справочнике появляется второй код для той же сущности — и
        шаги ссылаются то на один, то на другой.
        """
        word = word.lower()

        for code, name in known[kind]:
            low_name = (name or "").lower()

            if low_name and (word == low_name or word in low_name or low_name in word):
                return code, name

        return None

    def add(kind: str, code: str, name: str, source: str = "") -> None:
        code = re.sub(r"[^a-z0-9_\-]+", "_", (code or "").lower()).strip("_")

        if not code or not name:
            return

        key = (kind, code)

        if key in seen:
            return

        seen.add(key)
        entities.append({
            "class_type": kind,
            "code": code[:100],
            "name": _clean(name, 255),
            "attributes": {},
            "source": _clean(source, 240),
        })

    for kind, hints in (("role", _ROLE_HINTS), ("system", _SYSTEM_HINTS),
                        ("artifact", _ARTIFACT_HINTS)):
        for hint in hints:
            word, name = hint[0], hint[1]
            pos = low.find(word)

            if pos < 0:
                continue

            fixed = hint[2] if len(hint) > 2 else None
            known_one = prefer_known(kind, word)

            if known_one is not None:
                add(kind, known_one[0], known_one[1],
                    text[max(0, pos - 40):pos + len(word) + 60])
                continue

            add(kind, fixed or _slug(name).replace("-", "_"), name,
                text[max(0, pos - 40):pos + len(word) + 60])

    # Уже известные сущности, встретившиеся в тексте: они не новые, но нужны
    # шагам, чтобы сослаться на правильный код.
    for kind in ("role", "system", "artifact"):
        for code, name in known[kind]:
            pos = low.find(name.lower()) if name else -1

            if pos >= 0:
                add(kind, code, name, text[max(0, pos - 40):pos + len(name) + 40])

    known_names = {
        (name or "").lower()
        for kind in ("role", "system", "artifact")
        for _, name in known[kind]
        if name
    }

    for match in _DEPARTMENT_RE.finditer(text):
        name = _department_name(match.group(1), known_names)

        if name:
            add("department", _slug(name).replace("-", "_"), name, match.group(0))

    title = _doc_title(text, context)
    steps = []
    action_lines = _action_lines(text)

    for line in action_lines:
        line_low = line.lower()
        condition = ""

        if re.match(r"^(если|в случае|при условии|когда|иначе)\b", line_low):
            condition = line[:200]

        steps.append({
            "title": _clean(line, 255),
            "type": "service_task" if re.search(r"автоматическ|система |api|робот|скрипт", line_low)
                    else ("manual_task" if re.search(r"вручную|бумажн|по телефону|в мессенджер", line_low)
                          else "user_task"),
            "role_code": _find_known(line_low, known["role"]),
            "system_code": _find_known(line_low, known["system"]),
            "input_code": "",
            "output_code": "",
            "duration_minutes": _duration_of(line),
            "wait_time_minutes": 0,
            "condition": condition,
        })

    processes = []

    if len(steps) >= 3:
        processes.append({
            "title": title,
            "description": "Черновик по правилам: шаги выделены из документа, "
                           "роли и системы сопоставлены со справочником.",
            "department": "",
            "owner_role_code": next((s["role_code"] for s in steps if s["role_code"]), ""),
            "steps": steps,
        })

    return {
        "entities": entities,
        "processes": processes,
        "note": "Разбор без модели: проверьте названия и добавьте то, что пропущено.",
    }


async def _llm_ontology(ctx: HandlerContext, context: dict, text: str, payload: dict) -> dict:
    limits = context.get("limits") or {}
    user = {
        "документ": text[: _int(limits.get("max_text"), 20000)],
        "справочник": context.get("ontology") or {},
        "уже_есть": context.get("existing") or {},
        "максимум_сущностей": _int(limits.get("max_entities"), 120),
        "максимум_процессов": _int(limits.get("max_processes"), 10),
        "правило": ("Верни только то, что подтверждается документом. "
                    "Существующие сущности используй по их коду."),
    }

    raw, usage = await _ask(ctx, [
        {"role": "system", "content": ONTOLOGY_SYSTEM},
        {"role": "user", "content": json.dumps(user, ensure_ascii=False)},
    ], _model(payload), max_tokens=_int(limits.get("out_tokens"), 8000))

    data = _json_of(raw)
    data.setdefault("entities", [])
    data.setdefault("processes", [])
    note = _clean(data.get("note"), 500)

    if usage.get("_llm_model"):
        data["note"] = _clean("Модель: " + str(usage.get("_llm_model")) + ". " + note, 500)

    return data


async def handle_ontology(ctx: HandlerContext, payload: dict) -> dict:
    job_id = _int(payload.get("ontology_job_id"))

    if not job_id:
        raise ValueError("nj670.ontology: в задаче нет ontology_job_id")

    try:
        await ctx.progress("Читаю документ", f"задание {job_id}")
        context = await _api("/context", {"ontology_job_id": job_id}, timeout=120)
        text = await _source_text(ctx, context, {"ontology_job_id": job_id})

        if len(text.strip()) < 40:
            raise ValueError("В документе слишком мало текста: нужен PDF с текстовым слоем, "
                             "txt/md или описание словами.")

        await ctx.progress("Выделяю сущности", "модель размечает роли и системы" if ctx.llm else "правила")

        model = "rules-engine"

        if ctx.llm is not None:
            try:
                result = await _llm_ontology(ctx, context, text, payload)
                model = _model(payload) or "llm"
            except Exception as e:  # noqa: BLE001 — без модели есть правила
                log.warning("nj670.ontology: модель не справилась (%s), разбираю правилами", e)
                result = _rules_ontology(text, context)
        else:
            result = _rules_ontology(text, context)

        await ctx.progress("Сохраняю предложение", f"сущностей: {len(result.get('entities') or [])}")
        await _api("/ontology", {
            "ontology_job_id": job_id,
            "result": result,
            "model": model,
        }, timeout=120)

        return {
            "ok": True,
            "ontology_job_id": job_id,
            "entities": len(result.get("entities") or []),
            "processes": len(result.get("processes") or []),
            "model": model,
        }
    except Exception as e:  # noqa: BLE001
        try:
            await _api_soft("/ontology_fail", {
                "ontology_job_id": job_id,
                "error": _clean(str(e), 500) or "разбор не удался",
            }, timeout=60)
        except Exception as fail_error:  # noqa: BLE001
            log.warning("nj670.ontology: /ontology_fail не принят: %s", fail_error)

        raise


# --------------------------------------------------------------------------
# Mining Lite
# --------------------------------------------------------------------------

async def handle_mine(ctx: HandlerContext, payload: dict) -> dict:
    version_id = _int(payload.get("version_id"))
    process_id = _int(payload.get("process_id"))
    ids = {"version_id": version_id, "process_id": process_id}

    if not version_id:
        raise ValueError("nj670.mine: в задаче нет version_id")

    try:
        await ctx.progress("Читаю журнал", f"версия {version_id}")
        context = await _api("/context", {
            "version_id": version_id,
            "process_id": process_id,
            "import_id": _int(payload.get("import_id")),
        }, timeout=120)
        source = context.get("import") or {}

        if not source.get("content_b64"):
            raise ValueError("сайт не передал журнал событий")

        raw = base64.b64decode(str(source["content_b64"]))
        rows = _table_rows(raw, _clean(source.get("kind") or "csv"))
        await ctx.progress("Считаю переходы", f"строк: {len(rows)}")

        graph, stats = _mine_graph(rows, context)
        await _api("/graph", {
            "version_id": version_id,
            "graph": graph,
            "model": "process-mining-python",
            "note": (f"Кейсов: {stats['cases']}, событий: {stats['events']}, "
                     f"активностей: {stats['activities']}."),
            "mode": "mine",
            "rows": stats["events"],
        }, timeout=120)

        return {"ok": True, "version_id": version_id, **stats}
    except Exception as e:  # noqa: BLE001
        await _refuse("mine", ids, str(e))
        raise


def _table_rows(raw: bytes, kind: str) -> list[list[str]]:
    if kind == "xlsx":
        try:
            from openpyxl import load_workbook
        except Exception as e:  # noqa: BLE001
            raise ValueError(f"openpyxl не установлен: {e}") from e

        workbook = load_workbook(io.BytesIO(raw), read_only=True, data_only=True)
        sheet = workbook[workbook.sheetnames[0]]
        rows = []

        for row in sheet.iter_rows(values_only=True):
            rows.append(["" if cell is None else str(cell).strip() for cell in row])

            if len(rows) > 80000:
                break

        workbook.close()
        return rows

    text = raw.decode("utf-8-sig", errors="replace")

    if "\ufffd" in text[:2000]:
        try:
            text = raw.decode("windows-1251")
        except UnicodeDecodeError:
            pass

    first = text.splitlines()[0] if text.splitlines() else ""
    delimiter = max([";", ",", "\t", "|"], key=first.count)
    return [row for row in csv.reader(io.StringIO(text), delimiter=delimiter)]


def _timestamp(value: Any) -> dt.datetime | None:
    if isinstance(value, dt.datetime):
        return value
    if isinstance(value, dt.date):
        return dt.datetime(value.year, value.month, value.day)

    text = _clean(value)

    if not text:
        return None

    if re.fullmatch(r"\d+(?:\.\d+)?", text) and float(text) > 25569:
        return dt.datetime(1899, 12, 30) + dt.timedelta(days=float(text))

    text = text.replace("T", " ").replace("Z", "")
    text = re.sub(r"\.\d+$", "", text)

    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%d %H:%M", "%Y-%m-%d",
                "%d.%m.%Y %H:%M:%S", "%d.%m.%Y %H:%M", "%d.%m.%Y",
                "%d/%m/%Y %H:%M", "%d/%m/%Y", "%m/%d/%Y %H:%M", "%m/%d/%Y"):
        try:
            return dt.datetime.strptime(text, fmt)
        except ValueError:
            continue

    return None


def _map_header(header: list[str]) -> dict[str, int]:
    aliases = {
        "case": ["case_id", "caseid", "case", "кейс", "id_кейса", "номер_заявки", "заявка"],
        "activity": ["activity", "action", "step", "event", "активность", "операция", "шаг", "действие"],
        "timestamp": ["timestamp", "time", "datetime", "date", "дата", "время", "дата_время", "start_time"],
        "resource": ["resource", "user", "role", "actor", "ресурс", "исполнитель", "роль", "сотрудник"],
        "duration": ["duration", "duration_minutes", "minutes", "длительность", "минуты"],
    }
    mapping: dict[str, int] = {}

    for index, name in enumerate(header):
        key = re.sub(r"[^a-zа-яё0-9]+", "_", _clean(name).lower()).strip("_")

        for field, words in aliases.items():
            if field in mapping:
                continue
            if any(word == key or word in key for word in words):
                mapping[field] = index
                break

    return mapping


def _mine_graph(rows: list[list[str]], context: dict) -> tuple[dict, dict]:
    if len(rows) < 2:
        raise ValueError("в таблице нет строк данных")

    mapping = _map_header(rows[0])

    if not all(k in mapping for k in ("case", "activity", "timestamp")):
        raise ValueError("не найдены колонки Case_ID / Activity / Timestamp")

    events, skipped = [], 0

    for row in rows[1:]:
        def cell(field):
            idx = mapping.get(field, -1)
            return _clean(row[idx]) if 0 <= idx < len(row) else ""

        case = cell("case")
        activity = cell("activity")
        stamp = _timestamp(cell("timestamp"))

        if not case or not activity or stamp is None:
            skipped += 1
            continue

        events.append({
            "case": case,
            "activity": activity[:160],
            "time": stamp,
            "resource": cell("resource")[:80],
            "minutes": max(0, _int(cell("duration"))),
        })

    if len(events) < 2:
        raise ValueError("после разбора осталось меньше двух событий; проверьте формат даты")

    cases: dict[str, list[dict]] = {}

    for event in events:
        cases.setdefault(event["case"], []).append(event)

    activities: dict[str, dict] = {}
    transitions: dict[tuple[str, str], dict] = {}
    gaps: dict[str, dict] = {}
    resources: dict[str, dict] = {}
    elapsed = []

    for events_case in cases.values():
        events_case.sort(key=lambda e: e["time"])
        elapsed.append(max(0, (events_case[-1]["time"] - events_case[0]["time"]).total_seconds() / 60))

        for i, event in enumerate(events_case):
            key = _slug(event["activity"])
            info = activities.setdefault(key, {"title": event["activity"], "count": 0, "minutes": 0})
            info["count"] += 1
            info["minutes"] += event["minutes"]

            if event["resource"]:
                res = resources.setdefault(key, {})
                res[event["resource"]] = res.get(event["resource"], 0) + 1

            if i + 1 < len(events_case):
                nxt = events_case[i + 1]
                gap = max(0, (nxt["time"] - event["time"]).total_seconds() / 60)
                pair = (key, _slug(nxt["activity"]))
                edge = transitions.setdefault(pair, {"count": 0, "minutes": 0.0})
                edge["count"] += 1
                edge["minutes"] += gap
                g = gaps.setdefault(key, {"count": 0, "minutes": 0.0})
                g["count"] += 1
                g["minutes"] += gap

    dictionary = context.get("ontology") or {}
    role_by_name = {str(r.get("name") or "").lower(): str(r.get("code") or "")
                    for r in (dictionary.get("role") or []) if r.get("code")}

    nodes = [{"id": "start", "step_id": "start", "type": "start", "title": "Начало", "lane": ""}]
    lanes: dict[str, dict] = {}
    id_by_key: dict[str, str] = {}

    for number, (key, info) in enumerate(activities.items(), start=1):
        best = max((resources.get(key) or {"": 0}).items(), key=lambda kv: kv[1])[0] if resources.get(key) else ""
        role_code = role_by_name.get(best.lower(), "")
        role_name = best or "Без роли"

        if not role_code:
            role_code = "res_" + (_slug(best) or "general")

        lane_id = "lane_" + role_code
        lanes.setdefault(lane_id, {"id": lane_id, "title": role_name, "role_code": role_code})

        avg_minutes = int(round(info["minutes"] / max(1, info["count"])))
        gap = gaps.get(key) or {"count": 0, "minutes": 0}
        avg_wait = int(round(gap["minutes"] / gap["count"])) if gap["count"] else 0

        node_id = f"n{number}"
        id_by_key[key] = node_id

        nodes.append({
            "id": node_id,
            "step_id": key,
            "type": "user_task",
            "title": info["title"],
            "lane": lane_id,
            "role_code": role_code,
            "duration_minutes": avg_minutes,
            "wait_time_minutes": avg_wait,
            "properties": {"frequency": info["count"], "resource": best},
        })

    edges = []

    for (src, dst), info in transitions.items():
        edges.append({
            "from": id_by_key[src],
            "to": id_by_key[dst],
            "type": "sequence",
            "condition": "",
            "weight": info["count"],
            "avg_minutes": int(round(info["minutes"] / max(1, info["count"]))),
            "properties": {"count": info["count"]},
        })

    nodes.append({"id": "end", "step_id": "end", "type": "end", "title": "Конец", "lane": ""})
    incoming = {e["to"] for e in edges}
    outgoing = {e["from"] for e in edges}

    for node in list(nodes):
        if node["id"] in ("start", "end"):
            continue
        if node["id"] not in incoming:
            edges.append({"from": "start", "to": node["id"], "type": "sequence",
                          "condition": "", "weight": 1, "avg_minutes": 0, "properties": {}})
        if node["id"] not in outgoing:
            edges.append({"from": node["id"], "to": "end", "type": "sequence",
                          "condition": "", "weight": 1, "avg_minutes": 0, "properties": {}})

    median = int(statistics.median(elapsed)) if elapsed else 0
    graph = {
        "lanes": list(lanes.values()),
        "nodes": nodes,
        "edges": edges,
        "meta": {
            "title": "Факт по журналу событий",
            "source": "process-mining",
            "mode": "fact",
            "elapsed_minutes": median,
            "note": f"Кейсов: {len(cases)}, событий: {len(events)}" + (
                f", пропущено строк: {skipped}" if skipped else ""),
        },
    }

    stats = {
        "cases": len(cases),
        "events": len(events),
        "activities": len(activities),
        "transitions": len(transitions),
        "skipped": skipped,
        "median_minutes": median,
    }

    return _clean_graph(graph, {**context, "mode": "fact"}), stats


_TRANSLIT = str.maketrans({
    "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e",
    "ж": "zh", "з": "z", "и": "i", "й": "y", "к": "k", "л": "l", "м": "m",
    "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t", "у": "u",
    "ф": "f", "х": "h", "ц": "c", "ч": "ch", "ш": "sh", "щ": "sch", "ъ": "",
    "ы": "y", "ь": "", "э": "e", "ю": "yu", "я": "ya",
})


def _slug(text: str) -> str:
    """Активность → код латиницей: «Проверка СБ» → proverka-sb.

    Кириллица в id недопустима (сайт вырезает её из id узла), а slug от
    названия нужен стабильный: иначе один и тот же шаг получал бы новый код
    и частотная матрица переходов рассыпалась бы.
    """
    source = _clean(text).lower()
    text = source.translate(_TRANSLIT)
    text = re.sub(r"[^a-z0-9]+", "-", text).strip("-")[:40]
    return text or "step-" + hashlib.md5(source.encode("utf-8")).hexdigest()[:6]


# --------------------------------------------------------------------------
# Diff
# --------------------------------------------------------------------------

async def handle_diff(ctx: HandlerContext, payload: dict) -> dict:
    audit_id = _int(payload.get("audit_id"))
    process_id = _int(payload.get("process_id"))
    ids = {"audit_id": audit_id, "process_id": process_id}

    if not audit_id:
        raise ValueError("nj670.diff: в задаче нет audit_id")

    try:
        await ctx.progress("Сопоставляю схемы", f"аудит {audit_id}")
        context = await _api("/context", {"audit_id": audit_id, "process_id": process_id}, timeout=120)
        plan = context.get("plan") or {}
        fact = context.get("fact") or {}

        if not plan or not fact:
            raise ValueError("сайт не отдал обе схемы")

        try:
            result = await _llm_diff(ctx, context, plan, fact)
            mode = "llm"
        except Exception as e:  # noqa: BLE001 — аудит обязан закрыться
            log.warning("nj670.diff: модель не справилась (%s), считаю правилами", e)
            result = _rules_diff(context, plan, fact)
            mode = "rules"

        await ctx.progress("Сохраняю находки", f"{len(result['findings'])} шт.")
        await _api("/audit", {
            "audit_id": audit_id,
            "summary": result["summary"][:6000],
            "findings": result["findings"][:40],
            "total_impact_rub": round(float(result["total_impact_rub"]), 2),
            "risk_level": result["risk_level"],
            "model": result.get("model") or ("llm" if mode == "llm" else "rules-engine"),
            "mode": mode,
        }, timeout=120)

        return {"ok": True, "audit_id": audit_id, "findings": len(result["findings"]),
                "impact": result["total_impact_rub"], "mode": mode}
    except Exception as e:  # noqa: BLE001
        await _refuse("diff", ids, str(e))
        raise


DIFF_SYSTEM = """Ты — аудитор бизнес-процессов. Сравни план (To-Be) и факт (As-Is).

Ищи:
* shadow_it — шаги/системы вне регламента, особенно мессенджеры и Excel;
* bypass — контрольная процедура плана не встречается в факте;
* missing_control — пропущен обязательный контроль;
* unauthorized_system — шаг выполняется не в той системе;
* extra_step — лишний шаг, которого нет в плане;
* loop — возвраты на доработку;
* bottleneck — ожидание, которое съедает срок.

Верни ТОЛЬКО JSON:
{
  "summary": "3–6 предложений: сроки, главные разрывы, цена",
  "risk_level": "critical|high|medium|low",
  "total_impact_rub": 18400,
  "findings": [
    {"id": "f1", "severity": "critical|high|medium|low",
     "category": "shadow_it|bypass|missing_control|unauthorized_system|extra_step|loop|bottleneck|sla",
     "title": "кратко", "description": "что не так",
     "evidence": "цифры и шаги из схем", "recommendation": "что сделать",
     "impact_rub": 7200, "node_id": "security", "metric": "54 % срока"}
  ]
}

Правила:
1. Не выдумывай шаги: node_id — только из факта или плана.
2. impact_rub — оценка потери на ОДНУ процедуру; если ставки нет, 0.
3. total_impact_rub = сумма impact_rub (не выдумывай больше).
4. Доказательство должно ссылаться на числа из графов (минуты, частота, доля).
5. Никакого markdown и текста вне JSON.
"""


async def _llm_diff(ctx: HandlerContext, context: dict, plan: dict, fact: dict) -> dict:
    metrics = _metrics(plan), _metrics(fact)
    user = {
        "процесс": context.get("process"),
        "экономика": context.get("economy"),
        "справочник": context.get("ontology"),
        "план": plan,
        "факт": fact,
        "расчёт": {
            "план": metrics[0],
            "факт": metrics[1],
            "разрыв_минут": max(0, metrics[1]["elapsed_minutes"] - metrics[0]["elapsed_minutes"]),
        },
    }

    raw, usage = await _ask(ctx, [
        {"role": "system", "content": DIFF_SYSTEM},
        {"role": "user", "content": json.dumps(user, ensure_ascii=False)},
    ], _model({}), max_tokens=_int((context.get("limits") or {}).get("out_tokens"), 6000))

    data = _json_of(raw)
    findings = _clean_findings(data.get("findings"))

    if not findings:
        raise ValueError("модель не вернула находок")

    impact = sum(_float(f.get("impact_rub")) for f in findings)
    risk = _clean(data.get("risk_level")).lower()

    if risk not in ("critical", "high", "medium", "low"):
        risk = _risk_of(findings)

    return {
        "summary": _clean(data.get("summary"), 6000) or "Аудит выполнен.",
        "findings": findings,
        "total_impact_rub": impact,
        "risk_level": risk,
        "model": _clean(usage.get("_llm_model") or _model({}) or "llm", 160),
    }


def _clean_findings(rows: Any) -> list[dict]:
    out = []
    severity = {"critical", "high", "medium", "low"}

    for index, row in enumerate(rows or []):
        if not isinstance(row, dict):
            continue

        sev = _clean(row.get("severity") or "medium").lower()
        out.append({
            "id": _clean(row.get("id") or f"f{index + 1}", 40),
            "severity": sev if sev in severity else "medium",
            "category": _clean(row.get("category") or "other", 40),
            "title": _clean(row.get("title"), 255) or "Находка аудита",
            "description": _clean(row.get("description"), 1500),
            "evidence": _clean(row.get("evidence"), 1000),
            "recommendation": _clean(row.get("recommendation"), 1500),
            "impact_rub": max(0.0, _float(row.get("impact_rub"))),
            "node_id": _clean(row.get("node_id"), 60),
            "metric": _clean(row.get("metric"), 200),
        })

    return out


def _risk_of(findings: list[dict]) -> str:
    order = {"low": 1, "medium": 2, "high": 3, "critical": 4}
    rank = max([order.get(f.get("severity"), 2) for f in findings] or [1])

    for name, value in order.items():
        if value == rank:
            return name

    return "low"


def _nodes_by_id(graph: dict) -> dict[str, dict]:
    return {str(n.get("id")): n for n in (graph.get("nodes") or []) if isinstance(n, dict)}


def _metrics(graph: dict) -> dict:
    nodes = [n for n in (graph.get("nodes") or []) if isinstance(n, dict)]
    work = sum(max(0, _int(n.get("duration_minutes"))) for n in nodes)
    wait = sum(max(0, _int(n.get("wait_time_minutes"))) for n in nodes)
    elapsed = _int((graph.get("meta") or {}).get("elapsed_minutes")) or (work + wait)
    bottleneck = max(nodes, key=lambda n: _int(n.get("wait_time_minutes")), default=None)
    loops = sum(1 for e in (graph.get("edges") or [])
                if isinstance(e, dict) and _clean(e.get("type")) == "loop")
    shadow = sum(1 for n in nodes if (n.get("properties") or {}).get("shadow"))

    return {
        "work_minutes": work,
        "wait_minutes": wait,
        "elapsed_minutes": max(1, elapsed),
        "loop_count": loops,
        "shadow_count": shadow,
        "bottleneck": {
            "step_id": _clean(bottleneck.get("step_id")),
            "title": _clean(bottleneck.get("title")),
            "wait": _int(bottleneck.get("wait_time_minutes")),
            "share": round(_int(bottleneck.get("wait_time_minutes")) / max(1, elapsed), 4),
        } if bottleneck else None,
    }


def _rules_diff(context: dict, plan: dict, fact: dict) -> dict:
    """Детерминированный аудит: те же категории, что у модели, но без неё."""
    pm, fm = _metrics(plan), _metrics(fact)
    pn, fn = _nodes_by_id(plan), _nodes_by_id(fact)
    psteps = {str(n.get("step_id")): n for n in pn.values()}
    fsteps = {str(n.get("step_id")): n for n in fn.values()}
    economy = context.get("economy") or {}
    default_rate = _float(economy.get("default_rate_per_hour"), 1500)
    wait_share = _float(economy.get("wait_cost_share"), 0.5)
    rates = _role_rates(context)
    findings = []

    for node in fn.values():
        step = str(node.get("step_id"))
        if step in psteps or node.get("type") in ("start", "end", "gateway_xor", "gateway_and"):
            continue

        shadow = bool((node.get("properties") or {}).get("shadow")) or _clean(node.get("system_code")) in ("telegram", "excel")
        rate = rates.get(_clean(node.get("role_code")), default_rate)
        impact = (_int(node.get("duration_minutes")) / 60 * rate
                  + _int(node.get("wait_time_minutes")) / 60 * rate * wait_share)

        findings.append({
            "id": "rules-shadow-" + (step or "step"),
            "severity": "critical" if shadow else "high",
            "category": "shadow_it" if shadow else "extra_step",
            "title": f"Шаг вне регламента: {_clean(node.get('title'))}",
            "description": "В факте есть шаг, которого нет в плане.",
            "evidence": f"{_int(node.get('duration_minutes')) + _int(node.get('wait_time_minutes'))} мин, система: {_clean(node.get('system_code')) or '—'}.",
            "recommendation": "Узаконить шаг или убрать его причину.",
            "impact_rub": round(impact, 2),
            "node_id": step,
            "metric": "нет в плане",
        })

    for node in pn.values():
        step = str(node.get("step_id"))
        if step in fsteps or node.get("type") in ("start", "end", "gateway_xor", "gateway_and"):
            continue

        control = bool((node.get("properties") or {}).get("control")) or _clean(node.get("role_code")) in (
            "lawyer", "security", "cfo", "accountant")

        findings.append({
            "id": "rules-missing-" + (step or "step"),
            "severity": "high" if control else "medium",
            "category": "missing_control" if control else "bypass",
            "title": f"В факте нет шага: {_clean(node.get('title'))}",
            "description": "Контрольная процедура плана не встречается в журнале.",
            "evidence": f"План: {_clean(node.get('title'))} ({_clean(node.get('role_code'))}).",
            "recommendation": "Вернуть шаг в маршрут и сделать обязательным в системе.",
            "impact_rub": 0,
            "node_id": step,
            "metric": "не найдено в факте",
        })

    if fm.get("bottleneck"):
        b = fm["bottleneck"]
        share = _float(b.get("share"))
        if share >= 0.10:
            findings.append({
                "id": "rules-bottleneck-" + _clean(b.get("step_id")),
                "severity": "critical" if share >= 0.4 else ("high" if share >= 0.2 else "medium"),
                "category": "bottleneck",
                "title": f"Узкое место: {_clean(b.get('title'))}",
                "description": f"Шаг держит {round(share * 100, 1)} % календарного срока.",
                "evidence": f"{_int(b.get('wait'))} мин ожидания из {fm['elapsed_minutes']} мин.",
                "recommendation": "SLA, эскалейт, параллельная ветка.",
                "impact_rub": 0,
                "node_id": _clean(b.get("step_id")),
                "metric": f"{round(share * 100, 1)} %",
            })

    if fm["elapsed_minutes"] > pm["elapsed_minutes"]:
        delta = fm["elapsed_minutes"] - pm["elapsed_minutes"]
        findings.append({
            "id": "rules-sla",
            "severity": "critical" if delta >= pm["elapsed_minutes"] else "high",
            "category": "sla",
            "title": f"Срок вырос в {round(fm['elapsed_minutes'] / max(1, pm['elapsed_minutes']), 1)} раза",
            "description": f"Факт дольше плана на {delta} мин.",
            "evidence": f"План: {pm['elapsed_minutes']} мин, факт: {fm['elapsed_minutes']} мин.",
            "recommendation": "Разобрать ожидания по дорожкам.",
            "impact_rub": 0,
            "node_id": "",
            "metric": f"{round(delta / max(1, pm['elapsed_minutes']) * 100)} %",
        })

    impact = sum(_float(f["impact_rub"]) for f in findings)
    summary = (f"План: {pm['elapsed_minutes']} мин, факт: {fm['elapsed_minutes']} мин. "
               f"Найдено разрывов: {len(findings)}. Оценка потерь: {round(impact)} ₽.")

    return {
        "summary": summary,
        "findings": findings[:40],
        "total_impact_rub": impact,
        "risk_level": _risk_of(findings),
        "model": "rules-engine",
    }


def _role_rates(context: dict) -> dict[str, float]:
    rates = {}

    for row in ((context.get("ontology") or {}).get("role") or []):
        if not isinstance(row, dict):
            continue
        rate = _float(row.get("rate_per_hour"))
        if rate > 0 and row.get("code"):
            rates[str(row["code"])] = rate

    return rates


# --------------------------------------------------------------------------
# Экспорт
# --------------------------------------------------------------------------

async def handle_export(ctx: HandlerContext, payload: dict) -> dict:
    report_id = _int(payload.get("report_id"))
    ids = {"report_id": report_id, "process_id": _int(payload.get("process_id"))}

    if not report_id:
        raise ValueError("nj670.export: в задаче нет report_id")

    try:
        await ctx.progress("Собираю документ", f"отчёт {report_id}")
        context = await _api("/context", {"report_id": report_id}, timeout=120)
        document = context.get("document") or {}

        if not document:
            raise ValueError("сайт не отдал структуру документа")

        doc_json = _document_json(document)
        png = _diagram_png(document.get("graph") or {}, document.get("title") or "")

        if png:
            doc_json["sections"].insert(1, {
                "heading": "2. Схема процесса",
                "blocks": [
                    {"type": "image", "b64": base64.b64encode(png).decode("ascii"),
                     "width_mm": 165, "caption": "Рисунок 1 — схема процесса (автоматическая раскладка)"},
                    {"type": "p", "text": "Схема построена по дорожкам ролей. Подробные параметры шагов — в операционной таблице."},
                ],
            })
            for i, sec in enumerate(doc_json["sections"], start=1):
                if i > 1:
                    heading = re.sub(r"^\d+\.\s*", "", str(sec.get("heading") or ""))
                    sec["heading"] = f"{i}. {heading}"

        await ctx.progress("Верстаю DOCX/PDF", "ГОСТ Р 7.0.97-2016")

        from ..docgen.build_docx import build_docx
        from ..docgen.build_pdf import build_pdf

        docx = build_docx(doc_json)

        try:
            pdf = build_pdf(doc_json)
        except Exception as e:  # noqa: BLE001 — без шрифтов PDF не собирается
            log.warning("nj670.export: PDF не собран (%s), отдаю только DOCX", e)
            pdf = b""

        await ctx.progress("Отдаю файлы", f"DOCX {len(docx) // 1024} КБ")
        await _api("/report", {
            "report_id": report_id,
            "docx_b64": base64.b64encode(docx).decode("ascii"),
            "pdf_b64": base64.b64encode(pdf).decode("ascii") if pdf else "",
            "model": "docgen-gost",
        }, timeout=240)

        return {"ok": True, "report_id": report_id, "docx": len(docx), "pdf": len(pdf)}
    except Exception as e:  # noqa: BLE001
        await _refuse("export", ids, str(e))
        raise


def _document_json(document: dict) -> dict:
    steps = document.get("steps") or []
    rows = [["№", "Роль", "Вход", "Действие", "Система", "Выход", "SLA"]]

    for step in steps:
        rows.append([
            _clean(step.get("n")), _clean(step.get("role")), _clean(step.get("input")),
            _clean(step.get("title")), _clean(step.get("system")),
            _clean(step.get("output")), _clean(step.get("sla")),
        ])

    sections = [
        {
            "heading": "1. Общие сведения",
            "blocks": [
                {"type": "p", "text": f"Организация: {_clean(document.get('org_name'))}"},
                {"type": "p", "text": f"Подразделение: {_clean(document.get('department')) or '—'}"},
                {"type": "p", "text": f"Владелец процесса: {_clean(document.get('owner')) or '—'}"},
                {"type": "p", "text": f"Версия: {_clean(document.get('version_tag'))} · {_clean(document.get('kind_label'))}"},
                {"type": "p", "text": f"Формат: {_clean(document.get('gost'))}"},
            ],
        },
        {
            "heading": "3. Операционная таблица шагов",
            "blocks": [{"type": "table", "columns": rows[0], "rows": rows[1:]}],
        },
    ]

    raci = document.get("raci") or {}
    columns = [str(c.get("name")) for c in (raci.get("columns") or []) if isinstance(c, dict)]
    raci_rows = []

    for row in (raci.get("rows") or []):
        if not isinstance(row, dict):
            continue
        cells = row.get("cells") or {}
        raci_rows.append([_clean(row.get("title"))] + [
            _clean(cells.get(str(c.get("code")))) for c in (raci.get("columns") or []) if isinstance(c, dict)
        ])

    if columns and raci_rows:
        sections.append({
            "heading": "4. Матрица RACI",
            "blocks": [
                {"type": "table", "columns": ["Шаг"] + columns, "rows": raci_rows},
                {"type": "note", "text": "R — исполнитель, A — ответственный, C — консультирует, I — информируется."},
            ],
        })

    rules = document.get("rules") or []

    if rules:
        sections.append({
            "heading": "5. Контрольные правила",
            "blocks": [{
                "type": "table",
                "columns": ["Код", "Правило", "Комментарий"],
                "rows": [[_clean(r.get("code")), _clean(r.get("name")), _clean(r.get("note"))] for r in rules],
            }],
        })

    audit = document.get("audit") or {}

    if audit:
        findings = audit.get("findings") or []
        sections.append({
            "heading": "6. Результаты аудита «План ↔ Факт»",
            "blocks": [
                {"type": "p", "text": _clean(audit.get("summary"))},
                {"type": "p", "text": f"Риск: {_clean(audit.get('risk_level'))}. Оценка потерь: {_clean(audit.get('impact_rub'))} ₽."},
                {
                    "type": "table",
                    "columns": ["Уровень", "Находка", "Доказательство", "Рекомендация"],
                    "rows": [[
                        _clean(f.get("severity")), _clean(f.get("title")),
                        _clean(f.get("evidence")), _clean(f.get("recommendation")),
                    ] for f in findings],
                },
            ],
        })

    return {
        "org_name": _clean(document.get("org_name")),
        "title": f"Регламент бизнес-процесса «{_clean(document.get('title'))}»",
        "subtitle": "Регламент бизнес-процесса",
        "city_year": f"{_clean(document.get('city'))}, {_clean(document.get('year'))}",
        "needs_title_page": True,
        "approve_line": (
            f"УТВЕРЖДАЮ\n{_clean(document.get('approve_role')) or 'Владелец процесса'}\n"
            f"____________ / {_clean(document.get('owner')) or '________________'}\n"
            f"«___» ____________ {_clean(document.get('year'))} г."
        ),
        "sections": sections,
    }


def _diagram_png(graph: dict, title: str) -> bytes:
    nodes = [n for n in (graph.get("nodes") or []) if isinstance(n, dict)]
    if not nodes:
        return b""

    try:
        from PIL import Image, ImageDraw, ImageFont
    except Exception:  # noqa: BLE001
        return b""

    regular, bold = _font_paths()
    max_x = max((_int(n.get("x")) + _int(n.get("w"), 196) for n in nodes), default=1200) + 90
    max_y = max((_int(n.get("y")) + _int(n.get("h"), 86) for n in nodes), default=700) + 100
    k = min(1.8, max(0.6, 1700.0 / max(800, max_x)))
    width, height = int(max_x * k), int(max_y * k)

    image = Image.new("RGB", (width, height), "#f8fafc")
    draw = ImageDraw.Draw(image)

    def font(size, strong=False):
        path = bold if strong else regular
        try:
            return ImageFont.truetype(path, size) if path else ImageFont.load_default()
        except Exception:  # noqa: BLE001
            return ImageFont.load_default()

    f_title = font(max(16, int(13 * k)), True)
    f_node = font(max(11, int(11 * k)), True)
    f_meta = font(max(9, int(9 * k)))
    f_badge = font(max(9, int(9 * k)), True)

    if title:
        draw.text((24, 12), title[:120], fill="#0f172a", font=f_title)

    # Дорожки
    lanes: dict[str, dict] = {}

    for node in nodes:
        lane = _clean(node.get("lane")) or "default"
        top = _int(node.get("y"))
        bottom = top + _int(node.get("h"), 86)
        lanes.setdefault(lane, {"min": top, "max": bottom})
        lanes[lane]["min"] = min(lanes[lane]["min"], top)
        lanes[lane]["max"] = max(lanes[lane]["max"], bottom)

    lane_titles = {str(l.get("id")): _clean(l.get("title")) for l in (graph.get("lanes") or []) if isinstance(l, dict)}

    for lane_id, bounds in sorted(lanes.items(), key=lambda kv: kv[1]["min"]):
        x0, y0 = int(18 * k), int((bounds["min"] - 34) * k)
        x1, y1 = int(max(760, max_x - 36) * k), int((bounds["max"] + 40) * k)
        draw.rounded_rectangle([x0, y0, x1, y1], radius=int(8 * k), fill="#ffffff", outline="#e2e8f0")
        draw.text((x0 + 12, y0 + 6), lane_titles.get(lane_id, lane_id)[:40], fill="#475569", font=f_meta)

    by_id = {str(n.get("id")): n for n in nodes}

    def center(node):
        return (
            int((_int(node.get("x")) + _int(node.get("w"), 196) / 2) * k),
            int((_int(node.get("y")) + _int(node.get("h"), 86) / 2) * k),
        )

    def anchor(node, right=True):
        x = _int(node.get("x")) + (_int(node.get("w"), 196) if right else 0)
        y = _int(node.get("y")) + _int(node.get("h"), 86) / 2
        return int(x * k), int(y * k)

    # Связи
    for edge in (graph.get("edges") or []):
        if not isinstance(edge, dict):
            continue
        a = by_id.get(str(edge.get("from")))
        b = by_id.get(str(edge.get("to")))
        if not a or not b:
            continue

        x1, y1 = anchor(a, True)
        x2, y2 = anchor(b, False)
        color = "#94a3b8"

        if _clean(edge.get("type")) == "conditional":
            color = "#d97706"
        elif _clean(edge.get("type")) == "loop":
            color = "#dc2626"
        elif _int(edge.get("avg_minutes")) >= 480:
            color = "#e11d48"

        if x2 < x1 + 20:
            bottom = max(_int(a.get("y")) + _int(a.get("h"), 86), _int(b.get("y")) + _int(b.get("h"), 86)) + 34
            points = [(x1, y1), (x1, int(bottom * k)), (x2, int(bottom * k)), (x2, y2)]
        else:
            mid = (x1 + x2) // 2
            points = [(x1, y1), (mid, y1), (mid, y2), (x2, y2)]

        draw.line(points, fill=color, width=max(1, int(1.4 * k)))
        draw.polygon([(x2, y2), (x2 - int(7 * k), y2 - int(4 * k)),
                      (x2 - int(7 * k), y2 + int(4 * k))], fill=color)

    # Узлы
    for node in nodes:
        x, y = int(_int(node.get("x")) * k), int(_int(node.get("y")) * k)
        w, h = int(_int(node.get("w"), 196) * k), int(_int(node.get("h"), 86) * k)
        node_type = _clean(node.get("type")) or "user_task"
        fill = MARKER_COLORS.get(node_type, "#ffffff")
        border = {
            "start": "#16a34a", "end": "#dc2626", "service_task": "#6366f1",
            "manual_task": "#ea580c", "gateway_xor": "#ca8a04",
            "gateway_and": "#ca8a04", "timer": "#0284c7",
        }.get(node_type, "#94a3b8")

        if node_type in ("start", "end"):
            r = int(min(w, h) / 2)
            cx, cy = x + w // 2, y + h // 2
            draw.ellipse([cx - r, cy - r, cx + r, cy + r], fill=fill, outline=border, width=2)
            continue

        if node_type in ("gateway_xor", "gateway_and"):
            draw.polygon([(x + w // 2, y), (x + w, y + h // 2),
                          (x + w // 2, y + h), (x, y + h // 2)], fill=fill, outline=border)
            label = "XOR" if node_type == "gateway_xor" else "AND"
            draw.text((x + w // 2, y + h // 2), label, fill="#713f12", font=f_node, anchor="mm")
            continue

        draw.rounded_rectangle([x, y, x + w, y + h], radius=int(9 * k), fill=fill, outline=border, width=2)

        if (node.get("properties") or {}).get("shadow"):
            draw.rounded_rectangle([x + 3, y + 3, x + w - 3, y + h - 3], radius=int(7 * k),
                                   outline="#f59e0b", width=2)

        text = _clean(node.get("title"), 120)
        lines = _wrap_px(draw, text, f_node, max(40, w - int(18 * k)), 3)
        line_h = int(15 * k)

        for i, line in enumerate(lines):
            draw.text((x + 9, y + int(8 * k) + i * line_h), line, fill="#0f172a", font=f_node)

        meta = " · ".join(x for x in (_clean(node.get("role_code")),
                                     _clean(node.get("system_code"))) if x)
        if meta:
            draw.text((x + 9, y + h - int(16 * k)), meta[:46], fill="#64748b", font=f_meta)

        wait = _int(node.get("wait_time_minutes"))
        if wait > 0:
            label = _minutes(wait)
            bw = max(48, int((10 + len(label) * 6) * k))
            draw.rounded_rectangle([x + w - bw, y - int(9 * k), x + w + int(8 * k), y + int(9 * k)],
                                   radius=int(9 * k), fill="#fee2e2", outline="#fca5a5")
            draw.text((x + w - bw // 2, y), "⏳ " + label, fill="#b91c1c", font=f_badge, anchor="mm")

    buffer = io.BytesIO()
    image.save(buffer, "PNG", optimize=True)
    return buffer.getvalue()


def _wrap_px(draw, text: str, font, max_width: int, max_lines: int) -> list[str]:
    words = text.split()
    lines, current = [], ""

    for word in words:
        test = (current + " " + word).strip()
        try:
            width = draw.textlength(test, font=font)
        except Exception:  # noqa: BLE001
            width = len(test) * 7
        if width > max_width and current:
            lines.append(current)
            current = word
            if len(lines) >= max_lines:
                break
        else:
            current = test

    if current and len(lines) < max_lines:
        lines.append(current)

    if len(lines) == max_lines and words and len(" ".join(lines)) < len(text) - 3:
        lines[-1] = lines[-1][:-1] + "…"

    return lines or [text[:30]]


def _font_paths() -> tuple[str, str]:
    dirs = [
        "/usr/share/fonts/truetype/dejavu", "/usr/share/fonts/dejavu",
        "/usr/share/fonts/truetype/liberation", "/usr/share/fonts/truetype/liberation2",
        "/usr/share/fonts/TTF", "/usr/local/share/fonts",
    ]
    regular = bold = ""

    for directory in dirs:
        for name in ("DejaVuSans.ttf", "LiberationSans-Regular.ttf", "Arial.ttf"):
            path = os.path.join(directory, name)
            if os.path.isfile(path):
                regular = regular or path

        for name in ("DejaVuSans-Bold.ttf", "LiberationSans-Bold.ttf", "Arial Bold.ttf"):
            path = os.path.join(directory, name)
            if os.path.isfile(path):
                bold = bold or path

    return regular, bold or regular


def _minutes(minutes: int) -> str:
    minutes = max(0, _int(minutes))

    if minutes < 60:
        return f"{minutes} мин"
    if minutes < 480:
        return f"{minutes // 60} ч" + (f" {minutes % 60} мин" if minutes % 60 else "")

    days, rest = divmod(minutes, 480)
    hours, mins = divmod(rest, 60)
    return f"{days} дн." + (f" {hours} ч" if hours else "") + (f" {mins} мин" if mins else "")


# --------------------------------------------------------------------------
# Регистрация
# --------------------------------------------------------------------------

handle = handle_synthesize
