"""nj643_cad: «CAD-детали» — 3D-модель по описанию.

Конвейер (канал `nj643.cad`):

  1. ПЕРВЫЙ прогон LLM — только КОД: параметрическая модель на build123d
     (функция `build_model(**kwargs)`). Владелец (28.09.2026): «делать код одним
     прогоном, чтобы качественнее ответ был» — модель не тратит силы на схему,
     название и замечания, а пишет геометрию;
  2. ВТОРОЙ прогон — МЕТАИНФОРМАЦИЯ по готовому коду: схема параметров
     (min/max/default/label), название, назначение, замечания. Идёт на своей
     модели — см. NJ643_META_MODEL (пусто → модель модуля NJ643_MODEL) и не
     критичен: не ответит — схема собирается из кода, деталь не теряется;
  3. код проверяется у нас (безопасность: только build123d/math, без файлов,
     сети и eval; плюс разбор через ast: синтаксис, алгебра вместо builder-режима,
     наличие return, отсутствие кода на уровне импорта). Схему выравниваем по коду,
     чтобы ползунок на странице и деталь не разъезжались;
  4. внешний headless-движок выполняет `POST /v1/cad/verify-and-build`:
     компиляция, проверка геометрии, стресс-тест границ параметров и экспорт
     STEP / STL / GLB / ракурсов;
  5. если движок отклонил код или геометрия не собралась — просим LLM починить
     код (до NJ643_FIX_ATTEMPTS раз): модель получает адрес ошибки из трассировки,
     крайние значения параметров, геометрию и запрет упрощать деталь. Собралось,
     но min/max ломают модель — одна автоматическая починка; предыдущую удачную
     сборку храним и не затираем неудачной правкой;
  6. файлы уезжают на сайт (`/cad/api/upload`), итог — в `/cad/api/result`.

Формат ответа движка в контракте не зафиксирован (схема ответа = object),
поэтому разбор ответа терпимый: и base64-поля, и ссылки, и вложенные списки
файлов; вид файла определяется по имени/ключу и по «магическим» байтам.

Env (workers/.env):
    NJ643_API_BASE      — база API сайта, напр. https://n2j.ru/cad/api
                          (пусто — выводится из других адресов сайта)
    NJ643_SITE_TOKEN    — токен X-Worker-Token (пусто = WORKER_SITE_TOKEN/WORKER_API_TOKEN)
    NJ643_CAD_API       — адрес движка (обязателен; известен только воркеру и
                          нигде не выводится — ни в подсказках, ни на сайте)
    NJ643_CAD_API_KEY   — ключ движка (пусто = TOOLFORGE_API_KEY)
    NJ643_MODEL         — модель для кода («провайдер::модель»)
    NJ643_META_MODEL    — модель для метаинформации (схема, название, замечания);
                          пусто — та же, что NJ643_MODEL
    NJ643_META          — 0 выключает второй прогон (схему соберём из кода)
    NJ643_FIX_ATTEMPTS  — сколько раз просить ИИ починить код (по умолчанию 2)
    NJ643_TIMEOUT       — таймаут одного запроса к движку, сек (по умолчанию 600)
    NJ643_BUDGET        — общий бюджет прогона, сек (по умолчанию 2100)
    NJ643_MAX_CODE      — потолок размера кода, знаков (по умолчанию 60000)
"""

from __future__ import annotations

import ast
import asyncio
import base64
import binascii
import hashlib
import json
import logging
import os
import re
import time
from urllib.parse import urlparse

import httpx

from .. import site

log = logging.getLogger("n2j.nj643")

_PREFIX = "NJ643"
CHANNEL = "nj643.cad"

# Имя переменной окружения с адресом движка: печатать его можно, адрес — нет.
ENGINE_SETTING = "NJ643_CAD_API"
ENGINE_WORD = "внешний CAD-движок"

DEFAULT_TIMEOUT = 600.0
DEFAULT_MAX_CODE = 60_000
MAX_ARTIFACT_BYTES = 200 * 1024 * 1024      # суммарный потолок файлов с одного прогона
MAX_ARTIFACTS = 40                          # и по количеству — движок не бесконечный
MAX_FILE_BYTES = 40 * 1024 * 1024           # один файл (сайт принимает до 60 МБ base64)


# ---------------------------------------------------------------------------
# Настройки
# ---------------------------------------------------------------------------

def _api_base() -> str:
    """База API сайта. Если NJ643_API_BASE нет — выводим из другого адреса сайта."""
    base = (os.getenv(_PREFIX + "_API_BASE") or "").rstrip("/")

    if base:
        return base

    for name in ("NJ642_VACANCIES_API_BASE", "NJ641_API_BASE", "NJ591_PROFILE_API_BASE",
                 "NJ605_RADAR_API_BASE", _PREFIX + "_API_BASE", "WORKER_API_BASE", "WORKER_SITE_BASE"):
        value = (os.getenv(name) or "").strip()

        if not value:
            continue

        parts = urlparse(value)

        if parts.scheme and parts.netloc:
            base = f"{parts.scheme}://{parts.netloc}/cad/api"
            log.info("nj643: %s_API_BASE не задан — беру базу сайта из %s: %s", _PREFIX, name, base)
            os.environ[_PREFIX + "_API_BASE"] = base
            return base

    return ""


class CadBuildError(RuntimeError):
    """Финал сборки, при котором сделанное НЕ выбрасываем.

    Даже если движок недоступен, код модели и схема параметров уже готовы и
    оплачены токенами. Они уедут на сайт вместе с ошибкой: владелец увидит код
    и сможет нажать «Собрать заново», когда связь с движком починится.
    """

    def __init__(self, message: str, *, code: str = "", schema_definition: dict | None = None,
                 params: dict | None = None, report: dict | None = None, attempts: list | None = None):
        super().__init__(message)
        self.code = code
        self.schema_definition = schema_definition or {}
        self.params = params or {}
        self.report = report or {}
        self.attempts = attempts or []


class EngineUnreachable(str):
    """Причина недоступности движка — и слово-признак для вызывающего кода.

    Наследник str: старые проверки `isinstance(data, str)` и текст на сайте
    работают как раньше, но сетевую беду теперь можно отличить от отказа движка
    с кодом (401/422/500): при недоступности движка просить LLM «починить код»
    бессмысленно — код тут ни при чём.
    """


def is_unreachable(value) -> bool:
    return isinstance(value, EngineUnreachable)


def _engine_url() -> str:
    """Адрес движка — только из окружения воркера.

    Владелец (27.09.2026): «не пиши нигде url движка (в воркере тоже не
    передавай)». Поэтому здесь нет адреса по умолчанию: он живёт лишь в
    workers/.env на сервере воркера, а наружу (в подсказках об ошибке и в
    прогрессе для сайта) уходит безличное «внешний CAD-движок».
    """
    return (os.getenv(ENGINE_SETTING) or "").strip().rstrip("/")


def _engine_headers() -> dict:
    key = (os.getenv("NJ643_CAD_API_KEY") or os.getenv("TOOLFORGE_API_KEY") or "").strip()

    if not key:
        return {}

    return {"X-API-Key": key}


def _engine_key_set() -> bool:
    return bool((os.getenv("NJ643_CAD_API_KEY") or os.getenv("TOOLFORGE_API_KEY") or "").strip())


def _timeout() -> float:
    try:
        return float(os.getenv("NJ643_TIMEOUT", str(DEFAULT_TIMEOUT)))
    except ValueError:
        return DEFAULT_TIMEOUT


def _fix_attempts() -> int:
    try:
        return max(0, min(int(os.getenv("NJ643_FIX_ATTEMPTS", "2")), 4))
    except ValueError:
        return 2


def _budget() -> float:
    """Общий бюджет прогона: сколько всего секунд даём на LLM + движок."""
    try:
        return max(300.0, float(os.getenv("NJ643_BUDGET", "2100")))
    except ValueError:
        return 2100.0


def _max_code() -> int:
    try:
        return max(2000, int(os.getenv("NJ643_MAX_CODE", str(DEFAULT_MAX_CODE))))
    except ValueError:
        return DEFAULT_MAX_CODE


def _model() -> str:
    return (os.getenv("NJ643_MODEL") or "").strip()


def _engine_retries() -> int:
    """Сколько раз стучаться в движок при сетевой ошибке (NJ643_CAD_RETRIES=3)."""
    try:
        return max(1, min(6, int(os.getenv("NJ643_CAD_RETRIES", "3"))))
    except ValueError:
        return 3


def _retry_delay(attempt: int) -> float:
    """Пауза перед повтором: 2, 4, дальше по 6 с (NJ643_CAD_RETRY_DELAY — база)."""
    try:
        base = max(0.0, float(os.getenv("NJ643_CAD_RETRY_DELAY", "2")))
    except ValueError:
        base = 2.0

    return base * attempt * (1 if attempt < 3 else 3)


def _engine_proxy() -> str | None:
    """Прокси для запросов к движку (NJ643_CAD_PROXY) — если IP воркера не в белом списке."""
    return (os.getenv("NJ643_CAD_PROXY") or "").strip() or None


def _engine_verify():
    """Проверка сертификата: свой CA (NJ643_CAD_CA_BUNDLE) или отключение (NJ643_CAD_INSECURE=1)."""
    bundle = (os.getenv("NJ643_CAD_CA_BUNDLE") or "").strip()

    if bundle:
        return bundle

    if str(os.getenv("NJ643_CAD_INSECURE") or "").strip().lower() in ("1", "true", "yes", "on"):
        return False

    return True


def _health_timeout() -> float:
    try:
        return max(3.0, float(os.getenv("NJ643_HEALTH_TIMEOUT", "15")))
    except ValueError:
        return 15.0


def _preflight_enabled() -> bool:
    return str(os.getenv("NJ643_PREFLIGHT") or "1").strip().lower() not in ("0", "false", "no", "off")


def _exc_chain(e: BaseException) -> str:
    """Полная цепочка причин: httpx часто прячет настоящую беду в __cause__."""
    parts: list[str] = []
    seen: set[int] = set()
    cur: BaseException | None = e

    while cur is not None and id(cur) not in seen:
        seen.add(id(cur))
        text = f"{type(cur).__name__}: {cur}".strip()

        if text and text not in parts:
            parts.append(text)

        cur = cur.__cause__ or cur.__context__

    return " ← ".join(parts)


def _net_hint(chain: str) -> str:
    """Что делать по тексту сетевой ошибки (владелец: «что не так?»)."""
    low = chain.lower()

    if ("certificate_verify" in low or "certificate verify failed" in low
            or "self-signed" in low or "self signed" in low or "hostname mismatch" in low):
        return ("сертификат движка не проверяется этим сервером. Если он самоподписанный — сохраните его "
                "в файл и укажите NJ643_CAD_CA_BUNDLE=/путь/ca.pem (временно можно NJ643_CAD_INSECURE=1)")

    if ("eof" in low or "closed abruptly" in low or "connection reset" in low
            or "server disconnected" in low or "broken pipe" in low):
        return ("соединение закрыто на рукопожатии TLS. Так обычно отвечает движок, когда адрес воркера "
                "не в его белом списке, либо соединение режет фильтр на стороне сервера. Проверьте связь с "
                "движком с сервера воркера (curl -v по адресу из " + ENGINE_SETTING + "): если ошибка та же, "
                "попросите добавить IP воркера в разрешённые у движка либо задайте NJ643_CAD_PROXY")

    if ("gaierror" in low or "name or service not known" in low or "nodename nor servname" in low
            or "temporary failure in name resolution" in low or "getaddrinfo" in low):
        return ("домен движка не разрешается в IP: проверьте DNS на сервере воркера "
                "(getent hosts по адресу из " + ENGINE_SETTING + ")")

    if "connection refused" in low:
        return "соединение отвергается: порт 443 закрыт или сервис движка не слушает (проверьте firewall)"

    if "timed out" in low or "timeout" in low:
        return ("движок не ответил за отведённое время. Он может быть перегружен или запрос режется "
                "фильтром — проверьте связь с движком с сервера воркера")

    return ("проверьте связь с движком с сервера воркера (адрес — в " + ENGINE_SETTING + ")")


def _free_path(url: str) -> str:
    """Путь запроса без хоста: адрес движка наружу не отдаём (владелец, 27.09)."""
    tail = url.split("//", 1)[-1]
    slash = tail.find("/")

    return tail[slash:] if slash >= 0 else "/"


def _net_reason(e: BaseException, url: str, attempts: int) -> str:
    """Человеческий текст про недоступность движка + подсказка + ручная проверка."""
    chain = _exc_chain(e)
    tries = f" Повторял {attempts} раза — без изменений." if attempts > 1 else ""

    return (f"Движок недоступен ({_free_path(url)}): {chain}.{tries} "
            "Это НЕ ошибка кода модели — просить ИИ «починить код» здесь нечего. "
            f"Что делать: {_net_hint(chain)}.")


# ---------------------------------------------------------------------------
# Обмен с сайтом
# ---------------------------------------------------------------------------

async def _api_post(path: str, data: dict, retries: int = 2) -> tuple[bool, str, dict]:
    """POST на сайт (JSON-тело: сайт читает request body)."""
    base = _api_base()

    if not base:
        return False, f"{_PREFIX}_API_BASE не задан в workers/.env", {}

    return await site.post_json(_PREFIX, path, data, retries=retries, json_body=True)


def _api_unset(reason: str) -> bool:
    return "_API_BASE не задан" in reason or "токен сайта не задан" in reason


async def _upload(ctx, part_id: int, revision_id: int, kind: str, name: str,
                  raw: bytes, view: str = "", meta: dict | None = None) -> bool:
    """Файл модели на сайт. Большие файлы режем по MAX_FILE_BYTES (STEP бывает тяжёлым)."""
    if not raw:
        return False

    if len(raw) > MAX_FILE_BYTES:
        log.warning("nj643: файл %s (%d байт) больше потолка — не отправляю", name, len(raw))
        return False

    ok, reason, _j = await _api_post("/upload", {
        "part_id": str(part_id),
        "revision_id": str(revision_id),
        "kind": kind,
        "name": name,
        "view": view,
        "b64": base64.b64encode(raw).decode(),
        "meta": meta or {},
    }, retries=(1 if len(raw) > 12 * 1024 * 1024 else 2))

    if not ok and not _api_unset(reason):
        log.warning("nj643: файл %s не принят сайтом: %s", name, reason)

    return ok


# ---------------------------------------------------------------------------
# LLM: параметрический код build123d
# ---------------------------------------------------------------------------

SYSTEM_CODE = """
Ты — инженер-конструктор и программист CAD. По описанию пользователя ты пишешь
параметрическую 3D-модель на Python для библиотеки build123d. Движок ИСПОЛНЯЕТ твой код
как есть, без правок, поэтому он обязан работать с первого раза и строить годную деталь.
Владелец сервиса просит: «модель должна стараться и делать максимально хорошие конструкции».

РЕЖИМ РАБОТЫ — ТОЛЬКО АЛГЕБРА (объекты и операторы). Никаких builder-контекстов
(BuildPart/BuildSketch/BuildLine/Hole/CounterBoreHole) и никакого Mode.*: он существует
только внутри `with BuildPart(...)`. Это проверено на живом build123d: в алгебре
`mode=Mode.SUBTRACT` НЕ падает и НЕ вычитает — он молча игнорируется, и деталь получится
не той, которую ты описал. Вычитание в алгебре — только `part -= hole`; `Hole(...)` вне
контекста падает с «No depth provided».

РАЗРЕШЕНО: `from build123d import *`, `import math`, `from math import *`.
Ровно одна функция верхнего уровня `def build_model(**kwargs):`, которая ВОЗВРАЩАЕТ деталь
(Part/Solid/Compound) — ОДНО цельное тело. На уровне модуля — только импорты, вспомогательные
функции и числовые константы: ничего, что выполняется при импорте.

ЖЁСТКИЕ ПРАВИЛА КОДА:
1. Все ключевые размеры — параметры. Читай их ТОЛЬКО так:
   w = float(kwargs.get("width", 60)) — имя параметра, значение по умолчанию.
   Значение по умолчанию — обязательно явное число из описания: по нему соберут
   схему параметров (ползунки) и оно станет размером детали. Выражение вместо
   числа (`w / 2`, `None`) ползунком не выставить.
2. Единицы — миллиметры. Деталь реалистична: стенки не тоньше 1.5 мм, фаски и скругления
   только там, где они уместны и не ломают тело.
3. Модель обязана строиться при ЛЮБЫХ разумных значениях параметров — включая будущие
   границы ползунков (min и max, их соберут по твоему коду): связи размеров держи через
   min()/max(), не допускай вырожденной геометрии.
4. После булевых операций проверяй результат по объёму объекта (obj.volume), чтобы не
   получить пустое тело; деталь должна остаться ОДНИМ телом.

ШПАРГАЛКА — эти формы проверены движком, пиши ими:
* Тела: Box(l, w, h), Cylinder(radius, height), Sphere(radius), Cone(bottom_r, top_r, height),
  Torus(major_r, minor_r). Низ детали на Z=0 —
  `Box(l, w, h, align=(Align.CENTER, Align.CENTER, Align.MIN))` или `Pos(0, 0, h / 2) * Box(l, w, h)`.
* Положение и поворот: `Pos(x, y, z) * shape`, `Rot(rx, ry, rz) * shape`,
  `Plane.XY.offset(z) * shape`, вокруг оси — `Rot(0, 0, angle) * Pos(r, 0, 0) * shape`.
* Булевы операции: `part = part + boss`, `part = part - hole`, `part = part & other`.
  Вычитать список можно (`part -= [a, b]`), но цикл надёжнее и понятнее.
* Эскиз и тело из него: `sk = Circle(r) + Rectangle(w, h)` (ещё RegularPolygon(r, 6),
  SlotOverall(w, h)), затем `solid = extrude(sk, amount=h)`. Эскизы живут в плоскости XY.
* Тело вращения — ТОЛЬКО с профилем в плоскости XZ, отступленным от оси:
  `ring = revolve(Plane.XZ * Pos(r, 0) * Rectangle(w, h), Axis.Z)` (проверено: объём 2π·r·w·h).
  Профиль в XY-плоскости или касающийся оси даёт ПУСТОЕ тело (объём 0) либо
  «BRep_API: command not done» — так писать нельзя. Тела вращения проще и надёжнее
  собирать из Cylinder/Cone/Torus и вычитаний. Функции `max_fillet` в build123d нет.
* Скругления и фаски — ТОЛЬКО в самом конце, после всех булевых операций:
  `part = fillet(part.edges().filter_by(Axis.Z), radius=1)`;
  `part = chamfer(part.faces().sort_by(Axis.Z)[-1].edges(), length=0.5)`;
  `part = fillet(part.edges().group_by(Axis.Z)[0], radius=2)`.
* Выбор элементов: `.faces()`, `.edges()`, `.vertices()`, дальше `.filter_by(Axis.Z)`
  (параллельные оси), `.filter_by(GeomType.LINE)`, `.filter_by(GeomType.CIRCLE)`,
  `.sort_by(Axis.Z)` ([-1] — верхние, [0] — нижние), `.group_by(Axis.Z)[-1]` — верхний уровень.
* Зенковка под потайную головку в алгебре — усечённый конус ВНУТРИ материала:
  `part -= Pos(0, 0, thick - 1.5) * Cone(hole_d / 2, head_d / 2, 1.5,
                                          align=(Align.CENTER, Align.CENTER, Align.MIN))`
  (проверено: отверстие Ø4.5 под M4, головка Ø9, глубина 1.5 мм — объём детали
  уменьшается ровно на объём зенковки). Конус, поставленный СВЕРХУ плиты
  (`Pos(0, 0, thick) * Cone(head_d / 2, 0, …)`), не вычитает НИЧЕГО — останется
  прямое отверстие. Цековка — цилиндром нужной глубины. `CounterSinkHole` и
  `CounterBoreHole` — builder-объекты, в алгебре их нет.
* Массивы: цикл `for i in range(n): part -= Rot(0, 0, i * 360 / n) * Pos(r, 0, 0) * hole`.
  Зеркало — `mirror(shape, about=Plane.YZ)`.
* Проверки в уме: `part.volume`, `part.bounding_box().size`, `len(part.solids())`.

ЧАСТЫЕ ОШИБКИ — из-за них деталь не собирается (не допускай их):
1. `mode=Mode.SUBTRACT` (или ADD/INTERSECT) в алгебре: он МОЛЧА игнорируется — вырез не
   появится, а деталь соберётся «не та». В алгебре только `-`, `+`, `&`.
2. Builder без `with` (`BuildPart():`) — контексты не нужны вообще, пиши алгебру.
3. Фаска/скругление на кромке, которую только что вырезали (особенно на пересечении):
   такие кромки булева операция «съедает», и движок отвечает «Failed creating a fillet with
   radius of N, try a smaller value» или «Failed creating a chamfer, try a smaller length
   value(s)». Сначала ВСЕ вычитания, потом честно выбранные длинные кромки — `filter_by(Axis.Z)`
   или кромки верхней грани.
4. Радиус больше соседнего размера (проверено: скругление R2 на стенке 2 мм не строится) →
   R ≤ 0.5 от минимального прилегающего размера, фаска ≤ 0.3 от толщины. Тонкую плиту
   не скругляй по всем кромкам сразу — выбирай длинные.
5. `Box(...)` без align/Pos — тело встанет по центру координат, а не на Z=0 (так задумано).
6. Вычитание мимо тела (отверстие не пересекает материал) — проверь координаты Pos и размеры.
7. Несколько несвязанных тел на выходе (`len(part.solids()) > 1`) — соедини перемычкой или
   убери лишнее: деталь должна быть одним телом.
8. Пустое тело после вычитаний (объём ≈ 0) — вычитай меньше или сдвинь отверстия внутрь.
9. `export_*`, `show_object`, `display`, numpy, файлы, сеть, время, случайность — запрещены:
   файлы собирает движок, а не твой код.
10. Опечатка в имени параметра: `kwargs.get("widht", …)` — это имя попадёт на ползунок
    как есть, и человек будет думать, что крутит «ширину».

ТИХИЕ ОШИБКИ — движок НЕ ругается, а деталь получается не той (всё проверено на build123d):
* вычитание мимо тела: объём не изменился, отверстия нет — проверь координаты `Pos` и размеры;
* пустое тело: вычитание съело деталь целиком или профиль вырожден — `part.volume` ≈ 0;
* `mode=Mode.*` в алгебре: игнорируется, вырез не появляется;
* зенковка конусом сверху плиты: объём не изменился — выреза нет (конус остался в воздухе);
* `len(part.solids()) > 1`: детали не соединены — нужна перемычка или единая база.
Поэтому ПЕРЕД возвратом мысленно проверь: `part.volume > 0`, `len(part.solids()) == 1`
и что каждая булева операция действительно меняет объём.

СТРАХОВКА ДЛЯ КАПРИЗНОЙ КРОМКИ (косметика не должна валить деталь):

```python
def _safe_fillet(part, edges, radius):
    try:
        return fillet(edges, radius)
    except Exception:
        return part          # кромка оказалась «съеденной» — деталь остаётся без фаски
```

Так оборачивай ТОЛЬКО косметику (фаски, скругления). Основную геометрию так прятать нельзя.

КАЧЕСТВО КОНСТРУКЦИИ — обязательная часть работы. Владелец сервиса просит: «модель
должна стараться и делать максимально хорошие конструкции». Поэтому:
7. СНАЧАЛА ПОДУМАЙ как инженер: для чего деталь, как её нагружают, к чему и чем крепят,
   что с ней делают руками. Нужна рабочая деталь, а не «куб из примера».
8. ФУНКЦИОНАЛЬНЫЕ ЭЛЕМЕНТЫ обязательны там, где они уместны: отверстия под винты
   (Ø3.4 — M3, Ø4.5 — M4, Ø5.5 — M5), зенковки/цековки под головку, посадочные площадки и
   зазоры 0.2-0.4 мм для сопрягаемых деталей, бобышки, пазы, рёбра жёсткости, вырезы под
   кабель/палец/ключ. Голая пластина с дырками годится только если так прямо просили.
9. ФАСКИ И СКРУГЛЕНИЯ по внешним, заходным и «ручным» кромкам: фаска 45°×0.5-1 мм,
   скругление R0.5-R3. Не скругляй уплотнительные и точно сопрягаемые плоскости.
10. ПРОЧНОСТЬ И ПЕЧАТЬ: стенки не тоньше 1.5 мм (лучше 2), нависания ≤ 45°, отверстия
   по возможности вдоль Z или с фаской, деталь без парящих островов и тонких перемычек.
11. ГЕОМЕТРИЯ: одинаковые элементы — через цикл или поворот, деталь центрируй по X/Y,
   низ детали — на Z = 0, Z смотрит вверх. Если уместна симметрия — делай симметрично.
12. НИЧЕГО ЛИШНЕГО: ни декоративной геометрии, ни параметров, которые не меняют форму;
   каждая булева операция должна быть нужна. Резьбу «в металле» не рисуй: под неё —
   отверстие под нарезание или резьбовая бобышка.
13. Порядок построения — понятный: эскизы и тела → булевы операции → фаски и скругления в самом конце,
   после всех вычитаний (иначе булева операция «съедает» кромку, и скругление падает).
   Сложную деталь собирай из простых операций, а не одним «хаком».
14. В notes напиши, что именно сделало конструкцию хорошей (крепёж, фаски, рёбра, зазоры),
   а в checks — что стоит проверить перед печатью или заказом.

ЭТАЛОННЫЙ ПРИМЕР (собран и проверен на 11 наборах значений, включая все min и max;
проверены также габарит 100×60×14 мм, объём и одно тело). Это образец стиля и связей:
адаптируй его под деталь, но не копируй его как ответ.

```python
from build123d import *


def _safe_fillet(part, edges, radius):
    try:
        return fillet(edges, radius)              # косметика не должна валить деталь
    except Exception:
        return part


def build_model(**kwargs):
    length = float(kwargs.get("length", 100))     # все размеры — только через kwargs.get
    width = float(kwargs.get("width", 60))
    thick = float(kwargs.get("thickness", 8))
    hole_d = float(kwargs.get("hole_d", 4.5))
    boss_d = float(kwargs.get("boss_d", 24))
    margin = max(6.0, min(length, width) * 0.12)  # связи держи через min/max

    part = Box(length, width, thick, align=(Align.CENTER, Align.CENTER, Align.MIN))

    # бобышка под вал: встаёт ровно на верхнюю плоскость плиты (Z = thick)
    part = part + Pos(0, 0, thick) * Cylinder(boss_d / 2, 6, align=(Align.CENTER, Align.CENTER, Align.MIN))

    # четыре крепёжных отверстия по углам — цикл, а не четыре копии кода
    for sx in (-1, 1):
        for sy in (-1, 1):
            part -= Pos(sx * (length / 2 - margin), sy * (width / 2 - margin), thick / 2) * \
                Cylinder(hole_d / 2, thick * 2)

    # центральное отверстие сквозь бобышку; радиус ограничен, чтобы не съесть стенку
    part -= Pos(0, 0, thick) * Cylinder(max(boss_d / 2 - 3, 2), 20,
                                       align=(Align.CENTER, Align.CENTER, Align.MIN))

    # фаски и скругления — в самом конце, по длинным кромкам и с запасом по радиусу
    return _safe_fillet(part, part.edges().filter_by(Axis.Z), min(2.0, thick * 0.25))
```

ПЕРЕД ОТВЕТОМ ПРОВЕРЬ СЕБЯ (молча, в уме — это дешевле, чем пересборка):
1. у каждого параметра в `kwargs.get("имя", значение)` значение — ЯВНОЕ ЧИСЛО из описания
   (по этим числам соберут схему параметров и ползунки, и они станут размерами детали);
2. импортировано ровно то, что разрешено, и ничего не выполняется на уровне модуля;
3. код синтаксически верен: отступы 4 пробела, скобки закрыты, табов нет;
4. деталь строится на min и на max каждого параметра (нет деления на ноль, размеров ≤ 0,
   фасок больше кромки, вычитаний мимо тела);
5. после всех операций — одно тело с положительным объёмом (`part.volume > 0`,
   `len(part.solids()) == 1`), и каждое вычитание реально убирает материал;
6. габарит детали совпадает с размерами, названными в описании (100 мм — это 100 мм,
   а не 60 «на глаз»), а значение по умолчанию каждого параметра равно числу из описания;
7. в ответе только JSON, без текста вокруг.

Не используй numpy, show_object, export_step, display — файлы и изображения собирает движок,
а не твой код. Если параметр не влияет на геометрию — не заводи его вовсе.

ЧТО ВЕРНУТЬ — ТОЛЬКО JSON без markdown. Ни одного слова до или после JSON, никаких
рассуждений, черновиков, пояснений и повторов кода: рассуждай про себя, а в ответ
клади лишь сам JSON (владелец платит за каждый токен ответа). Формат:
{"code": "полный Python-код одной строкой с \\n"}
Больше в ответе НИЧЕГО не нужно: название, назначение и схему параметров соберут
ОТДЕЛЬНЫМ проходом по твоему коду — твоя работа здесь только геометрия. Поэтому
имена параметров и значения по умолчанию в `kwargs.get(...)` — контракт: они
попадут на ползунки как есть. Параметров 3-7: только те, что реально меняют
геометрию детали, и без дублирования чисел."""

SYSTEM_META = """
Ты — инженер-конструктор и технический редактор. Тебе дают описание детали и УЖЕ ГОТОВЫЙ
код модели на build123d (функция `build_model(**kwargs)`), который собран и проверен
движком. Код ПЕРЕПИСЫВАТЬ НЕЛЬЗЯ — твоя работа метаинформация о нём: схема параметров
для ползунков, название, назначение и замечания.

СХЕМА ПАРАМЕТРОВ — строго из кода, а не из головы:
* в схему идут РОВНО те параметры, которые код читает через `kwargs.get("имя", значение)`;
  имя — символ в символ, как в коде;
* `default` — ТОЧНО то число, что стоит в коде; не округляй, не «улучшай» и не меняй
  его: по коду собирается деталь, по схеме её собирают заново;
* `min`/`max` — рабочий диапазон: движок собирает деталь на каждой границе, поэтому
  границы обязаны не ломать конструкцию (никакого min ≤ 0 для диаметра, толщины и
  количества; не раздувай размах так, чтобы деталь вырождалась);
* `label` — короткая русская подпись для человека («Ширина», «Диаметр отверстий»),
  `unit` — «мм»;
* параметр, который не влияет на геометрию, в схему не включай.

ОСТАЛЬНОЕ — по коду и описанию, без выдумок:
* `title` — название детали до 60 знаков;
* `summary` — 1-2 предложения: что это и как применяется;
* `notes` — до 4 пунктов: что реально учтено в конструкции (крепёж, фаски, рёбра);
* `checks` — до 4 пунктов: что проверить перед печатью или заказом.
Не приписывай детали того, чего в коде нет: сомневаешься — не пиши.

Верни ТОЛЬКО JSON без markdown, ни слова вокруг:
{
  "title": "…",
  "summary": "…",
  "schema_definition": {"parameters": {
      "width": {"default": 60, "min": 20, "max": 200, "unit": "мм", "label": "Ширина"}
  }},
  "notes": ["…"],
  "checks": ["…"]
}
Кода в ответе быть не должно — он уже написан."""

SYSTEM_FIX = """
Ты — инженер-конструктор и программист CAD (build123d). Тебе дают код модели, сжатый отчёт
движка и ошибку. Верни ИСПРАВЛЕННЫЙ код, который соберётся и даст цельную деталь.

НЕ УПРОЩАЙ КОНСТРУКЦИЮ, чтобы «просто собралось»: крепёж, фаски, скругления, рёбра и
посадочные места — часть детали, их надо сохранить или починить. Правь ПРИЧИНУ (вырожденный
размер, фаска больше кромки, скругление на пересечении), а не выкидывай геометрию. Если
упрощение действительно единственный выход — скажи об этом в notes.

ПРАВЬ МИНИМАЛЬНО: не переписывай деталь целиком, меняй то, что упало, и его окружение.
Режим — АЛГЕБРА build123d: `Box`, `Cylinder`, `Pos(...) * shape`, `part -= hole`,
`fillet(edges, radius)`, `chamfer(edges, length)`. Никаких `with BuildPart(...)` и
`Mode.SUBTRACT` — вне builder-контекста они падают.

КАК ЧИТАТЬ ОТЧЁТ, который тебе дали:
* «Ошибка движка» — что именно упало; «Где» — номер строки: правь эту строку и рядом;
* «Крайние значения» — при каких min/max модель не строится: убери вырожденность
  (min()/max(), зазор, ограничение вычитания);
* «Геометрия» — габарит, объём, тел: объём ≈ 0 → вычитание съело деталь; тел больше одного →
  тела не соединены (нужна перемычка или бобышка); странный габарит → ошибка в координатах;
* «Предупреждение движка» и «Замечание к коду» — не ошибки, но их стоит снять, если это дёшево.

Частые причины падений: скругление/фаска на кромке, которую «съела» булева операция (перенеси
скругление на длинные кромки через `filter_by(Axis.Z)` или на кромки верхней грани, либо
уменьши радиус); радиус больше соседнего размера (R ≤ 0.5 от минимального прилегающего);
`Box(...)` без align/Pos поставил тело по центру вместо Z=0; вычитание мимо тела.
Косметику (только фаски и скругления) можно обернуть в try/except — основную геометрию нельзя.

Правила те же, что при генерации: только `from build123d import *` и `import math`, функция
`def build_model(**kwargs):` возвращает одну цельную деталь, все размеры — параметры из схемы
(имена и значения по умолчанию совпадают со схемой), деталь обязана строиться на min и max,
на уровне модуля ничего не выполняется.

Верни ТОЛЬКО JSON (ни слова вокруг, без рассуждений): {"code": "…", "notes": ["что исправлено"]}.
Схему параметров писать не нужно: если правка завела новый параметр — задай его
значение по умолчанию явным числом в `kwargs.get("имя", число)`, схему соберут по коду.
Список "parameters" не присылай — значения берутся из кода."""

def _system_code() -> str:
    return SYSTEM_CODE


def _user_prompt(prompt: str, params: dict | None = None, extra: str = "") -> str:
    parts = [f"Описание детали:\n{prompt.strip()}"]

    if params:
        parts.append("Значения параметров, которые нужно использовать как defaults:\n"
                     + json.dumps(params, ensure_ascii=False))

    if extra:
        parts.append(extra)

    parts.append("Размеры и материалы из описания — это значения по умолчанию (default) в КОДЕ; "
                 "схему параметров и границы min/max соберут по твоему коду отдельным проходом, "
                 "поэтому пиши их явными числами в kwargs.get(...).")
    parts.append("Числа, названные в описании («100 мм», «100×60×8»), — ТРЕБОВАНИЕ к детали: "
                 "габарит модели обязан им соответствовать, а значение по умолчанию параметра — "
                 "равняться этому числу. Не заменяй названный размер «похожим».")
    parts.append("Помни: нужна максимально хорошая конструкция, а не минимальная, которая "
                 "просто компилируется — продумай назначение детали, её крепление и удобство "
                 "использования, добавь уместный крепёж, фаски, скругления и рёбра.")
    parts.append("Перед ответом мысленно проверь деталь на минимальных и максимальных значениях "
                 "каждого параметра: код должен собираться везде (движок проверяет именно это).")
    parts.append("Верни JSON по формату из системного сообщения.")

    return "\n\n".join(parts)


def _meta_model() -> str:
    """Модель для метаинформации: своя, если задана; иначе модель модуля.

    Владелец (28.09.2026): «предусмотри для метаинформации и т.п. другую модель
    (если не будет указана, то основная для модуля)».
    """
    return (os.getenv("NJ643_META_MODEL") or "").strip() or _model()


def _meta_enabled() -> bool:
    """Второй прогон (мета) можно выключить: NJ643_META=0 — схема из кода, дёшево."""
    return (os.getenv("NJ643_META") or "").strip().lower() not in ("0", "off", "no", "false")


def _meta_prompt(prompt: str, code: str, schema_definition=None) -> str:
    """Задание мета-проходу: по описанию и УЖЕ ГОТОВОМУ коду — схема и описание."""
    parts = [
        f"Описание детали (со слов человека):\n{(prompt or '—').strip()}",
        "Код модели, который уже написан и собран (переписывать его нельзя):\n"
        f"```python\n{code}\n```",
    ]

    old = normalize_schema(schema_definition).get("parameters") if isinstance(schema_definition, dict) else {}

    if old:
        parts.append("Схема этой детали до правки кода (сохрани имена, подписи, единицы и "
                     "границы, если они по-прежнему верны коду):\n"
                     + json.dumps({"parameters": old}, ensure_ascii=False))

    parts.append("Собери метаинформацию по коду: только те параметры, которые код читает "
                 "через kwargs.get(...), значения по умолчанию — ТОЧНО из кода, границы "
                 "min/max — рабочие (движок собирает деталь на каждой границе). "
                 "Верни JSON по формату из системного сообщения.")

    return "\n\n".join(parts)


def merge_meta(base: dict, extra: dict) -> dict:
    """Свежая метаинформация поверх прежней.

    Текст (`title`, `summary`, схема) берём из второго прохода — он видит итоговый
    код. А списки `notes`/`checks` складываем: первый проход (правка) говорит, ЧТО
    исправлено, второй — что реально учтено в конструкции. Владельцу нужны оба
    факта, и терять их при слиянии нельзя.
    """
    out = {**base, **extra}

    for key in ("notes", "checks"):
        values: list[str] = []

        for item in (extra.get(key), base.get(key)):
            items = item if isinstance(item, list) else ([item] if item else [])

            for value in items:
                text = str(value).strip()

                if text and text not in values:
                    values.append(text)

        if values:
            out[key] = values[:6]

    return out


async def _ask_meta(ctx, prompt: str, code: str, base_schema=None, sink=None) -> dict:
    """Второй прогон: метаинформация по готовому коду (своя модель).

    Владелец (28.09.2026): «давай делать код одним прогоном (чтобы качественнее
    ответ был), а потом на основании кода метаинформацию и т.п. следующим прогоном
    (предусмотри для метаинформации и т.п. другую модель (если не будет указана,
    то основная для модуля))».

    Мета-проход НЕ критичен: код уже написан и собран. Если мета-модель упала или
    ответила без JSON — сборка продолжается, схему собираем из кода
    (`schema_from_code`), а название и описание просто не показываем. Терять
    готовую деталь из-за второго прохода нельзя.
    """
    if not _meta_enabled():
        return {}

    model = _meta_model()
    await ctx.progress("Собираю схему и описание (мета)", f"модель {model or 'модуля'}")

    try:
        await ctx.heartbeat()
        text = await _llm(ctx, [
            {"role": "system", "content": SYSTEM_META},
            {"role": "user", "content": _meta_prompt(prompt, code, base_schema)},
        ], "мета: схема и описание", sink if sink is not None else [], model=model or None)
    except Exception as e:  # noqa: BLE001 — мета не критична для сборки
        log.warning("nj643: мета-проход не удался (%s) — схему соберём из кода", e)
        return {}

    return _parse_json(text)


def _json_span(text: str) -> tuple[int, int] | None:
    """Где в ответе модели начинается и кончается JSON (для разбора расхода)."""
    if not text:
        return None

    m = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", text, re.S)

    if m:
        return m.start(1), m.end(1)

    start = text.find("{")
    end = text.rfind("}")

    return (start, end + 1) if 0 <= start < end else None


def _spend_note(text: str, usage: dict) -> str:
    """Почему за короткий код списывается столько токенов — по факту ответа.

    Владелец (27.09.2026): «напиши почему для генерации такого маленького кода
    потратилось много токенов?». В completion_tokens входит ВЕСЬ сгенерированный
    ответ, а не только код: обвязка JSON (schema_definition + parameters дублируют
    друг друга), экранированные \\n в строке кода, рассуждения модели, которые в
    тексте не видны, но провайдер их считает, и «болтовня» до/после JSON.
    Считаем это здесь и показываем владельцу на странице детали и в /cad/admin.
    """
    total = len(text or "")
    span = _json_span(text or "")
    parts = [f"ответ {total} знаков"]

    if span:
        before = span[0]
        after = max(0, total - span[1])
        parts.append(f"JSON: {span[1] - span[0]} знаков" + (f", до него {before}" if before else ""))
        if after:
            parts.append(f"после JSON {after}")
    else:
        parts.append("JSON не найден целиком")

    try:
        data = json.loads((text or "")[span[0]:span[1]]) if span else {}
    except ValueError:
        data = {}

    code = str(data.get("code") or "")

    if code:
        parts.append(f"код модели {len(code)} знаков")

    details = usage.get("completion_tokens_details")

    if isinstance(details, dict):
        reasoning = int(details.get("reasoning_tokens") or 0)

        if reasoning:
            parts.append(f"рассуждения модели {reasoning} токенов")

    finish = str(usage.get("_llm_finish_reason") or "")

    if finish == "length":
        parts.append("ответ ОБРЕЗАН по лимиту max_tokens")
    elif finish:
        parts.append(f"финиш {finish}")

    return "; ".join(parts)[:300]


async def _llm(ctx, messages: list[dict], phase: str, sink: list,
               model: str | None = None) -> str:
    """Один вызов LLM + учёт расхода: строки расхода уедут на сайт в result.usage.

    ЛИМИТ max_tokens В ЭТОМ МОДУЛЕ НЕ ПРИМЕНЯЕМ. Владелец (27.09.2026): «Лимит
    max_tokens в этом модуле применять нельзя». Причина видна в живом расходе:
    у починки кода ответ 787 знаков из 7996 токенов, JSON не найден целиком,
    «ответ ОБРЕЗАН по лимиту max_tokens» — у модели списывались рассуждения, а
    сам код приезжал недописанным, и деталь уходила на повтор. Модель сама решает,
    сколько ей нужно; наш потолок только режет полезное.
    """
    t0 = time.monotonic()
    text, usage = await ctx.llm.chat_with_usage(
        messages, model=((model or _model()) or None), request_timeout=300)

    dt = int((time.monotonic() - t0) * 1000)
    pt = int(usage.get("prompt_tokens") or 0)
    ct = int(usage.get("completion_tokens") or 0)
    real = str(usage.get("_llm_model") or "") or ((model or _model()) or "default")

    note = _spend_note(text, usage)

    log.info("nj643[%s] модель=%s %d+%d токенов, %d мс — %s", phase, real, pt, ct, dt, note)
    sink.append({
        "phase": phase,
        "model": real,
        "prompt_tokens": pt,
        "completion_tokens": ct,
        "duration_ms": dt,
        "note": note,
    })

    return text


def _parse_json(text: str) -> dict:
    """JSON из ответа модели: чистый, в ```-блоке или с текстом вокруг."""
    if not text:
        return {}

    m = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", text, re.S)

    if m:
        try:
            data = json.loads(m.group(1))
            return data if isinstance(data, dict) else {}
        except ValueError:
            pass

    try:
        data = json.loads(text)
        return data if isinstance(data, dict) else {}
    except ValueError:
        pass

    # последняя попытка: самый большой {...} в тексте
    start = text.find("{")
    end = text.rfind("}")

    if start != -1 and end > start:
        try:
            data = json.loads(text[start:end + 1])
            return data if isinstance(data, dict) else {}
        except ValueError:
            return {}

    return {}


def _clean_code(raw: str) -> str:
    """Код из ответа модели: снять ```-обёртку и лишние отступы."""
    code = (raw or "").strip()
    m = re.search(r"```(?:python|py)?\s*(.*?)```", code, re.S)

    if m:
        code = m.group(1)

    return code.strip()


_FORBIDDEN = [
    (r"\bimport\s+(os|sys|subprocess|shutil|socket|pathlib|requests|httpx|urllib|glob|tempfile|pickle|ctypes|multiprocessing|threading|signal)\b",
     "запрещённый импорт"),
    (r"\bfrom\s+(os|sys|subprocess|shutil|socket|pathlib|requests|httpx|urllib|glob|tempfile|pickle|ctypes|multiprocessing|threading|signal)\b",
     "запрещённый импорт"),
    (r"\b(eval|exec|compile|__import__|globals|locals|vars|open|input|breakpoint)\s*\(",
     "запрещённый вызов"),
    (r"\bgetattr\s*\(\s*(__builtins__|builtins)", "доступ к builtins"),
    (r"__\w+__\s*\(", "магический вызов"),
    (r"(\bos\.|\bsys\.|\bsubprocess\.|\bsocket\.|\bshutil\.|\bpathlib\.|\brequests\.|\bhttpx\.)",
     "обращение к системному модулю"),
]

_ALLOWED_IMPORTS = re.compile(
    r"^(from\s+(build123d|math|typing|dataclasses|enum|itertools|functools)\b|import\s+(build123d|math|typing|dataclasses|enum|itertools|functools)\b)")


_BUILDER_OBJECTS = ("BuildPart", "BuildSketch", "BuildLine", "Hole", "CounterBoreHole",
                    "CounterSinkHole", "BuildAssembly")


def _static_problems(code: str) -> str:
    """Проверки кода без движка: синтаксис, режим работы, структура модели.

    Живой смысл: каждая такая ошибка раньше уезжала в движок, возвращалась отказом,
    и только потом модель просили «починить» — то есть один лишний круг LLM и один
    лишний вызов движка на ровном месте. Python здесь у нас есть, грех не проверить.

    Возвращает '' — код в порядке, иначе готовую фразу для повторного запроса.
    """
    try:
        tree = ast.parse(code)
    except SyntaxError as e:
        spot = (e.text or "").strip()
        return (f"код не разбирается Python: {e.msg} (строка {e.lineno}"
                + (f": {spot[:80]}" if spot else "") + ")")

    fn = next((n for n in tree.body
               if isinstance(n, (ast.FunctionDef, ast.AsyncFunctionDef)) and n.name == "build_model"), None)

    if fn is None:
        return "функции build_model(**kwargs) нет на верхнем уровне модуля"

    if not any(isinstance(n, ast.Return) and n.value is not None for n in ast.walk(fn)):
        return "build_model ничего не возвращает: нужен `return part` с готовой деталью"

    for node in ast.walk(tree):
        if isinstance(node, ast.Attribute) and isinstance(node.value, ast.Name) and node.value.id == "Mode":
            return ("Mode.* (например Mode.SUBTRACT) работает только внутри `with BuildPart(...)`; "
                    "в алгебраическом режиме вычитай через `part -= hole`")

    # объекты builder-режима допустимы только внутри `with`
    in_with = set()

    for node in ast.walk(tree):
        if isinstance(node, ast.With):
            for item in node.items:
                in_with.update(id(n) for n in ast.walk(item.context_expr) if isinstance(n, ast.Call))

    for node in ast.walk(tree):
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) \
                and node.func.id in _BUILDER_OBJECTS and id(node) not in in_with:
            return (f"{node.func.id}(...) — это builder-контекст: нужен `with {node.func.id}(...)`, "
                    "а проще — алгебра без контекстов (Box, Cylinder, Pos, part -= hole)")

    # на уровне модуля не должно быть выполняемого кода: он выполнится при импорте
    for node in tree.body:
        ok = isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef, ast.AsyncFunctionDef,
                               ast.ClassDef, ast.AnnAssign))

        if not ok and isinstance(node, ast.Expr) and isinstance(node.value, ast.Constant) \
                and isinstance(node.value.value, str):
            ok = True                                  # строка документации

        if not ok and isinstance(node, ast.Assign) and _is_literal(node.value):
            ok = True                                  # константа: число, строка, список, словарь

        if not ok:
            return (f"на уровне модуля есть выполняемый код (строка {node.lineno}): он выполнится "
                    "при импорте. Оставь только импорты, константы, функции и сам build_model")

    return ""


def _is_literal(node) -> bool:
    """Литерал или набор литералов — то, что безопасно объявить на уровне модуля."""
    if isinstance(node, ast.Constant):
        return True

    if isinstance(node, (ast.List, ast.Tuple, ast.Set)):
        return all(_is_literal(e) for e in node.elts)

    if isinstance(node, ast.Dict):
        return all(_is_literal(k) for k in node.keys if k is not None) \
            and all(_is_literal(v) for v in node.values)

    if isinstance(node, ast.UnaryOp) and isinstance(node.op, (ast.USub, ast.UAdd)):
        return _is_literal(node.operand)

    return False


def check_code(raw: str) -> tuple[str, str]:
    """Проверка кода перед отправкой в движок (движок его ИСПОЛНЯЕТ).

    Возвращает (code, problem): problem == '' — код принят.
    """
    code = _clean_code(raw)

    if not code:
        return "", "Модель вернула пустой код"

    if len(code) > _max_code():
        return "", f"Код слишком большой ({len(code)} знаков, потолок {_max_code()})"

    if "def build_model" not in code:
        return "", "В коде нет функции build_model(**kwargs) — движку нечего вызывать"

    for pattern, why in _FORBIDDEN:
        m = re.search(pattern, code)

        if m:
            return "", f"Недопустимый код ({why}: {m.group(0)[:60]})"

    for line in code.splitlines():
        line = line.strip()

        if not line or line.startswith(("#", '"', "'")):
            continue

        if re.match(r"^(import|from)\s", line) and not _ALLOWED_IMPORTS.match(line):
            return "", f"Недопустимый импорт: {line[:60]}"

    # структура и режим работы: синтаксис, алгебра вместо builder, отсутствие кода на импорте
    problem = _static_problems(code)

    if problem:
        return "", problem

    return code, ""


# ---------------------------------------------------------------------------
# Код и схема: расхождения, которые портят деталь молча
# ---------------------------------------------------------------------------

_PARAM_RE = re.compile(r"""kwargs\.get\(\s*['"]([A-Za-z_][A-Za-z0-9_]*)['"]\s*(?:,\s*([^)]*?))?\s*\)""")
_NUM_RE = re.compile(r"^-?\d+(?:\.\d+)?$")


def code_params(code: str) -> dict[str, str | None]:
    """Параметры из кода → текст значения по умолчанию (None, если его не написали)."""
    out: dict[str, str | None] = {}

    for name, default in _PARAM_RE.findall(code or ""):
        out[name] = (default or "").strip() or None

    return out


def schema_from_code(code: str) -> dict:
    """Схема прямо из кода: имя параметра и его числовое значение по умолчанию.

    Страховка на случай, когда второй прогон (мета) не ответил: ползунки у детали
    должны быть в любом случае, иначе её нельзя пересобрать с другими размерами.
    Параметры с нечисловым значением (`None`, `w / 2`, `min(a, b)`) не выдумываем:
    их значение считает сам код, ползунком его не задать.
    """
    params: dict = {}

    for name, raw in code_params(code).items():
        if not raw or not _NUM_RE.match(raw):
            continue

        num = float(raw)
        params[name] = {"default": int(num) if num.is_integer() else num}

    return {"parameters": params} if params else {}


def schema_missing_params(code: str, schema_definition) -> list[str]:
    """Параметры, которые код читает, а схема не показывает: ползунка не будет."""
    shown = normalize_schema(schema_definition).get("parameters") or {}

    return [name for name in code_params(code) if name not in shown]


def merge_schema_from_code(code: str, schema_definition) -> tuple[dict, list[str]]:
    """Дополнить схему параметрами кода: мета-модель могла про них забыть."""
    schema = normalize_schema(schema_definition)
    params = {k: dict(v) if isinstance(v, dict) else v
              for k, v in (schema.get("parameters") or {}).items()}
    from_code = normalize_schema(schema_from_code(code)).get("parameters") or {}
    notes: list[str] = []

    for name, spec in from_code.items():
        if name in params:
            continue

        params[name] = spec
        notes.append(f"в схему добавлен параметр «{name}» из кода: {spec['default']}")

    return ({"parameters": params} if params else {}), notes


def code_warnings(code: str, schema_definition) -> list[str]:
    """Расхождения кода и схемы: ползунок на странице обязан менять геометрию.

    Схему видит человек на странице (подписи, границы, значения), код исполняет
    движок. Если они разошлись — владелец крутит ползунок и не понимает, почему
    деталь не изменилась. Проверяем и отдаём модели в правку, а не молчим.
    """
    params = normalize_schema(schema_definition).get("parameters") or {}
    in_code = code_params(code)
    out: list[str] = []

    if not in_code:
        out.append("в коде нет ни одного kwargs.get(...): размеры зашиты числами, "
                   "ползунков параметров у детали не будет")

    for name in params:
        if name not in in_code:
            out.append(f"параметр «{name}» есть в схеме, но код его не читает — "
                       "ползунок на странице ничего не изменит")

    for name in in_code:
        if name not in params:
            out.append(f"код читает параметр «{name}», которого нет в схеме")

    # параметр без значения по умолчанию: движок вызовет без него и получит None
    for name, raw in in_code.items():
        if raw is None:
            out.append(f"у параметра «{name}» в коде нет значения по умолчанию: "
                       f'kwargs.get("{name}") — на крайних значениях это даст None')

    for name, spec in params.items():
        raw = in_code.get(name)

        if raw is None or not isinstance(spec, dict) or "default" not in spec:
            continue

        if not _NUM_RE.match(raw):
            continue

        try:
            in_schema = float(spec["default"])
        except (TypeError, ValueError):
            continue

        if abs(float(raw) - in_schema) > 1e-9:
            out.append(f"значение по умолчанию для «{name}»: в коде {raw}, в схеме {spec['default']}")

    # revolve — самая тихая ловушка: профиль в XY-плоскости даёт пустое тело без
    # ошибки (проверено на живом build123d: объём 0.0 у Circle(5) и Rectangle).
    # Правильный профиль лежит в XZ и отступлен от оси, поэтому ищем Plane.XZ.
    if "revolve(" in code and "Plane.XZ" not in code:
        out.append("в коде есть revolve(...), но профиль не в плоскости XZ: без "
                   "`revolve(Plane.XZ * Pos(r, 0) * Rectangle(...), Axis.Z)` движок "
                   "отдаёт пустое тело (объём 0) или BRep_API: command not done")

    return out


def align_schema_defaults(code: str, schema_definition) -> tuple[dict, list[str]]:
    """Схему приводим к тому, что реально собирает код.

    Код — источник правды (его исполняет движок и он нарисован на чертеже), схема —
    витрина: подписи, границы и значения ползунков. Если модель написала в коде
    `width: 60`, а в схеме `default: 40`, человек увидит 40, а получит деталь на 60.
    Правим схему по коду (числа), границы расширяем, чтобы значение в них попадало.
    """
    schema = normalize_schema(schema_definition)
    params = {k: dict(v) if isinstance(v, dict) else v for k, v in (schema.get("parameters") or {}).items()}

    if not params:
        # пустая схема остаётся пустой: вызывающий код умеет взять её из задачи
        return {}, []

    in_code = code_params(code)
    notes: list[str] = []

    for name, spec in params.items():
        if not isinstance(spec, dict) or not _NUM_RE.match(in_code.get(name) or ""):
            continue

        num = float(in_code[name])
        old = spec.get("default")

        if old is None or abs(float(old) - num) > 1e-9:
            spec["default"] = int(num) if num.is_integer() else round(num, 3)
            notes.append(f"значение по умолчанию «{name}» выровнено по коду: {spec['default']}")

        for edge, name_ru, better in (("min", "минимум", min), ("max", "максимум", max)):
            if spec.get(edge) is None:
                continue

            try:
                bound = float(spec[edge])
            except (TypeError, ValueError):
                continue

            if (edge == "min" and num < bound) or (edge == "max" and num > bound):
                spec[edge] = int(num) if num.is_integer() else round(num, 3)
                notes.append(f"{name_ru} «{name}» расширен до значения из кода: {spec[edge]}")

    return {"parameters": params}, notes


def _align_schema_and_notes(code: str, schema_definition,
                            base_schema=None) -> tuple[dict, list[str], list[str]]:
    """Схему — по коду, замечания — модели. Возвращает (схема, правки схемы, замечания).

    Схема приходит из мета-прохода (второй прогон) или из задачи. Дальше она
    доводится до кода: параметры, которые код читает, а мета-модель забыла,
    добавляются; значения по умолчанию берутся ИЗ КОДА (код — источник правды);
    границы расширяются, если значение из кода в них не попадает. Совсем без схемы
    деталь не остаётся: её собираем из кода, иначе ползунков не будет.
    """
    schema = normalize_schema(schema_definition)
    notes: list[str] = []

    # Пустая схема — это {"parameters": {}}, а не «пустой словарь»: проверять надо
    # именно параметры, иначе страховка «соберём схему из кода» молча не сработает
    # (живой случай 28.09.2026: мета-проход не ответил, у детали не стало ползунков).
    if not (schema.get("parameters") or {}):
        base = normalize_schema(base_schema) if isinstance(base_schema, dict) else {}

        if base.get("parameters"):
            schema = base
        else:
            schema = schema_from_code(code)

            if schema:
                notes.append("схема собрана из кода: мета-проход ничего не вернул")
    else:
        schema, added = merge_schema_from_code(code, schema)
        notes.extend(added)

    schema, align_notes = align_schema_defaults(code, schema)
    notes.extend(align_notes)
    warnings = code_warnings(code, schema)

    if notes:
        log.info("nj643: схема выровнена по коду: %s", "; ".join(notes[:4]))

    if warnings:
        log.info("nj643: замечания к коду модели: %s", "; ".join(warnings[:4]))

    return schema, notes, warnings


# ---------------------------------------------------------------------------
# Правка по отчёту движка: сказать модели ИМЕННО то, что сломалось
# ---------------------------------------------------------------------------

_LINE_RE = re.compile(r"(?:line|строка|стр\.)\s*(\d+)", re.I)


def _traceback_spot(error: str, code: str) -> str:
    """Строка из ошибки движка + окружение: модели проще править по адресу.

    Движок исполняет наш код как есть, поэтому номера строк в его трассировке
    совпадают с кодом. Показать эти строки дешевле, чем просить модель «найти
    проблему самому» (и она её находит не всегда).
    """
    m = _LINE_RE.search(str(error or ""))

    if not m:
        return ""

    num = int(m.group(1))
    lines = str(code or "").splitlines()

    if not lines or not (1 <= num <= len(lines)):
        return ""

    lo, hi = max(1, num - 1), min(len(lines), num + 1)
    shown = " / ".join(f"{i}: {lines[i - 1].strip()[:110]}" for i in range(lo, hi + 1))

    return f"строка {num} и рядом — {shown}"


def _stress_failures(report: dict, limit: int = 3) -> list[str]:
    """На каких крайних значениях параметров модель не строится."""
    cases = (report.get("stress") or {}).get("cases") if isinstance(report.get("stress"), dict) else None
    out: list[str] = []

    for case in (cases or []):
        if not isinstance(case, dict) or case.get("ok"):
            continue

        # формат кейса знает только движок: берём параметры и ошибку, как есть
        params = case.get("params") or case.get("parameters") or case.get("values") or {}
        err = str(case.get("error") or case.get("message") or "ошибка")[:140]
        out.append(f"{json.dumps(params, ensure_ascii=False)[:120]} → {err}")

        if len(out) >= limit:
            break

    return out


_SIZE_TRIPLE_RE = re.compile(
    r"(?<![\d.,])(\d{1,4}(?:[.,]\d)?)\s*[x×х*]\s*(\d{1,4}(?:[.,]\d)?)"
    r"(?:\s*[x×х*]\s*(\d{1,4}(?:[.,]\d)?))?", re.I)
_SIZE_MM_RE = re.compile(r"(?<![\d.,])(\d{1,4}(?:[.,]\d{1,2})?)\s*(?:мм|mm)\b", re.I)


def _prompt_sizes(prompt: str) -> list[float]:
    """Габаритные размеры, названные в описании, — без домыслов.

    Берём только написанное человеком явно: «100×80×10» и «100 мм». Мелкие числа
    (< 20 мм) и числа без единиц пропускаем: «стенка 2 мм», «R3», «M3» — это
    элементы детали, а не габарит, и упрёк по ним был бы ложным.
    """
    out: list[float] = []
    text = prompt or ""

    for m in _SIZE_TRIPLE_RE.finditer(text):
        for raw in m.groups():
            if raw:
                out.append(float(raw.replace(",", ".")))

    for m in _SIZE_MM_RE.finditer(text):
        value = float(m.group(1).replace(",", "."))

        if value >= 20:
            out.append(value)

    seen: set[float] = set()
    uniq: list[float] = []

    for v in out:
        if v not in seen:
            seen.add(v)
            uniq.append(v)

    return uniq


def _size_mismatch(prompt: str, report: dict) -> list[str]:
    """Где деталь разошлась с размерами, которые человек назвал в описании.

    Движок про это молчит: он проверяет геометрию, а не смысл просьбы. Живой
    риск — модель поставила в схему 60 мм там, где просили 100, деталь собралась,
    и владелец узнаёт об этом линейкой на экране. Здесь сравниваем названные
    размеры с габаритом сборки и говорим словами, что не сошлось.
    """
    geo = (report.get("geometry") if isinstance(report, dict) else None) or {}
    bbox = geo.get("bbox") if isinstance(geo, dict) else None

    if not isinstance(bbox, (list, tuple)) or not bbox:
        return []

    dims: list[float] = []

    for v in bbox[:3]:
        try:
            dims.append(float(v))
        except (TypeError, ValueError):
            continue

    if not dims:
        return []

    out: list[str] = []

    for value in _prompt_sizes(prompt):
        tol = max(0.75, value * 0.05)

        if any(abs(d - value) <= tol for d in dims):
            continue

        near = min(dims, key=lambda d: abs(d - value))
        out.append(f"в описании {value:g} мм, а у детали ближайший габарит {near:g} мм")

    return out[:3]


def _report_gist(report: dict) -> list[str]:
    """Самые полезные для правки факты отчёта: габарит, объём, тела, грани."""
    geo = report.get("geometry") if isinstance(report.get("geometry"), dict) else {}
    out: list[str] = []

    bbox = geo.get("bbox")

    if isinstance(bbox, list) and bbox:
        try:
            out.append("габарит " + "×".join(str(round(float(x), 2)) for x in bbox[:3]) + " мм")
        except (TypeError, ValueError):
            pass

    if geo.get("volume") is not None:
        try:
            vol = float(geo["volume"])
            out.append(f"объём {round(vol, 1)} мм³" + (" (пусто!)" if vol < 1e-6 else ""))
        except (TypeError, ValueError):
            pass

    if geo.get("solids") is not None:
        try:
            solids = int(geo["solids"])
            out.append(f"тел {solids}" + (" (нужно одно)" if solids != 1 else ""))
        except (TypeError, ValueError):
            pass

    return out


# Тексты ошибок движка → что именно править. Всё проверено на живом build123d:
# «Failed creating a fillet with radius of N», «Failed creating a chamfer»,
# «No depth provided» у Hole вне контекста, пустое тело у revolve с профилем в XY.
_ENGINE_HINTS = (
    ("failed creating a fillet",
     "скругление не село: уменьши радиус (R ≤ 0.5 минимального прилегающего размера) "
     "и перенеси fillet ПОСЛЕ всех вычитаний, на длинные кромки (filter_by(Axis.Z))"),
    ("failed creating a chamfer",
     "фаска не села: уменьши длину (≤ 0.3 толщины) и выбирай кромки верхней грани, а не все подряд"),
    ("no depth provided",
     "Hole/CounterBoreHole работают только внутри `with BuildPart(...)`: в алгебре вычитай "
     "`part -= Cylinder(...)`"),
    ("brep_api",
     "операцию не выполнила геометрия: проверь размеры (0 или отрицательные), профиль "
     "вращения и пересечение вычитаемого с телом"),
    ("stdfail",
     "геометрия вырождена: проверь, что вычитаемое пересекает материал, а радиусы меньше "
     "соседних размеров"),
    ("zerodivisionerror",
     "деление на параметр, который может быть 0: ограничь его через max(value, 0.1)"),
    ("keyerror",
     "имя параметра не совпало со схемой: проверь kwargs.get(\"имя\") — оно должно быть "
     "и в схеме, и в коде"),
    ("typeerror",
     "неверный вызов формы или builder-объект вне контекста: сверься со шпаргалкой "
     "(Box/Cylinder/Pos, part -= hole)"),
    ("attributeerror",
     "у детали нет такого метода: смотри шпаргалку (edges(), faces(), filter_by, sort_by, "
     "group_by, volume, bounding_box())"),
    ("syntaxerror",
     "код не разбирается Python: проверь отступы (4 пробела), скобки и кавычки"),
    ("empty", "тело пустое: вычитание съело деталь целиком или профиль вырожден — "
              "проверь part.volume > 0"),
    ("multiple solids", "тел больше одного: соедини части перемычкой или сделай единую базу"),
    ("timed out", "модель слишком тяжёлая для лимита времени: уменьши число элементов "
                  "или упрости построение, не теряя крепёж и рёбра"),
)
_ENGINE_HINTS_DEFAULT = ("движок отклонил код: правь причину, а не выкидывай геометрию",)


def _engine_hints(error: str, limit: int = 3) -> list[str]:
    """Что делать по тексту ошибки движка: конкретный совет вместо «попробуй иначе»."""
    text = (error or "").lower()
    out: list[str] = []

    for needle, advice in _ENGINE_HINTS:
        if needle in text and advice not in out:
            out.append(advice)

        if len(out) >= limit:
            break

    return out


def _fix_message(code: str, report: dict, error: str, code_notes: list[str] | None = None) -> str:
    """Сообщение модели на починку: что упало, где, при каких параметрах и что нельзя терять.

    Раньше уходил сырой JSON отчёта на 1500 знаков и текст ошибки — модель искала
    причину сама. Теперь порядок обратный: сначала «где и что», потом геометрия и
    крайние значения, и только в конце — напоминание сохранить конструкцию.
    """
    parts = ["Код модели:", "```python", code, "```", "", "Отчёт движка (сжато) — что именно не так:"]
    parts.append(f"- ошибка движка: {str(error or 'движок не вернул файлов модели')[:400]}")

    spot = _traceback_spot(error, code)

    if spot:
        parts.append(f"- где: {spot}")

    gist = _report_gist(report if isinstance(report, dict) else {})

    if gist:
        parts.append("- геометрия: " + ", ".join(gist))

    for case in _stress_failures(report if isinstance(report, dict) else {}):
        parts.append(f"- крайние значения параметров: {case}")

    warns = report.get("warnings") if isinstance(report, dict) else None

    for w in (warns or [])[:3]:
        text = w.get("message") if isinstance(w, dict) else w
        parts.append(f"- предупреждение движка: {str(text)[:140]}")

    for note in (code_notes or [])[:6]:
        parts.append(f"- замечание к коду: {note}")

    for hint in _engine_hints(error):
        parts.append(f"- подсказка: {hint}")

    parts.append("")
    parts.append("Исправь ПРИЧИНУ минимальной правкой: крепёж, фаски, скругления и рёбра сохрани. "
                 "Не переписывай деталь целиком. Верни JSON по формату из системного сообщения.")

    return "\n".join(parts)[:4000]


# ---------------------------------------------------------------------------
# Движок: verify-and-build
# ---------------------------------------------------------------------------

_MAGIC = (
    (b"ISO-10303-21", "step"),
    (b"glTF", "glb"),
    (b"\x89PNG\r\n\x1a\n", "png"),
    (b"%PDF", "pdf"),
    (b"PK\x03\x04", "3mf"),
    (b"solid ", "stl"),
)

_EXT_KIND = {
    "step": "step", "stp": "step", "stl": "stl", "glb": "glb", "gltf": "glb",
    "png": "png", "svg": "svg", "3mf": "3mf", "off": "off", "csg": "csg",
    "txt": "txt", "md": "txt", "py": "txt", "json": "json",
}

_KEY_KIND = (
    ("step", "step"), ("stp", "step"), ("stl", "stl"), ("glb", "glb"), ("gltf", "glb"),
    ("png", "png"), ("render", "png"), ("view", "png"), ("preview", "png"),
    ("image", "png"), ("svg", "svg"), ("3mf", "3mf"),
)


def _b64_to_bytes(value: str) -> bytes | None:
    """Похоже на base64 и декодируется в осмысленные байты — вернуть байты."""
    s = re.sub(r"\s+", "", value or "")

    if len(s) < 32 or len(s) > 90_000_000 or len(s) % 4 != 0:
        return None

    if not re.fullmatch(r"[A-Za-z0-9+/]+={0,2}", s):
        return None

    try:
        raw = base64.b64decode(s, validate=True)
    except (binascii.Error, ValueError):
        return None

    if not raw or len(raw) < 16:
        return None

    return raw


def _is_binary_stl(raw: bytes) -> bool:
    """Бинарный STL: 80 байт заголовка + uint32-счётчик треугольников (по 50 байт)."""
    if len(raw) < 84:
        return False

    import struct

    try:
        triangles = struct.unpack("<I", raw[80:84])[0]
    except struct.error:  # pragma: no cover — длина уже проверена
        return False

    return triangles > 0 and 84 + triangles * 50 == len(raw)


def _kind_of(raw: bytes, hint: str = "") -> str:
    """Вид файла: сначала подсказка (имя/ключ), затем магические байты."""
    hint = (hint or "").lower()

    if hint:
        ext = hint.rsplit(".", 1)[-1] if "." in hint else ""

        if ext in _EXT_KIND:
            return _EXT_KIND[ext]

        for key, kind in _KEY_KIND:
            if key in hint:
                return kind

    for magic, kind in _MAGIC:
        if raw.startswith(magic):
            if kind == "stl" and b"facet" not in raw[:4000] and b"endsolid" not in raw[-2000:]:
                return "txt"
            return kind

    # бинарный STL магии не имеет — узнаём по структуре файла
    if _is_binary_stl(raw):
        return "stl"

    if raw[:5] == b"<?xml":
        return "svg" if b"<svg" in raw[:2000] else "txt"

    # текст (код/отчёт) — кладём как txt, если он читаем
    try:
        raw[:2000].decode("utf-8")
        return "txt"
    except UnicodeDecodeError:
        return "other"


class Artifact:
    __slots__ = ("kind", "name", "raw", "view")

    def __init__(self, kind: str, name: str, raw: bytes, view: str = ""):
        self.kind = kind
        self.name = name
        self.raw = raw
        self.view = view


_EXT_BY_KIND = {"png": "png", "svg": "svg", "step": "step", "stl": "stl", "glb": "glb",
                "3mf": "3mf", "off": "off", "csg": "csg", "txt": "txt", "pdf": "pdf"}


def _artifact_name(kind: str, hint: str, n: int, view: str = "") -> str:
    """Имя файла: из подсказки движка (если там есть расширение), иначе по виду."""
    hint = re.sub(r"[^A-Za-z0-9._\-]+", "-", (hint or "").strip()).strip("-")[:60]

    if hint and "." in hint.rsplit("/", 1)[-1]:
        return hint.rsplit("/", 1)[-1]

    if kind == "png":
        return f"view-{str(view) or n}.png"

    return f"{kind or 'file'}-{n:02d}.{_EXT_BY_KIND.get(kind, 'bin')}"


def _walk_strings(node, path: tuple = ()):
    """Все строки JSON с путём до них: ищем base64/ссылки в любой форме ответа."""
    if isinstance(node, dict):
        for k, v in node.items():
            yield from _walk_strings(v, path + (str(k),))
    elif isinstance(node, list):
        for i, v in enumerate(node):
            yield from _walk_strings(v, path + (str(i),))
    elif isinstance(node, str):
        yield path, node


def collect_artifacts(data) -> list[Artifact]:
    """Собрать файлы из ответа движка, каким бы ни был его формат.

    Контракт ответа не зафиксирован (schema = object), поэтому:
      * понимаем base64-строки, ссылки и текстовые файлы;
      * вид определяем по имени/ключу и по магическим байтам;
      * дубликаты (по sha1) отбрасываем.
    """
    out: list[Artifact] = []
    seen: set[str] = set()
    total = 0
    n = 0

    def add(kind: str, hint: str, raw: bytes, view: str = "") -> None:
        nonlocal total, n
        if not raw:
            return

        digest = hashlib.sha1(raw).hexdigest()

        if digest in seen:
            return

        if kind == "json" and not hint.lower().endswith(".json"):
            return

        seen.add(digest)
        total += len(raw)
        n += 1

        if total > MAX_ARTIFACT_BYTES or n > MAX_ARTIFACTS:
            return

        out.append(Artifact(kind, _artifact_name(kind, hint, n, view), raw, view))

    for path, value in _walk_strings(data):
        key = "/".join(path)
        hint = " ".join(path).lower()

        if value.startswith(("http://", "https://")):
            # ссылку не качаем здесь (нет контекста), помечаем для отдельного этапа
            continue

        raw = _b64_to_bytes(value)

        if raw is None:
            continue

        kind = _kind_of(raw, hint)

        if kind == "json":
            continue

        view = ""
        m = re.search(r"(view|render|angle|png|image)[^0-9]{0,3}(\d{1,2})", hint)

        if m:
            view = m.group(2)

        add(kind, hint, raw, view)

    return out


def _first(data: dict, keys: tuple, default=None):
    for k in keys:
        if isinstance(data, dict) and k in data and data[k] not in (None, "", [], {}):
            return data[k]

    return default


def parse_report(data: dict) -> dict:
    """Отчёт движка → наш формат (габарит, объём, тела, стресс, предупреждения)."""
    report: dict = {}

    # --- движок о себе
    engine = _first(data, ("engine", "engine_info", "service")) or {}
    services = _first(data, ("services",), None)
    version = _first(data, ("version", "engine_version", "cad_version"), "")

    engine_info: dict = {}

    if isinstance(engine, dict):
        engine_info.update(engine)
    elif engine:
        engine_info["title"] = str(engine)

    if version and "version" not in engine_info:
        engine_info["version"] = str(version)

    if isinstance(services, dict):
        engine_info["services"] = services

    for key in ("duration_ms", "duration", "elapsed_ms", "elapsed_sec"):
        if key in data:
            value = data[key]

            if key.endswith("_sec"):
                value = float(value) * 1000

            engine_info["duration_ms"] = int(value)
            break

    if engine_info:
        report["engine"] = engine_info

    # --- геометрия
    geo = _first(data, ("geometry", "geo", "metrics", "shape"), {}) or {}
    geometry: dict = {}

    if isinstance(geo, dict):
        bbox = _first(geo, ("bbox", "bounding_box", "size", "dimensions", "extents"))

        if isinstance(bbox, dict):
            bbox = [bbox.get(k) for k in ("x", "y", "z") if bbox.get(k) is not None]
        elif isinstance(bbox, list) and len(bbox) == 2:
            bbox = [abs(float(bbox[1][i]) - float(bbox[0][i])) for i in range(min(3, len(bbox[0])))]

        if isinstance(bbox, list) and bbox:
            geometry["bbox"] = [round(float(x), 3) for x in bbox[:3]]

        vol = _first(geo, ("volume", "vol", "volume_mm3"))

        if vol is not None:
            try:
                geometry["volume"] = round(float(vol), 3)
            except (TypeError, ValueError):
                pass

        area = _first(geo, ("area", "surface_area"))

        if area is not None:
            try:
                geometry["area"] = round(float(area), 3)
            except (TypeError, ValueError):
                pass

        solids = _first(geo, ("solids", "num_solids", "bodies", "n_solids", "solid_count"))

        if solids is not None:
            try:
                geometry["solids"] = int(solids)
            except (TypeError, ValueError):
                pass

        faces = _first(geo, ("faces", "num_faces", "n_faces"))

        if faces is not None:
            try:
                geometry["faces"] = int(faces)
            except (TypeError, ValueError):
                pass

    # некоторые движки кладут габарит/объём в корень ответа
    for key, dst in (("volume", "volume"), ("bbox", "bbox"), ("dimensions", "bbox"), ("solids", "solids")):
        if dst not in geometry and key in data:
            value = data[key]

            if dst == "bbox" and isinstance(value, list) and value:
                geometry["bbox"] = [round(float(x), 3) for x in value[:3]]
            elif dst == "volume":
                try:
                    geometry["volume"] = round(float(value), 3)
                except (TypeError, ValueError):
                    pass
            elif dst == "solids":
                try:
                    geometry["solids"] = int(value)
                except (TypeError, ValueError):
                    pass

    if geometry:
        report["geometry"] = geometry

    # --- проверка (компиляция/геометрия)
    verify = _first(data, ("verify", "validation", "checks", "verification"), {}) or {}
    verify_out: dict = {}

    if isinstance(verify, dict):
        if "ok" in verify:
            verify_out["ok"] = bool(verify["ok"])

        for key in ("warnings", "problems", "issues", "errors"):
            if isinstance(verify.get(key), list) and verify[key]:
                verify_out.setdefault("warnings", []).extend(verify[key])
    elif isinstance(verify, list):
        verify_out["warnings"] = [v for v in verify if v]

    # --- стресс-тест параметров
    stress = _first(data, ("stress", "stress_test", "stress_test_results", "bounds"), None)
    stress_out: dict = {}

    if isinstance(stress, dict):
        stress_out["ok"] = bool(stress.get("ok", stress.get("passed", False)))
        cases = stress.get("cases") or stress.get("results") or stress.get("runs") or []

        if isinstance(cases, list):
            stress_out["cases"] = [_norm_case(c) for c in cases]

        if isinstance(stress.get("errors"), list) and stress["errors"]:
            stress_out.setdefault("warnings", []).extend(stress["errors"])
    elif isinstance(stress, list):
        stress_out["cases"] = [_norm_case(c) for c in stress]
        stress_out["ok"] = all(c.get("ok") for c in stress_out["cases"]) if stress_out["cases"] else False

    warnings = _first(data, ("warnings", "problems", "issues"), [])
    all_warnings: list = []

    if isinstance(warnings, list):
        all_warnings.extend([w for w in warnings if w])

    if isinstance(verify_out.get("warnings"), list):
        all_warnings.extend(verify_out.pop("warnings"))

    if isinstance(stress_out.get("warnings"), list):
        all_warnings.extend(stress_out.pop("warnings"))

    if all_warnings:
        report["warnings"] = all_warnings[:12]

    if verify_out:
        report["verify"] = verify_out

    if stress_out:
        report["stress"] = stress_out

    return report


def _norm_case(case) -> dict:
    """Один случай стресс-теста → {params, ok, error}."""
    if not isinstance(case, dict):
        return {"params": {}, "ok": False, "error": str(case)[:200]}

    params = case.get("params") or case.get("parameters") or case.get("values") or {}
    ok = bool(case.get("ok", case.get("passed", case.get("success", False))))
    err = case.get("error") or case.get("message") or case.get("detail") or ""

    return {"params": params if isinstance(params, dict) else {}, "ok": ok,
            "error": str(err)[:300], "duration_ms": int(case.get("duration_ms") or 0)}


def engine_error(data, http_status: int, text: str) -> str:
    """Человеческая причина отказа движка (владелец должен понять, что делать)."""
    detail = ""

    if isinstance(data, dict):
        detail = str(_first(data, ("error", "detail", "message", "reason"), "") or "")

        if not detail and isinstance(data.get("detail"), list):
            try:
                detail = "; ".join(str(d.get("msg", d)) for d in data["detail"][:4] if isinstance(d, dict))
            except Exception:  # noqa: BLE001
                detail = str(data["detail"])[:300]

    if not detail:
        detail = (text or "").strip()[:300]

    if http_status in (401, 403):
        return ("Движок не принял ключ (NJ643_CAD_API_KEY или общий TOOLFORGE_API_KEY). "
                f"Проверьте значение в workers/.env и перезапустите воркер. Ответ движка: {detail or http_status}")

    if http_status == 0:
        # Не ответ движка, а сетевая беда (или ключ не задан): текст уже готов
        # и содержит подсказку — не портим его припиской «HTTP 0».
        return detail or "Движок недоступен"

    if http_status == 422:
        return f"Движок отклонил запрос (422): {detail}"

    return f"Движок ответил HTTP {http_status}: {detail}"


async def engine_call(path: str, payload: dict | None, timeout: float | None = None,
                      method: str = "POST") -> tuple[bool, int, dict | str, float]:
    """Запрос к движку. Возвращает (ok, http_status, data, seconds).

    Сетевые сбои (DNS, TLS, обрыв) повторяются `NJ643_CAD_RETRIES` раз с паузой:
    край движка капризен, а «одна попытка» превращалась в «движок недоступен» на
    ровном месте. Если и это не помогло, третьим элементом приходит
    EngineUnreachable с разбором причины и подсказкой — это НЕ ошибка кода.
    """
    if not _engine_url():
        return False, 0, EngineUnreachable(
            "Внешний CAD-движок не настроен: задайте " + ENGINE_SETTING
            + " в workers/.env на сервере воркера и перезапустите его — это не ошибка кода модели."), 0.0

    url = _engine_url() + path

    if not _engine_key_set():
        return False, 0, EngineUnreachable(
            "Внешний CAD-движок не настроен: задайте NJ643_CAD_API_KEY (или общий TOOLFORGE_API_KEY) "
            "в workers/.env и перезапустите воркер — это не ошибка кода модели."), 0.0

    attempts = _engine_retries()
    t0 = time.monotonic()

    for attempt in range(1, attempts + 1):
        try:
            kwargs: dict = {"timeout": timeout or _timeout()}
            proxy = _engine_proxy()

            if proxy:
                kwargs["proxy"] = proxy

            verify = _engine_verify()

            if verify is not True:
                kwargs["verify"] = verify

            async with httpx.AsyncClient(**kwargs) as client:
                if method == "GET":
                    r = await client.get(url, headers=_engine_headers())
                else:
                    r = await client.post(url, json=payload, headers=_engine_headers())
        except Exception as e:  # noqa: BLE001 — движок внешний: любая сетевая беда = понятный текст
            if attempt < attempts:
                delay = _retry_delay(attempt)
                log.warning("nj643: движок не ответил (%d/%d) %s — %s, повтор через %.0f с",
                            attempt, attempts, _free_path(url), type(e).__name__, delay)
                await asyncio.sleep(delay)
                continue

            dt = time.monotonic() - t0
            return False, 0, EngineUnreachable(_net_reason(e, url, attempts)), dt

        dt = time.monotonic() - t0
        text = r.text

        try:
            data = r.json()
        except ValueError:
            data = text

        if r.status_code == 200:
            if isinstance(data, dict) and (data.get("ok") is False or data.get("success") is False):
                return False, 200, data, dt

            return True, 200, data, dt

        return False, r.status_code, data, dt

    # недостижимо: цикл всегда либо возвращает, либо делает последнюю попытку
    return False, 0, EngineUnreachable("Движок недоступен (не выполнено ни одной попытки)"), time.monotonic() - t0


async def download_artifact(url: str, limit: int = MAX_FILE_BYTES) -> bytes | None:
    """Скачать файл по ссылке из ответа движка (только http/https, с потолком)."""
    if not url.startswith(("http://", "https://")):
        return None

    try:
        async with httpx.AsyncClient(timeout=120, follow_redirects=True) as client:
            r = await client.get(url, headers=_engine_headers())

            if r.status_code != 200:
                return None

            if len(r.content) > limit:
                return None

            return r.content
    except Exception as e:  # noqa: BLE001
        log.warning("nj643: файл по ссылке не скачался (%s): %s", _free_path(url), e)
        return None


def schema_for_engine(schema_definition) -> dict:
    """Схема параметров в форме, которую ждёт движок: {'parameters': {...}}."""
    if not isinstance(schema_definition, dict):
        return {}

    if isinstance(schema_definition.get("parameters"), dict):
        return {"parameters": schema_definition["parameters"]}

    return {"parameters": schema_definition}


def normalize_schema(schema_definition) -> dict:
    """Схема в НАШЕМ формате хранения: {'parameters': {...}} (или {})."""
    if not isinstance(schema_definition, dict):
        return {}

    if isinstance(schema_definition.get("parameters"), dict):
        return {"parameters": schema_definition["parameters"]}

    return {"parameters": schema_definition}


# Значение по умолчанию у kwargs.get(...) может быть и числом, и выражением
# («w / 2», «thick * 0.8»). Подставляем заказанное значение в оба случая, но не
# трогаем строковые значения и всё, где есть скобки или запятые (min(a, b) и т.п.):
# такие связи пусть решает сам код.
_KWARG_DEFAULT_RE = re.compile(
    r"""(kwargs\.get\(\s*['"](?P<name>[A-Za-z_][A-Za-z0-9_]*)['"]\s*,\s*)"""
    r"""(?!['"])(?P<expr>[^(),]*?)(?P<tail>\s*\))""")


def _value_text(value) -> str | None:
    """Число → текст для подстановки: целые без «.0», дробные — как есть."""
    try:
        num = float(value)
    except (TypeError, ValueError):
        return None

    return str(int(num)) if num == int(num) else repr(round(num, 4))


def code_with_values(code: str, values: dict | None) -> str:
    """Подставить заказанные значения в значения по умолчанию `kwargs.get(...)`.

    У движка НЕТ поля «значения параметров» — проверено по openapi.json:
    `/v1/cad/verify-and-build` принимает только `code`, `schema_definition`,
    `run_stress_test`, `exports`, `linear_deflection`. То есть ползунки влияли
    на подписи в схеме, но не на геометрию: владелец (28.09.2026) «двигаю
    ползунки, делаю новую ревизию, но вижу созданную модель со старыми
    размерами». Поэтому заказанный размер подставляем и в default схемы, и в
    сам код — сборка обязана быть с теми размерами, что человек выбрал.
    """
    if not isinstance(values, dict) or not values:
        return code

    def sub(m):
        name = m.group("name")

        if name not in values:
            return m.group(0)

        text = _value_text(values[name])

        return m.group(0) if text is None else m.group(1) + text + m.group("tail")

    return _KWARG_DEFAULT_RE.sub(sub, code or "")


def schema_with_values(schema_definition, values: dict | None) -> dict:
    """Копия схемы, где default = заказанные значения (для запроса в движок).

    Движок собирает деталь на значениях по умолчанию из схемы, а min/max
    использует для стресс-теста. Схему детали на сайте НЕ меняем: у владельца
    остаётся «вернуть размеры ИИ», а также исходные значения по умолчанию.
    """
    schema = normalize_schema(schema_definition) if isinstance(schema_definition, dict) else {}
    parameters = schema.get("parameters") if isinstance(schema, dict) else None

    if not isinstance(parameters, dict) or not parameters:
        return schema_definition

    out: dict = {}

    for name, spec in parameters.items():
        item = dict(spec) if isinstance(spec, dict) else {"default": spec}

        if isinstance(values, dict) and name in values:
            text = _value_text(values[name])

            if text is not None:
                item["default"] = float(text) if float(text) != int(float(text)) else int(float(text))

        out[name] = item

    return {"parameters": out}


def _values_note(values: dict | None, limit: int = 4) -> str:
    """Строка «width = 100, thickness = 8» для рапорта прогресса."""
    if not isinstance(values, dict) or not values:
        return ""

    bits: list[str] = []

    for name, value in list(values.items())[:limit]:
        text = _value_text(value)
        bits.append(f"{name} = {text if text is not None else value}")

    return ", ".join(bits)


def params_defaults(schema_definition) -> dict:
    """Значения параметров по умолчанию из схемы."""
    params = normalize_schema(schema_definition).get("parameters", {})
    out: dict = {}

    for name, spec in (params or {}).items():
        if isinstance(spec, dict) and "default" in spec:
            out[name] = spec["default"]

    return out


# ---------------------------------------------------------------------------
# Конвейер сборки
# ---------------------------------------------------------------------------

async def _build_once(ctx, code: str, schema_definition: dict, params: dict,
                      exports: list, deflection: float, stress: bool,
                      sink: list) -> tuple[dict, list[Artifact], str]:
    """Один запрос к движку. Возвращает (report, artifacts, error)."""
    # Заказанные значения уходят в движок ДВУМЯ путями: default в схеме и
    # значения по умолчанию в kwargs.get(...) кода. Так сборка совпадает с
    # ползунками независимо от того, что движок считает главным.
    payload = {
        "code": code_with_values(code, params),
        "schema_definition": schema_for_engine(schema_with_values(schema_definition, params)),
        "run_stress_test": bool(stress),
        "exports": exports or ["step", "stl", "glb", "views"],
        "linear_deflection": float(deflection or 0.025),
    }

    ok, status, data, dt = await engine_call("/v1/cad/verify-and-build", payload)

    if not ok:
        # Недоступность движка отдаём как есть: текст уже человеческий, а признак
        # EngineUnreachable нужен выше, чтобы НЕ просить LLM «починить код».
        if is_unreachable(data):
            return {"http_status": status, "duration_ms": int(dt * 1000), "unreachable": True}, [], data

        err = engine_error(data, status, data if isinstance(data, str) else "")
        return {"http_status": status, "duration_ms": int(dt * 1000)}, [], err

    if not isinstance(data, dict):
        return {"http_status": status, "duration_ms": int(dt * 1000)}, [], \
            f"Движок вернул не JSON: {str(data)[:200]}"

    report = parse_report(data)
    report["http_status"] = status
    engine_info = report.setdefault("engine", {})
    engine_info["engine_duration_ms"] = int(engine_info.get("duration_ms") or 0)
    engine_info["duration_ms"] = int(dt * 1000)   # наш замер: сеть + сборка
    report["response_keys"] = sorted(list(data.keys()))[:40]

    artifacts = collect_artifacts(data)

    # ссылки на файлы — качаем отдельно
    for path, value in _walk_strings(data):
        if not value.startswith(("http://", "https://")):
            continue

        ext = value.rsplit("?", 1)[0].rsplit(".", 1)[-1].lower()

        if ext not in _EXT_KIND or _EXT_KIND[ext] in ("json",):
            continue

        hint = " ".join(path)

        if any(a.kind == _EXT_KIND[ext] and a.name.endswith("." + ext) for a in artifacts):
            continue

        raw = await download_artifact(value)

        if raw:
            kind = _kind_of(raw, ext)
            artifacts.append(Artifact(kind, _artifact_name(kind, f"{hint}.{ext}", len(artifacts) + 1), raw, ""))

    return report, artifacts, ""


async def engine_preflight(ctx) -> None:
    """Быстрая проверка «движок вообще отвечает» ДО обращения к LLM.

    Живой случай 26.09: владелец 6 минут смотрел на сборку, которая не могла
    получиться — край движка закрывал соединение, а воркер узнавал об этом только
    после того, как модель написала код (и ещё дважды просил её «починить» то,
    что не ломалось). `/health` стоит миллисекунды, ключа не требует, зато
    сетевая беда выясняется сразу и с подсказкой. Отключается NJ643_PREFLIGHT=0.
    """
    if not _preflight_enabled() or not _engine_key_set():
        return

    ok, status, data, dt = await engine_call("/health", None, timeout=_health_timeout(), method="GET")

    if ok or status:
        log.info("nj643: движок отвечает за %.2f с", dt)
        return

    raise CadBuildError(str(data))


async def _do_build(ctx, payload: dict, sink: list) -> dict:
    """Сборка детали: генерация/правка кода → движок → (починка) → файлы → сайт."""
    part_id = int(payload.get("part_id") or 0)
    rev_id = int(payload.get("revision_id") or 0)
    action = str(payload.get("action") or "generate")
    code_id = str(payload.get("code") or "")
    rev = int(payload.get("rev") or 1)
    prompt = str(payload.get("prompt") or "").strip()
    instruction = str(payload.get("instruction") or "").strip()
    params = payload.get("params") if isinstance(payload.get("params"), dict) else {}
    exports = payload.get("exports") if isinstance(payload.get("exports"), list) else ["step", "stl", "glb", "views"]
    deflection = payload.get("deflection") or 0.025
    stress = payload.get("stress", True) is not False

    if not part_id or not rev_id:
        raise RuntimeError("nj643: в задаче нет part_id/revision_id")

    # Сначала «жив ли движок» — иначе токены и время уйдут на код, который некуда послать.
    await ctx.progress("Проверяю движок", ENGINE_WORD + " отвечает?")
    await engine_preflight(ctx)

    code = _clean_code(str(payload.get("code_py") or ""))
    schema_definition: dict = {}
    meta: dict = {}
    schema_notes: list[str] = []       # что подправили в схеме по коду
    code_notes: list[str] = []         # расхождения кода и схемы — модели в правку
    attempts: list[dict] = []
    deadline = time.monotonic() + _budget()

    # --- 1. код: готовый (rebuild) или от LLM (generate/revise)
    if action == "rebuild" and code:
        await ctx.progress("Пересобираю по параметрам",
                           _values_note(params) or f"ревизия {rev}")
        schema_definition = params_schema_from(payload)
    elif action == "revise" and code:
        await ctx.progress("Правлю модель словами", instruction[:80] or "правка")
        await ctx.heartbeat()

        text = await _llm(ctx, [
            {"role": "system", "content": SYSTEM_FIX},
            {"role": "user", "content": (
                f"Текущий код модели:\n```python\n{code}\n```\n\n"
                f"Схема параметров: {json.dumps(schema_for_engine(params_schema_from(payload)), ensure_ascii=False)}\n\n"
                f"Просьба пользователя: {instruction}\n\n"
                "Измени код по просьбе, сохранив работоспособность и параметричность. Верни JSON.")},
        ], "правка модели", sink)

        meta = _parse_json(text)
        new_code, problem = check_code(str(meta.get("code") or ""))

        if problem:
            # одна повторная попытка с прямым указанием на проблему
            await ctx.progress("Код не прошёл проверку", problem[:80])
            text = await _llm(ctx, [
                {"role": "system", "content": SYSTEM_FIX},
                {"role": "user", "content": (
                    f"Код модели:\n```python\n{code}\n```\n\nПросьба: {instruction}\n\n"
                    f"Твоя правка отклонена: {problem}. Исправь и верни JSON.")},
            ], "правка модели (повтор)", sink)
            meta = _parse_json(text) or meta
            new_code, problem = check_code(str(meta.get("code") or ""))

        if problem:
            raise RuntimeError(f"Правка не удалась: {problem}")

        code = new_code

        # Второй прогон — метаинформация по НОВОМУ коду: правка могла завести или
        # переименовать параметры, а схема на странице должна совпадать с кодом.
        if _meta_enabled() and time.monotonic() < deadline:
            meta = merge_meta(meta, await _ask_meta(ctx, prompt or instruction, code,
                                                    params_schema_from(payload), sink))

        schema_definition, schema_notes, code_notes = _align_schema_and_notes(
            code, meta.get("schema_definition"), params_schema_from(payload))
    else:
        # generate (или rebuild без кода — тогда генерируем заново)
        await ctx.progress("Пишу модель (ИИ)", f"«{prompt[:60]}»")
        await ctx.heartbeat()

        text = await _llm(ctx, [
            {"role": "system", "content": SYSTEM_CODE},
            {"role": "user", "content": _user_prompt(prompt, params)},
        ], "код модели", sink)

        meta = _parse_json(text)
        code, problem = check_code(str(meta.get("code") or ""))

        if problem:
            await ctx.progress("Код не прошёл проверку", problem[:80])
            text = await _llm(ctx, [
                {"role": "system", "content": SYSTEM_CODE},
                {"role": "user", "content": _user_prompt(prompt, params,
                    extra=f"Твой предыдущий ответ отклонён: {problem}. Верни корректный JSON.")},
            ], "код модели (повтор)", sink)
            meta = _parse_json(text) or meta
            code, problem = check_code(str(meta.get("code") or ""))

        if problem:
            raise RuntimeError(f"Модель не выдала рабочий код: {problem}")

        # Второй прогон — метаинформация по готовому коду: схема параметров,
        # название, назначение, замечания. Идёт на своей модели (NJ643_META_MODEL,
        # по умолчанию — модель модуля) и не критичен: не ответит — схему соберём из кода.
        if _meta_enabled() and time.monotonic() < deadline:
            meta = merge_meta(meta, await _ask_meta(ctx, prompt, code,
                                                    params_schema_from(payload), sink))

        schema_definition, schema_notes, code_notes = _align_schema_and_notes(
            code, meta.get("schema_definition"), params_schema_from(payload))

    if not schema_definition:
        schema_definition = normalize_schema(params_schema_from(payload))

    values = {**params_defaults(schema_definition), **(params or {})}

    # --- 2. движок: проверка кода, геометрия, стресс-тест, экспорт
    await ctx.progress("Проверяю и собираю (движок)", "build123d: verify-and-build")
    await ctx.heartbeat()

    report: dict = {}
    artifacts: list[Artifact] = []
    error = ""
    attempt = 0
    max_attempts = 1 + _fix_attempts()

    # Удачная сборка запоминается: если следующая правка (например, по крайним
    # значениям) развалит модель, владелец всё равно получит рабочую деталь,
    # а не пустую страницу. Раньше последняя неудачная попытка затирала предыдущую.
    best: tuple[dict, list[Artifact], str, dict, dict] | None = None
    extremes_tried = False
    sizes_tried = False

    while attempt < max_attempts:
        attempt += 1
        report, artifacts, error = await _build_once(ctx, code, schema_definition, values,
                                                     exports, deflection, stress, sink)

        attempts.append({"attempt": attempt, "ok": not error and bool(artifacts),
                         "error": error[:300], "fix_prompt": ""})

        if not error and artifacts:
            best = (report, artifacts, code, schema_definition, values)

            # Габарит разошёлся с размерами из описания: движок об этом молчит (он
            # проверяет геометрию, а не смысл просьбы), а владелец получает деталь
            # 60 мм там, где просил 100. Один раз просим модель привести размеры,
            # дальше не настаиваем — рабочая деталь уже собрана.
            sizes = _size_mismatch(prompt, report) if prompt else []

            if sizes:
                report["size_notes"] = sizes

            failing = _stress_failures(report, limit=4)
            ready = (attempt >= max_attempts or time.monotonic() > deadline)

            if sizes and not sizes_tried and not ready:
                sizes_tried = True
                await ctx.progress("Собралось, но размеры не совпали с описанием", sizes[0][:80])
                await ctx.heartbeat()
                error = ("Габарит детали не совпал с размерами из описания: "
                         + "; ".join(sizes[:3])
                         + ". Приведи деталь к размерам из описания (это значения по умолчанию "
                           "параметров), сохранив крепёж, фаски и рёбра.")
            elif not failing or extremes_tried or ready:
                break
            else:
                # Собралось — но крайние значения параметров ломают модель: владелец
                # крутит ползунок в край и получает ошибку. Один раз пробуем починить,
                # дальше не настаиваем (у нас уже есть рабочая деталь).
                extremes_tried = True
                await ctx.progress("Собралось, но крайние значения ломают модель", failing[0][:80])
                await ctx.heartbeat()
                error = "Модель строится при текущих значениях, но падает на min/max параметров"
        else:
            # Сеть до движка — не повод просить LLM «починить код»: модель тут ни при
            # чём, а владелец платит временем и токенами (живой случай 26.09).
            if is_unreachable(error):
                report["unreachable"] = str(error)
                log.warning("nj643: движок недоступен, повтор и починка кода отменены")
                break

            if attempt >= max_attempts or time.monotonic() > deadline:
                if time.monotonic() > deadline:
                    error = (error.strip() + " " if error.strip() else "") + \
                        f"Прогон остановлен по бюджету времени ({int(_budget())} с): уменьшите точность сетки или упростите деталь."

                break

        # --- просим LLM починить код по тексту ошибки движка
        await ctx.progress("Движок вернул ошибку", (error or "нет файлов модели")[:80])
        await ctx.heartbeat()

        fix_msg = _fix_message(code, report, error, code_notes)

        text = await _llm(ctx, [
            {"role": "system", "content": SYSTEM_FIX},
            {"role": "user", "content": fix_msg},
        ], f"починка кода (попытка {attempt})", sink)

        fixed = _parse_json(text)
        new_code, problem = check_code(str(fixed.get("code") or ""))

        if problem:
            attempts[-1]["fix_prompt"] = f"правка отклонена: {problem}"
            continue

        attempts[-1]["fix_prompt"] = (fix_msg.split("ошибка движка:", 1)[-1] or "").strip()[:300]
        code = new_code

        if isinstance(fixed.get("schema_definition"), dict) and fixed["schema_definition"]:
            schema_definition = normalize_schema(fixed["schema_definition"])

        # Правка завела параметр, которого в схеме нет: ползунка для него не было бы.
        # Мета-проход зовём ТОЛЬКО в этом случае — на каждой правке это лишние токены.
        if schema_missing_params(code, schema_definition) and _meta_enabled() \
                and time.monotonic() < deadline:
            got = await _ask_meta(ctx, prompt, code, schema_definition, sink)

            if got:
                meta = merge_meta(meta, got)

                if isinstance(got.get("schema_definition"), dict) and got["schema_definition"]:
                    schema_definition = normalize_schema(got["schema_definition"])

        schema_definition, schema_notes, code_notes = _align_schema_and_notes(code, schema_definition)
        values = {**params_defaults(schema_definition), **(params or {})}

        if fixed.get("title") and not meta:
            meta = fixed

    if error and not artifacts and best:
        # Все попытки после удачной сборки провалились (например, правка границ
        # сломала геометрию) — отдаём владельцу то, что уже собиралось.
        report, artifacts, code, schema_definition, values = best
        error = ""
        report["kept_previous"] = ("последняя правка не удалась — оставлена предыдущая удачная сборка")
        log.warning("nj643: правка не удалась, оставлена предыдущая удачная сборка")

    report["attempts"] = attempts

    # Замечания к коду и выравнивание схемы — в отчёт: видно, что именно мы
    # поправили и что осталось на совести модели (диагностика без домыслов).
    if code_notes:
        report["code_notes"] = code_notes[:10]

    if schema_notes:
        report["schema_notes"] = schema_notes[:10]

    if report.get("size_notes"):
        report["size_notes"] = list(report["size_notes"])[:3]

    if error and not artifacts:
        # Код и схему отдаём даже при ошибке — владелец не теряет работу модели
        raise CadBuildError(error, code=code, schema_definition=schema_definition,
                            params=values, report=report, attempts=attempts)

    if not artifacts:
        keys = report.get("response_keys") or []
        raise CadBuildError("Движок ответил, но не вернул файлов модели. Ключи ответа: "
                            + (", ".join(str(k) for k in keys) if keys else "нет"),
                            code=code, schema_definition=schema_definition,
                            params=values, report=report, attempts=attempts)

    # --- 3. файлы на сайт
    uploaded = 0
    total = 0

    for a in artifacts:
        await ctx.heartbeat()

        ok = await _upload(ctx, part_id, rev_id, a.kind, a.name, a.raw, a.view)

        if ok:
            uploaded += 1
            total += len(a.raw)

    if uploaded == 0:
        raise CadBuildError("Файлы собраны, но сайт их не принял — проверьте WORKER_API_TOKEN "
                            "и права на public_html/storage/nj643",
                            code=code, schema_definition=schema_definition,
                            params=values, report=report, attempts=attempts)

    # --- 4. итог на сайт
    values = {**params_defaults(schema_definition), **(params or {})}
    report["params"] = values

    if meta.get("summary"):
        report["summary"] = str(meta["summary"])[:500]

    if meta.get("notes"):
        report["notes"] = meta["notes"] if isinstance(meta["notes"], list) else [str(meta["notes"])]

    if meta.get("checks"):
        report["checks"] = meta["checks"] if isinstance(meta["checks"], list) else [str(meta["checks"])]

    if meta.get("title"):
        report["title"] = str(meta["title"])[:120]

    ok, reason, _j = await _api_post("/result", {
        "part_id": str(part_id),
        "revision_id": str(rev_id),
        "ok": "1",
        "code": code,
        "schema_definition": schema_definition,
        "params": values,
        "report": report,
        "attempts": str(attempt),
        "http_status": str(report.get("http_status") or 200),
        "duration_ms": str(int((report.get("engine") or {}).get("duration_ms") or 0)),
        "engine": report.get("engine") or {},
        "usage": sink,
    })

    if not ok:
        raise RuntimeError(f"Сайт не принял результат сборки: {reason}")

    log.info("nj643[%s]: собрано r%s — файлов %d, %.1f МБ, попыток %d",
             code_id, rev, uploaded, total / 1048576, attempt)

    return {"ok": True, "files": uploaded, "bytes": total, "attempts": attempt, "usage": sink}


def params_schema_from(payload: dict) -> dict:
    """Схема из задачи, если она там есть (воркер получает и schema, и values)."""
    schema = payload.get("schema_definition")

    if isinstance(schema, dict) and schema:
        return schema

    return {}


async def _do_check(ctx) -> dict:
    """Диагностика движка убрана вместе с кнопкой «Проверить движок».

    Владелец (27.09.2026): «CAD-движок проверяю… — убрать проверку». Заодно ушли
    и ручки /cad/api/engine*: держать их ради одного экрана смысла нет. Здесь
    остаётся вежливый финал для задач, поставленных ДО выкатки правки, — иначе
    такая задача уехала бы в общую сборку и упала бы на «нет part_id».
    """
    return {"ok": False, "error": "Проверка движка больше не нужна: состояние видно по журналу сборок "
                                  "в /cad/admin. Обновите страницу — кнопки проверки на сайте уже нет."}


async def handle(ctx, payload: dict) -> dict:
    """Точка входа канала nj643.cad."""
    action = str(payload.get("action") or "generate")
    sink: list = []

    try:
        if action == "check":
            return await _do_check(ctx)

        return await _do_build(ctx, payload, sink)
    except Exception as e:  # noqa: BLE001 — финал обязан быть понятным на сайте
        part_id = int(payload.get("part_id") or 0)
        rev_id = int(payload.get("revision_id") or 0)

        if action != "check" and part_id and rev_id:
            final = {
                "part_id": str(part_id),
                "revision_id": str(rev_id),
                "ok": "0",
                "error": str(e)[:1900],
                "usage": sink,
            }

            # «Найденное сохранится»: код модели и схему параметров отдаём и при
            # ошибке — иначе на странице нечего пересобирать, а токены потрачены.
            if isinstance(e, CadBuildError):
                if e.code:
                    final["code"] = e.code
                if e.schema_definition:
                    final["schema_definition"] = e.schema_definition
                if e.params:
                    final["params"] = e.params
                if e.report:
                    final["report"] = e.report
                if e.attempts:
                    final["attempts"] = str(len(e.attempts))

            ok, reason, _j = await _api_post("/result", final)

            if not ok and not _api_unset(reason):
                log.error("nj643: не смог сообщить об ошибке на сайт: %s", reason)

        raise
