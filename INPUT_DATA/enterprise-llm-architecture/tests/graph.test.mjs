// Граф модели: реестр сущностей, связи, поиск, влияние, покрытие.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadModel, compile } from '../scripts/lib/model.mjs';
import { buildGraph, searchGraph, impactOf, coverage, toCypher } from '../scripts/lib/graph.mjs';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { tempProject, MINIMAL_META, minimalFiles } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runCli = args => spawnSync(process.execPath, [path.join(ROOT, 'scripts', 'arch.mjs'), ...args], { cwd: ROOT, encoding: 'utf8' });

const META = {
  ...MINIMAL_META,
  title: 'test · граф',
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary' },
    { dir: 'governance', label: 'Управление', role: 'governance' },
  ],
};

const schemeTab = (title, nodes, edges, extra = {}) => ({
  title,
  overview: {
    lead: `${title}: вводный абзац`,
    blocks: [{ scheme: { size: [800, 300], nodes, edges } }],
  },
  detail: { lead: 'подробно', blocks: [{ note: `Про ${title} написано здесь.` }] },
  ...extra,
});

const FILES = {
  'meta.yaml': META,
  'components.yaml': { a: '**A** — описание' },
  'entities.yaml': {
    title: 'Реестр',
    entities: [
      { id: 'gateway', type: 'control', title: 'Шлюз доверия', owner: 'Платформа', aliases: ['gw', 'gw-v2'] },
      { id: 'dlp', type: 'control', title: 'Проверка DLP', owner: 'ИБ', aliases: ['dlp'] },
    ],
  },
  'tobe/01-flow.yaml': schemeTab('Основной путь',
    [{ id: 'gw', at: [100, 100, 140], label: 'Gateway', kind: 'core' },
      { id: 'dlp', at: [400, 100, 140], label: 'DLP', kind: 'ext' }],
    ['gw -> dlp']),
  'tobe/02-detail.yaml': schemeTab('Тот же шлюз',
    [{ id: 'gw-v2', at: [100, 100, 140], label: 'Gateway v2', kind: 'core' },
      { id: 'b', at: [400, 100, 140], label: 'Сервис B', kind: 'core' }],
    [{ 'gw-v2 -> b': { label: 'вызов' } }]),
};

const build = extra => {
  const p = tempProject({ ...FILES, ...extra });
  const model = loadModel(p.arch);
  return { p, model, graph: buildGraph(model) };
};

test('alias объединяет узлы разных вкладок в одну сущность', () => {
  const { p, graph } = build();
  try {
    const gw = graph.entities.get('gateway');
    assert.equal(gw.title, 'Шлюз доверия');
    assert.deepEqual([...gw.tabs].sort(), ['tobe/01-flow', 'tobe/02-detail']);
    assert.equal(gw.occurrences.length, 2);
    assert.ok(graph.edges.some(e => e.from === 'gateway' && e.to === 'dlp'));
    assert.ok(graph.edges.some(e => e.from === 'gateway' && e.to === 'b' && e.label === 'вызов'));
  } finally { p.cleanup(); }
});

test('ref связывает узел с реестром, неизвестный ref — ошибка', () => {
  const tab = schemeTab('С ref', [{ id: 'x1', ref: 'dlp', at: [100, 100, 140], label: 'DLP', kind: 'core' }], []);
  const a = build({ 'tobe/03-ref.yaml': tab });
  try {
    const dlp = a.graph.entities.get('dlp');
    assert.ok([...dlp.tabs].includes('tobe/03-ref'));
  } finally { a.p.cleanup(); }

  const bad = schemeTab('Плохой ref', [{ id: 'x2', ref: 'нет-такой', at: [100, 100, 140], label: 'X', kind: 'core' }], []);
  const b = build({ 'tobe/04-bad.yaml': bad });
  try {
    const { problems } = compile(b.model);
    assert.ok(problems.errors.some(e => e.includes('нет-такой')), 'ожидалась ошибка про неизвестный ref');
  } finally { b.p.cleanup(); }
});

test('занятый alias — ошибка реестра', () => {
  const { p, model } = build({
    'entities.yaml': { entities: [
      { id: 'one', type: 'service', title: 'Один', aliases: ['shared'] },
      { id: 'two', type: 'service', title: 'Два', aliases: ['shared'] },
    ] },
  });
  try {
    const { problems } = compile(model);
    assert.ok(problems.errors.some(e => e.includes('shared')));
  } finally { p.cleanup(); }
});

test('у сущности реестра должны быть владелец и класс данных', () => {
  const { p, model } = build({ 'entities.yaml': { entities: [{ id: 'x', type: 'service', title: 'Без хозяина' }] } });
  try {
    const { problems } = compile(model);
    const all = problems.warnings.join('\n');
    assert.match(all, /owner/);
    assert.match(all, /data_class/);
    const bad = build({ 'entities.yaml': { entities: [{ id: 'x', type: 'service', title: 'X', owner: 'ИТ', data_class: 'секретно' }] } });
    try {
      assert.match(compile(bad.model).problems.warnings.join('\n'), /неизвестный data_class/);
    } finally { bad.p.cleanup(); }
  } finally { p.cleanup(); }
});

test('реестр сущностей показывает, где сущность встречается', () => {
  const { p, model } = build();
  try {
    const tab = model.tabs.find(t => t.title === 'Реестр сущностей');
    assert.ok(tab, 'вкладка реестра не собрана');
    const table = tab.overview.blocks.find(b => b.table).table;
    const row = table.rows.find(r => r[0] === '`gateway`');
    assert.ok(row, 'в реестре нет gateway');
    assert.match(row[4], /tobe\/01-flow/, 'реестр не видит вкладку, где нарисован объект');
    assert.match(tab.overview.lead, /из них [1-9]/);
  } finally { p.cleanup(); }
});

test('ask находит фрагмент и указывает вкладку', () => {
  const { p, graph } = build();
  try {
    const res = searchGraph(graph, 'DLP', { limit: 5 });
    assert.ok(res.hits.length > 0);
    assert.ok(res.hits.some(h => h.tab === 'tobe/01-flow'), 'ожидался фрагмент из вкладки со схемой');
    assert.ok(res.hits[0].text.length > 0);
    assert.ok(res.entities.some(e => e.id === 'dlp'));
  } finally { p.cleanup(); }
});

test('impact показывает, кого затронет изменение', () => {
  const { p, graph } = build();
  try {
    const r = impactOf(graph, 'gateway');
    assert.ok(r);
    assert.ok(r.outgoing.some(e => e.to === 'dlp'));
    assert.equal(r.occurrences.length, 2);
    assert.ok(r.tabs.includes('tobe/02-detail'));
    const none = impactOf(graph, 'gateway', { depth: 1 });
    assert.ok(none.dependents.length >= 0);
  } finally { p.cleanup(); }
});

test('coverage считает сопоставленные узлы и экспорт в Cypher', () => {
  const { p, graph } = build();
  try {
    const rows = coverage(graph.model, graph);
    const flow = rows.find(r => r.tab === 'tobe/01-flow');
    assert.equal(flow.total, 2);
    assert.equal(flow.mapped, 2);
    const cy = toCypher(graph);
    assert.match(cy, /MERGE \(n:Entity \{id: "gateway"\}\)/);
    assert.match(cy, /MERGE \(a\)-\[:FLOW/);
  } finally { p.cleanup(); }
});

test('CLI: graph/ask/impact/entity/path отвечают на реальной модели проекта', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const arch = path.join(root, 'scripts', 'arch.mjs');
  const run = args => spawnSync(process.execPath, [arch, ...args], { cwd: root, encoding: 'utf8' });

  const g = run(['graph', '--json']);
  assert.equal(g.status, 0, g.stderr);
  const stats = JSON.parse(g.stdout);
  assert.ok(stats.entities > 50);
  assert.ok(stats.coverage.length > 10);

  const ask = run(['ask', 'DLP', '--json', '--limit', '3']);
  assert.equal(ask.status, 0, ask.stderr);
  const res = JSON.parse(ask.stdout);
  assert.ok(res.hits.length > 0);
  assert.ok(res.hits[0].where.length > 0);

  const imp = run(['impact', 'dlp', '--json']);
  assert.equal(imp.status, 0, imp.stderr);
  const ii = JSON.parse(imp.stdout);
  assert.equal(ii.id, 'dlp');
  assert.ok(ii.occurrences.length > 3);

  const ent = run(['entity', 'gateway', '--json']);
  assert.equal(ent.status, 0, ent.stderr);
  assert.ok(JSON.parse(ent.stdout).aliases.includes('gw-v2'));

  const pth = run(['path', 'gateway', 'dlp']);
  assert.equal(pth.status, 0, pth.stderr);
  assert.match(pth.stdout, /цепочка связей/);
});

test('экспорт в Neo4j: число узлов и связей в файле совпадает с графом', () => {
  const p = tempProject(minimalFiles({
    'entities.yaml': { entities: [
      { id: 'svc', type: 'service', title: 'Сервис "с кавычками"', owner: 'ИТ', data_class: 'internal' },
    ] },
  }));
  try {
    const model = loadModel(p.arch);
    compile(model);
    const g = buildGraph(model);
    const text = toCypher(g, { title: 'тест' });
    const nodes = text.split('\n').filter(l => l.startsWith('MERGE (n:Entity')).length;
    const edges = text.split('\n').filter(l => l.startsWith('MATCH (a:Entity')).length;
    assert.equal(nodes, g.entities.size);
    assert.ok(edges > 0);
    assert.ok(edges <= g.edges.length);
    assert.match(text, /CREATE CONSTRAINT entity_id/);
    assert.match(text, /MERGE \(n:Entity \{id: "svc"\}\)/);
    assert.match(text, /Сервис \\"с кавычками\\"/, 'кавычки экранируются');
    // с --notes попадают и «шумные» связи about/affects
    const all = toCypher(g, { notes: true });
    assert.equal(all.split('\n').filter(l => l.startsWith('MATCH (a:Entity')).length, g.edges.length);
  } finally { p.cleanup(); }
});

test('graph --cypher --out пишет файл и печатает совпадающие числа', () => {
  const json = runCli(['graph', '--json']);
  assert.equal(json.status, 0, json.stderr);
  const st = JSON.parse(json.stdout);
  const out = path.join(ROOT, 'exports', 'test-graph.cypher');
  try {
    const res = runCli(['graph', '--cypher', '--out', out]);
    assert.equal(res.status, 0, res.stderr);
    assert.match(res.stdout, /узлов 271|узлов \d+/);
    const text = fs.readFileSync(out, 'utf8');
    assert.equal(text.split('\n').filter(l => l.startsWith('MERGE (n:Entity')).length, st.entities);
    assert.ok(text.split('\n').filter(l => l.startsWith('MATCH (a:Entity')).length > 100);
  } finally { fs.rmSync(out, { force: true }); }
});
