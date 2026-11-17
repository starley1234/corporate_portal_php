import * as pdfjsLib from '/assets/vendor/pdfjs/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.mjs';

const app = document.getElementById('vlm-app');

if (app) {
    const fileInput = document.getElementById('vlm-file');
    const dropzone = document.getElementById('vlm-dropzone');
    const pagesSelect = document.getElementById('vlm-pages');
    const preview = document.getElementById('vlm-preview');
    const runButton = document.getElementById('vlm-run');
    const status = document.getElementById('vlm-status');
    const csrf = document.getElementById('vlm-csrf');
    const empty = document.getElementById('vlm-empty');
    const loading = document.getElementById('vlm-loading');
    const errorBox = document.getElementById('vlm-error');
    const errorText = document.getElementById('vlm-error-text');
    const resultBox = document.getElementById('vlm-result');
    const downloadButton = document.getElementById('vlm-download');
    const fieldLabels = readJson('vlm-field-labels');
    const typeLabels = readJson('vlm-types');
    const analyzeUrl = app.dataset.analyzeUrl || '/vlm-demo/analyze';
    const canRun = app.dataset.canRun === '1';
    const maxPages = Math.max(1, parseInt(app.dataset.maxPages || '3', 10));

    const state = {
        file: null,
        pdf: null,
        blobs: [],
        previewUrls: [],
        lastResponse: null,
        busy: false,
    };

    function readJson(id) {
        const node = document.getElementById(id);
        if (!node) {
            return {};
        }
        try {
            return JSON.parse(node.textContent || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function setStatus(text) {
        status.textContent = text;
    }

    function showError(message) {
        errorText.textContent = message;
        errorBox.classList.remove('hidden');
    }

    function hideError() {
        errorBox.classList.add('hidden');
        errorText.textContent = '';
    }

    function setBusy(busy) {
        state.busy = busy;
        runButton.disabled = busy || !canRun || state.blobs.length === 0;
        loading.classList.toggle('hidden', !busy);
        if (busy) {
            empty.classList.add('hidden');
            resultBox.classList.add('hidden');
            hideError();
        }
    }

    function clearPreview() {
        state.previewUrls.forEach((url) => URL.revokeObjectURL(url));
        state.previewUrls = [];
        state.blobs = [];
        preview.innerHTML = '';
    }

    function resetResult() {
        state.lastResponse = null;
        resultBox.innerHTML = '';
        resultBox.classList.add('hidden');
        empty.classList.remove('hidden');
        downloadButton.classList.add('hidden');
        hideError();
    }

    function addPreview(blob, label) {
        const url = URL.createObjectURL(blob);
        state.previewUrls.push(url);

        const figure = document.createElement('figure');
        figure.className = 'overflow-hidden rounded-lg border border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800';

        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.className = 'block bg-white dark:bg-gray-900';

        const img = document.createElement('img');
        img.src = url;
        img.alt = label;
        img.loading = 'lazy';
        img.className = 'block h-auto max-h-[360px] w-full object-contain';

        link.appendChild(img);
        const caption = document.createElement('figcaption');
        caption.className = 'border-t border-gray-200 px-2 py-1 text-[11px] text-gray-500 dark:border-gray-700 dark:text-gray-400';
        caption.textContent = label;

        figure.appendChild(link);
        figure.appendChild(caption);
        preview.appendChild(figure);
    }

    function fileKind(file) {
        const name = (file.name || '').toLowerCase();
        if (file.type === 'application/pdf' || name.endsWith('.pdf')) {
            return 'pdf';
        }
        if (file.type === 'image/png' || name.endsWith('.png')) {
            return 'image';
        }
        if (file.type === 'image/jpeg' || name.endsWith('.jpg') || name.endsWith('.jpeg')) {
            return 'image';
        }
        return '';
    }

    async function loadFile(file) {
        if (!file || state.busy) {
            return;
        }

        const kind = fileKind(file);
        if (!kind) {
            resetResult();
            clearPreview();
            state.pdf = null;
            showError('Поддерживаются только PDF, PNG и JPEG.');
            return;
        }

        if (file.size > 25 * 1024 * 1024) {
            resetResult();
            clearPreview();
            state.pdf = null;
            showError('Файл больше 25 МБ. Для демо возьмите письмо покороче.');
            return;
        }

        resetResult();
        clearPreview();
        state.file = file;
        state.pdf = null;

        try {
            if (kind === 'pdf') {
                setStatus('Читаю PDF в браузере…');
                const bytes = await file.arrayBuffer();
                state.pdf = await pdfjsLib.getDocument({
                    data: bytes,
                    isEvalSupported: false,
                }).promise;

                if (state.pdf.numPages < 1) {
                    throw new Error('В PDF нет страниц');
                }

                const requested = Math.min(parseInt(pagesSelect.value, 10) || 1, maxPages);
                await renderPdfPages(requested);
            } else {
                setStatus('Готовлю изображение…');
                const blob = await compressImage(file);
                state.blobs = [blob];
                addPreview(blob, 'Страница 1');
                setStatus('Готово: 1 страница. Можно запускать VLM.');
            }

            setBusy(false);
            runButton.disabled = !canRun || state.blobs.length === 0;
        } catch (error) {
            console.error(error);
            state.pdf = null;
            clearPreview();
            setStatus('Файл не загружен.');
            showError('Не удалось прочитать файл: ' + friendlyError(error));
        }
    }

    async function renderPdfPages(count) {
        if (!state.pdf) {
            return;
        }

        clearPreview();
        const total = Math.min(count, maxPages, state.pdf.numPages);
        setStatus('Рендерю страницы PDF… 0/' + total);

        for (let pageNumber = 1; pageNumber <= total; pageNumber += 1) {
            const page = await state.pdf.getPage(pageNumber);
            const natural = page.getViewport({ scale: 1 });
            const scale = Math.max(0.6, Math.min(2.2, 1600 / natural.width, 2400 / natural.height));
            const viewport = page.getViewport({ scale });

            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.floor(viewport.width));
            canvas.height = Math.max(1, Math.floor(viewport.height));
            const context = canvas.getContext('2d', { alpha: false });

            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            await page.render({ canvasContext: context, viewport }).promise;

            let blob = await canvasToBlob(canvas, 0.84);
            if (blob && blob.size > 2 * 1024 * 1024) {
                blob = await canvasToBlob(canvas, 0.66);
            }
            if (!blob) {
                throw new Error('Браузер не смог закодировать страницу ' + pageNumber);
            }
            if (blob.size > 2 * 1024 * 1024) {
                throw new Error('Страница ' + pageNumber + ' получилась больше 2 МБ');
            }

            state.blobs.push(blob);
            addPreview(blob, 'Страница ' + pageNumber);
            setStatus('Рендерю страницы PDF… ' + pageNumber + '/' + total);
        }

        runButton.disabled = !canRun || state.blobs.length === 0;
        setStatus(
            'Готово: ' + state.blobs.length + ' из ' + state.pdf.numPages
            + ' страниц. В PDF всего ' + state.pdf.numPages + '.'
        );
    }

    function canvasToBlob(canvas, quality) {
        return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    }

    async function compressImage(file) {
        if (typeof createImageBitmap !== 'function') {
            if (file.size > 2 * 1024 * 1024) {
                throw new Error('Браузер не умеет сжимать изображение, а файл больше 2 МБ');
            }
            return file;
        }

        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, 1600 / bitmap.width, 2400 / bitmap.height);
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.floor(bitmap.width * scale));
        canvas.height = Math.max(1, Math.floor(bitmap.height * scale));
        const context = canvas.getContext('2d', { alpha: false });
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await canvasToBlob(canvas, 0.84);
        if (!blob) {
            throw new Error('Браузер не смог сжать изображение');
        }
        if (blob.size > 2 * 1024 * 1024) {
            throw new Error('Изображение получилось больше 2 МБ');
        }
        return blob;
    }

    async function analyze() {
        if (state.busy || !canRun) {
            return;
        }
        if (!state.blobs.length) {
            setStatus('Сначала выберите PDF или изображение.');
            return;
        }

        const form = new FormData();
        form.append('csrf', csrf.value);
        state.blobs.forEach((blob, index) => {
            form.append('pages[]', blob, 'page-' + (index + 1) + '.jpg');
        });

        setBusy(true);
        hideError();

        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 190000);

        try {
            const response = await fetch(analyzeUrl, {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });

            const body = await response.text();
            let data;
            try {
                data = JSON.parse(body);
            } catch (error) {
                throw new Error('Сервер вернул не JSON (HTTP ' + response.status + ')');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || ('HTTP ' + response.status));
            }

            state.lastResponse = data;
            renderResult(data);
            setStatus('Готово за ' + formatDuration(data.duration_ms) + '.');
        } catch (error) {
            console.error(error);
            const message = error.name === 'AbortError'
                ? 'Запрос прерван: модель не ответила за 190 секунд.'
                : friendlyError(error);
            showError(message);
            setStatus('Разбор не удался.');
        } finally {
            window.clearTimeout(timer);
            setBusy(false);
            runButton.disabled = !canRun || state.blobs.length === 0;
        }
    }

    function renderResult(data) {
        const result = data.result || {};
        resultBox.innerHTML = '';
        empty.classList.add('hidden');
        resultBox.classList.remove('hidden');
        downloadButton.classList.remove('hidden');

        const meta = document.createElement('p');
        meta.className = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
        const tokens = data.usage && data.usage.total_tokens ? data.usage.total_tokens : '—';
        meta.textContent = 'Модель: ' + (data.model || data.requested_model || '—')
            + ' · ' + formatDuration(data.duration_ms)
            + ' · токенов: ' + tokens
            + ' · страниц: ' + (data.pages || 0);
        resultBox.appendChild(meta);

        const badges = document.createElement('div');
        badges.className = 'mt-3 flex flex-wrap items-center gap-2';
        badges.appendChild(badge(result.type_title || 'Прочее', 'info'));
        badges.appendChild(badge('уверенность ' + percent(result.type_confidence), confidenceTone(result.type_confidence)));
        if (result.confidence) {
            badges.appendChild(badge('общая ' + percent(result.confidence), confidenceTone(result.confidence)));
        }
        resultBox.appendChild(badges);

        if (result.summary) {
            resultBox.appendChild(section('Краткое содержание', result.summary, 'whitespace-pre-line'));
        }

        const risk = result.risk || {};
        if (risk.level && risk.level !== 'unknown' || (risk.reasons || []).length) {
            const riskBox = document.createElement('div');
            riskBox.className = 'mt-3 rounded-lg border p-3 text-sm ' + riskBoxClass(risk.level);
            const title = document.createElement('div');
            title.className = 'font-semibold';
            title.textContent = 'Риск: ' + riskLabel(risk.level);
            riskBox.appendChild(title);

            if ((risk.reasons || []).length) {
                const list = document.createElement('ul');
                list.className = 'mt-1 list-disc pl-5';
                risk.reasons.forEach((reason) => {
                    const item = document.createElement('li');
                    item.textContent = reason;
                    list.appendChild(item);
                });
                riskBox.appendChild(list);
            }
            resultBox.appendChild(riskBox);
        }

        resultBox.appendChild(fieldsTable(result.fields || {}));

        if ((result.visual_marks || []).length) {
            const marks = document.createElement('div');
            marks.className = 'mt-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800/60';
            const title = document.createElement('div');
            title.className = 'font-semibold text-gray-900 dark:text-white';
            title.textContent = 'Визуальные признаки';
            marks.appendChild(title);
            const list = document.createElement('div');
            list.className = 'mt-2 flex flex-wrap gap-2';
            result.visual_marks.forEach((mark) => list.appendChild(badge(mark, 'ok')));
            marks.appendChild(list);
            resultBox.appendChild(marks);
        }

        if ((result.findings || []).length) {
            const box = document.createElement('div');
            box.className = 'mt-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700';
            const title = document.createElement('div');
            title.className = 'font-semibold text-gray-900 dark:text-white';
            title.textContent = 'Наблюдения';
            box.appendChild(title);
            const list = document.createElement('ul');
            list.className = 'mt-1 list-disc pl-5 text-sm';
            result.findings.forEach((finding) => {
                const item = document.createElement('li');
                item.textContent = (finding.kind ? finding.kind + ': ' : '') + finding.text;
                list.appendChild(item);
            });
            box.appendChild(list);
            resultBox.appendChild(box);
        }

        const raw = document.createElement('details');
        raw.className = 'mt-4';
        const summary = document.createElement('summary');
        summary.className = 'cursor-pointer text-xs font-semibold text-gray-600 dark:text-gray-300';
        summary.textContent = 'Показать сырой JSON модели';
        raw.appendChild(summary);
        const pre = document.createElement('pre');
        pre.className = 'mt-2 max-h-[420px] overflow-auto rounded-lg bg-gray-900 p-3 text-xs text-gray-100';
        pre.textContent = JSON.stringify(data.raw || {}, null, 2);
        raw.appendChild(pre);
        resultBox.appendChild(raw);

        const note = document.createElement('p');
        note.className = 'mt-4 text-xs text-gray-500 dark:text-gray-400';
        note.textContent = 'Это предложение модели. Проверьте реквизиты, суммы и сроки по оригиналу — VLM тоже ошибается.';
        resultBox.appendChild(note);
    }

    function section(titleText, text, extraClass) {
        const box = document.createElement('div');
        box.className = 'mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800/60 dark:text-gray-200';
        const title = document.createElement('div');
        title.className = 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
        title.textContent = titleText;
        const body = document.createElement('div');
        body.className = 'mt-1 ' + (extraClass || '');
        body.textContent = text;
        box.appendChild(title);
        box.appendChild(body);
        return box;
    }

    function fieldsTable(fields) {
        const wrap = document.createElement('div');
        wrap.className = 'mt-4 overflow-x-auto';
        const table = document.createElement('table');
        table.className = 'prl-table min-w-[720px]';

        const head = document.createElement('thead');
        const headRow = document.createElement('tr');
        ['Поле', 'Значение', 'Цитата', 'Стр.', 'Уверенность'].forEach((label) => {
            const th = document.createElement('th');
            th.textContent = label;
            headRow.appendChild(th);
        });
        head.appendChild(headRow);
        table.appendChild(head);

        const body = document.createElement('tbody');
        const names = Object.keys(fieldLabels);
        let filled = 0;

        names.forEach((name) => {
            const item = fields[name] || {};
            const value = item.value || '';
            if (value !== '') {
                filled += 1;
            }
            const row = document.createElement('tr');

            const field = document.createElement('td');
            field.className = 'font-mono text-xs';
            field.textContent = fieldLabels[name] || name;
            row.appendChild(field);

            const valueCell = document.createElement('td');
            valueCell.className = 'font-semibold break-words';
            valueCell.textContent = value !== '' ? value : '—';
            row.appendChild(valueCell);

            const quoteCell = document.createElement('td');
            quoteCell.className = 'max-w-[360px] text-xs text-gray-500 dark:text-gray-400';
            quoteCell.textContent = item.quote || '—';
            row.appendChild(quoteCell);

            const pageCell = document.createElement('td');
            pageCell.className = 'text-xs';
            pageCell.textContent = item.page ? String(item.page) : '—';
            row.appendChild(pageCell);

            const confCell = document.createElement('td');
            if (value !== '') {
                confCell.appendChild(badge(percent(item.confidence), confidenceTone(item.confidence)));
            } else {
                confCell.textContent = '—';
            }
            row.appendChild(confCell);

            body.appendChild(row);
        });

        if (!filled) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 5;
            cell.className = 'text-center text-gray-500';
            cell.textContent = 'Реквизиты не найдены — посмотрите сырой JSON и оригинал.';
            row.appendChild(cell);
            body.appendChild(row);
        }

        table.appendChild(body);
        wrap.appendChild(table);
        return wrap;
    }

    function badge(text, tone) {
        const node = document.createElement('span');
        node.className = 'prl-badge prl-badge-' + (tone || 'info');
        node.textContent = text;
        return node;
    }

    function confidenceTone(value) {
        const number = Number(value) || 0;
        if (number >= 0.8) {
            return 'ok';
        }
        if (number >= 0.5) {
            return 'wait';
        }
        return 'err';
    }

    function percent(value) {
        return Math.round((Number(value) || 0) * 100) + '%';
    }

    function riskLabel(level) {
        return {
            low: 'низкий',
            medium: 'средний',
            high: 'высокий',
            unknown: 'не определён',
        }[level] || level || 'не определён';
    }

    function riskBoxClass(level) {
        if (level === 'high') {
            return 'border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-900/20 dark:text-red-100';
        }
        if (level === 'medium') {
            return 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-100';
        }
        if (level === 'low') {
            return 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-100';
        }
        return 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-200';
    }

    function formatDuration(ms) {
        const value = Number(ms) || 0;
        if (value < 1000) {
            return value + ' мс';
        }
        return (value / 1000).toFixed(1) + ' с';
    }

    function friendlyError(error) {
        if (!error) {
            return 'неизвестная ошибка';
        }
        if (error.message) {
            return error.message;
        }
        return String(error);
    }

    function downloadJson() {
        if (!state.lastResponse) {
            return;
        }
        const blob = new Blob([JSON.stringify(state.lastResponse, null, 2)], {
            type: 'application/json;charset=utf-8',
        });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'vlm-demo-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0];
        fileInput.value = '';
        if (file) {
            loadFile(file);
        }
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.add('border-blue-400', 'bg-blue-50');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.remove('border-blue-400', 'bg-blue-50');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
        if (file) {
            loadFile(file);
        }
    });

    pagesSelect.addEventListener('change', () => {
        if (!state.pdf || state.busy) {
            return;
        }
        const count = Math.min(parseInt(pagesSelect.value, 10) || 1, maxPages);
        resetResult();
        setBusy(false);
        runButton.disabled = true;
        renderPdfPages(count).catch((error) => {
            console.error(error);
            showError('Не удалось отрендерить страницы: ' + friendlyError(error));
        });
    });

    runButton.addEventListener('click', analyze);
    downloadButton.addEventListener('click', downloadJson);

    if (!canRun) {
        runButton.disabled = true;
        setStatus('Нет права prl398.run — запуск недоступен.');
    }
}
