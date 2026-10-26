<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JS калькулятора пеней (nj505_penalty).
 * Истина — PHP (Service_Nj505_Penalty); JS отправляет форму в API и рисует ответ.
 *
 * @var array $form
 */
?>
<script>
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }

    var NBSP = '\u00A0';
    var MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    var TITLES = { tax: 'Пени по налогам', gk395: 'Проценты по ст. 395 ГК РФ', contract: 'Договорная неустойка' };

    function fmt(kop) {
        var neg = kop < 0; kop = Math.abs(kop);
        var r = Math.floor(kop / 100), c = kop % 100;
        var s = String(r).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
        s += ',' + (c < 10 ? '0' : '') + c;
        return (neg ? '\u2212' : '') + s;
    }
    function rub(kop) { return fmt(kop) + NBSP + '\u20BD'; }
    function pct(x) { return String(x).replace('.', ',') + NBSP + '%'; }
    function human(ymd) { var p = ymd.split('-'); return +p[2] + ' ' + MONTHS[+p[1] - 1] + ' ' + p[0]; }
    function plural(n, a, b, c) { var m10 = n % 10, m100 = n % 100; return (m10 === 1 && m100 !== 11) ? a : (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) ? b : c; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

    var state = { mode: <?php echo json_encode($form['mode']); ?> };
    var timer = null, seq = 0;

    function read() {
        return {
            mode: state.mode, amount: $('pen-amount').value.trim(), due: $('pen-due').value, paid: $('pen-paid').value,
            payer: $('pen-payer').value, incl: $('pen-incl').checked ? '1' : '0', moratorium: $('pen-mor').checked ? '1' : '0',
            rate: $('pen-rate').value.trim(), cap: $('pen-cap').value.trim()
        };
    }

    function shareQuery(f) {
        var q = [];
        if (f.mode !== 'tax') q.push('m=' + f.mode);
        q.push('a=' + encodeURIComponent(f.amount.replace(/[\s\u00A0]/g, '')));
        if (f.due) q.push('f=' + f.due);
        if (f.paid) q.push('t=' + f.paid);
        if (f.mode === 'tax' && f.payer === 'person') q.push('p=person');
        if (f.mode === 'contract') { if (f.rate) q.push('r=' + encodeURIComponent(f.rate)); if (f.cap) q.push('c=' + encodeURIComponent(f.cap)); }
        if (f.incl === '0') q.push('i=0');
        if (f.moratorium === '0') q.push('mor=0');
        return '/penalty?' + q.join('&');
    }

    function applyMode() {
        document.querySelectorAll('.pen-mode').forEach(function (b) {
            var on = b.getAttribute('data-mode') === state.mode;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            ['is-on'].forEach(function (c) { b.classList.toggle(c, on); });
            [].forEach(function (c) { b.classList.toggle(c, !on); });
        });
        document.querySelectorAll('.pen-opt').forEach(function (el) {
            el.classList.toggle('hidden', (' ' + el.getAttribute('data-for') + ' ').indexOf(' ' + state.mode + ' ') < 0);
        });
    }

    function render(r) {
        var h = '<div class="n2j-card">';
        h += '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">' + esc(TITLES[r.mode]) + '</p>';
        h += '<p class="mt-1 text-3xl font-extrabold text-gray-900 dark:text-white sm:text-4xl">' + esc(rub(r.total_kop)) + '</p>';
        h += '<p class="mt-1 text-sm text-gray-600 dark:text-gray-300">' + r.days + ' ' + plural(r.days, 'день', 'дня', 'дней') + ' просрочки: с ' + esc(r.first_human) + ' по ' + esc(r.last_human) +
            (r.excluded ? ', без ' + r.excluded + ' ' + plural(r.excluded, 'дня', 'дней', 'дней') + ' моратория' : '') + '</p>';
        h += '<p class="mt-3 text-sm text-gray-700 dark:text-gray-200">Долг ' + esc(rub(r.amount_kop)) + ' + ' + (r.mode === 'contract' ? 'неустойка' : 'пени') + ' ' + esc(rub(r.total_kop)) +
            ' = <b>' + esc(rub(r.sum_with_kop)) + '</b></p>';

        // таблица периодов
        h += '<div class="mt-4 overflow-x-auto"><table class="n2j-table min-w-[520px] text-right"><thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400"><tr>' +
            '<th class="py-2 pr-3 text-left">Период</th><th class="py-2 pr-3">Дней</th><th class="py-2 pr-3">' + (r.mode === 'contract' ? '% в день' : 'Ставка ЦБ') + '</th>' +
            '<th class="py-2 pr-3">' + (r.mode === 'contract' ? '' : 'Доля') + '</th><th class="py-2">Сумма</th></tr></thead>' +
            '<tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-200">';
        r.rows.forEach(function (row) {
            h += '<tr><td class="py-1.5 pr-3 text-left">' + esc(human(row.from)) + (row.to !== row.from ? ' — ' + esc(human(row.to)) : '') + '</td>' +
                '<td class="py-1.5 pr-3">' + row.days + '</td><td class="py-1.5 pr-3">' + esc(pct(row.rate)) + '</td>' +
                '<td class="py-1.5 pr-3">' + (r.mode === 'contract' ? '' : esc(row.den_text)) + '</td><td class="py-1.5 font-semibold">' + fmt(row.kop) + '</td></tr>';
        });
        h += '</tbody><tfoot class="border-t-2 border-gray-300 font-bold text-gray-900 dark:border-gray-600 dark:text-white"><tr><td class="py-2 pr-3 text-left">Итого</td><td class="py-2 pr-3">' + r.days + '</td><td></td><td></td><td class="py-2">' + fmt(r.accrued_kop) + '</td></tr></tfoot></table></div>';

        if (r.capped) {
            h += '<p class="mt-2 text-sm text-gray-700 dark:text-gray-200">Начислено по ставке ' + esc(rub(r.accrued_kop)) + ', ограничено потолком — <b>' + esc(rub(r.total_kop)) + '</b>.</p>';
        }

        // сравнение для неустойки
        if (r.mode === 'contract' && r.refs && r.refs.gk395_kop !== undefined) {
            var verdict = r.total_kop > r.refs.double_key_kop
                ? 'Неустойка выше двойной ключевой ставки — суд может уменьшить её по ст. 333 ГК РФ.'
                : (r.total_kop < r.refs.gk395_kop ? 'Неустойка ниже процентов по ст. 395 ГК — кредитор вправе требовать разницу, если договор это не исключает.' : 'Неустойка в «коридоре» между ст. 395 ГК и двойной ключевой ставкой.');
            h += '<div class="mt-4 grid gap-3 sm:grid-cols-3">' +
                '<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/50"><p class="text-xs text-gray-500 dark:text-gray-400">Ваша неустойка</p><p class="mt-0.5 font-bold text-gray-900 dark:text-white">' + esc(rub(r.total_kop)) + '</p></div>' +
                '<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/50"><p class="text-xs text-gray-500 dark:text-gray-400">По ст. 395 ГК (ключевая ставка)</p><p class="mt-0.5 font-bold text-gray-900 dark:text-white">' + esc(rub(r.refs.gk395_kop)) + '</p></div>' +
                '<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/50"><p class="text-xs text-gray-500 dark:text-gray-400">По двойной ключевой ставке</p><p class="mt-0.5 font-bold text-gray-900 dark:text-white">' + esc(rub(r.refs.double_key_kop)) + '</p></div></div>' +
                '<p class="mt-2 text-xs text-gray-600 dark:text-gray-300"><i class="fas fa-circle-info mr-1.5"></i>' + esc(verdict) + '</p>';
        }

        if (r.warnings && r.warnings.length) {
            h += '<ul class="mt-4 space-y-1.5 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">' +
                r.warnings.map(function (w) { return '<li><i class="fas fa-circle-info mr-1.5"></i>' + esc(w) + '</li>'; }).join('') + '</ul>';
        }

        h += '<div class="mt-4 flex flex-wrap gap-2">' +
            '<button type="button" id="pen-copy" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-copy"></i> Копировать итог</button>' +
            '<button type="button" id="pen-share" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-link"></i> Ссылка на расчёт</button></div></div>';

        $('pen-result').innerHTML = h;

        var f = read();
        $('pen-copy').addEventListener('click', function () {
            var t = TITLES[r.mode] + ': ' + rub(r.total_kop) + ' на долг ' + rub(r.amount_kop) + ' (' + r.days + ' ' + plural(r.days, 'день', 'дня', 'дней') + ' просрочки, с ' + r.first_human + ' по ' + r.last_human + '). Всего к уплате ' + rub(r.sum_with_kop) + '.';
            window.N2J.copyText(t).then(function () { window.N2J.toast('Скопировано'); });
        });
        $('pen-share').addEventListener('click', function () {
            window.N2J.copyText(location.origin + shareQuery(f)).then(function () { window.N2J.toast('Ссылка скопирована'); });
        });
    }

    function hint(msg) {
        $('pen-result').innerHTML = '<div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400"><i class="fas fa-circle-info mr-2"></i>' + esc(msg) + '</div>';
    }
    function renderError(msg) {
        $('pen-result').innerHTML = '<div class="n2j-flash n2j-flash-err block"><i class="fas fa-triangle-exclamation mr-2"></i>' + esc(msg) + '</div>';
    }

    function calc() {
        var f = read();
        history.replaceState(null, '', shareQuery(f));

        if (f.amount === '' || f.due === '') { hint('Введите сумму долга и срок платежа — расчёт появится сразу.'); return; }

        var my = ++seq;
        var body = new URLSearchParams({
            mode: f.mode, amount: f.amount, due: f.due, paid: f.paid, payer: f.payer, incl: f.incl,
            moratorium: f.moratorium, rate: f.rate, cap: f.cap, today: iso(new Date())
        });
        fetch('/nj505/api/calc', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (my !== seq) return;
                if (j.ok) render(j.result); else renderError(j.message || 'Не удалось посчитать');
            })
            .catch(function () { if (my === seq) renderError('Нет связи с сервером — попробуйте ещё раз.'); });
    }

    function later() { clearTimeout(timer); timer = setTimeout(calc, 250); }

    ['pen-amount', 'pen-due', 'pen-paid', 'pen-payer', 'pen-incl', 'pen-mor', 'pen-rate', 'pen-cap'].forEach(function (id) {
        $(id).addEventListener('input', later);
        $(id).addEventListener('change', later);
    });
    document.querySelectorAll('.pen-mode').forEach(function (b) {
        b.addEventListener('click', function () { state.mode = b.getAttribute('data-mode'); applyMode(); calc(); });
    });
    $('pen-example').addEventListener('click', function () {
        var t = new Date(), due = new Date(t.getFullYear(), t.getMonth(), t.getDate() - 45);
        $('pen-amount').value = '250000';
        $('pen-due').value = iso(due);
        $('pen-paid').value = '';
        calc();
    });

    applyMode();
    calc();
})();
</script>
