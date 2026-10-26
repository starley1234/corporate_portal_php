#!/usr/bin/env node
/* =============================================================================
 * N2J — «Графовый 3D-конструктор» (nj667_graph3d): поведение холста в браузере.
 *
 *   node .tools/nj667_graph3d_ui.mjs
 *
 * Зачем. Холст — это three.js, карта импорта на вендор и около тысячи строк
 * логики правки. Ни php-линт, ни сервисный стенд этого не видят: там функции
 * и вёрстка, а не поведение. А владелец уже видел пустой WebGL-кадр в nj570
 * (заметка в nj643_cad) — второй раз так нельзя.
 *
 * Поэтому настоящий HTML (его отдаёт .tools/nj667_graph3d_dump.php через
 * php-wasm — та же вьюха, что уезжает в браузер) раздаётся статическим
 * сервером и открывается в настоящем chromium (playwright).
 *
 * Что проверяется:
 *  0. форма создания переключает три режима и активирует только их поля; тон
 *     кнопки у «Простой (для пользователя)» зелёный, фото — компактной плашкой
 *     рядом с описанием (просьба владельца 15.10.2026);
 *     авто-маршрут опрашивает проект и открывает готовую сборку;
 *  1. страница поднимается: three.js загрузился по карте импорта, сцена
 *     отрисована (в DOM появился <canvas>), консоль без ошибок;
 *  2. граф виден: столько подписей узлов, сколько узлов в графе;
 *  3. правка работает: «Узел» добавляет деталь, Ctrl+Z её убирает,
 *     «Повторить» возвращает;
 *  4. сохранение: уходит POST /graph3d/api/save с графом (fetch подменён);
 *  5. удаление — вторым нажатием: первое только взводит кнопку;
 *  6. запасной путь: если WebGL выключить, страница НЕ показывает пустой кадр,
 *     а рисует плоскую проекцию и объясняет, что произошло;
 *  7. публичный просмотр: холст есть, правящих кнопок нет, экспорт GLB/JSON есть.
 *
 * Зависимость одна — playwright (в devDependencies, как у других UI-стендов).
 * ========================================================================== */

import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

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

const tick = (ms = 120) => new Promise((resolve) => setTimeout(resolve, ms));

/* ---------- вёрстка: php-wasm отдаёт настоящую вьюху модуля ---------------- */

function pageHtml(page = 'project', options = {}) {
    if (!fs.existsSync(PHPSWASM)) {
        console.error('Нужен php-wasm стенд: /tmp/phpwasm/phpwasm_stand.mjs');
        process.exit(1);
    }

    return execFileSync('node', [PHPSWASM, '.tools/nj667_graph3d_dump.php'], {
        cwd: ROOT,
        encoding: 'utf8',
        env: {
            ...process.env,
            N2J_ROOT: ROOT,
            N2J_PAGE: page,
            N2J_AUTO_SIMPLE_FLOW: options.autoSimpleFlow ? '1' : '0',
        },
        maxBuffer: 32 * 1024 * 1024,
    });
}

/* ---------- статический сервер: public_html как корень --------------------- */

const TYPES = {
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.woff2': 'font/woff2',
};

function serve(pages) {
    const server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        const page = pages[url.pathname];

        if (page !== undefined) {
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            // Дамп — это вьюха модуля без шаблона сайта; общий слой (app.js:
            // взвод data-n2j-arm, тосты) шаблон подключает в <head> — здесь так же.
            res.end('<script src="/assets/js/app.js"></script>\n' + page);
            return;
        }

        const file = path.join(ROOT, 'public_html', url.pathname);

        if (!file.startsWith(path.join(ROOT, 'public_html')) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
            res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end('нет такого файла');
            return;
        }

        res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
        res.end(fs.readFileSync(file));
    });

    return new Promise((resolve) => {
        server.listen(0, '127.0.0.1', () => resolve({ server, port: server.address().port }));
    });
}

/* ---------- подмены в странице -------------------------------------------- */

/**
 * fetch подменяем ДО загрузки скриптов: страница должна думать, что сервер
 * жив, и показывать ответы. Заодно запоминаем, что именно уходило.
 */
const STUB = `
window.__POSTS = [];
window.__REPLIES = { save: { ok: true, ops: 3, no: 4 } };

window.fetch = function (url, options) {
    const body = options && options.body ? JSON.parse(options.body) : {};
    window.__POSTS.push({ url: String(url), body });

    let reply = { ok: true };

    if (String(url).indexOf('/save') >= 0) { reply = window.__REPLIES.save; }
    if (String(url).indexOf('/status') >= 0) {
        reply = { ok: true, status: 'draft', nodes: 7, job_id: 0, job: {} };
        if (window.location.pathname.indexOf('/auto-test') >= 0) {
            reply.simple_flow = { enabled: true, state: 'queued', build_id: 88, error: '' };
        }
    }
    if (String(url).indexOf('/generate') >= 0) { reply = { ok: true, job_id: 11 }; }
    if (String(url).indexOf('/inpaint') >= 0) { reply = { ok: true, job_id: 12 }; }
    if (String(url).indexOf('/publish') >= 0) { reply = { ok: true, status: 'review' }; }

    return Promise.resolve({ status: 200, json: () => Promise.resolve(reply) });
};
`;

async function open(browser, port, route, { webgl = true } = {}) {
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        // --disable-gpu не отключает WebGL: его отключает отсутствие GL-драйвера.
        // Для проверки запасного пути отключаем WebGL целиком флагом.
        args: webgl ? undefined : ['--disable-webgl', '--disable-webgl2'],
    });

    await context.addInitScript(STUB);

    const page = await context.newPage();
    const errors = [];

    page.on('console', (message) => {
        if (message.type() === 'error') { errors.push(message.text()); }
    });

    page.on('pageerror', (error) => errors.push(String(error)));

    await page.goto(`http://127.0.0.1:${port}${route}`, { waitUntil: 'load' });
    await page.waitForTimeout(900);

    return { page, context, errors };
}

/* ========================================================================== */

const autoFlowHtml = pageHtml('project', { autoSimpleFlow: true });

const launched = await serve({
    '/graph3d/p/test': pageHtml('project'),
    '/graph3d/p/auto-test': autoFlowHtml,
    '/graph3d/b/ab34cd56ef78': '<!doctype html><html><body>сборка создана</body></html>',
    '/graph3d/c/test': pageHtml('view'),
    '/graph3d/create/test': pageHtml('create'),
    '/graph3d/moderate': pageHtml('moderate'),
});

// Полный chromium, а не headless-shell: в песочнице установлен только он
// (переменная позволяет подсужить свой путь, как в .tools/nj510_qr_ui_smoke.mjs).
const executable = process.env.NJ667_CHROMIUM_EXECUTABLE || chromium.executablePath();

// Браузер — вещь накладная: в CI его ставит npx playwright install, а на
// машине без него стенд не должен валить сборку. Отсутствие бинарника —
// это «проверка пропущена», а не «холст сломан»; для второго есть jsdom-тест
// tests/Js/nj667_graph3d.test.mjs.
if (!executable || !fs.existsSync(executable)) {
    console.log(`\nБраузер не установлен (${executable}) — стенд пропущен.`);
    console.log('Поставить: npx playwright install --with-deps chromium');
    launched.server.close();
    process.exit(0);
}

const browser = await chromium.launch({
    headless: true,
    executablePath: executable,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
});

try {
    /* ---------- 0. создание: три режима и переключение полей ---------------- */
    {
        const { page, context, errors } = await open(browser, launched.port, '/graph3d/create/test');
        const modes = await page.locator('[data-mode-choice]').count();
        check('создание: доступны три режима', modes === 3, `режимов: ${modes}`);

        await page.locator('[data-mode-choice][value="template"]').check();
        const templatePanel = page.locator('[data-creation-panel="template"]');
        const templateHidden = await templatePanel.evaluate((el) => el.hidden || el.disabled);
        const templateOptions = await page.locator('#nj667-template option').count();
        check('создание: режим без LLM открывает панель каркаса', templateHidden === false);
        check('создание: каталог содержит четыре локальных шаблона', templateOptions === 4, `вариантов: ${templateOptions}`);
        check('создание: выбор шаблона активен', await page.locator('#nj667-template').isEnabled());

        await page.locator('[data-mode-choice][value="simple"]').check();
        check('создание: пользовательский режим показывает описание',
            await page.locator('[data-creation-panel="simple"]').isVisible());
        check('создание: описание доступно для ввода', await page.locator('#nj667-simple-prompt').isEnabled());
        check('создание: кнопка запускает полный маршрут',
            (await page.locator('#nj667-create-submit').textContent() || '').includes('собрать всё'));
        check('создание: кнопка простого режима зелёная',
            ((await page.locator('#nj667-create-submit').getAttribute('class')) || '').includes('bg-emerald-600'));
        check('создание: фото — компактная плашка рядом с полем описания',
            await page.locator('[data-creation-panel="simple"] [data-nj667-photo] label').isVisible());
        check('создание: у простого режима нет плашки про STL',
            await page.locator('text=STL каждого уникального узла').count() === 0);
        const photoBox = await page.locator('#nj667-photos').boundingBox();
        check('создание: поле фото спрятано под плашкой, а не отдельной строкой',
            photoBox !== null && photoBox.height <= 4, photoBox ? `высота: ${photoBox.height}` : 'нет бокса');

        await page.locator('[data-mode-choice][value="llm"]').check();
        check('создание: обычный режим показывает prompt', await page.locator('#nj667-prompt').isVisible());
        check('создание: переключение не оставляет ошибок в консоли', errors.length === 0, errors.join(' | '));
        await context.close();
    }

    /* ---------- 0b. простой поток: баннер и переход к сборке ---------------- */
    {
        check('автопоток: страница проекта показывает баннер ожидания',
            autoFlowHtml.includes('id="nj667-simple-flow"')
                && autoFlowHtml.includes('Когда сборка появится, открою её автоматически'));
        const { page, context, errors } = await open(browser, launched.port, '/graph3d/p/auto-test');
        try { await page.waitForURL(/\/graph3d\/b\/ab34cd56ef78\?build=88&auto=1/, { timeout: 4000 }); } catch { }
        const redirected = page.url().includes('/graph3d/b/ab34cd56ef78?build=88&auto=1');
        check('автопоток: после статуса сборки открывается проект сборки', redirected, page.url());
        check('автопоток: консоль без ошибок', errors.length === 0, errors.join(' | '));
        await context.close();
    }

    /* ---------- 1. редактор: сцена поднялась -------------------------------- */
    {
        const { page, context, errors } = await open(browser, launched.port, '/graph3d/p/test');

        const webgl = await page.evaluate(() => !!(window.NJ667Editor && window.NJ667Editor.state.webgl));
        const canvases = await page.locator('#nj667-canvas canvas').count();
        const labels = await page.locator('#nj667-canvas .nj667-label').count();
        const nodes = await page.evaluate(() => window.NJ667Editor
            ? window.NJ667Editor.state.graph.nodes.length : -1);

        check('редактор: three.js загрузился, состояние есть', nodes === 7, `узлов: ${nodes}`);
        check('редактор: холст отрисован (canvas в DOM)', canvases >= 1, `canvas: ${canvases}`);
        check('редактор: подписи узлов не рисуем', labels === 0, `подписей: ${labels}`);
        const hasLabelBtn = await page.locator('#nj667-label-btn').count();
        check('редактор: кнопки «Подписи» нет', hasLabelBtn === 0);
        check('редактор: WebGL доступен в этом браузере', webgl === true);
        const hasGlb = await page.locator('#nj667-export-glb-btn').count();
        const hasJson = await page.locator('#nj667-export-json-btn').count();
        check('редактор: кнопка GLB на месте', hasGlb === 1);
        check('редактор: кнопка JSON на месте', hasJson === 1);
        check('редактор: кнопка удалить узел', await page.locator('#nj667-del-node-btn').count() === 1);
        check('редактор: кнопка удалить связь', await page.locator('#nj667-del-edge-btn').count() === 1);
        const workingHidden = await page.locator('#nj667-working.hidden').count();
        check('редактор: оверлей воркера скрыт, пока нет задачи', workingHidden === 1);
        check('редактор: секундомер на оверлее', await page.locator('#nj667-elapsed').count() === 1);
        check('редактор: css-спиннер, не fa-spin', await page.locator('.nj667-spin').count() >= 1);
        check('редактор: поле фото', await page.locator('#nj667-photos').count() === 1);
        check('редактор: чекбокс RAG', await page.locator('#nj667-rag').count() === 1);
        check('редактор: консоль чистая', errors.length === 0, errors.join(' | '));

        /* ---------- 2. правка: добавить → отменить → повторить -------------- */
        await page.click('#nj667-add-btn');
        await page.waitForTimeout(150);

        const afterAdd = await page.evaluate(() => window.NJ667Editor.state.graph.nodes.length);
        check('правка: узел добавлен', afterAdd === 8, `узлов: ${afterAdd}`);

        await page.keyboard.press('Control+z');
        await page.waitForTimeout(150);

        const afterUndo = await page.evaluate(() => window.NJ667Editor.state.graph.nodes.length);
        check('правка: Ctrl+Z вернул как было', afterUndo === 7, `узлов: ${afterUndo}`);

        await page.click('#nj667-redo-btn');
        await page.waitForTimeout(150);

        const afterRedo = await page.evaluate(() => window.NJ667Editor.state.graph.nodes.length);
        check('правка: «Повторить» вернуло правку', afterRedo === 8, `узлов: ${afterRedo}`);

        const historyRows = await page.locator('#nj667-history li').count();
        check('правка: история показала запись', historyRows >= 1, `строк: ${historyRows}`);

        /* ---------- 3. сохранение ------------------------------------------ */
        await page.click('#nj667-save-btn');
        await page.waitForTimeout(250);

        const saved = await page.evaluate(() => window.__POSTS.filter((p) => p.url.indexOf('/save') >= 0).pop());
        check('сохранение: POST ушёл на /graph3d/api/save', !!saved && saved.url.indexOf('/save') >= 0);
        check('сохранение: граф доехал целиком',
            !!saved && (saved.body.graph.nodes || []).length === 8,
            saved ? `узлов: ${(saved.body.graph.nodes || []).length}` : 'нет запроса');
        check('сохранение: csrf в теле',
            !!saved && saved.body.csrf === 'stand-csrf-token');

        /* ---------- 4. удаление — вторым нажатием --------------------------- */
        await page.click('#nj667-delete-btn');
        await page.waitForTimeout(100);

        // Взвод — общий data-n2j-arm из app.js: признак взведённой кнопки — класс is-armed.
        const armed = await page.locator('#nj667-delete-btn').evaluate((el) => el.classList.contains('is-armed'));
        const postsAfterFirst = await page.evaluate(() => window.__POSTS.filter(
            (p) => p.url.indexOf('/delete') >= 0).length);

        check('удаление: первое нажатие только взводит кнопку',
            postsAfterFirst === 0 && armed === true);

        await page.click('#nj667-delete-btn');
        await page.waitForTimeout(200);

        const postsAfterSecond = await page.evaluate(() => window.__POSTS.filter(
            (p) => p.url.indexOf('/delete') >= 0).length);
        check('удаление: второе нажатие отправляет запрос', postsAfterSecond === 1);

        check('редактор: консоль чистая после правок', errors.length === 0, errors.join(' | '));

        await context.close();
    }

    /* ---------- 5. без WebGL: не пустой кадр, а 2D -------------------------- */
    {
        const { page, context, errors } = await open(browser, launched.port, '/graph3d/p/test',
            { webgl: false });

        const webgl = await page.evaluate(() => !!(window.NJ667Editor && window.NJ667Editor.state.webgl));
        const canvases = await page.locator('#nj667-canvas canvas').count();
        const note = await page.locator('#nj667-note').textContent();

        check('без WebGL: модуль понимает, что 3D нет', webgl === false);
        check('без WebGL: плоский холст нарисован', canvases >= 1, `canvas: ${canvases}`);
        check('без WebGL: причина сказана человеку',
            /WebGL/.test(note || '') && /плоский|вид сверху/i.test(note || ''),
            `текст: ${note}`);

        // Правка обязана работать и в запасном режиме: иначе запас зря.
        await page.click('#nj667-add-btn');
        await page.waitForTimeout(150);

        const nodes = await page.evaluate(() => window.NJ667Editor.state.graph.nodes.length);
        check('без WebGL: узел добавляется', nodes === 8, `узлов: ${nodes}`);
        check('без WebGL: консоль чистая', errors.length === 0, errors.join(' | '));

        await context.close();
    }

    /* ---------- 6. публичный просмотр: только чтение ------------------------ */
    {
        const { page, context, errors } = await open(browser, launched.port, '/graph3d/c/test');

        const canvases = await page.locator('#nj667-view-canvas canvas').count();
        const hasTools = await page.locator('#nj667-add-btn').count();

        check('просмотр: холст отрисован', canvases >= 1, `canvas: ${canvases}`);
        check('просмотр: правящих кнопок нет', hasTools === 0);
        const hasExport = await page.locator('#nj667-export-glb-btn').count();
        check('просмотр: экспорт GLB есть', hasExport === 1);
        check('просмотр: консоль чистая', errors.length === 0, errors.join(' | '));

        await context.close();
    }

    /* ---------- 7. модерация: вердикт уходит на API ------------------------- */
    {
        const { page, context, errors } = await open(browser, launched.port, '/graph3d/moderate');

        await page.click('button[value="approve"]');
        await page.waitForTimeout(250);

        const verdict = await page.evaluate(() => window.__POSTS.filter(
            (p) => p.url.indexOf('/moderate') >= 0).pop());

        check('модерация: решение уходит на /graph3d/api/moderate', !!verdict);
        check('модерация: вердикт одобрения доехал',
            !!verdict && verdict.body.verdict === 'approve',
            verdict ? JSON.stringify(verdict.body) : 'нет запроса');
        check('модерация: консоль чистая', errors.length === 0, errors.join(' | '));

        await context.close();
    }
} finally {
    await browser.close();
    launched.server.close();
}

/* ------------------------------------------------------------------ итог */

console.log(`\nитог: ${checks - failed} ок, ${failed} фейл`);

if (failed) process.exit(1);
