#!/usr/bin/env node
// Одноразовая миграция: reference/architecture_consistent_v1.html -> arch/*.yaml.
// Затем проверяет, что YAML компилируется в те же данные, что были в исходном файле.
// Перезаписывает arch/{meta,components}.yaml, arch/tobe/, arch/asis/ — запускать только для чистой пересоздачи.

import fs from 'node:fs';
import path from 'node:path';
import YAML from 'yaml';
import { fileURLToPath } from 'node:url';
import { evalBaseline } from './lib/baseline.mjs';
import { toMd, roundTrips } from './lib/mdlite.mjs';
import { loadModel, compile } from './lib/model.mjs';
import { deepDiff } from './lib/diff.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REF = path.join(ROOT, 'reference', 'architecture_consistent_v1.html');
const ARCH = path.join(ROOT, 'arch');

const TOBE_SLUGS = ['platform', 'lifecycle', 'request-path', 'guardrails', 'layers', 'services-nodes', 'models-egress', 'ocr-rag-tools', 'network-security', 'control-audit', 'vm-sizing', 'migration-plan'];
const ASIS_SLUGS = ['context', 'containers', 'request-path', 'lifecycle', 'models-egress', 'networks', 'config-management', 'sandboxes', 'security', 'data', 'stack', 'deployment', 'observability', 'tools', 'platform-changes'];

if (!process.argv.includes('--overwrite-arch')) {
  console.error('Одноразовая миграция из reference/*.html. Она ПЕРЕЗАПИШЕТ arch/ (все ваши правки YAML). Для запуска добавьте --overwrite-arch');
  process.exit(1);
}

const { TABS, K, KN, D, REG, banner, page } = evalBaseline(REF, { instrument: true });
const golden = evalBaseline(REF);
const notes = [];
const note = (w, m) => notes.push(`${w}: ${m}`);
const must = (c, w, m) => { if (!c) throw new Error(`${w}: ${m}`); };

// ---------- текст ----------
const mdc = (s, w) => { if (typeof s !== 'string') return s; if (!roundTrips(s)) { note(w, 'текст не переводится в мини-Markdown без потерь, оставлен HTML'); return s; } return toMd(s); };
const Y = '<span class="y">●</span>', P = '<span class="p">◐</span>';
const cellOut = (c, w) => (c === Y ? '●' : c === P ? '◐' : /^(0|[1-9]\d{0,8})$/.test(c) ? Number(c) : mdc(String(c), w));
const noTok = (s, w) => { must(!/[\u0001\u0002]/.test(s), w, 'в тексте остался маркер инструментирования'); return s; };

// ---------- схема ----------
function convScheme(S, w) {
  const allowed = ['vb', 'groups', 'nodes', 'edges', 'routes', 'ri', 'hint', 'host', 'notes', 'toggles'];
  for (const k of Object.keys(S)) must(allowed.includes(k), w, 'неожиданный ключ схемы ' + k);
  const o = { size: S.vb };
  if (S.host) o.host = [S.host.x, S.host.y, S.host.w, S.host.h, S.host.label];
  if (S.groups) o.groups = S.groups.map(g => [g.x, g.y, g.w, g.h, g.label]);
  if (S.notes) o.notes = S.notes.map(n => (n.a ? [n.x, n.y, n.t, n.a] : [n.x, n.y, n.t]));
  o.nodes = S.nodes.map(n => {
    const nw = `${w} › узел ${n.id}`;
    for (const k of Object.keys(n)) must(['id', 'x', 'y', 'w', 'h', 'label', 'sub', 'k', 'i', 'stack', 'dash', 'pill'].includes(k), nw, 'неожиданное поле ' + k);
    must(n.h === undefined || n.w !== undefined, nw, 'h без w');
    for (const f of ['stack', 'dash', 'pill']) must(n[f] === undefined || n[f] === 1, nw, f + ' не равно 1');
    const r = { id: n.id, at: [n.x, n.y, ...(n.w !== undefined ? [n.w] : []), ...(n.h !== undefined ? [n.h] : [])], label: n.label };
    if (n.sub) r.sub = n.sub;
    if (n.k) r.kind = n.k;
    if (n.i) r.info = mdc(n.i.replace(/\u0002(\w+)\u0002/g, '{$1}'), nw);
    for (const f of ['stack', 'dash', 'pill']) if (n[f]) r[f] = true;
    noTok(r.label, nw);
    return r;
  });
  o.edges = S.edges.map((e, i) => {
    const ew = `${w} › связь ${e.f}>${e.t}`;
    for (const k of Object.keys(e)) must(['f', 't', 'l', 'd', 'k', 'fs', 'ts', 'pts', 'lp', 'both', 'cat'].includes(k), ew, 'неожиданное поле ' + k);
    must(e.d === undefined || e.d === 1, ew, 'd не равно 1');
    must(e.both === undefined || e.both === 1, ew, 'both не равно 1');
    const key = `${e.f} ${e.d ? '-->' : '->'} ${e.t}`;
    const opt = {};
    if (e.l) opt.label = e.l;
    if (e.k) opt.kind = e.k;
    if (e.fs) opt.out = e.fs;
    if (e.ts) opt.in = e.ts;
    if (e.pts) opt.via = e.pts;
    if (e.lp) opt.label_at = e.lp;
    if (e.both) opt.both = true;
    if (e.cat) opt.tag = e.cat;
    const keys = Object.keys(opt);
    if (!keys.length) return key;
    if (keys.length === 1 && keys[0] === 'label') return { [key]: opt.label };
    return { [key]: opt };
  });
  if (S.routes) {
    o.routes = {};
    for (const [name, list] of Object.entries(S.routes)) {
      for (const id of list) must(/^\S+$/.test(id), w, 'id с пробелом в маршруте');
      const p = list.join(' -> ');
      const ri = S.ri && S.ri[name];
      o.routes[name] = ri ? { path: p, note: mdc(ri, `${w} › маршрут ${name}`) } : p;
    }
    for (const name of Object.keys(S.ri || {})) must(S.routes[name], w, 'ri для несуществующего маршрута ' + name);
  } else must(!S.ri, w, 'ri без routes');
  if (S.hint) o.hint = mdc(S.hint, w + ' › hint');
  if (S.toggles) {
    o.toggles = {};
    for (const [k, v] of Object.entries(S.toggles)) {
      must(Object.keys(v).every(x => ['l', 'on'].includes(x)) && (v.on === undefined || v.on === false), w, 'неожиданный toggle');
      o.toggles[k] = v.on === false ? { label: v.l, on: false } : v.l;
    }
  }
  return o;
}

function convSeq(Q, w) {
  for (const k of Object.keys(Q)) must(['w', 'px', 'actors', 'msgs', 'ph', 'hint'].includes(k), w, 'неожиданный ключ ' + k);
  const o = {};
  if (Q.w) o.width = Q.w;
  if (Q.px) o.px = Q.px;
  o.actors = Q.actors.map(a => { for (const k of Object.keys(a)) must(['id', 'label', 'k', 'sub'].includes(k), w, 'actor ' + k); return { id: a.id, label: a.label, ...(a.k ? { kind: a.k } : {}), ...(a.sub ? { sub: a.sub } : {}) }; });
  o.messages = Q.msgs.map(m => {
    for (const k of Object.keys(m)) must(['f', 't', 'l', 'd', 'ret'].includes(k), w, 'msg ' + k);
    must(m.ret === undefined || m.ret === 1, w, 'ret не 1');
    const key = `${m.f} ${m.ret ? '-->' : '->'} ${m.t}`;
    return m.d ? { [key]: { label: m.l, note: mdc(m.d, w) } } : { [key]: m.l };
  });
  if (Q.ph) o.phases = Q.ph.map(p => [p.a + 1, p.b + 1, p.l]);
  if (Q.hint) o.hint = mdc(Q.hint, w + ' › hint');
  return o;
}

// ---------- блок html -> типизированный блок ----------
function convHtml(html, w) {
  const full = html;
  let title, m = /^<h3>(.*?)<\/h3>/.exec(html);
  if (m) { title = mdc(m[1], w); html = html.slice(m[0].length); }
  const wrap = o => ({ ...(title !== undefined ? { title } : {}), ...o });
  if ((m = /^\u0001T(\d+)\u0001$/.exec(html))) {
    const r = REG[+m[1]];
    const o = { head: r.head.map(c => cellOut(c, w)), rows: r.rows.map(row => row.map(c => cellOut(c, w))) };
    if (r.cls) {
      must(r.cls.length === r.head.length && r.cls.every(c => c === '' || c === 'c'), w, 'нестандартные классы колонок');
      o.center = r.cls.map((c, i) => (c === 'c' ? i : -1)).filter(i => i >= 0);
    }
    return wrap({ table: o });
  }
  const card = (t, col, text, chips) => ({ title: mdc(t, w), ...(text ? { text: mdc(text, w) } : {}), ...(col !== '#2563eb' ? { color: col } : {}), ...(chips ? { chips: chips.map(x => mdc(x, w)) } : {}) });
  if ((m = /^<div class="cards">((?:\u0001C\d+\u0001)+)<\/div>$/.exec(html))) {
    return wrap({ cards: [...m[1].matchAll(/\u0001C(\d+)\u0001/g)].map(x => { const c = REG[+x[1]]; return card(c.heading, c.col, c.text); }) });
  }
  if ((m = /^\u0001K(\d+)\u0001$/.exec(html))) {
    return wrap({ cards: REG[+m[1]].a.map(([t, c, items, txt]) => card(t, c, txt, items)) });
  }
  if ((m = /^<div class="cards">(.*)<\/div>$/.exec(html)) && m[1].startsWith('<div class="card" style="--c:') && !/\u0001/.test(m[1])) {
    const items = m[1].split(/(?<=<\/div>)(?=<div class="card" style="--c:)/).map(piece => {
      const c = /^<div class="card" style="--c:([^"]+)"><h4>(.*?)<\/h4>([\s\S]*)<\/div>$/.exec(piece);
      if (!c) return null;
      const ch = /^(?:<div style="margin-bottom:6px">(.*?)<\/div>)?<div class="chips">((?:<span class="chip">.*?<\/span>)*)<\/div>$/.exec(c[3]);
      if (ch) return card(c[2], c[1], ch[1], [...ch[2].matchAll(/<span class="chip">(.*?)<\/span>/g)].map(x => x[1]));
      return card(c[2], c[1], c[3]);
    });
    if (items.every(Boolean)) return wrap({ cards: items });
  }
  if ((m = /^<ol class="steps">\n?((?:<li>.*?<\/li>\n?)*)<\/ol>$/.exec(html))) {
    return wrap({ steps: [...m[1].matchAll(/<li>(.*?)<\/li>/g)].map(x => mdc(x[1], w)) });
  }
  if ((m = /^<div class="lead( warn)?"(?: style="([^"]*)")?>([\s\S]*)<\/div>$/.exec(html)) && !/<div|<\/div>/.test(m[3])) {
    return wrap({ [m[1] ? 'warn' : 'note']: mdc(m[3], w), ...(m[2] ? { style: m[2] } : {}) });
  }
  note(w, 'блок не распознан, оставлен как html');
  return { html: noTok(full, w) };
}

function convBlock(B, w) {
  if (B.t === 'scheme') return { scheme: convScheme(B.S, w) };
  if (B.t === 'seq') return { sequence: convSeq(B.Q, w) };
  if (B.t === 'capacity') return { capacity: true };
  if (B.t === 'h') return { heading: B.text };
  must(B.t === 'html', w, 'неизвестный тип блока ' + B.t);
  return convHtml(B.html, w);
}

// ---------- вкладки ----------
const slugOf = (section, n) => `${String(n).padStart(2, '0')}-${(section === 'tobe' ? TOBE_SLUGS : ASIS_SLUGS)[n - 1]}`;
const bannerMd = (banner || '').replace(/ $/, '');
must(banner && banner.endsWith(' '), 'banner', 'не найден баннер AS-IS');

const out = [];
let nT = 0, nA = 0;
for (const T of TABS) {
  let m, section, n, title;
  if ((m = /^TO-BE · (\d\d)\. (.*)$/.exec(T.t))) { section = 'tobe'; n = ++nT; title = m[2]; must(+m[1] === n, T.t, 'нумерация'); }
  else if ((m = /^AS-IS · (\d+)\. (.*)$/.exec(T.t))) { section = 'asis'; n = ++nA; title = m[2]; must(+m[1] === n, T.t, 'нумерация'); }
  else throw new Error('неизвестный заголовок вкладки ' + T.t);
  const file = `${section}/${slugOf(section, n)}.yaml`;
  const view = (V, label) => {
    let lead = V.lead;
    if (section === 'asis') { must(lead.startsWith(banner), file, 'нет баннера AS-IS в lead'); lead = lead.slice(banner.length); }
    return { ...(lead ? { lead: mdc(lead, `${file} › ${label} › lead`) } : {}), blocks: V.blocks.map((B, i) => convBlock(B, `${file} › ${label} › blocks[${i}]`)) };
  };
  out.push({ file, section, title, data: { title, overview: view(T.o, 'overview'), detail: view(T.d, 'detail') } });
}

// ---------- запись YAML ----------
const keyOf = p => String(p.key?.value ?? p.key);
const nearest = path => { for (let i = path.length - 1; i >= 0; i--) if (YAML.isPair(path[i])) return keyOf(path[i]); };
const chain = path => path.filter(YAML.isPair).map(keyOf);
function dump(obj, header) {
  const doc = new YAML.Document(obj);
  YAML.visit(doc, {
    Scalar(_, node) { if (typeof node.value === 'string' && node.value.includes('\n')) node.type = 'QUOTE_DOUBLE'; },
    Seq(_, node, path) { if (node.items.every(YAML.isScalar) || nearest(path) === 'via') node.flow = true; },
    Map(_, node, path) {
      const parent = path[path.length - 1], pk = nearest(path), ch = chain(path);
      if (YAML.isSeq(parent) && (pk === 'nodes' || pk === 'actors')) node.flow = true;
      else if (YAML.isPair(parent) && / --?> /.test(keyOf(parent)) && ch.includes('edges')) node.flow = true;
      else if (YAML.isPair(parent) && ch.includes('toggles')) node.flow = true;
    },
  });
  doc.commentBefore = header;
  return doc.toString({ lineWidth: 0, blockQuote: false, minContentWidth: 0 });
}

for (const d of ['tobe', 'asis']) fs.rmSync(path.join(ARCH, d), { recursive: true, force: true });
fs.mkdirSync(path.join(ARCH, 'tobe'), { recursive: true });
fs.mkdirSync(path.join(ARCH, 'asis'), { recursive: true });
const hdr = (t, s) => ` ${s === 'tobe' ? 'TO-BE' : 'AS-IS'} · ${t}\n Формат: docs/FORMAT.md. Сборка: npm run build`;
for (const o of out) fs.writeFileSync(path.join(ARCH, o.file), dump(o.data, hdr(o.title, o.section)));

const kinds = {};
for (const id of Object.keys(K)) kinds[id] = [K[id], KN[id]];
fs.writeFileSync(path.join(ARCH, 'meta.yaml'), dump({ title: page.title, heading: page.heading, subtitle: page.subtitle, asis_banner: mdc(bannerMd, 'banner'), kinds }, ' Общие настройки страницы и палитра типов узлов (kinds: id -> [цвет, название])'));
const comps = {};
for (const [id, v] of Object.entries(D)) comps[id] = mdc(v, `components.${id}`);
fs.writeFileSync(path.join(ARCH, 'components.yaml'), dump(comps, ' Описания компонентов (показываются по клику на узел; id совпадает с id узла или подключается через info: "{id}")'));

// ---------- проверка: YAML -> данные страницы == исходные данные ----------
// отличия, не видимые на странице: пустой sub и переводы строк между <li> в списках шагов
const norm = v => JSON.parse(JSON.stringify(v, (k, x) => (k === 'sub' && x === '' ? undefined : typeof x === 'string' ? x.replace(/(<ol class="steps">|<\/li>)\n(?=<li>)/g, '$1') : x)));
const { legacy, problems } = compile(loadModel(ARCH));
let bad = 0;
const diffs = [];
for (const [name, a, b] of [['K', legacy.K, golden.K], ['KN', legacy.KN, golden.KN], ['D', legacy.D, golden.D], ['TABS', norm(legacy.TABS), norm(golden.TABS)]]) {
  const d = deepDiff(a, b, name);
  bad += d.length; diffs.push(...d.slice(0, 10));
}
if (legacy.TOBE_COUNT !== golden.TABS.filter(t => t.t.startsWith('TO-BE')).length) { bad++; diffs.push('TOBE_COUNT'); }

console.log(`вкладок: ${out.length}, файлов YAML: ${out.length + 2}`);
if (notes.length) console.log('заметки:\n  ' + notes.join('\n  '));
console.log(`ошибок компиляции: ${problems.errors.length}, предупреждений: ${problems.warnings.length}`);
problems.errors.slice(0, 20).forEach(e => console.log('  ERR ' + e));
if (bad) { console.error(`РАСХОЖДЕНИЙ С ИСХОДНИКОМ: ${bad}`); diffs.forEach(d => console.error('  ' + d)); process.exit(1); }
console.log('OK: YAML компилируется в точно те же данные, что были в исходном HTML');
