// Сверка модели с реальностью: инвентарь систем ↔ реестр сущностей.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { loadModel, compile } from '../scripts/lib/model.mjs';
import { buildGraph } from '../scripts/lib/graph.mjs';
import { compareInventory } from '../scripts/lib/inventory.mjs';
import { projectStatus } from '../scripts/lib/status.mjs';
import { tempProject, MINIMAL_META } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');

const META = {
  ...MINIMAL_META,
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary' },
    { dir: 'asis', label: 'AS-IS', role: 'asis', hidden: true },
    { dir: 'governance', label: 'Управление', role: 'governance' },
  ],
};
const tab = (title, ids) => ({
  title,
  overview: { lead: title, blocks: [{ scheme: { size: [800, 300], nodes: ids.map((id, i) => ({ id, at: [100 + i * 120, 100, 100], label: id, kind: 'core' })), edges: [] } }] },
  detail: { lead: 'x', blocks: [{ note: 'x' }] },
});
const FILES = {
  'meta.yaml': META,
  'entities.yaml': { entities: [
    { id: 'gateway', type: 'control', title: 'Шлюз', owner: 'Платформа', data_class: 'internal', aliases: ['gw'] },
    { id: 'postgres', type: 'store', title: 'База', owner: 'ИТ', data_class: 'internal' },
  ] },
  'asis/01-flow.yaml': tab('Как есть', ['gateway', 'postgres']),
  'tobe/01-flow.yaml': tab('Как надо', ['gateway']),
  'inventory.yaml': { title: 'Инвентарь', items: [
    { id: 'gw-1', entity: 'gateway', name: 'Gateway :4100', kind: 'service', owner: 'Платформа' },
    { id: 'db-1', entity: 'postgres', name: 'PostgreSQL', kind: 'store', owner: 'ИТ' },
  ] },
};

test('инвентарь сопоставляется с реестром, расхождений нет', () => {
  const p = tempProject(FILES);
  try {
    const model = loadModel(p.arch);
    const g = buildGraph(model);
    const res = compareInventory(model, g);
    assert.equal(res.total, 2);
    assert.equal(res.linked, 2);
    assert.deepEqual(res.unknown, []);
    assert.deepEqual(res.unconfirmed, []);
  } finally { p.cleanup(); }
});

test('позиция без entity находится по alias; неизвестная сущность — ошибка', () => {
  const ok = tempProject({ ...FILES, 'inventory.yaml': { items: [{ id: 'gw', name: 'Шлюз', kind: 'service', owner: 'ИТ' }] } });
  try {
    const model = loadModel(ok.arch);
    const res = compareInventory(model, buildGraph(model));
    assert.equal(res.unknown.length, 0, 'позиция должна найтись по alias gw');
  } finally { ok.cleanup(); }

  const bad = tempProject({ ...FILES, 'inventory.yaml': { items: [{ id: 'x', entity: 'нет-такой', name: 'X', owner: 'ИТ' }] } });
  try {
    const { problems } = compile(loadModel(bad.arch));
    assert.ok(problems.errors.some(e => e.includes('нет-такой')));
  } finally { bad.cleanup(); }

  const dup = tempProject({ ...FILES, 'inventory.yaml': { items: [
    { id: 'a', entity: 'gateway', name: 'A', owner: 'ИТ' },
    { id: 'a', entity: 'postgres', name: 'B', owner: 'ИТ' },
  ] } });
  try {
    const { problems } = compile(loadModel(dup.arch));
    assert.ok(problems.errors.some(e => e.includes('дубль')));
  } finally { dup.cleanup(); }
});

test('нарисовано в AS-IS, но не в инвентаре — попадает в отчёт', () => {
  const p = tempProject({ ...FILES, 'inventory.yaml': { items: [{ id: 'gw-1', entity: 'gateway', name: 'Gateway', kind: 'service', owner: 'ИТ' }] } });
  try {
    const model = loadModel(p.arch);
    const res = compareInventory(model, buildGraph(model));
    assert.deepEqual(res.unconfirmed.map(e => e.id), ['postgres']);
  } finally { p.cleanup(); }
});

test('сводка и CLI сообщают об инвентаре', () => {
  const p = tempProject(FILES);
  try {
    const model = loadModel(p.arch);
    const g = buildGraph(model);
    const st = projectStatus(model, g, compile(model).problems);
    assert.equal(st.inventory.total, 2);
    assert.deepEqual(st.inventory.unknown, []);
    const cli = spawnSync(process.execPath, [ARCH, 'inventory', '--json', '--dir', p.arch], { encoding: 'utf8' });
    assert.equal(cli.status, 0, cli.stderr);
    const data = JSON.parse(cli.stdout);
    assert.equal(data.total, 2);
    assert.deepEqual(data.unconfirmed, []);
  } finally { p.cleanup(); }
});

test('на реальном проекте инвентарь заполнен и не спорит с моделью', () => {
  const res = spawnSync(process.execPath, [ARCH, 'inventory', '--json'], { cwd: ROOT, encoding: 'utf8' });
  assert.equal(res.status, 0, res.stderr);
  const data = JSON.parse(res.stdout);
  assert.ok(data.total >= 20, `инвентарь слишком мал: ${data.total}`);
  assert.deepEqual(data.unknown, [], 'в инвентаре есть системы, которых нет в модели');
  assert.deepEqual(data.unconfirmed, [], 'в AS-IS нарисовано то, чего нет в инвентаре');
});
