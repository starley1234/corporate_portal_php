/* Избранное: сервер — источник истины, первый список рендерится без JS. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const searchSource = readFileSync(new URL('../../public_html/assets/js/search-layout.js', import.meta.url), 'utf8');
const source = readFileSync(new URL('../../public_html/assets/js/navpalette.js', import.meta.url), 'utf8');
const catalog = {
    items: [
        { href: '/inbox', title: 'Входящие документы', icon: 'fas fa-inbox', group: 'Документооборот', status: 'stable', nav_paths: [] },
        { href: '/prx391_documents', title: 'Реестр входящих', icon: 'fas fa-folder', group: 'Документооборот', status: 'stable', nav_paths: ['/document-card'] },
    ],
    groups: [],
};
const tick = () => new Promise(resolve => setImmediate(resolve));
const attr = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const response = (data, status = 200) => ({ ok: status >= 200 && status < 300, status, json: async () => structuredClone(data) });

function makeServer(initial = []) {
    const server = { items: structuredClone(initial), calls: [], failNext: null, userId: 7 };
    server.fetch = async (url, options = {}) => {
        server.calls.push({ url, options });
        const method = options.method || 'GET';
        if (server.failNext && server.failNext.method === method && url.includes(server.failNext.url)) {
            const status = server.failNext.status;
            server.failNext = null;
            return response({ error: 'Ошибка' }, status);
        }
        if (url === '/prx110/api/catalog') return response({ ...catalog, user_id: server.userId });
        if (url === '/prx110/api/favorites' && method === 'GET') return response({ user_id: server.userId, items: server.items });
        if (method === 'POST' && url.startsWith('/prx110/api/favorites')) {
            const body = new URLSearchParams(options.body);
            assert.equal(body.get('csrf'), 'csrf-test');
            if (Number(body.get('account_id')) !== server.userId) return response({ error: 'Аккаунт изменился' }, 409);
            const href = body.get('href');
            if (body.get('pinned') === '1') {
                const item = catalog.items.find(it => it.href === href);
                if (!item) return response({ error: 'Недоступно' }, 422);
                if (!server.items.some(it => it.href === href)) server.items.push(item);
            } else server.items = server.items.filter(it => it.href !== href);
            return response({ user_id: server.userId, items: server.items });
        }
        throw new Error(`unexpected request: ${method} ${url}`);
    };
    return server;
}

async function page({ path = '/inbox', initial = [], card = '', server = makeServer(initial), storageBlocked = false } = {}) {
    const rows = initial.map(item => `<li class="group relative"><a class="sidebar-item${item.active ? ' is-active' : ''}" href="${attr(item.href)}" data-search="${attr(item.title.toLowerCase())}">${attr(item.title)}</a><button class="nav-pin" data-nav-pin="${attr(item.href)}" aria-pressed="true">★</button></li>`).join('');
    const dom = new JSDOM(`<!doctype html><html><body>
        <aside id="sidebar" data-nav-status="ready" data-nav-user-id="7" data-nav-csrf="csrf-test" data-nav-items="${attr(JSON.stringify(initial))}">
            <input id="nav-search"><button id="nav-palette-open">Все</button>
            <nav id="sidebar-nav"><div class="sidebar-group" data-nav-group="pinned">
                <div>Избранное</div><ul data-nav-pinned-list>${rows || '<li data-nav-pinned-empty>Добавляйте сервисы звёздочкой в каталоге</li>'}</ul>
                <div id="nav-favorite-status" class="hidden" role="status"></div>
            </div><div class="sidebar-group"><a class="sidebar-item" href="/services">Все сервисы</a></div></nav>
        </aside>${card}
    </body></html>`, { url: 'https://portal.test' + path, runScripts: 'outside-only', pretendToBeVisual: true });
    const { window } = dom;
    if (storageBlocked) Object.defineProperty(window, 'localStorage', { value: {
        getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); },
    } });
    const originalLink = window.document.querySelector('[data-nav-group="pinned"] a');
    window.fetch = server.fetch;
    window.eval(searchSource);
    window.eval(source);
    if (window.document.readyState === 'loading') {
        await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    }
    await tick();
    return { dom, window, doc: window.document, server, originalLink };
}

function click(window, element) {
    assert.ok(element);
    element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
}

test('первый HTML с Избранным не заменяется и не ждёт запроса', async () => {
    const item = { ...catalog.items[0], active: true };
    const p = await page({ initial: [item] });
    assert.equal(p.originalLink, p.doc.querySelector('[data-nav-group="pinned"] a'));
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a').textContent, 'Входящие документы');
    assert.equal(p.server.calls.length, 0);
    assert.doesNotMatch(p.doc.body.textContent, /Загрузка Избранного/);
    p.dom.window.close();
});

test('звезда в палитре сохраняет и удаляет закладку в аккаунте', async () => {
    const p = await page();
    click(p.window, p.doc.getElementById('nav-palette-open'));
    await tick();
    const row = [...p.doc.querySelectorAll('.palette-item')].find(el => el.querySelector('a').getAttribute('href') === '/inbox');
    assert.equal(row.querySelector('a button'), null);
    click(p.window, row.querySelector('button'));
    await tick();
    assert.deepEqual(p.server.items.map(it => it.href), ['/inbox']);
    const pinRequest = p.server.calls.find(call => call.options.method === 'POST');
    assert.equal(new URLSearchParams(pinRequest.options.body).get('pinned'), '1');
    assert.equal(pinRequest.options.credentials, 'same-origin');
    assert.equal(p.window.localStorage.getItem('prx.nav.v1'), null);
    const group = p.doc.querySelector('[data-nav-group="pinned"]');
    assert.equal(group.querySelector('a[href="/inbox"]').getAttribute('aria-current'), 'page');
    assert.equal(group.querySelector('a button'), null);
    click(p.window, group.querySelector('button'));
    await tick();
    assert.deepEqual(p.server.items, []);
    assert.match(group.textContent, /Добавляйте сервисы/);
    p.dom.window.close();
});

test('карточка обновляется после подтверждения сервера и учитывает фильтр', async () => {
    const p = await page({ path: '/services', card: '<button class="nav-pin-card" data-nav-pin-card="/prx391_documents" data-nav-pin-title="Реестр входящих" data-nav-pin-icon="fas fa-folder">⋆</button>' });
    let filterUpdates = 0;
    p.doc.getElementById('nav-search').value = 'реестр';
    p.doc.getElementById('nav-search').addEventListener('input', () => filterUpdates++);
    click(p.window, p.doc.querySelector('.nav-pin-card'));
    await tick();
    assert.ok(p.doc.querySelector('[data-nav-group="pinned"] a[href="/prx391_documents"]'));
    assert.equal(filterUpdates, 1);
    assert.equal(p.doc.querySelector('.nav-pin-card').getAttribute('aria-pressed'), 'true');
    assert.equal(p.doc.querySelector('.nav-pin-card').getAttribute('aria-label'), 'Убрать из Избранного: Реестр входящих');
    p.dom.window.close();
});

test('ошибка сохранения не выдаётся за успех и не меняет Избранное', async () => {
    const p = await page({ card: '<button class="nav-pin-card" data-nav-pin-card="/inbox" data-nav-pin-title="Входящие документы">⋆</button>' });
    p.server.failNext = { method: 'POST', url: '/favorites', status: 503 };
    click(p.window, p.doc.querySelector('.nav-pin-card'));
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a[href="/inbox"]'), null);
    assert.equal(p.doc.querySelector('.nav-pin-card').getAttribute('aria-pressed'), 'false');
    assert.match(p.doc.getElementById('nav-favorite-status').textContent, /Не удалось подтвердить/);
    assert.deepEqual(p.server.items, []);
    p.dom.window.close();
});

test('повторное чтение без изменений сохраняет исходный DOM и фокус', async () => {
    const p = await page({ initial: [catalog.items[0]] });
    p.originalLink.focus();
    p.window.dispatchEvent(new p.window.StorageEvent('storage', {
        key: 'prx.nav.favorites.changed.v1', newValue: JSON.stringify({ user_id: 7, nonce: 'same' }),
    }));
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), p.originalLink);
    assert.equal(p.doc.activeElement, p.originalLink);
    p.dom.window.close();
});

test('закрепление другого окна перечитывается с сервера, а не из localStorage', async () => {
    const p = await page({ path: '/document-card/42' });
    p.server.items = [catalog.items[1]];
    p.window.dispatchEvent(new p.window.StorageEvent('storage', {
        key: 'prx.nav.favorites.changed.v1', newValue: JSON.stringify({ user_id: 7, nonce: 'n' }),
    }));
    await tick();
    const link = p.doc.querySelector('[data-nav-group="pinned"] a[href="/prx391_documents"]');
    assert.ok(link);
    assert.equal(link.getAttribute('aria-current'), 'location');
    assert.equal(p.server.calls[0].url, '/prx110/api/favorites');
    p.dom.window.close();
});

test('запоздавший ответ другой вкладки не затирает новое сохранение', async () => {
    const server = makeServer();
    const realFetch = server.fetch;
    let release;
    let delayed = false;
    server.fetch = (url, options = {}) => {
        if (url === '/prx110/api/favorites' && !options.method && !delayed) {
            delayed = true;
            server.calls.push({ url, options });
            return new Promise(resolve => { release = () => resolve(response({ user_id: 7, items: [] })); });
        }
        return realFetch(url, options);
    };
    const p = await page({ server, card: '<button class="nav-pin-card" data-nav-pin-card="/inbox" data-nav-pin-title="Входящие документы">⋆</button>' });
    p.window.dispatchEvent(new p.window.StorageEvent('storage', {
        key: 'prx.nav.favorites.changed.v1', newValue: JSON.stringify({ user_id: 7, nonce: 'old' }),
    }));
    click(p.window, p.doc.querySelector('.nav-pin-card'));
    await tick();
    assert.ok(p.doc.querySelector('[data-nav-group="pinned"] a[href="/inbox"]'));
    release();
    await tick();
    await tick();
    assert.ok(p.doc.querySelector('[data-nav-group="pinned"] a[href="/inbox"]'));
    assert.equal(server.calls.filter(call => call.url === '/prx110/api/favorites' && !call.options.method).length, 2);
    p.dom.window.close();
});

test('аккаунт не зависит от доступности localStorage', async () => {
    const p = await page({ storageBlocked: true, card: '<button class="nav-pin-card" data-nav-pin-card="/inbox" data-nav-pin-title="Входящие документы">⋆</button>' });
    click(p.window, p.doc.querySelector('.nav-pin-card'));
    await tick();
    assert.deepEqual(p.server.items.map(it => it.href), ['/inbox']);
    assert.ok(p.doc.querySelector('[data-nav-group="pinned"] a[href="/inbox"]'));
    p.dom.window.close();
});

test('устаревшая вкладка не меняет другой аккаунт и прячет прежние закладки и каталог', async () => {
    const p = await page({ initial: [catalog.items[1]], card: '<button class="nav-pin-card" data-nav-pin-card="/inbox" data-nav-pin-title="Входящие документы">⋆</button>' });
    click(p.window, p.doc.getElementById('nav-palette-open'));
    await tick();
    assert.ok(p.doc.querySelector('.palette-item'));
    p.server.userId = 8;
    click(p.window, p.doc.querySelector('.nav-pin-card'));
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    assert.equal(p.doc.querySelector('.palette-item'), null, 'старый каталог тоже скрыт');
    assert.match(p.doc.getElementById('nav-favorite-status').textContent, /аккаунт изменились/i);
    assert.equal(p.doc.querySelector('.nav-pin-card').disabled, true);
    assert.deepEqual(p.server.items.map(it => it.href), ['/prx391_documents']);
    p.dom.window.close();
});

test('запрос каталога другого аккаунта закрывает устаревшую вкладку без показа его данных', async () => {
    const p = await page({ initial: [catalog.items[1]], card: '<button class="nav-pin-card" data-nav-pin-card="/inbox">⋆</button>' });
    p.server.userId = 8;
    click(p.window, p.doc.getElementById('nav-palette-open'));
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    assert.equal(p.doc.querySelector('.palette-item'), null);
    assert.equal(p.doc.querySelector('.nav-pin-card').disabled, true);
    assert.match(p.doc.getElementById('nav-favorite-status').textContent, /аккаунт изменились/i);
    p.dom.window.close();
});

test('сигнал другого аккаунта проверяется сервером, а не подставляется во вкладку', async () => {
    const p = await page({ initial: [catalog.items[1]] });
    p.server.userId = 8;
    p.window.dispatchEvent(new p.window.StorageEvent('storage', {
        key: 'prx.nav.favorites.changed.v1', newValue: JSON.stringify({ user_id: 8, nonce: 'new-account' }),
    }));
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    assert.match(p.doc.getElementById('nav-favorite-status').textContent, /аккаунт изменились/i);
    p.dom.window.close();
});

test('запоздавший ответ каталога не возвращает элементы после смены аккаунта', async () => {
    const server = makeServer([catalog.items[1]]);
    const realFetch = server.fetch;
    let releaseCatalog;
    server.fetch = (url, options = {}) => {
        if (url === '/prx110/api/catalog') {
            return new Promise(resolve => { releaseCatalog = () => resolve(response({ ...catalog, user_id: 7 })); });
        }
        return realFetch(url, options);
    };
    const p = await page({ initial: [catalog.items[1]], server });
    click(p.window, p.doc.getElementById('nav-palette-open'));
    server.userId = 8;
    p.window.dispatchEvent(new p.window.StorageEvent('storage', {
        key: 'prx.nav.favorites.changed.v1', newValue: JSON.stringify({ user_id: 8, nonce: 'changed' }),
    }));
    await tick();
    assert.match(p.doc.getElementById('nav-favorite-status').textContent, /аккаунт изменились/i);
    releaseCatalog();
    await tick();
    assert.equal(p.doc.querySelector('.palette-item'), null);
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    p.dom.window.close();
});

test('запоздавшее успешное сохранение не восстанавливает список после выхода', async () => {
    const server = makeServer([catalog.items[1]]);
    let releaseRefresh, releaseSave;
    server.fetch = (url, options = {}) => {
        if (url === '/prx110/api/favorites' && !options.method) {
            return new Promise(resolve => { releaseRefresh = () => resolve(response({ error: 'auth' }, 401)); });
        }
        if (url === '/prx110/api/favorites' && options.method === 'POST') {
            return new Promise(resolve => { releaseSave = () => resolve(response({ user_id: 7, items: catalog.items })); });
        }
        throw new Error('unexpected request');
    };
    const p = await page({ initial: [catalog.items[1]], server, card: '<button class="nav-pin-card" data-nav-pin-card="/inbox">⋆</button>' });
    p.window.dispatchEvent(new p.window.StorageEvent('storage', {
        key: 'prx.nav.favorites.changed.v1', newValue: JSON.stringify({ user_id: 7, nonce: 'check' }),
    }));
    click(p.window, p.doc.querySelector('.nav-pin-card'));
    releaseRefresh();
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    releaseSave();
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    assert.equal(p.doc.querySelector('.nav-pin-card').disabled, true);
    assert.match(p.doc.getElementById('nav-favorite-status').textContent, /аккаунт изменились/i);
    p.dom.window.close();
});

test('запоздавшее чтение не восстанавливает список после ошибки сессии при записи', async () => {
    const server = makeServer([catalog.items[1]]);
    let releaseRefresh;
    server.fetch = (url, options = {}) => {
        if (url === '/prx110/api/favorites' && !options.method) {
            return new Promise(resolve => { releaseRefresh = () => resolve(response({ user_id: 7, items: catalog.items })); });
        }
        if (url === '/prx110/api/favorites' && options.method === 'POST') return Promise.resolve(response({ error: 'changed' }, 409));
        throw new Error('unexpected request');
    };
    const p = await page({ initial: [catalog.items[1]], server, card: '<button class="nav-pin-card" data-nav-pin-card="/inbox">⋆</button>' });
    p.window.dispatchEvent(new p.window.StorageEvent('storage', {
        key: 'prx.nav.favorites.changed.v1', newValue: JSON.stringify({ user_id: 7, nonce: 'check' }),
    }));
    click(p.window, p.doc.querySelector('.nav-pin-card'));
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    releaseRefresh();
    await tick();
    assert.equal(p.doc.querySelector('[data-nav-group="pinned"] a'), null);
    assert.match(p.doc.getElementById('nav-favorite-status').textContent, /аккаунт изменились/i);
    p.dom.window.close();
});

test('ошибка каталога отображается и повторное открытие восстанавливается', async () => {
    const p = await page();
    p.server.failNext = { method: 'GET', url: '/catalog', status: 503 };
    click(p.window, p.doc.getElementById('nav-palette-open'));
    await tick();
    assert.match(p.doc.getElementById('nav-palette-empty').textContent, /Не удалось загрузить каталог/);
    const input = p.doc.querySelector('#prx-nav-palette-dialog input');
    input.dispatchEvent(new p.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    click(p.window, p.doc.getElementById('nav-palette-open'));
    await tick();
    assert.ok(p.doc.querySelector('.palette-item a[href="/inbox"]'));
    input.dispatchEvent(new p.window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    assert.ok(p.doc.querySelector('.palette-item.bg-blue-50'));
    p.dom.window.close();
});


test('поиск палитры понимает английскую раскладку', async () => {
    const p = await page();
    click(p.window, p.doc.getElementById('nav-palette-open'));
    await tick();
    const input = p.doc.querySelector('input[aria-label="Поиск по всем сервисам"]');
    input.value = String.raw`d\[jzobt`;
    input.dispatchEvent(new p.window.Event('input', { bubbles: true }));
    assert.ok(p.doc.querySelector('.palette-item a[href="/inbox"]'));
    p.dom.window.close();
});
