#!/usr/bin/env python3
"""Проверка мультимодальной эмбеддинг-модели прямо с сервера воркера.

Запуск (из корня репозитория):

    python3 workers/nj653_doctor.py                     # полная проверка
    python3 workers/nj653_doctor.py --image photo.jpg   # + свой кадр
    python3 workers/nj653_doctor.py --text "рыжий кот"  # + свой запрос
    python3 workers/nj653_doctor.py --quiet             # только итог

Что делает:
  1. читает workers/.env и показывает паспорт подключения;
  2. перебирает известные форматы запроса и говорит, какой подошёл;
  3. меряет размерность и время ответа;
  4. проверяет повторяемость, смысл текста и кросс-модальность
     (нарисованные здесь же красный круг и синий квадрат);
  5. если дали --image, считает близость кадра к вашим текстам.

Сайт и очередь для этого НЕ нужны: доктор ходит прямо в эмбеддинг-сервер.
Код возврата 0 — модель пригодна, 1 — есть провалившиеся проверки.
"""

from __future__ import annotations

import argparse
import asyncio
import base64
import os
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))

from n2j_workers.config import _load_dotenv          # noqa: E402
from n2j_workers.handlers import nj653_veclab as lab  # noqa: E402
from n2j_workers.mmembed import (                     # noqa: E402
    BATCHABLE,
    DIALECT_TITLES,
    EmbedInput,
    MmEmbedError,
    MmEmbeddingClient,
    cosine,
)

OK, BAD, WARN, INFO = "  OK ", " FAIL", " WARN", " ····"


def line(status: str, text: str) -> None:
    print(f"[{status}] {text}")


async def main_async(args: argparse.Namespace) -> int:
    _load_dotenv(HERE / ".env")

    client = MmEmbeddingClient.from_env()

    if client is None:
        line(BAD, "эмбеддинг-сервер не настроен: нет EMBEDDING_MM_API_URL "
                  "и EMBEDDING_API_URL в workers/.env")
        return 1

    # Сверяем имя модели со списком сервера ДО паспорта: с точным именем
    # LM Studio поднимает модель сам (JIT), и грузить её руками не нужно.
    names, why = await client.list_models()
    software = await client.server_probe()
    note = await client.ensure_model()

    titles = {"lmstudio": "LM Studio", "llamacpp": "llama.cpp", "vllm": "vLLM",
              "omniembed": "свой сервис эмбеддингов (OmniEmbed)"}
    health = await client.health()

    line(INFO, f"софт:        {titles.get(software, 'неизвестен')}"
               + (f", маркер картинки {client.media_marker}" if software == "llamacpp" else ""))

    if health:
        line(INFO, "сервис:      "
                   + ", ".join(x for x in [
                       f"{health.get('service') or 'OmniEmbed'} {health.get('version') or ''}".strip(),
                       f"модель {health.get('model')}" if health.get("model") else "",
                       f"бэкенд {health.get('backend')}" if health.get("backend") else "",
                       f"устройство {health.get('device')}" if health.get("device") else "",
                   ] if x))
    line(INFO, f"сервер:      {client.base_url}"
               + (f" (как у чата: провайдер {client.provider})" if client.provider else ""))
    line(INFO, f"модель:      {client.model or '(имя не задано — сервер решит сам)'}")
    line(INFO, f"размерность: {client.dimensions or '(не задана — возьмём как есть)'}")
    line(INFO, f"токен:       {client.api_key_src or 'не отправляем'}"
               + (f", прокси {client.proxy}" if client.proxy else ""))

    if health and health.get("loaded") is False:
        line(WARN, "сервис отвечает, но модель ещё НЕ загружена в память "
                   f"({health.get('detail') or 'loaded: false'}) — "
                   "первый запрос будет долгим либо упадёт")

    if client.forced_dialect:
        line(INFO, f"формат запроса зафиксирован: EMBEDDING_MM_DIALECT={client.forced_dialect}")

    bad = 0

    # 0. Что сервер вообще готов дать --------------------------------------
    if names:
        shown = ", ".join(names[:12]) + ("…" if len(names) > 12 else "")

        if not note:
            line(OK, f"сервер отвечает, моделей в списке: {len(names)} ({shown})")
        elif client.model in names:
            # Имя из .env промахнулось, но мы нашли его на сервере сами —
            # ошибки нет: запрос уйдёт с точным именем, и модель загрузится.
            line(WARN, note + f". Доступны: {shown}")
        else:
            line(BAD, note)
            line(INFO, "впишите в EMBEDDING_MODEL имя из этого списка "
                       "(или загрузите нужную модель: lms load <имя>)")
            bad += 1
    else:
        line(WARN, f"список моделей получить не удалось: {why}")

    # 1. Формат запроса -----------------------------------------------------
    try:
        attempts = await client.detect()
    except MmEmbedError as e:
        line(BAD, str(e))
        await client.close()
        return 1

    if client.mode == "caption":
        # Главное, что должен понять человек: вектора по картинке считает НЕ
        # эмбеддинг-модель. Прятать это за «всё ОК» нельзя.
        line(WARN, "режим описания: " + client.caption_note)
        line(INFO, "настоящие мультимодальные эмбеддинги — см. «Как получить» "
                   "в application/modules/nj653_veclab/README.md "
                   "(llama-server --embedding --pooling last с mmproj, либо vLLM)")
        line(INFO, f"описывает картинки: {client.caption_model or 'модель чата по умолчанию'}; "
                   f"вектор даёт: {client.text_model}")

    for attempt in attempts:
        if attempt.ok:
            line(OK, f"формат «{attempt.dialect}» подошёл — "
                     f"{DIALECT_TITLES.get(attempt.dialect, '')}, "
                     f"{attempt.dims} чисел, {attempt.ms} мс")
        elif not args.quiet:
            line(INFO, f"формат «{attempt.dialect}» не подошёл: {attempt.error}")

    if not client.dialect:
        line(BAD, "ни один формат запроса не подошёл")
        bad += 1
        await client.close()
        return 1

    # 2–4. Содержательные проверки -----------------------------------------
    class Silent:
        """Заглушка контекста задачи: доктору рапорты в очередь не нужны."""

        async def progress(self, phase: str, note: str = "") -> None:
            if not args.quiet:
                line(INFO, f"{phase}: {note}" if note else phase)

    try:
        report = await lab.run_probe(Silent(), client)
    except (MmEmbedError, RuntimeError, ValueError) as e:
        line(BAD, f"проверка не доведена до конца: {e}")
        await client.close()
        return 1

    print()
    line(INFO, f"размерность вектора: {report['dims']}"
               + (f" (хранилище ждёт {client.dimensions})" if client.dimensions
                  and client.dimensions != report["dims"] else ""))

    for check in report["checks"]:
        status = OK if check["ok"] else (WARN if check["ok"] is None else BAD)
        value = "—" if check["value"] is None else check["value"]

        if check["ok"] is False:
            bad += 1

        line(status, f"{check['title']}: {value} — {check['note']}")

    # 4-бис. Влияет ли сосед по запросу на вектор ---------------------------
    # Сервер считает пачку conversations одним батчем, и у некоторых сборок
    # вектор входа зависит от соседей: тогда «одно и то же», посланное вдвоём,
    # перестаёт быть одним и тем же. Воркер поэтому шлёт картинки по одной;
    # эта проверка показывает, можно ли вообще поднимать EMBEDDING_MM_BATCH.
    if client.dialect in BATCHABLE:
        alone = await client.embed_one(EmbedInput(text=lab.PROBE_TEXTS["a"]))
        was, client.batch_size = client.batch_size, 2
        pair = await client.embed([EmbedInput(text=lab.PROBE_TEXTS["a"]),
                                   EmbedInput(text=lab.PROBE_TEXTS["far"])])
        client.batch_size = was
        drift = cosine(alone, pair[0])

        if drift > 0.999:
            line(OK, f"Пачка не меняет вектор: {drift:.4f} — EMBEDDING_MM_BATCH "
                     "можно поднять ради скорости")
        else:
            line(WARN, f"Пачка МЕНЯЕТ вектор: {drift:.4f} вместо 1,0 — один и тот же "
                       "вход вместе с соседом даёт другую точку пространства. "
                       "Держите EMBEDDING_MM_BATCH=1 (значение по умолчанию); "
                       "картинки воркер и так шлёт по одной")

    grid = report.get("cross", {}).get("grid")

    if grid and not args.quiet:
        labels = report["cross"]["labels"]
        print("\n       близость «подпись ↔ картинка» (по строкам — подписи):")
        head = "            " + "  ".join(f"{n + 1:>8}" for n in range(len(labels)))
        print(head)

        for n, row in enumerate(grid):
            print(f"       {n + 1}. " + "  ".join(f"{v:>8.4f}" for v in row)
                  + f"   {labels[n]}")

    # 5. Свой кадр ----------------------------------------------------------
    if args.image:
        path = Path(args.image)

        if not path.is_file():
            line(BAD, f"файл не найден: {path}")
            bad += 1
        else:
            b64 = base64.b64encode(path.read_bytes()).decode("ascii")
            mime = {"png": "image/png", "webp": "image/webp"}.get(
                path.suffix.lower().lstrip("."), "image/jpeg")

            vector = await client.embed_one(EmbedInput(image_b64=b64, media_type=mime))
            print()
            line(OK, f"кадр {path.name} превращён в вектор ({len(vector)} чисел)")

            for text in (args.text or []):
                score = cosine(vector, await client.embed_one(EmbedInput(text=text)))
                line(INFO, f"близость к «{text}»: {score:.4f}")

    await client.close()

    print()

    if bad:
        line(BAD, f"проверок не прошло: {bad}. Модель отвечает, но ведёт себя не так, "
                  f"как ждёт лаборатория — смотрите строки FAIL выше")
        return 1

    line(OK, f"модель пригодна: формат «{client.dialect}», {report['dims']} чисел в векторе")

    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Проверка мультимодальной эмбеддинг-модели (канал nj653.embed)")
    parser.add_argument("--image", default="", help="путь к своему кадру для проверки")
    parser.add_argument("--text", action="append", default=[],
                        help="текст для сравнения с кадром (можно несколько раз)")
    parser.add_argument("--quiet", action="store_true", help="только итоговые строки")
    args = parser.parse_args()

    if args.image and not args.text:
        args.text = ["фотография", "скриншот документа"]

    try:
        return asyncio.run(main_async(args))
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    os.environ.setdefault("PYTHONUNBUFFERED", "1")
    raise SystemExit(main())
