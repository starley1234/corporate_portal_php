"""Самодостаточная OpenSCAD-деталь → STL, GLB и три ракурса.

Общие scad._run (headless, timeout, логи) и glb (STL/VRML, мм/Z-up)
переиспользуются; зависимости от handler/table/view Graph3D нет.
Ракурсы сохраняются с прозрачным фоном (день и ночь одинаково); для ИИ-проверки
их подкладывают на белый (flatten_png), чтобы модель не видела чёрный фон альфы.
"""
from __future__ import annotations

import base64
import io
import os
import re
import shutil
import tempfile
from collections import Counter
from pathlib import Path

from . import glb, scad

MAX_BYTES = 24 * 1024 * 1024
# Допуск по цвету фона: антиалиасинг краёв уходит в полупрозрачность, сам фон — полностью.
BACKGROUND_TOLERANCE = 10
_STRINGS_COMMENTS = re.compile(r'"(?:\\.|[^"\\])*"|/\*.*?\*/|//[^\r\n]*', re.S)


def validate_source(source: str) -> str:
    if not source.strip() or len(source.encode('utf-8')) > 60000:
        raise ValueError('OpenSCAD: код должен занимать от 1 до 60 000 байт')
    plain = _STRINGS_COMMENTS.sub(' ', source)
    if re.search(r'\b(include|use|import|surface)\b', plain, re.I):
        raise ValueError('OpenSCAD: внешние файлы, include/use/import/surface запрещены')
    return source


def _read(path: Path) -> bytes:
    if not path.is_file() or not 0 < path.stat().st_size <= MAX_BYTES:
        raise ValueError('OpenSCAD: нет файла или превышен размер 24 МБ')
    return path.read_bytes()


def _run(binary: str, args: list[str], path: Path, timeout: int) -> None:
    cmd = [binary, *args]
    # Linux-лимиты наследуются именно процессом движка, не всем asyncio-воркером.
    # На других ОС остаются timeouts и кап выходных файлов.
    limit = shutil.which('prlimit')
    if limit:
        cmd = [limit, '--as=2147483648', f'--cpu={timeout}', f'--fsize={MAX_BYTES}', '--', *cmd]
    ok, reason = scad._run(cmd, str(path), timeout)
    if not ok:
        raise ValueError(('OpenSCAD: ' + reason)[:1500])


def transparent_png(raw: bytes) -> bytes:
    """Фон ракурса (цвет по углам кадра) становится прозрачным. Без Pillow — исходный PNG."""
    try:
        from PIL import Image
    except ImportError:
        return raw
    img = Image.open(io.BytesIO(raw)).convert('RGBA')
    width, height = img.size
    corners = [img.getpixel(point)[:3] for point in ((0, 0), (width - 1, 0), (0, height - 1), (width - 1, height - 1))]
    bg = Counter(corners).most_common(1)[0][0]

    def keyed(item):
        r, g, b, a = item
        if abs(r - bg[0]) <= BACKGROUND_TOLERANCE and abs(g - bg[1]) <= BACKGROUND_TOLERANCE and abs(b - bg[2]) <= BACKGROUND_TOLERANCE:
            return (r, g, b, 0)
        return item

    pixels = img.load()
    for y in range(height):
        for x in range(width):
            pixels[x, y] = keyed(pixels[x, y])
    out = io.BytesIO()
    img.save(out, 'PNG', optimize=True)
    return out.getvalue()


def flatten_png_b64(b64: str) -> str:
    """Ракурс с прозрачным фоном → base64 PNG на белом (для ИИ-проверки). Ошибка — исходные данные."""
    try:
        from PIL import Image
    except ImportError:
        return b64
    try:
        img = Image.open(io.BytesIO(base64.b64decode(b64))).convert('RGBA')
    except (ValueError, OSError):
        return b64
    base = Image.new('RGBA', img.size, (255, 255, 255, 255))
    base.alpha_composite(img)
    out = io.BytesIO()
    base.convert('RGB').save(out, 'PNG')
    return base64.b64encode(out.getvalue()).decode('ascii')


def render(source: str) -> dict:
    """Блокирующий экспорт; вызывать через asyncio.to_thread, не из HTTP.

    PNG ракурсы — настоящая геометрия OpenSCAD. WRL опционален: если движок
    его не знает, GLB будет одноцветным; STL для печати от этого не меняется.
    """
    validate_source(source)
    binary = (os.getenv('NJ669_OPENSCAD_BIN') or '').strip() or scad.openscad_bin()
    if not binary:
        raise RuntimeError('OpenSCAD не найден: установите движок или задайте NJ669_OPENSCAD_BIN')
    with tempfile.TemporaryDirectory(prefix='nj669_scad_') as temp:
        tmp = Path(temp)
        src = tmp / 'model.scad'
        src.write_text(source, encoding='utf-8')
        stl_path = tmp / 'model.stl'
        _run(binary, ['-o', str(stl_path), str(src)], stl_path, 150)
        stl = glb.normalize_stl(_read(stl_path))
        # Строгий общий GLB-конвертер проверяет конечность координат и непустую сетку.
        model = glb.stl_to_glb(stl, 'solid3d-part')
        color_note = ''
        wrl_path = tmp / 'model.wrl'
        try:
            _run(binary, ['-o', str(wrl_path), str(src)], wrl_path, 45)
            model = glb.wrl_to_glb(_read(wrl_path), 'solid3d-part')
        except (glb.GlbError, ValueError, OSError):
            color_note = 'Движок не отдал цветной VRML: GLB показан одним цветом.'
        if len(model) > MAX_BYTES or len(stl) > MAX_BYTES:
            raise ValueError('Сетка слишком тяжёлая: уменьшите детализацию $fn')
        views = []
        # Вид сверху и две противоположные изометрии: не один дублированный кадр.
        for i, (rx, rz) in enumerate(((55, 25), (55, 205), (0, 0))):
            path = tmp / f'view{i}.png'
            _run(binary, ['-o', str(path), '--imgsize=640,640', '--projection=o',
                          '--render', '--autocenter', '--viewall',
                          f'--camera=0,0,0,{rx},0,{rz},500', str(src)], path, 60)
            views.append(transparent_png(_read(path)))
        return {'stl': stl, 'glb': model, 'png': views[0], 'views': views,
                'color_note': color_note, 'info': glb.glb_info(model)}
