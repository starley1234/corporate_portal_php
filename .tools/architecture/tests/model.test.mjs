// Проверки модели: что компилятор считает ошибкой, а что предупреждением.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { loadModel, compile, mapCoverage } from '../scripts/lib/model.mjs';
import { tempProject, minimalFiles, MINIMAL_TAB } from './helpers.mjs';

const run = files => {
  const p = tempProject(files);
  try { const model = loadModel(p.arch); return { model, ...compile(model) }; }
  finally { p.cleanup(); }
};
const texts = ps => [...ps.errors, ...ps.warnings];

test('минимальный проект проходит без ошибок и предупреждений', () => {
  const { legacy, problems } = run(minimalFiles());
  assert.equal(problems.errors.length, 0);
  assert.equal(problems.warnings.length, 0);
  assert.equal(legacy.TABS.length, 1);
  assert.equal(legacy.SECTIONS.length, 1);
  assert.match(legacy.TABS[0].t, /^TO-BE · 1\. Общий вид$/);
});

test('неизвестный kind — ошибка', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.nodes[0].kind = 'нетТакого';
  const { problems } = run(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  assert.ok(problems.errors.some(e => /неизвестный kind/.test(e)), texts(problems).join('\n'));
});

test('связь на необъявленный узел — ошибка', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.edges.push('b -> ghost');
  const { problems } = run(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  assert.ok(problems.errors.some(e => /не объявлен в nodes/.test(e)));
});

test('разрыв маршрута — предупреждение, а не ошибка', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.nodes.push({ id: 'c', at: [400, 240, 160], label: 'C', kind: 'ext' });
  tab.overview.blocks[0].scheme.routes = { дырка: 'a -> b -> c' };
  const { problems } = run(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  assert.equal(problems.errors.length, 0);
  assert.ok(problems.warnings.some(w => /разрыв маршрута/.test(w)), texts(problems).join('\n'));
});

test('дубль id узла — ошибка', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.nodes.push({ id: 'a', at: [400, 240, 160], label: 'A2', kind: 'ext' });
  const { problems } = run(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  assert.ok(problems.errors.some(e => /уже есть в этой схеме/.test(e)));
});

test('число ячеек в таблице проверяется', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.detail.blocks = [{ table: { head: ['a', 'b'], rows: [['1', '2'], ['только одна']] } }];
  const { problems } = run(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  assert.ok(problems.errors.some(e => /в head 2/.test(e)));
});

test('info со ссылкой на несуществующий компонент — ошибка', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.nodes[0].info = '{такогоНет}';
  const { problems } = run(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  assert.ok(problems.errors.some(e => /неизвестный компонент/.test(e)));
});

test('перекрытие узлов — предупреждение', () => {
  const tab = structuredClone(MINIMAL_TAB);
  tab.overview.blocks[0].scheme.nodes[1].at = [210, 100, 160];
  const { problems } = run(minimalFiles({ 'tobe/01-overview.yaml': tab }));
  assert.ok(problems.warnings.some(w => /перекрываются/.test(w)), texts(problems).join('\n'));
});

test('разделы задаются в meta.sections, скрытый получает баннер и переключатель', () => {
  const meta = {
    title: 't', heading: 'h', subtitle: 's', kinds: { ext: ['#000', 'Внешние'], core: ['#111', 'Ядро'] },
    sections: [{ dir: 'tobe', label: 'ЦЕЛЬ' }, { dir: 'asis', label: 'БЫЛО', hidden: true, banner: '**было**' }],
  };
  const { legacy } = run({ 'meta.yaml': meta, 'components.yaml': {}, 'tobe/01-a.yaml': MINIMAL_TAB, 'asis/01-b.yaml': MINIMAL_TAB });
  assert.deepEqual(legacy.SECTIONS.map(s => [s.label, s.hidden, s.count]), [['ЦЕЛЬ', false, 1], ['БЫЛО', true, 1]]);
  assert.equal(legacy.TOBE_COUNT, 1);
  assert.match(legacy.TABS[0].t, /^ЦЕЛЬ · 1\./);
  assert.match(legacy.TABS[1].t, /^БЫЛО · 1\./);
  assert.match(legacy.TABS[1].o.lead, /^<b>было<\/b>/);
});

test('карта перехода: неизвестный узел TO-BE — ошибка, покрытие считается', () => {
  const files = minimalFiles({
    'map.yaml': { title: 'Карта', entries: [{ asis: 'a', tobe: 'нетТакого', status: 'move' }] },
  });
  const { model, problems } = run(files);
  assert.ok(problems.errors.some(e => /не найден ни в одной схеме раздела tobe/.test(e)), texts(problems).join('\n'));
  const cov = mapCoverage(model);
  assert.deepEqual(cov.missing, []);
  assert.deepEqual(cov.mapped, ['a']);
});

test('карта перехода добавляет сгенерированную вкладку', () => {
  const { legacy } = run(minimalFiles({ 'map.yaml': { entries: [{ asis: 'a', tobe: 'b', status: 'move' }, { tobe: 'b', status: 'new' }] } }));
  assert.equal(legacy.TABS.length, 2);
  assert.match(legacy.TABS[1].t, /Карта перехода/);
});
