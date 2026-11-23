// Сводка состояния проекта: одно место, откуда видно «что есть, как дела и что делать дальше».
// Используется командой `arch status` и MCP-инструментом arch_status.
// Внимание: модуль не должен зависеть от model.mjs — его импортирует сама модель,
// чтобы собрать вкладку «Состояние модели». Иначе получится циклический импорт.
import { graphStats, coverage } from './graph.mjs';
import { compareInventory } from './inventory.mjs';

const plural = (n, [one, few, many]) => {
  const a = Math.abs(n) % 100, b = a % 10;
  return `${n} ${a > 10 && a < 20 ? many : b === 1 ? one : b >= 2 && b <= 4 ? few : many}`;
};

export function projectStatus(model, graph, problems = { errors: [], warnings: [] }) {
  const st = graphStats(graph);
  const rows = coverage(model, graph);
  const solid = rows.filter(r => !r.process);
  const processes = rows.filter(r => r.process);
  const avg = solid.length ? Math.round(solid.reduce((s, r) => s + r.pct, 0) / solid.length) : 0;
  const weak = solid.filter(r => r.pct < 60).sort((a, b) => a.pct - b.pct);
  const hyps = model.hypotheses || [];
  const decs = model.decisions || [];
  const openHyps = hyps.filter(h => h.status === 'open');
  const proposed = decs.filter(d => d.status === 'proposed');
  // покрытие карты перехода считаем на месте: нужны только записи и компоненты
  const entries = (model.map && model.map.entries) || [];
  const mappedAsis = new Set(entries.map(e => String(e.asis)).filter(Boolean));
  const components = Object.keys(model.components || {});
  const map = { entries: entries.length, missing: components.filter(id => !mappedAsis.has(id)) };

  const inv = model.inventory ? compareInventory(model, graph) : null;
  const rules = model.invariantsReport || [];
  const ctrls = model.controlsReport || [];
  const ctrlGaps = ctrls.filter(c => !c.covered);
  const broken = rules.flatMap(r => r.violations.map(v => ({ id: r.id, title: r.title, tab: v.tab, msg: v.msg })));

  let schemes = 0, nodes = 0;
  for (const t of model.tabs)
    for (const level of ['overview', 'detail'])
      for (const b of (t[level] && t[level].blocks) || [])
        if (b && b.scheme) { schemes++; nodes += (b.scheme.nodes || []).length; }

  const next = [];
  if (problems.errors.length) next.push(`исправить ошибки модели: ${plural(problems.errors.length, ['ошибка', 'ошибки', 'ошибок'])} (npm run check)`);
  if (weak.length) next.push(`реестр: сопоставить ${plural(weak.length, ['вкладку', 'вкладки', 'вкладок'])} — ${weak.slice(0, 3).map(r => `${r.tab} ${r.pct}%`).join(', ')} (arch graph --coverage)`);
  if (openHyps.length) next.push(`гипотезы: закрыть или отложить ${openHyps.map(h => h.id).join(', ')} (arch hypo <id>)`);
  if (map.missing.length) next.push(`карта перехода: ${plural(map.missing.length, ['позиция AS-IS не покрыта', 'позиции AS-IS не покрыты', 'позиций AS-IS не покрыто'])} (arch map --missing)`);
  if (st.orphans.length) next.push(`связи: у ${plural(st.orphans.length, ['сущности', 'сущностей', 'сущностей'])} нет ни одной связи (${st.orphans.slice(0, 5).map(e => e.id).join(', ')})`);
  if (inv && inv.unknown.length) next.push(`инвентарь: ${plural(inv.unknown.length, ['система', 'системы', 'систем'])} есть в жизни, но не описана в модели (arch inventory)`);
  if (inv && inv.unconfirmed.length) next.push(`инвентарь: ${plural(inv.unconfirmed.length, ['объект нарисован', 'объекта нарисованы', 'объектов нарисованы'])} как «сегодня», но не подтверждён инвентарём (arch inventory)`);
  if (proposed.length) next.push(`решения: ${plural(proposed.length, ['предложено', 'предложено', 'предложено'])} — принять или отклонить (arch adr --status proposed)`);
  if (ctrlGaps.length) next.push(`требования: ${plural(ctrlGaps.length, ['требование', 'требования', 'требований'])} без покрытия — ${ctrlGaps.slice(0, 4).map(c => c.id).join(', ')} (arch controls --missing)`);
  if (broken.length) next.push(`инварианты: нарушено ${plural(broken.length, ['правило', 'правила', 'правил'])} — ${broken.slice(0, 3).map(b => `${b.id} на ${b.tab}`).join(', ')} (arch invariants)`);
  const hints = [...new Set(next)];
  if (!hints.length) hints.push('всё в порядке: можно строить (npm run build) и коммитить');

  return {
    title: model.meta.title,
    sections: (model.meta.sections || []).length,
    tabs: model.tabs.length, schemes, nodes,
    graph: { entities: st.entities, edges: st.edges, units: st.units,
      registry: model.entities ? model.entities.list.length : 0, orphans: st.orphans.map(e => e.id) },
    coverage: { avg, weak: weak.map(r => ({ tab: r.tab, pct: r.pct, mapped: r.mapped, total: r.total })),
      processes: processes.map(r => r.tab) },
    checks: { errors: problems.errors.length, warnings: problems.warnings.length },
    hypotheses: { total: hyps.length, open: openHyps.map(h => h.id) },
    decisions: { total: decs.length, proposed: proposed.map(d => d.id) },
    map: { entries: map.entries, missing: map.missing.length },
    inventory: inv ? { total: inv.total, unknown: inv.unknown.map(i => i.id), unconfirmed: inv.unconfirmed.map(e => e.id) } : null,
    invariants: { total: rules.length, violations: broken },
    controls: ctrls.length ? { total: ctrls.length, gaps: ctrlGaps.map(c => c.id) } : null,
    next: hints,
  };
}

export function statusText(s) {
  const lines = [
    `${s.title}   (arch/)`,
    `разделы ${s.sections} · вкладки ${s.tabs} · схем ${s.schemes} · узлов ${s.nodes}`,
    '',
    `граф: ${plural(s.graph.entities, ['сущность', 'сущности', 'сущностей'])} · ${plural(s.graph.edges, ['связь', 'связи', 'связей'])} · ${plural(s.graph.units, ['фрагмент', 'фрагмента', 'фрагментов'])}`,
    `реестр: ${plural(s.graph.registry, ['сущность', 'сущности', 'сущностей'])} · покрытие ${s.coverage.avg}%` +
      (s.coverage.processes.length ? ` · вкладок-процессов ${s.coverage.processes.length} (не оцениваются)` : ''),
  ];
  if (s.coverage.weak.length) lines.push('  слабые места: ' + s.coverage.weak.slice(0, 4).map(r => `${r.tab} ${r.pct}%`).join(' · '));
  if (s.graph.orphans.length) lines.push(`  без связей: ${s.graph.orphans.slice(0, 6).join(', ')}${s.graph.orphans.length > 6 ? '…' : ''}`);
  lines.push('', `состояние: проверки — ${s.checks.errors} ошибок, ${s.checks.warnings} предупреждений`);
  lines.push(`  гипотезы: ${s.hypotheses.total}` + (s.hypotheses.open.length ? ` (открыто ${s.hypotheses.open.length}: ${s.hypotheses.open.join(', ')})` : ''));
  lines.push(`  решения: ${s.decisions.total}` + (s.decisions.proposed.length ? ` (предложено ${s.decisions.proposed.length} — arch adr --status proposed)` : ''));
  lines.push(`  карта перехода: ${plural(s.map.entries, ['позиция', 'позиции', 'позиций'])}` + (s.map.missing ? `, не покрыто ${s.map.missing}` : ''));
  if (s.inventory) lines.push(`  инвентарь: ${plural(s.inventory.total, ['система', 'системы', 'систем'])}` +
    (s.inventory.unknown.length ? `, не описано ${s.inventory.unknown.length}` : '') +
    (s.inventory.unconfirmed.length ? `, не подтверждено ${s.inventory.unconfirmed.length}` : ''));
  if (s.invariants && s.invariants.total)
    lines.push(`  инварианты: ${plural(s.invariants.total, ['правило', 'правила', 'правил'])}` +
      (s.invariants.violations.length ? `, нарушено ${s.invariants.violations.length}` : ' — нарушений нет'));
  if (s.controls) lines.push(`  требования: ${s.controls.total}` + (s.controls.gaps.length ? ` (без покрытия ${s.controls.gaps.length}: ${s.controls.gaps.join(', ')})` : ' — все обеспечены'));
  lines.push('', 'что дальше:');
  s.next.forEach((t, i) => lines.push(`  ${i + 1}. ${t}`));
  return lines.join('\n');
}
