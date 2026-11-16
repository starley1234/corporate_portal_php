// Портфель процессов: расчёт оценки, жёсткие фильтры и генерация вкладок.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import YAML from 'yaml';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = path.join(ROOT, 'scripts', 'portfolio.mjs');

const CONFIG = {
  weights: { value: 0.35, feasibility: 0.3, risk: 0.2, speed: 0.15 },
  settings: { local_llm: true, hours_scale: 1000, quick_win: 60, speed_edge: 0.55 },
  class_fit: { public: 1, internal: 0.75, confidential: 0.45 },
  output: { ranked: 'arch/portfolio/02-ranked.yaml', map: 'arch/portfolio/03-map.yaml' },
};

const card = (over = {}) => ({
  code: 'P-01', name: 'Процесс', owner: 'Владелец', volume: 300, minutes: 20,
  error_cost: 3, strategic: 3, data_ready: 4, rules_maturity: 4, llm_share: 0.2,
  data_class: 'internal', regulatory: 2, integration_effort: 2,
  write_to_source: false, golden_set: true, ...over,
});

function project(cards, config = CONFIG) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'portfolio-test-'));
  fs.mkdirSync(path.join(dir, 'portfolio'), { recursive: true });
  fs.mkdirSync(path.join(dir, 'arch'), { recursive: true });
  fs.writeFileSync(path.join(dir, 'arch', 'meta.yaml'), YAML.stringify({ title: 't', heading: 't', subtitle: 't', kinds: { ext: ['#888888', 'Внешние'] }, sections: [{ dir: 'portfolio', label: 'Портфель', role: 'work' }] }));
  fs.writeFileSync(path.join(dir, 'portfolio', 'config.yaml'), YAML.stringify(config));
  cards.forEach((c, i) => fs.writeFileSync(path.join(dir, 'portfolio', `${String(i + 1).padStart(2, '0')}-${c.code}.yaml`), YAML.stringify(c)));
  return { dir, cleanup: () => fs.rmSync(dir, { recursive: true, force: true }) };
}

const run = (dir, extra = []) => spawnSync(process.execPath, [SCRIPT, '--dir', dir, ...extra], { encoding: 'utf8' });

test('оценка считается, вкладки генерируются и проходят check', () => {
  const p = project([card(), card({ code: 'P-02', name: 'Второй', volume: 100, minutes: 10 })]);
  try {
    const r = run(p.dir, ['--json']);
    assert.equal(r.status, 0, r.stderr);
    const out = JSON.parse(r.stdout);
    assert.equal(out.cards, 2);
    assert.equal(out.ranked.length, 2);
    // больший объём при равных прочих — выше оценка и первое место
    assert.equal(out.ranked[0].code, 'P-01');
    assert.ok(out.ranked[0].score >= 60 && out.ranked[0].score <= 100, 'оценка в шкале 0..100');
    for (const f of ['arch/portfolio/02-ranked.yaml', 'arch/portfolio/03-map.yaml']) {
      const tab = YAML.parse(fs.readFileSync(path.join(p.dir, f), 'utf8'));
      assert.ok(tab.title && tab.overview.blocks.length && tab.detail.blocks.length, `${f}: вкладка полная`);
    }
    const check = spawnSync(process.execPath, [path.join(ROOT, 'scripts', 'arch.mjs'), 'check', '--dir', p.dir, '--strict'], { encoding: 'utf8' });
    assert.equal(check.status, 0, check.stdout);
  } finally { p.cleanup(); }
});

test('жёсткие фильтры не понижают оценку, а ставят процесс в паузу', () => {
  const p = project([
    card({ code: 'P-01' }),
    card({ code: 'P-02', name: 'Без владельца', owner: '  ' }),
    card({ code: 'P-03', name: 'Без данных', data_ready: 1 }),
    card({ code: 'P-04', name: 'Скрининг', data_class: 'confidential', regulatory: 5 }),
  ], { ...CONFIG, settings: { ...CONFIG.settings, local_llm: false } });
  try {
    const out = JSON.parse(run(p.dir, ['--json']).stdout);
    assert.deepEqual(out.ranked.map(e => e.code), ['P-01']);
    assert.deepEqual(out.paused.map(e => e.code).sort(), ['P-02', 'P-03', 'P-04']);
    const reasons = Object.fromEntries(out.paused.map(e => [e.code, e.blockers.join('; ')]));
    assert.match(reasons['P-02'], /владельц/);
    assert.match(reasons['P-03'], /данн/i);
    assert.match(reasons['P-04'], /локальн/);
    // оценка у процесса без владельца всё равно посчитана — блокирует фильтр, а не балл
    assert.ok(out.paused.find(e => e.code === 'P-02').score > 0);
  } finally { p.cleanup(); }
});

test('условия (эталон, запись в источник) не блокируют пилот, но видны в решении', () => {
  const p = project([
    card({ code: 'P-01', golden_set: false }),
    card({ code: 'P-02', name: 'Заявки', write_to_source: true }),
  ]);
  try {
    const out = JSON.parse(run(p.dir, ['--json']).stdout);
    assert.equal(out.paused.length, 0);
    const byCode = Object.fromEntries(out.ranked.map(e => [e.code, e.conditions.join('; ')]));
    assert.match(byCode['P-01'], /эталон/i);
    assert.match(byCode['P-02'], /подтверждени/i);
  } finally { p.cleanup(); }
});

test('карточки с ошибками валят сборку, а не собираются молча', () => {
  const bad = [
    [{ ...card(), data_class: 'secret' }, /data_class/],
    [{ ...card(), error_cost: 9 }, /error_cost/],
    [card({ name: '' }), /name/],
  ];
  for (const [c, re] of bad) {
    const p = project([c]);
    try {
      const r = run(p.dir, ['--json']);
      assert.equal(r.status, 1, 'ожидался отказ');
      assert.match(r.stderr, re);
    } finally { p.cleanup(); }
  }
  const dup = project([card(), card()]);
  try {
    const r = run(dup.dir, ['--json']);
    assert.equal(r.status, 1);
    assert.match(r.stderr, /повторяется/);
  } finally { dup.cleanup(); }
});

test('--check проверяет карточки, но не перезаписывает вкладки', () => {
  const p = project([card()]);
  try {
    const r = run(p.dir, ['--check']);
    assert.equal(r.status, 0, r.stderr);
    assert.equal(fs.existsSync(path.join(p.dir, 'arch/portfolio/02-ranked.yaml')), false);
  } finally { p.cleanup(); }
});

// ---------- квартальный цикл ----------
const QCONFIG = {
  ...CONFIG,
  quarter: { name: '2026-Q1', start: '2026-01-01', end: '2026-03-31' },
  capacity: { fte: 2, hours_per_fte: 400, reserve: 0.25, max_projects: 2 },
  effort: { base: 100, per_integration: 50, per_missing_data: 0, confidential_extra: 0 },
  output: { ranked: 'arch/portfolio/02-ranked.yaml', map: 'arch/portfolio/03-map.yaml', plan: 'arch/portfolio/04-quarter.yaml' },
};
const review = (decisions, extra = {}) => ({ 'reviews/2026-Q1.yaml': { quarter: '2026-Q1', decisions, ...extra } });
function projectWithReview(cards, decisions, extra) {
  const p = project(cards, QCONFIG);
  for (const [rel, value] of Object.entries(review(decisions, extra))) {
    fs.mkdirSync(path.dirname(path.join(p.dir, rel)), { recursive: true });
    fs.writeFileSync(path.join(p.dir, rel), YAML.stringify(value));
  }
  return p;
}

test('план квартала укладывается в бюджет и лимит пилотов', () => {
  const cards = [
    card({ code: 'P-01', integration_effort: 1 }),   // 150 ч
    card({ code: 'P-02', integration_effort: 3 }),   // 250 ч
    card({ code: 'P-03', name: 'Третий', integration_effort: 1 }),
    card({ code: 'P-04', name: 'Четвёртый', integration_effort: 5 }),
  ];
  const p = project(cards, QCONFIG);
  try {
    const out = JSON.parse(run(p.dir, ['--json']).stdout);
    const q = out.quarter;
    assert.equal(q.budget, Math.round(2 * 400 * 0.75));
    assert.ok(q.plan.length <= QCONFIG.capacity.max_projects, 'лимит одновременных пилотов');
    assert.ok(q.used <= q.budget, `план дороже бюджета: ${q.used} > ${q.budget}`);
    assert.equal(q.over_budget, 0);
    for (const e of q.plan) assert.equal(e.measured, false, 'замера нет — план предварительный');
    assert.ok(q.queue.length > 0, 'остальные ждут в очереди');
  } finally { p.cleanup(); }
});

test('решения совета главнее расчёта, а перебор бюджета виден', () => {
  const cards = [
    card({ code: 'P-01', integration_effort: 1 }),   // 150 ч
    card({ code: 'P-02', integration_effort: 1 }),   // 150 ч
    card({ code: 'P-03', integration_effort: 5 }),   // 350 ч
  ];
  const p = projectWithReview(cards, [
    { code: 'P-03', decision: 'take', reason: 'Нужно руководству к отчётной дате' },
    { code: 'P-01', decision: 'next', reason: 'Ждём выгрузку из источника' },
  ]);
  try {
    const q = JSON.parse(run(p.dir, ['--json']).stdout).quarter;
    const codes = q.plan.map(e => e.code);
    assert.ok(codes.includes('P-03'), 'взяли задачу по решению совета');
    assert.ok(!codes.includes('P-01'), 'убрали задачу по решению совета');
    assert.equal(q.over_budget, 0, 'после переноса P-01 бюджет сходится');
    assert.equal(q.review.decisions, 2);
    assert.deepEqual(q.review.deviations.sort(), ['P-01', 'P-03']);
  } finally { p.cleanup(); }

  // совет берёт всё в план — бюджет перебирается, и это не скрывается
  const over = projectWithReview(cards, [
    { code: 'P-01', decision: 'take', reason: 'x' },
    { code: 'P-02', decision: 'take', reason: 'x' },
    { code: 'P-03', decision: 'take', reason: 'x' },
  ]);
  try {
    const q = JSON.parse(run(over.dir, ['--json']).stdout).quarter;
    assert.equal(q.plan.length, 3, 'лимит пилотов снят решением совета');
    assert.equal(q.used, 650);
    assert.equal(q.over_budget, 50, 'перебор виден числом');
  } finally { over.cleanup(); }
});

test('stop снимает задачу и требует причины', () => {
  const p = projectWithReview([card({ code: 'P-01' })], [
    { code: 'P-01', decision: 'stop', reason: 'Персональные данные, нет согласованного порядка' },
  ]);
  try {
    const out = JSON.parse(run(p.dir, ['--json']).stdout);
    assert.equal(out.quarter.plan.length, 0);
    assert.deepEqual(out.quarter.stopped.map(s => s.code), ['P-01']);
    assert.match(out.quarter.stopped[0].reason, /Персональные/);
  } finally {
    const noReason = projectWithReview([card({ code: 'P-01' })], [{ code: 'P-01', decision: 'stop' }]);
    try {
      const r = run(noReason.dir, ['--json']);
      assert.equal(r.status, 0, 'без причины — предупреждение, а не отказ');
      assert.match(r.stderr, /без причины/);
    } finally { noReason.cleanup(); }
  }
});

test('ошибки в решениях совета останавливают сборку', () => {
  for (const [decision, re] of [
    [{ code: 'P-99', decision: 'take', reason: 'x' }, /такой карточки нет/],
    [{ code: 'P-01', decision: 'может быть', reason: 'x' }, /decision/],
  ]) {
    const p = projectWithReview([card({ code: 'P-01' })], [decision]);
    try {
      const r = run(p.dir, ['--json']);
      assert.equal(r.status, 1);
      assert.match(r.stderr, re);
    } finally { p.cleanup(); }
  }
});

test('вкладка «План квартала» генерируется, проходит check и не пишется с --check', () => {
  const p = project([card({ code: 'P-01' }), card({ code: 'P-02', name: 'Второй' })], QCONFIG);
  try {
    assert.equal(run(p.dir, ['--check']).status, 0);
    assert.equal(fs.existsSync(path.join(p.dir, 'arch/portfolio/04-quarter.yaml')), false, '--check не пишет');

    const r = run(p.dir, []);
    assert.equal(r.status, 0, r.stderr);
    assert.match(r.stdout, /План квартала · 2026-Q1/);
    const tab = YAML.parse(fs.readFileSync(path.join(p.dir, 'arch/portfolio/04-quarter.yaml'), 'utf8'));
    assert.match(tab.title, /2026-Q1/);
    assert.ok(tab.overview.blocks.length >= 2 && tab.detail.blocks.length >= 1);
    const check = spawnSync(process.execPath, [path.join(ROOT, 'scripts', 'arch.mjs'), 'check', '--dir', p.dir, '--strict'], { encoding: 'utf8' });
    assert.equal(check.status, 0, check.stdout);
  } finally { p.cleanup(); }
});
