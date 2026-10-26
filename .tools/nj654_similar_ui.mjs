#!/usr/bin/env node
/* =============================================================================
 * N2J — «Похожие картинки»: поиск без перезагрузки (nj654_similar).
 *
 *   node .tools/nj654_similar_ui.mjs            # сам отрендерит вёрстку стендом
 *   N2J_UI_DUMP=/tmp/nj654-ui node …            # взять уже готовый дамп HTML
 *
 * Зачем. Владелец (08.10.2026) описал поведение словами:
 *   • «нажимаю кнопку поиска — форма с полями пропадает, вместо неё
 *     информация, что ищем, и индикатор процесса»;
 *   • «когда что-то находится, результаты появляются ниже плитками»;
 *   • «страница не перезагружается»;
 *   • «после отправки фокус НЕ возвращается в поле ввода — на телефоне
 *     клавиатура закрывает результаты».
 *
 * Ни PHP-линт, ни стенд вьюх этого не видят: там разметка и якоря, а не
 * поведение. Поэтому настоящий HTML (его отдаёт `nj654_similar_stand.php
 * --dump-ui=<dir>` — та же вёрстка, что уезжает в браузер) загружается в jsdom,
 * fetch подменяется управляемым, а таймеры ускоряются: опрос 2,5 с
 * превращается в десятки миллисекунд.
 *
 * Зависимость одна — jsdom (уже в devDependencies, как у tests/Js/*).
 * ========================================================================== */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

let failed = 0;
let checks = 0;

function check(label, ok, extra = '') {
    checks++;

    if (ok) {
        console.log(`  ок   ${label}`);
    } else {
        failed++;
        console.log(`  ФЕЙЛ ${label}${extra ? ` — ${extra}` : ''}`);
    }
}

const tick = (ms = 120) => new Promise((resolve) => setTimeout(resolve, ms));

/* ---------- вёрстка: стенд модуля отдаёт её как HTML ---------------------- */

function dumpDir() {
    if (process.env.N2J_UI_DUMP) {
        return process.env.N2J_UI_DUMP;
    }

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nj654-ui-'));

    try {
        execFileSync('php', [path.join(ROOT, '.tools/nj654_similar_stand.php'), `--dump-ui=${dir}`], {
            cwd: ROOT,
            stdio: 'ignore',
        });
    } catch (error) {
        console.error(`Нужен PHP и рабочий стенд модуля: ${error.message}`);
        process.exit(1);
    }

    return dir;
}

const DIR = dumpDir();
const page = (name) => fs.readFileSync(path.join(DIR, name), 'utf8');

/* ---------- страница в jsdom с управляемым fetch -------------------------- */

const CARD = {
    id: 42,
    title: 'Красный закат',
    kind: 'image',
    file: '/storage/nj654/202610/zakat.jpg',
    score: 0.9762,
    score_text: '0,976',
    say: 'почти то же самое',
    tone: 'emerald',
};

function jsonResponse(payload, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        redirected: false,
        url: 'https://n2j.ru/similar/find',
        text: async () => JSON.stringify(payload),
        json: async () => payload,
    };
}

/**
 * Открыть страницу модуля.
 *
 * `answers` — очередь ответов сервера: сначала POST поиска, затем опросы
 * состояния. Всё, что не описано, повторяет последний ответ (опрос идёт, пока
 * движок не увидит терминальный статус).
 */
function open(file, answers, { ui = 1, at = null } = {}) {
    const calls = [];
    const errors = [];
    const virtualConsole = new VirtualConsole();

    virtualConsole.on('jsdomError', (error) => errors.push(String(error.message || error)));

    const dom = new JSDOM(page(file), {
        // Адрес — как в бою: страница поиска или /similar/result/<id>.
        url: `https://n2j.ru${at || '/similar'}${ui > 1 ? `?ui=${ui}` : ''}`,
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        virtualConsole,
        beforeParse(window) {
            // Время под контролем: ждать 2,5 с между опросами в тесте не нужно.
            const scale = 0.02;
            const later = window.setTimeout;
            const every = window.setInterval;

            window.setTimeout = (fn, ms, ...rest) => later(fn, Math.max(1, Math.round((ms || 0) * scale)), ...rest);
            window.setInterval = (fn, ms, ...rest) => every(fn, Math.max(1, Math.round((ms || 0) * scale)), ...rest);

            window.Element.prototype.scrollIntoView = function () {};

            // В jsdom нет createObjectURL — движок проверяет его наличие, но
            // для честности даём заглушку: с ней проверяется и предпросмотр.
            window.URL.createObjectURL = () => 'blob:nj654-preview';
            window.URL.revokeObjectURL = () => {};

            let step = 0;

            window.fetch = (url, options = {}) => {
                const answer = answers[Math.min(step, answers.length - 1)];
                step++;

                calls.push({ url: String(url), options, answer });

                return Promise.resolve(
                    typeof answer === 'function'
                        ? answer(String(url), options)
                        : jsonResponse(answer.payload, answer.status || 200),
                );
            };
        },
    });

    return { dom, window: dom.window, doc: dom.window.document, calls, errors };
}

/* ---------- 1. Чистая страница: отправка, ожидание, находки --------------- */

async function submitFlow() {
    console.log('\n== Отправка, ожидание, находки ==');

    const answers = [
        { payload: { ok: true, id: 7, url: '/similar/result/7', status: '/similar/api/status?q=7' } },
        { payload: { ok: true, status: 'running', stage: 'Сравниваю смысл запроса…', elapsed_sec: 1, found: 0, cards: [] } },
        { payload: { ok: true, status: 'done', elapsed_sec: 2, found: 1, cards: [CARD] } },
    ];

    const box = open('ui1-fresh.html', answers);
    const { window, doc } = box;

    const form  = doc.getElementById('nj654-form');
    const input = doc.getElementById('nj654-text');
    const wrap  = doc.querySelector('[data-nj654-form]');
    const panel = doc.querySelector('[data-nj654-panel]');
    const grid  = doc.querySelector('[data-nj654-grid]');

    // Якоря состояния живут внутри карточки запроса: корень носит те же имена,
    // что и его поля (data-nj654-elapsed — и значение, и подпись таймера).
    const state = (sel) => panel.querySelector(sel);

    check('движок поднялся на странице', typeof window.NJ654 === 'object' && window.NJ654 !== null);
    check('до поиска видна форма, карточка запроса скрыта',
        !wrap.classList.contains('hidden') && panel.classList.contains('hidden'));

    input.value = 'рыжий кот';
    input.focus();

    check('поле ввода получило фокус до отправки', doc.activeElement === input);

    form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

    check('форма пропала сразу после нажатия «Найти»', wrap.classList.contains('hidden'));
    check('вместо формы — карточка «что ищем»',
        !panel.classList.contains('hidden')
        && doc.querySelector('[data-nj654-q]').textContent.includes('рыжий кот'));
    check('фокус ушёл из поля ввода и не вернулся (клавиатура закроется)',
        doc.activeElement !== input && doc.activeElement.tagName === 'BODY');
    check('показан неразрывный индикатор процесса',
        state('[data-nj654-title]').textContent.includes('Ищу')
        && /^\d+:\d\d$/.test(state('[data-nj654-elapsed]').textContent.trim()));

    await tick(200);

    check('POST ушёл на /similar/find с CSRF и заголовком fetch',
        box.calls.length > 0
        && box.calls[0].url.endsWith('/similar/find')
        && box.calls[0].options.method === 'POST'
        && box.calls[0].options.headers['X-Requested-With'] === 'XMLHttpRequest'
        && box.calls[0].options.body instanceof window.FormData);

    const tiles = doc.querySelectorAll('[data-nj654-grid] li[data-nj654-tile]');
    const tile  = tiles[0];

    check('находка появилась плиткой ниже, без перезагрузки', tiles.length === 1);
    check('подпись, число и словесная оценка на месте',
        tile && tile.querySelector('[data-nj654-tile-title]').textContent === 'Красный закат'
        && tile.querySelector('[data-nj654-tile-score]').textContent === '0,976'
        && tile.querySelector('[data-nj654-tile-say]').textContent === 'почти то же самое');
    check('цвет точки близости совпадает со шкалой',
        tile && tile.querySelector('[data-nj654-tile-tone]').className.includes('bg-emerald-500'));
    check('картинка открывается окном просмотра',
        tile && tile.querySelector('[data-nj654-tile-photo]').getAttribute('data-nj654-zoom') === CARD.file);
    check('статус завершён и число находок видно',
        state('[data-nj654-title]').textContent === 'Готово'
        && state('[data-nj654-found]').textContent.includes('1')
        && !state('[data-nj654-found]').classList.contains('hidden'));
    check('адрес сменился на страницу результата (F5 не потеряет поиск)',
        window.location.pathname === '/similar/result/7');
    check('опрос остановился после терминального статуса',
        box.calls.length === 3, `вызовов: ${box.calls.length}`);

    // Возврат к форме: значения целы, страница не перезагружается.
    doc.querySelector('[data-nj654-new]').click();

    check('«Новый поиск» возвращает форму с прежним запросом',
        !wrap.classList.contains('hidden')
        && panel.classList.contains('hidden')
        && doc.getElementById('nj654-text').value === 'рыжий кот');
    check('адрес вернулся на страницу поиска',
        window.location.pathname === '/similar');
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- 2. Страница в работе: движок досматривает поиск --------------- */

async function resumeFlow() {
    console.log('\n== Продолжение поиска после F5 ==');

    const answers = [
        { payload: { ok: true, status: 'running', stage: 'Сравниваю смысл запроса…', elapsed_sec: 3, found: 0, cards: [] } },
        { payload: { ok: true, status: 'done', elapsed_sec: 4, found: 1, cards: [CARD] } },
    ];

    const box = open('ui1-busy.html', answers, { at: '/similar/result/6' });
    const { doc } = box;

    check('страница в работе открывается карточкой запроса, без формы',
        doc.querySelector('[data-nj654-form]').classList.contains('hidden')
        && !doc.querySelector('[data-nj654-panel]').classList.contains('hidden')
        && doc.querySelector('[data-nj654-id]').getAttribute('data-nj654-id') === '6');

    await tick(250);

    check('находки достроились сами (без перезагрузки)',
        doc.querySelectorAll('[data-nj654-grid] li[data-nj654-tile]').length === 1
        && doc.querySelector('[data-nj654-panel] [data-nj654-title]').textContent === 'Готово');
    check('адрес остался страницей результата',
        doc.location.pathname === '/similar/result/6');
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- 3. Отказ воркера: причина и возврат к запросу ----------------- */

async function terminalRefusal() {
    console.log('\n== Отказ до открытия страницы: причина уже на странице ==');

    const box = open('ui1-dead.html', [{ payload: { ok: true, status: 'failed', error: 'не должен был опрашиваться' } }],
        { ui: 1, at: '/similar/result/7' });
    const { doc } = box;

    check('причина отказа видна, а не спрятана за «что-то пошло не так»',
        !doc.querySelector('[data-nj654-error]').classList.contains('hidden')
        && doc.querySelector('[data-nj654-error-text]').textContent.includes('Очередь'));
    check('разбор отказа: статус-ряд подписан «Поиск не выполнен»',
        doc.querySelector('[data-nj654-panel] [data-nj654-title]').textContent === 'Поиск не выполнен');
    check('терминальный отказ не опрашивает сервер',
        box.calls.length === 0, `вызовов: ${box.calls.length}`);

    doc.querySelector('[data-nj654-reset]').click();

    check('«Вернуться к запросу» показывает форму и подставляет текст запроса',
        !doc.querySelector('[data-nj654-form]').classList.contains('hidden')
        && doc.getElementById('nj654-text').value.includes('рыжий кот'));
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- 3.1. Отказ пришёл опросом, уже на открытой странице ----------- */

async function apiFailure() {
    console.log('\n== Отказ пришёл опросом: причина показывается без перезагрузки ==');

    const answers = [
        { payload: { ok: true, id: 7, url: '/similar/result/7' } },
        { payload: { ok: true, status: 'failed', error: 'модель недоступна', cards: [] } },
    ];

    const box = open('ui1-fresh.html', answers, { ui: 1 });
    const { window, doc } = box;

    doc.getElementById('nj654-text').value = 'рыжий кот';
    doc.getElementById('nj654-form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

    await tick(200);

    check('отказ из очереди показан причиной',
        !doc.querySelector('[data-nj654-error]').classList.contains('hidden')
        && doc.querySelector('[data-nj654-error-text]').textContent.includes('модель недоступна'));
    check('после отказа опрос остановлен',
        box.calls.length === 2, `вызовов: ${box.calls.length}`);

    doc.querySelector('[data-nj654-reset]').click();

    check('форма вернулась с прежним запросом',
        !doc.querySelector('[data-nj654-form]').classList.contains('hidden')
        && doc.getElementById('nj654-text').value === 'рыжий кот');
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- 4. Отказ сервера на отправке: сообщение рядом с формой ------- */

async function deniedFlow() {
    console.log('\n== Отказ сервера: «сессия устарела» рядом с формой ==');

    const box = open('ui1-fresh.html', [
        { payload: { ok: false, error: 'Сессия устарела — обновите страницу и повторите.' }, status: 403 },
    ], { ui: 1 });
    const { window, doc } = box;

    doc.getElementById('nj654-text').value = 'кот';
    doc.getElementById('nj654-form').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));

    await tick(200);

    check('отказ сервера показан текстом, а не молчанием',
        !doc.querySelector('[data-nj654-error]').classList.contains('hidden')
        && doc.querySelector('[data-nj654-error-text]').textContent.includes('Сессия устарела'));
    check('форма при этом остаётся скрытой, фокус не возвращается',
        doc.querySelector('[data-nj654-form]').classList.contains('hidden')
        && doc.activeElement.tagName === 'BODY');

    doc.querySelector('[data-nj654-reset]').click();

    check('после возврата запрос не потерян',
        doc.getElementById('nj654-text').value === 'кот');
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- запуск -------------------------------------------------------- */

console.log(`Вёрстка для проверки: ${DIR}`);

await submitFlow();
await resumeFlow();

await terminalRefusal();
await apiFailure();
await deniedFlow();

console.log(`\n== Итог ==\n\n  проверок: ${checks}\n`);

if (failed > 0) {
    console.log(`  Провалов: ${failed}\n`);
    process.exit(1);
}

console.log('  Все проверки пройдены\n');
