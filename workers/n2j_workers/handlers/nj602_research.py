"""
nj602: исследовательский конвейер статьи — «факты вместо воды».

Зачем отдельный модуль. Старый сбор (nj602_article) писал статью одним
проходом по 6 обрезанным до 2000 знаков страницам: модель видела куски
текста без цифр и дописывала «из головы» — получалось то, что ИИ сказал бы
и без интернета. Здесь сбор разбит на фазы, и текст пишется ТОЛЬКО по
выжимке проверяемых фактов:

  1. plan      — ИИ составляет 5–7 поисковых запросов (RU + EN, «статистика»,
                 «report», «benchmark», «case study») и список вопросов,
                 на которые статья обязана ответить цифрами;
  2. search    — DDG по всем запросам; результаты ранжируем: первоисточники
                 (исследования, документация, отчёты, .gov/.edu, arxiv, github)
                 выше SEO-витрин; сниппеты с цифрами и свежим годом — выше;
  3. read      — до NJ602_RESEARCH_MAX_SITES (8) страниц по NJ602_RESEARCH_MD_CAP
                 (5000) знаков;
  4. extract   — ИИ вытаскивает из каждого источника конкретные факты: числа,
                 даты, имена, цитаты, кейсы, контринтуитивные выводы — JSON с
                 привязкой к номеру источника. Общие места отбрасываются;
                 если цифр мало — добор: ещё один круг поиска по «дырам»;
  5. angle     — ИИ выбирает неочевидный тезис и план разделов, каждому
                 разделу назначаются факты; отдельно — «что это значит для
                 практика» и «где подвох»;
  6. write     — текст пишется по фактлисту; каждый факт помечается ссылкой
                 [n] на источник; требуются разделы «Цифры», «Что это значит
                 для вас», «Где подвох»;
  7. critic    — детерминированная метрика (цифры, ссылки, штампы) + ИИ-рецензент
                 (новизна, вода). Низкая оценка → один проход переписывания
                 по замечаниям, остаётся лучший по метрике вариант.

Итог: {body_md, facts, sources, quality} — сайт хранит facts/quality (миграция
004) и показывает ссылки [n] кликабельными.

Ручки окружения (workers/.env):
  NJ602_RESEARCH=0            выключить новый конвейер (старый одно-проходный)
  NJ602_RESEARCH_MAX_SITES=8  сколько страниц читать (3..12)
  NJ602_RESEARCH_MD_CAP=5000  знаков с одной страницы (1500..9000)
  NJ602_MIN_FACTS=8           минимум фактов с цифрами до добора
  NJ602_CRITIC_MIN=6          порог оценки рецензента (0..10), ниже — переписать
  NJ602_RESEARCH_MIN_CHARS=3500 нижняя граница объёма статьи
"""

from __future__ import annotations

import asyncio
from datetime import datetime
import json
import logging
import os
import re
from typing import Awaitable, Callable

from .. import webread

log = logging.getLogger("n2j.nj602.research")

# подпись функции _chat из nj602_article.handle: (messages, phase, max_tokens, timeout)
ChatFn = Callable[..., Awaitable[tuple[str, dict]]]


# ---------------------------------------------------------------------------
# Ручки
# ---------------------------------------------------------------------------

def _env_int(name: str, default: int, lo: int, hi: int) -> int:
    try:
        return max(lo, min(hi, int(os.getenv(name, str(default)).strip() or default)))
    except ValueError:
        return default


def enabled() -> bool:
    return os.getenv("NJ602_RESEARCH", "1").strip() != "0"


def max_sites() -> int:
    return _env_int("NJ602_RESEARCH_MAX_SITES", 8, 3, 12)


def md_cap() -> int:
    return _env_int("NJ602_RESEARCH_MD_CAP", 5000, 1500, 9000)


def min_facts() -> int:
    return _env_int("NJ602_MIN_FACTS", 8, 3, 40)


def critic_min() -> int:
    return _env_int("NJ602_CRITIC_MIN", 6, 0, 10)


def min_chars() -> int:
    return _env_int("NJ602_RESEARCH_MIN_CHARS", 3500, 1500, 20000)


# ---------------------------------------------------------------------------
# Ранжирование источников
# ---------------------------------------------------------------------------

# Первоисточники и площадки с данными: такие страницы дают цифры, а не пересказ.
_AUTHORITY = (
    "arxiv.org", "nature.com", "science.org", "acm.org", "ieee.org", "nber.org",
    "github.com", "gitlab.com", "docs.", "developer.", "research.", "blog.",
    "statista.com", "ourworldindata.org", "gartner.com", "mckinsey.com",
    "stackoverflow.co", "stackoverflow.blog", "cloudflare.com", "cncf.io",
    "pypi.org", "npmjs.com", "kernel.org", "w3.org", "ietf.org", "oecd.org",
    "europa.eu", "who.int", "worldbank.org", "imf.org", "bls.gov", "census.gov",
    "habr.com", "cnews.ru", "tadviser.ru", "rbc.ru", "kommersant.ru", "vc.ru",
    "theverge.com", "arstechnica.com", "techcrunch.com", "wired.com",
    "reuters.com", "bloomberg.com", "ft.com", "economist.com", "hbr.org",
    "medium.com", "substack.com",
)

# SEO-витрины и агрегаторы без собственных данных: читаем в последнюю очередь.
_LOWVALUE = (
    "pinterest.", "quora.com", "answers.", "slideshare.", "scribd.", "coursera.",
    "udemy.", "wikihow.", "yandex.ru/q", "otvet.mail.ru", "dzen.ru", "zen.yandex",
    "aliexpress.", "amazon.", "ozon.ru", "wildberries.", "youtube.com", "vk.com",
    "t.me", "linkedin.com/pulse",
)

_YEAR_RE = re.compile(r"\b(20[12][0-9])\b")
_NUM_RE = re.compile(r"\d[\d\s.,]*(?:%|％|млн|млрд|тыс|k|M|B|bn|x|×)?", re.I)


def _host(url: str) -> str:
    m = re.match(r"https?://([^/]+)", url or "")
    return (m.group(1) if m else "").lower()


def score_candidate(row: dict, now_year: int) -> float:
    """Чем выше — тем раньше читаем. Эвристика без ИИ, работает на сниппетах."""
    url = str(row.get("url") or "")
    host = _host(url)
    text = (str(row.get("title") or "") + " " + str(row.get("snippet") or "")).lower()
    s = 1.0

    if any(a in host or a in url for a in _AUTHORITY):
        s += 2.0
    if any(a in host or a in url for a in _LOWVALUE):
        s -= 2.5
    if host.endswith((".gov", ".edu", ".ac.uk", ".org")):
        s += 1.0
    if url.lower().endswith(".pdf"):
        s += 0.5  # отчёты в PDF обычно насыщены цифрами

    nums = len(re.findall(r"\d+(?:[.,]\d+)?\s*(?:%|млн|млрд|тыс|million|billion|k\b)", text))
    s += min(2.0, 0.7 * nums)

    years = [int(y) for y in _YEAR_RE.findall(text)]
    if years:
        best = max(years)
        if best >= now_year:
            s += 1.5
        elif best == now_year - 1:
            s += 0.8
        elif best <= now_year - 4:
            s -= 1.0

    for kw in ("исследован", "отчёт", "отчет", "статистик", "данные", "опрос", "бенчмарк",
               "study", "survey", "report", "benchmark", "dataset", "statistics", "case study"):
        if kw in text:
            s += 0.4

    return s



# ---------------------------------------------------------------------------
# JSON-вызов с автоповтором для «думающих» моделей
# ---------------------------------------------------------------------------

# Диагностика последних неудачных JSON-ответов: начало сырого текста попадает
# в журнал прогона (иначе «фактов: 0» не объяснить, не глядя в логи воркера).
LAST_DIAG: list[str] = []


def _note_diag(phase: str, raw: str, usage: dict) -> None:
    head = re.sub(r"\s+", " ", (raw or "").strip())[:160]
    LAST_DIAG.append(f"{phase}: JSON не разобран (finish={usage.get('_llm_finish_reason', '?')}, "
                     f"{int(usage.get('completion_tokens') or 0)} ток.); ответ начинается: «{head}»")
    del LAST_DIAG[:-6]


async def json_call(chat: ChatFn, system: str, user: str, phase: str, max_tokens: int,
                    timeout: float) -> dict:
    """Запрос, от которого ждём JSON-объект. Если модель израсходовала лимит на
    размышления и объекта нет — повтор с удвоенным лимитом и «/no_think»
    (Qwen3 и совместимые отключают размышления этой командой; для остальных
    это безобидный хвост). Возвращает {} если JSON так и не пришёл."""
    raw, usage = await chat(
        [{"role": "system", "content": system}, {"role": "user", "content": user}],
        phase, max_tokens, timeout,
    )
    j = webread.json_from_text(raw)
    if isinstance(j, dict) and j:
        return j
    _note_diag(phase, raw, usage)
    if str(usage.get("_llm_finish_reason") or "") != "length" and len(raw or "") > 40:
        # модель ответила, но не JSON — повтор с жёстким напоминанием
        user = user + "\n\nОТВЕТ — ТОЛЬКО JSON-объект, первый символ {. /no_think"
    else:
        user = user + "\n\n/no_think"
    raw, usage = await chat(
        [{"role": "system", "content": system + " Отвечай без размышлений, сразу JSON."},
         {"role": "user", "content": user}],
        phase + ".retry", min(max_tokens * 2, 8000), timeout,
    )
    j = webread.json_from_text(raw)
    if isinstance(j, dict) and j:
        return j
    _note_diag(phase + ".retry", raw, usage)
    # последний шанс — хотя бы целые объекты из обрезанного массива
    objs = [o for o in webread.json_objects(raw) if isinstance(o, dict)]
    return {"_objects": objs} if objs else {}

# ---------------------------------------------------------------------------
# Фаза 1: план запросов
# ---------------------------------------------------------------------------

SYSTEM_PLAN = (
    "Не рассуждай вслух и не пиши вступлений — первый символ ответа: {. "
    "Ты редактор-исследователь издания N2J.ru. По теме составь план поиска фактов. "
    "Верни ТОЛЬКО JSON: {\"queries\": [...], \"questions\": [...], \"angle_hints\": [...]}.\n"
    "queries — 6-7 поисковых запросов: 3 на русском и 3-4 на английском; обязательно включи "
    "запросы вида «<тема> статистика {year}», «<topic> report {year} data», «<topic> benchmark comparison», "
    "«<topic> case study results», «<topic> survey percent». Запросы короткие (до 8 слов), без кавычек.\n"
    "questions — 5-6 конкретных вопросов, на которые статья должна ответить ЦИФРАМИ или именами "
    "(сколько, во сколько раз, кто первый, когда, сколько стоит, что изменилось за год).\n"
    "angle_hints — 3 неочевидных ракурса темы, о которых обычно не пишут.\n"
    "Без пояснений, без markdown."
)


def _template_queries(topic: str, year: int) -> list[str]:
    return [
        topic,
        f"{topic} статистика {year}",
        f"{topic} исследование данные",
        f"{topic} report {year} data",
        f"{topic} benchmark comparison",
        f"{topic} case study results",
    ]


async def plan(chat: ChatFn, topic: str) -> dict:
    year = datetime.now().year
    out = {"queries": _template_queries(topic, year), "questions": [], "angle_hints": []}
    try:
        j = await json_call(chat, SYSTEM_PLAN.replace("{year}", str(year)),
                            f"ТЕМА: {topic}\nТЕКУЩИЙ ГОД: {year}", "plan", 1500, 150.0)
        qs = [str(q).strip()[:120] for q in (j.get("queries") or []) if str(q).strip()]
        if len(qs) >= 3:
            # шаблонные запросы остаются страховкой: ИИ иногда даёт слишком узкие
            out["queries"] = list(dict.fromkeys(qs[:7] + _template_queries(topic, year)[:2]))
        out["questions"] = [str(q).strip()[:200] for q in (j.get("questions") or []) if str(q).strip()][:6]
        out["angle_hints"] = [str(q).strip()[:200] for q in (j.get("angle_hints") or []) if str(q).strip()][:3]
    except Exception:  # noqa: BLE001 — план не критичен, есть шаблон
        log.warning("nj602.research: план запросов не получен, используем шаблон", exc_info=True)
    return out


# ---------------------------------------------------------------------------
# Фаза 2–3: поиск и чтение
# ---------------------------------------------------------------------------

async def search(queries: list[str], blocked: Callable[[str], bool], skip_hosts: set[str],
                 per_query: int = 8) -> list[dict]:
    """DDG по всем запросам → уникальные URL, отсортированные по score_candidate.
    Не больше двух страниц с одного хоста — иначе статья пересказывает один сайт."""
    seen: set[str] = set()
    rows: list[dict] = []
    now_year = datetime.now().year

    for q in queries:
        try:
            found = await webread.ddg_text(q, max_results=per_query)
        except Exception:  # noqa: BLE001
            found = []
        for r in found:
            url = str(r.get("url") or r.get("href") or "").strip()
            host = _host(url)
            if not url.startswith("http") or not host or url in seen:
                continue
            title = str(r.get("title") or "")
            snippet = str(r.get("body") or r.get("snippet") or "")
            if blocked(title + " " + snippet):
                continue
            if any(host == h or host.endswith("." + h) for h in skip_hosts):
                continue
            seen.add(url)
            rows.append({"url": url, "title": title[:200], "snippet": snippet[:400], "q": q})
        await asyncio.sleep(0.8)

    for r in rows:
        r["score"] = round(score_candidate(r, now_year), 2)
    rows.sort(key=lambda r: r["score"], reverse=True)

    per_host: dict[str, int] = {}
    out: list[dict] = []
    for r in rows:
        h = _host(r["url"])
        if per_host.get(h, 0) >= 2:
            continue
        per_host[h] = per_host.get(h, 0) + 1
        out.append(r)
    return out


async def read(sources: list[dict], limit: int) -> list[dict]:
    """Читаем лучшие страницы, пока не наберём limit удачных (битые пропускаем)."""
    sem = asyncio.Semaphore(4)
    cap = md_cap()

    async def one(s: dict) -> dict | None:
        async with sem:
            try:
                html, _ = await webread.fetch(s["url"])
                md, title = await webread.to_markdown(html, cap=cap)
                if len(md) >= 400:
                    return {**s, "title": (title or s.get("title") or "")[:200], "md": md}
            except Exception as e:  # noqa: BLE001
                log.info("nj602.research: не прочитали %s: %s", s["url"], e.__class__.__name__)
            return None

    out: list[dict] = []
    # берём с запасом: часть страниц не отдаётся (403, PDF без текста, капча)
    pool = sources[: limit * 2]
    results = await asyncio.gather(*(one(s) for s in pool))
    for b in results:
        if b is not None:
            out.append(b)
        if len(out) >= limit:
            break
    return out


# ---------------------------------------------------------------------------
# Фаза 4: выжимка фактов
# ---------------------------------------------------------------------------

SYSTEM_EXTRACT = (
    "Не рассуждай вслух и не пиши вступлений — первый символ ответа: {. "
    "Ты фактчекер-исследователь. Тебе даны выдержки из веб-страниц, каждая под номером [n]. "
    "Извлеки из них ТОЛЬКО конкретные, проверяемые факты по теме: числа, проценты, суммы, даты, "
    "названия компаний/продуктов/людей, результаты исследований и тестов, цитаты экспертов, "
    "кейсы «кто сделал что и что получилось», неожиданные или противоречащие интуиции выводы.\n"
    "НЕ бери: общие рассуждения, рекламу, определения, то, что знает любой без источника.\n"
    "Верни ТОЛЬКО JSON: {\"facts\": [{\"src\": n, \"fact\": \"одно предложение по-русски с цифрой/именем\", "
    "\"kind\": \"number|date|name|quote|case|contrast|mechanism\", \"when\": \"год или дата, если есть\", "
    "\"why\": \"почему это интересно читателю (до 12 слов)\"}]}.\n"
    "До 8 фактов на источник, формулируй точно (единицы, период, кто измерял). "
    "Если в источнике фактов нет — просто не включай его. Без пояснений вне JSON."
)


def _has_substance(fact: str) -> bool:
    """Факт считается предметным, если в нём есть цифра или имя собственное."""
    if re.search(r"\d", fact):
        return True
    # имя собственное: слово с заглавной не в начале предложения, или латиница
    return bool(re.search(r"(?<!^)(?<![.!?]\s)\b[A-ZА-ЯЁ][a-zа-яё]{2,}", fact)) or bool(re.search(r"[A-Za-z]{3,}", fact))


async def extract(chat: ChatFn, topic: str, blocks: list[dict], questions: list[str],
                  start_idx: int = 1) -> list[dict]:
    """Факты из страниц. Пакет — до 2 страниц / ≈9k знаков: короче ответ, меньше обрезов."""
    facts: list[dict] = []
    batch: list[tuple[int, dict]] = []
    size = 0

    async def flush() -> None:
        nonlocal batch, size
        if not batch:
            return
        parts = [f"ТЕМА: {topic}"]
        if questions:
            parts.append("ВОПРОСЫ, НА КОТОРЫЕ ИЩЕМ ОТВЕТЫ: " + " | ".join(questions))
        for n, b in batch:
            parts.append(f"\n[{n}] {b['title']} — {_host(b['url'])}\n{b['md']}")
        try:
            j = await json_call(chat, SYSTEM_EXTRACT, "\n".join(parts), "extract", 4000, 300.0)
            items = list(j.get("facts") or [])
            if not items:
                # обрыв по лимиту токенов или массив без обёртки: целые объекты
                # {src, fact} из того, что пришло — битый хвост отбрасывается
                items = [o for o in (j.get("_objects") or []) if isinstance(o, dict) and "fact" in o]
            valid = {n for n, _ in batch}
            for f in items:
                if not isinstance(f, dict):
                    continue
                try:
                    src = int(f.get("src") or 0)
                except (TypeError, ValueError):
                    continue
                text = str(f.get("fact") or "").strip()
                if src not in valid or len(text) < 25 or not _has_substance(text):
                    continue
                facts.append({
                    "src": src,
                    "fact": text[:400],
                    "kind": str(f.get("kind") or "")[:12],
                    "when": str(f.get("when") or "")[:40],
                    "why": str(f.get("why") or "")[:160],
                })
        except Exception:  # noqa: BLE001 — потеря одного пакета не валит сбор
            log.warning("nj602.research: пакет фактов не разобран", exc_info=True)
        batch, size = [], 0

    for i, b in enumerate(blocks):
        n = start_idx + i
        batch.append((n, b))
        size += len(b["md"])
        if size >= 9000 or len(batch) >= 2:
            await flush()
    await flush()

    # дедуп по нормализованному тексту
    seen: set[str] = set()
    out: list[dict] = []
    for f in facts:
        key = re.sub(r"\W+", " ", f["fact"].lower()).strip()[:90]
        if key in seen:
            continue
        seen.add(key)
        out.append(f)
    return out


_SENT_SPLIT = re.compile(r"(?<=[.!?])\s+(?=[A-ZА-ЯЁ«\"(\d])")
_NUM_SENT = re.compile(r"\d+(?:[.,]\d+)?\s*(?:%|％|млн|млрд|тыс\.?|million|billion|thousand|k\b|раз|x\b|×|\$|€|₽|руб|USD|EUR|сек|мс|ms|GB|ГБ|TB|ТБ|MB|МБ)|\b(?:19|20)\d{2}\b", re.I)


def regex_facts(blocks: list[dict], start_idx: int = 1, per_source: int = 6) -> list[dict]:
    """Страховка без ИИ: предложения с числами/процентами/годами прямо из
    текста страниц. Нужна, когда модель «думает» весь лимит токенов и JSON не
    приходит — статья всё равно получает проверяемые цифры с источниками."""
    out: list[dict] = []
    for i, b in enumerate(blocks):
        n = start_idx + i
        text = re.sub(r"[#*_>|`\[\]]+", " ", b.get("md") or "")
        text = re.sub(r"\s+", " ", text)
        got = 0
        seen: set[str] = set()
        for sent in _SENT_SPLIT.split(text):
            sent = sent.strip()
            if not (40 <= len(sent) <= 320) or not _NUM_SENT.search(sent):
                continue
            if sent.lower().count("cookie") or "©" in sent or "подписк" in sent.lower() or "subscribe" in sent.lower():
                continue
            key = sent[:60].lower()
            if key in seen:
                continue
            seen.add(key)
            out.append({"src": n, "fact": sent, "kind": "number", "when": "", "why": "цифра из источника"})
            got += 1
            if got >= per_source:
                break
    return out


def numeric_facts(facts: list[dict]) -> int:
    return sum(1 for f in facts if re.search(r"\d", f["fact"]))


# ---------------------------------------------------------------------------
# Фаза 5: ракурс и план
# ---------------------------------------------------------------------------

SYSTEM_ANGLE = (
    "Не рассуждай вслух и не пиши вступлений — первый символ ответа: {. "
    "Ты шеф-редактор аналитического издания N2J.ru. Тебе дан список проверенных фактов с номерами "
    "источников. Придумай, как из них собрать статью, которая научит читателя новому, а не повторит "
    "общеизвестное.\n"
    "Верни ТОЛЬКО JSON:\n"
    "{\"thesis\": \"главный неочевидный вывод одним предложением (с опорой на факты)\",\n"
    " \"title_hint\": \"рабочий заголовок с конкретикой (цифра или имя), до 90 знаков\",\n"
    " \"sections\": [{\"h2\": \"предметный заголовок раздела\", \"point\": \"что доказываем в разделе\", "
    "\"facts\": [номера фактов F]}],\n"
    " \"practical\": [\"3-5 конкретных действий/решений для практика, которые следуют из фактов\"],\n"
    " \"caveats\": [\"2-4 ограничения, контраргумента или риска, которые честно надо назвать\"],\n"
    " \"table\": {\"title\": \"\", \"columns\": [\"…\"], \"rows\": [[\"…\"]]} или null — только если факты "
    "действительно сравнимы (варианты/годы/инструменты)}\n"
    "Разделов 4-6, каждому — не меньше 2 фактов. Не выдумывай фактов, которых нет в списке. "
    "Избегай разделов «Введение» и «Заключение». Без пояснений вне JSON."
)


async def angle(chat: ChatFn, topic: str, facts: list[dict], hints: list[str],
                audience: dict | None, recent: list | None) -> dict:
    parts = [f"ТЕМА: {topic}"]
    if hints:
        parts.append("ВОЗМОЖНЫЕ РАКУРСЫ: " + " | ".join(hints))
    if recent:
        titles = [str(r.get("title") or "")[:120] for r in recent if r.get("title")][:8]
        if titles:
            parts.append("УЖЕ ПИСАЛИ (не повторять сюжет): " + " | ".join(titles))
    if audience:
        phr = [p.get("phrase") for p in (audience.get("phrases") or []) if p.get("phrase")][:6]
        if phr:
            parts.append("ЧТО ИЩУТ ЧИТАТЕЛИ САЙТА: " + "; ".join(map(str, phr)))
    parts.append("\nФАКТЫ:")
    for i, f in enumerate(facts, 1):
        parts.append(f"F{i} [src {f['src']}] {f['fact']}" + (f" ({f['when']})" if f["when"] else ""))

    out = {"thesis": "", "title_hint": "", "sections": [], "practical": [], "caveats": [], "table": None}
    try:
        j = await json_call(chat, SYSTEM_ANGLE, "\n".join(parts), "angle", 2500, 200.0)
        out["thesis"] = str(j.get("thesis") or "").strip()[:400]
        out["title_hint"] = str(j.get("title_hint") or "").strip()[:120]
        secs = []
        for s in (j.get("sections") or []):
            if isinstance(s, dict) and str(s.get("h2") or "").strip():
                ids = []
                for x in (s.get("facts") or []):
                    try:
                        ids.append(int(str(x).lstrip("Ff")))
                    except ValueError:
                        continue
                secs.append({"h2": str(s["h2"])[:120], "point": str(s.get("point") or "")[:300], "facts": ids[:10]})
        out["sections"] = secs[:7]
        out["practical"] = [str(x)[:300] for x in (j.get("practical") or []) if str(x).strip()][:5]
        out["caveats"] = [str(x)[:300] for x in (j.get("caveats") or []) if str(x).strip()][:4]
        t = j.get("table")
        if isinstance(t, dict) and t.get("columns") and t.get("rows"):
            out["table"] = {
                "title": str(t.get("title") or "")[:120],
                "columns": [str(c)[:60] for c in t["columns"]][:6],
                "rows": [[str(c)[:80] for c in r][:6] for r in t["rows"] if isinstance(r, list)][:10],
            }
    except Exception:  # noqa: BLE001 — без плана текст всё равно пишется по фактам
        log.warning("nj602.research: план статьи не получен", exc_info=True)
    return out


# ---------------------------------------------------------------------------
# Фаза 6: текст по фактлисту
# ---------------------------------------------------------------------------

_BANNED = (
    "в современном мире", "в сегодняшних реалиях", "стоит отметить", "важно отметить",
    "в заключение", "подводя итог", "давайте разберёмся", "давайте разберемся", "не секрет, что",
    "играет важную роль", "ни для кого не секрет", "в эпоху цифровизации", "как известно",
    "в наше время", "трудно переоценить", "является ключевым", "в данной статье",
    "итак,", "таким образом,", "в целом,", "безусловно,", "несомненно,",
)

SYSTEM_WRITE = (
    "Ты ведущий аналитик-колумнист издания N2J.ru: пишешь для практиков — разработчиков, "
    "продактов, владельцев бизнеса. Твой текст ценят за то, что из него узнают новое: цифры, "
    "имена, механизмы, сравнения — а не общие слова.\n\n"
    "ЖЁСТКИЕ ПРАВИЛА:\n"
    "1. Пиши ТОЛЬКО по ФАКТЛИСТУ. Каждое утверждение с цифрой, датой, именем или результатом "
    "сопровождай ссылкой на источник в квадратных скобках: [3] или [2][5]. Номер — это номер "
    "ИСТОЧНИКА (src) из фактлиста, не номер факта. Абзац без единой ссылки допустим только как "
    "связка в 1-2 предложения.\n"
    "2. Запрещено добавлять факты, цифры и примеры, которых нет в фактлисте. Если данных не хватает — "
    "напиши прямо, чего не хватает и почему это важно (это тоже информация).\n"
    "3. Каждый раздел начинай с самого сильного факта, а не с подводки. Объясняй МЕХАНИЗМ: почему "
    "цифра такая, что её двигает, с чем её сравнить, чтобы понять масштаб.\n"
    "4. Обязательные разделы в конце (ровно с такими заголовками):\n"
    "   '## Цифры, которые стоит запомнить' — список из 5-8 пунктов: число → что означает → [n];\n"
    "   '## Что это значит для вас' — 3-5 конкретных решений/действий, каждое привязано к факту;\n"
    "   '## Где подвох' — ограничения данных, контраргументы, риски (честно, без смягчения).\n"
    "5. Если в плане есть таблица — вставь её Markdown-таблицей (| a | b |) в подходящий раздел.\n"
    "6. Стиль: короткие абзацы (2-4 предложения), обращение на «вы», живые глаголы, без воды. "
    "ЗАПРЕЩЕНЫ: «в современном мире», «стоит отметить», «важно отметить», «в заключение», "
    "«подводя итог», «давайте разберёмся», «не секрет», «играет важную роль», «как известно», "
    "«в эпоху цифровизации», «трудно переоценить», вступительные «итак», «таким образом».\n"
    "7. Структура: начинай сразу с первого '## ' (без заголовка '#' и без лида). Заголовки разделов — "
    "предметные, лучше с цифрой или именем. Используй '### ' и списки '- ' где уместно.\n"
    "8. Объём: {min}–{max} знаков. Отвечай ТОЛЬКО сырым Markdown, без ```-заборов и комментариев."
)


def build_write_prompt(topic: str, facts: list[dict], sources: list[dict], plan_: dict,
                       rag: list[dict] | None = None) -> str:
    parts = [f"ТЕМА: {topic}"]
    if plan_.get("thesis"):
        parts.append("ГЛАВНЫЙ ТЕЗИС: " + plan_["thesis"])
    if plan_.get("sections"):
        parts.append("\nПЛАН РАЗДЕЛОВ:")
        for s in plan_["sections"]:
            fl = ", ".join(f"F{i}" for i in s.get("facts") or [])
            parts.append(f"- {s['h2']} — {s.get('point', '')}" + (f" (факты: {fl})" if fl else ""))
    if plan_.get("practical"):
        parts.append("\nДЛЯ РАЗДЕЛА «ЧТО ЭТО ЗНАЧИТ ДЛЯ ВАС»:")
        parts.extend("- " + p for p in plan_["practical"])
    if plan_.get("caveats"):
        parts.append("\nДЛЯ РАЗДЕЛА «ГДЕ ПОДВОХ»:")
        parts.extend("- " + c for c in plan_["caveats"])
    t = plan_.get("table")
    if t:
        parts.append("\nТАБЛИЦА ДЛЯ ВСТАВКИ (" + (t.get("title") or "сравнение") + "):")
        parts.append("| " + " | ".join(t["columns"]) + " |")
        parts.append("|" + "---|" * len(t["columns"]))
        for r in t["rows"]:
            parts.append("| " + " | ".join(r) + " |")

    parts.append("\nФАКТЛИСТ (ссылайся на src):")
    for i, f in enumerate(facts, 1):
        when = f" ({f['when']})" if f.get("when") else ""
        parts.append(f"F{i} [src {f['src']}] {f['fact']}{when}")

    parts.append("\nИСТОЧНИКИ:")
    for i, s in enumerate(sources, 1):
        parts.append(f"[{i}] {s.get('title') or s['url']} — {_host(s['url'])}")

    if rag:
        parts.append("\nАРХИВ N2J (только для связи с нашими прошлыми материалами, факты оттуда не брать):")
        for item in rag[:3]:
            parts.append(f"- {str(item.get('title') or 'заметка')[:120]}")

    parts.append("\nНапиши тело статьи по правилам. Начинай с первого '## '.")
    return "\n".join(parts)


# ---------------------------------------------------------------------------
# Фаза 7: метрика и рецензент
# ---------------------------------------------------------------------------

_CITE_RE = re.compile(r"\[(\d{1,2})\]")


def measure(body: str, n_sources: int) -> dict:
    """Детерминированная метрика «предметности» текста."""
    text = body or ""
    chars = len(text)
    cites = [int(x) for x in _CITE_RE.findall(text)]
    cites_ok = [c for c in cites if 1 <= c <= max(1, n_sources)]
    numbers = len(re.findall(r"(?<![\[\w])\d+(?:[.,]\d+)?(?!\])", text))
    low = text.lower()
    banned = [b for b in _BANNED if b in low]
    paras = [p for p in re.split(r"\n\s*\n", text) if p.strip() and not p.strip().startswith("#")]
    cited_paras = sum(1 for p in paras if _CITE_RE.search(p))
    return {
        "chars": chars,
        "citations": len(cites_ok),
        "sources_cited": len(set(cites_ok)),
        "numbers": numbers,
        "numbers_per_1k": round(numbers * 1000 / max(1, chars), 1),
        "banned": banned[:6],
        "paras": len(paras),
        "cited_paras_pct": int(100 * cited_paras / max(1, len(paras))),
        "has_required": all(h in text for h in ("## Цифры", "## Что это значит", "## Где подвох")),
    }


def deterministic_score(m: dict) -> float:
    """0..10 по метрике: ссылки, плотность цифр, штампы, обязательные разделы."""
    s = 0.0
    s += min(3.0, m["citations"] / 4.0)             # ≥12 ссылок → 3
    s += min(2.0, m["sources_cited"] / 2.5)         # ≥5 разных источников → 2
    s += min(2.5, m["numbers_per_1k"] / 2.0)        # ≥5 чисел на 1000 знаков → 2.5
    s += 1.0 if m["has_required"] else 0.0
    s += min(1.5, m["cited_paras_pct"] / 40.0)      # ≥60% абзацев со ссылкой → 1.5
    s -= 0.5 * len(m["banned"])
    return round(max(0.0, min(10.0, s)), 1)


SYSTEM_CRITIC = (
    "Не рассуждай вслух и не пиши вступлений — первый символ ответа: {. "
    "Ты строгий рецензент аналитического издания. Оцени статью по одному критерию: узнает ли из "
    "неё опытный практик что-то НОВОЕ и КОНКРЕТНОЕ, или это пересказ общеизвестного «водой».\n"
    "Верни ТОЛЬКО JSON: {\"score\": 0-10, \"novelty\": 0-10, \"water\": 0-10, "
    "\"water_examples\": [\"до 5 цитат-штампов или пустых абзацев из текста\"], "
    "\"missing\": [\"до 5 мест, где нужна цифра/имя/ссылка, а их нет\"], "
    "\"fix\": \"конкретные инструкции для переписывания в 3-6 предложениях\"}.\n"
    "score 8-10 — каждый абзац несёт факт со ссылкой, есть механизм и сравнение; 5-7 — факты есть, "
    "но между ними вода; 0-4 — общие слова. Без пояснений вне JSON."
)


async def critic(chat: ChatFn, body: str, facts_n: int) -> dict:
    out = {"score": -1, "novelty": -1, "water": -1, "water_examples": [], "missing": [], "fix": ""}
    try:
        j = await json_call(chat, SYSTEM_CRITIC,
                            f"В фактлисте было {facts_n} фактов.\n\nСТАТЬЯ:\n{body[:24000]}",
                            "critic", 1500, 180.0)
        for k in ("score", "novelty", "water"):
            try:
                out[k] = max(0, min(10, int(float(j.get(k)))))
            except (TypeError, ValueError):
                pass
        out["water_examples"] = [str(x)[:200] for x in (j.get("water_examples") or [])][:5]
        out["missing"] = [str(x)[:200] for x in (j.get("missing") or [])][:5]
        out["fix"] = str(j.get("fix") or "")[:1200]
    except Exception:  # noqa: BLE001
        log.warning("nj602.research: рецензент не ответил", exc_info=True)
    return out


def revise_instructions(m: dict, c: dict) -> str:
    lines = ["Перепиши статью ЦЕЛИКОМ, сохранив структуру и правила, но устранив замечания:"]
    if c.get("fix"):
        lines.append("- Рецензент: " + c["fix"])
    for w in c.get("water_examples") or []:
        lines.append(f"- Убери или замени фактом: «{w}»")
    for mm in c.get("missing") or []:
        lines.append(f"- Добавь конкретику (цифра/имя/ссылка [n]) здесь: {mm}")
    if m["banned"]:
        lines.append("- Удали штампы: " + ", ".join(f"«{b}»" for b in m["banned"]))
    if m["cited_paras_pct"] < 60:
        lines.append(f"- Ссылок [n] мало: только {m['cited_paras_pct']}% абзацев со ссылкой, нужно больше 60%.")
    if not m["has_required"]:
        lines.append("- Добавь обязательные разделы «## Цифры, которые стоит запомнить», "
                     "«## Что это значит для вас», «## Где подвох».")
    lines.append("Факты по-прежнему брать ТОЛЬКО из фактлиста. Отвечай только Markdown.")
    return "\n".join(lines)


# ---------------------------------------------------------------------------
# Автотема: ищем свежее и предметное
# ---------------------------------------------------------------------------

_TOPIC_QUERIES = (
    "{year} study finds developers productivity percent",
    "{year} report software engineering statistics survey",
    "{year} AI tools benchmark results comparison",
    "{year} open source adoption data report",
    "исследование {year} разработчики автоматизация цифры",
    "отчёт {year} рынок ИТ инструменты статистика",
    "{year} robotics automation industry numbers report",
    "{year} cloud infrastructure cost data analysis",
)


def _overlap(a: str, b: str) -> float:
    wa = set(re.findall(r"[a-zа-яё0-9]{4,}", a.lower()))
    wb = set(re.findall(r"[a-zа-яё0-9]{4,}", b.lower()))
    if not wa or not wb:
        return 0.0
    return len(wa & wb) / len(wa | wb)


async def discover_topic(blocked: Callable[[str], bool], recent: list | None) -> str:
    """Тема = заголовок свежего материала с цифрами, не похожий на уже
    опубликованное. Выдачу оцениваем той же эвристикой, что и источники."""
    year = datetime.now().year
    rows: list[dict] = []
    for q in _TOPIC_QUERIES:
        try:
            found = await webread.ddg_text(q.format(year=year), max_results=6)
        except Exception:  # noqa: BLE001
            continue
        for r in found:
            rows.append({
                "url": str(r.get("url") or r.get("href") or ""),
                "title": str(r.get("title") or "").strip(),
                "snippet": str(r.get("body") or r.get("snippet") or "").strip(),
            })
        await asyncio.sleep(0.8)

    recent_titles = [str(r.get("title") or "") for r in (recent or []) if r.get("title")]
    best, best_s = "", -99.0
    for r in rows:
        if not r["title"] or blocked(r["title"] + " " + r["snippet"]):
            continue
        s = score_candidate(r, year)
        if re.search(r"\d", r["snippet"]):
            s += 1.0
        if len(r["title"]) < 25:
            s -= 1.0
        if any(_overlap(r["title"], t) > 0.35 for t in recent_titles):
            s -= 4.0
        if s > best_s:
            best, best_s = r["title"], s

    # заголовок выдачи часто с хвостом « | Site» или « - Site»
    best = re.split(r"\s+[|•–—-]\s+", best)[0].strip() if best else ""
    return best[:200] or "Практические инструменты автоматизации создания цифровых артефактов"
