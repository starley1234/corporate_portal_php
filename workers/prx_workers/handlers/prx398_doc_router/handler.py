"""Маршрутизация PDF-документа (prx398).

PHP (job+file) → worker: LangGraph(ocr->classify->extract->validate->route/retry/manual)
→ POST /result. Checkpoint по thread_id=job_id. HTTP-протокол — общий `job_api` (Д6).
"""
from __future__ import annotations

import logging
import tempfile
from pathlib import Path

import httpx

from ... import job_api, site
from ...base import HandlerContext, PermanentJobError
from ...ocr import extract_pdf
from .graph import build_graph, close_checkpointer, forget_thread, make_checkpointer
from .limits import limits

CHANNEL = "doc.route"
log = logging.getLogger("prx.doc_router")

PERMANENT_CODES = {
    "invalid_pdf", "encrypted_pdf", "page_limit", "result_too_large", "no_text",
    "ocr_config", "ocr_unavailable", "ocr_auth", "ocr_rejected", "ocr_invalid_response",
    "ocr_incomplete", "ocr_page_failed", "file_unavailable", "worker_auth", "lease_lost",
    "invalid_result", "invalid_attempt", "method_not_allowed", "graph_unavailable",
}


def _public_state(state: dict) -> dict:
    # Do not leak full text into DB payload; keep routing-relevant fields.
    return {
        "doc_type": state.get("doc_type"),
        "confidence": state.get("confidence"),
        "attempts": state.get("attempts", 0),
        "reasons": state.get("reasons", []),
        "route": state.get("route"),
        "fields": state.get("fields", {}),
    }


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    job = ctx._current_job or {}
    job_id, attempt = int(job.get("id") or 0), int(job.get("attempts") or 0)
    if not job_id or not attempt:
        raise PermanentJobError("worker_config")

    base, source = site.module_api_config("PRX398_DOC_ROUTER", "/prx398_doc_router/worker")
    if not site.valid_http_base(base) or not ctx.config.api_token:
        log.error("doc.route job_id=%s php=%s (%s): проверьте адрес и WORKER_API_TOKEN",
                  job_id, site.safe_origin(base), source)
        raise PermanentJobError("worker_config")

    headers = {"X-Worker-Token": ctx.config.api_token, "X-PRX-Worker-Attempt": str(attempt),
               "Accept-Encoding": "identity"}
    lim = limits()
    url = f"{base}/{job_id}"

    # OCR node closure
    async def ocr_node(state: dict) -> dict:
        file_path = state.get("file_path")
        if not file_path:
            # resume case: text should already be in checkpoint; nothing to do
            if state.get("text"):
                return {}
            raise PermanentJobError("file_unavailable")
        await ctx.progress("ocr")
        ocr = await extract_pdf(Path(file_path), limits={
            "max_pdf_bytes": lim["max_pdf_bytes"],
            "max_pages": lim["max_pages"],
            "max_text_bytes": lim["max_text_bytes"],
            "pdf_memory_mb": lim["pdf_memory_mb"],
        }, progress=lambda stage, detail="": ctx.progress(stage, detail))
        text = ocr.get("text", "")
        return {"text": text}

    saver = None
    terminal = False
    try:
        # DSN чекпойнта: явный PRX398_CHECKPOINT_DSN, иначе режим pg даёт
        # ctx.config.pg_dsn. В режиме http (воркер без прямого доступа к БД)
        # без своего DSN чекпойнт недоступен — это честный graph_unavailable,
        # а не тихий откат на memory.
        dsn = lim.get("checkpoint_dsn") or getattr(ctx.config, "pg_dsn", None)
        try:
            saver = await make_checkpointer(dsn, lim["checkpoint"])
        except RuntimeError as e:
            if str(e) == "graph_unavailable":
                await job_api.report_failure(headers, url, "graph_unavailable")
            raise PermanentJobError("graph_unavailable") from None

        graph = build_graph(ocr_node=ocr_node).compile(checkpointer=saver)
        config = {"configurable": {"thread_id": str(job_id)}}

        existing = await graph.aget_state(config)
        has_checkpoint = bool(existing and existing.values)

        async with httpx.AsyncClient(headers=headers, timeout=60, follow_redirects=False) as client:
            if has_checkpoint and existing.values.get("text"):
                await ctx.progress("resume")
                final = await graph.ainvoke(None, config)
            else:
                with tempfile.TemporaryDirectory(prefix="prx398_") as tmp:
                    path = Path(tmp) / "document.pdf"
                    await ctx.progress("download_pdf")
                    await job_api.download_pdf(client, url + "/file", path, lim["max_pdf_bytes"])
                    initial = {
                        "file_path": str(path),
                        "ocr_limits": {"max_pages": lim["max_pages"]},
                        "_confidence_min": lim["confidence_min"],
                        "_max_retries": lim["max_retries"],
                        "attempts": 0,
                        "reasons": [],
                    }
                    await ctx.progress("graph")
                    final = await graph.ainvoke(initial, config)

            state_out = _public_state(final)
            # Маршрут выставлен => done; пустой (manual) => ручная проверка.
            status = "done" if final.get("route") else "review"
            await ctx.progress("store")
            await job_api.post_result(client, url + "/result", {
                "status": status,
                "doc_type": final.get("doc_type"),
                "route": final.get("route") or "",
                "confidence": final.get("confidence"),
                "attempts": int(final.get("attempts") or 0),
                "state": state_out,
            })
            terminal = True
            return {"status": status, "job_id": job_id, "doc_type": final.get("doc_type"), "route": final.get("route")}
    except PermanentJobError as e:
        code = str(e)
        if code in PERMANENT_CODES:
            await job_api.report_failure(headers, url, code)
        # Повтор такого задания не нужен: чекпойнт с текстом можно убрать.
        terminal = True
        raise
    finally:
        if saver is not None:
            if terminal:
                await forget_thread(saver, str(job_id))
            await close_checkpointer(saver)
