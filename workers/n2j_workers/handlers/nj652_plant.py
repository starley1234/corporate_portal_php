"""nj652.plant — «Растение по фото» (nj652_plant): кадр → вид, диагноз, уход.

Конвейер (кадр с сайта, выбор модели, сжатие, разбор JSON, доставка
результата) делает общий `vlm.run_photo_job`. Здесь своё: промпт и проверка
ответа.

ПРАВИЛО КАНАЛА: модель отвечает про УХОД, а не про съедобность. Вопрос «можно
ли это съесть» по фотографии неразрешим (у съедобных видов есть ядовитые
двойники), поэтому он запрещён промптом — цена ошибки несопоставима с пользой.
Календарь полива строит сайт: интервалы в днях он превращает в даты сам и
зимой растягивает их.

Env: VLM_MODEL (общая модель всех VLM-сервисов) или NJ652_VISION_MODEL,
VLM_MAX_TOKENS / NJ652_MAX_TOKENS, VLM_TIMEOUT / NJ652_LLM_TIMEOUT,
NJ652_API_BASE (или общий NJ120_API_BASE) и NJ652_SITE_TOKEN
(или WORKER_SITE_TOKEN / WORKER_API_TOKEN).
"""

from __future__ import annotations

import logging

from .. import vlm
from ..base import HandlerContext

log = logging.getLogger("n2j.nj652")

CHANNEL = "nj652.plant"
PREFIX = "NJ652"

#: Состояния, которые понимает сайт; чужое слово он сведёт к "unknown".
STATES = ("ok", "warning", "problem", "unknown")

SYSTEM = (
    "Ты определяешь растение по фотографии и возвращаешь СТРОГИЙ JSON. "
    "Твоя работа — назвать вид, сказать, что с растением не так, и дать "
    "практичный уход.\n"
    "Формат ответа (только объект, без пояснений и markdown):\n"
    '{"plant":{"name_ru":"","name_lat":"","family":"","confidence":0},'
    '"condition":{"state":"ok|warning|problem|unknown","problem":"",'
    '"causes":[""],"severity":0},'
    '"care":{"light":"","water_summer_days":0,"water_winter_days":0,'
    '"temp":"","humidity":"","soil":"","repot":"","feed_days":0,'
    '"toxic_pets":false},"actions":[""],"note":""}\n'
    "Правила, на которых модели чаще всего ошибаются:\n"
    "1) ЯЗЫК описаний — русский, name_lat — латынь.\n"
    "2) confidence — 0..100. Если по кадру вид не отличить от родственного, "
    "ставь низкое значение и скажи об этом в note. Уверенный ответ наугад "
    "хуже честного «не знаю»: человек будет лечить не то растение.\n"
    "3) state: ok — видимых проблем нет; warning — есть тревожные признаки; "
    "problem — явная болезнь, вредитель или серьёзная ошибка ухода; "
    "unknown — по фото не понять.\n"
    "4) problem — что именно видно НА ЭТОМ фото (жёлтые нижние листья, "
    "коричневые сухие кончики, белый налёт, паутина), без общих слов. "
    "causes — 2–4 вероятные причины, самая вероятная первой.\n"
    "5) severity: 0 — ничего делать не надо, 1 — поправить уходом, "
    "2 — вмешаться на этой неделе, 3 — спасать срочно.\n"
    "6) water_summer_days, water_winter_days, feed_days — ЧИСЛО ДНЕЙ между "
    "процедурами для комнатных условий. Зимний интервал больше летнего. "
    "Даты не называй: календарь построит сайт.\n"
    "7) actions — 3–5 коротких действий в повелительном наклонении, по "
    "порядку: сначала то, что делают сегодня.\n"
    "8) toxic_pets — true, если растение считается ядовитым для кошек и "
    "собак.\n"
    "9) НЕ отвечай на вопросы о съедобности растений и грибов — по фото "
    "это определять нельзя. Если на кадре гриб или дикая ягода, поставь "
    "state=unknown и напиши об этом в note.\n"
    "10) Если растения на фото нет — пустой name_ru, state=unknown и "
    "объяснение в note одним предложением."
)

QUESTION = ("Что это за растение и что с ним не так? Верни JSON по формату "
            "из инструкции.")


def _days(value, limit: int) -> int:
    """Интервал ухода в днях; мусор и «раз в год» отсекаем нулём."""
    try:
        out = int(round(float(str(value).replace(",", ".").strip())))
    except (TypeError, ValueError):
        return 0

    if out < 1 or out > limit:
        return 0

    return out


def _strings(value, limit: int, max_len: int) -> list:
    """Список непустых строк без дублей — модель любит повторять причины."""
    if not isinstance(value, list):
        return []

    out: list[str] = []

    for item in value:
        text = str(item or "").strip()

        if text and text not in out:
            out.append(text[:max_len])

        if len(out) >= limit:
            break

    return out


def _shape(data: dict, payload: dict) -> dict:
    """Проверка ответа модели. Падает ValueError, если это не разбор растения."""
    plant = data.get("plant")
    care = data.get("care")

    if not isinstance(plant, dict) or not isinstance(care, dict):
        raise ValueError("в ответе нет разделов plant и care")

    condition = data.get("condition") if isinstance(data.get("condition"), dict) else {}

    name_ru = str(plant.get("name_ru") or "").strip()
    state = str(condition.get("state") or "unknown").strip().lower()

    if state not in STATES:
        state = "unknown"

    try:
        confidence = int(round(float(plant.get("confidence") or 0)))
    except (TypeError, ValueError):
        confidence = 0

    try:
        severity = int(round(float(condition.get("severity") or 0)))
    except (TypeError, ValueError):
        severity = 0

    return {
        "plant": {
            "name_ru": name_ru[:120],
            "name_lat": str(plant.get("name_lat") or "").strip()[:120],
            "family": str(plant.get("family") or "").strip()[:80],
            "confidence": max(0, min(100, confidence)),
        },
        "condition": {
            "state": state,
            "problem": str(condition.get("problem") or "").strip()[:300],
            "causes": _strings(condition.get("causes"), 6, 160),
            "severity": max(0, min(3, severity)),
        },
        "care": {
            "light": str(care.get("light") or "").strip()[:160],
            "water_summer_days": _days(care.get("water_summer_days"), 30),
            "water_winter_days": _days(care.get("water_winter_days"), 60),
            "feed_days": _days(care.get("feed_days"), 120),
            "temp": str(care.get("temp") or "").strip()[:80],
            "humidity": str(care.get("humidity") or "").strip()[:80],
            "soil": str(care.get("soil") or "").strip()[:160],
            "repot": str(care.get("repot") or "").strip()[:160],
            "toxic_pets": bool(care.get("toxic_pets")),
        },
        "actions": _strings(data.get("actions"), 6, 200),
        "note": str(data.get("note") or "").strip()[:300],
        # Название прогона для списка «Мои растения» на сайте.
        "title": (name_ru or "Растение не определено")[:120],
    }


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    return await vlm.run_photo_job(
        ctx, payload,
        prefix=PREFIX,
        channel=CHANNEL,
        system=SYSTEM,
        question=QUESTION,
        shape=_shape,
        stage="Рассматриваю растение…",
        default_tokens=1600,
        default_timeout=180.0,
    )
