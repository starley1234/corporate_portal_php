"""nj645.review — «Лаборатория LLM»: прогон промпта на реальной модели.

Зачем модулю воркер: демонстрация «промпт решает» требует настоящей LLM.
Сайт (`Service_Nj645_Store::run_create`) кладёт задачу с парой
«задача + инструкция», воркер отвечает и возвращает текст обратно.

Ввод (payload):
    {"run_id": 12, "mode": "answer", "task": "…",
     "system": "…", "prompt": "…", "temperature": 0.2}

Пайплайн:
  1. собираем сообщения: инструкция (prompt + необязательный system) —
     системным сообщением, задача — пользовательским;
  2. один вызов LLM через ctx.llm (общие провайдеры workers/n2j_workers/llm.py,
     модель — NJ645_LAB_MODEL, спека «провайдер::модель»);
  3. результат → POST /llmlab/api/worker_result (X-Worker-Token, form-данные),
     прогресс → ctx.progress(); если сайт не принял — в job.result, чтобы
     сайт поднял ответ через reconcile.

Env: NJ645_LAB_API_BASE (напр. https://n2j.ru/llmlab/api),
NJ645_LAB_SITE_TOKEN (или общий WORKER_API_TOKEN / WORKER_SITE_TOKEN),
NJ645_LAB_MODEL (спека «провайдер::модель»), NJ645_LAB_MAX_TOKENS (по умолч. 1200).
"""

from __future__ import annotations

import logging
import os
import time

from .. import site

log = logging.getLogger("n2j.nj645")

CHANNEL = "nj645.review"


def _model() -> str:
    return os.getenv("NJ645_LAB_MODEL", "").strip()


def _max_tokens() -> int:
    try:
        return max(120, min(4000, int(os.getenv("NJ645_LAB_MAX_TOKENS", "1200"))))
    except ValueError:
        return 1200


async def _api_result(ctx, run_id: int, data: dict) -> bool:
    """Отдать результат на сайт. True — принят."""
    ok, reason, _j = await site.post_json(
        "NJ645_LAB", "/worker_result",
        {"run_id": str(run_id), **{k: str(v) for k, v in data.items() if v is not None}},
        timeout=45, retries=2,
    )

    if not ok:
        log.warning("nj645: результат прогона %s не принят сайтом: %s", run_id, reason)

    return ok


async def handle(ctx, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)

    if not run_id:
        raise ValueError("nj645: payload должен содержать run_id")

    task = str(payload.get("task") or "").strip()

    if not task:
        raise ValueError("nj645: пустая задача")

    instruction = str(payload.get("prompt") or "").strip()
    system = str(payload.get("system") or "").strip()

    if not instruction and not system:
        raise ValueError("nj645: пустая инструкция промпта")

    if ctx.llm is None:
        raise RuntimeError("nj645: LLM-провайдеры не настроены (workers/.env)")

    try:
        temperature = max(0.0, min(1.5, float(payload.get("temperature") or 0.2)))
    except (TypeError, ValueError):
        temperature = 0.2

    system_text = "\n\n".join(part for part in (system, instruction) if part)
    messages = [
        {"role": "system", "content": system_text},
        {"role": "user", "content": task},
    ]

    try:
        await ctx.progress("Модель отвечает…")
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    t0 = time.monotonic()
    text, usage = await ctx.llm.chat_with_usage(
        messages,
        model=_model() or None,
        max_tokens=_max_tokens(),
        temperature=temperature,
        request_timeout=300,
    )
    elapsed_ms = int((time.monotonic() - t0) * 1000)

    model_used = _model() or getattr(ctx.config, "llm_model", "") or "default"
    text = (text or "").strip()

    log.info("nj645[%s] model=%s %d знаков, %d мс", run_id, model_used, len(text), elapsed_ms)

    payload_out = {
        "status": "done" if text else "error",
        "answer": text[:20000],
        "model": str(model_used),
        "elapsed_ms": str(elapsed_ms),
        "usage": usage if isinstance(usage, dict) else {},
        "error": "" if text else "Модель вернула пустой ответ",
    }

    delivered = await _api_result(ctx, run_id, payload_out)

    result = {
        "run_id": run_id,
        "delivered": delivered,
        "answer": text[:20000],
        "model": str(model_used),
        "elapsed_ms": elapsed_ms,
        "usage": usage if isinstance(usage, dict) else {},
    }

    if not text:
        raise RuntimeError("nj645: модель вернула пустой ответ")

    return result
