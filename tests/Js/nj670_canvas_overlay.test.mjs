/** NJ-670 ProcessHub: комментарии и аудит-оверлеи на шаге (jsdom).
 *
 * Зачем: ревьюер должен оставить прямо на схеме метку («риск», «контроль»,
 * «вопрос», «идея») и комментарий, а не писать их в отдельный документ.
 * Проверяем настоящую вьюху script-canvas.php: бейдж рисуется, текст
 * комментария попадает в подсказку, инспектор заполняется и сохраняет
 * правку в properties узла.
 *
 *   node --test tests/Js/nj670_canvas_overlay.test.mjs
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const CANVAS = path.join(ROOT, 'application/modules/nj670_processhub/views/670/script-canvas.php');

const COMMENT = 'Юрист смотрит только сумму — приложение не проверяют.';

const GRAPH = {
    lanes: [{id: 'lawyer', title: 'Юрист'}],
    nodes: [
        {id: 'start', step_id: 'start', type: 'start', title: 'Старт', lane: 'lawyer', x: 0, y: 40},
        {
            id: 'check', step_id: 'check', type: 'user_task', title: 'Проверить договор', lane: 'lawyer',
            x: 200, y: 40, duration_minutes: 60, properties: {overlay: 'risk', comment: COMMENT}
        },
        {id: 'end', step_id: 'end', type: 'end', title: 'Конец', lane: 'lawyer', x: 600, y: 40}
    ],
    edges: [
        {id: 'e1', from: 'start', to: 'check', type: 'sequence'},
        {id: 'e2', from: 'check', to: 'end', type: 'sequence'}
    ],
    meta: {title: 'Закупка', mode: 'plan'}
};

function mountCanvas() {
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
        + '<select id="nj670-insp-overlay"><option value=""></option><option value="risk"></option>'
        + '<option value="control"></option><option value="question"></option><option value="idea"></option></select>'
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
    // Кадры по требованию: синхронный rAF в jsdom уводит render() в
    // бесконечную рекурсию, потому что перерисовка снова просит кадр.
    let queue = [];
    window.requestAnimationFrame = (cb) => { queue.push(cb); return queue.length; };
    window.cancelAnimationFrame = () => {};
    const frame = () => { const due = queue; queue = []; due.forEach((cb) => cb(Date.now())); };

    window.NJ670 = {
        code: 'demo01', kind: 'plan', versionId: 1, graph: GRAPH,
        metrics: {nodes_count: 3, edges_count: 2, elapsed_minutes: 60, work_minutes: 60,
            wait_minutes: 0, cost_rub: 1000, loop_count: 0, shadow_count: 0, bottleneck: null},
        csrf: 'x', api: '/process/api', nodeTypes: {user_task: 'Задача'},
        canSave: true, updatedAt: '', planCode: 'demo01', siblings: []
    };

    window.eval(script);
    frame();
    frame();

    return {window, svg, frame};
}

test('оверлей и комментарий видны на холсте без наведения', () => {
    const {window, svg} = mountCanvas();

    try {
        const group = svg.querySelector('[data-id="check"]');

        assert.ok(group, 'узел не отрисован');
        // Приглушённая метка риска и «облачко» комментария.
        assert.ok(group.querySelector('circle[fill="#be123c"]'), 'нет метки риска');
        assert.ok(group.querySelector('.nj670-comment-badge'), 'нет бейджа комментария');
        assert.match(group.querySelector('title').textContent, /приложение не проверяют/);
    } finally {
        window.close();
    }
});

test('инспектор показывает и сохраняет оверлей и комментарий', () => {
    const {window, svg} = mountCanvas();
    const doc = window.document;

    try {
        const group = svg.querySelector('[data-id="check"]');

        group.dispatchEvent(new window.MouseEvent('pointerdown', {bubbles: true, button: 0, clientX: 250, clientY: 70}));
        svg.dispatchEvent(new window.MouseEvent('pointerup', {bubbles: true, button: 0, clientX: 250, clientY: 70}));

        assert.equal(doc.getElementById('nj670-insp-overlay').value, 'risk');
        assert.equal(doc.getElementById('nj670-insp-comment').value, COMMENT);

        // Меняем метку на «контроль» и текст — правка должна лечь в properties.
        doc.getElementById('nj670-insp-overlay').value = 'control';
        doc.getElementById('nj670-insp-comment').value = 'Сверять приложение к договору.';
        doc.getElementById('nj670-insp-apply').dispatchEvent(new window.MouseEvent('click', {bubbles: true}));

        const node = window.NJ670.graph.nodes.find((n) => n.id === 'check');
        assert.equal(node.properties.overlay, 'control');
        assert.equal(node.properties.comment, 'Сверять приложение к договору.');

        // Пустой комментарий стирает свойство, иначе старый текст «залипнет».
        doc.getElementById('nj670-insp-comment').value = '  ';
        doc.getElementById('nj670-insp-apply').dispatchEvent(new window.MouseEvent('click', {bubbles: true}));

        assert.ok(!('comment' in node.properties), 'пустой комментарий остался в свойствах');
        assert.ok(!svg.querySelector('[data-id="check"] .nj670-comment-badge'), 'бейдж остался после очистки');
    } finally {
        window.close();
    }
});
