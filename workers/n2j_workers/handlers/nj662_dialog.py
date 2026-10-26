"""Канал 'nj662.dialog': один обмен репликами в диалоге двух ИИ (модуль nj662).

Пайплайн (наработки source_data/100_ai_symposium, «Симпозиум ИИ», переосмыслены):

  1. POST /dialog/api/context — сайт отдаёт всё, что нужно ходу: участников
     (имена, характеры, модели), историю последних обменов, сводку, материалы
     диалога и ОСТАТОК БЮДЖЕТА поиска в интернете. Воркер в базу не ходит: он
     работает по HTTP в любом режиме очереди (раньше ход дёргала jQuery-очередь
     в браузере — владельцу это не нравилось, теперь обмен идёт без браузера);
  2. если бюджет поиска не исчерпан — «утка го»: LLM пишет поисковый запрос,
     ddgs/webread ищут, читаются найденные страницы (поиск и переходы по
     ссылкам = один поиск из бюджета), находки и выжимки страниц уходят в
     подсказку агентам;
  3. реплика первого агента, затем реплика второго (каждому — свой характер);
  4. если включена суммаризация — короткая сводка обмена;
  5. POST /dialog/api/turn — сайт записывает реплики, поиск и сводку и САМ
     ставит задачу следующего обмена. Диалог идёт, пока не кончились обмены или
     владелец не поставил паузу.

Канал 'nj662.quick' — «быстрый старт»: по теме подобрать участников (порт
Service_Lib100_QuickStart::generate_agent_config из наработок: имена, характеры,
роли «структура» и «импульс», формула «да, и…» вместо «нет, потому что…»).

Env: NJ662_API_BASE (иначе общий NJ120_API_BASE + /dialog/api),
NJ662_SITE_TOKEN (иначе WORKER_SITE_TOKEN / WORKER_API_TOKEN), NJ662_MODEL
(«провайдер::модель» для служебных вызовов — запрос поиска и сводка; у реплик
модель своя, из настроек диалога), NJ662_SEARCH=0 (запретить поиск целиком),
NJ662_SEARCH_RESULTS, NJ662_SEARCH_TIMEOUT.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import time

from .. import site, webread

log = logging.getLogger("nj662_dialog")

_PREFIX = "NJ662"
API_PATH = "/dialog/api"

CHANNEL = "nj662.dialog"
QUICK_CHANNEL = "nj662.quick"

# Служебные подсказки: поисковый запрос, сводка обмена, подбор участников.
SEARCH_SYSTEM = (
    "Ты готовишь поисковый запрос для поисковика. Ответ — ОДНА строка: 3–8 слов "
    "по сути, без кавычек, без пояснений, без точки в конце и без слова «запрос»."
)

SUMMARY_SYSTEM = (
    "Ты ведёшь протокол диалога двух ИИ-агентов. Сожми обмен в 2–4 предложения по "
    "делу: о чём договорились, какие факты и числа прозвучали, что осталось "
    "нерешённым. Без вводных слов и без обращения к читателю."
)

QUICK_SYSTEM = (
    "Ты — режиссёр диалога двух ИИ-агентов: подбираешь участников под тему так, "
    "чтобы разговор давал результат, а не обмен любезностями. Отвечаешь строго "
    "JSON, без пояснений вокруг."
)

# Чего ждём от участника: это не «стиль», а условие полезного диалога.
QUICK_RULES = (
    "- первый участник — структура и реализуемость (шаги, критерии, ограничения), "
    "второй — идеи и ход (неожиданные ходы, аналогии, простые проверки);\n"
    "- в характере каждого: роль, правило «опирайся на факты и материалы запроса, "
    "не выдумывай числа и ссылки», формула «да, и…» вместо «нет, потому что…», "
    "длина ответа до 180 слов, в конце — вопрос или предложение собеседнику;\n"
    "- по-русски, без воды и без списков ради списков;\n"
    "- характеры по 500–900 знаков, имена короткие (2–3 слова)."
)


# ==========================================================================
# Чистые функции (проверяются pytest без сети и без модели)
# ==========================================================================

def _clean(text: str, limit: int = 0) -> str:
    """Текст от модели: убрать управляющие символы, лишние переводы, обрезать."""
    text = str(text or "").replace("\r\n", "\n").replace("\r", "\n")
    text = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f]+", "", text)
    text = re.sub(r"\n{3,}", "\n\n", text).strip()

    if limit and len(text) > limit:
        text = text[:limit].rstrip() + "…"

    return text


def _query_from(topic: str, last: str = "", limit: int = 120) -> str:
    """Запасной поисковый запрос, если модель не ответила внятно.

    Берём слова длиннее трёх букв из темы и последней реплики: этого хватает,
    чтобы поиск не пропустил ход вовсе (пустой запрос — потерянный поиск).
    """
    words: list[str] = []
    seen: set[str] = set()

    for chunk in (str(topic or ""), str(last or "")):
        for w in re.split(r"[^\w\-]+", chunk.lower()):
            w = w.strip("-")

            if len(w) < 4 or w in seen:
                continue

            seen.add(w)
            words.append(w)

            if len(words) >= 8:
                break

        if len(words) >= 8:
            break

    return " ".join(words)[:limit]


def _search_query(text: str, topic: str, last: str) -> str:
    """Ответ модели → поисковый запрос. Мусорные ответы заменяем запасным."""
    line = ""

    for raw in str(text or "").splitlines():
        raw = raw.strip()

        if raw:
            line = raw
            break

    line = line.strip('"\'«»` .:;-')
    line = re.sub(r"^(запрос|поисковый запрос)\s*[:\-]\s*", "", line, flags=re.I)
    line = re.sub(r"\s+", " ", line).strip()

    if len(line) < 3 or len(line) > 200:
        return _query_from(topic, last)

    return line[:200]


def _materials_block(knowledge: list[dict], feed_left: int = 2000) -> str:
    """Материалы диалога в подсказку: агент обязан опираться на них."""
    if not knowledge:
        return ""

    lines = ["Материалы диалога (опирайся на них; ссылайся на название материала):"]

    for i, item in enumerate(knowledge, 1):
        title = str(item.get("title") or "").strip() or f"материал {i}"
        text = str(item.get("text") or "").strip()

        if feed_left and len(text) > feed_left:
            text = text[:feed_left].rstrip() + "…"

        lines.append(f"{i}. «{title}» ({item.get('how') or 'материал диалога'})\n{text}")

    return "\n".join(lines)


def _web_block(search: dict | None) -> str:
    """Найденное в интернете: адреса, сниппеты и прочитанные страницы."""
    if not search:
        return ""

    results = list(search.get("results") or [])
    pages = list(search.get("pages") or [])

    if not results and not pages:
        return ""

    lines = [f"Найдено в интернете (поисковый запрос: {search.get('query') or ''}):"]

    for i, r in enumerate(results, 1):
        lines.append(f"{i}. {r.get('title') or ''} — {r.get('url') or ''}\n   {str(r.get('body') or '')[:400]}")

    if pages:
        lines.append("Прочитанные страницы:")

        for p in pages:
            lines.append(f"— {p.get('title') or ''} ({p.get('url') or ''})\n{str(p.get('text') or '')[:1500]}")

    lines.append("Ссылайся на источник коротко: название и адрес.")

    return "\n".join(lines)


def _agent_messages(which: str, context: dict, trigger: str, extra: str = "",
                    fresh: list[dict] | None = None) -> list[dict]:
    """Собрать запрос к модели для одного участника.

    История раскладывается по ролям чата: свои реплики — assistant, чужие — user
    (с именем автора в начале строки). Свежая реплика собеседника (для второго
    участника) добавляется в конец истории, а не дублируется в подсказке.
    """
    agents = dict(context.get("agents") or {})
    me = dict(agents.get(which) or {})

    messages: list[dict] = [{"role": "system", "content": str(me.get("system") or "")}]

    summary = str(context.get("summary") or "").strip()

    if summary:
        messages.append({
            "role": "system",
            "content": "Краткое содержание прошлых обменов:\n" + summary,
        })

    history = list(context.get("history") or []) + list(fresh or [])

    # Реплика владельца (who = "user") попадает в историю как сообщение
    # собеседника; без пояснения модель принимает её за реплику второго
    # агента и спорно «возражает» вместо того, чтобы учесть указание
    # (решение владельца 09.10.2026: реплика — способ поправить направление).
    if any(str(m.get("who") or "") == "user" for m in history):
        messages.append(
            {
                "role": "system",
                "content": "Реплики владельца разговора (человека, который его "
                "запустил) — указание, а не мнение собеседника: учти сказанное "
                "и разверни разговор в эту сторону, не спорь с формулировкой.",
            }
        )

    for m in history:
        content = str(m.get("content") or "").strip()

        if not content:
            continue

        mine = str(m.get("who") or "") == which

        messages.append({
            "role": "assistant" if mine else "user",
            "content": content if mine else f"{m.get('author') or 'Собеседник'}: {content}",
        })

    body = "\n\n".join(x for x in [extra, trigger] if x)

    messages.append({"role": "user", "content": body})

    return messages


def _tokens(usage: dict | None) -> int:
    """Токены ответа: total, иначе сумма входных и выходных."""
    usage = dict(usage or {})

    try:
        total = int(usage.get("total_tokens") or 0)
    except (TypeError, ValueError):
        total = 0

    if total:
        return total

    try:
        return int(usage.get("prompt_tokens") or 0) + int(usage.get("completion_tokens") or 0)
    except (TypeError, ValueError):
        return 0


def _parse_quick(text: str) -> dict:
    """Ответ модели (JSON участников) → словарь для сайта.

    Контракт взят из наработок (Service_Lib100_QuickStart::generate_agent_config):
    agent_1_name, agent_2_name, agent_1_system_text, agent_2_system_text. Ответ
    может прийти в ограде ```json — разбираем через общий webread.json_from_text.
    """
    data = webread.json_from_text(str(text or ""))

    if not isinstance(data, dict):
        raise RuntimeError("модель не вернула JSON с участниками диалога")

    def pick(*names: str) -> str:
        for name in names:
            value = data.get(name)

            if isinstance(value, str) and value.strip():
                return value.strip()

        return ""

    agents = data.get("agents") if isinstance(data.get("agents"), list) else []

    def pick_agent(i: int, kind: str) -> str:
        if len(agents) > i and isinstance(agents[i], dict):
            for key in (kind, f"agent_{kind}", "name" if kind == "name" else "system_text"):
                value = agents[i].get(key)

                if isinstance(value, str) and value.strip():
                    return value.strip()

        return ""

    out = {
        "a_name": pick("agent_1_name", "a_name", "first_name") or pick_agent(0, "name"),
        "b_name": pick("agent_2_name", "b_name", "second_name") or pick_agent(1, "name"),
        "a_system": pick("agent_1_system_text", "a_system", "first_system") or pick_agent(0, "system_text"),
        "b_system": pick("agent_2_system_text", "b_system", "second_system") or pick_agent(1, "system_text"),
        "note": pick("note", "role_note", "comment"),
    }

    if not out["a_name"] and not out["a_system"]:
        raise RuntimeError("в JSON нет участников диалога (agent_1_name / agent_1_system_text)")

    return out


def _tokens_of_context(limits: dict | None, default: int = 8000) -> int:
    """Примерный контекст модели: из env NJ662_CONTEXT (как у ИИ-радара)."""
    try:
        return max(2000, int((os.environ.get("NJ662_CONTEXT") or "").strip() or default))
    except ValueError:
        return default


# ==========================================================================
# Связь с сайтом
# ==========================================================================

async def _api(ctx, path: str, data: dict, timeout: float = 120.0) -> dict:
    """POST в приёмную модуля; ошибка — RuntimeError с человеческой причиной."""
    ok, reason, res = await site.post_json(
        _PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(_PREFIX, API_PATH))

    if not ok:
        raise RuntimeError(f"nj662: сайт {path} → {reason}")

    return dict(res or {})


async def _api_soft(ctx, path: str, data: dict, timeout: float = 120.0) -> dict:
    """POST, где отказ сайта — это ОТВЕТ, а не авария.

    `_api` поднимает RuntimeError на любой ответ с `error`, но у /context отказ
    бывает штатным: владелец поставил диалог на паузу, отменил его, или обмен
    уже не актуален (задачу повторили). Тогда задача обязана закрыться миром
    (`stopped`), а не покраснеть в очереди — иначе пауза выглядит как поломка.
    """
    ok, reason, res = await site.post_json(
        _PREFIX, path, data, json_body=True, timeout=timeout,
        base=site.module_api_base(_PREFIX, API_PATH))

    body = dict(res or {})

    if not ok and not str(body.get("error") or "").strip():
        body["error"] = reason

    body.setdefault("ok", ok)

    return body


async def _stage(ctx, dialog_id: int, stage: str, note: str = "") -> None:
    """Живой этап: и в журнал задачи (монитор nj120), и на страницу диалога.

    Владелец должен видеть, где ход: «Ищу в интернете», «Говорит Структура»,
    «Отвечает Импульс». Сбой рапорта не роняет обмен.
    """
    try:
        await ctx.progress(stage, note)
    except Exception:  # noqa: BLE001 — журнал не важнее обмена
        pass

    try:
        await _api(ctx, "/progress", {"dialog_id": dialog_id, "stage": stage, "note": note}, timeout=30.0)
    except Exception as e:  # noqa: BLE001 — этап виден и в мониторе очереди
        log.debug("nj662: этап %s не доехал: %s", stage, e)


async def _ask(ctx, messages: list[dict], model: str | None, max_tokens: int = 1200,
               timeout: int = 300) -> tuple[str, int]:
    """Один вызов модели: текст ответа и токены."""
    if ctx.llm is None:
        raise RuntimeError("LLM-шлюз недоступен: проверьте провайдера в workers/.env")

    text, usage = await ctx.llm.chat_with_usage(
        messages, model=model or None, max_tokens=max_tokens, request_timeout=timeout)

    return _clean(text), _tokens(usage)


# ==========================================================================
# Поиск в интернете («утка го»)
# ==========================================================================

def _search_enabled() -> bool:
    return (os.environ.get("NJ662_SEARCH", "1") or "1").strip().lower() not in ("0", "false", "no", "off")


def _search_timeout() -> float:
    try:
        return max(5.0, float((os.environ.get("NJ662_SEARCH_TIMEOUT") or "45").strip()))
    except ValueError:
        return 45.0


def _service_model(fallback: str | None = None) -> str | None:
    """Модель служебных вызовов: NJ662_MODEL, иначе модель участника.

    Поисковый запрос и сводка — не реплика: их можно считать моделью
    подешевле, не меняя модель агента (задаётся в workers/.env).
    """
    own = (os.environ.get("NJ662_MODEL") or "").strip()

    if own:
        return own

    return (str(fallback).strip() or None) if fallback else None


async def _web_search(ctx, context: dict, model: str | None) -> dict | None:
    """Один поиск из бюджета: запрос → выдача → чтение страниц.

    Бюджет N — это N таких поисков на диалог (владелец: «0 — не пользоваться,
    1 — один раз (поиск и переход по ссылкам), 2 — два раза…»). Поиск не роняет
    обмен: не нашлось — агенты работают на материалах и знаниях модели.
    """
    if not _search_enabled():
        return None

    limits = dict(context.get("limits") or {})
    sources_max = max(1, min(8, int(limits.get("sources_max") or 4)))
    pages_max = max(0, min(4, int(limits.get("pages_max") or 2)))
    page_chars = max(300, min(4000, int(limits.get("page_chars") or 1200)))

    topic = str(context.get("topic") or "")
    history = list(context.get("history") or [])
    last = str(history[-1].get("content") if history else "")

    dialog_id = int(context.get("dialog_id") or 0)

    # 1. Запрос пишет модель: поисковику нужны слова темы, а не реплика целиком.
    try:
        raw, q_tokens = await _ask(ctx, [
            {"role": "system", "content": SEARCH_SYSTEM},
            {"role": "user", "content": (
                f"Тема диалога: {topic}\n"
                f"О чём речь сейчас: {_clean(last, 600)}\n\n"
                "Напиши поисковый запрос.")},
        ], _service_model(model), max_tokens=60, timeout=120)
    except Exception as e:  # noqa: BLE001 — без запроса идём на запасной
        log.warning("nj662: модель не дала поисковый запрос (%s) — беру слова темы", e)
        raw, q_tokens = "", 0

    query = _search_query(raw, topic, last)

    if not query:
        return None

    await _stage(ctx, dialog_id, "Ищу в интернете", f"«{query}»")

    # 2. Выдача «утки»: библиотека ddgs, при молчании — HTML-выдача DuckDuckGo.
    try:
        rows = await asyncio.wait_for(
            webread.ddg_text(
                query,
                max_results=sources_max + 2,
                timeout_sec=_search_timeout(),
            ),
            timeout=_search_timeout() + 10,
        )
    except Exception as e:  # noqa: BLE001 — поиск в интернете обязан быть прощающим
        log.warning("nj662: поиск «%s» не отдался: %s", query[:60], e)
        rows = []

    results = []

    for r in rows or []:
        url = str(r.get("url") or r.get("href") or "").strip()
        title = str(r.get("title") or "").strip()

        if not url or not title:
            continue

        results.append({"title": title[:200], "url": url[:400],
                        "body": str(r.get("body") or "")[:400]})

        if len(results) >= sources_max:
            break

    # 3. Переходы по ссылкам: читаем первые страницы (это часть того же поиска).
    pages = []

    for r in results[:pages_max]:
        try:
            html, _err = await webread.fetch(r["url"])
            md, page_title = await webread.to_markdown(html, cap=page_chars)
        except Exception as e:  # noqa: BLE001 — страница не открылась — идём дальше
            log.info("nj662: страница %s не прочиталась: %s", r["url"][:80], e)
            continue

        text = _clean(md, page_chars)

        if len(text) < 120:
            continue

        pages.append({"url": r["url"], "title": _clean(page_title or r["title"], 200), "text": text})

    note = f"находок: {len(results)}, прочитано страниц: {len(pages)}"

    log.info("nj662: поиск «%s» — %s", query[:60], note)

    # Ничего не нашлось (поисковик молчит или запрос пустой выдачу) — это НЕ
    # поиск: бюджет диалога не тратим, в ленте обмена пустой блок не показываем.
    if not results and not pages:
        return None

    return {
        "agent": "a",
        "query": query,
        "results": results,
        "pages": pages,
        "note": note,
        "tokens": q_tokens,
    }


# ==========================================================================
# Канал: один обмен репликами
# ==========================================================================

async def handle(ctx, payload: dict) -> dict:
    dialog_id = int(payload.get("dialog_id") or 0)
    turn = int(payload.get("turn") or 0)

    if dialog_id <= 0 or turn <= 0:
        raise RuntimeError("nj662: в задаче нет dialog_id и turn")

    started = time.monotonic()

    context = await _api_soft(ctx, "/context", {"dialog_id": dialog_id, "turn": turn})

    if not context.get("ok"):
        # «Диалог больше не идёт» — это НЕ ошибка: пауза или отмена закрывают
        # задачу мирно, иначе очередь показала бы падение на ровном месте.
        return {
            "dialog_id": dialog_id,
            "turn": turn,
            "stopped": True,
            "reason": _clean(context.get("error") or "", 200),
        }

    agents = dict(context.get("agents") or {})
    a = dict(agents.get("a") or {})
    b = dict(agents.get("b") or {})
    pairs = int(context.get("pairs") or 1)
    topic = str(context.get("topic") or "")
    brief = str(context.get("brief") or "")
    a_name = str(a.get("name") or "Первый агент")
    b_name = str(b.get("name") or "Второй агент")

    await _stage(ctx, dialog_id, "Готовлю контекст", f"обмен {turn} из {pairs}")

    # --- 1. Поиск в интернете, если бюджет это разрешает -------------------
    search = None
    left = int((context.get("search") or {}).get("left") or 0)

    if left > 0:
        try:
            search = await _web_search(ctx, context, str(a.get("model") or "") or None)
        except Exception as e:  # noqa: BLE001 — без интернета диалог обязан идти
            log.warning("nj662: поиск не удался: %s", e)

    extra = "\n\n".join(x for x in [
        _materials_block(list(context.get("knowledge") or [])),
        _web_block(search),
        "Правило: факты и цифры — только из материалов диалога и найденного в "
        "интернете; чего нет — так и скажи, не выдумывай."
        if (context.get("knowledge") or search) else "",
    ] if x)

    # --- 2. Реплика первого агента ----------------------------------------
    if turn <= 1:
        opener = (
            f"Начинай разговор.\nТема: {topic}."
            + (f"\nЧто уже известно: {brief}." if brief.strip() else "")
            + "\n\nСкажи первое: коротко обозначь свой взгляд по теме и задай "
              "собеседнику один вопрос, с которого начнётся дело."
        )
    else:
        opener = (
            "Ответь собеседнику: прими или уточни сказанное, добавь то, чего не "
            "хватает, и закончи вопросом или предложением."
        )

    await _stage(ctx, dialog_id, f"Говорит {a_name}", f"реплика 1 из 2, обмен {turn}")

    try:
        text_a, tokens_a = await _ask(ctx, _agent_messages("a", context, opener, extra),
                                      str(a.get("model") or "") or None, max_tokens=1200)
    except Exception as e:  # noqa: BLE001 — причина важнее стектрейса
        await _fail(ctx, dialog_id,
                    f"Реплика «{a_name}» не получилась: {_model_note(a.get('model'))}{e}")

        raise

    if not text_a:
        await _fail(ctx, dialog_id, f"Модель вернула пустой ответ для «{a_name}»")

        raise RuntimeError("nj662: пустая реплика первого агента")

    # --- 3. Реплика второго агента ----------------------------------------
    trigger_b = (
        f"{a_name} сказал(а) выше. Теперь твой ход: ответь по формуле «да, и…» — "
        "прими зерно идеи и добавь то, чего не хватает. Закончи тем, как это "
        "проверить или развить."
    )

    await _stage(ctx, dialog_id, f"Отвечает {b_name}", f"реплика 2 из 2, обмен {turn}")

    fresh = [{"who": "a", "author": a_name, "content": text_a}]

    try:
        text_b, tokens_b = await _ask(ctx, _agent_messages("b", context, trigger_b, extra, fresh),
                                      str(b.get("model") or "") or None, max_tokens=1200)
    except Exception as e:  # noqa: BLE001
        await _fail(ctx, dialog_id,
                    f"Реплика «{b_name}» не получилась: {_model_note(b.get('model'))}{e}")

        raise

    if not text_b:
        await _fail(ctx, dialog_id, f"Модель вернула пустой ответ для «{b_name}»")

        raise RuntimeError("nj662: пустая реплика второго агента")

    # --- 4. Сводка обмена (если включена) ---------------------------------
    summary = ""

    if context.get("summarize"):
        await _stage(ctx, dialog_id, "Сводка", "сжимаю обмен для следующих ходов")

        try:
            summary, s_tokens = await _ask(ctx, [
                {"role": "system", "content": SUMMARY_SYSTEM},
                {"role": "user", "content": (
                    f"Тема: {topic}\n{a_name}: {_clean(text_a, 1500)}\n"
                    f"{b_name}: {_clean(text_b, 1500)}\n\nСводка обмена:")},
            ], _service_model(str(a.get("model") or "") or None), max_tokens=300, timeout=180)
            summary = _clean(summary, 600)
        except Exception as e:  # noqa: BLE001 — без сводки диалог продолжается
            log.warning("nj662: сводка обмена %s не получилась: %s", turn, e)
            summary = ""
            s_tokens = 0
    else:
        s_tokens = 0

    # --- 5. Отдаём обмен сайту: он запишет и поставит следующий ----------
    messages = [
        {"who": "a", "author": a_name, "content": text_a,
         "model": agent_model(a, ctx), "tokens": tokens_a},
        {"who": "b", "author": b_name, "content": text_b,
         "model": agent_model(b, ctx), "tokens": tokens_b,
         "summary": summary},
    ]

    res = await _api(ctx, "/turn", {
        "dialog_id": dialog_id,
        "turn": turn,
        "data": json.dumps({
            "messages": messages,
            "search": search,
            "summary": summary,
        }, ensure_ascii=False),
    })

    if not res.get("ok"):
        raise RuntimeError(f"nj662: сайт не принял обмен {turn}: {res.get('error') or 'без причины'}")

    elapsed = round(time.monotonic() - started, 1)

    return {
        "dialog_id": dialog_id,
        "turn": turn,
        "done": bool(res.get("done")),
        "messages": len(messages),
        "search": (search or {}).get("query") or "",
        "search_used": 1 if search else 0,
        "summary": bool(summary),
        "tokens": tokens_a + tokens_b + s_tokens + int((search or {}).get("tokens") or 0),
        "elapsed_sec": elapsed,
    }


def agent_model(agent: dict, ctx) -> str:
    """Подпись модели для реплики: своя из настроек, иначе модель воркера."""
    model = str(agent.get("model") or "").strip()

    if model:
        return model[:160]

    try:
        return str(getattr(ctx.llm, "model", "") or "")[:160]
    except Exception:  # noqa: BLE001
        return ""


def _model_note(model: str | None) -> str:
    """«модель openrouter::… не ответила» — в причине отказа важна не только ошибка.

    Без подписи модели владелец видит «TLS/SSL connection closed» и не понимает,
    что виновата конкретная модель из настроек (или отсутствующий ключ
    провайдера), а не весь диалог.
    """
    spec = str(model or "").strip()

    return f"модель {spec} не ответила: " if spec else "модель воркера не ответила: "


async def _fail(ctx, dialog_id: int, error: str) -> None:
    """Сообщить сайту причину: страница показывает её, а не молчит."""
    try:
        await _api(ctx, "/fail", {"dialog_id": dialog_id, "error": _clean(error, 480)}, timeout=30.0)
    except Exception as e:  # noqa: BLE001 — причина уже уходит в очередь
        log.warning("nj662: причина отказа не доехала: %s", e)


# ==========================================================================
# Канал: быстрый старт (подбор участников)
# ==========================================================================

async def handle_quick(ctx, payload: dict) -> dict:
    """Подобрать участников под тему — «быстрое безгеморройное начало диалога».

    Порт Service_Lib100_QuickStart::generate_agent_config: модель возвращает
    имена и характеры двух агентов строгим JSON. Дальше сайт заполняет диалог и
    (если владелец просил) сразу ставит первый обмен.
    """
    dialog_id = int(payload.get("dialog_id") or 0)

    if dialog_id <= 0:
        raise RuntimeError("nj662: в задаче нет dialog_id")

    topic = str(payload.get("topic") or "").strip()
    brief = str(payload.get("brief") or "").strip()
    model = str(payload.get("model") or "").strip() or None
    autostart = bool(payload.get("autostart"))

    await _stage(ctx, dialog_id, "Подбираю агентов", "модель придумывает имена и характеры под тему")

    user = (
        f"Тема диалога: {topic}\n"
        f"Что известно: {brief or 'ничего'}\n\n"
        "Верни строго JSON:\n"
        '{"agent_1_name": "…", "agent_2_name": "…", '
        '"agent_1_system_text": "…", "agent_2_system_text": "…", '
        '"note": "одна фраза о том, как роли делят работу"}\n\n'
        "Требования к участникам:\n" + QUICK_RULES
    )

    try:
        text, tokens = await _ask(ctx, [
            {"role": "system", "content": QUICK_SYSTEM},
            {"role": "user", "content": user},
        ], _service_model(model), max_tokens=1600, timeout=300)
    except Exception as e:  # noqa: BLE001 — причина важнее стектрейса
        await _fail(ctx, dialog_id, f"Подбор участников не удался: {_model_note(_service_model(model))}{e}")

        raise

    agents = _parse_quick(text)

    res = await _api_soft(ctx, "/quick", {
        "dialog_id": dialog_id,
        "agents": json.dumps(dict(agents, autostart=autostart), ensure_ascii=False),
    })

    if not res.get("ok"):
        # Диалог удалён или уже не ждёт подбора — задача закрывается миром:
        # красная задача в очереди на ровном месте пугает больше, чем помогает.
        reason = _clean(res.get("error") or "сайт не принял участников", 200)

        log.info("nj662: подбор участников для диалога %s не понадобился: %s", dialog_id, reason)

        return {"dialog_id": dialog_id, "stopped": True, "reason": reason}

    return {
        "dialog_id": dialog_id,
        "a_name": res.get("a_name") or agents["a_name"],
        "b_name": res.get("b_name") or agents["b_name"],
        "started": bool(res.get("started")),
        "tokens": tokens,
    }
