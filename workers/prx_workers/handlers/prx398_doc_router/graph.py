"""LangGraph маршрутизации документа (prx398).

Граф: ocr -> classify -> extract -> validate -> route|retry|manual.
retry ведёт обратно в classify (attempts < max_retries), иначе manual:
уверенность даёт classify, поэтому повтор только extract её не меняет.
Чекпойнт: postgres (AsyncPostgresSaver) или memory (только тесты).
"""
from __future__ import annotations

import os
from typing import Any, TypedDict

from langgraph.graph import END, START, StateGraph
from pydantic import BaseModel, Field

from ...langchain_llm import arun_structured


ROUTES = {"invoice": "erp", "contract": "legal", "letter": "inbox", "other": "inbox"}


class DocState(TypedDict, total=False):
    text: str
    doc_type: str
    fields: dict
    confidence: float
    attempts: int
    reasons: list[str]
    route: str
    file_path: str
    ocr_limits: dict
    _confidence_min: float
    _max_retries: int


class ClassifyOut(BaseModel):
    doc_type: str = Field(description="invoice|contract|letter|other")
    confidence: float = Field(ge=0.0, le=1.0)


class ExtractOut(BaseModel):
    title: str = ""
    counterparty: str = ""
    date: str = ""
    amount: float | None = None
    summary: str = ""


CLASSIFY_SYSTEM = """Классифицируй документ. doc_type строго один из: invoice, contract, letter, other.
confidence от 0 до 1. Не выдумывай тип при низкой уверенности - ставь other и низкую confidence."""

EXTRACT_SYSTEM = """Извлеки конверт документа. Верни только поля схемы.
Не выдумывай значения: неизвестное -> пустая строка/null.
title - название/тема документа, counterparty - контрагент/отправитель/сторона, date - дата как в документе,
amount - сумма если есть, иначе null, summary - краткое содержание (1-2 предложения)."""


async def classify_node(state: DocState) -> dict:
    text = state.get("text", "")
    parsed, _ = await arun_structured(ClassifyOut, CLASSIFY_SYSTEM, f"Текст документа:\n\n{text[:60000]}")
    doc_type = (parsed.doc_type or "other").strip().lower()
    if doc_type not in ROUTES:
        doc_type = "other"
    conf = float(parsed.confidence)
    conf = 0.0 if conf < 0 else 1.0 if conf > 1 else conf
    return {"doc_type": doc_type, "confidence": conf}


async def extract_node(state: DocState) -> dict:
    text = state.get("text", "")
    attempts = int(state.get("attempts") or 0) + 1
    parsed, _ = await arun_structured(ExtractOut, EXTRACT_SYSTEM, f"Тип: {state.get('doc_type','other')}\n\nТекст документа:\n\n{text[:60000]}")
    fields = parsed.model_dump() if hasattr(parsed, "model_dump") else dict(parsed)
    # normalize amount
    amt = fields.get("amount")
    if amt is not None:
        try:
            fields["amount"] = float(amt)
        except Exception:  # noqa: BLE001
            fields["amount"] = None
    for k in ("title", "counterparty", "date", "summary"):
        v = fields.get(k)
        fields[k] = v.strip() if isinstance(v, str) else ""
    return {"fields": fields, "attempts": attempts}


def validate_node(state: DocState) -> dict:
    reasons: list[str] = []
    fields = state.get("fields") or {}
    if not isinstance(fields, dict):
        fields = {}
        reasons.append("invalid_fields")
    if not str(fields.get("title", "")).strip():
        reasons.append("missing_title")
    if not str(fields.get("date", "")).strip():
        reasons.append("missing_date")
    # amount should be non-negative if present
    amt = fields.get("amount")
    if amt is not None:
        try:
            if float(amt) < 0:
                reasons.append("negative_amount")
        except Exception:  # noqa: BLE001
            reasons.append("invalid_amount")
    return {"reasons": reasons}


def route_node(state: DocState) -> dict:
    doc_type = state.get("doc_type", "other")
    return {"route": ROUTES.get(doc_type, "inbox")}


def manual_node(state: DocState) -> dict:
    reasons = list(state.get("reasons") or [])
    if not reasons:
        reasons.append("manual_review")
    # keep route unset/empty for manual
    return {"route": "", "reasons": reasons}


def decide_after_validate(state: DocState) -> str:
    reasons = state.get("reasons") or []
    confidence = float(state.get("confidence") or 0)
    attempts = int(state.get("attempts") or 0)
    confidence_min = float(state.get("_confidence_min", 0.8))
    max_retries = int(state.get("_max_retries", 2))
    checks_ok = len(reasons) == 0
    if confidence >= confidence_min and checks_ok:
        return "route"
    if attempts < max_retries:
        return "retry"
    return "manual"


def build_graph(*, ocr_node):
    g = StateGraph(DocState)
    g.add_node("ocr", ocr_node)
    g.add_node("classify", classify_node)
    g.add_node("extract", extract_node)
    g.add_node("validate", validate_node)
    g.add_node("route", route_node)
    g.add_node("manual", manual_node)
    g.add_edge(START, "ocr")
    g.add_edge("ocr", "classify")
    g.add_edge("classify", "extract")
    g.add_edge("extract", "validate")
    g.add_conditional_edges("validate", decide_after_validate, {"route": "route", "retry": "classify", "manual": "manual"})
    g.add_edge("route", END)
    g.add_edge("manual", END)
    return g


async def make_checkpointer(pg_dsn: str | None, mode: str):
    mode = (mode or "postgres").strip().lower()
    if mode == "memory":
        from langgraph.checkpoint.memory import MemorySaver
        return MemorySaver()
    if mode != "postgres":
        raise RuntimeError("graph_unavailable")
    if not pg_dsn:
        raise RuntimeError("graph_unavailable")
    try:
        from langgraph.checkpoint.postgres.aio import AsyncPostgresSaver
        # AsyncPostgresSaver.from_conn_string returns async context manager in recent versions;
        # support both patterns.
        cm = AsyncPostgresSaver.from_conn_string(pg_dsn)
        if hasattr(cm, "__aenter__"):
            saver = await cm.__aenter__()
            # attach cm for cleanup
            saver._prx_cm = cm  # type: ignore[attr-defined]
            await saver.setup()
            return saver
        # older API: direct instance
        saver = cm
        if hasattr(saver, "setup"):
            await saver.setup()
        return saver
    except Exception as e:  # noqa: BLE001
        raise RuntimeError("graph_unavailable") from e


async def close_checkpointer(saver):
    cm = getattr(saver, "_prx_cm", None)
    if cm is not None:
        try:
            await cm.__aexit__(None, None, None)
            return
        except Exception:  # noqa: BLE001
            pass
    close = getattr(saver, "close", None)
    if callable(close):
        try:
            r = close()
            if hasattr(r, "__await__"):
                await r
        except Exception:  # noqa: BLE001
            pass


async def forget_thread(saver, thread_id: str) -> None:
    """Удалить чекпойнт потока: он хранит полный OCR-текст.

    Вызывается после терминального результата (done/review/failed) — повтор
    такого задания уже не нужен. Ошибку глушим: уборка не должна ронять уже
    сохранённый результат; остаток по TTL добирает purge() на стороне PHP.
    """
    deleter = getattr(saver, "adelete_thread", None)
    if not callable(deleter):
        return
    try:
        await deleter(str(thread_id))
    except Exception:  # noqa: BLE001
        pass
