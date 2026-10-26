"""nj676.analysis — ИИ-анализ каталога MCP: что живо и чего не хватает.

Зачем отдельный канал. У каталога есть числа (готовы 17, карточек 1045) и есть
сырые срезы (кто не отвечает, что спрашивают агенты). Числа не дают решения:
«17 готовы» не говорит, что чинить первым, а «120 запросов без ответа» — что из
них стоит собирать. Решение принимает модель, и делать это на сайте нельзя:
HTTP-запрос не должен ждать LLM, а ключ модели живёт в воркере.

Как устроено (pull, как у перевода):

  1. сайт ставит задачу `nj676.analysis` и держит снимок в `nj_676_analysis`
     (`snapshot` — то, что увидит модель: числа, живые примеры, причины
     недоступности, падающие вызовы, спрос);
  2. воркер забирает снимок `GET /mcphub/api/analysis-get?id=N` и отправляет его
     модели с просьбой вернуть СТРОГИЙ JSON;
  3. отчёт уходит на `POST /mcphub/api/analysis-finish` — сайт нормализует поля
     (никакого доверия к модели: только известные ключи и обрезанные строки);
  4. админка показывает вердикт, поломки с причиной, пробелы и темы, по которым
     можно сразу нажать «Найти и добавить».

Почему снимок, а не «пусть воркер сам сходит в базу». Воркер не имеет доступа к
базе сайта и не должен его получать: он работает через API. Снимок заодно даёт
честность отчёта — видно, на каких данных модель сделала вывод.

Env: NJ676_ANALYSIS_MODEL (или общий NJ676_MODEL), NJ676_ANALYSIS_TOKENS
(бюджет ответа, 4000), адрес и токен сайта — как у nj676.discover.
"""

from __future__ import annotations

import json
import logging
import time

from .. import site, webread
from ..llmchat import chat_with_usage as _llm
from .nj676_mcphub import _PREFIX, _env_int, _site_base, _site_post, _strip_think, _think_options

log = logging.getLogger("n2j.nj676")

CHANNEL = "nj676.analysis"

SYSTEM = (
    "Ты — технический редактор каталога MCP-серверов N2J.ru. Тебе дают срез "
    "каталога: числа, серверы, которые уже отвечают, серверы, которые не "
    "работают и почему, падающие вызовы и запросы агентов, которые ничего не "
    "нашли. Твоя работа — короткий и точный разбор для владельца сервиса. "
    "Опирайся ТОЛЬКО на присланные данные: не выдумывай серверы, цифры и "
    "причины. Отвечай СТРОГО одним JSON-объектом без markdown и без пояснений."
)

PROMPT_TEMPLATE = (
    "Проанализируй срез каталога MCP и верни JSON строго такого вида:\n"
    "{\n"
    '  "verdict": "2–4 предложения: что с каталогом сейчас в целом — сколько реально '
    'работает, что мешает больше всего, стоит ли тревожиться",\n'
    '  "live_notes": "2–4 предложения про живую часть: у кого какая задержка, что '
    'надёжно, что отвечает, но требует ключа",\n'
    '  "problems": [{"slug": "слаг из среза", "what": "в чём проблема", '
    '"fix": "что конкретно сделать владельцу"}],\n'
    '  "actions": ["до 5 конкретных действий по приоритету, каждое одной фразой"],\n'
    '  "gaps": [{"theme": "тема по-русски", "why": "почему она нужна — по каким '
    'запросам видно", "queries": ["запросы агентов из среза"]}],\n'
    '  "collect": ["до 5 тем для кнопки «Найти и добавить», коротко"]\n'
    "}\n\n"
    "Правила:\n"
    "- problems — только реальные поломки из среза (нужен ключ, адрес не проходит, "
    "проба не отвечает, вызовы падают). Не больше 8, самые дорогие первыми.\n"
    "- gaps — только темы, по которым агенты реально спрашивали и не нашли. "
    "Если таких запросов нет, верни пустой список: пустой список честнее выдумки.\n"
    "- Ничего не предлагай переписывать в коде — ты говоришь о каталоге, а не о сайте.\n"
    "- Все тексты по-русски, коротко, без воды и без рекламы.\n"
)

# Сколько символов снимка максимум отдаём модели: срез компактный, но защита
# нужна — иначе один разросшийся отчёт спроса вытеснит саму задачу.
SNAPSHOT_LIMIT = 24000


def _cards(snapshot: dict) -> str:
    """Срез → компактный текст для модели (без пустых разделов)."""
    parts: list[str] = []

    catalog = snapshot.get("catalog") or {}
    ready = snapshot.get("ready") or {}
    probe = snapshot.get("probe") or {}
    calls = snapshot.get("calls") or {}

    parts.append(
        "ЧИСЛА\n"
        f"- серверов в каталоге: {catalog.get('servers', 0)}, инструментов в схемах: {catalog.get('tools', 0)}\n"
        f"- готовы к вызову сейчас: {ready.get('servers', 0)} серверов, {ready.get('tools', 0)} инструментов\n"
        f"- удалённых серверов: {probe.get('remote', 0)}; ни разу не проверялись: {probe.get('never', 0)}; "
        f"старше {probe.get('stale_days', 7)} дней: {probe.get('stale', 0)}; свежие: {probe.get('fresh', 0)}\n"
        f"- вызовов за 7 дней: {calls.get('calls', 0)}, успешных: {calls.get('ok', 0)}, "
        f"отзывов «плохо»: {calls.get('down', 0)}"
    )

    if ready.get("error"):
        parts.append("ВНИМАНИЕ: расчёт готовности не выполнился: " + str(ready["error"]))

    live = snapshot.get("live") or []
    if live:
        lines = ["ЖИВЫЕ СЕРВЕРЫ (готовы к вызову)"]
        for item in live[:20]:
            tools = ", ".join(str(t) for t in (item.get("examples") or [])[:3])
            lines.append(
                f"- {item.get('slug')} ({item.get('name')}): инструментов {item.get('tools', 0)}"
                + (f"; например {tools}" if tools else "")
                + (f"; {item.get('latency_ms')} мс" if item.get("latency_ms") else "")
            )
        parts.append("\n".join(lines))

    blocked = snapshot.get("blocked") or {}
    for key, title in (("needs_key", "ТРЕБУЮТ КЛЮЧ"),
                       ("invalid_url", "АДРЕС НЕ ПРОХОДИТ ПРОВЕРКУ"),
                       ("probe_failed", "НЕ ОТВЕЧАЮТ НА ПРОБУ")):
        rows = blocked.get(key) or []
        if not rows:
            continue
        lines = [title]
        for item in rows[:12]:
            lines.append(f"- {item.get('slug')}: {item.get('why') or 'без подробностей'}")
        parts.append("\n".join(lines))

    failing = snapshot.get("failing") or []
    if failing:
        lines = ["ВЫЗОВЫ ПАДАЮТ (7 дней)"]
        for item in failing[:12]:
            lines.append(
                f"- {item.get('slug')}: {item.get('failed')} ошибок из {item.get('calls')} вызовов"
            )
        parts.append("\n".join(lines))

    demand = snapshot.get("demand") or {}
    empty = demand.get("empty") or []
    if empty:
        lines = ["СПРАШИВАЛИ, НИЧЕГО НЕ НАШЛОСЬ"]
        for item in empty[:15]:
            lines.append(f"- «{item.get('query')}»: {item.get('searches')} запросов")
        parts.append("\n".join(lines))

    top = demand.get("top") or []
    if top:
        lines = ["ЧТО СПРАШИВАЮТ ЧАЩЕ ВСЕГО"]
        for item in top[:12]:
            empty_n = int(item.get("empty") or 0)
            lines.append(
                f"- «{item.get('query')}»: {item.get('searches')} запросов"
                + (f", без результата {empty_n}" if empty_n else "")
                + f"; лучший ответ {item.get('best_servers', 0)} серверов"
            )
        parts.append("\n".join(lines))

    text = "\n\n".join(parts)

    return text[:SNAPSHOT_LIMIT]


def _parse_report(raw: str) -> dict | None:
    """Ответ модели → словарь отчёта. None — если разобрать нельзя."""
    parsed = webread.json_from_text(_strip_think(raw or ""))

    if isinstance(parsed, dict):
        return parsed

    return None


async def _ask(ctx, snapshot: dict, mode: str):
    """Один вызов модели. → (отчёт, usage)."""
    prompt = PROMPT_TEMPLATE

    if mode == "live":
        prompt += "\nСейчас нужен только раздел про состояние каталога: gaps и collect верни пустыми списками.\n"
    elif mode == "gaps":
        prompt += "\nСейчас нужен только раздел про пробелы: problems и actions верни пустыми списками.\n"

    prompt += "\n\nСРЕЗ КАТАЛОГА:\n" + _cards(snapshot)
    prompt += "\n\nВерни только JSON."

    tokens = _env_int("NJ676_ANALYSIS_TOKENS", 4000, 800, 8000)

    return await _llm(
        ctx,
        [{"role": "system", "content": SYSTEM}, {"role": "user", "content": prompt}],
        "Анализирую каталог MCP",
        model_env="NJ676_ANALYSIS_MODEL",
        max_tokens=tokens,
        # Размышления выключены: разбор по готовому срезу не требует длинных
        # рассуждений, а рассуждающая модель тратит на них весь бюджет ответа и
        # возвращает обрезанный JSON (тот же урок, что у перевода 23.10.2026).
        options=_think_options(),
        timeout=240,
    )


async def handle(ctx, payload: dict) -> dict:
    payload = payload if isinstance(payload, dict) else {}
    analysis_id = int(payload.get("analysis_id") or 0)
    mode = str(payload.get("mode") or "both")

    base = _site_base()
    if not base:
        raise RuntimeError(
            "не настроен адрес сайта: NJ676_API_BASE (или общий NJ120_API_BASE) "
            "в workers/.env"
        )

    if analysis_id <= 0:
        raise RuntimeError("не передан analysis_id")

    ok, reason, answer = await site.get_json(
        _PREFIX, f"/analysis-get?id={analysis_id}", base=base
    )

    if not ok or not isinstance(answer, dict):
        raise RuntimeError(f"сайт не отдал снимок анализа: {reason or 'пустой ответ'}")

    snapshot = answer.get("snapshot")
    if not isinstance(snapshot, dict) or not snapshot:
        # Пустой снимок — не повод молча заканчивать «успехом»: отчёт без данных
        # был бы выдумкой.
        await _site_post(base, "/analysis-finish", {
            "analysis_id": analysis_id,
            "status": "fail",
            "error": "снимок анализа пуст — нечего анализировать",
        })
        raise RuntimeError("снимок анализа пуст")

    await _progress(ctx, f"Анализирую каталог: {int((snapshot.get('catalog') or {}).get('servers') or 0)} серверов")

    started = time.monotonic()
    errors: list[str] = []
    report = None
    raw = ""
    usage: dict = {}

    for attempt in (1, 2):
        try:
            raw, usage = await _ask(ctx, snapshot, mode)
        except Exception as exc:  # noqa: BLE001 — модель бывает недоступна
            errors.append(f"модель: {exc.__class__.__name__}")
            log.warning("nj676.analysis: модель не ответила (%s)", exc.__class__.__name__)
            continue

        report = _parse_report(raw or "")

        if report:
            break

        errors.append("модель вернула не JSON")
        log.warning("nj676.analysis: ответ модели не разобран, попытка %s", attempt)

    ms = int((time.monotonic() - started) * 1000)

    if not report:
        await _site_post(base, "/analysis-finish", {
            "analysis_id": analysis_id,
            "status": "fail",
            "error": "; ".join(errors[:3]) or "модель не дала пригодный ответ",
            "ms": ms,
            "model": str((usage or {}).get("model") or ""),
        })

        return {"analysis_id": analysis_id, "status": "fail", "errors": errors[:3]}

    await _site_post(base, "/analysis-finish", {
        "analysis_id": analysis_id,
        "status": "done",
        "report": report,
        "ms": ms,
        "model": str((usage or {}).get("model") or ""),
    })

    log.info("nj676.analysis: анализ %s готов за %s мс", analysis_id, ms)

    return {
        "analysis_id": analysis_id,
        "status": "done",
        "ms": ms,
        "gaps": len(report.get("gaps") or []),
        "problems": len(report.get("problems") or []),
    }


async def _progress(ctx, phase: str) -> None:
    """Рапорт в очередь: анализ может думать минуту, пульс должен идти."""
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт не важнее работы
        pass
