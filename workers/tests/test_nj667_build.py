"""Канал nj667.build: .scad сборки → STL в OpenSCAD (воркер, без модели).

Владелец (12.10.2026): «воркер должен делать из графовой структуры 3D-модель
конструкции и возвращать STL». Дорогое здесь — не LLM, а CSG, поэтому тесты
держат ровно то, что ломает сервис тихо:

  * нет бинаря — воркер обязан сказать `no_engine`, а не «модель не собралась»
    (страница по этому слову предлагает человеку собрать .scad самому);
  * пустой или обрезанный STL — это ошибка, а не успех с нулевым файлом:
    такую модель отдали бы в цех;
  * итог всегда доезжает до сайта (/build_result), иначе сборка повиснет
    «в очереди навсегда»;
  * выгрузка не настроена — потерянные байты должны быть видны в ответе.
"""
import asyncio
import json
import os
import struct

import pytest

import n2j_workers.handlers.nj667_graph3d as mod


class _Ctx:
    """Контекст задачи: только heartbeat — LLM этому каналу не нужна."""

    def __init__(self):
        self.beats = 0

    async def heartbeat(self):
        self.beats += 1
        return True


def stl_bytes(triangles: int = 1) -> bytes:
    """Бинарный STL из `triangles` граней (84 + 50·N байт)."""
    head = b"nj667 test".ljust(80, b" ")
    body = b""
    for _ in range(triangles):
        body += struct.pack("<12f", 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0) + b"\x00\x00"

    return head + struct.pack("<I", triangles) + body


@pytest.fixture(autouse=True)
def _no_llm(monkeypatch):
    """Ни одного обращения к модели: канал обязан жить без ключа."""
    monkeypatch.setattr(mod, "_ask", lambda *a, **k: pytest.fail("nj667.build не зовёт LLM"))


@pytest.fixture(autouse=True)
def _fresh_flags(monkeypatch):
    """Справку бинаря просим руками: subprocess и кэш флагов в тесте не нужны.

    Дефолт — «современный openscad», т.е. оба необязательных флага разрешены;
    тесты на старые сборки подменяют `_help_text` своим текстом.
    """
    mod._PROBE_CACHE.clear()
    monkeypatch.setattr(mod, "_help_text", lambda bin_path:
                       " --export-format <arg>  \n --hardwarnings \n -o <file>")
    monkeypatch.setattr(mod, "_version_text", lambda bin_path: "OpenSCAD version 2021.01")
    yield
    mod._PROBE_CACHE.clear()


def fake_files(stl: bytes | None, wrl: bytes | None = None):
    """Подмена рендера whole-сборки: ровно то, что вернул бы `_files_of`.

    VRML отдаём только если его реально просили (`exts`): по этому признаку и
    проверяем, что лишний выходOpenSCAD не зовут впустую.
    """
    def run(code, timeout, exts=("stl",)):
        if stl is None:
            return {}, "no_engine: openscad не найден (NJ667_OPENSCAD_BIN/NJ570_OPENSCAD_BIN/PATH)"

        out = {"stl": stl}

        if wrl and "wrl" in exts:
            out["wrl"] = wrl

        return out, ""

    return run


def wrl_two_colors() -> bytes:
    """Кусок VRML с двумя `color()`: две группы граней, два материала."""
    return ("""#VRML V2.0 utf8
Shape {
  appearance Appearance { material Material { diffuseColor 0.278 0.51 0.706 } }
  geometry IndexedFaceSet {
    coord Coordinate { point [ 0 0 0, 10 0 0, 10 10 0, 0 10 0 ] }
    coordIndex [ 0 1 2 -1 , 0 2 3 -1 , -1 ]
  }
}
Shape {
  appearance Appearance { material Material { diffuseColor 0.85 0.65 0.13 } }
  geometry IndexedFaceSet {
    coord Coordinate { point [ 0 0 5, 10 0 5, 10 10 5 ] }
    coordIndex [ 0 1 2 -1 , -1 ]
  }
}
""").encode()


def ascii_stl(triangles: int = 1) -> bytes:
    """ASCII solid — ровно то, что выкладывает openscad без --export-format."""
    out = ["solid nj667"]
    for i in range(triangles):
        out += ["  facet normal 0 0 1", "    outer loop",
                f"      vertex {i} 0 0", f"      vertex {i + 1} 0 0", f"      vertex {i} 1 0",
                "    endloop", "  endfacet"]
    out.append("endsolid nj667")

    return "\n".join(out).encode()


# ------------------------------------------------------------------ _stl_of

def test_no_engine_says_no_engine(monkeypatch):
    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: None)

    data, error = mod._stl_of("cube(10);", 60)

    assert data is None
    assert error.startswith("no_engine")


def test_include_outside_bosl_rejected(monkeypatch, tmp_path):
    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod.scad, "libraries_dir", lambda: str(tmp_path))

    data, error = mod._stl_of('include <../../etc/passwd>\ncube(10);', 60)

    assert data is None
    assert "Недопустимый путь подключения" in error or "запрещена" in error


def test_stl_from_disk(monkeypatch, tmp_path):
    """_run пишет файл — байты уезжают наружу без изменений (движок новый)."""
    target = tmp_path / "model.stl"
    payload = stl_bytes(2)

    def fake_run(cmd, output_file, timeout):
        assert "--export-format=binary" in cmd, "только бинарный STL: ASCII в 6 раз тяжелее"
        assert "--hardwarnings" in cmd, "пустой CSG обязан стать ошибкой, а не файлом-обманкой"
        assert timeout == 60
        with open(output_file, "wb") as handle:
            handle.write(payload)
        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    data, error = mod._stl_of("cube(10);", 60)

    assert error == ""
    assert data == payload


def test_old_openscad_gets_no_unknown_flags(monkeypatch):
    """2015.03: в справке нет ни --export-format, ни --hardwarnings → не зовём.

    Иначе человек получает ровно то, что ловили в бою:
    «openscad: Unknown --export-format option 'binary'. Use -h to list
    available options.» — и ни одной модели.
    """
    seen = []

    def fake_run(cmd, output_file, timeout):
        seen.append(cmd)
        with open(output_file, "wb") as handle:
            handle.write(stl_bytes(1))
        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod, "_help_text", lambda bin_path: "Usage: openscad [options] file\n")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    data, error = mod._stl_of("cube(10);", 60)

    assert error == "" and data
    assert "--export-format=binary" not in seen[0]
    assert "--hardwarnings" not in seen[0]
    assert seen[0][:2] == ["/usr/bin/openscad", "-o"]


def test_broken_help_still_renders(monkeypatch):
    """Не удалось прочитать справку — работаем минимальной командой, не падая."""
    seen = []

    def fake_run(cmd, output_file, timeout):
        seen.append(cmd)
        with open(output_file, "wb") as handle:
            handle.write(stl_bytes(1))
        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod, "_help_text", lambda bin_path: "")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    data, error = mod._stl_of("cube(10);", 60)

    assert error == "" and data
    assert len(seen) == 1, "без флагов повтор не нужен"


def test_unknown_option_retries_without_flags(monkeypatch):
    """Справка обещала флаг, бинарь его отверг — повторяем без него и запоминаем."""
    seen = []
    payload = stl_bytes(3)

    def fake_run(cmd, output_file, timeout):
        seen.append(cmd)
        if len(seen) == 1:
            return False, "ERROR: Unknown --export-format option 'binary'. Use -h to list available options."
        with open(output_file, "wb") as handle:
            handle.write(payload)
        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    data, error = mod._stl_of("cube(10);", 60)

    assert error == ""
    assert data == payload
    assert len(seen) == 2
    assert "--export-format=binary" in seen[0] and "--export-format=binary" not in seen[1]
    assert mod._openscad_flags("/usr/bin/openscad") == [], "вывод запоминаем, не спорим"


def test_ancient_engine_says_no_engine(monkeypatch):
    """0.15 не понимает ни let(), ни norm(): это не «модель не собралась».

    Страница по префиксу no_engine отдаёт ссылку на .scad и честную строчку
    вместо стека парсера; рендер при этом не запускается вовсе (минута CPU зря).
    """
    calls = []

    def fake_run(cmd, output_file, timeout):
        calls.append(cmd)
        return False, "ERROR: Parser error: syntax error line 1"

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod, "_version_text", lambda bin_path: "OpenSCAD version 0.15")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    data, error = mod._stl_of("cube(10);", 60)

    assert data is None
    assert error.startswith("no_engine") and "2019.05" in error
    assert calls == [], "старый бинарь не трогаем"

    assert mod._version_too_old("OpenSCAD version 2021.01") is False
    assert mod._version_too_old("OpenSCAD version 2019.05") is False
    assert mod._version_too_old("OpenSCAD version 2019.04") is True
    assert mod._version_too_old("") is False, "не поняли версию — не мешаем"


def test_ascii_stl_becomes_binary(monkeypatch):
    """Старый openscad выложил текст: до сайта должен доехать бинарный STL."""
    def fake_run(cmd, output_file, timeout):
        with open(output_file, "wb") as handle:
            handle.write(ascii_stl(2))
        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    data, error = mod._stl_of("cube(10);", 60)

    assert error == ""
    assert len(data) == 84 + 50 * 2, "бинарный: тот же расчёт, что у stl_stats сайта"
    assert data[:len(b"nj667: ascii -> binary")] == b"nj667: ascii -> binary"
    assert int.from_bytes(data[80:84], "little") == 2
    assert struct.unpack("<12f", data[84:132])[0:3] == (0.0, 0.0, 1.0), "нормаль"
    assert struct.unpack("<9f", data[96:132][:36]) == (0, 0, 0, 1, 0, 0, 0, 1, 0), "грань #1"
    assert data[132:134] == b"\x00\x00", "атрибут-падинг тоже на месте"


def test_rejects_not_a_stl(monkeypatch):
    """Мусор вместо граней — ошибка, а не «успех, 0 байт» (это несут в цех)."""
    def fake_run(cmd, output_file, timeout):
        with open(output_file, "wb") as handle:
            handle.write(b"WEIRD" * 40)
        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    data, error = mod._stl_of("cube(10);", 60)

    assert data is None
    assert "не STL" in error


def test_openscad_failure_and_empty_mesh(monkeypatch):
    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")

    def fail_run(cmd, output_file, timeout):
        return False, "Can't open OpenSCAD source file"

    monkeypatch.setattr(mod.scad, "_run", fail_run)
    data, error = mod._stl_of("cube(", 60)
    assert data is None and "openscad" in error

    def short_run(cmd, output_file, timeout):
        with open(output_file, "wb") as handle:
            handle.write(b"solid\n")           # заголовок STL — 84 байта, тут 6
        return True, ""

    monkeypatch.setattr(mod.scad, "_run", short_run)
    data, error = mod._stl_of("cube(10);", 60)
    assert data is None and "пуст" in error


# ---------------------------------------------------------------- handle_build

class _Site(dict):
    """Сайт: отдаёт контекст сборки и принимает итоги (их и проверяем)."""

    def __init__(self, context, plan=None, part_scads=None):
        super().__init__()
        self.context = context
        self.plan = plan
        self.part_scads = part_scads or {}
        self.results = []
        self.uploads = []
        self.blobs = []
        self.asked = []

    async def api(self, ctx, path, data, timeout=180.0):
        if path != "/build":
            raise AssertionError("неожиданный путь " + path)

        self.asked.append(dict(data))

        if data.get("kind") == "parts":
            assert self.plan is not None, "план пачки не заготовлен"
            return dict(self.plan)

        if data.get("part") is not None:
            return dict(self.part_scads[int(data["part"])])

        return dict(self.context)

    async def api_soft(self, ctx, path, data, timeout=180.0):
        assert path == "/build_result", path
        self.results.append(dict(data))
        return {"ok": True}

    async def upload(self, build_id, data, engine, limit_mb, part=None, kind="stl", parts_run_id=None):
        # part=None — файл сборки; число — файл узла (и 0 — это узел, а не «нет»)
        self.uploads.append((build_id, len(data), engine, limit_mb,
                             -1 if part is None else part, kind))
        self.blobs.append(data)          # байты рядом: хочется читать то, что уехало
        return ""


def _patch_site(monkeypatch, site):
    monkeypatch.setattr(mod, "_api", site.api)
    monkeypatch.setattr(mod, "_api_soft", site.api_soft)
    monkeypatch.setattr(mod, "_build_upload", site.upload)


def test_build_requires_build_id():
    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 0}))

    assert out["ok"] is False
    assert "build_id" in out["error"]


def test_happy_path_reports_done(monkeypatch):
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3), wrl_two_colors()))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 41, "kind": "stl"}))

    assert out["ok"] is True and out["bytes"] == 84 + 150
    assert out["render_ms"] >= 0
    assert [u[5] for u in site.uploads] == ["glb", "stl"], \
        "сначала то, что видно в браузере, потом то, чем печатают"
    assert all(u[0] == 41 and u[3] == 24 and u[4] == -1 for u in site.uploads), \
        "общая сборка уходит без part, лимит сайта — тот, что назван"
    assert site.uploads[1][1] == 84 + 150
    assert out["glb_bytes"] == site.uploads[0][1] == len(site.blobs[0])

    info = mod.glb.glb_info(site.blobs[0])

    assert info["ok"] is True and info["triangles"] == 3, "сайту в руки должен доесть читаемый glTF"
    assert info["dims"] == [10.0, 10.0, 5.0], "миллиметры как есть: ни шага в мм, ни деления на 10"
    assert info["groups"] == 2, "два цвета из OpenSCAD — два примитива в glTF"
    assert site.results and site.results[0]["ok"] is True
    assert "render_ms" in site.results[0]


def test_keep_stl_zero_uploads_only_glb(monkeypatch):
    """«STL не хранить» — на диске только glTF: просмотр есть, лишний файл нет.

    Лимит размера при этом не обходится: сайт всё так же режет, а печать —
    отдельная кнопка, она отдаёт то, что реально лежит.
    """
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24, "keep_stl": 0})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3), wrl_two_colors()))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 43}))

    assert out["ok"] is True
    assert [u[5] for u in site.uploads] == ["glb"]
    assert site.results[0]["ok"] is True and site.results[0]["error"] == ""


def test_glb_render_kind_uploads_only_glb(monkeypatch):
    """Новая обычная сборка отдаёт GLB и не хранит STL изделия."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24,
                  "render_kind": "glb", "glb": True, "keep_stl": False})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3), wrl_two_colors()))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 51, "kind": "glb"}))

    assert out["ok"] is True and out["render_kind"] == "glb"
    assert [u[5] for u in site.uploads] == ["glb"]
    assert site.results[0]["ok"] is True


def test_glb_render_kind_does_not_fallback_to_stl_on_upload_error(monkeypatch):
    """Сбой GLB не должен сохранять STL всего изделия как запасной файл."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24,
                  "render_kind": "glb", "glb": True, "keep_stl": False})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3), wrl_two_colors()))

    async def reject_glb(build_id, data, engine, limit_mb, part=None, kind="stl"):
        site.uploads.append((build_id, len(data), engine, limit_mb, -1, kind))
        return "сайт отверг GLB" if kind == "glb" else ""

    monkeypatch.setattr(mod, "_build_upload", reject_glb)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 52}))

    assert [u[5] for u in site.uploads] == ["glb"]
    assert out["ok"] is False and site.results[0]["ok"] is False
    assert "GLB" in site.results[0]["error"]


def test_stl_render_kind_uploads_only_stl(monkeypatch):
    """Отдельный STL всего изделия существует только по модераторскому запросу."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24,
                  "render_kind": "stl", "glb": False})
    _patch_site(monkeypatch, site)
    requested = []

    def run(code, timeout, exts=("stl",)):
        requested.append(tuple(exts))
        return {"stl": stl_bytes(2), "wrl": wrl_two_colors()}, ""

    monkeypatch.setattr(mod, "_files_of", run)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 53, "kind": "stl"}))

    assert requested == [("stl",)]
    assert [u[5] for u in site.uploads] == ["stl"]
    assert out["ok"] is True and out["render_kind"] == "stl"


def test_glb_kind_does_not_fallback_to_stl_on_converter_error(monkeypatch):
    """Если GLB не собрался, задача завершается ошибкой без STL-fallback."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24,
                  "render_kind": "glb", "glb": True, "keep_stl": False})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3)))

    def boom(data, name="model"):
        raise mod.glb.GlbError("GLB не удалось собрать")

    monkeypatch.setattr(mod.glb, "stl_to_glb", boom)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 54}))

    assert out["ok"] is False
    assert site.uploads == []
    assert site.results[0]["ok"] is False
    assert "GLB не собран" in site.results[0]["error"]


def test_glb_kind_without_site_support_fails_before_render(monkeypatch):
    site = _Site({"ok": True, "scad": "cube(10);", "render_kind": "glb", "glb": False})
    _patch_site(monkeypatch, site)
    called = []
    monkeypatch.setattr(mod, "_files_of", lambda *a: called.append(1) or ({}, ""))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 55}))

    assert out["ok"] is False and "GLB" in out["error"]
    assert called == [] and site.uploads == []
    assert site.results[0]["ok"] is False


def test_legacy_glb_rejected_by_site_falls_back_to_stl(monkeypatch):
    """Старый job без render_kind сохраняет прежний STL-fallback."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24,
                  "render_kind": "legacy", "keep_stl": 0})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3), wrl_two_colors()))

    async def picky(build_id, data, engine, limit_mb, part=None, kind="stl"):
        site.uploads.append((build_id, len(data), engine, limit_mb, -1, kind))
        site.blobs.append(data)
        return "сайт отверг glTF: GLB обрезан" if kind == "glb" else ""

    monkeypatch.setattr(mod, "_build_upload", picky)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 46}))

    assert [u[5] for u in site.uploads] == ["glb", "stl"], "за отказом glTF обязан идти STL"
    assert out["ok"] is True and out["bytes"] == 84 + 150
    assert site.results[0]["ok"] is True
    assert "glTF" in site.results[0]["error"], "текст приёмки не прячем"


def test_legacy_broken_glb_keeps_the_stl(monkeypatch):
    """Старый job без render_kind продолжает хранить STL при сбое конвертера."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24,
                  "render_kind": "legacy"})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3)))

    def boom(data, name="model"):
        raise mod.glb.GlbError("кукол не собрался")

    monkeypatch.setattr(mod.glb, "stl_to_glb", boom)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 44}))

    assert out["ok"] is True and out["glb_bytes"] == 0
    assert [u[5] for u in site.uploads] == ["stl"]
    assert site.results[0]["ok"] is True


def test_no_engine_is_reported_to_site(monkeypatch):
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 0})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(None))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 42}))

    assert out["ok"] is False and out["no_engine"] is True
    # сайт обязан узнать: иначе сборка останется «в очереди» навсегда
    assert site.results and site.results[0]["ok"] is False
    assert "no_engine" in site.results[0]["error"]


def test_empty_scad_is_error_not_render(monkeypatch):
    site = _Site({"ok": True, "scad": "   ", "error": ""})
    _patch_site(monkeypatch, site)

    called = []
    monkeypatch.setattr(mod, "_files_of", lambda *a: called.append(1) or ({}, "не вызываем"))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 43}))

    assert out["ok"] is False
    assert not called, "рендер не запускается на пустом коде"


def test_lost_context_marks_build(monkeypatch):
    site = _Site({})

    async def boom(ctx, path, data, timeout=180.0):
        raise RuntimeError("сайт 503")

    monkeypatch.setattr(mod, "_api", boom)
    monkeypatch.setattr(mod, "_api_soft", site.api_soft)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 44}))

    assert out["ok"] is False
    assert site.results and site.results[0]["ok"] is False


def test_upload_not_configured_fails_loudly(monkeypatch):
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 0})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(1)))
    async def no_upload(build_id, data, engine, limit_mb, kind="stl"):
        return "выгрузка не настроена"

    monkeypatch.setattr(mod, "_build_upload", no_upload)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 45}))

    assert out["ok"] is False
    assert "выгрузка" in out["error"]
    assert site.results[0]["ok"] is False


# ------------------------------------------------------------------- каналы

def test_channel_registered():
    """Реестр воркера: без строки в HANDLERS задача никогда не дойдёт до нас."""
    src = open(os.path.join(os.path.dirname(mod.__file__), "__init__.py"), encoding="utf-8").read()

    assert '"nj667.build": nj667_graph3d.handle_build' in src


# --------------------------------------------------- цвет: VRML → GLB

def test_glb_from_vrml_paints_every_cluster(monkeypatch):
    """Раскраска по типам узлов живёт в GLB, а не выдумана просмотрщиком.

    Владелец (13.10.2026): «хотелось бы в браузере увидеть раскраску по типам
    узлов». Значит в glTF обязано быть N примитивов и N материалов, а цвета —
    те же, что в `color()` (в линейном пространстве glTF, это отдельная ловушка).
    """
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3), wrl_two_colors()))

    asyncio.run(mod.handle_build(_Ctx(), {"build_id": 47}))

    info = mod.glb.glb_info(site.blobs[0])
    json_len = int.from_bytes(site.blobs[0][12:16], "little")
    gltf = json.loads(site.blobs[0][20:20 + json_len].rstrip(b" "))
    colors = [m["pbrMetallicRoughness"]["baseColorFactor"] for m in gltf["materials"]]

    assert info["groups"] == 2 and len(gltf["meshes"][0]["primitives"]) == 2
    assert len(colors) == 2, "на каждый цвет — свой материал"
    assert colors[0][0] == pytest.approx(0.0598, abs=0.002), \
        "SteelBlue (0.278) обязан приехать линейным (gamma 2.2): как есть — вдвое ярче"
    assert all(c[3] == 1.0 for c in colors) and gltf["meshes"][0]["primitives"][0]["material"] == 0


def test_no_vrml_still_builds_grey_glb_with_a_note(monkeypatch):
    """Движок не выложил VRML — человек получает модель без цвета, но с объяснением."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3)))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 48}))

    assert out["ok"] is True and out["glb_bytes"] > 0
    assert out["glb_colored"] is False
    assert [u[5] for u in site.uploads] == ["glb", "stl"]
    assert "цвета в нём нет" in site.results[0]["error"]


def test_broken_vrml_falls_back_to_stl_glb(monkeypatch):
    """Битый VRML — не провал сборки: GLB собираем из STL и честно пишем про цвет."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24})
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(3), b"#VRML V2.0 utf8\nbroken"))

    def boom(data, name="x"):
        raise mod.glb.GlbError("VRML: coordIndex не закрыт «-1»")

    monkeypatch.setattr(mod.glb, "wrl_to_glb", boom)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 49}))

    assert out["ok"] is True and out["glb_bytes"] > 0
    assert "не собрался" in site.results[0]["error"]


def test_wrl_not_requested_when_glb_is_off(monkeypatch):
    """GLB не нужен — не гоняем второй выход OpenSCAD впустую (VRML дорогой)."""
    site = _Site({"ok": True, "scad": "cube(10);", "max_stl_mb": 24, "glb": 0})
    _patch_site(monkeypatch, site)
    asked = []

    def run(code, timeout, exts=("stl",)):
        asked.append(tuple(exts))
        return {"stl": stl_bytes(3)}, ""

    monkeypatch.setattr(mod, "_files_of", run)

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 50}))

    assert asked == [("stl",)] and out["glb_bytes"] == 0
    assert [u[5] for u in site.uploads] == ["stl"]


def test_files_of_wants_both_outputs_in_one_run(monkeypatch):
    """Один запуск на STL+VRML: два вызова openscad = двойной просчёт CSG."""
    seen = []

    def fake_run(cmd, output_file, timeout):
        seen.append(list(cmd))

        for path in cmd:
            if path.endswith(".stl"):
                with open(path, "wb") as handle:
                    handle.write(stl_bytes(2))
            elif path.endswith(".wrl"):
                with open(path, "wb") as handle:
                    handle.write(wrl_two_colors())

        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    files, error = mod._files_of("cube(10);", 60, ("stl", "wrl"))

    assert error == "" and sorted(files) == ["stl", "wrl"]
    assert len(seen) == 1, "CSG считается один раз, файлы раскладываются с него"
    assert seen[0].count("-o") == 2
    assert files["wrl"].startswith(b"#VRML")


def test_files_of_retries_a_missing_output_alone(monkeypatch):
    """Старый бинарь молча пропустил второй `-o` — добираем его без --export-format."""
    calls = []

    def fake_run(cmd, output_file, timeout):
        calls.append(list(cmd))

        for path in cmd:
            if not (path.endswith(".stl") or path.endswith(".wrl")):
                continue

            if path.endswith(".wrl") and len(calls) == 1:
                continue                        # старый бинарь промолчал про второй -o

            with open(path, "wb") as handle:
                handle.write(wrl_two_colors() if path.endswith(".wrl") else stl_bytes(2))

        return True, ""

    monkeypatch.setattr(mod.scad, "openscad_bin", lambda: "/usr/bin/openscad")
    monkeypatch.setattr(mod.scad, "_run", fake_run)

    files, error = mod._files_of("cube(10);", 60, ("stl", "wrl"))

    assert error == "" and files["wrl"].startswith(b"#VRML")
    assert len(calls) == 2, "недостающий выход добираем отдельным запуском"
    assert not any(a.startswith("--export-format") for a in calls[1]), "он про STL, VRML мешает"


def test_timeout_default_is_render_shaped(monkeypatch):
    monkeypatch.delenv("NJ667_BUILD_TIMEOUT", raising=False)
    assert mod._build_timeout() == 900

    monkeypatch.setenv("NJ667_BUILD_TIMEOUT", "45")
    assert mod._build_timeout() == 45

    monkeypatch.setenv("NJ667_BUILD_TIMEOUT", "много")
    assert mod._build_timeout() == 900


def test_upload_without_config_loses_loudly(monkeypatch):
    monkeypatch.setattr(mod.site, "module_api_base", lambda prefix, default: "")
    monkeypatch.setattr(mod.site, "site_token", lambda prefix: ("", ""))

    out = asyncio.run(mod._build_upload(1, b"x" * 100, "openscad", 0))

    assert "выгрузка" in out, "молча потерять байты — худший из исходов"


def test_upload_size_limit_blocks_before_http(monkeypatch):
    """Большой STL не едет через HTTP: сайт всё равно бы его отклонил."""
    monkeypatch.setattr(mod.site, "module_api_base", lambda prefix, default: "http://site.test")
    monkeypatch.setattr(mod.site, "site_token", lambda prefix: ("tok", "ENV"))

    out = asyncio.run(mod._build_upload(1, b"x" * (3 * 1024 * 1024), "openscad", 2))

    assert "больше 2 МБ" in out


# ------------------------------------------------------------------ пачка узлов

def _parts_site(**kw):
    plan = {"ok": True, "kind": "parts", "build_id": 7, "budget_s": kw.pop("budget_s", 3600),
            "max_stl_mb": 24, "parts": [{"part": p, "name": "nj667-7-part-%d" % p} for p in kw["parts"]]}
    return _Site({}, plan=plan, part_scads={p: {"ok": True, "scad": "cube(%d);" % p} for p in kw["parts"]})


def test_parts_render_one_file_per_type(monkeypatch):
    """Каждому уникальному типу — свой STL, и ноль это тоже узел, а не «нет».

    Отдельные файлы нужны потому, что печатают по одному узлу: STL сборки
    годится только «примерить», а copies множит слайсер.
    """
    site = _parts_site(parts=[0, 1])
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_stl_of", lambda code, timeout: (stl_bytes(2), ""))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 7, "kind": "parts"}))

    assert out["ok"] is True and out["done"] == 2 and out["failed"] == 0
    stl_ups = [u for u in site.uploads if u[5] == "stl"]
    assert [u[4] for u in stl_ups] == [0, 1], "part уезжает в сайт на каждый файл"
    assert all(u[5] in ("stl", "preview") for u in site.uploads), "узлы печатают из STL, glb к ним не относится"
    assert site.results[0]["parts"] == [
        {"part": 0, "ok": True, "error": "", "render_ms": site.results[0]["parts"][0]["render_ms"],
         "bytes": 84 + 100},
        {"part": 1, "ok": True, "error": "", "render_ms": site.results[0]["parts"][1]["render_ms"],
         "bytes": 84 + 100},
    ]


def test_parts_budget_stops_between_types(monkeypatch):
    """Бюджет кончился — пачку бросаем, а не тянем до kill'а очереди.

    Недобранное остаётся в queued/spec и доделывается той же кнопкой: человек
    видит «1 из 2», а не молча полученный один файл.
    """
    site = _parts_site(parts=[0, 1], budget_s=1)
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_files_of", fake_files(stl_bytes(1)))

    out = asyncio.run(mod.handle_build_parts(_Ctx(), {"build_id": 7}))

    assert out["ok"] is True and out["done"] == 1
    assert out["skipped"] == 1, "второй узел не начинаем"
    assert len([u for u in site.uploads if u[5] == "stl"]) == 1
    assert [p["part"] for p in site.results[0]["parts"]] == [0]


def _cube_stl() -> bytes:
    """Бинарный STL куба 10 мм: 12 граней, для проверки растеризатора."""
    v = [(0, 0, 0), (10, 0, 0), (10, 10, 0), (0, 10, 0),
         (0, 0, 10), (10, 0, 10), (10, 10, 10), (0, 10, 10)]
    faces = [(0, 2, 1), (0, 3, 2), (4, 5, 6), (4, 6, 7), (0, 1, 5), (0, 5, 4),
             (1, 2, 6), (1, 6, 5), (2, 3, 7), (2, 7, 6), (3, 0, 4), (3, 4, 7)]
    body = b""

    for a, b, c in faces:
        body += struct.pack("<3f", 0, 0, 0) + b"".join(struct.pack("<3f", *v[i]) for i in (a, b, c)) + b"\x00\x00"

    return b"nj667 cube".ljust(80, b" ") + struct.pack("<I", len(faces)) + body


def test_preview_png_is_128px_and_shows_the_part():
    """Превью узла: PNG 128×128, фигура видна (не пустой белый квадрат)."""
    import io

    from PIL import Image

    png = mod._preview_png(_cube_stl())
    img = Image.open(io.BytesIO(png))

    assert png[:8] == b"\x89PNG\r\n\x1a\n"
    assert img.size == (128, 128)
    assert img.mode == "RGBA", "фон прозрачный — нужен альфа-канал"
    painted = sum(1 for p in img.getdata() if p[3] > 0)
    assert painted > 500, "куб должен закрасить заметную часть кадра"
    assert img.getpixel((0, 0))[3] == 0, "угол кадра прозрачный, а не белый"
    assert len(png) < 64 * 1024, "превью — маленький файл, а не модель"


def test_preview_png_is_drawn_in_the_part_colour():
    """Цвет узла виден на картинке: строка и превью одного узла одного цвета."""
    import io
    from collections import Counter

    from PIL import Image

    rose = mod.PREVIEW_PALETTE[3]
    img = Image.open(io.BytesIO(mod._preview_png(_cube_stl(), mod.PREVIEW_PX, rose)))
    px = [p[:3] for p in img.getdata() if p[3] > 0]
    r, g, b = Counter(px).most_common(1)[0][0]

    assert r > g + 40 and r > b, "узел розовый, а не серо-синий"
    assert len(set(mod.PREVIEW_PALETTE)) == len(mod.PREVIEW_PALETTE), "соседние узлы не должны совпадать по цвету"


def test_preview_palette_is_the_assembly_palette():
    """Превью красим теми же цветами, что деталь в общей 3D-модели (PAL в .scad)."""
    import re
    from pathlib import Path

    scad = (Path(__file__).resolve().parents[2]
            / "application/modules/nj667_graph3d/scad/generators/assembly.scad").read_text(encoding="utf-8")
    pal = re.search(r"PAL\s*=\s*\[(.*?)\];", scad, re.S)

    assert pal, "PAL не нашли в assembly.scad"
    assert re.findall(r'"([A-Za-z]+)"', pal.group(1)) == mod.PREVIEW_PALETTE_NAMES, \
        "палитра превью разошлась с общей моделью"


def test_preview_png_empty_or_broken_stl_gives_no_picture():
    assert mod._preview_png(b"") == b""
    assert mod._preview_png(b"\x00" * 84) == b""          # ноль граней
    assert mod._preview_png(b"junk") == b""


def test_parts_upload_preview_png_next_to_each_stl(monkeypatch):
    site = _parts_site(parts=[0, 1])
    _patch_site(monkeypatch, site)
    monkeypatch.setattr(mod, "_stl_of", lambda code, timeout: (_cube_stl(), ""))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 7, "kind": "parts"}))

    assert out["ok"] is True and out["done"] == 2
    previews = [(u[4], b) for u, b in zip(site.uploads, site.blobs) if u[5] == "preview"]
    assert [p for p, _ in previews] == [0, 1], "превью уходит на каждый узел"
    assert all(b[:8] == b"\x89PNG\r\n\x1a\n" for _, b in previews), "превью — PNG"
    assert [p["part"] for p in site.results[0]["parts"]] == [0, 1], "превью не меняет итог пачки"


def test_parts_one_broken_type_does_not_kill_the_batch(monkeypatch):
    site = _parts_site(parts=[0, 1])
    _patch_site(monkeypatch, site)

    def flaky(code, timeout):
        return (stl_bytes(1), "") if "0" in code else (None, "openscad: ERROR: CGAL failed")

    monkeypatch.setattr(mod, "_stl_of", flaky)

    out = asyncio.run(mod.handle_build_parts(_Ctx(), {"build_id": 7}))
    rows = site.results[0]["parts"]

    assert out["ok"] is False and out["done"] == 1 and out["failed"] == 1
    assert rows[0]["ok"] is True and rows[1]["ok"] is False
    assert "CGAL" in rows[1]["error"], "текст ошибки узла доезжает до сайта"
    assert site.results[0]["error"].startswith("openscad:"), "и в общий итог тоже"


def test_parts_empty_plan_renders_nothing(monkeypatch):
    """Сайт сказал «недостающих нет» — не рендерим и не делаем вид, что успели."""
    site = _Site({}, plan={"ok": False, "error": "Недостающих узлов нет", "parts": []})
    _patch_site(monkeypatch, site)
    calls = []
    monkeypatch.setattr(mod, "_stl_of", lambda *a: calls.append(1) or (None, "не должен вызываться"))

    out = asyncio.run(mod.handle_build(_Ctx(), {"build_id": 7, "kind": "parts"}))

    assert out["ok"] is False
    assert calls == []
    assert site.results[0]["parts"] == []

