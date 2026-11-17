// Инварианты архитектуры: правила, которые проверяются сами.
//
// Зачем: часть требований — это не «что нарисовано», а «чего быть не должно».
// Например: «ни один вызов модели не идёт в обход gateway и DLP». Такую фразу
// нельзя проверить глазами на 38 вкладках, а как правило — можно.
//
// Файл: arch/invariants/NN-имя.yaml
//   id: INV-001
//   title: Модель вызывается только через gateway и DLP
//   rule: no-bypass | no-edge | reachable
//   severity: error | warn
//   tabs: [ tobe/03-request-path, ... ]   # id вкладок или разделы
//   from: [ p-clients ]   to: [ p-ll ]   through: [ p-gw, p-dlp ]
//   why: "одна фраза, зачем это правило"
//
// Проверяются только связи схем (`edges`), не текст и не маршруты `routes`.
// Узлы, которых нет на вкладке, пропускаются: одно правило может покрывать
// несколько вкладок с разными именами узлов.

const asList = v => (Array.isArray(v) ? v : v === undefined || v === null ? [] : [v]);
const isObj = v => v && typeof v === 'object' && !Array.isArray(v);

export const INVARIANT_RULES = ['no-bypass', 'no-edge', 'reachable'];
export const INVARIANT_KEYS = ['id', 'title', 'rule', 'severity', 'tabs', 'from', 'to', 'through', 'why', 'status', 'file'];
const ARROW = /^(\S+)\s*(->|-->)\s*(\S+)$/;

// Схема вкладки → узлы и направленные связи.
export function tabGraph(tab) {
  const nodes = new Set();
  const out = new Map(); // откуда -> куда
  const add = (f, t) => {
    if (!f || !t || f === t) return;
    if (!out.has(f)) out.set(f, new Set());
    out.get(f).add(t);
  };
  for (const level of ['overview', 'detail'])
    for (const b of asList(tab[level] && tab[level].blocks)) {
      if (!isObj(b) || !b.scheme) continue;
      for (const n of asList(b.scheme.nodes)) if (isObj(n) && n.id !== undefined) nodes.add(String(n.id));
      for (const e of asList(b.scheme.edges)) {
        if (typeof e === 'string') {
          const m = ARROW.exec(e.trim());
          if (m) add(m[1], m[3]);
          continue;
        }
        if (!isObj(e)) continue;
        if (e.f !== undefined && e.t !== undefined) { add(String(e.f), String(e.t)); continue; }
        for (const k of Object.keys(e)) {
          const m = ARROW.exec(k);
          if (m) add(m[1], m[3]);
        }
      }
    }
  return { nodes, out };
}

// Есть ли путь from → to, не заходя в запрещённые узлы.
function reachable(g, from, to, avoid) {
  if (from === to) return true;
  const seen = new Set([from]);
  const queue = [from];
  while (queue.length) {
    const cur = queue.shift();
    for (const next of g.out.get(cur) || [])
      if (!seen.has(next) && !avoid.has(next)) {
        if (next === to) return true;
        seen.add(next);
        queue.push(next);
      }
  }
  return false;
}

// Одно правило на одной вкладке → список нарушений (строки для человека).
export function checkOnTab(rule, tab) {
  const g = tabGraph(tab);
  const from = asList(rule.from).map(String).filter(id => g.nodes.has(id));
  const to = asList(rule.to).map(String).filter(id => g.nodes.has(id));
  const through = asList(rule.through).map(String).filter(id => g.nodes.has(id));
  const bad = [];
  if (!from.length || !to.length) return { bad, skipped: true };

  if (rule.rule === 'no-edge') {
    for (const a of from) for (const b of to) {
      if ((g.out.get(a) || new Set()).has(b) || (g.out.get(b) || new Set()).has(a))
        bad.push(`${a} связан напрямую с ${b}`);
    }
  } else if (rule.rule === 'no-bypass') {
    const avoid = new Set(through);
    for (const a of from) for (const b of to) {
      if (!reachable(g, a, b, new Set())) continue;          // пути вообще нет — это не обход
      if (!through.length) continue;
      if (reachable(g, a, b, avoid)) bad.push(`есть путь ${a} → ${b} в обход ${through.join(', ')}`);
    }
  } else if (rule.rule === 'reachable') {
    for (const a of from) for (const b of to) {
      if (!reachable(g, a, b, new Set())) bad.push(`из ${a} не добраться до ${b}: контур разорван`);
    }
  }
  return { bad, skipped: false };
}

export function compileInvariants(model, P) {
  const list = model.invariants || [];
  const report = [];
  const seen = new Set();
  list.forEach((rule, i) => {
    const w = `${rule.file || `invariants[${i}]`}`;
    if (!isObj(rule)) { P.err(w, 'правило — словарь {id, title, rule, tabs, from, to}'); return; }
    const extra = Object.keys(rule).filter(k => !INVARIANT_KEYS.includes(k));
    if (extra.length) P.err(w, `неизвестные ключи: ${extra.join(', ')}`);
    for (const k of ['id', 'title', 'rule'])
      if (!rule[k]) P.err(w, `нет обязательного поля ${k}`);
    if (rule.id) {
      if (seen.has(String(rule.id))) P.err(w, `дубль id "${rule.id}"`);
      seen.add(String(rule.id));
    }
    if (rule.rule && !INVARIANT_RULES.includes(rule.rule))
      P.err(w, `неизвестное правило "${rule.rule}" (${INVARIANT_RULES.join(' | ')})`);
    if (rule.status !== undefined && rule.status !== 'active' && rule.status !== 'draft')
      P.err(w, `status: active | draft (сейчас ${rule.status})`);
    const severity = rule.severity || 'error';
    if (severity !== 'error' && severity !== 'warn') P.err(w, `severity: error | warn (сейчас ${rule.severity})`);
    if (rule.rule === 'no-bypass' && !asList(rule.through).length)
      P.err(w, 'no-bypass без through: не сказано, через что обязан проходить путь');

    const wanted = asList(rule.tabs).map(String);
    const tabs = wanted.length
      ? model.tabs.filter(t => !t.generated && (wanted.includes(t.id) || wanted.includes(t.section)))
      : model.tabs.filter(t => !t.generated);
    if (wanted.length && !tabs.length) P.err(w, `tabs: не найдено ни одной вкладки (${wanted.join(', ')})`);

    const rows = [];
    let skipped = 0;
    for (const tab of tabs) {
      const r = checkOnTab(rule, tab);
      if (r.skipped) { skipped++; continue; }
      for (const msg of r.bad) rows.push({ tab: tab.id, title: tab.title, msg });
    }
    if (tabs.length && skipped === tabs.length)
      P.warn(w, `правило ни к чему не применилось: узлов ${asList(rule.from).join(', ')} → ${asList(rule.to).join(', ')} нет на выбранных вкладках`);

    const active = rule.status !== 'draft';
    if (active)
      for (const r of rows) {
        const msg = `инвариант ${rule.id} (${rule.title}) нарушен на «${r.title}»: ${r.msg}`;
        if (severity === 'error') P.err(w, msg); else P.warn(w, msg);
      }
    report.push({ id: rule.id, title: rule.title, rule: rule.rule, status: rule.status || 'active',
      severity, tabs: tabs.map(t => t.id), checked: tabs.length - skipped, violations: rows, file: rule.file });
  });
  model.invariantsReport = report;
  return report;
}
