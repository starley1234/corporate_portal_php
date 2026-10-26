/**
 * Контентная шкала ProcessHub.
 *
 * Проверяем контракт, который легко потерять при следующей правке вёрстки:
 *   1. корень каждой страницы помечен .n2j-ds.is-125 — крупный режим
 *      (owner, 19.10.2026). База платформы с 20.10.2026 — 100 % (эталон v6),
 *      поэтому крупный режим ProcessHub задаётся модификатором, а не базой;
 *   2. в интерфейсе не осталось текста 9–11 px (иконки не в счёт);
 *   3. переключателя масштаба нет — у ProcessHub режим один, крупный;
 *   4. токены объявлены в источнике Tailwind и попадают в собранный css.
 *
 * Исключения: предпросмотр документа A4 в report.php (#nj670-print) —
 * он повторяет вёрстку DOCX и живёт в своём масштабе; подписи на SVG
 * в редакторе заданы в пикселях и не наследуют шкалу.
 */
import { readFileSync, existsSync } from 'node:fs';
import test from 'node:test';
import assert from 'node:assert/strict';

const root = '/home/user/n2j.ru';
const dir = `${root}/application/modules/nj670_processhub/views/670`;
const views = ['index', 'versions', 'demo', 'fact', 'admin', 'create', 'simulate', 'ontology', 'map', 'audit', 'passport', 'report', 'editor'];
const read = (name) => readFileSync(`${dir}/${name}.php`, 'utf8');
const input = readFileSync(`${root}/.tools/tailwind/input.css`, 'utf8');
const css = readFileSync(`${root}/public_html/assets/css/app.css`, 'utf8');

/** Вырезаем предпросмотр DOCX: у него собственный кегль под печать. */
const withoutDocumentPreview = (html) => {
  const start = html.indexOf('id="nj670-print"');
  if (start < 0) return html;
  const end = html.indexOf('\n\t</div>', start);
  return html.slice(0, start) + (end > 0 ? html.slice(end) : '');
};

test('корень каждой страницы включает контентную шкалу в крупном режиме', () => {
  for (const name of views) {
    assert.match(read(name), /class="n2j-ds is-125 [^"]*"/, `${name}.php: нет корня шкалы .n2j-ds.is-125`);
  }
});

test('в интерфейсе не осталось текста 9–11 px', () => {
  for (const name of views) {
    let html = withoutDocumentPreview(read(name));
    // иконки задают свой размер намеренно — это не текст
    html = html.replace(/<i\b[^>]*>[\s\S]*?<\/i>/g, '').replace(/<i\b[^>]*>/g, '');
    // крошечные кружки-легенды 14×14 в редакторе: кегль 9 px — часть размера
    html = html.replace(/<span class="inline-flex h-3\.5 w-3\.5[\s\S]*?<\/span>/g, '');
    const tiny = html.match(/text-\[(?:9|10|11)px\]/g) || [];
    assert.deepEqual(tiny, [], `${name}.php: ${tiny.join(', ')}`);
  }
});

test('переключатель убран из всех страниц', () => {
  for (const name of views) {
    const html = read(name);
    assert.ok(!html.includes('670/_scale'), `${name}.php: остался include переключателя`);
    assert.ok(!html.includes('data-n2j-scale'), `${name}.php: остался маркер переключателя`);
    assert.ok(!html.includes('n2j.contentScale'), `${name}.php: остался localStorage переключателя`);
  }
  assert.ok(!existsSync(`${dir}/_scale.php`), 'partial переключателя должен быть удалён');
});

test('токены объявлены и собраны в css: база 100 %, крупный режим .is-125', () => {
  for (const token of ['.n2j-ds {', '.n2j-ds.is-125 {', '--n2j-font-scale: 1.125', '.n2j-ds-body', '.n2j-ds-badge']) {
    assert.ok(input.includes(token), `нет в input.css: ${token}`);
  }
  for (const token of ['.n2j-ds{', '.n2j-ds.is-125{--n2j-font-scale:1.125', '.n2j-ds-body', '.n2j-ds-badge']) {
    assert.ok(css.includes(token), `нет в собранном app.css: ${token}`);
  }
  // База — эталон v6 (100 %): множитель по умолчанию 1, крупный — только модификатором.
  assert.match(input, /:root\s*\{\s*--n2j-font-scale:\s*1;/, 'база шкалы должна быть 100 %');
});

test('подписи на SVG не масштабируются вместе с панелями', () => {
  const editor = read('editor');
  assert.match(editor, /\.nj670-title\{font:600 11\.5px/, 'кегль подписей узлов должен остаться фиксированным');
  assert.match(editor, /\.nj670-meta\{font:9\.5px/, 'мета узлов должна остаться фиксированной');
  assert.match(editor, /\.nj670-edge-label\{font:600 10px/, 'подписи связей читаются на линии');
  for (const name of ['index', 'create', 'passport', 'simulate']) {
    const h1 = read(name).match(/<h1[^>]*>/);
    assert.ok(h1 && !/text-3xl/.test(h1[0]), `${name}.php: заголовок страницы крупнее остальных`);
  }
});

test('индикатор работы воркера: этап и точки, без полос прогресса', () => {
  const partial = readFileSync(`${dir}/_job.php`, 'utf8');

  assert.match(partial, /n2j-dots/, 'в полосе воркера должны быть пульсирующие точки');
  assert.match(partial, /stage/, 'нужен этап работы');
  assert.match(partial, /elapsed_sec/, 'нужен секундомер');
  assert.match(partial, /\/process\/api\/status/, 'полоса должна опрашивать статус');
  assert.ok(!/<progress/.test(partial), 'нативный progress запрещён');
  assert.ok(!/width:\s*\d+%/.test(partial), 'полосы прогресса запрещены правилами UI');

  // Полоса стоит там, где воркер действительно может работать в фоне.
  for (const name of ['fact', 'audit', 'report', 'versions', 'passport']) {
    assert.ok(read(name).includes("View::factory('670/_job')"), `${name}.php: нет полосы воркера`);
  }
});

test('реестр: быстрые фильтры и разрыв план/факт без полос', () => {
  const index = read('index');

  for (const state of ['no_fact', 'no_owner', 'audited']) {
    assert.ok(index.includes(`'state' => '${state}'`) || index.includes(`'${state}'`), `нет быстрого фильтра ${state}`);
  }

  assert.match(index, /факт ×/, 'в карточке нужен числовой разрыв факт/план');
  assert.ok(!index.includes('<progress'), 'полосы прогресса запрещены');
});
