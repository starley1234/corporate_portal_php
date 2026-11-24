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
