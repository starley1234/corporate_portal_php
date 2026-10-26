<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj518_percent: инлайн-скрипт калькулятора процентов (источник один — этот файл) -->
<script>
/**
 * nj518_percent — калькулятор процентов. Клиентское зеркало Service_Nj518_Percent.
 *
 * Все формулы и тексты результата совпадают с PHP построчно; при изменении
 * менять ОБА файла и гонять tests/Unit/PercentServiceTest.php.
 * Window.NJ518 отдаётся наружу для проверки паритета (ручная сверка PHP↔JS).
 */
(function () {
    'use strict';

    var MODES = <?php echo json_encode($modes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var SCALE = 4;
    var MAX_ABS = 1e15;
    var NBSP = '\u00A0';

    var state = { mode: <?php echo json_encode($mode); ?> };

    // ---------- числа ----------

    function parseNumber(raw) {
        if (raw == null) return null;
        var s = String(raw).replace(/[\s\u00A0\u202F\u2009]+/g, '').replace(/,/g, '.').replace(/[−–]/g, '-');
        if (!/^-?(\d{1,15}(\.\d{0,12})?|\.\d{1,12})$/.test(s)) return null;
        var f = parseFloat(s);
        return Math.abs(f) <= MAX_ABS ? f : null;
    }

    function round(x) {
        var k = Math.pow(10, SCALE);
        var r = Math.floor(Math.abs(x) * k + 0.5 + 1e-9) / k;
        return x < 0 ? -r : r;
    }

    function fmt(x) {
        if (x === null) return '—';
        var r = round(x);
        var s = Math.abs(r).toFixed(SCALE);
        var parts = s.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
        s = parts[0] + ',' + parts[1];
        s = s.replace(/0+$/, '').replace(/,$/, '');
        return (r < 0 ? '−' : '') + s;
    }

    // ---------- расчёт (зеркало Service_Nj518_Percent::calculate) ----------

    function calculate(mode, aRaw, bRaw) {
        if (!MODES[mode]) return null;
        var a = parseNumber(aRaw), b = parseNumber(bRaw);
        if (a === null || b === null) return null;
        var f = fmt, rows = [], v, res, price, profit, word;

        switch (mode) {
            case 'of':
                v = a * b / 100;
                res = [v, '', 'Это ' + f(v), f(a) + ' % × ' + f(b) + ' ÷ 100'];
                rows.push(['Число без этого процента', f(b - v)]);
                rows.push(['Число плюс этот процент', f(b + v)]);
                break;
            case 'share':
                if (b === 0) return null;
                v = a / b * 100;
                res = [v, '%', f(a) + ' — это ' + f(v) + ' % от ' + f(b), f(a) + ' ÷ ' + f(b) + ' × 100'];
                rows.push(['Остаток до целого', f(b - a) + ' (' + f(100 - v) + ' %)']);
                break;
            case 'change':
                if (a === 0) return null;
                v = (b - a) / Math.abs(a) * 100;
                word = v > 0 ? 'Прирост' : (v < 0 ? 'Убыль' : 'Без изменений');
                res = [v, '%', word + ' ' + f(Math.abs(v)) + ' %', '(' + f(b) + ' − ' + f(a) + ') ÷ ' + f(Math.abs(a)) + ' × 100'];
                rows.push(['Абсолютное изменение', f(b - a)]);
                if (b !== 0) rows.push(['Чтобы вернуться обратно', f((a - b) / Math.abs(b) * 100) + ' %']);
                break;
            case 'add':
                v = b * (1 + a / 100);
                res = [v, '', 'Получится ' + f(v), f(b) + ' × (1 + ' + f(a) + ' ÷ 100)'];
                rows.push(['Прибавка', f(v - b)]);
                break;
            case 'sub':
                v = b * (1 - a / 100);
                res = [v, '', 'Получится ' + f(v), f(b) + ' × (1 − ' + f(a) + ' ÷ 100)'];
                rows.push(['Уменьшение', f(b - v)]);
                break;
            case 'whole':
                if (b === 0) return null;
                v = a / b * 100;
                res = [v, '', 'Число — ' + f(v), f(a) + ' ÷ ' + f(b) + ' × 100'];
                rows.push(['Остальные ' + f(100 - b) + ' %', f(v - a)]);
                break;
            case 'markup':
                price = a * (1 + b / 100);
                profit = price - a;
                res = [price, '', 'Цена продажи ' + f(price), f(a) + ' × (1 + ' + f(b) + ' ÷ 100)'];
                rows.push(['Прибыль с единицы', f(profit)]);
                rows.push(['Маржа (доля прибыли в цене)', price !== 0 ? f(profit / price * 100) + ' %' : '—']);
                break;
            case 'margin':
                if (b >= 100) return null;
                price = a / (1 - b / 100);
                profit = price - a;
                res = [price, '', 'Цена продажи ' + f(price), f(a) + ' ÷ (1 − ' + f(b) + ' ÷ 100)'];
                rows.push(['Прибыль с единицы', f(profit)]);
                rows.push(['Наценка на себестоимость', a !== 0 ? f(profit / a * 100) + ' %' : '—']);
                break;
            case 'price':
                if (a === 0 || b === 0) return null;
                var markup = (b - a) / a * 100, margin = (b - a) / b * 100;
                res = [markup, '%', 'Наценка ' + f(markup) + ' %, маржа ' + f(margin) + ' %', '(' + f(b) + ' − ' + f(a) + ') ÷ ' + f(a) + ' × 100'];
                rows.push(['Маржа (от цены продажи)', f(margin) + ' %']);
                rows.push(['Прибыль с единицы', f(b - a)]);
                break;
            case 'discount':
                v = a * (1 - b / 100);
                res = [v, '', 'Цена со скидкой ' + f(v), f(a) + ' × (1 − ' + f(b) + ' ÷ 100)'];
                rows.push(['Экономия', f(a - v)]);
                break;
            default:
                return null;
        }
        return { mode: mode, value: round(res[0]), main: f(res[0]), unit: res[1], headline: res[2], formula: res[3], rows: rows };
    }

    window.NJ518 = { calculate: calculate, fmt: fmt, parseNumber: parseNumber };

    // ---------- интерфейс ----------

    var $ = function (id) { return document.getElementById(id); };
    var inA = $('pc-a'), inB = $('pc-b');
    if (!inA || !inB) return;

    var ON = ['is-on'];
    var OFF = [];

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }

    function phrase() {
        var m = MODES[state.mode];
        var a = inA.value.trim() || '…', b = inB.value.trim() || '…';
        $('pc-phrase').textContent = m.tpl.replace('{a}', a).replace('{b}', b);
    }

    function render() {
        var r = calculate(state.mode, inA.value, inB.value);
        phrase();
        var copy = $('pc-copy');
        if (r === null) {
            var filled = inA.value.trim() !== '' && inB.value.trim() !== '';
            $('pc-main').textContent = '—';
            $('pc-headline').textContent = filled
                ? 'Не получается посчитать: проверьте числа (деление на ноль и маржа от 100 % невозможны)'
                : 'Введите оба числа — результат появится сразу';
            $('pc-formula').textContent = '—';
            $('pc-rows').innerHTML = '';
            copy.disabled = true; copy.__text = '';
        } else {
            $('pc-main').textContent = r.main + (r.unit ? ' ' + r.unit : '');
            $('pc-headline').textContent = r.headline;
            $('pc-formula').textContent = r.formula;
            $('pc-rows').innerHTML = r.rows.map(function (row) {
                return '<div class="flex items-center justify-between py-1.5"><dt class="text-gray-600 dark:text-gray-300">' + esc(row[0]) +
                    '</dt><dd class="font-semibold text-gray-900 dark:text-white">' + esc(row[1]) + '</dd></div>';
            }).join('');
            copy.disabled = false;
            copy.__text = r.headline + '\n' + r.formula + '\n' + r.rows.map(function (x) { return x[0] + ': ' + x[1]; }).join('\n');
        }
        syncUrl();
    }

    function syncUrl() {
        if (!window.history || !history.replaceState) return;
        var q = new URLSearchParams();
        q.set('m', state.mode);
        if (inA.value.trim()) q.set('a', inA.value.trim());
        if (inB.value.trim()) q.set('b', inB.value.trim());
        history.replaceState(null, '', location.pathname + '?' + q.toString());
    }

    function setMode(mode, keepValues) {
        state.mode = mode;
        var m = MODES[mode];
        document.querySelectorAll('.pc-mode').forEach(function (btn) {
            var on = btn.getAttribute('data-mode') === mode;
            btn.setAttribute('aria-checked', on ? 'true' : 'false');
            (on ? OFF : ON).forEach(function (c) { btn.classList.remove(c); });
            (on ? ON : OFF).forEach(function (c) { btn.classList.add(c); });
        });
        $('pc-a-label').textContent = m.a;
        $('pc-b-label').textContent = m.b;
        inA.placeholder = m.ex[0];
        inB.placeholder = m.ex[1];
        if (!keepValues) { inA.value = ''; inB.value = ''; }
        render();
    }

    document.querySelectorAll('.pc-mode').forEach(function (btn) {
        btn.addEventListener('click', function () { setMode(btn.getAttribute('data-mode'), false); inA.focus(); });
    });
    inA.addEventListener('input', render);
    inB.addEventListener('input', render);
    $('pc-example').addEventListener('click', function () {
        var ex = MODES[state.mode].ex;
        inA.value = ex[0]; inB.value = ex[1]; render();
    });
    $('pc-swap').addEventListener('click', function () {
        var t = inA.value; inA.value = inB.value; inB.value = t; render();
    });

    function toast(m) { if (window.N2J && N2J.toast) N2J.toast(m); }
    $('pc-copy').addEventListener('click', function () {
        var t = this.__text;
        if (t && window.N2J) N2J.copyText(t).then(function () { toast('Скопировано'); });
    });
    $('pc-share').addEventListener('click', function () {
        if (window.N2J) N2J.copyText(location.href).then(function () { toast('Ссылка скопирована'); });
    });

    setMode(state.mode, true);
})();
</script>
