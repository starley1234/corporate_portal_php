"""nj610.inbox — Входящие под контролем: IMAP → классификация → черновики.

Пайплайн: стандартный imaplib (без зависимостей) забирает непрочитанные
письма (текстовые части, до 20 за раз) → POST /inbox/api/mail → ОДИН
LLM-вызов: категория, приоритет, черновик ответа → POST /inbox/api/drafts.

Env: NJ610_INBOX_HOST (imap.yandex.ru:993), NJ610_INBOX_USER,
NJ610_INBOX_PASS (пароль приложения!), NJ610_INBOX_FOLDER (INBOX),
NJ610_INBOX_API_BASE, NJ610_INBOX_SITE_TOKEN (или WORKER_API_TOKEN),
NJ610_INBOX_LIMIT (20).
"""

from __future__ import annotations

import email
import email.header
import imaplib
import logging
import os
import re


from .. import site
from .. import webread
from ..llmchat import chat as _llm

log = logging.getLogger("n2j.nj610")

CHANNEL = "nj610.inbox"

SYSTEM = (
    "Ты ассистент владельца бизнеса. Дают входящие письма. Для каждого: "
    "category — коротко тип (заказ, вопрос, счёт, спам, рассылка, партнёрство, "
    "жалоба, другое); priority — high (клиент/деньги/срочно), normal, low "
    "(рассылки/уведомления); draft — черновик ответа ПО-РУССКИ от первого лица "
    "(вежливо, по делу, 2-5 предложений) ТОЛЬКО для писем, где ответ уместен "
    "(заказ/вопрос/партнёрство/жалоба); для спама и рассылок draft = пустая "
    "строка. Ответ — ТОЛЬКО JSON: {\"drafts\": [{\"message_id\": \"...\", "
    "\"category\": \"...\", \"priority\": \"...\", \"draft\": \"...\"}]}."
)


def _env(key: str, default: str = "") -> str:
    return os.environ.get(f"NJ610_INBOX_{key}", default).strip()


def _limit() -> int:
    try:
        return max(1, int(_env("LIMIT", "20")))
    except ValueError:
        return 20


def _dec_hdr(raw) -> str:
    """RFC2047-заголовок → строка (кодировки/=?utf-8?...?=)."""
    if raw is None:
        return ""

    try:
        parts = email.header.decode_header(str(raw))
        out = []

        for text, enc in parts:
            if isinstance(text, bytes):
                out.append(text.decode(enc or "utf-8", "ignore"))
            else:
                out.append(text)

        return "".join(out)
    except Exception:  # noqa: BLE001 — битый заголовок не роняет задачу
        return str(raw)


def _body_of(msg) -> str:
    """Текстовая часть письма (text/plain, иначе text/html без тегов)."""
    body = ""

    if msg.is_multipart():
        for part in msg.walk():
            ctype = part.get_content_type()
            disp = str(part.get("Content-Disposition") or "")

            if "attachment" in disp:
                continue

            if ctype == "text/plain":
                payload = part.get_payload(decode=True) or b""
                charset = part.get_content_charset() or "utf-8"
                body = payload.decode(charset, "ignore")
                break

            if ctype == "text/html" and not body:
                payload = part.get_payload(decode=True) or b""
                charset = part.get_content_charset() or "utf-8"
                body = re.sub(r"<[^>]+>", " ", payload.decode(charset, "ignore"))
    else:
        payload = msg.get_payload(decode=True) or b""
        charset = msg.get_content_charset() or "utf-8"
        body = payload.decode(charset, "ignore")

    return re.sub(r"[ \t]+", " ", re.sub(r"\n{3,}", "\n\n", body)).strip()


def _fetch_unseen() -> list[dict]:
    """Непрочитанные письма из IMAP → [{message_id, from, subject, body, received_at}]."""
    host = _env("HOST")
    user = _env("USER")
    password = _env("PASS")
    folder = _env("FOLDER", "INBOX")

    if not (host and user and password):
        log.info("nj610_inbox: ящик не настроен (NJ610_INBOX_HOST/USER/PASS) — пропуск")
        return []

    if ":" in host:
        imap_host, _, port = host.partition(":")
        port = int(port or 993)
    else:
        imap_host, port = host, 993

    imap = imaplib.IMAP4_SSL(imap_host, port)
    messages: list[dict] = []

    try:
        imap.login(user, password)
        imap.select(folder, readonly=True)

        status, data = imap.search(None, "UNSEEN")

        if status != "OK" or not data or not data[0]:
            return []

        ids = data[0].split()[-_limit():]

        for num in ids:
            status, msg_data = imap.fetch(num, "(RFC822)")

            if status != "OK" or not msg_data or msg_data[0] is None:
                continue

            msg = email.message_from_bytes(msg_data[0][1])
            date_ts = 0

            try:
                from email.utils import parsedate_to_datetime

                date_ts = int(parsedate_to_datetime(msg.get("Date", "")).timestamp())
            except Exception:  # noqa: BLE001
                pass

            messages.append({
                "message_id": _dec_hdr(msg.get("Message-ID")) or f"noid-{num.decode()}",
                "from": _dec_hdr(msg.get("From")),
                "subject": _dec_hdr(msg.get("Subject")),
                "body": _body_of(msg)[:8000],
                "received_at": date_ts,
            })
    finally:
        try:
            imap.logout()
        except Exception:  # noqa: BLE001
            pass

    return messages


async def _post(ctx, path: str, data: dict) -> dict:
    """POST на сайт через общий слой site.post_json; ошибка = RuntimeError."""
    ok, reason, j = await site.post_json("NJ610_INBOX", path, data)

    if not ok:
        if "_API_BASE не задан" in reason or "токен сайта не задан" in reason:
            log.info("nj610_inbox: API сайта не настроен — отправка пропущена")
            return {}

        raise RuntimeError(f"nj610_inbox: сайт {path} → {reason}")

    return j if isinstance(j, dict) else {}


async def handle(ctx, payload: dict) -> dict:
    """Забрать письма, разобрать, положить черновики на сайт."""
    import json

    # Этапы — в журнал очереди (монитор + статус-ряд страницы модуля)
    await ctx.progress("Забираю письма", "почтовый ящик")

    messages = await _fetch_unseen_async()

    if not messages:
        await ctx.progress("Готово", "новых писем нет")
        return {"ok": True, "fetched": 0}

    await ctx.progress("Разбираю письма", f"получено: {len(messages)}")

    await _post(ctx, "/mail", {"messages": json.dumps(messages, ensure_ascii=False)})

    # --- LLM: категории и черновики одним вызовом -------------------------------
    await ctx.progress("ИИ готовит черновики", f"писем: {len(messages)}")
    parts = ["ВХОДЯЩИЕ ПИСЬМА:"]

    for m in messages:
        parts.append(f"--- message_id: {m['message_id']}")
        parts.append(f"От: {m['from']} · Тема: {m['subject']}")
        parts.append((m["body"] or "(пусто)")[:1500])
        parts.append("")

    raw = await _llm(ctx, [{"role": "system", "content": SYSTEM},
                           {"role": "user", "content": "\n".join(parts)}],
                     "nj610_inbox", model_env="NJ610_INBOX_MODEL", max_tokens=3000, timeout=300)

    parsed = webread.json_from_text(raw)
    drafts = parsed.get("drafts") if isinstance(parsed, dict) else None

    if isinstance(drafts, list) and drafts:
        known = {m["message_id"] for m in messages}

        for d in drafts:
            if isinstance(d, dict) and d.get("message_id") not in known:
                d["message_id"] = ""  # чужой message_id — вырезаем

        drafts = [d for d in drafts if isinstance(d, dict) and d.get("message_id")]
        res = await _post(ctx, "/drafts", {"drafts": json.dumps(drafts, ensure_ascii=False)})
        log.info("nj610_inbox: писем %d, черновиков применено %s", len(messages), res.get("applied", "?"))

    return {"ok": True, "fetched": len(messages)}


async def _fetch_unseen_async():
    """IMAP блокирующий — выносим в поток, чтобы не стопорить цикл воркера."""
    import asyncio

    return await asyncio.to_thread(_fetch_unseen)
