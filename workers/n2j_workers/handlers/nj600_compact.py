"""nj600.compact — сжатие профилей «Аккумулятора предпочтений».

Пайплайн: сырые события профиля (GET /prefs/api/profiles) → LLM сжимает и нормализует → POST /prefs/api/save по каждому профилю.

Экономия токенов в два этапа:
  1. СКРИПТЫ (0 токенов): выкачиваем компактные профили с сайта
     (GET /prefs/api/profiles), склеиваем близкие фразы (нормализация,
     вхождение подстроки), режем хвосты, собираем компактный текст;
  2. LLM: батчами по 5 владельцев пишет человеческое резюме (1-2 предложения
     по-русски) → POST /prefs/api/save по каждому.

Env: NJ600_PREFS_API_BASE (напр. https://n2j.ru/prefs/api),
NJ600_PREFS_SITE_TOKEN (или общий WORKER_SITE_TOKEN → WORKER_API_TOKEN),
NJ600_PREFS_MODEL (спека «провайдер::модель»).
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import time

from .. import site
from .. import webread

log = logging.getLogger("n2j.nj600")

CHANNEL = "nj600.compact"


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _model_spec() -> str:
    return _env("NJ600_PREFS_MODEL")


async def _get_site(path: str) -> tuple[bool, str, dict]:
    return await site.get_json("NJ600_PREFS", path)


async def _post_site(path: str, data: dict) -> tuple[bool, str]:
    ok, reason, _j = await site.post_json("NJ600_PREFS", path, data)
    return ok, reason


# --------------------------------------------------------- 1. скрипты (0 токенов)

def _norm_phrase(p: str) -> str:
    s = re.sub(r"\s+", " ", str(p).strip().lower())
    s = re.sub(r"[^\wа-я0-9 ]", "", s, flags=re.UNICODE)

    return s


def _compact_profile(p: dict) -> str:
    """Склеить профиль в компактный текст: топ-разделы, фразы (сгруппированные),
    источники. Всё локально — LLM получает уже сжатую выжимку."""
    sections = [(str(k), int(v)) for k, v in (p.get("sections") or {}).items()]
    sections.sort(key=lambda x: -x[1])

    raw_phrases = [([str(x) for x in pair][0], int(pair[1]))
                   for pair in (p.get("phrases") or []) if isinstance(pair, (list, tuple)) and pair]
    groups: list[tuple[str, int, list[str]]] = []

    for ph, cnt in sorted(raw_phrases, key=lambda x: -x[1]):
        nph = _norm_phrase(ph)

        if not nph:
            continue

        for i, (_, _, members) in enumerate(groups):
            if nph in _norm_phrase(members[0]) or _norm_phrase(members[0]) in nph:
                groups[i] = (groups[i][0], groups[i][1] + cnt, members + [ph])
                break
        else:
            groups.append((nph, cnt, [ph]))

        if len(groups) >= 8:
            break

    hosts = sorted(((str(k), int(v)) for k, v in (p.get("ref_hosts") or {}).items()),
                   key=lambda x: -x[1])[:4]

    lines = [f"Владелец {p.get('owner_key')}: страниц {p.get('pages', 0)}, поисков {p.get('searches', 0)}."]

    if sections:
        lines.append("Разделы: " + ", ".join(f"{k} ({v})" for k, v in sections[:6]) + ".")

    if groups:
        lines.append("Искал: " + "; ".join(
            f"«{m[0]}» (x{c})" for _, c, m in groups[:6]) + ".")

    if hosts:
        lines.append("Приходил с: " + ", ".join(f"{h} ({v})" for h, v in hosts) + ".")

    return "\n".join(lines)


# --------------------------------------------------------- 2. LLM (батчи по 5)

SYSTEM = (
    "Ты аналитик. По компактной выжимке поведения пользователя платформы пишешь "
    "по-русски 1-2 предложения: что ему интересно и чем он занимается, без "
    "выдумок и без персональных данных (имён/почт в выжимке нет). "
    'Отвечаешь СТРОГО JSON: {"profiles": [{"owner_key": "...", "summary": "..."}]}.'
)


async def handle(ctx, payload: dict) -> dict:
    limit = int(payload.get("limit") or 15)
    model_spec = _model_spec()
    sink_usage: list[dict] = []

    ok, why, data = await _get_site(f"/profiles?limit={limit}")

    if not ok:
        raise RuntimeError(f"Сайт не отдал профили — {why}")

    profiles = data.get("profiles") or []

    if not profiles:
        return {"ok": True, "profiles": 0, "note": "сжимать нечего — профилей нет"}

    compact = [_compact_profile(p) for p in profiles]

    saved = 0

    await ctx.progress("Сжимаю профили", f"порций: {(len(compact) + 4) // 5}")

    for i in range(0, len(compact), 5):
        batch = compact[i:i + 5]
        # этап виден в мониторе и «Моих задачах» — единый статус-ряд модулей
        await ctx.progress("ИИ сжимает профили", f"порция {i // 5 + 1}/{(len(compact) + 4) // 5}")
        t0 = time.monotonic()
        raw, usage = await ctx.llm.chat_with_usage(
            [{"role": "system", "content": SYSTEM},
             {"role": "user", "content": "\n\n".join(batch)
              + '\n\nВерни СТРОГО JSON: {"profiles": [{"owner_key": "...", "summary": "..."}] }'
              + "\nowner_key владельцев ровно в том порядке, как прислано: "
              + ", ".join(str(p.get("owner_key")) for p in profiles[i:i + 5])}],
            model=model_spec or None, max_tokens=1200, request_timeout=300)
        dt = int((time.monotonic() - t0) * 1000)
        model_real = str(usage.get("_llm_model") or "") or (model_spec or "default")
        log.info("nj600 compact batch %d: model=%s %d+%d tok, %d ms",
                 i // 5, model_real, int(usage.get("prompt_tokens") or 0),
                 int(usage.get("completion_tokens") or 0), dt)
        sink_usage.append({"phase": f"batch{i // 5}", "model": model_real,
                           "prompt_tokens": int(usage.get("prompt_tokens") or 0),
                           "completion_tokens": int(usage.get("completion_tokens") or 0)})

        j = webread.json_from_text(raw)

        if not isinstance(j, dict):
            log.warning("nj600: LLM вернул не JSON в батче %d", i // 5)
            continue

        by_key = {str(p.get("owner_key")): p for p in profiles}

        for it in (j.get("profiles") or []):
            if not isinstance(it, dict):
                continue

            owner = str(it.get("owner_key") or "")

            if owner not in by_key:
                continue

            summary = str(it.get("summary") or "").strip()

            if not summary:
                continue

            ok2, why2 = await _post_site("/save", {
                "owner_key": owner,
                "summary": summary[:2000],
                "model": model_real,
            })

            if ok2:
                saved += 1
            else:
                log.warning("nj600: save %s: %s", owner, why2)

        await asyncio.sleep(1.0)

    return {"ok": True, "profiles": len(profiles), "saved": saved,
            "usage": sink_usage}
