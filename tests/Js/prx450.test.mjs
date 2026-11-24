import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
import YAML from 'yaml';
const root = new URL('../../', import.meta.url);
const load = path => readFileSync(new URL(path, root), 'utf8');
const script = load('application/modules/prx450_document_ocr/views/prx450/script.php').split('<script>')[1].split('</script>')[0];

test('OCR submit is single-flight, busy via shared PRX and restorable after back navigation', () => {
    const dom = new JSDOM('<form id="prx450-upload"><button type="submit">OCR</button></form>', {runScripts:'outside-only'});
    const w=dom.window; const calls=[];
    w.PRX={run:(button,on)=>{button.disabled=on; calls.push(on);}};
    w.eval(script);
    const form=w.document.querySelector('form');
    const submit=()=>{const event=new w.Event('submit',{cancelable:true});form.dispatchEvent(event);return event;};
    assert.equal(submit().defaultPrevented,false);
    assert.equal(submit().defaultPrevented,true);
    assert.deepEqual(calls,[true]);
    w.dispatchEvent(new w.Event('pageshow'));
    assert.equal(form.querySelector('button').disabled,false);
    assert.equal(submit().defaultPrevented,false);
    dom.window.close();
});

test('OCR result download is a browser blob, never an upload or stored portal artifact', () => {
    const dom=new JSDOM('<form id="prx450-upload"><button type="submit"></button></form><pre id="prx450-content"># Hello</pre><button id="prx450-download"></button>',{runScripts:'outside-only'});
    const w=dom.window; let blob; let download;
    w.URL.createObjectURL=value=>{blob=value;return 'blob:result';};
    w.URL.revokeObjectURL=()=>{};
    w.HTMLAnchorElement.prototype.click=function(){download=this.download;};
    w.eval(script);
    w.document.getElementById('prx450-download').click();
    assert.equal(blob.type,'text/markdown;charset=utf-8');
    assert.equal(download,'document.md');
    dom.window.close();
});

test('OpenAPI covers the whitelist, scope, multipart input and stable result shape', () => {
    const doc=YAML.parse(load('openapi/prx450.yaml'));
    const operation=doc.paths['/api/v1/document-ocr/parse'].post;
    const config=load('application/modules/prx450_document_ocr/config/prx450_document_ocr.php');
    const whitelist=config.split("'client_overrides' => [")[1].split(']')[0];
    const keys=Array.from(whitelist.matchAll(/'([a-z_]+)'/g),m=>m[1]).sort();
    assert.deepEqual(operation.parameters.map(x=>x.name).sort(),keys);
    assert.match(doc.components.securitySchemes.ApiKey.description,/prx450.parse/);
    assert.deepEqual(operation.requestBody.content['multipart/form-data'].schema.required,['file']);
    assert.ok(operation.responses[413]); assert.ok(operation.responses[504]);
    assert.deepEqual(doc.components.schemas.Result.required,['file','content','cached','cache_source','engine_used','llm_fixed','raw_result']);
    assert.doesNotMatch(load('application/modules/prx450_document_ocr/views/prx450/index.php'),/\$_POST/);
});

function uploadPage(t) {
    const dom = new JSDOM(`<form id="prx450-upload" data-max-bytes="2048">
        <div data-prx450-drop><p data-prx450-drag-hint hidden></p>
            <input type="file" id="prx450-file" accept=".pdf,.png,application/pdf,image/png" required>
        </div>
        <div data-prx450-selection class="hidden"><p id="prx450-file-info"></p><button type="button" id="prx450-file-clear">Убрать файл</button></div>
        <p id="prx450-file-error" hidden></p><button type="submit">Распознать</button>
    </form>`, {runScripts: 'outside-only'});
    const w = dom.window;
    t.after(() => w.close());
    const form = w.document.querySelector('form');
    const input = form.querySelector('input');
    const error = form.querySelector('#prx450-file-error');
    const selection = form.querySelector('[data-prx450-selection]');
    const drop = form.querySelector('[data-prx450-drop]');
    // jsdom has no DataTransfer: model the browser's file-list setter and value reset.
    let files = [];
    Object.defineProperties(input, {
        files: {get: () => files, set: value => { files = value; }},
        value: {set: () => { files = []; }},
    });
    function choose(name, size, type) {
        const file = new w.File([new Uint8Array(size)], name, {type});
        input.files = [file];
        input.dispatchEvent(new w.Event('change'));
        return file;
    }
    function drag(kind, list) {
        const event = new w.Event(kind, {cancelable: true});
        Object.defineProperty(event, 'dataTransfer', {value: {files: list, types: ['Files']}});
        drop.dispatchEvent(event);
        return event;
    }
    w.eval(script);
    return {w, form, input, selection, error, drop, choose, drag};
}

test('OCR shows the selected name and size as text and can clear the file without submitting', (t) => {
    const p = uploadPage(t);
    assert.equal(p.drop.querySelector('[data-prx450-drag-hint]').hidden, false);
    assert.equal(p.selection.classList.contains('hidden'), true);
    p.choose('<scan>.PDF', 1024, 'application/pdf');
    assert.equal(p.selection.classList.contains('hidden'), false);
    assert.match(p.selection.querySelector('#prx450-file-info').textContent, /<scan>.PDF · 1 КиБ/);
    assert.equal(p.selection.querySelector('scan'), null);
    assert.equal(p.error.hidden, true);
    p.selection.querySelector('button').click();
    assert.equal(p.input.files.length, 0);
    assert.equal(p.selection.classList.contains('hidden'), true);
    assert.equal(p.w.document.activeElement, p.input);
    assert.equal(p.form.dataset.busy, undefined);
});

test('OCR provides inline errors for empty, oversized, or unsupported files and accepts replacement', (t) => {
    const p = uploadPage(t);
    for (const [name, bytes, type, message] of [
        ['empty.pdf', 0, 'application/pdf', /Файл пуст/],
        ['large.png', 2049, 'image/png', /больше 2 КиБ/],
        ['notes.txt', 1024, 'text/plain', /формат не поддерживается/],
    ]) {
        p.choose(name, bytes, type);
        assert.equal(p.input.validity.customError, true);
        assert.equal(p.input.getAttribute('aria-invalid'), 'true');
        assert.match(p.error.textContent, message);
    }
    p.choose('scan.png', 1024, 'image/png');
    assert.equal(p.input.validity.customError, false);
    assert.equal(p.input.hasAttribute('aria-invalid'), false);
    assert.equal(p.error.hidden, true);
});

test('OCR accepts a single dropped document but does not implicitly send it', (t) => {
    const p = uploadPage(t);
    const file = new p.w.File(['%PDF'], 'scan.pdf', {type: 'application/pdf'});
    assert.equal(p.drag('dragover', [file]).defaultPrevented, true);
    assert.equal(p.drop.dataset.dragging, 'true');
    assert.equal(p.drag('drop', [file, file]).defaultPrevented, true);
    assert.match(p.error.textContent, /только один документ/);
    assert.equal(p.input.files.length, 0);
    p.drag('drop', [file]);
    assert.equal(p.drop.dataset.dragging, undefined);
    assert.equal(p.input.files[0], file);
    assert.equal(p.form.dataset.busy, undefined);
    assert.equal(p.error.hidden, true);
});
