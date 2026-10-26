"""nj590.design — варианты улучшенного дизайна страницы (N2J.ru /design).

Пайплайн: пожелания из payload → LLM готовит варианты оформления → выгрузка /design/api/upload → выбор пользователем на /design/<code>.

Поток: payload {run_id, code, url, wishes, n} →
  1. скачать страницу (httpx; только публичные адреса — private/loopback запрещены);
  2. вычистить HTML (script/svg/base64/комментарии), собрать палитру из CSS;
  3. LLM-анализ страницы (назначение, проблемы) → progress на сайт;
  4. N генераций — по одной на вариант: строгий JSON {title, concept, html};
     каждый вариант — САМОДОСТАТОЧНЫЙ HTML-файл (инлайн-CSS, без внешних
     зависимостей и скриптов);
  5. выгрузка каждого варианта через /design/api/upload (X-Worker-Token);
     прогресс через /design/api/progress.

Режим: http (нужны NJ590_API_BASE и токен сайта) — файлы вариантов живут
на диске сайта, поэтому прямая запись в БД воркером не предусмотрена.
LLM-затраты возвращаются в job.result.usage — сайт дозаписывает в nj_590_runs.usage.

Env: NJ590_API_BASE (напр. https://n2j.ru/design/api), NJ590_SITE_TOKEN
(или общий WORKER_API_TOKEN), NJ590_DESIGN_MODEL (спека «провайдер::модель»),
NJ590_MAX_PAGE_BYTES (default 3 МБ), NJ590_FETCH_TIMEOUT (default 25 с).
"""

from __future__ import annotations

import asyncio
import ipaddress
import json
import logging
import os
import re
import socket
import time

import httpx

log = logging.getLogger("n2j.nj590")

CHANNEL = "nj590.design"

MAX_VARIANTS = 5
MAX_WISHES = 4000
HTML_CAP = 48_000          # сколько вычищенного HTML даём LLM
CSS_CAP = 2_000            # сколько CSS оставляем для палитры
MIN_HTML = 400             # короче — страница не содержательная
MAX_VARIANT_BYTES = 2_000_000

FETCH_TIMEOUT = float(os.getenv("NJ590_FETCH_TIMEOUT", "25") or 25)


class FetchError(Exception):
    """Страница недоступна/запрещена — понятная пользователю причина."""


# ---------------------------------------------------------------------------
# Промпты
# ---------------------------------------------------------------------------

SYSTEM_ANALYSIS = (
    "Ты разбираешь HTML-страницу, чтобы улучшить её дизайн. Верни СТРОГО JSON без markdown: "
    '{"page_title": "краткое название страницы по-русски", '
    '"purpose": "1-2 предложения: что это за страница и для кого", '
    '"problems": ["до 5 конкретных проблем текущего оформления"], '
    '"summary": "2-3 предложения для владельца: что улучшим в первую очередь"}. '
    "Не выдумывай фактов, которых нет в HTML; если язык страницы не русский — всё равно отвечай по-русски."
)

SYSTEM_DESIGN = (
    "Ты выдающийся веб-дизайнер и front-end разработчик. Делаешь УЛУЧШЕННЫЙ вариант дизайна страницы. "
    "ЖЁСТКИЕ ПРАВИЛА РЕЗУЛЬТАТА: "
    "(1) один самодостаточный HTML-файл: весь CSS — в <style> внутри файла; "
    "(2) ЗАПРЕЩЕНЫ внешние ресурсы: никаких <script>, CDN, шрифтов по сети, картинок по URL — "
    "картинки заменяй CSS-градиентами/фигурами или блоками-заглушками с подписью; "
    "(3) никакого JavaScript; интерактив — только CSS (:hover, details/summary, checkbox-хаки); "
    "(4) сохрани смысл, структуру и ПОЛНОТУ текстового содержимого исходной страницы (не сокращай "
    "разделы, не выдумывай новые факты/цифры/отзывы), улучши: иерархию, сетку, типографику, палитру, "
    "отступы, контрасты, состояния ссылок и кнопок, адаптивность (мобильный → десктоп); "
    "(5) язык текстов страницы не меняй; "
    "(6) accessibility: контраст ≥ WCAG AA, семантические теги, alt-тексты-заглушки. "
    'Верни СТРОГО JSON без markdown: {"title": "коротое имя варианта по-русски", '
    '"concept": "2-3 предложения: идея варианта и что изменилось", '
    '"html": "<!DOCTYPE html> … полный файл …"}. '
    "JSON должен быть валидным: в строках экранируй кавычки и переводы строк."
)

# Углы подачи — чтобы варианты реально отличались (индекс = номер варианта - 1)
ANGLES = [
    ("Деловой и сдержанный", "строгая деловая подача: спокойная палитра, акцент на надёжности и цифрах, много воздуха, классическая типографика"),
    ("Современный и яркий", "современный продуктовый стиль: живые акцентные цвета, крупные заголовки, скруглённые карточки, сочные CTA-кнопки"),
    ("Минимализм и типографика", "минимализм: монохром с одним акцентом, выразительная типографика, тонкие линии, ничего лишнего"),
    ("Тёмная тема", "тёмная тема: глубокий тёмный фон, яркие акценты, неоновые детали, высокая контрастность текста"),
    ("Журнальный и смелый", "журнальная вёрстка: смелые сочетания, крупные изображения-заглушки, асимметричная сетка, выразительные цитаты"),
]


# ---------------------------------------------------------------------------
# Скачивание страницы (только публичные адреса)
# ---------------------------------------------------------------------------

_UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
       "Chrome/126.0 Safari/537.36 N2J-nj590/1.0")


def _assert_public_host(host: str) -> None:
    """SSRF-щит: резолвим хост и запрещаем private/loopback/link-local и пр."""
    try:
        infos = socket.getaddrinfo(host, None)
    except OSError as e:
        raise FetchError(f"Не удалось определить адрес сервера {host}: {e}") from None

    for info in infos:
        try:
            ip = ipaddress.ip_address(info[4][0])
        except ValueError:
            continue

        if (ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_reserved
                or ip.is_multicast or ip.is_unspecified):
            raise FetchError("Этот адрес не поддерживается: внутренние и локальные сети закрыты")


def _max_page_bytes() -> int:
    try:
        return max(200_000, int(os.getenv("NJ590_MAX_PAGE_BYTES", str(3 * 1024 * 1024))))
    except ValueError:
        return 3 * 1024 * 1024


async def _fetch(url: str) -> tuple[str, str]:
    """Скачать страницу → (html, title). Бросает FetchError с причиной."""
    from urllib.parse import urlparse

    u = urlparse(url)

    if u.scheme not in ("http", "https") or not u.hostname:
        raise FetchError("Нужна ссылка http(s):// с именем сайта")

    _assert_public_host(u.hostname)

    try:
        async with httpx.AsyncClient(follow_redirects=True, timeout=FETCH_TIMEOUT,
                                     max_redirects=5, headers={
                                         "User-Agent": _UA,
                                         "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
                                         "Accept-Language": "ru,en;q=0.8",
                                     }) as client:
            r = await client.get(url)
    except httpx.TimeoutException:
        raise FetchError(f"Сайт не ответил за {int(FETCH_TIMEOUT)} с — страница недоступна") from None
    except httpx.HTTPError as e:
        raise FetchError(f"Не удалось скачать страницу: {e.__class__.__name__}") from None

    if r.status_code >= 400:
        raise FetchError(f"Сайт вернул HTTP {r.status_code} — страница недоступна")

    if len(r.content) > _max_page_bytes():
        raise FetchError("Страница слишком большая (лимит 3 МБ)")

    html = r.text

    if "<" not in html or len(html) < MIN_HTML:
        raise FetchError("По ссылке не HTML-страница (или она почти пустая)")

    m = re.search(r"<title[^>]*>(.*?)</title>", html, re.S | re.I)
    title = re.sub(r"\s+", " ", m.group(1)).strip()[:200] if m else ""

    return html, title


_RE_SCRIPT = re.compile(r"<script\b[^>]*>.*?</script>", re.S | re.I)
_RE_STYLE = re.compile(r"<style\b[^>]*>(.*?)</style>", re.S | re.I)
_RE_NOSVG = re.compile(r"<(noscript|svg|iframe|canvas|form|input|select|button)\b[^>]*>.*?</\1>", re.S | re.I)
_RE_VOID = re.compile(r"<(img|input|br|hr|source)\b[^>]*>", re.I)
_RE_COMMENT = re.compile(r"<!--.*?-->", re.S)
_RE_BASE64 = re.compile(r"data:[a-z/+;]*base64,[A-Za-z0-9+/=]+")
_RE_SPACES = re.compile(r"[ \t]{2,}")
_RE_BLANK = re.compile(r"\n{2,}")


def _clean_html(html: str) -> tuple[str, str]:
    """Вычистить HTML для LLM → (текст, палитра-строка)."""

    # палитра из CSS (до удаления стилей)
    css_fragments = " ".join(_RE_STYLE.findall(html))[:CSS_CAP]
    colors = re.findall(r"#[0-9a-fA-F]{3,8}\b|rgba?\([^)]{3,30}\)", css_fragments)
    palette = ", ".join(dict.fromkeys(c.lower() for c in colors[:15]))

    s = _RE_COMMENT.sub(" ", html)
    s = _RE_SCRIPT.sub(" ", s)
    s = _RE_STYLE.sub(" ", s)
    s = _RE_NOSVG.sub(" ", s)
    s = _RE_BASE64.sub("data:[img]", s)
    s = re.sub(r"\son[a-z]+\s*=\s*(\"[^\"]*\"|'[^']*'|[^\s>]+)", " ", s, flags=re.I)

    # длинные атрибуты классов/стилей режем — структуры достаточно
    s = re.sub(r"\s(class|style|srcset|sizes|data-[a-z-]+)\s*=\s*(\"[^\"]{0,80}\"|'[^']{0,80}')", " ", s, flags=re.I)

    s = _RE_SPACES.sub(" ", s)
    s = _RE_BLANK.sub("\n", s).strip()

    return s[:HTML_CAP], palette


# ---------------------------------------------------------------------------
# JSON из ответа LLM (терпимо к ```json-заборам и мусору вокруг)
# ---------------------------------------------------------------------------

def _extract_json(text: str) -> dict | None:
    if not text:
        return None

    s = re.sub(r"^```[a-zA-Z]*\s*|\s*```$", "", text.strip(), flags=re.M).strip()

    start = s.find("{")

    if start < 0:
        return None

    depth = 0
    in_str = False
    esc = False

    for i in range(start, len(s)):
        ch = s[i]

        if in_str:
            if esc:
                esc = False
            elif ch == "\\":
                esc = True
            elif ch == '"':
                in_str = False
            continue

        if ch == '"':
            in_str = True
        elif ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1

            if depth == 0:
                try:
                    obj = json.loads(s[start:i + 1])
                except json.JSONDecodeError:
                    return None

                return obj if isinstance(obj, dict) else None

    return None


# ---------------------------------------------------------------------------
# Сайт: прогресс и выгрузка вариантов
# ---------------------------------------------------------------------------

def _api_base() -> str:
    # напр. https://n2j.ru/design/api
    return os.getenv("NJ590_API_BASE", "").strip().rstrip("/")


def _site_token() -> tuple[str, str]:
    """(токен, откуда): NJ590_SITE_TOKEN → WORKER_SITE_TOKEN → WORKER_API_TOKEN. Пусто = не настроен."""
    t = os.getenv("NJ590_SITE_TOKEN", "").strip()

    if t:
        return t, "NJ590_SITE_TOKEN"

    t = os.getenv("WORKER_SITE_TOKEN", "").strip()  # общий токен сайта на все модули

    if t:
        return t, "WORKER_SITE_TOKEN"

    t = os.getenv("WORKER_API_TOKEN", "").strip()

    if t:
        return t, "WORKER_API_TOKEN"

    return "", ""


def _design_model() -> str:
    return os.getenv("NJ590_DESIGN_MODEL", "").strip()


def _input_cap() -> int:
    """Сколько вычищенного HTML отдаём модели варианта (малые LLM — меньше шумa)."""
    try:
        return max(6000, int(os.getenv("NJ590_DESIGN_INPUT_CAP", "24000")))
    except ValueError:
        return 24000


_ESCAPES = {"n": "\n", "t": "\t", "r": "", '"': '"', "\\": "\\", "/": "/", "b": "\b", "f": "\f"}


def _json_unescape(chunk: str, strict: bool = False) -> str:
    """Разэкранирование JSON-строки вручную (strict=True — до закрывающей кавычки)."""
    out: list[str] = []
    i = 0

    while i < len(chunk):
        ch = chunk[i]

        if ch == "\\" and i + 1 < len(chunk):
            nxt = chunk[i + 1]

            if nxt in _ESCAPES:
                out.append(_ESCAPES[nxt])
                i += 2
                continue

            if nxt == "u" and i + 5 < len(chunk):
                try:
                    out.append(chr(int(chunk[i + 2:i + 6], 16)))
                    i += 6
                    continue
                except ValueError:
                    pass

            out.append(nxt)
            i += 2
            continue

        if ch == '"':
            if strict:
                break

            out.append(ch)
            i += 1
            continue

        out.append(ch)
        i += 1

    return "".join(out)


def _salvage_variant(raw: str) -> dict | None:
    """Достать вариант из «почти-JSON» (обрезанный ответ, сырые переносы и т.п.).

    Малые модели часто ломают JSON: строка html длинная и обрезается по лимиту
    токенов, кавычки внутри не экранированы. Падать с «не JSON» дорого —
    восстанавливаем title/concept регулярками, html — от метки "html" до
    конца ответа с ручным разэкранированием.
    """
    if not raw or '"html"' not in raw:
        return None

    m_t = re.search(r'"title"\s*:\s*"((?:[^"\\]|\\.)*)"', raw)
    m_c = re.search(r'"concept"\s*:\s*"((?:[^"\\]|\\.)*)"', raw)
    m_h = re.search(r'"html"\s*:\s*"', raw)

    if not m_h:
        return None

    html = _json_unescape(raw[m_h.end():], strict=False).strip()

    if len(html) < MIN_HTML or "<" not in html:
        return None

    if "<!doctype" not in html.lower()[:200]:
        html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head>\n' + html

    if "</html>" not in html.lower()[-400:]:
        html = html + "\n</body></html>"

    return {
        "title": _json_unescape(m_t.group(1), strict=True).strip()[:200] if m_t else "",
        "concept": _json_unescape(m_c.group(1), strict=True).strip()[:4000] if m_c else "",
        "html": html,
    }


async def _api_progress(ctx, run_id: int, phase: str, **fields: str) -> dict | None:
    """Промежуточный статус → /design/api/progress (виден в UI сразу).
    Возвращает ответ сайта {'ok': True, 'cancel': bool}; cancel=True —
    прогон остановлен пользователем, воркер завершается на контрольной точке."""
    # Тот же этап — в журнал очереди: этап виден в мониторе nj120,
    # «Моих задачах» и статус-ряде модуля (единый механизм /trends).
    # Сбой журнала прогресс не отменяет: своя страница важнее.
    try:
        await ctx.progress(phase)
    except Exception:  # noqa: BLE001 — рапорт в очередь не критичен
        pass

    base = _api_base()
    token, _token_src = _site_token()

    if not (base and token):
        return None

    data = {"run_id": str(run_id), "phase": phase[:300]}
    data.update({k: v[:6000] for k, v in fields.items() if v})

    try:
        async with httpx.AsyncClient(timeout=30) as client:
            r = await client.post(base + "/progress", data=data,
                                  headers={"X-Worker-Token": token})
        if r.status_code != 200:
            log.warning("nj590: api/progress -> HTTP %s: %s", r.status_code, r.text[:300])
            return None

        return r.json() or {}
    except Exception:  # noqa: BLE001 — прогресс не критичен
        log.debug("nj590: api/progress failed", exc_info=True)
        return None


async def _checkpoint(ctx, run_id: int, phase: str) -> None:
    """Прогресс + heartbeat + контроль отмены (кнопка «Остановить» в /design)."""
    await ctx.heartbeat()
    res = await _api_progress(ctx, run_id, phase)

    if res and res.get("cancel"):
        raise RuntimeError("Отменено пользователем — прогон остановлен в /design")


def _readable_reason(text: str) -> str:
    """HTML-страница ошибки (Kohana) → читаемая строка вместо CSS-простыни."""
    t = (text or "").strip()

    if t.startswith("<"):
        t = re.sub(r"<script.*?</script>", " ", t, flags=re.S | re.I)
        t = re.sub(r"<style.*?</style>", " ", t, flags=re.S | re.I)
        t = re.sub(r"<[^>]+>", " ", t)
        t = re.sub(r"\s+", " ", t).strip()

    return t[:300]


async def _api_upload(ctx, run_id: int, no: int, title: str, concept: str,
                      html: bytes, model_used: str = "") -> tuple[bool, str]:
    """HTML-вариант → /design/api/upload. Две попытки: сеть не идеальна.
    Возвращает (ok, причина) — причина попадает в ошибку задачи в UI."""
    base = _api_base()
    token, token_src = _site_token()

    if not base:
        return False, "NJ590_API_BASE не задан в workers/.env"

    if not token:
        return False, ("токен сайта не задан — добавьте в workers/.env строку "
                       "NJ590_SITE_TOKEN=<тот же токен, что NJ580_SITE_TOKEN на сайте>")

    data = {"run_id": str(run_id), "no": str(no),
            "title": title[:300], "concept": concept[:4000]}

    if model_used:
        data["model"] = model_used[:120]  # фактическая модель — видно в UI прогона

    for attempt in (1, 2):
        try:
            async with httpx.AsyncClient(timeout=120) as client:
                r = await client.post(base + "/upload", data=data,
                                      files={"file": (f"variant-{no}.html", html)},
                                      headers={"X-Worker-Token": token})
            try:
                j = r.json()
            except Exception:  # noqa: BLE001
                j = {}

            if r.status_code == 200:
                if isinstance(j, dict) and j.get("error"):
                    # сайт сам отказал (напр. не записался файл хранилища) — его словами
                    return False, f"сайт: {str(j['error'])[:300]}"

                return True, ""

            if isinstance(j, dict) and j.get("error"):
                # ошибка сайта — словами и ЦЕЛИКОМ (не обрезок JSON)
                reason = f"HTTP {r.status_code}: {str(j['error'])[:300]}"
            else:
                reason = f"HTTP {r.status_code}: {_readable_reason(r.text)}"

            if r.status_code == 401:
                reason += f" — токен ({token_src}) не совпал с WORKER_API_TOKEN сайта"

            log.warning("nj590: upload v%d -> %s", no, reason)
        except Exception as e:  # noqa: BLE001
            reason = f"сеть: {e.__class__.__name__}"
            log.warning("nj590: upload v%d failed (попытка %d)", no, attempt, exc_info=True)

        await asyncio.sleep(2 * attempt)

    return False, reason


# ---------------------------------------------------------------------------
# LLM
# ---------------------------------------------------------------------------

def _msg(role: str, content: str) -> dict:
    return {"role": role, "content": content}


async def _chat(ctx, messages: list, phase: str, job_id: int, run_id: int,
                sink: list, model: str = "", max_tokens: int = 8000,
                request_timeout: float | None = None) -> tuple[str, str, str]:
    """→ (текст, заметка-подмена, фактическая модель). Подмены модели
    (обход на другого провайдера, повтор на DEFAULT_MODEL) не молчаливы:
    заметка попадает в job.result и в ошибку задачи."""
    t0 = time.monotonic()
    kw = {"model": model or None, "max_tokens": max_tokens}

    if request_timeout:
        kw["request_timeout"] = request_timeout

    text, usage = await ctx.llm.chat_with_usage(messages, **kw)
    dt = int((time.monotonic() - t0) * 1000)

    pt = int(usage.get("prompt_tokens") or 0)
    ct = int(usage.get("completion_tokens") or 0)
    model_used = model or ctx.config.llm_model or "default"
    model_real = str(usage.get("_llm_model") or "") or model_used
    llm_note = str(usage.get("_llm_note") or "")
    log.info("nj590[%s] model=%s%s %d+%d tok, %d ms", phase, model_real,
             (" — " + llm_note) if llm_note else "", pt, ct, dt)

    # затраты всегда возвращаем в job.result — сайт дозапишет в nj_590_runs.usage
    sink.append({"run_id": run_id, "phase": phase, "model": model_real,
                 "prompt_tokens": pt, "completion_tokens": ct, "duration_ms": dt,
                 "note": llm_note})

    return text, llm_note, model_real


# ---------------------------------------------------------------------------
# Обработчик канала
# ---------------------------------------------------------------------------

async def handle(ctx, payload: dict) -> dict:
    run_id = int(payload.get("run_id") or 0)
    code = str(payload.get("code") or "")
    url = str(payload.get("url") or "").strip()
    wishes = str(payload.get("wishes") or "").strip()[:MAX_WISHES]
    n = max(1, min(MAX_VARIANTS, int(payload.get("n") or 3)))
    # модель: админский выбор из формы приоритетнее env NJ590_DESIGN_MODEL
    model_override = str(payload.get("model") or "").strip()[:120] or _design_model()

    if not (run_id and url):
        raise ValueError("nj590: payload должен содержать run_id и url")

    if ctx.llm is None:
        raise RuntimeError("nj590: LLM-провайдеры не настроены (workers/.env)")

    sink: list = []

    # --- 1. страница -------------------------------------------------------
    await _checkpoint(ctx, run_id, "Скачиваю страницу…")

    html, title = await _fetch(url)
    cleaned, palette = _clean_html(html)
    log.info("nj590[%s] страница %d байт → %d символов, палитра: %s", code, len(html), len(cleaned), palette or "—")

    # --- 2. анализ ---------------------------------------------------------
    await _checkpoint(ctx, run_id, "Анализирую страницу…")

    analysis_user = ("HTML страницы:\n\n" + cleaned
                     + ("\n\nТекущая палитра (из CSS): " + palette if palette else "")
                     + ("\n\nПожелания владельца: " + wishes if wishes else ""))

    analysis_raw, a_note, a_model = await _chat(
        ctx, [_msg("system", SYSTEM_ANALYSIS), _msg("user", analysis_user)],
        "analysis", ctx._current_job["id"] if ctx._current_job else 0,
        run_id, sink, model=model_override or None, max_tokens=1500, request_timeout=240)
    analysis = _extract_json(analysis_raw) or {}

    page_title = str(analysis.get("page_title") or title or "").strip()[:200]
    problems = analysis.get("problems") or []
    page_summary = str(analysis.get("summary") or "").strip()
    purpose = str(analysis.get("purpose") or "").strip()

    if page_summary:
        await _api_progress(ctx, run_id, "Страница разобрана — готовлю варианты…"
                            + (" [LLM: " + a_note + "]" if a_note else ""),
                            page_title=page_title, page_summary=page_summary)

    # --- 3. варианты -------------------------------------------------------
    variants: list[dict] = []

    for i in range(1, n + 1):
        angle_name, angle_desc = ANGLES[(i - 1) % len(ANGLES)]
        await _checkpoint(ctx, run_id, f"Готовлю вариант {i}/{n}: {angle_name.lower()}…")

        user = (
            f"Вариант {i} из {n}. Стилевое направление варианта: {angle_desc}.\n"
            + (f"Пожелания владельца (приоритет над направлением): {wishes}\n" if wishes else "")
            + (f"Страница: {page_title}. Назначение: {purpose}\n" if page_title or purpose else "")
            + ("Проблемы текущего оформления: " + "; ".join(map(str, problems[:5])) + "\n" if problems else "")
            + "\nHTML исходной страницы:\n\n" + cleaned[:_input_cap()]
        )

        raw, v_note, v_model = await _chat(
            ctx, [_msg("system", SYSTEM_DESIGN), _msg("user", user)],
            f"design-{i}", ctx._current_job["id"] if ctx._current_job else 0,
            run_id, sink, model=model_override or None, max_tokens=12000,
            request_timeout=480)

        obj = _extract_json(raw)

        if not obj:
            obj = _salvage_variant(raw)

            if obj:
                log.warning("nj590[%s]: вариант %d восстановлен из нестрогого/обрезанного JSON", code, i)

        if not obj:
            raise ValueError(f"Вариант {i}: модель вернула не JSON (начало: {raw[:120]!r})")

        v_html = str(obj.get("html") or "")
        v_title = str(obj.get("title") or f"Вариант {i}").strip()[:200]
        v_concept = str(obj.get("concept") or "").strip()[:4000]

        if len(v_html) < MIN_HTML or "<" not in v_html:
            raise ValueError(f"Вариант {i}: модель вернула пустой/короткий HTML")

        if len(v_html.encode("utf-8")) > MAX_VARIANT_BYTES:
            raise ValueError(f"Вариант {i}: HTML больше 2 МБ — попробуйте ещё раз")

        ok, why = await _api_upload(ctx, run_id, i, v_title, v_concept,
                                    v_html.encode("utf-8"), model_used=v_model)

        if not ok:
            raise RuntimeError(f"Вариант {i}: сайт не принял выгрузку — {why}"
                               + (f" [LLM: {v_note}]" if v_note else ""))

        variants.append({"no": i, "title": v_title, "concept": v_concept})
        await _api_progress(ctx, run_id, f"Вариант {i}/{n} готов")

    return {
        "usage": sink,
        "page_title": page_title,
        "page_summary": page_summary,
        "variants": variants,
    }
