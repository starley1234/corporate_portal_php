/** Контракт настоящих PHP-рендеров, полученных стендом (не ручной HTML).
 * node .tools/nj669_solid3d_ui.mjs /tmp/nj669-fixtures.json
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
const { views } = JSON.parse(readFileSync(process.argv[2], 'utf8'));
let checks = 0;
for (const [name, html] of Object.entries(views)) {
	const { window } = new JSDOM(html);
	const doc = window.document;
	const ids = [...doc.querySelectorAll('[id]')].map((el) => el.id);
	assert.equal(ids.length, new Set(ids).size, name + ': повторный id'); checks++;
	for (const label of doc.querySelectorAll('label[for]')) {
		assert.ok(doc.getElementById(label.htmlFor), name + ': label без поля ' + label.htmlFor); checks++;
	}
	for (const form of doc.querySelectorAll('form[method="post"]')) {
		assert.equal(form.querySelector('[name="csrf"]')?.value, 'csrf-ok', name + ': CSRF'); checks++;
	}
	for (const el of doc.querySelectorAll('script[src],link[href],img[src]')) {
		const url = el.getAttribute('src') || el.getAttribute('href');
		assert.ok(url.startsWith('/'), name + ': внешний ресурс ' + url); checks++;
	}
	window.close();
}
const doc = new JSDOM(views['nj669/project']).window.document;
const model = doc.getElementById('nj667-stl');
assert.ok(model);
assert.equal(model.dataset.kind, 'glb');
assert.equal(model.dataset.status, '');
assert.match(model.dataset.src, /^\/solid3d\/f\/[a-f0-9]{20}\/[0-9]+\/glb$/);
assert.ok(doc.querySelector('a[href$="/stl"]'));
assert.equal(doc.querySelector('a[href$="/scad"]'), null, 'ссылка на SCAD скрыта');
assert.ok(!/openscad/i.test(doc.body.innerHTML), 'слово OpenSCAD не выводится');
assert.ok(doc.getElementById('solid-check'), 'модератору видна проверка ракурсов');
assert.ok(doc.querySelector('[value="vlm"]'), 'кнопка проверки ракурсов у модератора');
checks += 8;
console.log(`NJ669: ${checks} проверок настоящей вёрстки (jsdom) — ОК`);
const owner = new JSDOM(views['nj669/project-owner']).window.document;
const publicDoc = new JSDOM(views['nj669/project-public']).window.document;
assert.ok(owner.querySelector('[name="params[width]"]'));
assert.equal(owner.querySelector('[name="params[width]"]').type, 'range');
// Опубликованная версия: ползунки владельца собирают временный STL, версию и каталог не меняют.
assert.equal(owner.querySelector('[name="params[width]"]').closest('form').querySelector('[name="op"]').value, 'export');
assert.ok(owner.querySelector('[data-param-apply]').textContent.includes('Собрать STL'));
assert.equal(owner.querySelector('[name="op"][value="parameters"]'), null, 'ползунки не пишут размеры в версию');
assert.ok(!owner.body.innerHTML.includes('Пересчёт снимет её с публикации'), 'ползунки не предупреждают о снятии с каталога');
assert.ok(!owner.body.innerHTML.includes('Снимите её с публикации'), 'старый блок «снимите с публикации» убран');
// Черновая версия владельца: тот же временный STL, что и у остальных; версия не меняется.
const draft = new JSDOM(views['nj669/project-owner-draft']).window.document;
assert.equal(draft.querySelector('[name="params[len]"]').closest('form').querySelector('[name="op"]').value, 'export');
assert.ok(draft.querySelector('[data-param-apply]').textContent.includes('Собрать STL'));
assert.equal(draft.querySelector('[data-param-mode]').dataset.paramMode, 'export');
checks += 6;
assert.equal(owner.getElementById('solid-check'), null, 'владелец не видит проверку ракурсов');
assert.equal(owner.querySelector('[value="vlm"]'), null, 'владелец не видит кнопку проверки');
assert.equal(owner.querySelector('a[href$="/scad"]'), null, 'владелец не видит ссылку на SCAD');
assert.ok(!/openscad/i.test(owner.body.innerHTML), 'владельцу не выводится OpenSCAD');
assert.ok(owner.querySelector('[data-solid-tabs]'), 'вкладки левой колонки');
assert.equal(owner.querySelector('[name="params[width]"]').min, '1');
assert.equal(owner.querySelector('[name="params[width]"]').max, '100');
assert.equal(owner.querySelector('[name="title"]'), null, 'владелец не получает поля карточки');
assert.equal(owner.querySelector('[value="caption"]'), null, 'владелец не перегенерирует карточку');
assert.ok(owner.querySelector('[data-param-number]'), 'число рядом с ползунком');
assert.equal(owner.querySelectorAll('[data-slot-save]').length, 3, 'три слота');
assert.ok(owner.querySelector('[data-slot-default]'), 'кнопка «По умолчанию»');
assert.ok(owner.querySelector('[data-param-reset]'), 'кнопка «Вернуть»');
assert.ok(owner.querySelector('[name="milestone"]'));
assert.equal(owner.querySelector('[value="apply_caption"]'), null, 'рекомендацию не применяет владелец');
assert.ok(doc.querySelector('[value="caption"][name="op"]'), 'администратор перегенерирует карточку иконкой');
assert.ok(doc.querySelector('[aria-label="Перегенерировать карточку"]'));
assert.equal(doc.querySelector('[aria-label="Перегенерировать карточку"]').disabled, false, 'кнопка не заблокирована без работающей задачи карточки');
assert.equal(owner.querySelectorAll('figure img').length, 3);
assert.equal(publicDoc.querySelectorAll('figure img').length, 3);
// Публичная страница: размеры видны, но сохранять версию нельзя; собирается только временный STL.
assert.equal(publicDoc.querySelectorAll('form[method="post"] [name="op"][value="parameters"]').length, 0);
assert.ok(publicDoc.querySelector('form[method="post"] [name="op"][value="export"]'));
assert.ok(publicDoc.querySelectorAll('[name^="params["]').length > 0, 'размеры видны на публичной странице');
assert.ok(publicDoc.querySelector('fieldset[data-lock-panel][disabled]'), 'гость: размеры закрыты до входа');
assert.ok(/Войдите в аккаунт/.test(publicDoc.body.innerHTML), 'гостю подсказка входа');
const otherDoc = new JSDOM(views['nj669/project-public-user']).window.document;
assert.equal(otherDoc.querySelector('fieldset[data-lock-panel][disabled]'), null, 'другой пользователь собирает STL без блокировки');
assert.ok(otherDoc.querySelector('[data-param-apply]').textContent.includes('Собрать STL'));
checks += 6;
// Каталог: гость и владелец — без «Поиска» и без снятия; модератор — переключатель, снятие и очередь.
const guestCat = new JSDOM(views['nj669/catalog-guest']).window.document;
assert.ok(!/Поиск/.test(guestCat.body.innerHTML), 'в каталоге нет слова «Поиск»');
assert.ok(guestCat.getElementById('solid-search'), 'форма поиска осталась');
assert.ok(guestCat.querySelector('[class*="xl:grid-cols-4"]'), 'на больших экранах четыре карточки в ряд');
assert.ok(guestCat.querySelector('[class*="h-36"]'), 'превью компактное');
assert.equal(guestCat.querySelector('[value="unpublish"]'), null, 'гость не снимает детали');
const modCat = new JSDOM(views['nj669/catalog-moderator']).window.document;
assert.ok(modCat.querySelector('[value="unpublish"]'), 'модератор снимает деталь из каталога');
assert.ok(modCat.querySelector('a[href="/solid3d/catalog?view=moderate"]'), 'переключатель «Модерация»');
const modQueue = new JSDOM(views['nj669/catalog-moderate']).window.document;
assert.ok(modQueue.querySelector('[value="publish"]'), 'очередь: публикация');
assert.ok(modQueue.querySelector('textarea[name="note"]'), 'очередь: причина отказа');
const usageDoc = new JSDOM(views['nj669/usage-tokens']).window.document;
assert.ok(usageDoc.querySelector('table.table-fixed'), 'журнал: фиксированная раскладка таблицы');
assert.ok(!/min-w-\[720px\]/.test(usageDoc.body.innerHTML), 'журнал не растягивает таблицу');
const ownTokens = new JSDOM(views['nj669/project-owner-tokens']).window.document;
assert.ok(ownTokens.body.innerHTML.includes('токены'), 'владелец видит расход версии');
checks += 14;
assert.ok(new JSDOM(views['nj669/ideas']).window.document.querySelector('[data-delete-label="Удалить идею"]'));
assert.ok(new JSDOM(views['nj669/settings']).window.document.querySelector('[name="light_model"]'));
console.log('NJ669: 13 проверок параметров/карточек/галереи/публичного режима — ОК');
