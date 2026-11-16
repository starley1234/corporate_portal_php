// Типы связей: связь означает действие, а не только подпись.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { loadModel, compile, RELATION_TYPES } from '../scripts/lib/model.mjs';
import { buildGraph } from '../scripts/lib/graph.mjs';
import { agentScheme } from '../scripts/lib/agent.mjs';
import { tempProject, MINIMAL_META } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');
const run = args => spawnSync(process.execPath, [ARCH, ...args], { cwd: ROOT, encoding: 'utf8' });

const META = { ...MINIMAL_META, kinds: { ...MINIMAL_META.kinds, sec: ['#e11d48', 'Безопасность'] }, sections: [
  { dir: 'tobe', label: 'TO-BE', role: 'primary' },
  { dir: 'governance', label: 'Управление', role: 'governance' },
] };
const nodes = [
  { id: 'gw', at: [100, 100, 140], label: 'Шлюз', kind: 'core' },
  { id: 'dlp', at: [400, 100, 140], label: 'DLP', kind: 'sec' },
  { id: 'llm', at: [700, 100, 140], label: 'Модель', kind: 'core' },
];
const tab = edges => ({
  title: 'Контур',
  overview: { lead: 'контур', blocks: [{ scheme: { size: [900, 300], nodes, edges } }] },
  detail: { lead: 'подробно', blocks: [{ note: 'пусто' }] },
});
const proj = edges => tempProject({ 'meta.yaml': META, 'tobe/01-flow.yaml': tab(edges) });

test('rel становится типом связи в графе, по умолчанию — flow', () => {
  const p = proj([
    'gw -> dlp',
    { 'dlp -> llm': { label: 'запрос', rel: 'uses' } },
  ]);
  try {
    const model = loadModel(p.arch);
    const { problems } = compile(model);
    assert.equal(problems.errors.length, 0, problems.errors.join('\n'));
    const g = buildGraph(model);
    const flow = g.edges.find(e => e.from === 'gw' && e.to === 'dlp');
    const typed = g.edges.find(e => e.from === 'dlp' && e.to === 'llm');
    assert.equal(flow.type, 'flow');
    assert.equal(typed.type, 'uses');
    assert.equal(typed.label, 'запрос');
  } finally { p.cleanup(); }
});

test('неизвестный тип связи — ошибка со списком допустимых', () => {
  const p = proj([{ 'gw -> dlp': { rel: 'магия' } }]);
  try {
    const { problems } = compile(loadModel(p.arch));
    assert.ok(problems.errors.some(e => e.includes('магия') && e.includes('rel')), problems.errors.join('\n'));
    assert.ok(problems.errors.some(e => e.includes('uses')));
  } finally { p.cleanup(); }
});

test('rel виден агенту и человеку в выжимке вкладки', () => {
  const p = proj([{ 'gw -> dlp': { label: 'sync', rel: 'uses' } }]);
  try {
    const model = loadModel(p.arch);
    compile(model);
    const scheme = agentScheme(model.tabs[0].overview.blocks[0].scheme);
    assert.equal(scheme.edges[0].rel, 'uses');
    const plain = agentScheme({ nodes, edges: ['gw -> dlp'] });
    assert.equal(plain.edges[0].rel, undefined, 'flow не засоряет выжимку');
  } finally { p.cleanup(); }
});

test('формат объекта связи f/t тоже понимает rel', () => {
  const p = proj([{ f: 'gw', t: 'dlp', l: 'ключи', rel: 'carries' }]);
  try {
    const model = loadModel(p.arch);
    compile(model);
    const g = buildGraph(model);
    assert.equal(g.edges.find(e => e.from === 'gw' && e.to === 'dlp').type, 'carries');
  } finally { p.cleanup(); }
});

test('в реальном проекте типы связей размечены, а не только подписи', () => {
  const json = run(['graph', '--json']);
  assert.equal(json.status, 0, json.stderr);
  const byEdge = JSON.parse(json.stdout).byEdge;
  for (const t of ['uses', 'carries', 'enforces', 'runs_on'])
    assert.ok(byEdge[t] > 0, `нет связей типа ${t}: ${JSON.stringify(byEdge)}`);

  const model = loadModel(path.join(ROOT, 'arch'));
  compile(model);
  const g = buildGraph(model);
  const typed = g.edges.filter(e => e.tab && ['uses', 'carries', 'enforces', 'runs_on'].includes(e.type));
  assert.ok(typed.length >= 40, `размечено мало связей схем: ${typed.length}`);
  for (const e of typed) assert.ok(RELATION_TYPES.includes(e.type));
  assert.ok(typed.some(e => e.type === 'enforces'), 'нет связей «обеспечивает»');
});
