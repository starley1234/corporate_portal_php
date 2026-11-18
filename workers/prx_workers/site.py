"""Единый HTTP-клиент к сайту (для обработчиков).

Зачем: до этого каждый обработчик копировал свою `_post_site/_post` с чуть
разными сигнатурами (кто возвращал dict, кто tuple[bool,str,dict], кто
(sended, reason, dict)). Здесь общая часть:

  site_token(prefix)   — резолюция токена: PRXxxx_SITE_TOKEN → WORKER_SITE_TOKEN
                         → WORKER_API_TOKEN (возвращает (токен, откуда));
  api_base(prefix)     — адрес: PRXxxx_API_BASE → PRX120_API_BASE (корень сайта);
  module_api_base(prefix, path) — то же + путь модуля: root + "/prx115/api"
                         (свой PRXxxx_API_BASE перекрывает; для модульных
                         каналов с короткими путями — вместо корня);
  post_json(prefix, path, data, timeout, retries) — POST с X-Worker-Token,
                         двумя попытками и внятной причиной отказа.

Обработчик, переведённый на этот модуль, не должен хранить свой собственный
вариант _post_site — иначе конвенция снова разъедется. Пример — semantic.index.
"""

from __future__ import annotations

import asyncio
import errno
import ipaddress
import logging
import os
import socket
import ssl
from typing import Any
from urllib.parse import urlsplit

import httpx

log = logging.getLogger("prx.site")


def site_token(prefix: str) -> tuple[str, str]:
    """(токен, откуда взяли) для API сайта по имени обработчика.

    Порядок: <PREFIX>_SITE_TOKEN → WORKER_SITE_TOKEN → WORKER_API_TOKEN.
    В режиме pg WORKER_API_TOKEN может быть пуст — тогда обработчик честно
    сообщит «токен не настроен», а не молча пропустит доставку.
    """
    import os

    for name in (prefix + "_SITE_TOKEN", "WORKER_SITE_TOKEN", "WORKER_API_TOKEN"):
        value = (os.environ.get(name) or "").strip()
        if value:
            return value, name

    return "", ""


def api_base(prefix: str) -> str:
    """Адрес сайта для обработчика: <PREFIX>_API_BASE → PRX120_API_BASE.

    Один и тот же сайт не должен настраиваться дважды: у очереди prx120 уже есть
    PRX120_API_BASE, поэтому модульный канал берёт её как запасную — иначе
    обработчику пришлось бы требовать свою переменную ради того же адреса.

    Каналу с ПОЛНЫМ путём (semantic.index сам формирует /prx115/api/vectors)
    годится и корень сайта — см. origin_of(); такие каналы дополнительно
    смотрят на PRX120_API_URL, который в http-режиме уже задан.
    """
    import os

    for name in (prefix + "_API_BASE", "PRX120_API_BASE"):
        value = (os.environ.get(name) or "").strip().rstrip("/")

        if value:
            return value

    return ""


def module_api_base(prefix: str, default_path: str) -> str:
    """API-адрес модульного канала: <PREFIX>_API_BASE → общий корень + путь.

    Зачем: у модульных каналов пути короткие ("/items", "/analysis"), поэтому
    общего PRX120_API_BASE (корень сайта) им мало — вышел бы 404. Но заводить
    ради этого свою переменную на каждый модуль — размножать один и тот же
    адрес сайта по .env (замечание владельца 06.10.2026: «не раздувать .env»).
    Компромисс без переделок:

      * свой <PREFIX>_API_BASE по-прежнему перекрывает всё (канал на другом
        сайте) — поведение старых каналов не меняется;
      * без него адрес собирается из общего корня (PRX120_API_BASE, а в
        http-режиме — корень адреса очереди PRX120_API_URL, см. origin_of)
        и стандартного пути модуля: root + "/prx115/api".

    Первым её использует prx661_airadar; новые модульные каналы обязаны
    (правило AGENTS воркеров), старые переводятся по мере касания.
    """
    return module_api_config(prefix, default_path)[0]


def module_api_config(prefix: str, default_path: str) -> tuple[str, str]:
    """Фактическая база и ИМЯ источника; значение переменной не пишем в лог."""
    own_name = prefix + "_API_BASE"
    own = (os.environ.get(own_name) or "").strip().rstrip("/")
    if own:
        return own, own_name

    root = (os.environ.get("PRX120_API_BASE") or "").strip().rstrip("/")
    if root:
        return root + default_path, "PRX120_API_BASE"

    root = origin_of(os.environ.get("PRX120_API_URL", ""))
    return (root + default_path, "PRX120_API_URL") if root else ("", "не задан")


def origin_of(url: str) -> str:
    """Корень сайта из адреса его API: https://prx.ru/prx120/api → https://prx.ru.

    Зачем (задача 60): воркер в http-режиме уже знает адрес сайта — иначе он не
    забрал бы задачу из очереди (PRX120_API_URL), и токен у него тот же
    (WORKER_API_TOKEN = токен сайта). Требовать в этом режиме ЕЩЁ и отдельный
    адрес доставки — значит просить вписать тот же сайт второй раз; владелец
    увидел это как «в примерах нет этих переменных», а доставка векторов молчала.
    Теперь канал берёт корень отсюда.
    """
    import re

    value = (url or "").strip()

    if not value:
        return ""

    m = re.match(r"^(https?://[^/]+)", value)

    return m.group(1).rstrip("/") if m else ""


def safe_origin(url: str) -> str:
    """Только схема, hostname и порт: без userinfo, пути, query и fragment.

    Не печатаем произвольный исходный URL даже при ошибке его разбора.
    """
    import re

    try:
        parts = urlsplit(url or "")
        host = parts.hostname or ""
        if parts.scheme.lower() not in ("http", "https") or not host:
            return "(адрес не задан или некорректен)"
        try:
            ip = ipaddress.ip_address(host)
            host = f"[{ip}]" if ip.version == 6 else str(ip)
        except ValueError:
            if not re.fullmatch(r"[a-zA-Z0-9._-]{1,253}", host):
                return "(адрес не задан или некорректен)"
        port = parts.port  # может бросить ValueError для некорректного порта
        return f"{parts.scheme.lower()}://{host}" + (f":{port}" if port is not None else "")
    except (TypeError, ValueError):
        return "(адрес не задан или некорректен)"


def safe_base_details(base: str, expected_path: str) -> tuple[str, str, str, str]:
    """Классификация базы без вывода её произвольного пути или query."""
    try:
        parts = urlsplit(base or "")
        path = parts.path.rstrip("/")
        path_kind = "expected" if path == expected_path else "root" if not path else "other"
        return (path_kind, "yes" if parts.query else "no",
                "yes" if parts.fragment else "no", "yes" if parts.username is not None else "no")
    except (TypeError, ValueError):
        return "invalid", "unknown", "unknown", "unknown"


def network_error(exc: BaseException) -> str:
    """Безопасная классификация сети: только типы и системные коды, без текста исключений.

    httpx/httpcore оборачивают реальные ошибки в несколько исключений. Обходим
    цепочку и вложенные exception args, но никогда не печатаем их сообщения:
    там могут оказаться URL с токеном, заголовки или данные документа.
    """
    chain: list[BaseException] = []
    pending = [exc]
    seen: set[int] = set()
    while pending and len(chain) < 32:
        item = pending.pop()
        if id(item) in seen:
            continue
        seen.add(id(item))
        chain.append(item)
        for nested in (item.__cause__, item.__context__, *item.args):
            if isinstance(nested, BaseException):
                pending.append(nested)

    kind = "unknown"
    code = ""
    cert = next((e for e in chain if isinstance(e, ssl.SSLCertVerificationError)), None)
    dns = next((e for e in chain if isinstance(e, socket.gaierror)), None)
    os_error = next((e for e in chain if isinstance(e, OSError)
                     and isinstance(e.errno, int) and e.errno in errno.errorcode), None)
    if cert is not None:
        kind = "tls_certificate"
        verify_code = getattr(cert, "verify_code", None)
        if isinstance(verify_code, int):
            code = f", verify_code={verify_code}"
    elif any(isinstance(e, ssl.SSLError) for e in chain):
        kind = "tls"
    elif dns is not None:
        kind = "dns"
        if isinstance(dns.errno, int):
            code = f", dns_code={dns.errno}"
    elif os_error is not None:
        code_name = errno.errorcode[os_error.errno]
        kind = {
            "ECONNREFUSED": "connection_refused",
            "ECONNRESET": "connection_reset",
            "ENETUNREACH": "network_unreachable",
            "EHOSTUNREACH": "host_unreachable",
            "ETIMEDOUT": "timeout",
            "EACCES": "permission_denied",
        }.get(code_name, "os_error")
        code = f", errno={code_name}"
    elif any(isinstance(e, (httpx.TimeoutException, TimeoutError)) for e in chain):
        kind = "timeout"
    elif any(isinstance(e, httpx.ProxyError) for e in chain):
        kind = "proxy"
    elif any(isinstance(e, httpx.InvalidURL) for e in chain):
        kind = "invalid_url"
    elif any(isinstance(e, httpx.ConnectError) for e in chain):
        kind = "connect"
    return f"сеть: {type(exc).__name__}, причина={kind}{code}"


def private_http_error(response: httpx.Response) -> str:
    """Только код и статическая подсказка, без тела, Location и других заголовков."""
    status = response.status_code
    hint = {
        401: "проверьте авторизацию",
        403: "проверьте токен сайта и права",
        404: "маршрут или объект не найден",
        422: "сайт отклонил данные",
    }.get(status, "ошибка сайта" if status >= 500 else "неожиданный ответ")
    return f"HTTP {status}: {hint}"


def _http_error(response: httpx.Response, data: Any, url: str) -> str:
    """Отказ HTTP с фактическим адресом, но без userinfo/query/токенов и дампа HTML."""
    from urllib.parse import urlsplit, urlunsplit

    try:
        parts = urlsplit(url)
        safe_url = urlunsplit((parts.scheme, parts.netloc.rsplit('@', 1)[-1], parts.path, '', ''))
    except ValueError:
        safe_url = 'некорректный адрес'
    if isinstance(data, dict) and data.get('error'):
        detail = str(data['error'])[:300]
    else:
        text = (response.text or '').strip()
        detail = ('сервер вернул HTML вместо JSON' if text.lower().startswith(('<!doctype', '<html'))
                  else text[:200] or '—')
    return f'HTTP {response.status_code}: {detail} [URL: {safe_url}]'


async def get_json(prefix: str, path: str,
                   timeout: float = 60.0, base: str | None = None,
                   safe_errors: bool = False) -> tuple[bool, str, dict]:
    """GET с сайта с X-Worker-Token. Возвращает (ok, причина, json-ответ).

    Одна попытка (как у prx600._get_site): быстрые чтения (профили) не нуждаются
    в ретраях — при сбое обработчик честно поднимает RuntimeError.

    base — готовый адрес (например, site.module_api_base(...)); без него
    работает старая резолюция api_base(prefix). При safe_errors причина не
    содержит тело ответа или произвольный текст исключений.
    """
    if base is None:
        base = api_base(prefix)

    if not base:
        return False, (f"адрес сайта не задан: {prefix}_API_BASE "
                       f"(или общий PRX120_API_BASE) в workers/.env"), {}

    token, _token_src = site_token(prefix)

    if not token:
        return False, (f"токен сайта не задан: {prefix}_SITE_TOKEN или "
                       f"WORKER_SITE_TOKEN (= WORKER_API_TOKEN из .env сайта)"), {}

    try:
        async with httpx.AsyncClient(timeout=timeout) as client:
            r = await client.get(base + path, headers={"X-Worker-Token": token})
    except Exception as e:  # noqa: BLE001
        return False, network_error(e) if safe_errors else f"сеть: {e.__class__.__name__}", {}

    try:
        j = r.json()
    except Exception:  # noqa: BLE001
        j = None

    if r.status_code != 200:
        reason = private_http_error(r) if safe_errors else _http_error(r, j, base + path)
        return False, reason, {}

    if safe_errors and not isinstance(j, dict):
        return False, "HTTP 200: сайт вернул не JSON-объект", {}
    if safe_errors and isinstance(j, dict) and j.get("error"):
        return False, "HTTP 200: сайт сообщил об ошибке", {}
    return True, "", (j if isinstance(j, dict) else {})


async def post_json(prefix: str, path: str, data: dict,
                    timeout: float = 60.0, retries: int = 2,
                    json_body: bool = False,
                    base: str | None = None,
                    safe_errors: bool = False) -> tuple[bool, str, dict]:
    """POST на сайт. Возвращает (ok, причина, json-ответ).

    data отдаётся как form (по умолчанию) или как JSON-тело при json_body=True
    (так ходят PRXxxx-эндпоинты, читающие request body, напр. /touches/api/step).
    Причина человекочитаемая; safe_errors исключает тело ответа, исходный URL
    и трассировку и используется для обработки приватных документов.

    base — готовый адрес (например, site.module_api_base(...)); без него
    работает старая резолюция api_base(prefix).
    """
    if base is None:
        base = api_base(prefix)

    if not base:
        return False, (f"адрес сайта не задан: {prefix}_API_BASE "
                       f"(или общий PRX120_API_BASE) в workers/.env"), {}

    token, token_src = site_token(prefix)

    if not token:
        return False, (f"токен сайта не задан: {prefix}_SITE_TOKEN или "
                       f"WORKER_SITE_TOKEN (= WORKER_API_TOKEN из .env сайта)"), {}

    last: tuple[bool, str, dict] = (False, "нет попыток", {})

    for attempt in range(1, retries + 1):
        try:
            async with httpx.AsyncClient(timeout=timeout) as client:
                if json_body:
                    r = await client.post(base + path, json=data,
                                          headers={"X-Worker-Token": token})
                else:
                    r = await client.post(base + path, data=data,
                                          headers={"X-Worker-Token": token})

            try:
                j = r.json()
            except Exception:  # noqa: BLE001 — не-JSON ответ не роняет доставку
                j = None if safe_errors else {}

            if r.status_code == 200:
                if safe_errors and not isinstance(j, dict):
                    return False, "HTTP 200: сайт вернул не JSON-объект", {}
                if isinstance(j, dict) and j.get("error"):
                    if safe_errors:
                        return False, "HTTP 200: сайт сообщил об ошибке", {}
                    return False, f"сайт: {str(j['error'])[:300]}", j

                return True, "", (j if isinstance(j, dict) else {})

            reason = private_http_error(r) if safe_errors else _http_error(r, j, base + path)

            if r.status_code == 401:
                reason += f" — токен ({token_src}) не совпал с токеном сайта"

            log.warning("site: POST %s -> %s", path, reason)
            last = (False, reason, {} if safe_errors else j)
        except Exception as e:  # noqa: BLE001
            reason = network_error(e) if safe_errors else f"сеть: {e.__class__.__name__}"
            if safe_errors:
                log.warning("site: POST %s failed (попытка %d): %s", path, attempt, reason)
            else:
                log.warning("site: POST %s failed (попытка %d)", path, attempt, exc_info=True)
            last = (False, reason, {})

        if attempt < retries:
            await asyncio.sleep(1.5 * attempt)

    return last


__all__ = ["site_token", "post_json", "get_json", "api_base"]


async def post_file(prefix: str, path: str, data: dict, name: str, raw: bytes,
                    base: str | None = None, timeout: float = 90.0) -> tuple[bool, str, dict]:
    """Один файл multipart без base64-копий. Общая токенизация как у post_json.

    Повтор допустим для идемпотентных приёмных (токен версии + имя формата).
    Не используем произвольные URL из ответа модели: base задаёт сам модуль.
    """
    base = base if base is not None else api_base(prefix)
    token, _ = site_token(prefix)
    if not base or not token:
        return False, 'Не настроен адрес сайта или токен доставки файла', {}
    for attempt in range(2):
        try:
            async with httpx.AsyncClient(timeout=timeout) as client:
                response = await client.post(base + path, data=data,
                    files={'file': (name, raw, 'application/octet-stream')},
                    headers={'X-Worker-Token': token})
            try:
                result = response.json()
            except ValueError:
                result = {}
            if response.is_success and isinstance(result, dict) and result.get('ok'):
                return True, '', result
            reason = _http_error(response, result, base + path)
            if response.status_code < 500:
                return False, reason, result if isinstance(result, dict) else {}
        except (httpx.HTTPError, OSError) as exc:
            reason = 'сеть: ' + type(exc).__name__
        if attempt == 0:
            await asyncio.sleep(1)
    return False, reason, {}
