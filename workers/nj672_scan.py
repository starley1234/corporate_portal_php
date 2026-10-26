#!/usr/bin/env python3
"""Сканер безопасности ИИ-моделей из командной строки (канал nj672.scan без
сайта и очереди).

Зачем CLI: аудит чужого эндпоинта удобно гонять с машины воркера до того, как
настроена страница /aiscan, а также в CI. Код тот же, что в канале
(`n2j_workers.aiscan`), результат — тот же отчёт.

    cd workers
    python3 nj672_scan.py                                   # песочница: уязвимая
    python3 nj672_scan.py --target sandbox_hardened         # песочница: закалённая
    python3 nj672_scan.py --target sandbox_flaky --stress standard
    python3 nj672_scan.py --url http://127.0.0.1:8000/v1 --model my-model \
        --stress quick --json /tmp/scan.json                # свой vLLM
    python3 nj672_scan.py --target preset --quick           # пресет NJ672_TARGET_*

Локальные/приватные адреса требуют NJ672_ALLOW_PRIVATE=1 (SSRF-сторож):
    NJ672_ALLOW_PRIVATE=1 python3 nj672_scan.py --url http://127.0.0.1:8000/v1

Ключ цели: --api-key или NJ672_CLI_API_KEY (в командной строке ключ виден в
ps — для боевых ключей используйте пресет в workers/.env).
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from n2j_workers import aiscan, aiscan_battery  # noqa: E402
from n2j_workers.config import Config            # noqa: E402

PROFILE_KEYS = sorted(aiscan.SANDBOX_PROFILES) + sorted(aiscan.PROFILE_LABELS)


def _default_llm() -> dict:
    conf = Config.load()
    return {"base_url": conf.llm_base_url, "model": conf.llm_model,
            "api_key": conf.llm_api_key}


async def _main(args: argparse.Namespace) -> int:
    if args.url:
        target = aiscan.custom_target(args.url, args.model,
                                      args.api_key or os.environ.get("NJ672_CLI_API_KEY", ""))
    else:
        target = aiscan.profile_target(args.target, _default_llm())

    result = await aiscan.run_scan(
        target, suites=args.suite, stress_profile=args.stress,
        quick=args.quick,
        # CLI запускает оператор лично — он же и подтверждает право на тест.
        attested=bool(args.url))
    print(aiscan.report(result))

    if args.json:
        path = Path(args.json)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(result, ensure_ascii=False, indent=2),
                        encoding="utf-8")
        print(f"\nJSON: {path}")

    if result.get("status") == "error":
        return 2
    risk = (result.get("summary") or {}).get("risk_level")
    return 1 if risk in ("critical", "high") else 0


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Аудит безопасности и устойчивости ИИ-модели (nj672)")
    parser.add_argument("--target", default="sandbox_vulnerable",
                        choices=PROFILE_KEYS,
                        help="профиль цели: песочница, preset или worker_default")
    parser.add_argument("--url", default="",
                        help="свой OpenAI-совместимый адрес (…/v1); перекрывает --target")
    parser.add_argument("--model", default="", help="имя модели для --url")
    parser.add_argument("--api-key", default="",
                        help="ключ для --url (лучше NJ672_CLI_API_KEY)")
    parser.add_argument("--suite", nargs="*", default=None,
                        choices=[s for s in aiscan_battery.SUITES if s != "custom"],
                        help="наборы проверок (по умолчанию все)")
    parser.add_argument("--stress", default="off",
                        choices=["off", "quick", "standard", "full"],
                        help="профиль нагрузочного тестирования")
    parser.add_argument("--quick", action="store_true",
                        help="по 2 проверки из набора вместо полной батареи")
    parser.add_argument("--json", default="", help="куда записать машинный отчёт")
    parser.add_argument("--list", action="store_true",
                        help="показать наборы проверок и выйти")

    args = parser.parse_args()

    if args.list:
        for key, suite in aiscan_battery.SUITES.items():
            n = len([t for t in aiscan_battery.TESTS if t["suite"] == key])
            print(f"{key}: {suite['title']} [{suite['owasp']}] — {n} проверок")
        return 0

    return asyncio.run(_main(args))


if __name__ == "__main__":
    raise SystemExit(main())
