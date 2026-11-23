// Компактная модель для агентов: без координат, без HTML, тексты в мини-Markdown.
const ARROW = /^(\S+) (->|-->) (\S+)$/;
const isObj = v => v && typeof v === 'object' && !Array.isArray(v);
const asList = v => (Array.isArray(v) ? v : v === undefined || v === null ? [] : [v]);
const clean = o => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '' && !(Array.isArray(v) && !v.length)));

function entry(item) {
  if (typeof item === 'string') return [item, undefined];
  const k = Object.keys(item)[0];
  return [k, item[k]];
}

export function agentScheme(S) {
  const nodes = asList(S.nodes).map(n => clean({ id: n.id, label: n.label, sub: n.sub, kind: n.kind || 'ext', info: n.info, placed: n.at ? undefined : false }));
  const edges = asList(S.edges).map(item => {
    const [key, val] = entry(item);
    const m = ARROW.exec(key) || [];
    const o = isObj(val) ? val : { label: val };
    return clean({ from: m[1], to: m[3], label: o.label, rel: o.rel, dashed: m[2] === '-->' ? true : undefined, kind: o.kind, tag: o.tag, both: o.both || undefined });
  });
  const routes = S.routes && Object.fromEntries(Object.entries(S.routes).map(([name, v]) => {
    const path = (isObj(v) ? v.path : v).split(/\s*->\s*/).filter(Boolean);
    return [name, isObj(v) && v.note ? { path, note: v.note } : path];
  }));
  return clean({ type: 'scheme', zones: asList(S.groups).map(g => g[4]), nodes, edges, routes, hint: S.hint });
}

export function agentSequence(Q) {
  const messages = asList(Q.messages).map((item, i) => {
    const [key, val] = entry(item);
    const m = ARROW.exec(key) || [];
    const o = isObj(val) ? val : { label: val };
    return clean({ n: i + 1, from: m[1], to: m[3], label: o.label, note: o.note, ret: m[2] === '-->' ? true : undefined });
  });
  return clean({ type: 'sequence', actors: asList(Q.actors).map(a => clean({ id: a.id, label: a.label, kind: a.kind, sub: a.sub })), messages, phases: Q.phases, hint: Q.hint });
}

export function agentBlock(b) {
  if (b.scheme) return agentScheme(b.scheme);
  if (b.sequence) return agentSequence(b.sequence);
  const type = ['table', 'cards', 'steps', 'note', 'warn', 'html', 'heading', 'capacity'].find(t => b[t] !== undefined);
  return clean({ type, title: b.title, ...(isObj(b[type]) || Array.isArray(b[type]) ? (type === 'table' ? b[type] : { items: b[type] }) : { text: b[type] === true ? undefined : b[type] }) });
}

export function toAgentModel(model) {
  const kinds = Object.fromEntries(Object.entries(model.meta.kinds).map(([id, v]) => [id, v[1]]));
  return {
    title: model.meta.title,
    note: 'Модель без координат. Источник истины — model/*.yaml (или arch/*.yaml у внешних проектов); тексты в мини-Markdown (**жирный**, `код`).',
    kinds,
    components: model.components,
    tabs: model.tabs.map(t => clean({
      id: t.id, title: t.title,
      overview: clean({ lead: t.overview?.lead, blocks: asList(t.overview?.blocks).map(agentBlock) }),
      detail: clean({ lead: t.detail?.lead, blocks: asList(t.detail?.blocks).map(agentBlock) }),
    })),
  };
}

// JSON, где глубже maxDepth всё пишется в одну строку: удобно читать и grep-ать кусками.
export function stringifyCompact(v, maxDepth = 5, depth = 0, pad = '') {
  if (depth >= maxDepth || v === null || typeof v !== 'object') return JSON.stringify(v);
  const next = pad + '  ';
  if (Array.isArray(v)) return v.length ? '[\n' + v.map(x => next + stringifyCompact(x, maxDepth, depth + 1, next)).join(',\n') + '\n' + pad + ']' : '[]';
  const keys = Object.keys(v);
  return keys.length ? '{\n' + keys.map(k => `${next}${JSON.stringify(k)}: ${stringifyCompact(v[k], maxDepth, depth + 1, next)}`).join(',\n') + '\n' + pad + '}' : '{}';
}
