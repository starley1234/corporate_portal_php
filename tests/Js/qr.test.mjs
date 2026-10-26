/** nj510_qr: чистые функции, UTF-8, импорт, SVG и независимое чтение матрицы. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT, runPhp } from '../../.tools/qr_php_render.mjs';
const require = createRequire(import.meta.url);
const qr = require('../../public_html/assets/js/nj510-qr-core.js');
const qrcode = require('../../public_html/assets/vendor/qrcode/qrcode.js');
const jsQR = require('jsqr');
const settings = JSON.parse(await runPhp('--json'));
const limits = settings.limits;

function decode(grid) {
    const scale = 6, margin = 4, width = (grid.length + margin * 2) * scale;
    const pixels = new Uint8ClampedArray(width * width * 4).fill(255);
    for (let row = 0; row < grid.length; row++) {
        for (let col = 0; col < grid.length; col++) {
            if (!grid[row][col]) continue;
            for (let y = 0; y < scale; y++) for (let x = 0; x < scale; x++) {
                const offset = (((row + margin) * scale + y) * width + (col + margin) * scale + x) * 4;
                pixels[offset] = pixels[offset + 1] = pixels[offset + 2] = 0;
            }
        }
    }
    return jsQR(pixels, width, width, { inversionAttempts: 'dontInvert' })?.data;
}

test('Все семь типов восстанавливаются независимым декодером, включая кириллицу и эмодзи', () => {
    const examples = {
        url: { url: 'example.com/заказ?имя=Анна&x=1' },
        text: { text: 'Привет, мир! ☕\nВторая строка — заказ №42.' },
        wifi: { ssid: 'Кафе;сеть', password: 'pa:ss;wo\\rd', security: 'WPA', hidden: true },
        vcard: { first: 'Анна', last: 'Иванова', phone: '+7 (999) 123-45-67', email: 'anna@example.com', organization: 'ООО «Кофе»', url: 'example.com' },
        email: { email: 'a+test@example.com', subject: 'Заказ №5 & тест', message: 'Первая строка\nВторая строка' },
        phone: { phone: '+7 (999) 123-45-67' },
        sms: { phone: '+7 999 123-45-67', message: 'Заказ: 123' },
    };
    for (const [type, fields] of Object.entries(examples)) {
        const data = qr.payload(type, fields, limits);
        assert.equal(decode(qr.render(data, {}, settings).matrix), data, type);
    }
});

test('Текст кодируется как есть: пробелы и переносы не исчезают', () => {
    assert.equal(qr.payload('text', { text: '  текст\n ' }, limits), '  текст\n ');
});

test('Ссылка получает https, но опасные явные схемы и пробелы отклоняются', () => {
    assert.equal(qr.payload('url', { url: 'example.com' }, limits), 'https://example.com/');
    assert.equal(qr.payload('url', { url: 'example.com:8443/page' }, limits), 'https://example.com:8443/page');
    assert.equal(qr.payload('url', { url: 'localhost:8080' }, limits), 'https://localhost:8080/');
    for (const url of ['javascript:alert(1)', 'data:text/html,x', 'ftp://example.com', 'https://example.com/a b', 'https://']) {
        assert.throws(() => qr.payload('url', { url }, limits), /ссылк/i);
    }
});

test('Wi-Fi экранирует служебные символы и не включает старый пароль открытой сети', () => {
    assert.equal(qr.payload('wifi', { ssid: 'a;b:c,"\\', password: 'x;y', hidden: true }, limits),
        'WIFI:T:WPA;S:a\\;b\\:c\\,\\"\\\\;P:x\\;y;H:true;;');
    assert.equal(qr.payload('wifi', { ssid: 'Гости', password: 'секрет', security: 'nopass' }, limits),
        'WIFI:T:nopass;S:Гости;P:;H:false;;');
    assert.throws(() => qr.payload('wifi', { ssid: 'a', security: 'other' }, limits));
    assert.throws(() => qr.payload('wifi', { ssid: 'a', password: '' }, limits), /Пароль/);
});

test('vCard не допускает подстановку новых полей переносом строки', () => {
    const data = qr.payload('vcard', { first: 'Анна', last: 'a;b', organization: 'Фирма\nTEL:123' }, limits);
    assert.ok(data.includes('N:a\\;b;Анна;;;\r\n'));
    assert.ok(data.includes('ORG:Фирма\\nTEL:123\r\n'));
    assert.ok(data.endsWith('END:VCARD'));
});

test('mailto кодирует тему, тело и спецсимволы адреса отдельно', () => {
    const data = qr.payload('email', { email: 'a?x@example.com', subject: 'A&B', message: 'a\nb' }, limits);
    assert.equal(data, 'mailto:a%3Fx@example.com?subject=A%26B&body=a%0Ab');
});

test('Пустые и некорректные значения дают понятные ошибки', () => {
    for (const [type, fields] of [['url', {}], ['text', { text: ' ' }], ['phone', { phone: 'abc' }],
        ['vcard', {}], ['email', { email: 'bad' }], ['other', {}]]) {
        assert.throws(() => qr.payload(type, fields, limits), Error);
    }
    assert.throws(() => qr.payload('text', { text: 'a\0b' }, limits), /нулевой/);
});

test('Длина ограничивается байтами UTF-8, а не числом букв', () => {
    assert.equal(qr.bytes('я☕').length, 5);
    assert.throws(() => qr.payload('text', { text: 'я'.repeat(1001) }, limits), /2000 байт/);
    assert.throws(() => qr.parseBatch('a'.repeat(limits.import_bytes + 1), 'lines', 'auto', limits), /1 МБ/);
});

test('Построчный режим не режет ссылки по запятым, табам и точкам с запятой', () => {
    const items = qr.parseBatch('\uFEFFhttps://a.test/?q=one,two;three\r\n\r\nТекст\tданные\r', 'lines', 'auto', limits);
    assert.deepEqual(items.map((item) => [item.line, item.data]), [[1, 'https://a.test/?q=one,two;three'], [3, 'Текст\tданные']]);
});

test('CSV: BOM, заголовок, кавычки, ; внутри ячейки и переносы', () => {
    const items = qr.parseBatch('\uFEFFимя;данные\r\n"Кафе;1";"Первая\nВторая ""цитата"""\r\nВторой;https://example.com', 'csv', 'auto', limits);
    assert.deepEqual(items.map((item) => [item.label, item.data, item.line]),
        [['Кафе;1', 'Первая\nВторая "цитата"', 2], ['Второй', 'https://example.com', 4]]);
});

test('CSV: обратный порядок колонок заголовка', () => {
    const items = qr.parseBatch('url,name\nhttps://example.com,Меню', 'csv', 'auto', limits);
    assert.equal(items[0].label, 'Меню'); assert.equal(items[0].data, 'https://example.com');
});

test('TSV и CSV с одной колонкой тоже принимаются', () => {
    assert.equal(qr.parseBatch('name\tdata\nМеню\thttps://example.com', 'csv', '\t', limits)[0].label, 'Меню');
    assert.deepEqual(qr.parseBatch('one\ntwo', 'csv', ';', limits).map((item) => item.data), ['one', 'two']);
});

test('Некорректный CSV не приводит к тихому пропуску или неверным кодам', () => {
    for (const [input, message] of [['name;"unclosed', /не закрыта/], ['a;b;c', /2 колонки/],
        ['name;', /пустое/], ['a;"b"junk', /разделитель/], ['a;b"c', /кавычки/]]) {
        assert.throws(() => qr.parseBatch(input, 'csv', ';', limits), message);
    }
});

test('Пакет ровно из 200 кодов допустим, 201 — ошибка', () => {
    assert.equal(qr.parseBatch(Array(200).fill('x').join('\n'), 'lines', 'auto', limits).length, 200);
    assert.throws(() => qr.parseBatch(Array(201).fill('x').join('\n'), 'lines', 'auto', limits), /200 кодов/);
});

test('ID по маске используют литеральный префикс, случайные символы и уникальность', () => {
    let next = 0;
    const ids = qr.generateIds('prefix_####', 5, (max) => next++ % max, limits);
    assert.deepEqual(ids, ['prefix_0123', 'prefix_4567', 'prefix_8901', 'prefix_2345', 'prefix_6789']);
    assert.equal(new Set(ids).size, ids.length);
    let star = 0;
    const alphanumeric = qr.generateIds('SKU-******', 8, (max) => {
        const call = star++;
        return (Math.floor(call / 6) * 7 + call % 6) % max;
    }, limits);
    assert.equal(alphanumeric.length, 8);
    assert.equal(new Set(alphanumeric).size, 8);
    assert.ok(alphanumeric.every((id) => /^SKU-[A-Z0-9]{6}$/.test(id)));
    assert.deepEqual(qr.generateIds('literal-\\#*', 1, () => 0, limits), ['literal-#A']);
    assert.deepEqual(qr.generateIds('literal-\\\\#', 1, () => 7, limits), ['literal-\\7']);
    assert.throws(() => qr.generateIds('#', 11, () => 0, limits), /не может создать/);
    assert.throws(() => qr.generateIds('prefix', 1, () => 0, limits), /случайный символ/);
    assert.throws(() => qr.generateIds('bad;mask-#', 1, () => 0, limits), /допустимы/);
    assert.throws(() => qr.generateIds('x-#', 0, () => 0, limits), /Количество ID/);
});

test('Имена безопасны для ZIP, файловой системы и не пересекаются при повторах', () => {
    assert.equal(qr.filename('../folder\\bad:*?"<>|.png', 1, 'svg'), '001--folder-bad-------.svg');
    assert.equal(qr.filename('CON', 2, 'jpeg'), '002-_CON.jpg');
    assert.equal(qr.filename('   ...', 3, 'png'), '003-qr.png');
    assert.notEqual(qr.filename('одно имя', 1, 'png'), qr.filename('одно имя', 2, 'png'));
    assert.ok(qr.filename('я'.repeat(100), 1, 'png').length < 80);
    assert.throws(() => qr.filename('a', 1, '../../'), /формат/);
});

test('Контраст ограничен, в том числе у второго цвета градиента', () => {
    assert.equal(qr.contrast('#000000', '#ffffff'), 21);
    assert.throws(() => qr.options({ foreground: '#ffffff' }, settings), /контраст/);
    assert.throws(() => qr.options({ gradient: true, accent: '#ffffff' }, settings), /контраст/);
    assert.throws(() => qr.options({ foreground: '#ffffff', background: '#000000' }, settings), /тёмным/);
    for (const preset of Object.values(settings.presets)) assert.ok(qr.options(preset, settings).contrast >= 4.5);
});

test('Опции из хранилища проверяются по белому списку, инъекции цвета и формы запрещены', () => {
    for (const input of [{ foreground: '"><script>' }, { foreground: ['#ffffff'] }, { shape: ['dots'] }, { shape: 'x' }, { format: '../' }, { level: 'L' },
        { margin: 0 }, { margin: 4.5 }, { size: 123 }, { logo_size: 30 }]) assert.throws(() => qr.options(input, settings));
    const result = qr.options(JSON.parse('{"__proto__":{"hacked":true},"extra":"bad"}'), settings);
    assert.equal(result.hacked, undefined); assert.equal(result.extra, undefined);
});

test('Логотип безопасен, включает защиту H и вырезает центр из отрисованной матрицы', () => {
    const logo = 'data:image/png;base64,iVBORw0KGgo=';
    const data = 'https://example.com/logo-test';
    const rendered = qr.render(data, { logo, level: 'M' }, settings);
    assert.equal(rendered.style.level, 'H'); assert.ok(rendered.svg.includes('<image href="' + logo));
    assert.ok(rendered.logoZone); assert.ok(rendered.logoCutouts > 0);
    assert.equal(decode(rendered.matrix), data, 'исходная кодирующая матрица');
    assert.equal(decode(rendered.visibleMatrix), data, 'матрица с резервом под логотип');
    for (let row = rendered.logoZone.top; row < rendered.logoZone.bottom; row++) {
        for (let col = rendered.logoZone.left; col < rendered.logoZone.right; col++) {
            assert.equal(rendered.visibleMatrix[row][col], false, 'область логотипа очищена от модулей');
        }
    }
    for (const source of ['https://evil.example/logo.png', 'data:image/svg+xml,<svg/>', 'data:image/png;base64,xx" onload="x']) {
        assert.throws(() => qr.options({ logo: source }, settings), /изображение/);
    }
});

test('SVG не содержит исходный текст, скрипты или внешние изображения', () => {
    const secret = '</title><script>alert("секрет")</script>';
    const rendered = qr.render(secret, {}, settings);
    assert.ok(!rendered.svg.includes(secret)); assert.ok(!rendered.svg.includes('<script'));
    assert.ok(rendered.svg.includes('viewBox="0 0 ' + rendered.total));
    assert.equal(rendered.total - rendered.count, 8);
});

test('Все стили и размеры дают детерминированный SVG с нужным оформлением', () => {
    for (const preset of Object.values(settings.presets)) {
        for (const size of settings.sizes) {
            const input = { ...preset, size };
            const first = qr.render('https://example.com', input, settings);
            assert.equal(first.svg, qr.render('https://example.com', input, settings).svg);
            assert.ok(first.svg.includes('width="' + size + '"'));
            if (preset.shape === 'dots') assert.ok(first.svg.includes('<circle'));
            if (preset.gradient) assert.ok(first.svg.includes('<linearGradient'));
        }
    }
});

test('Общий UTF-8-конвертер библиотеки не меняется после рендера и ошибки вместимости', () => {
    const previous = qrcode.stringToBytes;
    qr.render('русский', {}, settings); assert.equal(qrcode.stringToBytes, previous);
    assert.throws(() => qr.render('x'.repeat(1800), { level: 'H' }, settings), /не помещаются/);
    assert.equal(qrcode.stringToBytes, previous);
});

test('Выравнивающие метки для версий 1, 7 и 32 совпадают со стандартом', () => {
    assert.deepEqual(qr.alignmentPositions(21), []);
    assert.deepEqual(qr.alignmentPositions(45), [6, 22, 38]);
    assert.deepEqual(qr.alignmentPositions(145), [6, 34, 60, 86, 112, 138]);
});

test('Список архива сохраняет соответствие и защищён от формул табличного редактора', () => {
    const output = qr.manifest([{ label: 'Имя', data: '=HYPERLINK("https://evil.test")' }, { label: 'Имя', data: '+79991234567' }], 'png');
    assert.ok(output.startsWith('\uFEFFномер;файл;содержимое\r\n'));
    assert.ok(output.includes('001-Имя.png')); assert.ok(output.includes('002-Имя.png'));
    assert.ok(output.includes('"\'=HYPERLINK(""https://evil.test"")"'));
    assert.ok(output.includes('"\'+79991234567"'));
});

test('Локальные сторонние библиотеки совпадают с закреплёнными npm-пакетами, лицензии на месте', () => {
    for (const [source, destination] of [['fflate/umd/index.js', 'fflate/fflate.min.js'], ['jsqr/dist/jsQR.js', 'jsqr/jsQR.js']]) {
        assert.deepEqual(readFileSync(resolve(ROOT, 'public_html/assets/vendor', destination)),
            readFileSync(resolve(ROOT, 'node_modules', source)));
    }
    assert.ok(readFileSync(resolve(ROOT, 'public_html/assets/vendor/fflate/LICENSE'), 'utf8').includes('MIT License'));
    assert.ok(readFileSync(resolve(ROOT, 'public_html/assets/vendor/jsqr/LICENSE'), 'utf8').includes('Apache License'));
});
