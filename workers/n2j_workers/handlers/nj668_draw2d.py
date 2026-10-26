"""Каналы nj668.* — «Плоские иллюстрации» (модуль nj668_draw2d).

Идея: векторная сцена, а не растр — единственный источник правды. Воркер
просит модель собрать JSON фигур (прямые, кубические Безье, заливки) и
сдаёт его сайту. В базу NJ668 сам не ходит.

  nj668.generate  описание и/или фото → сцена целиком. Кадры лежат на
                  сайте (storage/nj668/<code>), в задачу очереди не едут
                  (урок nj646). Если сцена уже есть — модель правит ЕЁ.
  nj668.ideas     тема → 30 коротких описаний сцен для кнопок на
                  /draw2d/create. Без проекта: копилка сервиса.

Контекст и приёмка — через сайт:

  POST /draw2d/w/context  {project_id}
  POST /draw2d/w/scene    {project_id, scene, model}
  POST /draw2d/w/fail     {project_id, error}  — сразу, не ждать таймаут.
  POST /draw2d/w/ideas    {theme, ideas, job_id}

Env: NJ668_API_BASE (или общий NJ120_API_BASE), NJ668_SITE_TOKEN (или
WORKER_SITE_TOKEN / WORKER_API_TOKEN), NJ668_MODEL (сцена),
NJ668_LIGHT_MODEL (идеи).
"""

from __future__ import annotations

import base64
import json
import logging
import os
import re

from .. import site, vlm
from ..base import HandlerContext

log = logging.getLogger("n2j.nj668")

PREFIX = "NJ668"
API_PATH = "/draw2d/w"

CHANNEL = "nj668.generate"
IDEAS_CHANNEL = "nj668.ideas"

GENERATE_SYSTEM = (
    "Ты — иллюстратор плоских векторных картинок. По описанию темы и/или фото "
    "СЦЕНУ из простых фигур: прямоугольники, круги, эллипсы, многоугольники, "
    "ломаные и кубические кривые Безье с цветной заливкой.\n\n"
    "Правила:\n"
    "1. Холст {w}×{h}. Координаты в пикселях этого холста. Фон — поле bg.\n"
    "2. Фигур: от 6 до {max_shapes}. Каждая фигура узнаваема в теме: закат — "
    "солнце, небо, море, лодка; дом — крыша, стены, деревья.\n"
    "3. kind только: rect, circle, ellipse, poly, line, cubic, path.\n"
    "4. У каждой фигуры: id (латиница, коротко: s1, s2), kind, fill и/или "
    "stroke (CSS #rrggbb), sw (толщина обводки), opacity (0.05–1).\n"
    "5. rect: x, y, w, h, необязательно rx. circle: x, y, r. ellipse: x, y, "
    "rx, ry. poly: pts — массив [x,y], минимум 3 точки. line: pts, минимум 2. "
    "cubic: pts — сначала точка M, затем тройки C (рычаг, рычаг, конец); "
    "closed=true если замкнуть и залить. path: d только из M/L/C/Q/H/V/Z.\n"
    "6. Без текста, без подписей, без растра, без внешних url.\n"
    "7. Если в сообщении есть «ТЕКУЩАЯ сцена» — правь ЕЁ по просьбе. Верни "
    "полную сцену после правки, не кусок.\n"
    "8. Отвечай ТОЛЬКО валидным JSON без пояснений и без markdown:\n"
    '{{"w":{w},"h":{h},"bg":"#eef6ff","shapes":['
    '{{"id":"s1","kind":"rect","x":0,"y":0,"w":{w},"h":{h},"fill":"#87ceeb"}}]}}\n'
)


def _clean(text: str, limit: int = 0) -> str:
    text = (text or "").strip()
    if limit and len(text) > limit:
        return text[:limit]
    return text


def _int_env(name: str, default: int) -> int:
    try:
        return max(1, int((os.environ.get(name) or "").strip() or default))
    except ValueError:
        return default


def _model_of(payload: dict, light: bool = False) -> str | None:
    if light:
        cheap = (os.environ.get("NJ668_LIGHT_MODEL") or "").strip()
        if cheap:
            return cheap
    own = (os.environ.get("NJ668_MODEL") or "").strip()
    if own and not light:
        return own
    if own and light:
        return own
    sent = str((payload or {}).get("model") or "").strip()
    return sent or None


def _photo_max_px(context: dict) -> int:
    raw = context.get("photo_max_px") if isinstance(context, dict) else None
    try:
        n = int(raw) if raw not in (None, "") else 0
    except (TypeError, ValueError):
        n = 0
    if n <= 0:
        n = vlm.photo_max_w("NJ668", 512)
    return max(128, min(2048, n))


def _shrink_bytes(raw: bytes, max_px: int) -> bytes:
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


def _user_msg(text: str, photos: list[dict]) -> dict:
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


def _parse_json(text: str) -> dict:
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


def _as_scene(data: dict) -> dict:
    if isinstance(data.get("scene"), dict):
        data = data["scene"]
    shapes = data.get("shapes") or data.get("items") or []
    if not isinstance(shapes, list) or not shapes:
        raise ValueError("в ответе нет фигур")
    return {
        "w": data.get("w") or 960,
        "h": data.get("h") or 640,
        "bg": data.get("bg") or "#eef6ff",
        "shapes": shapes,
    }


async def _api(ctx: HandlerContext, path: str, data: dict, timeout: float = 180.0) -> dict:
    ok, reason, res = await site.post_json(
        PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(PREFIX, API_PATH))
    if not ok:
        raise RuntimeError(f"nj668: сайт {path} → {reason}")
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


async def _refuse(ctx: HandlerContext, project_id: int, error: str) -> None:
    error = _clean(str(error or ""), 400) or "модель не нарисовала сцену"
    log.warning("nj668: проект %s отказан: %s", project_id, error)
    try:
        await _api(ctx, "/fail", {"project_id": project_id, "error": error})
    except Exception as e:  # noqa: BLE001
        log.warning("nj668: /fail проекта %s не принят: %s", project_id, e)
    raise RuntimeError(f"nj668: {error}")


async def _ask(ctx: HandlerContext, messages: list[dict], model: str | None,
               max_tokens: int = 4000, timeout: int = 300) -> str:
    if ctx.llm is None:
        raise RuntimeError("LLM-шлюз недоступен: проверьте провайдера в workers/.env")
    text, _usage = await ctx.llm.chat_with_usage(
        messages, model=model or None, max_tokens=max_tokens, request_timeout=timeout)
    return _clean(text)


async def run_generate(ctx: HandlerContext, payload: dict) -> dict:
    project_id = int(payload.get("project_id") or 0)
    if not project_id:
        raise ValueError(f"{CHANNEL}: в задаче нет project_id")

    prompt = _clean(str(payload.get("prompt") or ""), 800)
    await ctx.progress("Готовлю контекст", _clean(prompt, 80) or "сцена")

    model = _model_of(payload)
    context = await _api(ctx, "/context", {"project_id": project_id}, timeout=120)
    if not context.get("ok"):
        await _refuse(ctx, project_id, context.get("error") or "сайт не отдал контекст")

    prompt = prompt or _clean(str(context.get("prompt") or ""), 800)
    photos = _photos_of(context)
    if not prompt and not photos:
        await _refuse(ctx, project_id, "нет ни описания, ни фото")

    limits = context.get("limits") or {}
    canvas = context.get("canvas") or {}
    w = int(canvas.get("w") or 960)
    h = int(canvas.get("h") or 640)
    max_shapes = int(limits.get("max_shapes") or 120)

    system = GENERATE_SYSTEM.format(w=w, h=h, max_shapes=max_shapes)
    current = context.get("scene") if isinstance(context.get("scene"), dict) else {}
    current_shapes = current.get("shapes") if isinstance(current, dict) else []

    extra = " и по фото" if photos else ""
    if current_shapes:
        user = (
            "Это ТЕКУЩАЯ сцена проекта «"
            + _clean(str(context.get("title") or ""), 120)
            + "». Правь ЕЁ по просьбе. Не подменяй другой картинкой, "
            "если просьба не про неё.\n\nПросьба: «"
            + (prompt or "по фото")
            + "»\n\nТекущая сцена:\n"
            + json.dumps(current, ensure_ascii=False)
            + "\n\nВерни ПОЛНУЮ сцену после правки. Ответ — только JSON."
        )
    elif photos and prompt:
        user = (
            "Нарисуй плоскую иллюстрацию по фото и описанию:\n\n«"
            + prompt
            + "»\n\nПередай композицию кадра фигурами, не копируй растр. "
            "Ответ — только JSON."
        )
    elif photos:
        user = (
            "Нарисуй плоскую иллюстрацию по фото: те же объекты и цвета, "
            "но векторными фигурами (прямые, Безье, заливки). "
            "Ответ — только JSON."
        )
    else:
        user = (
            "Нарисуй плоскую иллюстрацию по описанию:\n\n«"
            + prompt
            + "»\n\nФигур должно хватать, чтобы тему узнали с первого взгляда. "
            "Ответ — только JSON."
        )

    await ctx.progress("Рисую сцену", _clean(prompt, 80) or ("по фото" + extra))

    messages = [
        {"role": "system", "content": system},
        _user_msg(user, photos),
    ]

    scene = None
    for attempt in (1, 2):
        raw = await _ask(ctx, messages, model,
                         max_tokens=_int_env("NJ668_MAX_TOKENS", 4000),
                         timeout=_int_env("NJ668_LLM_TIMEOUT", 300))
        try:
            scene = _as_scene(_parse_json(raw))
            break
        except ValueError as e:
            log.warning("nj668: попытка %s, ответ не годится: %s", attempt, e)
            if attempt == 2:
                await _refuse(ctx, project_id, str(e))
            user = user + f"\n\nПредыдущий ответ не принят: {e}. Ответь ещё раз, строго JSON."
            messages[1] = _user_msg(user, photos)

    delivered = await _api(ctx, "/scene", {
        "project_id": project_id,
        "scene": scene,
        "model": model or "",
    }, timeout=180)

    if not delivered.get("ok"):
        await _refuse(ctx, project_id, delivered.get("error") or "сайт не принял сцену")

    n = len((scene or {}).get("shapes") or [])
    await ctx.progress("Готово", f"фигур {n}")
    return {"project_id": project_id, "shapes": n, "model": model or ""}


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    try:
        return await run_generate(ctx, payload)
    except RuntimeError:
        raise
    except Exception as e:  # noqa: BLE001
        project_id = int((payload or {}).get("project_id") or 0)
        if project_id:
            try:
                await _api_soft(ctx, "/fail", {
                    "project_id": project_id,
                    "error": _clean(str(e), 400) or "модель не нарисовала сцену",
                })
            except Exception:  # noqa: BLE001
                pass
        raise

IDEAS_SYSTEM = (
    "Ты придумываешь идеи плоских векторных иллюстраций. "
    "По теме пользователя верни РОВНО 30 коротких описаний сцен. "
    "Каждое — одно-два предложения, 8–28 слов: кто, где, цвета, время суток. "
    "Без нумерации внутри строк, без markdown. Ответ — только JSON:\n"
    "{\"ideas\":[\"...\", \"...\"]}\n"
)


def _parse_ideas(text: str, need: int = 30) -> list[str]:
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
            line = re.sub(r"^\s*(?:[-*]|\d+[.)])\s*", "", line).strip().strip("«»\"\'")
            if 8 <= len(line) <= 280:
                ideas.append(line)
    out: list[str] = []
    seen: set[str] = set()
    for item in ideas:
        if isinstance(item, dict):
            item = item.get("text") or item.get("idea") or item.get("prompt") or ""
        t = _clean(str(item), 280)
        key = t.lower()
        if not t or key in seen:
            continue
        seen.add(key)
        out.append(t)
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
            f"Верни JSON с ровно {need} разными идеями иллюстраций по этой теме."
        )},
    ]
    last_error = "пусто"
    ideas: list[str] = []
    for attempt in (1, 2):
        raw = await _ask(ctx, messages, model, max_tokens=_int_env("NJ668_IDEAS_MAX_TOKENS", 2500),
                         timeout=_int_env("NJ668_LLM_TIMEOUT", 180))
        try:
            ideas = _parse_ideas(raw, need)
            break
        except ValueError as e:
            last_error = str(e)
            log.warning("nj668.ideas: попытка %s, ответ не годится: %s", attempt, e)
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


async def handle_ideas(ctx: HandlerContext, payload: dict) -> dict:
    return await run_ideas(ctx, payload)
