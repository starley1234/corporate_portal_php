#!/usr/bin/env python3
"""Стенд эмбеддингов из командной строки (канал nj671.bench без сайта и очереди).

Зачем отдельный CLI. Сравнение «текстовый путь против мультимодального» нужно
владельцу ДО того, как страница /embedbench начнёт работать: сервер, ключи и
размерности настраиваются в workers/.env, а сайт может быть занят. Здесь тот же
код (`n2j_workers.embbench`), что и в канале, но без очереди и доставки.

    cd workers
    python3 nj671_bench.py                        # набор catalog
    python3 nj671_bench.py --quick                # 6 описаний, 3 запроса
    python3 nj671_bench.py --json /tmp/bench.json # машинный отчёт целиком
    python3 nj671_bench.py --corpus catalog --repeat 2

Читает ровно те же переменные, что боевой воркер:
  EMBEDDING_API_URL / _KEY / _MODEL / _DIMENSIONS / _BATCH_SIZE   — текстовый;
  EMBEDDING_MM_API_URL / _KEY / _MODEL / _DIMENSIONS / _DIALECT   — мультимодальный.
Пустой адрес = «этот путь не настроен», а не «возьми соседний».
"""

from __future__ import annotations

import argparse
import asyncio
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from n2j_workers import embbench           # noqa: E402


async def _main(args: argparse.Namespace) -> int:
    result: dict = {}

    for attempt in range(1, max(1, args.repeat) + 1):
        if args.repeat > 1:
            print(f"\n=== прогон {attempt} из {args.repeat} ===")

        result = await embbench.run(corpus_key=args.corpus, quick=args.quick)
        print(embbench.report(result))

    if args.json:
        path = Path(args.json)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(result, ensure_ascii=False, indent=2),
                        encoding="utf-8")
        print(f"\nJSON: {path}")

    sides = result.get("engines", {})
    both_off = not (sides.get("text", {}).get("ok") or sides.get("mm", {}).get("ok"))

    if both_off:
        print("\nНи один путь не ответил. Проверьте EMBEDDING_API_URL и "
              "EMBEDDING_MM_API_URL в workers/.env (адрес, токен, имя модели).",
              file=sys.stderr)
        return 2

    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Сравнить два пути эмбеддингов на одном материале (nj671)")
    parser.add_argument("--corpus", default="catalog", choices=sorted(embbench.CORPORA),
                        help="набор текстов (по умолчанию catalog)")
    parser.add_argument("--quick", action="store_true",
                        help="быстро: 6 описаний и 3 запроса вместо 14 и 10")
    parser.add_argument("--json", default="", help="куда записать машинный отчёт")
    parser.add_argument("--repeat", type=int, default=1,
                        help="повторить прогон N раз (проверка стабильности)")
    parser.add_argument("--list", action="store_true", help="показать наборы и выйти")

    args = parser.parse_args()

    if args.list:
        for key, corpus in sorted(embbench.CORPORA.items()):
            print(f"{key}: {corpus['title']} — "
                  f"{len(corpus['docs'])} описаний, {len(corpus['queries'])} запросов")

        return 0

    return asyncio.run(_main(args))


if __name__ == "__main__":
    raise SystemExit(main())
