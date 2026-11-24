import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../public_html/assets/js/portal-plan.js', import.meta.url), 'utf8');
const searchLayout = readFileSync(new URL('../../public_html/assets/js/search-layout.js', import.meta.url), 'utf8');
const row = `<li data-portal-plan-item="Распознавание PDF сканы" data-portal-plan-module="prx395_document_extract" tabindex="-1">
    <form method="post" action="/portal-plan/approve" data-portal-plan-action>
        <input name="csrf" value="test-csrf" type="hidden">
        <input name="module" value="prx395_document_extract" type="hidden">
        <input name="state" value="approved" type="hidden">
        <input name="return_state" value="wip" type="hidden">
        <button type="submit">Одобрить</button>
    </form>
</li>`;
const root = (body) => `<div data-portal-plan-content>${body}</div>`;
const filter = (count) => `<a href="/portal-plan?state=wip" aria-current="page">В работе (${count})</a>`;
const success = (body) => ({ ok: true, text: async () => root(`<p data-portal-plan-message>Сервис одобрен.</p>${body}`) });
const settle = () => new Promise((done) => setImmediate(done));

const group = (name, rows) => `<section data-portal-plan-group="${name}"><ul>${rows}</ul></section>`;
const otherRow = '<li data-portal-plan-item="Расписание встреч">Встречи</li>';
const searchBox = `<div data-service-search hidden>
    <input id="portal-plan-search" type="search"><p id="portal-plan-search-empty" hidden>Ничего не найдено</p>
</div>`;

function page(t, fetch) {
    const body = `${filter(1)}${group('Документы', row)}${group('Календарь', otherRow)}`;
    const dom = new JSDOM(`<header>План портала</header>${searchBox}${root(body)}`, {
        url: 'https://portal.example.test/portal-plan?state=wip', runScripts: 'outside-only',
    });
    const { window } = dom;
    t.after(() => window.close());
    const { document } = window;
    const toasts = [];
    const scrolls = [];
    window.fetch = fetch;
    window.scrollBy = (...args) => scrolls.push(args);
    window.scrollTo = (...args) => scrolls.push(args);
    window.PRX = { busy(button, on) { button.disabled = on; }, toast(...args) { toasts.push(args); } };
    window.eval(searchLayout);
    window.eval(source);
    const submit = () => {
        const form = document.querySelector('[data-portal-plan-action]');
        const button = form.querySelector('button');
        button.focus();
        return form.dispatchEvent(new window.SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: button }));
    };
    return { window, document, submit, toasts, scrolls };
}

test('submits CSRF and the current filter once, then replaces only the plan without navigating', async (t) => {
    const requests = [];
    let resolve;
    const p = page(t, (url, opts) => {
        requests.push([url, opts]);
        return new Promise((done) => { resolve = done; });
    });
    const header = p.document.querySelector('header');
    assert.equal(p.submit(), false);
    assert.equal(p.document.querySelector('button').disabled, true);
    assert.equal(p.submit(), false);
    assert.equal(requests.length, 1);
    const [url, opts] = requests[0];
    assert.equal(url, 'https://portal.example.test/portal-plan/approve');
    assert.equal(opts.method, 'POST');
    assert.equal(opts.mode, 'same-origin');
    assert.deepEqual(Object.fromEntries(opts.body), {
        csrf: 'test-csrf', module: 'prx395_document_extract', state: 'approved', return_state: 'wip',
    });
    resolve(success(`${filter(0)}<p>Нет сервисов в этом состоянии</p>`));
    await settle();
    assert.equal(p.window.location.search, '?state=wip');
    assert.equal(p.document.querySelector('header'), header);
    assert.equal(p.document.querySelector('form'), null);
    assert.equal(p.document.activeElement.textContent, 'В работе (0)');
    assert.deepEqual(p.scrolls, []);
    assert.deepEqual(p.toasts, [['Сервис одобрен.', 0, 'ok']]);
});

test('keeps a surviving row at the same viewport position and supports a second submit', async (t) => {
    const p = page(t, async () => success(`${filter(1)}<ul>${row}</ul>`));
    const oldRow = p.document.querySelector('li');
    // Detached SSR markup receives its final position only after insertion.
    p.window.HTMLElement.prototype.getBoundingClientRect = function () {
        return { top: this === oldRow ? 400 : 420, bottom: 460 };
    };
    p.submit();
    await settle();
    assert.notEqual(p.document.querySelector('li'), oldRow);
    assert.equal(p.document.activeElement, p.document.querySelector('li'));
    assert.deepEqual(p.scrolls, [[0, 20]]);
    p.submit();
    await settle();
    assert.equal(p.toasts.length, 2);
});

test('keeps the old list and unlocks the form when saving is refused', async (t) => {
    const p = page(t, async () => ({ ok: false, status: 403 }));
    const form = p.document.querySelector('form');
    p.submit();
    await settle();
    assert.equal(p.document.querySelector('form'), form);
    assert.equal(form.querySelector('button').disabled, false);
    assert.equal(p.toasts[0][2], 'err');
    assert.match(p.toasts[0][0], /права доступа/);
});

test('does not replace the plan with a sign-in page returned after redirection', async (t) => {
    const p = page(t, async () => ({ ok: true, text: async () => '<h1>Вход</h1>' }));
    p.submit();
    await settle();
    assert.equal(p.document.querySelector('form').querySelector('button').disabled, false);
    assert.match(p.toasts[0][0], /Не удалось обновить план/);
});


test('searches service keywords and domain names across keyboard layouts and clears on Escape', (t) => {
    const p = page(t, async () => { throw new Error('Search must not fetch'); });
    const input = p.document.querySelector('#portal-plan-search');
    const empty = p.document.querySelector('#portal-plan-search-empty');
    const groups = () => Array.from(p.document.querySelectorAll('[data-portal-plan-group]:not([hidden])'),
        (group) => group.dataset.portalPlanGroup);
    const query = (value) => {
        input.value = value;
        input.dispatchEvent(new p.window.Event('input'));
    };
    assert.equal(input.parentElement.hidden, false);
    query('crfys'); // «сканы» на латинской раскладке — ключевое слово.
    assert.deepEqual(groups(), ['Документы']);
    query('календарь');
    assert.deepEqual(groups(), ['Календарь']);
    query('несуществующий сервис');
    assert.deepEqual(groups(), []);
    assert.equal(empty.hidden, false);
    input.dispatchEvent(new p.window.KeyboardEvent('keydown', { key: 'Escape', cancelable: true }));
    assert.equal(input.value, '');
    assert.deepEqual(groups(), ['Документы', 'Календарь']);
    assert.equal(empty.hidden, true);
});

test('keeps the search active when publication returns a new list and filter counts', async (t) => {
    const p = page(t, async () => success(`${filter(2)}${group('Документы', row + otherRow)}`));
    const input = p.document.querySelector('#portal-plan-search');
    input.value = 'PDF';
    input.dispatchEvent(new p.window.Event('input'));
    p.submit();
    await settle();
    assert.equal(p.document.querySelector('#portal-plan-search'), input);
    assert.equal(input.value, 'PDF');
    assert.equal(p.document.querySelector('[aria-current="page"]').textContent, 'В работе (2)');
    assert.equal(p.document.querySelectorAll('[data-portal-plan-item]:not([hidden])').length, 1);
});
