/** Переложить закреплённые npm-библиотеки QR в локальные assets, без CDN. */
import { copyFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { ROOT } from './qr_php_render.mjs';
for (const [source, destination] of [
    ['fflate/umd/index.js', 'fflate/fflate.min.js'], ['fflate/LICENSE', 'fflate/LICENSE'],
    ['jsqr/dist/jsQR.js', 'jsqr/jsQR.js'], ['jsqr/LICENSE', 'jsqr/LICENSE'],
]) {
    const target = resolve(ROOT, 'public_html/assets/vendor', destination);
    mkdirSync(resolve(target, '..'), { recursive: true });
    copyFileSync(resolve(ROOT, 'node_modules', source), target);
}
console.log('QR: fflate и jsQR скопированы с лицензиями.');
