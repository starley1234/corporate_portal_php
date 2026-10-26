/** nj510_qr: настоящая вьюха, Canvas, файловые загрузки и скачивания в Chromium. */
import { chromium, expect } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import { unzipSync, strFromU8 } from 'fflate';
import assert from 'node:assert/strict';
import { previewServer } from './qr_preview.mjs';

const server = await previewServer();
const origin = 'http://127.0.0.1:' + server.address().port;
let browser, page;
const errors = [], remote = [], writes = [], downloads = [];
let stages = 0;
async function stage(title, run) { await run(); stages++; console.log('✓ ' + title); }
async function ready() { await expect(page.locator('#qr-download')).toBeEnabled({ timeout: 15000 }); }
async function choose(selector, value) { await page.locator(selector).selectOption(String(value)); }
async function getDownload() {
    const waiting = page.waitForEvent('download', { timeout: 90000 });
    await page.locator('#qr-download').click();
    const file = await waiting;
    if (await file.failure()) throw new Error(await file.failure());
    return { name: file.suggestedFilename(), bytes: new Uint8Array(await readFile(await file.path())) };
}
async function decode(bytes, mime) {
    return await page.evaluate(async ({ bytes, mime }) => {
        const source = URL.createObjectURL(new Blob([new Uint8Array(bytes)], { type: mime }));
        try {
            const image = new Image();
            await new Promise((resolve, reject) => { image.onload = resolve; image.onerror = reject; image.src = source; });
            const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight;
            const context = canvas.getContext('2d'); context.drawImage(image, 0, 0);
            const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
            const decoded = window.jsQR(pixels.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
            return { data: decoded?.data, width: canvas.width, height: canvas.height };
        } finally { URL.revokeObjectURL(source); }
    }, { bytes: Array.from(bytes), mime });
}

try {
    browser = await chromium.launch({ headless: true,
        ...(process.env.QR_CHROMIUM_EXECUTABLE ? { executablePath: process.env.QR_CHROMIUM_EXECUTABLE } : {}),
        args: ['--no-sandbox', '--disable-dev-shm-usage'],
    });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 }, acceptDownloads: true });
    page = await context.newPage();
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('download', (file) => downloads.push(file.suggestedFilename()));
    page.on('request', (request) => {
        if (!request.url().startsWith(origin) && !/^(blob:|data:)/.test(request.url())) remote.push(request.url());
        if (request.method() !== 'GET') writes.push(request.url());
    });
    await page.route('**/*', async (route) => {
        const url = route.request().url();
        if (url.startsWith(origin) || /^(blob:|data:)/.test(url)) await route.continue();
        else await route.abort();
    });
    await page.goto(origin + '/qr');

    await stage('Начальный QR читается, все стили с логотипом скачиваются в PNG/SVG/JPEG', async () => {
        await ready();
        await expect(page.locator('#qr-scan-status')).toContainText('содержимое совпадает');
        await page.locator('#qr-url').fill('https://example.com/заказ?имя=Анна&x=1'); await ready();
        await page.locator('#qr-logo-demo').click(); await ready();
        const expected = new URL('https://example.com/заказ?имя=Анна&x=1').href;
        for (const style of ['classic', 'ocean', 'mint', 'violet']) {
            await page.locator('[data-preset="' + style + '"]').click(); await ready();
            assert.equal(await page.locator('#qr-level').inputValue(), 'H');
            await expect(page.locator('#qr-level')).toBeDisabled();
            for (const format of ['png', 'svg', 'jpeg']) {
                await choose('#qr-format', format); await ready();
                const file = await getDownload();
                const output = await decode(file.bytes, format === 'svg' ? 'image/svg+xml' : 'image/' + format);
                assert.equal(output.data, expected, style + ' / ' + format);
                assert.equal(output.width, 512); assert.equal(output.height, 512);
                assert.equal(file.name, 'qr-code.' + (format === 'jpeg' ? 'jpg' : format));
            }
        }
    });

    await stage('Низкий контраст блокируется и не оставляет старый код для скачивания', async () => {
        await page.locator('#qr-foreground-hex').fill('#ffffff');
        await expect(page.locator('#qr-error')).toContainText('контраст');
        await expect(page.locator('#qr-download')).toBeDisabled();
        await expect(page.locator('#qr-preview')).toBeHidden();
        await page.locator('#qr-style-reset').click(); await ready();
    });

    await stage('Все типы формы работают, Wi-Fi экранируется и пароль не попадает в storage', async () => {
        const cases = [
            ['text', { 'text': 'Проверка кириллицы ☕\nВторая строка' }],
            ['wifi', { 'wifi-ssid': 'Кафе;сеть', 'wifi-password': 'секрет-LOCAL-qr-123' }],
            ['vcard', { 'card-first': 'Анна', 'card-last': 'Иванова', 'card-phone': '+7 999 123-45-67', 'card-email': 'anna@example.com' }],
            ['email', { 'email-address': 'hello@example.com', 'email-subject': 'Заказ & вопрос', 'email-body': 'Текст письма' }],
            ['phone', { 'phone': '+7 999 123-45-67' }],
            ['sms', { 'sms-phone': '+7 999 123-45-67', 'sms-body': 'Код: 123' }],
        ];
        for (const [type, fields] of cases) {
            await choose('#qr-type', type);
            for (const [key, value] of Object.entries(fields)) await page.locator('#qr-' + key).fill(value);
            await ready();
            await expect(page.locator('#qr-scan-status')).toContainText('содержимое совпадает');
        }
        await choose('#qr-type', 'wifi'); await ready();
        await choose('#qr-wifi-security', 'nopass'); await ready();
        await expect(page.locator('#qr-wifi-password')).toBeDisabled();
        const open = await decode((await getDownload()).bytes, 'image/png');
        assert.equal(open.data, 'WIFI:T:nopass;S:Кафе\\;сеть;P:;H:false;;');
        await page.locator('#qr-logo-demo').click(); await ready();
        await page.locator('#qr-style-save').click();
        const stored = await page.evaluate(() => JSON.parse(localStorage.getItem('nj510.qr.style.v1')));
        assert.equal(stored.logo, undefined); assert.equal(stored.ssid, undefined);
        assert.ok(!JSON.stringify(stored).includes('секрет-LOCAL'));
        assert.ok(!JSON.stringify(stored).includes('data:image'));
    });

    await stage('Локальный PNG нормализуется, SVG и файлы больше лимита отклоняются', async () => {
        const logo = await page.evaluate(() => {
            const canvas = document.createElement('canvas'); canvas.width = canvas.height = 96;
            const context = canvas.getContext('2d'); context.fillStyle = '#172554'; context.fillRect(0, 0, 96, 96);
            context.fillStyle = '#fff'; context.fillRect(35, 20, 20, 55);
            return canvas.toDataURL('image/png').split(',')[1];
        });
        await page.locator('#qr-logo-file').setInputFiles({ name: 'мой-логотип.png', mimeType: 'image/png', buffer: Buffer.from(logo, 'base64') });
        await ready(); await expect(page.locator('#qr-logo-name')).toContainText('мой-логотип.png');
        await expect(page.locator('#qr-logo-icon')).toBeHidden();
        assert.ok((await page.locator('#qr-logo-thumb').getAttribute('src')).startsWith('data:image/png;base64,'));
        await page.locator('#qr-logo-file').setInputFiles({ name: 'bad.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg onload="alert(1)"/>') });
        await expect(page.locator('#qr-error')).toContainText('не поддерживаются');
        await page.locator('#qr-logo-file').setInputFiles({ name: 'big.png', mimeType: 'image/png', buffer: Buffer.alloc(2097153) });
        await expect(page.locator('#qr-error')).toContainText('2 МБ');
        await page.locator('#qr-style-reset').click(); await ready();
    });

    await stage('CSV-импорт: ZIP содержит реальные картинки, безопасные уникальные имена и список', async () => {
        await page.locator('#qr-mode-batch').click();
        await page.locator('#qr-import').setInputFiles({ name: 'список.csv', mimeType: 'text/csv',
            buffer: Buffer.from('\uFEFFимя;данные\r\nМеню;https://example.com/menu\r\nМеню;Привет ☕\r\n../unsafe;"Текст;с кавычками ""да"""') });
        await ready(); await expect(page.locator('#qr-batch-count')).toContainText('3 / 200');
        await page.locator('#qr-logo-demo').click(); await ready();
        await page.locator('[data-preset="mint"]').click(); await ready();
        for (const format of ['png', 'svg', 'jpeg']) {
            await choose('#qr-format', format); await ready();
            const archive = await getDownload();
            assert.equal(archive.name, 'qr-codes-3.zip');
            const files = unzipSync(archive.bytes), extension = format === 'jpeg' ? 'jpg' : format;
            const expected = { ['001-Меню.' + extension]: 'https://example.com/menu',
                ['002-Меню.' + extension]: 'Привет ☕', ['003--unsafe.' + extension]: 'Текст;с кавычками "да"' };
            assert.deepEqual(Object.keys(files).sort(), [...Object.keys(expected), 'manifest.csv'].sort());
            for (const [name, data] of Object.entries(expected)) {
                assert.equal((await decode(files[name], format === 'svg' ? 'image/svg+xml' : 'image/' + format)).data, data, name);
            }
            assert.ok(strFromU8(files['manifest.csv']).includes('001-Меню.' + extension));
            await expect(page.locator('#qr-cancel')).toBeHidden();
        }
        await page.locator('#qr-next').click(); await ready();
        await expect(page.locator('#qr-page')).toHaveText('2 / 3');
        await page.locator('#qr-import').setInputFiles({ name: 'bad.txt', mimeType: 'text/plain', buffer: Buffer.from([0xff]) });
        await expect(page.locator('#qr-error')).toContainText('UTF-8');
    });

    await stage('ID по маске уникальны, читаются с логотипом и раскладываются на A4 по 12 на лист', async () => {
        await page.locator('#qr-id-mask').fill('LOT-####');
        await page.locator('#qr-id-count').fill('13');
        await page.locator('#qr-id-generate').click();
        await expect(page.locator('#qr-message')).toContainText('Создано 13 уникальных ID.');
        await ready();
        const rows = (await page.locator('#qr-batch-text').inputValue()).trim().split(/\r?\n/);
        assert.equal(rows[0], 'имя;данные');
        const ids = rows.slice(1).map((row) => row.split(';')[0]);
        assert.equal(ids.length, 13);
        assert.equal(new Set(ids).size, 13);
        assert.ok(ids.every((id) => /^LOT-[0-9]{4}$/.test(id)));

        const popupReady = page.waitForEvent('popup', { timeout: 30000 });
        await page.locator('#qr-batch-print').click();
        const popup = await popupReady;
        await expect(popup.locator('.qr-print-page')).toHaveCount(2, { timeout: 30000 });
        await expect(popup.locator('.qr-print-card')).toHaveCount(13);
        assert.equal(await popup.locator('.qr-print-page').nth(0).locator('.qr-print-card').count(), 12);
        assert.equal(await popup.locator('.qr-print-page').nth(1).locator('.qr-print-card').count(), 1);
        const printCss = await popup.locator('style').textContent();
        assert.ok(printCss.includes('@page{size:A4 portrait'));
        assert.ok(printCss.includes('grid-template-columns:repeat(3'));
        assert.ok(printCss.includes('grid-template-rows:repeat(4'));
        assert.equal(await popup.locator('.qr-print-card img').evaluateAll((images) => images.every((image) => image.complete && image.naturalWidth > 0)), true);
        await expect(page.locator('#qr-progress-text')).toContainText('Готово: 13 кодов на 2 листах A4.');
        await popup.close();
    });

    await stage('Поздняя ошибка вместимости не скачивает частичный архив', async () => {
        await choose('#qr-format', 'svg');
        await choose('#qr-batch-mode', 'lines');
        await page.locator('#qr-batch-text').fill('первый код\n' + 'x'.repeat(1800)); await ready();
        const count = downloads.length;
        await page.locator('#qr-download').click();
        await expect(page.locator('#qr-error')).toContainText('Строка 2', { timeout: 15000 });
        assert.equal(downloads.length, count);
        await expect(page.locator('#qr-app')).toHaveAttribute('aria-busy', 'false');
    });

    await stage('Отмена большого пакета возвращает управление и не скачивает ZIP', async () => {
        await page.locator('#qr-style-reset').click();
        await choose('#qr-format', 'svg');
        await page.locator('#qr-batch-text').fill(Array.from({ length: 200 }, (_, index) => 'https://example.com/product-' + index).join('\n'));
        await ready();
        const count = downloads.length;
        await page.locator('#qr-download').click();
        await expect(page.locator('#qr-app')).toHaveAttribute('aria-busy', 'true');
        await expect(page.locator('#qr-batch-text')).toBeDisabled();
        await page.locator('#qr-cancel').click();
        await expect(page.locator('#qr-app')).toHaveAttribute('aria-busy', 'false', { timeout: 15000 });
        await expect(page.locator('#qr-message')).toContainText('отменена');
        await expect(page.locator('#qr-batch-text')).toBeEnabled();
        assert.equal(downloads.length, count);
    });

    await stage('Пакет из 200 кодов полностью скачивается; 201 блокируется до генерации', async () => {
        const archive = await getDownload();
        const files = unzipSync(archive.bytes);
        assert.equal(Object.keys(files).length, 201);
        assert.equal((await decode(files['001-qr.svg'], 'image/svg+xml')).data, 'https://example.com/product-0');
        assert.equal((await decode(files['200-qr.svg'], 'image/svg+xml')).data, 'https://example.com/product-199');
        await page.locator('#qr-batch-text').fill(Array(201).fill('x').join('\n'));
        await expect(page.locator('#qr-error')).toContainText('200 кодов');
        await expect(page.locator('#qr-download')).toBeDisabled();
        await page.locator('#qr-batch-clear').click();
    });

    await stage('Мобильный экран и тёмная тема не создают горизонтальную прокрутку', async () => {
        await page.locator('#qr-mode-single').click(); await choose('#qr-type', 'url'); await ready();
        for (const width of [320, 390, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false, 'Ширина ' + width);
        }
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await expect(page.locator('#qr-preview')).toBeVisible();
        await page.evaluate(() => document.documentElement.classList.remove('dark'));
    });

    await stage('Переключатели из URL работают; после перезагрузки нет сохранённого Wi-Fi/контактов', async () => {
        await page.goto(origin + '/qr?mode=batch');
        await expect(page.locator('#qr-batch-panel')).toBeVisible();
        await page.goto(origin + '/qr?type=wifi');
        await expect(page.locator('#qr-wifi-ssid')).toBeVisible();
        assert.equal(await page.locator('#qr-wifi-ssid').inputValue(), '');
        assert.equal(await page.locator('#qr-wifi-password').inputValue(), '');
    });

    assert.deepEqual(errors, [], 'JavaScript-ошибки');
    assert.deepEqual(remote, [], 'Внешние запросы');
    assert.deepEqual(writes, [], 'Отправка данных на сервер');
    console.log('QR: ' + stages + ' браузерных сценариев пройдено; внешних запросов и отправки данных нет.');
} catch (error) {
    console.error('ПРОВАЛ QR-стенда:', error.stack || error);
    if (page) {
        console.error('Ошибка интерфейса:', await page.locator('#qr-error').textContent().catch(() => '—'));
        console.error('Сканирование:', await page.locator('#qr-scan-status').textContent().catch(() => '—'));
        await page.screenshot({ path: process.env.QR_SCREENSHOT || '/tmp/nj510-failure.png', fullPage: true }).catch(() => {});
    }
    process.exitCode = 1;
} finally {
    if (browser) await browser.close();
    await new Promise((resolve) => server.close(resolve));
}
