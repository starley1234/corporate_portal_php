// CLI: команды работают на произвольном каталоге проекта и возвращают понятные коды.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { tempProject, minimalFiles, MINIMAL_TAB } from './helpers.mjs';

const ARCH = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'scripts', 'arch.mjs');
const run = (args, dir) => spawnSync(process.execPath, [ARCH, ...args, '--dir', dir], { encoding: 'utf8' });

test('tabs и check на минимальном проекте', () => {
  const p = tempProject(minimalFiles());
  try {
    const tabs = run(['tabs'], p.dir);
    assert.equal(tabs.status, 0, tabs.stderr);
    assert.match(tabs.stdout, /tobe\/01-overview/);
    const check = run(['check'], p.dir);
    assert.equal(check.status, 0, check.stdout);
    assert.match(check.stdout, /ошибок: 0, предупреждений: 0/);
  } finally { p.cleanup(); }
});

test('tabs работает, если у уровня есть lead, но нет блоков', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.detail = { lead: 'подробно без блоков' };
  const p = tempProject(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  try {
    const tabs = run(['tabs'], p.dir);
    assert.equal(tabs.status, 0, tabs.stderr);
    assert.match(tabs.stdout, /tobe\/01-overview/);
    assert.match(tabs.stdout, /\[схем 1; узлов 2\]/);
  } finally { p.cleanup(); }
});

test('node не падает, если у уровня есть lead, но нет блоков', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.detail = { lead: 'подробно без блоков' };
  const p = tempProject(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  try {
    const out = run(['node', 'a'], p.dir);
    assert.equal(out.status, 0, out.stderr);
    assert.match(out.stdout, /tobe\/01-overview/);
    assert.match(out.stdout, /a \[ext\] A/);
  } finally { p.cleanup(); }
});

test('check возвращает 1 при ошибке модели', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.edges.push('b -> ghost');
  const p = tempProject(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  try {
    const check = run(['check'], p.dir);
    assert.equal(check.status, 1);
    assert.match(check.stdout, /ERR /);
  } finally { p.cleanup(); }
});

test('init создаёт проект, который собирается', () => {
  const p = tempProject({});
  try {
    const target = path.join(p.dir, 'новый');
    const init = run(['init', target], p.dir);
    assert.equal(init.status, 0, init.stderr);
    assert.ok(fs.existsSync(path.join(target, 'arch', 'meta.yaml')));
    assert.ok(fs.existsSync(path.join(target, 'arch', 'tobe', '01-overview.yaml')));
    const check = run(['check'], target);
    assert.equal(check.status, 0, check.stdout + check.stderr);
  } finally { p.cleanup(); }
});

test('layout --dry не меняет файл, layout --mode zone расставляет узлы', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.nodes.push({ id: 'c', label: 'C', kind: 'ext' });
  tab.overview.blocks[0].scheme.edges.push('b -> c');
  const p = tempProject(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  try {
    const file = path.join(p.arch, 'tobe', '01-overview.yaml');
    const before = fs.readFileSync(file, 'utf8');
    const dry = run(['layout', 'tobe/01', 'o', '--mode', 'zone', '--dry'], p.dir);
    assert.equal(dry.status, 0, dry.stderr);
    assert.equal(fs.readFileSync(file, 'utf8'), before, '--dry не должен менять файл');
    const real = run(['layout', 'tobe/01', 'o', '--mode', 'zone'], p.dir);
    assert.equal(real.status, 0, real.stderr);
    assert.notEqual(fs.readFileSync(file, 'utf8'), before);
    assert.match(fs.readFileSync(file, 'utf8'), /at: \[/);
  } finally { p.cleanup(); }
});

test('snapshot + diff видят изменения', () => {
  const p = tempProject(minimalFiles());
  try {
    const snap = run(['snapshot', 'v1'], p.dir);
    assert.equal(snap.status, 0, snap.stderr);
    const dirs = fs.readdirSync(path.join(p.dir, 'snapshots'));
    assert.equal(dirs.length, 1);
    assert.ok(fs.existsSync(path.join(p.dir, 'snapshots', dirs[0], 'architecture.html')));

    const clean = run(['diff', path.join('snapshots', dirs[0])], p.dir);
    assert.equal(clean.status, 0, clean.stderr);
    assert.match(clean.stdout, /отличий нет/);

    const tab = structuredClone(MINIMAL_TAB);
    tab.overview.blocks[0].scheme.nodes.push({ id: 'c', at: [400, 240, 160], label: 'C', kind: 'ext' });
    fs.writeFileSync(path.join(p.arch, 'tobe', '01-overview.yaml'), JSON.stringify(tab), 'utf8');

    const changed = run(['diff', path.join('snapshots', dirs[0])], p.dir);
    assert.equal(changed.status, 0, changed.stderr);
    assert.match(changed.stdout, /отличий: [1-9]/);
    assert.match(changed.stdout, /tobe\/01-overview/);
  } finally { p.cleanup(); }
});

test('map показывает покрытие, а при отсутствии map.yaml — понятную ошибку', () => {
  const p = tempProject(minimalFiles());
  try {
    const none = run(['map'], p.dir);
    assert.equal(none.status, 1);
    assert.match(none.stderr, /нет arch\/map\.yaml/);
    fs.writeFileSync(path.join(p.arch, 'map.yaml'), 'entries:\n  - { asis: a, tobe: b, status: move }\n', 'utf8');
    const ok = run(['map'], p.dir);
    assert.equal(ok.status, 0, ok.stderr);
    assert.match(ok.stdout, /покрыто 1/);
  } finally { p.cleanup(); }
});

test('show --json даёт машинный вывод', () => {
  const p = tempProject(minimalFiles());
  try {
    const out = run(['show', 'tobe/01', 'o', '--json'], p.dir);
    assert.equal(out.status, 0, out.stderr);
    const data = JSON.parse(out.stdout);
    assert.equal(data.id, 'tobe/01-overview');
    assert.equal(data.overview.blocks[0].type, 'scheme');
  } finally { p.cleanup(); }
});
