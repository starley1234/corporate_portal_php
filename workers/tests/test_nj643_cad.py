"""Модуль «CAD-детали» (nj643) обязан быть рабочим сквозным сервисом.

Владелец (26.09.2026): «Сделай хороший сервис для генерации 3D с помощью LLM и
/v1/cad/verify-and-build. Постарайся сделать его рабочим, качественным. Далее
будем улучшать функционал».

Что проверяем (то, что ломается молча и дорого):
  * код модели перед отправкой в движок действительно чистится — движок его
    ИСПОЛНЯЕТ, значит `import os`/`eval`/`open` уходить не должны;
  * разбор ответа движка терпит любую форму: base64-поля, вложенные списки,
    ссылки, разные имена габаритов и стресс-теста;
  * отказ движка (401 — не тот ключ, 422 — плохой код, сеть) даёт понятную
    причину, а не «пусто»;
  * неудача сборки доходит до сайта как ошибка (финал обязателен);
  * расход LLM уезжает на сайт вместе с итогом.
"""
import asyncio
import base64
import json
import re

import pytest

import n2j_workers.handlers.nj643_cad as mod


class _Ctx:
    """Контекст задачи: LLM отвечает заготовкой, сайт и движок — подменяются."""

    def __init__(self, reply="{}"):
        self.steps = []
        self.reply = reply
        self.llm = self
        self.source = "тест"

    async def progress(self, stage=None, note=None):
        self.steps.append((stage, note))

    async def heartbeat(self):
        return True

    async def chat_with_usage(self, messages, **kw):
        # Живые грабли 27.09.2026: с max_tokens=9000 модель тратила лимит на
        # рассуждения, а код приезжал обрезанным («JSON не найден целиком»).
        # Владелец: «Лимит max_tokens в этом модуле применять нельзя» — это
        # проверяет test_llm_does_not_send_max_tokens.
        self.last_options = kw

        return self.reply, {"prompt_tokens": 120, "completion_tokens": 40,
                            "_llm_model": "test/model"}


GOOD_CODE = """from build123d import *

def build_model(**kwargs):
    w = float(kwargs.get("width", 60))
    return Box(w, w, 10)
"""

GOOD_REPLY = json.dumps({
    "title": "Куб",
    "summary": "Простая деталь",
    "code": GOOD_CODE,
    "schema_definition": {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}},
    "parameters": {"width": 60},
})


# ---------------------------------------------------------------------------
# Код модели: чистка и запреты
# ---------------------------------------------------------------------------

def test_clean_code_removes_markdown_fence():
    code, problem = mod.check_code("```python\n" + GOOD_CODE + "\n```")

    assert problem == ""
    assert code.startswith("from build123d import *")
    assert "```" not in code


def test_code_without_build_model_is_rejected():
    _code, problem = mod.check_code("import math\n\ndef make():\n    return 1\n")

    assert "build_model" in problem


@pytest.mark.parametrize("bad", [
    "import os\n" + GOOD_CODE,
    "from subprocess import run\n" + GOOD_CODE,
    GOOD_CODE + "\nopen('/etc/passwd').read()\n",
    GOOD_CODE + "\neval('1+1')\n",
    GOOD_CODE + "\n__import__('os').system('id')\n",
    GOOD_CODE + "\nos.system('id')\n",
])
def test_dangerous_code_never_reaches_engine(bad):
    _code, problem = mod.check_code(bad)

    assert problem, "опасный код обязан быть отклонён"


def test_allowed_stdlib_is_ok():
    code = ("import math\nfrom math import pi\nfrom build123d import *\n\n"
            "def build_model(**kwargs):\n    return Box(pi, math.tau, 1)\n")
    _code, problem = mod.check_code(code)

    assert problem == ""


# ---------------------------------------------------------------------------
# Разбор ответа движка
# ---------------------------------------------------------------------------

def _b64(raw: bytes) -> str:
    return base64.b64encode(raw).decode()


STEP = b"ISO-10303-21;\nHEADER;\nENDSEC;\nDATA;\nENDSEC;\nEND-ISO-10303-21;\n"
GLB = b"glTF\x02\x00\x00\x00" + b"\x00" * 40
PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64


def test_artifacts_found_in_flat_base64_fields():
    data = {
        "files": {
            "step": _b64(STEP),
            "stl": _b64(b"solid part\nfacet normal 0 0 1\nendsolid part\n"),
        },
        "views": [_b64(PNG)],
    }

    kinds = {a.kind for a in mod.collect_artifacts(data)}

    assert {"step", "stl", "png"} <= kinds


def test_artifacts_found_in_nested_lists_and_urls():
    data = {
        "result": {
            "artifacts": [
                {"kind": "step", "content": _b64(STEP)},
                {"kind": "glb", "data": _b64(GLB)},
            ],
        },
        "renders": {"view_1": _b64(PNG)},
    }

    arts = mod.collect_artifacts(data)

    assert {a.kind for a in arts} == {"step", "glb", "png"}
    # имя ракурса сохраняется — по нему на странице подписываются картинки
    assert any(a.view for a in arts if a.kind == "png")


def test_duplicates_are_dropped():
    data = {"step": _b64(STEP), "step_copy": _b64(STEP)}

    assert len([a for a in mod.collect_artifacts(data) if a.kind == "step"]) == 1


def test_kind_guessed_from_magic_bytes_when_names_are_useless():
    data = {"blob1": _b64(PNG), "blob2": _b64(STEP)}

    kinds = sorted(a.kind for a in mod.collect_artifacts(data))

    assert kinds == ["png", "step"]


def test_binary_stl_is_recognized_without_name():
    """Бинарный STL (без слова solid) должен опознаваться по структуре файла."""
    import struct

    triangles = b"\x00" * (50 * 2)
    binary_stl = b"binary stl from engine".ljust(80, b"\x00") + struct.pack("<I", 2) + triangles

    arts = mod.collect_artifacts({"blob": _b64(binary_stl)})

    assert [a.kind for a in arts] == ["stl"]


def test_report_understands_alternate_engine_field_names():
    data = {
        "engine": {"name": "Headless CAD", "version": "1.0.0"},
        "geometry": {"bbox": {"x": 60, "y": 40, "z": 12}, "volume": 28800.5,
                     "num_solids": 1, "faces": 6},
        "stress_test": {"ok": False, "results": [
            {"params": {"width": 20}, "ok": True},
            {"params": {"width": 200}, "ok": False, "error": "empty solid"},
        ]},
        "warnings": ["fillet skipped"],
    }

    report = mod.parse_report(data)

    assert report["geometry"]["bbox"] == [60.0, 40.0, 12.0]
    assert report["geometry"]["volume"] == 28800.5
    assert report["geometry"]["solids"] == 1
    assert report["stress"]["ok"] is False
    assert report["stress"]["cases"][1]["error"] == "empty solid"
    assert report["warnings"] == ["fillet skipped"]
    assert report["engine"]["version"] == "1.0.0"


def test_report_reads_root_level_dimensions():
    report = mod.parse_report({"volume": 1000, "bbox": [10, 10, 10], "solids": 2})

    assert report["geometry"] == {"bbox": [10.0, 10.0, 10.0], "volume": 1000.0, "solids": 2}


# ---------------------------------------------------------------------------
# Промпт: как писать код, который собирается с первого раза
# ---------------------------------------------------------------------------

def test_system_prompt_teaches_build123d_algebra():
    """Владелец (27.09.2026): «улучши промпт по генерации кода 3D, чтобы модель
    создавал очень хорошую, качественную и код писал без ошибок».

    Ошибки тут типовые и повторяются из детали в деталь: `mode=Mode.SUBTRACT` вне
    builder-контекста, фаска по «съеденной» кромке, `Box` без align по центру
    координат, вычитание мимо тела. Промпт обязан называть эти грабли прямо и
    давать проверенный синтаксис — иначе модель их повторяет.
    """
    text = mod.SYSTEM_CODE

    # режим работы: только алгебра, контексты builder падают
    assert "ТОЛЬКО АЛГЕБРА" in text
    assert "Mode.SUBTRACT" in text
    assert "BuildPart" in text

    # проверенный синтаксис вместо «догадайся сам»
    for needle in ("ШПАРГАЛКА", "Pos(x, y, z) * shape", "align=(Align.CENTER, Align.CENTER, Align.MIN)",
                   "fillet(part.edges().filter_by(Axis.Z), radius=1)",
                   "chamfer(part.faces().sort_by(Axis.Z)[-1].edges(), length=0.5)",
                   "group_by(Axis.Z)", "GeomType.LINE", "extrude(sk, amount=h)",
                   "mirror(shape, about=Plane.YZ)", "part.volume", "len(part.solids())"):
        assert needle in text, needle

    # частые падения названы поимённо
    assert "ЧАСТЫЕ ОШИБКИ" in text
    assert "вычитание мимо тела" in text.lower()

    # косметика не должна валить деталь — но только косметика
    assert "_safe_fillet" in text
    assert "ТОЛЬКО косметику" in text

    # самопроверка перед ответом
    assert "ПЕРЕД ОТВЕТОМ ПРОВЕРЬ СЕБЯ" in text
    assert "синтаксически верен" in text


def test_fix_prompt_explains_the_engine_report():
    """Починка: модель должна понять отчёт, а не угадывать."""
    text = mod.SYSTEM_FIX

    assert "ПРАВЬ МИНИМАЛЬНО" in text
    for needle in ("Ошибка движка", "Где", "Крайние значения", "Геометрия",
                   "объём ≈ 0", "тел больше одного", "filter_by(Axis.Z)"):
        assert needle in text, needle


# ---------------------------------------------------------------------------
# Код: статические проверки ДО движка
# ---------------------------------------------------------------------------

def test_syntax_error_is_caught_before_the_engine():
    """Синтаксис ловим у себя: раньше такой код уезжал в движок и возвращался отказом."""
    assert mod.check_code(GOOD_CODE)[1] == ""              # целый код проходит

    broken = "from build123d import *\n\ndef build_model(**kwargs):\n    return Box(1, 1, 1\n"
    code, problem = mod.check_code(broken)

    assert code == ""
    assert "не разбирается Python" in problem
    assert "строка" in problem


def test_builder_mode_outside_with_is_rejected():
    """`mode=Mode.SUBTRACT` в алгебре — TypeError в движке; говорим сразу и словами."""
    bad = ('from build123d import *\n\n'
           'def build_model(**kwargs):\n'
           '    part = Box(10, 10, 10)\n'
           '    part += Cylinder(2, 10, mode=Mode.SUBTRACT)\n'
           '    return part\n')
    code, problem = mod.check_code(bad)

    assert code == ""
    assert "Mode.SUBTRACT" in problem and "part -= hole" in problem


def test_builder_context_without_with_is_rejected():
    bad = ('from build123d import *\n\n'
           'def build_model(**kwargs):\n'
           '    BuildPart()\n'
           '    return Box(10, 10, 10)\n')
    code, problem = mod.check_code(bad)

    assert code == ""
    assert "builder-контекст" in problem


def test_model_without_return_is_rejected():
    bad = 'from build123d import *\n\ndef build_model(**kwargs):\n    Box(10, 10, 10)\n'
    code, problem = mod.check_code(bad)

    assert code == ""
    assert "return part" in problem


def test_code_running_at_import_is_rejected():
    """Код на уровне модуля выполнится при импорте движком — это недопустимо."""
    bad = ('from build123d import *\n\n'
           'part = Box(10, 10, 10)\n\n'
           'def build_model(**kwargs):\n'
           '    return part\n')
    code, problem = mod.check_code(bad)

    assert code == ""
    assert "на уровне модуля" in problem

    # а константы и вспомогательные функции на уровне модуля — можно
    good = ('from build123d import *\n\n'
            'WALLS = {"m3": 3.4, "m4": 4.5}\n\n\n'
            'def _holes(part, d, n):\n'
            '    for i in range(n):\n'
            '        part -= Pos(i * 10, 0, 0) * Cylinder(d / 2, 20)\n'
            '    return part\n\n\n'
            'def build_model(**kwargs):\n'
            '    part = Box(30, 20, 5)\n'
            '    return _holes(part, WALLS["m4"], 2)\n')
    code, problem = mod.check_code(good)

    assert problem == "", problem


# ---------------------------------------------------------------------------
# Код и схема: расхождения, из-за которых ползунок «не работает»
# ---------------------------------------------------------------------------

def test_code_warnings_find_schema_mismatch():
    schema = {"parameters": {
        "width": {"default": 60, "min": 20, "max": 100},
        "holes_d": {"default": 4.5, "min": 2, "max": 10},
    }}
    code = ('from build123d import *\n\n'
            'def build_model(**kwargs):\n'
            '    w = float(kwargs.get("width", 40))\n'          # дефолт разошёлся со схемой
            '    d = float(kwargs.get("hole_d"))\n'              # имени нет в схеме и нет дефолта
            '    return Box(w, w, 5) - Cylinder(d / 2, 10)\n')
    notes = mod.code_warnings(code, schema)

    joined = " | ".join(notes)
    assert "в коде 40, в схеме 60" in joined              # дефолты разошлись
    assert "holes_d" in joined                            # схема обещает параметр, которого нет
    assert "hole_d" in joined                             # код читает то, чего в схеме нет
    assert "нет значения по умолчанию" in joined and "None" in joined


def test_code_without_parameters_is_flagged():
    code = ('from build123d import *\n\n'
            'def build_model(**kwargs):\n'
            '    return Box(60, 40, 5)\n')
    notes = mod.code_warnings(code, {"parameters": {"width": {"default": 60}}})

    assert any("размеры зашиты числами" in n for n in notes)


def test_schema_defaults_align_to_the_code():
    """Схема — витрина: если дефолт разошёлся, человек крутит ползунок и не понимает."""
    schema = {"parameters": {"width": {"default": 40, "min": 20, "max": 100}}}
    code = ('from build123d import *\n\n'
            'def build_model(**kwargs):\n'
            '    return Box(float(kwargs.get("width", 60)), 10, 10)\n')
    aligned, notes = mod.align_schema_defaults(code, schema)

    assert aligned["parameters"]["width"]["default"] == 60
    assert any("выровнено по коду" in n for n in notes)

    # значение вне границ расширяет границу, а не остаётся снаружи
    schema2 = {"parameters": {"width": {"default": 40, "min": 20, "max": 50}}}
    code2 = ('from build123d import *\n\n'
             'def build_model(**kwargs):\n'
             '    return Box(float(kwargs.get("width", 80)), 10, 10)\n')
    aligned2, notes2 = mod.align_schema_defaults(code2, schema2)

    assert aligned2["parameters"]["width"]["default"] == 80
    assert aligned2["parameters"]["width"]["max"] == 80
    assert any("максимум" in n for n in notes2)

    # пустая схема остаётся пустой — вызывающий код возьмёт схему из задачи
    assert mod.align_schema_defaults(code, {}) == ({}, [])


# ---------------------------------------------------------------------------
# Сообщение на починку: адрес ошибки, крайние значения, запрет упрощать
# ---------------------------------------------------------------------------

def test_fix_message_points_to_the_line_and_extremes():
    code = ("from build123d import *\n\n\n"
            "def build_model(**kwargs):\n"
            "    part = Box(10, 10, 10)\n"
            "    part = fillet(part.edges(), 4)\n"
            "    return part\n")
    report = {
        "geometry": {"bbox": [10.0, 10.0, 10.0], "volume": 1000.0, "solids": 2},
        "stress": {"cases": [{"ok": False, "params": {"width": 20}, "error": "fillet radius too large"}]},
        "warnings": [{"message": "грань почти нулевой площади"}],
    }
    error = "ValueError: fillet radius is too large (line 5)"

    msg = mod._fix_message(code, report, error, ["значение по умолчанию для «width»: в коде 40, в схеме 60"])

    assert "Отчёт движка" in msg
    assert "line 5" in msg and "строка 5" in msg          # адрес ошибки указан
    assert "fillet(part.edges(), 4)" in msg               # и сама строка показана
    assert "крайние значения параметров" in msg and "fillet radius too large" in msg
    assert "тел 2 (нужно одно)" in msg
    assert "замечание к коду" in msg
    assert "Не переписывай деталь целиком" in msg


def test_fix_message_without_line_number_still_useful():
    msg = mod._fix_message("from build123d import *\n", {"geometry": {}}, "движок не вернул файлов модели")

    assert "движок не вернул файлов модели" in msg
    assert "строка" not in msg                            # выдумывать адрес нельзя


def test_engine_rejection_fix_message_reaches_the_model(monkeypatch):
    """Проверяем не только текст: до модели действительно доезжает адрес ошибки."""
    uploads, results = [], []

    fixed_code = "from build123d import *\n\ndef build_model(**kwargs):\n    return Box(10, 10, 10)\n"
    seen = {}

    engine_answers = [
        (False, 422, {"error": "TypeError: Box() takes 3 positional arguments (line 4)"}),
        ENGINE_OK,
    ]
    _patch_site(monkeypatch, uploads, results, engine_answers)

    class _CtxFix(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            if "Отчёт движка" in messages[-1]["content"]:
                seen["fix"] = messages[-1]["content"]
                return json.dumps({"code": fixed_code}), {"prompt_tokens": 5, "completion_tokens": 5}
            return GOOD_REPLY, {"prompt_tokens": 5, "completion_tokens": 5}

    asyncio.run(mod.handle(_CtxFix(), {
        "action": "generate", "part_id": 7, "revision_id": 31, "prompt": "куб", "rev": 1,
    }))

    assert "строка 4" in seen["fix"]                      # модель получила адрес
    assert "Не переписывай деталь целиком" in seen["fix"]


def test_extremes_failure_is_fixed_once_and_not_forever(monkeypatch):
    """Собралось, но крайние значения ломают модель — один раз просим починить.

    Это тот случай, когда деталь вроде готова, а ползунок в край — и ошибка.
    Владелец просил код без ошибок, поэтому пробуем починить сами, но ровно один
    раз: дальше у нас уже есть рабочая деталь, и тратить токены нельзя.
    """
    uploads, results = [], []
    fixed_code = "from build123d import *\n\ndef build_model(**kwargs):\n    return Box(10, 10, 10)\n"

    engine_answers = [
        # первая сборка: файлы есть, но на min параметра модель падает
        (True, 200, {
            "geometry": {"bbox": [60, 60, 10], "volume": 36000, "solids": 1},
            "stress": {"ok": False, "cases": [{"params": {"width": 20}, "ok": False,
                                               "error": "fillet radius too large"}]},
            "files": {"step": _b64(STEP), "stl": _b64(STL_ASCII)},
        }),
        # после правки: всё хорошо
        ENGINE_OK,
    ]
    calls = _patch_site(monkeypatch, uploads, results, engine_answers)

    seen = []

    class _CtxExtremes(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            content = messages[-1]["content"]

            if "Отчёт движка" in content:
                seen.append(content)
                return json.dumps({"code": fixed_code}), {"prompt_tokens": 5, "completion_tokens": 5}

            return GOOD_REPLY, {"prompt_tokens": 5, "completion_tokens": 5}

    out = asyncio.run(mod.handle(_CtxExtremes(), {
        "action": "generate", "part_id": 7, "revision_id": 41, "prompt": "куб", "rev": 1,
    }))

    assert out["ok"]
    assert calls["engine"] == 2                            # ровно одна починка
    assert len(seen) == 1
    assert "min/max" in seen[0]                            # объяснено, что чинить
    assert "fillet radius too large" in seen[0]
    assert results[0]["report"]["attempts"][0]["ok"] is True
    assert results[0]["attempts"] == "2"


def test_failed_fix_keeps_the_previous_good_build(monkeypatch):
    """Правка по крайним значениям развалила модель — отдаём предыдущую удачную сборку.

    Раньше последняя попытка затирала результат: владелец вместо готовой детали
    получал «не собралось», хотя деталь уже была построена и файлы были готовы.
    """
    uploads, results = [], []
    monkeypatch.setenv("NJ643_FIX_ATTEMPTS", "1")          # одна попытка правки — исход проверки однозначен

    engine_answers = [
        (True, 200, {
            "geometry": {"bbox": [60, 60, 10], "volume": 36000, "solids": 1},
            "stress": {"ok": False, "cases": [{"params": {"width": 20}, "ok": False, "error": "сбой"}]},
            "files": {"step": _b64(STEP), "stl": _b64(STL_ASCII)},
        }),
        (False, 422, {"error": "ValueError: fillet radius is too large (line 5)"}),
    ]
    _patch_site(monkeypatch, uploads, results, engine_answers)

    class _CtxBadFix(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            if "Отчёт движка" in messages[-1]["content"]:
                return json.dumps({"code": GOOD_CODE}), {"prompt_tokens": 5, "completion_tokens": 5}

            return GOOD_REPLY, {"prompt_tokens": 5, "completion_tokens": 5}

    out = asyncio.run(mod.handle(_CtxBadFix(), {
        "action": "generate", "part_id": 7, "revision_id": 42, "prompt": "куб", "rev": 1,
    }))

    assert out["ok"]                                       # сборка не потеряна
    assert {u["kind"] for u in uploads} == {"step", "stl"}
    final = results[0]
    assert final["ok"] == "1"
    assert "предыдущая удачная сборка" in final["report"]["kept_previous"]
    assert len(final["report"]["attempts"]) == 2           # обе попытки видны на странице
    assert final["report"]["attempts"][-1]["ok"] is False   # и видно, что правка не удалась


# ---------------------------------------------------------------------------
# Ползунки: заказанные размеры обязаны доехать до движка
# ---------------------------------------------------------------------------

def test_chosen_values_go_to_the_engine_on_rebuild(monkeypatch):
    """Владелец (28.09.2026): «двигаю ползунки, делаю новую ревизию, но вижу
    созданную модель со старыми размерами».

    Причина была в контракте: у `/v1/cad/verify-and-build` нет поля «значения
    параметров» (проверено по openapi.json: только code, schema_definition,
    run_stress_test, exports, linear_deflection). Движок собирает на default из
    схемы, а выбранные значения воркер вообще не передавал — ползунки меняли
    подписи, а не геометрию. Теперь значение уходит и в схему, и в kwargs.get().
    """
    uploads, results = [], []
    calls = _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    class _CtxRebuild(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            raise AssertionError("пересборка по параметрам не должна звать ИИ")

    out = asyncio.run(mod.handle(_CtxRebuild(), {
        "action": "rebuild", "part_id": 7, "revision_id": 71, "code": "cad0000001",
        "prompt": "куб", "rev": 2,
        "code_py": GOOD_CODE,
        "schema_definition": {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}},
        "params": {"width": 100},
    }))

    assert out["ok"]

    payload = calls["payloads"][0]

    # движок получил заказанный размер обоими путями
    assert payload["schema_definition"]["parameters"]["width"]["default"] == 100
    assert 'kwargs.get("width", 100)' in payload["code"]

    # а схема детали на сайте осталась исходной: «вернуть размеры ИИ» работает
    assert results[0]["schema_definition"]["parameters"]["width"]["default"] == 60
    assert results[0]["params"]["width"] == 100


def test_code_with_values_rewrites_only_kwargs_defaults():
    """Правка кода точечная: трогаем только значения по умолчанию kwargs.get."""
    code = ('from build123d import *\n\n'
            'HEIGHT = 60\n\n'
            'def build_model(**kwargs):\n'
            '    w = float(kwargs.get("width", 60))\n'
            '    d = float(kwargs.get("depth", w / 2))\n'
            '    h = 60\n'
            '    return Box(w, d, h)\n')

    out = mod.code_with_values(code, {"width": 120.5, "depth": 40})

    assert 'kwargs.get("width", 120.5)' in out
    assert 'kwargs.get("depth", 40)' in out
    assert "HEIGHT = 60" in out and "h = 60" in out        # константы не тронуты
    assert mod.code_with_values(code, {}) == code
    assert mod.code_with_values(code, {"нет_такого": 5}) == code


def test_schema_with_values_keeps_bounds_and_does_not_touch_the_part_schema():
    schema = {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}}

    out = mod.schema_with_values(schema, {"width": 100})

    assert out["parameters"]["width"]["default"] == 100
    assert out["parameters"]["width"]["min"] == 20        # границы не теряются
    assert schema["parameters"]["width"]["default"] == 60  # исходник не изменён

    # пустая схема и нечисловые значения не ломают запрос
    assert mod.schema_with_values({}, {"width": 1}) == {}
    assert mod.schema_with_values(schema, {"width": "широко"})["parameters"]["width"]["default"] == 60


def test_values_note_shows_what_is_being_built():
    assert mod._values_note({"width": 100, "hole_d": 4.5}) == "width = 100, hole_d = 4.5"
    assert mod._values_note({}) == ""


# ---------------------------------------------------------------------------
# Промпт: только проверенные утверждения, пример обязан собираться
# ---------------------------------------------------------------------------

def test_prompt_speaks_from_verified_engine_behaviour():
    """Промпт не должен врать про движок: враньё стоит круга LLM и вызова движка.

    Всё, что здесь проверяется, я проверил на живом build123d (0.13):
      * `mode=Mode.SUBTRACT` в алгебре НЕ падает — он молча игнорируется, и вырез
        не появляется (раньше промпт обещал TypeError);
      * `Hole(3)` вне builder-контекста падает с «No depth provided»;
      * скругление R2 на стенке 2 мм падает с «Failed creating a fillet with radius of 2»;
      * фаска с большой длиной — «Failed creating a chamfer, try a smaller length value(s)»;
      * `revolve` с профилем в XY-плоскости отдаёт ПУСТОЕ тело без ошибки (объём 0),
        правильный профиль — `Plane.XZ * Pos(r, 0) * Rectangle(...)`;
      * `max_fillet`, на которую ссылается текст ошибки build123d, в модуле нет.
    """
    text = mod.SYSTEM_CODE

    assert "молча игнорируется" in text           # про Mode в алгебре — правда
    assert "No depth provided" in text
    assert "Failed creating a fillet" in text
    assert "Failed creating a chamfer" in text
    assert "BRep_API" in text
    assert "Plane.XZ" in text                     # правильный профиль для revolve
    assert "max_fillet" in text and "нет" in text.split("max_fillet", 1)[1][:60]
    assert "ТИХИЕ ОШИБКИ" in text                 # пустое тело, вычитание мимо тела
    assert "100 мм" in text                       # размеры из описания — требование
    # Зенковка: наивный конус сверху плиты не вычитает ничего (проверено), поэтому
    # в промпте — только рабочий рецепт и прямое предупреждение о ловушке
    assert "Зенковка" in text and "не вычитает НИЧЕГО" in text
    assert "Cone(hole_d / 2, head_d / 2, 1.5" in text


def _prompt_example_code() -> str:
    """Код эталонного примера — прямо из промпта (не копия рядом)."""
    marker = mod.SYSTEM_CODE.split("ЭТАЛОННЫЙ ПРИМЕР", 1)
    assert len(marker) == 2, "в промпте пропал эталонный пример"

    found = re.search(r"```python\n(.*?)```", marker[1], re.S)
    assert found, "не нашёл код эталонного примера"

    return found.group(1)


def test_prompt_example_really_builds(monkeypatch):
    """Эталонный пример из промпта собирается на живом build123d — все min и max.

    Проверка честная: код вырезается ИЗ промпта (а не лежит рядом копией) и
    исполняется с параметрами по умолчанию и на каждом краю схемы. Если в
    примере появится пустое тело или два тела — тест упадёт здесь, а не в
    сборке у владельца.
    """
    # build123d есть не везде: на сервере воркера его нет (код исполняет движок),
    # а в среде разработки он ставится локально — тогда проверка идёт по-настоящему.
    # Ловим именно ImportError: установленный, но сломанный пакет (нет libGL)
    # тоже должен аккуратно пропустить тест, а не свалить прогон.
    try:
        import build123d  # noqa: F401
    except ImportError as e:  # pragma: no cover — зависит от машины
        pytest.skip(f"локальной сборки build123d нет: {e}")

    ns: dict = {}
    exec(compile(_prompt_example_code(), "<пример из промпта>", "exec"), ns)   # noqa: S102 — это наш код

    schema = {
        "length":    {"default": 100, "min": 60,  "max": 160},
        "width":     {"default": 60,  "min": 40,  "max": 120},
        "thickness": {"default": 8,   "min": 4,   "max": 16},
        "hole_d":    {"default": 4.5, "min": 3,   "max": 9},
        "boss_d":    {"default": 24,  "min": 12,  "max": 40},
    }

    cases = [dict((n, spec["default"]) for n, spec in schema.items())]

    for edge in ("min", "max"):
        for name in schema:
            cases.append(dict((n, (spec[edge] if n == name else spec["default"]))
                              for n, spec in schema.items()))

    for values in cases:
        part = ns["build_model"](**values)
        assert len(part.solids()) == 1, values
        assert part.volume > 0, values

    # габарит примера — тот, что обещан в промпте словами
    box = ns["build_model"](**{n: spec["default"] for n, spec in schema.items()}).bounding_box().size
    assert (round(box.X, 1), round(box.Y, 1), round(box.Z, 1)) == (100.0, 60.0, 14.0)


def test_rebuilt_geometry_follows_the_sliders_on_real_build123d():
    """Самый честный тест на жалобу владельца: собираем то, что уйдёт в движок.

    Берём код модели, применяем к нему подстановку заказанных значений (как это
    делает воркер) и строим деталь локальным build123d — двумя способами, как
    мог бы движок: без аргументов (значения из кода) и с default из схемы.
    Габарит обязан стать 160×120 мм, а не 100×60 из описания.
    """
    try:
        import build123d  # noqa: F401
    except ImportError as e:  # pragma: no cover — зависит от машины
        pytest.skip(f"локальной сборки build123d нет: {e}")

    values = {"length": 160, "width": 120}
    patched = mod.code_with_values(_prompt_example_code(), values)

    ns: dict = {}
    exec(compile(patched, "<сборка по ползункам>", "exec"), ns)            # noqa: S102

    without_args = ns["build_model"]()
    box = without_args.bounding_box().size

    assert (round(box.X, 1), round(box.Y, 1)) == (160.0, 120.0)
    assert len(without_args.solids()) == 1 and without_args.volume > 0

    with_defaults = ns["build_model"](**values)
    assert round(with_defaults.bounding_box().size.X, 1) == 160.0


# ---------------------------------------------------------------------------
# Ошибки движка: конкретные подсказки вместо «попробуй иначе»
# ---------------------------------------------------------------------------

def test_engine_hints_explain_the_real_messages():
    """Подсказки написаны по настоящим текстам ошибок build123d."""
    assert any("радиус" in h for h in mod._engine_hints(
        "ValueError: Failed creating a fillet with radius of 12, try a smaller value"))
    assert any("длину" in h for h in mod._engine_hints(
        "ValueError: Failed creating a chamfer, try a smaller length value(s)"))
    assert any("Cylinder" in h for h in mod._engine_hints("ValueError: No depth provided"))
    assert any("профиль" in h for h in mod._engine_hints(
        "StdFail_NotDone: BRep_API: command not done"))
    assert any("деление" in h for h in mod._engine_hints("ZeroDivisionError: float division by zero"))

    # незнакомая ошибка не выдумывает советов
    assert mod._engine_hints("что-то своё") == []


def test_fix_message_carries_the_hint():
    """Модель на починке получает не только текст ошибки, но и что с ним делать."""
    msg = mod._fix_message(GOOD_CODE, {}, "ValueError: Failed creating a fillet with radius of 3")

    assert "- подсказка:" in msg
    assert "радиус" in msg


def test_revolve_without_xz_plane_is_warned():
    """revolve с профилем в XY-плоскости даёт пустое тело — предупреждаем модель."""
    bad = ('from build123d import *\n\n'
           'def build_model(**kwargs):\n'
           '    return revolve(Circle(10), Axis.Z)\n')
    warns = mod.code_warnings(bad, {"parameters": {}})

    assert any("revolve" in w and "Plane.XZ" in w for w in warns)

    good = ('from build123d import *\n\n'
            'def build_model(**kwargs):\n'
            '    return revolve(Plane.XZ * Pos(10, 0) * Rectangle(5, 20), Axis.Z)\n')

    assert not any("revolve" in w for w in mod.code_warnings(good, {"parameters": {}}))


# ---------------------------------------------------------------------------
# Размеры из описания: деталь обязана совпасть с тем, что просили
# ---------------------------------------------------------------------------

def test_prompt_sizes_read_only_the_named_dimensions():
    """Габарит берём из описания, а не из головы: «стенка 2 мм» — не габарит."""
    assert mod._prompt_sizes("плита 100 мм, толщина 10 мм") == [100.0]
    assert mod._prompt_sizes("кронштейн 100×60×8 мм") == [100.0, 60.0, 8.0]
    assert mod._prompt_sizes("втулка Ø30, стенка 2 мм, R3") == []
    assert mod._prompt_sizes("") == []


def test_size_mismatch_names_the_difference():
    report = {"geometry": {"bbox": [60, 60, 10], "volume": 36000, "solids": 1}}

    bad = mod._size_mismatch("плита 100 мм", report)

    assert len(bad) == 1
    assert "100 мм" in bad[0] and "60 мм" in bad[0]

    # совпадение в пределах 5 % (движок считает по сетке) — не придираемся
    assert mod._size_mismatch("плита 62 мм", report) == []
    assert mod._size_mismatch("плита 100 мм", {}) == []


def test_mismatched_size_is_fixed_once(monkeypatch):
    """Просили 100 мм, собралось 60 мм — один раз просим привести размеры."""
    uploads, results = [], []

    def engine(payload):
        values = payload.get("params") or {}
        width = float(values.get("width") or 60)

        return (True, 200, {
            "geometry": {"bbox": [width, width, 10], "volume": width * width * 10, "solids": 1},
            "stress": {"ok": True, "cases": [{"params": {"width": 20}, "ok": True}]},
            "files": {"step": _b64(STEP), "stl": _b64(STL_ASCII)},
        }, 1.2)

    _patch_site(monkeypatch, uploads, results, [engine])

    seen = []

    class _CtxSize(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            content = messages[-1]["content"]

            if "Отчёт движка" in content:
                seen.append(content)

            return GOOD_REPLY, {"prompt_tokens": 5, "completion_tokens": 5}

    out = asyncio.run(mod.handle(_CtxSize(), {
        "action": "generate", "part_id": 7, "revision_id": 51,
        "prompt": "плита 100 мм", "rev": 1,
    }))

    assert out["ok"]
    assert len(seen) == 1                                   # ровно одна правка
    assert "не совпал с размерами из описания" in seen[0]
    assert results[0]["report"]["size_notes"], results[0]["report"].get("size_notes")


def test_schema_alignment_reaches_the_page_and_the_engine(monkeypatch):
    """Схема, которую видит человек, должна совпадать с тем, что строит движок."""
    uploads, results = [], []
    calls = _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    reply = json.dumps({
        "code": ('from build123d import *\n\n'
                 'def build_model(**kwargs):\n'
                 '    w = float(kwargs.get("width", 75))\n'
                 '    return Box(w, w, 10)\n'),
        "schema_definition": {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}},
    })

    asyncio.run(mod.handle(_Ctx(reply), {
        "action": "generate", "part_id": 7, "revision_id": 32, "prompt": "куб", "rev": 1,
    }))

    assert calls["payloads"][0]["schema_definition"]["parameters"]["width"]["default"] == 75
    assert results[0]["schema_definition"]["parameters"]["width"]["default"] == 75
    assert any("выровнено по коду" in n for n in results[0]["report"]["schema_notes"])


# ---------------------------------------------------------------------------
# Промпт: модель обязана стараться сделать хорошую конструкцию
# ---------------------------------------------------------------------------

def test_system_prompt_demands_a_good_construction():
    """Владелец (27.09.2026): «в воркер добавь промпт, чтобы модель старалась и
    делала максимально хорошие конструкции».

    Проверяем не буквы, а те требования, без которых «хорошая конструкция»
    превращается в голый куб: крепёж, фаски и скругления, рёбра, толщина стенок,
    технологичность печати, порядок построения и разумные типовые размеры.
    """
    text = mod.SYSTEM_CODE

    assert "КАЧЕСТВО КОНСТРУКЦИИ" in text
    assert "максимально хорошие конструкции" in text       # просьба владельца дословно

    for needle in ("крепёж", "отверстия под винты", "Ø3.4 — M3", "зенковки",
                   "фаска 45", "R0.5-R3", "рёбра жёсткости", "зазоры 0.2-0.4",
                   "стенки не тоньше 1.5 мм", "нависания ≤ 45", "низ детали — на Z = 0",
                   "фаски и скругления в самом конце",
                   "НИЧЕГО ЛИШНЕГО"):
        assert needle.lower() in text.lower(), needle

    # Каркас в промпте — пример, а не готовый ответ
    assert "не копируй его как ответ" in text


def test_fix_prompt_does_not_simplify_the_part():
    """Правка после ошибки не должна выкидывать крепёж и фаски ради «собралось»."""
    text = mod.SYSTEM_FIX

    assert "НЕ УПРОЩАЙ КОНСТРУКЦИЮ" in text
    assert "Правь ПРИЧИНУ" in text


def test_llm_does_not_send_max_tokens():
    """Владелец (27.09.2026): «Лимит max_tokens в этом модуле применять нельзя».

    Без лимита ответ не обрезается: раньше на «починке кода» ответ приходил 787
    знаков из 7996 токенов — рассуждения съедали лимит, JSON не находился целиком.
    """
    ctx = _Ctx(reply="{}")

    asyncio.run(mod._llm(ctx, [{"role": "user", "content": "привет"}], "код модели", []))

    assert "max_tokens" not in ctx.last_options, ctx.last_options

    # и сам вызов без параметра тоже не должен его подставлять
    import inspect

    sig = inspect.signature(mod._llm)

    assert "max_tokens" not in sig.parameters


def test_spend_note_explains_where_the_tokens_went():
    """Владелец (27.09.2026): «напиши почему для генерации такого маленького кода
    потратилось много токенов?». Разбор считает воркер и отдаёт на сайт в note."""
    big = json.dumps({
        "title": "Втулка", "summary": "s", "code": "x = 1\n" * 300,
        "schema_definition": {"parameters": {"a": {"default": 1, "min": 1, "max": 9}}},
        "parameters": {"a": 1}, "notes": ["n"], "checks": ["c"],
    }, ensure_ascii=False)

    note = mod._spend_note(big, {"completion_tokens_details": {"reasoning_tokens": 3700},
                                 "_llm_finish_reason": "stop"})

    assert "код модели" in note and "рассуждения модели 3700 токенов" in note
    assert "финиш stop" in note

    # «Болтовня» вокруг JSON: видно, сколько знаков до и после него.
    chatty = 'Думаю... вот код:\n```json\n{"code":"Box(1,1,1)"}\n```\nГотово!'
    note = mod._spend_note(chatty, {"_llm_finish_reason": "length"})

    assert "до него 26" in note and "после JSON 12" in note
    assert "ОБРЕЗАН" in note

    # Пустой ответ не должен ронять разбор.
    assert mod._spend_note("", {}) == "ответ 0 знаков; JSON не найден целиком"


def test_llm_sends_the_spend_note_to_the_site(monkeypatch):
    """note уезжает на сайт вместе с расходом — иначе владелец не увидит разбор."""
    ctx = _Ctx(reply=json.dumps({"code": "Box(1,1,1)"}))
    sink = []

    asyncio.run(mod._llm(ctx, [{"role": "user", "content": "привет"}], "код модели", sink))

    assert sink and sink[-1]["phase"] == "код модели"
    assert "ответ" in sink[-1]["note"] and "знаков" in sink[-1]["note"]


def test_user_prompt_asks_for_the_best_construction():
    text = mod._user_prompt("кронштейн 120x40")

    assert "кронштейн 120x40" in text
    assert "максимально хорошая конструкция" in text
    assert "крепёж" in text and "фаски" in text


# ---------------------------------------------------------------------------
# Два прогона: сначала код, потом метаинформация по коду
# ---------------------------------------------------------------------------

META_REPLY = json.dumps({
    "title": "Куб",
    "summary": "Простая деталь",
    "schema_definition": {"parameters": {
        "width": {"default": 60, "min": 20, "max": 100, "unit": "мм", "label": "Ширина"}}},
    "notes": ["учтены фаски"],
    "checks": ["проверить посадку"],
}, ensure_ascii=False)


def _generate_payload(rev_id: int = 61) -> dict:
    return {"action": "generate", "part_id": 7, "revision_id": rev_id,
            "prompt": "куб 60 мм", "rev": 1}


def test_code_and_meta_are_two_passes_with_their_own_models(monkeypatch):
    """Владелец (28.09.2026): «давай делать код одним прогоном (чтобы качественнее
    ответ был), а потом на основании кода метаинформацию и т.п. следующим прогоном
    (предусмотри для метаинформации и т.п. другую модель (если не будет указана,
    то основная для модуля))».

    Проверяем весь смысл затеи: первый прогон просит ТОЛЬКО код, второй считает
    метаинформацию по уже готовому коду и идёт на своей модели.
    """
    uploads, results = [], []
    calls = _patch_site(monkeypatch, uploads, results, [ENGINE_OK])
    monkeypatch.setenv("NJ643_MODEL", "основная::модель")
    monkeypatch.setenv("NJ643_META_MODEL", "мета::модель")

    seen = []

    class _CtxTwo(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            seen.append({"system": messages[0]["content"], "user": messages[-1]["content"], "kw": kw})

            if messages[0]["content"] == mod.SYSTEM_CODE:
                return json.dumps({"code": GOOD_CODE}), {"prompt_tokens": 10, "completion_tokens": 20}

            return META_REPLY, {"prompt_tokens": 5, "completion_tokens": 5}

    out = asyncio.run(mod.handle(_CtxTwo(), _generate_payload()))

    assert out["ok"]
    assert len(seen) == 2

    # первый прогон: код, и ничего кроме кода — ни схемы, ни названия
    assert seen[0]["system"] == mod.SYSTEM_CODE
    assert "schema_definition" not in seen[0]["user"]

    # второй прогон: метаинформация по ГОТОВОМУ коду (он передан целиком)
    assert seen[1]["system"] == mod.SYSTEM_META
    assert "kwargs.get" in seen[1]["user"] and "Box(w, w, 10)" in seen[1]["user"]

    # у каждого прогона своя модель; лимит max_tokens по-прежнему не передаём
    assert seen[0]["kw"]["model"] == "основная::модель"
    assert seen[1]["kw"]["model"] == "мета::модель"
    assert all("max_tokens" not in item["kw"] for item in seen)

    # схема из мета-прохода доехала и до движка, и до страницы
    assert calls["payloads"][0]["schema_definition"]["parameters"]["width"]["max"] == 100
    assert results[0]["schema_definition"]["parameters"]["width"]["label"] == "Ширина"
    assert results[0]["report"]["title"] == "Куб"
    assert results[0]["report"]["summary"] == "Простая деталь"
    assert results[0]["report"]["notes"] == ["учтены фаски"]

    # расход: два прохода, у каждого своя фаза — видно, куда ушли токены
    assert [u["phase"] for u in results[0]["usage"]] == ["код модели", "мета: схема и описание"]


def test_meta_model_falls_back_to_the_module_model(monkeypatch):
    """Мета-модель не задана — работает основная модель модуля (просьба владельца)."""
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results, [ENGINE_OK])
    monkeypatch.delenv("NJ643_META_MODEL", raising=False)
    monkeypatch.setenv("NJ643_MODEL", "основная::модель")

    models = []

    class _CtxFallback(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            models.append(kw.get("model"))

            if messages[0]["content"] == mod.SYSTEM_CODE:
                return json.dumps({"code": GOOD_CODE}), {"prompt_tokens": 1, "completion_tokens": 1}

            return META_REPLY, {"prompt_tokens": 1, "completion_tokens": 1}

    asyncio.run(mod.handle(_CtxFallback(), _generate_payload(62)))

    assert models == ["основная::модель", "основная::модель"]
    assert mod._meta_model() == "основная::модель"


def test_meta_failure_keeps_the_build_and_builds_schema_from_code(monkeypatch):
    """Мета-проход не ответил — деталь всё равно собрана, а ползунки берём из кода."""
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    class _CtxNoMeta(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            if messages[0]["content"] == mod.SYSTEM_CODE:
                return json.dumps({"code": GOOD_CODE}), {"prompt_tokens": 1, "completion_tokens": 1}

            return "Извини, JSON не получился — вот объяснение словами.", {"prompt_tokens": 1, "completion_tokens": 1}

    out = asyncio.run(mod.handle(_CtxNoMeta(), _generate_payload(63)))

    assert out["ok"]                                            # готовый код не потерян
    assert results[0]["schema_definition"]["parameters"]["width"]["default"] == 60
    assert any("схема собрана из кода" in n for n in results[0]["report"]["schema_notes"])
    assert not results[0]["report"].get("title")                 # ничего не выдумываем


def test_meta_pass_error_is_swallowed(monkeypatch):
    """Мета-модель упала (таймаут, 500) — это не повод терять собранную деталь."""
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    class _CtxBoom(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            if messages[0]["content"] == mod.SYSTEM_CODE:
                return json.dumps({"code": GOOD_CODE}), {"prompt_tokens": 1, "completion_tokens": 1}

            raise RuntimeError("провайдер мета-модели упал")

    out = asyncio.run(mod.handle(_CtxBoom(), _generate_payload(64)))

    assert out["ok"]
    assert results[0]["schema_definition"]["parameters"]["width"]["default"] == 60


def test_meta_can_be_switched_off(monkeypatch):
    """NJ643_META=0 — экономный режим: схему собираем из кода, второй прогон не нужен."""
    monkeypatch.setenv("NJ643_META", "0")
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    asked = []

    class _CtxOff(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            asked.append(messages[0]["content"])

            return json.dumps({"code": GOOD_CODE}), {"prompt_tokens": 1, "completion_tokens": 1}

    out = asyncio.run(mod.handle(_CtxOff(), _generate_payload(65)))

    assert out["ok"] and asked == [mod.SYSTEM_CODE]
    assert results[0]["schema_definition"]["parameters"]["width"]["default"] == 60


def test_code_pass_asks_only_for_code_and_meta_pass_for_schema():
    """Промпты разведены: схему просит только мета-проход, и только по коду."""
    assert '"code"' in mod.SYSTEM_CODE
    assert "schema_definition" not in mod.SYSTEM_CODE       # в первом прогоне схемы нет
    assert "schema_definition" in mod.SYSTEM_META
    assert 'kwargs.get("имя", значение)' in mod.SYSTEM_META  # параметры берутся из кода
    assert "min" in mod.SYSTEM_META and "max" in mod.SYSTEM_META
    assert "Кода в ответе быть не должно" in mod.SYSTEM_META  # мета не переписывает код


def test_schema_gets_parameters_the_meta_model_forgot():
    """Мета-модель забыла параметр — он всё равно появится ползунком."""
    code = ("from build123d import *\n\n"
            "def build_model(**kwargs):\n"
            "    w = float(kwargs.get(\"width\", 60))\n"
            "    d = float(kwargs.get(\"depth\", 30))\n"
            "    return Box(w, d, 10)\n")

    schema, notes, _w = mod._align_schema_and_notes(
        code, {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}})

    assert set(schema["parameters"]) == {"width", "depth"}
    assert schema["parameters"]["depth"]["default"] == 30
    assert any("добавлен параметр «depth»" in n for n in notes)


def test_schema_from_code_takes_only_numeric_defaults():
    """Схема из кода: только параметры, чьё значение по умолчанию — число.

    Значение-выражение (`w / 2`) или отсутствие значения ползунком не выставить:
    такие параметры оставляем коду, а не показываем нулём на странице.
    """
    code = ("def build_model(**kwargs):\n"
            "    w = float(kwargs.get(\"width\", 60))\n"
            "    d = float(kwargs.get(\"depth\", w / 2))\n"
            "    h = float(kwargs.get(\"height\"))\n"
            "    return Box(w, d, h or 5)\n")

    assert mod.schema_from_code(code) == {"parameters": {"width": {"default": 60}}}
    assert mod.schema_from_code("def build_model(**kwargs):\n    return Box(1, 1, 1)\n") == {}


def test_revise_collects_meta_on_the_new_code(monkeypatch):
    """Правка словами: код правит первая модель, метаинформацию обновляет вторая."""
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    seen = []

    class _CtxRevise(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            seen.append(messages[0]["content"])

            if messages[0]["content"] == mod.SYSTEM_META:
                return META_REPLY, {"prompt_tokens": 1, "completion_tokens": 1}

            return json.dumps({"code": GOOD_CODE, "notes": ["стало шире"]}), {"prompt_tokens": 1,
                                                                             "completion_tokens": 1}

    out = asyncio.run(mod.handle(_CtxRevise(), {
        "action": "revise", "part_id": 7, "revision_id": 66, "code": "cad0000001", "rev": 2,
        "code_py": GOOD_CODE, "instruction": "сделай шире",
        "schema_definition": {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}},
    }))

    assert out["ok"]
    assert seen == [mod.SYSTEM_FIX, mod.SYSTEM_META]
    assert results[0]["report"]["title"] == "Куб"
    # замечания правки и факты из мета-прохода сохраняются вместе, не перетирая друг друга
    assert results[0]["report"]["notes"] == ["учтены фаски", "стало шире"]
    assert results[0]["schema_definition"]["parameters"]["width"]["label"] == "Ширина"


def test_fix_that_adds_a_parameter_refreshes_the_schema(monkeypatch):
    """Правка после ошибки завела параметр — без мета-прохода ползунка для него не было бы."""
    uploads, results = [], []
    fixed = ("from build123d import *\n\n"
             "def build_model(**kwargs):\n"
             "    w = float(kwargs.get(\"width\", 60))\n"
             "    rim = float(kwargs.get(\"rim\", 4))\n"
             "    part = Box(w, w, 10)\n"
             "    return part - Box(w - 2 * rim, w - 2 * rim, 20)\n")

    engine_answers = [
        (False, 422, {"error": "ValueError: Box() bad (line 5)"}),
        ENGINE_OK,
    ]
    _patch_site(monkeypatch, uploads, results, engine_answers)

    seen = []
    meta_calls = []

    class _CtxNewParam(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            system = messages[0]["content"]
            seen.append(system)

            if system == mod.SYSTEM_META:
                meta_calls.append(messages[-1]["content"])

                # до правки в схеме только width; после правки появляется rim
                params = {"width": {"default": 60, "min": 20, "max": 100}}

                if len(meta_calls) > 1:
                    params["rim"] = {"default": 4, "min": 2, "max": 10}

                return json.dumps({"schema_definition": {"parameters": params}}), {"prompt_tokens": 1,
                                                                                   "completion_tokens": 1}
            if system == mod.SYSTEM_FIX:
                return json.dumps({"code": fixed}), {"prompt_tokens": 1, "completion_tokens": 1}

            return GOOD_REPLY, {"prompt_tokens": 1, "completion_tokens": 1}

    out = asyncio.run(mod.handle(_CtxNewParam(), _generate_payload(67)))

    assert out["ok"]
    assert seen == [mod.SYSTEM_CODE, mod.SYSTEM_META, mod.SYSTEM_FIX, mod.SYSTEM_META]
    assert "rim" in meta_calls[1]                             # второй мета-проход видит новый код
    assert results[0]["schema_definition"]["parameters"]["rim"]["default"] == 4


def test_meta_settings_are_documented():
    """Новые настройки обязаны быть в шапке модуля: их видно при разборе сборки."""
    doc = mod.__doc__ or ""

    assert "NJ643_META_MODEL" in doc
    assert "NJ643_META " in doc


# ---------------------------------------------------------------------------
# Причины отказа движка
# ---------------------------------------------------------------------------

def test_engine_401_names_the_env_variable():
    text = mod.engine_error({"detail": "invalid api key"}, 401, "")

    assert "NJ643_CAD_API_KEY" in text
    assert "invalid api key" in text


def test_engine_422_keeps_engine_text():
    text = mod.engine_error({"error": "SyntaxError: line 4"}, 422, "")

    assert "SyntaxError" in text


def test_engine_unreachable_reports_network_reason_as_is():
    """HTTP 0 — это не ответ движка, а сетевая беда: текст причины не портим."""
    text = mod.engine_error(mod.EngineUnreachable(
        "Движок недоступен: ConnectError: EOF"), 0,
        "Движок недоступен: ConnectError: EOF")

    assert "HTTP 0" not in text
    assert "ConnectError" in text
    assert text.startswith("Движок недоступен")


def test_engine_url_has_no_default_and_hides_address(monkeypatch):
    """Адрес движка берём только из окружения и наружу не отдаём.

    Владелец (27.09.2026): «не пиши нигде url движка (в воркере тоже не
    передавай)» — в коде нет адреса по умолчанию, а тексты ошибок говорят про
    имя переменной, а не про адрес.
    """
    monkeypatch.delenv("NJ643_CAD_API", raising=False)

    assert mod._engine_url() == ""
    assert "http" not in mod._net_hint("ConnectError: EOF")
    assert "http" not in mod._net_hint("gaierror: name or service not known")
    assert "NJ643_CAD_API" in mod._net_hint("gaierror: name or service not known")


# ---------------------------------------------------------------------------
# Схема параметров и значения по умолчанию
# ---------------------------------------------------------------------------

def test_schema_forms_are_normalized():
    flat = {"width": {"default": 10, "min": 1, "max": 20}}

    assert mod.normalize_schema({"parameters": flat}) == {"parameters": flat}
    assert mod.normalize_schema(flat) == {"parameters": flat}
    assert mod.schema_for_engine(flat) == {"parameters": flat}
    assert mod.params_defaults(flat) == {"width": 10}


# ---------------------------------------------------------------------------
# Конвейер целиком (движок подменён)
# ---------------------------------------------------------------------------

def _patch_site(monkeypatch, uploads, results, engine_answers):
    """Подмена сайта и движка: движок отвечает по очереди из engine_answers."""

    async def fake_api_post(path, data, retries=2):
        if path == "/upload":
            uploads.append(data)
            return True, "", {}
        if path == "/result":
            results.append(data)
            return True, "", {}
        raise AssertionError("неожиданный путь " + path)

    calls = {"engine": 0, "payloads": []}

    async def fake_engine_call(path, payload, timeout=None, method="POST"):
        if path == "/health":
            return True, 200, {"status": "ok", "version": "1.0.0",
                               "auth": {"required": True, "header": "X-API-Key"},
                               "services": {"cad": {"ready": True}}}, 0.4

        assert path == "/v1/cad/verify-and-build"
        calls["engine"] += 1
        calls["payloads"].append(payload)

        answer = engine_answers[min(calls["engine"] - 1, len(engine_answers) - 1)]

        if callable(answer):
            return answer(payload)

        ok, status, data = answer
        return ok, status, data, 1.5

    monkeypatch.setattr(mod, "_api_post", fake_api_post)
    monkeypatch.setattr(mod, "engine_call", fake_engine_call)
    monkeypatch.setattr(mod, "_api_base", lambda: "https://n2j.ru/cad/api")
    monkeypatch.setattr(mod, "_engine_key_set", lambda: True)

    return calls


STL_ASCII = (b"solid part\nfacet normal 0 0 1\n  outer loop\n    vertex 0 0 0\n"
             b"    vertex 1 0 0\n    vertex 0 1 0\n  endloop\nendfacet\nendsolid part\n")

ENGINE_OK = (True, 200, {
    "geometry": {"bbox": [60, 60, 10], "volume": 36000, "solids": 1},
    "stress": {"ok": True, "cases": [{"params": {"width": 20}, "ok": True}]},
    "files": {"step": _b64(STEP), "stl": _b64(STL_ASCII)},
    "views": [_b64(PNG)],
})


def test_full_pipeline_uploads_files_and_reports_result(monkeypatch):
    uploads, results = [], []
    calls = _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    ctx = _Ctx(GOOD_REPLY)
    out = asyncio.run(mod.handle(ctx, {
        "action": "generate", "part_id": 7, "revision_id": 11, "code": "cad0000001",
        "prompt": "куб 60 мм", "params": {}, "rev": 1,
    }))

    assert out["ok"] and out["files"] == 3
    assert calls["engine"] == 1
    assert {u["kind"] for u in uploads} == {"step", "stl", "png"}

    # payload движка собран по контракту
    payload = calls["payloads"][0]
    assert payload["code"].startswith("from build123d import *")
    assert payload["schema_definition"]["parameters"]["width"]["max"] == 100
    assert payload["run_stress_test"] is True
    assert payload["linear_deflection"] == 0.025

    # итог на сайт: успех, код, схема, расход
    final = results[0]
    assert final["ok"] == "1"
    assert final["part_id"] == "7" and final["revision_id"] == "11"
    assert final["usage"][0]["model"] == "test/model"
    assert final["report"]["geometry"]["bbox"] == [60.0, 60.0, 10.0]


def test_engine_rejection_asks_llm_to_fix_and_succeeds(monkeypatch):
    uploads, results = [], []

    fixed_code = "from build123d import *\n\ndef build_model(**kwargs):\n    return Box(10, 10, 10)\n"
    fix_reply = json.dumps({"code": fixed_code, "summary": "исправлено"})

    engine_answers = [
        (False, 422, {"error": "TypeError: Box() takes 3 positional arguments"}),
        ENGINE_OK,
    ]
    calls = _patch_site(monkeypatch, uploads, results, engine_answers)

    class _Ctx2(_Ctx):
        async def chat_with_usage(self, messages, **kw):
            # первый ответ — исходный код, второй — исправленный
            if "Отчёт движка" in messages[-1]["content"]:
                return fix_reply, {"prompt_tokens": 5, "completion_tokens": 5}
            return GOOD_REPLY, {"prompt_tokens": 5, "completion_tokens": 5}

    ctx = _Ctx2()
    out = asyncio.run(mod.handle(ctx, {
        "action": "generate", "part_id": 7, "revision_id": 11, "prompt": "куб", "rev": 1,
    }))

    assert calls["engine"] == 2
    assert out["attempts"] == 2
    assert out["ok"]

    attempts = results[0]["report"]["attempts"]
    assert attempts[0]["fix_prompt"]          # что именно чинили — сохранено для страницы
    assert results[0]["report"]["params"] == {"width": 60}


def test_engine_failure_without_artifacts_is_reported_to_site(monkeypatch):
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results, [(False, 401, {"detail": "invalid key"})])

    monkeypatch.setenv("NJ643_FIX_ATTEMPTS", "0")

    ctx = _Ctx(GOOD_REPLY)

    with pytest.raises(RuntimeError) as e:
        asyncio.run(mod.handle(ctx, {
            "action": "generate", "part_id": 7, "revision_id": 11, "prompt": "куб", "rev": 1,
        }))

    assert "NJ643_CAD_API_KEY" in str(e.value)

    final = results[-1]
    assert final["ok"] == "0"
    assert "NJ643_CAD_API_KEY" in final["error"]


def test_rebuild_sends_existing_code_without_new_llm_call(monkeypatch):
    uploads, results = [], []
    calls = _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    ctx = _Ctx("НЕ ДОЛЖНО БЫТЬ ИСПОЛЬЗОВАНО")

    out = asyncio.run(mod.handle(ctx, {
        "action": "rebuild", "part_id": 7, "revision_id": 12, "code": "cad0000001", "rev": 2,
        "code_py": GOOD_CODE, "params": {"width": 80},
        "schema_definition": {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}},
    }))

    assert out["ok"]
    assert calls["engine"] == 1
    # пересборка идёт с НОВЫМ значением параметра, а не со старым
    assert calls["payloads"][0]["linear_deflection"] == 0.025
    assert results[0]["params"] == {"width": 80}
    assert calls["payloads"][0]["run_stress_test"] is True


def test_revise_uses_instruction_and_keeps_schema(monkeypatch):
    uploads, results = [], []
    calls = _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    revised = json.dumps({"code": GOOD_CODE, "summary": "стало шире"})
    ctx = _Ctx(revised)

    out = asyncio.run(mod.handle(ctx, {
        "action": "revise", "part_id": 7, "revision_id": 13, "code": "cad0000001", "rev": 3,
        "code_py": GOOD_CODE, "instruction": "сделай шире до 90",
        "schema_definition": {"parameters": {"width": {"default": 60, "min": 20, "max": 100}}},
    }))

    assert out["ok"]
    assert calls["payloads"][0]["schema_definition"]["parameters"]["width"]["max"] == 100


def test_check_action_is_a_polite_final(monkeypatch):
    """Старые задачи «проверка движка» не уезжают в сборку детали.

    Кнопку «Проверить движок» владелец велел убрать (27.09.2026), но в очереди
    могли остаться задачи с action=check: они обязаны закончиться внятной
    фразой, а не падением «нет part_id/revision_id».
    """
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results, [ENGINE_OK])

    out = asyncio.run(mod.handle(_Ctx(), {"action": "check"}))

    assert out["ok"] is False
    assert "Проверка движка больше не нужна" in out["error"]
    assert uploads == [] and results == []          # на сайт ничего не пишем


def test_missing_engine_settings_are_a_clear_error(monkeypatch):
    """Адрес и ключ движка живут только в окружении воркера."""
    monkeypatch.delenv("NJ643_CAD_API_KEY", raising=False)
    monkeypatch.delenv("TOOLFORGE_API_KEY", raising=False)
    monkeypatch.setenv("NJ643_CAD_API", "https://engine.invalid")

    ok, status, data, _dt = asyncio.run(mod.engine_call("/v1/cad/verify-and-build", {}))

    assert ok is False and status == 0
    assert "NJ643_CAD_API_KEY" in str(data)


def test_missing_engine_address_is_a_clear_error(monkeypatch):
    monkeypatch.delenv("NJ643_CAD_API", raising=False)
    monkeypatch.setenv("NJ643_CAD_API_KEY", "test-key")

    ok, status, data, _dt = asyncio.run(mod.engine_call("/health", None, method="GET"))

    assert ok is False and status == 0
    assert "NJ643_CAD_API" in str(data)
    assert "http" not in str(data)                  # адреса в тексте нет


# ---------------------------------------------------------------------------
# Движок недоступен: причина, повторы и «найденное сохранится»
#
# Живой случай 26.09.2026: сборка «уголок 40х40» пять минут висела в «Пишу
# модель / Проверяю и собираю», потом упала «Движок ответил HTTP 0: Движок
# недоступен: ConnectErro», а код, который уже написала модель, не сохранился.
# Владелец спросил: «что не так? может из-за сертификата проблема?»
# Адрес движка в текстах не печатаем (владелец, 27.09.2026).
# ---------------------------------------------------------------------------

NET_EOF = ("[SSL: UNEXPECTED_EOF_WHILE_READING] EOF occurred in violation of protocol "
           "(_ssl.c:1006)")


def test_network_failure_explains_itself_and_does_not_blame_the_code(monkeypatch):
    import httpx

    monkeypatch.setenv("NJ643_CAD_API", "https://engine.invalid")
    monkeypatch.setenv("NJ643_CAD_API_KEY", "test-key")
    monkeypatch.setenv("NJ643_CAD_RETRIES", "3")
    monkeypatch.setenv("NJ643_CAD_RETRY_DELAY", "0")

    opened = {"clients": 0}

    class _FakeClient:
        def __init__(self, **kw):
            opened["clients"] += 1

        async def __aenter__(self):
            raise httpx.ConnectError(NET_EOF)

        async def __aexit__(self, *a):
            return False

    monkeypatch.setattr(mod.httpx, "AsyncClient", _FakeClient)

    ok, status, data, _dt = asyncio.run(mod.engine_call("/health", None, method="GET"))

    assert ok is False and status == 0
    assert opened["clients"] == 3                 # сеть повторяем, а не сдаёмся сразу
    assert isinstance(data, mod.EngineUnreachable)
    assert "ConnectError" in data and "EOF" in data          # настоящая причина видна
    assert "белом списке" in data                            # и что с этим делать
    assert "НЕ ошибка кода модели" in data                   # LLM тут ни при чём
    assert "curl -v" in data                                 # команда для ручной проверки
    assert "NJ643_CAD_API" in data                            # куда смотреть — имя переменной
    assert "http" not in data and "toolforge" not in data      # а самого адреса в тексте нет


def test_certificate_problem_gets_its_own_hint(monkeypatch):
    import httpx

    monkeypatch.setenv("NJ643_CAD_API", "https://engine.invalid")
    monkeypatch.setenv("NJ643_CAD_API_KEY", "test-key")
    monkeypatch.setenv("NJ643_CAD_RETRIES", "1")

    class _FakeClient:
        def __init__(self, **kw):
            pass

        async def __aenter__(self):
            raise httpx.ConnectError("SSL: CERTIFICATE_VERIFY_FAILED certificate verify failed: "
                                     "self-signed certificate")

        async def __aexit__(self, *a):
            return False

    monkeypatch.setattr(mod.httpx, "AsyncClient", _FakeClient)

    _ok, _status, data, _dt = asyncio.run(mod.engine_call("/health", None, method="GET"))

    assert "NJ643_CAD_CA_BUNDLE" in str(data)


def test_proxy_and_insecure_settings_reach_the_client(monkeypatch):
    """NJ643_CAD_PROXY / NJ643_CAD_INSECURE доезжают до httpx (это ответ на «нет доступа»)."""
    monkeypatch.setenv("NJ643_CAD_API", "https://engine.invalid")
    monkeypatch.setenv("NJ643_CAD_API_KEY", "test-key")
    monkeypatch.setenv("NJ643_CAD_RETRIES", "1")
    monkeypatch.setenv("NJ643_CAD_PROXY", "http://proxy.local:3128")
    monkeypatch.setenv("NJ643_CAD_INSECURE", "1")

    seen = {}

    class _FakeClient:
        def __init__(self, **kw):
            seen.update(kw)

        async def __aenter__(self):
            raise RuntimeError("stop here")

        async def __aexit__(self, *a):
            return False

    monkeypatch.setattr(mod.httpx, "AsyncClient", _FakeClient)

    asyncio.run(mod.engine_call("/health", None, method="GET"))

    assert seen.get("proxy") == "http://proxy.local:3128"
    assert seen.get("verify") is False


def test_unreachable_engine_keeps_the_model_code(monkeypatch):
    """Движок молчит — код модели всё равно уезжает на сайт (кнопка «Собрать заново»)."""
    uploads, results = [], []
    _patch_site(monkeypatch, uploads, results,
                [(False, 0, mod.EngineUnreachable("Движок недоступен (тест): ConnectError: EOF"))])
    monkeypatch.setenv("NJ643_FIX_ATTEMPTS", "2")
    monkeypatch.setenv("NJ643_PREFLIGHT", "0")

    ctx = _Ctx(GOOD_REPLY)

    with pytest.raises(mod.CadBuildError) as e:
        asyncio.run(mod.handle(ctx, {
            "action": "generate", "part_id": 7, "revision_id": 21, "prompt": "уголок 40х40", "rev": 1,
        }))

    assert is_unreachable_error(e.value)

    final = results[-1]
    assert final["ok"] == "0"
    assert final["code"].strip() == GOOD_CODE.strip()     # работа модели сохранена
    assert final["schema_definition"]["parameters"]["width"]["max"] == 100
    assert "Движок недоступен" in final["error"]


def is_unreachable_error(exc: BaseException) -> bool:
    """Текст финала для недоступного движка (без «HTTP 0» и без сваливания на код)."""
    text = str(exc)

    return "Движок недоступен" in text or "не настроен" in text


def test_unreachable_engine_is_not_retried_through_the_llm(monkeypatch):
    """Три обращения к модели при мёртвом движке — это сожжённые токены и 6 минут ожидания."""
    uploads, results = [], []
    calls = _patch_site(monkeypatch, uploads, results,
                [(False, 0, mod.EngineUnreachable("Движок недоступен (тест): ConnectError: EOF"))])
    monkeypatch.setenv("NJ643_FIX_ATTEMPTS", "2")
    monkeypatch.setenv("NJ643_PREFLIGHT", "0")

    ctx = _Ctx(GOOD_REPLY)

    with pytest.raises(mod.CadBuildError):
        asyncio.run(mod.handle(ctx, {
            "action": "generate", "part_id": 7, "revision_id": 22, "prompt": "уголок", "rev": 1,
        }))

    assert calls["engine"] == 1                       # ни повторов сборки, ни «починки кода»


def test_preflight_stops_before_spending_llm_tokens(monkeypatch):
    """Движок мёртв → узнаём это ДО обращения к модели, а не через шесть минут."""
    uploads, results = [], []

    async def fake_api_post(path, data, retries=2):
        results.append(data)
        return True, "", {}

    async def fake_engine_call(path, payload, timeout=None, method="POST"):
        assert path == "/health"
        return False, 0, mod.EngineUnreachable("Движок недоступен (тест): ConnectError: EOF"), 0.2

    monkeypatch.setattr(mod, "_api_post", fake_api_post)
    monkeypatch.setattr(mod, "engine_call", fake_engine_call)
    monkeypatch.setattr(mod, "_engine_key_set", lambda: True)
    monkeypatch.delenv("NJ643_PREFLIGHT", raising=False)

    ctx = _Ctx(GOOD_REPLY)

    with pytest.raises(mod.CadBuildError):
        asyncio.run(mod.handle(ctx, {
            "action": "generate", "part_id": 7, "revision_id": 23, "prompt": "уголок", "rev": 1,
        }))

    assert not any("Пишу модель" in str(step) for step in ctx.steps)
    assert results[-1]["ok"] == "0"
    assert "Движок недоступен" in results[-1]["error"]
