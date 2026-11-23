// Сборка: HTML и JSON собираются, шаблон подставляется, скрипт синтаксически валиден.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { buildAll } from '../scripts/build.mjs';
import { resolveProject, TOOL_ROOT } from '../scripts/lib/paths.mjs';
import { tempProject, minimalFiles } from './helpers.mjs';

test('buildAll собирает html и json во временный каталог', () => {
  const p = tempProject(minimalFiles());
  try {
    const out = path.join(p.dir, 'out');
    const res = buildAll({ dir: p.dir, out, quiet: true, noLog: true });
    const html = fs.readFileSync(path.join(out, 'architecture.html'), 'utf8');
    const json = JSON.parse(fs.readFileSync(path.join(out, 'architecture.json'), 'utf8'));
    assert.match(html, /<title>test · архитектура<\/title>/);
    assert.match(html, /const SECTIONS=\[/);
    assert.match(html, /const TABS=\[/);
    assert.equal(json.tabs.length, 1);
    assert.equal(json.tabs[0].id, 'tobe/01-overview');
    assert.equal(res.model.tabs.length, 1);
  } finally { p.cleanup(); }
});

test('сборка чужого проекта не зависит от cwd', () => {
  const p = tempProject(minimalFiles());
  try {
    const res = buildAll({ dir: p.arch, out: path.join(p.dir, 'out'), quiet: true, noLog: true });
    assert.equal(res.model.tabs.length, 1);
  } finally { p.cleanup(); }
});

test('оболочка страницы выбирается в meta.yaml: ui.shell', () => {
  const base = minimalFiles();
  const out = p => path.join(p.dir, 'out');

  // по умолчанию — panel: меню слева
  const a = tempProject(base);
  try {
    const res = buildAll({ dir: a.dir, out: out(a), quiet: true, noLog: true });
    assert.match(res.html, /id="navgroups"/);
    assert.match(res.html, /id="navq"/);
    assert.doesNotMatch(res.html, /class="tabs" id="tabs"/);
  } finally { a.cleanup(); }

  // classic — прежний вид: шапка и лента вкладок
  const classic = { ...base, 'meta.yaml': { ...base['meta.yaml'], ui: { shell: 'classic' } } };
  const b = tempProject(classic);
  try {
    const res = buildAll({ dir: b.dir, out: out(b), quiet: true, noLog: true });
    assert.match(res.html, /class="tabs" id="tabs"/);
    assert.match(res.html, /<header>/);
    assert.doesNotMatch(res.html, /id="navgroups"/);
  } finally { b.cleanup(); }
});

test('неизвестная оболочка — понятная ошибка и код 1', () => {
  const base = minimalFiles();
  const files = { ...base, 'meta.yaml': { ...base['meta.yaml'], ui: { shell: 'нетакой' } } };
  const p = tempProject(files);
  try {
    const errs = [], origErr = console.error, origExit = process.exit;
    let code = 0;
    console.error = m => errs.push(String(m));
    process.exit = c => { code = c; throw new Error('exit'); };
    try { buildAll({ dir: p.dir, out: path.join(p.dir, 'out'), quiet: true, noLog: true }); } catch { /* expected */ }
    console.error = origErr; process.exit = origExit;
    assert.equal(code, 1);
    assert.ok(errs.some(m => /оболочка «нетакой» не найдена/.test(m)), errs.join('\n'));
  } finally { p.cleanup(); }
});

test('ошибка модели завершает сборку с кодом 1', () => {
  const p = tempProject({ 'meta.yaml': { title: 't', heading: 'h', subtitle: 's' } }); // нет kinds
  try {
    const origExit = process.exit;
    let code = 0;
    process.exit = c => { code = c; throw new Error('exit'); };
    try { buildAll({ dir: p.dir, out: path.join(p.dir, 'out'), quiet: true, noLog: true }); } catch { /* expected */ }
    process.exit = origExit;
    assert.equal(code, 1);
  } finally { p.cleanup(); }
});


test('проект по умолчанию — docs/architecture независимо от cwd', () => {
  const prev = process.cwd();
  const env = process.env.ARCH_DIR;
  try {
    delete process.env.ARCH_DIR;
    process.chdir(TOOL_ROOT);
    assert.equal(resolveProject().dir, path.resolve(TOOL_ROOT, '../../docs/architecture'));
    assert.equal(resolveProject().src, path.join(TOOL_ROOT, 'src'));
  } finally {
    process.chdir(prev);
    if (env !== undefined) process.env.ARCH_DIR = env;
  }
});
