"""nj591.profile — «Профиль компании» (N2J.ru /company).

Пайплайн: домен или название → webread читает сайт (Markdown ≤ cap) → малая LLM: строгий JSON профиля → POST /company/api/item.

Ввод: домен или название компании. Конвейер (общий инструмент webread —
курс на малые LLM: чистый Markdown, минимум токенов, ноль GPU):
  1. домен? иначе DDG-поиск официального сайта по названию (агрегаторы — мимо);
  2. Главная (всегда) + до 4 страниц «О нас / Контакты / Команда / Реквизиты»;
  3. каждая страница → Markdown ≤2200 знаков (trafilatura); стек —
     детерминированно по разметке (webread.tech_hints, без LLM);
  4. ОДИН LLM-вызов со строгой JSON-схемой; анти-галлюцинации: имена,
     цифры и контакты — только из текста страниц, нет данных → пусто/«не найдено»;
  5. результат → /company/api/result (X-Worker-Token), прогресс → /company/api/progress.

Env: NJ591_PROFILE_API_BASE (напр. https://n2j.ru/company/api),
NJ591_PROFILE_SITE_TOKEN (или общий WORKER_API_TOKEN),
NJ591_PROFILE_MODEL (спека «провайдер::модель» — для Gemma 12B и т.п.),
NJ591_PROFILE_MAX_PAGES (default 4), NJ591_PROFILE_MD_CAP (default 2200).
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import time
from urllib.parse import urlparse

from .. import site
from .. import webread
from . import nj642_vacancies

log = logging.getLogger("n2j.nj591")

CHANNEL = "nj591.profile"

# сайты-агрегаторы: официальным сайтом компании быть не могут
_BLOCKED_HOSTS = (
    "wikipedia.org", "wikimedia", "linkedin.com", "facebook.com", "fb.com",
    "instagram.com", "youtube.com", "twitter.com", "x.com", "vk.com", "ok.ru",
    "telegram.me", "t.me", "yandex.", "google.", "bing.com", "duckduckgo.com",
    "hh.ru", "superjob.ru", "zarplata.ru", "avito.ru", "flamp.ru",
    "2gis.ru", "zoon.ru", "yell.ru", "spravker.ru", "rusprofile.ru",
    "checko.ru", "list-org.com", "sbis.ru", "kartoteka.ru", "skrin.ru",
    "kontur.ru", "dadata.ru", "pinterest.", "tiktok.com", "glassdoor.",
    "indeed.com", "crunchbase.com", "b2b-center.ru", "audit-it.ru",
)


def _model() -> str:
    return os.getenv("NJ591_PROFILE_MODEL", "").strip()


def _max_pages() -> int:
    try:
        return max(1, min(6, int(os.getenv("NJ591_PROFILE_MAX_PAGES", "4"))))
    except ValueError:
        return 4


def _md_cap() -> int:
    try:
        return max(600, int(os.getenv("NJ591_PROFILE_MD_CAP", "2200")))
    except ValueError:
        return 2200


def _site_budget() -> float:
    """Сколько секунд всего отведено на чтение сайта компании.

    Живой случай (26.09, /company/cmp247d1f61): застрявший запрос к сайту
    оставлял задачу без пульса, страница показывала «нет пульса 5 мин» и
    спиннер. Пайплайн обязан ЗАКОНЧИТЬСЯ сам — с честным «сайт не отвечает»,
    а не ждать таймаута очереди (20 мин).
    """
    try:
        return max(30.0, float(os.getenv("NJ591_PROFILE_SITE_BUDGET", "180")))
    except ValueError:
        return 180.0


# ---------------------------------------------------------------------------
# Промпт (строгая JSON-схема; анти-галлюцинации — как в nj580)
# ---------------------------------------------------------------------------

SYSTEM_PROFILE = (
    "Ты собираешь профиль компании по текстам её сайта. Верни СТРОГО JSON без markdown, ровно по схеме: "
    '{"company": "название компании из текста", '
    '"sphere": "сфера деятельности одним предложением", '
    '"summary": "2-3 предложения: что за компания, чем выделяется", '
    '"size_estimate": "примерный размер (сотрудники/масштаб) по признакам в тексте, иначе строка «не найдено»", '
    '"contacts": {"emails": [], "phones": [], "address": "адрес одним строком или пусто"}, '
    '"tech_stack": [], '
    '"decision_makers": [{"name": "", "role": "", "source": "страница, где найдено"}], '
    '"confidence": "высокая|средняя|низкая"}. '
    "ЖЁСТКИЕ ПРАВИЛА: имена людей, цифры, email, телефоны и адрес — ТОЛЬКО если они есть в тексте страниц; "
    "ничего не додумывай и не ищи по памяти. Нет данных — пустой список/строка «не найдено». "
    "В tech_stack перечисляй только упомянутые в материалах технологии. Всё по-русски (кроме имён/названий)."
)


# ---------------------------------------------------------------------------
# Сайт: прогресс и результат
# ---------------------------------------------------------------------------

async def _api_progress(ctx, profile_id: int, phase: str) -> None:
    # Двойной адрес: своя колонка профиля (страница /company/<code>) и журнал
    # очереди (монитор nj120, «Мои задачи» — единый статус-ряд всех модулей).
    # Сбой журнала прогресс не отменяет: страница модуля важнее.
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    ok, reason, _j = await site.post_json("NJ591_PROFILE", "/progress",
                                          {"profile_id": str(profile_id), "phase": phase[:300]},
                                          timeout=30, retries=1)

    if not ok and not ("_API_BASE не задан" in reason or "токен сайта не задан" in reason):
        log.warning("nj591: api/progress -> %s", reason)


async def _api_result(ctx, profile_id: int, company: str, result: dict, sources: list) -> bool:
    ok, reason, _j = await site.post_json("NJ591_PROFILE", "/result", {
        "profile_id": str(profile_id),
        "company": company[:300],
        "result": json.dumps(result, ensure_ascii=False),
        "sources": json.dumps(sources, ensure_ascii=False),
    })
    return ok, reason


# ---------------------------------------------------------------------------
# Шаги конвейера
# ---------------------------------------------------------------------------

def _host_of(url: str) -> str:
    try:
        return (urlparse(url).hostname or "").lower()
    except ValueError:
        return ""


def _is_blocked_host(host: str) -> bool:
    h = host.lower().lstrip("www.")
    return any(b.strip().lower() in h for b in _BLOCKED_HOSTS if b.strip())


async def _resolve_site(kind: str, domain: str, name: str) -> str:
    """Базовый URL официального сайта. Название → DDG-поиск (бережно).
    Ошибка — с подробностями: что искали, сколько нашли, какие хосты отсеяли."""
    if kind == "domain" and domain:
        return f"https://{domain}"

    query = f'"{name}" официальный сайт'
    log.info("nj591: DDG-поиск официального сайта: %s", query)
    rows = await webread.ddg_text(query, max_results=8)

    if not rows:
        # Подробности — в журнал воркера; пользователю нужна короткая причина и
        # понятное действие (владелец: «подвисает, видимо если нет сайта, это
        # надо решить лаконично»).
        log.warning("nj591: DDG вернул 0 результатов по %r", query)
        raise webread.WebReadError(
            "Сайт компании не нашёлся в поиске — укажите домен вручную "
            "(например romashka.ru) и запустите сбор заново")

    seen_hosts: list[str] = []
    skipped: list[str] = []

    for r in rows:
        url = str(r.get("href") or r.get("url") or "").strip()
        host = _host_of(url)

        if not host:
            continue

        if host not in seen_hosts:
            seen_hosts.append(host)

        if _is_blocked_host(host):
            skipped.append(host)
            continue

        log.info("nj591: сайт компании по DDG: %s (%s)", host, url)
        return f"https://{host}/"

    log.info("nj591: официальный сайт не найден, отсеяны: %s", ", ".join(sorted(set(skipped))[:8]))
    raise webread.WebReadError(
        "Официальный сайт компании не найден (в выдаче только агрегаторы) — "
        "укажите домен вручную (например romashka.ru) и запустите сбор заново")


_PAGE_PATTERNS = [
    r"about|o-nas|о-нас|onas|company|kompan|компан|about-us|nash",
    r"contact|kontakt|контакт|contacts",
    r"team|komanda|команда|sotrud|сотруд|staff|people|rukovod|руковод",
    r"requisite|rekvizit|реквизит|info|onas",
]

# Страницы работодателя для сбора ВАКАНСИЙ (владелец, 26.09): «в обход страниц
# также смотри страницы „Карьера“ „Вакансии“ „О компании“ (для сбора информации
# о вакансиях с сайта работодателя)». Читаются отдельным проходом — их текст
# уходит не в профиль, а в модуль вакансий (nj642).
_VACANCY_PATTERNS = [
    r"vacanc|vakans|ваканс|job|jobs|rabota|работа|career|karier|карьер|hr|hrs",
    r"about|o-nas|о-нас|onas|company|kompan|компан|about-us",
]


def _max_vacancy_pages() -> int:
    try:
        return max(0, min(4, int(os.getenv("NJ591_PROFILE_VACANCY_PAGES", "3"))))
    except ValueError:
        return 3


async def _read_site(ctx, code: str, profile_id: int, base_url: str) -> dict:
    """Главная + «О нас / Контакты / Команда» + страницы работодателя.

    Возвращает корпус текста для LLM, источники, подсказки стека и страницы
    «Карьера / Вакансии / О компании» — их переиспользует модуль вакансий
    (nj642) сразу после сборки профиля.
    """
    home_html, _ = await webread.fetch(base_url)
    home_md, home_title = await webread.to_markdown(home_html, cap=_md_cap())
    hints = webread.tech_hints(home_html)
    sources = [{"url": base_url, "title": home_title, "chars": len(home_md)}]
    pages_md: list[str] = [f"## Главная: {base_url}\n{home_md}"]

    links = webread.extract_links(home_html, base_url, _PAGE_PATTERNS, limit=_max_pages())

    for i, link in enumerate(links):
        await _api_progress(ctx, profile_id, f"Читаю страницу {i + 2}: {link['label'] or link['url']}…")
        await ctx.heartbeat()

        try:
            html, _ = await webread.fetch(link["url"])
        except webread.WebReadError as e:
            log.info("nj591[%s] страница %s пропущена: %s", code, link["url"], e)
            continue

        md, title = await webread.to_markdown(html, cap=max(800, _md_cap() * 3 // 4))

        if len(md) < 60:
            continue

        pages_md.append(f"## {link['label'] or title}: {link['url']}\n{md}")
        sources.append({"url": link["url"], "title": title, "chars": len(md)})

    # Страницы работодателя («Карьера», «Вакансии», «О компании»): их текст нужен
    # не профилю, а вакансиям — поэтому собираем отдельно и не тратим на них
    # бюджет профиля.
    vacancy_pages: list[dict] = []
    seen: set[str] = {base_url}

    for link in webread.extract_links(home_html, base_url, _VACANCY_PATTERNS, limit=_max_vacancy_pages()):
        url = str(link.get("url") or "")

        if not url or url in seen:
            continue

        seen.add(url)

        try:
            html, _ = await webread.fetch(url)
        except webread.WebReadError as e:
            log.info("nj591[%s] страница вакансий %s пропущена: %s", code, url, e)
            continue

        md, title = await webread.to_markdown(html, cap=max(1200, _md_cap()))

        if len(md) < 60:
            continue

        vacancy_pages.append({"url": url, "label": str(link.get("label") or title or url), "md": md})

    return {
        "corpus": "\n\n".join(pages_md),
        "home_title": home_title,
        "hints": hints,
        "sources": sources,
        "vacancy_pages": vacancy_pages,
    }


async def handle(ctx, payload: dict) -> dict:
    profile_id = int(payload.get("profile_id") or 0)
    code = str(payload.get("code") or "")
    kind = str(payload.get("kind") or "domain")
    domain = str(payload.get("domain") or "").strip()
    name = str(payload.get("name") or "").strip()

    if not profile_id:
        raise ValueError("nj591: payload должен содержать profile_id")

    if ctx.llm is None:
        raise RuntimeError("nj591: LLM-провайдеры не настроены (workers/.env)")

    sink: list = []
    job_id = ctx._current_job["id"] if ctx._current_job else 0

    # --- 1. официальный сайт -------------------------------------------------
    await _api_progress(ctx, profile_id, "Определяю сайт компании…" if kind != "domain"
                        else f"Открываю {domain}…")
    await ctx.heartbeat()

    base_url = await _resolve_site(kind, domain, name)
    log.info("nj591[%s] сайт: %s", code, base_url)

    # --- 2. Главная + страницы «О нас / Контакты / Команда» -------------------
    await _api_progress(ctx, profile_id, "Читаю главную страницу…")
    await ctx.heartbeat()

    # Бюджет времени на весь обход сайта: сбор ОБЯЗАН закончиться сам — либо
    # профилем, либо честной причиной. Зависший запрос к чужому сайту больше не
    # оставляет задачу без пульса (живой случай 26.09: «подвисает, видимо если
    # нет сайта» — профиль висел 5+ минут и закрывался только авто-стопом).
    try:
        site_data = await asyncio.wait_for(
            _read_site(ctx, code, profile_id, base_url), timeout=_site_budget())
    except asyncio.TimeoutError:
        raise webread.WebReadError(
            "Сайт компании не отвечает — страницы не открылись за "
            f"{int(_site_budget())} с. Укажите домен вручную и запустите сбор заново")

    corpus = str(site_data["corpus"])
    home_title = str(site_data["home_title"])
    hints = list(site_data["hints"])
    sources = list(site_data["sources"])
    vacancy_pages = list(site_data["vacancy_pages"])

    if len(corpus) < 200:
        raise webread.WebReadError("На сайте компании почти нет текста — профиль собрать не из чего")

    # --- 3. один LLM-вызов ---------------------------------------------------
    await _api_progress(ctx, profile_id, "Собираю профиль (ИИ)…")
    await ctx.heartbeat()

    user = ("Тексты страниц сайта компании:\n\n" + corpus[:24000]
            + ("\n\nТехнологии, определённые по разметке сайта: " + ", ".join(hints) if hints else ""))

    t0 = time.monotonic()
    kw = {"model": _model() or None, "max_tokens": 2000, "request_timeout": 300}
    text, usage = await ctx.llm.chat_with_usage(
        [{"role": "system", "content": SYSTEM_PROFILE}, {"role": "user", "content": user}], **kw)
    dt = int((time.monotonic() - t0) * 1000)

    pt = int(usage.get("prompt_tokens") or 0)
    ct = int(usage.get("completion_tokens") or 0)
    model_used = _model() or ctx.config.llm_model or "default"
    log.info("nj591[profile] model=%s %d+%d tok, %d ms", model_used, pt, ct, dt)

    sink.append({"profile_id": profile_id, "phase": "profile", "model": model_used,
                 "prompt_tokens": pt, "completion_tokens": ct, "duration_ms": dt})

    obj = webread.json_from_text(text)

    if not obj:
        raise ValueError(f"Модель вернула не JSON (начало: {text[:120]!r})")

    # нормализация схемы — модель могла схалтурить
    contacts = obj.get("contacts") if isinstance(obj.get("contacts"), dict) else {}
    result = {
        "company": str(obj.get("company") or home_title or "").strip()[:200],
        "sphere": str(obj.get("sphere") or "").strip()[:300],
        "summary": str(obj.get("summary") or "").strip()[:1500],
        "size_estimate": str(obj.get("size_estimate") or "не найдено").strip()[:200],
        "contacts": {
            "emails": [str(e).strip()[:120] for e in (contacts.get("emails") or [])][:10],
            "phones": [str(p).strip()[:60] for p in (contacts.get("phones") or [])][:10],
            "address": str(contacts.get("address") or "").strip()[:500],
        },
        "tech_stack": [str(t).strip()[:60] for t in (obj.get("tech_stack") or [])][:15],
        "decision_makers": [
            {"name": str(d.get("name") or "").strip()[:150],
             "role": str(d.get("role") or "").strip()[:150],
             "source": str(d.get("source") or "").strip()[:200]}
            for d in (obj.get("decision_makers") or []) if isinstance(d, dict)
        ][:10],
        "confidence": str(obj.get("confidence") or "средняя").strip()[:20],
    }

    # tech_hints детерминированы — добавляем то, что модель могла пропустить
    for h in hints:
        if h not in result["tech_stack"] and len(result["tech_stack"]) < 15:
            result["tech_stack"].append(h)

    # --- 4. выгрузка ---------------------------------------------------------
    await _api_progress(ctx, profile_id, "Сохраняю профиль…")

    ok, why = await _api_result(ctx, profile_id, result["company"], result, sources)

    if not ok:
        raise RuntimeError(f"Сайт не принял профиль — {why}")

    # --- 5. Вакансии работодателя (модуль nj642) -----------------------------
    # Владелец (26.09): «смотри страницы „Карьера“ „Вакансии“ „О компании“ — эта
    # информация важна, её можно обновлять и хранить историю появления и
    # исчезновения вакансий». Сбой этого шага профиль НЕ отменяет: он уже
    # сохранён, а вакансии — отдельная история со своими мониторами.
    if vacancy_pages:
        await _api_progress(ctx, profile_id, "Смотрю вакансии на сайте компании…")

        try:
            posted = await nj642_vacancies.ingest_from_pages(
                ctx, company=result["company"], domain=_host_of(base_url),
                profile_code=code, pages=vacancy_pages)
            log.info("nj591[%s]: вакансий отправлено %d", code, posted)
        except Exception as e:  # noqa: BLE001 — вакансии не роняют профиль
            log.warning("nj591[%s]: вакансии не отправлены: %s", code, e)

    return {"usage": sink, "company": result["company"], "domain": _host_of(base_url)}
