import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';
const root=new URL('../../',import.meta.url);
const source=readFileSync(new URL('public_html/assets/js/prx395-status.js',root),'utf8');

function page(pending=false) {
    const dom=new JSDOM(`<form data-prx395-submit><input name="sample" type="hidden" value="1"><button type="submit">Sample</button></form><form data-prx395-submit><button type="submit">Upload</button></form>${pending?'<div data-prx395-pending></div>':''}`,{runScripts:'outside-only'});
    const w=dom.window; const timers=[]; const busy=[];
    w.setTimeout=(fn,ms)=>timers.push({fn,ms});
    w.PRX={run:(button,on)=>{button.disabled=on;busy.push(on);}};
    w.eval(source);
    return {dom,w,timers,busy};
}

test('PDF submit is single-flight across upload and sample without losing sample field',()=>{
    const p=page();
    try {
        const forms=p.w.document.querySelectorAll('form');
        const first=new p.w.Event('submit',{cancelable:true});
        forms[0].dispatchEvent(first);
        assert.equal(first.defaultPrevented,false);
        assert.equal(new p.w.FormData(forms[0]).get('sample'),'1');
        assert.ok(Array.from(p.w.document.querySelectorAll('button')).every(b=>b.disabled));
        const duplicate=new p.w.Event('submit',{cancelable:true});
        forms[1].dispatchEvent(duplicate);
        assert.equal(duplicate.defaultPrevented,true);
        p.w.dispatchEvent(new p.w.Event('pageshow'));
        assert.ok(Array.from(p.w.document.querySelectorAll('button')).every(b=>!b.disabled));
        assert.equal(p.timers.length,0);
    } finally {p.dom.window.close();}
});

test('auto-refresh is scheduled only for pending extraction',()=>{
    const p=page(true);
    try {assert.equal(p.timers.length,1);assert.equal(p.timers[0].ms,3000);}
    finally {p.dom.window.close();}
});

test('connection examples match routes, scope, status contract and idempotency semantics',()=>{
    const help=readFileSync(new URL('application/modules/prx395_document_extract/views/prx395/api_help.php',root),'utf8');
    for(const text of ['prx395.extract','Idempotency-Key','Authorization: Bearer $PRX_API_KEY','/api/v1/document-extractions','status_url','result.text','succeeded','failed','canceled','idempotency_key_expired','затем удаляется']) assert.ok(help.includes(text),text);
    assert.match(help,/UI и API — разные владельцы/);
    const ui=readFileSync(new URL('application/modules/prx395_document_extract/views/prx395/index.php',root),'utf8');
    assert.doesNotMatch(ui,/prx-panel/);
    assert.match(ui,/View::factory\('prx395\/api_help',/);
    assert.match(ui,/HTML::chars\(\$job\['result'\]\['text'\]\)/);
});

test('friendly PDF picker announces filename and rejects wrong type or oversized PDF',()=>{
    const dom=new JSDOM('<div data-prx395-drop><input type="file" id="pdf-file" data-max-bytes="2097152" data-max-label="2 МиБ"><p id="prx395-file-info"></p></div>',{runScripts:'outside-only'});
    const w=dom.window;
    w.eval(source);
    const input=w.document.getElementById('pdf-file');
    function choose(name,size) {
        Object.defineProperty(input,'files',{value:[{name,size}],configurable:true});
        input.dispatchEvent(new w.Event('change'));
    }
    choose('<test>.pdf',1024);
    assert.equal(input.validationMessage,'');
    assert.match(w.document.getElementById('prx395-file-info').textContent,/<test>\.pdf/);
    assert.equal(w.document.querySelector('test'),null);
    choose('not.pdf.exe',1024);
    assert.notEqual(input.validationMessage,'');
    choose('fits.pdf',2097152);
    assert.equal(input.validationMessage,'');
    choose('large.pdf',2097153);
    assert.match(input.validationMessage,/2 МиБ/);
    dom.window.close();
});

test('upload and API cards fill their columns and keep keyboard-accessible file input',()=>{
    const ui=readFileSync(new URL('application/modules/prx395_document_extract/views/prx395/index.php',root),'utf8');
    assert.match(ui,/xl:grid-cols-\[minmax\(0,3fr\)_minmax\(0,2fr\)\]/);
    // Capping just the left stack leaves an unallocated strip inside its grid column.
    assert.doesNotMatch(ui,/max-w-2xl/);
    assert.match(ui,/focus-within:ring-2/);
    assert.match(ui,/type="file" name="file"[^>]+aria-describedby="prx395-file-info"/);
    assert.doesNotMatch(ui,/class="hidden"[^>]+id="pdf-file"/);
});
