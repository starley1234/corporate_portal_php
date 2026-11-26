"""Общий структурированный LLM-вызов для воркеров (LangChain).

Контракт: только LOCAL_API_URL, LOCAL_API_KEY, DEFAULT_LLM_MODEL.
Токены пишутся в Usage.current() и возвращаются вызывающему.
"""
from __future__ import annotations

import os
from typing import Any

from langchain_core.messages import HumanMessage, SystemMessage
from langchain_openai import ChatOpenAI

from .usage import Usage


def _env(name: str) -> str:
    value = os.getenv(name)
    return value.strip() if isinstance(value, str) else ""


def _model_name() -> str:
    raw = _env("DEFAULT_LLM_MODEL")
    if not raw:
        return ""
    # Совместимо со спеками вида provider::model.
    if "::" in raw:
        return raw.split("::", 1)[1].strip()
    return raw


def _usage_dict(response: Any) -> dict[str, int]:
    meta = getattr(response, "usage_metadata", None) or {}
    if isinstance(meta, dict):
        prompt = int(meta.get("input_tokens") or meta.get("prompt_tokens") or 0)
        completion = int(meta.get("output_tokens") or meta.get("completion_tokens") or 0)
        return {"prompt_tokens": prompt, "completion_tokens": completion}
    # Fallback for response_metadata.token_usage
    rmeta = getattr(response, "response_metadata", None) or {}
    token_usage = rmeta.get("token_usage") or rmeta.get("usage") or {}
    if isinstance(token_usage, dict):
        prompt = int(token_usage.get("prompt_tokens") or token_usage.get("input_tokens") or 0)
        completion = int(token_usage.get("completion_tokens") or token_usage.get("output_tokens") or 0)
        return {"prompt_tokens": prompt, "completion_tokens": completion}
    return {"prompt_tokens": 0, "completion_tokens": 0}


def build_chat_model(*, timeout: int = 120) -> ChatOpenAI:
    base_url = _env("LOCAL_API_URL").rstrip("/")
    api_key = _env("LOCAL_API_KEY")
    model = _model_name()
    if not base_url:
        raise RuntimeError("LOCAL_API_URL is not configured")
    if not model:
        raise RuntimeError("DEFAULT_LLM_MODEL is not configured")
    return ChatOpenAI(
        base_url=base_url,
        api_key=api_key or "local",
        model=model,
        timeout=timeout,
        max_retries=0,
    )


def _record_usage(message: Any) -> dict[str, int]:
    usage = _usage_dict(message)
    current = Usage.current()
    if current is not None:
        try:
            current.add_llm("local", _model_name(), usage)
        except Exception:  # noqa: BLE001 — учёт не должен ронять задачу
            pass
    return usage


def _unpack(raw: Any) -> tuple[Any, Any]:
    parsed = raw.get("parsed") if isinstance(raw, dict) else None
    message = raw.get("raw") if isinstance(raw, dict) else None
    if parsed is None:
        raise ValueError("LLM returned no structured output")
    return parsed, message


def _structured(schema: Any, timeout: int):
    return build_chat_model(timeout=timeout).with_structured_output(schema, include_raw=True)


def _messages(system: str, user: str) -> list[Any]:
    return [SystemMessage(content=system), HumanMessage(content=user)]


def run_structured(schema: Any, system: str, user: str, timeout: int = 120) -> tuple[Any, dict[str, int]]:
    """Синхронный структурированный запрос. Только для не-async кода и тестов.

    В воркерах (async-обработчики и узлы графа) используйте arun_structured:
    синхронный .invoke() блокирует event loop, из-за чего встают heartbeat и
    параллельные задачи.
    """
    parsed, message = _unpack(_structured(schema, timeout).invoke(_messages(system, user)))
    return parsed, _record_usage(message)


async def arun_structured(schema: Any, system: str, user: str, timeout: int = 120) -> tuple[Any, dict[str, int]]:
    """Async-структурированный запрос: не блокирует event loop воркера."""
    parsed, message = _unpack(await _structured(schema, timeout).ainvoke(_messages(system, user)))
    return parsed, _record_usage(message)


async def arun_structured_messages(schema: Any, messages: list, timeout: int = 120) -> tuple[Any, dict[str, int]]:
    """Async-структурированный запрос по готовым сообщениям (vision/multimodal).

    prx400 передаёт HumanMessage с image_url-блоками (pdftoppm → PNG → base64);
    учёт токенов тот же, что у arun_structured.
    """
    parsed, message = _unpack(await _structured(schema, timeout).ainvoke(messages))
    return parsed, _record_usage(message)
