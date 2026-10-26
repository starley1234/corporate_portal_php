/**
 * PHP-in-WASM стенд: запускает PHP-сценарий из репозитория без PHP в системе.
 *
 * Зачем: в песочницах Arena PHP CLI нет, а сервисный слой модулей проверять
 * нужно. Здесь PHP 8.3 собирается в WebAssembly (@php-wasm) и выполняет файл
 * стенда в памяти — с исходниками репозитория, доступными как /app/....
 *
 *   mkdir -p /tmp/phpwasm && cd /tmp/phpwasm
 *   npm i @php-wasm/node @php-wasm/universal
 *   cp <repo>/.tools/phpwasm_stand.mjs .
 *   node phpwasm_stand.mjs .tools/nj642_auto_stand.php
 *
 * Стенды читают исходники по путям из базы /app (root репозитория).
 */
import { loadNodeRuntime, createNodeFsMountHandler } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import { readFileSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';

const stand = process.argv[2];

if (!stand || !existsSync(stand) && !existsSync(resolve(process.env.N2J_ROOT || '.', stand || ''))) {
    console.error('использование: node phpwasm_stand.mjs <файл-стенда.php>');
    process.exit(2);
}

const root = (process.env.N2J_ROOT
    || spawnSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).stdout.trim()
    || process.cwd()).trim();
const rt = await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 8001 } });
const php = new PHP(rt);

php.mkdir('/app');

// Всё, что нужно стенду, копируем в виртуальную ФС по тем же путям.
function copy(abs) {
    const rel = abs.startsWith(root) ? abs.slice(root.length + 1) : abs.replace(/^\//, '');
    const to = '/app/' + rel;
    const parent = dirname(to);

    if (parent !== '/app' && !php.fileExists(parent)) {
        php.mkdir(parent);
    }

    php.writeFile(to, readFileSync(abs, 'utf8'));
}

// Кроме отслеживаемых файлов берём и новые (ещё не закоммиченные): стенд
// пишут ВМЕСТЕ с правкой, и «вьюхи нет в виртуальной ФС» — ложная ошибка,
// которая ничего не говорит о коде.
const list = spawnSync('git', ['ls-files', '--cached', '--others', '--exclude-standard'],
    { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).stdout.split('\n');

const only = process.argv.slice(3);   // необязательно: какие пути копировать (по подстроке)

for (const rel of list) {
    if (!rel || !/\.php$/.test(rel)) continue;
    if (only.length && !only.some((x) => rel.includes(x))) continue;

    const abs = resolve(root, rel);

    if (existsSync(abs)) copy(abs);
}

// Не-PHP файлы, которые стенды читают как справку (пример окружения):
// без них проверка «что обещано владельцу в .env.example» невозможна.
for (const rel of ['.env.example']) {
    const abs = resolve(root, rel);

    if (existsSync(abs)) copy(abs);
}

// Дополнительные данные, которые стенд читает из виртуальной ФС (сид-файлы
// миграций и т.п.): N2J_STAND_FILES="path1:path2" — пути от корня репозитория.
for (const rel of (process.env.N2J_STAND_FILES || '').split(':').filter(Boolean)) {
    const abs = resolve(root, rel);

    if (existsSync(abs)) copy(abs);
}

// сам стенд может быть ещё не закоммичен — копируем его отдельно
copy(resolve(root, stand));

// Переменные N2J_* из окружения доходят до стенда: так стенд включает режимы
// («показать страницы», свой набор данных) без правки самого стенда.
const passEnv = Object.fromEntries(
    Object.entries(process.env).filter(([key]) => key.startsWith('N2J_')));

// N2J_MOUNT="<host-каталог>:<путь в PHP>": стенд может писать артефакты
// (например, HTML-галерею вьюх) прямо на диск, а не только в память WASM.
// Внутри PHP путь виден как обычный каталог, и его же передают в N2J_*.
const mountSpec = (process.env.N2J_MOUNT || '').trim();

if (mountSpec) {
    const idx = mountSpec.lastIndexOf(':');

    if (idx > 0) {
        const hostPath = mountSpec.slice(0, idx);
        const vfsPath = mountSpec.slice(idx + 1);
        const { mkdirSync } = await import('node:fs');

        mkdirSync(hostPath, { recursive: true });
        await php.mount(vfsPath, createNodeFsMountHandler(hostPath));
    }
}

const boot = php.run({
    env: passEnv,
    code: `<?php
define('SYSPATH', '/app/system/');
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'localhost';
require '/app/${stand.replace(/^\.\//, '')}';
`,
});

boot.then((out) => {
    const text = (out.text || '').trim();
    const err = String(out.errors || '').trim();

    if (text) console.log(text);
    if (err) console.log('--- stderr ---\n' + err.slice(0, 4000));

    process.exit(out.exitCode === 0 && !/ФЕЙЛ|ПРОВАЛ/.test(text) ? 0 : 1);
}).catch((e) => {
    const out = e && e.response;
    console.log(out && out.text ? String(out.text).trim() : String(e).slice(0, 2000));
    console.log('--- стенд упал ---');
    process.exit(1);
});
