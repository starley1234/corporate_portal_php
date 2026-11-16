// Замочки разделов: locked: true фиксируется хешем содержимого вкладок.
// Проверяется в check/build; обновляется осознанно командой arch lock --force.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { tempProject, MINIMAL_META, MINIMAL_TAB } from './helpers.mjs';
import { loadModel, compile, mapCoverage } from '../scripts/lib/model.mjs';

const ARCH = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'scripts', 'arch.mjs');
const run = (args, dir) => spawnSync(process.execPath, [ARCH, ...args, '--dir', dir], { encoding: 'utf8' });

// Проект с залоченным основным разделом tobe и скрытым asis (не залочен).
const LOCKED_META = {
  ...MINIMAL_META,
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary', locked: true },
    { dir: 'asis', label: 'AS-IS', hidden: true, role: 'asis' },
  ],
};
const lockedFiles = () => ({
  'meta.yaml': LOCKED_META,
  'components.yaml': { a: '**A** — описание' },
  'tobe/01-overview.yaml': MINIMAL_TAB,
});

const compileFiles = files => {
  const p = tempProject(files);
  try { const model = loadModel(p.arch); return { model, ...compile(model) }; }
  finally { p.cleanup(); }
};

test('залоченный раздел без замочков — ошибка check, lock их устанавливает', () => {
  const p = tempProject(lockedFiles());
  try {
    const before = run(['check'], p.dir);
    assert.equal(before.status, 1);
    assert.match(before.stdout, /залочены, но замочки не установлены/);

    const lock = run(['lock'], p.dir);
    assert.equal(lock.status, 0, lock.stderr);
    assert.ok(fs.existsSync(path.join(p.arch, 'lock.yaml')), 'lock.yaml создан');

    const after = run(['check'], p.dir);
    assert.equal(after.status, 0, after.stdout + after.stderr);
    assert.match(after.stdout, /ошибок: 0, предупреждений: 0/);

    const list = run(['lock', '--list'], p.dir);
    assert.equal(list.status, 0, list.stderr);
    assert.match(list.stdout, /расхождений 0/);
  } finally { p.cleanup(); }
});

test('правка залоченной вкладки ломает check', () => {
  const p = tempProject(lockedFiles());
  try {
    run(['lock'], p.dir);
    fs.appendFileSync(path.join(p.arch, 'tobe', '01-overview.yaml'), '\n# правка\n', 'utf8');
    const check = run(['check'], p.dir);
    assert.equal(check.status, 1);
    assert.match(check.stdout, /залоченная вкладка изменена/);
  } finally { p.cleanup(); }
});

test('add-node в залоченном разделе запрещён', () => {
  const p = tempProject(lockedFiles());
  try {
    run(['lock'], p.dir);
    const res = run(['add-node', 'tobe/01', 'o', 'c', 'C'], p.dir);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /залочен/);
  } finally { p.cleanup(); }
});

test('arch lock без --force не перезаписывает разошедшиеся замочки, с --force — да', () => {
  const p = tempProject(lockedFiles());
  try {
    run(['lock'], p.dir);
    fs.appendFileSync(path.join(p.arch, 'tobe', '01-overview.yaml'), '\n# правка\n', 'utf8');

    const no = run(['lock'], p.dir);
    assert.equal(no.status, 1);
    assert.match(no.stderr, /--force/);

    const yes = run(['lock', '--force'], p.dir);
    assert.equal(yes.status, 0, yes.stderr);
    const check = run(['check'], p.dir);
    assert.equal(check.status, 0, check.stdout + check.stderr);
  } finally { p.cleanup(); }
});

test('раздел role: solution не считается AS-IS в карте перехода', () => {
  const SOLUTION_TAB = {
    title: 'Решение',
    overview: {
      lead: 'прикладной слой',
      blocks: [{ scheme: { size: [400, 200], nodes: [{ id: 'sol-node', at: [120, 100, 120], label: 'S', kind: 'ext' }], edges: [] } }],
    },
    detail: { lead: 'подробно', blocks: [{ note: 'пусто' }] },
  };
  const files = {
    'meta.yaml': {
      ...MINIMAL_META,
      sections: [
        { dir: 'tobe', label: 'TO-BE', role: 'primary' },
        { dir: 'solutions', label: 'Решения', role: 'solution' },
        { dir: 'asis', label: 'AS-IS', hidden: true, role: 'asis' },
      ],
    },
    'components.yaml': {},
    'tobe/01-overview.yaml': MINIMAL_TAB,
    'solutions/01-sol.yaml': SOLUTION_TAB,
    'map.yaml': { entries: [{ asis: 'sol-node', status: 'keep' }] },
  };
  const { model, problems } = compileFiles(files);
  // Узел прикладного слоя не попадает в AS-IS и потому «не найден» в карте.
  assert.ok(!mapCoverage(model).nodes.includes('sol-node'), 'solution-узел не в списке AS-IS');
  assert.ok(problems.errors.some(e => /AS-IS "sol-node" не найден/.test(e)), 'карта отвергает solution-узел как AS-IS');
});
