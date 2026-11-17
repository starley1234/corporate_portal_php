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
import logging
from typing import Any

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
    import os

    own = (os.environ.get(prefix + "_API_BASE") or "").strip().rstrip("/")

    if own:
        return own

    root = (os.environ.get("PRX120_API_BASE") or "").strip().rstrip("/") \
        or origin_of(os.environ.get("PRX120_API_URL", ""))

    return (root + default_path) if root else ""


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
                   timeout: float = 60.0, base: str | None = None) -> tuple[bool, str, dict]:
    """GET с сайта с X-Worker-Token. Возвращает (ok, причина, json-ответ).

    Одна попытка (как у prx600._get_site): быстрые чтения (профили) не нуждаются
    в ретраях — при сбое обработчик честно поднимает RuntimeError.

    base — готовый адрес (например, site.module_api_base(...)); без него
    работает старая резолюция api_base(prefix).
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
        return False, f"сеть: {e.__class__.__name__}", {}

    try:
        j = r.json()
    except Exception:  # noqa: BLE001
        j = {}

    if r.status_code != 200:
        return False, _http_error(r, j, base + path), {}

    return True, "", (j if isinstance(j, dict) else {})


async def post_json(prefix: str, path: str, data: dict,
                    timeout: float = 60.0, retries: int = 2,
                    json_body: bool = False,
                    base: str | None = None) -> tuple[bool, str, dict]:
    """POST на сайт. Возвращает (ok, причина, json-ответ).

    data отдаётся как form (по умолчанию) или как JSON-тело при json_body=True
    (так ходят PRXxxx-эндпоинты, читающие request body, напр. /touches/api/step).
    Причина всегда человекочитаемая: HTTP-код + тело ошибки сайта, либо
    'сеть: <исключение>'. 401 дополняется подсказкой про токен.

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
                j = {}

            if r.status_code == 200:
                if isinstance(j, dict) and j.get("error"):
                    return False, f"сайт: {str(j['error'])[:300]}", j

                return True, "", (j if isinstance(j, dict) else {})

            reason = _http_error(r, j, base + path)

            if r.status_code == 401:
                reason += f" — токен ({token_src}) не совпал с WORKER_API_TOKEN сайта"

            log.warning("site: POST %s -> %s", path, reason)
            last = (False, reason, j)
        except Exception as e:  # noqa: BLE001
            reason = f"сеть: {e.__class__.__name__}"
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
