import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// ИИ-составление шаблона: настоящий скрипт вьюхи в поддельном DOM.
// Проверяем, что панель ничего не сохраняет сама, тело запроса целое,
// а подстановка идёт ровно в карточку выбранного события и канала.
const view = readFileSync(new URL('../../application/modules/nj597_notifications/views/597/script.php', import.meta.url), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1];

function element(extra = {}) {
    return Object.assign({
        textContent: '',
        value: '',
        disabled: false,
        classList: { add() {}, remove() {} },
        scrollIntoView() {},
        addEventListener(event, cb) { this.handlers = this.handlers || {}; this.handlers[event] = cb; },
    }, extra);
}

function boot(response, options = {}) {
    const hasCard = options.hasCard !== false;
    const subjectField = element({ name: 'subject', value: 'старая тема' });
    const bodyField = element({ name: 'body', value: 'старый текст' });
    const cardSelector = 'form[data-nj597-tpl-event="nj120.job.done"][data-nj597-tpl-channel="email"]';
    const card = element({
        querySelector(selector) {
            if (selector.includes('subject')) return subjectField;
            if (selector.includes('body')) return bodyField;
            return null;
        },
    });

    const panel = {
        csrf: element({ name: 'csrf', value: 'csrf-test' }),
        event: element({ name: 'event', value: 'nj120.job.done' }),
        channel: element({ name: 'channel', value: 'email' }),
        tone: element({ name: 'tone', value: 'short' }),
        about: element({ name: 'about', value: 'обзор ждёт публикации' }),
        source: element({ name: 'source', value: 'Напоминаем: обзор собран.' }),
        off: element({ name: 'skip', value: 'служебное', disabled: true }),
    };
    const fields = Object.values(panel);
    const runBtn = element({});
    const form = element({
        querySelectorAll(selector) { return selector === 'input[name], select[name], textarea[name]' ? fields : []; },
        querySelector(selector) {
            if (selector === '[data-nj597-ai-run]') { return runBtn; }
            const by_name = /\[name="([^"]+)"\]/.exec(selector);
            return by_name && panel[by_name[1]] ? panel[by_name[1]] : null;
        },
    });

    const ui = {
        form,
        runBtn,
        applyBtn: element({}),
        againBtn: element({}),
        out: element({}),
        outSubject: element({}),
        outBody: element({}),
        outNotes: element({}),
        outMeta: element({}),
        outFlash: element({}),
        card,
        subjectField,
        bodyField,
        cardSelector,
        requests: [],
    };

    // Список «взять готовое сообщение»: опция с data-text, как в разметке.
    const option = {
        getAttribute(name) { return name === 'data-text' ? 'Напоминаем: обзор готов.' : null; },
    };
    ui.recent = Object.assign(element({ selectedIndex: 0 }), { options: [option] });

    // Чипы подстановок: один чип и поле шаблона, куда он вставляет {{title}}.
    ui.chip = element({});
    ui.chip.getAttribute = (name) => (name === 'data-nj597-insert' ? '{{title}}' : null);
    ui.chipField = element({ name: 'body', value: 'Строка: ', selectionStart: 8, selectionEnd: 8 });
    ui.chipField.setRangeText = function (token, from, to) {
        this.value = this.value.slice(0, from) + token + this.value.slice(to);
    };

    const map = {
        'form[data-nj597-ai]': ui.form,
        '[data-nj597-ai-run]': ui.runBtn,
        '[data-nj597-ai-apply]': ui.applyBtn,
        '[data-nj597-ai-again]': ui.againBtn,
        '[data-nj597-ai-out]': ui.out,
        '[data-nj597-ai-subject]': ui.outSubject,
        '[data-nj597-ai-body]': ui.outBody,
        '[data-nj597-ai-notes]': ui.outNotes,
        '[data-nj597-ai-meta]': ui.outMeta,
        '[data-nj597-ai-flash]': ui.outFlash,
        '[data-nj597-ai-recent]': ui.recent,
        'form[data-nj597-tpl-event] textarea[name="body"]': ui.chipField,
    };

    const document = {
        querySelectorAll(selector) { return selector === '[data-nj597-insert]' ? [ui.chip] : []; },
        addEventListener(event, cb) { ui.focusin = cb; },
        querySelector(selector) {
            if (selector === cardSelector) return hasCard ? card : null;
            return selector in map ? map[selector] : null;
        },
        getElementById() { return null; },
        createElement() { return element(); },
    };

    vm.runInNewContext(script, {
        document, URL, URLSearchParams, FormData, setTimeout, clearTimeout,
        window: {}, console,
        fetch: async (url, options) => { ui.requests.push([url, options]); return { json: async () => response }; },
    });

    return ui;
}

const tick = () => new Promise((resolve) => setImmediate(resolve));

async function compose(ui) {
    ui.form.handlers.submit({ preventDefault() {} });
    await tick();
}

test('сборка: форма уходит на /nj597/admin/ai_template целиком', async () => {
    const ui = boot({ ok: true, subject: 'Тема', body: 'Текст', notes: '', model: 'local::qwen', ms: 1200 });
    await compose(ui);

    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0][0], '/nj597/admin/ai_template');
    const body = new URLSearchParams(ui.requests[0][1].body);
    assert.equal(body.get('csrf'), 'csrf-test');
    assert.equal(body.get('event'), 'nj120.job.done');
    assert.equal(body.get('channel'), 'email');
    assert.equal(body.get('tone'), 'short');
    assert.equal(body.get('about'), 'обзор ждёт публикации');
    assert.equal(body.get('source'), 'Напоминаем: обзор собран.');
    assert.equal(body.get('skip'), null, 'выключенные поля в запрос не попадают');
});

test('сборка: результат показан, а шаблон до нажатия кнопки не тронут', async () => {
    const ui = boot({ ok: true, subject: 'Обзор ждёт публикации', body: 'Здравствуйте!', notes: 'учтено', model: 'local::qwen', ms: 900 });
    await compose(ui);

    assert.equal(ui.outSubject.textContent, 'Обзор ждёт публикации');
    assert.equal(ui.outBody.textContent, 'Здравствуйте!');
    assert.equal(ui.outNotes.textContent, 'учтено');
    assert.match(ui.outMeta.textContent, /local::qwen · 900 мс/);
    assert.equal(ui.runBtn.disabled, false, 'кнопка разблокирована');
    assert.equal(ui.subjectField.value, 'старая тема', 'до нажатия «подставить» шаблон не меняется');
});

test('сборка: пустая тема колокольчика объясняется словами', async () => {
    const ui = boot({ ok: true, subject: '', body: 'Обзор готов.', notes: '', model: 'local::qwen', ms: 300 });
    await compose(ui);

    assert.match(ui.outSubject.textContent, /у этого канала темы нет/);
});

test('сборка: ошибка модели видна, кнопку можно нажать снова', async () => {
    const ui = boot({ ok: false, error: 'Модель ответила не в формате шаблона — попробуйте ещё раз' });
    await compose(ui);

    assert.match(ui.outBody.textContent, /не в формате шаблона/);
    assert.equal(ui.runBtn.disabled, false, 'кнопка разблокирована');

    ui.applyBtn.handlers.click();
    assert.equal(ui.subjectField.value, 'старая тема', 'после ошибки подставлять нечего');
});

test('сборка: повторный запуск шлёт новый запрос', async () => {
    const ui = boot({ ok: true, subject: 'Тема', body: 'Текст', notes: '', model: 'local::qwen', ms: 100 });
    await compose(ui);
    ui.againBtn.handlers.click();
    await tick();

    assert.equal(ui.requests.length, 2);
});

test('подстановка: значения попадают в карточку события и канала', async () => {
    const ui = boot({ ok: true, event_alias: 'nj120.job.done', channel: 'email', subject: 'Обзор ждёт публикации', body: 'Здравствуйте! Обзор собран.', notes: '', model: 'local::qwen', ms: 700 });
    await compose(ui);

    ui.applyBtn.handlers.click();

    assert.equal(ui.subjectField.value, 'Обзор ждёт публикации');
    assert.equal(ui.bodyField.value, 'Здравствуйте! Обзор собран.');
    assert.match(ui.outFlash.textContent, /Сохранить/);
});

test('подстановка: без карточки канала сказано, что включить канал', async () => {
    const ui = boot({ ok: true, event_alias: 'nj120.job.done', channel: 'telegram', subject: 'Тема', body: 'Текст', notes: '', model: 'local::qwen', ms: 700 }, { hasCard: false });
    await compose(ui);

    ui.applyBtn.handlers.click();

    assert.equal(ui.subjectField.value, 'старая тема', 'без карточки ничего не подставляем');
    assert.equal(ui.bodyField.value, 'старый текст');
    assert.match(ui.outFlash.textContent, /включите канал/i);
});

test('источник: готовое сообщение из ленты подставляется в поле', async () => {
    const ui = boot({ ok: true, subject: 'Тема', body: 'Текст', notes: '', model: 'local::qwen', ms: 100 });
    const source = ui.form.querySelectorAll('input[name], select[name], textarea[name]')[5];
    assert.equal(source.name, 'source');

    ui.recent.selectedIndex = 0;
    ui.recent.handlers.change();

    assert.equal(source.value, 'Напоминаем: обзор готов.');
    assert.match(ui.outFlash.textContent, /Можно собирать/i);
});

test('подстановка: чип вставляет {{title}} в поле на месте курсора', async () => {
    const ui = boot({ ok: true, subject: 'Тема', body: 'Текст', notes: '', model: 'local::qwen', ms: 100 });

    ui.focusin({ target: ui.chipField });
    ui.chip.handlers.click();

    assert.equal(ui.chipField.value, 'Строка: {{title}}');
});
