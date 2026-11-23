/**
 * CSRF на клиенте: app.js ставит X-CSRF-Token на каждый небезопасный запрос
 * своего origin (серверная проверка — Prx_PostGuard, docs/FOUNDATION.md §4).
 *
 *   node --test tests/Js/prx_csrf.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../public_html/assets/js/app.js', import.meta.url), 'utf8');

function createPage(token = 'tok-123') {
    const dom = new JSDOM('<!doctype html><html><head>'
        + (token ? `<meta name="csrf-token" content="${token}">` : '')
        + '</head><body></body></html>', {
        runScripts: 'outside-only',
        pretendToBeVisual: true,
        url: 'https://portal.example/',
    });
    const { window } = dom;

    // jsdom не даёт fetch/Request/Response — берём серверные из Node.
    window.Headers = Headers;
    window.Request = Request;
    window.Response = Response;

    const calls = [];
    window.fetch = (input, init) => {
        const headers = new Headers((init && init.headers) || (input && input.headers) || {});
        calls.push({
            url: typeof input === 'string' ? input : input.url,
            method: ((init && init.method) || (input && input.method) || 'GET').toUpperCase(),
            csrf: headers.get('X-CSRF-Token'),
            hasBody: Boolean((init && init.body) || (input && input.body)),
        });
        return Promise.resolve(new Response('{}', { status: 200 }));
    };

    const xhrHeaders = [];
    window.XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
        xhrHeaders.push([name, value]);
    };
    window.XMLHttpRequest.prototype.send = function () { /* сеть не нужна */ };

    window.eval(source);

    const to = (url) => calls.filter((call) => call.url === url);

    return { dom, window, calls, xhrHeaders, to };
}

test('fetch POST своего origin получает X-CSRF-Token из meta', async () => {
    const { window, to, dom } = createPage('tok-123');
    await window.fetch('/prx597/api/read', { method: 'POST', body: 'id=1' });

    const [call] = to('/prx597/api/read');
    assert.equal(call.method, 'POST');
    assert.equal(call.csrf, 'tok-123');
    assert.equal(call.hasBody, true);
    dom.window.close();
});

test('GET не получает токен, чужой origin — тоже', async () => {
    const { window, to, dom } = createPage('tok-123');
    await window.fetch('/search/api?q=1');
    await window.fetch('https://evil.test/collect', { method: 'POST' });

    assert.equal(to('/search/api?q=1')[0].csrf, null);
    assert.equal(to('https://evil.test/collect')[0].csrf, null);
    dom.window.close();
});

test('Request на входе сохраняет body и получает заголовок', async () => {
    const { window, to, dom } = createPage('tok-123');
    await window.fetch(new Request('https://portal.example/prx212_link/create', {
        method: 'POST',
        body: 'file_id=1&ttl=0',
        keepalive: true,
    }));

    const [call] = to('https://portal.example/prx212_link/create');
    assert.equal(call.csrf, 'tok-123');
    assert.equal(call.hasBody, true);
    dom.window.close();
});

test('без meta токена заголовок не ставится (гость: токен в форме)', async () => {
    const { window, to, dom } = createPage('');
    await window.fetch('/auth/login', { method: 'POST', body: 'a=1' });

    assert.equal(to('/auth/login')[0].csrf, null);
    assert.equal(window.PRX.csrfToken(), '');
    dom.window.close();
});

test('XMLHttpRequest тоже получает токен', () => {
    const { window, xhrHeaders, dom } = createPage('tok-123');
    const xhr = new window.XMLHttpRequest();
    xhr.open('POST', '/prx218/api/update_sort_order');
    xhr.send('id=1');

    assert.deepEqual(xhrHeaders, [['X-CSRF-Token', 'tok-123']]);
    dom.window.close();
});
