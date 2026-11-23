// Черновая раскладка узлов схемы: проставляет at: [x, y, w] там, где координат нет
// (или пересобирает их целиком). Детерминировано: одинаковый вход -> одинаковый выход.
// Результат — черновик, а не финальная композиция: связи строятся движком автоматически.

export const DEF_W = 150;
export const GAP = 24;
export const MARGIN = 20;
const MIN_W = 90; // уже этого узла не делаем: лучше расширить холст

const subLines = n => (n && n.sub ? String(n.sub).split('\n').length : 0);
export function nodeSize(n) {
  return {
    w: Number(n?.at?.[2]) || DEF_W,
    h: Number(n?.at?.[3]) || (subLines(n) ? 34 + 12 * subLines(n) : 38),
  };
}

const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);

function parseEdge(e) {
  const key = typeof e === 'string' ? e : (e && Object.keys(e)[0]) || '';
  const m = /^(\S+)\s*(?:->|-->)\s*(\S+)$/.exec(String(key).trim());
  return m ? { f: m[1], t: m[2] } : null;
}

// рёбра: undirected — для «кто рядом»; directed — для порядка и слоёв
function adjacency(nodes, edges) {
  const nb = new Map(nodes.map(n => [n.id, new Set()]));
  for (const e of edges) { if (nb.has(e.f)) nb.get(e.f).add(e.t); if (nb.has(e.t)) nb.get(e.t).add(e.f); }
  return nb;
}
function directed(nodes, edges) {
  const out = new Map(nodes.map(n => [n.id, []]));
  const into = new Map(nodes.map(n => [n.id, []]));
  for (const e of edges) {
    if (out.has(e.f) && !out.get(e.f).includes(e.t)) { out.get(e.f).push(e.t); into.get(e.t).push(e.f); }
  }
  return { out, into };
}

// Порядок «по потоку»: топосортировка по ориентированным рёбрам; циклы — в конец.
function flowOrder(nodes, edges) {
  const { out, into } = directed(nodes, edges);
  const indeg = new Map(nodes.map(n => [n.id, into.get(n.id).length]));
  const queue = nodes.filter(n => indeg.get(n.id) === 0).map(n => n.id);
  const order = new Map();
  let i = 0;
  while (queue.length) {
    const id = queue.shift();
    if (order.has(id)) continue;
    order.set(id, i++);
    for (const m of out.get(id)) { indeg.set(m, indeg.get(m) - 1); if (indeg.get(m) <= 0 && !order.has(m)) queue.push(m); }
  }
  for (const n of nodes) if (!order.has(n.id)) order.set(n.id, i++);
  return order;
}

const rectOf = g => ({ x: g[0], y: g[1], w: g[2], h: g[3], label: g[4] });
const inRect = (p, r) => p.x >= r.x && p.x <= r.x + r.w && p.y >= r.y && p.y <= r.y + r.h;

function zoneFor(node, groups, pos, nb) {
  if (!groups.length) return -1;
  const p = pos.get(node.id);
  if (p) { const i = groups.findIndex(g => inRect(p, g)); if (i >= 0) return i; }
  const votes = new Map();
  for (const m of nb.get(node.id) || []) {
    const q = pos.get(m);
    if (!q) continue;
    const hit = groups.findIndex(g => inRect(q, g));
    if (hit >= 0) votes.set(hit, (votes.get(hit) || 0) + 1);
  }
  if (votes.size) return [...votes.entries()].sort((a, b) => b[1] - a[1])[0][0];
  if (p) {
    let best = -1, bd = Infinity;
    groups.forEach((g, i) => { const d = Math.hypot(p.x - (g.x + g.w / 2), p.y - (g.y + g.h / 2)); if (d < bd) { bd = d; best = i; } });
    return best;
  }
  return -1;
}

// Устранение перекрытий: жёсткое расталкивание, а для зажатых узлов — переселение
// в самую свободную область холста. Возвращает id, которые развести не удалось.
function separate(movable, pos, sizeOf, size, gap, anchors) {
  const clampX = (x, s) => clamp(x, s.w / 2 + 8, size[0] - s.w / 2 - 8);
  const clampY = (y, s) => clamp(y, s.h / 2 + 8, size[1] - s.h / 2 - 8);
  const fit = id => { const A = pos.get(id), s = sizeOf.get(id); A.x = clampX(A.x, s); A.y = clampY(A.y, s); };
  const tie = (a, b) => (String(a) < String(b) ? -1 : 1);
  const hit = (A, sa, B, sb) => {
    let dx = A.x - B.x, dy = A.y - B.y;
    if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) { dx = tie(A.id, B.id) * 0.5; dy = -0.5; }
    const ox = (sa.w + sb.w) / 2 + gap - Math.abs(dx);
    const oy = (sa.h + sb.h) / 2 + gap - Math.abs(dy);
    return (ox > 0 && oy > 0) ? { ox, oy, dx, dy } : null;
  };
  const push = (A, sa, axis, amount) => {
    const before = A.x + A.y;
    if (axis === 'x') A.x = clampX(A.x + Math.sign(amount) * Math.min(Math.abs(amount), 16), sa);
    else A.y = clampY(A.y + Math.sign(amount) * Math.min(Math.abs(amount), 16), sa);
    return Math.abs(A.x + A.y - before) > 0.5;
  };
  const all = [...pos.keys()];
  movable.forEach(fit);
  const sweep = rounds => {
    for (let it = 0; it < rounds; it++) {
      let moved = false;
      for (const id of movable) {
        const A = pos.get(id), sa = sizeOf.get(id);
        for (const other of all) {
          if (other === id) continue;
          const B = pos.get(other); if (!B) continue;
          const h = hit(A, sa, B, sizeOf.get(other));
          if (!h) continue;
          if (h.ox < h.oy) { if (!push(A, sa, 'x', Math.sign(h.dx || 1) * h.ox)) push(A, sa, 'y', Math.sign(h.dy || 1) * h.oy); }
          else { if (!push(A, sa, 'y', Math.sign(h.dy || 1) * h.oy)) push(A, sa, 'x', Math.sign(h.dx || 1) * h.ox); }
          moved = true;
        }
      }
      if (!moved) break;
    }
  };
  const freeSpot = (id, sa, anchor) => {
    let best = null, bestScore = Infinity;
    for (let x = sa.w / 2 + 8; x <= size[0] - sa.w / 2 - 8; x += 20) {
      for (let y = sa.h / 2 + 8; y <= size[1] - sa.h / 2 - 8; y += 20) {
        let over = 0;
        for (const other of all) {
          if (other === id) continue;
          const B = pos.get(other); if (!B) continue;
          const ox = (sa.w + sizeOf.get(other).w) / 2 + gap - Math.abs(x - B.x);
          const oy = (sa.h + sizeOf.get(other).h) / 2 + gap - Math.abs(y - B.y);
          if (ox > 0 && oy > 0) over += Math.min(ox, oy);
        }
        const score = over * 1000 + Math.hypot(x - anchor.x, y - anchor.y);
        if (score < bestScore) { bestScore = score; best = { x, y }; }
      }
    }
    return best;
  };
  const bad = () => movable.filter(id => {
    const A = pos.get(id), sa = sizeOf.get(id);
    return all.some(other => other !== id && hit(A, sa, pos.get(other), sizeOf.get(other)));
  });
  sweep(400);
  for (let round = 0; round < 4; round++) {
    const stuck = bad();
    if (!stuck.length) break;
    for (const id of stuck) {
      const A = pos.get(id), a = anchors.get(id) || { x: size[0] / 2, y: size[1] / 2 };
      const spot = freeSpot(id, sizeOf.get(id), a);
      if (spot) { A.x = spot.x; A.y = spot.y; }
    }
    sweep(120);
  }
  movable.forEach(fit);
  return bad();
}

const boxOf = (pos, sizeOf, size) => {
  const sizeOfAll = sizeOf;
  return {
    snap: () => {
      const at = {};
      for (const [id, p] of pos) at[id] = [Math.round(p.x / 5) * 5, Math.round(p.y / 5) * 5, sizeOfAll.get(id).w];
      return at;
    },
    warn: () => {
      const out = [];
      for (const [id, p] of pos) {
        const s = sizeOfAll.get(id);
        if (p.x - s.w / 2 < 0 || p.y - s.h / 2 < 0 || p.x + s.w / 2 > size[0] || p.y + s.h / 2 > size[1])
          out.push(`узел ${id} близко к границе size — увеличьте size или подвигайте вручную`);
      }
      return out;
    },
  };
};

// ---------- режим missing: расставить только узлы без at ----------
function missingLayout(nodes, edges, size, groups, opts) {
  const gap = opts.gap ?? GAP;
  const nb = adjacency(nodes, edges);
  const rects = groups.map(rectOf);
  const pos = new Map();
  for (const n of nodes) if (Array.isArray(n.at)) pos.set(n.id, { id: n.id, x: n.at[0], y: n.at[1], ...nodeSize(n) });
  const todo = nodes.filter(n => !Array.isArray(n.at));
  if (!todo.length) return { at: {}, placed: [], warnings: [], note: 'все узлы уже имеют at: нечего расставлять (добавьте --all для пересборки)' };

  const anchors = new Map();
  todo.forEach((n, i) => {
    const near = [...(nb.get(n.id) || [])].map(id => pos.get(id)).filter(Boolean);
    const zi = zoneFor(n, rects, pos, nb);
    const zone = zi >= 0 ? rects[zi] : null;
    const a = near.length
      ? { x: near.reduce((s, p) => s + p.x, 0) / near.length, y: near.reduce((s, p) => s + p.y, 0) / near.length }
      : zone ? { x: zone.x + zone.w / 2, y: zone.y + zone.h / 2 }
        : { x: size[0] / 2, y: size[1] / 2 };
    const s = nodeSize(n);
    const ang = (i / todo.length) * Math.PI * 2, r = 40 + 18 * todo.length;
    pos.set(n.id, {
      id: n.id, ...s,
      x: clamp(a.x + Math.cos(ang) * r, s.w / 2 + 8, Math.max(s.w / 2 + 8, size[0] - s.w / 2 - 8)),
      y: clamp(a.y + Math.sin(ang) * r, s.h / 2 + 8, Math.max(s.h / 2 + 8, size[1] - s.h / 2 - 8)),
    });
    anchors.set(n.id, a);
  });

  const sizeOf = new Map(nodes.map(n => [n.id, nodeSize(n)]));
  const stuck = separate(todo.map(n => n.id), pos, sizeOf, size, gap, anchors);
  const box = boxOf(pos, sizeOf, size);
  const at = box.snap();
  const trimmed = {};
  for (const n of todo) trimmed[n.id] = at[n.id];
  const warnings = box.warn().filter(w => todo.some(n => w.includes(`узел ${n.id} `)));
  for (const id of stuck) warnings.push(`узел ${id}: не удалось развести без перекрытий — увеличьте size`);
  return { at: trimmed, placed: todo.map(n => n.id), warnings, note: `размещено узлов: ${todo.length}` };
}

// ---------- режим zone: разложить по зонам (groups) ----------
function zoneLayout(nodes, edges, size, groups, opts) {
  const gap = opts.gap ?? GAP;
  const nb = adjacency(nodes, edges);
  const order = flowOrder(nodes, edges);
  const rects = groups.map(rectOf);
  const pos = new Map();
  for (const n of nodes) if (Array.isArray(n.at)) pos.set(n.id, { id: n.id, x: n.at[0], y: n.at[1] });
  // сначала зоны узлов с координатами, затем — по порядку потока: наследуем зону соседей
  const assigned = new Map();
  for (const n of nodes) if (pos.has(n.id)) assigned.set(n.id, zoneFor(n, rects, pos, nb));
  const byOrder = [...order.entries()].sort((a, b) => a[1] - b[1]).map(([id]) => id);
  for (const id of byOrder) {
    if (assigned.has(id)) continue;
    const votes = new Map();
    for (const m of nb.get(id) || []) {
      const z = assigned.get(m);
      if (z !== undefined && z >= 0) votes.set(z, (votes.get(z) || 0) + 1);
    }
    assigned.set(id, votes.size ? [...votes.entries()].sort((a, b) => b[1] - a[1])[0][0] : -1);
  }
  const buckets = new Map();
  for (const n of nodes) {
    const zi = assigned.get(n.id) ?? -1;
    if (!buckets.has(zi)) buckets.set(zi, []);
    buckets.get(zi).push(n);
  }
  const anchors = new Map();
  let outOfZone = [];
  for (const [zi, list] of buckets) {
    const zone = zi >= 0 ? rects[zi] : { x: MARGIN, y: size[1] + gap, w: size[0] - 2 * MARGIN, h: 200 };
    const allPlaced = list.every(n => Array.isArray(n.at));
    list.sort((a, b) => (allPlaced
      ? (a.at[1] - b.at[1]) || (a.at[0] - b.at[0])
      : (order.get(a.id) - order.get(b.id))) || String(a.id).localeCompare(String(b.id)));
    const rows = [];
    let row = [], rowW = 0;
    const inner = zone.w - 2 * gap;
    for (const n of list) {
      const s = nodeSize(n);
      if (row.length && rowW + gap + s.w > inner) { rows.push({ items: row, w: rowW }); row = []; rowW = 0; }
      row.push({ n, s }); rowW += (row.length > 1 ? gap : 0) + s.w;
    }
    if (row.length) rows.push({ items: row, w: rowW });
    const rowH = rows.map(r => Math.max(...r.items.map(i => i.s.h)));
    const totalH = rowH.reduce((a, b) => a + b, 0) + gap * (rows.length - 1);
    if (totalH > zone.h - 2 * gap) outOfZone.push(`зона «${zone.label ?? zi}»: узлы не влезают по высоте (${Math.round(totalH)} > ${zone.h - 2 * gap}) — увеличьте зону или size`);
    let y = zone.y + Math.max(gap, (zone.h - totalH) / 2);
    rows.forEach((r, ri) => {
      let x = zone.x + Math.max(gap, (zone.w - r.w) / 2);
      for (const { n, s } of r.items) {
        pos.set(n.id, { id: n.id, x: x + s.w / 2, y: y + rowH[ri] / 2 });
        anchors.set(n.id, { x: x + s.w / 2, y: y + rowH[ri] / 2 });
        x += s.w + gap;
      }
      y += rowH[ri] + gap;
    });
  }
  const sizeOf = new Map(nodes.map(n => [n.id, nodeSize(n)]));
  const height = Math.max(size[1], ...[...pos.values()].map(p => p.y + sizeOf.get(p.id).h / 2 + 8));
  const canvas = [size[0], Math.round(height)];
  const stuck = separate([...pos.keys()], pos, sizeOf, canvas, gap, anchors);
  const box = boxOf(pos, sizeOf, canvas);
  return {
    at: box.snap(),
    placed: nodes.map(n => n.id),
    size: canvas,
    warnings: [...outOfZone, ...box.warn(), ...stuck.map(id => `узел ${id}: не удалось развести без перекрытий — увеличьте size`)],
    note: `зон: ${buckets.size}, узлов: ${nodes.length}`,
  };
}

// ---------- режим flow: слои по потоку слева направо ----------
function flowLayout(nodes, edges, size, groups, opts) {
  const gap = opts.gap ?? GAP;
  const margin = opts.margin ?? MARGIN;
  const nb = adjacency(nodes, edges);
  const { into } = directed(nodes, edges);
  const order = flowOrder(nodes, edges);
  const byOrder = [...order.entries()].sort((a, b) => a[1] - b[1]).map(([id]) => id);
  const layer = new Map();
  for (const id of byOrder) {
    let L = 0;
    for (const p of into.get(id) || []) if (layer.has(p)) L = Math.max(L, layer.get(p) + 1);
    layer.set(id, L);
  }
  const cols = Math.max(1, Math.max(0, ...layer.values()) + 1);
  const maxW = Math.max(...nodes.map(n => nodeSize(n).w));
  // холст растём, если узлы не помещаются: иначе получим нечитаемо узкие колонки
  const width = Math.max(size[0], Math.round(cols * Math.max(MIN_W, maxW) + cols * gap + 2 * margin));
  const groupsByLayer = new Map();
  for (const n of nodes) {
    const L = layer.get(n.id) ?? 0;
    if (!groupsByLayer.has(L)) groupsByLayer.set(L, []);
    groupsByLayer.get(L).push(n);
  }
  const posOf = new Map();
  for (const [L, list] of groupsByLayer) list.forEach((n, i) => posOf.set(n.id, { L, i }));
  for (let sweep = 0; sweep < 4; sweep++) {
    const seq = [...groupsByLayer.keys()].sort((a, b) => (sweep % 2 === 0 ? a - b : b - a));
    for (const L of seq) {
      const list = groupsByLayer.get(L);
      const key = n => {
        const near = [...(nb.get(n.id) || [])].map(id => posOf.get(id)).filter(p => p && p.L !== L);
        return near.length ? near.reduce((s, p) => s + p.i, 0) / near.length : order.get(n.id);
      };
      list.sort((a, b) => key(a) - key(b) || String(a.id).localeCompare(String(b.id)));
      list.forEach((n, i) => posOf.set(n.id, { L, i }));
    }
  }
  const colW = (width - 2 * margin) / cols;
  const anchors = new Map();
  const pos = new Map();
  let height = size[1];
  for (const [L, list] of groupsByLayer) {
    const hs = list.map(n => nodeSize(n).h);
    const totalH = hs.reduce((a, b) => a + b, 0) + gap * (list.length - 1);
    height = Math.max(height, Math.round(totalH + 2 * margin));
  }
  for (const [L, list] of groupsByLayer) {
    const x0 = margin + L * colW;
    const w = Math.round(Math.min(colW - gap, ...list.map(n => nodeSize(n).w)));
    const hs = list.map(n => nodeSize(n).h);
    const totalH = hs.reduce((a, b) => a + b, 0) + gap * (list.length - 1);
    let y = Math.max(margin, (height - totalH) / 2);
    list.forEach((n, i) => {
      const cx = x0 + (colW - gap) / 2 + gap / 2;
      const cy = y + hs[i] / 2;
      pos.set(n.id, { id: n.id, x: cx, y: cy });
      anchors.set(n.id, { x: cx, y: cy });
      y += hs[i] + gap;
    });
  }
  const sizeOf = new Map(nodes.map(n => [n.id, nodeSize(n)]));
  const canvas = [width, height];
  const stuck = separate([...pos.keys()], pos, sizeOf, canvas, gap, anchors);
  const box = boxOf(pos, sizeOf, canvas);
  const warnings = [...box.warn(), ...stuck.map(id => `узел ${id}: не удалось развести без перекрытий — увеличьте size`)];
  if (width > size[0]) warnings.push(`холст расширен по ширине: ${size[0]} -> ${width} (${cols} слоёв)`);
  if (height > size[1]) warnings.push(`холст расширен по высоте: ${size[1]} -> ${height}`);
  return { at: box.snap(), placed: nodes.map(n => n.id), size: canvas, warnings, note: `слоёв: ${cols}, узлов: ${nodes.length}` };
}

/**
 * Раскладка одной схемы.
 * opts.mode: auto | missing | zone | flow; opts.all — пересобрать всё, а не только узлы без at.
 * Возвращает { mode, at: {id: [x, y, w]}, placed: [id], size?, warnings: [], note }.
 */
export function layoutScheme(S, opts = {}) {
  const nodes = (S.nodes || []).map(n => ({ ...n, id: String(n.id) })).filter(n => n.id && n.id !== 'undefined');
  const edges = (S.edges || []).map(parseEdge).filter(Boolean);
  const size = Array.isArray(S.size) && S.size.length === 2 ? [...S.size] : [1200, 600];
  const groups = Array.isArray(S.groups) ? S.groups : [];
  const mode = !opts.mode || opts.mode === 'auto'
    ? (opts.all ? (groups.length ? 'zone' : 'flow') : 'missing')
    : opts.mode;
  const run = { missing: missingLayout, zone: zoneLayout, flow: flowLayout }[mode];
  if (!run) throw new Error(`неизвестный режим раскладки: ${mode} (auto | missing | zone | flow)`);
  return { mode, ...run(nodes, edges, size, groups, opts) };
}
