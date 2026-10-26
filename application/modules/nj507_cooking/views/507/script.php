(function () {
    'use strict';
    var PRODUCTS = <?php echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var MEASURES = <?php echo json_encode($measures, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var initialProduct = <?php echo json_encode($product); ?>;
    var isProductPage = <?php echo $product_page ? 'true' : 'false'; ?>;
    var $ = function (id) { return document.getElementById(id); };
    var productField = $('cook-product'), measureField = $('cook-measure'), amountField = $('cook-amount');
    var directionField = $('cook-direction'), fromServings = $('cook-from-servings'), toServings = $('cook-to-servings');

    function parseNumber(raw) {
        if (raw == null) return null;
        var value = String(raw).replace(/[\s\u00A0\u202F\u2009]+/g, '').replace(/,/g, '.').replace(/[−–]/g, '-');
        if (!/^(\d{1,7}(\.\d{0,8})?|\.\d{1,8})$/.test(value)) return null;
        var number = Number(value);
        return Number.isFinite(number) && number <= 1e6 ? number : null;
    }

    function round(value, digits) {
        var factor = Math.pow(10, digits);
        return Math.floor(value * factor + 0.5 + 1e-9) / factor;
    }

    function format(value, digits) {
        var fixed = round(Number(value), digits == null ? 1 : digits).toFixed(digits == null ? 1 : digits).split('.');
        fixed[0] = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');
        return (fixed[0] + (digits ? ',' + fixed[1] : ',' + fixed[1])).replace(/0+$/, '').replace(/,$/, '');
    }

    function calculate(product, measure, rawAmount, direction, fromRaw, toRaw) {
        var item = PRODUCTS[product], unit = MEASURES[measure];
        var amount = parseNumber(rawAmount), from = parseNumber(fromRaw), to = parseNumber(toRaw);
        if (!item || !unit || (direction !== 'to_mass' && direction !== 'to_measure') || amount == null || from == null || to == null || from < 1 || to < 1 || from > 1000 || to > 1000) return null;
        var scale = to / from, scaled = amount * scale, density = item.density, min, max, text, formula;
        if (direction === 'to_mass') {
            min = round(scaled * unit.ml * density[0], 1);
            max = round(scaled * unit.ml * density[1], 1);
            text = format(min, 1) + '–' + format(max, 1) + ' г';
            formula = format(scaled, 1) + ' × ' + unit.ml + ' мл × ' + format(density[0], 3) + '–' + format(density[1], 3) + ' г/мл';
        } else {
            min = round(scaled / (density[1] * unit.ml), 2);
            max = round(scaled / (density[0] * unit.ml), 2);
            text = (min === max ? format(min, 2) : format(min, 2) + '–' + format(max, 2)) + ' ' + unit.symbol;
            formula = format(scaled, 1) + ' г ÷ (' + unit.ml + ' мл × плотность ' + density[0] + '–' + density[1] + ' г/мл)';
        }
        if (!Number.isFinite(min) || !Number.isFinite(max)) return null;
        return { product: product, measure: measure, direction: direction, min: min, max: max, formatted: text, formula: formula, scale: scale };
    }

    function area(shape, aRaw, bRaw) {
        var a = parseNumber(aRaw), b = parseNumber(bRaw);
        if (a == null || a < 1 || a > 200) return null;
        if (shape === 'round') return Math.PI * Math.pow(a / 2, 2);
        if (shape === 'square') return a * a;
        if (shape === 'rectangle' && b != null && b >= 1 && b <= 200) return a * b;
        return null;
    }

    function formFactor(fromShape, fromA, fromB, toShape, toA, toB) {
        var fromArea = area(fromShape, fromA, fromB), toArea = area(toShape, toA, toB);
        if (fromArea == null || toArea == null) return null;
        var factor = toArea / fromArea;
        if (!Number.isFinite(factor) || factor < 0.01 || factor > 100) return null;
        return { from_area: round(fromArea, 2), to_area: round(toArea, 2), factor: round(factor, 4), formatted: format(factor, 4), change: factor > 1 ? 'увеличить' : (factor < 1 ? 'уменьшить' : 'оставить без изменения') };
    }

    window.NJ507 = { calculate: calculate, formFactor: formFactor, format: format, parseNumber: parseNumber };
    if (!productField || !measureField || !amountField) return;

    var ON = ['is-on'];
    var OFF = [];

    function setDirection(direction) {
        directionField.value = direction;
        document.querySelectorAll('.cook-direction').forEach(function (button) {
            var active = button.dataset.direction === direction;
            button.setAttribute('aria-checked', active ? 'true' : 'false');
            button.classList.remove.apply(button.classList, ON.concat(OFF));
            button.classList.add.apply(button.classList, active ? ON : OFF);
        });
        $('cook-amount-label').textContent = direction === 'to_mass' ? 'Количество меры' : 'Масса, граммы';
        amountField.placeholder = direction === 'to_mass' ? '1' : '100';
        render();
    }

    function syncUrl() {
        if (!window.history || !history.replaceState) return;
        var query = new URLSearchParams();
        if (!isProductPage) query.set('p', productField.value);
        query.set('d', directionField.value); query.set('n', amountField.value.trim());
        query.set('u', measureField.value); query.set('from', fromServings.value); query.set('to', toServings.value);
        history.replaceState(null, '', location.pathname + '?' + query.toString());
    }

    function renderMeasureCards() {
        var product = productField.value;
        document.querySelectorAll('.cook-measure-card').forEach(function (button) {
            var item = calculate(product, button.dataset.measure, '1', 'to_mass', 1, 1);
            var output = button.querySelector('[data-output]');
            if (output) output.textContent = item ? item.formatted : '—';
        });
        $('cook-note').textContent = PRODUCTS[product].note;
    }

    function render() {
        var product = productField.value, item = PRODUCTS[product];
        var result = calculate(product, measureField.value, amountField.value, directionField.value, fromServings.value, toServings.value);
        var error = $('cook-error'), copy = $('cook-copy');
        if (!result) {
            $('cook-main').textContent = '—';
            $('cook-result-caption').textContent = 'Проверьте количество и число порций (от 1 до 1000).';
            $('cook-formula').textContent = '';
            error.textContent = 'Введите неотрицательное число. Для обратного пересчёта выберите граммы, продукт и меру.';
            error.classList.remove('hidden'); copy.disabled = true; copy.__text = '';
        } else {
            $('cook-main').textContent = result.formatted;
            $('cook-result-caption').textContent = item.title + ' · ' + MEASURES[measureField.value].title + (result.scale !== 1 ? ' · масштаб порций × ' + format(result.scale, 2) : '');
            $('cook-formula').textContent = 'Формула: ' + result.formula;
            error.classList.add('hidden'); copy.disabled = false; copy.__text = item.title + ': ' + result.formatted + '\n' + result.formula;
        }
        renderMeasureCards(); syncUrl();
    }

    function shapeTitle(shape, a, b) {
        if (shape === 'round') return 'круглая Ø' + a + ' см';
        if (shape === 'square') return 'квадратная ' + a + ' × ' + a + ' см';
        return 'прямоугольная ' + a + ' × ' + b + ' см';
    }

    function updateShapeFields(prefix) {
        var shape = $(prefix + '-shape').value;
        var wrap = $(prefix + '-b-wrap');
        var label = $(prefix + '-a-label');
        label.textContent = shape === 'round' ? 'Диаметр, см' : (shape === 'square' ? 'Сторона, см' : 'Длина, см');
        wrap.classList.toggle('hidden', shape !== 'rectangle');
    }

    function renderForm() {
        var fromShape = $('form-from-shape').value, toShape = $('form-to-shape').value;
        var fromA = $('form-from-a').value, fromB = $('form-from-b').value;
        var toA = $('form-to-a').value, toB = $('form-to-b').value;
        var result = formFactor(fromShape, fromA, fromB, toShape, toA, toB);
        if (!result) {
            $('form-main').textContent = 'Проверьте размеры: от 1 до 200 см';
            $('form-detail').textContent = '';
            $('form-error').textContent = 'Для круглой формы укажите диаметр, для квадратной — сторону, для прямоугольной — длину и ширину.';
            $('form-error').classList.remove('hidden'); return;
        }
        var percent = Math.abs((result.factor - 1) * 100);
        var action = result.factor > 1 ? 'увеличить' : (result.factor < 1 ? 'уменьшить' : 'оставить без изменения');
        $('form-main').textContent = result.factor === 1 ? 'Коэффициент 1 · ингредиенты не менять' : 'Коэффициент ' + result.formatted + ' · ' + action + ' ингредиенты примерно на ' + format(percent, 1) + ' %';
        $('form-detail').textContent = shapeTitle(fromShape, fromA, fromB) + ' → ' + shapeTitle(toShape, toA, toB) + '. Площадь: ' + format(result.from_area, 1) + ' → ' + format(result.to_area, 1) + ' см².';
        $('form-error').classList.add('hidden');
    }

    document.querySelectorAll('.cook-direction').forEach(function (button) {
        button.addEventListener('click', function () { setDirection(button.dataset.direction); });
    });
    ['input', 'change'].forEach(function (eventName) {
        [productField, measureField, amountField, fromServings, toServings].forEach(function (field) { field.addEventListener(eventName, render); });
    });
    $('cook-form').addEventListener('submit', function (event) { event.preventDefault(); render(); });
    $('cook-measure-grid').addEventListener('click', function (event) {
        var button = event.target.closest('.cook-measure-card');
        if (!button) return;
        measureField.value = button.dataset.measure; amountField.value = '1';
        fromServings.value = '1'; toServings.value = '1'; setDirection('to_mass'); render();
    });
    $('cook-copy').addEventListener('click', function () {
        if (this.__text && navigator.clipboard) navigator.clipboard.writeText(this.__text).then(function () { $('cook-copy').textContent = 'Скопировано'; });
    });
    $('cook-share').addEventListener('click', function () {
        if (navigator.clipboard) navigator.clipboard.writeText(location.href).then(function () { $('cook-share').textContent = 'Ссылка скопирована'; });
    });

    ['from', 'to'].forEach(function (prefix) {
        $(prefix === 'from' ? 'form-from-shape' : 'form-to-shape').addEventListener('change', function () { updateShapeFields('form-' + prefix); renderForm(); });
        ['a', 'b'].forEach(function (part) {
            var field = $('form-' + prefix + '-' + part);
            if (field) field.addEventListener('input', renderForm);
        });
    });
    $('cook-form-convert').addEventListener('submit', function (event) { event.preventDefault(); renderForm(); });

    setDirection(directionField.value);
    updateShapeFields('form-from'); updateShapeFields('form-to'); renderForm();
})();
