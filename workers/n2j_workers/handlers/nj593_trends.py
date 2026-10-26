"""nj593.trends — ежедневный обзор трендов и новинок GitHub (N2J.ru /trends).

Пайплайн: GitHub API (свежие репозитории по звёздам) → чтение README → LLM отбирает и описывает по-русски → POST /trends/api/item → /trends/api/complete.

Воркер: ищет СВЕЖИЕ репозитории через GitHub API (search/repositories,
created:>дата, stars>=N, опц. language), читает README самых звёздных,
LLM отбирает самые «интересные» и пишет по-русски: что это, почему в
обзоре, кому пригодится. Позиции выгружаются на сайт (/trends/api/item),
завершение — заголовок + вступление (/trends/api/complete). Страница
обзора публичная.

Env: NJ593_TRENDS_API_BASE (напр. https://n2j.ru/trends/api),
NJ593_TRENDS_SITE_TOKEN (или общий WORKER_SITE_TOKEN → WORKER_API_TOKEN),
NJ593_TRENDS_MODEL (спека «провайдер::модель»), NJ593_GITHUB_TOKEN
(опц.; без него GitHub даёт 60 запросов/час, с ним — 5000),
NJ593_TRENDS_MAX_ITEMS (10), NJ593_TRENDS_MIN_STARS (50),
NJ593_TRENDS_README_CAP (2400), NJ593_LLM_CURATE_TIMEOUT (300 c),
NJ593_LLM_CURATE_RETRY_TIMEOUT (150 c), NJ593_LLM_TAKEAWAYS_TIMEOUT (150 c).

Финалы сборки: «ИИ посмотрела и не нашла ничего» → «Готово» с объяснением;
«ИИ не ответила» → обзор по фактам GitHub с пометкой в сводке; «GitHub не дал
кандидатов» → задача падает с понятной причиной. Вечного «в работе» нет.

Ежедневный запуск: systemd timer на сервере воркера —
curl -fsS -X POST -H "X-Worker-Token: $TOKEN" -d 'period=1&count=8' \\
     https://n2j.ru/trends/api/create   (пример юнита в README модуля).
"""

from __future__ import annotations

import asyncio
import logging
import os
import re
import time
from datetime import date, datetime, timedelta, timezone

import httpx

from .. import site
from .. import webread
from .nj590_design import _readable_reason

log = logging.getLogger("n2j.nj593")

CHANNEL = "nj593.trends"

GH_API = "https://api.github.com"


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _github_token() -> str:
    return _env("NJ593_GITHUB_TOKEN")


def _max_items() -> int:
    try:
        return max(3, min(12, int(_env("NJ593_TRENDS_MAX_ITEMS", "10"))))
    except ValueError:
        return 10


def _min_stars_default() -> int:
    try:
        return max(10, int(_env("NJ593_TRENDS_MIN_STARS", "50")))
    except ValueError:
        return 50


def _llm_timeout(name: str, default: float) -> float:
    """Жёсткий бюджет LLM-этапа, секунды (env NJ593_LLM_<ЭТАП>).

    Раньше отбор шёл с timeout=480 c и повторялся ещё раз по 480 c: обзор мог
    «висеть» до 16 минут без единой строчки прогресса — владелец видел
    «Собираем обзор 6:31, ИИ отбирает…» и не понимал, что происходит.
    Теперь бюджет меньше, а после него воркер честно деградирует (собирает
    обзор по данным GitHub) или падает с понятной причиной.
    """
    try:
        return max(30.0, min(900.0, float(_env(name) or default)))
    except ValueError:
        return default


def _curate_timeout() -> float:
    return _llm_timeout("NJ593_LLM_CURATE_TIMEOUT", 300.0)


def _curate_retry_timeout() -> float:
    return _llm_timeout("NJ593_LLM_CURATE_RETRY_TIMEOUT", 150.0)


def _takeaways_timeout() -> float:
    return _llm_timeout("NJ593_LLM_TAKEAWAYS_TIMEOUT", 150.0)


def _apply_timeout() -> float:
    """Бюджет добора строк «Как применить» (env NJ593_LLM_APPLY_TIMEOUT)."""
    return _llm_timeout("NJ593_LLM_APPLY_TIMEOUT", 120.0)


# Последний рубеж: если и целевой запрос не дал строку, позиция всё равно
# выходит с практической подсказкой — блок «Как применить» не исчезает.
_APPLY_HINT = ("Откройте README репозитория и попробуйте на небольшой задаче — "
               "так польза для вашего проекта видна за 10–20 минут.")


def _readme_cap() -> int:
    try:
        return max(600, min(12000, int(_env("NJ593_TRENDS_README_CAP", "2400"))))
    except ValueError:
        return 2400


def _think_options() -> dict:
    """Отключить размышления рассуждающей модели (Qwen3.5 etc.).

    reasoning_effort — OpenAI-совместимые шлюзы, chat_template_kwargs — llama.cpp/LM Studio.
    Сервер, который полей не знает, вернёт 400 — вызывающий повторит без них.
    """
    return {"reasoning_effort": "none", "chat_template_kwargs": {"enable_thinking": False}}


def _strip_think(text: str) -> str:
    """Убрать <think>…</think> из ответа рассуждающей модели."""
    if not text:
        return ""
    # Qwen3/DeepSeek часто прячут размышления в <think>
    text = re.sub(r"<think>.*?</think>", "", text, flags=re.S | re.I)
    # Иногда модель начинает с "Анализ: …" без тегов — не трогаем, пусть парсер найдёт JSON
    return text.strip()


def _reasoning_tokens(usage: dict) -> int:
    details = usage.get("completion_tokens_details") or {}
    if not isinstance(details, dict):
        return 0
    try:
        return max(0, int(details.get("reasoning_tokens") or 0))
    except (TypeError, ValueError):
        return 0


def _is_length(usage: dict) -> bool:
    return str(usage.get("_llm_finish_reason") or "").strip().lower() == "length"


def _model_spec(payload: dict) -> str:
    """Модель: выбор из формы приоритетнее env NJ593_TRENDS_MODEL."""
    return (str(payload.get("model") or "").strip()[:120]
            or _env("NJ593_TRENDS_MODEL"))


# ---------------------------------------------------------------------------
# API сайта (токен воркера; ошибки — читаемым текстом)
# ---------------------------------------------------------------------------

async def _post_site(ctx, path: str, data: dict) -> tuple[bool, str, dict]:
    """POST на сайт. → (ok, причина, json-ответ). Две попытки на сеть."""
    return await site.post_json("NJ593_TRENDS", path, data)


async def _api_progress(ctx, review_id: int, phase: str) -> None:
    # Тот же этап — в журнал очереди (монитор nj120, «Мои задачи», статус-ряд).
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    await _post_site(ctx, "/progress", {"review_id": str(review_id), "phase": phase[:300]})


# ---------------------------------------------------------------------------
# «Сборка остановлена вручную» — не ошибка воркера
# ---------------------------------------------------------------------------

def _cancelled_reason(reason: str) -> bool:
    """Сайт отказал, потому что владелец прервал сборку («Прервать»).

    Отличаем отказ-по-отмене от настоящей ошибки: в этом случае воркер просто
    заканчивает работу без fail (задача уже снята на сайте), а не заваливает
    журнал стектрейсами.
    """
    r = (reason or "").lower()

    # «остановлен(а) вручную» — оба слова обязательны, иначе легко принять за
    # отмену обычную ошибку со словом «остановлен».
    return "вручную" in r and "остановлен" in r

async def _llm_watch(phase: str, factory, on_tick, every: float = 20.0,
                     limit: float | None = None) -> str:
    """Дождаться LLM-ответа, показывая «модель думает N с» вместо тишины.

    Раньше отбор и тексты — один вызов до 8 минут (timeout=480 c, 6000 токенов).
    Снаружи это выглядело «завис на этапе ИИ…»: пульс-то есть, а в статус-ряде
    висел один и тот же текст (владелец, 26.09: «завис… непонятно»). Теперь
    этап обновляется каждые `every` секунд и показывает лимит: видно и то, что
    процесс жив, и сколько ещё ждать максимум.

    Отмена (владелец нажал «Прервать») приходит как CancelledError — вызов
    гасим, чтобы не тянуть токены впустую.
    """
    engine = factory() if asyncio.iscoroutinefunction(factory) else factory
    task = asyncio.create_task(engine)
    t0 = time.monotonic()

    try:
        while True:
            left = None if not limit else max(0.0, float(limit) - (time.monotonic() - t0))
            done, _ = await asyncio.wait({task}, timeout=every if left is None else min(every, left))

            if done:
                break

            waited = int(time.monotonic() - t0)
            tail = f" (лимит {int(limit)} с)" if limit else ""

            if left is not None and left <= 0:
                # Жёсткий предел: клиент может не применить своё timeout, а обзор
                # «висеть» не должен. Гасим вызов и отдаём причину — вызывающий
                # код (curate) переходит к деградации или честной ошибке.
                task.cancel()
                # даём вызову короткую паузу, чтобы отмена дошла до сети
                await asyncio.wait({task}, timeout=2.0)

                raise asyncio.TimeoutError(
                    f"модель не ответила за {int(limit)} с — вызов прерван")

            try:
                await on_tick(f"{phase}: модель думает {waited} с{tail}…")
            except Exception:  # noqa: BLE001 — рапорт не важнее ответа модели
                pass

        return task.result()
    except BaseException:
        task.cancel()
        raise


# ---------------------------------------------------------------------------
# GitHub API
# ---------------------------------------------------------------------------

def _gh_headers() -> dict:
    h = {
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "n2j-trends-worker",
    }
    tok = _github_token()

    if tok:
        h["Authorization"] = f"Bearer {tok}"

    return h


async def _gh_search(since: str, min_stars: int, language: str,
                     per_page: int = 60, timeout: float = 20.0) -> tuple[list[dict], str]:
    """Свежие репозитории по звёздам. → (список, причина-ошибки)."""
    q = f"created:>{since} stars:>={min_stars}"

    if language:
        q += f" language:{language}"

    try:
        # asyncio.wait_for: httpx-таймаут не всегда гасит зависший коннект —
        # жёсткий дедлайн не даёт воркеру «застрять» на сетевом этапе.
        async def _get():
            async with httpx.AsyncClient(timeout=30) as client:
                r = await client.get(
                    f"{GH_API}/search/repositories",
                    params={"q": q, "sort": "stars", "order": "desc", "per_page": per_page},
                    headers=_gh_headers())
            return r

        r = await asyncio.wait_for(_get(), timeout=timeout)

        if r.status_code != 200:
            return [], f"GitHub HTTP {r.status_code}: {_readable_reason(r.text)}"

        items = (r.json() or {}).get("items") or []

        return items, ""
    except asyncio.TimeoutError:
        return [], f"GitHub не ответил за {timeout:.0f} с"
    except Exception as e:  # noqa: BLE001
        return [], f"GitHub сеть: {e.__class__.__name__}"


async def _gh_focus_search(query: str, since: str, per_page: int = 10,
                           timeout: float = 20.0) -> list[dict]:
    """Целевой поиск по фокусу читателя: ключевые слова + свежесть."""
    q = f"{query} created:>={since} stars:>=3"

    try:
        async def _get():
            async with httpx.AsyncClient(timeout=30) as client:
                r = await client.get(
                    f"{GH_API}/search/repositories",
                    params={"q": q, "sort": "best-match", "order": "desc", "per_page": per_page},
                    headers=_gh_headers())
            return r

        r = await asyncio.wait_for(_get(), timeout=timeout)

        if r.status_code != 200:
            log.warning("nj593: focus search HTTP %s", r.status_code)
            return []

        return (r.json() or {}).get("items") or []
    except asyncio.TimeoutError:
        log.warning("nj593: focus search timeout")
        return []
    except Exception as e:  # noqa: BLE001
        log.warning("nj593: focus search failed: %s", e.__class__.__name__)
        return []


async def _gh_readme(full_name: str, timeout: float = 15.0) -> str:
    """README репозитория (raw), обрезанный до капа. Пусто = нет/ошибка."""
    try:
        async def _get():
            async with httpx.AsyncClient(timeout=30, follow_redirects=True) as client:
                r = await client.get(
                    f"{GH_API}/repos/{full_name}/readme",
                    headers={**_gh_headers(), "Accept": "application/vnd.github.raw+json"})
            return r

        r = await asyncio.wait_for(_get(), timeout=timeout)

        if r.status_code != 200:
            return ""

        text = r.text
    except Exception:  # noqa: BLE001 — README не критичен, есть description
        return ""

    return text[:_readme_cap()]


async def _gh_readmes_parallel(full_names: list[str], budget_sec: float = 40.0) -> dict[str, str]:
    """README пачкой с общим дедлайном. Ключ — full_name (в нижнем регистре).

    Последовательное чтение до 30 README (каждый до 30 с) в тихий день
    «подвешивало» воркер на десятки минут. Один общий бюджет держит этап
    предсказуемым, а параллельное чтение отдаёт максимум README за это время.
    """
    sem = asyncio.Semaphore(5)
    out: dict[str, str] = {}

    async def one(name: str) -> None:
        async with sem:
            text = await _gh_readme(name)
        if text:
            out[name.lower()] = text

    try:
        await asyncio.wait_for(
            asyncio.gather(*(one(n) for n in full_names)), timeout=budget_sec)
    except asyncio.TimeoutError:
        log.warning("nj593: README не успели за %.0f с — продолжаем с тем, что есть", budget_sec)
    except Exception as e:  # noqa: BLE001 — чтение README не должно ронять обзор
        log.warning("nj593: чтение README: %s", e.__class__.__name__)

    return out


# ---------------------------------------------------------------------------
# LLM: отбор интересного и тексты
# ---------------------------------------------------------------------------

SYSTEM_CURATOR = (
    "Ты — редактор ежедневного тех-дайджеста по GitHub для русскоязычных "
    "разработчиков. Отбираешь только ИНТЕРЕСНОЕ: новизна, практическая польза, "
    "оригинальность подхода; форки и учебные проекты — мимо. Пишешь сухо и "
    "конкретно, только факты из присланных данных, БЕЗ выдумок. Отвечаешь "
    "СТРОГО одним JSON-объектом без markdown-заборов."
)


def _candidates_prompt(cands: list[dict], count: int, focus: str, readme_cap: int | None = None) -> str:
    # Бюджет промпта: рассуждающие модели (Qwen3) тратят токены на <think>, и большой
    # промпт + 4500 токенов ответа могут не влезть в контекст 8K. Обрезаем README
    # и уменьшаем число кандидатов, если промпт слишком длинный.
    cap = int(readme_cap) if readme_cap else 1200
    lines = []

    for c in cands:
        lines.append(
            f"- full_name: {c['full_name']}\n"
            f"  language: {c.get('language') or '-'}; stars: {c.get('stars')}; forks: {c.get('forks')}\n"
            f"  created: {(c.get('created_at') or '')[:10]}; topics: {', '.join((c.get('topics') or [])[:6]) or '-'}\n"
            f"  description: {(c.get('description') or '-')[:300]}\n"
            f"  README (начало): {(c.get('readme') or '-')[:cap]}")

    focus_line = (
        f"\nФОКУС ЧИТАТЕЛЯ (ОБЯЗАТЕЛЬНЫЙ): {focus}\n"
        "Включай ТОЛЬКО репозитории, реально относящиеся к фокусу: лучше меньше "
        'позиций, чем нерелевантные. Каждой позиции добавь поле "on_focus": '
        "true/false — относится ли она к фокусу. Если НИ ОДИН кандидат не "
        "относится к фокусу — верни пустой items и объясни это в intro.\n"
        if focus else "")

    return (
        f"Кандидаты (свежие репозитории GitHub, отсортированы по звёздам):\n\n"
        + "\n".join(lines)
        + focus_line
        + f"\n\nОтбери НЕ БОЛЕЕ {count} самых интересных. Верни СТРОГО JSON-объект:\n"
        + '{"title": "тема дня одной строкой по-русски", '
        + '"intro": "вступление обзора 2-3 предложения по-русски: что сегодня в трендах", '
        + '"items": [{"full_name": "owner/repo из списка", "score": 1-100, '
        + '"title": "короткий заголовок по-русски", '
        + '"what": "что это, 1-2 предложения по-русски", '
        + '"why": "почему попало в обзор: новизна или польза, 1-2 предложения", '
        + '"use_for": "кому и для чего пригодится, одно предложение", '
        + '"apply": "как применить с пользой или какую новую возможность это открывает, 1-2 предложения по-русски", '
        + '"topics": ["тег", "тег"]}]}'
    )


def _parse_result(raw: str) -> dict:
    """JSON от LLM: штатный разбор → ремонт → аварийный salvage."""
    raw = _strip_think(raw or "")
    j = webread.json_from_text(raw)

    if isinstance(j, dict) and isinstance(j.get("items"), list) and j["items"]:
        return j

    # Иногда модель отвечает без обёртки title/intro — только массив items
    if isinstance(j, dict) and isinstance(j.get("items"), list):
        # пустой items — это валидный ответ "ничего не нашлось" (см. _llm_answered)
        if j.get("items") == []:
            return j

    # salvage: тянем массив items регэкспом до конца текста
    m = re.search(r'"items"\s*:\s*\[', raw or "")

    if m:
        tail = raw[m.end() - 1:]
        tail = re.sub(r"`\s*$", "", tail).strip()

        try:
            arr = __import__("json").loads(tail.rstrip("`") if tail.endswith("`") else tail)

            if isinstance(arr, list) and arr:
                return {"title": "", "intro": "", "items": arr}
        except Exception:  # noqa: BLE001
            pass

    return {}


def _norm_items(parsed: dict, by_name: dict, count: int,
                skip_off_focus: bool = False) -> list[dict]:
    """Отфильтровать/нормализовать позиции: только известные full_name,
    без дублей, по убыванию score; при фокусе — без on_focus=false."""
    out: list[dict] = []
    seen = set()

    for it in parsed.get("items", [])[:count * 2]:
        if not isinstance(it, dict):
            continue

        if skip_off_focus and it.get("on_focus") is False:
            continue

        full = str(it.get("full_name") or "").strip().lower()

        if full not in by_name or full in seen:
            continue

        seen.add(full)
        src = by_name[full]
        topics = [str(t)[:30] for t in (it.get("topics") or []) if str(t).strip()][:4]
        out.append({
            "full_name": src["full_name"],
            "url": src.get("html_url") or f"https://github.com/{src['full_name']}",
            "description": str(src.get("description") or "")[:1000],
            "language": str(src.get("language") or ""),
            "stars": int(src.get("stargazers_count") or 0),
            "forks": int(src.get("forks_count") or 0),
            "gh_created_at": str(src.get("created_at") or ""),
            "topics": topics or [str(t) for t in (src.get("topics") or [])[:4]],
            "title": str(it.get("title") or "")[:300],
            "what": str(it.get("what") or "")[:2000],
            "why": str(it.get("why") or "")[:2000],
            "use_for": str(it.get("use_for") or "")[:1000],
            "apply": str(it.get("apply") or "")[:1500],
            "score": max(0, min(100, int(it.get("score") or 0))),
        })

    out.sort(key=lambda x: x["score"], reverse=True)

    return out[:count]


def _llm_answered(raw: str) -> bool:
    """Модель ответила JSON-объектом (пусть даже с пустым `items`).

    Нужно, чтобы отличать «модель посмотрела и не нашла ничего» (валидное
    «Готово» с объяснением) от «модель не ответила / ответ нечитаем» (тогда
    честнее сказать «ИИ-разбор не удался»).
    """
    return isinstance(webread.json_from_text(_strip_think(raw or "")), dict)


def _llm_err_text(e: BaseException, limit: float) -> str:
    """Короткая причина отказа ИИ для сводки обзора (без стектрейсов)."""
    name = e.__class__.__name__

    if isinstance(e, (asyncio.TimeoutError, TimeoutError)) or "Timeout" in name:
        return f"модель не ответила за {int(limit)} с"

    text = str(e).strip().replace("\n", " ")[:160]

    return f"{name}: {text}" if text else name


class _AiSkipped(Exception):
    """Служебный сигнал: ИИ-этап сознательно пропущен (модель недоступна)."""


def _fallback_items(cands: list[dict], count: int) -> list[dict]:
    """Позиции БЕЗ ИИ: факты GitHub + описание автора.

    Зачем: если модель не ответила за отведённый бюджет, обзор всё равно должен
    получиться — иначе владелец опять увидит «висит» или непонятную ошибку.
    Ничего не выдумываем: берём верхние по звёздам репозитории и их описание.
    В заголовке/сводке обзор честно помечается как собранный без ИИ-разбора.
    """
    out: list[dict] = []
    seen: set[str] = set()

    for c in sorted(cands, key=lambda x: int(x.get("stargazers_count") or 0), reverse=True):
        full = str(c.get("full_name") or "").strip()

        if not full or full.lower() in seen:
            continue

        seen.add(full.lower())
        desc = str(c.get("description") or "").strip()
        out.append({
            "full_name": full,
            "url": c.get("html_url") or f"https://github.com/{full}",
            "description": desc[:1000],
            "language": str(c.get("language") or ""),
            "stars": int(c.get("stargazers_count") or 0),
            "forks": int(c.get("forks_count") or 0),
            "gh_created_at": str(c.get("created_at") or ""),
            "topics": [str(t) for t in (c.get("topics") or [])[:4]],
            "title": (desc or full)[:300],
            "what": desc[:2000],
            "why": "Свежий репозиторий с заметным интересом на GitHub за выбранный период.",
            "use_for": "",
            "apply": "",
            "score": 0,
        })

        if len(out) >= count:
            break

    return out


# ---------------------------------------------------------------------------
# Обработчик канала nj593.trends
# ---------------------------------------------------------------------------

async def handle(ctx, payload: dict) -> dict:
    review_id = int(payload.get("review_id") or 0)
    code = str(payload.get("code") or "")
    rdate = str(payload.get("date") or date.today().isoformat()).strip()
    period = max(1, min(7, int(payload.get("period") or 1)))
    count = max(3, min(_max_items(), int(payload.get("count") or _max_items())))
    language = str(payload.get("language") or "").strip()
    min_stars = max(10, int(payload.get("min_stars") or _min_stars_default()))
    focus = str(payload.get("focus") or "").strip()[:500]
    model_spec = _model_spec(payload)

    try:
        d0 = datetime.strptime(rdate, "%Y-%m-%d").date()
    except ValueError:
        d0 = date.today()

    since = (d0 - timedelta(days=period)).isoformat()

    sink: list[dict] = []
    usage_calls: list[dict] = []

    async def _chat(messages: list[dict], phase: str, max_tokens: int,
                    timeout: float = 240.0, *, think: bool | None = None) -> str:
        """LLM-вызов: фактическая модель и подмены — в sink (как в nj590).

        think: None — авто (глушим размышления, если модель рассуждающая);
               True — не глушить (оставить как есть); False — глушить явно.
        """
        t0 = time.monotonic()
        opts = {}
        # По умолчанию глушим размышления: Qwen3 тратит половину бюджета на <think>
        if think is False or think is None:
            opts.update(_think_options())

        async def _ask():
            try:
                return await ctx.llm.chat_with_usage(messages, model=model_spec or None,
                                                     max_tokens=max_tokens,
                                                     request_timeout=timeout, **opts)
            except Exception as e:
                # Шлюз не знает полей отключения мышления → повтор без них
                if opts and ("400" in str(e) or "422" in str(e) or "reasoning" in str(e).lower()):
                    log.info("nj593[%s]: %s не принял отключение мышления — повтор без него", review_id, phase)
                    return await ctx.llm.chat_with_usage(messages, model=model_spec or None,
                                                         max_tokens=max_tokens,
                                                         request_timeout=timeout)
                raise

        # ждём ответ с живым этапом (иначе долгий вызов = «завис» в UI)
        text, usage = await _llm_watch(
            phase, _ask, on_tick=lambda note: _api_progress(ctx, review_id, note),
            limit=timeout)
        # рассуждающая модель могла отдать размышления отдельно — склеиваем для логов
        dt = int((time.monotonic() - t0) * 1000)
        pt = int(usage.get("prompt_tokens") or 0)
        ct = int(usage.get("completion_tokens") or 0)
        rs = _reasoning_tokens(usage)
        model_real = str(usage.get("_llm_model") or "") or (model_spec or "default")
        note = str(usage.get("_llm_note") or "")
        if rs:
            note = (note + "; " if note else "") + f"reasoning {rs} tok"
        if _is_length(usage):
            note = (note + "; " if note else "") + "обрыв по лимиту"
        log.info("nj593[%s] model=%s%s %d+%d tok, %d ms",
                 phase, model_real, (" — " + note) if note else "", pt, ct, dt)
        sink.append({"review_id": review_id, "phase": phase, "model": model_real,
                     "prompt_tokens": pt, "completion_tokens": ct,
                     "duration_ms": dt, "note": note,
                     "reasoning_tokens": rs, "finish_reason": usage.get("_llm_finish_reason")})
        usage_calls.append({"phase": phase, "model": model_real,
                            "prompt_tokens": pt, "completion_tokens": ct, "reasoning_tokens": rs})

        return text

    # --- 1. поиск -----------------------------------------------------------
    await _api_progress(ctx, review_id,
                        f"Ищу репозитории, созданные после {since}"
                        + (f" (язык: {language})" if language else "") + "…")

    cands, why = await _gh_search(since, min_stars, language)

    # --- 1b. фокус читателя: ИИ-перевод интереса в запросы GitHub + целевой поиск
    # (найдено 21.09: «openscad конструкторские отчёты» без целевого поиска давал
    #  «вообще не то» — дайджест набирался просто популярными свежими репо)
    focus_queries: list[str] = []

    if focus:
        await _api_progress(ctx, review_id, "Уточняю фокус читателя через ИИ…")

        try:
            raw_q = await _chat(
                [{"role": "system", "content": (
                    "Ты превращаешь интерес читателя (по-русски, свободная форма) в "
                    "1-3 поисковых запроса для GitHub: латиница, английские ключевые "
                    "слова, без кавычек и квалификаторов вида language:. Верни СТРОГО "
                    'JSON: {"queries": ["...", "..."]}')},
                 {"role": "user", "content": f"Интерес читателя: {focus}"}],
                "focus-queries", max_tokens=200, timeout=120)
            j_q = webread.json_from_text(raw_q)

            if isinstance(j_q, dict):
                focus_queries = [str(x).strip()[:100]
                                 for x in (j_q.get("queries") or []) if str(x).strip()][:3]
        except Exception as e:  # noqa: BLE001
            log.warning("nj593: focus queries: %s", e.__class__.__name__)

        seen_names = {str(c.get("full_name") or "").lower() for c in cands}

        for fq in focus_queries:
            await _api_progress(ctx, review_id, f"Ищу по фокусу: {fq}…")

            for s_found in await _gh_focus_search(fq, since):
                nm = str(s_found.get("full_name") or "").lower()

                if nm and nm not in seen_names:
                    seen_names.add(nm)
                    cands.append(s_found)

    if not cands:
        # «Тихий» день: свежих репозиториев с заданным минимумом звёзд может и
        # не быть (за сутки «звёздных» новинок ≠ много). Вместо вечного retry
        # ослабляем фильтр пошагово: звёзды → звёзды+окно → окно без звёзд.
        await _api_progress(ctx, review_id, "Свежих звёздных репозиториев нет — расширяю охват…")

        tries = [(min_stars, since, f"звёзды ≥ {min_stars}")]
        if min_stars > 10:
            tries.append((10, since, "звёзды ≥ 10"))
        since_looser = (d0 - timedelta(days=max(period + 2, min(7, period * 2)))).isoformat()
        tries.append((10, since_looser, "окно шире, звёзды ≥ 10"))
        tries.append((0, since_looser, "окно шире, без минимума звёзд"))

        for stars_try, since_try, what_try in tries:
            if cands:
                break
            await _api_progress(ctx, review_id, f"Пробую снова: {what_try}…")
            added, why = await _gh_search(since_try, stars_try, language, per_page=60)
            cands = added
            log.info("nj593[%s]: ослабление фильтра (%s) → %d кандидатов",
                     review_id, what_try, len(added))

        if not cands:
            raise RuntimeError(
                f"GitHub не вернул кандидатов: {why or 'пустой ответ'}. "
                "Даже без минимума звёзд за расширенное окно свежих "
                "репозиториев нет — попробуйте позже.")

    # Ограничиваем вход LLM, чтобы промпт влез в контекст рассуждающей модели (8K).
    # Раньше 30 кандидатов × 1200 README ≈ 36K символов — модель уходит в обрыв или таймаут.
    cands = cands[: max(count * 2, 12)]
    if len(cands) > 15:
        cands = cands[:15]
    by_name = {str(c.get("full_name") or "").lower(): c for c in cands}

    # --- 2. README ----------------------------------------------------------
    await _api_progress(ctx, review_id,
                        f"Найдено {len(cands)} — читаю README самых интересных…")

    names = [str(c.get("full_name") or "") for c in cands]
    readmes = await _gh_readmes_parallel(names)

    for c in cands:
        c["readme"] = readmes.get(str(c.get("full_name") or "").lower(), "")

    # --- 3. LLM-отбор и тексты ---------------------------------------------
    await _api_progress(ctx, review_id, "ИИ отбирает интересное и пишет тексты…")

    curate_timeout = _curate_timeout()
    ai_offline    = False       # модель не ответила/ответила мусором
    empty_reason  = ""          # модель ответила, но подходящего не нашлось
    llm_err       = ""          # причина, если модель не ответила (для сводки)
    answered      = False       # модель вернула JSON-объект (даже с пустым items)

    # Вспомогательный вызов, который возвращает и текст, и usage (с отключённым мышлением)
    async def _chat_with_usage(prompt_text: str, phase: str, max_tokens: int, timeout: float):
        t0 = __import__("time").monotonic()
        opts = _think_options()
        async def _ask():
            try:
                return await ctx.llm.chat_with_usage(
                    [{"role": "system", "content": SYSTEM_CURATOR},
                     {"role": "user", "content": prompt_text}],
                    model=model_spec or None, max_tokens=max_tokens, request_timeout=timeout, **opts)
            except Exception as e2:
                err_text = str(e2)
                # 400/422 — провайдер не знает полей отключения размышлений;
                # TypeError «unexpected keyword» — клиент LLM без проксирования
                # options (свой шлюз, тестовый двойник): тоже повторяем без них.
                if opts and ("400" in err_text or "422" in err_text
                             or "unexpected keyword" in err_text):
                    return await ctx.llm.chat_with_usage(
                        [{"role": "system", "content": SYSTEM_CURATOR},
                         {"role": "user", "content": prompt_text}],
                        model=model_spec or None, max_tokens=max_tokens, request_timeout=timeout)
                raise
        text, usage = await _llm_watch(phase, _ask, on_tick=lambda note: _api_progress(ctx, review_id, note), limit=timeout)
        dt = int((__import__("time").monotonic() - t0) * 1000)
        pt = int(usage.get("prompt_tokens") or 0)
        ct = int(usage.get("completion_tokens") or 0)
        rs = _reasoning_tokens(usage)
        model_real = str(usage.get("_llm_model") or "") or (model_spec or "default")
        note = str(usage.get("_llm_note") or "")
        if rs:
            note = (note + "; " if note else "") + f"reasoning {rs} tok"
        if _is_length(usage):
            note = (note + "; " if note else "") + "обрыв по лимиту"
        log.info("nj593[%s] model=%s%s %d+%d tok, %d ms", phase, model_real, (" — " + note) if note else "", pt, ct, dt)
        sink.append({"review_id": review_id, "phase": phase, "model": model_real, "prompt_tokens": pt, "completion_tokens": ct, "duration_ms": dt, "note": note, "reasoning_tokens": rs, "finish_reason": usage.get("_llm_finish_reason")})
        usage_calls.append({"phase": phase, "model": model_real, "prompt_tokens": pt, "completion_tokens": ct, "reasoning_tokens": rs})
        return text, usage

    # Бюджет промпта: если кандидатов много, ужимаем README, иначе контекст 8K не вместит
    def _prompt_for(cur_list: list[dict], rc: int = 800) -> str:
        p = _candidates_prompt(cur_list, count, focus, readme_cap=rc)
        if len(p) > 18000 and len(cur_list) > 8:
            p = _candidates_prompt(cur_list[:8], count, focus, readme_cap=min(rc, 600))
        return p

    last_usage: dict = {}
    raw = ""
    try:
        prompt = _prompt_for(cands, rc=900 if len(cands) > 10 else 1200)
        raw, last_usage = await _chat_with_usage(prompt, "curate", max_tokens=6000, timeout=curate_timeout)
    except Exception as e:  # noqa: BLE001 — ИИ не ответила: не висим, а деградируем
        llm_err = _llm_err_text(e, curate_timeout)
        log.warning("nj593[%s]: curate не удался: %s", review_id, llm_err)
        raw = ""

    # Если raw получен, но usage говорит об обрыве по лимиту — пометим для ретрая
    _curate_retry_needed = False
    if raw and _is_length(last_usage):
        rs = _reasoning_tokens(last_usage)
        log.warning("nj593[%s]: curate оборван по лимиту (reasoning=%s, tokens=%s/%s) — пробую с большим бюджетом", review_id, rs, last_usage.get("completion_tokens"), 6000)
        _curate_retry_needed = True
    elif raw and _reasoning_tokens(last_usage) > 2000:
        log.warning("nj593[%s]: curate потратил %s токенов на размышления — повтор с отключённым мышлением", review_id, _reasoning_tokens(last_usage))
        _curate_retry_needed = True

    answered = _llm_answered(raw)
    parsed = _parse_result(raw)

    if not parsed.get("items") or _curate_retry_needed:
        # Второй заход: дешевле и короче (малые модели любят мусор вокруг JSON).
        # Именно здесь раньше терялись минуты: две попытки по 480 с = до 16 минут
        # «ИИ отбирает…» без движения.
        # При обрыве — увеличиваем бюджет и уменьшаем промпт, при размышлениях — глушим их
        retry_tokens = 7000 if _curate_retry_needed else 3500
        retry_prompt = _prompt_for(cands[:min(len(cands), count * 2)], rc=600)
        if _curate_retry_needed and len(retry_prompt) > 12000:
            retry_prompt = _prompt_for(cands[:8], rc=500)
        try:
            retry_prompt_full = retry_prompt + "\n\nПРЕДЫДУЩИЙ ОТВЕТ БЫЛ НЕ JSON ИЛИ ОБОРВАН. Верни ТОЛЬКО JSON-объект, без текста вокруг. Пиши кратко: по 1 предложению в каждом поле."
            raw2, _u2 = await _chat_with_usage(retry_prompt_full, "curate-retry", max_tokens=retry_tokens, timeout=_curate_retry_timeout())
            # Если и тут обрыв — не мучаем модель, уходим в fallback
            if _is_length(_u2) and not raw2:
                log.warning("nj593[%s]: curate-retry тоже оборван — ухожу в fallback", review_id)
            else:
                # Обновляем last_usage для последующей диагностики
                last_usage = _u2
                answered = answered or _llm_answered(raw2)
                parsed2 = _parse_result(raw2)
                if parsed2.get("items"):
                    parsed = parsed2
                    raw = raw2
                elif not parsed.get("items"):
                    parsed = parsed2
                    raw = raw2
        except Exception as e:  # noqa: BLE001
            llm_err = _llm_err_text(e, _curate_retry_timeout())
            log.warning("nj593[%s]: curate-retry не удался: %s", review_id, llm_err)
            if not parsed.get("items"):
                parsed = {}

    items = _norm_items(parsed, by_name, count, bool(focus)) if parsed.get("items") else []

    if not items and focus and answered:
        # Фокус — жёсткое требование: показывать «что попало» вместо релевантного
        # нельзя. Это ВАЛИДНЫЙ финал («ничего не нашлось»), а не ошибка воркера.
        # ВАЖНО: только если модель ВООБЩЕ ответила (`parsed`) — иначе честнее
        # сказать «ИИ-разбор не удался», а не «ничего не нашлось».
        empty_reason = (
            f"По фокусу «{focus}» среди свежих репозиториев GitHub ничего "
            f"релевантного не нашлось (запросы: {', '.join(focus_queries) or '—'}). "
            "Переформулируйте фокус или увеличьте период.")
        log.info("nj593[%s]: %s", review_id, empty_reason)

    if not items and not empty_reason:
        # Модель молчит или отвечает мусором: НЕ висим и НЕ падаем — собираем
        # обзор по данным GitHub и честно помечаем это в сводке.
        ai_offline = True
        items = _fallback_items(cands, count)

        llm_err = llm_err or (f"модель не дала разбор за {int(curate_timeout)} с "
                             "(ответ не получен или нечитаем)")
        log.warning("nj593[%s]: ИИ-отбор не удался (%s) — обзор собран по данным "
                    "GitHub, позиций: %d", review_id, llm_err, len(items))

    if not items and not empty_reason:
        raise RuntimeError(
            "GitHub не дал пригодных кандидатов для обзора — попробуйте "
            "увеличить период или снизить минимум звёзд.")

    # --- 3а. «Как применить» — обязательная строка у каждой позиции ---------
    # Владелец: «куда дел "Как применить" — верни, это самое интересное!».
    # Малая модель иногда не заполняет поле `apply`: делаем ОДИН целевой запрос
    # только за недостающими строками, а если и он не помог — подставляем
    # честную практическую подсказку. Без блока применения позиция не выходит.
    if items and not ai_offline:
        missing = [it for it in items if not str(it.get("apply") or "").strip()]

        if missing:
            await _api_progress(ctx, review_id,
                                f"Дописываю «Как применить»: {len(missing)} из {len(items)}")

            try:
                raw_a = await _chat(
                    [{"role": "system", "content": (
                        "Ты — редактор тех-дайджеста. Для каждого проекта напиши, "
                        "КАК им применить на практике: один конкретный шаг, 1-2 "
                        "предложения по-русски, без воды и без повторов описания. "
                        "Отвечаешь СТРОГО JSON-объектом вида "
                        '{"apply": {"владелец/репозиторий": "текст"}}')},
                     {"role": "user", "content": "Проекты:\n" + "\n".join(
                         f"- {it['full_name']} — {it.get('title') or ''}: "
                         f"{(it.get('what') or it.get('description') or '')[:250]}"
                         f" (кому пригодится: {(it.get('use_for') or '')[:120]})"
                         for it in missing)}],
                    "apply", max_tokens=1200, timeout=_apply_timeout())

                j_a = webread.json_from_text(raw_a)
                mapping = j_a.get("apply") if isinstance(j_a, dict) else None

                if isinstance(mapping, dict):
                    for it in missing:
                        txt = str(mapping.get(it["full_name"]) or "").strip()
                        if txt:
                            it["apply"] = txt[:1500]
            except Exception as e:  # noqa: BLE001 — строка применения не критична
                log.warning("nj593[%s]: добор «как применить»: %s",
                            review_id, e.__class__.__name__)

    # Подсказка — последний рубеж для ЛЮБОГО пути: и когда ИИ молчала, и когда
    # отбор прошёл без строк применения. Блок «Как применить» не бывает пустым.
    for it in items:
        if not str(it.get("apply") or "").strip():
            it["apply"] = _APPLY_HINT
            log.info("nj593[%s]: %s — строка «как применить» подставлена "
                     "подсказкой", review_id, it["full_name"])

    # --- 3б. «Ничего не нашлось»: валидный финал вместо ошибки ---------------
    # Владелец: «может не нашлось ничего… — непонятно». Теперь это «Готово»
    # с объяснением в сводке и без выдуманных позиций.
    if empty_reason:
        await _api_progress(ctx, review_id, "Подходящих репозиториев нет — завершаю обзор")

        ok, why_e, _ = await _post_site(ctx, "/complete", {
            "review_id":   str(review_id),
            "title":       f"Обзор трендов GitHub за {d0.strftime('%d.%m.%Y')}",
            "summary":     empty_reason,
            "model_used":  (sink[-1]["model"] if sink else "") or model_spec,
        })

        if not ok and _cancelled_reason(why_e):
            return {"review_id": review_id, "code": code, "cancelled": True, "items": 0,
                    "usage": usage_calls}

        if not ok:
            raise RuntimeError(f"Сайт не принял завершение обзора — {why_e}")

        await _api_progress(ctx, review_id, "Обзор завершён: подходящего не нашлось")

        return {
            "review_id": review_id,
            "code": code,
            "items": 0,
            "empty_ok": True,
            "why": empty_reason,
            "model_used": (sink[-1]["model"] if sink else "") or model_spec,
            "usage": usage_calls,
        }

    # --- 4. выгрузка позиций ------------------------------------------------
    # фактическая модель уже известна (LLM-отбор случился) — едет с каждой позицией
    # (при деградации без ИИ модели нет: не приписываем ей чужие тексты)
    model_used = "" if ai_offline else ((sink[-1]["model"] if sink else "") or model_spec)
    rank = 0

    for it in sorted(items, key=lambda x: x["score"], reverse=True):
        rank += 1
        await _api_progress(ctx, review_id,
                            f"Публикую позиции… {rank}/{len(items)}: {it['full_name']}")

        ok, why2, _ = await _post_site(ctx, "/item", {
            "review_id": str(review_id),
            "rank": str(rank),
            "full_name": it["full_name"],
            "url": it["url"],
            "description": it["description"],
            "language": it["language"],
            "stars": str(it["stars"]),
            "forks": str(it["forks"]),
            "gh_created_at": it["gh_created_at"],
            "topics": __import__("json").dumps(it["topics"], ensure_ascii=False),
            "title": it["title"],
            "what": it["what"],
            "why": it["why"],
            "use_for": it["use_for"],
            "apply": it["apply"],
            "score": str(it["score"]),
            **({"model_used": model_used} if model_used else {}),
        })

        if not ok:
            # Владелец нажал «Прервать» (сайт снял задачу): продолжаться смысла
            # нет — прекращаем сразу и возвращаемся без ошибки в журнале.
            if _cancelled_reason(why2):
                log.warning("nj593[%s]: сборка прервана владельцем — остановился "
                            "на позиции %d", review_id, rank - 1)
                return {
                    "review_id": review_id,
                    "code": code,
                    "cancelled": True,
                    "items": rank - 1,
                    "model_used": model_used,
                    "usage": usage_calls,
                }

            raise RuntimeError(f"Позиция {rank} ({it['full_name']}): сайт не принял — {why2}")

    # --- 5. выводы дня ------------------------------------------------------
    # «Что взять в работу» — короткая сводка применения отобранных позиций.
    # Best-effort: если LLM/JSON не получится, обзор выходит без выводов
    # (takeaways=""), а не падает — тексты позиций важнее блока выводов.
    takeaways = ""

    if ai_offline:
        # модель не ответила уже на отборе — ещё один бюджет на «выводы» не тратим
        log.info("nj593[%s]: выводы дня пропущены (ИИ-отбор не удался)", review_id)

    try:
        if ai_offline:
            raise _AiSkipped()

        await _api_progress(ctx, review_id, "Формулирую выводы дня…")

        raw_t = await _chat(
            [{"role": "system", "content": (
                "Ты — редактор тех-дайджеста. По списку отобранных проектов дня "
                "напиши 2-4 коротких ПРАКТИЧЕСКИХ вывода: что из этого стоит "
                "внедрить/попробовать и какую возможность это открывает. Без "
                "воды, без повторов. Отвечаешь СТРОГО JSON: "
                '{"takeaways": ["вывод 1", "вывод 2"]}')},
             {"role": "user", "content": "Позиции дня:\n" + "\n".join(
                 f"- {it['full_name']} — {it.get('title') or ''}: "
                 f"{(it.get('what') or '')[:250]}"
                 for it in items)}],
            "takeaways", max_tokens=800, timeout=_takeaways_timeout())

        j_t = webread.json_from_text(raw_t)

        if isinstance(j_t, dict) and isinstance(j_t.get("takeaways"), list):
            lines = [str(x).strip() for x in j_t["takeaways"] if str(x).strip()][:4]
            takeaways = "\n".join("• " + ln for ln in lines)
    except _AiSkipped:
        pass                              # ожидаемо: ИИ недоступна, выводы просто нет
    except Exception as e:  # noqa: BLE001 — выводы не критичны, обзор без них валиден
        log.warning("nj593[%s]: takeaways: %s", review_id, e.__class__.__name__)

    # --- 6. завершение ------------------------------------------------------
    title = str(parsed.get("title") or "").strip()[:300]
    intro = str(parsed.get("intro") or "").strip()
    model_used = "" if ai_offline else ((sink[-1]["model"] if sink else model_spec) or model_spec)

    if ai_offline:
        # Честная пометка: обзор собран по данным GitHub, ИИ-разбор не удался.
        title = title or f"Обзор трендов GitHub за {d0.strftime('%d.%m.%Y')}"
        intro = (f"⚠ ИИ-разбор не удался: {llm_err}. Ниже — свежие репозитории "
                 "по данным GitHub (описания от авторов), без ИИ-пересказа. "
                 "Повторите позже или выберите другую модель.")
        complete_data = {
            "review_id": str(review_id),
            "title": title[:300],
            "summary": intro,
            "model_used": "",
        }
    else:
        complete_data = {
            "review_id": str(review_id),
            "title": title or f"Обзор трендов GitHub за {d0.strftime('%d.%m.%Y')}",
            "summary": intro,
            "model_used": model_used,
        }

    if takeaways:
        complete_data["takeaways"] = takeaways

    ok, why3, _ = await _post_site(ctx, "/complete", complete_data)

    if not ok and _cancelled_reason(why3):
        # обзор остановлен вручную — это не ошибка воркера
        log.warning("nj593[%s]: завершение не принято — сборка остановлена владельцем",
                    review_id)
        return {
            "review_id": review_id,
            "code": code,
            "cancelled": True,
            "items": rank,
            "model_used": model_used,
            "usage": usage_calls,
        }

    if not ok:
        raise RuntimeError(f"Сайт не принял завершение обзора — {why3}")

    await _api_progress(ctx, review_id, "Обзор опубликован")

    out = {
        "review_id": review_id,
        "code": code,
        "title": title,
        "items": rank,
        "model_used": model_used,
        "ai_offline": ai_offline,
        "usage": usage_calls,
    }

    if ai_offline:
        # Итог честно помечен и в статусе задачи: «Готово» на /trends не должно
        # выглядеть как разбор моделью, если модель молчала (владелец: «где
        # включается эвристика?»).
        out["note"] = ("ИИ не дал разбор — обзор собран по данным GitHub "
                       "(без модели), проверьте позиции")

    return out
