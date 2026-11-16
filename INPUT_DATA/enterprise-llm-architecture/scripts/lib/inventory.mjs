// Сверка «модель ↔ реальность»: что нарисовано, что существует, где расхождение.
//
// Модель отвечает на вопрос «как задумано», инвентарь — «что есть на самом деле».
// Без сверки модель красиво живёт своей жизнью, а в жизни появляются системы,
// которых нет ни на одной схеме.

const asList = v => (Array.isArray(v) ? v : v === undefined || v === null ? [] : [v]);
const plural = (n, [one, few, many]) => {
  const a = Math.abs(n) % 100, b = a % 10;
  return `${n} ${a > 10 && a < 20 ? many : b === 1 ? one : b >= 2 && b <= 4 ? few : many}`;
};

export const INVENTORY_KINDS = ['service', 'store', 'model', 'external', 'network', 'job'];

// Сопоставление: явное поле entity, иначе id позиции ищется в реестре как id или alias.
export function inventoryLink(model, item) {
  if (!model.entities) return null;
  if (item.entity) return model.entities.byId[item.entity] ? item.entity : null;
  const byAlias = model.entities.alias[String(item.id)];
  return byAlias || (model.entities.byId[String(item.id)] ? String(item.id) : null);
}

export function compareInventory(model, graph) {
  const items = asList(model.inventory && model.inventory.items).filter(x => x && x.id);
  const linked = new Map();          // entityId -> [items]
  const unknown = [];                // позиции без сущности
  for (const it of items) {
    const id = inventoryLink(model, it);
    if (!id) { unknown.push(it); continue; }
    if (!linked.has(id)) linked.set(id, []);
    linked.get(id).push(it);
  }

  // что реально нарисовано как «сегодня»: узлы вкладок раздела с ролью asis
  const asisSections = new Set((model.sections || []).filter(s => s.role === 'asis').map(s => s.dir));
  const drawnToday = new Set();
  for (const tab of model.tabs) {
    if (!asisSections.has(tab.section)) continue;
    const g = graph.entities;
    for (const ent of g.values())
      if ((ent.tabs || new Set()).has(tab.id)) drawnToday.add(ent.id);
  }
  // сравниваем только системы, а не роли и справочники: сотрудник или договор в CMDB не живут
  const SYSTEM_TYPES = new Set(['service', 'store', 'model', 'external', 'control', 'channel']);
  const unconfirmed = [...drawnToday].filter(id => !linked.has(id))
    .map(id => graph.entities.get(id)).filter(e => e && !e.implicit && SYSTEM_TYPES.has(e.type));

  const entitiesWithItems = new Set(linked.keys());
  const described = [...entitiesWithItems].map(id => graph.entities.get(id)).filter(Boolean);

  return {
    total: items.length,
    linked: items.length - unknown.length,   // позиции, нашедшие сущность
    entities: described.length,              // сколько разных сущностей за ними стоит
    unknown,
    unconfirmed: unconfirmed.sort((a, b) => a.id.localeCompare(b.id)),
    described,
    linkedMap: linked,
  };
}

export function inventoryText(res, model) {
  const src = (model.inventory && model.inventory.source) || 'источник не указан';
  const lines = [
    `инвентарь: ${res.total} позиций · сопоставлено ${res.linked} (${plural(res.entities, ['сущность', 'сущности', 'сущностей'])} модели) · источник: ${src}`,
  ];
  if (res.unknown.length) {
    lines.push('', 'нет в модели (существует, но не описано):');
    for (const it of res.unknown) lines.push(`  ${it.id} — ${it.name || ''}${it.owner ? ` · ${it.owner}` : ''}`);
  }
  if (res.unconfirmed.length) {
    lines.push('', 'нарисовано как «сегодня», но в инвентаре не подтверждено:');
    for (const e of res.unconfirmed.slice(0, 15)) lines.push(`  ${e.id} — ${e.title}${e.owner ? ` · ${e.owner}` : ''}`);
    if (res.unconfirmed.length > 15) lines.push(`  … ещё ${res.unconfirmed.length - 15}`);
  }
  if (!res.unknown.length && !res.unconfirmed.length) lines.push('', 'расхождений нет: что нарисовано, то и существует.');
  lines.push('', 'заполнить инвентарь: `arch/inventory.yaml` (выгрузка из CMDB, реестра ИС или схемы БД)');
  return lines.join('\n');
}
