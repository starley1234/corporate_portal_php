import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const script = readFileSync(new URL('../../public_html/assets/js/navpalette.js', import.meta.url), 'utf8');

async function setup(pinned = [], hidden = []) {
    const dom = new JSDOM(`<aside id="sidebar"><nav id="sidebar-nav"><div class="sidebar-group"><ul>
        ${['services', 'trends', 'jobs', 'graph3d'].map(slug => `<li><a class="sidebar-item" href="/${slug}"><span>${slug}</span><button class="nav-pin" data-nav-pin="/${slug}"></button></a></li>`).join('')}
        </ul></div></nav></aside>`, { url: 'https://n2j.ru/jobs', runScripts: 'outside-only' });
    const { window } = dom;
    await new Promise(resolve => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
    window.localStorage.setItem('n2j.nav.v1', JSON.stringify({ pinned, hidden, recents: [] }));
    window.fetch = async () => ({ json: async () => ({ items: [
        { href: '/vacancies', title: 'Вакансии компаний', icon: 'fas fa-building' }
    ] }) });
    window.eval(script);
    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
    await new Promise(resolve => setImmediate(resolve));
    return dom;
}

test('пустое Избранное остаётся первым блоком с подсказкой', async () => {
    const dom = await setup();
    try {
        const group = dom.window.document.querySelector('nav').firstElementChild;
        assert.equal(group.dataset.navGroup, 'pinned');
        assert.match(group.textContent, /Избранное/);
        assert.match(group.textContent, /звёздочкой/);
        assert.equal(group.querySelectorAll('a').length, 0);
    } finally { dom.window.close(); }
});

test('старое Избранное вне короткого меню восстанавливается из каталога', async () => {
    const dom = await setup(['/vacancies', '/trends'], ['/jobs']);
    try {
        const doc = dom.window.document;
        const group = doc.querySelector('[data-nav-group="pinned"]');
        assert.deepEqual([...group.querySelectorAll('a')].map(a => a.getAttribute('href')), ['/vacancies', '/trends']);
        assert.match(group.textContent, /Вакансии компаний/);
        assert.equal(group.querySelectorAll('ul > li > a').length, 2);
        assert.equal(doc.querySelector('a[href="/jobs"]').classList.contains('nav-hidden'), false);
    } finally { dom.window.close(); }
});

test('снятие последней звёздочки очищает Избранное без перезагрузки', async () => {
    const dom = await setup(['/vacancies']);
    try {
        const doc = dom.window.document;
        doc.querySelector('[data-nav-group="pinned"] button').click();
        const group = doc.querySelector('[data-nav-group="pinned"]');
        assert.equal(group.querySelectorAll('a').length, 0);
        assert.match(group.textContent, /звёздочкой/);
        assert.deepEqual(JSON.parse(dom.window.localStorage.getItem('n2j.nav.v1')).pinned, []);
    } finally { dom.window.close(); }
});
