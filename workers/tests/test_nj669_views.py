"""Ракурсы детали: прозрачный фон для страниц и белая подложка для ИИ-проверки."""
import base64
import io

from PIL import Image

from n2j_workers import solid


def _png(size, background, shape):
    img = Image.new('RGB', size, background)
    px = img.load()
    for x in range(size[0] // 3, size[0] // 3 * 2):
        for y in range(size[1] // 3, size[1] // 3 * 2):
            px[x, y] = shape
    out = io.BytesIO()
    img.save(out, 'PNG')
    return out.getvalue()


def test_background_becomes_transparent_and_part_stays_opaque():
    raw = _png((60, 60), (255, 255, 229), (200, 90, 30))
    out = Image.open(io.BytesIO(solid.transparent_png(raw))).convert('RGBA')
    assert out.getpixel((0, 0))[3] == 0
    assert out.getpixel((59, 59))[3] == 0
    assert out.getpixel((30, 30))[3] == 255  # деталь не прозрачная


def test_near_background_shade_is_keyed_but_real_colours_are_not():
    raw = _png((30, 30), (255, 255, 255), (250, 250, 250))
    out = Image.open(io.BytesIO(solid.transparent_png(raw))).convert('RGBA')
    assert out.getpixel((0, 0))[3] == 0
    assert out.getpixel((15, 15))[3] == 0  # в пределах допуска — фон
    raw = _png((30, 30), (255, 255, 255), (30, 30, 30))
    out = Image.open(io.BytesIO(solid.transparent_png(raw))).convert('RGBA')
    assert out.getpixel((15, 15))[3] == 255


def test_flatten_puts_transparent_view_on_white_for_the_model():
    raw = solid.transparent_png(_png((20, 20), (255, 255, 229), (10, 10, 10)))
    flat = Image.open(io.BytesIO(base64.b64decode(solid.flatten_png_b64(base64.b64encode(raw).decode())))).convert('RGB')
    assert flat.getpixel((0, 0)) == (255, 255, 255)
    assert flat.getpixel((10, 10)) == (10, 10, 10)


def test_flatten_keeps_broken_input_unchanged():
    assert solid.flatten_png_b64('не картинка') == 'не картинка'
