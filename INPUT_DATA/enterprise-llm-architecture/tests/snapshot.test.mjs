// Снимки: точка сравнения, из которой видно «что изменилось с прошлого раза».
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { tempProject, minimalFiles } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');
const run = (args, cwd) => spawnSync(process.execPath, [ARCH, ...args], { cwd: cwd || ROOT, encoding: 'utf8' });

test('снимок самодостаточен: в нём есть и страница, и данные, и копия arch/', () => {
  const p = tempProject(minimalFiles());
  try {
    const res = run(['--dir', p.dir, 'snapshot', 'baseline'], p.dir);
    assert.equal(res.status, 0, res.stderr);
    const dir = fs.readdirSync(path.join(p.dir, 'snapshots'))[0];
    const snap = path.join(p.dir, 'snapshots', dir);
    for (const f of ['architecture.html', 'architecture.json', 'README.md'])
      assert.ok(fs.existsSync(path.join(snap, f)), `в снимке нет ${f}`);
    assert.ok(fs.existsSync(path.join(snap, 'arch', 'meta.yaml')), 'нет копии arch/ — снимок нельзя ни сравнить, ни откатить');
  } finally { p.cleanup(); }
});

test('свежий снимок: отличий нет, а после правки — видно что именно', () => {
  const p = tempProject(minimalFiles());
  try {
    assert.equal(run(['--dir', p.dir, 'snapshot', 'base'], p.dir).status, 0);
    const dir = fs.readdirSync(path.join(p.dir, 'snapshots'))[0];
    const clean = run(['--dir', p.dir, 'diff', `snapshots/${dir}`], p.dir);
    assert.equal(clean.status, 0, clean.stderr);
    assert.match(clean.stdout, /отличий нет/);

    const tab = path.join(p.dir, 'arch', 'tobe', '01-overview.yaml');
    fs.writeFileSync(tab, fs.readFileSync(tab, 'utf8').replace('title: Общий вид', 'title: Общий вид (правка)'), 'utf8');
    const changed = run(['--dir', p.dir, 'diff', `snapshots/${dir}`], p.dir);
    assert.equal(changed.status, 0, changed.stderr);
    assert.match(changed.stdout, /отличий: 1/);
    assert.match(changed.stdout, /tobe\/01-overview\.title/);
    assert.match(changed.stdout, /Общий вид \(правка\)/);
    assert.match(changed.stdout, /вкладок/, 'нет короткой сводки размеров');
  } finally { p.cleanup(); }
});

test('повторный снимок с тем же именем требует --force; дата не дублируется', () => {
  const p = tempProject(minimalFiles());
  try {
    const date = new Date().toISOString().slice(0, 10);
    assert.equal(run(['--dir', p.dir, 'snapshot', 'base'], p.dir).status, 0);
    const again = run(['--dir', p.dir, 'snapshot', 'base'], p.dir);
    assert.equal(again.status, 1);
    assert.match(again.stderr, /снимок уже есть/);
    assert.equal(run(['--dir', p.dir, 'snapshot', 'base', '--force'], p.dir).status, 0);

    // имя уже с датой — папка не должна стать 2026-11-06-2026-11-06-base
    assert.equal(run(['--dir', p.dir, 'snapshot', `${date}-tagged`], p.dir).status, 0);
    const dirs = fs.readdirSync(path.join(p.dir, 'snapshots'));
    assert.ok(dirs.includes(`${date}-tagged`), `нет папки ${date}-tagged: ${dirs.join(', ')}`);
    assert.ok(dirs.includes(`${date}-base`));
  } finally { p.cleanup(); }
});

test('снимок старого формата объясняет, почему сравнивать нечего', () => {
  const p = tempProject(minimalFiles());
  try {
    const dir = path.join(p.dir, 'snapshots', 'old');
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, 'architecture.html'), '<html></html>', 'utf8');
    const res = run(['--dir', p.dir, 'diff', 'snapshots/old'], p.dir);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /старого формата/);
    assert.match(res.stderr, /arch snapshot/);
  } finally { p.cleanup(); }
});
