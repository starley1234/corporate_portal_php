/**
 * Контракт поведения дизайн-системы v6 (public_html/assets/js/app.js).
 *
 * Модули не копируют эти помощники, а вызывают PRX.* или ставят data-prx-*
 * (docs/DESIGN-SYSTEM.md). Поэтому ломать их тихо нельзя: двойной клик
 * удаления, окно подтверждения, кнопка процесса с секундомером, ошибка поля.
 *
 *   node --test tests/Js/prx_ui.test.mjs
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../public_html/assets/js/app.js', import.meta.url), 'utf8');

/**
 * Страница с app.js. Таймеры и часы подменены: секундомер и «взвод» на
 * 4 секунды проверяются без реального ожидания.
 */
function createPage(body) {
    const dom = new JSDOM(`<!doctype html><html><body>${body || ''}</body></html>`, {
        runScripts: 'outside-only',
        pretendToBeVisual: true,
    });
    const { window } = dom;
    const errors = [];
    window.addEventListener('error', (event) => errors.push(event.message));

    const clock = { now: 1_700_000_000_000 };
    window.Date.now = () => clock.now;

    const timers = [];
    const later = (fn, ms, repeat) => {
        timers.push({ fn, ms: Number(ms) || 0, repeat, cleared: false });
        return timers.length;
    };
    const cancel = (id) => { if (timers[id - 1]) timers[id - 1].cleared = true; };
    window.setTimeout = (fn, ms) => later(fn, ms, false);
    window.setInterval = (fn, ms) => later(fn, ms, true);
    window.clearTimeout = cancel;
    window.clearInterval = cancel;

    const copied = [];
    Object.defineProperty(window.navigator, 'clipboard', {
        configurable: true,
        value: { writeText: (text) => { copied.push(text); return Promise.resolve(); } },
    });

    window.eval(source);

    /** Прокрутить время: интервалы тикают, таймауты с ms ≤ шага срабатывают. */
    const advance = (ms) => {
        clock.now += ms;
        timers.slice().forEach((t) => {
            if (t.cleared) return;
            if (t.repeat) {
                t.fn();
            } else if (t.ms <= ms) {
                t.cleared = true;
                t.fn();
            }
        });
    };

    return { dom, window, document: window.document, PRX: window.PRX, errors, advance, copied };
}

function click(window, el) {
    el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

test('formatClock: «м:сс», после часа — «ч:мм:сс»', () => {
    const { PRX, dom } = createPage();
    assert.equal(PRX.formatClock(0), '0:00');
    assert.equal(PRX.formatClock(75), '1:15');
    assert.equal(PRX.formatClock(1023), '17:03');
    assert.equal(PRX.formatClock(3725), '1:02:05');
    assert.equal(PRX.formatClock(-5), '0:00');
    dom.window.close();
});

test('data-prx-stopwatch стартует сам и идёт от секунд сервера', async () => {
    const { document, advance, errors, dom } = createPage('<span id="w" data-prx-stopwatch="1023">17:03</span>');
    // app.js стоит в <head>: автозапуск ждёт DOMContentLoaded, как в браузере.
    if (document.readyState === 'loading') {
        await new Promise((resolve) => document.addEventListener('DOMContentLoaded', resolve));
    }
    const el = document.getElementById('w');
    assert.equal(el.textContent, '17:03');
    advance(2000);
    assert.equal(el.textContent, '17:05');
    assert.deepEqual(errors, []);
    dom.window.close();
});

test('PRX.run: кнопка процесса — спиннер, секундомер, «Прервать», возврат', () => {
    const { window, document, PRX, advance, errors, dom } = createPage(`
        <button id="go" data-prx-busy-show="#cancel, #hint">
            <span data-prx-idle class="inline-flex">Запустить анализ</span>
            <span data-prx-busy class="hidden inline-flex">Выполняется… (<span data-prx-clock>0:00</span>)</span>
        </button>
        <button id="cancel" class="hidden">Прервать</button>
        <p id="hint" class="hidden">Можно уйти со страницы</p>`);
    const btn = document.getElementById('go');
    const idle = btn.querySelector('[data-prx-idle]');
    const busy = btn.querySelector('[data-prx-busy]');
    const clockEl = btn.querySelector('[data-prx-clock]');

    PRX.run(btn, true);
    assert.equal(btn.disabled, true);
    assert.equal(btn.getAttribute('aria-busy'), 'true');
    assert.ok(idle.classList.contains('hidden'));
    assert.ok(!busy.classList.contains('hidden'));
    assert.ok(!document.getElementById('cancel').classList.contains('hidden'));
    assert.ok(!document.getElementById('hint').classList.contains('hidden'));
    assert.equal(clockEl.textContent, '0:00');

    advance(12000);
    assert.equal(clockEl.textContent, '0:12');

    const spent = PRX.run(btn, false);
    assert.equal(spent, 12);
    assert.equal(btn.disabled, false);
    assert.equal(btn.hasAttribute('aria-busy'), false);
    assert.ok(!idle.classList.contains('hidden'));
    assert.ok(busy.classList.contains('hidden'));
    assert.ok(document.getElementById('cancel').classList.contains('hidden'));
    assert.equal(clockEl.textContent, '0:00');
    assert.ok(window.PRX);
    assert.deepEqual(errors, []);
    dom.window.close();
});

test('PRX.fieldError: рамка, текст в [data-prx-error-for], снятие при правке', () => {
    const { window, document, PRX, errors, dom } = createPage(`
        <label for="url">Адрес</label><span id="err" class="prx-field-error" data-prx-error-for="url" hidden></span>
        <div class="relative"><input id="url" class="prx-input"><span class="prx-invalid-icon"></span></div>`);
    const input = document.getElementById('url');
    const box = document.getElementById('err');

    PRX.fieldError(input, 'Укажите корректный URL <b>');
    assert.equal(input.getAttribute('aria-invalid'), 'true');
    assert.equal(box.hidden, false);
    assert.equal(box.textContent, 'Укажите корректный URL <b>');
    assert.equal(box.querySelector('b'), null, 'сообщение вставляется как текст');
    assert.equal(input.getAttribute('aria-describedby'), 'err');

    input.value = 'https://prx.ru';
    input.dispatchEvent(new window.Event('input', { bubbles: true }));
    assert.equal(input.hasAttribute('aria-invalid'), false);
    assert.equal(box.hidden, true);
    assert.equal(box.textContent, '');
    assert.deepEqual(errors, []);
    dom.window.close();
});

test('PRX.fieldError без заготовки создаёт сообщение под полем (после обёртки .relative)', () => {
    const { document, PRX, dom } = createPage('<form><div class="relative" id="wrap"><input id="slug"></div><p id="after"></p></form>');
    const input = document.getElementById('slug');
    PRX.fieldError(input, 'Только латиница');
    const box = document.getElementById('wrap').nextElementSibling;
    assert.ok(box.classList.contains('prx-field-error'));
    assert.equal(box.textContent, 'Только латиница');
    assert.equal(box.getAttribute('data-prx-error-for'), 'slug');
    PRX.fieldError(input, null);
    assert.equal(box.hidden, true);
    dom.window.close();
});

test('data-prx-arm: первый клик взводит, второй выполняет submit и prx:confirmed', async () => {
    const { window, document, advance, errors, dom } = createPage(`
        <form id="f"><button id="del" type="submit" class="prx-btn-icon-danger" data-prx-arm="Удалить?" title="Удалить"><i class="fas fa-trash-can"></i></button></form>
        <button id="other">другое</button>`);
    const form = document.getElementById('f');
    const btn = document.getElementById('del');
    let submits = 0;
    let confirmed = 0;
    let moduleClicks = 0;
    form.addEventListener('submit', (e) => { e.preventDefault(); submits++; });
    document.addEventListener('prx:confirmed', (e) => { if (e.target === btn) confirmed++; });
    btn.addEventListener('click', () => { moduleClicks++; });

    click(window, btn);
    assert.ok(btn.classList.contains('is-armed'));
    assert.equal(btn.textContent.trim(), 'Удалить?');
    assert.ok(btn.querySelector('i.fa-trash-can'), 'иконка остаётся');
    assert.equal(submits, 0, 'первый клик не отправляет форму');
    assert.equal(moduleClicks, 0, 'первый клик не доходит до обработчика модуля');

    btn.querySelector('span').dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
    assert.equal(submits, 1);
    assert.equal(confirmed, 1);
    assert.equal(moduleClicks, 1);
    advance(0);
    assert.ok(!btn.classList.contains('is-armed'));
    assert.equal(btn.innerHTML, '<i class="fas fa-trash-can"></i>');

    // Взвод гаснет сам через 4 секунды…
    click(window, btn);
    assert.ok(btn.classList.contains('is-armed'));
    advance(4000);
    assert.ok(!btn.classList.contains('is-armed'));

    // …и от клика в другом месте, и от Esc.
    click(window, btn);
    click(window, document.getElementById('other'));
    assert.ok(!btn.classList.contains('is-armed'));
    click(window, btn);
    document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.ok(!btn.classList.contains('is-armed'));
    assert.equal(submits, 1);
    await flush();
    assert.deepEqual(errors, []);
    dom.window.close();
});

test('data-prx-arm: подпись меняется в [data-prx-arm-label]', () => {
    const { window, document, dom } = createPage(
        '<button id="b" class="prx-btn-danger" data-prx-arm="Точно удалить?"><i class="fas fa-trash-can"></i><span data-prx-arm-label>Удалить</span></button>');
    const btn = document.getElementById('b');
    click(window, btn);
    assert.equal(btn.querySelector('[data-prx-arm-label]').textContent, 'Точно удалить?');
    assert.ok(btn.querySelector('i'));
    dom.window.close();
});

test('data-prx-arm: подпись, которую сменил модуль, не затирается', () => {
    const { window, document, advance, dom } = createPage(`
        <button id="del" type="button" data-prx-arm="Удалить?"><i class="fas fa-trash-can"></i><span data-prx-arm-label>Удалить</span></button>
        <button id="many" type="button" data-prx-arm="Удалить выбранные?"><span data-prx-arm-label>Удалить выбранные (2)</span></button>`);
    const del = document.getElementById('del');
    del.addEventListener('click', () => { del.disabled = true; del.querySelector('span').textContent = 'Удаляю…'; });

    // Второй клик: обработчик модуля ставит «Удаляю…» — снятие взвода её оставляет.
    click(window, del);
    click(window, del);
    advance(0);
    assert.ok(!del.classList.contains('is-armed'));
    assert.equal(del.querySelector('span').textContent, 'Удаляю…');

    // Взвод гаснет сам, но счётчик уже сменился — остаётся новый, а не старый.
    const many = document.getElementById('many');
    click(window, many);
    assert.equal(many.textContent.trim(), 'Удалить выбранные?');
    many.querySelector('span').textContent = 'Удалить выбранные (3)';
    advance(4000);
    assert.ok(!many.classList.contains('is-armed'));
    assert.equal(many.textContent.trim(), 'Удалить выбранные (3)');

    // Без вмешательства модуля подпись по-прежнему возвращается.
    click(window, many);
    advance(4000);
    assert.equal(many.textContent.trim(), 'Удалить выбранные (3)');
    assert.equal(many.querySelector('span').textContent, 'Удалить выбранные (3)');
    dom.window.close();
});

test('взведённая кнопка видна в обеих темах: в сборке сплошной красный с !important', () => {
    // Решение владельца (16.10.2026): взвод обязан побеждать базовые классы кнопки,
    // иначе в день-теме «белое на белом». Теперь это одно общее правило вместо
    // важных классов в каждом модуле.
    const css = readFileSync(new URL('../../public_html/assets/css/app.css', import.meta.url), 'utf8');
    assert.match(css, /\[data-prx-arm\]\.is-armed\{background-color:#e11d48!important;border-color:#e11d48!important;color:#fff!important\}/);
});

test('data-prx-confirm: окно, «Отмена» ничего не делает, «Удалить» пропускает клик', async () => {
    const { window, document, errors, dom } = createPage(`
        <button id="del" data-prx-confirm="Удалить сущность {subject} и все связанные файлы?"
                data-prx-subject="<img src=x onerror=alert(1)>" data-prx-title="Удалить запись?"
                data-prx-ok="Удалить навсегда">Удалить запись</button>`);
    const btn = document.getElementById('del');
    let moduleClicks = 0;
    let confirmed = 0;
    btn.addEventListener('click', () => { moduleClicks++; });
    btn.addEventListener('prx:confirmed', () => { confirmed++; });
    btn.focus();

    click(window, btn);
    let modal = document.querySelector('.prx-modal');
    assert.ok(modal, 'окно открыто');
    assert.ok(modal.classList.contains('prx-ds'), 'окно несёт токены шкалы');
    assert.equal(modal.getAttribute('role'), 'alertdialog');
    assert.equal(modal.querySelector('h3').textContent, 'Удалить запись?');
    assert.match(modal.textContent, /невозможно отменить/);
    assert.equal(modal.querySelector('strong').textContent, '<img src=x onerror=alert(1)>');
    assert.equal(modal.querySelector('img'), null, 'subject — текст, не разметка');
    assert.equal(document.activeElement, modal.querySelector('[data-prx-cancel]'), 'фокус на безопасной кнопке');
    assert.equal(moduleClicks, 0);

    click(window, modal.querySelector('[data-prx-cancel]'));
    await flush();
    assert.equal(document.querySelector('.prx-modal'), null);
    assert.equal(moduleClicks, 0);
    assert.equal(document.activeElement, btn, 'фокус вернулся на кнопку');

    click(window, btn);
    modal = document.querySelector('.prx-modal');
    const ok = modal.querySelector('[data-prx-ok]');
    assert.equal(ok.textContent, 'Удалить навсегда');
    assert.ok(ok.classList.contains('prx-btn-danger-solid'));
    click(window, ok);
    await flush();
    assert.equal(document.querySelector('.prx-modal'), null);
    assert.equal(moduleClicks, 1);
    assert.equal(confirmed, 1);
    assert.deepEqual(errors, []);
    dom.window.close();
});

test('PRX.confirm: Esc — отказ; тон cta — зелёная кнопка; .is-125 наследуется', async () => {
    const { window, document, PRX, dom } = createPage('<div class="prx-ds is-125"></div>');
    let p = PRX.confirm({ title: 'Опубликовать?', message: 'Версия {subject} станет основной.', subject: 'v2', tone: 'cta', ok: 'Опубликовать' });
    const modal = document.querySelector('.prx-modal');
    assert.ok(modal.classList.contains('is-125'));
    assert.ok(modal.querySelector('[data-prx-ok]').classList.contains('prx-btn-cta'));
    assert.equal(modal.querySelector('[data-prx-note]'), null, 'у cta нет приписки про необратимость');
    document.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    assert.equal(await p, false);

    p = PRX.confirm({ message: 'Сбросить настройки?' });
    click(window, document.querySelector('.prx-modal [data-prx-ok]'));
    assert.equal(await p, true);
    dom.window.close();
});

test('data-prx-copy меняет иконку на галочку и возвращает; data-prx-dismiss закрывает плашку', async () => {
    const { window, document, advance, copied, dom } = createPage(`
        <button id="c" data-prx-copy="dsnf592ccad"><i class="fas fa-copy text-xs"></i></button>
        <div class="prx-flash prx-flash-ok" id="flash"><span>Сохранено</span><button id="x" data-prx-dismiss></button></div>`);
    const icon = document.querySelector('#c i');
    click(window, document.getElementById('c'));
    await flush();
    assert.deepEqual(copied, ['dsnf592ccad']);
    assert.ok(icon.classList.contains('fa-check'));
    assert.ok(icon.classList.contains('text-emerald-600'));
    advance(1200);
    assert.equal(icon.className, 'fas fa-copy text-xs');

    click(window, document.getElementById('x'));
    assert.equal(document.getElementById('flash'), null);
    dom.window.close();
});

test('PRX.hotkey: Ctrl+S в любой раскладке, within ограничивает фокусом', () => {
    const { window, document, PRX, dom } = createPage('<textarea id="raw"></textarea><input id="other">');
    let saves = 0;
    let parses = 0;
    const off = PRX.hotkey('mod+s', () => { saves++; });
    PRX.hotkey('mod+enter', () => { parses++; }, { within: '#raw' });

    const key = (init) => {
        const ev = new window.KeyboardEvent('keydown', Object.assign({ bubbles: true, cancelable: true }, init));
        document.activeElement.dispatchEvent(ev);
        return ev;
    };

    const ev = key({ ctrlKey: true, code: 'KeyS', key: 'ы' });
    assert.equal(saves, 1, 'русская раскладка: e.key = «ы», e.code = KeyS');
    assert.equal(ev.defaultPrevented, true);
    key({ metaKey: true, code: 'KeyS', key: 's' });
    assert.equal(saves, 2);
    key({ code: 'KeyS', key: 's' });
    assert.equal(saves, 2, 'без Ctrl не срабатывает');

    document.getElementById('other').focus();
    key({ ctrlKey: true, key: 'Enter', code: 'Enter' });
    assert.equal(parses, 0);
    document.getElementById('raw').focus();
    key({ ctrlKey: true, key: 'Enter', code: 'Enter' });
    assert.equal(parses, 1);

    off();
    key({ ctrlKey: true, code: 'KeyS', key: 's' });
    assert.equal(saves, 2, 'после отписки молчит');
    dom.window.close();
});

test('PRX.toast: нейтральный по умолчанию; тон err — rose, role=alert, держится дольше', () => {
    const { document, PRX, advance, dom } = createPage('');
    PRX.toast('Сохранено');
    const plain = document.querySelector('[role="status"]');
    assert.ok(plain && plain.className.includes('bg-gray-900/95'));
    assert.equal(plain.textContent, 'Сохранено');

    PRX.toast('<b>Сеть недоступна</b>', 0, 'err');
    const err = document.querySelector('[role="alert"]');
    assert.ok(err && err.className.includes('bg-rose-600'));
    assert.equal(err.textContent, '<b>Сеть недоступна</b>', 'текст, а не HTML');

    advance(1600);
    advance(300);
    assert.equal(document.querySelector('[role="status"]'), null, 'нейтральный тост ушёл через 1,6 с');
    assert.ok(document.querySelector('[role="alert"]'), 'ошибка ещё видна');
    advance(4000);
    advance(300);
    assert.equal(document.querySelector('[role="alert"]'), null);
    dom.window.close();
});
