"""nj661.airadar — ИИ-радар: конвейер наблюдения за прогрессом ИИ.

Пайплайн (наработки nj593_trends/nj605_radar: ddgs + webread + один LLM-вызов
со строгой JSON-схемой; nj613_digest: приём через сайт):
  1. источники кандидатов (каждый в своём try — один упавший не роняет прогон):
       - HuggingFace API: /api/models?sort=createdAt (свежие) + ?sort=likes;
       - OpenRouter /api/v1/models: бесплатные («окна возможностей»), новые,
         длинноконтекстные (скоринг зеркалирует PHP Service_Nj661_Airadar);
       - RSS ИИ-гигантов (HF/OpenAI/DeepMind/Meta/Mistral/Qwen/Phoronix);
       - ddgs-поиск («утка»): открытые модели, фреймворки, бесплатные токены;
       - lmarena.ai/leaderboard как страница-источник (текст, что отдается);
  2. POST /airadar/api/items — сайт дедупит по url_hash (виденное не
     возвращается) и отдаёт контекст базы знаний (недавние факты, теги);
  3. webread новых страниц (кап на страницу, бюджет времени, паузы);
  4. LLM-анализ БАТЧАМИ (строгий JSON): relevant/category/score/why +
     факты (не вода, с числами) + идеи для n2j (польза + куда);
  5. POST /airadar/api/analysis — факты и идеи уходят в базу знаний
     (дедуп по хешу — на стороне PHP);
  6. POST /airadar/api/report_input → LLM пишет заголовок/сводку/главное →
     POST /airadar/api/report (сайт сохраняет отчёт и решает, пора ли письмо);
  7. POST /airadar/api/finish.

Env: адрес приёмки и токен берутся из ОБЩИХ NJ120_API_BASE и WORKER_SITE_TOKEN
(или NJ661_AIRADAR_API_BASE/SITE_TOKEN — если канал живёт на другом сайте);
NJ661_AIRADAR_MODEL («провайдер::модель», опц.), NJ661_AIRADAR_CONTEXT
(примерный контекст модели в токенах, по умолчанию 16000 для локальной Gemma —
из него считаются размер батча анализа и обрезка текста), NJ661_AIRADAR_BATCH /
NJ661_AIRADAR_PAGE_CAP (точные переопределения), NJ661_AIRADAR_QUERIES
(доп. ddgs-запросы через «|»), NJ661_AIRADAR_RSS (доп. ленты через «|»),
NJ661_AIRADAR_MAX_PAGES (12), NJ661_AIRADAR_DELAY (1.5),
NJ661_AIRADAR_OUT_TOKENS (8000 — бюджет ответа для рассуждающей модели),
NJ661_AIRADAR_THINK (auto|0|1: отключать ли размышления модели),
NJ661_AIRADAR_MAX_FAILS (5 — сколько пачек подряд можно провалить, прежде
чем отложить остаток очереди).

Рассуждающие модели (Qwen3.5 и подобные) тратят completion-бюджет на
reasoning_content — инцидент 15.10.2026: 2953 токена из 3000 ушли в
размышления, JSON анализа оборвался, прогон закрылся «ни одного материала».
Поэтому анализ смотрит на usage: при обрыве по лимиту расширяет бюджет и
мельчит пачки, при размышлениях ещё и глушит их (если сервер умеет).
"""

from __future__ import annotations

import asyncio
import json
from collections import deque
import logging
import os
import time

import httpx

from .. import site, webread
from ..llmchat import chat_with_usage as _llm

log = logging.getLogger("nj661_airadar")

_PREFIX = "NJ661_AIRADAR"

_RSS_FEEDS = [
    "https://huggingface.co/blog/feed.xml",
    "https://openai.com/news/rss.xml",
    "https://blog.google/technology/ai/rss/",
    "https://deepmind.google/blog/rss.xml",
    "https://ai.meta.com/blog/rss/",
    "https://mistral.ai/news/rss.xml",
    "https://qwenlm.github.io/blog/index.xml",
    "https://www.phoronix.com/rss.php",
]

# «утка го»: DuckDuckGo-поиск по темам владельца. RU+EN, чтобы ловить и
# русскоязычные разборы, и первоисточники.
_DDGS_QUERIES = [
    "open source LLM release",
    "новая открытая языковая модель релиз",
    "open weights model release week",
    "AI framework open source release",
    "free LLM API credits tokens",
    "lmarena chatbot arena leaderboard",
    "AI breakthrough paper practical",
    "недооценённые open source ИИ инструменты",
]

_CATEGORIES = ["breakthrough", "window", "release", "framework", "niche", "research"]

SYSTEM_ANALYST = (
    "Ты — аналитик ИИ-индустрии на службе владельца платформы n2j.ru "
    "(практикующий разработчик сервисов). Тебе дают материалы из ИИ-радара: "
    "заголовок, сниппет и текст страницы (если есть). Отбери ценное ДЛЯ ПРАКТИКИ: "
    "технологические прорывы, которые можно применить; окна возможностей "
    "(ограниченные во времени: бесплатные токены хороших моделей, щедрые лимиты, "
    "ранний доступ); релизы открытых моделей и весов; открытые фреймворки и "
    "инструменты; недооценённое и незанятые ниши. Шум и маркетинг — relevant=false. "
    "ОТВЕЧАЙ ТОЛЬКО ВАЛИДНЫМ JSON без markdown: "
    '{"items": [{"id": <id>, "relevant": true|false, "category": '
    '"breakthrough|window|release|framework|niche|research", "score": 0-100, '
    '"why": "1-2 предложения по-русски: что это и почему полезно", '
    '"facts": [{"text": "краткий ФАКТ с числами/именами, не вода", "tags": ["модель","org"]}], '
    '"ideas": [{"title": "что сделать на n2j", "body": "как", '
    '"benefit": "польза одним предложением", "fit": "куда: модуль-сервис/улучшение чего", '
    '"score": 0-100}]}]}. '
    "Факты — только конкретика (числа, имена, версии, цены, лимиты); фактов 0-3 на "
    "материал. Идеи — 0-2, только если находка реально применима на n2j как "
    "модуль-сервис или улучшение. Учти КОНТЕКСТ БАЗЫ ЗНАНИЙ: то, что там уже есть, "
    "не выдавай за новое (но обновлённые числа — новое)."
)

SYSTEM_AUTHOR = (
    "Ты — редактор ИИ-радара. По данным прогона напиши владельцу n2j.ru короткий "
    "отчёт: что изменилось в ИИ, на что обратить внимание В ПЕРВУЮ ОЧЕРЕДЬ и какие "
    "окна возможностей закрываются со временем. По-русски, по делу, с числами, без "
    "воды. ОТВЕЧАЙ ТОЛЬКО ВАЛИДНЫМ JSON: "
    '{"title": "заголовок до 80 символов", "summary": "2-4 предложения", '
    '"highlights": ["3-6 пунктов, самое важное"]}'
)


def _env_int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, "") or default)
    except ValueError:
        return default


def _env_float(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, "") or default)
    except ValueError:
        return default


def _delay() -> float:
    return max(0.5, _env_float("NJ661_AIRADAR_DELAY", 1.5))


def _max_pages(limits: dict) -> int:
    return max(0, int(limits.get("max_pages", _env_int("NJ661_AIRADAR_MAX_PAGES", 12))))


def _context(limits: dict) -> int:
    """Примерный контекст модели, токенов.

    NJ661_AIRADAR_CONTEXT (например, 16000 для локальной Gemma на 16K):
    из него считаются размер батча анализа и обрезка текста страниц —
    промпт укладывается в контекст без правки кода (запрос владельца
    06.10.2026: карта не тянет больше 16К).
    """
    return max(4000, _env_int("NJ661_AIRADAR_CONTEXT", int(limits.get("context") or 16000)))


def _batch_size(limits: dict, wide: bool = False) -> int:
    """Материалов на один LLM-вызов анализа.

    Настройка задаёт верхнюю границу; учитываются контекст и бюджет ответа.
    При 3000 токенах ответа — не больше трёх материалов, разрешён батч 1.
    wide — режим длинного ответа: рассуждающей модели нужен запас и мелкая
    пачка (reasoning-токены считаются в тот же completion-бюджет).
    """
    requested = _env_int("NJ661_AIRADAR_BATCH", int(limits.get("llm_batch") or 8))

    if wide:
        return max(1, min(requested, _out_tokens(limits, True) // 3000))

    # Ответ содержит до трёх фактов и двух идей на материал. Восемь таких
    # объектов не помещались в 3000 токенов: хвост JSON обрывался.
    return max(1, min(requested, _context(limits) // 4000, _out_tokens(limits) // 1000))


def _page_cap(limits: dict) -> int:
    """Символов текста страницы на материал.

    Явный NJ661_AIRADAR_PAGE_CAP, иначе 1/8 контекста (до прежних 3000).
    """
    if (os.environ.get("NJ661_AIRADAR_PAGE_CAP") or "").strip():
        return max(500, _env_int("NJ661_AIRADAR_PAGE_CAP", 3000))

    return max(500, min(int(limits.get("page_cap") or 3000), _context(limits) // 8))


def _out_tokens(limits: dict, wide: bool = False) -> int:
    """Бюджет ответа LLM анализа: до 3000, но не больше 1/5 контекста.

    wide=True (модель рассуждает или уже обрывала ответ) — до
    NJ661_AIRADAR_OUT_TOKENS (по умолчанию 8000), но не больше половины
    контекста: reasoning-токены идут в тот же лимит completion.
    """
    base = max(800, min(3000, _context(limits) // 5))

    if not wide:
        return base

    ceiling = _env_int("NJ661_AIRADAR_OUT_TOKENS", int(limits.get("out_tokens") or 8000))

    return max(base, min(ceiling, _context(limits) // 2))


def _text_usage(result) -> tuple[str, dict]:
    """Результат вызова LLM: строка (стенды) либо (текст, usage) из llmchat."""
    if isinstance(result, tuple) and len(result) >= 2:
        text, usage = result[0], result[1]
        return ("" if text is None else str(text)), (usage if isinstance(usage, dict) else {})

    return ("" if result is None else str(result)), {}


def _reasoning_tokens(usage: dict) -> int:
    """Сколько токенов модель потратила на размышления (0 — не рассуждает)."""
    details = usage.get("completion_tokens_details") or {}

    if not isinstance(details, dict):
        return 0

    try:
        return max(0, int(details.get("reasoning_tokens") or 0))
    except (TypeError, ValueError):
        return 0


def _is_length(usage: dict) -> bool:
    """Ответ модели оборван по max_tokens (finish_reason=length)."""
    return str(usage.get("_llm_finish_reason") or "").strip().lower() == "length"


def _truncation_hint(reasoning: int, out_tokens: int) -> str:
    """Понятная причина обрыва: владелец видит её на странице прогона."""
    if reasoning:
        return (f"модель израсходовала {reasoning} токенов на размышления из "
                f"{out_tokens} — JSON анализа оборвался. Включите NJ661_AIRADAR_THINK=0, "
                f"чтобы отвечать без размышлений, увеличьте NJ661_AIRADAR_OUT_TOKENS "
                f"или возьмите модель без рассуждений (NJ661_AIRADAR_MODEL)")

    return (f"ответ модели оборван по лимиту {out_tokens} токенов — "
            f"увеличьте NJ661_AIRADAR_OUT_TOKENS")


def _think_off_requested() -> bool | None:
    """NJ661_AIRADAR_THINK: 0/off — глушить сразу, 1/on — не глушить, auto — по факту."""
    value = (os.environ.get("NJ661_AIRADAR_THINK") or "").strip().lower()

    if value in ("0", "off", "no", "false", "выкл"):
        return False

    if value in ("1", "on", "yes", "true", "вкл"):
        return True

    return None


def _think_mode(limits: dict) -> bool | None:
    """Глушить ли размышления: env NJ661_AIRADAR_THINK важнее limits.think.

    False — не рассуждать (сразу), True — не глушить вовсе, None — «auto»:
    решаем по факту первого ответа модели.
    """
    env = _think_off_requested()

    if env is not None:
        return env

    value = limits.get("think")

    if isinstance(value, bool):
        return value

    if isinstance(value, int):
        return bool(value)

    text = str(value if value is not None else "").strip().lower()

    if text in ("0", "off", "no", "false", "выкл"):
        return False

    if text in ("1", "on", "yes", "true", "вкл"):
        return True

    return None


def _think_options() -> dict:
    """Поля запроса, отключающие размышления там, где это поддержано.

    chat_template_kwargs — llama.cpp/LM Studio (шаблон Qwen), reasoning_effort —
    шлюзы и OpenAI-совместимые API. Сервер, который поля не знает, обычно
    игнорирует их; 400/422 ловит вызывающий и повторяет запрос без них.
    """
    return {"reasoning_effort": "none", "chat_template_kwargs": {"enable_thinking": False}}


def _repack(pending: deque, limit: int) -> deque:
    """Разложить оставшиеся пачки по limit материалов (мельче — вернее)."""
    out: deque = deque()

    for batch, retry in pending:
        if len(batch) <= limit:
            out.append((batch, retry))
            continue

        for item in batch:
            out.append(([item], retry))

    return out


async def _api_post(ctx, path: str, data: dict) -> dict:
    """POST на сайт через общий слой site.post_json; ошибка = RuntimeError.

    Адрес — site.module_api_base: общий корень (NJ120_API_BASE / NJ120_API_URL)
    + /airadar/api, чтобы один адрес сайта обслуживал все каналы; свой
    NJ661_AIRADAR_API_BASE нужен только для другого сайта.
    """
    ok, reason, j = await site.post_json(_PREFIX, path, data, json_body=True,
                                         timeout=120.0,
                                         base=site.module_api_base(_PREFIX, "/airadar/api"))

    if not ok:
        raise RuntimeError(f"nj661_airadar: сайт {path} → {reason}")

    if not isinstance(j, dict) or j.get("error") or j.get("ok") is not True:
        raise RuntimeError(f"nj661_airadar: сайт {path} отказал: {j}")

    return j if isinstance(j, dict) else {}


async def _sleep(sec: float) -> None:
    await asyncio.sleep(sec)


# ---------------------------------------------------------------- источники

async def _fetch_json(url: str, timeout: float = 45.0):
    """GET JSON у публичного API (HF/OpenRouter); ошибка — исключение наверх."""
    async with httpx.AsyncClient(timeout=timeout, follow_redirects=True,
                                 headers={"User-Agent": "n2j-airadar/1.0"}) as client:
        r = await client.get(url)
        r.raise_for_status()
        return r.json()


async def _source_huggingface() -> list[dict]:
    out: list[dict] = []

    for sort in ("createdAt", "likes"):
        try:
            data = await _fetch_json(
                f"https://huggingface.co/api/models?sort={sort}&direction=-1&limit=100")
        except Exception as e:  # noqa: BLE001
            log.info("nj661_airadar: HF sort=%s не отдался: %s", sort, e)
            continue

        for m in data if isinstance(data, list) else []:
            mid = str(m.get("id") or "")
            if not mid:
                continue

            tags = m.get("tags") or []
            license_ = next((str(t) for t in tags if str(t).startswith("license:")), "")
            fresh = str(m.get("createdAt") or "")[:10]
            out.append({
                "source": "huggingface",
                "url": f"https://huggingface.co/{mid}",
                "title": mid,
                "snippet": (f"HF · {m.get('pipeline_tag') or 'model'} · скачиваний "
                            f"{m.get('downloads') or 0} · лайков {m.get('likes') or 0}"
                            + (f" · {license_.replace('license:', '')}" if license_ else "")),
                "published_at": fresh or None,
                "meta": {"id": mid, "sort": sort},
            })
        await _sleep(_delay())

    return out


async def _source_openrouter(now: float) -> list[dict]:
    try:
        data = await _fetch_json("https://openrouter.ai/api/v1/models")
    except Exception as e:  # noqa: BLE001
        log.info("nj661_airadar: OpenRouter не отдался: %s", e)
        return []

    out: list[dict] = []

    for m in (data or {}).get("data") or []:
        mid = str(m.get("id") or "")
        name = str(m.get("name") or "")

        if not mid or not name:
            continue

        pricing = m.get("pricing") or {}
        free = float(pricing.get("prompt") or 1) == 0 and float(pricing.get("completion") or 1) == 0
        created = int(m.get("created") or 0)
        fresh = created > 0 and (now - created) <= 14 * 86400
        ctx_len = int(m.get("context_length") or 0)

        if not (free or fresh or ctx_len >= 128000):
            continue

        out.append({
            "source": "openrouter",
            "url": f"https://openrouter.ai/{mid}",
            "title": f"{name} ({mid})",
            "snippet": (f"OpenRouter · контекст {ctx_len} токенов"
                        + (" · БЕСПЛАТНО (окно возможностей)" if free else
                           f" · ${float(pricing.get('prompt') or 0):.2f}/M вход")),
            "published_at": (time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime(created))
                             if created > 0 else None),
            "meta": {"id": mid, "free": free, "context": ctx_len},
        })

    return out


async def _source_rss() -> list[dict]:
    feeds = list(_RSS_FEEDS)

    for extra in os.environ.get("NJ661_AIRADAR_RSS", "").split("|"):
        if extra.strip():
            feeds.append(extra.strip())

    out: list[dict] = []

    for feed in feeds:
        try:
            async with httpx.AsyncClient(timeout=30.0, follow_redirects=True,
                                         headers={"User-Agent": "n2j-airadar/1.0"}) as client:
                r = await client.get(feed)
                r.raise_for_status()
                xml = r.text
        except Exception as e:  # noqa: BLE001
            log.info("nj661_airadar: лента %s не отдалась: %s", feed, e)
            continue

        # разбор RSS/Atom на чистом stdlib (Entrez не нужен: title/link/description)
        try:
            entries = _parse_feed(xml, limit=6)
        except Exception as e:  # noqa: BLE001
            log.info("nj661_airadar: %s не разобралась: %s", feed, e)
            continue

        for e in entries:
            out.append({
                "source": "rss",
                "url": e["url"],
                "title": e["title"],
                "snippet": e.get("snippet") or feed,
                "published_at": e.get("published_at"),
                "meta": {"feed": feed},
            })
        await _sleep(_delay())

    return out


def _parse_feed(xml: str, limit: int = 6) -> list[dict]:
    """RSS/Atom → [{url,title,snippet,published_at}]. Чистая, тестируется pytest."""
    import re
    import email.utils as email_utils
    from xml.etree import ElementTree as ET

    root = ET.fromstring(xml)
    out: list[dict] = []

    # namespaces не важны: ищем локальные имена
    def _local(tag: str) -> str:
        return tag.rsplit("}", 1)[-1]

    def _text(node, name: str) -> str:
        for child in node:
            if _local(child.tag) == name:
                return (child.text or "").strip()
        return ""

    def _link(node) -> str:
        for child in node:
            if _local(child.tag) == "link":
                href = child.get("href")
                if href:
                    return href.strip()
                return (child.text or "").strip()
        return ""

    nodes = [n for n in root.iter() if _local(n.tag) in ("item", "entry")][:limit]

    for node in nodes:
        url = _link(node)
        title = _text(node, "title")

        if not url or not title:
            continue

        snippet = _text(node, "description") or _text(node, "summary")
        snippet = re.sub(r"<[^>]+>", " ", snippet or "")[:500].strip()

        date_raw = _text(node, "pubDate") or _text(node, "updated") or _text(node, "published")
        published = None

        for parser in (lambda s: email_utils.parsedate_to_datetime(s),
                       lambda s: __import__("datetime").datetime.fromisoformat(
                           s.replace("Z", "+00:00"))):
            try:
                published = parser(date_raw).strftime("%Y-%m-%d %H:%M:%S")
                break
            except Exception:  # noqa: BLE001
                continue

        out.append({"url": url, "title": title[:300], "snippet": snippet,
                    "published_at": published})

    return out


async def _source_ddgs() -> list[dict]:
    queries = list(_DDGS_QUERIES)

    for extra in os.environ.get("NJ661_AIRADAR_QUERIES", "").split("|"):
        if extra.strip() and extra.strip() not in queries:
            queries.append(extra.strip())

    out: list[dict] = []

    for q in queries:
        try:
            rows = await webread.ddg_text(q, max_results=6)
        except Exception as e:  # noqa: BLE001
            log.info("nj661_airadar: ddgs «%s» не отдался: %s", q, e)
            continue

        for r in rows or []:
            url = str(r.get("href") or r.get("url") or "").strip()
            title = str(r.get("title") or "").strip()

            if not url or not title:
                continue

            out.append({
                "source": "ddg",
                "url": url,
                "title": title[:300],
                "snippet": str(r.get("body") or "")[:500],
                "published_at": None,
                "meta": {"query": q},
            })
        await _sleep(_delay())

    return out


async def _source_arena() -> list[dict]:
    """lmarena.ai/leaderboard — текст страницы как материал (JS-рендер может
    оставить мало текста: тогда источник просто молчит, его добирает ddgs)."""
    try:
        html, _err = await webread.fetch("https://lmarena.ai/leaderboard")
        md, title = await webread.to_markdown(html, cap=4000)
    except Exception as e:  # noqa: BLE001
        log.info("nj661_airadar: lmarena не прочитался: %s", e)
        return []

    if len(md) < 300:
        return []

    return [{
        "source": "arena",
        "url": "https://lmarena.ai/leaderboard",
        "title": title or "LMArena: лидерборд моделей",
        "snippet": "Текущий лидерборд Chatbot Arena (таблица модели/elo).",
        "published_at": time.strftime("%Y-%m-%d %H:%M:%S", time.gmtime()),
        "meta": {"text": md[:4000]},
    }]


def _mix_sources(per_source: list[list[dict]]) -> list[dict]:
    """Round-robin между источниками: богатый источник (HF ~200 моделей) не
    вытесняет остальных из среза max_items — первый живой прогон отдал
    «120 материалов, все из hf». Чистая, тестируется pytest."""
    from itertools import zip_longest

    out: list[dict] = []

    for chunk in zip_longest(*per_source):
        out.extend(item for item in chunk if item)

    return out


def _dedupe(items: list[dict]) -> list[dict]:
    """Локальный дедуп по нормализованному URL (первое вхождение выигрывает)."""
    import re

    def norm(url: str) -> str:
        url = (url or "").strip()
        url = re.sub(r"[#?].*$", "", url)
        url = re.sub(r"^https?://", "", url, flags=re.I).lower().rstrip("/")
        return url

    seen: set[str] = set()
    out: list[dict] = []

    for it in items:
        key = norm(str(it.get("url") or ""))

        if not key or key in seen:
            continue

        seen.add(key)
        out.append(it)

    return out


# ---------------------------------------------------------------- конвейер

def _knowledge_block(knowledge: dict) -> str:
    """Контекст базы знаний для промпта анализа (учёт сделанного ранее)."""
    if not knowledge:
        return "База знаний пуста (первый прогон)."

    parts = [f"В базе знаний уже {knowledge.get('total_facts', 0)} фактов, "
             f"{knowledge.get('total_ideas', 0)} идей."]

    facts = knowledge.get("recent_facts") or []

    if facts:
        parts.append("Недавние факты (НЕ считай новое, если совпадает по сути):")

        for f in facts[:40]:
            parts.append(f"- {f.get('text', '')}")

    tags = knowledge.get("known_tags") or []

    if tags:
        parts.append("Известные темы: " + ", ".join(tags[:24]) + ".")

    return "\n".join(parts)


def _item_block(item: dict, md: str, cap: int = 3000) -> str:
    """Блок материала для промпта; cap — бюджет текста (из контекста модели)."""
    parts = [f"--- МАТЕРИАЛ #{item.get('id')} ---",
             f"Заголовок: {item.get('title', '')}",
             f"Источник: {item.get('source', '')} · {item.get('url', '')}",
             f"Сниппет: {item.get('snippet', '')}"]

    meta = item.get("meta") or {}

    if meta.get("text"):
        parts.append(f"Текст: {str(meta['text'])[:cap + 1000]}")
    elif md:
        parts.append(f"Текст страницы: {md[:cap]}")

    return "\n".join(parts)


def _analysis_messages(batch: list[dict], pages: dict, knowledge: dict, limits: dict,
                       out_tokens: int | None = None, strict: bool = False) -> list[dict]:
    """Общий бюджет: база знаний + материалы + ответ, с запасом для кириллицы.

    Один символ на токен — консервативная оценка, не точный токенизатор.
    out_tokens — фактический лимит ответа этого вызова (режим рассуждений,
    потолок провайдера): промпт не должен съесть его запас.
    strict — повтор после неразобранного ответа: напоминаем про «только JSON»
    прямо в конце. На длинном промпте модель к этому месту уже забыла
    инструкцию из system-сообщения и отвечала прозой.
    """
    budget = _context(limits) - (out_tokens if out_tokens is not None else _out_tokens(limits)) - 512
    intro = "КОНТЕКСТ БАЗЫ ЗНАНИЙ:\n"
    end = "\nПроанализируй все материалы и верни JSON со схемой из системы."

    if strict:
        end += ("\nОТВЕТ — ТОЛЬКО JSON-объект по схеме: {\"items\": [...]}; "
                "без markdown, без пояснений, без текста до и после.")
    available = max(0, budget - len(SYSTEM_ANALYST) - len(intro) - len(end) - 32)
    kb = _knowledge_block(knowledge)[:min(3000, available // 4)]
    cap = max(1, (available - len(kb)) // max(1, len(batch)) - 2)
    blocks = [_item_block(it, pages.get(int(it["id"]), ""), _page_cap(limits))[:cap] for it in batch]
    return [{"role": "system", "content": SYSTEM_ANALYST},
            {"role": "user", "content": intro + kb + "\n" + "\n".join(blocks) + end}]


def _boolish(value):
    """true/«да»/1 → True, false/«нет»/0 → False, иначе None (поля не было)."""
    if isinstance(value, bool):
        return value

    if isinstance(value, (int, float)):
        return bool(value)

    text = str(value if value is not None else "").strip().lower()

    if text in ("true", "yes", "1", "1.0", "да"):
        return True

    if text in ("false", "no", "0", "0.0", "нет"):
        return False

    return None


def _score(value) -> float:
    """Score 0-100 из числа или строки вида «85»/«85/100»; иначе 0."""
    if isinstance(value, bool):
        return 0.0

    try:
        num = float(str(value).replace(",", ".").split("/")[0].strip())
    except (TypeError, ValueError):
        return 0.0

    return max(0.0, min(100.0, num))


def _dicts(value) -> list[dict]:
    """Список объектов из поля модели (иногда приходит одиночный объект)."""
    if isinstance(value, dict):
        return [value]

    if isinstance(value, list):
        return [row for row in value if isinstance(row, dict)]

    return []


def _normalize_row(row: dict, known: set[int]) -> dict | None:
    """Строка ответа → строка схемы анализа; None — материал не разобран.

    Модели возвращают поля по-разному: нет relevant, score строкой, ideas
    отсутствуют, факт объектом без tags. Раньше любое отклонение выбрасывало
    ВЕСЬ материал, он уходил в повтор, а после повтора — в «не обработано»
    (живой прогон 21.10.2026: 6 из 120 при полностью готовом отчёте).
    Теперь поля чинятся мягко, но пустой огрызок объекта (только id+relevant)
    по-прежнему не считается разобранным материалом.
    """
    ident = row.get("id", row.get("item_id", row.get("material_id")))

    try:
        ident = int(str(ident).strip())
    except (TypeError, ValueError):
        return None

    if ident not in known:
        return None

    facts = []

    for fact in _dicts(row.get("facts")):
        text = str(fact.get("text") or fact.get("fact") or "").strip()

        if not text:
            continue

        out = {"text": text[:600]}
        raw_tags = fact.get("tags")

        if isinstance(raw_tags, list):
            tags = [str(t).strip()[:40] for t in raw_tags if str(t).strip()]

            if tags:
                out["tags"] = tags[:6]

        facts.append(out)

    ideas = []

    for idea in _dicts(row.get("ideas")):
        title = str(idea.get("title") or "").strip()

        if not title:
            continue

        ideas.append({"title": title[:200],
                      "body": str(idea.get("body") or idea.get("how") or "")[:800],
                      "benefit": str(idea.get("benefit") or "")[:400],
                      "fit": str(idea.get("fit") or idea.get("where") or "")[:200],
                      "score": _score(idea.get("score"))})

    score = _score(row.get("score"))
    relevant = _boolish(row.get("relevant"))

    if relevant is None:
        # поля нет — судим по существу ответа
        relevant = bool(ideas or facts) or score >= 50

    category = str(row.get("category") or "").strip().lower()

    if category not in _CATEGORIES:
        category = "research" if relevant else "niche"

    out = {"id": ident, "relevant": bool(relevant), "category": category,
           "score": score, "why": str(row.get("why") or row.get("reason") or "")[:800],
           "facts": facts[:3], "ideas": ideas[:2]}

    # Совсем пустой огрызок (модель оборвалась на этом объекте) — не материал.
    if not (out["why"] or out["score"] or out["facts"] or out["ideas"]):
        return None

    return out


def _analysis_results(raw: str, batch: list[dict]) -> list[dict]:
    """Разобрать ответ модели; пустой список — «ничего не разобрали».

    Три источника строк, по убыванию надёжности:
      1. поле items/results/materials разобранного объекта схемы;
      2. любой целый объект ответа (webread.json_objects) — модель вернула
         массив верхнего уровня или прозу вокруг JSON;
      3. ничего: ответ пуст, оборван в начале или это не JSON вовсе.
    """
    known = {int(it["id"]) for it in batch}
    clean: dict[int, dict] = {}
    parsed = webread.json_from_text(raw)
    rows: list = []

    if isinstance(parsed, dict):
        for key in ("items", "results", "materials", "data", "analysis", "answer"):
            value = parsed.get(key)

            if isinstance(value, list):
                rows = value
                break

    if not rows:
        rows = webread.json_objects(raw)

    for row in rows:
        if not isinstance(row, dict):
            continue

        norm = _normalize_row(row, known)

        if norm is not None:
            clean[norm["id"]] = norm

    if not clean and not rows:
        # Совсем нечего разобрать: в журнал и на страницу прогона нужно, ЧТО
        # вернула модель, иначе «пустой JSON» не отличить от «модель молчит».
        raise ValueError("LLM не вернула items: пустой, повреждённый или обрезанный JSON"
                         f" (ответ: {len(raw or '')} символов)")

    return list(clean.values())


async def _progress(ctx, run_id: int, stage: str, note: str, stats: dict) -> None:
    await ctx.progress(stage, note)
    await _api_post(ctx, "/progress", {"run_id": run_id, "stage": stage, "note": note, "stats": stats})


async def handle(ctx, payload: dict) -> dict:
    """Сбой, включая общий таймаут, закрывает прогон с причиной, не успехом."""
    run_id = int(payload.get("run_id") or 0)
    if not run_id:
        raise RuntimeError("nj661_airadar: в задаче нет run_id")
    stats = {"sources": {}, "analyzed": 0}
    try:
        return await _run(ctx, payload, stats)
    except (Exception, asyncio.CancelledError) as exc:
        reason = ("Прогон прерван: таймаут задачи или остановка воркера"
                  if isinstance(exc, asyncio.CancelledError) else f"{type(exc).__name__}: {exc}")
        log.error("nj661_airadar: run=%s failed: %s; stats=%s", run_id, reason, stats)
        try:
            await asyncio.wait_for(_api_post(ctx, "/finish", {
                "run_id": run_id, "status": "fail", "stats": stats, "error": reason[:1000],
            }), timeout=15)
        except Exception as delivery:
            log.error("nj661_airadar: run=%s finish не доставлен: %s", run_id, delivery)
        raise


async def _run(ctx, payload: dict, stats: dict) -> dict:
    run_id = int(payload["run_id"])
    # Оставляем время на отчёт и доставку до общего таймаута задачи 2700 с.
    deadline = time.monotonic() + 2400

    limits = dict(payload.get("limits") or {})
    now = time.time()

    await _progress(ctx, run_id, "Источники", "собираю кандидатов: HF, OpenRouter, RSS, ddgs, lmarena", stats)

    # --- 1. кандидаты из всех источников (каждый в своём try) ---------------
    # Смешивание round-robin (_mix_sources): HuggingFace один отдаёт ~200
    # моделей, и пока он стоял первым, срез max_items забивался только им —
    # живой прогон 06.10.2026: «в материалах из 120 только hf». Теперь срез
    # честно делится между всеми источниками.
    per_source: list[list[dict]] = []

    for name, source in (("huggingface", _source_huggingface),
                         ("openrouter", lambda: _source_openrouter(now)),
                         ("rss", _source_rss),
                         ("ddgs", _source_ddgs),
                         ("arena", _source_arena)):
        try:
            rows = await source()
        except Exception as e:  # noqa: BLE001 — источник упал — идём дальше
            log.warning("nj661_airadar: источник %s упал: %s", name, e)
            stats.setdefault("source_errors", {})[name] = f"{type(e).__name__}: {e}"[:160]
            rows = []

        stats["sources"][name] = len(rows)
        per_source.append(rows)

    candidates = _dedupe(_mix_sources(per_source))
    # Лимит применяется на сайте ПОСЛЕ дедупа: старые URL не съедают окно.
    if not candidates:
        raise RuntimeError("Все источники вернули 0 кандидатов: проверьте доступ к источникам и журнал воркера")
    stats["candidates"] = len(candidates)

    # --- 2. сайт дедупит и отдаёт только новое + контекст базы -------------
    res = await _api_post(ctx, "/items", {"run_id": run_id,
                                          "items": json.dumps(candidates, ensure_ascii=False)})

    accepted = res.get("accepted") or []
    knowledge = res.get("knowledge") or {}
    stats["accepted"] = len(accepted)
    stats["skipped_dup"] = int(res.get("skipped") or 0)
    stats["deferred"] = int(res.get("deferred") or 0)

    # --- 3. чтение страниц (без собственного текста) ------------------------
    pages: dict[int, str] = {}
    to_read = [it for it in accepted if not (it.get("meta") or {}).get("text")][: _max_pages(limits)]
    t_read = time.monotonic()

    for i, it in enumerate(to_read, 1):
        if time.monotonic() - t_read > 240:
            await _progress(ctx, run_id, "Чтение", f"бюджет чтения исчерпан: {len(pages)} из {len(to_read)}", stats)
            break

        await _progress(ctx, run_id, "Чтение", f"{i}/{len(to_read)} — {str(it.get('url'))[:80]}", stats)

        try:
            html, _ = await webread.fetch(str(it.get("url")))
            md, _title = await webread.to_markdown(html, cap=_page_cap(limits))
        except Exception as e:  # noqa: BLE001
            log.info("nj661_airadar: не прочитали %s: %s", it.get("url"), e)
            continue

        if len(md) >= 200 and it.get("id"):
            pages[int(it["id"])] = md

        await _sleep(_delay())

    stats["pages_read"] = len(pages)

    # --- 4. LLM-анализ батчами ----------------------------------------------
    # Рассуждающие модели тратят completion-бюджет на reasoning_content и
    # обрывают JSON: инцидент 15.10.2026 — 2953 токена из 3000 на размышления,
    # «LLM не проанализировала ни одного материала». Следим за usage:
    #   * обрыв по лимиту (finish_reason=length) → расширяем бюджет ответа;
    #   * есть reasoning-токены → мельчим пачки и глушим размышления, если
    #     можно (NJ661_AIRADAR_THINK=0 — глушить сразу, 1 — не глушить вовсе).
    think_setting = _think_mode(limits)
    wide = think_setting is False
    suppress = wide
    # Потолок провайдера: сервер вправе ответить 400 на большой max_tokens.
    budget_ceiling: int | None = None
    batch_size = _batch_size(limits, wide)
    totals = {"facts_new": 0, "facts_seen": 0, "ideas_new": 0}

    pending = deque((accepted[i:i + batch_size], False) for i in range(0, len(accepted), batch_size))
    failed_ids = []
    failures = 0
    # Сколько пачек ПОДРЯД можно провалить, прежде чем признать провайдера
    # нерабочим. Раньше порог был 3 на весь прогон, и три неудачные пачки
    # выбрасывали остаток очереди (живой прогон 21.10.2026: 6 из 120). Теперь
    # серия сбрасывается на любой удачной пачке, а неразобранный ответ модели
    # (ValueError) серию не растит вовсе — это не отказ провайдера.
    max_fail_streak = max(2, _env_int("NJ661_AIRADAR_MAX_FAILS", 5))
    while pending:
        if time.monotonic() >= deadline:
            stats["aborted"] = "deadline"
            failed_ids.extend(int(it["id"]) for batch, _ in pending for it in batch)
            log.warning("nj661_airadar: run=%s дедлайн анализа — не успели %s материалов",
                        run_id, sum(len(b) for b, _ in pending))
            break

        if failures >= max_fail_streak:
            stats["aborted"] = "provider"
            failed_ids.extend(int(it["id"]) for batch, _ in pending for it in batch)
            log.warning("nj661_airadar: run=%s %s пачек подряд без ответа — остаток (%s материалов) "
                        "отложен: %s", run_id, failures, sum(len(b) for b, _ in pending),
                        stats.get("last_analysis_error", ""))
            break

        batch, retry = pending.popleft()
        out_tokens = _out_tokens(limits, wide)

        if budget_ceiling is not None:
            out_tokens = min(out_tokens, budget_ceiling)

        messages = _analysis_messages(batch, pages, knowledge, limits, out_tokens, strict=retry)
        await _progress(ctx, run_id, "Анализ",
                        f"готово {stats['analyzed']}/{len(accepted)}; пачка {len(batch)}; "
                        f"контекст {_context(limits)}, ответ {out_tokens} токенов"
                        + ("; рассуждения модели" if wide else ""), stats)
        clean = []
        usage: dict = {}
        raw = ""
        try:
            wait = min(420, max(1, int(deadline - time.monotonic())))
            raw, usage = _text_usage(await asyncio.wait_for(
                _llm(ctx, messages, "analysis", model_env=f"{_PREFIX}_MODEL",
                     max_tokens=out_tokens, timeout=wait,
                     options=_think_options() if suppress else None), timeout=wait))
            reasoning = _reasoning_tokens(usage)

            if reasoning or _is_length(usage):
                wide = True

                if reasoning:
                    stats["reasoning_tokens"] = max(stats.get("reasoning_tokens", 0), reasoning)

                if reasoning and think_setting is None:
                    suppress = True

                if _is_length(usage):
                    stats["output_truncated"] = stats.get("output_truncated", 0) + 1
                    stats["last_analysis_error"] = _truncation_hint(reasoning, out_tokens)

                # Размышляющая модель: один материал на вызов — весь бюджет его.
                limit = 1 if reasoning else _batch_size(limits, True)

                if len(batch) > limit:
                    pending = _repack(pending, limit)

                log.warning("nj661_airadar: run=%s модель тратит токены на размышления "
                            "(reasoning=%s, finish=%s): бюджет ответа %s, пачки по %s, "
                            "отключение размышлений=%s", run_id, reasoning,
                            usage.get("_llm_finish_reason") or "?", _out_tokens(limits, True),
                            limit, suppress)

            clean = _analysis_results(raw, batch)

            if not clean:
                # Объекты в ответе были, но ни один не подошёл по схеме —
                # на странице прогона должна быть причина, а не тишина.
                # Причину обрыва по лимиту не затираем: она конкретнее.
                stats.setdefault("last_analysis_error",
                                 f"модель вернула ответ, но ни один материал не подошёл "
                                 f"по схеме ({len(raw)} символов)")
                stats["last_analysis_raw"] = (raw or "")[:400]
                stats["last_analysis_model"] = str(usage.get("_llm_model") or "")[:120]
        except Exception as exc:  # noqa: BLE001 — повторяем меньшими пачками
            status = getattr(getattr(exc, "response", None), "status_code", None)

            if suppress and status in (400, 422):
                # Провайдер не знает полей отключения размышлений: убираем их и
                # повторяем ту же пачку — попыткой это не считаем.
                suppress = False
                log.warning("nj661_airadar: run=%s провайдер отклонил отключение "
                            "размышлений (HTTP %s): %s", run_id, status, exc)
                pending.appendleft((batch, retry))
                continue

            if status in (400, 422) and out_tokens > 3000:
                # Потолок вывода провайдера ниже нашего запаса: возвращаемся к
                # базовому бюджету и повторяем ту же пачку.
                budget_ceiling = 3000
                stats["output_capped_by_provider"] = True
                log.warning("nj661_airadar: run=%s провайдер не принял ответ на %s токенов "
                            "(HTTP %s) — дальше базовый бюджет 3000: %s", run_id, out_tokens, status, exc)
                pending.appendleft((batch, retry))
                continue

            log.warning("nj661_airadar: run=%s ids=%s prompt_chars=%s output_tokens=%s: %s", run_id,
                        [it["id"] for it in batch], sum(len(m["content"]) for m in messages),
                        out_tokens, exc)

            if _is_length(usage):
                stats["last_analysis_error"] = _truncation_hint(_reasoning_tokens(usage), out_tokens)
            else:
                stats["last_analysis_error"] = f"{type(exc).__name__}: {exc}"[:500]

            # Неразобранный ответ модели — не отказ провайдера: серию сбоев не
            # растим, иначе пять «мусорных» пачек подряд выбрасывают очередь.
            if not isinstance(exc, ValueError):
                failures += 1
            else:
                # Что именно вернула модель — иначе на странице прогона «пустой
                # JSON» ничем не отличается от «модель молчит».
                stats["last_analysis_raw"] = (raw or "")[:400]
                stats["last_analysis_model"] = str(usage.get("_llm_model") or "")[:120]
        if clean:
            # Эмбеддинги — в воркере, а не N сетевых вызовов внутри HTTP /analysis.
            facts = [f for row in clean for f in row["facts"] if str(f.get("text") or "").strip()]
            for fact in facts:
                fact.pop("embedding", None)  # вектор от LLM не принимаем
            if facts and getattr(ctx, "embed", None) is not None:
                try:
                    vectors = await asyncio.wait_for(ctx.embed.embed([str(f["text"])[:500] for f in facts]), 30)
                    for fact, vec in zip(facts, vectors):
                        fact["embedding"] = vec
                except Exception as exc:
                    log.warning("nj661_airadar: run=%s embeddings: %s", run_id, exc)
                    stats["embedding_errors"] = stats.get("embedding_errors", 0) + 1
            back = await _api_post(ctx, "/analysis", {"run_id": run_id,
                                                     "results": json.dumps(clean, ensure_ascii=False)})
            stats["analyzed"] += len(clean)
            for key in totals:
                totals[key] += int(back.get(key) or 0)
            stats.update(totals)
            failures = 0
        done_ids = {int(row["id"]) for row in clean}
        missing = [it for it in batch if int(it["id"]) not in done_ids]
        if missing:
            stats["analysis_errors"] = stats.get("analysis_errors", 0) + 1
            log.warning("nj661_airadar: run=%s LLM пропустила ids=%s; retry=%s", run_id,
                        [it["id"] for it in missing], retry)
            if not retry:
                pending.extendleft(([it], True) for it in reversed(missing))
            else:
                failed_ids.extend(int(it["id"]) for it in missing)

    stats.update(totals)
    # Один материал мог попасть в failed_ids дважды (пачка + одиночный повтор):
    # «не обработано 6» не должно превращаться в 12.
    failed_ids = list(dict.fromkeys(failed_ids))
    stats["pending"] = len(failed_ids)
    if accepted and not stats["analyzed"]:
        raise RuntimeError("LLM не проанализировала ни одного материала. "
                           + stats.get("last_analysis_error", "Неполный или обрезанный JSON; проверьте лимит ответа"))

    # --- 5. отчёт -------------------------------------------------------------
    await _progress(ctx, run_id, "Отчёт", "собираю сводку прогона", stats)

    inp = await _api_post(ctx, "/report_input", {"run_id": run_id})
    # Если сайт не вернул данные для отчёта, report остаётся пустым: раньше
    # обращение к нему ниже падало NameError при непустом списке failed_ids.
    report: dict = {}

    if inp:
        items = inp.get("items") or []
        facts = inp.get("facts") or []
        ideas = inp.get("ideas") or []
        know = inp.get("knowledge") or {}

        parts = [f"Прогон: {len(items)} полезных материалов, {len(facts)} новых фактов, "
                 f"{len(ideas)} идей. База знаний: {know.get('total_facts', 0)} фактов.",
                 "ТОП МАТЕРИАЛЫ (скор/категория/заголовок/почему):"]

        for it in items[:20]:
            parts.append(f"[{it.get('score')}] {it.get('category')}: {it.get('title')} — {it.get('why')}")

        if ideas:
            parts.append("ИДЕИ ДЛЯ N2J:")

            for g in ideas[:10]:
                parts.append(f"- {g.get('title')} (польза: {g.get('benefit')}; куда: {g.get('fit')})")

        if not items and not facts and not ideas:
            report = {"title": "ИИ-радар: ничего нового",
                      "summary": ("Материалы проверены: полезных находок в этом прогоне нет." if accepted
                                  else "Новые материалы не найдены: кандидаты уже обработаны."), "highlights": []}
        else:
            try:
                report_out = 1200 if not wide else max(1200, min(3000, _out_tokens(limits, True)))
                report_budget = max(200, _context(limits) - report_out - len(SYSTEM_AUTHOR) - 512)
                raw, _usage = _text_usage(await asyncio.wait_for(_llm(ctx, [
                    {"role": "system", "content": SYSTEM_AUTHOR},
                    {"role": "user", "content": "\n".join(parts)[:report_budget]},
                ], "report", model_env=f"{_PREFIX}_MODEL", max_tokens=report_out, timeout=120,
                    options=_think_options() if suppress else None), timeout=120))
                report = webread.json_from_text(raw) or {}
            except Exception as e:  # noqa: BLE001
                log.warning("nj661_airadar: LLM-отчёт не удался: %s", e)
                report = {}

            if (not isinstance(report, dict) or not isinstance(report.get("title"), str)
                    or not report.get("title", "").strip()
                    or not isinstance(report.get("summary"), str) or not report.get("summary", "").strip()):
                report = {"title": f"ИИ-радар: сводка за {time.strftime('%d.%m.%Y')}",
                          "summary": (f"Сохранено полезных материалов: {len(items)}, фактов: {len(facts)}, идей: {len(ideas)}. "
                                      "Редактор LLM не подготовил текст; ниже — сохранённые результаты анализа."),
                          "highlights": [str(it.get("title") or "") for it in items[:6]]}
                stats["report_fallback"] = True
                log.warning("nj661_airadar: run=%s отчёт без текста LLM, используется сводка сохранённых данных", run_id)
        if failed_ids:
            report["summary"] = (f"Неполный прогон: не обработано {len(failed_ids)} материалов; "
                                 "они доступны для повторного запуска. " + report["summary"])

        await _api_post(ctx, "/report", {"run_id": run_id, "report": report, "stats": stats})

    # --- 6. финиш -------------------------------------------------------------
    # Неполный прогон ≠ провал прогона: отчёт собран, материалы проанализированы.
    # Раньше ЛЮБОЙ неразобранный материал (6 из 120) закрывал прогон как fail —
    # на дашборде это плашка «остановлен», хотя конвейер дошёл до конца
    # (живой прогон 21.10.2026). Fail оставляем для случая, когда потеряна
    # заметная доля (больше 10%, минимум 3 материала) или не разобрано ничего.
    total = len(accepted)
    error = ""

    if failed_ids:
        error = (f"Не обработано материалов: {len(failed_ids)} из {total}"
                 + (" (лимит времени или провайдер)" if stats.get("aborted") else "")
                 + "; повторите сбор.")

        if stats.get("last_analysis_error"):
            error += " " + stats["last_analysis_error"]

    partial_ok = stats["analyzed"] > 0 and len(failed_ids) <= max(3, total // 10)
    status = "fail" if error and not partial_ok else "done"

    await _api_post(ctx, "/finish", {"run_id": run_id, "status": status,
                                     "stats": stats, "error": error})
    return {"ok": status == "done", "stats": stats, "error": error}
