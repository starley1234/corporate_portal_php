// Скрипты запуска: чтобы «двойной клик» не сломался незаметно.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = f => fs.readFileSync(path.join(ROOT, f), 'utf8');
const pkg = JSON.parse(read('package.json'));
const scripts = Object.keys(pkg.scripts || {});

const npmCalls = text => [...text.matchAll(/npm run ([a-zA-Z0-9:_-]+)/g)].map(m => m[1]);

test('start.bat: Windows-кодировка, проверка и сборка, открытие страницы', () => {
  const file = path.join(ROOT, 'start.bat');
  assert.ok(fs.existsSync(file), 'нет start.bat');
  const text = read('start.bat');
  assert.match(text, /chcp 65001/, 'без chcp 65001 в консоли будут кракозябры');
  assert.match(text, /where node/, 'нужна проверка, что Node установлен');
  assert.match(text, /npm install/);
  assert.match(text, /dist\\architecture\.html/);
  assert.ok(text.includes('\r\n'), 'bat-файл должен быть с CRLF');
  assert.equal(text.replace(/\r\n/g, '').includes('\n'), false, 'в bat-файле не должно быть одиночных LF');
  assert.match(text, /pause/, 'окно не должно закрываться до прочтения ошибки');
});

test('start.sh: bash, проверка и сборка, открытие страницы', () => {
  const file = path.join(ROOT, 'start.sh');
  assert.ok(fs.existsSync(file), 'нет start.sh');
  const text = read('start.sh');
  assert.match(text, /^#!\/usr\/bin\/env bash/);
  assert.match(text, /set -euo pipefail/);
  assert.match(text, /npm run check/);
  assert.match(text, /npm run build/);
  assert.match(text, /architecture\.html/);
  assert.equal(text.includes('\r\n'), false, 'в .sh не должно быть CRLF');
  // NTFS не хранит бит исполняемости: на Windows его не восстановить, проверяем только на POSIX
  if (process.platform !== 'win32') {
    const mode = fs.statSync(file).mode;
    assert.ok(mode & 0o100, 'start.sh должен быть исполняемым');
  }
});

test('arch.bat передаёт аргументы в CLI', () => {
  const text = read('arch.bat');
  assert.match(text, /node scripts\\arch\.mjs %\*/);
  assert.match(text, /cd \/d "%~dp0"/, 'скрипт должен работать из любой папки');
});

test('все npm-скрипты, на которые ссылаются bat/sh, существуют', () => {
  for (const f of ['start.bat', 'start.sh', 'arch.bat']) {
    for (const name of npmCalls(read(f))) {
      assert.ok(scripts.includes(name), `${f} вызывает несуществующий скрипт npm run ${name}`);
    }
  }
});

test('README и Запуск.md ведут к скриптам запуска', () => {
  const readme = read('README.md');
  const guide = read('Запуск.md');
  for (const text of [readme, guide]) {
    assert.match(text, /start\.bat/);
    assert.match(text, /start\.sh/);
  }
});
