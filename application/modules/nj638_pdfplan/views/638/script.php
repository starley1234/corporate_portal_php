<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj638: конструктор А4 — client-зеркало сервера (/pdf-plan/api/build). */
(function () {
    'use strict';

    var titleEl = document.getElementById('nj638-title');
    var dateEl  = document.getElementById('nj638-date');
    var subEl   = document.getElementById('nj638-subtitle');
    var textEl  = document.getElementById('nj638-text');
    var colsEl  = document.getElementById('nj638-columns');
    var sizeEl  = document.getElementById('nj638-size');
    var footerEl= document.getElementById('nj638-footer');
    var goBtn   = document.getElementById('nj638-go');

    var statusRow= document.getElementById('nj638-status');
    var statusTx = document.getElementById('nj638-status-text');
    var timerEl  = document.getElementById('nj638-timer');
    var errBox   = document.getElementById('nj638-error');
    var resBox   = document.getElementById('nj638-result');

    var pdfB64 = '';

    function setBusy(busy) {
        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
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

    function run() {
        errBox.classList.add('hidden');
        resBox.classList.add('hidden');
        pdfB64 = '';
        setBusy(true);
        goBtn.disabled = true;

        var payload = {
            title: titleEl.value.trim(),
            subtitle: subEl.value.trim(),
            date: dateEl.value.trim(),
            footer: footerEl.value.trim(),
            text: textEl.value,
            columns: parseInt(colsEl.value, 10),
            size: parseFloat(sizeEl.value)
        };

        fetch('/pdf-plan/api/build', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
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
                showDownload(res.size);
            } else {
                var j = res.json || {};
                errBox.textContent = j.error || 'Не удалось собрать документ.';
                errBox.classList.remove('hidden');
            }
        })
        .catch(function () {
            errBox.textContent = 'Сервер не ответил — попробуйте ещё раз.';
            errBox.classList.remove('hidden');
        })
        .finally(function () {
            setBusy(false);
            goBtn.disabled = false;
        });
    }

    function showDownload(size) {
        var name = (titleEl.value.trim() || 'document') + '.pdf';

        resBox.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'font-semibold';
        p.textContent = 'Готово (' + size + ' байт).';
        resBox.appendChild(p);

        var dl = document.createElement('button');
        dl.type = 'button';
        dl.className = 'n2j-btn-process mt-2';
        dl.innerHTML = '<i class="fas fa-download"></i> Скачать PDF';
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

    goBtn.addEventListener('click', run);
    textEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) run();
    });
})();
</script>
