/** NJ-668 «Плоские иллюстрации»: поллинг /draw2d/api/status в jsdom.
 *
 *   node --test tests/Js/nj668_draw2d.test.mjs
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const SCRIPT = readFileSync(new URL('../../application/modules/nj668_draw2d/views/nj668/script-canvas.php', import.meta.url), 'utf8')
    .replace(/^<\?php[\s\S]*?\?>\s*/u, '');

function pageHtml(jobId) {
    return `<!doctype html><html><body>
<div id="nj668-canvas"><div id="nj668-stage"></div></div>
<div id="nj668-working" class="hidden"></div>
<div id="nj668-fail" class="hidden"><span id="nj668-fail-text"></span><button id="nj668-fail-close"></button></div>
<span id="nj668-selected"></span>
<span id="nj668-stat-shapes"></span>
<span id="nj668-elapsed"></span>
<button id="nj668-save-icon-btn"></button>
<button id="nj668-undo-btn"></button>
<button id="nj668-redo-btn"></button>
<button id="nj668-del-shape-btn"></button>
<button id="nj668-full-btn"></button>
<button id="nj668-generate-btn"></button>
<button id="nj668-publish-btn"></button>
<button id="nj668-export-json-btn"></button>
<button id="nj668-export-svg-btn"></button>
<button id="nj668-delete-btn"></button>
<input id="nj668-fill">
<input id="nj668-stroke">
<textarea id="nj668-prompt"></textarea>
<script>window.NJ668 = ${JSON.stringify({
        code: 'pmdmthzupkch',
        csrf: 'stand-csrf-token',
        jobId,
        canStart: true,
        published: false,
        maxShapes: 120,
        scene: { w: 960, h: 640, bg: '#eef6ff', shapes: [{ id: 's1', kind: 'rect', x: 10, y: 10, w: 40, h: 20, fill: '#333' }] },
    })};</script>
${SCRIPT}
</body></html>`;
}

async function boot(jobId) {
    const posts = [];
    const dom = new JSDOM(pageHtml(jobId), {
        runScripts: 'dangerously',
        url: 'https://n2j.test/draw2d/p/pmdmthzupkch',
        pretendToBeVisual: true,
        beforeParse(window) {
            window.fetch = async (url, options) => {
                posts.push({ url: String(url), body: options && options.body ? JSON.parse(options.body) : {} });
                return {
                    status: 200,
                    json: async () => ({
                        ok: true,
                        job_id: 0,
                        job: { found: false, status: 'none', job_id: 0 },
                        scene: window.NJ668.scene,
                    }),
                };
            };
        },
    });

    await new Promise((resolve) => dom.window.setTimeout(resolve, 60));
    return { window: dom.window, posts };
}

test('jobIsLive: none и нулевой id — не живы', async () => {
    const { window } = await boot(0);
    const live = window.NJ668.jobIsLive;

    assert.equal(typeof live, 'function');
    assert.equal(live({ found: false, status: 'none' }, 42), false);
    assert.equal(live({ found: true, status: 'running' }, 7), true);
    assert.equal(live({ found: true, status: 'queued', error: 'не JSON' }, 7), false);
    assert.equal(live({ found: true, status: 'done' }, 7), false);

    window.close();
});

test('мёртвый job_id: status один раз, дальше тишина', async () => {
    const { window, posts } = await boot(42);

    await new Promise((resolve) => window.setTimeout(resolve, 80));
    const first = posts.filter((p) => String(p.url).includes('/draw2d/api/status')).length;
    assert.equal(first, 1, 'первый status ушёл');
    assert.equal(window.NJ668.jobId, 0, 'хвост jobId сброшен');

    await new Promise((resolve) => window.setTimeout(resolve, 1800));
    const again = posts.filter((p) => String(p.url).includes('/draw2d/api/status')).length;
    assert.equal(again, 1, 'повторного status нет');

    window.close();
});
