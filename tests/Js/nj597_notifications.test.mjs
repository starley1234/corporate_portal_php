import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Исполняем настоящий скрипт вьюхи. DOM/HTTP заменены, браузер и сеть не нужны.
const view = readFileSync(new URL('../../application/modules/nj597_notifications/views/597/script.php', import.meta.url), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1];
async function bind(response) {
    let click;
    const opened = [], requests = [];
    const box = { textContent: '', children: [], appendChild(el) { this.children.push(el); } };
    const btn = {
        disabled: false, classList: { add() {}, remove() {} },
        getAttribute(key) { return key === 'data-url' ? 'https://t.me/notify_bot?start=__CODE__' : 'telegram'; },
        addEventListener(event, cb) { click = cb; },
    };
    const document = {
        querySelectorAll(selector) { return selector === '[data-nj597-bind-btn]' ? [btn] : []; },
        querySelector(selector) {
            if (selector.includes('input[name="csrf"]')) return { value: 'csrf-test' };
            if (selector.includes('data-nj597-bind-state')) return box;
            return null;
        },
        getElementById() { return null; }, createElement() { return {}; },
    };
    vm.runInNewContext(script, {
        document, URL, FormData, setTimeout, clearTimeout,
        window: { open(...args) { opened.push(args); } },
        fetch: async (url, options) => { requests.push([url, options]); return { json: async () => response }; },
    });
    click();
    await new Promise(resolve => setImmediate(resolve));
    return { box, btn, opened, requests };
}

test('привязка: старый ответ без expires_at не ломает ссылку', async () => {
    const { box, btn, opened, requests } = await bind({ ok: true, code: 'a1b2c3', url: 'https://t.me/notify_bot?start=a1b2c3' });
    assert.match(box.textContent, /a1b2c3.*15 минут/);
    assert.equal(box.children[0].href, 'https://t.me/notify_bot?start=a1b2c3');
    assert.equal(box.children[0].rel, 'noopener noreferrer');
    assert.equal(opened.length, 1);
    assert.equal(btn.disabled, false);
    assert.equal(requests[0][0], '/nj597/api/bind');
    assert.equal(requests[0][1].body.get('csrf'), 'csrf-test');
    assert.equal(requests[0][1].body.get('channel'), 'telegram');
});
test('привязка: данные API выводятся текстом, а не HTML', async () => {
    const { box } = await bind({ ok: true, code: '<img src=x>', url: 'https://t.me/notify_bot' });
    assert.match(box.textContent, /<img src=x>/);
    assert.equal(box.innerHTML, undefined);
    assert.equal(box.children.length, 1);
});
test('привязка: опасная ссылка не открывается', async () => {
    const { box, btn, opened } = await bind({ ok: true, code: 'abc', url: 'javascript:alert(1)' });
    assert.match(box.textContent, /Неверная ссылка/);
    assert.equal(opened.length, 0);
    assert.equal(btn.disabled, false);
});
test('привязка: ошибка API видна, кнопку можно нажать повторно', async () => {
    const { box, btn, opened } = await bind({ error: 'Канал отключён' });
    assert.match(box.textContent, /Канал отключён/);
    assert.equal(btn.disabled, false);
    assert.equal(opened.length, 0);
});
