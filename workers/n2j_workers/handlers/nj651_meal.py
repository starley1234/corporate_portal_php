"""nj651.meal — «Калории по фото тарелки» (nj651_meal): кадр еды → блюда и БЖУ.

Конвейер (кадр с сайта, выбор модели, сжатие, разбор JSON, доставка
результата) берёт на себя общий `vlm.run_photo_job`. Здесь своё: промпт и
проверка ответа.

ПРАВИЛО КАНАЛА: модель НАЗЫВАЕТ и ВЗВЕШИВАЕТ, сайт считает. Калорийность
блюда получается из белков, жиров и углеводов (`Service_Nj651_Meal` умножает
их на 4/9/4), поэтому поле kcal модели — только подсказка: при расхождении
больше трети сайт молча предпочитает свой счёт. Просить модель «посчитать
калории» бесполезно — она называет круглые числа из памяти.

Env: VLM_MODEL (общая модель всех VLM-сервисов) или NJ651_VISION_MODEL,
VLM_MAX_TOKENS / NJ651_MAX_TOKENS, VLM_TIMEOUT / NJ651_LLM_TIMEOUT,
NJ651_API_BASE (или общий NJ120_API_BASE) и NJ651_SITE_TOKEN
(или WORKER_SITE_TOKEN / WORKER_API_TOKEN).
"""

from __future__ import annotations

import logging

from .. import vlm
from ..base import HandlerContext

log = logging.getLogger("n2j.nj651")

CHANNEL = "nj651.meal"
PREFIX = "NJ651"

SYSTEM = (
    "Ты оцениваешь еду на фотографии и возвращаешь СТРОГИЙ JSON. "
    "Твоя работа — назвать блюда, оценить вес порции и состав на 100 грамм "
    "этого блюда в его обычном приготовлении.\n"
    "Формат ответа (только объект, без пояснений и markdown):\n"
    '{"dishes":[{"name":"название блюда по-русски","grams":0,"kcal":0,'
    '"protein":0,"fat":0,"carbs":0,"confidence":0}],"note":""}\n'
    "Правила, на которых модели чаще всего ошибаются:\n"
    "1) ЯЗЫК — русский. «Куриная грудка на гриле», а не «grilled chicken».\n"
    "2) Одно блюдо — одна строка. Гарнир, мясо и салат на одной тарелке "
    "разделяй: человеку важно видеть, что именно весит больше всего.\n"
    "3) grams — вес СЪЕДОБНОЙ части порции на фото, без тарелки и костей. "
    "Опирайся на размер посуды и столовых приборов в кадре. Лучше честная "
    "грубая оценка, чем круглое число наугад.\n"
    "4) protein, fat, carbs — граммы белков, жиров и углеводов ВО ВСЕЙ порции "
    "(не на 100 г). Учитывай способ приготовления: жареное впитывает масло, "
    "салат заправлен, выпечка содержит сахар.\n"
    "5) kcal — калорийность всей порции. Не округляй её до сотен и не "
    "подгоняй: сайт пересчитает калории по БЖУ и при сильном расхождении "
    "возьмёт свой счёт.\n"
    "6) confidence — 0..100, насколько ты уверен в опознании блюда. Если "
    "видно только соус или блюдо закрыто — ставь низкое значение честно.\n"
    "7) Напитки и хлеб тоже блюда: они часто дают больше калорий, чем "
    "основное.\n"
    "8) Если еды на фото нет — верни пустой список dishes и объясни это в "
    "note одним предложением по-русски. Выдумывать блюда нельзя."
)

QUESTION = ("Что на этой тарелке? Верни JSON по формату из инструкции: "
            "блюда, вес порций и БЖУ.")

#: Потолки, за которыми цифра перестаёт быть едой: порция в 5 кг и «блюдо» на
#: 20 000 ккал — это сбой разбора, а не обед. Пропускать такое в базу нельзя:
#: сайт покажет долю дневной нормы в 900 %.
MAX_GRAMS = 5000
MAX_KCAL = 20000
MAX_MACRO = 2000


def _num(value, limit: float) -> float:
    """Неотрицательное число в разумных пределах; мусор — ноль."""
    try:
        out = float(str(value).replace(",", ".").strip())
    except (TypeError, ValueError):
        return 0.0

    if out != out or out < 0:  # NaN или отрицательное
        return 0.0

    return round(min(out, limit), 2)


def _shape(data: dict, payload: dict) -> dict:
    """Проверка ответа модели. Падает ValueError, если это не еда.

    Калории здесь не пересчитываем — это делает сайт: на странице он должен
    уметь объяснить расхождение, а не получить уже «исправленные» числа.
    """
    rows = data.get("dishes")

    if not isinstance(rows, list):
        raise ValueError("в ответе нет списка блюд")

    dishes: list[dict] = []

    for row in rows[:30]:
        if not isinstance(row, dict):
            continue

        name = str(row.get("name") or "").strip()

        if not name:
            continue

        dishes.append({
            "name": name[:120],
            "grams": _num(row.get("grams"), MAX_GRAMS),
            "kcal": _num(row.get("kcal"), MAX_KCAL),
            "protein": _num(row.get("protein"), MAX_MACRO),
            "fat": _num(row.get("fat"), MAX_MACRO),
            "carbs": _num(row.get("carbs"), MAX_MACRO),
            "confidence": int(_num(row.get("confidence"), 100)),
        })

    note = str(data.get("note") or "").strip()

    if not dishes:
        # Пустая тарелка — законный ответ сервиса, но пустая страница без
        # объяснения выглядит как поломка, поэтому подпись подставляем сами.
        note = note or "На фотографии не удалось различить еду."

    title = dishes[0]["name"] if dishes else "Еда не распознана"

    if len(dishes) > 1:
        title = f"{title} и ещё {len(dishes) - 1}"

    return {
        "dishes": dishes,
        "note": note[:300],
        # Название прогона для списка «Мои тарелки» на сайте.
        "title": title[:120],
    }


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    return await vlm.run_photo_job(
        ctx, payload,
        prefix=PREFIX,
        channel=CHANNEL,
        system=SYSTEM,
        question=QUESTION,
        shape=_shape,
        stage="Смотрю тарелку…",
        default_tokens=1400,
        default_timeout=180.0,
    )
