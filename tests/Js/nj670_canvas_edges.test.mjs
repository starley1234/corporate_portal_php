/** NJ-670 ProcessHub: маршрутизация стрелок и масштаб колесом (jsdom).
 *
 * Жалоба: «стрелочки не всегда корректно рисуются» — линия входила в левую
 * грань независимо от того, где стоит цель, шла сквозь блоки, а петли
 * накладывались друг на друга. И «не работает масштабирование колёсиком»:
 * обработчик выходил без Ctrl.
 *
 * Здесь проверяем настоящую вьюху script-canvas.php: выбирает ли она грани
 * по положению цели, обходит ли третий блок и реагирует ли на обычное колесо.
 *
 *   node --test tests/Js/nj670_canvas_edges.test.mjs
 */

import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import {JSDOM} from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const CANVAS = path.join(ROOT, 'application/modules/nj670_processhub/views/670/script-canvas.php');

const GRAPH = {
    lanes: [{id: 'lawyer', title: 'Юрист'}],
    nodes: [
        {id: 'start', step_id: 'start', type: 'start', title: 'Старт', lane: 'lawyer', x: 0, y: 40},
        {id: 'check', step_id: 'check', type: 'user_task', title: 'Проверить', lane: 'lawyer', x: 300, y: 40},
        {id: 'approve', step_id: 'approve', type: 'user_task', title: 'Согласовать', lane: 'lawyer', x: 600, y: 40},
        {id: 'below', step_id: 'below', type: 'user_task', title: 'Ниже', lane: 'lawyer', x: 300, y: 420},
        {id: 'end', step_id: 'end', type: 'end', title: 'Конец', lane: 'lawyer', x: 900, y: 420}
    ],
    edges: [
        {id: 'e1', from: 'start', to: 'check', type: 'sequence'},
        {id: 'e2', from: 'check', to: 'approve', type: 'sequence'},
        {id: 'e3', from: 'check', to: 'below', type: 'sequence'},
        {id: 'e4', from: 'approve', to: 'below', type: 'sequence'},
        // Петля назад: цель левее источника, обе стрелки в нижнюю грань.
        {id: 'e5', from: 'approve', to: 'start', type: 'loop'}
    ],
    meta: {title: 'Закупка', mode: 'plan'}
};

function mount(graph = GRAPH) {
    const view = readFileSync(CANVAS, 'utf8');
    const script = view.slice(view.indexOf('<script>') + 8, view.indexOf('</script>'));

    const dom = new JSDOM(
        '<!doctype html><html><body>'
        + '<div class="nj670-canvas-shell"><svg id="nj670-canvas"></svg></div>'
        + '<div id="nj670-status"></div>'
        + '<button id="nj670-zoom-in"></button><button id="nj670-zoom-out"></button>'
        + '<button id="nj670-fit-btn"></button><button id="nj670-full-btn"></button>'
        + '<ul id="nj670-issues"></ul><span id="nj670-issue-count"></span>'
        + '<span id="nj670-save-text"></span><button id="nj670-save-btn"></button>'
        + '<div id="nj670-insp" class="hidden"></div><p id="nj670-insp-empty"></p>'
        + '<input id="nj670-insp-title"><select id="nj670-insp-type"></select>'
        + '<select id="nj670-insp-role"></select><select id="nj670-insp-system"></select>'
        + '<select id="nj670-insp-input"></select><select id="nj670-insp-output"></select>'
        + '<input id="nj670-insp-duration"><input id="nj670-insp-wait">'
        + '<select id="nj670-insp-process"></select>'
        + '<input id="nj670-insp-cond">'
        + '<select id="nj670-insp-overlay"><option value=""></option></select>'
        + '<textarea id="nj670-insp-comment"></textarea>'
        + '<button id="nj670-insp-apply"></button><button id="nj670-del-btn"></button>'
        + '<p id="nj670-insp-meta"></p>'
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

    let queue = [];
    window.requestAnimationFrame = (cb) => { queue.push(cb); return queue.length; };
    window.cancelAnimationFrame = () => {};
    const frame = () => { const due = queue; queue = []; due.forEach((cb) => cb(Date.now())); };

    window.NJ670 = {
        code: 'demo01', kind: 'plan', versionId: 1, graph,
        metrics: {
            nodes_count: graph.nodes.length, edges_count: graph.edges.length,
            elapsed_minutes: 60, work_minutes: 60, wait_minutes: 0, cost_rub: 1000,
            loop_count: 0, shadow_count: 0, bottleneck: null
        },
        csrf: 'x', api: '/process/api', nodeTypes: {user_task: 'Задача'},
        canSave: true, updatedAt: '', planCode: 'demo01', siblings: []
    };

    window.eval(script);
    frame();
    frame();

    return {window, svg};
}

function pathOf(svg, edgeId) {
    const d = svg.querySelector(`[data-edge="${edgeId}"] path`).getAttribute('d');
    return d;
}

/** Разобрать SVG-path в список точек (линии и кривые Безье). */
function sample(d, perCurve = 24) {
    const tokens = d.match(/[MLCQ]|-?\d+(?:\.\d+)?/g) || [];
    const points = [];
    let i = 0;
    let cursor = {x: 0, y: 0};

    const num = () => parseFloat(tokens[i++]);

    while (i < tokens.length) {
        const cmd = tokens[i++];
        if (cmd === 'M' || cmd === 'L') {
            cursor = {x: num(), y: num()};
            points.push({...cursor});
        } else if (cmd === 'C') {
            const p0 = {...cursor};
            const p1 = {x: num(), y: num()};
            const p2 = {x: num(), y: num()};
            const p3 = {x: num(), y: num()};
            for (let t = 1; t <= perCurve; t++) {
                const u = t / perCurve;
                const v = 1 - u;
                points.push({
                    x: v * v * v * p0.x + 3 * v * v * u * p1.x + 3 * v * u * u * p2.x + u * u * u * p3.x,
                    y: v * v * v * p0.y + 3 * v * v * u * p1.y + 3 * v * u * u * p2.y + u * u * u * p3.y
                });
            }
            cursor = p3;
        } else if (cmd === 'Q') {
            const p0 = {...cursor};
            const p1 = {x: num(), y: num()};
            const p2 = {x: num(), y: num()};
            for (let t = 1; t <= perCurve; t++) {
                const u = t / perCurve;
                const v = 1 - u;
                points.push({
                    x: v * v * p0.x + 2 * v * u * p1.x + u * u * p2.x,
                    y: v * v * p0.y + 2 * v * u * p1.y + u * u * p2.y
                });
            }
            cursor = p2;
        } else {
            throw new Error('неожиданная команда пути: ' + cmd);
        }
    }

    return points;
}

function rectOfNode(node) {
    const sizes = {start: [86, 60], end: [86, 60], gateway_xor: [92, 92], gateway_and: [92, 92]};
    const [w, h] = sizes[node.type] || [196, 86];
    return {x: node.x, y: node.y, w: node.w || w, h: node.h || h};
}

function inside(point, rect, pad = 2) {
    return point.x > rect.x + pad && point.x < rect.x + rect.w - pad
        && point.y > rect.y + pad && point.y < rect.y + rect.h - pad;
}

test('стрелка вперёд выходит из правой грани и входит в левую', () => {
    const {window, svg} = mount();

    try {
        const points = sample(pathOf(svg, 'e1'));
        const start = GRAPH.nodes[0], target = GRAPH.nodes[1];

        assert.equal(points[0].x, start.x + 86);
        assert.equal(points[0].y, start.y + 30);
        assert.equal(points[points.length - 1].x, target.x);
        assert.equal(points[points.length - 1].y, target.y + 43);
    } finally {
        window.close();
    }
});

test('цель ниже — стрелка входит в верхнюю грань, а не в левую', () => {
    const {window, svg} = mount();

    try {
        const points = sample(pathOf(svg, 'e3'));
        const target = GRAPH.nodes[3];

        // Верхняя грань «Ниже»: y = 420, x внутри рамки.
        assert.equal(points[points.length - 1].y, target.y);
        assert.ok(points[points.length - 1].x > target.x + 20
            && points[points.length - 1].x < target.x + 176, 'вошла не в верхнюю грань');
    } finally {
        window.close();
    }
});

test('петля назад обходит блоки, а не проходит сквозь них', () => {
    const {window, svg} = mount();

    try {
        const points = sample(pathOf(svg, 'e5'), 30);
        const rects = GRAPH.nodes.map(rectOfNode);

        // Концы лежат на границах — проверяем внутренние точки.
        for (const point of points.slice(2, -2)) {
            for (const rect of rects) {
                assert.ok(!inside(point, rect), `линия прошла сквозь блок: ${JSON.stringify(point)}`);
            }
        }
    } finally {
        window.close();
    }
});

test('третий блок в коридоре — стрелка уводится под него', () => {
    const graph = {
        lanes: [{id: 'l', title: 'Линия'}],
        nodes: [
            {id: 'a', step_id: 'a', type: 'user_task', title: 'A', lane: 'l', x: 0, y: 40},
            {id: 'blocker', step_id: 'blocker', type: 'user_task', title: 'Блок', lane: 'l', x: 320, y: 30},
            {id: 'b', step_id: 'b', type: 'user_task', title: 'B', lane: 'l', x: 640, y: 40}
        ],
        edges: [{id: 'e1', from: 'a', to: 'b', type: 'sequence'}],
        meta: {title: 'Коридор', mode: 'plan'}
    };
    const {window, svg} = mount(graph);

    try {
        const points = sample(pathOf(svg, 'e1'), 30);
        const rects = graph.nodes.map(rectOfNode);
        const blocker = rects[1];

        // Коридор между A и B перекрыт: линия должна обойти блок.
        const crosses = points.filter((p) => inside(p, blocker)).length;
        assert.equal(crosses, 0, 'стрелка прошла сквозь третий блок');
        assert.ok(points.some((p) => p.y > 40 + 86), 'нет обхода ниже блока');
    } finally {
        window.close();
    }
});

test('обычное колесо масштабирует, Shift+колесо сдвигает', () => {
    const {window, svg} = mount();
    const doc = window.document;

    try {
        const box = () => svg.getAttribute('viewBox').split(' ').map(Number);
        const before = box();

        svg.dispatchEvent(new window.WheelEvent('wheel', {
            deltaY: -120, clientX: 400, clientY: 250, bubbles: true, cancelable: true
        }));

        const zoomed = box();
        assert.ok(zoomed[2] < before[2], `ширина viewBox не уменьшилась: ${before[2]} → ${zoomed[2]}`);

        svg.dispatchEvent(new window.WheelEvent('wheel', {
            deltaY: 120, shiftKey: true, clientX: 400, clientY: 250, bubbles: true, cancelable: true
        }));

        const panned = box();
        assert.ok(Math.abs(panned[2] - zoomed[2]) < 0.01, 'Shift+колесо не должно менять масштаб');
        assert.notEqual(panned[0], zoomed[0], 'Shift+колесо не сдвинуло окно');

        assert.equal(doc.getElementById('nj670-status').textContent, doc.getElementById('nj670-status').textContent);
    } finally {
        window.close();
    }
});

test('самопетля выходит из верхней грани и не режет собственный блок', () => {
    const node = {id: 'rework', step_id: 'rework', type: 'user_task', title: 'Доработка', lane: 'lawyer', x: 300, y: 220};
    const graph = {
        lanes: [{id: 'lawyer', title: 'Юрист'}],
        nodes: [node],
        edges: [{id: 'loop', from: 'rework', to: 'rework', type: 'loop'}],
        meta: {title: 'Петля', mode: 'plan'}
    };
    const {window, svg} = mount(graph);

    try {
        const points = sample(pathOf(svg, 'loop'));
        const start = points[0], end = points[points.length - 1];

        // Оба конца — на верхней грани, стрелка возвращается в тот же шаг.
        assert.equal(start.y, node.y);
        assert.equal(end.y, node.y);
        assert.ok(start.x !== end.x, 'порты выхода и входа должны различаться');
        assert.ok(start.x > node.x && start.x < node.x + 196);
        assert.ok(end.x > node.x && end.x < node.x + 196);

        // Дуга уходит выше блока и не проходит через его внутренность.
        assert.ok(Math.min(...points.map((p) => p.y)) < node.y, 'петля не поднялась над блоком');
        assert.ok(!points.some((p) => inside(p, rectOfNode(node), 0)), 'петля режет свой блок');
    } finally {
        window.close();
    }
});

test('вертикальная связь обходит блок, стоящий между шагами', () => {
    const graph = {
        lanes: [{id: 'lawyer', title: 'Юрист'}],
        nodes: [
            {id: 'a', step_id: 'a', type: 'user_task', title: 'Сверху', lane: 'lawyer', x: 300, y: 40},
            {id: 'mid', step_id: 'mid', type: 'user_task', title: 'Мешает', lane: 'lawyer', x: 300, y: 200},
            {id: 'b', step_id: 'b', type: 'user_task', title: 'Снизу', lane: 'lawyer', x: 300, y: 400}
        ],
        edges: [{id: 'e', from: 'a', to: 'b', type: 'sequence'}],
        meta: {title: 'Обход', mode: 'plan'}
    };
    const {window, svg} = mount(graph);

    try {
        const points = sample(pathOf(svg, 'e'));
        const blocker = rectOfNode(graph.nodes[1]);

        assert.ok(!points.some((p) => inside(p, blocker, 1)), 'линия прошла сквозь третий блок');
        assert.ok(points.some((p) => p.x > 500 || p.x < 100), 'линия не ушла в боковой канал');

        // Вход — в правую или левую грань цели (обход сбоку), но не в торец.
        const target = graph.nodes[2];
        const end = points[points.length - 1];
        assert.ok(end.x === target.x || end.x === target.x + 196, 'вход не в боковую грань');
        assert.ok(end.y > target.y && end.y < target.y + 86, 'вход мимо грани цели');
    } finally {
        window.close();
    }
});

test('две связи между одной парой шагов расходятся по портам', () => {
    const graph = {
        lanes: [{id: 'lawyer', title: 'Юрист'}],
        nodes: [
            {id: 'a', step_id: 'a', type: 'user_task', title: 'Проверить', lane: 'lawyer', x: 100, y: 100},
            {id: 'b', step_id: 'b', type: 'user_task', title: 'Согласовать', lane: 'lawyer', x: 500, y: 100}
        ],
        edges: [
            {id: 'yes', from: 'a', to: 'b', type: 'sequence', condition: 'да'},
            {id: 'no', from: 'a', to: 'b', type: 'conditional', condition: 'нет'}
        ],
        meta: {title: 'Ветки', mode: 'plan'}
    };
    const {window, svg} = mount(graph);

    try {
        const one = sample(pathOf(svg, 'yes'))[0];
        const two = sample(pathOf(svg, 'no'))[0];

        assert.notEqual(one.y, two.y, 'выходы совпали — стрелки лягут друг на друга');
        assert.ok(Math.abs(one.y - two.y) >= 6, 'порты слишком близко');
    } finally {
        window.close();
    }
});
