#!/usr/bin/env node
/* =============================================================================
 * N2J — «Декодер JSON» (nj664_jsondecode): поведение страницы в браузере.
 *
 *   node .tools/nj664_decode_ui.mjs
 *
 * Зачем. Владелец (09.10.2026): «Сделай модуль такой же как
 * codebeautify.org/json-decode-online». Там разбор идёт на каждое нажатие,
 * ошибка показывает место, а результат можно забрать файлом. Ни PHP-линт, ни
 * сервисный стенд этого не видят: там функции и вёрстка, а не поведение.
 *
 * Поэтому настоящий HTML (его отдаёт .tools/nj664_decode_dump.php через
 * php-wasm — та же вьюха, что уезжает в браузер) загружается в jsdom, fetch
 * подменяется управляемым, а буфер обмена и скачивание — заглушками.
 *
 * Что проверяется:
 *  1. живой разбор: пять видов входа (честный, экранированный, URL, HTML,
 *     в кавычках) и подпись того, что именно пришлось поправить;
 *  2. ошибка: называется строка и позиция, вывод очищается;
 *  3. вкладки: сжатый JSON, PHP Array и json_decode() — серверный ответ
 *     показывается, повторный вход по кэшу не уходит;
 *  4. пульт: копировать, скачать (имя файла по вкладке), на весь экран,
 *     очистить, пример;
 *  5. тишина в консоли: ни одной ошибки движка.
 *
 * Зависимость одна — jsdom (в devDependencies, как у tests/Js/*).
 * ========================================================================== */

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM, VirtualConsole } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHPSWASM = process.env.N2J_PHPWASM || '/tmp/phpwasm/phpwasm_stand.mjs';

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

const tick = (ms = 60) => new Promise((resolve) => setTimeout(resolve, ms));

/* ---------- вёрстка: php-wasm отдаёт настоящую вьюху модуля ---------------- */

function page(env = {}) {
    if (!fs.existsSync(PHPSWASM)) {
        console.error('Нужен php-wasm стенд: /tmp/phpwasm/phpwasm_stand.mjs (см. .tools/phpwasm_stand.mjs)');
        process.exit(1);
    }

    return execFileSync('node', [PHPSWASM, '.tools/nj664_decode_dump.php'], {
        cwd: ROOT,
        encoding: 'utf8',
        env: { ...process.env, N2J_ROOT: ROOT, N2J_INPUT: '', N2J_MODE: 'json', ...env },
        maxBuffer: 32 * 1024 * 1024,
    });
}

/* ---------- ответы «сервера»: ровно то, что отдаёт Controller_Nj664_Api ---- */

function phpArray(value, level = 0) {
    if (value === null || typeof value !== 'object') return String(value === null ? '' : value);

    const pad = '    '.repeat(level);
    const keys = Object.keys(value);

    if (!keys.length) return `${pad}Array\n${pad}(\n${pad})`;

    let out = `${pad}Array\n${pad}(\n`;

    for (const key of keys) {
        const item = value[key];

        out += `${pad}    [${key}] => `;

        if (item !== null && typeof item === 'object') {
            out += phpArray(item, level + 2).replace(/^\s+/, '') + '\n\n';
        } else {
            out += `${item === null ? '' : item === true ? 1 : item === false ? '' : item}\n`;
        }
    }

    return out + pad + ')';
}

function serverAnswer(url, options = {}) {
    const body = JSON.parse(String((options && options.body) || '{}'));
    const text = String(body.input || 'null');

    // Вход может быть экранированным ({\"a\":1}) — сервис это умеет, значит
    // и заглушка должна, иначе упадёт на примере.
    let data;
    try {
        data = JSON.parse(text);
    } catch (error) {
        data = JSON.parse(text.replace(/\\(["\\/])/g, '$1'));
    }

    return {
        ok: true,
        mode: 'raw',
        mode_label: 'как есть — это уже JSON',
        result: JSON.stringify(data, null, 2),
        minified: JSON.stringify(data),
        php_array: phpArray(data),
        php_code: `<?php\n$json = '${JSON.stringify(data)}';\n$data = json_decode($json, TRUE);`,
        stats: { nodes: 2, keys: 1, depth: 1, type: 'объект' },
    };
}

/* ---------- страница в jsdom с управляемым fetch -------------------------- */

function open(answers = {}) {
    const calls = [];
    const errors = [];
    const clipboard = { written: [], read: null };
    const downloads = [];
    const virtualConsole = new VirtualConsole();

    virtualConsole.on('jsdomError', (error) => errors.push(String(error.message || error)));

    const dom = new JSDOM(page(), {
        url: 'https://n2j.ru/json-decode',
        runScripts: 'dangerously',
        pretendToBeVisual: true,
        virtualConsole,
        beforeParse(window) {
            window.N2J = undefined;   // на странице модуля общих скриптов нет: проверяем fallback

            Object.defineProperty(window.navigator, 'clipboard', {
                configurable: true,
                value: {
                    writeText: (text) => { clipboard.written.push(text); return Promise.resolve(); },
                    readText: () => Promise.resolve(clipboard.read === null ? '' : clipboard.read),
                },
            });

            window.URL.createObjectURL = () => 'blob:stand';
            window.URL.revokeObjectURL = () => {};

            // Скачивание в jsdom — навигация, которую он не умеет: считаем
            // факт и имя файла, саму навигацию не пускаем.
            window.HTMLAnchorElement.prototype.click = function () {
                downloads.push(this.getAttribute('download') || '');
            };

            window.fetch = (url, options = {}) => {
                calls.push({ url: String(url), options });

                const name = String(url).replace(/^https?:\/\/[^/]+/, '');
                // null — валидный ответ («чужой сайт отдал 403»), поэтому не `||`
                const answer = Object.prototype.hasOwnProperty.call(answers, name) ? answers[name] : answers['*'];
                const payload = typeof answer === 'function' ? answer(url, options) : answer;

                // null — «чужой сайт отдал 403»: проверяем, что страница
                // объясняет это человеку, а не молчит.
                if (payload === null) {
                    return Promise.resolve({
                        ok: false,
                        status: 403,
                        redirected: false,
                        url: String(url),
                        text: async () => 'HTTP 403',
                        json: async () => ({}),
                    });
                }

                const body = typeof payload === 'string' ? payload : JSON.stringify(payload);

                return Promise.resolve({
                    ok: true,
                    status: 200,
                    redirected: false,
                    url: String(url),
                    text: async () => body,
                    json: async () => (typeof payload === 'string' ? JSON.parse(payload) : payload),
                });
            };
        },
    });

    return { dom, w: dom.window, calls, errors, clipboard, downloads };
}

/* ---------- действия ------------------------------------------------------ */

function type(w, text) {
    const input = w.document.getElementById('nj664-input');

    input.value = text;
    input.dispatchEvent(new w.Event('input', { bubbles: true }));
}

function press(w, act) {
    const button = w.document.querySelector(`[data-nj664-act="${act}"]`);

    if (!button) throw new Error(`нет кнопки ${act}`);

    button.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
}

function tab(w, name) {
    const button = w.document.querySelector(`[data-nj664-tab="${name}"]`);

    if (!button) throw new Error(`нет вкладки ${name}`);

    button.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
}

const outText = (w) => w.document.getElementById('nj664-out-raw').textContent;
const statusText = (w) => w.document.getElementById('nj664-status').textContent;

/* ---------- 1. живой разбор пяти видов входа ------------------------------ */

{
    const { w, errors } = open();
    const cases = [
        ['честный JSON', '{"a":1,"b":"два"}', 'как есть'],
        ['экранированный', '{\\"a\\":1}', 'снято экранирование'],
        ['URL-кодированный', '%7B%22a%22%3A1%7D', 'раскодировано из URL'],
        ['HTML-сущности', '{&quot;a&quot;:1}', 'раскодированы HTML-сущности'],
        ['в кавычках', '\'{"a":1}\'', 'раскрыта строка в кавычках'],
    ];

    check('старт: подсказка про живой разбор', statusText(w).includes('Разбор живой'));

    for (const [name, text, mark] of cases) {
        type(w, text);
        await tick(200);

        check(`${name}: разобрано`, statusText(w).includes('Разобрано'), statusText(w));
        check(`${name}: сказано «${mark}»`, statusText(w).includes(mark), statusText(w));
        check(`${name}: вывод не пустой`, outText(w).trim() !== '', outText(w));
    }

    type(w, '{"a":1,"b":"два"}');
    await tick(200);
    check('красивый JSON: с отступами', outText(w) === '{\n  "a": 1,\n  "b": "два"\n}', JSON.stringify(outText(w)));
    check('сводка: есть узлы и глубина', /узлов: \d+/.test(statusText(w)) && /глубина: \d+/.test(statusText(w)), statusText(w));
    check('размер входа показан', /байт/.test(w.document.querySelector('[data-nj664-size]').textContent));
    check('консоль чистая', errors.length === 0, errors.join(' | '));

    w.close();
}

/* ---------- 2. ошибка с местом ------------------------------------------- */

{
    const { w, errors } = open();

    type(w, '{\n  "a": [1, 2}\n]');
    await tick(200);

    check('ошибка: статус красный', w.document.querySelector('#nj664-status p').className.includes('rose'));
    check('ошибка: названа строка и позиция', /строка \d+, позиция \d+/.test(statusText(w)), statusText(w));
    check('ошибка: вывод очищен', outText(w) === '', outText(w));

    type(w, '');
    await tick(200);
    check('очистка поля: подсказка вернулась', statusText(w).includes('Разбор живой'));
    check('консоль чистая (ошибки)', errors.length === 0, errors.join(' | '));

    w.close();
}

/* ---------- 3. вкладки --------------------------------------------------- */

{
    let step = 0;
    const { w, calls, errors } = open({
        '/nj664/api/decode': serverAnswer,
    });

    type(w, '{"a":1,"b":"два"}');
    await tick(200);

    tab(w, 'min');
    await tick(120);
    check('вкладка «Сжатый»: одна строка', outText(w) === '{"a":1,"b":"два"}', JSON.stringify(outText(w)));

    tab(w, 'array');
    await tick(200);
    check('вкладка PHP Array: ушёл запрос', calls.length === 1 && calls[0].url === '/nj664/api/decode', JSON.stringify(calls.map((c) => c.url)));
    check('вкладка PHP Array: вид print_r', outText(w).includes('[a] => 1') && outText(w).includes('Array'), JSON.stringify(outText(w)));

    tab(w, 'php');
    await tick(200);
    check('вкладка json_decode(): код есть', outText(w).includes('json_decode($json, TRUE)'), JSON.stringify(outText(w)));
    check('вкладка json_decode(): второй запрос не нужен — ответ уже в кэше', calls.length === 1, String(calls.length));

    tab(w, 'array');
    await tick(200);
    check('возврат на PHP Array: из кэша, без запроса', calls.length === 1, String(calls.length));
    check('возврат на PHP Array: вид print_r на месте', outText(w).includes('[a] => 1'), JSON.stringify(outText(w)));

    // Другой вход — другой ответ: кэш по тексту, а не «навсегда»
    type(w, '{"a":1,"c":3}');
    await tick(250);
    check('новый вход: кэш не подошёл, запрос ушёл', calls.length === 2, String(calls.length));
    check('новый вход: в выводе новый ключ', outText(w).includes('[c] => 3'), JSON.stringify(outText(w)));
    check('режим записан в скрытое поле', w.document.getElementById('nj664-mode').value === 'array', w.document.getElementById('nj664-mode').value);

    tab(w, 'json');
    await tick(120);
    check('возврат на JSON: красиво', outText(w) === '{\n  "a": 1,\n  "c": 3\n}', JSON.stringify(outText(w)));
    check('консоль чистая (вкладки)', errors.length === 0, errors.join(' | '));

    w.close();
}

/* ---------- 4. пульт ----------------------------------------------------- */

{
    const { w, calls, errors, clipboard, downloads } = open({
        '/nj664/api/decode': serverAnswer,
        '/nj664/api/sample': {
            ok: true,
            input: '{\\"Name\\":\\"Berkshire\\"}',
            output: '{\n  "Name": "Berkshire"\n}',
        },
        '/data.json': '{"from":"url"}',
        '/deny.json': null,
    });

    type(w, '{"a":1,"b":"два"}');
    await tick(200);

    press(w, 'copy');
    await tick(60);
    check('копирование: текст ушёл в буфер', clipboard.written.length === 1 && clipboard.written[0].includes('"a": 1'), JSON.stringify(clipboard.written));

    press(w, 'save');
    await tick(60);
    check('скачивание: имя по вкладке JSON', downloads[0] === 'decoded.json', JSON.stringify(downloads));

    tab(w, 'php');
    await tick(200);
    press(w, 'save');
    await tick(60);
    check('скачивание: имя по вкладке PHP', downloads[1] === 'decoded.php', JSON.stringify(downloads));

    const pre = w.document.getElementById('nj664-out');

    press(w, 'full');
    await tick(30);
    check('весь экран: класс поставлен', pre.parentNode.classList.contains('nj664-full'));
    check('весь экран: подпись поменялась', w.querySelector ? true : w.document.querySelector('[data-nj664-act="full"]').textContent.includes('Экран'));

    press(w, 'full');
    await tick(30);
    check('весь экран: повторный клик снимает', !pre.parentNode.classList.contains('nj664-full'));

    press(w, 'sample');
    await tick(200);
    check('пример: ушёл за образцом', calls.some((c) => c.url === '/nj664/api/sample'), JSON.stringify(calls.map((c) => c.url)));
    check('пример: поле заполнено', w.document.getElementById('nj664-input').value.includes('Berkshire'), w.document.getElementById('nj664-input').value);
    check('пример: разобран', statusText(w).includes('Разобрано'), statusText(w));

    // Загрузка по адресу: тянет браузер, сервер в чужие сети не ходит
    press(w, 'url');
    await tick(30);
    check('ссылка: строка адреса показана', !w.document.getElementById('nj664-url-row').className.includes('hidden'));

    w.document.getElementById('nj664-url').value = 'https://api.example.com/data.json';
    press(w, 'load');
    await tick(250);
    check('загрузка: текст ушёл в поле', w.document.getElementById('nj664-input').value === '{"from":"url"}', w.document.getElementById('nj664-input').value);
    check('загрузка: разобран', statusText(w).includes('Разобрано'), statusText(w));

    w.document.getElementById('nj664-url').value = 'https://api.example.com/deny.json';
    press(w, 'load');
    await tick(250);
    check('загрузка: отказ объяснён человеку', statusText(w).includes('CORS') && statusText(w).includes('403'), statusText(w));

    press(w, 'clear');
    await tick(200);
    check('очистить: поле пусто', w.document.getElementById('nj664-input').value === '');
    check('очистить: подсказка вернулась', statusText(w).includes('Разбор живой'));

    press(w, 'url');
    await tick(30);
    check('ссылка: повторный клик прячет строку', w.document.getElementById('nj664-url-row').className.includes('hidden'));
    check('консоль чистая (пульт)', errors.length === 0, errors.join(' | '));

    w.close();
}

/* ---------- 5. страница с разобранным входом (режим без JavaScript) ------- */

{
    const html = page({ N2J_INPUT: '{"a":1}', N2J_MODE: 'array' });

    check('без JS: вывод уже в странице', html.includes('[a] =&gt; 1'), 'в <pre> нет разобранного массива');
    check('без JS: вход вернулся в поле', html.includes('{&quot;a&quot;:1}'), 'поле входа пустое');
    check('без JS: сказано, что разобрано', html.includes('Разобрано'));
    check('без JS: кнопка отправки в noscript', html.includes('<noscript>') && html.includes('Раскодировать'));
}

/* ------------------------------------------------------------------ итог */

console.log(`\nитог: ${checks - failed} ок, ${failed} фейл`);

if (failed) process.exit(1);
