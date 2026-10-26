"""Локальный рендер OpenSCAD для nj570 «Sketch 2 Solid» — внутри n2j_workers,
без HTTP-обращений к внешнему сервису openscad-renderer.

Повторяет проверенную конфигурацию workers/openscad-renderer/app.py:
  * бинарник: NJ570_OPENSCAD_BIN → workers/openscad-renderer/bin/openscad → which('openscad');
  * под xvfb-run (если есть) — openscad в безголовой среде требует дисплей;
  * OPENSCADPATH → каталог с BOSL2 (NJ570_OPENSCAD_LIB → workers/openscad-renderer/libraries
    → workers/libraries);
  * белый список include/use: только BOSL2/*.scad (остальное отвергаем ДО запуска);
  * PNG: --imgsize=RxR --projection=p --render --autocenter --viewall --colorscheme=X,
    затем Pillow: прозрачный фон (цвет фола по схеме или самый частый цвет);
  * STL: openscad -o out.stl (полный CGAL-рендер);
  * ошибка = returncode != 0, нет файла или «ERROR:» в выводе → отдаём отфильтрованный
    stderr (строки ERROR/WARNING/line) — его кормим код-модели в фикс-цикл.

Настройки (workers/.env):
    NJ570_OPENSCAD_BIN=       # путь к openscad (пусто = автопоиск)
    NJ570_OPENSCAD_LIB=       # каталог с BOSL2/ (пусто = автопоиск)
    NJ570_OPENSCAD_TIMEOUT=120
    NJ570_COLORSCHEME=Starnight
"""
from __future__ import annotations

import asyncio
import logging
import os
import re
import shutil
import subprocess
import tempfile
from pathlib import Path

log = logging.getLogger("n2j.nj570.scad")

# --- настройки -----------------------------------------------------------------

_REPO = Path(__file__).resolve().parent.parent.parent  # .../workers/n2j_workers → корень репо

_QUALITY = {
    "low":    {"render_size": 1024, "output_size": 512},
    "medium": {"render_size": 1536, "output_size": 768},
    "high":   {"render_size": 2048, "output_size": 1024},
}
DEFAULT_QUALITY = "low"
DEFAULT_COLORSCHEME = "Starnight"

_COLORSCHEME_BACKGROUNDS = {
    "starnight": (0, 0, 0),
    "tomorrow night": (0, 0, 0),
    "monotone": (0, 0, 0),
    "nature": (255, 255, 255),
}

_INCLUDE_RE = re.compile(r"(?:^|\n)\s*(?:include|use)\s*<([^>]+)>")
_ALLOWED_INCLUDE = re.compile(r"^BOSL2/[a-zA-Z0-9_\-\./]+\.scad$")
MAX_CODE_SIZE = 300_000


def _env(name: str, default: str = "") -> str:
    return os.environ.get(name) or default


def openscad_bin() -> str | None:
    """Путь к openscad: env → bin соседнего openscad-renderer → PATH."""
    custom = _env("NJ570_OPENSCAD_BIN")
    if custom and os.path.isfile(custom) and os.access(custom, os.X_OK):
        return custom

    sibling = _REPO / "workers" / "openscad-renderer" / "bin" / "openscad"
    if sibling.is_file():
        if not os.access(sibling, os.X_OK):
            try:  # бинарник из git может приехать без бита исполнения
                os.chmod(sibling, 0o755)
            except OSError:
                pass
        if os.access(sibling, os.X_OK):
            return str(sibling)

    return shutil.which("openscad")


def libraries_dir() -> str | None:
    """Каталог, внутри которого лежит BOSL2/ (для OPENSCADPATH)."""
    custom = _env("NJ570_OPENSCAD_LIB")
    for cand in ([custom] if custom else []) + [
        str(_REPO / "workers" / "openscad-renderer" / "libraries"),
        str(_REPO / "workers" / "libraries"),
    ]:
        if cand and (Path(cand) / "BOSL2").is_dir():
            return cand
    return custom or None


def available() -> bool:
    """Локальный рендер доступен (нужны бинарник и BOSL2)."""
    return openscad_bin() is not None and libraries_dir() is not None


def check_includes(code: str) -> str | None:
    """Белый список include/use — только BOSL2/*.scad. None = всё разрешено."""
    if not code or not code.strip():
        return "Отсутствует OpenSCAD код."
    if len(code) > MAX_CODE_SIZE:
        return f"Размер кода превышает лимит ({MAX_CODE_SIZE} символов)."

    for target in _INCLUDE_RE.findall(code):
        target = target.strip()
        if ".." in target or target.startswith("/") or target.startswith("\\"):
            return f"Недопустимый путь подключения файла: '{target}'."
        if not _ALLOWED_INCLUDE.match(target):
            return (f"Библиотека '{target}' запрещена. "
                    "Разрешены только модули BOSL2 (например, BOSL2/std.scad).")
    return None


def _filter_log(full_log: str) -> str:
    lines = [ln for ln in full_log.splitlines()
             if "ERROR:" in ln or "WARNING:" in ln or "line" in ln.lower()]
    return "\n".join(lines) if lines else full_log[:2000]


def _run(cmd: list, output_file: str, timeout: int) -> tuple[bool, str]:
    """Запуск openscad (под xvfb-run, если есть). → (ok, отфильтрованный лог)."""
    env = os.environ.copy()
    lib = libraries_dir()
    if lib:
        env["OPENSCADPATH"] = f"{lib}:{env['OPENSCADPATH']}" if env.get("OPENSCADPATH") else lib

    xvfb = shutil.which("xvfb-run")
    full_cmd = ([xvfb, "--auto-servernum", "--server-args=-screen 0 2048x2048x24"] + cmd) if xvfb else cmd

    try:
        proc = subprocess.run(full_cmd, capture_output=True, text=True, timeout=timeout, env=env)
    except subprocess.TimeoutExpired:
        return False, f"Timeout: рендеринг превысил лимит {timeout} с"

    full_log = ((proc.stdout or "") + "\n" + (proc.stderr or "")).strip()
    failed = proc.returncode != 0 or not os.path.exists(output_file) or "ERROR:" in full_log
    return (not failed), (_filter_log(full_log) if failed else "")


def _transparent_png(png_path: str, target_size: int, colorscheme: str) -> None:
    """Прозрачный фон + ресайз (как в openscad-renderer)."""
    try:
        from PIL import Image
    except ImportError:
        log.warning("nj570.scad: нет Pillow — PNG остаётся с фоном (pip install Pillow)")
        return

    try:
        img = Image.open(png_path).convert("RGBA")
        if img.width > target_size:
            img = img.resize((target_size, target_size), Image.LANCZOS)

        key = (colorscheme or "").lower().strip()
        bg = _COLORSCHEME_BACKGROUNDS.get(key)
        if bg is None:
            from collections import Counter
            bg = Counter(item[:3] for item in img.getdata()).most_common(1)[0][0]

        img.putdata([(0, 0, 0, 0) if item[:3] == bg else item for item in img.getdata()])
        img.save(png_path, "PNG", optimize=True)
    except Exception:  # noqa: BLE001 — превью не критично
        log.warning("nj570.scad: постобработка PNG не удалась", exc_info=True)


_RENDER_SEM: asyncio.Semaphore | None = None


def _render_sem() -> asyncio.Semaphore:
    """Одновременные openscad-процессы ограничены: при CONCURRENCY>1 несколько
    задач иначе роняют сервер рендерами (перегрузка/OOM = «всё пропало»)."""
    global _RENDER_SEM

    if _RENDER_SEM is None:
        try:
            n = max(1, int(_env("NJ570_MAX_RENDER_PARALLEL", "2") or 2))
        except ValueError:
            n = 2
        _RENDER_SEM = asyncio.Semaphore(n)

    return _RENDER_SEM


async def render(code: str, quality: str = DEFAULT_QUALITY, with_stl: bool = True,
                 colorscheme: str | None = None) -> tuple[bytes | None, bytes | None, str]:
    """(png, stl, stderr). stderr непуст при ошибке кода (для фикс-цикла LLM)."""
    async with _render_sem():
        return await _render_locked(code, quality, with_stl, colorscheme)


async def _render_locked(code: str, quality: str, with_stl: bool,
                         colorscheme: str | None) -> tuple[bytes | None, bytes | None, str]:
    bin_path = openscad_bin()
    lib = libraries_dir()

    if bin_path is None:
        return None, None, "openscad не найден (NJ570_OPENSCAD_BIN/PATH)"
    if lib is None:
        return None, None, "BOSL2 не найден (NJ570_OPENSCAD_LIB: каталог, внутри которого BOSL2/)"

    err = check_includes(code)
    if err:
        return None, None, err

    q = _QUALITY.get(quality, _QUALITY[DEFAULT_QUALITY])
    colorscheme = colorscheme or _env("NJ570_COLORSCHEME", DEFAULT_COLORSCHEME)
    timeout = int(_env("NJ570_OPENSCAD_TIMEOUT", "120") or 120)

    png: bytes | None = None
    stl: bytes | None = None

    with tempfile.TemporaryDirectory(prefix="nj570_scad_") as tmp:
        scad_path = os.path.join(tmp, "model.scad")
        with open(scad_path, "w", encoding="utf-8") as f:
            f.write(code)

        png_path = os.path.join(tmp, "model.png")
        ok, log_text = await asyncio.to_thread(
            _run,
            [bin_path, "-o", png_path,
             f"--imgsize={q['render_size']},{q['render_size']}",
             "--projection=p", "--render", "--autocenter", "--viewall",
             f"--colorscheme={colorscheme}", scad_path],
            png_path, timeout)

        if not ok:
            return None, None, f"PNG: {log_text}"

        await asyncio.to_thread(_transparent_png, png_path, q["output_size"], colorscheme)
        try:
            with open(png_path, "rb") as f:
                png = f.read()
        except OSError:
            png = None

        if with_stl:
            stl_path = os.path.join(tmp, "model.stl")
            # CGAL-экспорт тяжёлее превью: свой (больший) лимит
            stl_timeout = int(_env("NJ570_OPENSCAD_STL_TIMEOUT", "300") or 300)
            ok, log_text = await asyncio.to_thread(
                _run, [bin_path, "-o", stl_path, scad_path], stl_path, stl_timeout)
            if not ok:
                return png, None, f"STL: {log_text}"
            try:
                with open(stl_path, "rb") as f:
                    stl = f.read()
            except OSError:
                stl = None

    return png, stl, ""
