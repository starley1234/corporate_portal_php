(function () {
    'use strict';
    var CATEGORIES = <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var initialCategory = <?php echo json_encode($category); ?>;
    var initialResult = <?php echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var $ = function (id) { return document.getElementById(id); };
    var categoryField = $('size-type'), measureField = $('size-measure');
    var ON = ['is-on'];
    var OFF = [];

    function parseNumber(raw) {
        if (raw == null) return null;
        var value = String(raw).replace(/[\s\u00A0\u202F\u2009]+/g, '').replace(/,/g, '.').replace(/[−–]/g, '-');
        if (!/^\d{1,3}(\.\d{0,3})?$/.test(value)) return null;
        var number = Number(value);
        return Number.isFinite(number) && number > 0 && number <= 300 ? number : null;
    }

    function format(number, digits) {
        var parts = Number(number).toFixed(digits == null ? 1 : digits).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');
        return (parts[0] + ',' + parts[1]).replace(/0+$/, '').replace(/,$/, '');
    }

    function calculate(category, rawMeasure) {
        var definition = CATEGORIES[category], measure = parseNumber(rawMeasure);
        if (!definition || measure == null) return null;
        var best = null, distance = null;
        definition.rows.forEach(function (row) {
            var current = Math.abs(Number(row.measure) - measure);
            if (distance == null || current < distance) { best = row; distance = current; }
        });
        if (!best) return null;
        return { category: category, measure: measure, unit: definition.unit, matched_measure: Number(best.measure), display: String(best.display), difference: Math.round(distance * 1000) / 1000, sizes: best.sizes, details: best.details, nearest: best };
    }

    window.NJ511 = { calculate: calculate, parseNumber: parseNumber, format: format };
    if (!categoryField || !measureField) return;

    function renderTable() {
        var definition = CATEGORIES[categoryField.value], body = $('size-table');
        body.textContent = '';
        $('size-table-label').textContent = definition.title;
        $('size-table-measure').textContent = definition.field_label + ' · ' + definition.unit;
        definition.rows.forEach(function (row) {
            var tr = document.createElement('tr'), first = document.createElement('th'), sizes = document.createElement('td'), details = document.createElement('td');
            tr.className = 'size-row cursor-pointer transition hover:bg-blue-50/70 dark:hover:bg-blue-900/20';
            tr.dataset.measure = row.measure;
            first.className = 'py-2 pr-3 font-semibold text-gray-800 dark:text-gray-200';
            sizes.className = 'py-2 pr-3 text-xs text-gray-600 dark:text-gray-300';
            details.className = 'py-2 text-xs text-gray-500 dark:text-gray-400';
            first.textContent = row.display + ' ' + definition.unit;
            sizes.textContent = Object.keys(row.sizes).map(function (key) { return key + ' ' + row.sizes[key]; }).join(' · ');
            details.textContent = Object.keys(row.details).map(function (key) { return key + ' ' + row.details[key]; }).join(' · ');
            tr.appendChild(first); tr.appendChild(sizes); tr.appendChild(details); body.appendChild(tr);
        });
    }

    function syncUrl() {
        if (!window.history || !history.replaceState) return;
        var query = new URLSearchParams();
        query.set('type', categoryField.value);
        if (measureField.value.trim()) query.set('v', measureField.value.trim());
        history.replaceState(null, '', location.pathname + '?' + query.toString());
    }

    function render() {
        var definition = CATEGORIES[categoryField.value], result = calculate(categoryField.value, measureField.value);
        var output = $('size-results'), error = $('size-error'), copy = $('size-copy');
        $('size-measure-label').textContent = definition.field_label + ' (' + definition.unit + ')';
        $('size-unit').textContent = definition.unit;
        $('size-guide').textContent = definition.guide;
        if (!measureField.value.trim()) {
            $('size-main').textContent = '—';
            $('size-main-label').textContent = 'Введите мерку — таблица подберёт ближайшее значение';
            $('size-difference').textContent = '';
            output.textContent = ''; $('size-details').textContent = '';
            error.classList.add('hidden'); copy.disabled = true; copy.__text = '';
        } else if (!result) {
            $('size-main').textContent = '—';
            $('size-main-label').textContent = 'Проверьте мерку';
            $('size-difference').textContent = '';
            output.textContent = ''; $('size-details').textContent = '';
            error.textContent = 'Введите положительное число в пределах 0–300 ' + definition.unit + '.';
            error.classList.remove('hidden'); copy.disabled = true; copy.__text = '';
        } else {
            var keys = Object.keys(result.sizes);
            $('size-main').textContent = result.sizes[keys[0]];
            $('size-main-label').textContent = keys[0] + ' · ' + definition.title;
            output.textContent = '';
            Object.keys(result.sizes).forEach(function (key) {
                var wrapper = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd');
                wrapper.className = 'flex items-center justify-between gap-2 rounded-lg bg-white/80 px-3 py-2 dark:bg-gray-900/50';
                dt.className = 'text-xs text-gray-600 dark:text-gray-300'; dd.className = 'text-sm font-extrabold text-gray-900 dark:text-white';
                dt.textContent = key; dd.textContent = result.sizes[key]; wrapper.appendChild(dt); wrapper.appendChild(dd); output.appendChild(wrapper);
            });
            var details = $('size-details'); details.textContent = '';
            Object.keys(result.details).forEach(function (key) {
                var wrapper = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd');
                wrapper.className = 'flex items-center justify-between gap-2 rounded-lg border border-blue-100 px-3 py-2 dark:border-blue-900/40';
                dt.className = 'text-xs text-gray-500 dark:text-gray-400'; dd.className = 'text-xs font-bold text-gray-800 dark:text-gray-200';
                dt.textContent = key; dd.textContent = result.details[key]; wrapper.appendChild(dt); wrapper.appendChild(dd); details.appendChild(wrapper);
            });
            $('size-difference').textContent = 'Ближайшая строка таблицы: ' + result.display + ' ' + result.unit + (result.difference ? ' · отличие от введённой мерки ' + format(result.difference, 3) + ' ' + result.unit : ' · точное совпадение');
            error.classList.add('hidden'); copy.disabled = false;
            copy.__text = definition.title + ': ' + Object.keys(result.sizes).map(function (key) { return key + ' ' + result.sizes[key]; }).join(', ');
        }
        document.querySelectorAll('.size-row').forEach(function (row) {
            var matched = result && Number(row.dataset.measure) === result.matched_measure;
            row.classList.toggle('bg-blue-50', !!matched);
            row.classList.toggle('dark:bg-blue-900/30', !!matched);
        });
        syncUrl();
    }

    function setCategory(category) {
        if (!CATEGORIES[category]) return;
        categoryField.value = category;
        document.querySelectorAll('.size-category').forEach(function (button) {
            var active = button.dataset.category === category;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.classList.remove.apply(button.classList, ON.concat(OFF));
            button.classList.add.apply(button.classList, active ? ON : OFF);
        });
        renderTable(); render();
    }

    document.querySelectorAll('.size-category').forEach(function (button) {
        button.addEventListener('click', function () { setCategory(button.dataset.category); });
    });
    $('size-form').addEventListener('submit', function (event) { event.preventDefault(); render(); });
    measureField.addEventListener('input', render);
    $('size-table').addEventListener('click', function (event) {
        var row = event.target.closest('.size-row');
        if (!row) return;
        measureField.value = row.dataset.measure; render(); measureField.focus();
    });
    $('size-copy').addEventListener('click', function () {
        if (this.__text && navigator.clipboard) navigator.clipboard.writeText(this.__text).then(function () { $('size-copy').textContent = 'Скопировано'; });
    });
    $('size-share').addEventListener('click', function () {
        if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(function () { $('size-share').textContent = 'Ссылка скопирована'; });
    });

    if (initialCategory !== categoryField.value) categoryField.value = initialCategory;
    renderTable();
    if (initialResult && !measureField.value) measureField.value = String(initialResult.measure);
    render();
})();
