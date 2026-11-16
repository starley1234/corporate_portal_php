// Профили домена: новый проект собирается из общего словаря и сразу проходит проверки.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');
const BUILD = path.join(ROOT, 'scripts', 'build.mjs');
const run = (file, args) => spawnSync(process.execPath, [file, ...args], { cwd: ROOT, encoding: 'utf8' });
const tmp = () => fs.mkdtempSync(path.join(os.tmpdir(), 'arch-profile-'));

test('список профилей читается и содержит три домена', () => {
  const res = run(ARCH, ['profiles', '--json']);
  assert.equal(res.status, 0, res.stderr);
  const list = JSON.parse(res.stdout);
  const names = list.map(x => x.name);
  for (const n of ['minimal', 'platform', 'department']) assert.ok(names.includes(n), `нет профиля ${n}`);
  for (const p of list) {
    assert.ok(p.description, `${p.name}: нет описания`);
    assert.ok(p.sections >= 1 && p.tabs >= 1, `${p.name}: пустой профиль`);
  }
  const text = run(ARCH, ['profiles']).stdout;
  assert.match(text, /новый проект: arch init/);
});

test('проект из профиля department проходит проверки и собирается', () => {
  const dir = path.join(tmp(), 'dept');
  try {
    const init = run(ARCH, ['init', dir, '--profile', 'department']);
    assert.equal(init.status, 0, init.stderr);
    assert.match(init.stdout, /профиль: department/);
    assert.ok(fs.existsSync(path.join(dir, 'arch', 'entities.yaml')), 'сущности профиля не записались');
    assert.ok(fs.existsSync(path.join(dir, 'arch', 'invariants', '01-pilot-has-metric.yaml')), 'инвариант не записался');

    const check = run(ARCH, ['check', '--dir', dir, '--strict']);
    assert.equal(check.status, 0, check.stdout + check.stderr);

    const build = run(BUILD, ['--dir', dir]);
    assert.equal(build.status, 0, build.stderr);
    assert.match(build.stdout, /вкладок: \d+/);
    const html = fs.readFileSync(path.join(dir, 'dist', 'architecture.html'), 'utf8');
    assert.match(html, /Портфель|Воронка процессов/);
    assert.doesNotMatch(html, /(?:src|href)="https?:/);
  } finally { fs.rmSync(path.dirname(dir), { recursive: true, force: true }); }
});

test('платформенный профиль: свой словарь и рабочий инвариант', () => {
  const dir = path.join(tmp(), 'plat');
  try {
    assert.equal(run(ARCH, ['init', dir, '--profile', 'platform']).status, 0);
    const inv = run(ARCH, ['invariants', '--dir', dir, '--json']);
    assert.equal(inv.status, 0, inv.stderr);
    const report = JSON.parse(inv.stdout);
    assert.equal(report.length, 1);
    assert.deepEqual(report[0].violations, []);
    assert.equal(run(ARCH, ['check', '--dir', dir, '--strict']).status, 0);
  } finally { fs.rmSync(path.dirname(dir), { recursive: true, force: true }); }
});

test('неизвестный профиль и битый профиль — понятная ошибка', () => {
  const dir = tmp();
  try {
    const miss = run(ARCH, ['init', path.join(dir, 'x'), '--profile', 'нет-такого']);
    assert.equal(miss.status, 1);
    assert.match(miss.stderr, /не найден.*Доступные/s);

    const bad = path.join(dir, 'bad.yaml');
    fs.writeFileSync(bad, 'title: без разделов\n', 'utf8');
    const broken = run(ARCH, ['init', path.join(dir, 'y'), '--profile', bad]);
    assert.equal(broken.status, 1);
    assert.match(broken.stderr, /нет обязательного поля sections/);
  } finally { fs.rmSync(dir, { recursive: true, force: true }); }
});

test('профиль можно указать файлом, а --force перезаписывает проект', () => {
  const dir = path.join(tmp(), 'proj');
  try {
    const file = path.join(ROOT, 'profiles', 'minimal.yaml');
    assert.equal(run(ARCH, ['init', dir, '--profile', file]).status, 0);
    const again = run(ARCH, ['init', dir, '--profile', file]);
    assert.equal(again.status, 1, 'без --force перезаписывать нельзя');
    assert.equal(run(ARCH, ['init', dir, '--profile', file, '--force']).status, 0);
  } finally { fs.rmSync(path.dirname(dir), { recursive: true, force: true }); }
});

test('init без профиля по-прежнему даёт пустой каркас без предупреждений', () => {
  const dir = path.join(tmp(), 'plain');
  try {
    assert.equal(run(ARCH, ['init', dir]).status, 0);
    assert.ok(!fs.existsSync(path.join(dir, 'arch', 'entities.yaml')));
    assert.equal(run(ARCH, ['check', '--dir', dir, '--strict']).status, 0);
  } finally { fs.rmSync(path.dirname(dir), { recursive: true, force: true }); }
});
