"""nj672.scan — сканер безопасности ИИ-моделей (nj672_aiscan): канал очереди.

Один прогон = полный чёрноящичный аудит цели (батарея атак + опционально
стресс-профиль) и доставка отчёта на сайт. Сайт ничего не считает — он
показывает; вся арифметика в `n2j_workers/aiscan.py`.

  payload = {
    run_id,                                 # строка nj_672_runs
    target: {kind: profile|custom, profile?, base_url?, model?, api_key?},
    suites: [...],                          # ключи наборов (aiscan_battery)
    stress: off|quick|standard|full,
    quick: bool, judge: bool,
    custom_prompts: [...], custom_system: ""
  }

Ключ цели (`api_key`) существует только в payload задачи и в памяти воркера:
в отчёт, плоскую сводку задачи и логи он НЕ попадает (держит тест
`test_report_has_no_secret`). Для боевых ключей правильный путь — пресет
NJ672_TARGET_* в workers/.env, где ключ не покидает машину воркера.

Задача ставится сайтом с max_attempts=1: повтор аудита = повторная атака на
цель и двойной счёт за её токены, поэтому ретрай здесь хуже честной ошибки.

Судья-модель (LLM-as-a-Judge) — СВОЯ модель воркера (ctx.llm), а не цель:
оценивать цель ею же самой нельзя. Модель судьи: NJ672_JUDGE_MODEL
(«провайдер::модель»), пусто = провайдер по умолчанию.

Доставка — POST /aiscan/api/worker_result с X-Worker-Token
(NJ672_SITE_TOKEN -> WORKER_SITE_TOKEN -> WORKER_API_TOKEN). Молчаливый
отказ доставки запрещён: не принял сайт — задача падает с подсказкой.
"""

from __future__ import annotations

import logging
import os
import time

from .. import aiscan, site
from ..base import HandlerContext

log = logging.getLogger("n2j.nj672")

CHANNEL = "nj672.scan"
PREFIX = "NJ672"
DELIVER_PATH = "/worker_result"


async def deliver(payload: dict) -> tuple[bool, str]:
    """Отдать отчёт сайту. Молчаливый отказ доставки запрещён."""
    base = site.module_api_base(PREFIX, "/aiscan/api")

    ok, why, _ = await site.post_json(PREFIX, DELIVER_PATH, payload,
                                      timeout=90, retries=2, json_body=True,
                                      base=base or None)
    if not ok:
        log.warning("nj672: сайт не принял отчёт прогона %s: %s",
                    payload.get("run_id"), why)

    return ok, why


def _target_from_payload(payload: dict, ctx: HandlerContext) -> aiscan.Target:
    raw = payload.get("target") or {}
    kind = str(raw.get("kind") or "profile").strip()
    if kind == "custom":
        return aiscan.custom_target(str(raw.get("base_url") or ""),
                                    str(raw.get("model") or ""),
                                    str(raw.get("api_key") or ""))
    default_llm = None
    if ctx.llm is not None:
        provider = ctx.llm._providers.get(ctx.llm._default)
        if provider is not None:
            default_llm = {"base_url": provider.base_url,
                           "model": provider.model,
                           "api_key": provider.api_key}
    return aiscan.profile_target(str(raw.get("profile") or ""), default_llm)


def _judge_chat(ctx: HandlerContext, enabled: bool):
    """Судья на своей модели воркера; None — судья выключен или недоступен."""
    if not enabled or ctx.llm is None:
        return None
    model = (os.environ.get("NJ672_JUDGE_MODEL") or "").strip() or None

    async def chat(messages: list[dict]) -> str:
        return await ctx.llm.chat(messages, model=model, temperature=0.0,
                                   max_tokens=400)

    return chat


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    started = time.monotonic()

    if run_id <= 0:
        raise ValueError(f"{CHANNEL}: в задаче нет run_id — результат некуда принять")

    suites = [str(s) for s in (payload.get("suites") or [])]
    stress = str(payload.get("stress") or "off").strip().lower()
    quick = bool(payload.get("quick"))
    custom_prompts = [str(p) for p in (payload.get("custom_prompts") or [])]
    custom_system = str(payload.get("custom_system") or "")
    target = _target_from_payload(payload, ctx)
    judge_chat = _judge_chat(ctx, bool(payload.get("judge")))

    async def progress(phase: str, note: str = "") -> None:
        await ctx.progress(phase, note)

    try:
        result = await aiscan.run_scan(
            target, suites=suites, stress_profile=stress, quick=quick,
            custom_prompts=custom_prompts, custom_system=custom_system,
            judge_chat=judge_chat, progress=progress,
            attested=bool(payload.get("attested")))
    except Exception as e:  # noqa: BLE001 — прогон обязан закрыться причиной
        reason = f"{type(e).__name__}: {str(e)[:600]}"
        log.warning("nj672: прогон %s не удался: %s", run_id, reason)
        await deliver({"run_id": run_id, "status": "error", "error": reason,
                       "elapsed_ms": int((time.monotonic() - started) * 1000)})
        raise

    result["run_id"] = run_id
    result["elapsed_ms"] = int((time.monotonic() - started) * 1000)

    ok, why = await deliver(result)

    if not ok:
        raise RuntimeError(
            "отчёт посчитан, но сайт его не принял: "
            f"{why or 'пустой ответ'}. Проверьте NJ672_API_BASE (или общий "
            "NJ120_API_BASE) и NJ672_SITE_TOKEN (= WORKER_API_TOKEN сайта) "
            "в workers/.env")

    # В результат задачи кладём ПОЛНЫЙ отчёт: дожим на сайте сможет
    # восстановить его, если доставка когда-нибудь не дойдёт (урок nj671
    # 22.10.2026: короткая сводка перезаписала доставленный отчёт).
    # `summary_only` — контракт: сайт отличает сводку от отчёта с находками.
    summary = result.get("summary") or {}
    flat = dict(result)
    flat.update({
        "summary_only": False,
        "run_id": run_id,
        # Плоские поля для монитора очереди и истории одной строкой.
        "security_score": summary.get("security_score"),
        "risk_level": summary.get("risk_level"),
        "failed": (summary.get("counts") or {}).get("fail"),
        "reliability_score": summary.get("reliability_score"),
    })

    return flat
