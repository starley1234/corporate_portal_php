// Каркас страницы: скрипт из собранного HTML запускается на минимальной DOM-заглушке.
// Проверяем левое меню (группы разделов, пункты, свёртывание, поиск, палитру),
// скрытые разделы, листание вкладок и заголовок для печати.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { buildAll } from '../scripts/build.mjs';
import { tempProject, minimalFiles, MINIMAL_META, MINIMAL_TAB } from './helpers.mjs';

// --- минимальный DOM: ровно то, что нужно движку страницы ---
function El(tag) {
  const e = {
    tag, children: [], attrs: {}, style: {}, dataset: {}, parentNode: null, ls: {},
    className: '', value: '',
    classList: {
      s: new Set(),
      add(c) { this.s.add(c); }, remove(c) { this.s.delete(c); }, contains(c) { return this.s.has(c); },
      toggle(c, on) { const want = on === undefined ? !this.s.has(c) : !!on; want ? this.s.add(c) : this.s.delete(c); return want; },
    },
    appendChild(c) { this.children.push(c); c.parentNode = e; return c; },
    insertBefore(c) { this.children.push(c); return c; },
    removeChild(c) { this.children = this.children.filter(x => x !== c); return c; },
    setAttribute(k, v) { this.attrs[k] = v; }, getAttribute(k) { return this.attrs[k]; }, removeAttribute(k) { delete this.attrs[k]; },
    addEventListener(t, fn) { (this.ls[t] = this.ls[t] || []).push(fn); }, removeEventListener() {},
    focus() {}, select() {}, click() { if (e.onclick) e.onclick(); },
    getBBox() { return { x: 0, y: 0, width: 40, height: 12 }; },
    querySelectorAll() { return []; }, querySelector() { return null }, closest() { return null; },
  };
  let html = '', text = '';
  Object.defineProperty(e, 'innerHTML', {
    get: () => html,
    set: v => { html = String(v); e.textContent = html.replace(/<[^>]*>/g, ''); },
  });
  Object.defineProperty(e, 'textContent', {
    get: () => (e.children.length ? e.children.map(c => c.textContent).join('') : text),
    set: v => { text = String(v); e.children = []; },   // как в DOM: запись заменяет потомков
  });
  return e;
}
const fire = (el, type, ev) => (el.ls[type] || []).forEach(fn => fn({ preventDefault() {}, target: el, ...ev }));

// Запускает скрипт страницы и возвращает заглушки элементов.
function runPage(html, hash = '', idList) {
  const code = /<script>\n([\s\S]*?)<\/script>/.exec(html);
  assert.ok(code, 'в HTML нет <script>');
  const ids = idList || ['main', 'nav', 'ptitle', 'cols', 'navtog', 'navq', 'navgroups', 'navno', 'navexpandall', 'navcollapseall', 'pager'];
  const byId = {};
  for (const id of ids) byId[id] = El(id === 'navq' ? 'input' : id === 'nav' ? 'aside' : 'div');
  if (byId.navtog) byId.navtog.textContent = '‹';   // в разметке иконка свёртывания
  if (byId.navexpandall) byId.navexpandall.textContent = 'Развернуть всё';
  if (byId.navcollapseall) byId.navcollapseall.textContent = 'Свернуть всё';
  const lvl = [El('button'), El('button')];
  lvl[0].dataset.l = 'o'; lvl[1].dataset.l = 'd';
  const document = {
    createElement: t => El(t),
    createElementNS: (_ns, t) => El(t),
    getElementById: id => byId[id] || null,
    querySelectorAll: sel => (sel === '#lvl button' ? lvl : []),
    querySelector: () => null,
    addEventListener() {},
    activeElement: null,
    body: El('body'),
  };
  const ctx = { document, console, location: { hash, href: 'file:///page.html' + hash, search: '' }, history: { replaceState() {} }, setTimeout, clearTimeout };
  vm.createContext(ctx);
  new vm.Script(code[1], { filename: 'page.js' }).runInContext(ctx);
  return { byId, lvl, ctx };
}

function buildPage(files) {
  const p = tempProject(files);
  try {
    const out = path.join(p.dir, 'out');
    buildAll({ dir: p.dir, out, quiet: true, noLog: true });
    return { html: fs.readFileSync(path.join(out, 'architecture.html'), 'utf8'), cleanup: p.cleanup };
  } catch (e) { p.cleanup(); throw e; }
}

const TAB2 = {
  title: 'Второй вид',
  overview: { lead: 'вторая вкладка', blocks: [{ note: 'текст' }] },
  detail: { lead: 'подробно', blocks: [{ note: 'текст' }] },
};

// Разделы заданы явно: TO-BE (2 вкладки), Решения (1), AS-IS (1, скрыт).
const files3 = {
  'meta.yaml': {
    ...MINIMAL_META,
    sections: [
      { dir: 'tobe', label: 'TO-BE' },
      { dir: 'solutions', label: 'Решения' },
      { dir: 'asis', label: 'AS-IS', hidden: true },
    ],
  },
  'components.yaml': { a: '**A** — описание' },
  'tobe/01-overview.yaml': MINIMAL_TAB,
  'tobe/02-second.yaml': TAB2,
  'solutions/01-app.yaml': TAB2,
  'asis/01-old.yaml': { ...TAB2, title: 'Старый вид' },
};
const groups = nav => nav.children.filter(c => c.className === 'ng');   // в заглушке className не меняется классом
const navGroups = byId => byId.navgroups.children.filter(c => c.className === 'ng');
const head = g => g.children[0];
const items = g => g.children[1].children;

test('заголовок раздела .nnm в меню выровнен по левому краю', () => {
  const css = fs.readFileSync(new URL('../src/shells/panel/styles.css', import.meta.url), 'utf8');
  assert.match(css, /\.nnm\{[^}]*text-align:left/);
});

test('страница запускается и строит левое меню по разделам', t => {
  const b = buildPage(files3);
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  const gs = navGroups(byId);
  const toggles = byId.navgroups.children.filter(c => c.className === 'nt');
  assert.equal(gs.length, 3);
  assert.equal(toggles.length, 1);

  // заголовок группы: стрелка, подпись раздела, маркер активной вкладки, число вкладок
  assert.deepEqual(gs.slice(0, 2).map(g => head(g).children[1].textContent), ['TO-BE', 'Решения']);
  assert.equal(head(gs[0]).children[3].textContent, '2');
  // пункты меню — короткие подписи, без префикса раздела
  assert.deepEqual(items(gs[0]).map(x => x.textContent), ['1. Общий вид', '2. Второй вид']);
  // активный пункт и заголовок для печати
  assert.ok(items(gs[0])[0].classList.contains('on'));
  assert.equal(byId.ptitle.textContent, 'TO-BE · 1. Общий вид');
  // скрытый раздел свёрнут
  assert.ok(gs[2].classList.contains('hide'));
  assert.match(toggles[0].textContent, /^Показать AS-IS \(1 вкладка\)$/);
});

test('раздел сворачивается кликом по заголовку и показывает активную вкладку', t => {
  const b = buildPage(files3);
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  const gs = navGroups(byId);
  assert.equal(head(gs[0]).children[0].textContent, '▾');
  head(gs[0]).onclick();
  assert.ok(gs[0].classList.contains('fold'), 'раздел свёрнут');
  assert.equal(head(gs[0]).children[0].textContent, '▸');
  // в свёрнутом разделе видно, какая вкладка открыта
  assert.equal(head(gs[0]).children[2].textContent, '1. Общий вид');
  head(gs[0]).onclick();
  assert.equal(gs[0].classList.contains('fold'), false, 'раздел раскрылся');
  assert.equal(head(gs[0]).children[2].textContent, '', 'маркер нужен только свёрнутому разделу');
});

test('кнопки сворачивают и разворачивают все разделы меню', t => {
  const b = buildPage(files3);
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  const gs = navGroups(byId), expand = byId.navexpandall, collapse = byId.navcollapseall;

  assert.equal(expand.textContent, 'Развернуть всё');
  assert.equal(collapse.textContent, 'Свернуть всё');
  assert.equal(expand.disabled, true, 'когда всё раскрыто, повторное раскрытие недоступно');
  assert.equal(collapse.disabled, false);

  collapse.onclick();
  assert.ok(gs.every(g => g.classList.contains('fold')), 'свернулись все группы');
  assert.equal(expand.disabled, false);
  assert.equal(collapse.disabled, true);
  assert.ok(gs[2].classList.contains('hide'), 'переключатель «всё» не раскрывает скрытый раздел');

  expand.onclick();
  assert.ok(gs.every(g => !g.classList.contains('fold')), 'развернулись все группы');
  assert.equal(expand.disabled, true);
  assert.equal(collapse.disabled, false);

  head(gs[0]).onclick();
  assert.equal(expand.disabled, false, 'после ручного сворачивания доступно раскрытие всех');
  collapse.onclick();
  assert.ok(gs.every(g => g.classList.contains('fold')));
});

test('поиск фильтрует вкладки и находит их в скрытых разделах', t => {
  const b = buildPage(files3);
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  const gs = navGroups(byId), q = byId.navq;

  q.value = 'второй'; fire(q, 'input');
  assert.deepEqual(items(gs[0]).map(x => x.classList.contains('hide')), [true, false]);
  assert.ok(items(gs[1])[0].classList.contains('hide') === false, 'совпадение в «Решениях»');
  assert.ok(gs[2].classList.contains('hide'), 'в AS-IS совпадений нет — группа скрыта');
  assert.ok(byId.navno.classList.contains('hide'), 'совпадения есть — сообщение скрыто');

  // скрытый раздел находится поиском, даже если он не раскрыт переключателем
  q.value = 'старый'; fire(q, 'input');
  assert.equal(gs[2].classList.contains('hide'), false, 'AS-IS показан: в нём есть совпадение');
  fire(q, 'keydown', { key: 'Enter' });
  assert.equal(byId.ptitle.textContent, 'AS-IS · 1. Старый вид');

  q.value = 'нетакого'; fire(q, 'input');
  assert.equal(byId.navno.classList.contains('hide'), false, 'ничего не найдено');
  assert.ok(navGroups(byId).every(g => g.classList.contains('hide')), 'все группы скрыты');

  fire(q, 'keydown', { key: 'Escape' });
  assert.equal(byId.navno.classList.contains('hide'), true);
  assert.equal(gs[0].classList.contains('hide'), false, 'после сброса видимость вернулась');
  assert.equal(gs[2].classList.contains('hide'), true, 'скрытый раздел снова скрыт');
});

test('легенда типов узлов — под схемой, в меню её нет', t => {
  const b = buildPage(files3);
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  assert.equal(byId.navgroups.children.filter(c => c.className === 'nlg').length, 0, 'в меню палитры нет');
  const find = (el, cls) => (el.className === cls ? el : el.children.reduce((a, c) => a || find(c, cls), null));
  const lg = find(byId.main, 'legend');
  assert.ok(lg, 'под схемой есть легенда');
  assert.match(lg.textContent, /Внешние/, 'в легенде — типы, встречающиеся на схеме');
  assert.match(lg.textContent, /Ядро/);
});

test('внизу страницы — ссылки на соседние вкладки', t => {
  const b = buildPage(files3);
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  const gs = navGroups(byId);
  // активна первая вкладка TO-BE: только «следующая»
  assert.deepEqual(byId.pager.children.map(x => x.textContent), ['2. Второй вид →']);
  items(gs[0])[1].onclick();
  assert.deepEqual(byId.pager.children.map(x => x.textContent), ['← 1. Общий вид', 'Решения · 1. Второй вид →']);
  byId.pager.children[1].onclick();
  assert.equal(byId.ptitle.textContent, 'Решения · 1. Второй вид');
  assert.deepEqual(byId.pager.children.map(x => x.textContent), ['← TO-BE · 2. Второй вид'], 'это последняя видимая вкладка');
});

test('переключатель скрытого раздела показывает и прячет его пункты', t => {
  const b = buildPage(minimalFiles({ 'asis/01-old.yaml': { ...TAB2, title: 'Старый вид' } }));
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  const gs = navGroups(byId);
  const toggle = byId.navgroups.children.find(c => c.className === 'nt');
  assert.ok(gs[1].classList.contains('hide'));
  toggle.onclick();
  assert.equal(gs[1].classList.contains('hide'), false, 'AS-IS должен раскрыться');
  assert.match(toggle.textContent, /^Скрыть AS-IS/);
  toggle.onclick();
  assert.ok(gs[1].classList.contains('hide'), 'AS-IS должен снова свернуться');
});

test('клик по пункту меню переключает вкладку и режим подробно', t => {
  const b = buildPage(minimalFiles({ 'tobe/02-second.yaml': TAB2 }));
  t.after(b.cleanup);
  const { byId, lvl } = runPage(b.html);
  const it = items(navGroups(byId)[0]);
  it[1].onclick();
  assert.ok(it[1].classList.contains('on'));
  assert.equal(it[0].classList.contains('on'), false);
  assert.equal(byId.ptitle.textContent, 'TO-BE · 2. Второй вид');
  assert.equal(byId.main.children.length, 2, 'обе вкладки построены в main');
  lvl[1].onclick();                       // режим «Подробно»
  assert.equal(byId.main.children.length, 3, 'для подробного режима строится свой view');
  assert.ok(lvl[1].classList.contains('on'));
});

test('кнопка-иконка на границе меню сворачивает и разворачивает колонку', t => {
  const b = buildPage(minimalFiles());
  t.after(b.cleanup);
  const { byId } = runPage(b.html);
  assert.equal(byId.navtog.textContent, '‹');
  assert.equal(byId.cols.classList.contains('navoff'), false);
  byId.navtog.onclick();
  assert.ok(byId.cols.classList.contains('navoff'), 'меню свёрнуто');
  assert.equal(byId.navtog.textContent, '›', 'иконка развернулась в другую сторону');
  assert.equal(byId.navtog.attrs['aria-pressed'], 'false');
  assert.equal(byId.navtog.attrs['aria-label'], 'Показать меню');
  byId.navtog.onclick();
  assert.equal(byId.cols.classList.contains('navoff'), false, 'меню вернулось');
  assert.equal(byId.navtog.textContent, '‹');
  assert.equal(byId.navtog.attrs['aria-label'], 'Свернуть меню');
});

test('оболочка classic: лента вкладок и переключатель скрытого раздела', t => {
  const files = minimalFiles({ 'asis/01-old.yaml': { ...TAB2, title: 'Старый вид' } });
  files['meta.yaml'] = { ...files['meta.yaml'], ui: { shell: 'classic' } };
  const b = buildPage(files);
  t.after(b.cleanup);
  const { byId } = runPage(b.html, '', ['main', 'tabs']);
  const tabs = byId.tabs.children;
  assert.equal(tabs.length, 3, 'две вкладки и переключатель');
  assert.match(tabs[2].textContent, /^Показать AS-IS \(1 вкладка\)$/);
  assert.ok(tabs[1].classList.contains('legacy-hidden'), 'AS-IS скрыт');
  tabs[2].onclick();
  assert.equal(tabs[1].classList.contains('legacy-hidden'), false, 'AS-IS раскрылся');
  assert.match(tabs[2].textContent, /^Скрыть AS-IS/);
  tabs[2].onclick();
  assert.ok(tabs[1].classList.contains('legacy-hidden'), 'AS-IS снова скрыт');
});

test('якорь в адресе открывает нужную вкладку', t => {
  const b = buildPage(minimalFiles({ 'tobe/02-second.yaml': TAB2 }));
  t.after(b.cleanup);
  const { byId } = runPage(b.html, '#t=1&l=d');
  const it = items(navGroups(byId)[0]);
  assert.ok(it[1].classList.contains('on'), 'вкладка из #t= активна');
  assert.equal(byId.ptitle.textContent, 'TO-BE · 2. Второй вид');
});

test('меню прокручивается внутри панели, не задевая рамку', t => {
  const b = buildPage(minimalFiles());
  t.after(b.cleanup);
  // панель — flex-колонка ограниченной высоты, внутри — сжимаемый блок с прокруткой.
  // Без min-height:0 flex-элемент не сжимается, и прокрутка не появляется вовсе.
  assert.match(b.html, /\.nav\{[^}]*max-height:calc\(100vh - 32px\)[^}]*overflow:hidden/);
  assert.match(b.html, /\.nav\{[^}]*display:flex;flex-direction:column/);
  assert.match(b.html, /\.nscroll\{flex:1 1 auto;min-height:0;overflow-y:auto/);
});

test('страница занимает всю ширину окна', t => {
  const b = buildPage(minimalFiles());
  t.after(b.cleanup);
  assert.match(b.html, /\.wrap\{max-width:none;margin:0 auto\}/);
  assert.match(b.html, /grid-template-columns:264px minmax\(0,1fr\)/);   // колонка меню
  assert.match(b.html, /\.wrap\{max-width:none/);                        // и никакого ограничения по ширине
});
