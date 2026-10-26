(function () {
    'use strict';
    var CATALOG = <?php echo json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var initialCategory = <?php echo json_encode($category); ?>;
    var initialFrom = <?php echo json_encode($from); ?>;
    var initialTo = <?php echo json_encode($to); ?>;
    var $ = function (id) { return document.getElementById(id); };
    var categoryField = $('un-category'), valueField = $('un-value'), fromField = $('un-from'), toField = $('un-to');
    var NBSP = '\u00A0', SCALE = 8, MAX_ABS = 1e12;

    function parseNumber(raw) {
        if (raw == null) return null;
        var value = String(raw).replace(/[\s\u00A0\u202F\u2009]+/g, '').replace(/,/g, '.').replace(/[−–]/g, '-');
        if (!/^-?(\d{1,13}(\.\d{0,12})?|\.\d{1,12})$/.test(value)) return null;
        var number = Number(value);
        return Number.isFinite(number) && Math.abs(number) <= MAX_ABS ? number : null;
    }

    function round(value) {
        var factor = Math.pow(10, SCALE);
        var rounded = Math.floor(Math.abs(value) * factor + 0.5 + 1e-9) / factor;
        return value < 0 ? -rounded : rounded;
    }

    function format(value) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        var rounded = round(Number(value));
        var parts = Math.abs(rounded).toFixed(SCALE).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
        var text = (parts[0] + ',' + parts[1]).replace(/0+$/, '').replace(/,$/, '');
        return (rounded < 0 ? '−' : '') + text;
    }

    function calculate(category, rawValue, from, to) {
        var group = CATALOG[category];
        if (!group || !group.units[from] || !group.units[to]) return null;
        var value = parseNumber(rawValue);
        if (value == null || (category !== 'temperature' && value < 0)) return null;
        var source = group.units[from], target = group.units[to];
        var base = value * Number(source.factor) + Number(source.offset || 0);
        if (category === 'temperature' && base < -273.150000001) return null;
        var converted = (base - Number(target.offset || 0)) / Number(target.factor);
        if (!Number.isFinite(converted) || Math.abs(converted) > MAX_ABS) return null;
        converted = round(converted);
        return {
            category: category, value: value, from: from, to: to, converted: converted,
            formatted: format(converted),
            formula: format(value) + ' ' + source.symbol + ' = ' + format(converted) + ' ' + target.symbol
        };
    }

    window.NJ506 = { calculate: calculate, format: format, parseNumber: parseNumber };
    if (!categoryField || !valueField || !fromField || !toField) return;

    var ON = ['is-on'];
    var OFF = [];

    function populateUnits(category, from, to) {
        var units = CATALOG[category].units;
        [fromField, toField].forEach(function (select, index) {
            var preferred = index === 0 ? from : to;
            select.textContent = '';
            Object.keys(units).forEach(function (key) {
                var option = document.createElement('option');
                option.value = key;
                option.textContent = units[key].title + ' (' + units[key].symbol + ')';
                option.selected = key === preferred;
                select.appendChild(option);
            });
        });
    }

    function renderExamples() {
        var container = $('un-examples');
        var examples = CATALOG[categoryField.value].examples || [];
        container.textContent = '';
        examples.forEach(function (example) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'un-example n2j-chip';
            button.dataset.value = example.value;
            button.dataset.from = example.from;
            button.dataset.to = example.to;
            button.textContent = example.label;
            container.appendChild(button);
        });
    }

    function setCategory(category) {
        if (!CATALOG[category]) return;
        var def = CATALOG[category];
        categoryField.value = category;
        populateUnits(category, def.default_from, def.default_to);
        document.querySelectorAll('.un-category').forEach(function (button) {
            var active = button.dataset.category === category;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.classList.remove.apply(button.classList, ON.concat(OFF));
            button.classList.add.apply(button.classList, active ? ON : OFF);
        });
        renderExamples();
        render();
    }

    function updateTable(result) {
        var body = $('un-table'), category = categoryField.value, group = CATALOG[category];
        body.textContent = '';
        $('un-table-caption').textContent = valueField.value.trim() ? format(parseNumber(valueField.value)) + ' ' + group.units[fromField.value].symbol : '';
        Object.keys(group.units).forEach(function (unitCode) {
            var row = document.createElement('tr'), heading = document.createElement('th'), cell = document.createElement('td');
            heading.className = 'py-2 pr-3 font-medium text-gray-700 dark:text-gray-200';
            cell.className = 'py-2 text-right font-mono font-semibold text-gray-900 dark:text-white';
            heading.textContent = group.units[unitCode].title + ' (' + group.units[unitCode].symbol + ')';
            var converted = result ? calculate(category, valueField.value, fromField.value, unitCode) : null;
            cell.textContent = converted ? converted.formatted : '—';
            row.appendChild(heading); row.appendChild(cell); body.appendChild(row);
        });
    }

    function syncUrl() {
        if (!window.history || !history.replaceState) return;
        var query = new URLSearchParams();
        query.set('c', categoryField.value);
        if (valueField.value.trim()) query.set('v', valueField.value.trim());
        query.set('from', fromField.value); query.set('to', toField.value);
        history.replaceState(null, '', location.pathname + '?' + query.toString());
    }

    function render() {
        var category = categoryField.value, group = CATALOG[category];
        var result = calculate(category, valueField.value, fromField.value, toField.value);
        var entered = valueField.value.trim() !== '';
        var error = $('un-error'), copy = $('un-copy');
        if (!entered) {
            $('un-main').textContent = '—';
            $('un-formula').textContent = 'Введите число — пересчёт появится сразу';
            error.classList.add('hidden'); copy.disabled = true; copy.__text = '';
        } else if (!result) {
            $('un-main').textContent = '—';
            $('un-formula').textContent = 'Проверьте число и единицы измерения';
            error.textContent = category === 'temperature' ? 'Температура ниже абсолютного нуля или значение записано неверно.' : 'Введите неотрицательное число и выберите единицы одной категории.';
            error.classList.remove('hidden'); copy.disabled = true; copy.__text = '';
        } else {
            $('un-main').textContent = result.formatted + ' ' + group.units[toField.value].symbol;
            $('un-formula').textContent = result.formula;
            error.classList.add('hidden'); copy.disabled = false; copy.__text = result.formula;
        }
        updateTable(result);
        syncUrl();
    }

    document.querySelectorAll('.un-category').forEach(function (button) {
        button.addEventListener('click', function () { setCategory(button.dataset.category); });
    });
    $('un-examples').addEventListener('click', function (event) {
        var button = event.target.closest('.un-example');
        if (!button) return;
        fromField.value = button.dataset.from; toField.value = button.dataset.to; valueField.value = button.dataset.value;
        render(); valueField.focus();
    });
    $('un-form').addEventListener('submit', function (event) { event.preventDefault(); render(); });
    [valueField, fromField, toField].forEach(function (field) {
        field.addEventListener(field === valueField ? 'input' : 'change', render);
    });
    $('un-swap').addEventListener('click', function () {
        var old = fromField.value; fromField.value = toField.value; toField.value = old; render();
    });
    $('un-copy').addEventListener('click', function () {
        if (!this.__text || !navigator.clipboard) return;
        navigator.clipboard.writeText(this.__text).then(function () { $('un-copy').innerHTML = '<i class="fas fa-check"></i> Скопировано'; });
    });
    $('un-share').addEventListener('click', function () {
        var link = location.href;
        if (navigator.clipboard) navigator.clipboard.writeText(link).then(function () { $('un-share').innerHTML = '<i class="fas fa-check"></i> Ссылка скопирована'; });
    });

    if (initialCategory !== categoryField.value || !CATALOG[initialCategory]) setCategory('length');
    else {
        populateUnits(initialCategory, initialFrom, initialTo);
        renderExamples();
        render();
    }
})();
