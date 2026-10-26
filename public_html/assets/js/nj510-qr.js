/* nj510_qr — интерфейс. Данные и файлы существуют только в этой вкладке. */
(function () {
    'use strict';
    var root = document.getElementById('qr-app');
    if (!root) return;
    var core = window.Nj510Qr;
    function el(id) { return document.getElementById('qr-' + id); }
    function show(node, visible) { node.classList.toggle('hidden', !visible); node.style.display = visible ? '' : 'none'; }
    function value(id) { return el(id).value; }
    function message(text, error) {
        var target = el(error ? 'error' : 'message');
        target.textContent = text;
        show(target, Boolean(text));
        if (text) show(el(error ? 'message' : 'error'), false);
    }
    if (!core || typeof window.jsQR !== 'function' || !window.fflate) {
        message('Не загрузились библиотеки генератора. Обновите страницу или проверьте блокировщик скриптов.', true);
        return;
    }
    var settings = JSON.parse(el('settings').textContent);
    var state = { mode: root.dataset.mode, items: [], index: 0, rendered: null, readable: false,
        logo: '', logoPending: false, logoRevision: 0, manualLevel: 'M', revision: 0,
        importRevision: 0, importPending: false,
        previewUrl: '', running: false, cancelled: false, timer: null, disabled: [] };
    var STYLE_KEY = 'nj510.qr.style.v1';
    // Вид активной кнопки режима задаёт n2j-seg-item[aria-pressed="true"].
    var activeClass = [];
    var inactiveClass = [];

    function styleInput() {
        return { foreground: value('foreground-hex').trim(), background: value('background-hex').trim(),
            accent: value('accent-hex').trim(), gradient: el('gradient').checked,
            shape: value('shape'), eye: value('eye'), size: Number(value('size')),
            margin: Number(value('margin')), level: value('level'), format: value('format'),
            logo_size: Number(value('logo-size')), logo: state.logo };
    }

    function applyStyle(style) {
        style = core.options(Object.assign({}, settings.defaults, style, { logo: '' }), settings);
        ['foreground', 'background', 'accent'].forEach(function (key) {
            el(key).value = style[key]; el(key + '-hex').value = style[key];
        });
        ['shape', 'eye', 'size', 'margin', 'format', 'logo_size'].forEach(function (key) {
            el(key.replace('_', '-')).value = String(style[key]);
        });
        state.manualLevel = style.level;
        el('level').value = state.logo ? 'H' : style.level;
        el('gradient').checked = style.gradient;
        el('logo-size-value').textContent = style.logo_size + '%';
    }

    function updatePresets() {
        root.querySelectorAll('[data-preset]').forEach(function (button) {
            var preset = settings.presets[button.dataset.preset];
            var active = value('foreground-hex').toLowerCase() === preset.foreground && value('background-hex').toLowerCase() === '#ffffff'
                && value('shape') === preset.shape && value('eye') === preset.eye && el('gradient').checked === preset.gradient
                && (!preset.gradient || value('accent-hex').toLowerCase() === preset.accent);
            button.setAttribute('aria-pressed', String(active));
            button.classList.toggle('ring-2', active); button.classList.toggle('ring-blue-400', active);
        });
    }

    function syncType() {
        root.querySelectorAll('[data-qr-fields]').forEach(function (node) {
            show(node, node.dataset.qrFields === value('type'));
        });
        var open = value('wifi-security') === 'nopass';
        el('wifi-password').disabled = open;
        el('wifi-show').disabled = open;
    }

    function syncMode() {
        show(el('single-panel'), state.mode === 'single');
        show(el('batch-panel'), state.mode === 'batch');
        show(el('verify-wrap'), state.mode === 'batch');
        el('verify-wrap').classList.toggle('flex', state.mode === 'batch');
        el('export-note').textContent = state.mode === 'batch'
            ? 'ZIP с manifest.csv или печать всех кодов на листах A4. Перед печатью проверьте результат камерой.'
            : 'Без водяных знаков. Ссылка открывается напрямую.';
        root.querySelectorAll('[data-mode-button]').forEach(function (button) {
            var active = button.dataset.modeButton === state.mode;
            button.setAttribute('aria-pressed', String(active));
            activeClass.forEach(function (name) { button.classList.toggle(name, active); });
            inactiveClass.forEach(function (name) { button.classList.toggle(name, !active); });
        });
        show(el('delimiter-wrap'), value('batch-mode') === 'csv');
        el('batch-hint').textContent = value('batch-mode') === 'csv'
            ? 'Одна или две колонки: имя;данные. Заголовок необязателен. Кавычки и переносы внутри ячеек поддерживаются.'
            : 'Каждая непустая строка станет отдельным кодом. Данные кодируются как есть, без сокращения ссылок.';
    }

    function fields() {
        switch (value('type')) {
            case 'url': return { url: value('url') };
            case 'text': return { text: value('text') };
            case 'wifi': return { ssid: value('wifi-ssid'), password: value('wifi-password'),
                security: value('wifi-security'), hidden: el('wifi-hidden').checked };
            case 'vcard': return { first: value('card-first'), last: value('card-last'), phone: value('card-phone'),
                email: value('card-email'), organization: value('card-organization'),
                position: value('card-position'), url: value('card-url') };
            case 'email': return { email: value('email-address'), subject: value('email-subject'), message: value('email-body') };
            case 'phone': return { phone: value('phone') };
            case 'sms': return { phone: value('sms-phone'), message: value('sms-body') };
            default: return {};
        }
    }

    function updateActions() {
        var canExport = Boolean(state.rendered) && !state.running && !state.logoPending && !state.importPending;
        if (state.mode === 'batch') canExport = canExport && state.items.length > 0 && (!el('verify').checked || state.readable);
        else canExport = canExport && state.readable;
        el('download').disabled = !canExport;
        el('download-label').textContent = state.mode === 'batch'
            ? 'Скачать ZIP' + (state.items.length ? ' (' + state.items.length + ')' : '')
            : 'Скачать ' + value('format').toUpperCase();
        var canPrint = state.mode === 'batch' && state.items.length > 0 && !state.running
            && !state.logoPending && !state.importPending && (!el('verify').checked || state.readable);
        show(el('batch-print'), state.mode === 'batch');
        el('batch-print').disabled = !canPrint;
        var clipboardAvailable = Boolean(navigator.clipboard && navigator.clipboard.write && window.ClipboardItem);
        show(el('copy-image'), state.mode === 'single');
        el('copy-image').disabled = !canExport || !clipboardAvailable;
        el('copy-image').title = clipboardAvailable ? 'PNG в буфер обмена' : 'Копирование картинки не поддерживается этим браузером — скачайте PNG.';
        el('prev').disabled = state.running || state.index === 0;
        el('next').disabled = state.running || state.index >= state.items.length - 1;
        var pager = state.mode === 'batch' && state.items.length > 1;
        show(el('pager'), pager); el('pager').classList.toggle('flex', pager);
        el('page').textContent = (state.index + 1) + ' / ' + state.items.length;
    }

    function clearPreview() {
        state.rendered = null; state.readable = false;
        if (state.previewUrl) { URL.revokeObjectURL(state.previewUrl); state.previewUrl = ''; }
        el('preview').removeAttribute('src'); show(el('preview'), false); show(el('placeholder'), true);
        el('meta').textContent = 'Предпросмотр появится после заполнения данных.';
        el('scan-status').textContent = 'Готовы создать ваш код.';
        show(el('warning'), false); updateActions();
    }

    function updateSummary() {
        el('batch-count').textContent = 'Кодов: ' + state.items.length + ' / ' + settings.limits.batch;
        var tbody = el('batch-rows'); tbody.replaceChildren();
        state.items.slice(0, 4).forEach(function (item, index) {
            var row = document.createElement('tr');
            var file = document.createElement('td'), data = document.createElement('td');
            file.className = 'break-all px-3 py-2 font-medium text-gray-700 dark:text-gray-200';
            data.className = 'max-w-xs break-all px-3 py-2 text-gray-500 dark:text-gray-400';
            file.textContent = core.filename(item.label, index + 1, value('format'));
            data.textContent = item.data.length > 90 ? item.data.slice(0, 90) + '…' : item.data;
            row.append(file, data); tbody.appendChild(row);
        });
        if (state.items.length > 4) {
            var more = document.createElement('tr'), cell = document.createElement('td');
            cell.colSpan = 2; cell.className = 'px-3 py-2 text-gray-400 dark:text-gray-500';
            cell.textContent = 'И ещё ' + (state.items.length - 4) + ' файлов. Предпросмотр — стрелками ниже.';
            more.appendChild(cell); tbody.appendChild(more);
        }
        show(el('batch-summary'), state.items.length > 0);
    }

    function loadImage(source) {
        return new Promise(function (resolve, reject) {
            var image = new Image();
            image.onload = function () { resolve(image); };
            image.onerror = function () { reject(new Error('Не удалось прочитать изображение. Выберите другой файл.')); };
            image.src = source;
        });
    }

    async function canvasFromSvg(rendered, size) {
        var source = URL.createObjectURL(new Blob([rendered.svg], { type: 'image/svg+xml' }));
        try {
            var image = await loadImage(source);
            var canvas = document.createElement('canvas'); canvas.width = canvas.height = size;
            var context = canvas.getContext('2d');
            if (!context) throw new Error('Браузер не поддерживает создание картинки. Попробуйте другой браузер.');
            context.fillStyle = rendered.style.background; context.fillRect(0, 0, size, size);
            context.drawImage(image, 0, 0, size, size);
            return canvas;
        } finally { URL.revokeObjectURL(source); }
    }

    async function readable(rendered) {
        var canvas = await canvasFromSvg(rendered, Math.min(rendered.style.size, 768));
        try {
            var pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
            var decoded = window.jsQR(pixels.data, canvas.width, canvas.height, { inversionAttempts: 'dontInvert' });
            return Boolean(decoded && decoded.data === rendered.data);
        } finally { canvas.width = canvas.height = 1; }
    }

    async function imageBlob(rendered, format) {
        if (format === 'svg') return new Blob([rendered.svg], { type: 'image/svg+xml;charset=utf-8' });
        var canvas = await canvasFromSvg(rendered, rendered.style.size);
        try {
            return await new Promise(function (resolve, reject) {
                canvas.toBlob(function (blob) {
                    if (blob) resolve(blob); else reject(new Error('Не удалось сохранить картинку. Попробуйте SVG.'));
                }, format === 'jpeg' ? 'image/jpeg' : 'image/png', 0.96);
            });
        } finally { canvas.width = canvas.height = 1; }
    }

    async function refresh(revision) {
        if (state.running || revision !== state.revision) return;
        syncMode(); syncType(); updatePresets(); show(el('error'), false); show(el('message'), false);
        show(el('input-error'), false); show(el('style-error'), false);
        var phase = 'input';
        try {
            var data;
            if (state.mode === 'batch') {
                state.items = core.parseBatch(value('batch-text'), value('batch-mode'),
                    value('delimiter') === 'tab' ? '\t' : value('delimiter'), settings.limits);
                if (state.index >= state.items.length) state.index = 0;
                updateSummary();
                if (!state.items.length) { clearPreview(); return; }
                data = state.items[state.index].data;
            } else {
                state.items = [];
                data = core.payload(value('type'), fields(), settings.limits);
            }
            phase = 'style';
            var rendered = core.render(data, styleInput(), settings);
            state.rendered = rendered;
            if (state.previewUrl) URL.revokeObjectURL(state.previewUrl);
            state.previewUrl = URL.createObjectURL(new Blob([rendered.svg], { type: 'image/svg+xml' }));
            el('preview').src = state.previewUrl; show(el('preview'), true); show(el('placeholder'), false);
            el('preview-label').textContent = state.mode === 'batch'
                ? core.filename(state.items[state.index].label, state.index + 1, rendered.style.format) : 'Живой предпросмотр';
            el('meta').textContent = rendered.style.size + ' × ' + rendered.style.size + ' пикс. · ' + rendered.bytes
                + ' байт · защита ' + rendered.style.level;
            var warnings = [];
            if (rendered.style.logo) warnings.push('С логотипом обязательно проверьте код камерой перед печатью.');
            if (rendered.style.contrast < 4.5) warnings.push('Контраст невысокий — лучше затемнить цвет кода.');
            if (rendered.style.size / rendered.total < 4) warnings.push('Много данных для этого размера. Выберите картинку побольше.');
            el('warning').textContent = warnings.join(' '); show(el('warning'), warnings.length > 0);
            el('scan-status').textContent = 'Проверяем читаемость в браузере…';
            updateActions();
            var good = await readable(rendered);
            if (revision !== state.revision || state.running) return;
            state.readable = good;
            el('scan-status').textContent = good ? '✓ Код прочитан: содержимое совпадает.'
                : 'Не удалось прочитать код. Уменьшите логотип, увеличьте размер или верните классический стиль.';
            el('scan-status').className = good ? 'text-sm font-medium text-emerald-700 dark:text-emerald-400'
                : 'text-sm font-medium text-amber-700 dark:text-amber-300';
            updateActions();
        } catch (error) {
            if (revision !== state.revision) return;
            if (state.mode === 'batch') { state.items = []; state.index = 0; updateSummary(); }
            clearPreview(); message(error.message, true);
            var inlineError = el(phase === 'input' ? 'input-error' : 'style-error');
            inlineError.textContent = error.message; show(inlineError, true);
        }
    }

    function schedule() {
        if (state.running) return;
        clearTimeout(state.timer); show(el('progress-wrap'), false);
        state.revision++; state.rendered = null; state.readable = false; updateActions();
        el('scan-status').textContent = 'Обновляем код…';
        var revision = state.revision;
        state.timer = setTimeout(function () { refresh(revision); }, 160);
    }

    function download(blob, name) {
        var source = URL.createObjectURL(blob), link = document.createElement('a');
        link.href = source; link.download = name; document.body.appendChild(link); link.click(); link.remove();
        // Не отзываем URL до того, как браузер успел начать скачивание (Safari).
        setTimeout(function () { URL.revokeObjectURL(source); }, 30000);
    }

    function setBusy(busy) {
        state.running = busy;
        if (busy) {
            state.disabled = [];
            root.querySelectorAll('input, select, textarea, button').forEach(function (node) {
                if (node.id === 'qr-cancel') return;
                state.disabled.push([node, node.disabled]); node.disabled = true;
            });
        } else {
            state.disabled.forEach(function (entry) { entry[0].disabled = entry[1]; }); state.disabled = [];
        }
        root.setAttribute('aria-busy', String(busy)); show(el('cancel'), busy); updateActions();
    }

    function checkCancelled() {
        if (state.cancelled) { var error = new Error('Генерация отменена. Архив не скачан.'); error.cancelled = true; throw error; }
    }

    async function batchDownload() {
        var items = state.items.map(function (item) { return Object.assign({}, item); });
        var style = core.options(styleInput(), settings), verify = el('verify').checked;
        if (!items.length) return;
        state.cancelled = false; setBusy(true); show(el('progress-wrap'), true);
        el('progress-text').textContent = 'Подготавливаем пакет…';
        show(el('error'), false); show(el('message'), false);
        var chunks = [], archiveSize = 0, archiveError = null, zip;
        var finish;
        var finished = new Promise(function (resolve) { finish = resolve; });
        zip = new window.fflate.Zip(function (error, chunk, final) {
            if (error) { archiveError = error; finish(); return; }
            archiveSize += chunk.length;
            if (archiveSize > settings.limits.archive_bytes) {
                archiveError = new Error('Архив превышает 64 МБ. Разделите список или уменьшите размер картинок.');
                finish(); return;
            }
            chunks.push(chunk);
            if (final) finish();
        });
        function addFile(name, buffer, compress) {
            var entry = compress ? new window.fflate.ZipDeflate(name, { level: 6 }) : new window.fflate.ZipPassThrough(name);
            zip.add(entry); entry.push(buffer, true);
            if (archiveError) throw archiveError;
        }
        try {
            for (var i = 0; i < items.length; i++) {
                checkCancelled();
                var item = items[i], rendered;
                el('progress-text').textContent = 'Код ' + (i + 1) + ' из ' + items.length
                    + ' (' + Math.round(100 * (i + 1) / items.length) + '%): ' + item.label;
                try {
                    rendered = core.render(item.data, style, settings);
                    if (verify && !await readable(rendered)) {
                        throw new Error('Проверка читаемости не пройдена. Уменьшите логотип, увеличьте размер или измените стиль.');
                    }
                    checkCancelled();
                    var blob = await imageBlob(rendered, style.format);
                    checkCancelled();
                    addFile(core.filename(item.label, i + 1, style.format), new Uint8Array(await blob.arrayBuffer()), style.format === 'svg');
                } catch (error) {
                    if (error.cancelled) throw error;
                    throw new Error('Строка ' + item.line + ' («' + item.label + '»): ' + error.message + ' Архив не скачан.');
                }
                // Даём браузеру перерисоваться и обработать кнопку «Отменить».
                await new Promise(function (resolve) { setTimeout(resolve, 0); });
            }
            checkCancelled();
            addFile('manifest.csv', core.bytes(core.manifest(items, style.format)), true);
            zip.end(); await finished;
            if (archiveError) throw archiveError;
            checkCancelled();
            download(new Blob(chunks, { type: 'application/zip' }), 'qr-codes-' + items.length + '.zip');
            el('progress-text').textContent = 'Готово: ' + items.length + ' кодов.';
            message('ZIP-архив готов: ' + items.length + ' кодов и manifest.csv.');
        } catch (error) {
            zip.terminate(); chunks = [];
            el('progress-text').textContent = error.cancelled ? 'Отменено.' : 'Остановлено: исправьте данные или оформление.';
            message(error.message, !error.cancelled);
        } finally { setBusy(false); }
    }

    function secureRandomIndex(max) {
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            throw new Error('Браузер не предоставляет криптостойкий генератор случайных значений. Откройте сайт по HTTPS.');
        }
        var word = new Uint32Array(1), range = 0x100000000;
        var limit = Math.floor(range / max) * max;
        do { window.crypto.getRandomValues(word); } while (word[0] >= limit);
        return word[0] % max;
    }

    function generateIdBatch() {
        if (state.running) return;
        try {
            var ids = core.generateIds(value('id-mask'), value('id-count'), secureRandomIndex, settings.limits);
            // Маска допускает только литералы без CSV-разделителей и переносов.
            el('batch-text').value = 'имя;данные\n' + ids.map(function (id) { return id + ';' + id; }).join('\n');
            el('batch-mode').value = 'csv'; el('delimiter').value = ';';
            state.importRevision++; state.importPending = false; state.index = 0;
            schedule(); message('Создано ' + ids.length + ' уникальных ID. Список можно проверить и отредактировать ниже.');
        } catch (error) { message(error.message, true); }
    }

    function printableDocument(printWindow, items, imageUrls) {
        var doc = printWindow.document;
        doc.open();
        doc.write('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Пакет QR-кодов · N2J</title></head><body></body></html>');
        doc.close();
        var style = doc.createElement('style');
        style.textContent = '@page{size:A4 portrait;margin:8mm}*{box-sizing:border-box}html,body{margin:0;color:#111827;font-family:Arial,sans-serif}.qr-print-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px;background:#f3f4f6}.qr-print-toolbar h1{margin:0;font-size:18px}.qr-print-toolbar p{margin:5px 0 0;color:#4b5563;font-size:12px}.qr-print-button{min-height:44px;padding:10px 16px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-size:14px;font-weight:700;cursor:pointer}.qr-print-page{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));grid-template-rows:repeat(4,minmax(0,1fr));gap:3mm;width:100%;height:281mm;margin:12px auto;padding:0;background:#fff}.qr-print-card{display:flex;min-width:0;flex-direction:column;align-items:center;justify-content:center;overflow:hidden;border:.2mm solid #d1d5db;padding:1mm;break-inside:avoid}.qr-print-card img{display:block;width:48mm;height:48mm;object-fit:contain}.qr-print-number{margin-top:1mm;color:#6b7280;font-size:7pt}.qr-print-caption{max-width:100%;max-height:10mm;overflow:hidden;overflow-wrap:anywhere;text-align:center;font-size:8pt;line-height:1.2}@media screen{body{background:#e5e7eb}.qr-print-page{max-width:210mm;box-shadow:0 2px 12px #0002}}@media print{.qr-print-toolbar{display:none!important}.qr-print-page{margin:0;box-shadow:none;break-after:page;page-break-after:always}.qr-print-page:last-child{break-after:auto;page-break-after:auto}}';
        doc.head.appendChild(style);

        var toolbar = doc.createElement('header'); toolbar.className = 'qr-print-toolbar';
        var intro = doc.createElement('div'), heading = doc.createElement('h1'), note = doc.createElement('p');
        heading.textContent = 'Пакет QR-кодов · ' + items.length + ' шт.';
        note.textContent = 'A4, книжная ориентация · до 12 кодов на листе · проверьте масштаб в диалоге печати.';
        intro.append(heading, note);
        var button = doc.createElement('button'); button.type = 'button'; button.className = 'qr-print-button';
        button.textContent = 'Печать / сохранить PDF';
        button.addEventListener('click', function () { printWindow.focus(); printWindow.print(); });
        toolbar.append(intro, button); doc.body.appendChild(toolbar);

        var pageCount = Math.ceil(items.length / 12);
        for (var pageIndex = 0; pageIndex < pageCount; pageIndex++) {
            var page = doc.createElement('section'); page.className = 'qr-print-page';
            page.setAttribute('aria-label', 'Лист ' + (pageIndex + 1));
            for (var offset = pageIndex * 12; offset < Math.min(items.length, (pageIndex + 1) * 12); offset++) {
                var item = items[offset], card = doc.createElement('article'), image = doc.createElement('img');
                var number = doc.createElement('span'), caption = doc.createElement('div');
                card.className = 'qr-print-card'; image.src = imageUrls[offset]; image.alt = 'QR-код ' + (offset + 1);
                number.className = 'qr-print-number'; number.textContent = String(offset + 1).padStart(3, '0');
                caption.className = 'qr-print-caption';
                caption.textContent = item.label && item.label !== 'qr' ? item.label : item.data;
                card.append(image, number, caption); page.appendChild(card);
            }
            doc.body.appendChild(page);
        }
        return Promise.all(Array.from(doc.images).map(function (image) { return image.decode(); }));
    }

    async function batchPrint() {
        if (el('batch-print').disabled || state.running) return;
        var printWindow = window.open('about:blank', '_blank');
        if (!printWindow) { message('Разрешите всплывающие окна, чтобы открыть лист для печати.', true); return; }
        var items = state.items.map(function (item) { return Object.assign({}, item); });
        var style = core.options(styleInput(), settings), verify = el('verify').checked;
        var imageUrls = [], released = false;
        function releaseUrls() {
            if (released) return;
            released = true; imageUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        }
        printWindow.addEventListener('beforeunload', releaseUrls, { once: true });
        printWindow.addEventListener('unload', releaseUrls, { once: true });
        if (!items.length) { printWindow.close(); releaseUrls(); return; }
        state.cancelled = false; setBusy(true); show(el('progress-wrap'), true);
        el('progress-text').textContent = 'Подготавливаем листы A4…';
        show(el('error'), false); show(el('message'), false);
        try {
            for (var i = 0; i < items.length; i++) {
                checkCancelled();
                if (printWindow.closed) throw new Error('Окно печати закрыто. Пакет не сформирован.');
                var item = items[i], rendered;
                el('progress-text').textContent = 'Код ' + (i + 1) + ' из ' + items.length
                    + ' (' + Math.round(100 * (i + 1) / items.length) + '%): ' + item.label;
                try {
                    rendered = core.render(item.data, style, settings);
                    if (verify && !await readable(rendered)) {
                        throw new Error('Проверка читаемости не пройдена. Уменьшите логотип, увеличьте размер или измените стиль.');
                    }
                    checkCancelled();
                    imageUrls.push(URL.createObjectURL(new Blob([rendered.svg], { type: 'image/svg+xml' })));
                } catch (error) {
                    if (error.cancelled) throw error;
                    throw new Error('Строка ' + item.line + ' («' + item.label + '»): ' + error.message + ' Листы не сформированы.');
                }
                await new Promise(function (resolve) { setTimeout(resolve, 0); });
            }
            checkCancelled();
            if (printWindow.closed) throw new Error('Окно печати закрыто. Пакет не сформирован.');
            await printableDocument(printWindow, items, imageUrls);
            el('progress-text').textContent = 'Готово: ' + items.length + ' кодов на ' + Math.ceil(items.length / 12) + ' листах A4.';
            message('Листы открыты в новой вкладке. Нажмите «Печать / сохранить PDF».');
        } catch (error) {
            if (!printWindow.closed) printWindow.close();
            releaseUrls();
            el('progress-text').textContent = error.cancelled ? 'Отменено.' : 'Остановлено: исправьте данные или оформление.';
            message(error.message, !error.cancelled);
        } finally { setBusy(false); }
    }

    async function importFile(file) {
        if (!file || state.running) return;
        var revision = ++state.importRevision;
        state.importPending = true; updateActions();
        try {
            if (!/\.(txt|csv|tsv)$/i.test(file.name)) throw new Error('Для списка нужен файл TXT, CSV или TSV в UTF-8.');
            if (file.size > settings.limits.import_bytes) throw new Error('Список слишком большой: максимум 1 МБ.');
            var content;
            try { content = new TextDecoder('utf-8', { fatal: true }).decode(await file.arrayBuffer()); }
            catch (_) { throw new Error('Не удалось прочитать UTF-8. Пересохраните файл в UTF-8 и загрузите снова.'); }
            if (revision !== state.importRevision || state.running || state.mode !== 'batch') return;
            el('batch-text').value = content;
            el('batch-mode').value = /\.(csv|tsv)$/i.test(file.name) ? 'csv' : 'lines';
            el('delimiter').value = /\.tsv$/i.test(file.name) ? 'tab' : 'auto';
            state.index = 0; schedule();
        } catch (error) { if (revision === state.importRevision) message(error.message, true); }
        finally {
            if (revision === state.importRevision) { state.importPending = false; el('import').value = ''; updateActions(); }
        }
    }

    function setLogo(source, name) {
        var hadLogo = Boolean(state.logo);
        state.logo = source;
        if (source && !hadLogo) state.manualLevel = value('level');
        el('level').value = source ? 'H' : state.manualLevel;
        el('level').disabled = Boolean(source);
        if (source) el('logo-thumb').src = source; else el('logo-thumb').removeAttribute('src');
        show(el('logo-thumb'), Boolean(source)); show(el('logo-icon'), !source);
        show(el('logo-remove'), Boolean(source)); show(el('logo-settings'), Boolean(source));
        el('logo-name').textContent = name || 'PNG, JPEG или WebP · до 2 МБ';
        schedule();
    }

    async function logoFile(file) {
        if (!file || state.running) return;
        var revision = ++state.logoRevision;
        state.logoPending = true; updateActions();
        var source = '';
        try {
            if (file.size > settings.limits.logo_bytes) throw new Error('Логотип слишком большой: максимум 2 МБ.');
            var header = new Uint8Array(await file.slice(0, 12).arrayBuffer());
            var png = header[0] === 137 && header[1] === 80 && header[2] === 78 && header[3] === 71;
            var jpeg = header[0] === 255 && header[1] === 216 && header[2] === 255;
            var webp = String.fromCharCode.apply(null, header.slice(0, 4)) === 'RIFF'
                && String.fromCharCode.apply(null, header.slice(8, 12)) === 'WEBP';
            if (!png && !jpeg && !webp) throw new Error('Для логотипа нужен PNG, JPEG или WebP. SVG и другие форматы не поддерживаются.');
            source = URL.createObjectURL(file);
            var image = await loadImage(source);
            if (image.naturalWidth > settings.limits.logo_side || image.naturalHeight > settings.limits.logo_side) {
                throw new Error('Сторона логотипа не должна превышать ' + settings.limits.logo_side + ' пикселей. Уменьшите изображение.');
            }
            var scale = Math.min(1, 256 / Math.max(image.naturalWidth, image.naturalHeight));
            var canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
            canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
            canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height);
            var normalized = canvas.toDataURL('image/png'); canvas.width = canvas.height = 1;
            if (revision === state.logoRevision) setLogo(normalized, file.name);
        } catch (error) { if (revision === state.logoRevision) message(error.message, true); }
        finally {
            if (source) URL.revokeObjectURL(source);
            if (revision === state.logoRevision) { state.logoPending = false; el('logo-file').value = ''; updateActions(); }
        }
    }

    root.addEventListener('input', function (event) {
        if (state.running || !event.target.matches('input:not([type=file]), textarea, select')) return;
        var id = event.target.id;
        ['foreground', 'background', 'accent'].forEach(function (key) {
            if (id === 'qr-' + key) el(key + '-hex').value = value(key);
            if (id === 'qr-' + key + '-hex' && /^#[\da-f]{6}$/i.test(value(key + '-hex'))) el(key).value = value(key + '-hex');
        });
        if (id === 'qr-batch-text') { state.index = 0; state.importRevision++; state.importPending = false; }
        if (id === 'qr-level' && !state.logo) state.manualLevel = value('level');
        if (id === 'qr-wifi-show') el('wifi-password').type = el('wifi-show').checked ? 'text' : 'password';
        el('logo-size-value').textContent = value('logo-size') + '%';
        schedule();
    });
    // Некоторые браузеры отправляют для select только change.
    root.addEventListener('change', function (event) {
        if (event.target.matches('select')) {
            if (event.target.id === 'qr-level' && !state.logo) state.manualLevel = value('level');
            schedule();
        }
    });
    root.querySelectorAll('[data-mode-button]').forEach(function (button) {
        button.addEventListener('click', function () { state.mode = button.dataset.modeButton; state.index = 0; schedule(); });
    });
    root.querySelectorAll('[data-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
            var preset = settings.presets[button.dataset.preset];
            applyStyle(Object.assign({}, styleInput(), preset, { background: '#ffffff', level: state.manualLevel }));
            root.querySelectorAll('[data-preset]').forEach(function (other) {
                other.setAttribute('aria-pressed', String(other === button));
            });
            schedule();
        });
    });
    el('prev').addEventListener('click', function () { if (state.index > 0) { state.index--; schedule(); } });
    el('next').addEventListener('click', function () { if (state.index < state.items.length - 1) { state.index++; schedule(); } });
    el('batch-example').addEventListener('click', function () {
        state.importRevision++; state.importPending = false;
        el('batch-mode').value = 'csv'; el('delimiter').value = ';';
        el('batch-text').value = 'имя;данные\nМеню;https://example.com/menu\nКаталог;https://example.com/catalog\nКонтакты;https://example.com/contact';
        state.index = 0; schedule();
    });
    el('batch-clear').addEventListener('click', function () { el('batch-text').value = ''; state.index = 0; state.importRevision++; state.importPending = false; schedule(); });
    el('id-generate').addEventListener('click', generateIdBatch);
    el('batch-print').addEventListener('click', batchPrint);
    el('import').addEventListener('change', function () { importFile(this.files[0]); });
    el('drop-zone').addEventListener('dragover', function (event) { event.preventDefault(); });
    el('drop-zone').addEventListener('drop', function (event) {
        event.preventDefault(); if (!state.running) importFile(event.dataTransfer.files[0]);
    });
    el('logo-file').addEventListener('change', function () { logoFile(this.files[0]); });
    el('logo-remove').addEventListener('click', function () {
        state.logoRevision++; state.logoPending = false; setLogo('', '');
    });
    el('logo-demo').addEventListener('click', function () {
        state.logoRevision++; state.logoPending = false;
        var canvas = document.createElement('canvas'); canvas.width = canvas.height = 128;
        var context = canvas.getContext('2d'); context.fillStyle = '#2563eb'; context.fillRect(0, 0, 128, 128);
        context.fillStyle = '#ffffff'; context.font = 'bold 80px sans-serif'; context.textAlign = 'center';
        context.textBaseline = 'middle'; context.fillText('N', 64, 68);
        setLogo(canvas.toDataURL('image/png'), 'Демонстрационный логотип');
    });
    el('style-save').addEventListener('click', function () {
        try {
            var clean = core.options(Object.assign({}, styleInput(), { logo: '', level: state.manualLevel }), settings);
            var saved = {};
            Object.keys(settings.defaults).forEach(function (key) { saved[key] = clean[key]; });
            localStorage.setItem(STYLE_KEY, JSON.stringify(saved));
            message('Стиль сохранён в этом браузере. Содержимое и логотип не сохранялись.');
        } catch (error) { message('Не удалось сохранить стиль: ' + error.message, true); }
    });
    el('style-reset').addEventListener('click', function () {
        state.logoRevision++; state.logoPending = false; setLogo('', ''); applyStyle(settings.defaults);
        try { localStorage.removeItem(STYLE_KEY); } catch (_) { /* Закрытое хранилище не мешает генерации. */ }
        schedule();
    });
    el('cancel').addEventListener('click', function () { state.cancelled = true; el('progress-text').textContent = 'Останавливаем генерацию…'; });
    el('download').addEventListener('click', async function () {
        if (this.disabled || !state.rendered) return;
        if (state.mode === 'batch') { await batchDownload(); return; }
        var rendered = state.rendered, revision = state.revision;
        this.disabled = true;
        try {
            var blob = await imageBlob(rendered, rendered.style.format);
            if (revision !== state.revision) return;
            download(blob, 'qr-code.' + (rendered.style.format === 'jpeg' ? 'jpg' : rendered.style.format));
            message('Картинка готова. Перед печатью проверьте её камерой телефона.');
        } catch (error) { message(error.message, true); }
        finally { updateActions(); }
    });
    el('copy-image').addEventListener('click', async function () {
        if (this.disabled || !state.rendered) return;
        try {
            // write вызывается внутри жеста пользователя, а PNG вычисляется обещанием.
            await navigator.clipboard.write([new window.ClipboardItem({ 'image/png': imageBlob(state.rendered, 'png') })]);
            message('PNG-картинка скопирована в буфер обмена.');
        } catch (_) { message('Браузер не разрешил копирование картинки. Скачайте PNG кнопкой выше.', true); }
    });
    window.addEventListener('beforeunload', function (event) {
        if (state.running) { event.preventDefault(); event.returnValue = ''; }
    });

    try {
        var stored = localStorage.getItem(STYLE_KEY);
        if (stored) applyStyle(JSON.parse(stored)); else applyStyle(settings.defaults);
    } catch (_) { applyStyle(settings.defaults); }
    el('type').value = root.dataset.type;
    syncMode(); syncType(); schedule();
})();
