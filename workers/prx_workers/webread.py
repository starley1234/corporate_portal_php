"""webread — общий инструмент «веб-страница → чистый Markdown → JSON» для малых LLM.

Зачем: у малых моделей (Gemma 12B q4 и т.п.) узкое контекстное окно и низкая
устойчивость к шуму — сырой HTML скармливать нельзя. Конвейер (нулевая цена
по GPU/CPU):

    [URL] → fetch (curl_cffi c TLS-отпечатком Chrome; фолбэк httpx)
          → сырой HTML (кап 3 МБ)
          → trafilatura → Markdown (шапки/футеры/меню/баннеры вырезаны,
            кап по умолчанию 2000 знаков ≈ 0.5–2 КБ текста, −70..85% токенов)
          → LLM со строгой JSON-схемой → валидный JSON

Правила безопасности:
  * только публичные адреса (private/loopback/link-local/reserved запрещены);
  * headless-браузер НЕ используем вообще (RAM) — если trafilatura вернула
    пусто, отдаём вычищенный текст наивным фолбэком; SPA без текста в HTML
    честно помечаем как «страница без текста».

Используется: prx591.profile («Профиль компании»), prx592.email («Холодное
письмо») и любые другие конвейеры: from ..webread import fetch, html_to_markdown.

Env: WEBREAD_FETCH_TIMEOUT (20 с), WEBREAD_MAX_PAGE_BYTES (3 МБ),
WEBREAD_MD_CAP (2000 знаков), WEBREAD_IMPERSONATE (chrome124).
"""

from __future__ import annotations

import asyncio
import ipaddress
import json
import logging
import os
import re
import socket
from pathlib import Path
from urllib.parse import urljoin, urlparse

import httpx

log = logging.getLogger("prx.webread")

from .usage import Usage


class WebReadError(Exception):
    """Понятная пользователю причина: страница не скачалась/не та."""


# ---------------------------------------------------------------------------
# Оффлайн-мок (тесты/песочница): PRX_WEBREAD_MOCK=/path/file.json
# {"search": [{"q": "подстрока запроса", "results": [{"title","url","body"}]}, ...],
#  "pages":  {"https://example.com/a": "<html>…", ...}}
# Попадание в поиск — по подстроке (без регистра); страницы — по точному URL.
# В проде переменная не задаётся, крючки стоят до любого сетевого вызова.
# ---------------------------------------------------------------------------

_MOCK: dict | None = None


def _mock() -> dict:
    global _MOCK
    if _MOCK is None:
        path = os.environ.get("PRX_WEBREAD_MOCK", "")
        try:
            _MOCK = json.loads(Path(path).read_text(encoding="utf-8")) if path else {}
        except Exception as e:  # noqa: BLE001 — мок не должен ронять конвейер
            log.warning("webread: мок %s не читается: %s", path, e)
            _MOCK = {}
    return _MOCK



# ---------------------------------------------------------------------------
# Настройки
# ---------------------------------------------------------------------------

def fetch_timeout() -> float:
    try:
        return max(5.0, float(os.getenv("WEBREAD_FETCH_TIMEOUT", "20") or 20))
    except ValueError:
        return 20.0


def max_page_bytes() -> int:
    try:
        return max(100_000, int(os.getenv("WEBREAD_MAX_PAGE_BYTES", str(3 * 1024 * 1024))))
    except ValueError:
        return 3 * 1024 * 1024


def md_cap_default() -> int:
    try:
        return max(300, int(os.getenv("WEBREAD_MD_CAP", "2000") or 2000))
    except ValueError:
        return 2000


def _impersonate() -> str:
    return os.getenv("WEBREAD_IMPERSONATE", "chrome124").strip() or "chrome124"


_UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
       "Chrome/126.0 Safari/537.36 PRX-webread/1.0")


# ---------------------------------------------------------------------------
# SSRF-щит
# ---------------------------------------------------------------------------

def assert_public_host(host: str) -> None:
    """Резолвим хост и запрещаем внутренние/локальные адреса."""
    try:
        infos = socket.getaddrinfo(host, None)
    except OSError as e:
        raise WebReadError(f"Не удалось определить адрес сервера {host}: {e}") from None

    for info in infos:
        try:
            ip = ipaddress.ip_address(info[4][0])
        except ValueError:
            continue

        if (ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_reserved
                or ip.is_multicast or ip.is_unspecified):
            raise WebReadError("Этот адрес не поддерживается: внутренние и локальные сети закрыты")


# ---------------------------------------------------------------------------
# Скачивание: curl_cffi (TLS-отпечаток) → httpx
# ---------------------------------------------------------------------------

def _curl_cffi_get(url: str) -> tuple[int, str]:
    from curl_cffi import requests as cffi

    r = cffi.get(url, impersonate=_impersonate(), timeout=fetch_timeout(),
                 allow_redirects=True,
                 headers={"Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
                          "Accept-Language": "ru,en;q=0.8"})
    return r.status_code, r.text


def _httpx_get(url: str) -> tuple[int, str]:
    async def _go() -> tuple[int, str]:
        async with httpx.AsyncClient(follow_redirects=True, timeout=fetch_timeout(),
                                     max_redirects=5, headers={
                                         "User-Agent": _UA,
                                         "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
                                         "Accept-Language": "ru,en;q=0.8",
                                     }) as client:
            r = await client.get(url)
        return r.status_code, r.text

    # fetch_sync() уходит в asyncio.to_thread (рабочий поток без running loop),
    # поэтому run_until_complete тут падает — нужен asyncio.run() каждый раз.
    return asyncio.run(_go())


def fetch_sync(url: str) -> tuple[str, str]:
    """Скачать страницу → (html, final_url). WebReadError с понятной причиной."""
    u = urlparse(url)

    if u.scheme not in ("http", "https") or not u.hostname:
        raise WebReadError("Нужна ссылка http(s):// с именем сайта")

    # оффлайн-мок: страница из фикстуры — до DNS/сети
    page = _mock().get("pages", {}).get(url)
    if page is not None:
        return (page if isinstance(page, str) else str(page)), url

    assert_public_host(u.hostname)

    try:
        status, text = _curl_cffi_get(url)
    except WebReadError:
        raise
    except ImportError:
        status, text = _httpx_get(url)
    except Exception as e:  # noqa: BLE001 — TLS/сеть: пробуем httpx, онdiagnoostic честнее
        log.info("webread: curl_cffi не смог (%s), пробую httpx", e.__class__.__name__)
        try:
            status, text = _httpx_get(url)
        except Exception:  # noqa: BLE001
            raise WebReadError("Сайт не ответил (проверьте адрес или попробуйте позже)") from None

    if status >= 400:
        raise WebReadError(f"Сайт вернул HTTP {status} — страница недоступна")

    if len(text.encode("utf-8", "ignore")) > max_page_bytes():
        raise WebReadError("Страница слишком большая (лимит 3 МБ)")

    _u = Usage.current()
    if _u is not None:
        _u.add_fetch()
    return text, url


async def fetch(url: str) -> tuple[str, str]:
    """Асинхронная обёртка над fetch_sync (не блокирует цикл воркера)."""
    return await asyncio.to_thread(fetch_sync, url)


async def to_markdown(html: str, cap: int | None = None) -> tuple[str, str]:
    """Асинхронная обёртка над html_to_markdown — парсинг идёт в РАБОЧЕМ потоке.

    Зачем: trafilatura/bs4 на странице в пару мегабайт держат процессор
    секундами. В event loop это останавливает ВСЁ: пульс задачи (который
    видит монитор), таймауты «висит ли задача», и остальные задачи воркера.
    Владелец видел результат именно так — «Выполняется, пульс 4 мин».
    """
    return await asyncio.to_thread(html_to_markdown, html, cap)


# ---------------------------------------------------------------------------
# HTML → Markdown (trafilatura → markdownify → наивный фолбэк)
# ---------------------------------------------------------------------------

_RE_SCRIPT = re.compile(r"<script\b[^>]*>.*?</script>", re.S | re.I)
_RE_STYLE = re.compile(r"<style\b[^>]*>.*?</style>", re.S | re.I)
_RE_NOSVG = re.compile(r"<(noscript|svg|iframe|canvas|form|input|select|button)\b[^>]*>.*?</\1>", re.S | re.I)
_RE_COMMENT = re.compile(r"<!--.*?-->", re.S)
_RE_BASE64 = re.compile(r"data:[a-z/+;]*base64,[A-Za-z0-9+/=]+")
_RE_TAGS = re.compile(r"<[^>]+>")
_RE_SPACES = re.compile(r"[ \t]{2,}")
_RE_BLANK = re.compile(r"\n{2,}")


def _parse_limit_bytes() -> int:
    """Сколько HTML максимум отдаём парсеру (после чистки).

    Дальше мы всё равно оставляем лишь `md_cap` символов markdown, а тяжёлые
    парсеры (trafilatura/bs4) стоят времени и памяти пропорционально входу.
    Ограничение защищает воркер от страниц-монстров: у нас уже были задачи,
    которые «висели», пока разбиралась такая страница.
    """
    try:
        return max(100_000, int(os.getenv("WEBREAD_PARSE_LIMIT_BYTES", str(1024 * 1024))))
    except ValueError:
        return 1024 * 1024


def _preclean(html: str) -> str:
    """Убрать то, что парсеру не нужно: скрипты, стили, svg, data:-base64, комментарии.

    Раньше это делалось ТОЛЬКО в фолбэке markdownify, а основной путь
    (trafilatura) получал сырые страницы целиком — вместе с килобайтами
    JavaScript и инлайновых картинок.
    """
    s = _RE_COMMENT.sub(" ", html)
    s = _RE_SCRIPT.sub(" ", s)
    s = _RE_STYLE.sub(" ", s)
    s = _RE_NOSVG.sub(" ", s)
    s = _RE_BASE64.sub("data:[img]", s)

    limit = _parse_limit_bytes()

    if len(s) > limit * 2:
        # режем по границе тега, а не посреди слова/атрибута
        s = s[:limit * 2].rsplit(">", 1)[0] + ">"

    return s


def _trafilatura_md(html: str) -> str:
    try:
        import trafilatura

        return (trafilatura.extract(html, output_format="markdown", include_comments=False,
                                    include_tables=True, include_links=False,
                                    favor_recall=True) or "").strip()
    except Exception:  # noqa: BLE001 — пакет может отсутствовать на сервере
        log.info("webread: trafilatura недоступна — фолбэк markdownify")
        return ""


def _markdownify_md(html: str) -> str:
    try:
        from markdownify import markdownify as md

        return md(html, heading_style="ATX").strip()
    except Exception:  # noqa: BLE001
        return ""


def _naive_text(html: str) -> str:
    s = _RE_COMMENT.sub(" ", html)
    s = _RE_SCRIPT.sub(" ", s)
    s = _RE_STYLE.sub(" ", s)
    s = _RE_NOSVG.sub(" ", s)
    s = _RE_BASE64.sub("data:[img]", s)
    s = _RE_TAGS.sub("\n", s)
    s = re.sub(r"&nbsp;?", " ", s)
    s = _RE_SPACES.sub(" ", s)
    return _RE_BLANK.sub("\n", s).strip()


def html_to_markdown(html: str, cap: int | None = None) -> tuple[str, str]:
    """HTML → (markdown ≤ cap, title). Три ступени: trafilatura → markdownify → наивный.

    Функция СИНХРОННАЯ и недешёвая (CPU): вызывать её из обработчиков нужно
    через `await webread.to_markdown(...)` — иначе она замораживает event loop
    воркера, и тогда не тикает ни heartbeat, ни таймауты задач (симптом:
    «Выполняется, пульс N мин»).
    """
    cap = max(300, cap or md_cap_default())

    title = ""
    m = re.search(r"<title[^>]*>(.*?)</title>", html, re.S | re.I)

    if m:
        title = re.sub(r"\s+", " ", m.group(1)).strip()[:200]

    html = _preclean(html)

    md = _trafilatura_md(html)

    if len(md) < 60:
        md2 = _markdownify_md(html)

        if len(md2) > len(md):
            md = md2

    if len(md) < 60:
        md = _naive_text(html)

    md = _RE_BLANK.sub("\n", md).strip()

    if len(md) > cap:
        # режем по последнему абзацу, не посреди слова
        cut = md[:cap]
        nl = cut.rfind("\n")
        md = cut[:nl if nl > cap // 2 else cap] + "\n…"

    return md, title


# ---------------------------------------------------------------------------
# Детерминированные помощники (без LLM)
# ---------------------------------------------------------------------------

_TECH_SIGNATURES = [
    ("WordPress", "wp-content"), ("WordPress", "wp-includes"),
    ("1С-Битрикс", "bitrix"), ("Tilda", "tildacdn"), ("Tilda", "tilda.ws"),
    ("Wix", "wix.com"), ("Squarespace", "squarespace"), ("Shopify", "cdn.shopify"),
    ("Joomla", "joomla"), ("Drupal", "drupal"), ("OpenCart", "opencart"),
    ("InSales", "insales"), ("Next.js", "__NEXT_DATA__"), ("Next.js", "/_next/"),
    ("Nuxt", "__NUXT__"), ("React", "data-reactroot"), ("Vue", "data-v-"),
    ("Angular", "ng-version"), ("jQuery", "jquery"), ("Bootstrap", "bootstrap"),
    ("Tailwind CSS", "tailwind"), ("HubSpot", "hs-scripts"), ("Bitrix24", "bitrix24"),
    ("Roistat", "roistat"), ("Calltouch", "calltouch"), ("Callibri", "callibri"),
    ("Google Analytics", "googletagmanager"), ("Google Analytics", "gtag("),
    ("Яндекс.Метрика", "mc.yandex"), ("ВК Пиксель", "vk.com/rtrg"),
]


def tech_hints(html: str) -> list[str]:
    """Детерминированные признаки стека из разметки (честно, без LLM)."""
    low = html.lower()
    out = []

    for name, sig in _TECH_SIGNATURES:
        if sig.lower() in low and name not in out:
            out.append(name)

    m = re.search(r'<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']{2,60})', html, re.I)

    if m and m.group(1) not in out:
        out.insert(0, m.group(1).strip())

    return out[:12]


_LINK_RE = re.compile(r'<a\b[^>]*href\s*=\s*["\']([^"\'#]+)["\'][^>]*>(.*?)</a>', re.S | re.I)
_RE_TAGStrip = re.compile(r"<[^>]+>")


def extract_links(html: str, base_url: str, patterns: list[str],
                  same_host_only: bool = True, limit: int = 8) -> list[dict]:
    """Ссылки по регуляркам в href или тексте → [{url, label}] (абсолютные)."""
    base_host = urlparse(base_url).hostname or ""
    out: list[dict] = []
    seen: set[str] = set()

    for m in _LINK_RE.finditer(html):
        href = m.group(1).strip()
        label = re.sub(r"\s+", " ", _RE_TAGStrip.sub(" ", m.group(2))).strip()[:80]

        if href.startswith(("mailto:", "tel:", "javascript:")):
            continue

        absu = urljoin(base_url, href)
        pu = urlparse(absu)

        if pu.scheme not in ("http", "https") or not pu.hostname:
            continue

        if same_host_only and pu.hostname != base_host:
            continue

        hay = (href + " " + label).lower()

        if not any(re.search(p, hay) for p in patterns):
            continue

        norm = pu.scheme + "://" + pu.hostname + (":" + str(pu.port) if pu.port else "") + pu.path

        if norm in seen:
            continue

        seen.add(norm)
        out.append({"url": norm, "label": label})

        if len(out) >= limit:
            break

    return out


_DOMAIN_RE = re.compile(
    r"^(https?://)?([a-z0-9а-яё]([a-z0-9а-яё-]*[a-z0-9а-яё])?\.)+[a-zа-яё]{2,}/?$", re.I)


def parse_query(text: str) -> tuple[str, str]:
    """Ввод пользователя → (kind, value): ('domain', 'example.com') | ('name', 'Ромашка')."""
    q = (text or "").strip().strip("/")
    q = re.sub(r"^https?://", "", q, flags=re.I)
    q = q.split("/")[0].split("?")[0].strip()

    if q and " " not in q and _DOMAIN_RE.match(q if "." in q else ""):
        return "domain", q.lower()

    if q and " " not in q and "." in q and re.match(r"^[a-z0-9.-]+\.[a-z]{2,}$", q, re.I):
        return "domain", q.lower()

    return "name", (text or "").strip()


# ---------------------------------------------------------------------------
# Строгий JSON из ответа LLM (терпимо к ```-заборам и мусору вокруг)
# ---------------------------------------------------------------------------

_ESCAPE_MAP = {"\n": "\\n", "\t": "\\t", "\r": "\\r"}


def _escape_control_in_strings(candidate: str) -> str:
    """Raw переводы строк/табы внутри строк → \n/\t (вне строк не трогаем).

    Это чинит JSON от малых моделей, которые кладут в строковое значение
    настоящие переносы (любимый приём Gemma), и при этом СОХРАНЯЕТ разметку
    Markdown в теле статьи — в отличие от сжатия в пробелы.
    """
    out: list[str] = []
    in_str = False
    esc = False

    for ch in candidate:
        if in_str:
            if esc:
                out.append(ch)
                esc = False
            elif ch == "\\":
                out.append(ch)
                esc = True
            elif ch == "\n":
                out.append("\\n")
            elif ch == "\t":
                out.append("\\t")
            elif ch == "\r":
                out.append("\\r")
            elif ch == '"':
                out.append(ch)
                in_str = False
            else:
                out.append(ch)
            continue

        out.append(ch)

        if ch == '"':
            in_str = True

    return "".join(out)


def _scan_json(s: str, start: int = 0) -> tuple[int, list[str], int]:
    """Сканирует JSON-фрагмент от 'start': (индекс за закрытием корневого
    объекта, стек открытых скобок, 1 если строка не закрыта к концу).

    Понимает строки и escape-последовательности — кавычки/скобки внутри строк
    не считаются.
    """
    first = s.find("{", start)

    if first < 0:
        return -1, [], 0

    depth = 0
    stack: list[str] = []
    in_str = False
    esc = False
    n = len(s)

    for i in range(first, n):
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
            stack.append("{")
        elif ch == "[":
            stack.append("[")
        elif ch == "}":
            if stack and stack[-1] == "{":
                stack.pop()
                depth -= 1

                if depth == 0:
                    return i + 1, stack, 0
        elif ch == "]":
            if stack and stack[-1] == "[":
                stack.pop()

    return n, stack, 1 if in_str else 0


def _repair_truncated_json(s: str) -> str:
    """Чинит JSON, обрезанный по max_tokens/финишу модели (finish_reason=length).

    Перекладывает структурный скелет от первой '{', закрывает незакрытую
    строку (аккуратно с висячим бэкслэшем) и добирает недостающие '}'/']' в
    обратном порядке. Мусор вокруг не трогаем — потом re-скан выделит хвост.
    """
    first = s.find("{")

    if first < 0:
        return s

    out: list[str] = []
    stack: list[str] = []
    in_str = False
    esc = False

    for ch in s[first:]:
        out.append(ch)

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
            stack.append("{")
        elif ch == "[":
            stack.append("[")
        elif ch == "}":
            if stack and stack[-1] == "{":
                stack.pop()
        elif ch == "]":
            if stack and stack[-1] == "[":
                stack.pop()

    if in_str:
        # строка оборвана: закрываем её, экранируя висячий бэкслэш
        k = len(out)
        bs = 0

        while k - bs - 1 >= 0 and out[k - bs - 1] == "\\":
            bs += 1

        if bs % 2 == 1:
            out.append("\\")

        out.append('"')

    closers = {"{": "}", "[": "]"}

    while stack:
        out.append(closers[stack.pop()])

    return "".join(out)


def _json_repair_variants(candidate: str) -> list[str]:
    """Безопасные ремонты частых ошибок LLM-JSON (порядок = дешевизна).

    Ступень A сохраняет переносы Markdown внутри строк (экранирование в
    \n), ступень B сжимает их в пробелы — самый грубый, но самый
    универсальный запасной вариант.
    """
    out = [candidate]

    # хвостовые запятые: {"a": 1,} / [1, 2,]
    out.append(re.sub(r",\s*([}\]])", r"\1", candidate))

    # (A) raw newline/tab → \n/\t только ВНУТРИ строк — сохраняет разметку
    out.append(_escape_control_in_strings(candidate))

    # (B) всё сжать в пробелы — ломает меньше всего
    out.append(re.sub(r"[\t\r\n]+", " ", candidate))

    # вместе (хвостовая запятая + A; хвостовая запятая + B)
    out.append(re.sub(r",\s*([}\]])", r"\1", _escape_control_in_strings(candidate)))
    out.append(re.sub(r",\s*([}\]])", r"\1", re.sub(r"[\t\r\n]+", " ", candidate)))

    return out


def _plain_json_text(text: str) -> str:
    """Текст ответа модели без размышлений и markdown-заборов."""
    s = re.sub(r"<think>.*?</think>", "", text, flags=re.S | re.I).strip()
    s = re.sub(r"<thinking>.*?</thinking>", "", s, flags=re.S | re.I).strip()
    # обрыв размышлений: закрывающего тега нет — всё до него не является ответом
    s = re.sub(r"^.*?</(?:think|thinking)>", "", s, flags=re.S | re.I).strip()
    s = re.sub(r"^```[a-zA-Z]*\s*|\s*```$", "", s, flags=re.M).strip()

    return s


def json_objects(text: str) -> list[dict]:
    """Все целые JSON-объекты из ответа модели, по порядку.

    Спасательный круг для батчевых обработчиков: модель может вернуть массив
    верхнего уровня, список вместо объекта схемы или оборвать ответ по лимиту
    токенов. Здесь вытаскиваются ВСЕ завершённые объекты (включая элементы
    массива) — битый хвост отбрасывается, а не роняет весь вызов.

    Возвращает [] если разобрать нечего. Вложенные объекты тоже попадают в
    список: фильтровать по схеме — дело вызывающего.
    """
    if not text:
        return []

    import json

    s = _plain_json_text(text)

    if s.find("{") < 0:
        return []

    out: list[dict] = []
    pos = 0
    n = len(s)

    while pos < n:
        first = s.find("{", pos)

        if first < 0:
            break

        end, stack, unclosed = _scan_json(s, first)

        if end < 0:
            break

        broken = bool(stack or unclosed)
        frag = _repair_truncated_json(s[first:]) if broken else s[first:end]
        parsed = None

        for variant in _json_repair_variants(frag):
            try:
                parsed = json.loads(variant)
            except json.JSONDecodeError:
                continue

            break

        if parsed is None:
            # Внешний объект не собрался (обрыв в неудобном месте): спускаемся
            # внутрь — целые вложенные объекты (элементы items) ещё спасаемы.
            pos = first + 1
            continue

        if isinstance(parsed, dict):
            out.append(parsed)
        elif isinstance(parsed, list):
            out.extend(row for row in parsed if isinstance(row, dict))

        pos = n if broken else end

    return out


def json_from_text(text: str) -> dict | None:
    if not text:
        return None

    s = _plain_json_text(text)

    if s.find("{") < 0:
        return None

    import json

    candidates: list[str] = []

    # 1) честно сбалансированный объект (модель ответила целиком)
    end, _stack, unclosed = _scan_json(s)

    if end >= 0 and not _stack and unclosed == 0:
        candidates.append(s[s.find("{"):end])

    # 2) баланса нет — чиним обрез по токенам и пробуем ещё раз
    if not candidates:
        repaired = _repair_truncated_json(s)

        if repaired:
            end2, _stack2, _uncl2 = _scan_json(repaired)

            if end2 >= 0:
                candidates.append(repaired[:end2])

    for candidate in candidates:
        for variant in _json_repair_variants(candidate):
            try:
                obj = json.loads(variant)
            except json.JSONDecodeError:
                continue

            if isinstance(obj, dict):
                return obj

    return None


# ---------------------------------------------------------------------------
# DDG (бережно: как в prx550/prx580 — лимиты и таймаут, поиск не критичен)
# ---------------------------------------------------------------------------

def ddg_text_sync(query: str, max_results: int = 5) -> list[dict]:
    _u = Usage.current()
    if _u is not None:
        _u.add_search()

    # оффлайн-мок: первый блок, чья подстрока «q» сидит в запросе
    # (сравниваем нормализованно: без кавычек «»"' и лишних пробелов)
    nq = re.sub(r"[\s«»\"']+", " ", query.lower()).strip()

    for spec in _mock().get("search", []):
        nq_spec = re.sub(r"[\s«»\"']+", " ", str(spec.get("q", "")).lower()).strip()
        if nq_spec and nq_spec in nq:
            return list(spec.get("results") or [])[:max_results]

    from ddgs import DDGS

    with DDGS() as ddgs:
        return list(ddgs.text(query, region="ru-ru", safesearch="moderate",
                              max_results=max_results) or [])


# ---------------------------------------------------------------------------
# Запасной поиск: HTML-выдача DuckDuckGo (когда библиотека ddgs молчит)
# ---------------------------------------------------------------------------

# Два разных хоста: обычная HTML-выдача и «облегчённая» (lite) — если один
# заблокирован/отдаёт капчу, второй часто работает.
_DDG_HTML = (
    "https://html.duckduckgo.com/html/?q={q}&kl=ru-ru",
    "https://lite.duckduckgo.com/lite/?q={q}",
)


def _clean_ddg_link(href: str) -> str:
    """Ссылка выдачи → прямой URL (DDG заворачивает их в /l/?uddg=…)."""
    from urllib.parse import parse_qs, unquote, urlparse

    if not href:
        return ""

    if href.startswith("//"):
        href = "https:" + href

    try:
        u = urlparse(href)
    except ValueError:
        return ""

    if "duckduckgo.com" in (u.netloc or ""):
        q = parse_qs(u.query or "")
        target = (q.get("uddg") or [""])[0]

        if target:
            return unquote(target)

    return href


def ddg_html_sync(query: str, max_results: int = 5) -> list[dict]:
    """Поиск по HTML-выдаче DDG — резервный канал, когда ddgs недоступен.

    Владелец видел в этапах «найдено площадок: 0» на всех формулировках: значит,
    библиотека ddgs молчала (лимит/блокировка/прокси). Возвращаем те же поля,
    что и ddg_text — url/title/body, чтобы конвейер не менялся.

    Никакого «парсинга ради парсинга»: берём только ссылки и сниппеты выдачи.
    """
    from urllib.parse import quote

    from bs4 import BeautifulSoup

    for tpl in _DDG_HTML:
        url = tpl.format(q=quote(query))

        try:
            status, html = _curl_cffi_get(url)
        except Exception:  # noqa: BLE001 — пробуем httpx
            try:
                status, html = _httpx_get(url)
            except Exception as e:  # noqa: BLE001
                log.info("webread: HTML-поиск недоступен (%s): %s", url.split("?")[0], e)
                continue

        if status >= 400 or not html:
            log.info("webread: HTML-поиск вернул %s (%s)", status, url.split("?")[0])
            continue

        soup = BeautifulSoup(html, "html.parser")
        rows: list[dict] = []

        for block in soup.select("div.result, tr"):
            # в lite-выдаче класс висит на ячейке таблицы, а не на ссылке
            a = block.select_one("a.result__a, .result-link a, a.result-link")

            if a is None:
                continue

            link = _clean_ddg_link(str(a.get("href") or "").strip())

            if not link.startswith("http"):
                continue

            sn = block.select_one(".result__snippet, .result-snippet")

            # lite-выдача кладёт сниппет в СЛЕДУЮЩУЮ строку таблицы
            if sn is None:
                nxt = block.find_next("tr")

                if nxt is not None:
                    sn = nxt.select_one(".result-snippet, .result__snippet")
            rows.append({
                "url": link,
                "title": a.get_text(" ", strip=True)[:200],
                "body": (sn.get_text(" ", strip=True)[:400] if sn is not None else ""),
            })

            if len(rows) >= max_results:
                break

        if rows:
            return rows

    return []


async def ddg_text(query: str, max_results: int = 5, timeout_sec: float = 60.0) -> list[dict]:
    """Поиск: библиотека ddgs → HTML-выдача DDG.

    Ошибку больше НЕ глотаем молча (раньше в журнале было только «молчит», и
    «найдено площадок: 0» выглядело как отсутствие спроса): пишем причину, а
    запасной канал даёт результаты, даже когда библиотека заблокирована.
    """
    err = ""

    try:
        rows = await asyncio.wait_for(asyncio.to_thread(ddg_text_sync, query, max_results),
                                      timeout=timeout_sec)

        if rows:
            return rows

        err = "пустая выдача"
    except Exception as e:  # noqa: BLE001 — поисковик не критичен, но причину видно
        err = f"{type(e).__name__}: {e}"[:200]

    try:
        rows = await asyncio.wait_for(asyncio.to_thread(ddg_html_sync, query, max_results),
                                      timeout=timeout_sec)

        if rows:
            log.info("webread: ddgs молчит (%s) — сработала HTML-выдача DDG (%s…)",
                     err, query[:40])
            return rows
    except Exception as e:  # noqa: BLE001
        err = f"{err}; html: {type(e).__name__}: {e}"[:300]

    log.warning("webread: поиск не дал результатов (%s…): %s", query[:40], err or "нет причин?")
    return []
