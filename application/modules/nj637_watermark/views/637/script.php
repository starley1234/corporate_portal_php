<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj637: водяные знаки — клиентское зеркало серверного слоя (/watermark/api/apply). */
(function () {
    'use strict';

    var fileEl   = document.getElementById('nj637-file');
    var textEl   = document.getElementById('nj637-text');
    var modeEl   = document.getElementById('nj637-mode');
    var pagesEl  = document.getElementById('nj637-pages');
    var sizeEl   = document.getElementById('nj637-size');
    var opacityEl= document.getElementById('nj637-opacity');
    var goBtn    = document.getElementById('nj637-go');

    var statusRow= document.getElementById('nj637-status');
    var statusTx = document.getElementById('nj637-status-text');
    var timerEl  = document.getElementById('nj637-timer');
    var errBox   = document.getElementById('nj637-error');
    var doneBox  = document.getElementById('nj637-done');

    function setBusy(busy) {
        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
            statusTx.textContent = 'Накладываю знак…';
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
        doneBox.classList.add('hidden');
    }

    function done(text, name) {
        errBox.classList.add('hidden');
        doneBox.innerHTML = '';
        var ok = document.createElement('span');
        ok.className = 'font-semibold';
        ok.textContent = text;
        doneBox.appendChild(ok);
        doneBox.appendChild(document.createTextNode(' '));

        var dl = document.createElement('button');
        dl.type = 'button';
        dl.className = 'n2j-btn-process mt-2';
        dl.innerHTML = '<i class="fas fa-download"></i> Скачать ' + (name || 'file.pdf');
        dl.addEventListener('click', function () {
            var buf = new Uint8Array(atob(pdfB64));
            var blob = new Blob([buf], { type: 'application/pdf' });
            if (window.N2J && N2J.downloadBlob) {
                N2J.downloadBlob(blob, name || 'file.pdf');
                return;
            }
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url; a.download = name || 'file.pdf';
            document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
        });
        doneBox.appendChild(dl);
        doneBox.classList.remove('hidden');
    }

    var pdfB64 = '';

    function run() {
        var f = fileEl.files && fileEl.files[0];
        if (!f) { showError('Сначала выберите PDF-файл.'); fileEl.focus(); return; }
        var text = textEl.value.trim();
        if (!text) { showError('Введите текст знака.'); textEl.focus(); return; }

        errBox.classList.add('hidden');
        doneBox.classList.add('hidden');
        pdfB64 = '';
        setBusy(true);
        goBtn.disabled = true;

        var fd = new FormData();
        fd.append('file', f);
        fd.append('text', text);
        fd.append('mode', modeEl.value);
        fd.append('pages', pagesEl.value);
        var size = parseInt(sizeEl.value, 10);
        var op = parseInt(opacityEl.value, 10);
        if (size > 0) fd.append('size', String(size));
        if (op > 0) fd.append('opacity', String(op / 100));

        fetch('/watermark/api/apply', {
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
                var f = fileEl.files && fileEl.files[0];
                var base = (f ? f.name : 'document').replace(/\.pdf$/i, '');
                done('Готово: знак наложен (' + res.size + ' байт).', base + '_sign.pdf');
            } else {
                showError((res.json && res.json.error) || 'Не удалось наложить знак.');
            }
        })
        .catch(function () { showError('Сервер не ответил — попробуйте ещё раз.'); })
        .finally(function () {
            setBusy(false);
            goBtn.disabled = false;
        });
    }

    goBtn.addEventListener('click', run);
    textEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') run();
    });
})();
</script>
