"""

Пайплайн: текст и эскиз → LLM пишет OpenSCAD-код → сборка STL → рендер PNG → файлы на сайт.
Хендлеры nj570 «Sketch 2 Solid»: текст+изображение → OpenSCAD-код → STL+PNG.

Три канала (PHP: Service_Nj570_Models::_dispatch):
  nj570.generate — генерация/правка кода (фикс-цикл по stderr рендера, vision-итерации);
  nj570.clarify  — уточняющие вопросы с вариантами-кнопками (до 4, до кода);
  nj570.render   — пересборка STL по значениям параметрических ползунков.

Конвейер generate:
  search   — опционально: DuckDuckGo-референсы (ddgs; пауза NJ570_SEARCH_DELAY,
             число запросов NJ570_SEARCH_MAX — бережно, как в nj550);
  code     — код-модель (NJ570_CODE_MODEL): по тексту (+изображение на шаге 1);
  render   — ЛОКАЛЬНЫЙ OpenSCAD (n2j_workers/scad.py: бинарник + BOSL2,
             прозрачный PNG, STL; строки ERROR: из вывода → код-модели
             на исправление до iterations). Никаких внешних сервисов рендера
             — воркер самодостаточен (кроме LLM-шлюза и сайта);
  vision   — сравнение «замысел vs рендер» (NJ570_VISION_MODEL): шаг 1 — эскиз юзера,
             далее исходное изображение + рендер кода; изображения сжимаются до
             512px по ширине (экономия токенов);
  describe — короткое описание каталога дешёвой моделью (NJ570_DESC_MODEL);
  upload   — STL/PNG сразу POST-ом на сайт /3d/api/upload (у рендера файлы
             живут ~60 c). Затраты — построчно в nj_570_usage (pg-режим; в
             http-режиме возвращаются в job.result, PHP дозапишет).

Модели поддерживают провайдеров: "openrouter::google/…" / "модель" (провайдер
по умолчанию) / "" (дефолт провайдера) — см. workers/n2j_workers/llm.py.
"""
from __future__ import annotations

import asyncio
import base64
import io
import json
import logging
import os
import re
import struct
import time

from .. import scad, vlm
from ..base import HandlerContext

log = logging.getLogger("n2j.nj570")

CHANNEL = "nj570.generate"  # ключ по умолчанию; см. HANDLERS в __init__.py

# --------------------------------------------------------------------------
# Настройки (env воркера / workers/.env)
#
# ВАЖНО: читаем ТОЛЬКО в функциях, никогда на уровне модуля. .env загружается
# в os.environ в Config.load() ПОСЛЕ импорта хендлеров — константы, прочитанные
# при импорте, остаются пустыми навсегда (классический баг: «выгрузка выключена»
# при заданном NJ570_UPLOAD_URL). Образец — nj550.job_search: env читается в вызовах.
# --------------------------------------------------------------------------

def _code_model() -> str:
    return os.getenv("NJ570_CODE_MODEL", "").strip()


def _desc_model() -> str:
    return os.getenv("NJ570_DESC_MODEL", "").strip()


def _vision_model() -> str:
    """NJ570_VISION_MODEL → общая VLM_MODEL → дефолт провайдера."""
    return vlm.model("NJ570")


def _upload_url() -> str:
    # https://<сайт>/3d/api/upload
    return os.getenv("NJ570_UPLOAD_URL", "").strip()


def _site_token() -> str:
    # Токен сайта для выгрузки. НЕ тот же env, что WORKER_API_TOKEN: в режиме
    # pg задавать WORKER_API_TOKEN запрещено (config.validate), поэтому выгрузка
    # живёт в NJ570_SITE_TOKEN (фолбэк — для http-режима, где это один токен).
    return os.getenv("NJ570_SITE_TOKEN", "").strip() or os.getenv("WORKER_API_TOKEN", "").strip()


def _max_iter() -> int:
    try:
        return max(1, int(os.getenv("NJ570_MAX_ITER", "5")))
    except ValueError:
        return 5


def _search_max() -> int:
    try:
        return max(1, int(os.getenv("NJ570_SEARCH_MAX", "3")))
    except ValueError:
        return 3


def _search_delay() -> float:
    try:
        return float(os.getenv("NJ570_SEARCH_DELAY", "2.5"))
    except ValueError:
        return 2.5


# Кадр для модели: NJ570_PHOTO_MAX_PX → VLM_PHOTO_MAX_PX → 512 (ТЗ: экономия
# токенов vision); настраивается без правки кода.
IMAGE_MAX_W = vlm.photo_max_w("NJ570", 512)

BOSL2_REMINDER = (
    "Разрешён только OpenSCAD с библиотекой BOSL2: в начале файла "
    "include <BOSL2/std.scad>; другие include запрещены. Без import(), surface() "
    "и обращений к файловой системе. Код самодостаточный, единицы — миллиметры, "
    "деталь должна быть цельной (manifold) и пригодной для 3D-печати."
)

SYSTEM_CODE = (
    "Ты опытный инженер-конструктор и программист OpenSCAD. Пишешь параметрический "
    "печабельный код по описанию и эскизу пользователя. " + BOSL2_REMINDER +
    " Код ОБЯЗАН быть параметрическим: все ключевые размеры вынеси в числовые "
    "переменные верхнего уровня вида `имя = число; // русская подпись` (3-8 штук) "
    "и построй геометрию ТОЛЬКО на них: изменение любой переменной обязано менять "
    "деталь. Неиспользуемые и «декоративные» переменные запрещены. "
    " Отвечай ТОЛЬКО кодом в одном блоке ```scad ... ``` без пояснений."
)

SYSTEM_DESC = (
    "Ты кратко описываешь 3D-модели по-русски для каталога. Верни JSON: "
    "{\"title\": \"название до 60 символов\", \"summary\": \"1-2 предложения\"}. "
    "Только JSON, без markdown."
)

SYSTEM_VISION = (
    "Ты контролёр качества 3D-моделей. Сравни рендер кода с замыслом пользователя "
    "(текст/эскиз). Верни JSON: {\"ok\": true} при хорошем соответствии, иначе "
    "{\"ok\": false, \"critique\": \"что не так и как исправить, 1-3 предложения\"}. "
    "Только JSON."
)

SYSTEM_CLARIFY = (
    "Ты помогаешь уточнить требования к 3D-модели перед написанием кода. Задай до 4 "
    "ключевых вопросов, где важны размеры/форма/назначение. Верни JSON: "
    "{\"questions\": [{\"q\": \"вопрос по-русски\", \"options\": [\"вариант 1\", "
    "\"вариант 2\", \"вариант 3\"]}]}. Только JSON, без markdown."
)


# --------------------------------------------------------------------------
# LLM: chat + учёт затрат (chat возвращает str, usage рядом не отдаёт)
# --------------------------------------------------------------------------

async def _chat(ctx: HandlerContext, messages: list[dict], phase: str, job_id: int,
                revision_id: int, sink: list, model: str = "",
                max_tokens: int = 8000, request_timeout: float | None = None) -> str:
    """Вызов LLM + учёт затрат: pg-режим — строка в nj_570_usage сразу,
    http-режим — строка в sink (вернётся в job.result, PHP дозапишет)."""
    t0 = time.monotonic()
    kw = {"model": model or None, "max_tokens": max_tokens}

    if request_timeout:
        kw["request_timeout"] = request_timeout

    text, usage = await ctx.llm.chat_with_usage(messages, **kw)
    dt = int((time.monotonic() - t0) * 1000)

    pt = int(usage.get("prompt_tokens") or 0)
    ct = int(usage.get("completion_tokens") or 0)
    model_used = model or ctx.config.llm_model or "default"
    log.info("nj570[%s] model=%s %d+%d tok, %d ms", phase, model_used, pt, ct, dt)

    # Этап в журнал очереди (монитор nj120, «Мои задачи», статус-ряд модуля)
    try:
        await ctx.progress(str(phase))
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    await _usage_row(ctx, revision_id=revision_id, job_id=job_id, phase=phase,
                     model=model_used, prompt_tokens=pt, completion_tokens=ct,
                     duration_ms=dt, sink=sink)
    return text


def _extract_code(text: str) -> str:
    m = re.search(r"```(?:scad|openscad)?\s*(.*?)```", text, re.S)
    code = (m.group(1) if m else text).strip().removeprefix("```").removesuffix("```").strip()
    if "BOSL2/std.scad" not in code:
        code = "include <BOSL2/std.scad>\n" + code
    return code


def _extract_json(text: str) -> dict:
    m = re.search(r"\{.*\}", text, re.S)
    if not m:
        return {}
    try:
        data = json.loads(m.group(0))
        return data if isinstance(data, dict) else {}
    except ValueError:
        return {}


# --------------------------------------------------------------------------
# PG-помощники (asyncpg-пул; в http-режиме ctx.pg is None)
# --------------------------------------------------------------------------

async def _pg_exec(ctx: HandlerContext, sql: str, *args) -> None:
    if ctx.pg is None:
        return
    try:
        async with ctx.pg.acquire() as conn:
            await conn.execute(sql, *args)
    except Exception:  # noqa: BLE001 — запись статуса не должна ронять задачу
        log.warning("nj570: pg execute: %s", sql.split("(")[0].strip())


async def _status(ctx: HandlerContext, revision_id: int, status: str) -> None:
    await _pg_exec(ctx, "UPDATE nj_570_revisions SET status = $1 WHERE id = $2",
                   status, revision_id)


async def _usage_row(ctx: HandlerContext, *, revision_id: int, job_id: int, phase: str,
                     model: str, prompt_tokens: int, completion_tokens: int,
                     duration_ms: int, sink: list) -> None:
    """pg — сразу в nj_570_usage; http — в sink → job.result (дозапишет PHP)."""
    if ctx.pg is not None:
        await _pg_exec(
            ctx,
            "INSERT INTO nj_570_usage (model_id, revision_id, job_id, phase, model, "
            "prompt_tokens, completion_tokens, duration_ms) "
            "SELECT model_id, $1, $2, $3, $4, $5, $6, $7 FROM nj_570_revisions "
            "WHERE id = $1",
            revision_id, job_id, phase, model, prompt_tokens, completion_tokens,
            duration_ms)
    else:
        sink.append({"revision_id": revision_id, "phase": phase, "model": model,
                     "prompt_tokens": prompt_tokens,
                     "completion_tokens": completion_tokens, "duration_ms": duration_ms})


# --------------------------------------------------------------------------
# Изображения
# --------------------------------------------------------------------------

def _shrink_sync(png_bytes: bytes, max_w: int) -> bytes | None:
    try:
        from PIL import Image

        img = Image.open(io.BytesIO(png_bytes)).convert("RGB")
        w, h = img.size
        if w > max_w:
            img = img.resize((max_w, max(1, round(h * max_w / w))), Image.LANCZOS)
        out = io.BytesIO()
        img.save(out, "PNG", optimize=True)
        return out.getvalue()
    except Exception:  # noqa: BLE001 — нет PIL/битое изображение
        return None


async def _shrink_b64(ctx: HandlerContext, b64: str, max_w: int = IMAGE_MAX_W) -> str:
    """base64-изображение → уменьшенный PNG в base64 (экономия токенов vision)."""
    if not b64:
        return ""
    try:
        raw = base64.b64decode(b64)
    except Exception:  # noqa: BLE001
        return ""
    out = await asyncio.to_thread(_shrink_sync, raw, max_w)
    if out is not None:
        return base64.b64encode(out).decode()
    return b64 if len(b64) < 700_000 else ""


def _img_msg(b64_png: str, text: str) -> dict:
    return {"role": "user", "content": [
        {"type": "text", "text": text},
        {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{b64_png}"}},
    ]}


def _msg(role: str, content: str) -> dict:
    return {"role": role, "content": content}


# --------------------------------------------------------------------------
# HTTP-рендер (workers/openscad-renderer) и выгрузка файлов на сайт
# --------------------------------------------------------------------------

async def _render(ctx: HandlerContext, code: str, quality: str = "low",
                  with_stl: bool = True) -> tuple[bytes | None, bytes | None, str]:
    """(png, stl, stderr). stderr непуст при ошибке кода (для фикс-цикла).

    Рендер ТОЛЬКО локальный (scad.py: бинарник openscad + BOSL2 на этом же
    сервере). Внешних сервисов рендера нет и не будет — по решению владельца;
    если бинарник/библиотека не найдены, воркер честно скажет об этом (см.
    workers/nj570_doctor.py — раздел «Локальный рендер»)."""
    if not scad.available():
        return None, None, ("Локальный OpenSCAD недоступен: нет бинарника "
                            "(workers/openscad-renderer/bin/openscad или NJ570_OPENSCAD_BIN) "
                            "или BOSL2 (workers/openscad-renderer/libraries/BOSL2 или "
                            "NJ570_OPENSCAD_LIB). Диагностика: python3 workers/nj570_doctor.py")

    return await scad.render(code, quality=quality, with_stl=with_stl)


async def _upload(ctx: HandlerContext, revision_id: int, kind: str, data: bytes) -> None:
    """Файл на сайт (у рендера файлы живут ~60 c — забираем сразу)."""
    upload_url, site_token = _upload_url(), _site_token()

    if not (upload_url and site_token and data):
        if data:
            log.info("nj570: выгрузка ВЫКЛЮЧЕНА (NJ570_UPLOAD_URL/NJ570_SITE_TOKEN пусты в окружении воркера!) — %d байт %s потеряны", len(data), kind)
        return

    import httpx

    try:
        async with httpx.AsyncClient(timeout=120) as client:
            r = await client.post(
                upload_url,
                data={"revision_id": str(revision_id), "kind": kind},
                files={"file": (f"model.{kind}", data)},
                headers={"X-Worker-Token": site_token})
        log.info("nj570: upload %s rev=%s -> HTTP %s%s", kind, revision_id,
                 r.status_code, "" if r.status_code == 200 else ": " + r.text[:300])
    except Exception:  # noqa: BLE001
        log.warning("nj570: upload failed:\n%s", __import__("traceback").format_exc())


# --------------------------------------------------------------------------
# DuckDuckGo-референсы (бережно: паузы и лимиты, как в nj550)
# --------------------------------------------------------------------------

def _ddg_search(query: str, max_results: int) -> list[dict]:
    from ddgs import DDGS

    with DDGS() as ddgs:
        return list(ddgs.text(query, region="ru-ru", safesearch="moderate",
                              max_results=max_results) or [])


async def _duck_refs(ctx: HandlerContext, query: str) -> str:
    try:
        rows = await asyncio.to_thread(_ddg_search, query + " 3D модель конструкция",
                                       _search_max())
    except Exception:  # noqa: BLE001 — поисковик не критичен
        log.info("nj570: ddgs недоступен, идём без референсов")
        return ""
    await asyncio.sleep(_search_delay())
    return "; ".join(f"{r.get('title', '')} ({(r.get('body') or '')[:120]})"
                     for r in rows)[:800]


# --------------------------------------------------------------------------
# Канал nj570.generate
# --------------------------------------------------------------------------

async def handle(ctx: HandlerContext, payload: dict) -> dict:
    sink: list = []
    job_id = int((ctx._current_job or {}).get("id") or 0)
    result = await _generate(ctx, payload, job_id, sink)
    result["usage"] = sink  # http-режим: PHP дозапишет (в pg строки уже в базе)
    return result


async def _generate(ctx: HandlerContext, payload: dict, job_id: int, sink: list) -> dict:
    prompt = str(payload.get("prompt", "") or "").strip()
    img_b64 = str(payload.get("image_b64", "") or "")
    prev_code = str(payload.get("prev_code", "") or "")
    answers = payload.get("answers") or {}
    questions = payload.get("questions") or []
    rev_id = int(payload.get("revision_id") or 0)

    iters = max(1, min(_max_iter(), int(payload.get("iterations") or 3)))
    user_img = await _shrink_b64(ctx, img_b64)

    # --- референсы ---
    refs = ""
    if payload.get("use_search") and prompt:
        refs = await _duck_refs(ctx, prompt)

    # --- базовая подсказка ---
    task = "Задача: напиши код OpenSCAD для конструкции.\n"
    if prompt:
        task += f"Описание пользователя: {prompt}\n"
    if answers:
        qa = "; ".join(
            f"{(questions[i].get('q') if isinstance(questions, list) and i < len(questions) else '?')}"
            f" → {v}" for i, v in answers.items())
        task += f"Уточнения пользователя: {qa}\n"
    if refs:
        task += f"Референсы из интернета (только для понимания формы): {refs}\n"
    if prev_code:
        task += ("Это правка существующей модели. Текущий код:\n```scad\n" + prev_code +
                 "\n```\nВнеси изменения по новому запросу, верни ПОЛНЫЙ новый код.\n")

    code = ""
    vision_notes: list[str] = []
    history: list[dict] = []
    png = None
    stl = None

    for it in range(1, iters + 1):
        await ctx.heartbeat()
        await _status(ctx, rev_id, "coding")

        content = task
        if vision_notes:
            content += "Замечания предыдущих проверок: " + " | ".join(vision_notes[-2:]) + "\n"

        msgs = [_msg("system", SYSTEM_CODE)]
        if user_img and it == 1:
            msgs.append(_img_msg(user_img, content + "\n(эскиз/фото пользователя приложено)"))
        else:
            msgs.append(_msg("user", content))

        text = await _chat(ctx, msgs, phase=f"code#{it}", job_id=job_id, revision_id=rev_id,
                           sink=sink, model=_code_model(), request_timeout=480)
        code = _extract_code(text)
        history.append({"iter": it, "phase": "code", "code_len": len(code)})

        # --- рендер: STL дорог (CGAL), на промежуточных итерациях только PNG ---
        final_iter = (it == iters)
        await ctx.heartbeat()   # длинный рендер не должен выглядеть «мертвым»
        await _status(ctx, rev_id, "rendering")
        png, stl, stderr = await _render(ctx, code, quality="low" if not final_iter else "medium",
                                         with_stl=final_iter)

        # stderr непуст при ЛЮБОЙ неудаче рендера (ошибка кода, таймаут,
        # нет openscad/BOSL2) — раньше ловили только «ERROR», и таймаут
        # молча пропускал STL
        if stderr:
            history.append({"iter": it, "phase": "render_error", "stderr": stderr[:500]})
            if final_iter:
                return {"ok": False, "error": f"Код не собирается: {stderr[:400]}",
                        "code": code, "history": history}
            vision_notes.append(f"OpenSCAD не собрал модель: {stderr[:600]}\n"
                                "Исправь код; если дело в производительности — "
                                "уменьши $fn/количество элементов.")
            continue

        # --- файлы сразу на сайт ---
        if stl:
            await _upload(ctx, rev_id, "stl", stl)
        if png:
            await _upload(ctx, rev_id, "png", png)

        # --- vision: замысел vs рендер (нет смысла на последней итерации) ---
        if final_iter or not user_img or png is None:
            break

        vision_img = await _shrink_b64(ctx, base64.b64encode(png).decode())
        msgs = [_msg("system", SYSTEM_VISION),
                _img_msg(user_img, "Это замысел пользователя (текст/эскиз).")]
        if vision_img:
            msgs.append(_img_msg(vision_img,
                                 "Это рендер кода — оцени соответствие. Код:\n```scad\n"
                                 + code[:3000] + "\n```"))
        else:
            msgs.append(_msg("user", "Рендер недоступен — оцени по коду:\n```scad\n"
                             + code[:3000] + "\n```"))
        try:
            vtext = await _chat(ctx, msgs, phase=f"vision#{it}", job_id=job_id,
                                revision_id=rev_id, sink=sink, model=_vision_model(),
                                max_tokens=500)
            verdict = _extract_json(vtext)
            history.append({"iter": it, "phase": "vision", "verdict": verdict})
            if verdict.get("ok"):
                break
            vision_notes.append(str(verdict.get("critique") or "несоответствие замыслу"))
        except Exception:  # noqa: BLE001 — vision-модель недоступна/не умеет картинки:
            # принимаем текущий код без проверки, задачу не роняем
            log.warning("nj570: vision недоступна — код принят без vision-проверки:\n%s",
                        __import__("traceback").format_exc())
            history.append({"iter": it, "phase": "vision_unavailable"})
            break

    # --- STL обязателен: если цикл закончился ДО финальной итерации (vision
    #     одобрил раньше), STL ещё не собран — собираем для финального кода ---
    if stl is None:
        await ctx.heartbeat()
        await _status(ctx, rev_id, "rendering")
        png2, stl, stderr = await _render(ctx, code, quality="medium", with_stl=True)
        if stderr or not stl:
            history.append({"phase": "final_stl_error", "stderr": (stderr or "пустой STL")[:500]})
            return {"ok": False,
                    "error": f"STL не собрался: {(stderr or 'пустой файл')[:400]}",
                    "code": code, "history": history}
        await _upload(ctx, rev_id, "stl", stl)
        if png2 and png is None:
            await _upload(ctx, rev_id, "png", png2)

    # --- описание (дешёвая модель) ---
    title = summary = ""
    try:
        await ctx.heartbeat()
        dtext = await _chat(ctx,
                            [_msg("system", SYSTEM_DESC),
                             _msg("user", (prompt or (prev_code and "правка модели") or "3D-модель")
                                  + "\n\nКод:\n```scad\n" + code[:6000] + "\n```")],
                            phase="describe", job_id=job_id, revision_id=rev_id,
                            sink=sink, model=_desc_model(), max_tokens=300)
        d = _extract_json(dtext)
        title = str(d.get("title") or "")[:200]
        summary = str(d.get("summary") or "")[:2000]
    except Exception:  # noqa: BLE001 — описание не критично
        log.warning("nj570: describe failed:\n%s", __import__("traceback").format_exc())

    return {"ok": True, "code": code, "history": history,
            "code_model": _code_model() or "default", "desc_model": _desc_model() or "default",
            "title": title, "summary": summary,
            "params": _parametric_params(code)}


# --------------------------------------------------------------------------
# Канал nj570.clarify: вопросы с кнопками ДО кода
# --------------------------------------------------------------------------

async def handle_clarify(ctx: HandlerContext, payload: dict) -> dict:
    sink: list = []
    job_id = int((ctx._current_job or {}).get("id") or 0)
    rev_id = int(payload.get("revision_id") or 0)

    prompt = str(payload.get("prompt", "") or "").strip()
    img_b64 = str(payload.get("image_b64", "") or "")
    user_img = await _shrink_b64(ctx, img_b64)

    msgs = [_msg("system", SYSTEM_CLARIFY)]
    if user_img:
        msgs.append(_img_msg(user_img, "Эскиз/фото пользователя. " +
                             (f"Описание: {prompt}" if prompt else "Опиши, что уточнить.")))
    else:
        msgs.append(_msg("user", prompt or "Пользователь хочет 3D-модель, уточни требования."))

    try:
        text = await _chat(ctx, msgs, phase="clarify", job_id=job_id, revision_id=rev_id,
                           sink=sink, model=_desc_model(), max_tokens=600)
        qs = [q for q in (_extract_json(text).get("questions") or [])
              if isinstance(q, dict) and q.get("q")][:4]
    except Exception:  # noqa: BLE001 — нет модели вопросов → сразу к генерации
        log.warning("nj570: clarify недоступен — генерируем без уточнений:\n%s",
                    __import__("traceback").format_exc())
        qs = []

    return {"ok": True, "usage": sink, "questions": qs or [], "proceed": not qs}


# --------------------------------------------------------------------------
# Канал nj570.render: пересборка STL по ползункам (код не переписывается)
# --------------------------------------------------------------------------

async def handle_render(ctx: HandlerContext, payload: dict) -> dict:
    sink: list = []
    job_id = int((ctx._current_job or {}).get("id") or 0)
    rev_id = int(payload.get("revision_id") or 0)

    code = str(payload.get("code", "") or "")
    overrides = payload.get("overrides") or {}
    if not code:
        return {"ok": False, "error": "нет кода ревизии", "usage": []}

    # значения ползунков вписываются в код: name = value; // label
    patched = _apply_overrides(code, overrides)
    if patched == code:
        log.info("nj570: overrides не изменили код (rev=%s)", rev_id)

    await ctx.heartbeat()
    await _status(ctx, rev_id, "rendering")
    png, stl, stderr = await _render(ctx, patched, quality="medium")
    if stderr and "ERROR" in stderr.upper():
        return {"ok": False, "error": f"рендер: {stderr[:400]}", "usage": sink}

    # Честная проверка пересборки: крупный параметр обязан отразиться в
    # габаритах STL. Если нет — код не параметричен («мёртвые» переменные),
    # и молча отдавать ту же деталь нельзя: ревизия помечается ошибкой
    # с понятным советом (правка текстом «вынеси размеры в переменные»).
    if overrides:
        dead = _dead_overrides(overrides, _stl_bbox_mm(stl or b""))
        if dead:
            return {"ok": False, "usage": sink,
                    "error": ("Код не параметричен: параметр(ы) " + ", ".join(dead)
                              + " не влияют на геометрию (габарит не изменился). "
                                "Сделайте правку текстом: «вынеси ключевые размеры "
                                "в переменные верхнего уровня и используй их в геометрии».")}

    await _upload(ctx, rev_id, "stl", stl or b"")
    if png:
        await _upload(ctx, rev_id, "png", png)

    return {"ok": True, "code": patched, "rerendered": True,
            "params": _parametric_params(patched), "usage": sink}


_PARAM_RE = re.compile(r"^(\s*)([A-Za-z_]\w*)\s*=\s*(-?\d+(?:\.\d+)?)\s*;\s*(?://\s*(.*))?$")


def _stl_bbox_mm(data: bytes):
    """Габариты STL в мм, отсортированные по возрастанию; None — не разобрали."""
    if not data or len(data) < 84:
        return None
    mins = [1e30] * 3
    maxs = [-1e30] * 3

    def _acc(x, y, z):
        for i, v in enumerate((x, y, z)):
            mins[i] = min(mins[i], v)
            maxs[i] = max(maxs[i], v)

    n = struct.unpack_from("<I", data, 80)[0]
    if 0 < n < 5_000_000 and len(data) >= 84 + n * 50:
        for f in range(n):
            off = 84 + f * 50 + 12
            _acc(*struct.unpack_from("<3f", data, off))
            _acc(*struct.unpack_from("<3f", data, off + 12))
            _acc(*struct.unpack_from("<3f", data, off + 24))
    else:
        trip = re.findall(
            r"vertex\s+([-\d.eE+]+)\s+([-\d.eE+]+)\s+([-\d.eE+]+)",
            data[:2_000_000].decode("utf-8", "ignore"))
        if not trip:
            return None
        for a, b, c in trip:
            _acc(float(a), float(b), float(c))

    dims = [maxs[i] - mins[i] for i in range(3)]
    return sorted(dims) if all(d > 0 for d in dims) else None


def _dead_overrides(overrides: dict, dims) -> list:
    """Параметры с крупными значениями, НЕ отразившиеся в габаритах STL.

    Консервативно: проверяются только значения >= 0.75 максимума габарита,
    попадание в [0.75v, 1.5v] считается «параметр применился» (допускаем
    множители вида size*1.5). Мелкие параметры габаритом не проверяются.
    """
    if not dims or max(dims) <= 0:
        return []
    suspects = []
    for name, raw in overrides.items():
        try:
            v = abs(float(str(raw).replace(",", ".")))
        except (ValueError, TypeError):
            continue
        if v < 0.75 * max(dims):
            continue
        if not any(0.75 * v <= d <= 1.5 * v for d in dims):
            suspects.append(str(name))
    return suspects


def _apply_overrides(code: str, overrides: dict) -> str:
    out = []
    for line in code.splitlines():
        m = _PARAM_RE.match(line)
        if m and m.group(2) in overrides:
            try:
                val = float(str(overrides[m.group(2)]).replace(",", "."))
                out.append(f"{m.group(1)}{m.group(2)} = {val:g}; // {m.group(4) or ''}".rstrip())
                continue
            except ValueError:
                pass
        out.append(line)
    return "\n".join(out)


def _parametric_params(code: str) -> list:
    """Числовые переменные верхнего уровня → ползунки (name, label, min, max, step, default)."""
    out = []
    for line in code.splitlines()[:150]:
        m = _PARAM_RE.match(line)
        if not m:
            continue
        name, val, label = m.group(2), float(m.group(3)), (m.group(4) or "").strip()
        if val <= 0:
            continue
        step = 1.0 if val == int(val) else 0.1
        out.append({"name": name, "label": label or name,
                    "min": round(max(step, val / 3), 2), "max": round(val * 3, 2),
                    "step": step, "default": val})
        if len(out) >= 10:
            break
    return out
