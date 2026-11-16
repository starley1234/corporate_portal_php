#!/usr/bin/env node
// Портфель процессов подразделения: карточки -> оценка -> вкладки страницы.
//
//   node scripts/portfolio.mjs [--dir dept] [--json] [--check] [--no-write]
//
// Читает карточки dept/portfolio/*.yaml (кроме config.yaml) и настройки из config.yaml,
// считает оценку по четырём критериям (ценность, осуществимость, риск, скорость),
// применяет жёсткие фильтры и перезаписывает две сгенерированные вкладки:
//   arch/portfolio/02-ranked.yaml  — ранжированная таблица портфеля
//   arch/portfolio/03-map.yaml     — карта «оценка × скорость» по квадрантам
// Карточки — источник истины; таблицу и карту править руками не нужно.

import fs from 'node:fs';
import path from 'node:path';
import YAML from 'yaml';

const argv = process.argv.slice(2);
const flags = {};
for (let i = 0; i < argv.length; i++) {
  if (!argv[i].startsWith('--')) continue;
  const k = argv[i].slice(2);
  flags[k] = argv[i + 1] && !argv[i + 1].startsWith('--') ? argv[++i] : true;
}

const DIR = path.resolve(process.cwd(), typeof flags.dir === 'string' ? flags.dir : 'dept');
const CARDS = path.join(DIR, 'portfolio');
const ARCH = path.join(DIR, 'arch');
const die = m => { console.error(`ошибка: ${m}`); process.exit(1); };
const warn = m => console.error(`предупреждение: ${m}`);
const num = v => typeof v === 'number' && Number.isFinite(v);
const clamp01 = v => Math.min(1, Math.max(0, v));
const round = (v, n = 1) => Number(v.toFixed(n));

if (!fs.existsSync(CARDS)) die(`нет каталога карточек: ${CARDS} (задайте --dir)`);
const cfgFile = path.join(CARDS, 'config.yaml');
if (!fs.existsSync(cfgFile)) die(`нет ${cfgFile}: без весов и порогов считать нечем`);
const cfg = YAML.parse(fs.readFileSync(cfgFile, 'utf8'));
const W = cfg.weights || {};
const S = { hours_scale: 1000, quick_win: 60, speed_edge: 0.55, local_llm: true, ...(cfg.settings || {}) };
const FIT = cfg.class_fit || { public: 1, internal: 0.75, confidential: 0.45 };
// Квартальный цикл: сколько сил у команды и сколько стоит одна задача внедрения.
const Q = { name: 'квартал', ...(cfg.quarter || {}) };
const C = { fte: 0, hours_per_fte: 480, reserve: 0.2, max_projects: 4, ...(cfg.capacity || {}) };
const EF = { base: 60, per_integration: 40, per_missing_data: 30, confidential_extra: 60, ...(cfg.effort || {}) };
const budget = num(C.fte) && C.fte > 0
  ? Math.round(C.fte * C.hours_per_fte * (1 - clamp01(C.reserve)))
  : null;
// Стоимость внедрения: замер из карточки, если он есть, иначе прозрачная оценка из тех же полей.
const effortOf = c => (num(c.build_hours) ? c.build_hours
  : EF.base + EF.per_integration * c.integration_effort + EF.per_missing_data * (5 - c.data_ready)
    + (c.data_class === 'confidential' ? EF.confidential_extra : 0));

const wsum = ['value', 'feasibility', 'risk', 'speed'].reduce((n, k) => n + (W[k] || 0), 0);
if (Math.abs(wsum - 1) > 0.01) warn(`веса в config.yaml дают в сумме ${round(wsum, 3)}, а не 1 — оценка будет смещена`);
for (const k of ['value', 'feasibility', 'risk', 'speed']) if (!num(W[k])) die(`в weights нет "${k}"`);

// ---------- чтение карточек ----------
const RANGE = {
  volume: [0, 1e7], minutes: [0, 1e5], error_cost: [1, 5], strategic: [1, 5],
  data_ready: [1, 5], rules_maturity: [1, 5], llm_share: [0, 1], regulatory: [1, 5],
  integration_effort: [1, 5],
};
const cards = [];
for (const f of fs.readdirSync(CARDS).filter(f => /\.ya?ml$/.test(f) && f !== 'config.yaml').sort()) {
  const where = `portfolio/${f}`;
  let c;
  try { c = YAML.parse(fs.readFileSync(path.join(CARDS, f), 'utf8')) || {}; } catch (e) { die(`${where}: не разбирается YAML — ${e.message}`); }
  if (!c.code) die(`${where}: нет code`);
  if (!c.name) die(`${where}: нет name`);
  for (const [k, [lo, hi]] of Object.entries(RANGE)) {
    if (!num(c[k])) die(`${where}: поле "${k}" должно быть числом`);
    if (c[k] < lo || c[k] > hi) die(`${where}: поле "${k}" = ${c[k]}, ожидается ${lo}..${hi}`);
  }
  if (!(c.data_class in FIT)) die(`${where}: data_class "${c.data_class}" — допустимо: ${Object.keys(FIT).join(' | ')}`);
  cards.push({ ...c, file: f });
}
if (!cards.length) die(`${CARDS}: нет карточек процессов`);
const seen = new Set();
for (const c of cards) { if (seen.has(c.code)) die(`код ${c.code} повторяется`); seen.add(c.code); }

// ---------- оценка ----------
// V — ценность: масштаб (часы/мес по логарифму), цена ошибки, стратегическая значимость.
// F — осуществимость: готовность данных, зрелость правил, доля шага, где нужна LLM (меньше — проще).
// R — риск: класс данных и регуляторные требования.
// T — скорость: сложность интеграции наоборот.
const evaluated = cards.map(c => {
  const hours = c.volume * c.minutes / 60;
  const H = Math.min(1, Math.log10(1 + hours) / Math.log10(1 + S.hours_scale));
  const E = c.error_cost / 5, St = c.strategic / 5;
  const V = 0.5 * H + 0.25 * E + 0.25 * St;
  const F = 0.4 * (c.data_ready / 5) + 0.3 * (c.rules_maturity / 5) + 0.3 * (1 - c.llm_share);
  const R = 0.6 * FIT[c.data_class] + 0.4 * (1 - (c.regulatory - 1) / 4);
  const T = 1 - (c.integration_effort - 1) / 4;

  const blockers = [];
  if (!String(c.owner || '').trim()) blockers.push('нет владельца процесса');
  if (c.data_ready <= 1) blockers.push('нет пригодных данных');
  if (c.data_class === 'confidential' && !S.local_llm) blockers.push('confidential без локальной модели');
  const conditions = [];
  if (c.write_to_source) conditions.push('запись в источник — только с подтверждением человеком');
  if (c.golden_set === false) conditions.push('нет эталонного набора — в промышленную нельзя');

  const score = 100 * clamp01(W.value * V + W.feasibility * F + W.risk * R + W.speed * T);
  const quadrant = blockers.length ? 'pause'
    : score >= S.quick_win ? (T >= S.speed_edge ? 'quick' : 'strategic')
    : (T >= S.speed_edge ? 'fill' : 'later');

  return { ...c, hours, V, F, R, T, score, blockers, conditions, quadrant, effort: Math.round(effortOf(c)), measured: !!c.baseline_hours };
});

const ranked = evaluated.filter(e => !e.blockers.length).sort((a, b) => b.score - a.score || a.code.localeCompare(b.code));
ranked.forEach((e, i) => { e.rank = i + 1; e.wave = i < 4 ? 1 : i < 9 ? 2 : 3; });
const paused = evaluated.filter(e => e.blockers.length).sort((a, b) => b.score - a.score);

const QUAD = {
  quick: { label: 'Быстрые победы', note: 'балл выше порога, результат близко' },
  strategic: { label: 'Крупные задачи', note: 'высокий балл, длинный путь к результату' },
  fill: { label: 'Мелкие быстрые', note: 'быстро, но польза небольшая' },
  later: { label: 'Не сейчас', note: 'ни балла, ни быстрой пользы' },
  pause: { label: 'Пауза', note: 'сработали жёсткие стопы' },
};

// ---------- вкладка 02: ранжированная таблица ----------
const rows = [...ranked, ...paused].map(e => {
  const decision = e.blockers.length ? `пауза: ${e.blockers.join('; ')}`
    : e.conditions.length ? `волна ${e.wave}; условие: ${e.conditions.join('; ')}`
    : `волна ${e.wave}`;
  return [
    String(e.blockers.length ? '—' : e.rank),
    e.code,
    e.name,
    e.owner || '—',
    String(Math.round(e.hours)),
    String(round(e.score)),
    QUAD[e.quadrant].label,
    decision,
  ];
});
const sumHours = ranked.reduce((n, e) => n + e.hours, 0);
const waveHours = [1, 2, 3].map(w => Math.round(ranked.filter(e => e.wave === w).reduce((n, e) => n + e.hours, 0)));

const rankedTab = {
  title: 'Список задач по баллам',
  overview: {
    lead: '**Этот список собирается сам из карточек задач, вручную его не пишут.** Задачи идут по убыванию балла: пересчитать — `npm run portfolio`. Жёсткие стопы (нет хозяина процесса, нет данных, секретные данные без своей модели) балл не понижают — они останавливают задачу: она остаётся в очереди, пока причина не устранена. Числа в карточках пока демонстрационные: после разбора процесса их заменяют замерами.',
    blocks: [
      {
        table: {
          head: ['№', 'Код', 'Задача', 'Хозяин процесса', 'Часов в месяц', 'Балл', 'Категория', 'Что делаем'],
          rows,
        },
      },
      {
        note: `**Итого в работе:** ${ranked.length} задач, около ${Math.round(sumHours)} часов ручной работы в месяц. **Волна 1** — ${ranked.filter(e => e.wave === 1).length} задачи (~${waveHours[0]} ч/мес), **волна 2** — ${ranked.filter(e => e.wave === 2).length} (~${waveHours[1]} ч/мес), **волна 3** — ${ranked.filter(e => e.wave === 3).length} (~${waveHours[2]} ч/мес). В паузе — ${paused.length}.`,
      },
      {
        warn: '**Числа в этой таблице — пример, а не результат.** Объём, минуты на случай и оценки взяты из заготовок. Пока процесс не разобран и не замерен «как было», балл годится только для сравнения задач между собой — обещать по нему экономию нельзя.',
      },
    ],
  },
  detail: {
    lead: '**Расчёт открыт, чтобы с ним можно было спорить.** Балл считается из полей карточки; веса и пороги лежат в `dept/portfolio/config.yaml`. Если балл кажется неверным, правят не таблицу, а карточку или веса — и это видно в истории изменений.',
    blocks: [
      {
        table: {
          head: ['Код', 'Часов в месяц', 'Польза', 'Осуществимость', 'Риски', 'Скорость', 'Балл'],
          rows: evaluated.slice().sort((a, b) => b.score - a.score).map(e => [e.code, String(Math.round(e.hours)), String(round(e.V, 2)), String(round(e.F, 2)), String(round(e.R, 2)), String(round(e.T, 2)), String(round(e.score))]),
        },
      },
      {
        table: {
          head: ['Критерий', 'Из чего складывается', 'Как понимать'],
          rows: [
            ['Польза', 'часы в месяц (0.5) · цена ошибки (0.25) · важность для стратегии (0.25)', 'Часы считаем по шкале, чтобы задача на 3000 случаев не вытеснила все остальные одним объёмом'],
            ['Осуществимость', 'наличие данных (0.4) · можно ли описать правила (0.3) · насколько нужен ИИ (0.3)', 'Чем меньше нужен ИИ, тем быстрее и дешевле результат: сначала соединяем данные, потом добавляем генерацию'],
            ['Риски', 'какие данные (0.6) · требования проверяющих (0.4, чем жёстче, тем ниже)', 'Секретные и персональные данные стоят дороже: отдельный контур и отдельные согласования'],
            ['Скорость', 'сколько систем надо соединить (чем больше, тем ниже)', 'Один источник — быстро; пять источников и сканы — долго'],
          ],
        },
      },
      {
        note: 'Формула: `балл = 100 × (0.35·польза + 0.30·осуществимость + 0.20·риски + 0.15·скорость)`. Порог «берём в работу» — 60; «быстрая победа» — балл от 60 при высокой скорости. Все значения — в `dept/portfolio/config.yaml`.',
      },
    ],
  },
};

// ---------- вкладка 03: карта квадрантов ----------
// Цвет узла берётся из kinds в meta.yaml проекта; если подходящего типа нет — узел остаётся без kind.
const META_KINDS = (() => {
  try { return Object.keys(YAML.parse(fs.readFileSync(path.join(ARCH, 'meta.yaml'), 'utf8')).kinds || {}); } catch { return []; }
})();
const KIND_WISH = {
  quick: ['ok', 'good', 'done'],
  strategic: ['dec', 'ctrl', 'core'],
  fill: ['app', 'core'],
  later: ['local', 'ext'],
  pause: ['err', 'sec'],
};
const kindFor = q => (KIND_WISH[q] || []).find(k => META_KINDS.includes(k));
const size = [1560, 1020];
const zones = [
  { q: 'quick', box: [20, 40, 750, 400] },
  { q: 'strategic', box: [790, 40, 750, 400] },
  { q: 'fill', box: [20, 460, 750, 400] },
  { q: 'later', box: [790, 460, 750, 400] },
  { q: 'pause', box: [20, 880, 1520, 100] },
];
const shortName = s => {
  const t = String(s);
  if (t.length <= 30) return t;
  const cut = t.slice(0, 30);
  return cut.slice(0, Math.max(12, cut.lastIndexOf(' '))) + '…';
};
const nodes = [];
for (const { q, box } of zones) {
  const list = evaluated.filter(e => e.quadrant === q).sort((a, b) => b.score - a.score);
  const [gx, gy, gw] = box;
  const cols = q === 'pause' ? 4 : 2;
  const rowsN = Math.max(1, Math.ceil(list.length / cols));
  const dx = (gw - 340) / Math.max(1, cols - 1 || 1);
  const dy = q === 'pause' ? 0 : Math.min(85, (box[3] - 130) / Math.max(1, rowsN - 1));
  list.forEach((e, i) => {
    const col = i % cols, row = Math.floor(i / cols);
    const x = cols === 1 ? gx + gw / 2 : gx + 170 + col * dx;
    const y = q === 'pause' ? gy + 50 : gy + 90 + row * dy;
    nodes.push({
      id: e.code.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
      at: [Math.round(x), Math.round(y), q === 'pause' ? 300 : q === 'later' || q === 'fill' ? 300 : 300],
      label: `${e.code} · ${shortName(e.name)}`,
      sub: `${Math.round(e.hours)} ч/мес · ${round(e.score)}/100${e.blockers.length ? ' · пауза' : ''}`,
      ...(kindFor(q) ? { kind: kindFor(q) } : {}),
    });
  });
}
const mapTab = {
  title: 'Карта задач: балл и скорость',
  overview: {
    lead: '**Карта отвечает на вопрос «за что берёмся в этом квартале», а не «что интереснее».** Слева-справа — насколько быстро можно получить результат (есть ли данные, сколько систем соединять). Сверху-вниз — балл: сколько ручной работы уходит и чем грозит ошибка. Верхний левый угол — крупные задачи: ими занимаются, но не всей командой и не в этом квартале.',
    blocks: [
      {
        scheme: {
          size,
          groups: zones.map(z => [...z.box, `${QUAD[z.q].label} · ${QUAD[z.q].note}`]),
          nodes,
          edges: [],
          hint: 'Точки расставляет скрипт по категориям (`npm run portfolio`), внутри категории — по убыванию балла. Меняете карточку — меняется карта; ручные правки карты скрипт перезапишет.',
        },
      },
      {
        table: {
          head: ['Категория', 'Что это значит', 'Что делаем', 'Задач', 'Часов в месяц'],
          rows: ['quick', 'strategic', 'fill', 'later', 'pause'].map(q => {
            const list = evaluated.filter(e => e.quadrant === q);
            const action = {
              quick: 'Берём в пилот первыми: короткий путь к подтверждённой пользе',
              strategic: 'Планируем волной 2–3, с отдельным обоснованием и защитой бюджета',
              fill: 'Только если нужен быстрый видимый результат, чтобы укрепить доверие',
              later: 'Не берём; пересматриваем раз в квартал',
              pause: 'Возвращаемся, когда устранена причина: хозяин, данные, контур',
            }[q];
            return [QUAD[q].label, QUAD[q].note, action, String(list.length), String(Math.round(list.reduce((n, e) => n + e.hours, 0)))];
          }),
        },
      },
    ],
  },
  detail: {
    lead: '**Как читать карту перед руководством.** Это не список «что можно сделать с ИИ», а ответ на вопрос, чем команда занята следующие три месяца и что из этого можно остановить. Категории нужны, чтобы не путать «интересно» с «выгодно сейчас».',
    blocks: [
      {
        table: {
          head: ['Ситуация', 'Что делаем', 'Чем рискуем, если сделаем наоборот'],
          rows: [
            ['Задач больше, чем сил', 'Берём только верхнюю часть «быстрых побед» и одну крупную задачу', 'Распыление: несколько пилотов без внедрения, доверие к отделу падает'],
            ['Задача крупная и долгая', 'Готовим данные и обоснование отдельным потоком, не запуская пилот', 'Пилот на сырых данных дискредитирует саму идею'],
            ['Задача с причиной остановки', 'Оставляем в очереди и пишем условие возврата: какой хозяин и какие данные нужны', 'Задача «висит» годами и занимает место в списке'],
            ['Задача остановлена из-за данных или ИБ', 'Меняем схему (своя модель, обезличивание) или отказываемся', 'Запуск в обход правил: один инцидент дороже всей программы'],
          ],
        },
      },
      {
        note: 'Карта пересобирается вместе со списком: `npm run portfolio`. Ручные правки этих вкладок перезаписываются — меняйте карточки, веса и пороги.',
      },
    ],
  },
};


// ---------- квартальный план и решения портфельного совета ----------
// Решения человека лежат в dept/reviews/<квартал>.yaml и главнее расчёта; расхождение не ошибка,
// но должно быть объяснено причиной.
const REVIEW_FILE = path.join(DIR, 'reviews', `${Q.name}.yaml`);
let review = null;
if (fs.existsSync(REVIEW_FILE)) {
  try { review = YAML.parse(fs.readFileSync(REVIEW_FILE, 'utf8')) || {}; }
  catch (e) { die(`${path.relative(process.cwd(), REVIEW_FILE)}: не разбирается YAML — ${e.message}`); }
}
const DECISIONS = { take: 'в план', next: 'следующая очередь', pause: 'пауза', stop: 'остановлено' };
const reviewOf = new Map();
for (const d of (review && review.decisions) || []) {
  if (!d || !d.code) die(`${path.relative(process.cwd(), REVIEW_FILE)}: у решения нет code`);
  if (!seen.has(String(d.code))) die(`${path.relative(process.cwd(), REVIEW_FILE)}: решения по коду ${d.code}, а такой карточки нет`);
  if (!(d.decision in DECISIONS)) die(`${path.relative(process.cwd(), REVIEW_FILE)}: ${d.code} — decision "${d.decision}", допустимо: ${Object.keys(DECISIONS).join(' | ')}`);
  if (!String(d.reason || '').trim()) warn(`${path.relative(process.cwd(), REVIEW_FILE)}: ${d.code} — решение без причины; через квартал никто не вспомнит, почему`);
  reviewOf.set(String(d.code), d);
}

const plan = [], queue = [], stopped = [], pausedFinal = [];
let used = 0, overBudget = 0, autoPlan = new Set();
if (budget) {
  // сверху вниз по баллу, без перескоков: если задача не влезла, следующие ждут квартала
  for (const e of ranked) {
    if (plan.length >= C.max_projects) { queue.push(e); continue; }
    if (used + e.effort <= budget) { plan.push(e); used += e.effort; } else queue.push(e);
  }
  for (const e of paused) pausedFinal.push(e);
  autoPlan = new Set(plan.map(e => e.code));
  // решения человека: take — в план, next — в очередь, pause/stop — из плана вон
  const takeExtra = [];
  for (const [code, d] of reviewOf) {
    const e = ranked.find(x => x.code === code) || paused.find(x => x.code === code);
    if (!e) continue;
    if (d.decision === 'take') {
      const at = plan.findIndex(x => x.code === code);
      if (at === -1) {
        plan.push({ ...e, byReview: true });
        used += e.effort;
        if (used > budget) { overBudget++; }
        const qi = queue.findIndex(x => x.code === code);
        if (qi >= 0) queue.splice(qi, 1);
      }
      e.decisionNote = d;
    } else if (d.decision === 'next') {
      const at = plan.findIndex(x => x.code === code);
      if (at >= 0) { const [x] = plan.splice(at, 1); used -= x.effort; queue.unshift(x); }
      e.decisionNote = d;
    } else if (d.decision === 'pause' || d.decision === 'stop') {
      const at = plan.findIndex(x => x.code === code);
      if (at >= 0) { const [x] = plan.splice(at, 1); used -= x.effort; }
      const qi = queue.findIndex(x => x.code === code);
      if (qi >= 0) queue.splice(qi, 1);
      const pi = pausedFinal.findIndex(x => x.code === code);
      if (pi >= 0) pausedFinal.splice(pi, 1);
      (d.decision === 'stop' ? stopped : pausedFinal).push({ ...e, decisionNote: d });
    }
  }
  plan.sort((a, b) => b.score - a.score);
}
const planRows = [];
let running = 0;
plan.forEach((e, i) => {
  running += e.effort;
  const d = e.decisionNote;
  planRows.push([
    String(i + 1), e.code, e.name, e.owner || '—',
    String(e.effort), String(budget ? Math.max(0, budget - running) : '—'),
    String(round(e.score)),
    e.measured ? 'есть' : 'нужен',
    d ? DECISIONS[d.decision] + (d.reason ? `: ${d.reason}` : '') : (e.byReview ? 'в план по решению совета' : 'по расчёту'),
  ]);
});
const unfitted = queue.length ? queue : [];
// где человек пошёл против расчёта — это нормально, но должно быть с причиной
const deviations = [...reviewOf.entries()].filter(([code, d]) => {
  const autoIn = autoPlan.has(code);
  return d.decision === 'take' ? !autoIn : autoIn;
});


// ---------- вкладка 04: план квартала ----------
const queueRows = unfitted.map(e => {
  const need = e.effort;
  const free = budget ? Math.max(0, budget - used) : 0;
  return `${e.code} — ${e.name}: нужно ~${need} ч, свободно ${free} ч`;
});
const stopRows = [
  ...pausedFinal.map(e => `${e.code} — пауза: ${(e.decisionNote && e.decisionNote.reason) || e.blockers.join('; ')}`),
  ...stopped.map(e => `${e.code} — остановлено: ${(e.decisionNote && e.decisionNote.reason) || 'причина не записана'}`),
];
const noBaseline = plan.filter(e => !e.measured);
const reviewRows = [...reviewOf.entries()].map(([code, d]) => [
  code, DECISIONS[d.decision], d.reason || '—', d.due || '—', d.owner || '—',
]);
const quarterTab = budget && planRows.length ? {
  title: `План квартала · ${Q.name}`,
  overview: {
    lead: `**${Q.name}: что команда делает в этом квартале.** Бюджет ${budget} ч, занято ${used} ч ` +
      `(${Math.round(100 * used / budget)}%), задач в плане ${plan.length} из ${ranked.length} прошедших отбор. ` +
      `Список собран расчётом и поправлен решениями портфельного совета — расхождение с расчётом допустимо, ` +
      `если причина записана.`,
    blocks: [
      { table: { head: ['№', 'Код', 'Задача', 'Хозяин процесса', 'Часы внедрения', 'Остаток бюджета', 'Балл', 'Замер «как было»', 'Почему в плане'], rows: planRows } },
      { note: `Очередь на следующий квартал: ${unfitted.length} · пауза: ${pausedFinal.length} · остановлено: ${stopped.length}. ` +
        `Правило: задачи берутся сверху вниз по баллу, без перескоков, и не больше ${C.max_projects} одновременно.` },
      ...(overBudget ? [{ warn: `Бюджет превышен на ${used - budget} ч: совет взял в план больше, чем есть сил. Это осознанное решение — но тогда что-то из плана нужно снять или сдвинуть срок.` }] : []),
      ...(noBaseline.length ? [{ warn: `**Замера «как было» нет у ${noBaseline.length} задач плана.** По правилу подразделения пилот без замера не считается запущенным: до старта нужен замер, иначе выигрыш будет нечем доказать.` }] : []),
      ...(queueRows.length ? [{ steps: ['Не влезло в квартал (очередь, а не отказ):', ...queueRows] }] : []),
      ...(stopRows.length ? [{ steps: ['Не берём и почему:', ...stopRows] }] : []),
      { note: `Пересмотр — в конце ${Q.name}: решения пишутся в \`dept/reviews/${Q.name}.yaml\`, карточки обновляются по факту, затем \`npm run portfolio\`.` },
    ],
  },
  detail: {
    lead: '**Откуда взялся план.** Бюджет — это силы команды, а не пожелания; стоимость задачи — оценка по полям карточки. Оба числа открыты, чтобы с ними можно было спорить.',
    blocks: [
      {
        table: {
          head: ['Что', 'Значение', 'Откуда'],
          rows: [
            ['Людей в команде внедрения', String(C.fte), '`capacity.fte` в config.yaml'],
            ['Часов на человека за квартал', String(C.hours_per_fte), '`capacity.hours_per_fte`'],
            ['Запас на поддержку и сюрпризы', `${Math.round(clamp01(C.reserve) * 100)}%`, '`capacity.reserve`'],
            ['Бюджет квартала', `${budget} ч`, 'fte × часы × (1 − запас)'],
            ['Занято планом', `${used} ч`, 'сумма часов внедрения задач плана'],
            ['Оценка стоимости задачи', 'от ' + Math.round(EF.base) + ' ч', `base ${EF.base} + ${EF.per_integration}×сложность интеграции + ${EF.per_missing_data}×(5−готовность данных)` + (EF.confidential_extra ? ` + ${EF.confidential_extra} для confidential` : '')],
            ['Одновременно в работе', String(C.max_projects), '`capacity.max_projects` — правило, а не расчёт'],
          ],
        },
      },
      ...(reviewRows.length ? [{ table: { head: ['Код', 'Решение совета', 'Причина', 'Срок', 'Кто'], rows: reviewRows } }] : []),
      ...(deviations.length ? [{ note: `Совет пошёл против расчёта по ${deviations.length} задачам (${deviations.map(([c]) => c).join(', ')}). Это допустимо: расчёт — входные данные, а не приговор. Важно, что причина записана.` }] : []),
      { note: 'Правки в эту вкладку не вносятся руками: меняются карточки (`dept/portfolio/*.yaml`), настройки (`config.yaml`) или решения (`dept/reviews/<квартал>.yaml`), затем `npm run portfolio`.' },
    ],
  },
} : null;

// ---------- запись и вывод ----------
const out = {
  ranked: path.join(DIR, cfg.output?.ranked || 'arch/portfolio/02-ranked.yaml'),
  map: path.join(DIR, cfg.output?.map || 'arch/portfolio/03-map.yaml'),
  ...(quarterTab ? { plan: path.join(DIR, cfg.output?.plan || 'arch/portfolio/04-quarter.yaml') } : {}),
};
const str = o => YAML.stringify(o, { lineWidth: 0 });
if (!flags.check && !flags['no-write']) {
  for (const [k, file] of Object.entries(out)) {
    fs.mkdirSync(path.dirname(file), { recursive: true });
    const head = `# СГЕНЕРИРОВАНО: node scripts/portfolio.mjs (источник — dept/portfolio/*.yaml). Руками не править.\n`;
    const tab = k === 'ranked' ? rankedTab : k === 'map' ? mapTab : quarterTab;
    fs.writeFileSync(file, head + str(tab));
  }
}

if (flags.json) {
  console.log(JSON.stringify({
    cards: evaluated.length,
    weights: W,
    ranked: ranked.map(e => ({ rank: e.rank, wave: e.wave, code: e.code, name: e.name, hours: round(e.hours), score: round(e.score), quadrant: e.quadrant, conditions: e.conditions })),
    paused: paused.map(e => ({ code: e.code, name: e.name, score: round(e.score), blockers: e.blockers })),
    quarter: budget ? {
      name: Q.name, budget, used, over_budget: Math.max(0, used - budget),
      plan: plan.map(e => ({ code: e.code, name: e.name, effort: e.effort, score: round(e.score), measured: e.measured, by_review: !!e.byReview })),
      queue: unfitted.map(e => ({ code: e.code, effort: e.effort })),
      paused: pausedFinal.map(e => ({ code: e.code, reason: (e.decisionNote && e.decisionNote.reason) || e.blockers.join('; ') })),
      stopped: stopped.map(e => ({ code: e.code, reason: e.decisionNote && e.decisionNote.reason })),
      review: review ? { file: path.relative(process.cwd(), REVIEW_FILE), decisions: reviewOf.size, deviations: deviations.map(([c]) => c) } : null,
    } : null,
  }, null, 2));
} else {
  console.log(`# Список задач · ${evaluated.length} карточек · ${Math.round(sumHours)} часов ручной работы в месяц\n`);
  console.log('| № | Волна | Код | Задача | Часов/мес | Балл | Категория |');
  console.log('|---|---|---|---|---|---|---|');
  for (const e of ranked) console.log(`| ${e.rank} | ${e.wave} | ${e.code} | ${e.name} | ${Math.round(e.hours)} | ${round(e.score)} | ${QUAD[e.quadrant].label} |`);
  if (paused.length) {
    console.log('\n## Пауза: жёсткие стопы');
    for (const e of paused) console.log(`- ${e.code} ${e.name} — ${e.blockers.join('; ')}`);
  }
  const withConditions = ranked.filter(e => e.conditions.length);
  if (withConditions.length) {
    console.log('\n## Условия перед запуском в работу');
    for (const e of withConditions) console.log(`- ${e.code} — ${e.conditions.join('; ')}`);
  }
  if (quarterTab) {
    console.log(`\n## План квартала · ${Q.name}`);
    console.log(`бюджет ${budget} ч · в плане ${plan.length} задач на ${used} ч` + (used > budget ? ` (перебор ${used - budget} ч)` : '') +
      ` · очередь ${unfitted.length} · пауза ${pausedFinal.length} · остановлено ${stopped.length}`);
    for (const e of plan) console.log(`  ${e.code}  ${String(e.effort).padStart(4)} ч  ${round(e.score)}  ${e.name}`);
    if (noBaseline.length) console.log(`  замер «как было» нужен у ${noBaseline.length} задач плана`);
    if (deviations.length) console.log(`  решений совета против расчёта: ${deviations.map(([c]) => c).join(', ')}`);
  }
  if (!flags.check && !flags['no-write']) console.log(`\nзаписано: ${Object.values(out).map(f => path.relative(process.cwd(), f)).join(', ')}`);
}
