#!/usr/bin/env node
/* =============================================================================
 * N2J — «Кузница систем» (erp222_forge): живой ход цикла без перезагрузки.
 *
 *   node .tools/erp222_forge_ui.mjs                 # вёрстку отдаст стенд модуля
 *   N2J_FORGE_UI_DUMP=/tmp/forge-ui node .tools/erp222_forge_ui.mjs
 *
 * Зачем. Владелец (08.10.2026): «пересмотри всю логику, нужно чтобы модуль
 * формировал ценные и полезные артефакты и был ИНТЕРЕСЕН», а по дизайн-эталону
 * интерес начинается с того, что видно процесс: спиннер + название этапа +
 * секундомер одним неразрывным рядом (правило 4 /pages/required_design_sample).
 *
 * Ни PHP-линт, ни стенд вьюх этого не видят: там разметка и якоря, а не
 * поведение. Поэтому настоящий HTML (его отдаёт
 * `php .tools/erp222_forge_stand.php --dump-ui=<dir>` — та же вёрстка, что
 * уезжает в браузер) загружается в jsdom, fetch подменяется управляемым, а
 * таймеры ускоряются: опрос 3 с превращается в десятки миллисекунд.
 *
 * Что проверяется:
 *  1. живой цикл: карточка хода видна, спиннер крутится, секундомер тикает
 *     САМ между опросами, лестница этапов показывает текущий;
 *  2. «Запустить цикл»: fetch вместо перехода (X-Requested-With), карточка
 *     оживает сразу, затем идёт опрос /forge/progress и данные сервера
 *     отрисовываются (этап, заметка, счётчики, роль);
 *  3. «Один шаг»: кнопка-отправитель уезжает в запрос как quick=one — иначе
 *     сервер запустил бы полный цикл вместо одного шага;
 *  4. завершение цикла: движок не падает, останавливает опрос;
 *  5. «взведённая» кнопка публикации: первый клик не отправляет форму и меняет
 *     подпись, второй — отправляет (window.confirm в проекте не используется);
 *  6. «Скопировать документ»: копируется ровно тело документа.
 *
 * Зависимость одна — jsdom (в devDependencies, как у tests/Js/*).
 * ========================================================================== */

import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ENGINE = fs.readFileSync(path.join(ROOT, 'public_html/assets/js/erp222-forge.js'), 'utf8');
// Общий слой (window.N2J): взвод кнопок data-n2j-arm, тосты — как в шаблоне сайта.
const APP = fs.readFileSync(path.join(ROOT, 'public_html/assets/js/app.js'), 'utf8');

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

const tick = (ms = 150) => new Promise((resolve) => setTimeout(resolve, ms));

/* ---------- вёрстка: стенд модуля отдаёт её как HTML ---------------------- */

function dumpDir() {
    if (process.env.N2J_FORGE_UI_DUMP) {
        return process.env.N2J_FORGE_UI_DUMP;
    }

    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'forge-ui-'));

    try {
        execFileSync('php', [path.join(ROOT, '.tools/erp222_forge_stand.php'), `--dump-ui=${dir}`], {
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

function jsonResponse(payload, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        redirected: false,
        url: 'https://n2j.ru/forge/progress',
        text: async () => JSON.stringify(payload),
        json: async () => payload,
    };
}

/* ---------- страница в jsdom с управляемым fetch -------------------------- */

function open(file, answers = []) {
    const calls = [];
    const errors = [];
    const submissions = [];
    const virtualConsole = new VirtualConsole();

    virtualConsole.on('jsdomError', (error) => {
        const text = String(error.message || error);

        // Навигацию jsdom не умеет — она заменена заглушками ниже; всё
        // остальное (в том числе «Undefined variable» в движке) — провал.
        if (!text.includes('Not implemented')) errors.push(text);
    });

    const dom = new JSDOM(page(file), {
        url: 'https://n2j.ru/forge',
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        virtualConsole,
        beforeParse(window) {
            // Время под контролем: ждать 3 с между опросами в тесте не нужно.
            const scale = 0.02;
            const later = window.setTimeout;
            const every = window.setInterval;

            window.setTimeout = (fn, ms, ...rest) => later(fn, Math.max(1, Math.round((ms || 0) * scale)), ...rest);
            window.setInterval = (fn, ms, ...rest) => every(fn, Math.max(1, Math.round((ms || 0) * scale)), ...rest);

            // Перезагрузку страницы не подменяем: location.reload в jsdom —
            // непереопределяемое свойство, а её вызов даёт «Not implemented:
            // navigation» (фильтр ниже его гасит). Движок после финала цикла
            // именно перезагружает страницу — проверяем, что опрос остановлен.

            // Отправка формы без JS (второй клик «взведённой» кнопки) — тоже
            // навигация: считаем событие и не пускаем jsdom в «Not implemented».
            window.HTMLFormElement.prototype.submit = function () {
                submissions.push(this.getAttribute('action') || '');
                this.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
            };

            window.document.addEventListener('submit', (event) => {
                const form = event.target;

                if (form instanceof window.HTMLFormElement) {
                    submissions.push(form.getAttribute('action') || '');
                }
            }, true);

            let step = 0;

            window.fetch = (url, options = {}) => {
                // Учёт посещения из общего app.js к движку не относится: не
                // записываем и не тратим на него подготовленные ответы.
                if (String(url).startsWith('/nj516/')) {
                    return Promise.resolve(jsonResponse({ ok: true }));
                }
                const answer = answers[Math.min(step, answers.length - 1)];
                step++;

                calls.push({ url: String(url), options });

                return Promise.resolve(
                    typeof answer === 'function' ? answer(String(url), options) : jsonResponse(answer),
                );
            };
        },
    });

    dom.window.__submissions = submissions;

    return { dom, window: dom.window, doc: dom.window.document, calls, errors, submissions };
}

/**
 * Включить движок — как браузер.
 *
 * Стенд отдаёт только содержимое модуля, `<script>` подключает шаблон страницы,
 * а jsdom разбирает разметку асинхронно: до DOMContentLoaded движок не находит
 * якоря и молча ничего не делает. Поэтому ждём разбор и выполняем скрипт.
 */
async function boot(box) {
    if (box.doc.readyState === 'loading') {
        await new Promise((resolve) => box.doc.addEventListener('DOMContentLoaded', resolve, { once: true }));
    }

    box.window.eval(APP);
    box.window.eval(ENGINE);
    await tick(120);
}

/* ---------- 1. Живой цикл: спиннер, этап, секундомер, лестница ----------- */

async function liveFlow() {
    console.log('\n== Живой цикл: спиннер, этап и секундомер одним рядом ==');

    const box = open('index-live.html', [{ ok: true, run: RUN_LIVE, pulse: true }]);
    const { window, doc } = box;

    // Движок уже загружен вёрсткой? Нет: его подключает шаблон страницы, а
    // стенд отдаёт только контент модуля — выполняем его сами, как браузер.
    await boot(box);

    const root = doc.querySelector('[data-erp222-run]');
    const stage = doc.querySelector('[data-erp222-stage]');
    const elapsed = doc.querySelector('[data-erp222-elapsed-text]');

    check('карточка хода не скрыта', !root.hidden);
    check('спиннер крутится', (doc.querySelector('[data-erp222-spin]') || {}).className?.includes('animate-spin') === true);
    check('этап показан словом', stage.textContent.trim().length > 0, stage.textContent);
    check('этап и секундомер стоят рядом (одна строка статус-ряда)',
        stage.closest('div') === elapsed.closest('div'));
    check('счётчики — числом, а не полосой', doc.querySelector('[data-erp222-counter]').textContent.includes('шаг'));
    check('лестница: текущий этап выделен жирным, пройденные зелёные',
        doc.querySelector('[data-erp222-stage-item="0"]').className.includes('emerald')
        && /font-bold/.test(doc.querySelector('[data-erp222-stage-item="1"]').className),
        doc.querySelector('[data-erp222-stage-item="1"]').className);

    // Секундомер обязан тикать САМ: между опросами владелец видит движение.
    // Опрос «выключаем» (fetch никогда не отвечает) — тогда изменение текста
    // может прийти только от локального секундомера, а не от сервера.
    // Ждём по РЕАЛЬНОМУ времени: ускоряются таймеры, а не часы.
    window.fetch = () => new Promise(() => {});

    const before = elapsed.textContent.trim();
    await tick(1300);
    check('секундомер тикает между опросами сервера', elapsed.textContent.trim() !== before,
        `${before} → ${elapsed.textContent.trim()}`);

    check('опрос идёт на /forge/progress', box.calls.some((c) => c.url === '/forge/progress'));
    check('опрос помечен как AJAX', box.calls.every((c) => c.options.headers['X-Requested-With'] === 'XMLHttpRequest'));
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

const RUN_LIVE = {
    id: 3,
    status: 'running',
    live: true,
    stage: 'Синтез',
    stage_note: 'шаг 4/6 · Инженер → Контракт API',
    stage_index: 1,
    stages: ['Роли', 'Синтез', 'Критика', 'Сводка'],
    role_code: 'engineer',
    role_title: 'Инженер',
    elapsed_sec: 65,
    counters_text: 'шаг 4/6 · артефактов: 3 · ценность: 240',
    error: '',
};

/* ---------- 2. Запуск цикла без перезагрузки ------------------------------ */

async function startFlow(useOneStep) {
    console.log(`\n== Запуск ${useOneStep ? '«Один шаг»' : 'цикла'} без перезагрузки ==`);

    const box = open('index-fresh.html', [
        { ok: true, error: '', run: RUN_LIVE },
        { ok: true, run: { ...RUN_LIVE, id: 4, stage: 'Критика', stage_index: 2, role_title: 'Критик',
            counters_text: 'шаг 5/6 · артефактов: 4 · ценность: 320', elapsed_sec: 140 } },
    ]);
    const { window, doc } = box;

    await boot(box);

    const form = doc.querySelector('[data-erp222-run-form]');
    const card = doc.querySelector('[data-erp222-run]');
    const stage = doc.querySelector('[data-erp222-stage]');

    check('до запуска карточка хода скрыта', card.hidden);

    const button = useOneStep
        ? doc.querySelector('[data-erp222-one-step]')
        : doc.querySelector('[data-erp222-run-button]');

    // Нажимаем кнопку — браузер отправляет форму с ней как с submitter
    // (в jsdom requestSubmit объявлен нереализованным, поэтому клик честнее).
    button.click();

    // Проверяем синхронно: ответ сервера приходит микрозадачей и сразу
    // перерисовывает карточку — «ожила сразу» видно только до него.
    check('карточка ожила сразу, до ответа сервера', !card.hidden
        && card.getAttribute('data-erp222-status') === 'new');

    await tick(80);

    // Адрес формы браузер отдаёт абсолютным (form.action), опрос идёт по
    // относительному — сравниваем по концу пути.
    const post = box.calls.find((c) => c.url.endsWith('/forge/step'));

    check('запрос ушёл fetch-ом (страница не перезагружается)', !!post);
    check('запрос помечен как AJAX (X-Requested-With)', post?.options?.headers['X-Requested-With'] === 'XMLHttpRequest');
    check('метод POST и тот же источник', post?.options?.method === 'POST' && post?.options?.credentials === 'same-origin');

    const body = post?.options?.body;

    check('в теле — мир, токен и адрес возврата',
        typeof body?.get === 'function'
        && body.get('world_id') === '1'
        && body.get('csrf') === 'stand-csrf'
        && String(body.get('back') || '').startsWith('/forge'));

    if (useOneStep) {
        check('«Один шаг» уехал как quick=one (иначе сервер запустит полный цикл)', body.get('quick') === 'one',
            String(body.get('quick')));
    } else {
        check('обычный запуск не притворяется одним шагом', body.get('quick') !== 'one', String(body.get('quick')));
    }

    await tick(200);

    check('данные сервера отрисованы: этап', stage.textContent === 'Синтез' || stage.textContent === 'Критика',
        stage.textContent);
    check('данные сервера отрисованы: счётчики', doc.querySelector('[data-erp222-counter]').textContent.includes('шаг'));
    check('роль шага показана', (doc.querySelector('[data-erp222-role]') || {}).textContent === 'Инженер'
        || (doc.querySelector('[data-erp222-role]') || {}).textContent === 'Критик');
    check('опрос продолжается, пока цикл жив', box.calls.some((c) => c.url === '/forge/progress'));
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- 3. Отказ сервера: кнопка снова живая, сказано почему ---------- */

async function deniedFlow() {
    console.log('\n== Отказ сервера: «цикл уже идёт» вместо тишины ==');

    const box = open('index-fresh.html', [{ ok: false, error: 'Цикл уже идёт — дождитесь завершения' }]);
    const { window, doc } = box;

    await boot(box);

    const form = doc.querySelector('[data-erp222-run-form]');
    const button = doc.querySelector('[data-erp222-run-button]');

    button.click();
    await tick(200);

    const error = doc.querySelector('[data-erp222-error]');

    check('причина показана текстом', !!error && error.textContent.includes('Цикл уже идёт'));
    check('кнопка снова доступна', button.disabled === false);
    check('подпись вернулась', doc.querySelector('[data-erp222-run-label]').textContent === 'Запустить цикл');
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- 4. Завершение цикла: опрос останавливается ------------------- */

async function doneFlow() {
    console.log('\n== Цикл завершён: опрос останавливается ==');

    const box = open('index-live.html', [
        { ok: true, run: { ...RUN_LIVE, status: 'done', live: false, stage: 'Сводка',
            stage_note: 'цикл завершён', stage_index: 3 } },
    ]);
    const { window, doc } = box;

    await boot(box);
    await tick(300);

    const callsAfter = box.calls.length;

    await tick(400);

    check('после завершения новых опросов нет', box.calls.length === callsAfter,
        `${callsAfter} → ${box.calls.length}`);
    check('видно «завершён»', doc.querySelector('[data-erp222-status-pill]').textContent === 'завершён');
    check('спиннер сменился галочкой', doc.querySelector('[data-erp222-spin]').className.includes('fa-circle-check'));
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- 5. Публикация: «взведённая» кнопка ---------------------------- */

async function armedFlow() {
    console.log('\n== Публикация: два клика (data-n2j-arm из app.js) вместо window.confirm ==');

    const box = open('artifact.html', []);
    const { window, doc } = box;

    await boot(box);

    const submit = doc.querySelector('form button[type="submit"][data-n2j-arm]');
    const idle = submit.innerHTML;

    submit.click();
    await tick(20);

    check('первый клик форму НЕ отправляет', box.submissions.length === 0);
    check('подпись стала «взведённой»', submit.innerHTML !== idle && submit.innerHTML.includes('Точно'),
        submit.innerHTML);
    check('кнопка взведена общим классом is-armed', submit.classList.contains('is-armed'), submit.className);

    submit.click();
    await tick(20);

    check('второй клик отправляет форму', box.submissions.length > 0);

    const fresh = open('artifact.html', []);
    await boot(fresh);

    const submit2 = fresh.doc.querySelector('form button[type="submit"][data-n2j-arm]');

    submit2.click();
    await tick(20);

    // Если второго клика нет — кнопка обязана вернуться в исходный вид сама
    // (иначе владелец видит «Точно снять?» и не понимает, что это не отправлено).
    await tick(80 * 8);

    check('без второго клика кнопка возвращается сама', !submit2.innerHTML.includes('Точно'), submit2.innerHTML);
    check('ошибок JS нет', box.errors.length === 0 && fresh.errors.length === 0);

    box.dom.window.close();
    fresh.dom.window.close();
}

/* ---------- 6. Копирование документа -------------------------------------- */

async function copyFlow() {
    console.log('\n== «Скопировать документ»: в буфер уходит тело документа ==');

    const box = open('artifact.html', []);
    const { window, doc } = box;
    const copied = [];

    // jsdom не реализует буфер обмена: подменяем свойство, а не пишем в него
    // (в модуле строгий режим — присваивание в геттер без сеттера упало бы).
    Object.defineProperty(window.navigator, 'clipboard', {
        configurable: true,
        value: { writeText: (text) => { copied.push(text); return Promise.resolve(); } },
    });

    await boot(box);

    const button = doc.querySelector('[data-erp222-copy]');
    const source = doc.querySelector('[data-erp222-copy-source]');

    button.click();
    await tick(10); // подпись живёт 2,5 с (в ускоренных таймерах — 50 мс)

    check('текст взят из тела документа', copied.length === 1 && copied[0] === source.textContent);
    check('подпись подтверждает копирование', button.querySelector('[data-erp222-copy-text]').textContent === 'Скопировано');
    check('ошибок JS нет', box.errors.length === 0, box.errors.join(' | '));

    box.dom.window.close();
}

/* ---------- запуск -------------------------------------------------------- */

console.log(`Вёрстка для проверки: ${DIR}`);

await liveFlow();
await startFlow(false);
await startFlow(true);
await deniedFlow();
await doneFlow();
await armedFlow();
await copyFlow();

console.log(`\n== Итог ==\n\n  проверок: ${checks}\n`);

if (failed > 0) {
    console.log(`  Провалов: ${failed}\n`);
    process.exit(1);
}

console.log('  Все проверки пройдены\n');
