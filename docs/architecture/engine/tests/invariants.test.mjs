// Инварианты: правила «чего быть не должно» проверяются на схемах.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { loadModel, compile } from '../scripts/lib/model.mjs';
import { checkOnTab } from '../scripts/lib/invariants.mjs';
import { tempProject, MINIMAL_META } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');

const META = { ...MINIMAL_META, sections: [{ dir: 'tobe', label: 'TO-BE', role: 'primary' }] };
const tab = (edges, ids = ['a', 'b', 'c', 'd']) => ({
  title: 'Контур',
  overview: {
    lead: 'контур',
    blocks: [{ scheme: { size: [800, 300], nodes: ids.map((id, i) => ({ id, at: [100 + i * 150, 100, 120], label: id, kind: 'core' })), edges } }],
  },
  detail: { lead: 'подробно', blocks: [{ note: 'пусто' }] },
});
const rule = (o = {}) => ({ file: 'invariants/01.yaml', id: 'INV-1', title: 'Правило', rule: 'no-bypass', tabs: ['tobe/01-flow'], from: ['a'], to: ['d'], through: ['b'], ...o });
const project = (edges, extra = {}) => tempProject({ 'meta.yaml': META, 'tobe/01-flow.yaml': tab(edges), 'invariants/01.yaml': rule(), ...extra });

test('no-bypass: путь в обход обязательного узла — нарушение', () => {
  const direct = project(['a -> b', 'b -> c', 'c -> d', 'a -> d']);
  try {
    const model = loadModel(direct.arch);
    const { problems } = compile(model);
    assert.ok(problems.errors.some(e => e.includes('в обход')), 'должен поймать путь a → d мимо b');
    assert.equal(model.invariantsReport[0].violations.length, 1);
  } finally { direct.cleanup(); }

  const ok = project(['a -> b', 'b -> c', 'c -> d']);
  try {
    const { problems } = compile(loadModel(ok.arch));
    assert.equal(problems.errors.length, 0);
  } finally { ok.cleanup(); }
});

test('no-edge: прямая связь запрещённых узлов — нарушение в обе стороны', () => {
  for (const e of ['a -> d', 'd -> a']) {
    const p = project([e], { 'invariants/01.yaml': rule({ rule: 'no-edge', through: undefined }) });
    try {
      const { problems } = compile(loadModel(p.arch));
      assert.ok(problems.errors.some(x => x.includes('напрямую')), `не поймал ${e}`);
    } finally { p.cleanup(); }
  }
  const p = project(['a -> b', 'b -> d'], { 'invariants/01.yaml': rule({ rule: 'no-edge', through: undefined }) });
  try {
    const { problems } = compile(loadModel(p.arch));
    assert.equal(problems.errors.length, 0);
  } finally { p.cleanup(); }
});

test('reachable: разорванный контур — нарушение; severity warn даёт предупреждение', () => {
  const broken = project(['a -> b'], { 'invariants/01.yaml': rule({ rule: 'reachable', through: undefined }) });
  try {
    const { problems } = compile(loadModel(broken.arch));
    assert.ok(problems.errors.some(e => e.includes('контур разорван')));
  } finally { broken.cleanup(); }

  const soft = project(['a -> b'], { 'invariants/01.yaml': rule({ rule: 'reachable', severity: 'warn', through: undefined, to: ['c'] }) });
  try {
    const { problems } = compile(loadModel(soft.arch));
    assert.equal(problems.errors.length, 0);
    assert.ok(problems.warnings.some(w => w.includes('контур разорван')));
  } finally { soft.cleanup(); }
});

test('черновик не проверяется, а нарушение видно в отчёте', () => {
  const p = project(['a -> d'], { 'invariants/01.yaml': rule({ status: 'draft' }) });
  try {
    const model = loadModel(p.arch);
    const { problems } = compile(model);
    assert.equal(problems.errors.length, 0);
    const rep = model.invariantsReport[0];
    assert.equal(rep.status, 'draft');
    assert.equal(rep.violations.length, 1, 'нарушение всё равно показывается в отчёте');
  } finally { p.cleanup(); }
});

test('проверяется структура правила', () => {
  const cases = [
    [{ rule: 'magic' }, 'неизвестное правило'],
    [{ through: [] }, 'no-bypass без through'],
    [{ severity: 'blocker' }, 'severity'],
    [{ tabs: ['нет/такой'] }, 'не найдено ни одной вкладки'],
    [{ лишнее: 1 }, 'неизвестные ключи'],
  ];
  for (const [patch, needle] of cases) {
    const p = project(['a -> b'], { 'invariants/01.yaml': rule(patch) });
    try {
      const { problems } = compile(loadModel(p.arch));
      assert.ok(problems.errors.some(e => e.includes(needle)), `нет ошибки «${needle}»: ${problems.errors}`);
    } finally { p.cleanup(); }
  }
});

test('правило, не нашедшее своих узлов, предупреждает, а не молчит', () => {
  const p = project(['a -> b'], { 'invariants/01.yaml': rule({ from: ['x'], to: ['y'], through: ['b'] }) });
  try {
    const { problems } = compile(loadModel(p.arch));
    assert.ok(problems.warnings.some(w => w.includes('ни к чему не применилось')));
  } finally { p.cleanup(); }
});

test('checkOnTab отличает «нет пути» от «путь есть, но в обход»', () => {
  const t = tab(['a -> b', 'b -> c']);
  const r1 = checkOnTab({ rule: 'no-bypass', from: ['a'], to: ['c'], through: ['b'] }, t);
  assert.deepEqual(r1.bad, []);
  const r2 = checkOnTab({ rule: 'no-bypass', from: ['a'], to: ['c'], through: ['b'] }, tab(['a -> c']));
  assert.equal(r2.bad.length, 1);
});
