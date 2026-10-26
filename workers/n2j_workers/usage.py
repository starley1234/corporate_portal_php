"""Учёт ресурсов задачи (usage): модели/токены, веб-чтение, поиски, ошибки.

Трекер живёт в contextvar: обработчик (или общий инструмент llm/webread) зовёт
Usage.current() — учёт привязывается к КОНКРЕТНОЙ задаче, без сигнатурных
изменений обработчиков. Базовый цикл (base.py) сам:
  * begin() перед обработчиком;
  * подмешивает usage.to_dict() в result (или в queue.fail при падении);
сайт складывает отчёт в nj_120_usage (потребление по юзерам/модулям/моделям).
"""

from __future__ import annotations

import contextvars
import time

_CURRENT: contextvars.ContextVar["Usage | None"] = contextvars.ContextVar(
    "n2j_usage", default=None)

_MAX_ERRORS = 20


class Usage:
    """Накопитель ресурсов одной задачи. Потокобезопасность не нужна:
    asyncio-задача выполняется в одном потоке."""

    def __init__(self) -> None:
        self.models: dict[str, dict] = {}   # "provider::model" -> сводка
        self.web_fetches: int = 0
        self.search_queries: int = 0
        self.errors: list[str] = []
        self._t0: float = time.monotonic()

    # -- контекст задачи -----------------------------------------------------
    @staticmethod
    def begin() -> "Usage":
        u = Usage()
        _CURRENT.set(u)
        return u

    @staticmethod
    def current() -> "Usage | None":
        return _CURRENT.get()

    # -- накопление ----------------------------------------------------------
    def add_llm(self, provider: str, model: str, usage_dict: dict | None = None,
                error: str | None = None) -> None:
        key = f"{provider or '?'}::{model or '?'}"
        slot = self.models.setdefault(
            key, {"provider": provider or "", "model": model or "",
                  "calls": 0, "prompt_tokens": 0, "completion_tokens": 0, "errors": 0})
        slot["calls"] += 1
        u = usage_dict or {}
        try:
            slot["prompt_tokens"] += int(u.get("prompt_tokens") or 0)
            slot["completion_tokens"] += int(u.get("completion_tokens") or 0)
        except (TypeError, ValueError):
            pass
        if error:
            slot["errors"] += 1
            self.add_error(f"LLM {key}: {error}")
        if u.get("_llm_note"):  # подмена модели/провайдера — не ошибка, но факт
            self.add_error(f"LLM note {key}: {u['_llm_note']}")

    def add_fetch(self, n: int = 1) -> None:
        self.web_fetches += n

    def add_search(self, n: int = 1) -> None:
        self.search_queries += n

    def add_error(self, msg: str) -> None:
        if len(self.errors) < _MAX_ERRORS:
            self.errors.append(str(msg)[:300])

    # -- отчёт ---------------------------------------------------------------
    def to_dict(self) -> dict:
        return {
            "models": list(self.models.values()),
            "llm_calls": sum(m["calls"] for m in self.models.values()),
            "prompt_tokens": sum(m["prompt_tokens"] for m in self.models.values()),
            "completion_tokens": sum(m["completion_tokens"] for m in self.models.values()),
            "web_fetches": self.web_fetches,
            "search_queries": self.search_queries,
            "errors": self.errors,
            "elapsed_ms": int((time.monotonic() - self._t0) * 1000),
        }
