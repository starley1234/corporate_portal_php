// Загрузка модели из arch/*.yaml, проверки и компиляция в данные для страницы.
import fs from 'node:fs';
import path from 'node:path';
import { buildGraph, coverage } from './graph.mjs';
import { projectStatus } from './status.mjs';
import { INVENTORY_KINDS } from './inventory.mjs';
import { compileInvariants } from './invariants.mjs';
import YAML from 'yaml';
import { fromMd } from './mdlite.mjs';
import { verifyLock } from './lock.mjs';

export class Problems {
  errors = []; warnings = [];
  err(where, msg) { this.errors.push(`${where}: ${msg}`); }
  warn(where, msg) { this.warnings.push(`${where}: ${msg}`); }
}

const sortName = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

// Разделы (вкладки) задаются в meta.sections; по умолчанию — tobe (основной) и asis (скрытый за переключателем).
export const STATUS_LABEL = { keep: 'сохраняется', move: 'переходит', drop: 'выводится', new: 'новое', tbd: 'не решено' };
export const STATUSES = Object.keys(STATUS_LABEL);

export function normalizeSections(meta) {
  const explicit = Array.isArray(meta.sections);
  const list = explicit ? meta.sections : [{ dir: 'tobe', label: 'TO-BE' }, { dir: 'asis', label: 'AS-IS', hidden: true }];
  return list.map((s, i) => ({
    dir: s.dir,
    label: s.label || String(s.dir).toUpperCase(),
    hidden: !!s.hidden,
    // locked — раздел зафиксирован замочком (см. scripts/lib/lock.mjs)
    locked: !!s.locked,
    // role: primary — целевой, к его узлам привязывается карта перехода;
    //       asis — текущее; solution — прикладной слой (не участвует в карте).
    role: s.role || (i === 0 ? 'primary' : s.hidden ? 'asis' : 'solution'),
    banner: s.banner !== undefined ? s.banner : (!explicit && i === 1 ? meta.asis_banner : undefined),
  }));
}

export const primaryDir = model => (model.sections.find(s => s.role === 'primary') || model.sections[0] || {}).dir;
export const asisDirs = model => model.sections.filter(s => s.role === 'asis').map(s => s.dir);

// ---------- журнал решений (arch/decisions/NN-*.yaml, необязательно) ----------
export const DECISION_STATUS = ['proposed', 'accepted', 'rejected', 'superseded'];
export const DECISION_LABEL = { proposed: 'предложено', accepted: 'принято', rejected: 'отклонено', superseded: 'заменено' };
const DECISION_KEYS = ['id', 'title', 'status', 'date', 'owner', 'due', 'need', 'links', 'context', 'decision', 'consequences', 'supersedes', 'file'];

export function compileDecisions(model, P) {
  const list = model.decisions || [];
  const seen = new Set();
  list.forEach((d, i) => {
    const w = `${d.file || `decisions[${i}]`}`;
    if (!isObj(d)) { P.err(w, 'решение — словарь {id, title, status, context, decision}'); return; }
    checkKeys(d, DECISION_KEYS, w, P);
    for (const k of ['id', 'title', 'status', 'context', 'decision'])
      if (d[k] === undefined || d[k] === '') P.err(w, `нет обязательного поля ${k}`);
    if (d.id) {
      if (seen.has(String(d.id))) P.err(w, `дубль id "${d.id}"`);
      seen.add(String(d.id));
    }
    if (d.status && !DECISION_STATUS.includes(d.status))
      P.err(w, `неизвестный status "${d.status}" (${DECISION_STATUS.join(' | ')})`);
    if (d.date !== undefined && !/^\d{4}-\d{2}-\d{2}$/.test(String(d.date))) P.warn(w, `date лучше писать как ДДДД-ММ-ДД, сейчас: ${d.date}`);
    if (d.status === 'superseded' && !d.supersedes) P.warn(w, `status superseded без supersedes: непонятно, чем заменено`);
    if (d.supersedes && !list.some(x => String(x.id) === String(d.supersedes)) && !seen.has(String(d.supersedes)))
      P.warn(w, `supersedes ссылается на "${d.supersedes}", которого нет в журнале`);
    for (const link of asList(d.links))
      if (!model.tabs.some(t => t.id === String(link))) P.err(w, `links: вкладка "${link}" не найдена`);
    if (d.consequences !== undefined && !Array.isArray(d.consequences)) P.err(w, 'consequences — список пунктов');
    // «кто решает и когда» — чтобы предложенное решение не висело безымянным
    if (d.due !== undefined && !/^\d{4}-\d{2}-\d{2}$/.test(String(d.due))) P.warn(w, `due лучше писать как ДДДД-ММ-ДД, сейчас: ${d.due}`);
    if (d.need !== undefined && !Array.isArray(d.need)) P.err(w, 'need — список пунктов: что нужно, чтобы принять решение');
    if (d.status === 'proposed' && !d.owner) P.warn(w, 'status proposed без owner: непонятно, кто решает');
    if (d.status === 'proposed' && !d.need) P.warn(w, 'status proposed без need: непонятно, что нужно для решения');
  });
}

function decisionsTab(model) {
  const list = model.decisions || [];
  const by = s => list.filter(d => d.status === s).length;
  const rows = list.map(d => [
    `\`${d.id}\``,
    DECISION_LABEL[d.status] || String(d.status),
    d.date ? `\`${d.date}\`` : '—',
    d.title,
    (d.links || []).map(l => `\`${l}\``).join(', ') || '—',
  ]);
  const lead = `**Почему целевой контур именно такой.** ${list.length} архитектурных решений: ` +
    `${by('accepted')} принято, ${by('proposed')} предложено, ${by('rejected')} отклонено, ${by('superseded')} заменено. ` +
    `Пока решение в статусе «предложено», соответствующая часть схемы — гипотеза, а не обязательство. ` +
    `Журнал собирается из \`arch/decisions/\`; список открытых — \`arch adr --status proposed\`.`;
  const table = { head: ['ADR', 'Статус', 'Дата', 'Решение', 'Где'], rows, center: [1, 2] };
  const detail = [];
  for (const d of list) {
    detail.push({ heading: `${d.id} · ${d.title}` });
    detail.push({
      note: `**Статус:** ${DECISION_LABEL[d.status] || d.status}` +
        (d.date ? ` · ${d.date}` : '') + (d.owner ? ` · ответственный: ${d.owner}` : '') +
        (d.due ? ` · решить до ${d.due}` : '') +
        (d.supersedes ? ` · заменяет \`${d.supersedes}\`` : ''),
    });
    detail.push({ note: `**Контекст.** ${d.context}` });
    detail.push({ note: `**Решение.** ${d.decision}` });
    if (Array.isArray(d.need) && d.need.length) detail.push({ steps: d.need.map(x => `Чтобы решить: ${x}`) });
    if (Array.isArray(d.consequences) && d.consequences.length) detail.push({ steps: d.consequences });
    if (asList(d.links).length) detail.push({ note: `Где влияет: ${d.links.map(l => `\`${l}\``).join(', ')}` });
  }
  return {
    section: generatedDir(model),
    id: `${generatedDir(model)}/decisions`,
    file: 'decisions/',
    generated: true,
    title: 'Журнал решений (ADR)',
    overview: {
      lead,
      blocks: [
        { table },
        ...(list.some(d => d.status === 'proposed')
          ? [{ cards: list.filter(d => d.status === 'proposed').slice(0, 9).map(d => ({ title: `${d.id} · ${d.owner || 'нет владельца'}`, chips: asList(d.need || ['что нужно — не записано']).slice(0, 3) })) }]
          : []),
        { note: 'Открытые решения не висят в таблицах плана внедрения, а живут здесь: статус меняется в `arch/decisions/*.yaml`, история — в git.' },
      ],
    },
    detail: { lead: 'Полный журнал: контекст, решение и последствия по каждому ADR.', blocks: detail },
  };
}

// ---------- контракт слоёв: uses / via ----------
const TAB_KEYS = ['title', 'overview', 'detail', 'uses', 'via'];

// Платформенные узлы, которые использует прикладной слой; узлы-модели для проверки via.
const MODEL_KINDS = ['egress', 'local'];

export function contractIndex(model) {
  const primary = primaryDir(model);
  const ids = nodeIdsOf(model, [primary]);
  const used = new Map();
  const missing = [], declared = [];
  for (const tab of model.tabs) {
    if (tab.generated) continue;
    if (tab.uses === undefined) continue;
    const list = asList(tab.uses).map(String);
    declared.push({ tab: tab.id, uses: list, role: tab.role });
    for (const id of list) if (!used.has(id)) used.set(id, []); // валидность проверяется в compileContract
    for (const id of list) {
      if (!used.has(id)) used.set(id, []);
      used.get(id).push(tab.id);
    }
  }
  // «Не используется» считаем по каноническому виду платформы: первая вкладка целевого раздела
  // (или meta.contract.reference). Иначе в список попадут дубли одного компонента из других вкладок
  // (gw-v2 / cy-gw / p-gw / h-gw — это один gateway, нарисованный в разных разрезах).
  const refId = model.meta?.contract?.reference;
  const refTab = refId
    ? model.tabs.find(t => t.id === String(refId) || t.id.endsWith(String(refId).split('/').pop()))
    : model.tabs.find(t => t.section === primary);
  const refNodes = refTab ? nodeIdsOf({ tabs: [refTab] }, [primary]) : ids;
  return {
    primary,
    reference: refTab ? refTab.id : null,
    platformNodes: [...ids],
    used,
    declared,
    missing,
    unused: [...refNodes].filter(id => !used.has(id)),
    undeclared: model.tabs.filter(t => !t.generated && t.role === 'solution' && t.uses === undefined).map(t => t.id),
  };
}

export function compileContract(model, P) {
  const primary = primaryDir(model);
  const ids = nodeIdsOf(model, [primary]);
  for (const tab of model.tabs) {
    if (tab.generated) continue;
    const w = `${tab.file}`;
    const extra = Object.keys(tab).filter(k => !TAB_KEYS.includes(k) && !['section', 'role', 'locked', 'id', 'file', 'generated'].includes(k));
    if (extra.length) P.err(w, `неизвестные ключи вкладки: ${extra.join(', ')}`);
    if (tab.uses === undefined) {
      if (tab.role === 'solution') P.warn(w, `прикладная вкладка не объявляет uses: чем она пользуется в платформе (uses: [] — если ничем)`);
      continue;
    }
    if (!Array.isArray(tab.uses)) { P.err(w, 'uses — список id узлов платформы (или [])'); continue; }
    for (const id of tab.uses) {
      if (!ids.has(String(id))) P.err(w, `uses: узел платформы "${id}" не найден в схемах раздела ${primary}`);
    }
    if (tab.via !== undefined) {
      if (!Array.isArray(tab.via) || !tab.via.length) P.err(w, 'via — список узлов схемы, через которые разрешены обращения к моделям');
      else checkVia(tab, String(tab.via), P, w);
    }
  }
}

// via: все пути от источников схемы к узлам-моделям должны проходить через via-узлы.
function checkVia(tab, viaRaw, P, where) {
  const via = new Set(asList(tab.via).map(String));
  for (const level of ['overview', 'detail']) {
    for (const b of asList(tab[level]?.blocks)) {
      if (!b || !b.scheme) continue;
      const nodes = asList(b.scheme.nodes).map(n => String(n.id));
      const edges = asList(b.scheme.edges).map(parseEdgeLocal).filter(Boolean);
      const indeg = new Map(nodes.map(id => [id, 0]));
      const preds = new Map(nodes.map(id => [id, []]));
      for (const e of edges) {
        if (!indeg.has(e.f) || !indeg.has(e.t)) continue;
        indeg.set(e.t, indeg.get(e.t) + 1);
        preds.get(e.t).push(e.f);
      }
      for (const id of via) if (!nodes.includes(id)) P.err(`${where} › via`, `узел "${id}" не объявлен в схеме`);
      const targets = asList(b.scheme.nodes).filter(n => MODEL_KINDS.includes(n.kind)).map(n => String(n.id));
      for (const target of targets) {
        // обратный обход от модели, не заходя в via: если добрались до узла без входов — путь в обход
        const seen = new Set([target]);
        const queue = [target];
        let bypass = null;
        while (queue.length) {
          const id = queue.shift();
          if (!via.has(id) && indeg.get(id) === 0 && id !== target) { bypass = id; break; }
          for (const p of preds.get(id) || []) {
            if (via.has(p) || seen.has(p)) continue;
            seen.add(p); queue.push(p);
          }
        }
        if (bypass) P.err(`${where} › via`, `к узлу-модели "${target}" есть путь в обход [${[...via].join(', ')}] — через "${bypass}"`);
      }
      if (!targets.length) P.warn(`${where} › via`, 'via задан, но в схеме нет узлов-моделей (kinds: ' + MODEL_KINDS.join(', ') + ') — проверка не сработала');
      for (const id of via) {
        const onPath = targets.some(t => reachesViaGuard(preds, t, id, via));
        if (!onPath) P.warn(`${where} › via`, `узел "${id}" не лежит ни на одном пути к моделям — проверьте, что это действительно контрольная точка`);
      }
    }
  }
}

// достигается ли target из узла guard (обратный обход, guard как старт)
function reachesViaGuard(preds, target, guard, via) {
  const seen = new Set([target]);
  const queue = [target];
  while (queue.length) {
    const id = queue.shift();
    for (const p of preds.get(id) || []) {
      if (p === guard) return true;
      if (seen.has(p)) continue; // контрольных точек на пути может быть несколько — идём и через них
      seen.add(p); queue.push(p);
    }
  }
  return false;
}

function parseEdgeLocal(e) {
  const key = typeof e === 'string' ? e : (e && Object.keys(e)[0]) || '';
  const m = /^(\S+)\s*(?:->|-->)\s*(\S+)$/.exec(String(key).trim());
  return m ? { f: m[1], t: m[2] } : null;
}

function readYaml(dir, rel) {
  const text = fs.readFileSync(path.join(dir, rel), 'utf8').replace(/^\uFEFF/, '');
  try { return YAML.parse(text, { prettyErrors: true }); }
  catch (e) { throw new Error(`${rel}: ${e.message}`); }
}

const readYamlIfExists = (dir, rel, fallback) => (fs.existsSync(path.join(dir, rel)) ? readYaml(dir, rel) : fallback);

// Куда класть сгенерированные вкладки (карта перехода, журнал решений):
// в раздел с role: governance, иначе — в первый раздел.
export function generatedDir(model) {
  const s = model.sections.find(x => x.role === 'governance') || model.sections[0];
  return s ? s.dir : 'tobe';
}


// ---------- реестр сущностей (arch/entities.yaml, необязательно) ----------
// Один канонический id на компонент: узлы схем ссылаются на него через ref, либо
// совпадают по alias. Это то, чего не хватало графу: gw, gw-v2, p-gw, h-gw — одна сущность.
export const ENTITY_TYPES = ['service', 'store', 'channel', 'control', 'model', 'external', 'data-class', 'role', 'doc', 'object', 'stage', 'pattern', 'tab'];
export const DATA_CLASSES = ['public', 'internal', 'confidential', 'secret'];
const ENTITY_KEYS = ['id', 'type', 'title', 'owner', 'data_class', 'aliases', 'note'];

export function normalizeEntities(raw) {
  const src = isObj(raw) && Array.isArray(raw.entities) ? raw.entities : asList(raw);
  const list = src.filter(isObj).map(e => ({ ...e, id: String(e.id), aliases: asList(e.aliases).map(String) }));
  const byId = {}, alias = {};
  for (const e of list) {
    byId[e.id] = e;
    for (const a of e.aliases) alias[a] = e.id;
  }
  return { list, byId, alias, title: isObj(raw) ? raw.title : undefined };
}

export function compileEntities(model, P) {
  if (!model.entities) return;
  const seenAlias = new Map();
  model.entities.list.forEach((e, i) => {
    const w = `entities.yaml › сущность ${e.id || '#' + i}`;
    checkKeys(e, ENTITY_KEYS, w, P);
    if (!e.id) return P.err(w, 'нет id');
    if (!e.title) P.err(w, 'нет title: непонятно, что это за компонент');
    if (e.type && !ENTITY_TYPES.includes(e.type)) P.err(w, `неизвестный type "${e.type}" (${ENTITY_TYPES.join(' | ')})`);
    if (!e.owner || e.owner === '—') P.warn(w, 'нет owner: у объекта должен быть хозяин — иначе непонятно, кто отвечает за его права и изменения');
    if (!e.data_class) P.warn(w, `нет data_class (${DATA_CLASSES.join(' | ')}): без класса данных права проверить нельзя`);
    else if (!DATA_CLASSES.includes(String(e.data_class))) P.warn(w, `неизвестный data_class "${e.data_class}" (${DATA_CLASSES.join(' | ')})`);
    if (model.entities.list.filter(x => x.id === e.id).length > 1) P.err(w, `дубль id "${e.id}"`);
    for (const a of e.aliases) {
      if (a === e.id) continue; // канонический id сам по себе — обычное дело
      if (model.entities.byId[a]) P.err(w, `alias "${a}" совпадает с id другой сущности`);
      if (seenAlias.has(a) && seenAlias.get(a) !== e.id) P.err(w, `alias "${a}" уже занят сущностью ${seenAlias.get(a)}`);
      seenAlias.set(a, e.id);
    }
  });
}

// Где сущность встречается: вкладки, в схемах которых есть узел с её id или alias.
export function entityUsage(model, entity) {
  const keys = new Set([entity.id, ...(entity.aliases || [])]);
  const refs = new Set();
  const tabs = [];
  for (const tab of model.tabs) {
    let hit = false;
    for (const level of ['overview', 'detail'])
      for (const b of asList(tab[level]?.blocks))
        if (b && b.scheme) for (const n of asList(b.scheme.nodes)) {
          const key = n && n.ref ? String(n.ref) : n && n.id ? String(n.id) : null;
          if (!key) continue;
          if (keys.has(key)) { hit = true; if (n.id) refs.add(String(n.id)); }
        }
    if (hit) tabs.push(tab.id);
  }
  return { tabs, nodeIds: [...refs] };
}

function entitiesTab(model) {
  const list = model.entities.list;
  const rows = list.map(e => {
    const u = entityUsage(model, e);
    return [
      `\`${e.id}\``,
      e.type || '—',
      e.title || '—',
      e.owner || '—',
      u.tabs.length ? `${u.tabs.length} — ${u.tabs.slice(0, 3).map(t => `\`${t}\``).join(', ')}${u.tabs.length > 3 ? '…' : ''}` : '**не встречается**',
    ];
  });
  const tabsUsed = new Set();
  for (const e of list) for (const t of entityUsage(model, e).tabs) tabsUsed.add(t);
  return {
    section: generatedDir(model),
    id: `${generatedDir(model)}/entities`,
    file: 'entities.yaml',
    generated: true,
    title: 'Реестр сущностей',
    overview: {
      lead: `**Один компонент — один id.** Всего сущностей: ${list.length}; из них ${list.filter(e => (entityUsage(model, e).tabs.length > 1)).length} встречаются больше чем в одной вкладке. ` +
        `Раньше gateway был нарисован как \`gw\`, \`gw-v2\`, \`p-gw\` и \`h-gw\` — четыре узла, которые никто не связывал между собой. ` +
        `Теперь узлы ссылаются на сущность через \`ref:\` или совпадают с одним из \`aliases\`, и граф (\`arch graph\`, \`arch ask\`) видит их как один объект.`,
      blocks: [
        { table: { head: ['Сущность', 'Тип', 'Название', 'Владелец', 'Где встречается'], rows } },
        { note: 'Реестр — источник канонических id: `arch/entities.yaml`. Узлы схем можно не переписывать: достаточно объявить старые id в `aliases`.' },
      ],
    },
    detail: {
      lead: '**Как добавить сущность.** Реестр нужен не для красоты: без него любой вопрос «где ещё это нарисовано» решается поиском по тексту, а граф распадается на одинаковые узлы.',
      blocks: [
        { steps: [
          'Найдите компонент, который встречается в нескольких вкладках под разными id (например, шлюз).',
          'Добавьте сущность в `arch/entities.yaml`: `id` (канонический), `type`, `title`, `aliases` — все старые id и будущие варианты.',
          'В новых вкладках указывайте `ref: <id>` прямо в узле схемы — тогда связь с реестром видна в файле, а не только в реестре.',
          'Проверьте: `npm run check` (дубли alias) и `arch graph --coverage` (сколько узлов ещё не сопоставлено).',
        ] },
        { note: 'Правило: `ref` и `aliases` не отменяют переименование, но позволяют жить с историческим разнобоем, не ломая замороженные разделы.' },
      ],
    },
  };
}

// ---------- гипотезы (arch/hypotheses/NN-*.yaml, необязательно) ----------
// Гипотеза — это не решение: у неё есть вопрос, варианты, чем проверяем и чем закончилась.
export const HYPOTHESIS_STATUS = ['open', 'confirmed', 'rejected', 'parked'];
export const HYPOTHESIS_LABEL = { open: 'открыта', confirmed: 'подтверждена', rejected: 'опровергнута', parked: 'отложена' };
const HYPOTHESIS_KEYS = ['id', 'title', 'status', 'date', 'owner', 'question', 'assumptions', 'options', 'links', 'evidence', 'verdict', 'file'];
const OPTION_KEYS = ['id', 'title', 'pros', 'cons', 'cost', 'risk', 'answer'];

export function compileInventory(model, P) {
  if (!model.inventory) return;
  const items = asList(model.inventory.items);
  if (!items.length) P.warn('inventory.yaml', 'инвентарь пуст: сверять нечего');
  const seen = new Set();
  items.forEach((it, i) => {
    const w = `inventory.yaml › позиция ${(it && it.id) || '#' + (i + 1)}`;
    if (!isObj(it)) return P.err(w, 'позиция — словарь {id, entity, name, kind, owner}');
    if (!it.id) P.err(w, 'нет id');
    else if (seen.has(String(it.id))) P.err(w, `дубль id «${it.id}»`);
    else seen.add(String(it.id));
    if (!it.name) P.warn(w, 'нет name: непонятно, что это за система');
    if (it.kind && !INVENTORY_KINDS.includes(it.kind)) P.warn(w, `неизвестный kind «${it.kind}» (${INVENTORY_KINDS.join(' | ')})`);
    if (it.entity && (!model.entities || !model.entities.byId[String(it.entity)]))
      P.err(w, `entity «${it.entity}» нет в arch/entities.yaml`);
    if (!it.owner) P.warn(w, 'нет owner: у системы должен быть хозяин');
  });
}

export function compileHypotheses(model, P) {
  if (!model.hypotheses) return;
  const tabIds = new Set(model.tabs.map(t => t.id));
  const seen = new Set();
  model.hypotheses.forEach((h, i) => {
    const w = `${h.file || `hypotheses[${i}]`}`;
    if (!isObj(h)) return P.err(w, 'гипотеза — словарь {id, title, status, question}');
    checkKeys(h, HYPOTHESIS_KEYS, w, P);
    for (const k of ['id', 'title', 'status', 'question'])
      if (h[k] === undefined || h[k] === '') P.err(w, `нет обязательного поля ${k}`);
    if (h.id) {
      const id = String(h.id);
      if (seen.has(id)) P.err(w, `дубль id "${id}"`);
      seen.add(id);
    }
    if (h.status && !HYPOTHESIS_STATUS.includes(h.status))
      P.err(w, `неизвестный status "${h.status}" (${HYPOTHESIS_STATUS.join(' | ')})`);
    if (h.date !== undefined && !/^\d{4}-\d{2}-\d{2}$/.test(String(h.date))) P.warn(w, `date лучше писать как ДДДД-ММ-ДД, сейчас: ${h.date}`);
    if ((h.status === 'confirmed' || h.status === 'rejected') && !h.verdict)
      P.warn(w, `status ${h.status} без verdict: непонятно, чем закончилась проверка`);
    if (!Array.isArray(h.options) || !h.options.length) P.warn(w, 'нет options: гипотеза без вариантов — это вопрос, а не гипотеза');
    const optSeen = new Set();
    asList(h.options).forEach((o, k) => {
      const ow = `${w} › options[${k}]`;
      if (!isObj(o)) return P.err(ow, 'вариант — словарь {id, title, pros, cons}');
      checkKeys(o, OPTION_KEYS, ow, P);
      if (!o.id) P.err(ow, 'нет id варианта');
      else if (optSeen.has(String(o.id))) P.err(ow, `дубль id варианта "${o.id}"`);
      else optSeen.add(String(o.id));
      if (!o.title) P.err(ow, 'нет title варианта');
    });
    for (const link of asList(h.links))
      if (!tabIds.has(String(link))) P.err(w, `links ссылается на несуществующую вкладку "${link}"`);
    asList(h.evidence).forEach((ev, k) => {
      if (!isObj(ev)) return P.err(`${w} › evidence[${k}]`, 'доказательство — словарь {kind, note, date}');
      if (!ev.note) P.err(`${w} › evidence[${k}]`, 'нет note');
      if (ev.date !== undefined && !/^\d{4}-\d{2}-\d{2}$/.test(String(ev.date))) P.warn(`${w} › evidence[${k}]`, `date лучше как ДДДД-ММ-ДД`);
    });
  });
}

function hypothesesTab(model) {
  const list = model.hypotheses || [];
  const by = s => list.filter(h => h.status === s).length;
  const rows = list.map(h => [
    `\`${h.id}\``,
    HYPOTHESIS_LABEL[h.status] || String(h.status),
    h.title,
    h.question,
    asList(h.evidence).map(e => e.kind || e.note).join(', ') || '—',
    asList(h.links).map(l => `\`${l}\``).join(', ') || '—',
  ]);
  const detail = [];
  for (const h of list) {
    detail.push({ heading: `${h.id} · ${h.title}` });
    detail.push({ note: `**Статус:** ${HYPOTHESIS_LABEL[h.status] || h.status}` + (h.date ? ` · ${h.date}` : '') + (h.owner ? ` · владелец: ${h.owner}` : '') });
    detail.push({ note: `**Вопрос.** ${h.question}` });
    if (asList(h.assumptions).length) detail.push({ steps: asList(h.assumptions).map(a => `Допущение: ${a}`) });
    if (asList(h.options).length) detail.push({
      table: {
        head: ['Вариант', 'Плюсы', 'Минусы', 'Стоимость', 'Риск'],
        rows: asList(h.options).map(o => [`**${o.id}.** ${o.title}`, asList(o.pros).join(' ') || '—', asList(o.cons).join(' ') || '—', o.cost || '—', o.risk || '—']),
      },
    });
    if (asList(h.evidence).length) detail.push({ note: 'Чем проверяли или проверяем: ' + asList(h.evidence).map(e => `${e.kind || 'доказательство'} — ${e.note}${e.date ? ` (${e.date})` : ''}`).join('; ') });
    detail.push(h.verdict ? { note: `**Итог.** ${h.verdict}` } : { warn: 'Итог не зафиксирован: пока гипотеза открыта, любая часть схемы, которая на неё опирается, — тоже гипотеза.' });
  }
  return {
    section: generatedDir(model),
    id: `${generatedDir(model)}/hypotheses`,
    file: 'hypotheses/',
    generated: true,
    title: 'Гипотезы',
    overview: {
      lead: `**Гипотеза — это вопрос с вариантами и способом проверки; решение — отдельная запись.** Всего гипотез: ${list.length} — открыто ${by('open')}, подтверждено ${by('confirmed')}, опровергнуто ${by('rejected')}, отложено ${by('parked')}. ` +
        `Гипотеза не меняет архитектуру сама по себе: пока она открыта, всё, что на неё опирается, помечено как предположение. Когда проверка закончена, гипотеза получает итог, а решение — запись в журнале ADR.`,
      blocks: [
        { table: { head: ['Гипотеза', 'Статус', 'Название', 'Вопрос', 'Чем проверяем', 'Где'], rows } },
        { note: 'Файлы — `arch/hypotheses/NN-*.yaml`. Список из терминала: `arch hypo`; подробности: `arch hypo H-001`; влияние на граф: `arch impact <узел>`.' },
      ],
    },
    detail: { lead: '**Разбор гипотез: варианты, доказательства и итог.** Пустая графа «итог» означает, что вопрос всё ещё открыт.', blocks: detail },
  };
}


// ---------- требования и покрытие (arch/controls/*.yaml, необязательно) ----------
export const CONTROL_KEYS = ['id', 'title', 'area', 'source', 'satisfied_by', 'evidence', 'note', 'file'];
export const CONTROL_AREAS = ['ИБ', 'Данные', 'Эксплуатация', 'Процесс'];

export function compileControls(model, P) {
  const list = model.controls || [];
  if (!list.length) { model.controlsReport = []; return; }
  const known = new Set();
  if (model.entities) for (const e of model.entities.list) { known.add(String(e.id)); for (const a of asList(e.aliases)) known.add(String(a)); }
  for (const id of nodeIdsOf(model, model.sections.map(s => s.dir))) known.add(String(id));
  const seen = new Set();
  const report = [];
  list.forEach((c, i) => {
    const w = `${c.file || `controls[${i}]`}`;
    if (!isObj(c)) { P.err(w, 'требование — словарь {id, title, satisfied_by}'); return; }
    checkKeys(c, CONTROL_KEYS, w, P);
    for (const k of ['id', 'title']) if (c[k] === undefined || c[k] === '') P.err(w, `нет обязательного поля ${k}`);
    if (c.id) {
      if (seen.has(String(c.id))) P.err(w, `дубль id "${c.id}"`);
      seen.add(String(c.id));
    }
    if (c.satisfied_by !== undefined && !Array.isArray(c.satisfied_by)) P.err(w, 'satisfied_by — список id компонентов');
    const by = asList(c.satisfied_by).map(String);
    for (const id of by)
      if (!known.has(id)) P.err(w, `satisfied_by: компонент "${id}" не найден ни в реестре, ни на схемах`);
    if (!by.length && !c.note) P.warn(w, 'ничем не обеспечено и без note: непонятно, это пробел или ещё не разобрали');
    if (c.area !== undefined && !CONTROL_AREAS.includes(c.area))
      P.warn(w, `area: ${CONTROL_AREAS.join(' | ')} (сейчас ${c.area})`);
    report.push({ id: c.id, title: c.title, area: c.area || '—', source: c.source, note: c.note,
      evidence: c.evidence, satisfied_by: by, covered: by.length > 0, file: c.file });
  });
  model.controlsReport = report;
}

function controlsTab(model) {
  const list = model.controlsReport || [];
  const gaps = list.filter(c => !c.covered);
  const rows = list.map(c => [
    `\`${c.id}\``, c.title, c.area,
    c.satisfied_by.length ? c.satisfied_by.map(id => `\`${id}\``).join(', ') : '—',
    c.evidence || '—',
  ]);
  const detail = [];
  for (const c of list) {
    detail.push({ heading: `${c.id} · ${c.title}` });
    detail.push({ note: `**Область:** ${c.area}` + (c.source ? ` · источник: ${c.source}` : '') +
      ` · ${c.covered ? 'обеспечено' : '**покрытия нет**'}` });
    if (c.satisfied_by.length) detail.push({ note: 'Чем обеспечено: ' + c.satisfied_by.map(id => `\`${id}\``).join(', ') });
    if (c.evidence) detail.push({ note: `**Доказательство.** ${c.evidence}` });
    if (c.note) detail.push({ note: `**Комментарий.** ${c.note}` });
  }
  return {
    section: generatedDir(model),
    id: `${generatedDir(model)}/controls`,
    file: 'controls/',
    generated: true,
    title: 'Требования и покрытие',
    overview: {
      lead: `**Что от нас требуют и чем это обеспечено.** Требований ${list.length}: ` +
        `${list.length - gaps.length} обеспечено, ${gaps.length} без покрытия. ` +
        `Требование без покрытия — не ошибка модели, а решение, которое ещё не принято, или работа, которая не сделана.`,
      blocks: [
        { table: { head: ['Требование', 'Формулировка', 'Область', 'Чем обеспечено', 'Доказательство'], rows } },
        ...(gaps.length ? [{ steps: gaps.map(g => `${g.id} — ${g.title}${g.note ? ': ' + g.note : ''}`) }] : []),
        { note: 'Файлы — `arch/controls/NN-*.yaml`. Только пробелы: `arch controls --missing`. Требование ссылается на компоненты по id, поэтому видно, что именно его закрывает.' },
      ],
    },
    detail: { lead: '**По требованию: чем обеспечено и чем это подтверждается.**', blocks: detail },
  };
}

// ---------- вкладка «Состояние модели» (собирается из графа и реестра) ----------
function statusTab(model) {
  let st;
  try { st = projectStatus(model, buildGraph(model), { errors: [], warnings: [] }); }
  catch (e) { st = null; }
  const num = (k, v) => [`**${k}**`, String(v)];
  const main = st ? [
    num('Вкладок', st.tabs + 1), num('Схем', st.schemes), num('Узлов на схемах', st.nodes),
    num('Сущностей в графе', st.graph.entities), num('Связей', st.graph.edges),
    num('Фрагментов знания', st.graph.units),
    num('Сущностей в реестре', st.graph.registry),
    num('Покрытие реестром', `${st.coverage.avg}%` + (st.coverage.processes.length ? ` (вкладок-процессов ${st.coverage.processes.length} не считаем)` : '')),
    num('Гипотез', `${st.hypotheses.total}` + (st.hypotheses.open.length ? `, открыто ${st.hypotheses.open.length}` : '')),
    num('Решений', `${st.decisions.total}` + (st.decisions.proposed.length ? `, предложено ${st.decisions.proposed.length}` : '')),
    ...(st.inventory ? [num('Инвентарь', `${st.inventory.total} систем` +
      (st.inventory.unknown.length ? `, не описано ${st.inventory.unknown.length}` : '') +
      (st.inventory.unconfirmed.length ? `, не подтверждено ${st.inventory.unconfirmed.length}` : ''))] : []),
  ] : [['**Состояние**', 'собирается при загрузке модели']];
  const attention = st ? st.next.map((x, i) => [`${i + 1}`, x]) : [['—', '—']];
  const weak = st ? st.coverage.weak.slice(0, 6).map(r => [`${r.tab}`, `${r.pct}%`, `${r.mapped} из ${r.total}`]) : [];
  const blocks = [
    { table: { head: ['Показатель', 'Значение'], rows: main } },
    { table: { head: ['№', 'Что требует внимания'], rows: attention } },
  ];
  if (weak.length) blocks.push({ table: { head: ['Вкладка', 'Покрытие', 'Сопоставлено'], rows: weak, center: [1, 2] } });
  blocks.push({ note: 'Живые цифры и проверки — `npm run status`; покрытие по вкладкам — `arch graph --coverage`; проверки модели — `npm run check`. Здесь они показываются на момент сборки страницы.' });
  return {
    section: generatedDir(model),
    id: `${generatedDir(model)}/status`,
    file: '—',
    generated: true,
    title: 'Состояние модели',
    overview: {
      lead: '**Где мы сейчас.** Одна страница вместо обхода вкладок: сколько всего описано, насколько полно объекты связаны между собой, что требует внимания. ' +
        'Числа пересобираются вместе со страницей, поэтому устареть не могут.',
      blocks,
    },
    detail: {
      lead: '**Как это читать.** Покрытие реестром — доля узлов схем, у которых есть канонический id. Чем ниже, тем хуже граф видит связи между вкладками.',
      blocks: [
        { steps: [
          'Посмотрите «Что требует внимания» — это готовый список следующих шагов, а не приглашение читать всё подряд.',
          'Низкое покрытие у вкладки значит: её узлы живут под собственными именами. Лечится добавлением `aliases` в `arch/entities.yaml`.',
          'Открытые гипотезы — это не долги, а вопросы, на которые ещё нет ответа. Пока они открыты, опирающиеся на них части схемы — предположения.',
          'Если строки «требует внимания» кончились, модель в порядке: можно собирать страницу и принимать решения по ней.',
        ] },
        { note: 'Технические подробности: граф собирается командой `arch graph`, влияние объекта — `arch impact <id>`, поиск — `arch ask "<вопрос>"`.' },
      ],
    },
  };
}

export function loadModel(dir) {
  const meta = readYaml(dir, 'meta.yaml') || {};
  const sections = normalizeSections(meta);
  const model = { dir, meta, sections, components: readYamlIfExists(dir, 'components.yaml', {}) || {}, entities: null, tabs: [], map: null, inventory: null, decisions: null, hypotheses: null, invariants: null, controls: null };
  if (fs.existsSync(path.join(dir, 'entities.yaml'))) model.entities = normalizeEntities(readYamlIfExists(dir, 'entities.yaml', null));
  if (fs.existsSync(path.join(dir, 'map.yaml'))) model.map = readYamlIfExists(dir, 'map.yaml', null);
  if (fs.existsSync(path.join(dir, 'inventory.yaml'))) model.inventory = readYamlIfExists(dir, 'inventory.yaml', null);
  const decDir = path.join(dir, 'decisions');
  const decFiles = fs.existsSync(decDir) ? fs.readdirSync(decDir).filter(n => n.endsWith('.yaml')).sort(sortName) : [];
  // пустой каталог — не журнал: вкладку не создаём
  if (decFiles.length) model.decisions = decFiles.map(f => ({ file: `decisions/${f}`, ...readYaml(dir, `decisions/${f}`) }));
  const hypDir = path.join(dir, 'hypotheses');
  const hypFiles = fs.existsSync(hypDir) ? fs.readdirSync(hypDir).filter(n => n.endsWith('.yaml')).sort(sortName) : [];
  if (hypFiles.length) model.hypotheses = hypFiles.map(f => ({ file: `hypotheses/${f}`, ...readYaml(dir, `hypotheses/${f}`) }));
  const invDir = path.join(dir, 'invariants');
  const invFiles = fs.existsSync(invDir) ? fs.readdirSync(invDir).filter(n => n.endsWith('.yaml')).sort(sortName) : [];
  if (invFiles.length) model.invariants = invFiles.map(f => ({ file: `invariants/${f}`, ...readYaml(dir, `invariants/${f}`) }));
  const ctlDir = path.join(dir, 'controls');
  const ctlFiles = fs.existsSync(ctlDir) ? fs.readdirSync(ctlDir).filter(n => n.endsWith('.yaml')).sort(sortName) : [];
  // в файле требований допустим и один объект, и список: удобно держать одну область в одном файле
  if (ctlFiles.length) model.controls = ctlFiles.flatMap(f => {
    const raw = readYaml(dir, `controls/${f}`);
    const list = Array.isArray(raw) ? raw : (isObj(raw) && Array.isArray(raw.controls) ? raw.controls : [raw]);
    return list.map(x => ({ file: `controls/${f}`, ...(isObj(x) ? x : { bad: x }) }));
  });
  const groups = sections.map(s => {
    const sd = path.join(dir, s.dir);
    if (!fs.existsSync(sd)) return [];
    return fs.readdirSync(sd).filter(n => n.endsWith('.yaml')).sort(sortName)
      .map(f => ({ section: s.dir, role: s.role, locked: s.locked, id: `${s.dir}/${f.replace(/\.yaml$/, '')}`, file: `${s.dir}/${f}`, ...readYaml(dir, `${s.dir}/${f}`) }));
  });
  const gi = Math.max(0, sections.findIndex(s => s.dir === generatedDir(model)));
  // сначала список вкладок, потом сгенерированные: реестру нужно видеть, где встречается сущность
  model.tabs = groups.flat();
  if (model.map) groups[gi].push(mapTab(model));
  if (model.decisions) groups[gi].push(decisionsTab(model));
  // требования показываем только когда подтверждён хотя бы один компонент — иначе таблица пустая
  if (model.controls && model.controls.length && model.entities && model.entities.list.length) groups[gi].push(controlsTab(model));
  if (model.hypotheses) groups[gi].push(hypothesesTab(model));
  if (model.entities && model.entities.list.length) groups[gi].push(entitiesTab(model));
  model.tabs = groups.flat();
  // «Состояние модели» строится по уже собранным вкладкам — поэтому ставим её последней.
  // Появляется там, где ведётся реестр сущностей: в минимальном проекте сводка из нулей не нужна.
  if (model.entities && model.entities.list.length) {
    groups[gi].push(statusTab(model));
    model.tabs = groups.flat();
  }
  return model;
}

// id узлов, объявленных во вкладках указанных разделов
export function nodeIdsOf(model, sections) {
  const ids = new Set();
  for (const tab of model.tabs) {
    if (!sections.includes(tab.section)) continue;
    for (const level of ['overview', 'detail'])
      for (const b of asList(tab[level]?.blocks)) if (b && b.scheme) for (const n of asList(b.scheme.nodes)) if (n && n.id) ids.add(String(n.id));
  }
  return ids;
}

// Вкладка «карта перехода» собирается из map.yaml, а не пишется руками.
function mapTab(model) {
  const m = model.map || {};
  const title = m.title || 'Карта перехода';
  const rows = asList(m.entries).map(e => {
    const st = STATUS_LABEL[e.status] || e.status || 'не решено';
    return [
      e.asis ? `\`${e.asis}\`` : '—',
      st,
      e.tobe ? `\`${e.tobe}\`` : (e.status === 'drop' ? '—' : '?'),
      e.note || '',
    ];
  });
  const by = s => asList(m.entries).filter(e => e.status === s).length;
  const lead = `**Переход от текущего состояния к целевому — как данные, а не как рассказ.** ${rows.length} позиций: ` +
    `${by('keep')} сохраняются, ${by('move')} переходят в целевой контур, ${by('drop')} выводятся, ${by('new')} — новое, ${by('tbd')} не решено. ` +
    `Покрытие проверяется командой \`arch map\`; таблица собирается из \`arch/map.yaml\` автоматически.`;
  const table = { head: ['AS-IS', 'Статус', 'TO-BE', 'Комментарий'], rows, center: [1] };
  const target = generatedDir(model);
  const section = model.sections.find(s => s.dir === target) || model.sections[0];
  return {
    section: target,
    role: section && section.role,
    locked: section && section.locked,
    id: `${target}/map-transition`,
    file: 'map.yaml',
    generated: true,
    title,
    overview: { lead, blocks: [{ table }] },
    detail: {
      lead: `Полная карта перехода. Статусы: ` + STATUSES.map(s => `\`${s}\` — ${STATUS_LABEL[s]}`).join('; ') + '.',
      blocks: [{ table }, { note: 'Непокрытые позиции AS-IS ищите командой `arch map --missing`: они не попадают в план перехода и остаются незамеченными.' }],
    },
  };
}

// ---------- HTML-фрагменты (точные копии прежнего движка) ----------
const attr = (cls, i) => (cls && cls[i] ? ` class="${cls[i]}"` : '');
export const tableHtml = (head, rows, cls) =>
  '<div class="tw"><table><thead><tr>' + head.map((x, i) => `<th${attr(cls, i)}>${x}</th>`).join('') + '</tr></thead><tbody>' +
  rows.map(r => '<tr>' + r.map((x, i) => `<td${attr(cls, i)}>${x}</td>`).join('') + '</tr>').join('') + '</tbody></table></div>';
const cardHtml = (heading, text, col = '#2563eb') => `<div class="card" style="--c:${col}"><h4>${heading}</h4>${text}</div>`;
const chipCardHtml = (t, c, items, txt) =>
  `<div class="card" style="--c:${c}"><h4>${t}</h4>${txt ? '<div style="margin-bottom:6px">' + txt + '</div>' : ''}<div class="chips">${items.map(x => `<span class="chip">${x}</span>`).join('')}</div></div>`;
const MARK = { '●': '<span class="y">●</span>', '◐': '<span class="p">◐</span>' };
const cell = c => (MARK[c] !== undefined ? MARK[c] : fromMd(String(c)));

// ---------- разбор мелких форматов ----------
const ARROW = /^(\S+) (->|-->) (\S+)$/;
const asList = v => (Array.isArray(v) ? v : v === undefined || v === null ? [] : [v]);
const isObj = v => v && typeof v === 'object' && !Array.isArray(v);

function parseArrow(key, where, P) {
  const m = ARROW.exec(String(key).trim());
  if (!m) { P.err(where, `ожидается "a -> b" (или "a --> b" для пунктира), а не ${JSON.stringify(key)}`); return null; }
  return { f: m[1], dash: m[2] === '-->', t: m[3] };
}

// элемент списка: "a -> b"  |  {"a -> b": "подпись"}  |  {"a -> b": {…опции}}
function entry(item, where, P) {
  if (typeof item === 'string') return { key: item, val: undefined };
  if (isObj(item) && Object.keys(item).length === 1) { const k = Object.keys(item)[0]; return { key: k, val: item[k] }; }
  P.err(where, 'ожидается строка "a -> b" или словарь из одного ключа "a -> b"');
  return null;
}

function checkKeys(obj, allowed, where, P) {
  for (const k of Object.keys(obj)) if (!allowed.includes(k)) P.err(where, `неизвестный ключ "${k}" (допустимы: ${allowed.join(', ')})`);
}

// ---------- схема ----------
const NODE_KEYS = ['id', 'at', 'label', 'sub', 'kind', 'info', 'stack', 'dash', 'pill', 'ref'];
const EDGE_OPTS = ['label', 'kind', 'out', 'in', 'via', 'label_at', 'both', 'tag', 'rel'];
// Тип связи — то, что связь означает, а не как она подписана.
//   flow — поток запроса/данных (по умолчанию);
//   uses — вызывает или задействует другой компонент;
//   carries — несёт данные, ответ, метрики;
//   runs_on — развёрнут на узле/хосте;
//   enforces — обеспечивает правило, политику или требование;
//   satisfies — закрывает требование из arch/controls.
export const RELATION_TYPES = ['flow', 'uses', 'carries', 'runs_on', 'enforces', 'satisfies'];
const SCHEME_KEYS = ['size', 'groups', 'host', 'notes', 'nodes', 'edges', 'routes', 'hint', 'toggles'];

function expandInfo(text, model, where, P) {
  const html = fromMd(String(text));
  return html.replace(/\{([\p{L}\p{N}_]+)\}/gu, (m, id) => {
    if (model.components[id] === undefined) { P.err(where, `info ссылается на неизвестный компонент {${id}} (arch/components.yaml)`); return m; }
    return fromMd(String(model.components[id]));
  });
}

function nodeBox(n) {
  const sl = n.sub ? String(n.sub).split('\n').length : 0;
  return { w: n.w || 110, h: n.h || (sl ? 34 + 12 * sl : 38) };
}

function compileScheme(S, model, where, P) {
  checkKeys(S, SCHEME_KEYS, where, P);
  if (!Array.isArray(S.size) || S.size.length !== 2) { P.err(where, 'size: [ширина, высота] обязателен'); S.size = [1200, 600]; }
  const out = { vb: [...S.size], nodes: [], edges: [] };
  if (S.hint) out.hint = fromMd(S.hint);
  if (S.host) { const [x, y, w, h, label] = S.host; out.host = { x, y, w, h, label }; }
  if (S.groups) out.groups = S.groups.map(([x, y, w, h, label]) => ({ x, y, w, h, label }));
  if (S.notes) out.notes = S.notes.map(([x, y, t, a]) => (a ? { x, y, t, a } : { x, y, t }));
  if (S.toggles) {
    out.toggles = {};
    for (const [k, v] of Object.entries(S.toggles)) out.toggles[k] = isObj(v) ? { l: v.label, ...(v.on === false ? { on: false } : {}) } : { l: String(v) };
  }

  const ids = new Set(), unplaced = [];
  asList(S.nodes).forEach((n, i) => {
    const w = `${where} › nodes[${i}]${n && n.id ? ' (' + n.id + ')' : ''}`;
    if (!isObj(n)) { P.err(w, 'узел должен быть словарём'); return; }
    checkKeys(n, NODE_KEYS, w, P);
    if (!n.id) { P.err(w, 'нет id'); return; }
    if (n.label === undefined) P.err(w, 'нет label');
    if (ids.has(n.id)) P.err(w, `id "${n.id}" уже есть в этой схеме`);
    ids.add(n.id);
    if (n.kind && !model.meta.kinds[n.kind]) P.err(w, `неизвестный kind "${n.kind}" (см. kinds в arch/meta.yaml)`);
    const o = { id: String(n.id), label: String(n.label) };
    if (n.at) { [o.x, o.y] = n.at; if (n.at[2] !== undefined) o.w = n.at[2]; if (n.at[3] !== undefined) o.h = n.at[3]; }
    else unplaced.push(o);
    if (n.ref !== undefined) {
      const ref = String(n.ref);
      if (!model.entities || !model.entities.byId[ref]) P.err(w, `ref "${ref}" не найден в arch/entities.yaml`);
      o.ref = ref;
    }
    if (n.sub !== undefined && n.sub !== '') o.sub = String(n.sub);
    if (n.kind) o.k = n.kind;
    if (n.info) o.i = expandInfo(n.info, model, w, P);
    for (const f of ['stack', 'dash', 'pill']) if (n[f]) o[f] = 1;
    out.nodes.push(o);
  });

  asList(S.edges).forEach((item, i) => {
    const w = `${where} › edges[${i}]`;
    const en = entry(item, w, P); if (!en) return;
    const a = parseArrow(en.key, w, P); if (!a) return;
    const o = en.val === undefined ? {} : isObj(en.val) ? en.val : { label: en.val };
    checkKeys(o, EDGE_OPTS, w, P);
    for (const id of [a.f, a.t]) if (!ids.has(id)) P.err(w, `узел "${id}" не объявлен в nodes этой схемы`);
    const e = { f: a.f, t: a.t };
    if (o.label !== undefined && o.label !== '') e.l = String(o.label);
    if (a.dash) e.d = 1;
    if (o.kind) { e.k = o.kind; if (!model.meta.kinds[o.kind]) P.err(w, `неизвестный kind "${o.kind}"`); }
    if (o.out) e.fs = o.out;
    if (o.in) e.ts = o.in;
    if (o.via) e.pts = o.via;
    if (o.label_at) e.lp = o.label_at;
    if (o.both) e.both = 1;
    if (o.tag) { e.cat = o.tag; if (!S.toggles || !S.toggles[o.tag]) P.err(w, `tag "${o.tag}" не описан в toggles`); }
    if (o.rel !== undefined) {
      if (!RELATION_TYPES.includes(String(o.rel))) P.err(w, `неизвестный тип связи rel "${o.rel}" (${RELATION_TYPES.join(' | ')})`);
      else if (String(o.rel) !== 'flow') e.rel = String(o.rel);
    }
    out.edges.push(e);
  });

  if (S.routes) {
    out.routes = {};
    for (const [name, v] of Object.entries(S.routes)) {
      const w = `${where} › routes › ${name}`;
      const path = isObj(v) ? v.path : v;
      if (typeof path !== 'string') { P.err(w, 'маршрут — строка "a -> b -> c" или {path, note}'); continue; }
      const list = path.split(/\s*->\s*/).filter(Boolean);
      for (const id of list) if (!ids.has(id)) P.err(w, `узел "${id}" не объявлен в nodes`);
      for (let k = 0; k < list.length - 1; k++) {
        if (!out.edges.some(e => (e.f === list[k] && e.t === list[k + 1]) || (e.t === list[k] && e.f === list[k + 1])))
          P.warn(w, `нет связи между "${list[k]}" и "${list[k + 1]}" (разрыв маршрута)`);
      }
      out.routes[name] = list;
      if (isObj(v) && v.note) (out.ri ||= {})[name] = fromMd(v.note);
    }
  }

  // геометрия: проверки и «лоток» для узлов без at
  const placed = out.nodes.filter(n => !unplaced.includes(n));
  placed.forEach((a, i) => {
    const A = nodeBox(a);
    if (a.x - A.w / 2 < 0 || a.y - A.h / 2 < 0 || a.x + A.w / 2 > out.vb[0] || a.y + A.h / 2 > out.vb[1])
      P.warn(`${where} › узел ${a.id}`, 'выходит за границы size');
    for (const b of placed.slice(i + 1)) {
      const B = nodeBox(b);
      if (Math.abs(a.x - b.x) < (A.w + B.w) / 2 - 2 && Math.abs(a.y - b.y) < (A.h + B.h) / 2 - 2) P.warn(`${where}`, `узлы "${a.id}" и "${b.id}" перекрываются`);
    }
  });
  if (unplaced.length) {
    const gap = 16, top = out.vb[1] + 14;
    let x = 14, y = top + 24, rowH = 0;
    for (const n of unplaced) {
      n.w ||= 150; const { h } = nodeBox(n); n.h ||= h;
      if (x + n.w > out.vb[0] - 14) { x = 14; y += rowH + gap; rowH = 0; }
      n.x = x + n.w / 2; n.y = y + n.h / 2; x += n.w + gap; rowH = Math.max(rowH, n.h);
      P.warn(`${where} › узел ${n.id}`, 'нет at: размещён автоматически внизу схемы; задайте at: [x, y, ширина]');
    }
    const bottom = y + rowH + 14;
    (out.groups ||= []).push({ x: 6, y: top - 6, w: out.vb[0] - 12, h: bottom - top + 6, label: 'Не размещено (добавьте at: [x, y, ширина])' });
    out.vb[1] = bottom + 6;
  }
  return out;
}

// ---------- диаграмма последовательности ----------
const SEQ_KEYS = ['width', 'px', 'actors', 'messages', 'phases', 'hint'];
function compileSequence(Q, model, where, P) {
  checkKeys(Q, SEQ_KEYS, where, P);
  const out = { actors: [], msgs: [] };
  if (Q.width) out.w = Q.width;
  if (Q.px) out.px = Q.px;
  if (Q.hint) out.hint = fromMd(Q.hint);
  const ids = new Set();
  asList(Q.actors).forEach((a, i) => {
    const w = `${where} › actors[${i}]`;
    checkKeys(a, ['id', 'label', 'kind', 'sub'], w, P);
    if (ids.has(a.id)) P.err(w, `дубль id "${a.id}"`);
    ids.add(a.id);
    if (a.kind && !model.meta.kinds[a.kind]) P.err(w, `неизвестный kind "${a.kind}"`);
    const o = { id: String(a.id), label: String(a.label), k: a.kind };
    if (a.sub) o.sub = String(a.sub);
    out.actors.push(o);
  });
  asList(Q.messages).forEach((item, i) => {
    const w = `${where} › messages[${i}]`;
    const en = entry(item, w, P); if (!en) return;
    const a = parseArrow(en.key, w, P); if (!a) return;
    for (const id of [a.f, a.t]) if (!ids.has(id)) P.err(w, `участник "${id}" не объявлен в actors`);
    const o = isObj(en.val) ? en.val : { label: en.val };
    checkKeys(o, ['label', 'note'], w, P);
    const m = { f: a.f, t: a.t, l: String(o.label) };
    if (o.note) m.d = fromMd(o.note);
    if (a.dash) m.ret = 1;
    out.msgs.push(m);
  });
  if (Q.phases) out.ph = Q.phases.map(([a, b, l], i) => {
    if (!(a >= 1 && b >= a && b <= out.msgs.length)) P.err(`${where} › phases[${i}]`, `номера шагов 1..${out.msgs.length}, a ≤ b`);
    return { a: a - 1, b: b - 1, l };
  });
  return out;
}

// ---------- блоки ----------
const BLOCK_TYPES = ['scheme', 'sequence', 'table', 'cards', 'steps', 'note', 'warn', 'html', 'heading', 'capacity'];

function compileBlock(b, model, where, P) {
  if (!isObj(b)) { P.err(where, 'блок должен быть словарём с одним типом: ' + BLOCK_TYPES.join(' | ')); return null; }
  const types = Object.keys(b).filter(k => BLOCK_TYPES.includes(k));
  const extra = Object.keys(b).filter(k => !BLOCK_TYPES.includes(k) && k !== 'title' && !(k === 'style' && (b.note !== undefined || b.warn !== undefined)));
  if (types.length !== 1) { P.err(where, `нужен ровно один тип блока из: ${BLOCK_TYPES.join(', ')}`); return null; }
  if (extra.length) P.err(where, `лишние ключи: ${extra.join(', ')}`);
  const type = types[0], v = b[type], w = `${where} › ${type}`;
  const title = b.title ? `<h3>${fromMd(b.title)}</h3>` : '';
  switch (type) {
    case 'scheme': return { t: 'scheme', S: compileScheme(v, model, w, P) };
    case 'sequence': return { t: 'seq', Q: compileSequence(v, model, w, P) };
    case 'capacity': return { t: 'capacity' };
    case 'heading': return { t: 'h', text: String(v) };
    case 'html': return { t: 'html', html: title + v };
    case 'note': return { t: 'html', html: title + `<div class="lead"${b.style ? ` style="${b.style}"` : ''}>${fromMd(String(v))}</div>` };
    case 'warn': return { t: 'html', html: title + `<div class="lead warn"${b.style ? ` style="${b.style}"` : ''}>${fromMd(String(v))}</div>` };
    case 'table': {
      checkKeys(v, ['head', 'rows', 'center'], w, P);
      const head = asList(v.head), rows = asList(v.rows);
      rows.forEach((r, i) => { if (!Array.isArray(r) || r.length !== head.length) P.err(`${w} › rows[${i}]`, `в строке ${Array.isArray(r) ? r.length : '?'} ячеек, в head ${head.length}`); });
      const cls = v.center ? head.map((_, i) => (v.center.includes(i) ? 'c' : '')) : undefined;
      return { t: 'html', html: title + tableHtml(head.map(h => cell(h)), rows.map(r => asList(r).map(cell)), cls) };
    }
    case 'cards': {
      const items = asList(v).map((c, i) => {
        checkKeys(c, ['title', 'text', 'color', 'chips'], `${w}[${i}]`, P);
        const color = c.color || '#2563eb', text = c.text ? fromMd(c.text) : '';
        return c.chips ? chipCardHtml(fromMd(c.title), color, asList(c.chips).map(x => fromMd(String(x))), text) : cardHtml(fromMd(c.title), text, color);
      });
      return { t: 'html', html: title + `<div class="cards">${items.join('')}</div>` };
    }
    case 'steps':
      return { t: 'html', html: title + `<ol class="steps">${asList(v).map(x => `<li>${fromMd(String(x))}</li>`).join('')}</ol>` };
  }
}

function compileView(v, tab, mode, model, P) {
  const where = `${tab.file} › ${mode === 'o' ? 'overview' : 'detail'}`;
  if (!isObj(v)) { P.err(where, 'раздел обязателен: {lead, blocks}'); return { lead: '', blocks: [] }; }
  checkKeys(v, ['lead', 'blocks'], where, P);
  const out = { blocks: [] };
  if (v.lead) out.lead = fromMd(v.lead);
  asList(v.blocks).forEach((b, i) => { const c = compileBlock(b, model, `${where} › blocks[${i}]`, P); if (c) out.blocks.push(c); });
  return out;
}

// ---------- карта перехода (arch/map.yaml, необязательно) ----------
const MAP_KEYS = ['title', 'entries'];
const ENTRY_KEYS = ['asis', 'tobe', 'status', 'note'];

export function compileMap(model, P) {
  const m = model.map;
  if (!m) return;
  const where = 'map.yaml';
  checkKeys(m, MAP_KEYS, where, P);
  const primary = primaryDir(model);
  const secondary = asisDirs(model);
  const tobeIds = nodeIdsOf(model, [primary]);
  const asisIds = nodeIdsOf(model, secondary);
  for (const id of Object.keys(model.components)) asisIds.add(id);
  const seenFrom = new Set();
  asList(m.entries).forEach((e, i) => {
    const w = `${where} › entries[${i}]`;
    if (!isObj(e)) { P.err(w, 'позиция карты — словарь {asis, tobe, status, note}'); return; }
    checkKeys(e, ENTRY_KEYS, w, P);
    if (!e.status) P.err(w, `нет status (${STATUSES.join(' | ')})`);
    else if (!STATUSES.includes(e.status)) P.err(w, `неизвестный status "${e.status}" (${STATUSES.join(' | ')})`);
    if (e.status === 'new') {
      if (!e.tobe) P.err(w, 'для status new нужен tobe');
    } else if (!e.asis) P.err(w, `нужен asis (или status new)`);
    else if (!asisIds.has(String(e.asis))) P.err(w, `AS-IS "${e.asis}" не найден ни в components.yaml, ни в узлах разделов ${secondary.join(', ')}`);
    if (e.asis) {
      if (seenFrom.has(String(e.asis))) P.warn(w, `AS-IS "${e.asis}" встречается в карте повторно`);
      seenFrom.add(String(e.asis));
    }
    if (e.tobe && !tobeIds.has(String(e.tobe))) P.err(w, `TO-BE узел "${e.tobe}" не найден ни в одной схеме раздела ${primary}`);
    if ((e.status === 'move' || e.status === 'keep') && !e.tobe) P.warn(w, `status "${e.status}" без tobe: целевой узел не зафиксирован`);
  });
}

// Покрытие карты: какие позиции AS-IS не упомянуты.
export function mapCoverage(model) {
  const asisIds = nodeIdsOf(model, asisDirs(model));
  const components = Object.keys(model.components);
  const mapped = new Set(asList(model.map?.entries).map(e => String(e.asis)).filter(Boolean));
  return {
    components,
    mapped: components.filter(id => mapped.has(id)),
    missing: components.filter(id => !mapped.has(id)),
    nodes: [...asisIds],
    entries: asList(model.map?.entries).length,
  };
}

export function compile(model) {
  const P = new Problems();
  const m = model.meta || {};
  for (const k of ['title', 'heading', 'subtitle', 'kinds'])
    if (m[k] === undefined) P.err('meta.yaml', `нет ключа ${k}`);
  m.kinds ||= {};
  if (model.dir) verifyLock(model, model.dir, P);
  compileMap(model, P);
  compileDecisions(model, P);
  compileEntities(model, P);
  compileInventory(model, P);
  compileHypotheses(model, P);
  compileInvariants(model, P);
  compileControls(model, P);
  compileContract(model, P);
  const K = {}, KN = {};
  for (const [id, v] of Object.entries(m.kinds)) { K[id] = v[0]; KN[id] = v[1]; }
  const D = {};
  for (const [id, text] of Object.entries(model.components)) D[id] = fromMd(String(text));

  const TABS = [], SECTIONS = [], seen = new Set();
  const counts = {};
  for (const tab of model.tabs) counts[tab.section] = (counts[tab.section] || 0) + 1;
  const num = {};
  let cur = null;
  for (const tab of model.tabs) {
    if (tab.section !== cur) {
      cur = tab.section;
      const sec = model.sections.find(s => s.dir === cur) || { label: String(cur).toUpperCase(), hidden: false };
      SECTIONS.push({ label: sec.label, hidden: !!sec.hidden, locked: !!sec.locked, banner: sec.banner, from: TABS.length, to: TABS.length, count: counts[cur] || 0 });
      num[cur] = 0;
    }
    if (seen.has(tab.id)) P.err(tab.file, 'дубль вкладки');
    seen.add(tab.id);
    if (!tab.title) P.err(tab.file, 'нет title');
    const S = SECTIONS[SECTIONS.length - 1];
    const pad = n => String(n).padStart(String(S.count).length, '0');
    const n = ++num[cur];
    const t = `${S.label} · ${pad(n)}. ${tab.title}`;
    // t — полный заголовок (печать, ссылки), s — короткий для меню: раздел показан над группой
    const T = { t, s: `${pad(n)}. ${tab.title}`, n, o: compileView(tab.overview, tab, 'o', model, P), d: compileView(tab.detail, tab, 'd', model, P) };
    if (S.banner) for (const v of [T.o, T.d]) v.lead = fromMd(String(S.banner)) + ' ' + (v.lead || '');
    TABS.push(T);
    S.to = TABS.length;
  }
  const firstHidden = SECTIONS.find(s => s.hidden);
  return {
    legacy: { K, KN, D, TABS, SECTIONS, TOBE_COUNT: firstHidden ? firstHidden.from : TABS.length },
    problems: P,
  };
}
