"""VRML от OpenSCAD → GLB с материалом на каждый цвет (раскраска по типам узлов).

Владелец (13.10.2026): «хотелось бы в браузере увидеть раскраску по типам
узлов, а палочки — своего цвета». `color()` живёт в OpenSCAD ровно до экспорта:
в STL материалов не бывает, поэтому един носитель раскраски — VRML, а страница
читает цвет из файла и никогда не выдумывает его сама.

Отсюда и проверки: разбор групп, точность цвета (glTF ждёт ЛИНЕЙНЫЙ), лимит
групп, отказ на том, что мы не умеем (полигоны, побитый файл), и то, что молча
потерять цвет конвертер не имеет права.
"""
import json
import struct

import pytest

from n2j_workers import glb
from test_nj667_glb import FACES, stl_bytes


def wrl(shapes) -> bytes:
    """VRML как его пишет OpenSCAD: `Shape { appearance … geometry … }`."""
    out = ["#VRML V2.0 utf8"]

    for color, points, index in shapes:
        out.append("Shape {")

        if color is not None:
            out.append("  appearance Appearance { material Material {")
            out.append("    diffuseColor %s } }" % " ".join("%.3f" % c for c in color))

        out.append("  geometry IndexedFaceSet {")
        out.append("    coord Coordinate { point [ "
                   + ", ".join("%.4f %.4f %.4f" % p for p in points) + " ] }")
        out.append("    coordIndex [ "
                   + " , ".join(" ".join(str(i) for i in face) + " -1" for face in index)
                   + " , -1 ]")
        out.append("  }")
        out.append("}")

    return "\n".join(out).encode()


def two_shapes() -> bytes:
    """Два `color()`: квадрат из двух граней и треугольник палочки."""
    return wrl([
        ((0.278, 0.510, 0.706),  # SteelBlue — тип узла
         [(0, 0, 0), (10, 0, 0), (10, 10, 0), (0, 10, 0)],
         [(0, 1, 2), (0, 2, 3)]),
        ((0.851, 0.647, 0.129),  # Goldenrod — палочка
         [(0, 0, 5), (10, 0, 5), (10, 10, 5)],
         [(0, 1, 2)]),
    ])



#: Жёлтая метка «цвета нет»: OpenSCAD пишет её и в материал, и хвостом палитры.
WRL_DEFAULT = (0.97647, 0.843137, 0.172549)


def scad_wrl(shapes, per_vertex: bool = False) -> bytes:
    """VRML ровно в том виде, в котором его пишет OpenSCAD (src/io/export_wrl.cc).

    Один `Shape` на всё изделие, `material/diffuseColor` — константа WRL_DEFAULT,
    а краски разложены по граням самого IndexedFaceSet: палитра
    `color Color { color [ … ] }` плюс `colorIndex` — по индексу на грань, либо
    на вершину (`per_vertex`). Грань без `color()` указывает на хвостовой
    элемент палитры, и это тот же жёлтый.

    shapes: [(color | None, [точки], [грани])] — как в `wrl` выше.
    """
    verts: list = []
    faces: list = []
    palette: list = []
    seen: dict = {}
    color_of: list = []

    def index_of(color):
        if color is None:
            return len(palette)                     # хвостовой «default colour»

        if color not in seen:
            seen[color] = len(palette)
            palette.append(color)

        return seen[color]

    for color, points, index in shapes:
        base = len(verts)
        verts.extend(points)

        for face in index:
            faces.append([base + i for i in face])
            color_of.append(index_of(color))

    lines = ["#VRML V2.0 utf8", "", "Shape {", "appearance Appearance { material Material {",
             "ambientIntensity 0.3",
             "diffuseColor %s" % " ".join("%.6g" % c for c in WRL_DEFAULT),
             "specularColor 0.2 0.2 0.2", "shininess 0.3", "} }", "",
             "geometry IndexedFaceSet {", "", "creaseAngle 0.5", "",
             "coord Coordinate { point ["]

    for pos, vert in enumerate(verts):
        lines.append(" %.6g %.6g %.6g%s"
                     % (vert[0], vert[1], vert[2], "" if pos == len(verts) - 1 else ","))

    lines += ["] }", "", "coordIndex ["]
    lines += [" " + " ".join(str(i) for i in face) + " -1" for face in faces]
    lines += ["]", ""]

    if palette:
        lines += ["colorPerVertex %s" % ("TRUE" if per_vertex else "FALSE"), "",
                  "color Color { color ["]
        lines += [" %.6g %.6g %.6g," % c for c in palette]
        # Числа в комментарии — не вершины и не краски: разбор обязан их не видеть.
        lines.append(" %.6g %.6g %.6g, # default colour 1 2 3" % WRL_DEFAULT)
        lines += ["] }", "", "colorIndex ["]

        if per_vertex:
            lines += [" %d %d %d -1" % (c, c, c) for c in color_of]
        else:
            lines.append(" " + " ".join(str(c) for c in color_of))

        lines += ["]", ""]

    lines += ["}", "", "}"]

    return "\n".join(lines).encode()


def doc_of(glb_bytes: bytes) -> dict:
    return json.loads(glb_bytes[20:20 + struct.unpack_from("<I", glb_bytes, 12)[0]].rstrip(b" "))


# ------------------------------------------------------------------ разбор VRML

def test_wrl_groups_keep_their_colors():
    groups = glb.parse_wrl(two_shapes())

    assert len(groups) == 2
    assert [len(g["tris"]) for g in groups] == [2, 1]
    assert groups[0]["color"] == pytest.approx((0.278, 0.510, 0.706))
    assert groups[1]["color"] == pytest.approx((0.851, 0.647, 0.129))


# ------------------------------------------------- VRML в формате самого OpenSCAD

def test_scad_wrl_one_shape_many_colors():
    """Регрессия вопроса владельца (13.10.2026): «цвета в браузере для модели нет».

    OpenSCAD пишет ОДИН Shape на изделие и красит грани через colorIndex. Пока
    читальщик знал только `material/diffuseColor`, вся сборка склеивалась в одну
    краску — жёлтую метку «цвета нет», — и браузер показывал именно её.
    """
    groups = glb.parse_wrl(scad_wrl([
        ((0.278, 0.510, 0.706), [(0, 0, 0), (10, 0, 0), (0, 10, 0)], [(0, 1, 2)]),
        ((0.851, 0.647, 0.129), [(0, 0, 5), (10, 0, 5), (10, 10, 5)], [(0, 1, 2)]),
        ((0.278, 0.510, 0.706), [(0, 0, 6), (10, 0, 6), (0, 10, 6)], [(0, 1, 2)]),
    ]))

    assert [g["color"] for g in groups] == pytest.approx(
        [(0.278, 0.510, 0.706), (0.851, 0.647, 0.129)]), \
        "два цвета, а не одна желтая метка; порядок — по первому появлению"
    assert [len(g["tris"]) for g in groups] == [2, 1], "грани своего типа не уезжают в чужую группу"
    assert all(g["color"] != WRL_DEFAULT for g in groups), "жёлтый OpenSCAD — не краска изделия"


def test_scad_wrl_default_marker_is_not_a_paint():
    """Метка OpenSCAD «цвета нет» краской изделия быть не должна.

    Жёлтый 0.97647/0.843137/0.172549 экспорт пишет всегда: и в материал всего
    файла, и хвостом палитры для граней вне `color()`. Если принять его за
    краску, несобранная в цвета деталь приедет к человеку жёлтой — и «почему
    всё жёлтое» станет неотличимым от «цвет потерялся».
    """
    groups = glb.parse_wrl(scad_wrl([
        (None, [(0, 0, 0), (10, 0, 0), (0, 10, 0)], [(0, 1, 2)]),
    ]))

    assert len(groups) == 1 and groups[0]["color"] is None

    doc = doc_of(glb.wrl_to_glb(scad_wrl([
        (None, [(0, 0, 0), (10, 0, 0), (0, 10, 0)], [(0, 1, 2)]),
    ])))
    factor = doc["materials"][0]["pbrMetallicRoughness"]["baseColorFactor"]

    assert round(factor[0], 2) != round(WRL_DEFAULT[0], 2), "не жёлтая метка"
    assert glb.PAPER_SRGB == (0.82, 0.72, 0.55), "бумага остаётся бумагой"


def test_scad_wrl_mixed_model_splits_the_default_into_paper():
    """Одна грань крашена, другая нет: две группы, и бесцветная — не жёлтая."""
    groups = glb.parse_wrl(scad_wrl([
        ((0.278, 0.510, 0.706), [(0, 0, 0), (10, 0, 0), (0, 10, 0)], [(0, 1, 2)]),
        (None, [(0, 0, 5), (10, 0, 5), (10, 10, 5)], [(0, 1, 2)]),
    ]))

    assert len(groups) == 2
    assert groups[0]["color"] == pytest.approx((0.278, 0.510, 0.706))
    assert groups[1]["color"] is None


def test_scad_wrl_per_vertex_colors_land_on_faces():
    """colorPerVertex TRUE: краски у вершин — грань получает цвет первой вершины."""
    groups = glb.parse_wrl(scad_wrl([
        ((0.278, 0.510, 0.706), [(0, 0, 0), (10, 0, 0), (0, 10, 0)], [(0, 1, 2)]),
        ((0.851, 0.647, 0.129), [(0, 0, 5), (10, 0, 5), (10, 10, 5)], [(0, 1, 2)]),
    ], per_vertex=True))

    assert len(groups) == 2
    assert [len(g["tris"]) for g in groups] == [1, 1]


def test_scad_wrl_comments_are_not_numbers():
    """«# default colour 1 2 3» — текст, а не координаты: иначе палитра побита."""
    data = scad_wrl([((0.278, 0.510, 0.706), [(0, 0, 0), (1, 0, 0), (0, 1, 0)], [(0, 1, 2)])])

    assert b"# default colour 1 2 3" in data, "тест бессилен, если комментария нет"
    assert len(glb.parse_wrl(data)) == 1


def test_scad_wrl_becomes_glb_with_real_materials():
    """glTF обязан доехать с тремя красками — приёмка сайта считает именно их."""
    data = scad_wrl([
        ((0.278, 0.510, 0.706), [(0, 0, 0), (10, 0, 0), (0, 10, 0)], [(0, 1, 2)]),
        ((0.851, 0.647, 0.129), [(0, 0, 5), (10, 0, 5), (10, 10, 5)], [(0, 1, 2)]),
        ((0.5, 0.5, 0.5), [(0, 0, 7), (10, 0, 7), (0, 10, 7)], [(0, 1, 2)]),
    ])
    doc = doc_of(glb.wrl_to_glb(data))

    assert len(doc["meshes"][0]["primitives"]) == 3
    assert len(doc["materials"]) == 3

    colors = {tuple(round(c, 2) for c in (m["pbrMetallicRoughness"]["baseColorFactor"][:3]))
              for m in doc["materials"]}

    assert len(colors) == 3, "три материала — три разных baseColorFactor, не три копии одного"


def test_wrl_without_color_is_one_group():
    """Модель вовсе без `color()` — одна группа; цвет ей даст PAPER_SRGB ниже."""
    groups = glb.parse_wrl(wrl([(None, [(0, 0, 0), (1, 0, 0), (0, 1, 0)], [(0, 1, 2)])]))

    assert len(groups) == 1 and groups[0]["color"] is None


def test_wrl_drops_empty_shapes_and_rejects_quads():
    """Пустой Shape (бывает от `color()` без тела) — не группа; полигон — не треугольник."""
    one = glb.parse_wrl(wrl([
        (None, [], []),
        ((0.1, 0.2, 0.3), [(0, 0, 0), (1, 0, 0), (0, 1, 0)], [(0, 1, 2)]),
    ]))

    assert len(one) == 1, "пустая группа не должна превращаться в материал"

    quad = wrl([(None, [(0, 0, 0), (1, 0, 0), (1, 1, 0), (0, 1, 0)], [(0, 1, 2, 3)])])

    with pytest.raises(glb.GlbError, match="треугольн"):
        glb.parse_wrl(quad)


def test_wrl_index_out_of_range_and_bad_header():
    with pytest.raises(glb.GlbError, match="за конец списка вершин"):
        glb.parse_wrl(wrl([(None, [(0, 0, 0), (1, 0, 0)], [(0, 1, 7)])]))

    with pytest.raises(glb.GlbError, match="VRML"):
        glb.parse_wrl(b"solid cube\nendsolid cube")

    with pytest.raises(glb.GlbError, match="гран"):
        glb.parse_wrl(b"#VRML V2.0 utf8\n")


def test_wrl_too_many_COLORS_is_refused():
    """Лимит — на число ЦВЕТОВ, а не на число солидов (см. тест ниже)."""
    shapes = [((round(0.10 + i / 100, 3), 0.1, 0.1), [(0, 0, i), (1, 0, i), (0, 1, i)], [(0, 1, 2)])
              for i in range(glb.MAX_GROUPS + 1)]

    assert len({key for key, *_ in shapes}) == glb.MAX_GROUPS + 1, "цвета правда разные"

    with pytest.raises(glb.GlbError, match=str(glb.MAX_GROUPS)):
        glb.parse_wrl(wrl(shapes))


def test_merge_by_color_folds_solids_and_keeps_order():
    """OpenSCAD пишет Shape на каждый солид: 60 форм при двух красках — это два цвета."""
    tris = [((0, 0, 1), ((0, 0, 0), (1, 0, 0), (0, 1, 0)))]
    groups = [{"tris": list(tris), "color": (0.2, 0.3, 0.4) if i % 2 else (0.9, 0.5, 0.1)}
              for i in range(60)]
    merged = glb.merge_by_color(groups)

    assert [g["color"] for g in merged] == [(0.9, 0.5, 0.1), (0.2, 0.3, 0.4)], \
        "порядок — по первому появлению: палитра узлов осталась бы первой"
    assert sum(len(g["tris"]) for g in merged) == 60, "грани не потерялись"
    assert all(len(g["tris"]) == 30 for g in merged)


def test_big_assembly_is_colored_not_grey():
    """Регрессия вопроса владельца (13.10.2026): сборку не видно в цвете.

    31 узел × (корпус + палочка) = 62 солида при 9 красках. Считали группы за
    цвета — и большая сборка падала на лимите, а сайт получал бесцветный GLB
    из STL. Теперь groups = красок, и цвет доезжает до браузера всегда.
    """
    palette = [(0.278, 0.510, 0.706), (0.863, 0.361, 0.322), (0.502, 0.620, 0.447),
               (0.851, 0.647, 0.129), (0.620, 0.451, 0.788), (0.0, 0.5, 0.5),
               (0.984, 0.502, 0.447), (0.439, 0.502, 0.565)]
    sticks = (0.871, 0.722, 0.529)
    shapes = []

    for i in range(31):
        color = palette[i % len(palette)]
        shapes.append((color, [(0, 0, i), (10, 0, i), (10, 10, i), (0, 10, i)], [(0, 1, 2), (0, 2, 3)]))
        shapes.append((sticks, [(0, 0, i), (20, 0, i), (20, 20, i)], [(0, 1, 2)]))

    groups = glb.parse_wrl(wrl(shapes))

    assert len(groups) == len(palette) + 1, "красок девять — примитивов девять"
    assert sum(len(g["tris"]) for g in groups) == 31 * 3

    out = glb.wrl_to_glb(wrl(shapes))
    info = glb.glb_info(out)

    assert info["groups"] == len(palette) + 1
    assert info["triangles"] == 31 * 3, "склейка не съела грани"
    assert len(doc_of(out)["materials"]) == len(palette) + 1


# ------------------------------------------------------------------- GLB-сборка

def test_wrl_becomes_one_mesh_with_a_material_per_color():
    out = glb.wrl_to_glb(two_shapes())
    doc = doc_of(out)
    prims = doc["meshes"][0]["primitives"]

    assert len(doc["meshes"]) == 1, "один меш: страница крутит одну сцену"
    assert len(prims) == 2, "на каждый цвет — свой примитив"
    assert len(doc["materials"]) == 2
    assert [p["material"] for p in prims] == [0, 1]
    assert doc["materials"][0]["name"] == "print"
    assert doc["materials"][1]["name"] == "print-1"
    for prim in prims:
        pos = doc["accessors"][prim["attributes"]["POSITION"]]

        assert pos["type"] == "VEC3" and "min" in pos and "max" in pos, \
            "без габарита страница не поставит камеру"
        assert doc["accessors"][prim["indices"]]["count"] % 3 == 0


def test_glb_color_is_linear_not_srgb():
    """glTF требует ЛИНЕЙНЫЙ цвет: 0.278 как есть = вдвое ярче в браузере."""
    doc = doc_of(glb.wrl_to_glb(two_shapes()))
    factor = doc["materials"][0]["pbrMetallicRoughness"]["baseColorFactor"]

    assert factor[3] == 1.0, "альфа глухая: прозрачная сборка в окне не нужна"
    assert factor[0] == pytest.approx(0.278 ** 2.2, rel=1e-4)
    assert factor[0] < 0.278, "линейное значение меньше sRGB — иначе гамма съехала дважды"
    assert doc["materials"][0]["pbrMetallicRoughness"]["metallicFactor"] == 0.0
    assert doc["materials"][0]["pbrMetallicRoughness"]["roughnessFactor"] == 0.85


def test_glb_info_adds_up_every_primitive():
    """Габарит и счёт — по всем группам: иначе «96×96×140 мм» врёт о щепке."""
    info = glb.glb_info(glb.wrl_to_glb(two_shapes()))

    assert info["groups"] == 2
    assert info["triangles"] == 3
    assert info["vertices"] == 7, "вершины склеены внутри группы, но не через границу цвета"
    assert info["dims"] == [10.0, 10.0, 5.0]
    assert info["min"] == [0.0, 0.0, 0.0] and info["max"] == [10.0, 10.0, 5.0]


def test_groups_to_glb_refuses_nothing_to_draw():
    with pytest.raises(glb.GlbError, match="гран"):
        glb.groups_to_glb([])

    with pytest.raises(glb.GlbError, match="треугольник|ни одной грани"):
        glb.groups_to_glb([{"tris": [((0, 0, 1), ((0, 0, 0), (0, 0, 0), (1, 0, 0)))], "color": None}])

def test_stl_falls_back_to_paper_and_groups_are_counted():
    """STL цвета не имеет: ему даётся «бумага», и glb_info честно говорит про это."""
    out = glb.groups_to_glb([{"tris": glb.parse_stl(stl_bytes(FACES)), "color": None}])
    doc = doc_of(out)
    factor = doc["materials"][0]["pbrMetallicRoughness"]["baseColorFactor"]
    paper = [round(glb._srgb2lin(c), 6) for c in glb.PAPER_SRGB]

    assert factor[:3] == paper
    assert glb.glb_info(out)["groups"] == 1
