#!/usr/bin/env python3
"""nj570_doctor — диагностика 3D-конструктора на сервере воркеров.

Запуск (из корня репозитория или откуда угодно):
    python3 workers/nj570_doctor.py [код модели, напр. m3d1ca305d9]

Только читает и пробует: ничего не ставит в очередь, ничего не меняет.
Собирает: конфиг (.env), локальный рендер (openscad+BOSL2, пробный куб),
LLM-шлюз, сайт/очередь (версия сайта! счётчики nj570.*), процессы и журнал
systemd. Отчёт печатается и сохраняется в /tmp/nj570_doctor_report.txt —
пришлите его целиком.
"""
from __future__ import annotations

import asyncio
import json
import os
import platform
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
WORKERS = REPO / "workers"
REPORT_LINES: list[str] = []
ENV: dict[str, str] = {}


def out(line: str = "") -> None:
    print(line)
    REPORT_LINES.append(line)


def mask(value: str | None, keep: int = 4) -> str:
    if not value:
        return "не задан"
    return value[:keep] + "…" + f" (длина {len(value)})" if len(value) > keep else "задан"


def load_env() -> None:
    path = WORKERS / ".env"
    if path.is_file():
        for raw in path.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            if line.startswith("export "):
                line = line[len("export "):]
            key, _, value = line.partition("=")
            ENV[key.strip()] = value.strip().strip("'\"")

    for key in list(ENV):
        os.environ.setdefault(key, ENV[key])


def env(name: str, default: str = "") -> str:
    return os.environ.get(name) or default


def http_json(url: str, payload: dict | None = None, token: str = "",
              timeout: int = 30, method: str | None = None, auth: bool = False):
    """(status, dict|None, текст-ошибка). urllib, без зависимостей.
    auth=True → токен идёт как Authorization: Bearer (LM Studio/OpenAI-шлюзы),
    иначе — как X-Worker-Token (API сайта)."""
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(url, data=data, method=method or ("POST" if data else "GET"))
    req.add_header("Accept", "application/json")
    if data:
        req.add_header("Content-Type", "application/json")
    if token:
        header = "Authorization" if auth else "X-Worker-Token"
        value = f"Bearer {token}" if auth else token
        req.add_header(header, value)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = resp.read().decode("utf-8", "replace")
            try:
                return resp.status, json.loads(body), ""
            except ValueError:
                return resp.status, None, body[:300]
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        try:
            return e.code, json.loads(body), ""
        except ValueError:
            return e.code, None, body[:300]
    except Exception as e:  # noqa: BLE001
        return 0, None, f"{type(e).__name__}: {e}"


def run_cmd(cmd: list, timeout: int = 20) -> tuple[int, str]:
    try:
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        return proc.returncode, ((proc.stdout or "") + (proc.stderr or "")).strip()
    except FileNotFoundError:
        return 127, "не найдено"
    except subprocess.TimeoutExpired:
        return 124, f"таймаут {timeout}с"
    except Exception as e:  # noqa: BLE001
        return 1, f"{type(e).__name__}: {e}"


def section(title: str) -> None:
    out()
    out("=" * 72)
    out(title)
    out("=" * 72)


# ---------------------------------------------------------------------------

def check_env() -> list[str]:
    section("1. ОКРУЖЕНИЕ И КОНФИГ (workers/.env)")
    problems: list[str] = []

    code, git_hash = run_cmd(["git", "rev-parse", "--short", "HEAD"], 10)
    out(f"репозиторий: {REPO} @ {git_hash if code == 0 else '?'}")
    out(f"python: {platform.python_version()} · {platform.platform()}")

    out(f"WORKER_QUEUE_MODE = {env('WORKER_QUEUE_MODE', 'pg (по умолчанию)')}")
    out(f"NJ120_API_URL = {env('NJ120_API_URL') or 'не задан'}")
    out(f"WORKER_API_TOKEN = {mask(env('WORKER_API_TOKEN'))}")
    out(f"TOOLFORGE_API_URL = {env('TOOLFORGE_API_URL') or 'НЕ ЗАДАН'}")
    out(f"TOOLFORGE_API_KEY = {mask(env('TOOLFORGE_API_KEY'))}")
    out(f"TOOLFORGE_DEFAULT_MODEL = {env('TOOLFORGE_DEFAULT_MODEL')}")
    out(f"NJ570_CODE_MODEL = {env('NJ570_CODE_MODEL') or '(пусто = дефолт)'}")
    out(f"NJ570_DESC_MODEL = {env('NJ570_DESC_MODEL') or '(пусто = дефолт)'}")
    out(f"NJ570_VISION_MODEL = {env('NJ570_VISION_MODEL') or '(пусто = дефолт)'}")
    out(f"NJ570_UPLOAD_URL = {env('NJ570_UPLOAD_URL') or 'НЕ ЗАДАН (файлы не сохранятся!)'}")
    out(f"NJ570_SITE_TOKEN = {mask(env('NJ570_SITE_TOKEN') or env('WORKER_API_TOKEN'))}"
        + (" (фолбэк на WORKER_API_TOKEN)" if not env('NJ570_SITE_TOKEN') else ""))
    if env('NJ570_RENDER_URL') or env('NJ570_RENDER_TOKEN'):
        out("NJ570_RENDER_URL/NJ570_RENDER_TOKEN в .env БОЛЬШЕ НЕ ИСПОЛЬЗУЮТСЯ "
            "(рендер только локальный) — удалите эти строки из workers/.env")
    out(f"NJ570_OPENSCAD_BIN = {env('NJ570_OPENSCAD_BIN') or '(автопоиск)'}")
    out(f"NJ570_OPENSCAD_LIB = {env('NJ570_OPENSCAD_LIB') or '(автопоиск)'}")
    out(f"NJ570_OPENSCAD_TIMEOUT = {env('NJ570_OPENSCAD_TIMEOUT', '120')}")
    out(f"NJ570_MAX_ITER = {env('NJ570_MAX_ITER', '5')}")

    channels = env("WORKER_CHANNELS")
    out(f"WORKER_CHANNELS = {channels or '(пусто = все зарегистрированные каналы)'}")
    if channels and "nj570" not in channels:
        problems.append(
            f"WORKER_CHANNELS='{channels}' не содержит nj570.* — воркер НИКОГДА не возьмёт "
            "задачи 3D-конструктора. Уберите переменную или добавьте nj570.generate.")

    if not env("TOOLFORGE_API_URL"):
        problems.append("TOOLFORGE_API_URL не задан — LLM недоступен, генерация упадёт.")
    if not env("NJ570_UPLOAD_URL"):
        problems.append("NJ570_UPLOAD_URL не задан — STL/PNG не будут сохраняться на сайте.")

    return problems


def check_openscad() -> list[str]:
    section("2. ЛОКАЛЬНЫЙ РЕНДЕР (openscad + BOSL2)")
    problems: list[str] = []

    sys.path.insert(0, str(WORKERS))
    try:
        from n2j_workers import scad
    except Exception as e:  # noqa: BLE001
        out(f"n2j_workers.scad не импортируется: {e}")
        return ["не удалось импортировать n2j_workers.scad"]

    bin_path = scad.openscad_bin()
    lib = scad.libraries_dir()
    out(f"openscad_bin: {bin_path or 'НЕ НАЙДЕН'}")
    out(f"BOSL2 (OPENSCADPATH): {lib or 'НЕ НАЙДЕН'}")

    if not bin_path:
        problems.append("openscad не найден: положите бинарник в workers/openscad-renderer/bin/openscad "
                        "или задайте NJ570_OPENSCAD_BIN, или sudo apt install openscad")
    if not lib:
        problems.append("BOSL2 не найден: cd workers && mkdir -p openscad-renderer/libraries && "
                        "git clone --depth 1 https://github.com/BelfrySCAD/BOSL2.git "
                        "openscad-renderer/libraries/BOSL2")
    if bin_path and lib:
        code, ver = run_cmd([bin_path, "--version"], 20)
        out(f"openscad --version: rc={code} · {ver[:200]}")
        if code != 0:
            low = ver.lower()
            if "fuse" in low or "appimage" in low:
                problems.append("Бинарник — AppImage, не хватает libfuse2: sudo apt install libfuse2 "
                                "(вариант: APPIMAGE_EXTRACT_AND_RUN=1 в systemd-юните воркера)")
            else:
                problems.append(f"openscad --version падает: {ver[:300]}")
        else:
            out("пробный рендер куба (PNG low + STL, до ~2 мин)…")
            t0 = time.monotonic()
            try:
                png, stl, err = asyncio.run(scad.render(
                    "include <BOSL2/std.scad>\ncube([20,10,5]);", quality="low"))
                dt = time.monotonic() - t0
                if err:
                    out(f"рендер: ОШИБКА за {dt:.0f}с → {err[:500]}")
                    problems.append(f"пробный рендер не удался: {err[:300]}")
                else:
                    out(f"рендер: OK за {dt:.0f}с (png {len(png or b'')} байт, stl {len(stl or b'')} байт)")
                    if dt > 100:
                        problems.append("рендер куба занял >100с — на VPS это очень медленно, "
                                        "генерации будут жить по 10+ минут")
            except Exception as e:  # noqa: BLE001
                out(f"рендер: исключение {type(e).__name__}: {e}")
                problems.append(f"пробный рендер упал: {e}")

    xvfb_code, xvfb = run_cmd(["bash", "-c", "command -v xvfb-run || command -v Xvfb"], 10)
    out(f"xvfb: {xvfb or 'НЕТ (нужен для PNG в безголовой среде)'}")
    if xvfb_code != 0 or not xvfb:
        problems.append("xvfb не найден: sudo apt install -y xvfb")

    return problems


def check_llm() -> list[str]:
    section("3. LLM-ШЛЮЗ (TOOLFORGE_API_URL)")
    problems: list[str] = []
    base = env("TOOLFORGE_API_URL").rstrip("/")
    if not base:
        return ["TOOLFORGE_API_URL не задан — проверка пропущена"]

    model = env("NJ570_CODE_MODEL") or env("TOOLFORGE_DEFAULT_MODEL") or "default"
    out(f"проверяю модель: {model} (ping, max_tokens=5)…")
    t0 = time.monotonic()
    status, data, raw = http_json(f"{base}/chat/completions",
                                  {"model": model,
                                   "messages": [{"role": "user", "content": "Скажи OK"}],
                                   "max_tokens": 5},
                                  token=env("TOOLFORGE_API_KEY"), timeout=90, auth=True)
    dt = time.monotonic() - t0
    if status == 200 and data and data.get("choices"):
        text = (data["choices"][0].get("message") or {}).get("content", "")[:60]
        usage = data.get("usage") or {}
        out(f"OK за {dt:.0f}с · ответ: {text!r} · токены: {usage.get('prompt_tokens')}+{usage.get('completion_tokens')}")
    else:
        out(f"ОШИБКА HTTP {status} за {dt:.0f}с: {(raw or (json.dumps(data, ensure_ascii=False) if data else ''))[:400]}")
        if status == 401:
            problems.append("LLM-шлюз: 401 даже с Authorization: Bearer — неверный TOOLFORGE_API_KEY "
                            "или в LM Studio другой токен (Server → API Token)")
        else:
            problems.append(f"LLM-шлюз не отвечает по {base} (HTTP {status}) — генерация кода невозможна")

    return problems


def check_site(model_code: str) -> list[str]:
    section("4. САЙТ И ОЧЕРЕДЬ (nj120 API, выгрузка файлов)")
    problems: list[str] = []
    api = env("NJ120_API_URL").rstrip("/")
    token = env("WORKER_API_TOKEN")

    if api:
        status, data, raw = http_json(f"{api}/stats", token=token, timeout=20)
        if status == 200 and data and data.get("ok"):
            out(f"очередь отвечает · ВЕРСИЯ САЙТА: {data.get('version')}")
            stats = data.get("stats")
            out(f"stats (всего по очереди): {json.dumps(stats, ensure_ascii=False)[:800]}")
        else:
            out(f"очередь НЕ отвечает: HTTP {status} {(raw or '')[:200]}")
            problems.append(f"nj120 API недоступен ({api}): HTTP {status} — проверьте NJ120_API_URL/токен")

    upload = env("NJ570_UPLOAD_URL").rstrip("/")
    if upload:
        # фиктивная выгрузка: ожидаем 401 (токен неверный) или 400 (токен ок, ревизии нет)
        png1x1 = bytes.fromhex(
            "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
            "0000000d4944415478da63f8ffff3f0300050201f34d3f1b0000000049454e44ae426082")
        boundary = "----nj570doctor"
        body = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"revision_id\"\r\n\r\n0\r\n"
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"kind\"\r\n\r\npng\r\n"
                f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"t.png\"\r\n"
                f"Content-Type: image/png\r\n\r\n").encode() + png1x1 + f"\r\n--{boundary}--\r\n".encode()
        req = urllib.request.Request(upload, data=body, method="POST")
        req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
        req.add_header("X-Worker-Token", env("NJ570_SITE_TOKEN") or token)
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                code_http, answer = resp.status, resp.read().decode("utf-8", "replace")[:200]
        except urllib.error.HTTPError as e:
            code_http, answer = e.code, e.read().decode("utf-8", "replace")[:200]
        except Exception as e:  # noqa: BLE001
            code_http, answer = 0, f"{type(e).__name__}: {e}"
        out(f"тест выгрузки /3d/api/upload: HTTP {code_http} · {answer}")
        if code_http == 401:
            problems.append("NJ570_SITE_TOKEN отвергнут сайтом — STL/PNG сохраняться не будут. "
                            "Токен должен быть равен WORKER_API_TOKEN из .env САЙТА.")
        elif code_http == 400 and "auth" not in answer:
            out("  (400 = токен принят, ревизии 0 нет — это ожидаемый ответ, выгрузка настроена верно)")
        elif code_http == 0:
            problems.append(f"сайт недоступен с сервера воркеров: {answer}")
    else:
        problems.append("NJ570_UPLOAD_URL не задан — куда выгружать STL/PNG?")

    # хранилище сайта: существует? доступно на запись? есть ли файлы?
    origin = upload.split("/3d/")[0] if "/3d/" in upload else "https://n2j.ru"
    status, data, raw = http_json(f"{origin}/3d/api/storage",
                                  token=env("NJ570_SITE_TOKEN") or token, timeout=20)
    if status == 200 and isinstance(data, dict):
        out(f"хранилище сайта: {data.get('dir')}")
        out(f"  каталог есть: {data.get('is_dir')} · доступен на запись: {data.get('is_writable')} "
            f"· родитель на запись: {data.get('writable_root')} · файлов: {data.get('count')}")
        for f in (data.get('recent') or []):
            out(f"  свежее: {f['f']} ({f['size']} байт)")
        if not data.get('is_dir') and not data.get('writable_root'):
            problems.append("Хранилище сайта не существует и родитель не доступен на запись — "
                            "bash bin/setup.sh на сервере САЙТА (создаёт public_html/storage/nj570)")
        elif data.get('is_dir') and not data.get('is_writable'):
            problems.append("Хранилище сайта ЕСТЬ, но php-fpm не может писать: "
                            "chmod -R a+rwX public_html/storage на сервере сайта")
        elif (data.get('count') or 0) == 0:
            out("  (файлов пока нет — выгрузки ещё не было или они терялись)")
    else:
        out(f"хранилище сайта: HTTP {status} {(raw or '')[:150]} "
            "(нужен деплой сайта с эндпоинтом /3d/api/storage)")

    if model_code:
        status, data, raw = http_json(f"{origin}/3d/api/status/{model_code}", timeout=20)
        if status == 200 and isinstance(data, dict):
            out(f"статус модели {model_code}: {json.dumps(data, ensure_ascii=False)[:600]}")
        elif status == 403:
            out(f"статус модели {model_code}: HTTP 403 (приватная модель — без входа это норма; "
                "смотрите статус на /3d/<код> под своим логином)")
        else:
            out(f"статус модели {model_code}: HTTP {status} {(raw or '')[:150]}")

    return problems


def check_processes() -> list[str]:
    section("5. ПРОЦЕССЫ И ЖУРНАЛ (systemd)")
    problems: list[str] = []

    code, ps = run_cmd(["bash", "-c", "ps aux | grep -E 'n2j[._-]?worker|n2j_workers' | grep -v grep"], 10)
    out(f"процессы воркера: {'НЕТ — воркер не запущен!' if code != 0 else ps[:500]}")
    if code != 0:
        problems.append("процесс воркера не найден: systemctl start n2j-worker (или как называется юнит)")

    active = None
    for unit in ("n2j-worker", "n2j_workers", "n2jworker"):
        code, state = run_cmd(["systemctl", "is-active", unit], 10)
        if state.strip() in ("active", "activating", "failed", "inactive"):
            out(f"systemctl is-active {unit}: {state.strip()}")
            if active is None and state.strip() != "inactive":
                active = unit

    unit = active or "n2j-worker"
    code, log = run_cmd(["journalctl", "-u", unit, "-n", "60", "--no-pager", "-o", "short-iso"], 20)
    if code == 0 and log:
        out(f"--- journalctl -u {unit} (последние 60 строк) ---")
        for line in log.splitlines()[-60:]:
            out(line)
        out("--- конец журнала ---")
    else:
        problems.append(f"журнал недоступен без прав: запустите отдельно "
                        f"'sudo journalctl -u {unit} -n 80 --no-pager' и пришлите вывод")

    return problems


def main() -> int:
    model_code = sys.argv[1] if len(sys.argv) > 1 else ""
    load_env()

    out("NJ570 DOCTOR — диагностика 3D-конструктора")
    out(f"время: {time.strftime('%Y-%m-%d %H:%M:%S')}")

    all_problems: list[str] = []
    all_problems += check_env()
    all_problems += check_openscad()
    all_problems += check_llm()
    all_problems += check_site(model_code)
    all_problems += check_processes()

    section("ИТОГ")
    if all_problems:
        out("Найденные проблемы (по убыванию важности):")
        for i, problem in enumerate(all_problems, 1):
            out(f"  {i}. {problem}")
    else:
        out("Критичных проблем не найдено. Если модель всё ещё «в работе» — генерация "
            "занимает 2-10 минут (LLM + локальный рендер). Поглядите: "
            "journalctl -u n2j-worker -f — будут строки nj570[code#1], nj570[vision#1]…")

    out()
    out(">>> Пришлите агенту ВСЁ, что выше (файл-копия: /tmp/nj570_doctor_report.txt)")

    try:
        Path("/tmp/nj570_doctor_report.txt").write_text("\n".join(REPORT_LINES), encoding="utf-8")
    except OSError:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
