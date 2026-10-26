/** Изолированный предпросмотр настоящей вьюхи nj510, без БД и авторизации. */
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { resolve, extname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { ROOT, runPhp } from './qr_php_render.mjs';

export async function previewServer(port = 0) {
    const html = await runPhp('--html');
    const settings = JSON.parse(html.match(/<script id="qr-settings" type="application\/json">(.*?)<\/script>/s)[1]);
    const publicRoot = resolve(ROOT, 'public_html');
    const types = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' };
    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url, 'http://preview.invalid');
        if (url.pathname === '/' || url.pathname === '/qr') {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
            const mode = url.searchParams.get('mode') === 'batch' ? 'batch' : 'single';
            const type = Object.hasOwn(settings.types, url.searchParams.get('type')) ? url.searchParams.get('type') : 'url';
            response.end(html.replace('data-mode="single" data-type="url"', `data-mode="${mode}" data-type="${type}"`)); return;
        }
        if (url.pathname === '/favicon.ico') { response.writeHead(204); response.end(); return; }
        try {
            const file = resolve(publicRoot, '.' + decodeURIComponent(url.pathname));
            if (!file.startsWith(publicRoot + '/') || !url.pathname.startsWith('/assets/')) throw new Error('Недоступный путь');
            const data = await readFile(file);
            response.writeHead(200, { 'Content-Type': types[extname(file)] || 'application/octet-stream' }); response.end(data);
        } catch (_) { response.writeHead(404); response.end('Не найдено'); }
    });
    await new Promise((resolve) => server.listen(port, '0.0.0.0', resolve));
    return server;
}
if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
    const server = await previewServer(Number(process.env.PORT || 4173));
    console.log('QR-генератор: http://0.0.0.0:' + server.address().port + '/qr');
}
