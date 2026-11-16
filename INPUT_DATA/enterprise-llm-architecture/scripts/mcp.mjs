#!/usr/bin/env node
// MCP-сервер поверх графа модели: агент задаёт вопросы и получает цитаты,
// а не читает YAML целиком. Транспорт — stdio, протокол JSON-RPC 2.0 (MCP).
//
// Запуск:  node scripts/mcp.mjs [--dir <проект>]
// Проверка руками:
//   echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | node scripts/mcp.mjs
//
// Инструменты: arch_status, arch_ask, arch_impact, arch_entity, arch_hypotheses, arch_show, arch_graph, arch_check, arch_inventory.
// Никаких зависимостей: сервер работает там, где работает node.

import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';
import { loadModel, compile } from './lib/model.mjs';
import { buildGraph, searchGraph, impactOf, graphStats, coverage } from './lib/graph.mjs';
import { resolveProject } from './lib/paths.mjs';
import { toAgentModel } from './lib/agent.mjs';
import { projectStatus, statusText } from './lib/status.mjs';
import { compareInventory } from './lib/inventory.mjs';

const argv = process.argv.slice(2);
let dirArg;
for (let i = 0; i < argv.length; i++) if (argv[i] === '--dir') dirArg = argv[++i];
const PROJECT = resolveProject(dirArg);
const ARCH = PROJECT.arch;

let cache = null;
function stamp(dir) {
  let max = 0;
  const walk = d => {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else max = Math.max(max, fs.statSync(p).mtimeMs);
    }
  };
  if (fs.existsSync(dir)) walk(dir);
  return max;
}
function state() {
  const s = stamp(ARCH);
  if (!cache || cache.stamp !== s) {
    const model = loadModel(ARCH);
    cache = { stamp: s, model, graph: buildGraph(model) };
  }
  return cache;
}

const ok = text => ({ content: [{ type: 'text', text }] });
const fail = text => ({ content: [{ type: 'text', text }], isError: true });
const clip = (s, n = 240) => { s = String(s == null ? '' : s).replace(/\s+/g, ' ').trim(); return s.length > n ? s.slice(0, n - 1) + '…' : s; };

const TOOLS = [
  {
    name: 'arch_status',
    description: 'Сводка по проекту с чего начать: размеры модели, покрытие реестром, проверки, открытые гипотезы и решения, список следующих действий. Дешевле, чем arch_graph + arch_check по отдельности.',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'arch_ask',
    description: 'Найти ответ в модели архитектуры: возвращает фрагменты вкладок, решений и гипотез с указанием места. Используйте вместо чтения YAML.',
    inputSchema: { type: 'object', properties: {
      query: { type: 'string', description: 'Вопрос или ключевые слова, например «что делает DLP»' },
      limit: { type: 'number', description: 'Сколько фрагментов вернуть (по умолчанию 6)' },
    }, required: ['query'] },
  },
  {
    name: 'arch_impact',
    description: 'Что затронет изменение или удаление объекта: где он нарисован, входящие и исходящие связи, волны зависимостей, связанные решения и гипотезы.',
    inputSchema: { type: 'object', properties: {
      id: { type: 'string', description: 'id сущности или узла, например dlp, gateway, postgres' },
      depth: { type: 'number', description: 'Глубина зависимостей (по умолчанию 3)' },
    }, required: ['id'] },
  },
  {
    name: 'arch_entity',
    description: 'Карточка объекта: канонический id, тип, владелец, другие имена в схемах, вкладки, связи. Без id — список сущностей реестра.',
    inputSchema: { type: 'object', properties: { id: { type: 'string', description: 'id сущности (необязательно)' } } },
  },
  {
    name: 'arch_hypotheses',
    description: 'Журнал гипотез: вопрос, варианты, чем проверяем, итог. Без id — список.',
    inputSchema: { type: 'object', properties: {
      id: { type: 'string', description: 'например H-001' },
      status: { type: 'string', enum: ['open', 'confirmed', 'rejected', 'parked'] },
    } },
  },
  {
    name: 'arch_inventory',
    description: 'Сверка модели с реальностью: что нарисовано в AS-IS, но не подтверждено инвентарём, и что существует, но не описано в модели.',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'arch_show',
    description: 'Выжимка одной вкладки: узлы, связи, маршруты, таблицы — без координат.',
    inputSchema: { type: 'object', properties: {
      tab: { type: 'string', description: 'id вкладки или его часть, например tobe/04-guardrails или 04-guard' },
    }, required: ['tab'] },
  },
  {
    name: 'arch_graph',
    description: 'Статистика графа: сущности, связи, фрагменты знания, покрытие реестром по вкладкам, объекты без связей.',
    inputSchema: { type: 'object', properties: { coverage: { type: 'boolean' } } },
  },
  {
    name: 'arch_check',
    description: 'Проверки модели: ошибки и предупреждения (структура, ссылки, гипотезы, реестр).',
    inputSchema: { type: 'object', properties: {} },
  },
];

function callTool(name, args = {}) {
  const { model, graph } = state();
  if (name === 'arch_status') {
    const { problems } = compile(loadModel(ARCH));
    return ok(statusText(projectStatus(model, graph, problems)));
  }
  if (name === 'arch_ask') {
    const res = searchGraph(graph, args.query || '', { limit: args.limit || 6 });
    if (!res.hits.length) return ok(`По запросу «${args.query}» ничего не найдено. Попробуйте слова из модели: DLP, Gateway, права, гипотеза. Список вкладок — arch_show без аргументов не поможет: используйте arch_graph.`);
    const lines = [`Запрос: «${res.query}». Найдено ${res.hits.length} фрагментов (в графе ${graph.entities.size} сущностей, ${graph.edges.length} связей).`];
    res.hits.forEach((h, i) => {
      lines.push(`\n[${i + 1}] ${h.where}${h.tab ? ` (${h.tab})` : ''}`);
      lines.push(clip(h.text, 500));
      if (h.entities.length) lines.push('объекты: ' + h.entities.slice(0, 6).map(id => (graph.entities.get(id) || {}).title || id).join(', '));
    });
    const e = res.entities.find(x => x.type !== 'tab');
    lines.push('\nДальше: ' + [e ? `arch_impact ${e.id}` : null, res.hits[0].tab ? `arch_show ${res.hits[0].tab}` : null].filter(Boolean).join('; '));
    return ok(lines.join('\n'));
  }
  if (name === 'arch_impact') {
    const r = impactOf(graph, args.id, { depth: args.depth || 3 });
    if (!r) {
      const near = [...graph.entities.values()].filter(e => e.id.includes(String(args.id).toLowerCase())).slice(0, 6).map(e => e.id);
      return fail(`Объект «${args.id}» не найден.` + (near.length ? ` Похожие: ${near.join(', ')}` : ' Список: arch_entity.'));
    }
    const lines = [`${r.entity.id} — ${r.entity.title} [${r.entity.type}${r.entity.owner ? ', владелец: ' + r.entity.owner : ''}]`,
      `Встречается: ${r.tabs.length} вкладок, ${r.occurrences.length} узлов.`];
    if (r.occurrences.length) lines.push('Где нарисовано: ' + r.occurrences.slice(0, 8).map(o => `${o.tab}/${o.nodeId}`).join(', '));
    const out = r.outgoing.filter(e => e.type !== 'uses');
    if (out.length) lines.push('Исходящие: ' + out.slice(0, 8).map(e => `${e.to}${e.label ? ` «${clip(e.label, 30)}»` : ''}`).join(', '));
    if (r.incoming.length) lines.push('Входящие: ' + r.incoming.filter(e => e.type !== 'uses').slice(0, 8).map(e => e.from).join(', '));
    if (r.dependents.length) lines.push('Затронет при изменении: ' + r.dependents.map(w => `шаг ${w.depth}: ${w.entities.map(e => e.id).join(', ')}`).join('; '));
    const dec = [...graph.entities.values()].filter(e => (e.type === 'decision' || e.type === 'hypothesis')
      && graph.edges.some(x => x.from === e.id && x.to === r.entity.id && x.type === 'about')).map(e => `${e.id} (${e.title})`);
    if (dec.length) lines.push('Решения и гипотезы: ' + dec.join('; '));
    if (r.units.length) lines.push('Где описано: ' + r.units.slice(0, 5).map(u => u.where).join(' | '));
    return ok(lines.join('\n'));
  }
  if (name === 'arch_entity') {
    if (!args.id) {
      const list = [...graph.entities.values()].filter(e => !e.implicit && e.type !== 'tab')
        .sort((a, b) => (b.tabs ? b.tabs.size : 0) - (a.tabs ? a.tabs.size : 0));
      return ok(`Сущностей в реестре: ${list.length}\n` + list.map(e => `${e.id} [${e.type}] ${e.title} — вкладок: ${(e.tabs || new Set()).size}`).join('\n'));
    }
    const e = graph.entities.get(graph.alias.get(args.id) || args.id);
    if (!e) return fail(`Сущность «${args.id}» не найдена. Реестр: arch_entity без id.`);
    const out = graph.edges.filter(x => x.from === e.id), inc = graph.edges.filter(x => x.to === e.id);
    return ok([
      `${e.id} — ${e.title}`,
      `Тип: ${e.type}${e.owner ? ` · владелец: ${e.owner}` : ''}${e.data_class ? ` · класс данных: ${e.data_class}` : ''}`,
      e.aliases.length ? `Другие имена: ${e.aliases.join(', ')}` : null,
      `Вкладки: ${[...(e.tabs || [])].join(', ') || '—'}`,
      `Связи: ${out.length} исходящих, ${inc.length} входящих`,
      out.length ? '  → ' + out.slice(0, 10).map(x => x.to.replace(/^tab:/, '')).join(', ') : null,
      inc.length ? '  ← ' + inc.slice(0, 10).map(x => x.from.replace(/^tab:/, '')).join(', ') : null,
    ].filter(Boolean).join('\n'));
  }
  if (name === 'arch_hypotheses') {
    const list = model.hypotheses || [];
    if (!list.length) return ok('Гипотез нет (arch/hypotheses/ пуст).');
    if (args.id) {
      const h = list.find(x => String(x.id).toLowerCase() === String(args.id).toLowerCase());
      if (!h) return fail(`Гипотеза «${args.id}» не найдена. Есть: ${list.map(x => x.id).join(', ')}`);
      return ok([
        `${h.id} — ${h.title}`, `Статус: ${h.status}${h.date ? ' · ' + h.date : ''}`, `Вопрос: ${h.question}`,
        h.assumptions && h.assumptions.length ? 'Допущения: ' + h.assumptions.join(' | ') : null,
        h.options && h.options.length ? 'Варианты: ' + h.options.map(o => `${o.id}. ${o.title} (+${clip(o.pros, 120)} / −${clip(o.cons, 120)})`).join(' | ') : null,
        h.evidence && h.evidence.length ? 'Проверка: ' + h.evidence.map(e => `${e.kind || '—'}: ${e.note}`).join(' | ') : null,
        h.verdict ? `Итог: ${h.verdict}` : 'Итог не зафиксирован.',
        h.links ? 'Где смотреть: ' + h.links.join(', ') : null,
      ].filter(Boolean).join('\n'));
    }
    const rows = args.status ? list.filter(h => h.status === args.status) : list;
    return ok(rows.map(h => `${h.id} [${h.status}] ${h.title} — ${h.question}`).join('\n'));
  }
  if (name === 'arch_inventory') {
    if (!model.inventory) return ok('Инвентаря нет: arch/inventory.yaml не заполнен.');
    const res = compareInventory(model, graph);
    return ok([
      `Инвентарь: ${res.total} позиций, сопоставлено ${res.linked}; это ${res.entities} сущностей модели.`,
      res.unknown.length ? 'Нет в модели: ' + res.unknown.map(i => `${i.id} (${i.name || '—'})`).join(', ') : 'Все позиции инвентаря есть в модели.',
      res.unconfirmed.length ? 'Нарисовано как «сегодня», но не подтверждено: ' + res.unconfirmed.map(e => e.id).join(', ') : 'Всё нарисованное в AS-IS подтверждено инвентарём.',
    ].join('\n'));
  }
  if (name === 'arch_show') {
    const q = String(args.tab || '');
    if (!q) return fail('Укажите tab: id вкладки или его часть.');
    const tabs = model.tabs.filter(t => t.id === q);
    const hits = tabs.length ? tabs : model.tabs.filter(t => t.id.includes(q) || t.title.toLowerCase().includes(q.toLowerCase()));
    if (hits.length !== 1) return fail(hits.length ? `Подходит несколько вкладок: ${hits.map(t => t.id).join(', ')}` : `Вкладка «${q}» не найдена.`);
    const A = toAgentModel(model).tabs.find(t => t.id === hits[0].id);
    const lines = [`# ${A.id} — ${A.title}`];
    for (const level of ['overview', 'detail']) {
      const v = A[level] || {};
      lines.push(`\n## ${level}`);
      if (v.lead) lines.push(clip(v.lead, 600));
      (v.blocks || []).forEach(b => {
        if (b.type === 'scheme') {
          lines.push(`схема: узлов ${b.nodes.length}, связей ${b.edges.length}`);
          for (const n of b.nodes) lines.push(`  ${n.id} [${n.kind || ''}] ${clip(n.label, 60)}${n.sub ? ' — ' + clip(n.sub, 60) : ''}`);
          for (const e of b.edges) lines.push(`  ${e.from} ${e.dashed ? '-->' : '->'} ${e.to}${e.label ? ' : ' + clip(e.label, 40) : ''}`);
          for (const [name, r] of Object.entries(b.routes || {})) lines.push(`  маршрут «${name}»: ${(Array.isArray(r) ? r : r.path).join(' -> ')}`);
        } else if (b.type === 'table') {
          lines.push(`таблица: ${b.rows.length} строк; ${b.head.join(' | ')}`);
          for (const r of b.rows.slice(0, 20)) lines.push('  ' + r.map(c => clip(c, 70)).join(' | '));
        } else if (b.items) lines.push(`${b.type}: ${b.items.length}`);
        else if (b.text) lines.push(clip(b.text, 400));
      });
    }
    return ok(lines.join('\n'));
  }
  if (name === 'arch_graph') {
    const st = graphStats(graph);
    const lines = [`Граф: ${st.entities} сущностей, ${st.edges} связей, ${st.units} фрагментов знания.`,
      'По связям: ' + Object.entries(st.byEdge).map(([k, v]) => `${k} ${v}`).join(', ') + '.',
      `Своих id (вне реестра): ${st.implicit}. Без связей: ${st.orphans.length}.`];
    if (args.coverage) lines.push('\nПокрытие реестром:', ...coverage(model, graph).sort((a, b) => a.pct - b.pct)
      .map(r => `  ${String(r.pct).padStart(3)}% ${r.tab} — ${r.mapped}/${r.total}`));
    return ok(lines.join('\n'));
  }
  if (name === 'arch_check') {
    const { problems } = compile(loadModel(ARCH));
    const lines = [`ошибок: ${problems.errors.length}, предупреждений: ${problems.warnings.length}`,
      ...problems.errors.map(e => 'ERR  ' + e), ...problems.warnings.map(w => 'WARN ' + w)];
    return { content: [{ type: 'text', text: lines.join('\n') }], isError: problems.errors.length > 0 };
  }
  return fail(`Неизвестный инструмент: ${name}`);
}

function send(msg) { process.stdout.write(JSON.stringify(msg) + '\n'); }

const rl = readline.createInterface({ input: process.stdin });
rl.on('line', line => {
  const text = line.trim();
  if (!text) return;
  let req;
  try { req = JSON.parse(text); } catch (e) { return send({ jsonrpc: '2.0', id: null, error: { code: -32700, message: 'parse error' } }); }
  const { id, method, params } = req;
  const isNote = method && method.startsWith('notifications/');
  if (isNote) return;
  try {
    if (method === 'initialize') {
      return send({ jsonrpc: '2.0', id, result: {
        protocolVersion: '2024-11-05',
        capabilities: { tools: { listChanged: false } },
        serverInfo: { name: 'arch-graph', version: '1.0.0' },
        instructions: 'Модель архитектуры как граф. Начните с arch_status (что есть и что делать), затем arch_ask, arch_impact, arch_entity. Не читайте YAML целиком без нужды.',
      } });
    }
    if (method === 'ping') return send({ jsonrpc: '2.0', id, result: {} });
    if (method === 'tools/list') return send({ jsonrpc: '2.0', id, result: { tools: TOOLS } });
    if (method === 'tools/call') {
      const name = params && params.name;
      const args = (params && params.arguments) || {};
      if (!TOOLS.some(t => t.name === name)) return send({ jsonrpc: '2.0', id, error: { code: -32601, message: `unknown tool: ${name}` } });
      const out = callTool(name, args);
      return send({ jsonrpc: '2.0', id, result: out });
    }
    if (method === 'resources/list') return send({ jsonrpc: '2.0', id, result: { resources: [] } });
    if (method === 'prompts/list') return send({ jsonrpc: '2.0', id, result: { prompts: [] } });
    return send({ jsonrpc: '2.0', id, error: { code: -32601, message: `method not found: ${method}` } });
  } catch (e) {
    return send({ jsonrpc: '2.0', id, error: { code: -32603, message: String(e && e.message || e) } });
  }
});
