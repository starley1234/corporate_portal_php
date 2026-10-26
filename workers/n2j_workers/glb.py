"""STL → GLB (бинарный glTF 2.0) — чтобы серверную модель было видно в браузере.

Зачем это нужно. OpenSCAD рисует геометрию честно, но наружу отдаёт STL —
«мешок треугольников» без материала, без цвета и без единиц: браузеру такого
мало, а тащить в страницу трёхмегатный STL и разбирать его javascript'ом —
удовольствие на пару секунд даже на десктопе. GLB — тот же меш, но с
вершинами, нормальями и индексом: three.js читает его одним BufferGeometry,
файл меньше и грузится одним запросом.

Почему конвертер свой, а не numpy/trimesh. Воркер намеренно живёт на одной
стандартной библиотеке (урок nj570: лишняя тяжёлая зависимость в venv =
«у админа не заводится»), а нам нужен ровно один переход: треугольники STL →
индексы + общие вершины glTF. Всё остальное — 200 байт заголовка GLB.

Что мы умеем:
  * бинарный STL (84 + 50·N) — другой нам и не положен из OpenSCAD;
  * VRML (.wrl) — единственный формат, который OpenSCAD выносит наружу ВМЕСТЕ
    с color(): из него растёт цветная модель браузера, по примитиву на цвет.
    STL цвета не хранит в природе, поэтому «серая сборка» = «вам принесли STL»;
  * склейка совпадающих вершин (STL хранит вершину на каждую грань) — файл
    в ~2,5 раза меньше и нормаль на ребре гладкая, как в самом OpenSCAD;
  * один меш, N примитивов (по одному на цвет), без текстур и анимаций. Чужой
    GLB наш читальщик не обещает понимать — он тогда говорит словами, что не так.

Единицы: как в STL (миллиметры сборки). glTF по спецификации — метры, и
просмотрщик на сайте ставит масштаб сам (см. assets/js/nj667-stl.js), поэтому
здесь никакой пере­множки нет: файл остаётся тем же числом, что посчитал PHP.
"""
from __future__ import annotations

import json
import math
import re
import struct

__all__ = ["stl_to_glb", "wrl_to_glb", "glb_info", "GLB_MAGIC", "parse_stl", "parse_wrl",
           "merge_by_color", "normalize_stl"]

GLB_MAGIC = b"glTF"
_CHUNK_JSON = 0x4E4F534A          # "JSON"
_CHUNK_BIN = 0x004E4942           # "BIN\0"
_FLOAT32 = 5126
_UINT16 = 5123
_UINT32 = 5125
_ARRAY_BUFFER = 34962
_ELEMENT_BUFFER = 34963

#: STL — это 12 float + uint16 на грань.
_STL_HEADER = 84
_STL_TRIANGLE = 50

#: Сколько цветов (примитивов) терпит один меш. Ограничение договорное: его же
#: проверяют приёмка сайта и просмотрщик, расходятся — и файл начнёт жить,
#: а рисоваться криво.
MAX_GROUPS = 24

#: Бумажный цвет и шероховатость 0.85: деталь из фанеры/PLA, а не хром.
#: Значение — sRGB, как его видит CSS; в glTF пишем линейным (см. _srgb2lin).
PAPER_SRGB = (0.82, 0.72, 0.55)

#: Жёлтый, который OpenSCAD пишет ВСЕГДА: и в `material/diffuseColor` всего
#: файла, и хвостом палитры для граней вне `color()` (см. src/io/export_wrl.cc).
#: Это не краска изделия, а метка «цвета нет»: на ней и держался ответ
#: «модель одноцветная» — склейка по диффузному цвету давала ровно её.
WRL_DEFAULT_SRGB = (0.97647, 0.843137, 0.172549)


def _is_wrl_default(color) -> bool:
    # Запас нужен: материал пишет 0.97647, а хвост палитры — 0.976471.
    if color is None:
        return False

    return all(abs(float(a) - float(b)) < 1e-4 for a, b in zip(color, WRL_DEFAULT_SRGB))


class GlbError(Exception):
    """Причина, словами для человека (её отдаём в error сборки)."""


def _f32(value: float) -> float:
    """Значение так, как его увидит glTF: float32 round-trip.

    Иначе склейка вершин поссорится с тем, что реально легло в буфер: ключ
    должен считаться по тем же байтам, что и координаты в файле.
    """
    return struct.unpack("<f", struct.pack("<f", value))[0]


def _srgb2lin(c: float) -> float:
    """sRGB → линейное: glTF требует baseColorFactor в линейном пространстве.

    Просмотрщик делает обратный шаг той же степенью (1/2.2) — тест на склейку
    держит пару в согласии, иначе цвета поедут в одну сторону.
    """
    return max(0.0, min(1.0, float(c))) ** 2.2


def merge_by_color(groups: list[dict]) -> list[dict]:
    """Гранёные группы → группы ЦВЕТА (порядок первого появления сохранён).

    OpenSCAD пишет в VRML отдельный Shape на каждый солид, а не на каждый
    цвет: сборка на 31 узел даёт 60+ форм при девяти красках, и лимит
    примитивов glTF ловил именно их — большая сборка уезжала на сайт серой
    (вопрос владельца, 13.10.2026). Красок в палитре `assembly.scad` заведомо
    меньше, чем солидов, поэтому склейка по цвету снимает проблему и заодно
    уменьшает файл.

    Вершины склеиваются внутри группы, а не между ними: грань другого цвета
    чужая, сшивать её нельзя — на шве появился бы зазор, а нормаль потекла бы
    через границу материала.
    """
    order: list[dict] = []
    by_key: dict = {}

    for group in groups:
        color = group.get("color")
        key = None if color is None else tuple(round(float(c), 5) for c in color[:3])
        bucket = by_key.get(key)

        if bucket is None:
            bucket = {"tris": [], "color": color}
            by_key[key] = bucket
            order.append(bucket)

        bucket["tris"].extend(group["tris"])

    return order


#: Число в VRML: десятичное, с показателем или без.
_WRL_NUM = r"[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?"


def _wrl_bracket(text: str, field: str):
    """Содержимое «field [ … ]» (строка) или None, если поля в файле нет."""
    hit = re.search(r"\b" + field + r"\s*\[(.*?)\]", text, re.S)

    return hit.group(1) if hit else None


def _wrl_blocks(raw: str, strict: bool = True) -> list[list[int]]:
    """Список индексов, разбитый «-1» на блоки (полигоны coordIndex, группы colorIndex).

    OpenSCAD дописывает одиночный «-1» в конце — пустой хвост не считаем.
    """
    blocks: list[list[int]] = []
    cur: list[int] = []

    for token in re.findall(r"[-+]?\d+", raw):
        value = int(token)

        if value == -1:
            if cur:
                blocks.append(cur)
                cur = []

            continue

        cur.append(value)

    if cur:
        if strict:
            raise GlbError("VRML: coordIndex не закрыт «-1»")

        blocks.append(cur)                        # в colorIndex хвост без «-1» — норма
    return blocks


def _wrl_palette(chunk: str):
    """Палитра граней: «color Color { color [ r g b, … ] }». None — палитры нет.

    Это то место, куда современный OpenSCAD кладёт `color()` из .scad: один
    список красок на всё изделие и индекс краски на каждую грань.
    """
    raw = _wrl_bracket(chunk, "color")

    if raw is None:
        return None

    nums = [float(x) for x in re.findall(_WRL_NUM, raw)]

    if len(nums) % 3:
        raise GlbError("VRML: палитра color[] не кратна трём — файл побит")

    return [(nums[k], nums[k + 1], nums[k + 2]) for k in range(0, len(nums), 3)]


def _wrl_face_colors(chunk: str, polys: list[list[int]], count_verts: int):
    """Индекс краски на грань по «colorIndex» или None, если граней не касались.

    Разметку встречают две: по одной на грань (colorPerVertex FALSE — так пишет
    OpenSCAD) и по одной на ВЕРШИНУ (TRUE). Вторую приводим к первой: грань
    получает цвет своей первой вершины — ровно так же её увидит three.js.
    """
    raw = _wrl_bracket(chunk, "colorIndex")

    if raw is None:
        return None

    nums = [int(float(x)) for x in re.findall(r"[-+]?\d+", raw)]
    per_vertex = re.search(r"\bcolorPerVertex\s+TRUE", chunk) is not None
    flat = -1 not in nums

    if flat and not per_vertex and len(nums) == len(polys):
        return nums

    if flat and per_vertex and len(nums) == count_verts:
        return [nums[poly[0]] for poly in polys]

    blocks = _wrl_blocks(raw, strict=False)

    if len(blocks) == len(polys):
        return [block[0] if block else -1 for block in blocks]

    if len(nums) == len(polys):
        return nums

    raise GlbError("VRML: colorIndex не совпадает ни с числом граней, ни с числом вершин")


def parse_wrl(data: bytes) -> list[dict]:
    """VRML97 (экспорт OpenSCAD) → группы ГРАНЕЙ со цветами.

    Возвращает [{"tris": [(normal, (a, b, c)), …], "color": (r, g, b) | None}].

    Вся хитрость — где лежит цвет. Текущий OpenSCAD пишет ОДИН `Shape` на всё
    изделие, а краски раскладывает по граням самого IndexedFaceSet: «color Color
    { color [ … ] }» + «colorIndex [ … ]». Его `material/diffuseColor` при этом
    константа «0.97647 0.843137 0.172549» — жёлтая метка «цвета нет». Читать
    только диффузный цвет — значит склеить сборку в одну краску именно так, как
    это увидел владелец 13.10.2026 («цвета в браузере нет»). Экспорт, где
    материал сидит внутри каждого Shape, остаётся запасным путём.

    Разбор намеренно маленький: point[], coordIndex[], color[], colorIndex[].
    Неожиданную форму файла говорим словами, а не рисуем наугад.
    """
    if not data.lstrip()[:6].startswith(b"#VRML"):
        raise GlbError("это не VRML: заголовка «#VRML V2.0» на месте нет")

    # Комментарии VRML («…, # default colour») вырезаем до поиска чисел: иначе
    # цифры из текста комментария влезли бы в палитру или в список вершин.
    text = re.sub(r"#[^\n]*", "", data.decode("utf-8", "replace"))
    chunks = re.split(r"\bShape\s*\{", text)[1:] or [text]
    groups: list[dict] = []

    for chunk in chunks:
        mat = re.search(
            r"diffuseColor\s+({0})\s+({0})\s+({0})".format(_WRL_NUM), chunk)
        material = tuple(float(x) for x in mat.groups())[:3] if mat else None

        if _is_wrl_default(material):
            material = None                     # метка «цвета нет», а не краска

        pts = _wrl_bracket(chunk, "point")
        idx = _wrl_bracket(chunk, "coordIndex")

        if not (pts and idx):
            continue                        # пустая группа (например, color() без тела)

        nums = re.findall(_WRL_NUM, pts)

        if len(nums) % 3:
            raise GlbError("VRML: список вершин не кратен трём — файл побит")

        verts = [(_f32(float(nums[k])), _f32(float(nums[k + 1])), _f32(float(nums[k + 2])))
                 for k in range(0, len(nums), 3)]
        polys = _wrl_blocks(idx)
        palette = _wrl_palette(chunk)
        face_of = _wrl_face_colors(chunk, polys, len(verts)) if palette else None

        # Группы красок держим списком по первому появлению цвета: порядок
        # материалов в glTF читается человеком как «тип 1, тип 2, …».
        buckets: dict = {}
        order: list[dict] = []

        for n, poly in enumerate(polys):
            if not poly:
                continue

            if len(poly) != 3:
                raise GlbError("VRML: умеем только треугольные грани «a b c -1»")

            if any(i < 0 or i >= len(verts) for i in poly):
                raise GlbError("VRML: индекс смотрит за конец списка вершин")

            color = material

            if face_of is not None:
                hit = face_of[n]
                color = palette[hit] if 0 <= hit < len(palette) else material

                if _is_wrl_default(color):
                    color = None                # грань без color() — в общую краску

            key = None if color is None else tuple(round(float(c), 5) for c in color[:3])
            bucket = buckets.get(key)

            if bucket is None:
                bucket = {"tris": [], "color": color}
                buckets[key] = bucket
                order.append(bucket)

            a, b, c = (verts[i] for i in poly)
            bucket["tris"].append((None, (a, b, c)))

        groups.extend(order)

    groups = merge_by_color([g for g in groups if g["tris"]])

    if not groups:
        raise GlbError("в VRML нет ни одной гранёной группы")

    if len(groups) > MAX_GROUPS:
        raise GlbError(f"цветов в модели {len(groups)}, а умеем {MAX_GROUPS}")

    return groups


def parse_stl(data: bytes) -> list[tuple[tuple[float, float, float], tuple[float, float, float]]]:
    """Бинарный STL → список граней ((normal), (v0, v1, v2)).

    Нормали STL не заслуживают доверия (их пишет CSG-движок и нередко ставит
    нуль на вырожденных гранях) — считаем свою, а входную возвращаем только
    чтобы не молчать о мусоре.
    """
    if len(data) < _STL_HEADER:
        raise GlbError("файл короче заголовка STL")

    count = struct.unpack_from("<I", data, 80)[0]

    if count == 0:
        raise GlbError("в STL нет ни одного треугольника")

    if len(data) < _STL_HEADER + _STL_TRIANGLE * count:
        raise GlbError(f"STL оборвался: ждали {count} граней, файлов на {len(data)} байт")

    tris: list[tuple[tuple[float, ...], tuple[tuple[float, ...], ...]]] = []

    for i in range(count):
        base = _STL_HEADER + _STL_TRIANGLE * i
        nums = struct.unpack_from("<12f", data, base)
        normal = tuple(_f32(c) for c in nums[0:3])
        verts = tuple(tuple(_f32(c) for c in nums[o:o + 3]) for o in (3, 6, 9))
        tris.append((normal, verts))

    return tris


def _face_normal(a, b, c):
    ux, uy, uz = b[0] - a[0], b[1] - a[1], b[2] - a[2]
    vx, vy, vz = c[0] - a[0], c[1] - a[1], c[2] - a[2]
    return (uy * vz - uz * vy, uz * vx - ux * vz, ux * vy - uy * vx)


def _norm(v):
    length = math.sqrt(v[0] * v[0] + v[1] * v[1] + v[2] * v[2])

    if length <= 0.0:
        return (0.0, 0.0, 1.0)

    return (v[0] / length, v[1] / length, v[2] / length)


def merge_vertices(tris) -> tuple[list, list, list]:
    """Грани → (позиции, нормали, индексы): общие вершины склеены.

    Нормаль вершины — сумма нормалей её граней: на печатной детали фаска
    выглядит так же, как в окне OpenSCAD, а не как «стог треугольников».
    """
    positions: list[tuple[float, float, float]] = []
    normals: list[list[float]] = []
    index_of: dict[tuple[float, float, float], int] = {}
    indices: list[int] = []

    for _normal, verts in tris:
        a, b, c = verts[0], verts[1], verts[2]

        if a == b or b == c or a == c:
            continue                        # вырожденная грань — её не на что опирать

        fn = _face_normal(a, b, c)

        if fn == (0.0, 0.0, 0.0):
            continue

        for v in (a, b, c):
            idx = index_of.get(v)

            if idx is None:
                idx = len(positions)
                index_of[v] = idx
                positions.append(v)
                normals.append([0.0, 0.0, 0.0])

            normals[idx][0] += fn[0]
            normals[idx][1] += fn[1]
            normals[idx][2] += fn[2]

        indices.extend((index_of[a], index_of[b], index_of[c]))

    if not positions:
        raise GlbError("после склейки не осталось ни одной грани")

    out_normals = [_norm(n) for n in normals]

    return positions, out_normals, indices


def _index_component_type(vertex_count: int) -> int:
    """uint16 пока хватает (WebGL резервирует 65535), дальше — uint32.

    Ошибка в эту сторону — молча съехавшая геометрия на модели в 30 000 граней,
    поэтому порог держим отдельной функцией и проверяем тестом.
    """
    return _UINT16 if vertex_count <= 65534 else _UINT32


def stl_to_glb(data: bytes, name: str = "nj667-assembly") -> bytes:
    """Бинарный STL → бинарный GLB одним серым мешем. Бросает GlbError словами."""
    return groups_to_glb([{"tris": parse_stl(data), "color": None}], name)


def wrl_to_glb(data: bytes, name: str = "nj667-assembly") -> bytes:
    """VRML от OpenSCAD → GLB с материалом на каждый цвет (группа = примитив)."""
    return groups_to_glb(parse_wrl(data), name)


def groups_to_glb(groups: list[dict], name: str = "nj667-assembly") -> bytes:
    """Грани групп → ОДИН меш glTF: примитив и материал на каждый цвет.

    `color()` доживает из OpenSCAD только до VRML, поэтому цветная сборка в
    браузере растёт именно оттуда — STL цвета не хранит в природе. Вершины
    склеиваем внутри группы, а не между ними: грань другого цвета чужая, и
    сшивать её нельзя — на шве появился бы зазор, а нормаль потекла бы через
    границу материала.
    """
    if not groups:
        raise GlbError("граней нет — конвертировать нечего")

    if len(groups) > MAX_GROUPS:
        raise GlbError(f"цветов в модели {len(groups)}, а умеем {MAX_GROUPS}")

    accessors: list[dict] = []
    views: list[dict] = []
    prims: list[dict] = []
    materials: list[dict] = []
    blob: list[bytes] = []
    size = 0
    tris_total = 0

    def add_view(chunk: bytes, target: int) -> int:
        nonlocal size

        padded = _pad(chunk)
        views.append({"buffer": 0, "byteOffset": size, "byteLength": len(padded), "target": target})
        blob.append(padded)
        size += len(padded)

        return len(views) - 1

    for group in groups:
        positions, normals, indices = merge_vertices(group["tris"])

        if not indices:
            raise GlbError("в группе не осталось ни одной грани")

        count = len(positions)
        index_type = _index_component_type(count)
        mins = [min(p[i] for p in positions) for i in range(3)]
        maxs = [max(p[i] for p in positions) for i in range(3)]

        pos_v = add_view(b"".join(struct.pack("<3f", *v) for v in positions), _ARRAY_BUFFER)
        nrm_v = add_view(b"".join(struct.pack("<3f", *n) for n in normals), _ARRAY_BUFFER)
        pack = "<%dH" % len(indices) if index_type == _UINT16 else "<%dI" % len(indices)
        idx_v = add_view(struct.pack(pack, *indices), _ELEMENT_BUFFER)

        base = len(accessors)
        accessors.append({
            "bufferView": pos_v, "componentType": _FLOAT32, "count": count,
            "type": "VEC3", "min": mins, "max": maxs,
        })
        accessors.append({"bufferView": nrm_v, "componentType": _FLOAT32, "count": count, "type": "VEC3"})
        accessors.append({
            "bufferView": idx_v, "componentType": index_type, "count": len(indices), "type": "SCALAR",
        })
        prims.append({
            "attributes": {"POSITION": base, "NORMAL": base + 1},
            "indices": base + 2,
            "material": len(materials),
        })

        rgb = group.get("color") or PAPER_SRGB
        materials.append({
            "name": "print" if not materials else "print-%d" % len(materials),
            "pbrMetallicRoughness": {
                "baseColorFactor": [round(_srgb2lin(c), 6) for c in rgb] + [1.0],
                "metallicFactor": 0.0,
                "roughnessFactor": 0.85,
            },
        })
        tris_total += len(indices) // 3

    if not tris_total:
        raise GlbError("граней нет — конвертировать нечего")

    bin_blob = b"".join(blob)

    json_doc = {
        "asset": {"version": "2.0", "generator": "n2j.ru nj667 (STL/VRML→GLB)"},
        "scene": 0,
        "scenes": [{"nodes": [0], "name": name}],
        "nodes": [{"mesh": 0, "name": name}],
        "meshes": [{"name": name, "primitives": prims}],
        "materials": materials,
        "accessors": accessors,
        "bufferViews": views,
        "buffers": [{"byteLength": len(bin_blob)}],
    }

    json_bytes = _pad(json.dumps(json_doc, separators=(",", ":")).encode("utf-8"), b" ")

    total = 12 + 8 + len(json_bytes) + 8 + len(bin_blob)
    head = struct.pack("<3I", 0x46546C67, 2, total)   # 'glTF'
    out = head
    out += struct.pack("<2I", len(json_bytes), _CHUNK_JSON) + json_bytes
    out += struct.pack("<2I", len(bin_blob), _CHUNK_BIN) + bin_blob

    return out


def _pad(chunk: bytes, fill: bytes = b"\x00") -> bytes:
    """Дополнить до кратности 4 — так требует спецификация GLB/glTF."""
    rest = len(chunk) % 4

    return chunk if rest == 0 else chunk + fill * (4 - rest)


def glb_info(data: bytes) -> dict:
    """Что лежит в GLB: размер, грани, габарит. Разбор — минимальный и строгий.

    Нужен и сайту (проверить байты до записи на диск), и тестам, и человеку:
    «GLB готов, 12 480 граней, 96×96×140 мм» — это строка в интерфейсе.
    """
    if len(data) < 12:
        raise GlbError("GLB короче заголовка")

    magic, version, total = struct.unpack_from("<3I", data, 0)

    if magic != 0x46546C67:
        raise GlbError("это не GLB (нет подписи glTF)")

    if version != 2:
        raise GlbError(f"поддерживаем glTF 2, а тут версия {version}")

    if total > len(data):
        raise GlbError("GLB оборвался: заявлено больше, чем байт")

    offset = 12
    json_doc = None
    bin_len = 0
    bin_start = 0

    while offset + 8 <= min(total, len(data)):
        chunk_len, chunk_type = struct.unpack_from("<2I", data, offset)
        offset += 8

        if offset + chunk_len > len(data):
            raise GlbError("chunk GLB вылезает за конец файла")

        if chunk_type == _CHUNK_JSON and json_doc is None:
            json_doc = json.loads(data[offset:offset + chunk_len].decode("utf-8", "replace"))
        elif chunk_type == _CHUNK_BIN:
            bin_len = chunk_len
            bin_start = offset

        offset += chunk_len

    if json_doc is None:
        raise GlbError("в GLB нет JSON-описания")

    meshes = json_doc.get("meshes") or []
    accessors = json_doc.get("accessors") or []

    if not meshes or not accessors:
        raise GlbError("в GLB нет ни одного меша")

    # Цветная сборка — один меш с примитивом на каждый цвет, поэтому считаем
    # по всем примитивам, а не по первому: иначе «габарит» показал бы одну
    #Cluster'ов щепку вместо всей сборки.
    groups = 0
    tris = 0
    verts = 0
    lo = [float("inf")] * 3
    hi = [float("-inf")] * 3

    for mesh in meshes:
        for prim in mesh.get("primitives") or []:
            groups += 1
            pos = accessors[int((prim.get("attributes") or {}).get("POSITION", 0))]
            idx_count = int(accessors[int(prim["indices"])].get("count", 0)) if "indices" in prim else 0

            tris += (idx_count // 3) if idx_count else int(pos.get("count", 0)) // 3
            verts += int(pos.get("count", 0))

            for d in range(3):
                if pos.get("min") and pos.get("max"):
                    lo[d] = min(lo[d], float(pos["min"][d]))
                    hi[d] = max(hi[d], float(pos["max"][d]))

    has_box = tris > 0 and lo[0] != float("inf")

    return {
        "ok": True,
        "size": len(data),
        "triangles": tris,
        "vertices": verts,
        "groups": groups,
        "min": [round(v, 3) for v in lo] if has_box else None,
        "max": [round(v, 3) for v in hi] if has_box else None,
        "dims": [round(hi[i] - lo[i], 3) for i in range(3)] if has_box else [],
        "bin_len": bin_len,
        "bin_start": bin_start,
        "generator": ((json_doc.get("asset") or {}).get("generator") or ""),
    }


def normalize_stl(data: bytes) -> bytes:
    """ASCII или binary STL → строгий binary STL для сайта и GLB-конвертера.

    Общий вход рендеров Graph3D и Solid3D: старые OpenSCAD не умеют
    --export-format и возвращают ASCII. Никаких внешних библиотек.
    """
    if len(data) >= 84:
        count = struct.unpack_from('<I', data, 80)[0]
        if count > 0 and len(data) == 84 + 50 * count:
            for record in struct.iter_unpack('<12f2x', memoryview(data)[84:]):
                if not all(math.isfinite(c) for c in record):
                    raise GlbError('STL: координаты не конечны')
            return data
    text = data.decode('ascii', 'strict')
    number = r'[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?'
    facets = re.findall(r'facet\s+normal\s+(' + number + r')\s+(' + number + r')\s+(' + number + r')(.*?)endfacet', text, re.S | re.I)
    body = bytearray()
    for x, y, z, facet in facets:
        vertices = re.findall(r'vertex\s+(' + number + r')\s+(' + number + r')\s+(' + number + r')', facet, re.I)
        if len(vertices) != 3:
            raise GlbError('ASCII STL: у грани не три вершины')
        nums = [float(x), float(y), float(z)] + [float(c) for vertex in vertices for c in vertex]
        if not all(math.isfinite(c) for c in nums):
            raise GlbError('STL: координаты не конечны')
        body.extend(struct.pack('<12fH', *nums, 0))
    if not body:
        raise GlbError('Это не STL или сетка пуста')
    return b'n2j: ascii -> binary'.ljust(80, b' ') + struct.pack('<I', len(body) // 50) + body
