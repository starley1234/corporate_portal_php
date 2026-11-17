#!/usr/bin/env python3
"""Сверить `.env.example` с тем, что реально читает код.

Зачем. `.env.example` — человекочитаемый контракт окружения, но без проверки
он расходится с `getenv()`/`os.environ`: появляются мёртвые ключи и пропадают
нужные. Скрипт:
  * находит ключи, которые читает PHP/Python;
  * находит ключи, задокументированные в `.env.example` (включая комментарии
    с примером `# KEY=`);
  * падает на устаревших именах (`LM_API_TOKEN`, `TOOLFORGE_DEFAULT_MODEL`);
  * предупреждает о ключах, которых нет в примере (у части есть умолчания в
    коде — это нормально, поэтому предупреждение, а не ошибка).

Запуск: `make env-check` или `python3 .tools/env_check.py`.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ENV_FILES = (ROOT / ".env.example", ROOT / "workers" / ".env.example")
SCAN_DIRS = (ROOT / "application", ROOT / "workers")

KEY = r"[A-Z][A-Z0-9_]+"
LEGACY = {
    "LM_API_TOKEN": "LOCAL_API_KEY",
    "TOOLFORGE_DEFAULT_MODEL": "LOCAL_DEFAULT_MODEL",
    "DEFAULT_LLM_MODEL": "LLM_DEFAULT_PROVIDER + LOCAL_DEFAULT_MODEL",
    "DEFAULT_VLM_MODEL": "PRX398_VLM_MODEL",
}
REQUIRED = ("LLM_DEFAULT_PROVIDER", "LOCAL_API_KEY", "LOCAL_DEFAULT_MODEL")

# Ключи с умолчаниями в коде: их можно не дублировать в примере.
DEFAULTS_IN_CODE = re.compile(
    r"^(?:PRX_LOG_.*|PRX_JOB_.*|PRX120_BUDGET_.*|PRX_.*_RETENTION_DAYS|"
    r"WORKER_LOG_.*|WEBREAD_.*|PRX_.*_SEC|PRX_.*_GRACE|PRX_.*_WARN|"
    r"PRX_.*_MOCK|PRX_STALLED_PROGRESS_SEC|PRX_SILENT_STOP_SEC|"
    r"PRX_SWEEP_INTERVAL_SEC|PRX_RELEASE_ORPHANS|PRX_HARD_WATCHDOG_SEC|"
    r"PRX_LOOP_LAG_WARN|PRX_LOOP_STALL_EXIT|PRX646_API_BASE|"
    r"PRX_ENV_FILE_VARS|PRX_DEFAULT_ADMIN_EMAIL|PRX599_SCHEMA_AUTOCREATE|"
    r"PRX115_EMBED_TIMEOUT|PRX394_EMAIL_ENABLED|LLM_THINKING|"
    r"OPENROUTER_PROXY|LLM_PROVIDER|WORKER_API_URL)$"
)


def documented() -> dict[str, str]:
    """KEY → файл, где он описан (включая закомментированные примеры)."""
    found: dict[str, str] = {}
    for path in ENV_FILES:
        for raw in path.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if line.startswith("#"):
                line = line.lstrip("#").strip()
            if "=" not in line:
                continue
            name = line.split("=", 1)[0].strip()
            if re.fullmatch(KEY, name):
                found.setdefault(name, str(path.relative_to(ROOT)))
    return found


def read_by_code() -> dict[str, str]:
    """KEY → первый файл, где он читается."""
    found: dict[str, str] = {}
    patterns = (
        re.compile(rf"getenv\(\s*['\"]({KEY})['\"]"),
        re.compile(rf"(?:os\.environ\.get|os\.getenv|_env(?:_int|_float|_bool|_json)?)"
                   rf"\(\s*['\"]({KEY})['\"]"),
    )
    for base in SCAN_DIRS:
        for path in base.rglob("*"):
            if path.suffix not in (".php", ".py") or "tests" in path.parts:
                continue
            if "mock" in path.parts:
                continue
            try:
                text = path.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            for pattern in patterns:
                for match in pattern.finditer(text):
                    found.setdefault(match.group(1), str(path.relative_to(ROOT)))
    return found


def main() -> int:
    docs = documented()
    code = read_by_code()

    errors: list[str] = []
    warnings: list[str] = []

    for old, new in LEGACY.items():
        if old in docs:
            errors.append(f"устаревшее имя {old} в .env.example → используйте {new}")
    for name in REQUIRED:
        if name not in docs:
            errors.append(f"в .env.example нет обязательного ключа {name}")
        if name not in code:
            errors.append(f"{name} описан, но нигде не читается")

    for name in sorted(code):
        if name in docs or DEFAULTS_IN_CODE.match(name):
            continue
        warnings.append(f"{name} читается кодом ({code[name]}), но не описан в .env.example")

    for line in warnings:
        print(f"WARN  {line}")
    for line in errors:
        print(f"ERROR {line}")

    print(f"env-check: документировано {len(docs)}, читается кодом {len(code)}, "
          f"ошибок {len(errors)}, предупреждений {len(warnings)}")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
