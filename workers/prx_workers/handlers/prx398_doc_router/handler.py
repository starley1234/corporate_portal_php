"""Маршрутизация PDF-документа (prx398).

PHP (job+file) → worker: LangGraph(ocr->classify->extract->validate->route/retry/manual)
→ POST /result. Checkpoint по thread_id=job_id.
"""
from __future__ import annotations

import logging
import tempfile
from pathlib import Path

import httpx

from ... import site
from ...base import HandlerContext, PermanentJobError
from ...ocr import extract_pdf
from .graph import build_graph, close_checkpointer, make_checkpointer
from .limits import limits

CHANNEL = "doc.route"
log = logging.getLogger("prx.doc_router")

PERMANENT_CODES = {"invalid_pdf", "encrypted_pdf", "page_limit", "ocr_unavailable", "graph_unavailable"}


class DocumentJobError(RuntimeError):
    pass


def _check(response: httpx.Response) -> dict | None:
    code = response.status_code
    if code in (401, 403):
        raise PermanentJobError("worker_auth")
    if code == 409:
        raise PermanentJobError("lease_lost")
    if code in (410, 404):
        raise PermanentJobError("file_unavailable")
    if code == 422:
        detail = _error_code(response)
        raise PermanentJobError(detail or "job_failed")
    if code in (408, 429) or code >= 500:
        raise DocumentJobError("worker_endpoint_unavailable")
    if not response.is_success:
        raise PermanentJobError("worker_endpoint_unavailable")
    if response.content:
        try:
            return response.json()
        except ValueError:
            return None
    return None


def _error_code(response: httpx.Response) -> str:
    try:
        data = response.json()
        code = data.get("error", {}).get("code")
        return code if isinstance(code, str) else ""
    except Exception:  # noqa: BLE001
        return ""


async def _download(client: httpx.AsyncClient, url: str, path: Path, max_pdf_bytes: int) -> None:
    async with client.stream("GET", url) as response:
        _check(response)
        ctype = response.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        if ctype != "application/pdf":
            raise PermanentJobError("file_unavailable")
        size = 0
        with path.open("xb") as pdf:
            async for block in response.aiter_bytes(chunk_size=65536):
                size += len(block)
                if size > max_pdf_bytes:
                    raise PermanentJobError("file_unavailable")
                pdf.write(block)
        if not size:
            raise PermanentJobError("file_unavailable")


async def _post_result(client: httpx.AsyncClient, url: str, body: dict) -> dict:
    response = await client.post(url, json=body)
    data = _check(response)
    return data if isinstance(data, dict) else {}


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

    base, source = site.module_api_config("PRX398_DOC_ROUTER", "/prx398/worker")
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
            "max_text_bytes": 5 * 1024 * 1024,
        }, progress=lambda stage, detail="": ctx.progress(stage, detail))
        text = ocr.get("text", "")
        return {"text": text}

    saver = None
    try:
        try:
            saver = await make_checkpointer(getattr(ctx.config, "pg_dsn", None), lim["checkpoint"])
        except RuntimeError as e:
            if str(e) == "graph_unavailable":
                # report to PHP
                async with httpx.AsyncClient(headers=headers, timeout=30, follow_redirects=False) as client:
                    await _post_result(client, url + "/result", {"error_code": "graph_unavailable", "status": "failed"})
            raise PermanentJobError("graph_unavailable")

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
                    await _download(client, url + "/file", path, lim["max_pdf_bytes"])
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
            status = "done" if final.get("route") else "review"
            # if route set (non-empty) => done, if manual/empty route => review
            if not final.get("route"):
                status = "review"
            else:
                status = "done"
            await ctx.progress("store")
            await _post_result(client, url + "/result", {
                "status": status,
                "doc_type": final.get("doc_type"),
                "route": final.get("route") or "",
                "confidence": final.get("confidence"),
                "attempts": int(final.get("attempts") or 0),
                "state": state_out,
            })
            return {"status": status, "job_id": job_id, "doc_type": final.get("doc_type"), "route": final.get("route")}
    except PermanentJobError as e:
        code = str(e)
        if code in PERMANENT_CODES or code == "graph_unavailable":
            try:
                async with httpx.AsyncClient(headers=headers, timeout=30, follow_redirects=False) as client:
                    await _post_result(client, url + "/result", {"error_code": code, "status": "failed"})
            except Exception:  # noqa: BLE001
                pass
        raise
    finally:
        if saver is not None:
            await close_checkpointer(saver)
