// Локальный статический сервер для dist/ (без зависимостей).
// Использование: npm run serve [-- --port 8765 --host 127.0.0.1 --open]
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const opt = (name, def) => {
  const i = args.indexOf(`--${name}`);
  return i >= 0 && args[i + 1] ? args[i + 1] : def;
};
const port = Number(opt('port', process.env.PORT || 8765));
const host = opt('host', '127.0.0.1');
const page = '/dist/architecture.html';

const types = {
  '.html': 'text/html; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
};

const server = http.createServer((req, res) => {
  let pathname;
  try {
    pathname = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  } catch {
    res.statusCode = 400;
    return res.end('Bad request');
  }
  if (pathname === '/') {
    res.statusCode = 302;
    res.setHeader('Location', page);
    return res.end();
  }
  const file = path.join(root, pathname);
  const rel = path.relative(root, file);
  if (rel.startsWith('..') || path.isAbsolute(rel) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    res.statusCode = 404;
    return res.end('404 Not found');
  }
  res.setHeader('Content-Type', types[path.extname(file)] || 'application/octet-stream');
  res.setHeader('Cache-Control', 'no-store');
  fs.createReadStream(file).pipe(res);
});

server.on('error', (e) => {
  console.error(e.code === 'EADDRINUSE'
    ? `Порт ${port} занят. Укажите другой: npm run serve -- --port 8766`
    : e.message);
  process.exit(1);
});

server.listen(port, host, () => {
  const url = `http://${host}:${port}${page}`;
  console.log(`Сервер запущен: ${url}\nОстановить: Ctrl+C`);
  if (args.includes('--open')) {
    const [cmd, cmdArgs] =
      process.platform === 'win32' ? ['cmd', ['/c', 'start', '', url]]
      : process.platform === 'darwin' ? ['open', [url]]
      : ['xdg-open', [url]];
    spawn(cmd, cmdArgs, { stdio: 'ignore', detached: true }).unref();
  }
});
