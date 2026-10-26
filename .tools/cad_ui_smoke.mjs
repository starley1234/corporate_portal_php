#!/usr/bin/env node
/* =============================================================================
 * N2J — проверка удобств страницы детали /cad/<code> без браузера.
 *
 *   node .tools/cad_ui_smoke.mjs
 *
 * Зачем: точный ввод размера, «вернуть размеры ИИ», масса по материалу, черновик
 * описания и печать живут в <script> внутри PHP-вьюхи (views/643/script.php).
 * Ни PHP-линт, ни pytest это не покрывают, а сломать легко: синхронизацию
 * ползунка с числом, зажим значения по min/max, формат массы (граммы/килограммы),
 * построчный diff кода модели при сравнении ревизий.
 * Поэтому скрипт вьюхи запускается здесь в Node с поддельным DOM и localStorage,
 * а проверки считают ровно то, что увидит человек.
 *
 * Зависимостей нет — только Node 18+.
 * ========================================================================== */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = path.join(ROOT, 'application/modules/nj643_cad/views/643/script.php');

/* ---------- крошечный DOM ---------- */
class El {
    constructor(tag, attrs = {}, sel = []) {
        this.tagName = tag.toUpperCase();
        this.attrs = { ...attrs };
        this._sel = sel;
        this._handlers = {};
        this._children = [];
        this.value = attrs.value !== undefined ? String(attrs.value) : '';
        this.textContent = '';
        this.innerHTML = '';
        this.disabled = false;
        this.options = [];
        this.style = {};
        this.classList = { add() {}, remove() {} };
    }

    getAttribute(n) { return n in this.attrs ? String(this.attrs[n]) : null; }
    matches(sel) { return this._sel.includes(sel) || sel.toUpperCase() === this.tagName; }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    addEventListener(k, f) { (this._handlers[k] = this._handlers[k] || []).push(f); }
    removeEventListener() {}
    appendChild(el) { this._children.push(el); return el; }
    remove() {}
    focus() {}
    closest(sel) {
        let node = this._parent || null;

        while (node) {
            if (node.matches(sel)) return node;
            node = node._parent || null;
        }

        return null;
    }

    dispatch(type, detail) {
        const ev = { type, target: this, preventDefault() {}, bubbles: true, ...(detail || {}) };
        let node = this;

        while (node) {
            for (const fn of (node._handlers[type] || [])) fn(ev);
            node = node._parent || null;
        }
    }

    click() { this.dispatch('click'); }
    dispatchEvent(ev) { this.dispatch(ev && ev.type ? ev.type : 'event'); }

    querySelector(sel) {
        for (const child of this._children) {
            if (child.matches(sel)) return child;
            const deep = child.querySelector(sel);
            if (deep) return deep;
        }

        return null;
    }

    querySelectorAll(sel) {
        const out = [];

        for (const child of this._children) {
            if (child.matches(sel)) out.push(child);
            out.push(...child.querySelectorAll(sel));
        }

        return out;
    }

    submit() { this.dispatch('submit'); }
}

function storage(map = new Map()) {
    return {
        getItem: (k) => (map.has(k) ? map.get(k) : null),
        setItem: (k, v) => map.set(k, String(v)),
        removeItem: (k) => map.delete(k),
        _dump: () => Object.fromEntries(map),
    };
}

/* ---------- фикстура страницы детали ---------- */
function open({ store = storage(), printed = { n: 0 }, codeA = null, codeB = null } = {}) {
    const php = fs.readFileSync(SCRIPT, 'utf8');
    const code = php.match(/<script>([\s\S]*?)<\/script>/)[1]
        .replace(/<\?php[\s\S]*?\?>/g, '"csrf"');

    const prompt = new El('textarea', {}, ['#nj643-prompt']);
    prompt.value = '';
    const createForm = new El('form', {}, ['form[data-nj643-create]']);
    createForm.appendChild(prompt);
    prompt._parent = createForm;

    const paramsBox = new El('section', { 'data-code': 'cad12345678' }, ['[data-nj643-params]']);
    const ranges = [], nums = [];

    for (const [name, def, min, max, val] of [
        ['length', 120, 40, 200, 120],
        ['width', 60, 20, 120, 60],
        ['hole_d', 6, 2, 20, 6],
    ]) {
        const r = new El('input', { 'data-nj643-range': name, 'data-default': def, min, max, step: 1, value: val },
            ['[data-nj643-range]']);
        r.value = String(val);
        const n = new El('input', { 'data-nj643-num': name, min, max, step: 1, value: val },
            ['[data-nj643-num="' + name + '"]']);
        n.value = String(val);
        paramsBox.appendChild(r);
        paramsBox.appendChild(n);
        ranges.push(r);
        nums.push(n);
    }

    const reset = new El('button', {}, ['[data-nj643-reset]']);
    paramsBox.appendChild(reset);

    const massBox = new El('section', { 'data-volume': '100000' }, ['[data-nj643-mass]']);
    const matSel = new El('select', {}, ['[data-nj643-material]']);
    matSel.options = [
        { value: '1.24', text: 'PLA' },
        { value: '2.7', text: 'Алюминий' },
        { value: '7.85', text: 'Сталь' },
    ];
    matSel.value = '1.24';
    const massOut = new El('span', {}, ['[data-nj643-mass-out]']);
    massBox.appendChild(matSel);
    massBox.appendChild(massOut);

    const printBtn = new El('button', {}, ['[data-nj643-print]']);

    let diffBox = null, diffPreA = null, diffPreB = null;

    if (codeA !== null && codeB !== null) {
        const preA = new El('pre', {}, ['#nj643-diff-a']);
        const preB = new El('pre', {}, ['#nj643-diff-b']);
        preA.textContent = codeA;
        preB.textContent = codeB;
        const out = new El('div', {}, ['[data-nj643-diff-out]']);
        diffBox = new El('div', {}, ['[data-nj643-diff]']);
        diffBox.appendChild(preA);
        diffBox.appendChild(preB);
        diffBox.appendChild(out);
        diffBox._preA = preA;
        diffBox._preB = preB;
        diffBox._out = out;
        diffPreA = preA;
        diffPreB = preB;
    }
    const exampleBtn = new El('button', { 'data-nj643-example': 'Фланец Ø80×8 мм' }, ['[data-nj643-example]']);

    const all = [prompt, createForm, paramsBox, reset, massBox, matSel, massOut, printBtn, exampleBtn, ...ranges, ...nums];

    if (diffBox) all.push(diffBox);

    globalThis.document = {
        querySelector: (sel) => all.find((el) => el.matches(sel)) || null,
        querySelectorAll: (sel) => all.filter((el) => el.matches(sel)),
        getElementById: (id) => {
            if (id === 'nj643-prompt') return prompt;
            if (id === 'nj643-diff-a') return diffPreA;
            if (id === 'nj643-diff-b') return diffPreB;
            return null;
        },
        createElement: (tag) => new El(tag),
        body: { appendChild() {} },
        hidden: false,
    };
    // в браузере localStorage и window.localStorage — одно и то же
    globalThis.localStorage = store;
    globalThis.window = {
        localStorage: store,
        print: () => { printed.n++; },
        addEventListener() {},
        requestAnimationFrame: (cb) => setTimeout(cb, 0),
    };
    globalThis.Event = class {
        constructor(type, opts) { this.type = type; Object.assign(this, opts || {}); }
    };
    globalThis.fetch = async () => ({ json: async () => ({}) });

    new Function(code)();

    return { prompt, createForm, ranges, nums, reset, massSel: matSel, massOut, printBtn, exampleBtn, printed, store, diffBox };
}

/* ---------- проверки ---------- */
let failed = 0;

function check(name, ok, extra = '') {
    console.log(`${ok ? ' OK ' : 'FAIL'}  ${name}${extra ? ' — ' + extra : ''}`);

    if (!ok) failed++;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/* 1. Ползунок → число, число → ползунок, зажим по границам */
{
    const ui = open();
    const [range, , holeRange] = ui.ranges;
    const [num, , holeNum] = ui.nums;

    range.value = '150';
    range.dispatch('input');
    check('ползунок обновляет число', num.value === '150', `число ${num.value}`);

    num.value = '77.5';
    num.dispatch('change');
    check('число двигает ползунок', range.value === '77.5', `ползунок ${range.value}`);

    num.value = '9999';
    num.dispatch('change');
    check('значение зажато максимумом схемы', range.value === '200', `ползунок ${range.value}`);

    holeNum.value = '1';
    holeNum.dispatch('change');
    check('значение зажато минимумом схемы', holeRange.value === '2', `ползунок ${holeRange.value}`);

    num.value = 'abc';
    num.dispatch('change');
    check('мусор в поле возвращает значение ползунка', range.value === '200', `ползунок ${range.value}`);
}

/* 2. «Вернуть размеры ИИ» */
{
    const ui = open();

    ui.ranges[0].value = '199';
    ui.ranges[0].dispatch('input');
    ui.ranges[1].value = '21';
    ui.ranges[1].dispatch('input');

    ui.reset.click();

    check('кнопка вернула размеры ИИ',
        ui.ranges.map((r) => r.value).join(',') === '120,60,6',
        ui.ranges.map((r) => r.value).join(','));
    check('числа рядом с ползунками тоже вернулись',
        ui.nums.map((n) => n.value).join(',') === '120,60,6',
        ui.nums.map((n) => n.value).join(','));
}

/* 3. Масса по материалу + память выбора */
{
    const ui = open();
    check('масса по PLA', ui.massOut.textContent === '124 г', ui.massOut.textContent);

    ui.massSel.value = '7.85';
    ui.massSel.dispatch('change');
    check('масса по стали', ui.massOut.textContent === '785 г', ui.massOut.textContent);
    check('выбор материала запомнен', ui.store.getItem('n2j:cad:material') === '7.85', String(ui.store.getItem('n2j:cad:material')));

    const again = open({ store: ui.store });
    check('выбор материала восстановлен', again.massSel.value === '7.85', again.massSel.value);

    const heavy = open({ store: storage(new Map([['n2j:cad:material', '7.85']])) });
    heavy.massSel.value = '2.7';
    heavy.massSel.dispatch('change');
    check('крупная деталь показывается в килограммах', heavy.massOut.textContent === '270 г', heavy.massOut.textContent);
}

/* 4. Печать */
{
    const ui = open();
    ui.printBtn.click();
    check('кнопка «Печать» зовёт печать', ui.printed.n === 1, `вызовов ${ui.printed.n}`);
}

/* 5. Заготовки подставляют описание */
{
    const ui = open();
    ui.exampleBtn.click();
    check('заготовка заполняет описание', ui.prompt.value === 'Фланец Ø80×8 мм', ui.prompt.value);
}

/* 6. Черновик описания */
{
    const ui = open({ store: storage(new Map([['n2j:cad:draft', 'Уголок 40×40×4']])) });
    check('черновик восстановлен', ui.prompt.value === 'Уголок 40×40×4', ui.prompt.value);

    const typed = open();
    typed.prompt.value = 'Втулка Ø20';
    typed.prompt.dispatch('input');
    await sleep(520);
    check('набранный текст сохраняется', typed.store.getItem('n2j:cad:draft') === 'Втулка Ø20',
        String(typed.store.getItem('n2j:cad:draft')));

    typed.prompt.value = '';
    typed.prompt.dispatch('input');
    await sleep(520);
    check('пустое поле не оставляет черновик', typed.store.getItem('n2j:cad:draft') === null,
        String(typed.store.getItem('n2j:cad:draft')));

    const sent = open({ store: storage(new Map([['n2j:cad:draft', 'Кронштейн']])) });
    sent.createForm.dispatch('submit');
    check('отправка формы убирает черновик', sent.store.getItem('n2j:cad:draft') === null,
        String(sent.store.getItem('n2j:cad:draft')));
}

/* 7. Сравнение ревизий: построчный diff */
{
    const ui = open({
        codeA: 'part = Box(80, 60, 8)\npart -= Hole(20)\npart -= Hole(6)\nreturn part',
        codeB: 'part = Box(80, 60, 10)\npart -= Hole(20)\npart -= Hole(6)\nreturn part',
    });

    const html = ui.diffBox._out.innerHTML;
    check('diff посчитал изменения', html.indexOf('Изменено строк: +1 / −1') !== -1,
        html.slice(0, 60).replace(/<[^>]*>/g, ''));
    check('удалённая строка помечена минусом', html.indexOf('− part = Box(80, 60, 8)') !== -1);
    check('добавленная строка помечена плюсом', html.indexOf('+ part = Box(80, 60, 10)') !== -1);
    check('одинаковые строки не выданы за изменения', html.indexOf('− part -= Hole(20)') === -1);
}

/* 8. Длинные совпадения в diff сворачиваются */
{
    const same = [];
    for (let i = 1; i <= 20; i++) same.push('line ' + i);
    const ui = open({ codeA: same.join('\n'), codeB: same.concat(['added']).join('\n') });

    const html = ui.diffBox._out.innerHTML;
    check('длинный кусок совпадений свёрнут', html.indexOf('строк без изменений') !== -1,
        (html.match(/… \d+ строк без изменений/) || ['нет'])[0]);
    check('добавление видно после свёртки', html.indexOf('+ added') !== -1);
}

/* 9. Одинаковый код — нет ложных отличий */
{
    const ui = open({ codeA: 'a\nb\nc', codeB: 'a\nb\nc' });
    const html = ui.diffBox._out.innerHTML;
    check('одинаковый код: изменений нет', html.indexOf('Изменено строк: +0 / −0') !== -1, html.slice(0, 40));
}

console.log(`\n${failed === 0 ? 'Страница детали в порядке.' : `Провалов: ${failed}`}`);
process.exit(failed === 0 ? 0 : 1);
