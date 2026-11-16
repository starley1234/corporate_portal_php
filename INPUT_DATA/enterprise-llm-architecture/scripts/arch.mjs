#!/usr/bin/env node
// CLI для работы с архитектурой экономно по токенам. Источник — arch/*.yaml.
//   node scripts/arch.mjs tabs [--json]                         список вкладок
//   node scripts/arch.mjs show <вкладка> [o|d] [--full] [--json] выжимка вкладки (узлы, связи, маршруты, таблицы)
//   node scripts/arch.mjs node <id|текст> [--full]              где встречается узел, его связи и описание
//   node scripts/arch.mjs grep <текст>                          поиск по всем текстам
//   node scripts/arch.mjs check [--strict]                      проверки модели (ошибки -> код 1)
//   node scripts/arch.mjs add-node <вкладка> <o|d> <id> <label> [--sub s] [--kind k] [--at x,y,w] [--block N]
//   node scripts/arch.mjs add-edge <вкладка> <o|d> "a -> b" [--label s] [--kind k] [--block N]   ("a --> b" = пунктир)
//   node scripts/arch.mjs layout <вкладка> [o|d] [--block N] [--mode auto|missing|zone|flow] [--all] [--dry] [--gap 24]
//                                                               черновая раскладка: проставить at: [x, y, w]
//   node scripts/arch.mjs map [--missing] [--json]              карта перехода AS-IS -> TO-BE и её покрытие
//   node scripts/arch.mjs adr [<id>] [--status proposed] [--json] журнал решений (arch/decisions/)
//   node scripts/arch.mjs status [--json]                        сводка: что в модели, в каком она состоянии и что делать дальше
//   node scripts/arch.mjs graph [--coverage] [--json] [--cypher [--out файл] [--notes]]  граф модели: сущности, связи, покрытие ref/alias
//   node scripts/arch.mjs ask "<вопрос>" [--limit N] [--json]    поиск по модели как по графу знаний (с цитатами)
//   node scripts/arch.mjs impact <id> [--depth N] [--json]       что затронет изменение узла/сущности
//   node scripts/arch.mjs entity [<id>] [--json]                 реестр сущностей или карточка одной
//   node scripts/arch.mjs path <id> <id>                         кратчайшая цепочка связей между объектами
//   node scripts/arch.mjs hypo [<id>] [--status open] [--json]    журнал гипотез (arch/hypotheses/)
//   node scripts/arch.mjs inventory [--missing] [--json]          сверка модели с реальностью (arch/inventory.yaml)
//   node scripts/arch.mjs invariants [--json]                     правила-инварианты (arch/invariants/) и их нарушения
//   node scripts/arch.mjs controls [--missing] [--area ИБ] [--json]  требования и чем они обеспечены (arch/controls/)
//   node scripts/arch.mjs export <вкладка|раздел> [--out файл]    отдельный HTML: одна вкладка или раздел целиком, для смежников
//   node scripts/arch.mjs contract [--json]                      контракт слоёв: uses прикладного слоя и обратный индекс
//   node scripts/arch.mjs lock [--list] [--force]               замочки залоченных разделов (locked: true)
//   node scripts/arch.mjs snapshot [имя]                        снимок: сборка в snapshots/ДДДД-ММ-ДД[-имя]/
//   node scripts/arch.mjs diff <снимок|каталог|json>            что изменилось по сравнению со снимком
//   node scripts/arch.mjs init <каталог> [--profile имя|файл] [--force]  новый проект: каркас arch/ или готовый профиль домена
//   node scripts/arch.mjs profiles                               доступные профили домена (profiles/*.yaml)
//   node scripts/arch.mjs paths                                 где инструмент нашёл arch/, dist/ и шаблон страницы
// Глобальные флаги: --dir <каталог проекта|arch/> (или ARCH_DIR), --json — машинный вывод.
// <вкладка> — id (tobe/02-lifecycle) или его часть (02-life, lifecycle); если подходит несколько — покажет список.

import fs from 'node:fs';
import path from 'node:path';
import YAML from 'yaml';
import { fileURLToPath } from 'node:url';
import { loadModel, compile, mapCoverage, contractIndex, STATUS_LABEL, STATUSES, DECISION_LABEL, DECISION_STATUS,
  HYPOTHESIS_LABEL, HYPOTHESIS_STATUS, ENTITY_TYPES, CONTROL_AREAS } from './lib/model.mjs';
import { buildGraph, searchGraph, impactOf, graphStats, coverage, toCypher, toDot, cypherCounts } from './lib/graph.mjs';
import { projectStatus, statusText } from './lib/status.mjs';
import { compareInventory, inventoryText } from './lib/inventory.mjs';
import { lockedFiles, readLock, writeLock, hashFile, LOCK_FILE } from './lib/lock.mjs';
import { toAgentModel } from './lib/agent.mjs';
import { deepDiff } from './lib/diff.mjs';
import { layoutScheme } from './lib/layout.mjs';
import { resolveProject, TOOL_ROOT, relTo } from './lib/paths.mjs';
import { buildAll, renderHtml } from './build.mjs';

// глобальные флаги вынимаются до разбора команды: arch --dir ../other tabs
const argv = process.argv.slice(2);
const globals = {}, plain = [];
for (let i = 0; i < argv.length; i++) {
  const a = argv[i];
  if (a === '--dir' || a === '--project') globals.dir = argv[++i];
  else if (a.startsWith('--dir=')) globals.dir = a.slice(6);
  else plain.push(a);
}
const [cmd, ...rest] = plain;

const flags = {}, pos = [];
for (let i = 0; i < rest.length; i++) {
  if (rest[i].startsWith('--')) { const k = rest[i].slice(2); flags[k] = rest[i + 1] && !rest[i + 1].startsWith('--') ? rest[++i] : true; }
  else pos.push(rest[i]);
}
const die = m => { console.error(m); process.exit(1); };
const short = (s, n = 150) => { s = String(s ?? '').replace(/\s+/g, ' ').trim(); return flags.full || s.length <= n ? s : s.slice(0, n - 1) + '…'; };
const LEVELS = { o: 'overview', d: 'detail', overview: 'overview', detail: 'detail' };
// «3 вкладки», а не «3 вкладок»: русское склонение в выводе команд
const plural = (n, [one, few, many]) => {
  const a = Math.abs(n) % 100, b = a % 10;
  return `${n} ${a > 10 && a < 20 ? many : b === 1 ? one : b >= 2 && b <= 4 ? few : many}`;
};

const PROJECT = resolveProject(globals.dir);
const ROOT = PROJECT.dir, ARCH = PROJECT.arch, DIST = PROJECT.dist;
if (!fs.existsSync(ARCH)) die(`нет каталога arch/: ${ARCH} (задайте --dir или создайте проект: arch init <каталог>)`);

function load() { try { return loadModel(ARCH); } catch (e) { die(e.message); } }

function findTab(model, q) {
  if (!q) die('укажите вкладку (arch tabs — список)');
  const exact = model.tabs.filter(t => t.id === q);
  if (exact.length === 1) return exact[0];
  const hits = model.tabs.filter(t => t.id.includes(q) || t.title.toLowerCase().includes(q.toLowerCase()));
  if (hits.length === 1) return hits[0];
  die(hits.length ? `«${q}» подходит к нескольким вкладкам:\n  ${hits.map(t => t.id).join('\n  ')}` : `вкладка «${q}» не найдена (arch tabs — список)`);
}

const agent = model => toAgentModel(model);

// ---------- команды ----------
function cmdTabs() {
  const model = load();
  if (flags.json) return console.log(JSON.stringify(agent(model).tabs.map(t => ({ id: t.id, title: t.title })), null, 2));
  for (const t of agent(model).tabs) {
    // Сгенерированные вкладки могут содержать только lead без блоков на одном уровне.
    const blocks = level => Array.isArray(level?.blocks) ? level.blocks : [];
    const allBlocks = [...blocks(t.overview), ...blocks(t.detail)];
    const sc = allBlocks.filter(b => b.type === 'scheme');
    const sq = allBlocks.filter(b => b.type === 'sequence').length;
    console.log(`${t.id.padEnd(34)} ${t.title}   [схем ${sc.length}${sq ? `, последовательностей ${sq}` : ''}; узлов ${sc.reduce((n, b) => n + b.nodes.length, 0)}]`);
  }
}

function cmdShow() {
  const model = load(), tab = findTab(model, pos[0]);
  const want = pos[1] ? LEVELS[pos[1]] : null;
  if (pos[1] && !want) die('уровень: o (overview) или d (detail)');
  const A = agent(model).tabs.find(t => t.id === tab.id);
  if (flags.json) {
    const out = { id: A.id, title: A.title };
    for (const level of ['overview', 'detail']) if (!want || want === level) out[level] = A[level];
    return console.log(JSON.stringify(out, null, 2));
  }
  console.log(`# ${A.id} — ${A.title}`);
  for (const level of ['overview', 'detail']) {
    if (want && want !== level) continue;
    const v = A[level];
    console.log(`\n## ${level}`);
    if (v.lead) console.log('lead: ' + short(v.lead, 220));
    v.blocks.forEach((b, i) => {
      const head = `[${i + 1}] ${b.type}${b.title ? ` «${b.title}»` : ''}`;
      if (b.type === 'scheme') {
        console.log(`${head}: узлов ${b.nodes.length}, связей ${b.edges.length}` + (b.zones ? `; зоны: ${b.zones.join(' | ')}` : ''));
        for (const n of b.nodes) console.log(`  ${n.id} [${n.kind}] ${short(n.label, 60)}${n.sub ? ' — ' + short(n.sub, 70) : ''}${n.placed === false ? '  (без at)' : ''}`);
        for (const e of b.edges) console.log(`  ${e.from} ${e.dashed ? '-->' : '->'} ${e.to}${e.rel ? ` [${e.rel}]` : ''}${e.label ? ' : ' + short(e.label, 50) : ''}`);
        for (const [name, r] of Object.entries(b.routes || {})) console.log(`  маршрут «${name}»: ${(Array.isArray(r) ? r : r.path).join(' -> ')}`);
      } else if (b.type === 'sequence') {
        console.log(`${head}: участников ${b.actors.length}, шагов ${b.messages.length}`);
        for (const m of b.messages) console.log(`  ${m.n}. ${m.from} ${m.ret ? '-->' : '->'} ${m.to} : ${short(m.label, 70)}`);
      } else if (b.type === 'table') {
        console.log(`${head}: ${b.rows.length} строк; колонки: ${b.head.join(' | ')}`);
        if (flags.full) for (const r of b.rows) console.log('  ' + r.map(c => short(c, 60)).join(' | '));
      } else if (b.items) console.log(`${head}: ${b.items.length}` + (flags.full ? '\n' + b.items.map(x => '  ' + short(typeof x === 'string' ? x : x.title + (x.text ? ': ' + x.text : ''), 110)).join('\n') : ''));
      else console.log(`${head}: ${short(b.text, 160)}`);
    });
  }
}

function cmdNode() {
  const q = pos[0]; if (!q) die('укажите id или часть названия');
  const model = load(), A = agent(model), ql = q.toLowerCase();
  let found = 0;
  for (const t of A.tabs) for (const level of ['overview', 'detail']) {
    let si = 0;
    for (const b of (t[level]?.blocks || [])) {
      if (b.type !== 'scheme') continue;
      si++;
      for (const n of b.nodes) {
        const hit = n.id === q || n.id.toLowerCase().includes(ql) || String(n.label).toLowerCase().includes(ql);
        if (!hit) continue;
        if (++found > 40) { console.log('… (слишком много совпадений, уточните запрос)'); return; }
        console.log(`${t.id} ${level} схема#${si}: ${n.id} [${n.kind}] ${short(n.label, 70)}${n.sub ? ' — ' + short(n.sub, 80) : ''}`);
        const inn = b.edges.filter(e => e.to === n.id).map(e => `${e.from}${e.dashed ? '(--)' : ''}${e.label ? ' «' + short(e.label, 30) + '»' : ''}`);
        const out = b.edges.filter(e => e.from === n.id).map(e => `${e.to}${e.dashed ? '(--)' : ''}${e.label ? ' «' + short(e.label, 30) + '»' : ''}`);
        if (inn.length) console.log('    вход : ' + inn.join(', '));
        if (out.length) console.log('    выход: ' + out.join(', '));
        const routes = Object.entries(b.routes || {}).filter(([, r]) => (Array.isArray(r) ? r : r.path).includes(n.id)).map(([k]) => k);
        if (routes.length) console.log('    в маршрутах: ' + routes.join('; '));
        const d = n.info || A.components[n.id];
        if (d) console.log('    описание: ' + short(d, 300));
      }
    }
  }
  if (!found) console.log(`узел «${q}» не найден (arch grep — поиск по тексту)`);
}

function cmdGrep() {
  const q = pos.join(' ').toLowerCase(); if (!q) die('укажите текст');
  const A = agent(load());
  let found = 0;
  const walk = (v, where) => {
    if (found > 40) return;
    if (typeof v === 'string') {
      const i = v.toLowerCase().indexOf(q);
      if (i >= 0) { found++; console.log(`${where}: …${short(v.slice(Math.max(0, i - 50), i + 90), 150)}…`); }
    } else if (Array.isArray(v)) v.forEach((x, i) => walk(x, `${where}[${i}]`));
    else if (v && typeof v === 'object') for (const [k, x] of Object.entries(v)) walk(x, k === 'overview' || k === 'detail' || k === 'blocks' ? where + (k === 'blocks' ? '' : ' ' + k) : `${where}.${k}`);
  };
  for (const t of A.tabs) walk({ overview: t.overview, detail: t.detail }, t.id);
  walk(A.components, 'components');
  if (found > 40) console.log('… (показаны первые 40)'); else if (!found) console.log('ничего не найдено');
}

function cmdCheck() {
  const model = load();
  const { problems } = compile(model);
  problems.warnings.forEach(w => console.log('WARN ' + w));
  problems.errors.forEach(e => console.log('ERR  ' + e));
  console.log(`ошибок: ${problems.errors.length}, предупреждений: ${problems.warnings.length}`);
  if (problems.errors.length || (flags.strict && problems.warnings.length)) process.exit(1);
}

// ---------- карта перехода ----------
function cmdMap() {
  const model = load();
  if (!model.map) die('нет arch/map.yaml — создайте его (формат: docs/FORMAT.md, раздел «Карта перехода»), либо проект обходится без карты');
  const cov = mapCoverage(model);
  const { problems } = compile(model);
  const byStatus = {};
  for (const e of model.map.entries || []) byStatus[e.status] = (byStatus[e.status] || 0) + 1;
  if (flags.json) return console.log(JSON.stringify({ entries: cov.entries, byStatus, covered: cov.mapped, missing: cov.missing, problems }, null, 2));
  console.log(`карта: ${cov.entries} позиций; компонентов AS-IS: ${cov.components.length}, покрыто ${cov.mapped.length}`);
  console.log('по статусам: ' + Object.entries(byStatus).map(([k, v]) => `${k} — ${v}`).join('; '));
  problems.errors.filter(e => e.startsWith('map.yaml')).forEach(e => console.log('ERR  ' + e));
  problems.warnings.filter(e => e.startsWith('map.yaml')).forEach(e => console.log('WARN ' + e));
  if (cov.missing.length) {
    console.log(`\nне покрыто картой (${cov.missing.length}):`);
    cov.missing.forEach(id => console.log(`  ${id}${flags.missing ? ' — ' + short(model.components[id], 120) : ''}`));
  } else console.log('\nнепокрытых позиций нет');
  if (!flags.missing && cov.missing.length) console.log('\nподробно: arch map --missing');
  if (problems.errors.some(e => e.startsWith('map.yaml'))) process.exit(1);
}

// ---------- раскладка ----------
function cmdLayout() {
  const [tabQ, lvl] = pos;
  if (!tabQ) die('использование: layout <вкладка> [o|d] [--block N] [--mode auto|missing|zone|flow] [--all] [--dry]');
  const opts = {
    mode: flags.mode || 'auto',
    all: !!flags.all,
    gap: flags.gap ? Number(flags.gap) : undefined,
    margin: flags.margin ? Number(flags.margin) : undefined,
  };
  const { tab, level } = editScheme(tabQ, lvl || 'o', (doc, scheme) => {
    const S = scheme.toJSON();
    const plan = layoutScheme(S, opts);
    if (plan.size && S.size && (plan.size[0] !== S.size[0] || plan.size[1] !== S.size[1])) {
      scheme.set('size', flowOf(doc, plan.size));
      console.log(`холст расширен: ${S.size.join('×')} -> ${plan.size.join('×')}`);
    }
    const nodes = scheme.get('nodes', true);
    let changed = 0;
    nodes.items.forEach(item => {
      const id = item && item.get ? String(item.get('id', true)) : null;
      const at = id && plan.at[id];
      if (!at) return;
      if (item.set) item.set('at', flowOf(doc, at)); else item.at = at;
      changed++;
    });
    plan.warnings.forEach(w => console.log('WARN ' + w));
    console.log(`режим «${plan.mode}»: ${plan.note}${flags.dry ? ' (черновик, --dry: файл не изменён)' : ''}`);
    if (!changed) console.log('изменений нет');
  }, { dry: !!flags.dry });
  if (!flags.dry) console.log(`${tab.id} ${level}: готово. Проверьте схему (npm run build) и при необходимости подправьте вручную.`);
}

// ---------- снимки и сравнение ----------
function cmdSnapshot() {
  const name = pos[0] || '';
  const date = new Date().toISOString().slice(0, 10);
  // имя можно задать и с датой, и без: 2026-11-06-baseline не превращается в 2026-11-06-2026-11-06-baseline
  const folder = !name ? date : (name.startsWith(date) ? name : `${date}-${name}`);
  const dir = path.join(ROOT, 'snapshots', folder);
  if (fs.existsSync(path.join(dir, 'architecture.html')) && !flags.force)
    die(`снимок уже есть: ${relTo(ROOT, dir)} (перезаписать: --force)`);
  fs.mkdirSync(dir, { recursive: true });
  // копия источника: снимок должен быть самодостаточным — и сравнимым, и пригодным для отката
  fs.cpSync(path.join(ROOT, 'arch'), path.join(dir, 'arch'), { recursive: true });
  const res = buildAll({ quiet: true, dir: ROOT, out: dir, noLog: true });
  const model = res.model;
  const readme = [
    `# Снимок архитектуры ${date}${name ? ` (${name})` : ''}`,
    '',
    `Вкладок: ${model.tabs.length}. Источник: \`arch/\` на момент снимка.`,
    '',
    '- Посмотреть: открыть `architecture.html` (файл самодостаточный, работает офлайн).',
    '- Сравнить с текущим состоянием: `npm run arch -- diff ' + path.relative(ROOT, dir).split(path.sep).join('/') + '`',
    '- Снимок не редактировать; новые снимки — `npm run arch -- snapshot <имя>`.',
    '',
  ].join('\n');
  fs.writeFileSync(path.join(dir, 'README.md'), readme, 'utf8');
  console.log(`снимок: ${relTo(ROOT, dir)} (вкладок: ${model.tabs.length})`);
}

function loadSnapshotModel(target) {
  // сначала относительно каталога проекта (arch diff snapshots/…), затем относительно cwd
  const nearProject = path.isAbsolute(target) ? target : path.join(ROOT, target);
  const p = fs.existsSync(nearProject) ? nearProject : path.resolve(process.cwd(), target);
  if (fs.existsSync(p) && fs.statSync(p).isDirectory()) {
    const arch = fs.existsSync(path.join(p, 'arch', 'meta.yaml')) ? path.join(p, 'arch') : null;
    if (arch) return { model: toAgentModel(loadModel(arch)), from: `arch/ → ${relTo(ROOT, arch)}` };
    const json = path.join(p, 'architecture.json');
    if (fs.existsSync(json)) return { model: JSON.parse(fs.readFileSync(json, 'utf8')), from: relTo(ROOT, json) };
    if (fs.existsSync(path.join(p, 'architecture.html')))
      die(`снимок ${target} старого формата: в нём только architecture.html, сравнивать не с чем.\n` +
        `  Такие снимки остаются как история. Сделайте новый: arch snapshot <имя> — он сохранит и arch/, и данные.`);
    die(`в ${target} нет ни arch/meta.yaml, ни architecture.json`);
  }
  if (fs.existsSync(p) && p.endsWith('.json')) return { model: JSON.parse(fs.readFileSync(p, 'utf8')), from: relTo(ROOT, p) };
  die(`не найден снимок или каталог: ${target}`);
}

function cmdDiff() {
  const target = pos[0]; if (!target) die('использование: diff <снимок|каталог|json>');
  const cur = agent(load());
  const old = loadSnapshotModel(target);
  const diffs = deepDiff(old.model, cur);
  if (!diffs.length) { console.log(`отличий нет: ${old.from}`); return; }
  const idOf = i => (cur.tabs[i] && cur.tabs[i].id) || (old.model.tabs[i] && old.model.tabs[i].id) || `#${i}`;
  const byTab = new Map();
  for (const d of diffs) {
    const m = /^\.?tabs\[(\d+)\]/.exec(d); // deepDiff даёт путь с ведущей точкой от корня
    const key = m ? idOf(+m[1]) : 'прочее';
    if (!byTab.has(key)) byTab.set(key, []);
    byTab.get(key).push(m ? d.slice(m[0].length) : d);
  }
  // короткая сводка «что стало больше»: руководителю проще, чем список путей
  const size = m => {
    let tabs = 0, schemes = 0, nodes = 0;
    for (const t of m.tabs || []) {
      tabs++;
      for (const b of (t.blocks || [])) if (b.type === 'scheme') { schemes++; nodes += (b.nodes || []).length; }
    }
    return { tabs, schemes, nodes };
  };
  const was = size(old.model), now = size(cur);
  const delta = (a, b, one, few, many) => a === b ? `${b}` : `${a} → ${b}`;
  console.log(`отличий: ${diffs.length} (сравнение с ${old.from})`);
  console.log(`  вкладок ${delta(was.tabs, now.tabs)}, схем ${delta(was.schemes, now.schemes)}, узлов ${delta(was.nodes, now.nodes)}`);
  for (const [tab, list] of byTab) console.log(`  ${tab}: ${list.length}`);
  const limit = flags.full ? diffs.length : 40;
  const flat = [...byTab.entries()].flatMap(([tab, list]) => list.map(x => `${tab}${x}`));
  flat.slice(0, limit).forEach(l => console.log('   ' + l));
  if (flat.length > limit) console.log(`   … и ещё ${flat.length - limit} (показать все: --full)`);
}

// ---------- новый проект ----------
const TEMPLATE_META = (name) => `# Настройки страницы и палитра типов узлов (kinds: id -> [цвет, название])
title: ${name} · архитектура
heading: ${name}
subtitle: "Описание предметной области — один абзац"
# Внешний вид страницы: panel — меню слева, classic — шапка и лента вкладок сверху.
#ui:
#  shell: panel
# Разделы: порядок = порядок на странице. hidden: true — вкладки скрыты за переключателем.
# role: primary — целевое; asis — текущее; solution — прикладной слой; governance — управление
# (в governance складываются сгенерированные вкладки: карта перехода и журнал решений).
sections:
  - { dir: tobe, label: "TO-BE", role: primary }
  - { dir: solutions, label: "Решения", role: solution }
  - { dir: governance, label: "Управление", role: governance }
  - { dir: asis, label: "AS-IS", hidden: true, role: asis, banner: "**ТЕКУЩЕЕ СОСТОЯНИЕ.** Вкладка оставлена для сравнения." }
kinds:
  ext: [ "#8a93a3", Внешние ]
  edge: [ "#3b82f6", Вход / сеть ]
  app: [ "#8b5cf6", Приложения ]
  core: [ "#f59e0b", Ядро ]
  data: [ "#ec4899", Данные ]
  sec:  [ "#e11d48", Безопасность ]
  mon:  [ "#0ea5e9", Мониторинг ]
`;

const TEMPLATE_COMPONENTS = `# Описания компонентов: id -> текст. Показываются по клику на узел с тем же id
# и подставляются в info узла как {id}.
gateway: "**Шлюз.** Единая точка входа: аутентификация, лимиты, журнал."
`;

const TEMPLATE_ADR = `# Журнал решений: одно архитектурное решение — один файл.
# Из каталога собирается вкладка «Журнал решений (ADR)» в разделе governance.
# Статусы: proposed (гипотеза) | accepted (решено) | rejected | superseded.
id: ADR-001
title: "Пример: единая точка входа для всех клиентов"
status: proposed
date: ${new Date().toISOString().slice(0, 10)}
owner: "Владелец платформы"
due: "2026-12-01"
need:
  - "Список клиентов и их способов подключения."
  - "Приемлемая задержка на входе."
links: [ "tobe/01-overview" ]
context: "Клиенты обращаются к сервису кто как умеет: часть напрямую, часть через обёртку."
decision: "Все обращения идут через один шлюз: аутентификация, лимиты и журнал в одном месте."
consequences:
  - "Плюс: единая точка контроля и журнала."
  - "Минус: шлюз становится обязательным участником каждого запроса."
  - "Открыто: что делать с пакетными заданиями, которым журнал не нужен."
`;

const TEMPLATE_TAB = (name) => `title: Общий вид
overview:
  lead: "**Один абзац: что изображено и зачем.** Текст поддерживает **жирный** и \`код\`."
  blocks:
    - scheme:
        size: [ 1000, 340 ]
        groups:
          - [ 20, 40, 300, 260, Вход ]
          - [ 360, 40, 300, 260, Обработка ]
          - [ 700, 40, 280, 260, Результат ]
        nodes:
          - { id: user, at: [ 170, 110, 200 ], label: Пользователь, sub: "кто инициирует", kind: ext }
          - { id: gw, at: [ 510, 110, 200 ], label: gateway, sub: "единая точка входа", kind: core, info: "{gateway}" }
          - { id: store, at: [ 840, 110, 200 ], label: Хранилище, sub: "куда попадают данные", kind: data }
        edges:
          - user -> gw: запрос
          - gw -> store: запись
        routes:
          Основной путь: user -> gw -> store
        hint: "Одна мысль под схемой: что важно понять из этой картинки."
    - note: "Дальше — таблицы, карточки, шаги и диаграммы последовательности. Формат: docs/FORMAT.md."
detail:
  lead: "Подробно: тот же контур, но с составом и деталями."
  blocks:
    - table:
        head: [ Компонент, Роль, Примечание ]
        rows:
          - [ "«gateway»", Единая точка входа, "Описание — в components.yaml" ]
        center: [ 1 ]
`;

const PROFILES_DIR = path.join(TOOL_ROOT, 'profiles');

// профиль домена: общий словарь для нескольких проектов (kinds, разделы, стартовые вкладки)
function listProfiles() {
  if (!fs.existsSync(PROFILES_DIR)) return [];
  return fs.readdirSync(PROFILES_DIR).filter(n => n.endsWith('.yaml')).sort()
    .map(name => {
      const file = path.join(PROFILES_DIR, name);
      let p = {};
      try { p = YAML.parse(fs.readFileSync(file, 'utf8')) || {}; } catch { return { name: name.replace(/\.yaml$/, ''), file, broken: true }; }
      return { name: p.name || name.replace(/\.yaml$/, ''), file, description: p.description || '', profile: p };
    });
}

function loadProfile(arg) {
  if (!arg) return null;
  const asFile = path.resolve(process.cwd(), String(arg));
  let file = fs.existsSync(asFile) && fs.statSync(asFile).isFile() ? asFile : null;
  if (!file) {
    const hit = listProfiles().find(x => x.name === String(arg));
    if (!hit) {
      const names = listProfiles().map(x => x.name);
      die(`профиль «${arg}» не найден. Доступные: ${names.join(', ') || '—'} (arch profiles)`);
    }
    file = hit.file;
  }
  let p;
  try { p = YAML.parse(fs.readFileSync(file, 'utf8')); } catch (e) { die(`профиль ${file}: ${e.message}`); }
  if (!p || typeof p !== 'object') die(`профиль ${file}: пустой`);
  for (const k of ['title', 'sections', 'kinds'])
    if (!p[k]) die(`профиль ${file}: нет обязательного поля ${k}`);
  if (!Array.isArray(p.sections) || !p.sections.length) die(`профиль ${file}: sections — непустой список`);
  for (const t of p.tabs || [])
    if (!t.file || !t.tab) die(`профиль ${file}: у вкладки нужны file и tab`);
  return { ...p, file };
}

function cmdProfiles() {
  const list = listProfiles();
  if (!list.length) die(`нет каталога ${PROFILES_DIR} — профили не заведены`);
  if (flags.json) return console.log(JSON.stringify(list.map(x => ({ name: x.name, description: x.description, file: relTo(ROOT, x.file),
    sections: (x.profile.sections || []).length, tabs: (x.profile.tabs || []).length })), null, 2));
  for (const x of list) {
    console.log(`${x.name.padEnd(12)} ${x.description}${x.broken ? '  (файл не читается)' : ''}`);
    if (x.profile) console.log(`${' '.repeat(13)}разделов ${(x.profile.sections || []).length} · вкладок ${(x.profile.tabs || []).length} · ${relTo(ROOT, x.file)}`);
  }
  console.log('\nновый проект: arch init <каталог> --profile <имя>');
}

function cmdInit() {
  const dir = pos[0]; if (!dir) die('использование: init <каталог> [--profile имя|файл] [--force]');
  const p = path.resolve(process.cwd(), dir);
  const arch = path.join(p, 'arch');
  if (fs.existsSync(path.join(arch, 'meta.yaml')) && !flags.force) die(`проект уже есть: ${arch} (перезаписать: --force)`);
  const name = path.basename(p) || 'Проект';
  const profile = loadProfile(flags.profile === true ? undefined : flags.profile);
  for (const d of ['tobe', 'solutions', 'governance', 'decisions', 'asis']) fs.mkdirSync(path.join(arch, d), { recursive: true });

  if (!profile) {
    fs.writeFileSync(path.join(arch, 'meta.yaml'), TEMPLATE_META(name), 'utf8');
    fs.writeFileSync(path.join(arch, 'components.yaml'), TEMPLATE_COMPONENTS, 'utf8');
    fs.writeFileSync(path.join(arch, 'tobe', '01-overview.yaml'), TEMPLATE_TAB(name), 'utf8');
    fs.writeFileSync(path.join(arch, 'decisions', '01-example.yaml'), TEMPLATE_ADR, 'utf8');
    for (const d of ['solutions', 'governance', 'asis']) fs.writeFileSync(path.join(arch, d, '.gitkeep'), '', 'utf8');
    console.log(`создан проект: ${arch}`);
    console.log('  meta.yaml, components.yaml, tobe/01-overview.yaml (пример), decisions/01-example.yaml (ADR)');
    console.log('  разделы: tobe (целевое), solutions (прикладное), governance (управление), asis (скрыт)');
    console.log(`сборка: node ${relTo(process.cwd(), path.join(TOOL_ROOT, 'scripts', 'build.mjs'))} --dir ${path.relative(process.cwd(), p) || '.'}`);
    console.log('готовые профили домена: arch profiles');
    return;
  }

  const meta = {
    title: profile.title, heading: profile.heading || profile.title, subtitle: profile.subtitle || '',
    sections: profile.sections, kinds: profile.kinds,
  };
  if (profile.ui) meta.ui = profile.ui;
  fs.writeFileSync(path.join(arch, 'meta.yaml'),
    `# Профиль домена: ${profile.name || path.basename(profile.file)} (${relTo(TOOL_ROOT, profile.file)})\n` +
    `# Общий словарь для нескольких сервисов: kinds, разделы, стартовые вкладки.\n\n` + YAML.stringify(meta), 'utf8');

  if (profile.components) fs.writeFileSync(path.join(arch, 'components.yaml'), YAML.stringify(profile.components), 'utf8');
  if (profile.entities) fs.writeFileSync(path.join(arch, 'entities.yaml'), YAML.stringify({ entities: profile.entities }), 'utf8');
  for (const t of profile.tabs || []) {
    const file = path.join(arch, t.file);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, YAML.stringify(t.tab), 'utf8');
  }
  if (profile.controls && profile.controls.length) {
    // требования группируются по файлу: одна область — один файл со списком
    fs.mkdirSync(path.join(arch, 'controls'), { recursive: true });
    const byFile = new Map();
    for (const c of profile.controls) {
      if (!byFile.has(c.file)) byFile.set(c.file, []);
      byFile.get(c.file).push(c.control || c);
    }
    for (const [file, list] of byFile) fs.writeFileSync(path.join(arch, 'controls', file), YAML.stringify(list), 'utf8');
  }
  if (profile.invariants && profile.invariants.length) {
    fs.mkdirSync(path.join(arch, 'invariants'), { recursive: true });
    for (const inv of profile.invariants) fs.writeFileSync(path.join(arch, 'invariants', inv.file), YAML.stringify(inv.rule), 'utf8');
  }
  if (profile.decisions && profile.decisions.length)
    for (const d of profile.decisions) fs.writeFileSync(path.join(arch, 'decisions', d.file), YAML.stringify(d.adr), 'utf8');
  else fs.writeFileSync(path.join(arch, 'decisions', '.gitkeep'), '', 'utf8');
  for (const d of ['solutions', 'governance', 'asis'])
    if (!fs.existsSync(path.join(arch, d)) || !fs.readdirSync(path.join(arch, d)).length) fs.writeFileSync(path.join(arch, d, '.gitkeep'), '', 'utf8');

  const tabs = (profile.tabs || []).length;
  console.log(`создан проект: ${arch}`);
  console.log(`  профиль: ${profile.name || path.basename(profile.file)} — ${profile.description || ''}`.trimEnd());
  console.log(`  разделы: ${profile.sections.map(x => x.dir).join(', ')} · вкладок ${tabs}` +
    (profile.entities ? ` · сущностей ${profile.entities.length}` : '') +
    (profile.invariants ? ` · инвариантов ${profile.invariants.length}` : '') +
    ((profile.controls || []).length ? ` · требований ${profile.controls.length}` : '') +
    ((profile.decisions || []).length ? ` · решений ${profile.decisions.length}` : ''));
  console.log(`  проверка: node ${relTo(process.cwd(), path.join(TOOL_ROOT, 'scripts', 'arch.mjs'))} check --dir ${path.relative(process.cwd(), p) || '.'} --strict`);
  console.log(`  сборка:   node ${relTo(process.cwd(), path.join(TOOL_ROOT, 'scripts', 'build.mjs'))} --dir ${path.relative(process.cwd(), p) || '.'}`);
}

function cmdPaths() {
  console.log(JSON.stringify({
    tool: TOOL_ROOT,
    project: ROOT,
    arch: ARCH,
    dist: DIST,
    src: PROJECT.src,
    srcFromProject: PROJECT.src === path.join(ROOT, 'src'),
  }, null, 2));
}

// ---------- журнал решений ----------
function cmdAdr() {
  const model = load();
  const list = model.decisions || [];
  if (!list.length) die('нет arch/decisions/*.yaml — журнал решений не заведён');
  if (pos[0]) {
    const q = String(pos[0]).toLowerCase();
    const d = list.find(x => String(x.id).toLowerCase() === q || String(x.id).toLowerCase().endsWith(q));
    if (!d) die(`решение «${pos[0]}» не найдено (arch adr — список)`);
    if (flags.json) return console.log(JSON.stringify(d, null, 2));
    console.log(`${d.id} · ${d.title}\nстатус: ${DECISION_LABEL[d.status] || d.status}${d.date ? ` · ${d.date}` : ''}${d.owner ? ` · ответственный: ${d.owner}` : ''}${d.due ? ` · решить до ${d.due}` : ''}`);
    if (d.links && d.links.length) console.log('где: ' + d.links.join(', '));
    console.log(`\nконтекст: ${d.context}\n\nрешение: ${d.decision}`);
    if (Array.isArray(d.need) && d.need.length) console.log('\nчто нужно, чтобы решить:\n' + d.need.map(x => '  - ' + x).join('\n'));
    if (Array.isArray(d.consequences) && d.consequences.length) console.log('\nпоследствия:\n' + d.consequences.map(x => '  - ' + x).join('\n'));
    return;
  }
  const want = flags.status ? String(flags.status) : null;
  if (want && !DECISION_STATUS.includes(want)) die(`status: ${DECISION_STATUS.join(' | ')}`);
  const shown = want ? list.filter(d => d.status === want) : list;
  if (flags.json) return console.log(JSON.stringify(shown, null, 2));
  for (const d of shown) console.log(`${String(d.id).padEnd(9)} ${String(DECISION_LABEL[d.status] || d.status).padEnd(11)} ${String(d.due || d.date || '').padEnd(11)} ${d.title}${d.owner && !want ? ` — ${d.owner}` : ''}`);
  const by = s => list.filter(d => d.status === s).length;
  console.log(`\nвсего ${list.length}: ` + DECISION_STATUS.map(s => `${DECISION_LABEL[s]} — ${by(s)}`).join('; '));
  if (!want && by('proposed')) console.log('открытые: arch adr --status proposed');
}

// ---------- контракт слоёв ----------
function cmdContract() {
  const model = load();
  const ix = contractIndex(model);
  if (flags.json) return console.log(JSON.stringify({
    platform: ix.primary,
    byTab: ix.declared,
    undeclared: ix.undeclared,
    reverse: Object.fromEntries([...ix.used].map(([k, v]) => [k, v])),
    unused: ix.unused,
  }, null, 2));
  console.log(`контракт прикладного слоя (платформа — раздел ${ix.primary})`);
  console.log('\nчто решения занимают у платформы:');
  for (const d of ix.declared) console.log(`  ${d.tab.padEnd(34)} ${d.uses.length ? d.uses.join(', ') : '— (не пользуется)'}`);
  for (const id of ix.undeclared) console.log(`  ${id.padEnd(34)} не объявлено (uses отсутствует)`);
  console.log('\nобратный индекс: что нельзя менять без оглядки на решения');
  const rows = [...ix.used.entries()].sort((a, b) => b[1].length - a[1].length);
  for (const [node, tabs] of rows) console.log(`  ${node.padEnd(16)} ${tabs.length} — ${tabs.join(', ')}`);
  if (ix.unused.length) console.log(`\nне используется ни одним решением (${ix.unused.length}): ${ix.unused.join(', ')}`);
  else console.log('\nнеиспользуемых узлов платформы нет');
}

// ---------- замочки разделов ----------
function cmdLock() {
  const model = load();
  const locked = model.sections.filter(s => s.locked);
  if (!locked.length) die('нет залоченных разделов: задайте locked: true у раздела в arch/meta.yaml (или в проекте нет разделов)');
  const files = lockedFiles(model, ARCH);
  const prev = (readLock(ARCH) || {}).tabs || {};
  if (flags.list) {
    let same = 0, diff = 0;
    for (const f of files) {
      if (prev[f.id] === hashFile(f.file)) { same++; continue; }
      diff++; console.log(`${prev[f.id] ? 'ИЗМЕНЕНО ' : 'НЕТ ЗАМКА'} ${f.id}`);
    }
    console.log(`залоченных вкладок: ${files.length}; совпадает ${same}, расхождений ${diff}`);
    if (diff || Object.keys(prev).length !== files.length) process.exit(1);
    return;
  }
  const entries = {};
  for (const f of files) entries[f.id] = hashFile(f.file);
  const changed = files.filter(f => prev[f.id] && prev[f.id] !== entries[f.id]).map(f => f.id);
  if (changed.length && !flags.force)
    die(`замочки уже стоят и расходятся для ${changed.length} вкладок:\n  ${changed.join('\n  ')}\nперезаписать осознанно: arch lock --force`);
  writeLock(ARCH, entries);
  console.log(`замочки ${changed.length ? 'обновлены' : 'установлены'}: ${files.length} вкладок (разделы: ${locked.map(s => s.dir).join(', ')})`);
  // --force благословляет всё сразу — перечисляем, что именно изменилось, чтобы это видно было в истории
  if (changed.length) {
    console.log('перезаписаны замочки изменённых вкладок:');
    changed.forEach(id => console.log(`  ${id}`));
  }
  const added = files.filter(f => !prev[f.id]).map(f => f.id);
  if (added.length) console.log(`добавлены замочки новых вкладок: ${added.join(', ')}`);
}

// ---------- правки (с откатом, если модель стала невалидной) ----------
const OUT = { lineWidth: 0, blockQuote: false, minContentWidth: 0 };
function quoteMultiline(doc) { YAML.visit(doc, { Scalar(_, n) { if (typeof n.value === 'string' && n.value.includes('\n')) n.type = 'QUOTE_DOUBLE'; } }); }

function editScheme(tabQ, levelQ, mutate, { dry = false } = {}) {
  const model = load(), tab = findTab(model, tabQ);
  const level = LEVELS[levelQ]; if (!level) die('уровень: o (overview) или d (detail)');
  if (tab.generated) die(`вкладка ${tab.id} собирается из map.yaml — правьте arch/map.yaml`);
  if (tab.locked) die(`раздел «${tab.section}» залочен (${tab.file}): правки запрещены. Снять замочек осознанно — locked: false в arch/meta.yaml. Проверить состояние: arch lock --list`);
  const file = path.join(ARCH, tab.file);
  const before = fs.readFileSync(file, 'utf8');
  const doc = YAML.parseDocument(before);
  const blocks = doc.getIn([level, 'blocks'], true);
  const schemes = blocks ? blocks.items.filter(b => YAML.isMap(b) && b.has('scheme')) : [];
  const n = Number(flags.block || 1);
  const sch = schemes[n - 1];
  if (!sch) die(`в ${tab.id} ${level} нет схемы №${n} (схем: ${schemes.length})`);
  mutate(doc, sch.get('scheme', true));
  if (dry) return { tab, level };
  quoteMultiline(doc);
  fs.writeFileSync(file, doc.toString(OUT));
  const { problems } = compile(loadModel(ARCH));
  if (problems.errors.length) {
    fs.writeFileSync(file, before);
    console.error('правка отменена, модель стала невалидной:');
    problems.errors.forEach(e => console.error('  ' + e));
    process.exit(1);
  }
  return { tab, level, problems };
}

function flowOf(doc, value) { const n = doc.createNode(value); n.flow = true; YAML.visit(n, { Seq(_, s) { s.flow = true; } }); return n; }

function cmdAddNode() {
  const [tabQ, lvl, id, label] = pos;
  if (!tabQ || !lvl || !id || !label) die('использование: add-node <вкладка> <o|d> <id> <label> [--sub s] [--kind k] [--at x,y,w] [--block N]');
  const node = { id, ...(flags.at ? { at: String(flags.at).split(',').map(Number) } : {}), label, ...(flags.sub ? { sub: flags.sub } : {}), kind: flags.kind || 'core' };
  const { tab, level, problems } = editScheme(tabQ, lvl, (doc, scheme) => scheme.get('nodes', true).add(flowOf(doc, node)));
  console.log(`добавлен узел ${id} в ${tab.id} ${level}` + (flags.at ? '' : ' (без at: разместите командой "arch layout" или задайте at вручную)'));
  problems.warnings.filter(w => w.includes(id)).forEach(w => console.log('WARN ' + w));
}

function cmdAddEdge() {
  const [tabQ, lvl, spec] = pos;
  if (!tabQ || !lvl || !spec) die('использование: add-edge <вкладка> <o|d> "a -> b" [--label s] [--kind k] [--block N]');
  const opts = { ...(flags.label ? { label: flags.label } : {}), ...(flags.kind ? { kind: flags.kind } : {}) };
  const keys = Object.keys(opts);
  const { tab, level } = editScheme(tabQ, lvl, (doc, scheme) => {
    const seq = scheme.get('edges', true);
    if (!keys.length) seq.add(doc.createNode(spec));
    else if (keys.length === 1 && keys[0] === 'label') seq.add(doc.createNode({ [spec]: opts.label }));
    else seq.add(doc.createNode({ [spec]: flowOf(doc, opts) }));
  });
  console.log(`добавлена связь ${spec} в ${tab.id} ${level}`);
}


// ---------- граф модели ----------
const graphOf = () => { const model = load(); return { model, g: buildGraph(model) }; };

function cmdGraph() {
  const { model, g } = graphOf();
  if (flags.cypher) {
    const text = toCypher(g, { notes: !!flags.notes, title: model.meta.title, date: new Date().toISOString().slice(0, 10) });
    const c = cypherCounts(text);
    if (flags.out) {
      const out = path.resolve(process.cwd(), String(flags.out));
      fs.mkdirSync(path.dirname(out), { recursive: true });
      fs.writeFileSync(out, text + '\n', 'utf8');
      console.log(`OK  ${relTo(process.cwd(), out)}  узлов ${c.nodes}, связей ${c.edges}`);
      console.log('    загрузка: cat <файл> | cypher-shell -a bolt://localhost:7687 -u neo4j -p <пароль>');
      return;
    }
    return console.log(text);
  }
  if (flags.dot) return console.log(toDot(g, { noTabs: !flags.tabs, withNotes: !!flags.notes }));
  const st = graphStats(g);
  if (flags.json) return console.log(JSON.stringify({
    entities: st.entities, edges: st.edges, units: st.units, byType: st.byType, byEdge: st.byEdge,
    orphans: st.orphans.map(e => e.id), coverage: coverage(model, g),
  }, null, 2));
  console.log(`граф модели: ${st.entities} сущностей, ${st.edges} связей, ${st.units} фрагментов знания`);
  console.log('по типам: ' + Object.entries(st.byType).sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ${v}`).join(' · '));
  console.log('по связям: ' + Object.entries(st.byEdge).sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ${v}`).join(' · '));
  console.log(`узлы без реестра (собственные id): ${st.implicit}`);
  if (st.orphans.length) {
    const list = st.orphans.slice(0, 8).map(e => e.id);
    console.log(`связей нет у ${st.orphans.length} сущностей: ${list.join(', ')}${st.orphans.length > list.length ? '…' : ''}`);
  }
  if (flags.coverage || flags.all) {
    console.log('\nпокрытие реестром (узлы схем сопоставлены с каноническими сущностями):');
    const rows = coverage(model, g);
    const solid = rows.filter(r => !r.process).sort((a, b) => a.pct - b.pct);
    for (const r of solid) {
      const bar = '█'.repeat(Math.round(r.pct / 10)).padEnd(10, '·');
      console.log(`  ${bar} ${String(r.pct).padStart(3)}%  ${r.tab.padEnd(32)} ${r.mapped}/${r.total}${r.refs ? ` (ref: ${r.refs})` : ''}`);
    }
    const proc = rows.filter(r => r.process);
    if (proc.length) {
      console.log(`  вкладки-процессы (шаги и развилки, сопоставление не нужно): ${proc.map(r => `${r.tab} ${r.mapped}/${r.total}`).join(' · ')}`);
    }
    console.log('  ниже 60% — у вкладки свой набор id; добавьте их в aliases в arch/entities.yaml, чтобы граф их узнавал.');
  } else {
    console.log('покрытие реестром: arch graph --coverage');
  }
}

function cmdAsk() {
  const q = pos.join(' ');
  if (!q) die('использование: ask "<вопрос>" [--limit N]');
  const { g } = graphOf();
  const res = searchGraph(g, q, { limit: Number(flags.limit || 8) });
  if (flags.json) return console.log(JSON.stringify({
    query: res.query, terms: res.terms,
    entities: res.entities.map(e => ({ id: e.id, title: e.title, type: e.type, occurrences: e.occurrences.length })),
    hits: res.hits.map(h => ({ where: h.where, tab: h.tab, kind: h.kind, text: h.text, score: h.score, entities: h.entities })),
  }, null, 2));
  console.log(`вопрос: «${q}»`);
  if (!res.hits.length) {
    console.log('ничего не найдено. Попробуйте одно-два слова из лексики модели: DLP, Gateway, гипотеза, права, AS-IS.');
    console.log('если не помогает — посмотрите список вкладок: arch tabs');
    return;
  }
  console.log(`найдено ${res.hits.length} фрагментов (в графе ${g.entities.size} сущностей, ${g.edges.length} связей)\n`);
  res.hits.forEach((h, i) => {
    const tag = h.kind === 'decision' ? ' [решение]' : h.kind === 'hypothesis' ? ' [гипотеза]' : h.kind === 'route' ? ' [маршрут]' : '';
    console.log(`[${i + 1}]${tag} ${h.where}${h.tab ? `   (${h.tab})` : ''}`);
    console.log('    ' + short(h.text, flags.full ? 4000 : 260));
    if (h.entities.length) console.log('    о чём: ' + h.entities.slice(0, 6).map(id => short((g.entities.get(id) || {}).title || id, 46)).join(' · '));
  });
  if (res.entities.length) {
    console.log('\nобъекты, о которых спрашивали:');
    for (const e of res.entities) {
      const what = e.type === 'tab' ? 'вкладка' : e.type === 'decision' || e.type === 'hypothesis' ? 'запись' : e.implicit ? 'узел схемы' : 'реестр';
      console.log(`  ${e.id.padEnd(18)} ${short(e.title, 60).padEnd(62)} ${what}${e.occurrences.length ? `, упоминаний ${e.occurrences.length}` : ''}`);
    }
  }
  const hint = res.entities.find(e => e.type !== 'tab');
  const showTab = res.hits[0].tab || (res.entities.find(e => e.type === 'tab') || {}).id.replace(/^tab:/, '');
  console.log('\nдальше: ' + (hint ? `arch impact ${hint.id} · arch entity ${hint.id} · ` : '') + (showTab ? `arch show ${showTab} · ` : '') + 'arch tabs');
}

// Требования, которые закрывает компонент: связь «компонент → требование» без отдельного графа.
const asArray = v => (Array.isArray(v) ? v : v === undefined || v === null ? [] : [v]);
function requirementsOf(model, entity) {
  const ids = new Set([entity.id, ...asArray(entity.aliases)].map(String));
  return asArray(model.controls)
    .filter(c => c && asArray(c.satisfied_by).map(String).some(x => ids.has(x)))
    .map(c => {
      const by = asArray(c.satisfied_by).map(String);
      return { id: c.id, title: c.title, area: c.area, others: by.filter(x => !ids.has(x)) };
    });
}

function cmdImpact() {
  const id = pos[0];
  if (!id) die('использование: impact <id|название> [--depth N]');
  const { model, g } = graphOf();
  const r = impactOf(g, id, { depth: Number(flags.depth || 3) });
  if (!r) {
    const near = [...g.entities.values()].filter(e => e.id.includes(id.toLowerCase()) || String(e.title).toLowerCase().includes(id.toLowerCase())).slice(0, 8);
    die(`сущность «${id}» не найдена` + (near.length ? `; похожие: ${near.map(e => e.id).join(', ')}` : ' (реестр: arch entity)'));
  }
  if (flags.json) return console.log(JSON.stringify({
    id: r.entity.id, title: r.entity.title, type: r.entity.type, owner: r.entity.owner,
    tabs: r.tabs,
    occurrences: r.occurrences,
    outgoing: r.outgoing, incoming: r.incoming,
    dependents: r.dependents.map(w => ({ depth: w.depth, entities: w.entities.map(e => e.id) })),
    requirements: requirementsOf(model, r.entity),
  }, null, 2));
  console.log(`${r.entity.id} — ${r.entity.title}   [${r.entity.type}${r.entity.owner ? ' · владелец: ' + r.entity.owner : ''}]`);
  if (r.entity.implicit) console.log('   (своя сущность: нет в arch/entities.yaml — связь с другими вкладками может быть неполной)');
  console.log(`встречается: ${plural(r.tabs.length, ['вкладка', 'вкладки', 'вкладок'])}, ${plural(r.occurrences.length, ['узел', 'узла', 'узлов'])}`);
  for (const o of r.occurrences.slice(0, 10)) console.log(`  ${o.tab} › ${o.nodeId}: ${short(o.label, 60)}${o.sub ? ' — ' + short(o.sub, 60) : ''}`);
  const out = r.outgoing.filter(e => e.type !== 'flow' || e.tab);
  if (out.length) {
    console.log('\nисходящие связи:');
    for (const e of out.slice(0, 12)) console.log(`  → ${e.to.replace(/^tab:/, '')} [${e.type}]${e.label ? ' «' + short(e.label, 60) + '»' : ''}`);
  }
  if (r.incoming.length) {
    console.log('\nвходящие связи:');
    for (const e of r.incoming.slice(0, 12)) console.log(`  ← ${e.from.replace(/^tab:/, '')} [${e.type}]${e.label ? ' «' + short(e.label, 60) + '»' : ''}`);
  }
  if (r.dependents.length) {
    console.log('\nесли изменить или убрать — по связям затронет:');
    for (const w of r.dependents) console.log(`  шаг ${w.depth}: ${w.entities.map(e => e.id).filter(x => !x.startsWith('tab:') && !x.includes(':')).join(', ') || '—'}`);
  }
  const dec = [...g.entities.values()].filter(e => (e.type === 'decision' || e.type === 'hypothesis')
    && g.edges.some(x => x.from === e.id && x.to === r.entity.id && x.type === 'about'));
  const allDec = [...new Set(dec.map(e => e.id))];
  if (allDec.length) console.log('\nрешения и гипотезы, которые это касаются: ' + allDec.join(', '));
  const reqs = requirementsOf(model, r.entity);
  if (reqs.length) {
    console.log('\nобеспечивает требования:');
    for (const q of reqs)
      console.log(`  ${q.id} — ${short(q.title, 70)}` +
        (q.others.length ? ` (кроме него: ${q.others.join(', ')})` : '  ← других исполнителей нет: без него требование останется без покрытия'));
  }
  if (r.units.length) {
    console.log('\nгде описано:');
    for (const u of r.units) console.log(`  ${u.where}`);
  }
  console.log(`\nподробнее: arch entity ${r.entity.id} · arch ask "${r.entity.title}"`);
}

function cmdEntity() {
  const { g } = graphOf();
  const id = pos[0];
  if (!id) {
    const list = [...g.entities.values()].filter(e => !e.implicit && e.type !== 'tab').sort((a, b) => (b.tabs ? b.tabs.size : 0) - (a.tabs ? a.tabs.size : 0));
    if (flags.json) return console.log(JSON.stringify(list.map(e => ({ id: e.id, title: e.title, type: e.type, owner: e.owner, tabs: [...(e.tabs || [])] })), null, 2));
    console.log(`реестр сущностей: ${list.length} (типы: ${ENTITY_TYPES.join(', ')})`);
    for (const e of list) console.log(`  ${e.id.padEnd(18)} ${String(e.type).padEnd(11)} ${short(e.title, 46).padEnd(48)} вкладок: ${(e.tabs || new Set()).size}`);
    console.log('\nкарточка: arch entity <id> · влияние: arch impact <id>');
    return;
  }
  const e = g.entities.get(g.alias.get(id) || id);
  if (!e) die(`сущность «${id}» не найдена (реестр: arch entity)`);
  const out = g.edges.filter(x => x.from === e.id), inc = g.edges.filter(x => x.to === e.id);
  if (flags.json) return console.log(JSON.stringify({ id: e.id, title: e.title, type: e.type, owner: e.owner,
    data_class: e.data_class, aliases: e.aliases, implicit: e.implicit, tabs: [...(e.tabs || [])],
    outgoing: out, incoming: inc, occurrences: e.occurrences }, null, 2));
  console.log(`${e.id} — ${e.title}`);
  console.log(`тип: ${e.type}${e.owner ? ' · владелец: ' + e.owner : ''}${e.data_class ? ' · класс данных: ' + e.data_class : ''}`);
  if (e.aliases.length) console.log('другие имена в схемах: ' + e.aliases.join(', '));
  console.log(`вкладки: ${[...(e.tabs || [])].join(', ') || '—'}`);
  console.log(`связей: ${out.length} исходящих, ${inc.length} входящих`);
  if (out.length) console.log('  → ' + out.slice(0, 10).map(x => `${x.to.replace(/^tab:/, '')}${x.label ? ' «' + short(x.label, 30) + '»' : ''}`).join(', '));
  if (inc.length) console.log('  ← ' + inc.slice(0, 10).map(x => `${x.from.replace(/^tab:/, '')}${x.label ? ' «' + short(x.label, 30) + '»' : ''}`).join(', '));
  if (e.occurrences.length) {
    console.log('\nгде нарисовано:');
    for (const o of e.occurrences) console.log(`  ${o.tab} › ${o.nodeId}: ${short(o.label, 60)}`);
  }
}

function cmdPath() {
  const [a, b] = pos;
  if (!a || !b) die('использование: path <id> <id>');
  const { g } = graphOf();
  const from = g.alias.get(a) || a, to = g.alias.get(b) || b;
  if (!g.entities.has(from)) die(`не найдено: ${a}`);
  if (!g.entities.has(to)) die(`не найдено: ${b}`);
  const adj = new Map();
  for (const e of g.edges) {
    if (!adj.has(e.from)) adj.set(e.from, []);
    if (!adj.has(e.to)) adj.set(e.to, []);
    adj.get(e.from).push({ id: e.to, edge: e });
    adj.get(e.to).push({ id: e.from, edge: e });
  }
  const prev = new Map([[from, null]]);
  const queue = [from];
  while (queue.length) {
    const cur = queue.shift();
    if (cur === to) break;
    for (const n of adj.get(cur) || []) if (!prev.has(n.id)) { prev.set(n.id, { id: cur, edge: n.edge }); queue.push(n.id); }
  }
  if (!prev.has(to)) return console.log(`связи между ${a} и ${b} нет: объекты в разных частях графа. Проверьте реестр (arch entity) и aliases.`);
  const chain = [];
  for (let cur = to; cur; cur = prev.get(cur) ? prev.get(cur).id : null) chain.push(cur);
  chain.reverse();
  console.log(`цепочка связей: ${plural(chain.length - 1, ['шаг', 'шага', 'шагов'])}`);
  chain.forEach((id, i) => {
    const e = g.entities.get(id);
    const step = i === 0 ? '' : (() => { const p = prev.get(id).edge; return `  --[${p.type}${p.label ? ': ' + short(p.label, 40) : ''}]-->  `; })();
    console.log(step + (i === 0 ? '' : '') + `${id}${e ? ' — ' + short(e.title, 60) : ''}`);
  });
}

function cmdHypo() {
  const model = load();
  const list = model.hypotheses || [];
  if (!list.length) die('гипотез нет: добавьте arch/hypotheses/NN-*.yaml (id, title, status, question)');
  const id = pos[0];
  if (id) {
    const h = list.find(x => String(x.id).toLowerCase() === id.toLowerCase());
    if (!h) die(`гипотеза «${id}» не найдена (arch hypo — список)`);
    if (flags.json) return console.log(JSON.stringify(h, null, 2));
    console.log(`${h.id} — ${h.title}`);
    console.log(`статус: ${HYPOTHESIS_LABEL[h.status] || h.status}${h.date ? ' · ' + h.date : ''}${h.owner ? ' · владелец: ' + h.owner : ''}`);
    console.log(`\nвопрос: ${h.question}`);
    if (h.assumptions && h.assumptions.length) { console.log('\nдопущения:'); h.assumptions.forEach(a => console.log('  - ' + a)); }
    if (h.options && h.options.length) {
      console.log('\nварианты:');
      for (const o of h.options) {
        console.log(`  ${o.id}. ${o.title}`);
        if (o.pros) console.log('     плюсы: ' + o.pros);
        if (o.cons) console.log('     минусы: ' + o.cons);
        if (o.cost) console.log('     стоимость: ' + o.cost);
        if (o.risk) console.log('     риск: ' + o.risk);
      }
    }
    if (h.evidence && h.evidence.length) { console.log('\nчем проверяем: '); h.evidence.forEach(ev => console.log(`  ${ev.kind || '—'}${ev.date ? ' ' + ev.date : ''}: ${ev.note}`)); }
    if (h.verdict) console.log('\nитог: ' + h.verdict); else console.log('\nитог не зафиксирован — гипотеза открыта.');
    if (h.links && h.links.length) console.log('\nгде смотреть: ' + h.links.join(', '));
    return;
  }
  const status = flags.status;
  const rows = status ? list.filter(h => h.status === status) : list;
  if (flags.json) return console.log(JSON.stringify(rows, null, 2));
  console.log(`гипотез: ${rows.length}` + (status ? ` (статус ${status})` : '') + `; статусы: ${HYPOTHESIS_STATUS.map(s => HYPOTHESIS_LABEL[s]).join(' · ')}`);
  for (const h of rows) console.log(`  ${String(h.id).padEnd(7)} ${HYPOTHESIS_LABEL[h.status] || h.status}`.padEnd(28) + `${short(h.title, 70)}`);
  console.log('\nподробнее: arch hypo <id> · влияние: arch impact <id>');
}


// ---------- сводка: одна страница для человека и агента ----------
function cmdStatus() {
  const model = load();
  const { problems } = compile(model);
  const g = buildGraph(model);
  const st = projectStatus(model, g, problems);
  if (flags.json) return console.log(JSON.stringify(st, null, 2));
  console.log(statusText(st));
}


// ---------- сверка с реальностью ----------
function cmdInventory() {
  const model = load();
  if (!model.inventory) die('нет arch/inventory.yaml: создайте файл со списком существующих систем (id, entity, name, kind, owner)');
  const g = buildGraph(model);
  const res = compareInventory(model, g);
  if (flags.json) return console.log(JSON.stringify({
    total: res.total, linked: res.linked, entities: res.entities,
    unknown: res.unknown.map(i => ({ id: i.id, name: i.name, owner: i.owner })),
    unconfirmed: res.unconfirmed.map(e => ({ id: e.id, title: e.title, owner: e.owner })),
  }, null, 2));
  if (flags.missing) {
    if (!res.unknown.length && !res.unconfirmed.length) { console.log('расхождений нет'); return; }
    if (res.unknown.length) {
      console.log('нет в модели (существует, но не описано):');
      res.unknown.forEach(i => console.log(`  ${i.id} — ${i.name || ''}`));
    }
    if (res.unconfirmed.length) {
      console.log('нарисовано как «сегодня», но не подтверждено инвентарём:');
      res.unconfirmed.forEach(e => console.log(`  ${e.id} — ${e.title}`));
    }
    return;
  }
  console.log(inventoryText(res, model));
}


// ---------- требования и покрытие ----------
function cmdControls() {
  const model = load();
  compile(model);
  const report = model.controlsReport || [];
  if (!report.length) die('нет arch/controls/*.yaml — требования не заведены (пример: docs/TOOLKIT.md › Привязка к требованиям)');
  const gaps = report.filter(c => !c.covered);
  const area = flags.area ? String(flags.area) : null;
  if (area && !CONTROL_AREAS.includes(area)) die(`area: ${CONTROL_AREAS.join(' | ')}`);
  let shown = flags.missing ? gaps : report;
  if (area) shown = shown.filter(c => c.area === area);
  if (flags.json) return console.log(JSON.stringify({ total: report.length, gaps: gaps.map(c => c.id), items: shown }, null, 2));
  for (const c of shown) {
    console.log(`${c.covered ? '✓' : '✗'} ${String(c.id).padEnd(7)} ${String(c.area).padEnd(12)} ${c.title}`);
    console.log(`    ${c.covered ? 'обеспечено: ' + c.satisfied_by.join(', ') : 'покрытия нет' + (c.note ? ' — ' + c.note : '')}`);
  }
  console.log(`\nтребований ${report.length} · обеспечено ${report.length - gaps.length} · без покрытия ${gaps.length}`);
  if (gaps.length) console.log('пробелы — это решения, которых ещё нет: ' + gaps.map(c => c.id).join(', '));
  else console.log('все требования чем-то обеспечены — проверьте, что доказательства ещё живы.');
}

// ---------- инварианты ----------
function cmdInvariants() {
  const model = load();
  compile(model);                     // правила проверяются при сборке модели
  const report = model.invariantsReport || [];
  if (!report.length) die('нет arch/invariants/*.yaml — правила не заведены (пример: docs/FORMAT.md › Инварианты)');
  if (flags.json) return console.log(JSON.stringify(report, null, 2));
  const label = r => (r.status === 'draft' ? 'черновик' : r.severity === 'error' ? 'ошибка' : 'мягко');
  for (const r of report) {
    const mark = r.status === 'draft' ? '·' : r.violations.length ? (r.severity === 'error' ? '✗' : '!') : '✓';
    console.log(`${mark} ${r.id}  ${String(label(r)).padEnd(9)} ${r.title}`);
    console.log(`    правило: ${r.rule} · вкладок проверено: ${r.checked} (${r.tabs.join(', ') || '—'})`);
    for (const v of r.violations) console.log(`    нарушение · ${v.title}: ${v.msg}`);
  }
  const broken = report.reduce((n, r) => n + (r.status === 'draft' ? 0 : r.violations.length), 0);
  const drafts = report.filter(r => r.status === 'draft').length;
  console.log(`\nправил ${report.length}${drafts ? ` (черновиков ${drafts})` : ''} · нарушений ${broken}`);
  if (!broken) console.log('инварианты держатся: то, чего быть не должно, на схемах не появилось.');
}

// ---------- выгрузка раздела в отдельный HTML ----------
// Собираем полную модель (все проверки — как обычно), затем оставляем в данных
// только выбранные вкладки. Так нумерация и содержимое совпадают с общей страницей.
// имя файла: латиница, цифры и дефис (кириллицу переводим, чтобы файл не ломался в почте и на порталах)
const TRANSLIT = { а: 'a', б: 'b', в: 'v', г: 'g', д: 'd', е: 'e', ё: 'e', ж: 'zh', з: 'z', и: 'i', й: 'y',
  к: 'k', л: 'l', м: 'm', н: 'n', о: 'o', п: 'p', р: 'r', с: 's', т: 't', у: 'u', ф: 'f', х: 'h', ц: 'c',
  ч: 'ch', ш: 'sh', щ: 'sch', ъ: '', ы: 'y', ь: '', э: 'e', ю: 'yu', я: 'ya' };
function slugify(text) {
  return String(text).toLowerCase().split('').map(ch => (ch in TRANSLIT ? TRANSLIT[ch] : ch)).join('')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'export';
}

function cmdExport() {
  const model = load();
  const q = pos.join(' ').trim();
  if (!q) die('использование: export <вкладка|раздел> [--out файл]\n  например: arch export tobe/04-guardrails · arch export solutions · arch export "Гипотезы"');

  const exact = new Set(), fuzzy = new Set();
  let label = '';
  for (const s of model.sections)
    if (s.dir === q || String(s.label || '').toLowerCase() === q.toLowerCase()) {
      model.tabs.filter(t => t.section === s.dir).forEach(t => exact.add(t.id));
      label = String(s.label || s.dir);
    }
  for (const t of model.tabs) {
    if (t.id === q) exact.add(t.id);
    else if (t.id.toLowerCase().includes(q.toLowerCase()) || String(t.title).toLowerCase().includes(q.toLowerCase())) fuzzy.add(t.id);
  }
  const pick = exact.size ? exact : fuzzy;
  if (!pick.size) die(`ничего не найдено по «${q}» (список: arch tabs)`);
  const keep = model.tabs.map((t, i) => (pick.has(t.id) ? i : -1)).filter(i => i >= 0);
  const tabs = keep.map(i => model.tabs[i]);
  if (!label) label = tabs.length === 1 ? tabs[0].title : `${tabs.length} вкладок`;

  const { legacy, problems } = compile(model);
  if (problems.errors.length) {
    problems.errors.slice(0, 5).forEach(e => console.error('ERR  ' + e));
    die('выгрузка отменена: в модели есть ошибки (npm run check)');
  }

  // вкладки — в исходном порядке, разделы — только те, что попали в выгрузку
  const TABS = keep.map(i => legacy.TABS[i]);
  const SECTIONS = [];
  model.sections.forEach(sec => {
    const idx = keep.filter(i => model.tabs[i].section === sec.dir);
    if (!idx.length) return;
    const from = keep.indexOf(idx[0]);
    SECTIONS.push({ label: sec.label, hidden: !!sec.hidden, locked: !!sec.locked, banner: sec.banner, from, to: from + idx.length, count: idx.length });
  });
  const firstHidden = SECTIONS.find(x => x.hidden);
  const cut = { ...legacy, TABS, SECTIONS, TOBE_COUNT: firstHidden ? firstHidden.from : TABS.length };

  const date = new Date().toISOString().slice(0, 10);
  const meta = { ...model.meta, title: `${model.meta.title} — ${label}`, subtitle: `Выдержка из модели архитектуры · ${date}` };
  let html;
  try { html = renderHtml(PROJECT, { meta }, cut, model.meta.ui && model.meta.ui.shell); }
  catch (e) { die('сборка страницы не удалась: ' + e.message); }

  const slug = String(label).toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'export';
  const out = flags.out ? path.resolve(process.cwd(), String(flags.out)) : path.join(PROJECT.dir, 'exports', `${slugify(label)}.html`);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, html, 'utf8');
  console.log(`OK  ${path.relative(process.cwd(), out)}  ${(Buffer.byteLength(html) / 1024).toFixed(0)} КБ  вкладок: ${tabs.length}`);
  console.log(`    ${tabs.map(t => t.title).join(' · ')}`);
  console.log('    Файл самодостаточный: открывается двойным кликом, без интернета и без Node.');
}

const COMMANDS = {
  tabs: cmdTabs, show: cmdShow, node: cmdNode, grep: cmdGrep, check: cmdCheck,
  'add-node': cmdAddNode, 'add-edge': cmdAddEdge,
  layout: cmdLayout, map: cmdMap, adr: cmdAdr, contract: cmdContract, snapshot: cmdSnapshot, diff: cmdDiff, init: cmdInit, profiles: cmdProfiles, paths: cmdPaths,
  status: cmdStatus, graph: cmdGraph, inventory: cmdInventory, invariants: cmdInvariants, controls: cmdControls, export: cmdExport, ask: cmdAsk, impact: cmdImpact, entity: cmdEntity, path: cmdPath, hypo: cmdHypo,
  lock: cmdLock,
};
if (!COMMANDS[cmd]) {
  const head = [];
  for (const line of fs.readFileSync(fileURLToPath(import.meta.url), 'utf8').split('\n')) {
    if (line.startsWith('//')) head.push(line.slice(3));
    else if (head.length) break;
  }
  console.log(head.join('\n'));
  process.exit(cmd ? 1 : 0);
}
COMMANDS[cmd]();
