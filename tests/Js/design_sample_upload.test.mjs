import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../application/views/pages/required_design_sample.php', import.meta.url), 'utf8');
const markup = source.match(/<section id="file-upload"[\s\S]*?<\/section>/)[0];
const script = source.match(/<script id="ds-upload-demo">([\s\S]*?)<\/script>/)[1];

function uploadDemo(t) {
    const { window } = new JSDOM(markup, { runScripts: 'outside-only' });
    t.after(() => window.close());
    const { document } = window;
    const input = document.getElementById('ds-upload-file');
    const form = input.form;
    let files = [];
    // jsdom does not implement DataTransfer or the native file chooser.
    const nativeValidity = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'validity').get;
    Object.defineProperties(input, {
        files: { get: () => files, set: value => { files = value; } },
        value: { set: () => { files = []; } },
        validity: { get: () => {
            const { customError } = nativeValidity.call(input);
            return { customError, valid: files.length === 1 && !customError };
        } },
    });
    window.eval(script);
    function choose(name, size, type = '') {
        const file = new window.File([new Uint8Array(size)], name, { type });
        input.files = [file];
        input.dispatchEvent(new window.Event('change'));
        return file;
    }
    function drop(list) {
        const event = new window.Event('drop', { cancelable: true });
        Object.defineProperty(event, 'dataTransfer', { value: { files: list, types: ['Files'] } });
        document.getElementById('ds-upload-drop').dispatchEvent(event);
        return event;
    }
    return { window, document, input, form, choose, drop };
}

test('the file-upload example cannot post files and shows the local check result', (t) => {
    const { window, document, form, input, choose } = uploadDemo(t);
    assert.equal(form.method, 'dialog');
    assert.equal(input.required, true);
    const status = document.getElementById('ds-upload-status');
    choose('<scan>.PDF', 1024, 'application/pdf');
    assert.equal(status.textContent, '<scan>.PDF · 1 КиБ');
    assert.equal(status.querySelector('scan'), null);
    const submit = new window.Event('submit', { cancelable: true });
    form.dispatchEvent(submit);
    assert.equal(submit.defaultPrevented, true);
    assert.match(status.textContent, /Файл не отправлен/);
    document.getElementById('ds-upload-clear').click();
    assert.equal(input.files.length, 0);
    assert.equal(document.activeElement, input);
    assert.equal(status.textContent, '');
});

test('the example explains file errors and accepts one dropped replacement', (t) => {
    const { document, input, choose, drop } = uploadDemo(t);
    const error = document.getElementById('ds-upload-error');
    for (const [name, size, reason] of [
        ['notes.txt', 2, /Выберите файл PDF/],
        ['empty.pdf', 0, /Файл пуст/],
        ['large.jpg', 10 * 1048576 + 1, /больше 10 МиБ/],
    ]) {
        choose(name, size);
        assert.equal(input.validity.customError, true);
        assert.match(error.textContent, reason);
    }
    const image = choose('scan.jpg', 1024, 'image/jpeg');
    drop([image, image]);
    assert.match(error.textContent, /только один файл/);
    assert.deepEqual(input.files, [image]);
    assert.equal(drop([image]).defaultPrevented, true);
    assert.equal(input.validity.customError, false);
    assert.equal(input.hasAttribute('aria-invalid'), false);
    assert.equal(error.textContent, '');
});
