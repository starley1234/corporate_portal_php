/** NJ-670 ProcessHub: панорамирование холста (jsdom).
 *
 * Зачем: на /process/<code> схема «дрожала» при перетаскивании мышью влево и
 * вправо. Причина была в старом коде: при захвате запоминалась точка, уже
 * пересчитанная через viewBox, а движение считалось по формуле, которая этот
 * же viewBox вычитала ещё раз. Пока кадр не перерисован, схема «догоняла»
 * курсор, после перерисовки — отставала: на экране получалось мелкое дрожание
 * с частотой кадров.
 *
 * Тест берёт настоящую вьюху application/modules/nj670_processhub/views/670/
 * script-canvas.php (без копии в тесте — иначе тест проверит сам себя) и
 * подсовывает ей минимальный DOM. CTM считается от текущего viewBox, как в
 * браузере, поэтому старая ревизия вьюхи на этих проверках падает:
 *
 *   git show HEAD~1:.../script-canvas.php > /tmp/old-canvas.php
 *   NJ670_CANVAS=/tmp/old-canvas.php node --test tests/Js/nj670_canvas_pan.test.mjs
 *
 *   node --test tests/Js/nj670_canvas_pan.test.mjs
 */

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const CANVAS = process.env.NJ670_CANVAS
    || path.join(ROOT, 'application/modules/nj670_processhub/views/670/script-canvas.php');

const GRAPH = {
    lanes: [{id: 'initiator', title: 'Инициатор'}],
    // Схема заметно шире окна: у панорамирования должен быть запас, иначе
    // clampView законно упирается в край и тест поймает не баг, а лимит.
    nodes: [
        {id: 'start', step_id: 'start', type: 'start', title: 'Старт', lane: 'initiator', x: 0, y: 0},
        {id: 'request', step_id: 'request', type: 'user_task', title: 'Заявка', lane: 'initiator', x: 900, y: 0},
        {id: 'pay', step_id: 'pay', type: 'user_task', title: 'Оплата', lane: 'initiator', x: 1800, y: 0},
        {id: 'end', step_id: 'end', type: 'end', title: 'Конец', lane: 'initiator', x: 2700, y: 0}
    ],
    edges: [
        {id: 'e1', from: 'start', to: 'request', type: 'sequence'},
        {id: 'e2', from: 'request', to: 'pay', type: 'sequence'},
        {id: 'e3', from: 'pay', to: 'end', type: 'sequence'}
    ],
    meta: {title: 'Тест', mode: 'plan'}
};

const SCREEN_W = 660;
const SCREEN_H = 640;

/** Холст в jsdom: реальный скрипт вьюхи, фальшивая геометрия как у браузера. */
function mountCanvas() {
    const view = readFileSync(CANVAS, 'utf8');
    const script = view.slice(view.indexOf('<script>') + 8, view.indexOf('</script>'));

    const dom = new JSDOM(
        '<!doctype html><html><body>'
        + '<div class="nj670-canvas-shell"><svg id="nj670-canvas"></svg></div>'
        + '<div id="nj670-status"></div>'
        + '<button id="nj670-zoom-in"></button><button id="nj670-zoom-out"></button>'
        + '<button id="nj670-fit-btn"></button>'
        + '<button id="nj670-full-btn"><i class="fas fa-expand"></i></button>'
        + '<ul id="nj670-issues"></ul><span id="nj670-issue-count"></span>'
        + '</body></html>',
        {runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/process/demo01'}
    );

    const {window} = dom;
    const svg = window.document.getElementById('nj670-canvas');

    // preserveAspectRatio по умолчанию: xMidYMid meet. Матрица зависит от
    // viewBox — именно из-за этого старый код ловил сам себя.
    window.SVGSVGElement.prototype.getScreenCTM = function () {
        const [vx, vy, vw, vh] = (svg.getAttribute('viewBox') || '0 0 1100 680').split(' ').map(Number);
        const k = Math.min(SCREEN_W / vw, SCREEN_H / vh);

        return {
            a: k, b: 0, c: 0, d: k,
            e: (SCREEN_W - vw * k) / 2 - vx * k,
            f: (SCREEN_H - vh * k) / 2 - vy * k,
            inverse() { return this; }
        };
    };
    window.SVGSVGElement.prototype.createSVGPoint = function () {
        return {
            x: 0, y: 0,
            matrixTransform(m) { return {x: (this.x - m.e) / m.a, y: (this.y - m.f) / m.d}; }
        };
    };

    // Кадры анимации по требованию: так видно, влияет ли перерисовка на сдвиг.
    let queue = [];
    window.requestAnimationFrame = (cb) => queue.push(cb);
    window.cancelAnimationFrame = () => {};
    const frame = () => {
        const due = queue;
        queue = [];
        due.forEach((cb) => cb(Date.now()));
    };
    const settle = () => { frame(); frame(); };

    window.NJ670 = {
        code: 'demo01', kind: 'plan', versionId: 1, graph: GRAPH,
        metrics: {
            nodes_count: 4, edges_count: 3, elapsed_minutes: 60, work_minutes: 60,
            wait_minutes: 0, cost_rub: 1000, loop_count: 0, shadow_count: 0, bottleneck: null
        },
        csrf: 'x', api: '/process/api', nodeTypes: {user_task: 'Задача'},
        canSave: true, updatedAt: '', planCode: 'demo01'
    };

    window.eval(script);
    settle();

    const box = () => svg.getAttribute('viewBox').split(' ').map(Number);
    const viewX = () => box()[0];

    // jsdom не перенаправляет события по setPointerCapture — шлём их в SVG,
    // как браузер делает для захваченного указателя.
    const pointer = (type, clientX, clientY, target) => {
        const evt = new window.MouseEvent(type, {
            bubbles: true, cancelable: true, button: 0, clientX, clientY
        });
        evt.pointerId = 1;
        (target || svg).dispatchEvent(evt);
    };

    /** Тянем фон: массив смещений курсора в экранных px, с кадрами или без. */
    const pan = (offsets, withFrames) => {
        pointer('pointerdown', 400, 300);
        const start = viewX();
        let last = 0;

        for (const dx of offsets) {
            last = dx;
            pointer('pointermove', 400 + dx, 300);

            if (withFrames) { frame(); }
        }

        pointer('pointerup', 400 + last, 300);
        settle();

        return {start, end: viewX()};
    };

    return {window, svg, box, viewX, pointer, pan, frame, settle, dom};
}

test('полноэкранный режим: кнопка разворачивает холст и вписывает схему', () => withCanvas((c) => {
    const doc = c.window.document;
    const btn = doc.getElementById('nj670-full-btn');
    const shell = doc.querySelector('.nj670-canvas-shell');
    const click = () => btn.dispatchEvent(new c.window.MouseEvent('click', {bubbles: true}));

    click();
    c.settle();

    assert.ok(doc.body.classList.contains('nj670-fullscreen'), 'body без класса полноэкранного режима');
    assert.ok(shell.classList.contains('is-fullscreen'), 'оболочка холста не развернулась');
    assert.equal(btn.getAttribute('aria-pressed'), 'true');

    // Схема вписана в окно: viewBox осмысленный и не меньше минимума clampView.
    const [,, w, h] = c.box();
    assert.ok(w >= 700 && h >= 460, `viewBox ${w}×${h}`);

    click();
    c.settle();

    assert.ok(!doc.body.classList.contains('nj670-fullscreen'), 'режим не выключился');
    assert.ok(!shell.classList.contains('is-fullscreen'));
    assert.equal(btn.getAttribute('aria-pressed'), 'false');
}));

test('проверка схемы: связная схема не даёт замечаний', () => withCanvas((c) => {
    assert.equal(c.window.NJ670.validate().length, 0);
    assert.match(c.window.document.getElementById('nj670-issues').textContent, /Ошибок нет/);
}));

test('проверка схемы: находит старт/конец, тупики, шлюзы и условия', () => withCanvas((c) => {
    const issues = c.window.NJ670.validate({
        lanes: [],
        nodes: [
            {id: 't1', type: 'user_task', title: 'Заявка'},
            {id: 'g1', type: 'gateway_xor', title: 'Сумма?'},
            {id: 't2', type: 'manual_task', title: 'Оплата'}
        ],
        edges: [
            {id: 'e1', from: 't1', to: 'g1', type: 'sequence'},
            {id: 'e2', from: 'g1', to: 't2', type: 'sequence'}
        ]
    });
    const codes = issues.map((issue) => issue.code);

    assert.ok(codes.includes('no_start'), 'нет стартового события');
    assert.ok(codes.includes('no_end'), 'нет конечного события');
    assert.ok(codes.includes('unreachable'), 'недостижимый шаг');
    assert.ok(codes.includes('gateway_branch'), 'шлюз без ветвления');
    assert.ok(codes.includes('no_condition'), 'XOR без условия');
    assert.ok(codes.includes('no_role'), 'задача без роли');
    assert.ok(issues.every((issue) => issue.severity === 'error' || issue.severity === 'warning'));

    // Тексты человеческие: роль и название шага, а не только код.
    assert.ok(issues.some((issue) => issue.text.includes('Заявка')));
}));

test('проверка схемы: связь в себя — ошибка', () => withCanvas((c) => {
    const issues = c.window.NJ670.validate({
        lanes: [],
        nodes: [
            {id: 's', type: 'start', title: 'Старт'},
            {id: 'a', type: 'user_task', title: 'A', lane: 'l'},
            {id: 'e', type: 'end', title: 'Конец'}
        ],
        edges: [
            {id: 'e1', from: 's', to: 'a', type: 'sequence'},
            {id: 'e2', from: 'a', to: 'a', type: 'sequence'},
            {id: 'e3', from: 'a', to: 'e', type: 'sequence'}
        ]
    });

    assert.ok(issues.some((issue) => issue.code === 'self_loop' && issue.severity === 'error'));
}));

/** Каждый тест — свой холст; окно закрываем, иначе таймер опроса держит процесс. */
function withCanvas(fn) {
    const canvas = mountCanvas();

    try {
        fn(canvas);
    } finally {
        canvas.window.close();
    }
}

test('панорамирование: сдвиг мыши влево уводит схему на ту же величину', () => withCanvas((c) => {
    // 60 экранных px при масштабе 0.6 — это 100 единиц схемы.
    const r = c.pan([-20, -40, -60], false);

    assert.ok(Math.abs((r.end - r.start) - 100) < 0.5, `view.x ${r.start} → ${r.end}`);
}));

test('панорамирование не зависит от перерисовки кадров', () => withCanvas((c) => {
    // Старый код читал координаты через уже сдвинутый viewBox и за шесть
    // событий с кадрами проезжал вдвое меньше (100 вместо 200 единиц).
    const r = c.pan([-10, -20, -30, -40, -50, -60], true);

    assert.ok(Math.abs((r.end - r.start) - 100) < 0.5, `view.x ${r.start} → ${r.end}`);
}));

test('схема едет монотонно, без откатов на каждом кадре', () => withCanvas((c) => {
    // Ровно тот сценарий из жалобы: тянем влево, кадры идут между событиями.
    c.pointer('pointerdown', 500, 300);
    const xs = [c.viewX()];

    for (let i = 1; i <= 10; i++) {
        c.pointer('pointermove', 500 - i * 6, 300);
        c.frame();
        xs.push(c.viewX());
    }

    c.pointer('pointerup', 440, 300);
    c.settle();

    for (let i = 1; i < xs.length; i++) {
        assert.ok(xs[i] >= xs[i - 1] - 0.001, `кадр ${i}: откат назад ${xs[i - 1]} → ${xs[i]}`);
    }

    const steps = xs.slice(1).map((x, i) => x - xs[i]);
    // 6 экранных px при масштабе 0.6 — ровно 10 единиц на кадр, без разброса.
    steps.forEach((d, i) => assert.ok(Math.abs(d - 10) < 0.5, `шаг ${i + 1}: ${d}`));
}));

test('во время панорамирования DOM схемы не пересобирается', () => withCanvas((c) => {
    // Каждый кадр раньше делал svg.innerHTML = ... — схема мигала и «дрожала».
    c.pointer('pointerdown', 400, 300);
    // Один render на старте жеста (снять выделение) допустим — дальше нет.
    const node = c.svg.querySelector('[data-id="pay"]');

    for (let i = 1; i <= 6; i++) {
        c.pointer('pointermove', 400 - i * 10, 300);
        c.frame();
    }

    assert.equal(c.svg.querySelector('[data-id="pay"]'), node, 'узел остался тем же элементом');

    c.pointer('pointerup', 340, 300);
    c.settle();
}));

test('микродрожание руки не шевелит холст', () => withCanvas((c) => {
    c.pointer('pointerdown', 500, 300);
    const start = c.viewX();

    c.pointer('pointermove', 501, 300);
    c.pointer('pointermove', 499, 300);
    c.pointer('pointerup', 499, 300);
    c.settle();

    assert.equal(c.viewX(), start, 'сдвиг меньше мёртвой зоны (3 px) не применяется');
}));

test('возврат курсора в точку захвата возвращает исходный вид', () => withCanvas((c) => {
    c.pointer('pointerdown', 400, 300);
    const start = c.viewX();

    c.pointer('pointermove', 320, 300);
    c.frame();
    c.pointer('pointermove', 400, 300);
    c.frame();
    c.pointer('pointerup', 400, 300);
    c.settle();

    assert.ok(Math.abs(c.viewX() - start) < 0.5, `view.x ${start} → ${c.viewX()}`);
}));

test('перетаскивание узла двигает узел, а не холст', () => withCanvas((c) => {
    // render() пересобирает innerHTML, поэтому элемент ищем каждый раз заново.
    const nodeEl = () => c.svg.querySelector('[data-id="request"]');
    const nodeX = () => Number(/translate\(([-0-9.]+),/.exec(nodeEl().getAttribute('transform'))[1]);
    const before = {x: nodeX(), view: c.viewX()};

    c.pointer('pointerdown', 300, 30, nodeEl());
    c.pointer('pointermove', 360, 30);
    c.pointer('pointerup', 360, 30);
    c.settle();

    assert.equal(c.viewX(), before.view, 'окно просмотра не должно ехать');
    assert.equal(nodeX(), before.x + 100, '60 экранных px = 100 единиц схемы');
}));

test('pointercancel не оставляет холст зажатым', () => withCanvas((c) => {
    c.pointer('pointerdown', 400, 300);
    const start = c.viewX();

    // Alt+Tab, звонок, смена окна: захват потерян, движения мыши больше нет.
    c.pointer('pointercancel', 400, 300);
    c.settle();
    c.pointer('pointermove', 600, 300);
    c.settle();

    assert.equal(c.viewX(), start, 'после отмены движения мыши не двигают схему');
}));

test('масштаб пересчитывается при изменении окна просмотра', () => withCanvas((c) => {
    c.window.document.getElementById('nj670-zoom-out').click();
    c.settle();

    assert.ok(Math.abs(c.box()[2] - 1320) < 0.5, `w=${c.box()[2]}`);

    // Те же 60 экранных px при масштабе 0.5 — уже 120 единиц схемы.
    const r = c.pan([-60], false);
    assert.ok(Math.abs((r.end - r.start) - 120) < 0.5, `view.x ${r.start} → ${r.end}`);
}));
