"""STL → GLB: серверная модель должна быть видна в браузере одним BufferGeometry.

Владелец (12.10.2026): «рендерить на сервере и показывать всю сборку в браузере
в формате GLB». Значит конвертер обязан:
  * не портить геометрию (число граней, габарит, сами координаты);
  * склеивать вершины — иначе файл в 2,5 раза тяжелее и нормаль «стог»;
  * держать выравнивание glTF (кратность 4), иначе three.js читает мусор;
  * отказываться словами: битый STL/чужой GLB — это error в сборке, а не
    «успех, 0 граней».
"""
import json
import math
import struct

import pytest

from n2j_workers import glb


CUBE = [
    (0, 0, 0), (10, 0, 0), (10, 10, 0), (0, 10, 0),
    (0, 0, 10), (10, 0, 10), (10, 10, 10), (0, 10, 10),
]
FACES = [
    (0, 1, 2), (0, 2, 3),          # низ
    (4, 6, 5), (4, 7, 6),          # верх
    (0, 4, 5), (0, 5, 1),          # -y
    (1, 5, 6), (1, 6, 2),          # +x
    (2, 6, 7), (2, 7, 3),          # +y
    (3, 7, 4), (3, 4, 0),          # -x
]


def stl_bytes(triangles) -> bytes:
    """Бинарный STL из граней ((a, b, c), ...). Нормали — нулём: считаем сами."""
    out = bytearray(b"nj667 test".ljust(80, b" "))
    out += struct.pack("<I", len(triangles))

    for a, b, c in triangles:
        out += struct.pack("<12fH", 0.0, 0.0, 0.0, *CUBE[a], *CUBE[b], *CUBE[c], 0)

    return bytes(out)


def cube_stl() -> bytes:
    return stl_bytes(FACES)


def json_chunk(glb_bytes: bytes) -> dict:
    """Первый chunk — JSON: его и разбираем в тестах как есть."""
    assert glb_bytes[:4] == b"glTF"
    version, total = struct.unpack_from("<2I", glb_bytes, 4)
    assert (version, total) == (2, len(glb_bytes)), "заголовок обязан врать не умеет"

    length, kind = struct.unpack_from("<2I", glb_bytes, 12)
    assert kind == glb._CHUNK_JSON and length % 4 == 0, "JSON-блок кратен 4"

    return json.loads(glb_bytes[20:20 + length].decode())


def test_cube_keeps_geometry_and_merges_vertices():
    doc = json_chunk(glb.stl_to_glb(cube_stl()))
    info = glb.glb_info(glb.stl_to_glb(cube_stl()))

    assert info["triangles"] == 12
    assert info["vertices"] == 8, "у куба 8 углов, а не 36 отдельных вершин"
    assert info["dims"] == [10.0, 10.0, 10.0]
    assert info["min"] == [0.0, 0.0, 0.0] and info["max"] == [10.0, 10.0, 10.0]

    mesh = doc["meshes"][0]["primitives"][0]
    assert sorted(mesh["attributes"]) == ["NORMAL", "POSITION"]
    assert doc["accessors"][mesh["indices"]]["componentType"] == glb._UINT16
    assert doc["buffers"][0]["byteLength"] % 4 == 0
    assert mesh["material"] == 0 and doc["materials"][0]["name"] == "print"


def glb_parts(blob: bytes) -> tuple:
    """(doc, abs_start BIN-блока) — offsets считаем честно, как их читает three.js."""
    doc = json_chunk(blob)
    json_len = struct.unpack_from("<I", blob, 12)[0]
    bin_len = struct.unpack_from("<I", blob, 20 + json_len)[0]
    bin_start = 20 + json_len + 8

    assert struct.unpack_from("<I", blob, 20 + json_len + 4)[0] == glb._CHUNK_BIN
    assert bin_len == doc["buffers"][0]["byteLength"], "буфер и chunk — одно и то же"
    assert 12 + 8 + json_len + 8 + bin_len == len(blob), "хвостов в GLB не бывает"

    return doc, bin_start


def test_positions_match_the_source_triangles():
    """Координаты из BIN-блока — ровно те же числа, что легли в STL.

    Сюда и надо смотреть, если человек скажет «модель съехала»: перестановка
    осей, scale или порядок байт видны именно здесь, а не в глазу.
    """
    blob = glb.stl_to_glb(cube_stl())
    doc, bin_start = glb_parts(blob)
    view = doc["bufferViews"][0]

    got = [tuple(struct.unpack_from("<3f", blob, bin_start + view["byteOffset"] + 12 * i))
           for i in range(doc["accessors"][0]["count"])]

    assert sorted(got) == sorted(tuple(struct.unpack("<f", struct.pack("<f", c))[0] for c in v)
                                for v in CUBE), "8 углов куба, без потерь и без дублей"
    assert doc["bufferViews"][2]["byteOffset"] == view["byteLength"] + \
        doc["bufferViews"][1]["byteLength"], "offset'ы идут плотным списком"


def test_normals_are_unit_and_chunks_cover_the_file():
    blob = glb.stl_to_glb(cube_stl())
    doc, bin_start = glb_parts(blob)
    nrm = doc["bufferViews"][1]

    for i in range(doc["accessors"][1]["count"]):
        x, y, z = struct.unpack_from("<3f", blob, bin_start + nrm["byteOffset"] + 12 * i)
        assert abs(math.sqrt(x * x + y * y + z * z) - 1.0) < 1e-5, "нормаль единичная"

    total = struct.unpack_from("<I", blob, 8)[0]

    assert total == len(blob) and len(blob) % 4 == 0, "выравнивание на месте"


def test_degenerate_faces_are_dropped():
    """Вырожденные грани (повтор вершины, нулевая нормаль) — мусор CSG, не модель."""
    tris = [(0, 1, 2), (0, 0, 1), (5, 6, 5), (4, 5, 6)]
    blob = glb.stl_to_glb(stl_bytes(tris))

    assert glb.glb_info(blob)["triangles"] == 2


def test_index_type_crosses_at_65534():
    assert glb._index_component_type(8) == glb._UINT16
    assert glb._index_component_type(65534) == glb._UINT16
    assert glb._index_component_type(65535) == glb._UINT32, "WebGL резервирует 65535"


def test_glb_melche_stl_na_setke_realnogo_uzla():
    """На сетке размера узла glTF вдвое меньше STL — в этом и смысл подмены.

    На трёх треугольниках GLB проигрывает (перевес — JSON-описание), поэтому
    меряем на цилиндре, похожем на юбку узла: 30 граней вокруг, 40 колец.
    """
    seg, rings, radius, height = 30, 40, 10.0, 5.0
    ring = [(radius * math.cos(2 * math.pi * i / seg), radius * math.sin(2 * math.pi * i / seg))
            for i in range(seg)]
    faces = []

    for j in range(rings):
        z0, z1 = height * j / rings, height * (j + 1) / rings
        for i in range(seg):
            k = (i + 1) % seg
            a, b = (ring[i][0], ring[i][1], z0), (ring[k][0], ring[k][1], z0)
            c, d = (ring[i][0], ring[i][1], z1), (ring[k][0], ring[k][1], z1)
            faces += [(a, b, d), (a, d, c)]

    body = b"".join(struct.pack("<12fH", 0.0, 0.0, 0.0, *[c for p in tri for c in p], 0)
                    for tri in faces)
    stl = b"ring".ljust(80, b" ") + struct.pack("<I", len(faces)) + body

    blob = glb.stl_to_glb(stl, "yubka")

    assert len(faces) > 2000
    assert len(blob) * 2 < len(stl), "вдвое меньше — иначе подмена не нужна"
    assert glb.glb_info(blob)["vertices"] == seg * (rings + 1), "швы колец склеены"


def test_broken_stl_says_so_in_words():
    """Битый вход — предложение человеку, а не трейсбек и не «0 граней, ок»."""
    empty = b"nj667".ljust(80, b" ") + struct.pack("<I", 0)

    for raw, key in [
        (b"short", "короче заголовка"),
        (empty, "ни одного треугольника"),
        (cube_stl()[:-10], "оборвался"),
    ]:
        with pytest.raises(glb.GlbError) as got:
            glb.stl_to_glb(raw)

        assert key in str(got.value)


def test_garbage_stl_is_not_converted():
    """Мусор длиной «всё нормально»: граней нет, а файл есть — так не пропускаем."""
    junk = b"x" * 80 + struct.pack("<I", 1) + b"\x00" * 50

    with pytest.raises(glb.GlbError, match="не осталось ни одной грани"):
        glb.stl_to_glb(junk)


def test_foreign_glb_is_refused_not_misread():
    good = glb.stl_to_glb(cube_stl())

    with pytest.raises(glb.GlbError, match="не GLB"):
        glb.glb_info(b"glTX" + good[4:])

    bad = bytearray(good)
    struct.pack_into("<I", bad, 4, 1)                      # glTF 1 читать не обещаем
    with pytest.raises(glb.GlbError, match="версия"):
        glb.glb_info(bytes(bad))

    with pytest.raises(glb.GlbError, match="оборвался"):
        glb.glb_info(good[:-8])                            # заявлено больше, чем байт

    with pytest.raises(glb.GlbError, match="вылезает"):
        bigger = bytearray(good)
        struct.pack_into("<I", bigger, 12, len(good) * 3)  # JSON-блок длиннее файла
        glb.glb_info(bytes(bigger))

    with pytest.raises(glb.GlbError, match="нет ни одного меша"):
        doc, blob = None, glb.stl_to_glb(cube_stl())
        parsed = json.loads(blob[20:20 + struct.unpack_from("<I", blob, 12)[0]])
        parsed["meshes"] = []
        text = json.dumps(parsed, separators=(",", ":")).encode()
        text += b" " * (-len(text) % 4)
        head = struct.pack("<3I", 0x46546C67, 2, 12 + 8 + len(text))
        blob = head + struct.pack("<2I", len(text), glb._CHUNK_JSON) + text
        glb.glb_info(blob)
