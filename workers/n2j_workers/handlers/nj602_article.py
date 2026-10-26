"""nj602.article — «Контент-конвейер»: автостатья по теме.

Архитектура:
1. Поиск свежих источников (бережный ddgs с актуальным годом)
2. Параллельное чтение страниц (webread + Semaphore)
3. RAG: сверка с внутренней памятью (с меткой происхождения)
4. Проход 1 (LLM): генерация ТОЛЬКО тела статьи (чистый Markdown, без JSON-рисков).
   Если длина недостаточна или произошел обрыв — дописывание через контекст диалога.
5. Проход 2 (LLM): формирование заголовка (title), лида (summary), тегов,
   mermaid-диаграммы и image_prompt по УЖЕ НАПИСАННОМУ телу.
6. Экспорт: отправка статьи на сайт через общий HTTP-клиент + сохранение в RAG.
"""

from __future__ import annotations

import asyncio
from datetime import datetime
import json
import logging
import os
import re
import time
from urllib.parse import urlparse

import httpx

from .. import webread
from ..llmwatch import wait_with_ticks
from .nj590_design import _readable_reason
from . import nj602_research as research

log = logging.getLogger("n2j.nj602")

CHANNEL = "nj602.article"

_SKIP_HOSTS = {"facebook.com", "instagram.com", "tiktok.com", "x.com", "twitter.com"}
_BLOCKED_TOPIC_TERMS = {
    "политик", "религи", "религия", "войн", "военн", "арм", "выбор", "президент",
    "правительств", "санкц", "конфликт", "террор", "politic", "religion", "war", "military",
}


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _api_base() -> str:
    return _env("NJ602_CONTENT_API_BASE").rstrip("/")


def _model_spec(payload: dict) -> str:
    return str(payload.get("model") or "").strip()[:120] or _env("NJ602_CONTENT_MODEL")


def _md_cap() -> int:
    try:
        return max(600, min(6000, int(_env("NJ602_CONTENT_MD_CAP", "2000"))))
    except ValueError:
        return 2000


def _max_sites() -> int:
    try:
        return max(2, min(10, int(_env("NJ602_CONTENT_MAX_SITES", "6"))))
    except ValueError:
        return 6


def _llm_timeout() -> float:
    try:
        return max(60.0, min(360.0, float(_env("NJ602_LLM_TIMEOUT", "240"))))
    except ValueError:
        return 240.0


def _body_minchars() -> int:
    try:
        return max(1400, min(58000, int(_env("NJ602_CONTENT_MIN_CHARS", "1800"))))
    except ValueError:
        return 1800


def _site_token() -> tuple[str, str]:
    for name in ("NJ602_CONTENT_SITE_TOKEN", "WORKER_SITE_TOKEN", "WORKER_API_TOKEN"):
        t = os.getenv(name, "").strip()
        if t:
            return t, name
    return "", ""


def _host_of(url: str) -> str:
    try:
        return (urlparse(url).hostname or "").lower()
    except ValueError:
        return ""


# ---------------------------------------------------------------------------
# Сеть и сайт API
# ---------------------------------------------------------------------------

async def _post_site(client: httpx.AsyncClient, path: str, data: dict) -> tuple[bool, str, dict]:
    """POST на сайт через общий client. Две попытки на сеть."""
    base = _api_base()
    token, token_src = _site_token()

    if not base:
        return False, "NJ602_CONTENT_API_BASE не задан в workers/.env", {}
    if not token:
        return False, ("Токен сайта не задан (NJ602_CONTENT_SITE_TOKEN / WORKER_SITE_TOKEN)"), {}

    last = (False, "нет попыток", {})
    headers = {"X-Worker-Token": token}

    for attempt in (1, 2):
        try:
            r = await client.post(base + path, data=data, headers=headers)
            try:
                j = r.json()
            except Exception:  # noqa: BLE001
                j = {}

            if r.status_code == 200:
                if isinstance(j, dict) and j.get("error"):
                    return False, f"сайт: {str(j['error'])[:300]}", j
                return True, "", (j if isinstance(j, dict) else {})

            reason = (f"HTTP {r.status_code}: "
                      + (str(j.get("error"))[:300] if isinstance(j, dict) and j.get("error")
                         else _readable_reason(r.text)))

            if r.status_code == 401:
                reason += f" — токен ({token_src}) не совпал с WORKER_API_TOKEN сайта"

            log.warning("nj602: POST %s -> %s", path, reason)
            last = (False, reason, j)
        except Exception as e:  # noqa: BLE001
            log.warning("nj602: POST %s failed (попытка %d)", path, attempt, exc_info=True)
            last = (False, f"сеть: {e.__class__.__name__}", {})

        await asyncio.sleep(1.5 * attempt)

    return last


async def _get_site(client: httpx.AsyncClient, path: str) -> tuple[bool, str, dict]:
    """GET с сайта (токен воркера). Нужен перегенерации схемы: статья уже
    лежит на сайте, воркеру передают только её код."""
    base = _api_base()
    token, token_src = _site_token()

    if not base:
        return False, "NJ602_CONTENT_API_BASE не задан в workers/.env", {}
    if not token:
        return False, "Токен сайта не задан (NJ602_CONTENT_SITE_TOKEN / WORKER_SITE_TOKEN)", {}

    try:
        r = await client.get(base + path, headers={"X-Worker-Token": token})
    except Exception as e:  # noqa: BLE001
        return False, f"сеть: {e.__class__.__name__}", {}

    try:
        j = r.json()
    except Exception:  # noqa: BLE001
        j = {}

    if r.status_code == 200 and isinstance(j, dict) and not j.get("error"):
        return True, "", j

    reason = (f"HTTP {r.status_code}: "
              + (str(j.get("error"))[:300] if isinstance(j, dict) and j.get("error")
                 else _readable_reason(r.text)))

    if r.status_code == 401:
        reason += f" — токен ({token_src}) не совпал с WORKER_API_TOKEN сайта"

    return False, reason, {}


def _mark(trace: list, phase: str, note: str = "") -> None:
    """Метка журнала прогона: {t, phase, note}. Едет в result задачи и на сайт."""
    trace.append({"t": int(time.time()), "phase": phase, "note": (note or "")[:300]})


async def _flush_trace(client: httpx.AsyncClient, code: str, trace: list) -> None:
    """Отправить накопленный журнал на сайт БЕЗ добавления новой метки.

    Нужен для точек, где прогресс меняется не фазой, а числом/таймингом
    (старт и финиш LLM-вызова): иначе длинный вызов «молчит» до 3 минут и
    в журнале виден только по следущему /progress.
    """
    if not code:
        return
    await _post_site(client, "/progress/" + code, {
        "phase": "trace",
        "trace": json.dumps(trace, ensure_ascii=False),
    })


async def _api_progress(ctx, client: httpx.AsyncClient, code: str, phase: str,
                        trace: list | None = None) -> None:
    # Тот же этап — в журнал очереди: этап виден в мониторе nj120, «Моих
    # задачах» и статус-ряде модуля (единый механизм /trends). Сбой журнала
    # прогресс не отменяет: своя страница важнее.
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    body: dict = {"phase": phase[:300]}
    if trace is not None:
        _mark(trace, "progress", phase)
        body["trace"] = json.dumps(trace, ensure_ascii=False)
    await _post_site(client, "/progress/" + code, body)


async def _brief(client: httpx.AsyncClient) -> dict:
    ok, reason, data = await _post_site(client, "/brief", {})
    if not ok:
        log.info("nj602: брифинг недоступен: %s", reason)
        return {}
    return data if isinstance(data, dict) else {}


async def _rag_context(client: httpx.AsyncClient, topic: str) -> list[dict]:
    ok, reason, data = await _post_site(client, "/knowledge_search", {"q": topic})
    if not ok:
        log.info("nj602: RAG недоступен: %s", reason)
        return []
    rows = data.get("text") if isinstance(data, dict) else []
    return [r for r in rows if isinstance(r, dict) and str(r.get("content") or "").strip()][:8]


# ---------------------------------------------------------------------------
# Поиск и чтение источников
# ---------------------------------------------------------------------------

def _blocked(text: str) -> bool:
    value = str(text or "").lower()
    return any(term in value for term in _BLOCKED_TOPIC_TERMS)


async def _discover_topic() -> str:
    queries = (
        "technology science automation software trend",
        "developer tools AI engineering open source trend",
        "digital products robotics productivity technology trend",
    )
    candidates: list[dict] = []
    for query in queries:
        try:
            candidates.extend(await webread.ddg_text(query, max_results=6))
        except Exception:  # noqa: BLE001
            continue
        await asyncio.sleep(1.0)

    for row in candidates:
        title = str(row.get("title") or "").strip()
        snippet = str(row.get("body") or row.get("snippet") or "").strip()
        if title and not _blocked(title + " " + snippet):
            return title[:240]

    return "Практические инструменты автоматизации создания цифровых артефактов"


async def _find_sources(topic: str) -> list[dict]:
    out: list[dict] = []
    seen: set[str] = set()
    current_year = datetime.now().year

    for q in (f"{topic} новости", topic, f"{topic} обзор {current_year}"):
        try:
            rows = await webread.ddg_text(q, max_results=6)
        except Exception:  # noqa: BLE001
            rows = []

        for r in rows:
            url = str(r.get("url") or r.get("href") or "").strip()
            host = _host_of(url)

            if not url.startswith("http") or url in seen or not host:
                continue
            if _blocked(str(r.get("title") or "") + " " + str(r.get("body") or "")):
                continue
            if any(host == h or host.endswith("." + h) for h in _SKIP_HOSTS):
                continue

            seen.add(url)
            out.append({
                "url": url,
                "title": str(r.get("title") or "")[:200],
                "snippet": str(r.get("body") or "")[:400],
            })

        await asyncio.sleep(1.0)

    return out


async def _read_pages(sources: list[dict], max_sites: int) -> list[dict]:
    """Параллельное чтение целевых страниц с ограничением одновременных задач."""
    semaphore = asyncio.Semaphore(3)
    cap = _md_cap()

    async def _fetch_single(s: dict) -> dict | None:
        async with semaphore:
            try:
                html, _ = await webread.fetch(s["url"])
                md, title = await webread.to_markdown(html, cap=cap)
                if len(md) >= 250:
                    log.info("nj602: прочитан %s (%d симв.)", s["url"], len(md))
                    return {**s, "title": title or s["title"], "md": md}
            except Exception as e:  # noqa: BLE001
                log.warning("nj602: не прочитали %s: %s", s["url"], e.__class__.__name__)
            return None

    tasks = [_fetch_single(s) for s in sources[:max_sites]]
    results = await asyncio.gather(*tasks)
    return [b for b in results if b is not None]


# ---------------------------------------------------------------------------
# Промпты и логика генерации
# ---------------------------------------------------------------------------

# ПРОХОД 1: ТОЛЬКО ТЕЛО СТАТЬИ В MARKDOWN
SYSTEM_WRITE_BODY = (
    "Ты ведущий отраслевой редактор издания N2J.ru. Пишешь для практиков и владельцев бизнеса. "
    "Твоя задача — написать ТОЛЬКО содержательное тело статьи на русском языке на основе присланных источников.\n\n"
    "ПРАВИЛА:\n"
    "1. Факты строго из источников. Никаких выдумок, если фактов нет — так и напиши.\n"
    "2. Редакционные табу: запрещены темы политики, религии, войн и вооружённых конфликтов.\n"
    "3. Стилистика: живой язык, обращение на «вы», разнообразие синтаксиса, конкретные примеры.\n"
    "4. ЗАПРЕЩЕННЫЕ ШТАМПЫ: «в современном мире», «в сегодняшних реалиях», «стоит отметить», "
    "«важно отметить», «в заключение хочется сказать», «подводя итог», «давайте разберёмся», "
    "«не секрет, что», «играет важную роль».\n"
    "5. Форматирование: Markdown. Начинай сразу с первого раздела '## '. Разделы называй предметно "
    "(не «Введение» и не «Заключение»). Внутри используй '### ', списки '- ', короткие абзацы (2-4 предложения).\n"
    "6. ОБЪЁМ: от 1800 до 3200 печатных знаков.\n"
    "7. ФОРМАТ ОТВЕТА: отдавай ТОЛЬКО сырой Markdown тела статьи. Без заголовка '#', без мета-полей, "
    "без вводных фраз от себя и без обрамления в ```markdown."
)

# ПРОХОД 2: МЕТАДАННЫЕ И РАЗМЕТКА ПО ГОТОВОМУ ТЕКСТУ
SYSTEM_ENRICH = (
    "Ты выпускающий шеф-редактор N2J.ru. Тебе передана ПОЛНОСТЬЮ ГОТОВАЯ статья. "
    "Твоя задача — сформировать метаданные публикации строго в формате JSON.\n\n"
    "ТРЕБОВАНИЯ К ПОЛЯМ:\n"
    "1. 'title': сильный, конкретный заголовок статьи без штампов и кликбейта (до 100 знаков).\n"
    "2. 'summary': ёмкий лид/вводный абзац статьи (1-3 предложения), отражающий реальную суть написанного.\n"
    "3. 'tags': массив из 3-5 коротких предметных тегов на русском языке.\n"
    "4. 'mermaid': СХЕМА 'flowchart TD' (4-8 узлов), отражающая РЕАЛЬНЫЙ процесс/последовательность\n"
    "из статьи: что происходит сначала, что потом, где ветвление. НЕ рисуй схему «ради схемы» —\n"
    "если материал не описывает процесса, верни пустую строку.\n"
    "ЯЗЫК СХЕМЫ — СТРОГО РУССКИЙ: все подписи узлов только на русском.\n"
    "СИНТАКСИС (жёстко, иначе схема не отрисуется):\n"
    "  - строка объявления 'flowchart TD' первой;\n"
    "  - идентификаторы узлов латиницей без кириллицы и спецсимволов;\n"
    "  - ЧИТАЕМЫЙ русский текст узла — В ДВОЙНЫХ КАВЫЧКАХ [\"…\"] или (\"…\");\n"
    "  - стрелки: --> (порядок), -->|надпись| (условие); ветвление — фигурные {…};\n"
    "  - КАЖДАЯ СВЯЗЬ — НА ОТДЕЛЬНОЙ СТРОКЕ: сначала 'A[\"…\"] --> B[\"…\"]', затем с новой\n"
    "    строки 'B[\"…\"] --> C[\"…\"]'. ЗАПРЕЩЕНО писать всю схему одной строкой;\n"
    "    ЗАПРЕЩЕНО объединять узлы через '&' ('A & B & C --> D' расписывай тремя связями).\n"
    "  - РОВНО ОДНА схема в ответе: второй 'flowchart TD' ломает рендер (схема не появится вовсе);\n"
    "  - не больше 10 узлов; подпись стрелки — ВСЕГДА в кавычках: -->|\"текст\"|;\n"
    "  - закрывающая кавычка ВСЕГДА до закрывающей скобки фигуры: [\"…\"], (\"…\"), {\"…\"};\n"
    "    комбинации ]\", )\", }\" (кавычка ПОСЛЕ скобки) — синтаксическая ошибка;\n"
    "  - подпись внутри стрелки («A -- текст --> B») запрещена — только -->|\"текст\"|;\n"
    "  - БЕЗ markdown-заборов ```, без ручного форматирования и без текста вне синтаксиса mermaid.\n"
    "ПРИМЕР (строго такой формат):\n"
    "flowchart TD\n"
    "    A[\"Сбор данных\"] --> B{\"Данные полные?\"}\n"
    "    B -->|\"да\"| C[\"Анализ\"]\n"
    "    B -->|\"нет\"| D[\"Дозагрузка\"]\n"
    "5. 'image_prompt': детализированный англоязычный промпт для нейросети (Midjourney/Flux) "
    "для генерации иллюстрации к статье. Это ЕДИНСТВЕННОЕ поле на английском языке. Без текста на картинке.\n\n"
    "Отвечай СТРОГО одним валидным JSON-объектом без пояснений и без markdown-заборов."
)


def _build_write_prompt(blocks: list[dict], topic: str, rag: list[dict] | None = None,
                        audience: dict | None = None, recent: list | None = None) -> str:
    parts = [f"ТЕМА СТАТЬИ: {topic}", ""]

    if audience:
        secs = [s.get("name") for s in (audience.get("sections") or []) if s.get("name")]
        phr = [p.get("phrase") for p in (audience.get("phrases") or []) if p.get("phrase")]
        if secs or phr:
            parts.append("ИНТЕРЕСЫ АУДИТОРИИ (сделай фокус на этих потребностях):")
            if secs:
                parts.append("Читаемые разделы: " + ", ".join(map(str, secs[:6])))
            if phr:
                parts.append("Частые поиски: " + "; ".join(map(str, phr[:6])))
            parts.append("")

    if recent:
        titles = [str(r.get("title") or "") for r in recent if r.get("title")]
        if titles:
            parts.append("УЖЕ ОПУБЛИКОВАНО (не повторяй сюжеты этих статей):")
            parts.extend("- " + t[:150] for t in titles[:8])
            parts.append("")

    if rag:
        parts.append("ВНУТРЕННИЙ АРХИВ N2J (контекст, приоритет всегда у свежих внешних источников):")
        for item in rag:
            parts.append(f"--- {item.get('title', 'заметка')} ---")
            parts.append(str(item.get('content') or '')[:2500])
        parts.append("")

    parts.append("ИСТОЧНИКИ ФАКТОВ:")
    for b in blocks:
        parts.append(f"--- {b['title']} ({_host_of(b['url'])}) ---")
        parts.append(b["md"])

    parts.append("")
    parts.append("Напиши содержательное тело статьи (1800-3200 знаков). Начинай сразу с первого '## ' раздела.")
    return "\n".join(parts)


def _clean_body(raw: str) -> str:
    """Удаляет заборы кода и возможные случайные заголовки первого уровня."""
    text = (raw or "").strip()
    text = re.sub(r"^```[a-zA-Z]*\s*|\s*```$", "", text, flags=re.M).strip()
    # Убираем '# Заголовок', если модель по инерции его добавила
    text = re.sub(r"^#\s+[^\n]+\n+", "", text).strip()
    return text


def _strip_overlap(prev: str, nxt: str) -> str:
    """Мягко склеивает стык, удаляя повторы предложений."""
    prev = prev.strip()
    nxt = nxt.strip()
    if not prev or not nxt:
        return nxt

    # Сверка по токенам (до 30 слов на стыке)
    pw = re.findall(r"\w+|[^\w\s]+", prev.lower())
    nw = re.findall(r"\w+|[^\w\s]+", nxt.lower())

    max_match = 0
    for k in range(1, min(len(pw), len(nw), 35)):
        if pw[-k:] == nw[:k]:
            max_match = k

    if max_match > 0:
        head = re.findall(r"\S+\s*", nxt)
        return "".join(head[max_match:]).strip()

    return nxt


_MM_DECL_RE = re.compile(r"^\s*(?:flowchart|graph)\s+(?:TD|TB|LR|RL|BT)\b", re.I)

# То же объявление, но в любом месте текста (в т.ч. склеенное в строку):
# второй заголовок — синтаксическая ошибка, схема не рисуется вовсе.
_MM_DECL_ANY_RE = re.compile(r"(?<![0-9A-Za-z_])(?:flowchart|graph)[ \t]+(?:TD|TB|LR|RL|BT)\b", re.I)

# Идентификатор узла: латиница, кириллица, цифры, «_». Кириллицу принимаем
# сознательно — модели подписывают узлы по-русски, и такой id mermaid рисует.
_MM_ID_RE = re.compile(r"[0-9A-Za-z_\u0400-\u04FF][0-9A-Za-z_\u0400-\u04FF]*")

# Стрелки mermaid, по которым режем связи. Порядок важен: длинные раньше
# коротких («-.->» раньше «-.-», «--->» раньше «-->»).
_MM_ARROWS = ("-.->", "--->", "-->", "==>", "===", "---", "~~~", "--x", "--o", "-.-", "==")

# Серединные подписи-стрелки: «A -- текст --> B», «A == текст ==> B»,
# «A -. текст .-> B». Mermaid их рисует, но кавычек не прощает — приводим
# к каноничному «A -->|"текст"| B».
_MM_MID_LABEL_RES = (
    (re.compile(r"--\s+([^-|>\n]+?)\s+-->"), "-->"),
    (re.compile(r"==\s+([^=|>\n]+?)\s+==>"), "==>"),
    (re.compile(r"-\.\s+([^-|>\n]+?)\s+\.->"), "-.->"),
)


def _split_outside_quotes(s: str, tokens: tuple[str, ...]) -> tuple[list[str], list[str]]:
    """Делит строку по токенам, НЕ заглядывая внутрь двойных кавычек.

    Подписи узлов у нас всегда в кавычках (["Сбор данных"]), и внутри подписи
    может встретиться «тире» или «точка с запятой» — их нельзя принимать за
    синтаксис схемы.
    """
    segs: list[str] = []
    ops: list[str] = []
    buf = ""
    i = 0
    quote = False

    while i < len(s):
        ch = s[i]

        if ch == '"':
            quote = not quote
            buf += ch
            i += 1
            continue

        if not quote:
            hit = None
            for t in tokens:
                if s.startswith(t, i):
                    hit = t
                    break
            if hit:
                segs.append(buf)
                ops.append(hit)
                buf = ""
                i += len(hit)
                continue

        buf += ch
        i += 1

    segs.append(buf)

    return segs, ops


def _amp_parts(seg: str) -> list[str]:
    """Разбирает «A & B & C» на части ('&' вне кавычек и вне скобок узла)."""
    parts: list[str] = []
    buf = ""
    i = 0
    quote = False
    depth = 0

    while i < len(seg):
        ch = seg[i]

        if ch == '"':
            quote = not quote
        elif not quote:
            if ch in "[({":
                depth += 1
            elif ch in "])}":
                depth = max(0, depth - 1)
            elif ch == "&" and depth == 0:
                parts.append(buf)
                buf = ""
                i += 1
                continue

        buf += ch
        i += 1

    parts.append(buf)

    return [p.strip() for p in parts if p.strip()]


def _mm_shape_end(s: str, i: int) -> int:
    """Индекс за закрывающей скобкой фигуры узла ([\"…"], {\"…\"}, (…)).

    Учитываем кавычки и вложенность: подписи бывают со скобками и тире внутри.
    """
    stack: list[str] = []
    quote = False

    while i < len(s):
        ch = s[i]

        if ch == '"':
            quote = not quote
        elif not quote:
            if ch in "([{":
                stack.append(ch)
            elif ch in ")]}":
                if stack:
                    stack.pop()

                    if not stack:
                        return i + 1
        i += 1

    return -1


def _mm_pipe_end(s: str, i: int) -> int:
    """Индекс закрывающей «|» подписи стрелки (кавычки внутри не считаем)."""
    quote = False

    while i < len(s):
        ch = s[i]

        if ch == '"':
            quote = not quote
        elif ch == "|" and not quote:
            return i
        i += 1

    return -1


def _mm_arrow_at(s: str, i: int) -> str:
    for t in _MM_ARROWS:
        if s.startswith(t, i):
            return t

    return ""


def _mm_edge_count(s: str) -> int:
    """Сколько связей в тексте (стрелки вне кавычек) — мера содержательности."""
    quote = False
    count, i = 0, 0

    while i < len(s):
        ch = s[i]

        if ch == '"':
            quote = not quote
            i += 1
            continue

        if not quote:
            arrow = _mm_arrow_at(s, i)

            if arrow:
                count += 1
                i += len(arrow)
                continue

        i += 1

    return count


def _mm_has_arrow(s: str) -> bool:
    return _mm_edge_count(s) > 0


# Модель часто ставит закрывающую кавычку ПОСЛЕ закрывающей скобки фигуры:
# «D{"текст}"» вместо «D{"текст"}». Mermaid на такой записи падает
# («Syntax error in text»), и читатель видит вместо схемы ошибку.
# Шаблоны требуют открывающую кавычку СРАЗУ после скобки фигуры, поэтому
# каноничные записи («D{"текст"}», «A["текст (уточнение)"]») не задеваются.
_MM_SHAPE_FIXES = (
    (re.compile(r'\{"([^"{}]*)\}"'), r'{"\1"}'),
    (re.compile(r'\{"([^"{}]*)\}'), r'{"\1"}'),
    (re.compile(r'\["([^"\[\]]*)\]"'), r'["\1"]'),
    (re.compile(r'\["([^"\[\]]*)\]'), r'["\1"]'),
    (re.compile(r'\("([^"()]*)\)"'), r'("\1")'),
    (re.compile(r'\("([^"()]*)\)'), r'("\1")'),
)


def _mm_repair_shapes(s: str) -> str:
    """Починить фигуры узлов с кавычкой не на своём месте («{…}» → «{"…"}»)."""
    for rx, repl in _MM_SHAPE_FIXES:
        s = rx.sub(repl, s)

    return s


def _mm_fix_mid_labels(s: str) -> str:
    """«A -- текст --> B» → «A -->|\"текст\"| B» (каноничная подпись стрелки)."""
    quote = False
    safe = ""

    for ch in s:
        if ch == '"':
            quote = not quote
        safe += "\u0000" if (quote and ch in "|") else ch

    for rx, arrow in _MM_MID_LABEL_RES:
        s = rx.sub(lambda m, a=arrow: f'{a}|"{m.group(1).strip()}"|', s)

    return s


def _mm_tokens(s: str) -> list[tuple[str, str]]:
    """Поток токенов схемы: node (узел с фигурой), arrow, label, amp.

    Именно токенизация (а не резка по стрелкам) даёт верный разбор схемы,
    написанной ОДНОЙ строкой: граница связи — там, где после узла идёт не
    стрелка, а следующий узел.
    """
    toks: list[tuple[str, str]] = []
    i, n = 0, len(s)

    while i < n:
        ch = s[i]

        if ch.isspace() or ch == ";":
            i += 1
            continue

        if ch == "&":
            toks.append(("amp", "&"))
            i += 1
            continue

        arrow = _mm_arrow_at(s, i)

        if arrow:
            toks.append(("arrow", arrow))
            i += len(arrow)

            j = i

            while j < n and s[j].isspace():
                j += 1

            if j < n and s[j] == "|":
                end = _mm_pipe_end(s, j + 1)

                if end != -1:
                    toks.append(("label", s[j + 1:end].strip()))
                    i = end + 1
                    continue
            continue

        m = _MM_ID_RE.match(s, i)

        if m:
            j = m.end()

            if j < n and s[j] in "([{":
                end = _mm_shape_end(s, j)

                if end != -1:
                    j = end

            toks.append(("node", s[i:j]))
            i = j
            continue

        # мусор от модели (запятые, лишние скобки) — выбрасываем
        i += 1

    return toks


def _mm_label(raw: str) -> str:
    """Подпись стрелки всегда в кавычках: без них ломаются «(», «,», «:»."""
    text = (raw or "").strip()

    if len(text) >= 2 and text[0] == '"' and text[-1] == '"':
        text = text[1:-1]

    text = text.replace('"', "'").strip()

    return f'|"{text}"|' if text else ""


def _mm_build(toks: list[tuple[str, str]]) -> list[str]:
    """Собрать токены в строки-связи: одна связь — одна строка."""
    out: list[str] = []
    i, n = 0, len(toks)

    while i < n:
        if toks[i][0] != "node":
            i += 1
            continue

        group = [toks[i][1]]
        i += 1

        while i < n and toks[i][0] == "amp":
            i += 1

            if i < n and toks[i][0] == "node":
                group.append(toks[i][1])
                i += 1
            else:
                break

        if i >= n or toks[i][0] != "arrow":
            # объявления узлов без связей — каждое своей строкой
            out.extend(group)
            continue

        lefts = group

        while i < n and toks[i][0] == "arrow":
            arrow = toks[i][1]
            i += 1

            label = ""

            if i < n and toks[i][0] == "label":
                label = _mm_label(toks[i][1])
                i += 1

            rights: list[str] = []

            if i < n and toks[i][0] == "node":
                rights.append(toks[i][1])
                i += 1

                while i < n and toks[i][0] == "amp":
                    i += 1

                    if i < n and toks[i][0] == "node":
                        rights.append(toks[i][1])
                        i += 1
                    else:
                        break

            if not rights:
                break

            for one in lefts:
                for right in rights:
                    out.append(f"{one} {arrow}{label} {right}")

            lefts = rights  # продолжение цепочки: «A --> B --> C»

    return out


def _mm_decl_marks(s: str) -> list[int]:
    """Позиции объявлений диаграмм — ВНЕ кавычек.

    Модель умудряется приклеить вторую схему к последней строке первой
    («… --> E[\"Итог\"] flowchart TD …»), поэтому ищем объявление везде, а не
    только в начале строки; кавычки защищают подписи узлов.
    """
    marks: list[int] = []
    quote = False
    i, n = 0, len(s)

    while i < n:
        ch = s[i]

        if ch == '"':
            quote = not quote
            i += 1
            continue

        if not quote:
            m = _MM_DECL_ANY_RE.match(s, i)

            if m:
                marks.append(i)
                i = m.end()
                continue

        i += 1

    return marks


def _mm_pick_diagram(s: str) -> str:
    """Оставить РОВНО одну диаграмму.

    Модель нередко склеивает две схемы в один ответ («…F --> G» + новый
    «flowchart TD …»). Второй заголовок для mermaid — синтаксическая ошибка,
    поэтому режем текст по объявлениям и берём лучший кусок: со связями и
    самый крупный (при равенстве — первый).
    """
    marks = _mm_decl_marks(s)

    if not marks:
        return ""

    bounds = marks[1:] + [len(s)]
    chunks = [s[a:b].strip() for a, b in zip(marks, bounds)]
    chunks = [c for c in chunks if c]

    if not chunks:
        return ""

    with_edges = [c for c in chunks if _mm_edge_count(c) > 0]
    pool = with_edges or chunks

    # Больше связей — содержательнее; при равенстве берём ПЕРВЫЙ кусок
    # (max возвращает первый максимальный): первая схема — авторская, вторая
    # обычно дописана моделью «в довесок».
    return max(pool, key=_mm_edge_count)


def _norm_mermaid_line(line: str, indent: str | None = None) -> list[str]:
    """Одна строка схемы → строки-связи (по одной связи на строку).

    indent можно задать снаружи: части, полученные делением строки по «;»,
    должны остаться с отступом своей исходной строки, а не с нулевым.
    """
    indent = re.match(r"^\s*", line).group(0) if indent is None else indent
    core = line.strip()

    # строки без связей (объявления узлов, «%%»-комментарии, style) —
    # оставляем как есть, но с их законным отступом
    if not core or core.startswith("%%") or "subgraph" in core:
        return [indent + core]

    if not _mm_has_arrow(core):
        return [indent + core]

    built = _mm_build(_mm_tokens(_mm_fix_mid_labels(_mm_repair_shapes(core))))

    if not built:
        return [indent + core]

    return [indent + ln for ln in built]


def _norm_mermaid(mmd: str) -> str:
    """Нормализует схему к построчному виду, который mermaid точно рисует.

    Модель нередко отдаёт всю схему ОДНОЙ строкой
    («flowchart TD A[\"…\"] --> B{\"…\"}») или склеивает узлы через «&».
    Mermaid такое не парсит — схема молча пропадает («много схем не
    отрисовывается»). Здесь: объявление — отдельной первой строкой, каждая
    связь — своей строкой, «&»-склейки раскрыты, «;» — как разделитель.
    Границы связей в однострочнике ищем токенами (узел → стрелка → узел), а не
    резкой по стрелкам: иначе соседние связи «слипаются» в мусор.
    Схемы с subgraph не трогаем (там своя структура).
    """
    s = (mmd or "").strip()

    if not s:
        return ""
    if "subgraph" in s:
        return s

    decl = ""
    m = _MM_DECL_RE.match(s)

    if m:
        decl = m.group(0).strip()
        s = s[m.end():]

        # «flowchart TD A[...] --> B[...]»: остаток той же строки начинается
        # без отступа (первый перевод строки после объявления сохраняем)
        s = s.lstrip(" \t")

        if s.startswith("\n"):
            s = s[1:]

    lines: list[str] = []

    for raw in s.splitlines():
        if not raw.strip():
            continue

        indent = re.match(r"^\s*", raw).group(0)

        # «;» — разделитель связей внутри строки: каждая часть — своя строка,
        # отступ берём у исходной строки (иначе вторая связь «съезжает»).
        for piece in _split_outside_quotes(raw.strip(), (";",))[0]:
            lines.extend(_norm_mermaid_line(piece, indent))

    lines = [ln.rstrip() for ln in lines if ln.strip()]

    if not lines:
        return decl

    return (decl + "\n" if decl else "") + "\n".join(lines)


def _validate_mermaid(mmd: str) -> str:
    """Санитайзер Mermaid: только flowchart/graph, без заборов и мусора.

    Модель иногда возвращает схему внутри ```mermaid … ``` или с вводной
    строкой — всё это ломает рендер (mermaid.js падает, вид «упал»). Здесь
    снимаем обёртку, отсекаем не-диаграмму и держим разумный размер.
    """
    s = (mmd or "").strip()

    if not s:
        return ""

    # 1) снять markdown-забор (```mermaid / ```)
    s = re.sub(r"^```(?:mermaid)?\s*|\s*```$", "", s, flags=re.M | re.I).strip()

    if not s:
        return ""

    # 1.5) фигуры с кавычкой не на своём месте («D{"рост}"») — чиним ДО выбора
    #      диаграммы: иначе токенизатор видит связи неверно и склейку двух схем
    #      разбирает неправильно
    s = _mm_repair_shapes(s)

    # 2) берём РОВНО одну диаграмму: срезаем вводную фразу модели и отбрасываем
    #    вторую схему, если модель склеила две (это и ломало рендер)
    s = _mm_pick_diagram(s).strip()

    if not s or len(s) < 8:
        return ""

    # 3) только flowchart/graph, не sequence/class/state и не «почти пусто»
    if not re.match(r"^(flowchart|graph)\b", s, re.I):
        return ""

    # 4) построчная нормализация: однострочники и «&»-склейки не рисуются
    s = _norm_mermaid(s)

    if not s or not re.match(r"^(flowchart|graph)\b", s, re.I):
        return ""

    # 5) верхний предел: mermaid-строки дольше ~8 КБ не несут пользы
    return s[:8000]



_MM_FALLBACK = (
    "flowchart TD\n"
    '    A["Сбор данных"] --> B["Анализ"]\n'
    '    B --> C["Выводы"]\n'
    '    C --> D["Применение на практике"]'
)


def _fallback_mermaid(body_md: str) -> str:
    """Схема «сбор → анализ → выводы → применение» из заголовков статьи.

    Детерминированный фоллбек: проход 2 нередко возвращает пустую mermaid
    («материал не описывает процесса»), и статья публикуется без схемы.
    Строим каркас flowchart TD из реальных разделов (##-заголовки) тела —
    это подходит ЛЮБОЙ содержательной статье и не ломает рендер.
    """
    heads: list[str] = []
    for line in (body_md or "").splitlines():
        m = re.match(r"^\s*#{2,4}\s+(.+?)\s*$", line)
        if not m:
            continue
        t = re.sub(r"[\[\]{\}\"\\,.;:!?()«»\u2014\u2013]", " ", m.group(1))
        t = re.sub(r"\s+", " ", t).strip()
        if t and t not in heads and not re.fullmatch(r"(введение|заключение|выводы?|итоги?)", t, re.I):
            heads.append(t[:60])
        if len(heads) >= 5:
            break

    if heads:
        nodes = [f'    N{i}["{h}"]' for i, h in enumerate(heads)]
        edges = [f"    N{i} --> N{i + 1}" for i in range(len(heads) - 1)]
        return "flowchart TD\n" + "\n".join(nodes) + "\n" + "\n".join(edges)

    return _MM_FALLBACK


def _fallback_extras(body_md: str, topic: str) -> dict:
    """Резервные метаданные при сбое прохода 2."""
    words = re.findall(r"[а-яёa-z0-9]{4,}", (topic + " " + body_md[:400]).lower())
    # Выделяем первое предложение как аварийный лид
    first_sentence = body_md.strip().split("\n")[0]
    first_sentence = re.sub(r"^[#\-\*\s]+", "", first_sentence)[:200]

    return {
        "title": topic[:120],
        "summary": first_sentence or topic,
        "image_prompt": "",
        "mermaid": _fallback_mermaid(body_md),
        "tags": list(dict.fromkeys(words))[:4],
    }


def _parse_extras(raw: str, body_md: str, topic: str) -> dict:
    """Разбор JSON из прохода 2."""
    try:
        j = webread.json_from_text(raw or "")
        if not isinstance(j, dict):
            raise ValueError("Ответ разметки не является JSON")

        title = str(j.get("title") or "").strip()[:200]
        summary = str(j.get("summary") or "").strip()[:500]
        tags = [str(t)[:30].strip() for t in (j.get("tags") or []) if str(t).strip()][:5]
        mermaid = _validate_mermaid(str(j.get("mermaid") or ""))
        image_prompt = str(j.get("image_prompt") or "").strip()[:2000]

        fb = _fallback_extras(body_md, topic)
        # пустая схема встречалась у пользователя («статья без mermaid»):
        # вместо «нет схемы» отдаём детерминированный каркас из разделов
        if not mermaid:
            mermaid = fb["mermaid"]
        return {
            "title": title or fb["title"],
            "summary": summary or fb["summary"],
            "tags": tags or fb["tags"],
            "mermaid": mermaid,
            "image_prompt": image_prompt,
        }
    except Exception:  # noqa: BLE001
        log.warning("nj602: сбой разбора метаданных — применены безопасные значения", exc_info=True)
        return _fallback_extras(body_md, topic)


# ---------------------------------------------------------------------------
# Главная точка входа воркера
# ---------------------------------------------------------------------------

async def _fix_mermaids(ctx, client: httpx.AsyncClient, payload: dict,
                        trace: list[dict]) -> dict:
    """Починить СХЕМЫ уже опубликованных статей — без ИИ, только нормализация.

    Зачем: у части статей в базе лежит схема, которую mermaid НЕ рисует
    (одной строкой, с «&»-склейками или с вторым 'flowchart TD' — ровно те
    примеры, что прислал владелец 26.09). Перегенерация ИИ не нужна: текст
    схемы чинится детерминированно. Кнопка «Починить схемы» в блоке
    «Модерация» передаёт пачку кодов, воркер правит их подряд и отчитывается
    прогрессом (статус-ряд как у сбора статьи).
    """
    codes = payload.get("codes") or []
    codes = [str(c).strip() for c in codes if str(c).strip()]

    one = str(payload.get("code") or "").strip()

    if one and one not in codes:
        codes.insert(0, one)

    if not codes:
        raise RuntimeError("Не передано ни одного кода статьи для починки схем")

    fixed, skipped, failed = 0, 0, 0
    total = len(codes)

    for n, code in enumerate(codes, 1):
        await ctx.heartbeat()
        await ctx.progress("Чиню схемы", f"{n}/{total}: {code}")

        ok, why, data = await _get_site(client, "/article/" + code)

        if not ok:
            failed += 1
            _mark(trace, "fix", f"{code}: не получил статью ({why})")
            continue

        old_mmd = str(data.get("mermaid") or "")
        new_mmd = _validate_mermaid(old_mmd)

        if not new_mmd or new_mmd == old_mmd.strip():
            skipped += 1
            continue

        ok, why, _ = await _post_site(client, "/mermaid/" + code, {"mermaid": new_mmd})

        if ok:
            fixed += 1
            _mark(trace, "fix", f"{code}: схема починена ({len(new_mmd)} знаков)")
        else:
            failed += 1
            _mark(trace, "fix", f"{code}: сайт не принял схему ({why})")

        await ctx.progress("Чиню схемы", f"{n}/{total}: починено {fixed}, без изменений {skipped}")

    try:
        await ctx.progress("Готово", f"починено {fixed} из {total}, без изменений {skipped}")
    except Exception:  # noqa: BLE001 — рапорт не важнее результата
        pass

    return {"ok": True, "mode": "mermaid_fix", "total": total,
            "fixed": fixed, "skipped": skipped, "failed": failed, "trace": trace}


async def _regen_mermaid(ctx, client: httpx.AsyncClient, payload: dict,
                         code: str, trace: list[dict]) -> dict:
    """Перегенерация ТОЛЬКО mermaid-схемы по уже готовой статье.

    Вызывается кнопкой «Перегенерировать схему» в блоке «Модерация»: тело
    статьи берём с сайта (воркеру передают лишь код), просим у ИИ новую
    схему тем же промптом, что и при сборке (SYSTEM_ENRICH), и возвращаем
    её на сайт одним полем — текст статьи при этом не трогается.
    """
    if not code:
        raise RuntimeError("Не передан код статьи для перегенерации схемы")

    await _api_progress(ctx, client, code, "Перегенерация схемы…", trace)
    ok, why, data = await _get_site(client, "/article/" + code)

    if not ok:
        raise RuntimeError(f"Не удалось получить статью с сайта: {why}")

    body_md = str(data.get("body_md") or "")
    topic = str(data.get("topic") or payload.get("topic") or "").strip()[:300]

    if len(body_md) < 200:
        raise RuntimeError("У статьи нет тела — схему строить не по чему")

    if ctx.llm is None:
        raise RuntimeError("LLM-клиент не инициализирован в контексте воркера")

    _mark(trace, "article", f"статья получена с сайта: {len(body_md)} знаков")
    await _api_progress(ctx, client, code, "ИИ рисует схему заново…", trace)

    messages = [
        {"role": "system", "content": SYSTEM_ENRICH},
        {"role": "user", "content": f"ТЕМА: {topic}\n\nГОТОВАЯ СТАТЬЯ:\n{body_md[:24000]}"},
    ]

    async def _tick(waited: int, limit: float | None) -> None:
        note = f"ИИ рисует схему… {waited} с"
        _mark(trace, "llm_wait", note)
        await _flush_trace(client, code, trace)

        try:
            await ctx.progress(note)
        except Exception:  # noqa: BLE001
            pass

    try:
        raw, _ = await wait_with_ticks(
            lambda: ctx.llm.chat_with_usage(
                messages,
                model=_model_spec(payload) or None,
                max_tokens=2000,
                request_timeout=180.0,
            ),
            every=20.0,
            limit=200.0,
            on_tick=_tick,
            what="Модель (перегенерация схемы)",
        )
    except asyncio.TimeoutError as e:
        raise RuntimeError(f"Схема не перегенерирована: {e}") from e

    # Ответ прохода 2 — JSON целиком: схему достаём из поля 'mermaid'
    # (тот же разбор, что и при сборке: снятие заборов, нормализация,
    # каркас по разделам, если модель схему не дала).
    try:
        mmd = str(_parse_extras(str(raw or ""), body_md, topic).get("mermaid") or "")
    except Exception:  # noqa: BLE001
        log.warning("nj602: перегенерация схемы — ответ модели не разобран", exc_info=True)
        mmd = ""

    if not mmd:
        mmd = _fallback_mermaid(body_md)

    if not mmd:
        raise RuntimeError("ИИ не дал схему, и собрать её из разделов статьи не получилось")

    _mark(trace, "mermaid", f"схема готова: {len(mmd)} знаков, {mmd.count(chr(10)) + 1} строк")

    ok, why, _ = await _post_site(client, "/mermaid/" + code, {
        "mermaid": mmd,
        "progress": json.dumps(trace, ensure_ascii=False),
    })

    if not ok:
        raise RuntimeError(f"Сайт не принял схему: {why}")

    await _api_progress(ctx, client, code, "Схема обновлена", trace)

    return {"ok": True, "code": code, "mermaid_chars": len(mmd), "trace": trace}


async def _legacy_pipeline(ctx, http_client, code: str, topic: str, brief: dict,
                           trace: list, _chat) -> tuple[str, list[dict]]:
    """Старый одно-проходный сбор (NJ602_RESEARCH=0): источники → один текст."""
    # 1. Поиск источников
    await _api_progress(ctx, http_client, code, "Поиск источников в сети…", trace)
    sources = await _find_sources(topic)
    _mark(trace, "sources", f"найдено источников: {len(sources)}")
    if not sources:
        raise RuntimeError(f"Не удалось найти внешние источники по теме «{topic}»")

    # 2. Параллельное чтение источников
    await _api_progress(ctx, http_client, code, f"Чтение источников (топ-{min(_max_sites(), len(sources))})…", trace)
    blocks = await _read_pages(sources, _max_sites())
    _mark(trace, "read", f"прочитано страниц: {len(blocks)}")
    if not blocks:
        raise RuntimeError("Не удалось извлечь контент ни из одного источника")

    # 3. RAG контекст
    await _api_progress(ctx, http_client, code, "Сверка с базой знаний…", trace)
    rag = await _rag_context(http_client, topic)

    # 4. ПРОХОД 1: Генерация тела статьи (Markdown)
    await _api_progress(ctx, http_client, code, "ИИ пишет текст статьи…", trace)
    initial_user_prompt = _build_write_prompt(
        blocks, topic, rag,
        audience=brief.get("audience") or {},
        recent=brief.get("recent") or [],
    )
    write_messages = [
        {"role": "system", "content": SYSTEM_WRITE_BODY},
        {"role": "user", "content": initial_user_prompt},
    ]

    raw_body, w_usage = await _chat(write_messages, "write_body", max_tokens=4000, timeout=_llm_timeout())
    body_md = _clean_body(raw_body)

    # 4а. Бесшовное дописывание при нехватке объёма или обрыве токенов
    is_length_limit = str(w_usage.get("_llm_finish_reason") or "") == "length"
    min_chars = _body_minchars()

    if is_length_limit or len(body_md) < min_chars:
        await _api_progress(ctx, http_client, code, "Дописывание статьи до полного объёма…", trace)
        log.info("nj602: требуется продолжение (текущая длина: %d, min: %d)", len(body_md), min_chars)

        cont_messages = [
            {"role": "system", "content": SYSTEM_WRITE_BODY},
            {"role": "user", "content": initial_user_prompt},
            {"role": "assistant", "content": body_md},
            {
                "role": "user",
                "content": (
                    "Продолжи текст статьи ровно с того места, где оборвалась мысль. "
                    "Не повторяй уже написанное, соблюдай структуру и стиль. "
                    "Отвечай сразу продолжением в Markdown."
                ),
            },
        ]
        try:
            raw_tail, _ = await _chat(cont_messages, "write_body.cont", max_tokens=2500, timeout=_llm_timeout())
            cleaned_tail = _clean_body(raw_tail)
            body_md = body_md.rstrip() + "\n\n" + _strip_overlap(body_md, cleaned_tail)
        except Exception:  # noqa: BLE001
            log.warning("nj602: не удалось дописать хвост, сохраняем исходный текст", exc_info=True)


    return body_md, blocks


async def _research_pipeline(ctx, http_client, code: str, topic: str, brief: dict,
                             trace: list, _chat) -> tuple[str, list[dict], list[dict], dict]:
    """Факты → ракурс → текст → рецензент. Возвращает (body_md, blocks, facts, quality)."""
    # 1. План поиска
    await _api_progress(ctx, http_client, code, "План исследования: запросы и вопросы…", trace)
    plan_ = await research.plan(_chat, topic)
    _mark(trace, "plan", f"запросов: {len(plan_['queries'])}, вопросов: {len(plan_['questions'])}")
    for d in research.LAST_DIAG:
        _mark(trace, "llm_diag", d)

    # 2. Поиск и ранжирование
    await _api_progress(ctx, http_client, code, f"Поиск источников ({len(plan_['queries'])} запросов)…", trace)
    found = await research.search(plan_["queries"], _blocked, _SKIP_HOSTS)
    _mark(trace, "sources", f"кандидатов: {len(found)}; лучшие: "
          + ", ".join(research._host(r["url"]) or r["url"][:40] for r in found[:5]))
    if not found:
        raise RuntimeError(f"Не удалось найти внешние источники по теме «{topic}»")

    # 3. Чтение
    limit = research.max_sites()
    await _api_progress(ctx, http_client, code, f"Чтение источников (до {limit})…", trace)
    blocks = await research.read(found, limit)
    _mark(trace, "read", f"прочитано страниц: {len(blocks)}, знаков: {sum(len(b['md']) for b in blocks)}")
    if not blocks:
        raise RuntimeError("Не удалось извлечь контент ни из одного источника")

    # 4. Факты (+ добор, если цифр мало)
    await _api_progress(ctx, http_client, code, "Выжимка фактов из источников…", trace)
    research.LAST_DIAG.clear()
    facts = await research.extract(_chat, topic, blocks, plan_["questions"])
    _mark(trace, "facts", f"фактов: {len(facts)}, с цифрами: {research.numeric_facts(facts)}")
    for d in research.LAST_DIAG:
        _mark(trace, "llm_diag", d)

    if research.numeric_facts(facts) < research.min_facts() and plan_["questions"]:
        await _api_progress(ctx, http_client, code, "Мало цифр — добор источников по вопросам…", trace)
        extra_q = [q[:100] for q in plan_["questions"][:3]]
        used = {b["url"] for b in blocks}
        more = [r for r in await research.search(extra_q, _blocked, _SKIP_HOSTS, per_query=6)
                if r["url"] not in used]
        more_blocks = await research.read(more, max(2, limit // 2))
        if more_blocks:
            facts += await research.extract(_chat, topic, more_blocks, plan_["questions"],
                                            start_idx=len(blocks) + 1)
            blocks = blocks + more_blocks
        _mark(trace, "facts", f"после добора: фактов {len(facts)}, страниц {len(blocks)}")

    pipeline = "research"
    if len(facts) < 3:
        # ИИ не отдал JSON (думающая модель съела лимит токенов) — берём
        # предложения с цифрами прямо из страниц: статья выйдет с фактами
        # и ссылками, а не упадёт и не станет «водой»
        rx = research.regex_facts(blocks)
        _mark(trace, "facts", f"ИИ дал {len(facts)} фактов — добавлены {len(rx)} предложений с цифрами из страниц")
        facts += rx
        pipeline = "research-regex"
    if len(facts) < 3:
        raise RuntimeError(f"В источниках не нашлось проверяемых фактов по теме «{topic}» (найдено {len(facts)})")

    # 5. Ракурс и план
    await _api_progress(ctx, http_client, code, "Выбор ракурса и плана статьи…", trace)
    rag = await _rag_context(http_client, topic)
    ang = await research.angle(_chat, topic, facts, plan_["angle_hints"],
                               brief.get("audience") or {}, brief.get("recent") or [])
    _mark(trace, "angle", (ang.get("thesis") or "план без тезиса")[:200])

    # 6. Текст
    await _api_progress(ctx, http_client, code, "ИИ пишет статью по фактлисту…", trace)
    lo = research.min_chars()
    hi = int(lo * 1.8)
    system = research.SYSTEM_WRITE.replace("{min}", str(lo)).replace("{max}", str(hi))
    user_prompt = research.build_write_prompt(topic, facts, blocks, ang, rag)
    msgs = [{"role": "system", "content": system}, {"role": "user", "content": user_prompt}]

    raw_body, w_usage = await _chat(msgs, "write_body", max_tokens=5000, timeout=_llm_timeout())
    body_md = _clean_body(raw_body)

    if str(w_usage.get("_llm_finish_reason") or "") == "length" or len(body_md) < lo * 0.7:
        await _api_progress(ctx, http_client, code, "Дописывание статьи до полного объёма…", trace)
        cont = msgs + [
            {"role": "assistant", "content": body_md},
            {"role": "user", "content": "Продолжи текст ровно с места обрыва: без повторов, в том же стиле, "
                                        "со ссылками [n]. Обязательные разделы в конце не забудь. Только Markdown."},
        ]
        try:
            raw_tail, _ = await _chat(cont, "write_body.cont", max_tokens=2500, timeout=_llm_timeout())
            body_md = body_md.rstrip() + "\n\n" + _strip_overlap(body_md, _clean_body(raw_tail))
        except Exception:  # noqa: BLE001
            log.warning("nj602: не удалось дописать хвост", exc_info=True)

    # 7. Метрика + рецензент → при необходимости одно переписывание
    m = research.measure(body_md, len(blocks))
    det = research.deterministic_score(m)
    await _api_progress(ctx, http_client, code, f"Рецензия: ссылок {m['citations']}, цифр {m['numbers']}…", trace)
    crit = await research.critic(_chat, body_md, len(facts))
    _mark(trace, "critic", f"метрика {det}/10, рецензент {crit['score']}/10, вода {crit['water']}, "
          f"штампов {len(m['banned'])}")

    need_rewrite = (crit["score"] >= 0 and crit["score"] < research.critic_min()) or det < 4.0 \
        or not m["has_required"]
    revised = False
    if need_rewrite:
        await _api_progress(ctx, http_client, code, "Переписываю по замечаниям рецензента…", trace)
        rew = msgs + [
            {"role": "assistant", "content": body_md},
            {"role": "user", "content": research.revise_instructions(m, crit)},
        ]
        try:
            raw2, _ = await _chat(rew, "rewrite", max_tokens=5000, timeout=_llm_timeout())
            body2 = _clean_body(raw2)
            m2 = research.measure(body2, len(blocks))
            det2 = research.deterministic_score(m2)
            if len(body2) >= 1500 and det2 >= det:
                body_md, m, det, revised = body2, m2, det2, True
            _mark(trace, "rewrite", f"переписано: метрика {det2}/10 (было {det}), принято: {'да' if revised else 'нет'}")
        except Exception:  # noqa: BLE001
            log.warning("nj602: переписывание не удалось, оставляем первый вариант", exc_info=True)

    quality = {
        "score": det,
        "critic": crit["score"],
        "novelty": crit["novelty"],
        "water": crit["water"],
        "facts": len(facts),
        "facts_numeric": research.numeric_facts(facts),
        "citations": m["citations"],
        "sources_cited": m["sources_cited"],
        "numbers": m["numbers"],
        "banned": m["banned"],
        "revised": revised,
        "thesis": (ang.get("thesis") or "")[:300],
        "title_hint": (ang.get("title_hint") or "")[:120],
        "pipeline": pipeline,
    }
    return body_md, blocks, facts, quality


async def handle(ctx, payload: dict) -> dict:
    article_id = int(payload.get("article_id") or 0)
    code = str(payload.get("code") or "")
    topic = str(payload.get("topic") or "").strip()[:300]
    # Журнал прогона: метки фаз складываются сюда (см. _mark), в конце попадают
    # в result очереди (nj_120_jobs.result.trace) и на сайт (/progress) — видно в
    # админке /nj602/admin во время «собирается».
    trace: list[dict] = []

    # Единый клиент с поддержкой пула соединений на весь жизненный цикл генерации
    async with httpx.AsyncClient(timeout=60.0) as http_client:
        _mark(trace, "start", "воркер принял задачу; тема: " + topic[:140])
        brief = await _brief(http_client)

        # Работа со СХЕМАМИ идёт раньше проверки «конвейер выключен»: это не
        # сбор новой статьи, а правка уже сохранённой — выключенный конвейер
        # не должен мешать починить схемы (владелец: «много схем не
        # отрисовываются»).
        mode = str(payload.get("mode") or "").strip()

        # Перегенерация схемы: отдельный режим той же задачи (кнопка в блоке
        # «Модерация» на странице статьи) — тело статьи уже есть на сайте.
        if mode == "mermaid":
            return await _regen_mermaid(ctx, http_client, payload, code, trace)

        # Починка схем у пачки статей (нормализация без ИИ): кнопка в блоке
        # «Модерация», чинит уже сохранённые «нерисующиеся» схемы.
        if mode == "mermaid_fix":
            return await _fix_mermaids(ctx, http_client, payload, trace)

        if brief and brief.get("enabled") is False:
            raise RuntimeError("Конвейер отключен администратором в /nj602/admin")

        if not topic:
            raise RuntimeError("Пустая тема статьи в задании")

        if topic == "__AUTO_TRENDS__":
            await _api_progress(ctx, http_client, code, "Мониторю свежие тренды…", trace)
            suggested = str(brief.get("suggested") or "").strip()
            if suggested and suggested != "__AUTO_TRENDS__":
                topic = suggested
            elif research.enabled():
                topic = await research.discover_topic(_blocked, brief.get("recent") or [])
            else:
                topic = await _discover_topic()
            log.info("nj602: выбрана авто-тема: %s", topic)

        if _blocked(topic):
            raise RuntimeError("Тема отклонена политикой контентной безопасности")

        sink_usage: list[dict] = []

        async def _chat(messages: list[dict], phase: str, max_tokens: int,
                        timeout: float = 480.0) -> tuple[str, dict]:
            if ctx.llm is None:
                raise RuntimeError("LLM-клиент не инициализирован в контексте воркера")

            _mark(trace, "llm", f"{phase}: запрос к ИИ (лимит {max_tokens} токенов)")
            await _flush_trace(http_client, code, trace)

            t0 = time.monotonic()
            log.info("nj602[%s]: обращение к LLM (лимит токенов: %d)...", phase, max_tokens)

            # Тикер: пока модель думает, статус-ряд и журнал обязаны показывать
            # «сколько уже ждём» — иначе вызов на локальной модели выглядит
            # зависанием (единый механизм llmwatch, как в nj593/nj606).
            async def _tick(waited: int, limit: float | None) -> None:
                note = f"{phase}: ИИ пишет… {waited} с" + (f" (лимит {int(limit)} с)" if limit else "")
                _mark(trace, "llm_wait", note)
                await _flush_trace(http_client, code, trace)

                try:
                    await ctx.progress(note[:300])
                except Exception:  # noqa: BLE001 — рапорт не важнее ответа модели
                    pass

            text, usage = await wait_with_ticks(
                lambda: ctx.llm.chat_with_usage(
                    messages,
                    model=_model_spec(payload) or None,
                    max_tokens=max_tokens,
                    request_timeout=timeout,
                    # размышления выключены на всех фазах: думающая 9B-модель
                    # тратила весь лимит на reasoning и отдавала пустой content
                    think=False,
                ),
                every=20.0,
                limit=float(timeout) + 30.0,
                on_tick=_tick,
                what=f"Модель на этапе «{phase}»",
            )
            dt = int((time.monotonic() - t0) * 1000)
            if usage.get("_llm_reasoning_only"):
                # пришли только размышления — для конвейера это пустой ответ
                _mark(trace, "llm_diag", f"{phase}: модель вернула только размышления без ответа "
                      "(thinking не выключился — проверьте модель/LM Studio)")
                text = ""
            model_real = str(usage.get("_llm_model") or "") or (_model_spec(payload) or "default")
            finish = str(usage.get("_llm_finish_reason") or "?")

            log.info("nj602[%s]: ответ получен за %dms (%d токенов, finish=%s)",
                     phase, dt, int(usage.get("completion_tokens") or 0), finish)

            _mark(trace, "llm_ok",
                  f"{phase}: ответ за {dt / 1000:.1f}с, {int(usage.get('completion_tokens') or 0)} токенов, finish={finish}")
            await _flush_trace(http_client, code, trace)

            sink_usage.append({
                "phase": phase,
                "model": model_real,
                "prompt_tokens": int(usage.get("prompt_tokens") or 0),
                "completion_tokens": int(usage.get("completion_tokens") or 0),
            })
            return text, usage

        try:
            facts: list[dict] = []
            quality: dict = {}

            if research.enabled():
                # === Исследовательский конвейер (см. nj602_research.py) ===
                body_md, blocks, facts, quality = await _research_pipeline(
                    ctx, http_client, code, topic, brief, trace, _chat)
            else:
                body_md, blocks = await _legacy_pipeline(ctx, http_client, code, topic, brief, trace, _chat)

            if len(body_md) < 700:
                raise RuntimeError("Сгенерированная статья неприемлемо мала (< 700 символов)")

            # 5. ПРОХОД 2: Разметка метаданных по ГОТОВОМУ тексту статьи
            await _api_progress(ctx, http_client, code, "Формирование заголовка, лида и разметки…", trace)
            enrich_user_prompt = f"ТЕМА: {topic}\n"
            if quality.get("thesis"):
                enrich_user_prompt += (f"ГЛАВНЫЙ ТЕЗИС: {quality['thesis']}\n"
                                       f"РАБОЧИЙ ЗАГОЛОВОК (с цифрой/именем, можно улучшить): {quality.get('title_hint', '')}\n"
                                       "Заголовок и лид должны содержать конкретику (цифру, имя, результат), а не тему вообще.\n")
            enrich_user_prompt += f"\nГОТОВАЯ СТАТЬЯ:\n{body_md[:24000]}"
            enrich_messages = [
                {"role": "system", "content": SYSTEM_ENRICH},
                {"role": "user", "content": enrich_user_prompt},
            ]

            try:
                raw_extras, _ = await _chat(enrich_messages, "enrich", max_tokens=2000, timeout=min(_llm_timeout(), 180.0))
                extras = _parse_extras(raw_extras, body_md, topic)
            except Exception:  # noqa: BLE001
                log.warning("nj602: проход 2 завершился ошибкой — включен фоллбек", exc_info=True)
                extras = _fallback_extras(body_md, topic)

            # Собираем итоговый объект
            article = {
                "title": extras["title"],
                "summary": extras["summary"],
                "body_md": body_md[:60000],
                "image_prompt": extras["image_prompt"],
                "mermaid": extras["mermaid"],
                "tags": extras["tags"],
                "sources": [{"n": i, "url": b["url"], "title": b["title"][:200]} for i, b in enumerate(blocks, 1)],
                "facts": facts[:80],
                "quality": quality,
            }

            # 6. Отправка на сайт
            model_used = (sink_usage[-1]["model"] if sink_usage else "") or _model_spec(payload)
            ok, why, _ = await _post_site(http_client, "/item/" + code, {
                "article_id": str(article_id),
                "topic": topic,
                "title": article["title"],
                "summary": article["summary"],
                "body_md": article["body_md"],
                "image_prompt": article["image_prompt"],
                "mermaid": article["mermaid"],
                "tags": json.dumps(article["tags"], ensure_ascii=False),
                "sources": json.dumps(article["sources"], ensure_ascii=False),
                "facts": json.dumps(article["facts"], ensure_ascii=False),
                "quality": json.dumps(article["quality"], ensure_ascii=False),
                "model_used": model_used,
                "usage": json.dumps(sink_usage, ensure_ascii=False),
                "progress": json.dumps(trace, ensure_ascii=False),
            })

            if not ok:
                raise RuntimeError(f"Сайт не принял статью: {why}")

            _mark(trace, "posted", "статья принята сайтом: " + article["title"][:120])
            await _flush_trace(http_client, code, trace)

            # 7. Обновление RAG с явной пометкой происхождения контента
            _mark(trace, "rag", "сохраняю статью в базу знаний")
            await _flush_trace(http_client, code, trace)

            await _post_site(http_client, "/knowledge_remember", {
                "kind": "article_note",
                "title": article["title"],
                "content": article["body_md"],
                "summary": article["summary"],
                "meta": json.dumps({
                    "topic": topic,
                    "sources": article["sources"],
                    "origin": "ai_generated",
                    "created_at": int(time.time()),
                }, ensure_ascii=False),
            })

            await _api_progress(ctx, http_client, code, "Статья успешно сформирована", trace)

            _mark(trace, "done", f"готово: {len(article['body_md'])} знаков, фактов {len(facts)}, "
                  f"качество {quality.get('score', '—')}/10, схема {len(article['mermaid'])} знаков")
            return {
                "ok": True,
                "code": code,
                "title": article["title"],
                "sources": len(blocks),
                "chars": len(article["body_md"]),
                "facts": len(facts),
                "quality": quality,
                "trace": trace,
            }

        except Exception as exc:
            _mark(trace, "error", str(exc)[:300])
            try:
                await _post_site(http_client, "/fail/" + code, {
                    "error": f"Ошибка воркера: {exc}"[:250],
                    "trace": json.dumps(trace, ensure_ascii=False),
                })
            except Exception:  # noqa: BLE001
                log.warning("nj602: не удалось передать ошибку на сайт", exc_info=True)
            raise