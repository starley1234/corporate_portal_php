// Общие помощники тестов: временный проект arch/ из объекта.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import YAML from 'yaml';

export function tempProject(files = {}) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'arch-test-'));
  fs.mkdirSync(path.join(dir, 'arch', 'tobe'), { recursive: true });
  for (const [rel, value] of Object.entries(files)) {
    const p = path.join(dir, 'arch', rel);
    fs.mkdirSync(path.dirname(p), { recursive: true });
    fs.writeFileSync(p, typeof value === 'string' ? value : YAML.stringify(value), 'utf8');
  }
  return { dir, arch: path.join(dir, 'arch'), cleanup: () => fs.rmSync(dir, { recursive: true, force: true }) };
}

export const MINIMAL_META = {
  title: 'test · архитектура',
  heading: 'Тест',
  subtitle: 'минимальный проект',
  kinds: { ext: ['#8a93a3', 'Внешние'], core: ['#f59e0b', 'Ядро'] },
};

export const MINIMAL_TAB = {
  title: 'Общий вид',
  overview: {
    lead: 'схема из двух узлов',
    blocks: [{
      scheme: {
        size: [800, 300],
        nodes: [
          { id: 'a', at: [200, 100, 160], label: 'A', kind: 'ext' },
          { id: 'b', at: [600, 100, 160], label: 'B', kind: 'core' },
        ],
        edges: ['a -> b'],
        routes: { основной: 'a -> b' },
      },
    }],
  },
  detail: { lead: 'подробно', blocks: [{ note: 'пусто' }] },
};

export const minimalFiles = extra => ({
  'meta.yaml': MINIMAL_META,
  'components.yaml': { a: '**A** — описание' },
  'tobe/01-overview.yaml': MINIMAL_TAB,
  ...extra,
});
