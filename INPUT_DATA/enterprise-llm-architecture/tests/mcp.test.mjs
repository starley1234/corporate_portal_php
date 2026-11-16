// MCP-сервер: JSON-RPC поверх stdio, список инструментов и вызовы.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { tempProject, MINIMAL_META, MINIMAL_TAB } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const MCP = path.join(ROOT, 'scripts', 'mcp.mjs');

const META = {
  ...MINIMAL_META,
  sections: [
    { dir: 'tobe', label: 'TO-BE', role: 'primary' },
    { dir: 'governance', label: 'Управление', role: 'governance' },
  ],
};
const FILES = {
  'meta.yaml': META,
  'tobe/01-overview.yaml': MINIMAL_TAB,
  'entities.yaml': { entities: [{ id: 'gateway', type: 'control', title: 'Шлюз', aliases: ['a'] }] },
};

const talk = (dir, messages) => {
  const input = messages.map(m => JSON.stringify(m)).join('\n') + '\n';
  const res = spawnSync(process.execPath, [MCP, '--dir', dir], { input, encoding: 'utf8' });
  assert.equal(res.status, 0, res.stderr);
  return res.stdout.split('\n').filter(Boolean).map(l => JSON.parse(l));
};

test('initialize отвечает версией протокола и именем сервера', () => {
  const p = tempProject(FILES);
  try {
    const [init] = talk(p.arch, [
      { jsonrpc: '2.0', id: 1, method: 'initialize', params: {} },
      { jsonrpc: '2.0', method: 'notifications/initialized' },
    ]);
    assert.equal(init.result.protocolVersion, '2024-11-05');
    assert.equal(init.result.serverInfo.name, 'arch-graph');
    assert.ok(init.result.capabilities.tools);
  } finally { p.cleanup(); }
});

test('tools/list отдаёт инструменты графа', () => {
  const p = tempProject(FILES);
  try {
    const out = talk(p.arch, [{ jsonrpc: '2.0', id: 1, method: 'tools/list' }]);
    const names = out[0].result.tools.map(t => t.name);
    for (const n of ['arch_status', 'arch_ask', 'arch_impact', 'arch_entity', 'arch_hypotheses', 'arch_show', 'arch_graph', 'arch_check', 'arch_inventory'])
      assert.ok(names.includes(n), `нет инструмента ${n}`);
    const ask = out[0].result.tools.find(t => t.name === 'arch_ask');
    assert.equal(ask.inputSchema.required[0], 'query');
  } finally { p.cleanup(); }
});

test('arch_status отвечает сводкой со следующими шагами', () => {
  const p = tempProject(FILES);
  try {
    const out = talk(p.arch, [{ jsonrpc: '2.0', id: 8, method: 'tools/call', params: { name: 'arch_status', arguments: {} } }]);
    const text = out[0].result.content[0].text;
    assert.match(text, /граф:/);
    assert.match(text, /что дальше:/);
    assert.equal(out[0].result.isError, undefined);
  } finally { p.cleanup(); }
});

test('arch_ask возвращает фрагменты с указанием вкладки', () => {
  const p = tempProject(FILES);
  try {
    const out = talk(p.arch, [{ jsonrpc: '2.0', id: 7, method: 'tools/call', params: { name: 'arch_ask', arguments: { query: 'схема из двух узлов' } } }]);
    const text = out[0].result.content[0].text;
    assert.match(text, /Запрос/);
    assert.match(text, /tobe\/01-overview/);
    assert.equal(out[0].result.isError, undefined);
  } finally { p.cleanup(); }
});

test('arch_impact по неизвестному объекту — isError и подсказка', () => {
  const p = tempProject(FILES);
  try {
    const out = talk(p.arch, [{ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'arch_impact', arguments: { id: 'нет-такого' } } }]);
    assert.equal(out[0].result.isError, true);
    assert.match(out[0].result.content[0].text, /не найден/);
  } finally { p.cleanup(); }
});

test('неизвестный инструмент и неизвестный метод — ошибки JSON-RPC', () => {
  const p = tempProject(FILES);
  try {
    const out = talk(p.arch, [
      { jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'nope', arguments: {} } },
      { jsonrpc: '2.0', id: 4, method: 'нет/такого' },
    ]);
    assert.equal(out[0].error.code, -32601);
    assert.equal(out[1].error.code, -32601);
  } finally { p.cleanup(); }
});

test('arch_graph и arch_entity дают статистику и карточку объекта', () => {
  const p = tempProject(FILES);
  try {
    const out = talk(p.arch, [
      { jsonrpc: '2.0', id: 5, method: 'tools/call', params: { name: 'arch_graph', arguments: { coverage: true } } },
      { jsonrpc: '2.0', id: 6, method: 'tools/call', params: { name: 'arch_entity', arguments: { id: 'gateway' } } },
    ]);
    assert.match(out[0].result.content[0].text, /Граф:/);
    assert.match(out[0].result.content[0].text, /Покрытие/);
    assert.match(out[1].result.content[0].text, /Другие имена: a/);
  } finally { p.cleanup(); }
});
