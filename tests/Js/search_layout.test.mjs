import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const helper = readFileSync(new URL('../../public_html/assets/js/search-layout.js', import.meta.url), 'utf8');
const catalog = readFileSync(new URL('../../application/modules/prx110_catalog/views/prx110/index.php', import.meta.url), 'utf8');
const script = catalog.match(/<script>\s*([\s\S]*?)\s*<\/script>/)[1];

test('раскладка в обе стороны, регистр, пунктуация, смешанные слова', () => {
    const dom = new JSDOM('', { runScripts: 'outside-only' });
    dom.window.eval(helper);
    const { matches } = dom.window.PRXSearchLayout;
    assert.equal(matches('Входящие документы', 'D[JLZOBT'), true);
    assert.equal(matches('Входящие документы', String.raw`d\[jzobt`), true);
    assert.equal(matches('Входящие документы', 'd[jlzobt документы'), true);
    assert.equal(matches('API gateway', 'фзш'), true);
    assert.equal(matches('Входящие документы', 'неизвестно'), false);
    dom.window.close();
});

test('фильтр каталога находит название при неверной раскладке и сбрасывает поиск', () => {
    const dom = new JSDOM(`
        <div data-service-search hidden><input id="catalog-search"><p id="catalog-search-empty" hidden></p></div>
        <div id="catalog-groups">
            <section data-catalog-section><h2>Рабочие сервисы</h2>
                <section class="catalog-group" data-search="документы">
                    <div class="catalog-item" data-search="входящие документы">Входящие</div>
                    <div class="catalog-item" data-search="исходящие">Исходящие</div>
                </section>
            </section>
            <section data-catalog-section><h2>Инструменты платформы</h2>
                <section class="catalog-group" data-search="платформа"><div class="catalog-item" data-search="реестр">Реестр</div></section>
            </section>
        </div>`, { runScripts: 'outside-only' });
    const { window } = dom;
    window.eval(helper);
    window.eval(script);
    const input = window.document.getElementById('catalog-search');
    input.value = String.raw`d\[jzobt`;
    input.dispatchEvent(new window.Event('input'));
    const items = window.document.querySelectorAll('.catalog-item');
    assert.equal(items[0].classList.contains('hidden'), false);
    assert.equal(items[1].classList.contains('hidden'), true);
    const sections = window.document.querySelectorAll('[data-catalog-section]');
    assert.deepEqual(Array.from(sections, (section) => section.hidden), [false, true]);
    input.value = 'реестр';
    input.dispatchEvent(new window.Event('input'));
    assert.deepEqual(Array.from(sections, (section) => section.hidden), [true, false]);
    input.value = 'zxcv';
    input.dispatchEvent(new window.Event('input'));
    assert.equal(window.document.getElementById('catalog-search-empty').hidden, false);
    input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape' }));
    assert.equal(items[1].classList.contains('hidden'), false);
    assert.deepEqual(Array.from(sections, (section) => section.hidden), [false, false]);
    dom.window.close();
});
