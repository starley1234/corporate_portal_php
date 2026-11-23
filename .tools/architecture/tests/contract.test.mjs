// Контракт слоёв: uses (что решение занимает у платформы) и via (путь к моделям).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { tempProject, MINIMAL_META } from './helpers.mjs';
import { loadModel, compile, contractIndex } from '../scripts/lib/model.mjs';

const ARCH = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'scripts', 'arch.mjs');
const run = (args, dir) => spawnSync(process.execPath, [ARCH, ...args, '--dir', dir], { encoding: 'utf8' });

const META = {
  ...MINIMAL_META,
  kinds: { ext: ['#8a93a3', 'Внешние'], core: ['#f59e0b', 'Ядро'], local: ['#22c55e', 'Локальная модель'] },
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary' },
    { dir: 'solutions', label: 'Решения', role: 'solution' },
  ],
};

// Платформа: клиент -> gw -> модель (kind: local — «локальная модель»).
const PLATFORM = {
  title: 'Платформа',
  overview: {
    lead: 'платформа',
    blocks: [{
      scheme: {
        size: [800, 300],
        nodes: [
          { id: 'web', at: [120, 120, 140], label: 'Клиент', kind: 'ext' },
          { id: 'gw', at: [300, 120, 140], label: 'Gateway', kind: 'core' },
          { id: 'qwen', at: [560, 120, 140], label: 'Qwen3', kind: 'local' },
        ],
        edges: ['web -> gw', 'gw -> qwen'],
        routes: { основной: 'web -> gw -> qwen' },
      },
    }],
  },
  detail: { lead: 'подробно', blocks: [{ note: 'платформа' }] },
};

// Прикладная вкладка: свой контур, вызывает модель через s-gw; via задан на s-gw.
const solution = (via, extraEdges = []) => ({
  title: 'Решение',
  ...(via === null ? {} : { via }),
  uses: ['gw', 'qwen'],
  overview: {
    lead: 'решение',
    blocks: [{
      scheme: {
        size: [800, 300],
        nodes: [
          { id: 's-user', at: [120, 120, 130], label: 'Пользователь', kind: 'ext' },
          { id: 's-gw', at: [280, 120, 130], label: 'Шлюз решения', kind: 'core' },
          { id: 's-llm', at: [520, 120, 130], label: 'Qwen3', kind: 'local' },
        ],
        edges: ['s-user -> s-gw', 's-gw -> s-llm', ...extraEdges],
        routes: { основной: 's-user -> s-gw -> s-llm' },
      },
    }],
  },
  detail: { lead: 'подробно', blocks: [{ note: 'решение' }] },
});

const build = files => {
  const p = tempProject({ 'meta.yaml': META, 'tobe/01-platform.yaml': PLATFORM, ...files });
  try {
    const model = loadModel(p.arch);
    return { model, ix: contractIndex(model), ...compile(model), cleanup: p.cleanup };
  } catch (e) { p.cleanup(); throw e; }
};

test('uses: валидный контракт и обратный индекс', t => {
  const r = build({ 'solutions/01-app.yaml': solution(['s-gw']) });
  t.after(r.cleanup);
  assert.deepEqual(r.problems.errors, []);
  assert.deepEqual(r.problems.warnings, []);
  assert.deepEqual(r.ix.declared, [{ tab: 'solutions/01-app', uses: ['gw', 'qwen'], role: 'solution' }]);
  assert.deepEqual([...r.ix.used.entries()], [['gw', ['solutions/01-app']], ['qwen', ['solutions/01-app']]]);
  // не востребовано решениями — только клиентский узел канонической схемы
  assert.deepEqual(r.ix.unused, ['web']);
  assert.equal(r.ix.reference, 'tobe/01-platform');
});

test('uses: ссылка на несуществующий узел платформы — ошибка', t => {
  const r = build({ 'solutions/01-app.yaml': { ...solution(['s-gw']), uses: ['gw', 'нет-такого'] } });
  t.after(r.cleanup);
  assert.match(r.problems.errors.join(), /uses: узел платформы "нет-такого" не найден/);
});

test('uses: отсутствие ключа у прикладной вкладки — предупреждение', t => {
  const r = build({ 'solutions/01-app.yaml': { title: 'Решение', overview: PLATFORM.overview, detail: PLATFORM.detail } });
  t.after(r.cleanup);
  assert.match(r.problems.warnings.join(), /не объявляет uses/);
  assert.deepEqual(r.ix.undeclared, ['solutions/01-app']);
});

test('uses: [] — «ничем не пользуюсь», это не предупреждение', t => {
  const r = build({ 'solutions/01-app.yaml': { ...solution(null), uses: [] } });
  t.after(r.cleanup);
  assert.deepEqual(r.problems.warnings, []);
  assert.deepEqual(r.ix.declared, [{ tab: 'solutions/01-app', uses: [], role: 'solution' }]);
});

test('via: прямой путь к модели в обход контрольной точки — ошибка', t => {
  const r = build({ 'solutions/01-app.yaml': solution(['s-gw'], ['s-user -> s-llm']) });
  t.after(r.cleanup);
  assert.match(r.problems.errors.join(), /есть путь в обход \[s-gw\].*через "s-user"/);
});

test('via: контрольная точка вне пути к моделям — предупреждение и ошибка обхода', t => {
  // s-side — узел, не лежащий ни на одном пути к s-llm: проверка честно говорит, что не сработала,
  // и отдельно сообщает о пути в обход.
  const tab = solution(['s-side']);
  tab.overview.blocks[0].scheme.nodes.push({ id: 's-side', at: [120, 260, 130], label: 'Сбоку', kind: 'core' });
  const r = build({ 'solutions/01-app.yaml': tab });
  t.after(r.cleanup);
  assert.match(r.problems.warnings.join(), /не лежит ни на одном пути/);
  assert.match(r.problems.errors.join(), /есть путь в обход \[s-side\]/);
});

test('via: неизвестный узел схемы — ошибка', t => {
  const r = build({ 'solutions/01-app.yaml': solution(['s-none']) });
  t.after(r.cleanup);
  assert.match(r.problems.errors.join(), /узел "s-none" не объявлен в схеме/);
});

test('via: без ключа проверка не навязывается', t => {
  const r = build({ 'solutions/01-app.yaml': solution(null) });
  t.after(r.cleanup);
  assert.deepEqual(r.problems.errors, []);
  assert.deepEqual(r.problems.warnings, []);
});

test('CLI contract: кто что занимает и обратный индекс', t => {
  const p = tempProject({
    'meta.yaml': META,
    'tobe/01-platform.yaml': PLATFORM,
    'solutions/01-app.yaml': solution(['s-gw']),
  });
  t.after(p.cleanup);
  const out = run(['contract'], p.arch);
  assert.equal(out.status, 0, out.stderr);
  assert.match(out.stdout, /solutions\/01-app\s+gw, qwen/);
  assert.match(out.stdout, /обратный индекс/);
  assert.match(out.stdout, /не используется ни одним решением \(1\)/);

  const js = JSON.parse(run(['contract', '--json'], p.arch).stdout);
  assert.equal(js.platform, 'tobe');
  assert.deepEqual(js.reverse, { gw: ['solutions/01-app'], qwen: ['solutions/01-app'] });
  assert.deepEqual(js.unused, ['web']);
});
