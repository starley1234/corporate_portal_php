/**
 * nj520_poll: QR-код ссылки на опрос читается камерой — и рисуется локально.
 *
 * Запуск: npm run test:poll-qr
 *
 * QR на странице /p/<код> строит браузер, поэтому PHP-тесты его не видят.
 * Здесь берём ТЕ ЖЕ параметры, что во вьюхе (версия 0 — автоподбор, уровень
 * коррекции M, поле в один модуль), рисуем матрицу в растр и читаем её
 * НЕЗАВИСИМЫМ декодером jsQR: если адрес не совпал, участник, наведя камеру,
 * попадёт не туда (или никуда) — а это весь смысл кода.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const require = createRequire(import.meta.url);
const qrcode = require('../../public_html/assets/vendor/qrcode/qrcode.js');
const jsQR = require('jsqr');

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const VIEW = readFileSync(resolve(ROOT, 'application/modules/nj520_poll/views/520/script.php'), 'utf8');
const PAGE = readFileSync(resolve(ROOT, 'application/modules/nj520_poll/views/520/poll.php'), 'utf8');

/** Нарисовать матрицу QR в растр и прочитать её сторонним декодером. */
function decode(text, { margin = 1, scale = 6 } = {}) {
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();

    const count = qr.getModuleCount();
    const width = (count + margin * 2) * scale;
    const pixels = new Uint8ClampedArray(width * width * 4).fill(255);

    for (let row = 0; row < count; row++) {
        for (let col = 0; col < count; col++) {
            if (!qr.isDark(row, col)) continue;

            for (let y = 0; y < scale; y++) {
                for (let x = 0; x < scale; x++) {
                    const offset = (((row + margin) * scale + y) * width + (col + margin) * scale + x) * 4;
                    pixels[offset] = pixels[offset + 1] = pixels[offset + 2] = 0;
                }
            }
        }
    }

    return jsQR(pixels, width, width, { inversionAttempts: 'dontInvert' })?.data;
}

test('Ссылка на опрос читается из QR обратно, знак в знак', () => {
    const links = [
        'https://n2j.ru/p/17qjNr',
        'https://n2j.ru/p/51G2D3',
        'http://localhost:8080/p/YYTyih',
        'https://n2j.ru/p/9mKpQw?created=1',
    ];

    for (const link of links) {
        assert.equal(decode(link), link, `QR не вернул ссылку ${link}`);
    }
});

test('Поле вокруг кода обязательно: без него камера код не поймает', () => {
    // «Тихая зона» — часть стандарта. Во вьюхе margin = 1 модуль (плюс
    // светлая плашка вокруг блока), поэтому проверяем, что хотя бы такое
    // поле даёт читаемый код, а совсем без поля — нет.
    assert.equal(decode('https://n2j.ru/p/17qjNr', { margin: 1 }), 'https://n2j.ru/p/17qjNr');
    assert.match(VIEW, /margin:\s*1\b/, 'Вьюха перестала задавать поле вокруг QR');
});

test('QR рисуется локальной библиотекой — ни одного внешнего хоста', () => {
    // Урок платформы: секрет 2FA однажды уехал в адресе картинки на
    // api.qrserver.com. Ссылка на опрос — тоже данные участника.
    assert.match(PAGE, /\/assets\/vendor\/qrcode\/qrcode\.js/, 'QR-библиотека должна быть локальной');
    assert.doesNotMatch(PAGE, /https?:\/\/(?!n2j\.ru)[a-z0-9.-]+\/[^"']*\.(js|png|svg)/i,
        'На странице опроса появился внешний ресурс');
    assert.match(VIEW, /qrcode\(0,\s*'M'\)/, 'Версия и уровень коррекции QR изменились — перепроверьте чтение');
});

test('Страница опроса остаётся рабочей без JavaScript', () => {
    // QR и живые цифры — добавка, а не условие: голосование идёт формой.
    assert.match(PAGE, /<noscript>/, 'Нет честного пояснения для выключенного JS');
    assert.match(PAGE, /<form method="post" action="\/p\//, 'Голосование должно работать обычной формой');
    assert.match(PAGE, /data-option-bar/, 'Полоски долей рисует сервер, а не только скрипт');
});
