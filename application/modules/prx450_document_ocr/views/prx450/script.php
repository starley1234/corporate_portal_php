<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
(function () {
    'use strict';
    var form = document.getElementById('prx450-upload');
    if (!form) return;
    var button = form.querySelector('[type="submit"]');
    var input = document.getElementById('prx450-file');
    var drop = form.querySelector('[data-prx450-drop]');
    var selection = form.querySelector('[data-prx450-selection]');
    var info = document.getElementById('prx450-file-info');
    var error = document.getElementById('prx450-file-error');
    var clear = document.getElementById('prx450-file-clear');
    var formats = input ? input.accept.toLowerCase().split(',') : [];
    var maxBytes = Number(form.dataset.maxBytes);
    var size = new Intl.NumberFormat('ru', { maximumFractionDigits: 1 });

    function formatSize(bytes) {
        if (bytes < 1024) return size.format(bytes) + ' Б';
        if (bytes < 1048576) return size.format(bytes / 1024) + ' КиБ';
        return size.format(bytes / 1048576) + ' МиБ';
    }

    function fileError(message) {
        input.setCustomValidity(message);
        if (message) input.setAttribute('aria-invalid', 'true');
        else input.removeAttribute('aria-invalid');
        error.hidden = !message;
        error.textContent = message;
    }

    function showFile() {
        if (!input || !info || !selection || !error) return;
        var file = input.files[0];
        selection.classList.toggle('hidden', !file);
        info.textContent = file ? 'Выбран файл: ' + file.name + ' · ' + formatSize(file.size) : '';
        var message = '';
        if (file) {
            if (!file.size) message = 'Файл пуст. Выберите другой документ.';
            else if (maxBytes > 0 && file.size > maxBytes) message = 'Файл больше ' + formatSize(maxBytes) + '. Выберите документ меньшего размера.';
            else if (!formats.some(function (format) {
                return format.charAt(0) === '.' ? file.name.toLowerCase().endsWith(format) : format === file.type.toLowerCase();
            })) message = 'Этот формат не поддерживается. Выберите PDF или изображение из списка выше.';
        }
        fileError(message);
    }

    if (input && drop && selection && info && error && clear) {
        form.querySelector('[data-prx450-drag-hint]').hidden = false;
        input.addEventListener('change', showFile);
        clear.addEventListener('click', function () {
            if (form.dataset.busy === '1') return;
            input.value = '';
            showFile();
            input.focus();
        });
        drop.addEventListener('dragover', function (event) {
            if (!event.dataTransfer || !Array.from(event.dataTransfer.types).includes('Files')) return;
            event.preventDefault();
            var busy = form.dataset.busy === '1';
            event.dataTransfer.dropEffect = busy ? 'none' : 'copy';
            drop.dataset.dragging = busy ? 'false' : 'true';
        });
        drop.addEventListener('dragleave', function () { delete drop.dataset.dragging; });
        drop.addEventListener('drop', function (event) {
            event.preventDefault();
            delete drop.dataset.dragging;
            if (form.dataset.busy === '1') return;
            var files = event.dataTransfer && event.dataTransfer.files;
            if (!files || !files.length) return;
            if (files.length !== 1) {
                input.value = '';
                showFile();
                fileError('Можно загрузить только один документ за раз.');
                return;
            }
            try {
                input.files = files;
                showFile();
            } catch (exception) {
                input.value = '';
                showFile();
                fileError('Перетащить файл не удалось. Нажмите «Выбрать файл».');
            }
        });
        showFile();
    }

    form.addEventListener('submit', function (event) {
        if (event.defaultPrevented || form.dataset.busy === '1') { event.preventDefault(); return; }
        form.dataset.busy = '1';
        if (window.PRX) window.PRX.run(button, true);
        else button.disabled = true;
    });
    // Back/forward cache must not leave the restored form disabled.
    window.addEventListener('pageshow', function () {
        delete form.dataset.busy;
        if (window.PRX) window.PRX.run(button, false);
        else button.disabled = false;
        showFile();
    });
    var content = document.getElementById('prx450-content');
    var download = document.getElementById('prx450-download');
    if (download && content) download.addEventListener('click', function () {
        var url = URL.createObjectURL(new Blob([content.textContent], {type: 'text/markdown;charset=utf-8'}));
        var link = document.createElement('a');
        link.href = url; link.download = 'document.md';
        document.body.appendChild(link); link.click(); link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    });
    var print = document.getElementById('prx450-print');
    if (print) print.addEventListener('click', function () { window.print(); });
    var feedback = document.getElementById('prx450-feedback');
    if (feedback) { feedback.focus(); feedback.scrollIntoView({block: 'start', behavior: 'smooth'}); }
})();
</script>
