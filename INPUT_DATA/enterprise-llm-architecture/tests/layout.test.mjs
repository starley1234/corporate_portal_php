// Раскладка: детерминированность и отсутствие перекрытий.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { layoutScheme, nodeSize } from '../scripts/lib/layout.mjs';

const scheme = () => ({
  size: [1000, 400],
  groups: [[20, 40, 300, 320, 'Вход'], [660, 40, 320, 320, 'Выход']],
  nodes: [
    { id: 'in', at: [170, 90, 160], label: 'Вход', sub: 'подпись' },
    { id: 'out', at: [820, 90, 160], label: 'Выход' },
    ...Array.from({ length: 10 }, (_, i) => ({ id: `n${i}`, label: `Узел ${i}`, kind: 'core' })),
  ],
  edges: ['in -> n0', ...Array.from({ length: 9 }, (_, i) => `n${i} -> n${i + 1}`), 'n9 -> out', 'n2 -> n7'],
});

const overlaps = (S, at) => {
  const box = n => ({ ...nodeSize(n), x: (at[n.id] || n.at)[0], y: (at[n.id] || n.at)[1], id: n.id });
  const boxes = S.nodes.map(box);
  const bad = [];
  for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
    const a = boxes[i], b = boxes[j];
    const ox = (a.w + b.w) / 2 - Math.abs(a.x - b.x);
    const oy = (a.h + b.h) / 2 - Math.abs(a.y - b.y);
    if (ox > 2 && oy > 2) bad.push(`${a.id}~${b.id}`);
  }
  return bad;
};

for (const mode of ['missing', 'zone', 'flow']) {
  test(`раскладка «${mode}»: без перекрытий`, () => {
    const S = scheme();
    const out = layoutScheme(S, { mode });
    assert.deepEqual(overlaps(S, out.at), [], 'перекрытия: ' + overlaps(S, out.at).join(', '));
  });

  test(`раскладка «${mode}»: детерминирована`, () => {
    const a = layoutScheme(scheme(), { mode });
    const b = layoutScheme(scheme(), { mode });
    assert.deepEqual(a.at, b.at);
  });
}

test('missing трогает только узлы без at', () => {
  const S = scheme();
  const out = layoutScheme(S, { mode: 'missing' });
  assert.deepEqual(Object.keys(out.at).sort(), Array.from({ length: 10 }, (_, i) => `n${i}`).sort());
  assert.equal(out.at.in, undefined);
});

test('zone раскладывает по зонам, flow — по слоям', () => {
  const S = scheme();
  const zone = layoutScheme(S, { mode: 'zone' });
  assert.ok(zone.note.includes('зон'));
  const flow = layoutScheme(S, { mode: 'flow' });
  assert.ok(flow.note.includes('слоёв'));
  // в flow узлы выстраиваются слева направо по слоям
  assert.ok(flow.at.in[0] < flow.at.out[0]);
});

test('узлы не выходят за границы size (холст может быть расширен)', () => {
  const S = scheme();
  for (const mode of ['missing', 'zone', 'flow']) {
    const out = layoutScheme(S, { mode });
    const [W, H] = out.size || S.size; // zone/flow умеют расширять холст и возвращают новый size
    for (const [id, [x, y, w]] of Object.entries(out.at)) {
      const h = nodeSize({ id });
      assert.ok(x - w / 2 >= 0 && x + w / 2 <= W, `${mode}: ${id} по ширине (${x}±${w / 2} вне ${W})`);
      assert.ok(y - h.h / 2 >= 0 && y + h.h / 2 <= H, `${mode}: ${id} по высоте (${y}±${h.h / 2} вне ${H})`);
    }
  }
});
