<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/* nj634: PDF-инструменты — клиентское зеркало серверного слоя (/pdf/api/*).
   Браузер только отправляет файл/текст и показывает результат; вся работа — на сервере. */
(function () {
    'use strict';

    var tabFile = document.getElementById('nj634-tab-file');
    var tabTxt  = document.getElementById('nj634-tab-txt');
    var paneFile = document.getElementById('nj634-pane-file');
    var paneTxt  = document.getElementById('nj634-pane-txt');

    var fileEl   = document.getElementById('nj634-file');
    var fileMeta = document.getElementById('nj634-file-meta');
    var filesEl  = document.getElementById('nj634-files');
    var filesMeta= document.getElementById('nj634-files-meta');
    var opButtons= document.querySelectorAll('.nj634-op');
    var splitBox = document.getElementById('nj634-split-box');
    var mergeBox = document.getElementById('nj634-merge-box');
    var mergeFilesBox = document.getElementById('nj634-mergefiles-box');
    var firstEl  = document.getElementById('nj634-first');
    var lastEl   = document.getElementById('nj634-last');
    var pagesEl  = document.getElementById('nj634-pages');
    var goBtn    = document.getElementById('nj634-go');

    var titleEl  = document.getElementById('nj634-title');
    var txtEl    = document.getElementById('nj634-txt');
    var genBtn   = document.getElementById('nj634-gen');

    var statusRow= document.getElementById('nj634-status');
    var statusTx = document.getElementById('nj634-status-text');
    var timerEl  = document.getElementById('nj634-timer');
    var errBox   = document.getElementById('nj634-error');
    var resBox   = document.getElementById('nj634-result');
    var dlBtn    = document.getElementById('nj634-dl');
    var copyBtn  = document.getElementById('nj634-copy');

    var op = 'info';
    var lastText = '';
    var lastPdfBlob = null;
    var lastPdfName = '';

    // --- вкладки ---
    function setTab(which) {
        var fileOn = which === 'file';
        paneFile.classList.toggle('hidden', !fileOn);
        paneTxt.classList.toggle('hidden', fileOn);
        tabFile.className = 'n2j-seg-item' + (fileOn ? ' is-active' : '');
        tabTxt.className = 'n2j-seg-item' + (!fileOn ? ' is-active' : '');
    }

    tabFile.addEventListener('click', function () { setTab('file'); });
    tabTxt.addEventListener('click', function () { setTab('txt'); });

    // --- операции с файлом ---
    opButtons.forEach(function (b) {
        b.addEventListener('click', function () {
            op = b.getAttribute('data-op');
            splitBox.classList.toggle('hidden', op !== 'split');
            mergeBox.classList.toggle('hidden', op !== 'merge');
            mergeFilesBox.classList.toggle('hidden', op !== 'mergefiles');
            opButtons.forEach(function (x) {
                var on = x === b;
                x.className = x.className.replace(/border-blue-500 text-blue-700 bg-blue-50[^\s]*/g, '')
                                      .replace(/border-gray-300 text-gray-700/g, '')
                    + (on ? ' border-blue-500 bg-blue-50 text-blue-700'
                          : ' border-gray-300 text-gray-700');
            });
        });
    });

    fileEl.addEventListener('change', function () {
        var f = fileEl.files && fileEl.files[0];
        if (f) {
            fileMeta.textContent = f.name + ' · ' + humanSize(f.size);
        }
    });

    filesEl.addEventListener('change', function () {
        var list = filesEl.files;
        var n = list ? list.length : 0;
        filesMeta.textContent = n ? ('Выбрано файлов: ' + n + ' · ' + humanSize(totalSize(list))) : 'До 12 файлов; страницы склеиваются в порядке выбора.';
    });

    function totalSize(list) {
        var s = 0;
        for (var i = 0; i < list.length; i++) s += list[i].size;
        return s;
    }

    function humanSize(n) {
        if (window.N2J && N2J.humanSize) return N2J.humanSize(n);
        if (n < 1024) return n + ' Б';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' КБ';
        return (n / 1048576).toFixed(1) + ' МБ';
    }

    // --- статус/ошибки ---
    function setBusy(busy, text) {
        if (busy) {
            statusRow.classList.remove('hidden');
            statusRow.classList.add('flex');
            statusTx.textContent = text || 'Выполняю…';
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

    function showError(msg) { errBox.textContent = msg || 'Не удалось выполнить операцию.'; errBox.classList.remove('hidden'); }
    function clearError() { errBox.classList.add('hidden'); errBox.textContent = ''; }

    function resetResult() {
        dlBtn.classList.add('hidden');
        copyBtn.classList.add('hidden');
        lastPdfBlob = null;
        lastText = '';
    }

    function showJson(j) {
        if (j.error) {
            showError(j.error);
            return;
        }
        showInfo(j);
    }

    function showInfo(j) {
        var rows = [];
        if (j.version) rows.push('Версия PDF:        ' + j.version);
        if (typeof j.pages !== 'undefined') rows.push('Страниц:           ' + j.pages);
        if (typeof j.size !== 'undefined') rows.push('Размер:            ' + humanSize(j.size));
        if (typeof j.encrypted !== 'undefined') rows.push('Шифрование:        ' + (j.encrypted ? 'да' : 'нет'));
        if (typeof j.obj_count !== 'undefined') rows.push('Объектов:          ' + j.obj_count);
        if (j.info) {
            Object.keys(j.info).forEach(function (k) { rows.push(k + ': ' + j.info[k]); });
        }
        if (j.text !== undefined) {
            if (j.text.trim() === '') {
                rows.push('(текстовый слой пуст — возможно, это скан)');
            } else {
                rows.push('Извлечено: ' + j.text.length + ' символов');
                resBox.textContent = j.text;
                lastText = j.text;
                copyBtn.classList.remove('hidden');
                return;
            }
        }
        resBox.textContent = rows.join('\n');
    }

    // --- выполнение операций с файлом ---
    function runFileOp() {
        var f, fd, api = op, srcName = 'document.pdf';

        if (op === 'mergefiles') {
            var list = filesEl.files;
            if (!list || list.length < 1) { showError('Выберите PDF-файлы для объединения.'); filesEl.focus(); return; }
            clearError();
            resetResult();
            setBusy(true, 'Объединяю файлы…');
            goBtn.disabled = true;
            fd = new FormData();
            for (var i = 0; i < list.length; i++) fd.append('files[]', list[i]);
            srcName = 'merged.pdf';
        } else {
            f = fileEl.files && fileEl.files[0];
            if (!f) { showError('Сначала выберите PDF-файл.'); fileEl.focus(); return; }
            clearError();
            resetResult();
            setBusy(true, 'Обрабатываю файл…');
            goBtn.disabled = true;
            fd = new FormData();
            fd.append('file', f);
            srcName = f.name;
            if (op === 'split') {
                fd.append('first', parseInt(firstEl.value, 10) || 1);
                fd.append('last', parseInt(lastEl.value, 10) || 1);
            }
            if (op === 'merge') {
                fd.append('pages', pagesEl.value.trim());
            }
        }

        fetch('/pdf/api/' + api, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
            cache: 'no-store'
        })
        .then(function (r) {
            var ct = r.headers.get('Content-Type') || '';
            if (ct.indexOf('application/pdf') !== -1) {
                // бинарный ответ → сохранить как файл
                return r.blob().then(function (b) {
                    return { pdf: true, blob: b, name: guessName(srcName) };
                });
            }
            return r.json().then(function (j) { return { pdf: false, json: j }; });
        })
        .then(function (res) {
            if (res.pdf) {
                lastPdfBlob = res.blob;
                lastPdfName = res.name;
                resBox.textContent = 'Готово: ' + res.name + ' (' + humanSize(res.blob.size) + '). Нажмите «Скачать PDF».';
                dlBtn.classList.remove('hidden');
                copyBtn.classList.remove('hidden');
                lastText = resBox.textContent;
            } else {
                var j = res.json || {};
                if (j.error) { showError(j.error); return; }
                showInfo(j);
            }
        })
        .catch(function () { showError('Сервер не ответил — попробуйте ещё раз.'); })
        .finally(function () {
            setBusy(false);
            goBtn.disabled = false;
        });
    }

    function guessName(src) {
        var base = src.replace(/\.pdf$/i, '');
        if (op === 'split') return base + '_' + (parseInt(firstEl.value, 10) || 1) + '-' + (parseInt(lastEl.value, 10) || 1) + '.pdf';
        if (op === 'merge') return base + '_merged.pdf';
        if (op === 'mergefiles') return 'merged.pdf';
        return src;
    }

    // --- Текст → PDF ---
    function runTxt2pdf() {
        var text = txtEl.value;
        if (!text || !text.trim()) { showError('Вставьте текст для PDF.'); txtEl.focus(); return; }
        clearError();
        resetResult();
        setBusy(true, 'Генерирую PDF…');
        genBtn.disabled = true;

        fetch('/pdf/api/txt2pdf', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ title: titleEl.value.trim(), text: text }),
            cache: 'no-store'
        })
        .then(function (r) {
            var ct = r.headers.get('Content-Type') || '';
            if (ct.indexOf('application/pdf') !== -1) {
                return r.blob().then(function (b) { return { pdf: true, blob: b, name: (titleEl.value.trim() || 'document') + '.pdf' }; });
            }
            return r.json().then(function (j) { return { pdf: false, json: j }; });
        })
        .then(function (res) {
            if (res.pdf) {
                lastPdfBlob = res.blob;
                lastPdfName = res.name;
                resBox.textContent = 'PDF готов: ' + res.name + ' (' + humanSize(res.blob.size) + '). Нажмите «Скачать PDF».';
                dlBtn.classList.remove('hidden');
                copyBtn.classList.remove('hidden');
                lastText = resBox.textContent;
            } else {
                showError((res.json && res.json.error) || 'Не удалось сгенерировать PDF.');
            }
        })
        .catch(function () { showError('Сервер не ответил — попробуйте ещё раз.'); })
        .finally(function () {
            setBusy(false);
            genBtn.disabled = false;
        });
    }

    // --- скачивание и копирование ---
    function doDownload() {
        if (!lastPdfBlob) return;
        if (window.N2J && N2J.downloadBlob) {
            N2J.downloadBlob(lastPdfBlob, lastPdfName);
            return;
        }
        var url = URL.createObjectURL(lastPdfBlob);
        var a = document.createElement('a');
        a.href = url;
        a.download = lastPdfName;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 500);
    }

    function doCopy() {
        var text = resBox.textContent;
        if (!text) return;

        function ok() {
            var old = copyBtn.innerHTML;
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Скопировано';
            setTimeout(function () { copyBtn.innerHTML = old; }, 1500);
        }

        if (window.N2J && N2J.copyText) {
            N2J.copyText(text).then(ok, ok);
            return;
        }

        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        ok();
    }

    goBtn.addEventListener('click', runFileOp);
    genBtn.addEventListener('click', runTxt2pdf);
    dlBtn.addEventListener('click', doDownload);
    copyBtn.addEventListener('click', doCopy);

    txtEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) runTxt2pdf();
    });

    setTab('file');
})();
</script>
