// Требования и покрытие: кто чем закрыт и где пробел.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { loadModel, compile } from '../scripts/lib/model.mjs';
import { buildGraph } from '../scripts/lib/graph.mjs';
import { projectStatus } from '../scripts/lib/status.mjs';
import { tempProject, MINIMAL_META } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');
const run = args => spawnSync(process.execPath, [ARCH, ...args], { cwd: ROOT, encoding: 'utf8' });

const META = { ...MINIMAL_META, sections: [
  { dir: 'tobe', label: 'TO-BE', role: 'primary' },
  { dir: 'governance', label: 'Управление', role: 'governance' },
] };
const tab = {
  title: 'Контур',
  overview: { lead: 'контур', blocks: [{ scheme: { size: [800, 300], nodes: [
    { id: 'dlp', at: [100, 100, 160], label: 'DLP', kind: 'core' },
    { id: 'gw', at: [400, 100, 160], label: 'Шлюз', kind: 'core' },
  ], edges: ['gw -> dlp'] } }] },
  detail: { lead: 'подробно', blocks: [{ note: 'пусто' }] },
};
const base = {
  'meta.yaml': META,
  'tobe/01-flow.yaml': tab,
  'entities.yaml': { entities: [
    { id: 'dlp', type: 'control', title: 'DLP', owner: 'ИБ', data_class: 'secret' },
    { id: 'gw', type: 'control', title: 'Шлюз', owner: 'ИТ', data_class: 'internal' },
  ] },
};
const controlsFile = items => ({ 'controls/01-ib.yaml': items });
const proj = extra => tempProject({ ...base, ...extra });

test('требование ссылается на компоненты, покрытие считается', () => {
  const p = proj(controlsFile([
    { id: 'IB-01', title: 'Проверка текста', area: 'ИБ', satisfied_by: ['dlp'] },
    { id: 'IB-02', title: 'Шифрование диска', area: 'Эксплуатация', satisfied_by: [], note: 'не сделано' },
  ]));
  try {
    const model = loadModel(p.arch);
    const { problems } = compile(model);
    assert.equal(problems.errors.length, 0);
    const rep = model.controlsReport;
    assert.equal(rep.length, 2);
    assert.equal(rep[0].covered, true);
    assert.equal(rep[1].covered, false);
    const st = projectStatus(model, buildGraph(model), problems);
    assert.deepEqual(st.controls.gaps, ['IB-02']);
    assert.ok(st.next.some(x => x.includes('IB-02')), 'пробел попадает в «что дальше»');
  } finally { p.cleanup(); }
});

test('неизвестный компонент — ошибка, пробел без пояснения — предупреждение', () => {
  const p = proj(controlsFile([
    { id: 'IB-01', title: 'Проверка текста', satisfied_by: ['нет-такого'] },
    { id: 'IB-02', title: 'Без покрытия' },
  ]));
  try {
    const { problems } = compile(loadModel(p.arch));
    assert.ok(problems.errors.some(e => e.includes('нет-такого')));
    assert.ok(problems.warnings.some(w => w.includes('без note')));
  } finally { p.cleanup(); }
});

test('проверяются структура, дубли и область', () => {
  const p = proj(controlsFile([
    { id: 'X-1', title: 'Первое', satisfied_by: [] , note: 'нет' },
    { id: 'X-1', title: 'Дубль', area: 'Магия', satisfied_by: [] , note: 'нет' },
    { title: 'Без id', satisfied_by: ['dlp'] },
  ]));
  try {
    const { problems } = compile(loadModel(p.arch));
    assert.ok(problems.errors.some(e => e.includes('дубль id')));
    assert.ok(problems.errors.some(e => e.includes('нет обязательного поля id')));
    assert.ok(problems.warnings.some(w => w.includes('area')));
  } finally { p.cleanup(); }
});

test('в файле допустим и один объект, и список; вкладка появляется', () => {
  const single = proj({ 'controls/01-ib.yaml': { id: 'IB-01', title: 'Одна', satisfied_by: ['dlp'] } });
  try {
    const model = loadModel(single.arch);
    compile(model);
    assert.equal(model.controlsReport.length, 1);
    assert.ok(model.tabs.some(t => t.title === 'Требования и покрытие'), 'вкладка требований не собралась');
  } finally { single.cleanup(); }
});

test('без требований проект остаётся прежним', () => {
  const p = proj({});
  try {
    const model = loadModel(p.arch);
    const { problems } = compile(model);
    assert.equal(problems.errors.length, 0);
    assert.equal(model.controlsReport.length, 0);
    assert.ok(!model.tabs.some(t => t.title === 'Требования и покрытие'));
  } finally { p.cleanup(); }
});

test('impact показывает, какие требования закрывает компонент', () => {
  const p = proj(controlsFile([
    { id: 'IB-01', title: 'Проверка текста', area: 'ИБ', satisfied_by: ['dlp', 'gw'] },
    { id: 'IB-02', title: 'Только DLP', area: 'ИБ', satisfied_by: ['dlp'] },
  ]));
  try {
    const res = run(['impact', 'dlp', '--dir', p.dir, '--json']);
    assert.equal(res.status, 0, res.stderr);
    const data = JSON.parse(res.stdout);
    assert.deepEqual(data.requirements.map(r => r.id), ['IB-01', 'IB-02']);
    assert.deepEqual(data.requirements[0].others, ['gw']);
    assert.deepEqual(data.requirements[1].others, []);

    const text = run(['impact', 'dlp', '--dir', p.dir]).stdout;
    assert.match(text, /обеспечивает требования/);
    assert.match(text, /других исполнителей нет/);
  } finally { p.cleanup(); }
});
