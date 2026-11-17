/* Избранное — основной способ добавления сервисов в короткое меню. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../public_html/assets/js/navpalette.js', import.meta.url), 'utf8');
const catalog = {
    items: [
        { href: '/inbox', title: 'Входящие документы', icon: 'fas fa-inbox', group: 'Документооборот', status: 'stable' },
        { href: '/documents', title: 'Реестр входящих', icon: 'fas fa-folder', group: 'Документооборот', status: 'stable' },
    ],
    groups: [],
};

async function page({ path = '/inbox', pinned = [], hidden = [], card = '', data = catalog, cached = null, fetchImpl = null } = {}) {
    const dom = new JSDOM(`<!doctype html><html><body>
        <aside id="sidebar">
            <input id="nav-search"><button id="nav-palette-open">Все</button>
            <nav id="sidebar-nav"><div class="sidebar-group"><a class="sidebar-item" href="/services">Все сервисы</a></div></nav>
        </aside>${card}
    </body></html>`, { url: 'https://portal.test' + path, runScripts: 'outside-only', pretendToBeVisual: true });
    const { window } = dom;
    window.localStorage.setItem('prx.nav.v1', JSON.stringify({ pinned, hidden, recents: [] }));
    if (cached) window.localStorage.setItem('prx.palette.v1', JSON.stringify(cached));
    window.catalogFetches = 0;
    window.fetch = async (...args) => {
        window.catalogFetches++;
        return fetchImpl ? fetchImpl(...args) : { ok: true, json: async () => data };
    };
    window.eval(source);
    if (window.document.readyState === 'loading') {
        await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    }
    await new Promise(resolve => setImmediate(resolve));
    return dom;
}

function click(window, element) {
    assert.ok(element);
    element.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
}

test('закрепление и открепление из палитры сразу обновляют избранное', async () => {
    const dom = await page();
    const { window } = dom;
    const doc = window.document;
    click(window, doc.getElementById('nav-palette-open'));
    await new Promise(resolve => setImmediate(resolve));

    const firstRow = [...doc.querySelectorAll('.palette-item')].find(row => row.querySelector('a').getAttribute('href') === '/inbox');
    assert.ok(firstRow);
    assert.equal(firstRow.querySelector('a button'), null, 'кнопка не вложена в ссылку');
    click(window, firstRow.querySelector('button'));

    const group = doc.querySelector('[data-nav-group="pinned"]');
    const link = group.querySelector('a[href="/inbox"]');
    assert.ok(link, 'сервис появился без перезагрузки');
    assert.equal(link.textContent.trim(), 'Входящие документы');
    assert.ok(link.classList.contains('is-active'));
    assert.equal(link.getAttribute('aria-current'), 'page');
    assert.equal(link.querySelector('button'), null);
    assert.equal(group.querySelector('button').getAttribute('aria-pressed'), 'true');
    assert.deepEqual(JSON.parse(window.localStorage.getItem('prx.nav.v1')).pinned, ['/inbox']);

    click(window, group.querySelector('button'));
    assert.equal(doc.querySelector('[data-nav-group="pinned"] a'), null);
    assert.match(doc.querySelector('[data-nav-group="pinned"]').textContent, /Добавляйте сервисы/);
    assert.deepEqual(JSON.parse(window.localStorage.getItem('prx.nav.v1')).pinned, []);
    dom.window.close();
});

test('старые скрытые пункты остаются доступны в палитре полного каталога', async () => {
    const dom = await page({ hidden: ['/inbox'] });
    const { window } = dom;
    click(window, window.document.getElementById('nav-palette-open'));
    await new Promise(resolve => setImmediate(resolve));
    assert.ok(window.document.querySelector('.palette-item a[href="/inbox"]'));
    assert.deepEqual(JSON.parse(window.localStorage.getItem('prx.nav.v1')).hidden, ['/inbox']);
    dom.window.close();
});

test('избранное восстанавливается из каталога и подсвечивает вложенную страницу', async () => {
    const dom = await page({ path: '/documents/42', pinned: ['/documents', '/unavailable', '/services'] });
    const group = dom.window.document.querySelector('[data-nav-group="pinned"]');
    const link = group.querySelector('a[href="/documents"]');
    assert.ok(link);
    assert.ok(link.classList.contains('is-active'));
    assert.equal(link.getAttribute('aria-current'), 'location');
    assert.equal(group.querySelector('a[href="/unavailable"]'), null);
    assert.equal(group.querySelector('a[href="/services"]'), null);
    dom.window.close();
});

test('звёздочка карточки обновляет избранное и фильтр, стрелки палитры не выбрасывают исключение', async () => {
    const dom = await page({
        path: '/services',
        card: '<button class="nav-pin-card" data-nav-pin-card="/documents" data-nav-pin-title="Реестр входящих" data-nav-pin-icon="fas fa-folder">⋆</button>',
    });
    const { window } = dom;
    const doc = window.document;
    let filterUpdates = 0;
    const filter = doc.getElementById('nav-search');
    filter.value = 'реестр';
    filter.addEventListener('input', () => filterUpdates++);
    click(window, doc.querySelector('.nav-pin-card'));
    assert.ok(doc.querySelector('[data-nav-group="pinned"] a[href="/documents"]'));
    assert.equal(filterUpdates, 1);
    assert.equal(doc.querySelector('.nav-pin-card').getAttribute('aria-pressed'), 'true');
    assert.equal(doc.querySelector('.nav-pin-card').getAttribute('aria-label'), 'Убрать из Избранного: Реестр входящих');
    assert.equal(doc.querySelector('.nav-pin-card').classList.contains('opacity-0'), false);

    click(window, doc.getElementById('nav-palette-open'));
    await new Promise(resolve => setImmediate(resolve));
    const input = doc.querySelector('#prx-nav-palette-dialog input');
    input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    assert.ok(doc.querySelector('.palette-item.bg-blue-50'));
    dom.window.close();
});

test('каталог запрашивается заново для текущей сессии, старый кэш не открывает скрытые сервисы', async () => {
    const dom = await page({
        pinned: ['/inbox'],
        cached: { ts: Date.now(), ...catalog },
        data: { items: [], groups: [] },
    });
    const { window } = dom;
    assert.equal(window.catalogFetches, 1, 'устаревший localStorage-каталог не используется');
    assert.equal(window.document.querySelector('[data-nav-group="pinned"] a[href="/inbox"]'), null);
    assert.match(window.document.querySelector('[data-nav-group="pinned"]').textContent, /недоступны текущему пользователю/);
    click(window, window.document.getElementById('nav-palette-open'));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(window.catalogFetches, 1, 'повторный запрос в пределах той же страницы не нужен');
    assert.equal(window.document.querySelector('.palette-item'), null);
    dom.window.close();
});

test('избранное выбирает наиболее точный маршрут и учитывает алиасы из каталога', async () => {
    const data = { ...catalog, items: [
        ...catalog.items,
        { href: '/documents/settings', title: 'Настройки', icon: 'fas fa-gear', group: 'Документы', nav_paths: ['/document-settings'] },
    ] };
    for (const path of ['/documents/settings/detail', '/document-settings/42']) {
        const dom = await page({ path, data, pinned: ['/documents', '/documents/settings'] });
        const links = dom.window.document.querySelectorAll('[data-nav-group="pinned"] a');
        assert.equal([...links].filter(link => link.classList.contains('is-active')).length, 1);
        assert.ok(dom.window.document.querySelector('a[href="/documents/settings"]').classList.contains('is-active'));
        dom.window.close();
    }
});

test('ошибка каталога показывается и следующий запрос может восстановиться', async () => {
    let attempts = 0;
    const dom = await page({ fetchImpl: async () => ++attempts === 1
        ? { ok: false }
        : { ok: true, json: async () => catalog } });
    const { window } = dom;
    const doc = window.document;
    click(window, doc.getElementById('nav-palette-open'));
    await new Promise(resolve => setImmediate(resolve));
    assert.match(doc.getElementById('nav-palette-empty').textContent, /Не удалось загрузить каталог/);
    doc.querySelector('#prx-nav-palette-dialog input').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    click(window, doc.getElementById('nav-palette-open'));
    await new Promise(resolve => setImmediate(resolve));
    assert.ok(doc.querySelector('.palette-item a[href="/inbox"]'));
    assert.equal(attempts, 2);
    dom.window.close();
});

test('закрепление в другой вкладке обновляет избранное', async () => {
    const dom = await page();
    const { window } = dom;
    const value = JSON.stringify({ pinned: ['/inbox'], hidden: [], recents: [] });
    window.localStorage.setItem('prx.nav.v1', value);
    window.dispatchEvent(new window.StorageEvent('storage', { key: 'prx.nav.v1', newValue: value }));
    await new Promise(resolve => setImmediate(resolve));
    assert.ok(window.document.querySelector('[data-nav-group="pinned"] a[href="/inbox"]'));
    assert.equal(window.catalogFetches, 1);
    dom.window.close();
});
