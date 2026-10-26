import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { JSDOM } from 'jsdom';

const js = readFileSync(new URL('../../public_html/assets/js/nj669-solid3d.js', import.meta.url), 'utf8');
const appJs = readFileSync(new URL('../../public_html/assets/js/app.js', import.meta.url), 'utf8');
const view = readFileSync(new URL('../../application/modules/nj669_solid3d/views/nj669/project.php', import.meta.url), 'utf8');
const ideasView = readFileSync(new URL('../../application/modules/nj669_solid3d/views/nj669/ideas.php', import.meta.url), 'utf8');
function setup(html, fetcher = null, withApp = false) {
	const dom = new JSDOM(html, { url: 'https://test.local/solid3d/p/aaaaaaaaaaaaaaaaaaaa', runScripts: 'outside-only', pretendToBeVisual: true });
	const { window: w } = dom;
	const timers = [];
	w.setTimeout = (callback, delay) => { timers.push({ callback, delay }); return timers.length; };
	w.setInterval = () => 1;
	w.clearInterval = () => {};
	w.AbortSignal.timeout = () => undefined;
	w.fetch = fetcher || (async () => { throw new Error('Неожиданный fetch'); });
	if (withApp) { w.eval(appJs); }
	w.eval(js);
	return { dom, w, doc: w.document, timers };
}
const polling = '<div data-solid-poll="?state=1" data-signature="queued"><span data-solid-clock="1792123200"></span></div><div id="solid-new-result" hidden></div><form data-solid-form><textarea id="solid-prompt"></textarea></form>';
const flush = () => new Promise((resolve) => setImmediate(resolve));

test('Идеи заполняют реальную форму и не требуют поллинга', () => {
	const { dom, doc } = setup('<textarea id="solid-prompt"></textarea><button data-solid-idea="Кронштейн 30×40 мм">Идея</button>');
	doc.querySelector('button').click();
	assert.equal(doc.querySelector('textarea').value, 'Кронштейн 30×40 мм');
	assert.equal(doc.activeElement, doc.querySelector('textarea'));
	dom.window.close();
});

test('Удаление: первое нажатие взводит (data-n2j-arm), второе отправляет armed=1', () => {
	const { dom, doc } = setup('<form><input name="armed" value="0"><button type="button" data-solid-delete data-n2j-arm="Удалить версию?">Удалить</button></form>', null, true);
	let submitted = 0;
	doc.querySelector('form').requestSubmit = () => { submitted++; };
	const button = doc.querySelector('button');
	button.click();
	assert.equal(submitted, 0);
	assert.equal(doc.querySelector('input').value, '0', 'первое нажатие только взводит');
	assert.ok(button.classList.contains('is-armed'));
	assert.equal(button.textContent, 'Удалить версию?');
	button.click();
	assert.equal(submitted, 1);
	assert.equal(doc.querySelector('input').value, '1');
	dom.window.close();
});

test('Взвод удаления гаснет сам через 4 секунды и не ставит armed=1', () => {
	const { dom, doc, timers } = setup('<form><input name="armed" value="0"><button type="button" data-solid-delete data-n2j-arm="Удалить идею?">Удалить идею</button></form>', null, true);
	const button = doc.querySelector('button');
	button.click();
	const timer = timers.find((t) => t.delay === 4000);
	assert.ok(timer);
	timer.callback();
	assert.ok(!button.classList.contains('is-armed'));
	assert.equal(button.textContent, 'Удалить идею');
	assert.equal(doc.querySelector('input').value, '0');
	dom.window.close();
});

test('Кнопки не отключаются до сериализации name/value формы', () => {
	const { dom, doc, timers } = setup('<form data-solid-form><button name="op" value="render">Пересчитать</button></form>');
	const form = doc.querySelector('form');
	form.dispatchEvent(new dom.window.Event('submit', { cancelable: true }));
	assert.equal(form.querySelector('button').disabled, false);
	timers.find((t) => t.delay === 0).callback();
	assert.equal(form.querySelector('button').disabled, true);
	dom.window.close();
});

test('queued → running не перезагружает страницу, done сохраняет введённую правку', async () => {
	const replies = [{ ok: true, signature: 'running', busy: true }, { ok: true, signature: 'done', busy: false }];
	const requests = [];
	const { dom, doc, w, timers } = setup(polling, async (url, options) => {
		requests.push([url, options]);
		return { ok: true, status: 200, json: async () => replies.shift() };
	});
	timers.shift().callback(); await flush();
	assert.equal(doc.querySelector('[data-solid-poll]').dataset.signature, 'running');
	assert.equal(doc.getElementById('solid-new-result').hidden, true);
	assert.equal(timers.length, 1);
	const textarea = doc.querySelector('textarea');
	textarea.value = 'Не потерять мою новую правку';
	textarea.dispatchEvent(new w.Event('input', { bubbles: true }));
	timers.shift().callback(); await flush();
	assert.equal(doc.getElementById('solid-new-result').hidden, false);
	assert.equal(textarea.value, 'Не потерять мою новую правку');
	assert.equal(requests.length, 2);
	assert.equal(requests[0][1].credentials, 'same-origin');
	assert.equal(requests[0][1].cache, 'no-store');
	assert.equal(timers.length, 0);
	dom.window.close();
});

test('Ошибки опроса ограничены: сообщение вместо вечного ожидания', async () => {
	let calls = 0;
	const { dom, doc, timers } = setup(polling, async () => { calls++; return { ok: false, status: 503 }; });
	for (let i = 0; i < 5; i++) { timers.shift().callback(); await flush(); }
	assert.equal(calls, 5);
	assert.equal(doc.getElementById('solid-new-result').hidden, false);
	assert.match(doc.getElementById('solid-new-result').textContent, /проверить задачу/);
	assert.equal(timers.length, 0);
	dom.window.close();
});

test('После ухода со страницы новые опросы не выполняются', async () => {
	let calls = 0;
	const { dom, w, timers } = setup(polling, async () => { calls++; return { ok: false, status: 503 }; });
	w.dispatchEvent(new w.Event('pagehide'));
	timers.shift().callback(); await flush();
	assert.equal(calls, 0);
	dom.window.close();
});

test('Просмотрщик — общий, но его Graph3D polling не включён', () => {
	assert.match(view, /id="nj667-stl"/);
	assert.match(view, /data-kind="glb" data-state="done" data-status=""/);
	assert.match(view, /src="\/assets\/js\/nj667-stl\.js/);
	assert.match(view, /three\/mod\/addons\//);
	assert.doesNotMatch(view, /\/graph3d\/(api|w)\//);
	assert.match(view, /id="nj667-grid-toggle"/);
	assert.match(view, /id="nj667-stl-full"/);
});

test('Этап и пульс обновляются без HTML-инъекции и не перезаписывают форму параметров', async () => {
	const markup = polling + '<span data-solid-stage></span><p data-solid-heartbeat></p><form data-solid-form><input id="width" type="number" value="30"></form>';
	const { dom, doc, w, timers } = setup(markup, async () => ({ ok: true, json: async () => ({ ok: true, signature: 'running', busy: true, live: { stage: '<img src=x onerror=alert(1)>', silent_sec: 120 } }) }));
	const field = doc.getElementById('width');
	field.value = '45'; field.dispatchEvent(new w.Event('input', { bubbles: true }));
	timers.shift().callback(); await flush();
	assert.equal(doc.querySelector('[data-solid-stage]').textContent, '<img src=x onerror=alert(1)>');
	assert.equal(doc.querySelectorAll('img').length, 0);
	assert.match(doc.querySelector('[data-solid-heartbeat]').textContent, /2 мин/);
	assert.equal(field.value, '45');
	dom.window.close();
});

test('Виды: проект удаляется через окно, идея и версия — вторым нажатием; своего взвода в JS нет', () => {
	assert.equal((view.match(/data-solid-delete data-n2j-confirm="[^"]+" data-n2j-title="Удалить проект\?"/g) || []).length, 2);
	assert.match(view, /data-solid-delete data-n2j-arm="Удалить версию\?"/);
	assert.match(ideasView, /data-solid-delete data-n2j-arm="Удалить идею\?"/);
	assert.match(view, /name="armed" value="0"/, 'серверная защита armed=1 остаётся');
	assert.doesNotMatch(js, /let armed|armed = (true|false)|deleteLabel/);
});

test('Копилка идей использует тот же безопасный polling, даже без секундомера', async () => {
	const { dom, doc, w, timers } = setup('<div data-solid-poll="/solid3d/ideas?state=1" data-signature="queued"></div><div id="solid-new-result" hidden></div><form data-solid-form><input name="theme"></form>', async () => ({ ok: true, json: async () => ({ ok: true, signature: 'done', busy: false }) }));
	doc.querySelector('input').value = 'Новая тема';
	doc.querySelector('input').dispatchEvent(new w.Event('input', { bubbles: true }));
	timers.shift().callback(); await flush();
	assert.equal(doc.getElementById('solid-new-result').hidden, false);
	assert.equal(doc.querySelector('input').value, 'Новая тема');
	assert.equal(timers.length, 0);
	dom.window.close();
});
