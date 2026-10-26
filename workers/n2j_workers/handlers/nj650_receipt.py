"""nj650.receipt — «Чек по фото» (nj650_receipt): кадр чека → строки и суммы.

Весь конвейер (кадр с сайта, выбор модели, сжатие, разбор JSON, доставка
результата) делает общий `vlm.run_photo_job`. Здесь своё: промпт и проверка
ответа.

ГЛАВНОЕ ПРАВИЛО ЭТОГО КАНАЛА: модель ЧИТАЕТ, а не считает. Суммы, сверку с
итогом и дележ считает сайт (`Service_Nj650_Receipt`) — модель охотно
«подтверждает» любой итог, который ей подсказали, поэтому её арифметику мы не
используем вовсе, а итог чека просим переписать отдельным полем ровно так,
как он напечатан.

Env: VLM_MODEL (общая модель всех VLM-сервисов) или NJ650_VISION_MODEL,
VLM_MAX_TOKENS / NJ650_MAX_TOKENS, VLM_TIMEOUT / NJ650_LLM_TIMEOUT,
NJ650_API_BASE (или общий NJ120_API_BASE) и NJ650_SITE_TOKEN
(или WORKER_SITE_TOKEN / WORKER_API_TOKEN).
"""

from __future__ import annotations

import logging

from .. import vlm
from ..base import HandlerContext

log = logging.getLogger("n2j.nj650")

CHANNEL = "nj650.receipt"
PREFIX = "NJ650"

#: Категории, которые понимает сайт. Чужое слово он всё равно сведёт к «прочее»,
#: но просить модель выбирать из списка дешевле, чем потом угадывать.
CATEGORIES = ("продукты", "готовое", "напитки", "алкоголь", "бытовое", "аптека",
              "транспорт", "одежда", "техника", "услуги", "прочее")

SYSTEM = (
    "Ты читаешь кассовый чек по фотографии и возвращаешь СТРОГИЙ JSON. "
    "Твоя работа — переписать то, что напечатано, и ничего не досочинять.\n"
    "Формат ответа (только объект, без пояснений и markdown):\n"
    '{"merchant":"название магазина как на чеке","date":"ГГГГ-ММ-ДД",'
    '"currency":"RUB","items":[{"name":"строка товара","qty":1,"price":0,'
    '"sum":0,"category":"одно слово из списка"}],"total":0,"note":""}\n'
    "Правила, на которых модели чаще всего ошибаются:\n"
    "1) ЯЗЫК — язык чека. Русскую строку оставляй кириллицей, не переводи и "
    "не транслитерируй: человек сверяет список с бумажкой глазами.\n"
    "2) Каждую строку товара пиши ОДИН раз и в порядке чека. Не объединяй "
    "одинаковые названия и не раскрывай сокращения («Мол. ультрапаст.» так и "
    "оставь).\n"
    "3) qty — количество (для весового товара дробное, например 0.482), "
    "price — цена за единицу, sum — сумма строки. Если на чеке есть только "
    "сумма, ставь sum, а price оставь 0: сайт посчитает сам. НЕ СКЛАДЫВАЙ "
    "строки и не проверяй итог — арифметика не твоя задача.\n"
    "4) total — итог, НАПЕЧАТАННЫЙ на чеке (строка «ИТОГ», «ИТОГО», «К оплате»). "
    "Не считай его сам. Если итога не видно — 0.\n"
    "5) Скидки, бонусы и возвраты отдельной строкой сохраняй как есть, с "
    "отрицательной суммой.\n"
    "6) Служебные строки (ИНН, кассир, смена, номер чека, адрес, QR) в items "
    "НЕ попадают.\n"
    "7) category — одно слово из списка: " + ", ".join(CATEGORIES) + ".\n"
    "8) Нечитаемую строку пропусти и честно скажи об этом в note по-русски "
    "(одним предложением). Выдумывать товары нельзя ни при каких условиях."
)

QUESTION = ("Прочитай этот чек и верни JSON по формату из инструкции. "
            "Только то, что напечатано на фото.")


def _shape(data: dict, payload: dict) -> dict:
    """Проверка ответа модели. Падает ValueError, если это не чек.

    Арифметику не трогаем: суммы, сверку и дележ считает сайт. Здесь только
    отсечение мусора и приведение к простым типам, чтобы в jsonb не уехали
    ни None, ни вложенные объекты.
    """
    rows = data.get("items")

    if not isinstance(rows, list) or not rows:
        raise ValueError("в ответе нет ни одной строки чека")

    items: list[dict] = []

    for row in rows[:150]:
        if not isinstance(row, dict):
            continue

        name = str(row.get("name") or "").strip()

        if not name:
            continue

        items.append({
            "name": name[:120],
            "qty": row.get("qty", 0),
            "price": row.get("price", 0),
            "sum": row.get("sum", 0),
            "category": str(row.get("category") or "прочее").strip().lower()[:20],
        })

    if not items:
        raise ValueError("строки чека пустые")

    return {
        "merchant": str(data.get("merchant") or "").strip()[:120],
        "date": str(data.get("date") or "").strip()[:20],
        "currency": str(data.get("currency") or "RUB").strip()[:8],
        "items": items,
        "total": data.get("total", 0),
        "note": str(data.get("note") or "").strip()[:300],
        # Название прогона для списка «Мои чеки» на сайте.
        "title": (str(data.get("merchant") or "").strip() or "Чек")[:120],
    }


async def handle(ctx: HandlerContext, payload: dict) -> dict:
    return await vlm.run_photo_job(
        ctx, payload,
        prefix=PREFIX,
        channel=CHANNEL,
        system=SYSTEM,
        question=QUESTION,
        shape=_shape,
        stage="Читаю чек…",
        default_tokens=1600,
        default_timeout=180.0,
    )
