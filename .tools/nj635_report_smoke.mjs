#!/usr/bin/env node
/* =============================================================================
 * N2J — проверка живого отчёта /check (nj635) без браузера.
 *
 *   node .tools/nj635_report_smoke.mjs
 *
 * Зачем: опрос состояния живёт в <script> внутри views/635/script.php. Ни
 * PHP-линт, ни стенды на PHP его не покрывают, а сломать легко и заметно:
 *   • перезагрузка страницы, когда ещё ничего не изменилось (и тогда только что
 *     открытая страница крутит перезагрузку по кругу);
 *   • блок, перерисовываемый на каждом опросе (мигание, таймер дёргается назад);
 *   • опрос, который не остановился после завершения всех шагов.
 * Поэтому здесь скрипт запускается в Node с крошечным поддельным DOM и
 * управляемым таймером, а fetch отдаёт заранее заданные ответы /check/api/status.
 *
 * Зависимостей нет — только Node 18+.
 * ========================================================================== */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = path.join(ROOT, 'application/modules/nj635_scout/views/635/script.php');

let failed = 0;
function check(label, ok, extra = '') {
    if (ok) {
        console.log(`  ок   ${label}`);
    } else {
        failed++;
        console.log(`  ФЕЙЛ ${label}${extra ? ` — ${extra}` : ''}`);
    }
}

/* ---------- крошечный DOM ---------- */
class El {
    constructor(tag, attrs = {}, sel = []) {
        this.tagName = tag.toUpperCase();
        this.attrs = { ...attrs };
        this._sel = sel;
        this._children = [];
        this.replacedWith = null;
        this._html = '';
        this.dataset = {};

        for (const [k, v] of Object.entries(attrs)) {
            if (k.startsWith('data-')) {
                const key = k.slice(5).replace(/-([a-z])/g, (m, c) => c.toUpperCase());
                this.dataset[key] = String(v);
            }
        }
    }

    getAttribute(n) { return n in this.attrs ? String(this.attrs[n]) : null; }
    setAttribute(n, v) { this.attrs[n] = String(v); }
    matches(sel) {
        if (sel.startsWith('[') && sel.endsWith(']')) {
            const body = sel.slice(1, -1);
            const [name, value] = body.split('=');
            const attr = value !== undefined ? value.replace(/^"|"$/g, '') : null;

            return (this.attrs[name] !== undefined) && (attr === null || String(this.attrs[name]) === attr);
        }

        return this._sel.includes(sel);
    }

    querySelector(sel) {
        for (const c of this._children) {
            if (c.matches(sel)) return c;
            const deep = c.querySelector(sel);
            if (deep) return deep;
        }

        return null;
    }

    querySelectorAll(sel) {
        const out = [];

        for (const c of this._children) {
            if (c.matches(sel)) out.push(c);
            out.push(...c.querySelectorAll(sel));
        }

        return out;
    }

    appendChild(el) { this._children.push(el); return el; }

    set outerHTML(v) { this.replacedWith = v; }
    get outerHTML() { return this._html; }
    set textContent(v) { this._text = String(v); }
    get textContent() { return this._text === undefined ? '' : this._text; }
    set innerHTML(v) { this._html = String(v); }
    get innerHTML() { return this._html; }
}

/* ---------- страница + управляемый таймер ---------- */
function open({ steps: initial, api = [] } = {}) {
    const php = fs.readFileSync(SCRIPT, 'utf8');
    const code = php.match(/<script>([\s\S]*?)<\/script>/)[1];

    const blocks = new El('div', { 'data-nj635-blocks': '' }, ['[data-nj635-blocks]']);

    for (const s of initial) {
        const el = new El('div', { 'data-nj635-step': s.key, 'data-rev': s.rev }, [`[data-nj635-step="${s.key}"]`]);
        el._html = `<div data-nj635-step="${s.key}">${s.text || ''}</div>`;
        blocks.appendChild(el);
    }

    const root = new El('div', { 'data-nj635-report': '', 'data-api': '/check/api/status?p=sct1' });
    root.appendChild(blocks);
    root.appendChild(new El('span', { 'data-nj635-counter': '' }, ['[data-nj635-counter]']));
    root.appendChild(new El('span', { 'data-nj635-hint': '' }, ['[data-nj635-hint]']));

    const timers = { intervals: new Map(), timeouts: new Map(), nextId: 1, reloads: 0 };
    const responses = api.slice();

    globalThis.document = { querySelector: (sel) => root.matches(sel) ? root : null };
    globalThis.window = { location: { reload: () => timers.reloads++ } };
    globalThis.fetch = async () => {
        const body = responses.length ? responses.shift() : { ok: false };

        if (body.__error) throw new Error('сеть');

        return { json: async () => body };
    };
    globalThis.setInterval = (fn, ms) => { const id = timers.nextId++; timers.intervals.set(id, { fn, ms }); return id; };
    globalThis.clearInterval = (id) => timers.intervals.delete(id);
    globalThis.setTimeout = (fn, ms) => { const id = timers.nextId++; timers.timeouts.set(id, { fn, ms }); return id; };
    globalThis.clearTimeout = (id) => timers.timeouts.delete(id);

    new Function(code)();

    return {
        root,
        blocks,
        timers,
        counter: () => root.querySelector('[data-nj635-counter]').textContent,
        hint: () => root.querySelector('[data-nj635-hint]').innerHTML,
        block: (key) => blocks.querySelector(`[data-nj635-step="${key}"]`),
        // один цикл опроса: ждём микротаски fetch/JSON
        poll: async () => {
            const iv = [...timers.intervals.values()].find((t) => t.ms === 4000);
            if (!iv) return;
            iv.fn();
            for (let i = 0; i < 6; i++) await Promise.resolve();
        },
        tick: (times = 1) => {
            const iv = [...timers.intervals.values()].find((t) => t.ms === 1000);
            for (let i = 0; i < times && iv; i++) iv.fn();
        },
        fireTimeouts: () => { for (const t of [...timers.timeouts.values()]) t.fn(); },
        polling: () => [...timers.intervals.values()].some((t) => t.ms === 4000),
    };
}

/* 1. Уже готовый отчёт: опрос не перезагружает страницу по кругу */
console.log('1. Отчёт уже собран (страница только что открыта)');
{
    const ui = open({
        steps: [{ key: 'dossier', rev: 'aaa', text: 'Итог' }],
        api: [{ ok: true, done: true, steps: { dossier: { key: 'dossier', status: 'done', rev: 'aaa', html: '<i>д</i>' } } }],
    });

    await ui.poll();

    check('опрос остановлен после терминального состояния', ui.polling() === false);
    check('блок не перерисован (отпечаток тот же)', ui.block('dossier').replacedWith === null);
    ui.fireTimeouts();
    check('перезагрузки нет — иначе был бы цикл', ui.timers.reloads === 0, `reloads=${ui.timers.reloads}`);
    check('счётчик показывает готовность', ui.counter() === 'готово 1 из 1', ui.counter());
}

/* 2. Идёт сборка: меняется этап — блок обновляется, перезагрузка после готовности */
console.log('\n2. Сборка идёт → готово');
{
    const ui = open({
        steps: [
            { key: 'dossier', rev: 'r1', text: 'Собираем' },
            { key: 'profile', rev: 'p1', text: 'Ждём' },
        ],
        api: [
            { ok: true, done: false, steps: {
                dossier: { key: 'dossier', status: 'running', rev: 'r2', html: '<i>этап 2</i>' },
                profile: { key: 'profile', status: 'queued', rev: 'p1', html: '<i>ждём</i>' },
            } },
            { ok: true, done: true, steps: {
                dossier: { key: 'dossier', status: 'done', rev: 'r3', html: '<i>итог</i>' },
                profile: { key: 'profile', status: 'done', rev: 'p2', html: '<i>профиль</i>' },
            } },
        ],
    });

    await ui.poll();
    check('изменившийся блок подменён', ui.block('dossier').replacedWith === '<i>этап 2</i>');
    check('неизменившийся блок не трогаем', ui.block('profile').replacedWith === null);
    check('опрос продолжается, пока шаги идут', ui.polling() === true);

    await ui.poll();
    check('после готовности опрос остановлен', ui.polling() === false);
    ui.fireTimeouts();
    check('страница перезагружается один раз', ui.timers.reloads === 1, `reloads=${ui.timers.reloads}`);
    check('счётчик: 2 из 2', ui.counter() === 'готово 2 из 2', ui.counter());
}

/* 3. Секундомер тикает локально и не откатывается */
console.log('\n3. Секундомер идущего шага');
{
    const timer = new El('span', { 'data-nj635-timer': '', 'data-elapsed': '5' }, ['[data-nj635-timer]']);
    timer.textContent = '5 с';

    const ui = open({
        steps: [{ key: 'dossier', rev: 'r1' }],
        api: [{ ok: true, done: false, steps: { dossier: { key: 'dossier', status: 'running', rev: 'r1', html: '<i>то же</i>' } } }],
    });
    ui.root.appendChild(timer);

    ui.tick(3);
    check('секунды идут от факта', timer.textContent === '8 с', timer.textContent);

    await ui.poll();
    check('опрос не откатывает секундомер', timer.textContent === '8 с', timer.textContent);
    check('блок без изменений не перерисовывается', ui.block('dossier').replacedWith === null);

    ui.tick(120);
    check('после 2 минут — минуты и секунды', /2 мин \d+ с/.test(timer.textContent), timer.textContent);
}

/* 4. Связь пропала: честная подсказка, без перезагрузки */
console.log('\n4. Потеря связи');
{
    const ui = open({
        steps: [{ key: 'dossier', rev: 'r1' }],
        api: [{ __error: true }, { __error: true }, { __error: true }],
    });

    await ui.poll();
    await ui.poll();
    check('после двух сбоев молчим', ui.hint().indexOf('связь потеряна') === -1, ui.hint());
    await ui.poll();
    check('после третьего — «связь потеряна»', ui.hint().indexOf('связь потеряна') !== -1, ui.hint());
    check('перезагрузки нет', ui.timers.reloads === 0);
}

/* 5. Ответ без ok (ошибка сервера) не ломает страницу */
console.log('\n5. Сервер ответил ошибкой');
{
    const ui = open({
        steps: [{ key: 'dossier', rev: 'r1' }],
        api: [{ ok: false, error: 'Не удалось получить состояние отчёта' }],
    });

    await ui.poll();
    check('блок не тронут', ui.block('dossier').replacedWith === null);
    check('опрос продолжается (попробуем снова)', ui.polling() === true);
}

console.log(`\n${failed === 0 ? 'Живой отчёт ведёт себя правильно.' : `Провалов: ${failed}`}`);
process.exit(failed === 0 ? 0 : 1);
