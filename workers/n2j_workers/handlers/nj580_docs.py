"""
Хендлер nj580 «Документы организации»: сведения об организации → пакет
административных/кадровых документов в форматах DOCX + PDF (+ XLSX, ZIP).

Канал: nj580.generate (PHP: Service_Nj580_Orgdocs::_dispatch).

Конвейер (оркестрация LangGraph; если пакет не установлен — те же шаги
выполняются последовательно, режим идентичен):
  1. plan     — набор типов документов (LLM при doc_types=['auto'], иначе фикс);
  2. research — опционально DuckDuckGo-референсы (бережно: паузы NJ580_SEARCH_DELAY,
                число запросов NJ580_SEARCH_MAX — как в nj550);
  3. write    — на каждый документ: черновик (LLM, структурированный JSON:
                секции/абзацы/перечисления/таблицы) → проход-проверка (LLM:
                полнота, деловой стиль, непротиворечивость);
  4. build    — сборка файлов КОДОМ (не LLM): DOCX/PDF по ГОСТ Р 7.0.97-2016
                (workers/n2j_workers/docgen), таблицы дублируются в XLSX,
                реестр пакета + ZIP; выгрузка на сайт /docs/api/*.

Строки документов воркер создаёт/обновляет сам через /docs/api/doc (токен),
файлы — через /docs/api/upload. Затраты LLM: pg-режим — сразу в nj_580_usage,
http-режим — в job.result (дозапишет PHP).

Env (workers/.env): NJ580_DOC_MODEL, NJ580_PLAN_MODEL, NJ580_QA_MODEL,
NJ580_API_BASE (напр. https://n2j.ru/docs/api), NJ580_SITE_TOKEN,
NJ580_MAX_DOCS, NJ580_SEARCH_MAX, NJ580_SEARCH_DELAY.
Модели поддерживают провайдеров: "openrouter::google/…" / "модель" (провайдер
по умолчанию) / "" (дефолт провайдера) — см. workers/n2j_workers/llm.py.
"""
from __future__ import annotations

import asyncio
import json
import logging
import os
import time

log = logging.getLogger("n2j.nj580")

CHANNEL = "nj580.generate"


class Cancelled(Exception):
    """Сборка отменена администратором (флаг cancel в /docs/api/progress)."""

# --------------------------------------------------------------------------
# Каталог типов документов — ключи 1:1 с config/nj580_orgdocs.php (сайт).
# hint — что именно должен содержать документ (уходит в промпт).
# --------------------------------------------------------------------------

DOC_TYPES: dict[str, dict] = {
    "ptr": {"title": "Правила внутреннего трудового распорядка",
            "hint": "приём/увольнение, режим рабочего времени и отдыха, перерывы, "
                    "поощрения и взыскания, ответственность; ссылки на ТК РФ"},
    "oplata": {"title": "Положение об оплате труда и премировании",
               "hint": "системы оплаты, оклады/тарифы, премии и депремирование, "
                       "порядок и сроки выплаты, НДФЛ-оговорки, индексация"},
    "shtatnoe": {"title": "Штатное расписание (форма Т-3)",
                 "hint": "основное содержание — таблица: № п/п, наименование должности "
                         "(по структуре организации), количество штатных единиц, оклад, надбавки, примечание"},
    "dolzhnostnye": {"title": "Должностные инструкции",
                     "hint": "по каждой должности из структуры организации отдельный раздел: "
                             "общие положения, обязанности, права, ответственность, квалификация"},
    "trudovoy": {"title": "Типовой трудовой договор",
                 "hint": "реквизиты сторон, трудовая функция, режим, оплата, отпуск, "
                         "материальная ответственность, конфиденциальность, реквизиты и подписи"},
    "prikazy": {"title": "Пакет типовых приказов",
                "hint": "разделы: о приёме на работу, о переводе, о предоставлении отпуска, "
                        "об увольнении — каждый с шапкой организации и местом для подписи"},
    "grafik": {"title": "График отпусков (форма Т-7)",
               "hint": "таблица: должность, Ф.И.О., табельный номер, запланированные даты отпуска, "
                       "перенос, примечание; учёт очередности по ТК РФ"},
    "ohrana": {"title": "Положение об охране труда и пожарной безопасности",
               "hint": "обязанности работодателя и работников, инструктажи (вводный, повторный), "
                       "СИЗ, действия при пожаре, ответственные лица"},
    "instruktazh": {"title": "Журнал регистрации инструктажа на рабочем месте",
                    "hint": "таблица журнала: дата, Ф.И.О., должность, вид инструктажа, подписи "
                            "инструктируемого и инструктирующего; в начале — правила ведения"},
    "pdn": {"title": "Положение о персональных данных (152-ФЗ)",
            "hint": "перечень ПДн, цели и правовые основания обработки, сроки, права субъекта, "
                    "меры защиты, ответственный за обработку, передача третьим лицам"},
    "ib": {"title": "Политика информационной безопасности",
           "hint": "объекты защиты, доступы и пароли, рабочие устройства и почта, резервные копии, "
                   "реагирование на инциденты, ответственность"},
    "komtayna": {"title": "Положение о коммерческой тайне",
                 "hint": "перечень сведений, режим конфиденциальности, доступ, обязанность "
                         "неразглашения (в т.ч. после увольнения), ответственность"},
    "polozhenie": {"title": "Положение об организации",
                   "hint": "наименование и статус, цели деятельности, органы управления, "
                           "структура, компетенция директора, реорганизация и ликвидация"},
    "otdely": {"title": "Положения об отделах",
               "hint": "по каждому подразделению из структуры: задачи, функции, руководство, "
                       "взаимодействие с другими подразделениями, ответственность"},
    "reglament": {"title": "Регламент внутреннего документооборота",
                  "hint": "виды документов, регистрация, согласование, сроки исполнения, "
                          "номенклатура дел, электронный документооборот, хранение"},
    "reestr": {"title": "Реестр обязательных документов организации",
               "hint": "чек-лист-таблица: документ, срок хранения, ответственный, периодичность "
                       "актуализации; ссылки на требования ТК РФ и 152-ФЗ"},
    "sto_delo": {"title": "Инструкция по делопроизводству",
                 "hint": "стандарт организации (по ГОСТ Р 1.5): требования к оформлению документов "
                         "(бланки, шрифты, поля по ГОСТ Р 7.0.97), порядок согласования и подписания, "
                         "регистрация, сроки хранения, порядок внесения изменений в ЛНА"},
    "nomenklatura": {"title": "Номенклатура дел организации",
                     "hint": "основное содержание — таблица: индекс дела, заголовок дела, количество томов, "
                             "срок хранения и статья по перечню, примечание; дела формируются по структуре "
                             "организации и видовым документам; правила формирования и передачи в архив"},
    "dogovory": {"title": "Регламент согласования договоров",
                 "hint": "этапы согласования с ответственными и сроками, матрица согласования (таблица: "
                         "этап, ответственный, срок, результат), порядок подписания, учёт и хранение"},
    "kadr_instr": {"title": "Инструкция по кадровому делопроизводству",
                   "hint": "порядок приёма/перевода/увольнения, перечень кадровых документов и сроки их "
                           "оформления, учётные журналы, ответственность за ведение"},
    "zhurnal_prikazy": {"title": "Журнал регистрации приказов по личному составу",
                        "hint": "первая страница — ОБЛОЖКА журнала: наименование организации, название журнала, "
                                "год(ы) ведения, ответственный; далее правила ведения и таблица журнала: "
                                "№ записи, дата приказа, вид приказа, Ф.И.О., основание, подпись"},
    "zhurnal_pochta": {"title": "Журнал входящей и исходящей корреспонденции",
                       "hint": "первая страница — ОБЛОЖКА журнала: организация, название, год ведения; далее "
                               "правила регистрации и таблица: №, дата, корреспондент, содержание, "
                               "исполнитель, резолюция, исходящий номер"},
    "zhurnal_siz": {"title": "Журнал учёта выдачи СИЗ",
                    "hint": "первая страница — ОБЛОЖКА журнала: организация, название, год ведения; далее "
                            "правила выдачи и таблица: Ф.И.О., должность, наименование СИЗ, количество, "
                            "дата выдачи, срок носки, подпись получателя"},
}

_XLSX_FORCE = {"shtatnoe", "grafik", "instruktazh", "reestr",
               "nomenklatura", "zhurnal_prikazy", "zhurnal_pochta", "zhurnal_siz"}

SYSTEM_DOC = (
    "Ты — опытный специалист по делопроизводству и кадровому администрированию "
    "российских организаций. Пишешь ПОЛНЫЙ готовый документ на русском языке в "
    "деловом стиле. Верни СТРОГО один JSON-объект без markdown-обёрток, по схеме: "
    '{"title": "название документа", "subtitle": "вид документа (напр. ПОЛОЖЕНИЕ, ПРИКАЗ) или пусто", '
    '"needs_title_page": true|false, "approve_line": "многострочный гриф «УТВЕРЖДАЮ» с должностью и подписью '
    'или пусто", "city_year": "город и текущий год из задания", "sections: [{"heading": "1. Название раздела", "blocks": ['
    '{"type": "p", "text": "абзац"}, '
    '{"type": "ul", "items": ["пункт перечисления"]}, '
    '{"type": "table", "caption": "название таблицы", "columns": ["Колонка"], "rows": [["ячейка"]]}]]}]. '
    "Правила: 4–9 разделов с нумерацией в заголовках; таблицы — для штатного расписания, "
    "графиков, журналов и реестров (заполненные примеры данных организации); не используй "
    "курсив; формулировки готовые к печати, без «вставьте сюда» кроме мест для Ф.И.О./подписей; "
    "ссылайся на нормы ТК РФ / 152-ФЗ там, где уместно. "
    "ФАКТЫ ОБ ОРГАНИЗАЦИИ (названия, должности, структура, Ф.И.О., адреса, даты) бери "
    "ТОЛЬКО из предоставленных сведений и сырых данных — ничего не выдумывай: если данных "
    "нет, ставь место для заполнения (____________ или Ф.И.О. ____________), а не своё "
    "предположение. Если наименование организации НЕИЗВЕСТНО (не указано или это число) — "
    "ВЕЗДЕ вместо названия пиши «____________»: ПРИДУМЫВАТЬ НАЗВАНИЯ КАТЕГОРИЧЕСКИ "
    "ЗАПРЕЩЕНО (никаких ООО «Альфа», ИП Иванов и т.п.). approve_line — СТРОГО многострочный: "
    "каждая часть с новой строки (слово УТВЕРЖДАЮ, должность, организация, линия для "
    "подписи с Ф.И.О., дата). Только JSON."
)

SYSTEM_QA = (
    "Ты — редактор-корректор деловой документации. Проверь JSON документа: полнота "
    "для заявленного типа, деловой стиль, непротиворечивость, корректные реквизиты "
    "(гриф утверждения, подписи), отсутствие пустых обещаний и заглушек. Если в тексте "
    "встречается наименование организации, которого НЕТ в предоставленных фактах "
    "(придуманное), замени его на «____________». Исправь недостатки и верни "
    "УЛУЧШЕННЫЙ JSON ЦЕЛИКОМ по той же схеме. Только JSON."
)

SYSTEM_PLAN = (
    "Ты помогаешь собрать пакет обязательных документов организации. По сведениям "
    "об организации выбери из каталога ключей минимально достаточный набор (не более "
    "{max}) и верни JSON: {{\"keys\": [\"{keys}\"], \"why\": \"одно предложение\"}}. Только JSON."
)

SYSTEM_PARSE_ORG = (
    "Ты ассистент по делопроизводству. Тебе даны фрагменты официальных данных об "
    "организации РФ (выписка ЕГРЮЛ/ЕГРИП или поисковая выдача). Извлеки факты и верни "
    "СТРОГО один JSON без markdown: "
    '{{\"full_name\": \"полное наименование\", \"inn\": \"ИНН\", \"kpp\": \"КПП или пусто\", '
    '\"address\": \"юридический адрес одной строкой\", '
    '\"okved\": \"основной ОКВЭД: код и название\", '
    '\"director\": \"руководитель (должность + Ф.И.О.) или пусто\", '
    '\"reg_date\": \"дата регистрации (ГГГГ-ММ-ДД) или пусто\"}}. '
    "Чего нет в данных — пустая строка, ничего не выдумывай. Только JSON."
)

SYSTEM_PARSE_RAW = (
    "Ты ассистент по делопроизводству. Пользователь вставил сырой текст о своей "
    "организации (описание с сайта, письмо, фрагмент документа). Извлеки из него "
    "реквизиты и верни СТРОГО один JSON-объект без markdown: "
    '{{\"org_name\": \"название организации (ООО …, ИП …)\", \"industry\": \"сфера деятельности\", '
    '\"size\": \"численность персонала (напр. 12 человек) или пусто\", '
    '\"structure\": \"отделы и должности через точку с запятой\", '
    '\"needs\": \"особые требования/пожелания из текста или пусто\"}}. '
    "Чего в тексте нет — оставь пустой строкой, ничего не выдумывай. Только JSON."
)


# --------------------------------------------------------------------------
# Env — только внутри функций (lazy), как в nj570
# --------------------------------------------------------------------------

def _model(kind: str) -> str:
    return os.getenv(f"NJ580_{kind}_MODEL", "").strip()


def _api_base() -> str:
    # напр. https://n2j.ru/docs/api
    return os.getenv("NJ580_API_BASE", "").strip().rstrip("/")


def _site_token() -> str:
    return os.getenv("NJ580_SITE_TOKEN", "").strip() or os.getenv("WORKER_API_TOKEN", "").strip()


def _max_docs() -> int:
    try:
        return max(1, int(os.getenv("NJ580_MAX_DOCS", "10")))
    except ValueError:
        return 10


def _search_max() -> int:
    try:
        return max(0, int(os.getenv("NJ580_SEARCH_MAX", "2")))
    except ValueError:
        return 2


def _search_delay() -> float:
    try:
        return float(os.getenv("NJ580_SEARCH_DELAY", "3.0"))
    except ValueError:
        return 3.0


# --------------------------------------------------------------------------
# LLM + учёт затрат (pg — сразу в nj_580_usage; http — sink в job.result)
# --------------------------------------------------------------------------

async def _chat(ctx, messages: list, phase: str, job_id: int, package_id: int,
                document_id: int, sink: list, model: str = "", max_tokens: int = 7000,
                request_timeout: float | None = None) -> str:
    t0 = time.monotonic()
    kw = {"model": model or None, "max_tokens": max_tokens}

    if request_timeout:
        kw["request_timeout"] = request_timeout

    text, usage = await ctx.llm.chat_with_usage(messages, **kw)
    dt = int((time.monotonic() - t0) * 1000)

    pt = int(usage.get("prompt_tokens") or 0)
    ct = int(usage.get("completion_tokens") or 0)
    model_used = model or ctx.config.llm_model or "default"
    log.info("nj580[%s] model=%s %d+%d tok, %d ms", phase, model_used, pt, ct, dt)

    if ctx.pg is not None:
        await _pg_exec(
            ctx,
            "INSERT INTO nj_580_usage (package_id, document_id, job_id, phase, model, "
            "prompt_tokens, completion_tokens, duration_ms) VALUES ($1,$2,$3,$4,$5,$6,$7,$8)",
            package_id, document_id or None, job_id, phase, model_used, pt, ct, dt)
    else:
        sink.append({"package_id": package_id, "document_id": document_id, "phase": phase,
                     "model": model_used, "prompt_tokens": pt,
                     "completion_tokens": ct, "duration_ms": dt})
    return text


async def _pg_exec(ctx, sql: str, *args) -> None:
    if ctx.pg is None:
        return
    try:
        async with ctx.pg.acquire() as conn:
            await conn.execute(sql, *args)
    except Exception:  # noqa: BLE001 — статус не должен ронять задачу
        log.warning("nj580: pg execute failed: %s", sql.split("(")[0].strip())


def _msg(role: str, content: str) -> dict:
    return {"role": role, "content": content}


def _extract_json(text: str) -> dict:
    m = None
    import re
    m = re.search(r"\{.*\}", str(text or ""), re.S)
    if not m:
        return {}
    try:
        data = json.loads(m.group(0))
        return data if isinstance(data, dict) else {}
    except ValueError:
        return {}


# --------------------------------------------------------------------------
# API сайта: строки документов + выгрузка файлов
# --------------------------------------------------------------------------

async def _api_doc(ctx, package_id: int, doc_key: str, ord_: int, title: str,
                   status: str, error: str = "", content_json: str = "") -> int:
    """Создать/обновить строку документа → id (0 — не удалось)."""
    base, token = _api_base(), _site_token()

    if not (base and token):
        log.info("nj580: API ВЫКЛЮЧЕН (NJ580_API_BASE/NJ580_SITE_TOKEN пусты) — документ %s не сохранён", doc_key)
        return 0

    import asyncio
    import httpx

    for attempt in (1, 2):
        try:
            async with httpx.AsyncClient(timeout=60) as client:
                r = await client.post(base + "/doc",
                                      data={"package_id": str(package_id), "doc_key": doc_key,
                                            "ord": str(ord_), "title": title[:300],
                                            "status": status, "error": error[:900],
                                            "content": content_json[:2_000_000]},
                                      headers={"X-Worker-Token": token})
            if r.status_code == 200:
                return int((r.json() or {}).get("id") or 0)
            log.warning("nj580: api/doc %s -> HTTP %s: %s (попытка %d)",
                        doc_key, r.status_code, r.text[:200], attempt)
        except Exception:  # noqa: BLE001
            log.warning("nj580: api/doc failed (попытка %d):\n%s", attempt,
                        __import__("traceback").format_exc())
        if attempt == 1:
            await asyncio.sleep(1.5)
    return 0


async def _api_upload(ctx, package_id: int, document_id: int, kind: str,
                      filename: str, data: bytes) -> bool:
    base, token = _api_base(), _site_token()

    if not (base and token and data):
        if data:
            log.info("nj580: выгрузка ВЫКЛЮЧЕНА (NJ580_API_BASE/NJ580_SITE_TOKEN пусты) — %d байт потеряны", len(data))
        return False

    import httpx

    try:
        async with httpx.AsyncClient(timeout=180) as client:
            r = await client.post(base + "/upload",
                                  data={"package_id": str(package_id),
                                        "document_id": str(document_id or 0),
                                        "kind": kind},
                                  files={"file": (filename, data)},
                                  headers={"X-Worker-Token": token})
        log.info("nj580: upload %s pkg=%s doc=%s -> HTTP %s", kind, package_id,
                 document_id, r.status_code)
        return r.status_code == 200
    except Exception:  # noqa: BLE001
        log.warning("nj580: upload failed:\n%s", __import__("traceback").format_exc())
        return False


import re as _re_mod

_OPF_NAME_RE = _re_mod.compile(
    r"\b(ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ|ООО|АКЦИОНЕРНОЕ ОБЩЕСТВО|АО|"
    r"ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО|ПАО|ЗАКРЫТОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО|ЗАО|ОАО|"
    r"ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ|ИП)\s*(«[^»]{1,80}»|\"[^\"]{1,80}\")")


def _org_known(name: str) -> bool:
    """Наименование организации реально известно (не число, не заглушка)."""
    n = str(name or "").strip()

    return bool(n) and not n.isdigit() and not n.startswith("Разбираем")


def _scrub_unknown_names(content: dict) -> dict:
    """Наименование организации неизвестно — вырезать из документа ЛЮБЫЕ
    «придуманные» названия после ОПФ (защита от галлюцинаций LLM), заменить
    на «____________». Работает по всем строкам контента."""
    if not isinstance(content, dict):
        return content

    def fix(t: str) -> str:
        return _OPF_NAME_RE.sub(lambda m: m.group(1) + " «____________»", t)

    content["org_name"] = "____________"

    if content.get("approve_line"):
        content["approve_line"] = fix(str(content["approve_line"]))

    for sec in (content.get("sections") or []):
        if not isinstance(sec, dict):
            continue

        if sec.get("heading"):
            sec["heading"] = fix(str(sec["heading"]))

        for b in (sec.get("blocks") or []):
            if not isinstance(b, dict):
                continue

            if b.get("text"):
                b["text"] = fix(str(b["text"]))

            if b.get("caption"):
                b["caption"] = fix(str(b["caption"]))

            items = b.get("items")

            if isinstance(items, list):
                b["items"] = [fix(str(i)) if isinstance(i, str) else i for i in items]

            rows = b.get("rows")

            if isinstance(rows, list):
                b["rows"] = [[fix(str(c)) if isinstance(c, str) else c for c in row]
                             for row in rows if isinstance(row, list)]

    return content


def _parse_fns_result(data: dict) -> dict | None:
    """JSON поиска ЕГРЮЛ/ЕГРИП (egrul.nalog.ru/search-result) → карточка.
    Поля строк: n — наименование, o — ОГРН/ОГРНИП, i — ИНН, p/k — КПП,
    a — адрес, r — дата регистрации."""
    import re as _re

    rows = data.get("rows") or []

    if not rows:
        return None

    r = rows[0] or {}
    card = {}

    def clean(key):
        return _re.sub(r"\D", "", str(r.get(key) or ""))

    if str(r.get("n") or "").strip():
        card["full_name"] = str(r["n"]).strip()[:500]
    if clean("o"):
        card["ogrn"] = clean("o")
    if clean("i"):
        card["inn"] = clean("i")
    if clean("p") or clean("k"):
        card["kpp"] = clean("p") or clean("k")
    if str(r.get("a") or "").strip():
        card["address"] = str(r["a"]).strip()[:500]
    if str(r.get("r") or "").strip():
        card["reg_date"] = str(r["r"]).strip()[:20]

    return card if card.get("full_name") else None


async def _fns_lookup(ogrn: str) -> dict | None:
    """Официальная карточка из ФНС: поиск ЕГРЮЛ/ЕГРИП без капчи.
    GET / (cookie) → POST {query} → {"t": token} → GET /search-result/{token}."""
    import httpx

    base = "https://egrul.nalog.ru/"

    async with httpx.AsyncClient(timeout=20, follow_redirects=True,
                                 headers={"User-Agent": "Mozilla/5.0 (X11; Linux x86_64)"}) as cl:
        await cl.get(base)
        pr = await cl.post(base, data={
            "vyp3CaptchaToken": "", "page": "", "query": ogrn,
            "region": "", "PreventChromeAutocomplete": "",
        })
        token = (pr.json() or {}).get("t")

        if not token:
            log.info("nj580: ФНС не дал токен поиска (HTTP %s)", pr.status_code)
            return None

        for _ in range(6):
            await asyncio.sleep(0.6)
            rr = await cl.get(f"{base}search-result/{token}",
                              params={"r": int(time.time() * 1000), "_": int(time.time() * 1000)})
            data = rr.json() or {}

            if data.get("status") == "wait":
                continue

            card = _parse_fns_result(data)

            if card is None:
                log.info("nj580: ФНС: по %s ничего не найдено", ogrn)

            return card

    return None


async def _api_progress(ctx, package_id: int, phase: str, **fields: str) -> dict | None:
    """Промежуточный статус сборки → /docs/api/progress (виден в UI сразу).
    Возвращает ответ сайта {'ok': True, 'cancel': bool} или None при сбое."""
    # Тот же этап — в журнал очереди: этап виден в мониторе nj120,
    # «Моих задачах» и статус-ряде модуля (единый механизм /trends).
    # Сбой журнала прогресс не отменяет: своя страница важнее.
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    base, token = _api_base(), _site_token()

    if not (base and token):
        return

    import httpx

    data = {"package_id": str(package_id), "phase": phase[:300]}
    data.update({k: v[:2000] for k, v in fields.items() if v})

    try:
        async with httpx.AsyncClient(timeout=30) as client:
            r = await client.post(base + "/progress", data=data,
                                  headers={"X-Worker-Token": token})
        if r.status_code != 200:
            log.warning("nj580: api/progress -> HTTP %s: %s", r.status_code, r.text[:150])
            return None
        return r.json() or {}
    except Exception:  # noqa: BLE001 — прогресс не критичен
        log.debug("nj580: api/progress failed")
        return None


# --------------------------------------------------------------------------
# DuckDuckGo-референсы (бережно: паузы и лимиты, как в nj550)
# --------------------------------------------------------------------------

def _ddg_search(query: str, max_results: int) -> list[dict]:
    from ddgs import DDGS

    with DDGS() as ddgs:
        return list(ddgs.text(query, region="ru-ru", safesearch="moderate",
                              max_results=max_results) or [])


DDG_TIMEOUT_SEC = 90.0


async def _duck_refs(ctx, query: str) -> str:
    if _search_max() <= 0:
        return ""
    try:
        rows = await asyncio.wait_for(
            asyncio.to_thread(_ddg_search, query, _search_max()), timeout=DDG_TIMEOUT_SEC)
    except asyncio.TimeoutError:
        log.warning("nj580: ddgs молчит дольше %.0f с (прокси?) — идём без референсов", DDG_TIMEOUT_SEC)
        return ""
    except Exception:  # noqa: BLE001 — поисковик не критичен
        log.info("nj580: ddgs недоступен, идём без референсов")
        return ""
    await asyncio.sleep(_search_delay())
    return "; ".join(f"{r.get('title', '')} ({(r.get('body') or '')[:140]})"
                     for r in rows)[:900]


# --------------------------------------------------------------------------
# Шаги конвейера (состояние — dict;.LangGraph оркестрирует, фолбэк — последовательно)
# --------------------------------------------------------------------------

async def step_plan(state: dict, ctx, job_id: int, sink: list) -> dict:
    """Возвращает {"doc_keys": [...], "plan_note": str, "org_info": …, "org_name": …}.
    Ноды LangGraph обновляют состояние ТОЛЬКО возвратом значения — мутации dict
    на месте между нодами не переносятся."""
    types = [str(t) for t in (state.get("doc_types") or ["auto"])]
    info = dict(state.get("org_info") or {})
    org_name = str(state.get("org_name") or "")

    # ОГРН/ОГРНИП/ИНН → официальная карточка (кэш сервиса → ЕГРЮЛ-зеркало → DDG+LLM)
    ogrn = str(state.get("ogrn") or "").strip()

    if ogrn:
        await _api_progress(ctx, state["package_id"], f"Проверяю организацию по ОГРН {ogrn}…",
                            ogrn=ogrn, q_ogrn=ogrn)

        # обогащение выполняем один раз (на повторных пересборках данные уже в кэше)
        pr = await _api_progress(ctx, state["package_id"], "Уточняю реквизиты организации…",
                                 ogrn=ogrn, q_ogrn=ogrn)
        cached = (pr or {}).get("org") if isinstance(pr, dict) else None

        if cached and (cached.get("full_name") or cached.get("okved")):
            log.info("nj580: карточка %s из кэша сервиса", ogrn)
            info.setdefault("inn", cached.get("inn", ""))
            info.setdefault("kpp", cached.get("kpp", ""))
            info.setdefault("address", cached.get("address", ""))
            info.setdefault("okved", cached.get("okved", ""))
            info.setdefault("director", cached.get("director", ""))
            if cached.get("full_name") and not _org_known(org_name):
                org_name = cached["full_name"]
        else:
            src_text = ""
            fns_found = False

            # 1) официальный поиск ФНС ЕГРЮЛ/ЕГРИП (без капчи, детерминированный парсинг)
            try:
                card = await _fns_lookup(ogrn)

                if card:
                    fns_found = True
                    log.info("nj580: ФНС нашёл %s: %s", ogrn, card.get("full_name", "")[:80])
                    fields = {k: card[k] for k in ("inn", "kpp", "address", "director", "reg_date", "okved")
                              if card.get(k)}
                    fields["ogrn"] = ogrn

                    if card.get("full_name") and not _org_known(org_name):
                        org_name = card["full_name"]
                        fields["org_name"] = card["full_name"]

                    for k, v in fields.items():
                        if k != "org_name":
                            info.setdefault(k, v)

                    shown = card.get("full_name") or ogrn
                    await _api_progress(ctx, state["package_id"],
                                        f"ФНС: организация найдена — {shown[:120]}", **fields)
            except Exception:  # noqa: BLE001 — ФНС может быть недоступна, есть запасной путь
                log.warning("nj580: поиск ФНС не удался:\n%s", __import__("traceback").format_exc())

            # 2) запасной путь: DuckDuckGo + LLM-извлечение (если ФНС не дала карточку)
            if not fns_found:
                try:
                    refs = await _duck_refs(ctx, f"ОГРН {ogrn}")
                    refs2 = await _duck_refs(ctx, f"{ogrn} ИНН руководитель ОКВЭД")
                    src_text = "ПОИСКОВАЯ ВЫДАЧА:\n" + "; ".join(x for x in (refs, refs2) if x)
                except Exception:  # noqa: BLE001
                    src_text = ""

            if src_text:
                try:
                    text = await _chat(ctx, [_msg("system", SYSTEM_PARSE_ORG), _msg("user", src_text[:8000])],
                                       phase="org_lookup", job_id=job_id, package_id=state["package_id"],
                                       document_id=0, sink=sink, model=_model("PLAN"), max_tokens=500)
                    data = _extract_json(text)
                    card = {k: str(data.get(k) or "").strip() for k in
                            ("full_name", "inn", "kpp", "address", "okved", "director", "reg_date")}
                    card = {k: v for k, v in card.items() if v}

                    if card:
                        for k in ("inn", "kpp", "address", "okved", "director", "reg_date"):
                            if card.get(k):
                                info.setdefault(k, card[k])
                        if card.get("full_name") and not _org_known(org_name):
                            org_name = card["full_name"]
                        shown = card.get("full_name") or card.get("inn") or "реквизиты"
                        await _api_progress(ctx, state["package_id"],
                                            f"Организация найдена: {shown[:120]}",
                                            ogrn=ogrn, **{k: card[k] for k in card if k != "full_name"},
                                            **({"org_name": card["full_name"]} if card.get("full_name") else {}))
                except Exception:  # noqa: BLE001 — не нашли: работаем с тем, что ввёл пользователь
                    log.warning("nj580: разбор карточки организации не удался:\n%s",
                                __import__("traceback").format_exc())
            else:
                await _api_progress(ctx, state["package_id"],
                                    "Организация в ФНС не найдена — названия будут «____________», заполните их после", ogrn=ogrn)

    # сырые данные → реквизиты организации ( воркер сам разбирает текст )
    raw = str(state.get("raw_text") or "").strip()

    if raw:
        await _api_progress(ctx, state["package_id"], "Разбираю сырые данные о компании…")

        try:
            text = await _chat(ctx, [_msg("system", SYSTEM_PARSE_RAW), _msg("user", raw[:20000])],
                               phase="parse_raw", job_id=job_id, package_id=state["package_id"],
                               document_id=0, sink=sink, model=_model("PLAN"), max_tokens=700)
            data = _extract_json(text)
            fields = {k: str(data.get(k) or "").strip() for k in
                      ("org_name", "industry", "size", "structure", "needs")}
            fields = {k: v for k, v in fields.items() if v}

            if fields:
                for k, v in fields.items():
                    if k == "org_name":
                        # сырые данные главнее: и пустого, и заглушечного «Разбираем…»
                        org_name = v if (not org_name or org_name.startswith("Разбираем")) else org_name
                    elif k == "needs":
                        # требования не конфликтуют — объединяем
                        info[k] = ((info.get(k) + "; " + v) if info.get(k) else v)[:2000]
                    else:
                        # сырые данные пользователя ценнее полей формы
                        info[k] = v
                shown = fields.get("org_name") or fields.get("industry") or "реквизиты извлечены"
                await _api_progress(ctx, state["package_id"],
                                    f"Сырые данные разобраны: {shown[:120]}", **fields)
        except Exception:  # noqa: BLE001 — разбор не удался: работаем с тем, что есть
            log.warning("nj580: разбор сырых данных не удался:\n%s", __import__("traceback").format_exc())
            await _api_progress(ctx, state["package_id"], "Сырые данные разобраны частично")

    if "auto" not in types:
        return {"doc_keys": [t for t in types if t in DOC_TYPES][:_max_docs()],
                "plan_note": "набор выбран владельцем",
                "org_info": info, "org_name": org_name}

    pr = await _api_progress(ctx, state["package_id"], "Подбираю состав пакета документов…")
    if pr and pr.get("cancel"):
        raise Cancelled()
    catalog = ", ".join(DOC_TYPES.keys())
    prompt = SYSTEM_PLAN.format(max=_max_docs(), keys=catalog)
    user = (f"Организация: {org_name}\n"
            f"Сфера: {info.get('industry', '')}\nРазмер: {info.get('size', '')}\n"
            f"Структура: {info.get('structure', '')}\nПожелания: {info.get('needs', '')}")

    try:
        text = await _chat(ctx, [_msg("system", prompt), _msg("user", user)],
                           phase="plan", job_id=job_id, package_id=state["package_id"],
                           document_id=0, sink=sink, model=_model("PLAN"), max_tokens=400)
        data = _extract_json(text)
        keys = [str(k) for k in (data.get("keys") or []) if str(k) in DOC_TYPES][:_max_docs()]
        return {"doc_keys": keys or list(DOC_TYPES.keys())[:_max_docs()],
                "plan_note": str(data.get("why") or "")[:300],
                "org_info": info, "org_name": org_name}
    except Exception:  # noqa: BLE001 — план не удался: базовый набор
        log.warning("nj580: план не удался, берём базовый набор:\n%s", __import__("traceback").format_exc())
        return {"doc_keys": ["ptr", "oplata", "pdn", "ohrana", "shtatnoe", "reestr"],
                "plan_note": "базовый набор (план недоступен)",
                "org_info": info, "org_name": org_name}


async def step_research(state: dict, ctx, job_id: int, sink: list) -> dict:
    if not state.get("use_search"):
        await _api_progress(ctx, state["package_id"], "Состав готов — пишу тексты документов…")
        return {"refs": ""}

    pr = await _api_progress(ctx, state["package_id"], "Ищу актуальные образцы в интернете…")
    if pr and pr.get("cancel"):
        raise Cancelled()
    info = state.get("org_info") or {}
    q1 = await _duck_refs(ctx, f"обязательные документы организации {info.get('industry', '')} кадровые 2026")
    q2 = await _duck_refs(ctx, "ГОСТ Р 7.0.97-2016 оформление организационно-распорядительных документов требования")
    return {"refs": " | ".join(r for r in (q1, q2) if r)}


async def step_write(state: dict, ctx, job_id: int, sink: list) -> dict:
    docs = []
    org_name = str(state.get("org_name") or "")
    info = state.get("org_info") or {}
    all_keys = [k for k in (state.get("doc_keys") or []) if k in DOC_TYPES]

    for ord_, key in enumerate(all_keys, start=1):
        spec = DOC_TYPES[key]
        title = spec["title"]
        pr = await _api_progress(ctx, state["package_id"],
                                 f"Пишу документ {ord_} из {len(all_keys)}: {title}…")
        if pr and pr.get("cancel"):
            raise Cancelled()
        doc_id = await _api_doc(ctx, state["package_id"], key, ord_, title, "writing")
        await ctx.heartbeat()

        state_ogrn = str(state.get("ogrn") or "").strip()

        if _org_known(org_name):
            org_line = f"Организация: {org_name}\n"
        else:
            org_line = ("Организация: НАИМЕНОВАНИЕ НЕИЗВЕСТНО"
                        + (f" (известен только ОГРН/ИНН {state_ogrn})" if state_ogrn else "")
                        + " — везде вместо названия используй «____________», "
                          "ПРИДУМЫВАТЬ НАЗВАНИЕ ЗАПРЕЩЕНО.\n")

        user = (org_line
                + f"Сфера: {info.get('industry', '')}; численность: {info.get('size', '')}\n"
                f"Структура и должности: {info.get('structure', '')}\n"
                f"Особые пожелания: {info.get('needs', '')}\n"
                f"Текущий год: {time.strftime('%Y')} — используй его в реквизитах "
                f"(city_year) и всех датах документа.\n\n"
                f"Документ: {title}\nЧто должно быть внутри: {spec['hint']}\n")
        corrections = [str(c) for c in (state.get("corrections") or []) if str(c).strip()]
        if corrections:
            user += ("\nПРАВКИ И УТОЧНЕНИЯ УЧАСТНИКОВ прошлых итераций (приоритетнее "
                     f"прочих пожеланий, отражай их в тексте):\n" + "\n".join("— " + c[:500] for c in corrections[:20]) + "\n")

        raw = str(state.get("raw_text") or "").strip()
        if raw:
            user += ("\nПОЛНЫЕ СЫРЫЕ ДАННЫЕ ОБ ОРГАНИЗАЦИИ (приоритетный источник фактов — "
                     f"названия, должности, отделы, сотрудники, реквизиты; используй их, не "
                     f"выдумывай и не противоречь им):\n{raw[:8000]}\n")
        if state.get("refs"):
            user += f"\nАктуальные референсы из интернета (для полноты): {state['refs']}\n"

        entry = {"doc_key": key, "ord": ord_, "title": title, "status": "writing",
                 "error": "", "content": None, "document_id": doc_id}

        try:
            text = await _chat(ctx, [_msg("system", SYSTEM_DOC), _msg("user", user)],
                               phase=f"draft#{key}", job_id=job_id,
                               package_id=state["package_id"], document_id=doc_id,
                               sink=sink, model=_model("DOC"), max_tokens=7000,
                               request_timeout=420)
            content = _extract_json(text)

            if not content.get("sections"):
                text2 = await _chat(
                    ctx,
                    [_msg("system", SYSTEM_DOC), _msg("user", user),
                     _msg("user", "Предыдущий ответ не распарсился как JSON с sections. "
                                  "Верни только валидный JSON по схеме.")],
                    phase=f"draft2#{key}", job_id=job_id,
                    package_id=state["package_id"], document_id=doc_id,
                    sink=sink, model=_model("DOC"), max_tokens=7000,
                    request_timeout=420)
                content = _extract_json(text2)

            if not content.get("sections"):
                raise ValueError("LLM не вернула структуру документа")

            content.setdefault("title", title)
            content.setdefault("org_name", org_name)

            # проход-проверка: полнота, стиль, реквизиты (не критичен)
            await _api_progress(ctx, state["package_id"],
                                f"Проверяю документ {ord_} из {len(all_keys)}: {title}…")
            try:
                qtext = await _chat(
                    ctx,
                    [_msg("system", SYSTEM_QA),
                     _msg("user", "Тип документа: " + title + "\nТекущий год: "
                         + time.strftime('%Y') + " (проверь, что в датах и реквизитах он, а не прошлый)\nСведения: "
                         + json.dumps({"org": org_name if _org_known(org_name) else "НАИМЕНОВАНИЕ НЕИЗВЕСТНО — любое название в документе замени на «____________»",
                          "industry": info.get("industry", ""),
                          "size": info.get("size", "")}, ensure_ascii=False) +
                      (("\nСырые данные организации (факты должны им соответствовать): "
                        + str(state.get("raw_text") or "")[:4000]) if state.get("raw_text") else "") +
                      "\nJSON документа:\n" + json.dumps(content, ensure_ascii=False))],
                    phase=f"qa#{key}", job_id=job_id,
                    package_id=state["package_id"], document_id=doc_id,
                    sink=sink, model=_model("QA"), max_tokens=7000)
                q = _extract_json(qtext)
                if q.get("sections"):
                    q.setdefault("title", content["title"])
                    q.setdefault("org_name", org_name)
                    content = q
            except Exception:  # noqa: BLE001
                log.warning("nj580: QA-проход не удался (%s), берём черновик", key)

            if not _org_known(org_name):
                content = _scrub_unknown_names(content)

            entry["content"] = content
            entry["status"] = "done"

            # таблицы — дублируем в XLSX
            tables = [b for s in content.get("sections", [])
                      for b in (s.get("blocks") or []) if b.get("type") == "table"]
            entry["xlsx"] = bool(tables) or key in _XLSX_FORCE
        except Exception as e:  # noqa: BLE001 — документ падает, пакет живёт
            entry["status"] = "error"
            entry["error"] = f"Черновик не удался: {e}"[:400]
            log.warning("nj580: документ %s упал:\n%s", key, __import__("traceback").format_exc())

        await _api_doc(ctx, state["package_id"], key, ord_, entry["title"],
                       entry["status"], entry["error"],
                       json.dumps(entry["content"], ensure_ascii=False) if entry["content"] else "")
        docs.append(entry)

    return {"docs": docs}


async def step_build(state: dict, ctx, job_id: int, sink: list) -> dict:
    from ..docgen import build_docx, build_pdf, build_xlsx
    from ..docgen.zipper import make_zip, slug

    pr = await _api_progress(ctx, state["package_id"], "Тексты готовы — собираю файлы Word/PDF/Excel…")
    if pr and pr.get("cancel"):
        raise Cancelled()

    docs = state.get("docs") or []
    org_name = str(state.get("org_name") or "")
    zip_files = []
    built = 0

    for entry in docs:
        if entry.get("status") != "done" or not entry.get("content"):
            continue

        base_name = f"{entry['ord']:02d}_{slug(entry['title'])}"
        try:
            docx_bytes = await asyncio.to_thread(build_docx.build_docx, entry["content"])
            pdf_bytes = await asyncio.to_thread(build_pdf.build_pdf, entry["content"])
        except Exception as e:  # noqa: BLE001 — сборщик упал: помечаем документ
            entry["status"] = "error"
            entry["error"] = f"Сборка файла не удалась: {e}"[:400]
            await _api_doc(ctx, state["package_id"], entry["doc_key"], entry["ord"],
                           entry["title"], "error", entry["error"], "")
            log.warning("nj580: build %s упал:\n%s", entry["doc_key"],
                        __import__("traceback").format_exc())
            continue

        doc_id = int(entry.get("document_id") or 0)
        await _api_upload(ctx, state["package_id"], doc_id, "docx", base_name + ".docx", docx_bytes)
        await _api_upload(ctx, state["package_id"], doc_id, "pdf", base_name + ".pdf", pdf_bytes)
        zip_files.append((base_name + ".docx", docx_bytes))
        zip_files.append((base_name + ".pdf", pdf_bytes))
        # повторное подтверждение статуса (если первый не дошёл — документ
        # не останется «пишется» при готовых файлах)
        if doc_id:
            await _api_doc(ctx, state["package_id"], entry["doc_key"], entry["ord"],
                           entry["title"], "done")
        built += 1

        if entry.get("xlsx"):
            tables = [b for s in entry["content"].get("sections", [])
                      for b in (s.get("blocks") or []) if b.get("type") == "table"]
            if tables:
                big = max(tables, key=lambda t: len(t.get("rows") or []))
                try:
                    xlsx_bytes = await asyncio.to_thread(
                        build_xlsx.build_xlsx,
                        str(big.get("caption") or entry["content"].get("title") or "Таблица"),
                        [str(c) for c in (big.get("columns") or [])],
                        [[str(c) for c in (r or [])] for r in (big.get("rows") or [])],
                        org_name)
                    await _api_upload(ctx, state["package_id"], doc_id, "xlsx",
                                      base_name + ".xlsx", xlsx_bytes)
                    zip_files.append((base_name + ".xlsx", xlsx_bytes))
                except Exception:  # noqa: BLE001 — xlsx не критичен
                    log.warning("nj580: xlsx %s упал:\n%s", entry["doc_key"],
                                __import__("traceback").format_exc())

        await ctx.heartbeat()

    # реестр пакета — всегда
    try:
        registry_rows = [
            [str(e["ord"]), e["title"],
             "готово" if e["status"] == "done" else "ошибка",
             ("DOCX, PDF" + (", XLSX" if e.get("xlsx") else "")) if e["status"] == "done" else "—"]
            for e in docs
        ]
        registry = await asyncio.to_thread(
            build_xlsx.build_xlsx,
            "Реестр документов пакета",
            ["№", "Документ", "Статус", "Форматы"],
            registry_rows, org_name, "Реестр")
        await _api_progress(ctx, state["package_id"], "Формирую ZIP-архив пакета…")
        await _api_upload(ctx, state["package_id"], 0, "xlsx", "00_reestr.xlsx", registry)
        zip_files.append(("00_reestr.xlsx", registry))
    except Exception:  # noqa: BLE001
        log.warning("nj580: реестр не собрался:\n%s", __import__("traceback").format_exc())

    if zip_files:
        zbytes = await asyncio.to_thread(make_zip, zip_files, org_name)
        await _api_upload(ctx, state["package_id"], 0, "zip", "documents.zip", zbytes)

    ok_count = sum(1 for e in docs if e.get("status") == "done")
    failed = [(e["title"], (e.get("error") or "").strip()) for e in docs if e.get("status") == "error"]

    def _fmt_failed() -> str:
        # название — причина (коротко): видно сразу и пользователю, и нам
        return "; ".join(f"{t} — {r[:140] or 'причина в журнале воркера (journalctl -u n2j-worker)'}"
                         for t, r in failed)

    out = {"built": built, "ok_count": ok_count, "summary": "", "error": ""}
    if docs and ok_count == 0:
        out["error"] = ("Ни один документ не удалось собрать: " + _fmt_failed())[:900]
    else:
        out["summary"] = (f"Собрано {ok_count} из {len(docs)} документов "
                          f"(Word + PDF по ГОСТ Р 7.0.97-2016"
                          + (", таблицы в Excel" if any(e.get("xlsx") for e in docs) else "")
                          + "). "
                          + ("Не удались: " + _fmt_failed() if failed else "Все файлы — в ZIP-архиве."))[:1900]
    return out


def _build_graph():
    """LangGraph-оркестрация: plan → research → write → build."""
    from langgraph.graph import END, START, StateGraph

    class State(dict):
        pass

    graph = StateGraph(State)

    async def plan_node(state: State):
        await step_plan(state, state["__ctx"], state["__job_id"], state["__sink"])
        return {}

    async def research_node(state: State):
        await step_research(state, state["__ctx"], state["__job_id"], state["__sink"])
        return {}

    async def write_node(state: State):
        await step_write(state, state["__ctx"], state["__job_id"], state["__sink"])
        return {}

    async def build_node(state: State):
        await step_build(state, state["__ctx"], state["__job_id"], state["__sink"])
        return {}

    graph.add_node("plan", plan_node)
    graph.add_node("research", research_node)
    graph.add_node("write", write_node)
    graph.add_node("build", build_node)
    graph.add_edge(START, "plan")
    graph.add_edge("plan", "research")
    graph.add_edge("research", "write")
    graph.add_edge("write", "build")
    graph.add_edge("build", END)

    return graph.compile()


# --------------------------------------------------------------------------
# Точка входа канала nj580.generate
# --------------------------------------------------------------------------

async def handle(ctx, payload: dict) -> dict:
    sink: list = []
    job_id = int((ctx._current_job or {}).get("id") or 0)
    package_id = int(payload.get("package_id") or 0)

    state = {
        "package_id": package_id,
        "code": str(payload.get("code", "") or ""),
        "org_name": str(payload.get("org_name", "") or ""),
        "org_info": payload.get("org_info") or {},
        "doc_types": payload.get("doc_types") or ["auto"],
        "use_search": bool(payload.get("use_search")),
        "docs": [],
        "__ctx": ctx,
        "__job_id": job_id,
        "__sink": sink,
    }

    if package_id <= 0:
        return {"ok": False, "error": "нет package_id", "usage": sink}

def _build_graph(ctx, job_id: int, sink: list):
    """LangGraph-оркестрация: plan → research → write → build.
    Нода возвращает результат шага — так новые ключи попадают
    в состояние следующих нод."""
    from langgraph.graph import END, START, StateGraph

    graph = StateGraph(dict)

    # {**state, **шаг} — корректно при ОБЕИХ семантиках langgraph:
    # и «merge частичного обновления», и «возврат заменяет состояние целиком»
    async def plan_node(state: dict):
        return {**state, **(await step_plan(state, ctx, job_id, sink))}

    async def research_node(state: dict):
        return {**state, **(await step_research(state, ctx, job_id, sink))}

    async def write_node(state: dict):
        return {**state, **(await step_write(state, ctx, job_id, sink))}

    async def build_node(state: dict):
        return {**state, **(await step_build(state, ctx, job_id, sink))}

    graph.add_node("plan", plan_node)
    graph.add_node("research", research_node)
    graph.add_node("write", write_node)
    graph.add_node("build", build_node)
    graph.add_edge(START, "plan")
    graph.add_edge("plan", "research")
    graph.add_edge("research", "write")
    graph.add_edge("write", "build")
    graph.add_edge("build", END)

    return graph.compile()


# --------------------------------------------------------------------------
# Точка входа канала nj580.generate
# --------------------------------------------------------------------------

async def handle(ctx, payload: dict) -> dict:
    sink: list = []
    job_id = int((ctx._current_job or {}).get("id") or 0)
    package_id = int(payload.get("package_id") or 0)

    state = {
        "package_id": package_id,
        "code": str(payload.get("code", "") or ""),
        "org_name": str(payload.get("org_name", "") or ""),
        "org_info": payload.get("org_info") or {},
        "doc_types": payload.get("doc_types") or ["auto"],
        "use_search": bool(payload.get("use_search")),
        "raw_text": str(payload.get("raw_text", "") or "")[:100000],
        "ogrn": str(payload.get("ogrn", "") or "").strip(),
        "corrections": list(payload.get("corrections") or [])[:20],
        "docs": [],
    }

    if package_id <= 0:
        return {"ok": False, "error": "нет package_id", "usage": sink}

    log.info("nj580: пакет %s (%s), типы=%s, поиск=%s, raw=%d байт, ogrn=%s, уточнений=%d",
             state["code"], state["org_name"][:40],
             ",".join(state["doc_types"]), state["use_search"], len(state["raw_text"]),
             state["ogrn"] or "—", len(state["corrections"]))

    await _api_progress(ctx, package_id, "Воркер взял задачу — начинаю сборку…")

    # Фолбэк важен ТОЛЬКО на отсутствие пакета langgraph (import внутри
    # _build_graph). Ошибки ВНУТРИ графа фолбэком не глушим — иначе шаги
    # выполняются дважды (ModuleNotFoundError — подкласс ImportError!).
    try:
        graph = _build_graph(ctx, job_id, sink)
    except ImportError:
        graph = None

    try:
        if graph is not None:
            state = await graph.ainvoke(state)
            log.info("nj580: конвейер выполнен (langgraph)")
        else:
            log.info("nj580: langgraph не установлен — последовательный режим")
            state.update(await step_plan(state, ctx, job_id, sink))
            state.update(await step_research(state, ctx, job_id, sink))
            state.update(await step_write(state, ctx, job_id, sink))
            state.update(await step_build(state, ctx, job_id, sink))
    except Cancelled:
        log.info("nj580: пакет %s отменён администратором", state["code"])
        return {"ok": False, "error": "Сборка отменена администратором",
                "summary": "", "usage": sink}
    except Exception as e:  # noqa: BLE001 — любая ошибка конвейера = ошибка пакета
        log.warning("nj580: конвейер упал:\n%s", __import__("traceback").format_exc())
        return {"ok": False, "error": f"Конвейер: {e}"[:400],
                "summary": "", "usage": sink}

    if state.get("error"):
        return {"ok": False, "error": state["error"],
                "summary": state.get("summary", ""), "usage": sink}

    return {"ok": True, "summary": state.get("summary", ""), "usage": sink}
