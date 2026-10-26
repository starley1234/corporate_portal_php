<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj636: подписи и реквизиты PDF — client-зеркало сервера (/pdf-sign/api/*). */
(function () {
    'use strict';

    var fileEl  = document.getElementById('nj636-file');
    var linesEl = document.getElementById('nj636-lines');
    var posEl   = document.getElementById('nj636-pos');
    var pagesEl = document.getElementById('nj636-pages');
    var stampBtn= document.getElementById('nj636-stamp');
    var countBtn= document.getElementById('nj636-count');
    var stripBtn= document.getElementById('nj636-strip');

    var statusRow= document.getElementById('nj636-status');
    var statusTx = document.getElementById('nj636-status-text');
    var timerEl  = document.getElementById('nj636-timer');
    var errBox   = document.getElementById('nj636-error');
    var resBox   = document.getElementById('nj636-result');

    var pdfB64 = '';
    var pdfName = 'document.pdf';

    function setBusy(busy, text) {
        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
            statusTx.textContent = text || 'Обрабатываю…';
            var t0 = Date.now();
            clearInterval(timerEl._t);
            timerEl._t = setInterval(function () {
                timerEl.textContent = ((Date.now() - t0) / 1000).toFixed(1) + 's';
            }, 100);
        } else {
            clearInterval(timerEl._t);
            timerEl.textContent = '';
            statusRow.classList.add('hidden');
            statusRow.classList.remove('flex');
        }
    }

    function showError(msg) {
        errBox.textContent = msg || 'Не удалось выполнить операцию.';
        errBox.classList.remove('hidden');
        resBox.classList.add('hidden');
    }

    function clearState() {
        errBox.classList.add('hidden');
        resBox.classList.add('hidden');
        pdfB64 = '';
    }

    function showJson(j) {
        if (!j) return;
        if (!j.ok) { showError(j.error || 'Не удалось выполнить операцию.'); return; }

        errBox.classList.add('hidden');
        resBox.innerHTML = '';

        if (typeof j.pages !== 'undefined') appendRow('Страниц', String(j.pages));
        if (typeof j.annotations !== 'undefined') appendRow('Аннотаций', String(j.annotations));
        if (typeof j.text_markers !== 'undefined') appendRow('Маркеров подписи', String(j.text_markers));
        if (typeof j.likely_signed !== 'undefined') appendRow('Вероятно подписан', j.likely_signed ? 'да' : 'нет');
        if (typeof j.removed_annots !== 'undefined') appendRow('Удалено аннотаций', String(j.removed_annots));
        if (typeof j.lines !== 'undefined') appendRow('Строк реквизитов', String(j.lines));

        if (!resBox.textContent.trim()) appendRow('Статус', 'готово');
        resBox.classList.remove('hidden');
    }

    function appendRow(k, v) {
        var p = document.createElement('p');
        var b = document.createElement('span');
        b.className = 'font-semibold';
        b.textContent = k + ': ';
        p.appendChild(b);
        p.appendChild(document.createTextNode(v));
        resBox.appendChild(p);
    }

    function finishPdf(res, suffix) {
        var base = pdfName.replace(/\.pdf$/i, '');
        showDownload(res.size, base + suffix);
    }

    function showDownload(size, name) {
        errBox.classList.add('hidden');
        resBox.innerHTML = '';

        var p = document.createElement('p');
        p.className = 'font-semibold';
        p.textContent = 'Готово (' + size + ' байт).';
        resBox.appendChild(p);

        var dl = document.createElement('button');
        dl.type = 'button';
        dl.className = 'n2j-btn-process mt-2';
        dl.innerHTML = '<i class="fas fa-download"></i> Скачать ' + name;
        dl.addEventListener('click', function () {
            var u8 = new Uint8Array(atob(pdfB64).split('').map(function (c) { return c.charCodeAt(0); }));
            var blob = new Blob([u8], { type: 'application/pdf' });
            if (window.N2J && N2J.downloadBlob) {
                N2J.downloadBlob(blob, name);
                return;
            }
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = name;
            document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
        });
        resBox.appendChild(dl);
        resBox.classList.remove('hidden');
    }

    function post(action, fields, isPdf, suffix) {
        var f = fileEl.files && fileEl.files[0];
        if (!f) { showError('Сначала выберите PDF-файл.'); fileEl.focus(); return; }

        pdfName = f.name;
        clearState();
        setBusy(true, fields._label || 'Обрабатываю…');
        stampBtn.disabled = countBtn.disabled = stripBtn.disabled = true;

        var fd = new FormData();
        fd.append('file', f);
        Object.keys(fields).forEach(function (k) {
            if (k !== '_label') fd.append(k, fields[k]);
        });

        fetch('/pdf-sign/api/' + action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            cache: 'no-store'
        })
        .then(function (r) {
            var ct = r.headers.get('Content-Type') || '';
            if (ct.indexOf('application/pdf') !== -1) {
                return r.blob().then(function (b) {
                    return b.arrayBuffer().then(function (ab) {
                        var u8 = new Uint8Array(ab);
                        var s = '';
                        for (var i = 0; i < u8.length; i++) s += String.fromCharCode(u8[i]);
                        pdfB64 = btoa(s);
                        return { pdf: true, size: b.size };
                    });
                });
            }
            return r.json().then(function (j) { return { pdf: false, json: j }; });
        })
        .then(function (res) {
            if (res.pdf) {
                finishPdf(res, suffix);
            } else {
                showJson(res.json);
            }
        })
        .catch(function () { showError('Сервер не ответил — попробуйте ещё раз.'); })
        .finally(function () {
            setBusy(false);
            stampBtn.disabled = countBtn.disabled = stripBtn.disabled = false;
        });
    }

    stampBtn.addEventListener('click', function () {
        post('stamp', {
            lines: linesEl.value,
            pos: posEl.value,
            pages: pagesEl.value,
            underline: '1',
            _label: 'Накладываю реквизиты…'
        }, true, '_signed.pdf');
    });

    countBtn.addEventListener('click', function () {
        post('count', { _label: 'Считаю подписи…' }, false, '');
    });

    stripBtn.addEventListener('click', function () {
        post('strip', { _label: 'Убираю подписи…' }, true, '_clean.pdf');
    });
})();
</script>
