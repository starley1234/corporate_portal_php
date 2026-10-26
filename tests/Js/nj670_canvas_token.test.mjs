/** NJ-670 ProcessHub: «шустрый токен» — проигрывание пути по схеме (jsdom).
 *
 * Зачем: на демо и защите регламента нужно показать, как заявка идёт по
 * процессу. Кнопка ▶ запускает точку от старта до конца по связям; токен
 * живёт в координатах графа, поэтому панорама и масштаб его не «отрывают».
 *
 * Тест берёт настоящую вьюху script-canvas.php и управляет кадрами вручную,
 * чтобы анимация была детерминированной.
 *
 *   node --test tests/Js/nj670_canvas_token.test.mjs
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const CANVAS = path.join(ROOT, 'application/modules/nj670_processhub/views/670/script-canvas.php');

const GRAPH = {
    lanes: [{id: 'buyer', title: 'Закупщик'}],
    nodes: [
        {id: 'start', step_id: 'start', type: 'start', title: 'Старт', lane: 'buyer', x: 0, y: 0},
        {id: 'request', step_id: 'request', type: 'user_task', title: 'Заявка', lane: 'buyer', x: 160, y: 0},
        {id: 'end', step_id: 'end', type: 'end', title: 'Конец', lane: 'buyer', x: 400, y: 0}
    ],
    edges: [
        {id: 'e1', from: 'start', to: 'request', type: 'sequence'},
        {id: 'e2', from: 'request', to: 'end', type: 'sequence'}
    ],
    meta: {title: 'Закупка', mode: 'plan'}
};

function mountCanvas(graph = GRAPH) {
    const view = readFileSync(CANVAS, 'utf8');
    const script = view.slice(view.indexOf('<script>') + 8, view.indexOf('</script>'));

    const dom = new JSDOM(
        '<!doctype html><html><body>'
        + '<div class="nj670-canvas-shell"><svg id="nj670-canvas"></svg></div>'
        + '<div id="nj670-status"></div>'
        + '<button id="nj670-zoom-in"></button><button id="nj670-zoom-out"></button>'
        + '<button id="nj670-fit-btn"></button><button id="nj670-full-btn"></button>'
        + '<button id="nj670-play-btn"></button>'
        + '<ul id="nj670-issues"></ul><span id="nj670-issue-count"></span>'
        + '</body></html>',
        {runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/process/demo01'}
    );

    const {window} = dom;
    const svg = window.document.getElementById('nj670-canvas');

    window.SVGSVGElement.prototype.getScreenCTM = function () {
        return {a: 1, b: 0, c: 0, d: 1, e: 0, f: 0, inverse() { return this; }};
    };
    window.SVGSVGElement.prototype.createSVGPoint = function () {
        return {x: 0, y: 0, matrixTransform() { return {x: this.x, y: this.y}; }};
    };

    // Кадры по требованию с управляемым временем.
    let queue = [];
    window.requestAnimationFrame = (cb) => { queue.push(cb); return queue.length; };
    window.cancelAnimationFrame = () => {};
    const tick = (ts) => { const due = queue; queue = []; due.forEach((cb) => cb(ts)); };

    window.NJ670 = {
        code: 'demo01', kind: 'plan', versionId: 1, graph,
        metrics: {nodes_count: 3, edges_count: 2, elapsed_minutes: 60, work_minutes: 60,
            wait_minutes: 0, cost_rub: 1000, loop_count: 0, shadow_count: 0, bottleneck: null},
        csrf: 'x', api: '/process/api', nodeTypes: {user_task: 'Задача'},
        canSave: true, updatedAt: '', planCode: 'demo01'
    };

    window.eval(script);
    tick(0);
    tick(0);

    return {window, svg, tick, dom};
}

test('токен идёт от старта до конца и останавливается', () => {
    const {window, svg, tick} = mountCanvas();

    try {
        const doc = window.document;
        const btn = doc.getElementById('nj670-play-btn');

        btn.dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        tick(0);

        assert.equal(btn.getAttribute('aria-pressed'), 'true', 'кнопка не перешла в режим проигрывания');
        assert.match(doc.getElementById('nj670-status').textContent, /Токен:/);

        const layer = svg.querySelector('#nj670-token-layer');
        assert.ok(layer, 'слой токена не создан');

        const first = layer.getAttribute('transform');
        const [firstX, firstY] = first.match(/-?\d+(\.\d+)?/g).map(Number);
        // Стартовое событие 86×60, центр (43, 30); за первый кадр токен
        // успевает сдвинуться на пару единиц.
        assert.ok(Math.abs(firstX - 43) < 8 && Math.abs(firstY - 30) < 8, `старт токена: ${first}`);

        // 25 кадров по 100 мс: ~2,5 с при 260 ед/с — путь ~370 единиц.
        for (let i = 1; i <= 25; i++) { tick(i * 100); }

        const last = layer.getAttribute('transform');
        assert.notEqual(first, last, 'токен не сдвинулся');
        assert.match(doc.getElementById('nj670-status').textContent, /Токен прошёл схему: 3 шагов/);
        assert.equal(btn.getAttribute('aria-pressed'), 'false', 'кнопка осталась в режиме проигрывания');
    } finally {
        window.close();
    }
});

test('повторный клик останавливает токен на полпути', () => {
    const {window, tick} = mountCanvas();

    try {
        const doc = window.document;
        const btn = doc.getElementById('nj670-play-btn');

        btn.dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        tick(0);
        tick(100);
        tick(200);

        btn.dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        tick(300);

        assert.equal(btn.getAttribute('aria-pressed'), 'false');
        assert.match(doc.getElementById('nj670-status').textContent, /Токен остановлен/);
    } finally {
        window.close();
    }
});

test('без стартовой связи токен честно отказывается ехать', () => {
    const {window, tick} = mountCanvas({
        lanes: [],
        nodes: [{id: 'solo', type: 'user_task', title: 'Один шаг', x: 0, y: 0}],
        edges: []
    });

    try {
        const doc = window.document;
        const btn = doc.getElementById('nj670-play-btn');

        btn.dispatchEvent(new window.MouseEvent('click', {bubbles: true}));
        tick(0);

        assert.notEqual(btn.getAttribute('aria-pressed'), 'true');
        assert.match(doc.getElementById('nj670-status').textContent, /некуда идти/);
    } finally {
        window.close();
    }
});
