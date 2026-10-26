/** Regression test for /bytes: the human() formatter must not be shadowed by its DOM output node. */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const view = readFileSync(new URL('../../application/modules/nj625_bytes/views/625/script.php', import.meta.url), 'utf8');
const match = view.match(/<script>([\s\S]*?)<\/script>/);
assert.ok(match, 'Expected the client script in the PHP view');
const source = match[1];

function createPage() {
    const dom = new JSDOM(`<!doctype html><html><body>
      <input id="nj625-in" value="1.5 гибибайт"><button id="nj625-go"></button>
      <div id="nj625-table"></div><p id="nj625-human"></p><button id="nj625-copy"></button>
      <button class="nj625-example" data-value="500 МБ"></button>
    </body></html>`, { runScripts: 'outside-only' });
    const errors = [];
    dom.window.addEventListener('error', (event) => errors.push(event.message));
    dom.window.eval(source);
    return { dom, errors };
}

test('/bytes форматирует исходное значение, не затеняя human(bytes)', () => {
    const { dom, errors } = createPage();
    const { document, Event } = dom.window;
    assert.equal(document.getElementById('nj625-human').textContent, '= 1.500 GiB');
    assert.equal(document.querySelectorAll('#nj625-table > div').length, 10);
    assert.equal(document.getElementById('nj625-copy').disabled, false);

    const input = document.getElementById('nj625-in');
    input.value = '500 МБ';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    assert.equal(document.getElementById('nj625-human').textContent, '= 476.84 MiB');
    assert.deepEqual(errors, []);
    dom.window.close();
});

test('Ошибочный ввод блокирует копирование и показывает сообщение без TypeError', () => {
    const { dom, errors } = createPage();
    const { document, Event } = dom.window;
    const input = document.getElementById('nj625-in');
    input.value = 'не единица';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    assert.equal(document.getElementById('nj625-human').textContent, '');
    assert.equal(document.getElementById('nj625-copy').disabled, true);
    assert.match(document.getElementById('nj625-table').textContent, /Формат/);
    assert.deepEqual(errors, []);
    dom.window.close();
});
