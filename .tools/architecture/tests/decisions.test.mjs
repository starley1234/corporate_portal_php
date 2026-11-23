// Журнал решений: arch/decisions/*.yaml -> сгенерированная вкладка в разделе role: governance.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { tempProject, MINIMAL_META, MINIMAL_TAB } from './helpers.mjs';
import { loadModel, compile } from '../scripts/lib/model.mjs';

const ARCH = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'scripts', 'arch.mjs');
const run = (args, dir) => spawnSync(process.execPath, [ARCH, ...args, '--dir', dir], { encoding: 'utf8' });

const META = {
  ...MINIMAL_META,
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary' },
    { dir: 'governance', label: 'Управление', role: 'governance' },
  ],
};
const ADR = (id, extra = {}) => ({
  id,
  title: `Решение ${id}`,
  status: 'proposed',
  date: '2026-11-05',
  links: ['tobe/01-overview'],
  context: 'контекст',
  decision: 'решение',
  consequences: ['последствие'],
  ...extra,
});

const build = files => {
  const p = tempProject({ 'meta.yaml': META, 'tobe/01-overview.yaml': MINIMAL_TAB, ...files });
  try { const model = loadModel(p.arch); return { model, ...compile(model), cleanup: p.cleanup }; }
  catch (e) { p.cleanup(); throw e; }
};

test('журнал собирает вкладку в раздел role: governance', t => {
  const r = build({ 'decisions/01-a.yaml': ADR('ADR-001'), 'decisions/02-b.yaml': ADR('ADR-002', { status: 'accepted' }) });
  t.after(r.cleanup);
  assert.equal(r.problems.errors.length, 0);
  const tab = r.model.tabs.find(x => x.id === 'governance/decisions');
  assert.ok(tab, 'вкладка «Журнал решений» должна быть в разделе governance');
  assert.equal(tab.generated, true);
  assert.match(tab.overview.lead, /2 архитектурных решений/);
  assert.match(tab.overview.lead, /1 принято, 1 предложено/);
  assert.equal(tab.overview.blocks[0].table.rows.length, 2);
  // тексты всех решений попадают в «Подробно»
  assert.ok(tab.detail.blocks.some(b => b.heading && b.heading.startsWith('ADR-001')));
  assert.ok(tab.detail.blocks.some(b => Array.isArray(b.steps)));
});

test('без раздела governance вкладка идёт в первый раздел', t => {
  const p = tempProject({ 'meta.yaml': MINIMAL_META, 'tobe/01-overview.yaml': MINIMAL_TAB, 'decisions/01-a.yaml': ADR('ADR-001') });
  t.after(p.cleanup);
  const model = loadModel(p.arch);
  assert.ok(model.tabs.find(x => x.id === 'tobe/decisions'), 'нет раздела governance — вкладка в tobe');
});

test('нет каталога decisions — вкладки нет и это не ошибка', t => {
  const r = build({});
  t.after(r.cleanup);
  assert.equal(r.problems.errors.length, 0);
  assert.equal(r.model.tabs.filter(x => x.generated).length, 0);
});

test('проверки журнала: дубль id, статус, ссылки, superseded', t => {
  assert.match(build({ 'decisions/01.yaml': ADR('ADR-001'), 'decisions/02.yaml': ADR('ADR-001') }).problems.errors.join(), /дубль id/);
  assert.match(build({ 'decisions/01.yaml': ADR('ADR-001', { status: 'maybe' }) }).problems.errors.join(), /неизвестный status/);
  assert.match(build({ 'decisions/01.yaml': ADR('ADR-001', { links: ['tobe/99-none'] }) }).problems.errors.join(), /вкладка "tobe\/99-none" не найдена/);
  assert.match(build({ 'decisions/01.yaml': ADR('ADR-001', { status: 'superseded' }) }).problems.warnings.join(), /superseded без supersedes/);
  assert.match(build({ 'decisions/01.yaml': ADR('ADR-001', { title: '' }) }).problems.errors.join(), /нет обязательного поля title/);
  assert.match(build({ 'decisions/01.yaml': ADR('ADR-001', { consequences: 'строка' }) }).problems.errors.join(), /consequences — список/);
});

test('CLI adr: список, фильтр по статусу и текст решения', t => {
  const p = tempProject({
    'meta.yaml': META,
    'tobe/01-overview.yaml': MINIMAL_TAB,
    'decisions/01.yaml': ADR('ADR-001'),
    'decisions/02.yaml': ADR('ADR-002', { status: 'accepted' }),
  });
  t.after(p.cleanup);
  const list = run(['adr'], p.arch);
  assert.equal(list.status, 0, list.stderr);
  assert.match(list.stdout, /всего 2/);

  const open = run(['adr', '--status', 'proposed'], p.arch);
  assert.match(open.stdout, /ADR-001/);
  assert.doesNotMatch(open.stdout, /ADR-002/);

  const one = run(['adr', 'ADR-002'], p.arch);
  assert.match(one.stdout, /принято/);
  assert.match(one.stdout, /последствия/);

  const js = JSON.parse(run(['adr', '--json'], p.arch).stdout);
  assert.equal(js.length, 2);
  assert.equal(js[0].id, 'ADR-001');

  const bad = run(['adr', '--status', 'whatever'], p.arch);
  assert.equal(bad.status, 1);
});
