/** Выполнение изолированного PHP-стенда nj510: CLI, а в песочнице — PHP-in-WASM. */
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
export const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export async function runPhp(argument = '') {
    if (!['', '--json', '--html'].includes(argument)) throw new Error('Неизвестный режим PHP-стенда nj510.');
    const native = spawnSync(process.env.PHP_BIN || 'php', ['.tools/nj510_stand.php', argument], {
        cwd: ROOT, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024,
    });
    if (!native.error) {
        if (native.status !== 0) throw new Error(native.stderr || native.stdout);
        return native.stdout;
    }
    if (native.error.code !== 'ENOENT') throw native.error;
    let runtime, PHP;
    try {
        ({ loadNodeRuntime: runtime } = await import('@php-wasm/node'));
        ({ PHP } = await import('@php-wasm/universal'));
    } catch (_) {
        throw new Error('Нужен PHP CLI или npm i --no-save --package-lock=false @php-wasm/node @php-wasm/universal (см. nj510_qr/AGENTS.md).');
    }
    const php = new PHP(await runtime('8.3', { emscriptenOptions: { processId: process.pid } }));
    const files = ['.tools/nj510_stand.php', ...[
        'init.php', 'config/nj_module.php', 'classes/Service/Nj510/Qr.php', 'classes/Controller/Nj510/Qr.php',
        'views/510/index.php', 'views/510/script.php',
    ].map((path) => 'application/modules/nj510_qr/' + path)];
    for (const file of files) {
        const destination = '/app/' + file;
        if (!php.fileExists(dirname(destination))) php.mkdir(dirname(destination));
        php.writeFile(destination, readFileSync(resolve(ROOT, file), 'utf8'));
    }
    try {
        const result = await php.run({ code: `<?php $argv = ['nj510_stand.php', '${argument}']; require '/app/.tools/nj510_stand.php';` });
        if (result.exitCode !== 0 || result.errors) throw new Error(result.errors || result.text);
        return result.text;
    } finally { php.exit(); }
}
