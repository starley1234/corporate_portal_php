// Гипотезы: arch/hypotheses/*.yaml -> вкладка «Гипотезы» и проверки полей.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { loadModel, compile } from '../scripts/lib/model.mjs';
import { tempProject, MINIMAL_META, MINIMAL_TAB } from './helpers.mjs';

const ARCH = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'scripts', 'arch.mjs');
const run = (args, dir) => spawnSync(process.execPath, [ARCH, ...args, '--dir', dir], { encoding: 'utf8' });

const META = {
  ...MINIMAL_META,
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary' },
    { dir: 'governance', label: 'Управление', role: 'governance' },
  ],
};
const HYP = (extra = {}) => ({
  id: 'H-001',
  title: 'Проверить подход',
  status: 'open',
  date: '2026-11-05',
  owner: 'Подразделение',
  question: 'Стоит ли это делать?',
  assumptions: ['допущение'],
  options: [
    { id: 'A', title: 'Вариант А', pros: 'быстро', cons: 'дорого', cost: '1 нед', risk: 'низкий' },
    { id: 'B', title: 'Вариант Б', pros: 'надёжно', cons: 'долго' },
  ],
  evidence: [{ kind: 'замер', note: 'сравнить', date: '2026-11-10' }],
  links: ['tobe/01-overview'],
  ...extra,
});
const files = extra => ({ 'meta.yaml': META, 'tobe/01-overview.yaml': MINIMAL_TAB, 'hypotheses/01-h.yaml': HYP(), ...extra });

test('гипотеза попадает во вкладку «Гипотезы» раздела управления', () => {
  const p = tempProject(files());
  try {
    const model = loadModel(p.arch);
    const tab = model.tabs.find(t => t.title === 'Гипотезы');
    assert.ok(tab, 'вкладка гипотез не собрана');
    assert.equal(tab.section, 'governance');
    assert.match(tab.overview.lead, /Всего гипотез: 1/);
    const table = tab.overview.blocks.find(b => b.table).table;
    assert.deepEqual(table.head, ['Гипотеза', 'Статус', 'Название', 'Вопрос', 'Чем проверяем', 'Где']);
    assert.equal(table.rows[0][0], '`H-001`');
    assert.equal(table.rows[0][1], 'открыта');
  } finally { p.cleanup(); }
});

test('ошибки: статус, вопрос, ссылка, дубль варианта', () => {
  const p = tempProject(files({ 'hypotheses/01-h.yaml': HYP({ status: 'может быть', question: '', links: ['tobe/нет'], options: [{ id: 'A', title: 'раз' }, { id: 'A', title: 'два' }] }) }));
  try {
    const { problems } = compile(loadModel(p.arch));
    const all = problems.errors.join('\n');
    assert.match(all, /status/);
    assert.match(all, /question/);
    assert.match(all, /tobe\/нет/);
    assert.match(all, /дубль id варианта/);
  } finally { p.cleanup(); }
});

test('предупреждения: подтверждено без итога, нет вариантов', () => {
  const p = tempProject(files({ 'hypotheses/01-h.yaml': HYP({ status: 'confirmed', options: undefined, evidence: undefined }) }));
  try {
    const { problems } = compile(loadModel(p.arch));
    const all = problems.warnings.join('\n');
    assert.match(all, /verdict/);
    assert.match(all, /options/);
  } finally { p.cleanup(); }
});

test('CLI: hypo, hypo <id>, --status и --json', () => {
  const p = tempProject(files());
  try {
    const list = run(['hypo'], p.dir);
    assert.equal(list.status, 0, list.stderr);
    assert.match(list.stdout, /H-001/);
    assert.match(list.stdout, /открыта/);

    const one = run(['hypo', 'H-001'], p.dir);
    assert.match(one.stdout, /Стоит ли это делать\?/);
    assert.match(one.stdout, /Вариант А/);

    const json = JSON.parse(run(['hypo', '--json'], p.dir).stdout);
    assert.equal(json.length, 1);
    assert.equal(json[0].options.length, 2);

    const empty = run(['hypo', '--status', 'parked'], p.dir);
    assert.match(empty.stdout, /гипотез: 0/);
  } finally { p.cleanup(); }
});

test('на реальной модели: есть гипотезы с вариантами и все со ссылками на вкладки', () => {
  const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
  const model = loadModel(path.join(root, 'arch'));
  const list = model.hypotheses || [];
  assert.ok(list.length >= 3);
  for (const h of list) {
    assert.ok(h.options.length >= 2, `${h.id}: ожидались варианты`);
    for (const l of h.links) assert.ok(model.tabs.some(t => t.id === l), `${h.id}: нет вкладки ${l}`);
  }
  const ids = list.map(h => h.id);
  assert.deepEqual(ids, [...new Set(ids)]);
});
