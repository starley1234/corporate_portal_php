import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const root = new URL('../../', import.meta.url);
const partial = readFileSync(new URL('application/views/partials/live_status_js.php', root), 'utf8');
const script = partial.split('<script>')[1].split('</script>')[0];

function page() {
    const dom = new JSDOM(`<div data-prx-live data-live-url="/prx391_documents/progress/1" data-live-status="queued" data-live-elapsed="0" data-live-keep>
        <i class="fas"></i><span data-live-title>В очереди</span><span data-live-elapsed>0:00</span>
        <p data-live-stage>Ожидание</p><p data-live-note></p><ol data-live-steps></ol>
        <span data-live-silent class="hidden"></span></div>`, {
        runScripts: 'outside-only', url: 'https://portal.example/prx391_documents/1',
    });
    const { window } = dom;
    const timers = [];
    window.setTimeout = (fn, ms) => timers.push({ fn, ms, repeat: false });
    window.setInterval = (fn, ms) => timers.push({ fn, ms, repeat: true });
    const requests = [];
    let reply = { status: 'running', stage: 'Этап 2 из 3 · Модель думает', elapsed_sec: 10 };
    window.fetch = async (url, options) => {
        requests.push({ url, options });
        return { ok: true, json: async () => reply };
    };
    window.eval(script);
    return { dom, window, timers, requests, reply: (value) => { reply = value; } };
}

async function runTimer(p, ms) {
    const timer = p.timers.find(t => t.ms === ms);
    assert.ok(timer, `timer ${ms} registered`);
    timer.fn();
    await new Promise(resolve => setImmediate(resolve));
}

test('document page includes poller after process markup in bounded sidebar', () => {
    const view = readFileSync(new URL('application/modules/prx391_documents/views/prx391/show.php', root), 'utf8');
    assert.ok(view.indexOf('<aside') < view.indexOf("/partials/process.php"));
    assert.ok(view.indexOf("/partials/process.php") < view.indexOf("partials/live_status_js"));
    assert.match(view, /max-w-md/);
});

test('SSR process ticks, polls authenticated endpoint and updates title and stage', async () => {
    const p = page();
    try {
        await runTimer(p, 1000);
        assert.equal(p.window.document.querySelector('span[data-live-elapsed]').textContent, '0:01');
        await runTimer(p, 2000);
        assert.equal(p.requests[0].url, '/prx391_documents/progress/1');
        assert.equal(p.requests[0].options.credentials, 'same-origin');
        assert.equal(p.window.document.querySelector('[data-live-title]').textContent, 'В работе');
        assert.equal(p.window.document.querySelector('[data-live-stage]').textContent, 'Этап 2 из 3 · Модель думает');
        assert.equal(p.window.document.querySelector('span[data-live-elapsed]').textContent, '0:10');
        p.reply({ status: 'done', elapsed_sec: 12 });
        await runTimer(p, 3000);
        assert.equal(p.window.document.querySelector('[data-live-title]').textContent, 'Готово');
        await runTimer(p, 1000);
        assert.equal(p.window.document.querySelector('span[data-live-elapsed]').textContent, '0:12');
    } finally { p.dom.window.close(); }
});

test('polling failure retries rather than disabling stopwatch', async () => {
    const p = page();
    try {
        p.window.fetch = async () => { throw new Error('offline'); };
        await runTimer(p, 2000);
        await runTimer(p, 1000);
        assert.equal(p.window.document.querySelector('span[data-live-elapsed]').textContent, '0:01');
        assert.ok(p.timers.some(t => t.ms === 3000));
    } finally { p.dom.window.close(); }
});
