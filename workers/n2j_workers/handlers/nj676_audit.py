"""nj676.audit — полевой ИИ-агент исследователь открытых MCP-серверов («поиск алмазов»).

Зачем отдельный канал:
  Обычная проба (`nj676.probe`) проверяет только то, что сервер ответил на
  `initialize` и `tools/list`. Этого мало, чтобы понять:
    1. даёт ли сервер реальную пользу агенту или это пустышка / демо-заглушка;
    2. требуют ли инструменты скрытый API-ключ на первом же `tools/call`;
    3. безопасны ли описания и ответы инструментов (нет ли prompt injection,
       скрытых инструкций для модели, опасных мутаций без пометок);
    4. какие конкретные задачи этим сервером стоит решать («алмазы каталога»).

Как работает воркер-исследователь:
  1. получает от сайта список открытых удалённых MCP-серверов (`targets`);
  2. подключается к каждому серверу (`initialize` + `tools/list`), проверяет
     схемы и сканирует описания инструментов на риски безопасности;
  3. выбирает 1–2 безопасных read-only инструмента, подбирает тестовые
     аргументы по JSON Schema и выполняет живой пробный вызов `tools/call`;
  4. передаёт досье (метаданные, инструменты, скан безопасности и реальный
     ответ сервера на `tools/call`) в LLM для составления краткого экспертного
     отчёта (а если модель недоступна — строит честный детерминированный отчёт
     по результатам живого вызова);
  5. отправляет отчёт на `POST /mcphub/api/audit-item`, где сайт сохраняет его
     в `nj_676_audits` и `nj_676_servers`, индексирует в полнотекстовом и
     семантическом поиске и ранжирует серверы по степени полезности.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import time
from urllib.parse import urlparse

import httpx

from .. import site, webread
from ..llmchat import chat_with_usage as _llm
from .nj676_mcphub import (
    _PREFIX,
    _clean_description,
    _env_int,
    _probe_remote,
    _site_base,
    _site_post,
    _strip_think,
    _think_options,
)

log = logging.getLogger("n2j.nj676")

CHANNEL = "nj676.audit"

SYSTEM = (
    "Ты — ведущий архитектор ИИ-агентов и аудитор безопасности каталога MCP-серверов N2J.ru. "
    "Мы только что подключились к внешнему открытому MCP-серверу, сняли список "
    "его инструментов, проверили схемы на безопасность и выполнили живой пробный "
    "вызов tools/call. Твоя задача — составить глубокий, но лаконичный экспертный "
    "отчёт СТРОГО НА РУССКОМ ЯЗЫКЕ.\n"
    "Главное требование к разделу «use_cases» («Реальное применение для агентов»): "
    "КАТЕГОРИЧЕСКИ ЗАПРЕЩЕНО просто перечислять названия инструментов или копировать/переводить "
    "их описания в формате «tool_name: что делает». Вместо этого дай продуманный "
    "инженерный вывод на основе реального ответа сервера и связки его инструментов: "
    "какую прикладную задачу ИИ-агент закрывает этим сервером, в какой рабочий процесс "
    "(workflow) его встроить и какую проверенную пользу это даёт агенту (например, "
    "ответы без галлюцинаций по живому коду/истории коммитов, автоматический аудит "
    "изменений перед релизом, обогащение контекста актуальными данными в реальном времени). "
    "Опирайся ТОЛЬКО на факты из досье. Отвечай СТРОГО одним JSON-объектом без markdown."
)

PROMPT_TEMPLATE = (
    "Проанализируй полевое досье MCP-сервера (включая реальный ответ живого вызова tools/call) "
    "и верни JSON строго такого вида (ВСЕ ТЕКСТЫ СТРОГО НА РУССКОМ ЯЗЫКЕ):\n"
    "{\n"
    '  "utility_score": 0..100,\n'
    '  "safety_score": 0..100,\n'
    '  "verdict": "gem" | "useful" | "niche" | "questionable" | "broken",\n'
    '  "is_gem": true | false,\n'
    '  "summary": "2–3 содержательных предложения на русском: какую ценность сервер даёт ИИ-агенту, что показал живой пробный вызов и почему сервер заслуживает такой оценки",\n'
    '  "use_cases": [\n'
    '    "3–4 продуманных сценария реального применения для ИИ-агентов на русском языке (каждый по 1–2 предложения): какую задачу и в каком workflow агент решает с помощью этого сервера и почему это надёжнее обычной генерации LLM. НЕ перечисляй просто имена инструментов и НЕ оставляй английский текст!"\n'
    "  ],\n"
    '  "safety_notes": "1–2 предложения на русском про безопасность: чистота JSON Schema, соотношение read-only и пишущих операций, отсутствие prompt-injection и скрытых требований ключа",\n'
    '  "observations": "2–3 предложения на русском: конкретные выводы по итогам живого теста tools/call (какие именно данные вернул сервер, скорость отклика, свежесть и структура ответа)"\n'
    "}\n\n"
    "Правила оценки:\n"
    "- «gem» (is_gem: true, utility_score >= 80, safety_score >= 75) ставь только "
    "серверам, которые реально отвечают без ключа, имеют валидные схемы и возвращают "
    "осмысленный практический результат при живом вызове.\n"
    "- Если при пробном вызове сервер потребовал API-ключ/OAuth или вернул ошибку "
    "авторизации — укажи это в observations и safety_notes, не ставь is_gem: true.\n"
    "- Если сервер не ответил на живую пробу — verdict: \"broken\", utility_score <= 15.\n"
    "- Весь текст (summary, use_cases, safety_notes, observations) обязан быть на грамотном русском языке.\n"
)

_INJECTION_RE = re.compile(
    r"(ignore\s+(all\s+)?(previous|prior)\s+instructions|"
    r"system\s+prompt|you\s+must\s+always|do\s+not\s+tell\s+the\s+user|"
    r"<script\b|javascript:|data:text/html|"
    r"\bcurl\s+https?://|\bwget\s+https?://|\bexfiltrat)",
    re.IGNORECASE,
)

_MUTATING_RE = re.compile(
    r"(?:^|[_\-\s])(create|update|delete|remove|drop|destroy|write|send|post|"
    r"publish|pay|charge|transfer|execute|exec|run_command|sign_up|signup|"
    r"register|reset|modify|patch|put|insert)(?:$|[_\-\s])",
    re.IGNORECASE,
)

_AUTH_ERR_RE = re.compile(
    r"(unauthorized|unauthenticated|invalid[_\s-]*api[_\s-]*key|api\s*key\s*required|"
    r"authentication\s*required|missing\s*token|bearer\s*token|401\b|403\b|"
    r"access\s*denied|forbidden|quota\s*exceeded|payment\s*required)",
    re.IGNORECASE,
)


def _is_mutating_tool(tool: dict) -> bool:
    """Определить, может ли инструмент менять данные или вызывать побочные эффекты."""
    annotations = tool.get("annotations") if isinstance(tool.get("annotations"), dict) else {}
    if annotations.get("readOnlyHint") is True:
        return False
    if annotations.get("destructiveHint") is True:
        return True
    name = str(tool.get("name") or "")
    desc = str(tool.get("description") or "")
    return bool(_MUTATING_RE.search(name)) or bool(
        re.search(r"\b(deletes?|creates?|modifies|updates?|sends?\s+email|charges?)\b", desc, re.IGNORECASE)
    )


def _security_scan(tools: list[dict], server_desc: str = "") -> dict:
    """Статический аудит безопасности списка инструментов и их JSON Schema."""
    issues: list[str] = []
    mutating_count = 0
    readonly_count = 0
    schema_ok_count = 0
    injection_hits = 0

    if _INJECTION_RE.search(server_desc or ""):
        injection_hits += 1
        issues.append("подозрительная инструкция в описании сервера")

    for tool in tools:
        if not isinstance(tool, dict):
            continue
        name = str(tool.get("name") or "")
        desc = str(tool.get("description") or "")
        schema = tool.get("inputSchema") if isinstance(tool.get("inputSchema"), dict) else {}

        if _INJECTION_RE.search(name + " " + desc):
            injection_hits += 1
            issues.append(f"подозрительный паттерн (prompt injection) в инструменте {name}")

        if _is_mutating_tool(tool):
            mutating_count += 1
        else:
            readonly_count += 1

        props = schema.get("properties")
        if schema.get("type") == "object" and (props is None or isinstance(props, dict)):
            schema_ok_count += 1
        else:
            issues.append(f"нестандартная JSON Schema у {name}")

    total = len(tools)
    safety = 85
    if total == 0:
        safety = 40
        issues.append("список инструментов пуст")
    else:
        if schema_ok_count == total:
            safety += 8
        elif schema_ok_count < total:
            safety -= min(20, (total - schema_ok_count) * 5)

        if mutating_count == 0:
            safety += 5
        elif mutating_count > readonly_count:
            safety -= 10
            issues.append(f"преобладают пишущие/мутирующие инструменты ({mutating_count} из {total})")

        if injection_hits > 0:
            safety = min(40, safety - min(70, injection_hits * 50))

    return {
        "safety_score": max(5, min(100, safety)),
        "total_tools": total,
        "readonly_tools": readonly_count,
        "mutating_tools": mutating_count,
        "schema_ok_tools": schema_ok_count,
        "injection_hits": injection_hits,
        "issues": issues[:6],
    }


def _build_test_arguments(schema: dict) -> dict | None:
    """Подобрать безопасные тестовые аргументы для read-only вызова по схеме.

    Если инструмент требует сложные или секретные параметры (api_key, token,
    password, url, file_path, id записи), возвращаем None — угадывать чужие ID
    нельзя. Для простых поисковых/справочных параметров подставляем нейтральные
    значения.
    """
    if not isinstance(schema, dict):
        return {}
    props = schema.get("properties")
    if not isinstance(props, dict) or not props:
        return {}

    required = schema.get("required")
    req_list = [str(r) for r in required] if isinstance(required, list) else []

    args: dict = {}
    for key in req_list:
        prop = props.get(key) if isinstance(props.get(key), dict) else {}
        k_low = key.lower()

        # Не пытаемся угадывать ключи, токены, пароли или внутренние UUID.
        if any(sec in k_low for sec in ("key", "token", "secret", "password", "auth", "credential", "cookie")):
            return None

        if "default" in prop and prop["default"] is not None:
            args[key] = prop["default"]
            continue
        if isinstance(prop.get("enum"), list) and prop["enum"]:
            args[key] = prop["enum"][0]
            continue

        ptype = str(prop.get("type") or "string")
        if ptype == "boolean":
            args[key] = False
        elif ptype in ("integer", "number"):
            minimum = prop.get("minimum")
            args[key] = int(minimum) if isinstance(minimum, (int, float)) else 1
        elif ptype == "string":
            if any(w in k_low for w in ("query", "search", "keyword", "term", "topic", "q", "text")):
                args[key] = "mcp"
            elif any(w in k_low for w in ("city", "location", "place")):
                args[key] = "London"
            elif any(w in k_low for w in ("country", "lang", "language", "locale")):
                args[key] = "en"
            elif any(w in k_low for w in ("currency", "coin", "symbol", "ticker")):
                args[key] = "BTC"
            elif "date" in k_low:
                args[key] = "2026-10-24"
            elif len(req_list) == 1:
                args[key] = "test"
            else:
                return None
        else:
            return None

    return args


def _pick_safe_tools(tools: list[dict], max_tools: int = 2) -> list[tuple[dict, dict]]:
    """Выбрать до max_tools безопасных read-only инструментов с готовыми аргументами."""
    candidates: list[tuple[int, dict, dict]] = []

    for tool in tools:
        if not isinstance(tool, dict) or not tool.get("name"):
            continue
        if _is_mutating_tool(tool):
            continue

        schema = tool.get("inputSchema") if isinstance(tool.get("inputSchema"), dict) else {"type": "object"}
        args = _build_test_arguments(schema)
        if args is None:
            continue

        # Приоритет: инструменты без обязательных параметров или с простым поиском.
        req = schema.get("required") if isinstance(schema.get("required"), list) else []
        priority = 0 if len(req) == 0 else (1 if len(req) == 1 else 2)
        candidates.append((priority, tool, args))

    candidates.sort(key=lambda item: item[0])
    return [(tool, args) for _, tool, args in candidates[:max_tools]]


async def _call_remote_tool(url: str, tool_name: str, arguments: dict) -> dict:
    """Выполнить безопасный пробный JSON-RPC вызов tools/call к открытому MCP-серверу."""
    timeout = _env_int("NJ676_AUDIT_CALL_TIMEOUT", 8, 3, 20)

    try:
        parsed = urlparse(url)
        if parsed.scheme != "https" or not parsed.hostname:
            return {
                "tool": tool_name, "ok": False, "latency_ms": 0,
                "sample": "", "note": "адрес не HTTPS", "auth_required": False,
            }
        await asyncio.to_thread(webread.assert_public_host, parsed.hostname)
    except Exception as exc:  # noqa: BLE001
        return {
            "tool": tool_name, "ok": False, "latency_ms": 0,
            "sample": "", "note": f"хост отклонён: {type(exc).__name__}", "auth_required": False,
        }

    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        "User-Agent": "n2j-mcphub-auditor/1.0",
    }
    started = time.monotonic()

    def _run_call() -> tuple[int, dict | None, str]:
        with httpx.Client(timeout=timeout, follow_redirects=False) as client:
            init_resp = client.post(url, headers=headers, json={
                "jsonrpc": "2.0", "id": 1, "method": "initialize",
                "params": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {},
                    "clientInfo": {"name": "n2j-mcphub-auditor", "version": "1.0"},
                },
            })
            session_id = init_resp.headers.get("Mcp-Session-Id") or init_resp.headers.get("mcp-session-id")
            call_headers = dict(headers)
            if session_id:
                call_headers["Mcp-Session-Id"] = session_id

            resp = client.post(url, headers=call_headers, json={
                "jsonrpc": "2.0", "id": 2, "method": "tools/call",
                "params": {"name": tool_name, "arguments": arguments},
            })
            status_code = resp.status_code
            raw_text = resp.text or ""
            try:
                return status_code, resp.json(), raw_text
            except ValueError:
                payload = None
                for line in raw_text.splitlines():
                    if line.startswith("data:"):
                        try:
                            payload = json.loads(line[5:].strip())
                        except ValueError:
                            continue
                return status_code, payload, raw_text

    try:
        status_code, payload, raw_text = await asyncio.to_thread(_run_call)
    except Exception as exc:  # noqa: BLE001
        ms = int((time.monotonic() - started) * 1000)
        return {
            "tool": tool_name, "ok": False, "latency_ms": ms,
            "sample": "", "note": f"ошибка сети: {type(exc).__name__}", "auth_required": False,
        }

    ms = int((time.monotonic() - started) * 1000)

    if status_code in (401, 403):
        return {
            "tool": tool_name, "ok": False, "latency_ms": ms,
            "sample": "", "note": f"HTTP {status_code}: сервер требует авторизацию",
            "auth_required": True,
        }

    if status_code != 200 or not isinstance(payload, dict):
        auth_hit = bool(_AUTH_ERR_RE.search(raw_text[:400]))
        return {
            "tool": tool_name, "ok": False, "latency_ms": ms,
            "sample": "", "note": f"HTTP {status_code}", "auth_required": auth_hit,
        }

    if "error" in payload and isinstance(payload["error"], dict):
        err_msg = str(payload["error"].get("message") or "ошибка JSON-RPC")
        auth_hit = bool(_AUTH_ERR_RE.search(err_msg))
        return {
            "tool": tool_name, "ok": False, "latency_ms": ms,
            "sample": _clean_description(err_msg, 240),
            "note": "ошибка вызова: " + _clean_description(err_msg, 120),
            "auth_required": auth_hit,
        }

    result = payload.get("result") if isinstance(payload.get("result"), dict) else {}
    is_error = bool(result.get("isError"))
    texts: list[str] = []
    for block in (result.get("content") or [])[:4]:
        if isinstance(block, dict) and isinstance(block.get("text"), str):
            texts.append(block["text"])
    if not texts and result:
        texts.append(json.dumps(result, ensure_ascii=False)[:400])

    sample = _clean_description(" ".join(texts), 500)
    auth_hit = bool(_AUTH_ERR_RE.search(sample)) if is_error else False

    return {
        "tool": tool_name,
        "ok": (not is_error) and bool(sample),
        "latency_ms": ms,
        "sample": sample,
        "note": "ответил данными" if (not is_error and sample) else ("вернул ошибку" if is_error else "пустой ответ"),
        "auth_required": auth_hit,
    }


_CYRILLIC_RE = re.compile(r"[а-яёА-ЯЁ]")
_LATIN_RE = re.compile(r"[a-zA-Z]")


def _is_russian_text(text: str, min_ratio: float = 0.35) -> bool:
    """Проверить, что строка действительно написана по-русски, а не оставлена на английском."""
    cyr = len(_CYRILLIC_RE.findall(text or ""))
    lat = len(_LATIN_RE.findall(text or ""))
    if cyr < 12:
        return False
    return (cyr / max(1, cyr + lat)) >= min_ratio


def _is_good_russian_use_cases(items: list, tool_names: list[str]) -> bool:
    """Проверить, что use_cases — осмысленные выводы на русском, а не дамп «tool: english desc»."""
    if not isinstance(items, list) or len(items) < 2:
        return False
    lower_tools = {t.lower().strip() for t in tool_names if t}
    valid_count = 0
    for raw in items[:5]:
        line = str(raw or "").strip()
        if not line or not _is_russian_text(line, 0.35):
            return False
        # Отсекаем примитивный шаблон «tool_name: описание...»
        prefix = line.split(":", 1)[0].strip().lower()
        if prefix in lower_tools and len(line) < 90:
            return False
        valid_count += 1
    return valid_count >= 2


def _synthesize_russian_use_cases(name: str, desc_ru: str, tools: list[dict], tested_tools: list[dict]) -> list[str]:
    """Сформировать продуманные сценарии применения на русском языке (фоллбэк без сырого английского)."""
    tool_names = [str(t.get("name") or "") for t in tools if isinstance(t, dict) and t.get("name")]
    blob = " ".join(
        [name, desc_ru]
        + [f"{t.get('name', '')} {t.get('description', '')}" for t in tools if isinstance(t, dict)]
    ).lower()

    cases: list[str] = []

    if any(w in blob for w in ("product_expert", "ask_", "code", "repo", "commit", "changelog", "recent_changes", "git")):
        cases.append(
            f"Верификация ответов ИИ-агента по живому исходному коду и истории коммитов «{name}» без галлюцинаций — агент запрашивает проверенную фактуру напрямую у сервера перед ответом разработчику."
        )
    if any(w in blob for w in ("recent_changes", "changelog", "release", "commit", "diff", "version")):
        cases.append(
            "Автоматический мониторинг последних изменений и семантического списка правок (changelog) перед деплоем, ревью или разбором регрессий."
        )
    if any(w in blob for w in ("search", "find", "query", "lookup", "fetch", "docs", "documentation", "knowledge")):
        cases.append(
            f"Обогащение контекста агента актуальными справочными данными и документацией через инструменты {', '.join(tool_names[:3])} в реальном времени."
        )
    if any(w in blob for w in ("weather", "forecast", "meteo", "station", "temperature")):
        cases.append(
            "Получение актуальных метеосводок и данных станций наблюдения для планировщиков маршрутов, логистических и справочных ассистентов."
        )
    if any(w in blob for w in ("sql", "database", "postgres", "mysql", "table", "schema", "analytics", "cohort", "metric")):
        cases.append(
            "Выполнение аналитических срезов и проверочных запросов к данным без ручного экспорта таблиц, напрямую в диалоге с агентом."
        )

    ok_calls = [t for t in tested_tools if t.get("ok")]
    if ok_calls:
        first_ok = ok_calls[0]
        cases.append(
            f"Бесключевое встраивание в многошаговые цепочки (workflows): пробный вызов «{first_ok['tool']}» подтвердил стабильный возврат структурированных данных за {first_ok['latency_ms']} мс без предварительной настройки токенов."
        )

    if len(cases) < 2:
        clean_ru = desc_ru if _is_russian_text(desc_ru, 0.3) else f"работа с внешним сервисом «{name}»"
        cases.append(
            f"Интеграция ИИ-агента с возможностями сервера ({clean_ru}) через готовые MCP-вызовы {', '.join(tool_names[:3]) or 'сервера'}."
        )
        cases.append(
            "Использование сервера как достоверного внешнего источника данных в автоматизированных сценариях поддержки, анализа и принятия решений."
        )

    return cases[:4]


def _fallback_report(target: dict, probe_ok: bool, latency_ms: int, probe_note: str,
                     tools: list[dict], scan: dict, tested_tools: list[dict]) -> dict:
    """Детерминированный русскоязычный отчёт на случай, если LLM недоступна."""
    name = str(target.get("name_ru") or target.get("name") or target.get("slug") or "MCP-сервер")
    desc_ru = str(target.get("description_ru") or "").strip()
    desc_any = desc_ru or str(target.get("description") or "").strip()

    if not probe_ok:
        return {
            "status": "fail",
            "utility_score": 10,
            "safety_score": int(scan.get("safety_score") or 50),
            "verdict": "broken",
            "is_gem": False,
            "summary": f"Сервер «{name}» не ответил на живое подключение ({probe_note or 'таймаут'}). Использовать его в цепочках агентов сейчас нельзя.",
            "use_cases": [],
            "safety_notes": "Эндпоинт недоступен, схемы не подтверждены.",
            "observations": f"Живая проверка завершилась ошибкой: {probe_note or 'нет ответа'}.",
        }

    auth_required = any(t.get("auth_required") for t in tested_tools)
    ok_calls = [t for t in tested_tools if t.get("ok")]
    tool_names = [str(t.get("name") or "") for t in tools[:6] if isinstance(t, dict) and t.get("name")]

    utility = 55
    if len(tools) >= 3:
        utility += 10
    if len(tools) >= 6:
        utility += 5
    if ok_calls:
        utility += 20
    elif tested_tools and not auth_required:
        utility -= 10
    if auth_required:
        utility = min(utility, 50)
    if latency_ms > 0 and latency_ms < 1200:
        utility += 5

    safety = int(scan.get("safety_score") or 80)
    utility = max(15, min(96, utility))

    if auth_required:
        verdict = "niche"
        is_gem = False
    elif utility >= 80 and safety >= 75 and ok_calls:
        verdict = "gem"
        is_gem = True
    elif utility >= 55 and safety >= 55:
        verdict = "useful"
        is_gem = False
    else:
        verdict = "questionable"
        is_gem = False

    summary_parts = [
        f"Сервер «{name}» стабильно отвечает за {latency_ms} мс и предоставляет агенту {len(tools)} инстр."
        + (f" ({', '.join(tool_names[:4])})" if tool_names else "") + "."
    ]
    if ok_calls:
        first_ok = ok_calls[0]
        summary_parts.append(
            f"Живой пробный вызов «{first_ok['tool']}» прошёл успешно за {first_ok['latency_ms']} мс и подтвердил готовность сервера отдавать проверенные структурированные данные без настройки ключей."
        )
    elif auth_required:
        summary_parts.append("При пробном вызове сервер запросил авторизацию (API-ключ или токен).")
    elif desc_ru and _is_russian_text(desc_ru, 0.3):
        summary_parts.append(desc_ru[:220])

    use_cases = _synthesize_russian_use_cases(name, desc_any, tools, tested_tools)

    issues = scan.get("issues") or []
    if issues:
        safety_notes = "Замечания аудита: " + "; ".join(str(i) for i in issues) + "."
    else:
        safety_notes = (
            f"Все JSON Schema валидны ({scan.get('schema_ok_tools', 0)}/{len(tools)}), "
            f"безопасных read-only инструментов: {scan.get('readonly_tools', 0)}, "
            f"признаков prompt-injection или опасных инструкций не обнаружено."
        )

    obs_parts = [f"Отклик на инициализацию и tools/list составил {latency_ms} мс."]
    for tt in tested_tools:
        obs_parts.append(
            f"Пробный вызов «{tt['tool']}»: {tt['note']} ({tt['latency_ms']} мс)"
            + (f" — пример ответа: «{tt['sample'][:140]}»." if tt.get("sample") else ".")
        )

    return {
        "status": "done",
        "utility_score": utility,
        "safety_score": safety,
        "verdict": verdict,
        "is_gem": is_gem,
        "summary": " ".join(summary_parts)[:900],
        "use_cases": use_cases[:4],
        "safety_notes": safety_notes[:600],
        "observations": " ".join(obs_parts)[:900],
    }


def _parse_llm_json(raw: str) -> dict | None:
    text = _strip_think(raw or "").strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text, flags=re.DOTALL).strip()
    start = text.find("{")
    end = text.rfind("}")
    if start < 0 or end <= start:
        return None
    try:
        parsed = json.loads(text[start : end + 1])
        return parsed if isinstance(parsed, dict) else None
    except ValueError:
        return None


async def _call_audit_llm(ctx, messages: list[dict], phase: str, model_env: str, max_tokens: int) -> tuple[str, dict]:
    """Вызвать LLM через штатный асинхронный хелпер воркера (или синхронный мок в тестах)."""
    if ctx is not None:
        return await _llm(
            ctx,
            messages,
            phase,
            model_env=model_env,
            max_tokens=max_tokens,
            timeout=180,
            options=_think_options(),
        )
    # Фоллбэк для unit-тестов без ctx:
    res = _llm(None, messages, phase, model_env=model_env, max_tokens=max_tokens, timeout=60, options=_think_options())
    if asyncio.iscoroutine(res):
        return await res
    return res


async def _evaluate_with_llm(target: dict, probe_ok: bool, latency_ms: int, probe_note: str,
                             tools: list[dict], scan: dict, tested_tools: list[dict],
                             ctx=None) -> tuple[dict, str]:
    """Сформировать экспертный отчёт на русском языке через LLM с проверкой качества выводов."""
    fallback = _fallback_report(target, probe_ok, latency_ms, probe_note, tools, scan, tested_tools)
    if not probe_ok:
        return fallback, "probe-direct"

    model_env = "NJ676_AUDIT_MODEL"
    if not os.environ.get(model_env, "").strip():
        model_env = "NJ676_ANALYSIS_MODEL" if os.environ.get("NJ676_ANALYSIS_MODEL", "").strip() else "NJ676_MODEL"
    max_tokens = _env_int("NJ676_AUDIT_TOKENS", 2400, 800, 6000)

    tool_names = [str(t.get("name") or "") for t in tools if isinstance(t, dict) and t.get("name")]
    dossier = {
        "slug": target.get("slug"),
        "name": target.get("name_ru") or target.get("name"),
        "original_name": target.get("name"),
        "description_ru": target.get("description_ru") or "",
        "description_original": target.get("description") or "",
        "categories": target.get("categories") or [],
        "stars": target.get("stars") or 0,
        "probe_latency_ms": latency_ms,
        "security_scan": scan,
        "tools": [
            {
                "name": t.get("name"),
                "description": _clean_description(str(t.get("description") or ""), 240),
                "parameters": list(((t.get("inputSchema") or {}).get("properties") or {}).keys())[:10]
                if isinstance(t.get("inputSchema"), dict) else [],
                "mutating": _is_mutating_tool(t),
            }
            for t in tools[:15]
            if isinstance(t, dict) and t.get("name")
        ],
        "live_tested_tools": tested_tools,
    }

    base_prompt = (
        PROMPT_TEMPLATE
        + "\n--- ДОСЬЕ СЕРВЕРА ---\n"
        + json.dumps(dossier, ensure_ascii=False, indent=2)[:14000]
    )

    parsed: dict | None = None
    used_model = ""

    for attempt in (1, 2):
        prompt = base_prompt
        if attempt == 2:
            prompt += (
                "\n\nВНИМАНИЕ: предыдущий ответ был отклонён! Убедись, что ВЕСЬ текст "
                "(summary, use_cases, safety_notes, observations) написан НА РУССКОМ ЯЗЫКЕ, "
                "а в use_cases даны продуманные инженерные сценарии применения для ИИ-агентов, "
                "а НЕ перечисление «имя_инструмента: английское описание»."
            )
        try:
            reply, usage = await _call_audit_llm(
                ctx,
                [
                    {"role": "system", "content": SYSTEM},
                    {"role": "user", "content": prompt},
                ],
                f"Исследую MCP-сервер {target.get('slug') or ''}",
                model_env=model_env,
                max_tokens=max_tokens,
            )
            candidate = _parse_llm_json(reply)
            used_model = str((usage or {}).get("model") or os.environ.get(model_env) or "llm")
        except Exception as exc:  # noqa: BLE001
            log.warning("nj676.audit: ошибка вызова LLM (попытка %s, %s): %s", attempt, type(exc).__name__, exc)
            continue

        if not isinstance(candidate, dict) or not candidate.get("summary"):
            continue

        if _is_russian_text(str(candidate.get("summary") or ""), 0.35) and _is_good_russian_use_cases(
            candidate.get("use_cases") or [], tool_names
        ):
            parsed = candidate
            break
        log.info("nj676.audit: ответ LLM не прошёл проверку русского языка/глубины use_cases (попытка %s)", attempt)

    if not isinstance(parsed, dict) or not parsed.get("summary"):
        return fallback, "heuristic"

    # Склеиваем ответ модели с объективными границами безопасности из сканера.
    llm_util = int(parsed.get("utility_score") or fallback["utility_score"])
    llm_safe = int(parsed.get("safety_score") or fallback["safety_score"])
    if scan.get("injection_hits", 0) > 0:
        llm_safe = min(llm_safe, 35)
    if any(t.get("auth_required") for t in tested_tools):
        llm_util = min(llm_util, 55)

    verdict = str(parsed.get("verdict") or fallback["verdict"]).lower().strip()
    if verdict not in ("gem", "useful", "niche", "questionable", "broken"):
        verdict = fallback["verdict"]

    is_gem = bool(parsed.get("is_gem")) and llm_util >= 75 and llm_safe >= 70 and not any(
        t.get("auth_required") for t in tested_tools
    )
    if is_gem:
        verdict = "gem"

    raw_uc = parsed.get("use_cases")
    if isinstance(raw_uc, list) and _is_good_russian_use_cases(raw_uc, tool_names):
        clean_use_cases = [str(u).strip()[:2000] for u in raw_uc[:6] if str(u).strip()]
    else:
        clean_use_cases = fallback["use_cases"]

    summary_val = str(parsed.get("summary") or "").strip()
    if not _is_russian_text(summary_val, 0.3):
        summary_val = fallback["summary"]

    safety_val = str(parsed.get("safety_notes") or "").strip()
    if not _is_russian_text(safety_val, 0.3):
        safety_val = fallback["safety_notes"]

    obs_val = str(parsed.get("observations") or "").strip()
    if not _is_russian_text(obs_val, 0.3):
        obs_val = fallback["observations"]

    return {
        "status": "done",
        "utility_score": max(0, min(100, llm_util)),
        "safety_score": max(0, min(100, llm_safe)),
        "verdict": verdict,
        "is_gem": is_gem,
        "summary": summary_val[:4000],
        "use_cases": clean_use_cases,
        "safety_notes": safety_val[:3000],
        "observations": obs_val[:4000],
    }, used_model


async def _audit_one_server(target: dict, ctx=None) -> dict:
    """Полный цикл исследования одного открытого MCP-сервера."""
    slug = str(target.get("slug") or "")[:100]
    url = str(target.get("url") or target.get("remote_url") or "")
    known_tools = target.get("tools") if isinstance(target.get("tools"), list) else []

    ok, latency_ms, note, live_tools = await _probe_remote(url)
    tools = live_tools if live_tools else known_tools
    scan = _security_scan(tools, str(target.get("description") or ""))

    tested_tools: list[dict] = []
    if ok and tools:
        safe_pairs = _pick_safe_tools(tools, max_tools=2)
        for tool_def, test_args in safe_pairs:
            call_res = await _call_remote_tool(url, str(tool_def["name"]), test_args)
            tested_tools.append(call_res)

    report, model_name = await _evaluate_with_llm(
        target, ok, latency_ms, note, tools, scan, tested_tools, ctx=ctx
    )

    return {
        "slug": slug,
        "status": report["status"],
        "utility_score": report["utility_score"],
        "safety_score": report["safety_score"],
        "verdict": report["verdict"],
        "is_gem": report["is_gem"],
        "summary": report["summary"],
        "use_cases": report["use_cases"],
        "safety_notes": report["safety_notes"],
        "observations": report["observations"],
        "tested_tools": tested_tools,
        "latency_ms": latency_ms,
        "model": model_name,
        "auth_required": any(t.get("auth_required") for t in tested_tools),
    }


async def handle(ctx, payload: dict) -> dict:
    """Обработчик канала nj676.audit."""
    base = _site_base()
    if not base:
        raise RuntimeError("не настроен адрес сайта: NJ676_API_BASE (или NJ120_API_BASE) в workers/.env")

    run_id = int(payload.get("run_id") or 0)
    targets = payload.get("targets")
    if not isinstance(targets, list):
        targets = []

    limit = _env_int("NJ676_AUDIT_MAX", 8, 1, 16)
    targets = [t for t in targets if isinstance(t, dict) and (t.get("url") or t.get("remote_url"))][:limit]

    stats = {"audited": 0, "gems": 0, "useful": 0, "failed": 0}

    for idx, target in enumerate(targets, 1):
        slug = str(target.get("slug") or "")
        if not slug:
            continue

        await ctx.heartbeat()
        if run_id:
            await _site_post(base, "/discover-progress", {
                "run_id": run_id,
                "phase": f"Исследую MCP-сервер {idx}/{len(targets)}: {slug}…",
            })

        result = await _audit_one_server(target, ctx=ctx)
        result["run_id"] = run_id

        stats["audited"] += 1
        if result.get("status") == "fail" or result.get("verdict") == "broken":
            stats["failed"] += 1
        elif result.get("is_gem"):
            stats["gems"] += 1
        else:
            stats["useful"] += 1

        sent, reason, _ans = await _site_post(base, "/audit-item", result)
        if not sent:
            log.warning("nj676.audit: отчёт по %s не принят: %s", slug, reason)

    if run_id:
        await _site_post(base, "/discover-finish", {
            "run_id": run_id,
            "status": "done",
            "progress": (
                f"Исследовано {stats['audited']} серверов: "
                f"алмазов {stats['gems']}, полезных {stats['useful']}, недоступных {stats['failed']}"
            ),
            "found": stats["audited"],
            "added": stats["gems"],
            "updated": stats["useful"],
            "skipped": stats["failed"],
            "probed": stats["audited"],
        })

    log.info("nj676.audit: исследовано %s, алмазов %s", stats["audited"], stats["gems"])
    return {"source": "audit", **stats}
