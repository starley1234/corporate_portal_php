// Граф модели: сущности (компоненты), связи (потоки, зависимости, решения)
// и «единицы знания» — куски текста вкладок, по которым можно искать.
//
// Зачем это нужно:
//   1) агент спрашивает модель, а не грепает YAML: `arch ask "что делает DLP"`;
//   2) видно последствия изменения: `arch impact dlp`;
//   3) гипотезы и решения становятся частью графа, а не отдельными файлами.

const asList = v => (Array.isArray(v) ? v : v === undefined || v === null ? [] : [v]);
const isObj = v => v && typeof v === 'object' && !Array.isArray(v);
const uniq = arr => [...new Set(arr)];

const strip = html => String(html == null ? '' : html)
  .replace(/<[^>]+>/g, ' ')
  .replace(/&nbsp;/g, ' ')
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
  .replace(/\s+/g, ' ')
  .trim();

// Служебные короткие обозначения, которые встречаются в обычном тексте:
// их нельзя считать упоминанием сущности, иначе граф обрастает ложными связями.
const AMBIGUOUS = new Set(['ll', 'ow', 'cp', 'in', 'ui', 'pg', 'pr', 'gr', 'rec', 'tb', 'ut', 'os', 'ob',
  'od', 'met', 'sup', 'rt', 'dir', 'blk', 'adm', 'doc', 'log', 'mon', 'term', 'prov', 'cloud', 'cli',
  'api', 'gw', 'z0', 'z1', 'z2', 'z3', 'z4']);
const escRe = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const ARROW = /^(\S+)\s*(->|-->)\s*(\S+)$/;

export function buildGraph(model) {
  const E = new Map();          // id -> сущность
  const alias = new Map();      // alias/id узла -> канонический id
  const units = [];             // куски знания
  const edges = [];             // связи
  const addEdge = (from, to, type, label, extra) => {
    if (!from || !to || from === to) return;
    edges.push({ from, to, type, label, ...(extra || {}) });
  };
  const touch = ent => { ent.tabs = ent.tabs || new Set(); return ent; };
  const mk = (id, o) => {
    const ent = touch({ id, title: o.title || id, type: o.type || 'node', owner: o.owner, data_class: o.data_class,
      aliases: o.aliases || [], implicit: !!o.implicit, note: o.note, occurrences: [], units: [] });
    E.set(id, ent);
    alias.set(id, id);
    for (const a of ent.aliases) if (!alias.has(a)) alias.set(a, id);
    return ent;
  };

  // 1. реестр
  for (const e of asList(model.entities && model.entities.list)) mk(e.id, e);
  const resolve = key => {
    if (!key) return null;
    const k = String(key);
    const id = alias.get(k) || (model.entities && model.entities.byId[k] ? k : null);
    return id ? E.get(id) : null;
  };
  const getOrMake = (key, label, extra) => {
    const k = String(key);
    const id = alias.get(k);
    if (id) return E.get(id);
    return mk(k, { title: label || k, type: (extra && extra.type) || 'node', implicit: true });
  };

  // 2. вкладки как узлы графа
  for (const tab of model.tabs) {
    const id = `tab:${tab.id}`;
    mk(id, { title: tab.title, type: 'tab' });
    E.get(id).section = tab.section;
    E.get(id).tabId = tab.id;
    for (const f of ['uses', 'via'])
      for (const ref of asList(tab[f])) {
        const ent = getOrMake(ref, ref, { type: 'node' });
        touch(ent).tabs.add(tab.id);
        addEdge(id, ent.id, f, f === 'uses' ? 'использует' : 'опирается на');
      }
  }

  // 3. узлы схем и связи между ними
  for (const tab of model.tabs) {
    for (const level of ['overview', 'detail']) {
      const blocks = asList(tab[level] && tab[level].blocks);
      blocks.forEach((b, bi) => {
        if (!isObj(b) || !b.scheme) return;
        const S = b.scheme;
        const local = new Map();
        for (const n of asList(S.nodes)) {
          if (!isObj(n) || n.id == null) continue;
          const key = n.ref ? String(n.ref) : String(n.id);
          const ent = getOrMake(key, n.label, { type: 'node' });
          if (n.kind || n.k) ent.kind = n.kind || n.k;
          local.set(String(n.id), ent.id);
          touch(ent).tabs.add(tab.id);
          const text = [n.label, n.sub, strip(n.i)].filter(Boolean).join(' — ');
          ent.occurrences.push({ tab: tab.id, tabTitle: tab.title, section: tab.section, level, block: bi, nodeId: String(n.id), label: n.label, sub: n.sub, text });
          units.push({ id: `node:${tab.id}:${level}:${bi}:${n.id}`, kind: 'node', tab: tab.id, tabTitle: tab.title,
            section: tab.section, where: `${tab.title} › схема › ${n.label}`, text, entities: [ent.id] });
        }
        for (const item of asList(S.edges)) {
          // в модели до компиляции связь — это {«a -> b»: подпись}, после — {f, t, l}
          let f, t, label = '', dashed = false, rel = 'flow';
          if (isObj(item) && item.f !== undefined && item.t !== undefined) {
            f = item.f; t = item.t; label = item.l || ''; dashed = !!item.d; rel = item.rel || 'flow';
          } else if (isObj(item)) {
            const key = Object.keys(item)[0];
            const m = key && ARROW.exec(key);
            if (!m) continue;
            f = m[1]; t = m[3]; dashed = m[2] === '-->';
            const val = item[key];
            label = isObj(val) ? (val.label || '') : (val == null ? '' : String(val));
            rel = isObj(val) && val.rel ? String(val.rel) : 'flow';
          } else if (typeof item === 'string') {
            const m = ARROW.exec(item.trim());
            if (!m) continue;
            f = m[1]; t = m[3]; dashed = m[2] === '-->'; rel = 'flow';
          } else continue;
          const from = local.get(String(f)), to = local.get(String(t));
          if (!from || !to) continue;
          addEdge(from, to, rel || 'flow', label, { tab: tab.id, dashed });
        }
        for (const [name, v] of Object.entries(isObj(S.routes) ? S.routes : {})) {
          const raw = isObj(v) ? v.path : v;
          const path = (Array.isArray(raw) ? raw : String(raw == null ? '' : raw).split(/\s*->\s*/)).map(String).filter(Boolean);
          const note = isObj(v) ? v.note : undefined;
          const ents = uniq(path.map(p => local.get(p)).filter(Boolean));
          const labels = path.map(p => {
            const n = asList(S.nodes).find(x => String(x.id) === p);
            return n && n.label ? `${n.label}` : p;
          });
          units.push({ id: `route:${tab.id}:${name}`, kind: 'route', tab: tab.id, tabTitle: tab.title, section: tab.section,
            where: `${tab.title} › маршрут «${name}»`, text: `Маршрут «${name}»: ${labels.join(' → ')}${note ? '. ' + strip(note) : ''}`, entities: ents });
        }
      });
    }
  }

  // 4. текст вкладок: вводные абзацы и блоки — то, по чему реально ищут
  for (const tab of model.tabs) {
    const push = (kind, text, block) => {
      const t = strip(text);
      if (!t) return;
      units.push({ id: `unit:${units.length}`, kind, tab: tab.id, tabTitle: tab.title, section: tab.section,
        where: `${tab.title}${block ? ' › ' + block : ''}`, text: t, entities: [] });
    };
    push('lead', tab.overview && tab.overview.lead);
    for (const [level, src] of [['overview', tab.overview], ['detail', tab.detail]])
      asList(src && src.blocks).forEach((b, bi) => {
        if (!isObj(b)) return;
        const where = `${level === 'overview' ? 'обзор' : 'подробно'} · блок ${bi + 1}`;
        if (b.table && isObj(b.table)) {
          asList(b.table.rows).forEach((row, ri) => {
            const cells = asList(row).map(strip).filter(Boolean);
            if (!cells.length) return;
            units.push({ id: `unit:${units.length}`, kind: 'row', tab: tab.id, tabTitle: tab.title, section: tab.section,
              where: `${tab.title} › ${where} › строка ${ri + 1}`, text: cells.join(' · '), entities: [] });
          });
        } else if (b.note) push('note', b.note, where);
        else if (b.warn) push('warn', b.warn, where);
        else if (b.steps) units.push({ id: `unit:${units.length}`, kind: 'steps', tab: tab.id, tabTitle: tab.title, section: tab.section,
          where: `${tab.title} › ${where}`, text: asList(b.steps).map(strip).join(' · '), entities: [] });
        else if (b.cards) units.push({ id: `unit:${units.length}`, kind: 'cards', tab: tab.id, tabTitle: tab.title, section: tab.section,
          where: `${tab.title} › ${where}`, text: asList(b.cards).map(c => isObj(c) ? strip(c.h || c.heading) + ': ' + strip(c.t || c.text) : strip(c)).join(' · '), entities: [] });
        else if (typeof b.html === 'string') push('html', b.html, where);
        if (b.title) push('title', b.title, where);
      });
  }

  // 5. компоненты (переиспользуемые описания)
  for (const [id, text] of Object.entries(model.components || {}))
    units.push({ id: `component:${id}`, kind: 'component', where: `компонент «${id}»`, text: `${id}: ${strip(text)}`, entities: [] });

  // 6. решения и гипотезы
  for (const d of asList(model.decisions)) {
    const id = `adr:${d.id}`;
    mk(id, { title: d.title, type: 'decision' });
    E.get(id).status = d.status;
    for (const l of asList(d.links)) addEdge(id, `tab:${l}`, 'affects', 'влияет на');
    const text = [d.title, d.context, d.decision, asList(d.consequences).join(' ')].map(strip).filter(Boolean).join(' · ');
    units.push({ id, kind: 'decision', where: `журнал решений › ${d.id}`, text, entities: [] });
    E.get(id).text = text;
  }
  for (const h of asList(model.hypotheses)) {
    const id = `hyp:${h.id}`;
    mk(id, { title: h.title, type: 'hypothesis' });
    E.get(id).status = h.status;
    for (const l of asList(h.links)) addEdge(id, `tab:${l}`, 'tests', 'проверяет на');
    const text = [h.title, h.question, asList(h.assumptions).join(' '),
      asList(h.options).map(o => `${o.id}. ${o.title}: ${asList(o.pros).join(' ')}, ${asList(o.cons).join(' ')}`).join(' · '),
      asList(h.evidence).map(ev => ev.note).join(' '), h.verdict].map(strip).filter(Boolean).join(' · ');
    units.push({ id, kind: 'hypothesis', where: `гипотезы › ${h.id}`, text, entities: [] });
    E.get(id).text = text;
  }

  // 7. упоминания сущностей в тексте: сначала явные, потом текстовые
  const keys = [...alias.entries()]
    .filter(([k]) => k.length >= 3 && !AMBIGUOUS.has(k.toLowerCase()) && !/^\d+$/.test(k))
    .sort((a, b) => b[0].length - a[0].length);
  for (const u of units) {
    const ids = new Set(u.entities || []);
    const hay = ' ' + u.text + ' ';
    for (const [k, id] of keys) {
      if (id === u.id) continue;
      const re = new RegExp(`(^|[^\\p{L}\\p{N}_-])${escRe(k)}([^\\p{L}\\p{N}_-]|$)`, 'iu');
      if (re.test(hay)) ids.add(id);
      if (ids.size > 12) break;
    }
    u.entities = [...ids];
  }

  // 8. карта перехода: связи «было → стало»
  for (const e of asList(model.map && model.map.entries)) {
    if (!isObj(e)) continue;
    const fromE = resolve(e.asis), toE = resolve(e.tobe);
    if (fromE && toE) addEdge(fromE.id, toE.id, 'transition', `переход: ${e.status || '—'}`, { comment: strip(e.comment) });
  }

  // 8b. решения и гипотезы ссылаются на сущности, о которых говорят
  for (const u of units) {
    if (u.kind !== 'decision' && u.kind !== 'hypothesis') continue;
    for (const id of u.entities.slice(0, 8)) addEdge(u.id, id, 'about', 'упоминает');
  }

  // 9. обратные ссылки «сущность → единицы знания»
  const byEntity = new Map();
  for (const u of units) for (const id of u.entities) {
    if (!byEntity.has(id)) byEntity.set(id, []);
    byEntity.get(id).push(u.id);
  }
  for (const ent of E.values()) {
    ent.units = byEntity.get(ent.id) || [];
    ent.occurrences = ent.occurrences || [];
  }

  return { model, entities: E, alias, units, edges, tabs: model.tabs.map(t => ({ id: t.id, title: t.title, section: t.section })) };
}

// ---------- поиск ----------
const SUFFIX = ['иями', 'ями', 'ами', 'ого', 'ему', 'ому', 'ыми', 'ими', 'ая', 'яя', 'ое', 'ее', 'ые', 'ие',
  'ой', 'ей', 'ом', 'ем', 'ах', 'ях', 'ов', 'ев', 'ии', 'ий', 'ый', 'ия', 'ию', 'ам', 'ям', 'а', 'я', 'ы', 'и', 'е', 'у', 'ю', 'о'];
export function stem(w) {
  const s = String(w).toLowerCase().replace(/ё/g, 'е');
  if (s.length <= 4) return s;
  for (const suf of SUFFIX) if (s.length - suf.length >= 3 && s.endsWith(suf)) return s.slice(0, -suf.length);
  return s;
}
const STOP = new Set(['что', 'как', 'где', 'для', 'или', 'мне', 'нам', 'это', 'при', 'чем', 'кто', 'так', 'все', 'его', 'она', 'они', 'если', 'чтобы', 'почему', 'какой', 'какие', 'есть', 'быть', 'надо', 'нужно', 'можно', 'the', 'and', 'for', 'with']);
export function tokens(text) {
  return String(text).toLowerCase().replace(/ё/g, 'е').split(/[^\p{L}\p{N}]+/u)
    .filter(w => w.length >= 3 && !STOP.has(w)).map(stem);
}

export function searchGraph(graph, query, opts = {}) {
  const limit = opts.limit || 8;
  const qt = tokens(query);
  if (!qt.length) return { query, hits: [], entities: [] };
  const qset = uniq(qt);
  const df = new Map();
  const prepared = graph.units.map(u => {
    const tf = new Map();
    for (const t of tokens(u.text)) tf.set(t, (tf.get(t) || 0) + 1);
    const seen = new Set(tf.keys());
    for (const t of seen) df.set(t, (df.get(t) || 0) + 1);
    return { u, tf };
  });
  const N = prepared.length || 1;
  const idf = t => Math.log(1 + N / (df.get(t) || 1));
  // прямой поиск по сущностям: «DLP», «gateway», «pgvector»
  const hitEntities = [];
  for (const ent of graph.entities.values()) {
    const ids = [ent.id, ...(ent.aliases || [])].map(x => String(x).toLowerCase());
    const titleTokens = tokens(ent.title);
    let score = 0;
    for (const t of qset) {
      if (ids.some(n => n === t || stem(n) === t)) score += 3;              // «dlp» — это id
      else if (titleTokens.includes(t)) score += 1.5;                        // слово из названия
      else if (ids.some(n => n.length > 3 && (n.includes(t) || t.includes(n)))) score += 0.4;
    }
    if (!score) continue;
    if (!ent.implicit) score += 0.6;                                         // реестр важнее одноразового узла
    if (ent.type === 'tab') score -= 0.4;                                    // вкладка — контекст, а не объект
    score += Math.min(0.8, (ent.occurrences ? ent.occurrences.length : 0) * 0.04);
    hitEntities.push({ ent, score });
  }
  hitEntities.sort((a, b) => b.score - a.score || a.ent.id.localeCompare(b.ent.id));
  const topEnts = new Set(hitEntities.slice(0, 6).map(x => x.ent.id));

  const scored = prepared.map(({ u, tf }) => {
    let s = 0;
    const why = [];
    for (const t of qset) {
      if (tf.has(t)) {
        const v = idf(t) * (1 + Math.log(tf.get(t)));
        s += v;
        why.push(t);
      }
    }
    if (!s) return { u, s: 0, why };
    for (const id of u.entities) if (topEnts.has(id)) s += 1.2;
    s += u.kind === 'lead' ? 0.5 : u.kind === 'row' ? 0.15 : 0;
    s += u.kind === 'decision' || u.kind === 'hypothesis' ? 0.6 : 0;
    return { u, s, why };
  }).filter(x => x.s > 0);

  // расширение по графу: соседи сильных попаданий получают бонус
  scored.sort((a, b) => b.s - a.s);
  const seedEnts = new Set();
  for (const x of scored.slice(0, 10)) for (const id of x.u.entities) seedEnts.add(id);
  for (const e of graph.edges) {
    if (seedEnts.has(e.from)) seedEnts.add(e.to);
    if (seedEnts.has(e.to)) seedEnts.add(e.from);
  }
  for (const x of scored) {
    if (x.u.kind === 'note' || x.u.kind === 'row' || x.u.kind === 'lead')
      if (x.u.entities.some(id => seedEnts.has(id))) x.s *= 1.15;
  }
  scored.sort((a, b) => b.s - a.s);

  // не более 2 попаданий из одной вкладки в первой пятёрке — иначе ответ перекошен
  const perTab = new Map();
  const hits = [];
  for (const x of scored) {
    const n = perTab.get(x.u.tab) || 0;
    if (n >= 3) continue;
    perTab.set(x.u.tab, n + 1);
    hits.push({ ...x.u, score: Math.round(x.s * 100) / 100, why: uniq(x.why) });
    if (hits.length >= limit) break;
  }
  return { query, hits, entities: hitEntities.slice(0, 8).map(x => x.ent), terms: qset };
}

// ---------- влияние изменения ----------
export function impactOf(graph, key, opts = {}) {
  const ent = graph.entities.get(graph.alias.get(String(key)) || String(key));
  if (!ent) return null;
  const depth = opts.depth || 3;
  const out = graph.edges.filter(e => e.from === ent.id);
  const inc = graph.edges.filter(e => e.to === ent.id);
  const seen = new Set([ent.id]);
  const waves = [];
  let cur = [ent.id];
  for (let d = 1; d <= depth; d++) {
    const next = [];
    for (const id of cur) for (const e of graph.edges) {
      if (e.to !== id || seen.has(e.from)) continue;
      if (!['flow', 'transition', 'via', 'uses'].includes(e.type)) continue;
      const src = graph.entities.get(e.from);
      if (!src || src.type === 'tab' || src.type === 'decision' || src.type === 'hypothesis') continue;
      seen.add(e.from);
      next.push(e.from);
    }
    if (!next.length) break;
    waves.push({ depth: d, entities: uniq(next).map(id => graph.entities.get(id)) });
    cur = uniq(next);
  }
  const tabs = uniq([...(ent.tabs || []), ...out.filter(e => e.to.startsWith('tab:')).map(e => e.to.slice(4))]);
  return { entity: ent, occurrences: ent.occurrences, outgoing: out, incoming: inc, dependents: waves, tabs,
    units: ent.units.slice(0, 8).map(id => graph.units.find(u => u.id === id)).filter(Boolean) };
}

export function graphStats(graph) {
  const byType = {};
  for (const e of graph.entities.values()) byType[e.type] = (byType[e.type] || 0) + 1;
  const byEdge = {};
  for (const e of graph.edges) byEdge[e.type] = (byEdge[e.type] || 0) + 1;
  const implicit = [...graph.entities.values()].filter(e => e.implicit);
  const orphans = [...graph.entities.values()].filter(e => e.type !== 'tab' && e.type !== 'decision' && e.type !== 'hypothesis'
    && !graph.edges.some(x => x.from === e.id || x.to === e.id));
  return { entities: graph.entities.size, edges: graph.edges.length, units: graph.units.length,
    byType, byEdge, implicit: implicit.length, orphans };
}

// Шаги процесса, развилки и ошибки — не компоненты: их не нужно сопоставлять с реестром.
export const PROCESS_KINDS = new Set(['dec', 'err', 'ok']);
const STEP_ID = /^[a-z]{1,3}\d+$/i;
export function coverage(model, graph) {
  const rows = [];
  for (const tab of model.tabs) {
    let total = 0, mapped = 0, refs = 0, skipped = 0;
    for (const level of ['overview', 'detail'])
      for (const b of asList(tab[level] && tab[level].blocks)) {
        if (!isObj(b) || !b.scheme) continue;
        for (const n of asList(b.scheme.nodes)) {
          if (!isObj(n)) continue;
          if (!n.ref && PROCESS_KINDS.has(n.kind)) { skipped++; continue; }
          total++;
          if (n.ref) { refs++; mapped++; continue; }
          const id = graph.alias.get(String(n.id));
          if (id && !graph.entities.get(id).implicit) mapped++;
        }
      }
    if (total) {
      // вкладка-процесс: узлы — шаги (s1, i3, f7), а не компоненты; такие вкладки не оцениваем
      const steps = asList(tab.overview && tab.overview.blocks).concat(asList(tab.detail && tab.detail.blocks))
        .filter(b => isObj(b) && b.scheme).flatMap(b => asList(b.scheme.nodes))
        .filter(n => isObj(n) && STEP_ID.test(String(n.id)));
      const process = total >= 4 && steps.length / total >= 0.6;
      rows.push({ tab: tab.id, title: tab.title, total, mapped, refs, skipped, process,
        pct: process ? null : Math.round(100 * mapped / total) });
    }
  }
  return rows;
}

// Граф для глаз: Graphviz (dot -Tsvg model.dot -o model.svg) или Obsidian/Neo4j.
export function toDot(graph, opts = {}) {
  const color = { control: '#dc2626', store: '#7c3aed', service: '#2563eb', channel: '#0891b2', model: '#059669', external: '#64748b', decision: '#b45309', hypothesis: '#c026d3', tab: '#94a3b8' };
  const skipTabs = opts.noTabs !== false;
  const q = s => '"' + String(s).replace(/"/g, '\\"') + '"';
  const lines = ['digraph model {', '  rankdir=LR;', '  node [shape=box, style="rounded,filled", fontname="Helvetica", fillcolor="#f8fafc"];'];
  for (const e of graph.entities.values()) {
    if (skipTabs && e.type === 'tab') continue;
    const c = color[e.type] || '#e2e8f0';
    lines.push(`  ${q(e.id)} [label=${q(e.title)}, color=${q(c)}, penwidth=1.4];`);
  }
  for (const e of graph.edges) {
    if (skipTabs && (e.from.startsWith('tab:') || e.to.startsWith('tab:'))) continue;
    if (['affects', 'about'].includes(e.type) && opts.withNotes !== true) continue;
    lines.push(`  ${q(e.from)} -> ${q(e.to)} [label=${q(e.label || e.type)}, fontsize=9, color="#94a3b8"];`);
  }
  lines.push('}');
  return lines.join('\n');
}

// Экспорт в Neo4j / любую Cypher-совместимую базу — на случай, если граф вырастет.
// Повторный запуск безопасен: узлы и связи создаются через MERGE.
export function toCypher(graph, opts = {}) {
  const q = s => JSON.stringify(String(s == null ? '' : s));
  const rel = t => String(t || 'related').toLowerCase().replace(/[^a-z0-9_]/g, '_').toUpperCase() || 'RELATED';
  const nodes = [...graph.entities.values()];
  const edges = graph.edges.filter(e => opts.notes || !['about', 'affects'].includes(e.type));
  const head = [
    `// Граф модели${opts.title ? ': ' + opts.title : ''}${opts.date ? ' · ' + opts.date : ''}`,
    `// узлов ${nodes.length}, связей ${edges.length}. Загрузка: cypher-shell -f <файл>`,
    'CREATE CONSTRAINT entity_id IF NOT EXISTS FOR (n:Entity) REQUIRE n.id IS UNIQUE;',
  ];
  const nodeLines = nodes.map(e => {
    const props = [['id', e.id], ['title', e.title], ['type', e.type]];
    if (e.owner) props.push(['owner', e.owner]);
    if (e.data_class) props.push(['data_class', e.data_class]);
    if (e.status) props.push(['status', e.status]);
    if (e.implicit) props.push(['implicit', true]);
    const sets = props.slice(1).map(([k, v]) => `n.${k} = ${typeof v === 'boolean' ? String(v) : q(v)}`).join(', ');
    return `MERGE (n:Entity {id: ${q(e.id)}}) SET ${sets};`;
  });
  const edgeLines = edges.map(e =>
    `MATCH (a:Entity {id: ${q(e.from)}}), (b:Entity {id: ${q(e.to)}}) ` +
    `MERGE (a)-[:${rel(e.type)} {label: ${q(e.label || '')}}]->(b);`);
  return [...head, ...nodeLines, ...edgeLines].join('\n');
}

// Сколько узлов и связей в готовом экспорте: проверка «числа совпадают» без Neo4j.
export function cypherCounts(text) {
  const lines = String(text).split('\n');
  return {
    nodes: lines.filter(l => l.startsWith('MERGE (n:Entity')).length,
    edges: lines.filter(l => l.startsWith('MATCH (a:Entity')).length,
  };
}
