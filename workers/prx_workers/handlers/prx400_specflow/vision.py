"""Vision-режим prx400: рендер страниц PDF (pdftoppm) + multimodal LLM → строки.

Режимы (payload parse_mode):
- ocr        — детерминированный текстовый разбор (по умолчанию, без LLM);
- ocr_vision — OCR-разбор, при пустом результате — vision-фолбэк;
- vision     — сразу vision-разбор (сканы без текстового слоя, «результат 0» у tesseract).

LLM-инфраструктура общая (LOCAL_API_URL/LOCAL_API_KEY/DEFAULT_LLM_MODEL, как
в prx397). Строки — та же нормализованная схема, что у текстового парсера:
pos/section/raw_name/code_doc/qty/unit/unit_price/total_price → math/matching/HITL.
"""
from __future__ import annotations

import asyncio
import base64
import logging
from pathlib import Path

from langchain_core.messages import HumanMessage, SystemMessage
from pydantic import BaseModel, Field

from ...base import PermanentJobError
from ...langchain_llm import arun_structured_messages
from .limits import limits

log = logging.getLogger("prx.specflow")


class _Detect(BaseModel):
    """Выбор шаблона и реквизиты по первой странице."""

    template_id: str | None = Field(default=None, description="template_id из списка, лучше всего подходящий")
    spec_number: str | None = Field(default=None, description="Обозначение документа (например КБ20.01.00.00)")
    department: str | None = Field(default=None, description="Разработал/подразделение, если видно")


class _Row(BaseModel):
    pos: int | None = Field(default=None, description="Позиция (графа «Поз.»)")
    section: str = Field(default="", description="Раздел: Документация, Сборочные единицы, Детали, Стандартные изделия…")
    raw_name: str = Field(default="", description="Наименование и техническая характеристика")
    code_doc: str = Field(default="", description="Обозначение/децимальный номер/тип/марка (например КБ20.01.02.01, В5-1-22)")
    qty: float | None = Field(default=None, description="Количество")
    unit: str = Field(default="", description="Единица измерения (шт., компл. …)")
    unit_price: float | None = Field(default=None, description="Цена за единицу, если есть")
    total_price: float | None = Field(default=None, description="Сумма, если есть")


class _Page(BaseModel):
    rows: list[_Row] = Field(default_factory=list)


async def _render_page(path: Path, page: int, prefix: str) -> bytes:
    """Одна страница → PNG (тот же pdftoppm, что у локального OCR)."""
    from ...ocr.proc import run

    stem = path.parent / f"{prefix}_{page}"
    image = stem.with_suffix(".png")
    try:
        rc, _ = await run("pdftoppm", "-f", str(page), "-l", str(page), "-singlefile",
                          "-scale-to", "1600", "-png", str(path), str(stem), timeout=30)
        if rc or not image.is_file():
            raise PermanentJobError("vision_unavailable")
        return image.read_bytes()
    except FileNotFoundError:
        raise PermanentJobError("ocr_unavailable") from None
    finally:
        image.unlink(missing_ok=True)


def _messages(image: bytes, prompt: str, system: str) -> list:
    b64 = base64.b64encode(image).decode("ascii")
    return [
        SystemMessage(content=system),
        HumanMessage(content=[
            {"type": "text", "text": prompt},
            {"type": "image_url", "image_url": {"url": f"data:image/png;base64,{b64}"}},
        ]),
    ]


def _columns_hint(tpl: dict | None) -> str:
    """Синонимы колонок выбранного шаблона — подсказка LLM."""
    columns = ((tpl or {}).get("table_rules") or {}).get("columns") or {}
    titles = {
        "pos": "Поз.", "item_name": "Наименование", "code_doc": "Обозначение/Тип, марка",
        "qty": "Кол.", "unit": "Ед. изм.", "unit_price": "Цена", "total_price": "Сумма",
    }
    parts = []
    for key, meta in columns.items():
        syn = ", ".join((meta or {}).get("synonyms") or [])
        parts.append(f"{key} ({titles.get(key, key)}{'; ' + syn if syn else ''})")
    return "Колонки таблицы: " + ("; ".join(parts) if parts else "Поз.; Наименование; Обозначение; Кол.; Ед. изм.")


_SYSTEM = (
    "Ты извлекаешь строки из отсканированных спецификаций (ЕСКД/ведомости ОРУ). "
    "Возвращай ТОЛЬКО фактические строки позиций с заполненными графами; шапки, "
    "заголовки разделов (Документация, Сборочные единицы, Детали, Стандартные изделия, "
    "Оборудование), итоговые строки и прочерки — не возвращай. Наименование — "
    "полностью, включая многострочные и продолжения («- высоковольтный выключатель…»). "
    "Числа — без пробелов и единиц. Если строк на странице нет — верни пустой список."
)


async def detect(path: Path, pages: int, templates: list[dict], lim: dict) -> dict:
    """Выбор шаблона + реквизиты по первой странице. {'template_id','spec_number','department'}."""
    image = await _render_page(path, 1, "prx400_v")
    titles = "; ".join(f"{t.get('template_id')}: {t.get('template_name')}" for t in (templates or []))
    anchors = "; ".join(
        f"{t.get('template_id')} ← {', '.join((t.get('detection_policy') or {}).get('anchor_keywords') or [])}"
        for t in (templates or []))
    prompt = (
        f"Документ — спецификация. Доступные шаблоны (id: название ← якоря): {anchors or titles}. "
        "Выбери template_id по графам таблицы и заголовку. Верни также обозначение документа "
        "(штамп, например КБ20.01.00.00) и подразделение, если видно."
    )
    try:
        parsed, _ = await arun_structured_messages(_Detect, _messages(image, prompt, _SYSTEM), timeout=180)
    except RuntimeError as e:  # LOCAL_API_URL/KEY/MODEL не заданы
        log.error("specflow vision detect: config=%s", e)
        raise PermanentJobError("vision_config") from None
    except Exception as e:  # noqa: BLE001 — сеть/модель/мультимодальность
        log.error("specflow vision detect failed: %s", type(e).__name__)
        raise PermanentJobError("vision_unavailable") from None
    return {
        "template_id": (parsed.template_id or "").strip() or None,
        "spec_number": (parsed.spec_number or "").strip() or None,
        "department": (parsed.department or "").strip() or None,
    }


async def extract(path: Path, pages: int, tpl: dict | None, lim: dict, progress) -> dict:
    """Строки со всех страниц (vision). Возвращает {rows, spec_number, department, warnings}."""
    cap = min(max(1, pages), lim["max_vision_pages"])
    if pages > cap:
        log.warning("specflow vision: страниц %d больше лимита %d — обрезано", pages, cap)
    rows: list[dict] = []
    warnings: list[str] = []
    hint = _columns_hint(tpl)
    for page in range(1, cap + 1):
        await progress("vision_page", f"{page}/{cap}")
        image = await _render_page(path, page, "prx400_v")
        prompt = (
            f"Страница {page} из {pages} спецификации. {hint}. "
            "Извлеки строки позиций (поля pos/section/raw_name/code_doc/qty/unit/unit_price/total_price). "
            "Раздел (section) неси из заголовков страницы; продолжение многострочного наименования — "
            "в raw_name той же строки."
        )
        try:
            parsed, _ = await arun_structured_messages(_Page, _messages(image, prompt, _SYSTEM), timeout=300)
        except RuntimeError as e:
            log.error("specflow vision page=%d: config=%s", page, e)
            raise PermanentJobError("vision_config") from None
        except Exception as e:  # noqa: BLE001
            log.error("specflow vision page=%d failed: %s", page, type(e).__name__)
            warnings.append(f"page_{page}_failed")
            continue
        for r in parsed.rows:
            rows.append({
                "pos": r.pos, "section": (r.section or "").strip()[:255],
                "raw_name": (r.raw_name or "").strip()[:4000],
                "code_doc": (r.code_doc or "").strip()[:255],
                "qty": r.qty, "unit": (r.unit or "").strip()[:16],
                "unit_price": r.unit_price, "total_price": r.total_price,
                "flags": [],
            })
        await asyncio.sleep(0)
    return {"rows": rows[: lim["max_rows"]], "spec_number": None, "department": None, "warnings": warnings}
