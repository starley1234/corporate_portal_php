// Сводка проекта: что считается, что попадает в «что дальше», как отличить вкладку-процесс.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { loadModel, compile } from '../scripts/lib/model.mjs';
import { buildGraph, coverage } from '../scripts/lib/graph.mjs';
import { projectStatus, statusText } from '../scripts/lib/status.mjs';
import { tempProject, MINIMAL_META } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');

const META = {
  ...MINIMAL_META,
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary' },
    { dir: 'governance', label: 'Управление', role: 'governance' },
  ],
};
const nodes = ids => ids.map((id, i) => ({ id, at: [100 + i * 120, 100, 100], label: id, kind: 'core' }));
const tab = (title, ids) => ({ title, overview: { lead: title, blocks: [{ scheme: { size: [800, 300], nodes: nodes(ids), edges: [] } }] }, detail: { lead: 'x', blocks: [{ note: 'x' }] } });

test('вкладка из шагов считается процессом и не портит покрытие', () => {
  const p = tempProject({
    'meta.yaml': META,
    'tobe/01-flow.yaml': tab('Поток', ['a', 'b']),
    'tobe/02-steps.yaml': tab('Шаги', ['s1', 's2', 's3', 's4', 's5', 's6']),
    'entities.yaml': { entities: [{ id: 'a', type: 'service', title: 'A', aliases: ['a'] }] },
    'hypotheses/01-h.yaml': { id: 'H-001', title: 'Проверить', status: 'open', question: 'Стоит ли?', options: [{ id: 'A', title: 'да' }] },
  });
  try {
    const model = loadModel(p.arch);
    const g = buildGraph(model);
    const rows = coverage(model, g);
    const flow = rows.find(r => r.tab === 'tobe/01-flow');
    const steps = rows.find(r => r.tab === 'tobe/02-steps');
    assert.equal(flow.pct, 50);
    assert.equal(steps.process, true);
    assert.equal(steps.pct, null);
    const st = projectStatus(model, g, { errors: [], warnings: [] });
    assert.equal(st.coverage.avg, 50, 'процессная вкладка не должна входить в средний процент');
    assert.deepEqual(st.coverage.processes, ['tobe/02-steps']);
    assert.ok(st.hypotheses.open.includes('H-001'));
    assert.ok(st.next.some(x => x.includes('реестр')));
    assert.match(statusText(st), /что дальше:/);
  } finally { p.cleanup(); }
});

test('сводка на реальном проекте: цифры согласованы, есть следующие шаги', () => {
  const res = spawnSync(process.execPath, [ARCH, 'status', '--json'], { cwd: ROOT, encoding: 'utf8' });
  assert.equal(res.status, 0, res.stderr);
  const st = JSON.parse(res.stdout);
  assert.ok(st.tabs >= 30);
  assert.ok(st.graph.registry >= 50);
  assert.ok(st.coverage.avg > 0 && st.coverage.avg <= 100);
  assert.ok(st.coverage.processes.includes('asis/04-lifecycle'));
  assert.equal(st.checks.errors, 0);
  assert.ok(st.next.length >= 1);
  assert.ok(st.next.every(x => typeof x === 'string' && x.length > 3));
  assert.ok(Array.isArray(st.graph.orphans));
  assert.ok(st.coverage.avg >= 80, `покрытие реестром просело: ${st.coverage.avg}%`);
});

test('на странице есть вкладка «Состояние модели» со сводкой', () => {
  const model = loadModel(path.join(ROOT, 'arch'));
  const tab = model.tabs.find(t => t.title === 'Состояние модели');
  assert.ok(tab, 'вкладка состояния не собрана');
  assert.equal(tab.section, 'governance');
  const rows = tab.overview.blocks.find(b => b.table).table.rows;
  const keys = rows.map(r => r[0]);
  assert.ok(keys.includes('**Покрытие реестром**'));
  assert.ok(keys.includes('**Гипотез**'));
  const tabsRow = rows.find(r => r[0] === '**Вкладок**');
  assert.equal(Number(tabsRow[1]), model.tabs.length, 'вкладка состояния должна считать себя тоже');
  assert.ok(tab.overview.blocks.some(b => b.table && b.table.head[1] === 'Что требует внимания'));
});

test('сводка говорит по-русски и без машинного мусора', () => {
  const res = spawnSync(process.execPath, [ARCH, 'status'], { cwd: ROOT, encoding: 'utf8' });
  assert.equal(res.status, 0, res.stderr);
  assert.match(res.stdout, /разделы \d+ · вкладки \d+/);
  assert.match(res.stdout, /покрытие \d+%/);
  assert.match(res.stdout, /что дальше:/);
  assert.doesNotMatch(res.stdout, /undefined|NaN|\[object/);
});
