<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JS калькулятора отпуска (nj504_vacation).
 * Истина — PHP (Service_Nj504_Vacation); JS шлёт форму в API и рисует ответ.
 *
 * @var string $tab
 * @var string $today
 */
?>
<script>
(function () {
    'use strict';

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }

    var NBSP = '\u00A0';
    var MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
    function fmt(kop) {
        var neg = kop < 0; kop = Math.abs(kop);
        var r = Math.floor(kop / 100), c = kop % 100;
        var s = String(r).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
        s += ',' + (c < 10 ? '0' : '') + c;
        return (neg ? '\u2212' : '') + s;
    }
    function rub(kop) { return fmt(kop) + NBSP + '\u20BD'; }
    function plural(n, a, b, c) { var m10 = n % 10, m100 = n % 100; return (m10 === 1 && m100 !== 11) ? a : (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) ? b : c; }
    function days(n) { return n + NBSP + plural(n, 'день', 'дня', 'дней'); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }

    var state = { tab: <?php echo json_encode($tab); ?> };
    var timer = null, seq = 0;

    var BTN = 'inline-flex items-center gap-2 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-700 transition hover:border-blue-400 hover:text-blue-600 dark:border-gray-600 dark:text-gray-300';
    var TAB_ON = ['is-on'];
    var TAB_OFF = [];

    // --------------------------------------------------- параметры вкладок
    function params(tab) {
        switch (tab) {
            case 'plan':  return { mode: 'plan', year: $('vac-p-year').value, days: $('vac-p-days').value };
            case 'dates': return { mode: 'dates', start: $('vac-d-start').value, days: $('vac-d-days').value };
            case 'pay':   return { mode: 'pay', income: $('vac-y-income').value.trim(), months: $('vac-y-months').value, days: $('vac-y-days').value, rate: $('vac-y-rate').value };
            default:      return { mode: 'compensation', hire: $('vac-c-hire').value, dismiss: $('vac-c-end').value, annual: $('vac-c-annual').value,
                                   used: $('vac-c-used').value.trim() || '0', income: $('vac-c-income').value.trim(), months: $('vac-c-months').value, rate: $('vac-c-rate').value };
        }
    }

    function ready(tab, p) {
        if (tab === 'plan') return p.days !== '';
        if (tab === 'dates') return p.start !== '' && p.days !== '';
        if (tab === 'pay') return p.income !== '' && p.days !== '';
        return p.hire !== '' && p.dismiss !== '' && p.income !== '';
    }
    var HINTS = {
        plan: 'Укажите число дней отпуска.',
        dates: 'Выберите дату начала и число дней — покажем, когда отпуск закончится.',
        pay: 'Введите доход за последние 12 месяцев (или зарплату в месяц) и число дней отпуска.',
        comp: 'Укажите даты приёма и увольнения и доход за 12 месяцев.'
    };

    function shareQuery(tab, p) {
        var q = ['m=' + tab];
        var add = function (k, v) { if (v !== '' && v !== undefined && v !== null) q.push(k + '=' + encodeURIComponent(String(v).replace(/[\s\u00A0]/g, ''))); };
        if (tab === 'plan') { add('y', p.year); add('d', p.days); }
        else if (tab === 'dates') { add('s', p.start); add('d', p.days); }
        else if (tab === 'pay') { add('a', p.income); add('n', p.months); add('d', p.days); add('r', p.rate); }
        else { add('h', p.hire); add('e', p.dismiss); add('an', p.annual); add('u', p.used); add('a', p.income); add('n', p.months); add('r', p.rate); }
        return '/vacation?' + q.join('&');
    }

    // --------------------------------------------------- отрисовка
    function shell(title, big, sub, inner, copyText, shareUrl) {
        var h = '<div class="n2j-card">' +
            '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">' + esc(title) + '</p>' +
            '<p class="mt-1 text-3xl font-extrabold text-gray-900 dark:text-white sm:text-4xl">' + big + '</p>' +
            (sub ? '<p class="mt-1 text-sm text-gray-600 dark:text-gray-300">' + sub + '</p>' : '') + inner;
        h += '<div class="mt-4 flex flex-wrap gap-2"><button type="button" id="vac-copy" class="' + BTN + '"><i class="fas fa-copy"></i> Копировать итог</button>' +
            '<button type="button" id="vac-share" class="' + BTN + '"><i class="fas fa-link"></i> Ссылка на расчёт</button></div></div>';
        $('vac-result').innerHTML = h;
        $('vac-copy').addEventListener('click', function () { window.N2J.copyText(copyText).then(function () { window.N2J.toast('Скопировано'); }); });
        $('vac-share').addEventListener('click', function () { window.N2J.copyText(location.origin + shareUrl).then(function () { window.N2J.toast('Ссылка скопирована'); }); });
    }

    function notes(list) {
        if (!list || !list.length) return '';
        return '<ul class="mt-4 space-y-1.5 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">' +
            list.map(function (w) { return '<li><i class="fas fa-circle-info mr-1.5"></i>' + esc(w) + '</li>'; }).join('') + '</ul>';
    }
    function stat(label, value, sub) {
        return '<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/50"><p class="text-xs text-gray-500 dark:text-gray-400">' + esc(label) + '</p>' +
            '<p class="mt-0.5 text-base font-bold text-gray-900 dark:text-white">' + value + '</p>' + (sub ? '<p class="text-xs text-gray-500 dark:text-gray-400">' + sub + '</p>' : '') + '</div>';
    }

    function renderPlan(r, p) {
        var inner = '<div class="mt-4 grid gap-3 sm:grid-cols-2">';
        r.top.forEach(function (w, i) {
            inner += '<div class="rounded-xl border ' + (i === 0 ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30' : 'border-gray-200 dark:border-gray-700') + ' p-4">' +
                (i === 0 ? '<p class="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300"><i class="fas fa-trophy mr-1"></i>Лучший вариант</p>' : '') +
                '<p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">' + esc(w.start_human) + ' — ' + esc(w.end_human) + '</p>' +
                '<p class="mt-1 text-sm text-gray-700 dark:text-gray-200">Отдыхаете подряд <b>' + days(w.rest.len) + '</b> (' + esc(w.rest.from_human) + ' — ' + esc(w.rest.to_human) + ')</p>' +
                '<p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Бонус <b>+' + w.bonus + '</b> ' + plural(w.bonus, 'день', 'дня', 'дней') + ' сверх ' + r.days + ' дней отпуска; пропускаете рабочих дней: ' + w.work_days + '</p>' +
                '<button type="button" class="vac-open n2j-link mt-2 text-xs" data-start="' + esc(w.start) + '" data-days="' + r.days + '">Показать даты и выход на работу →</button></div>';
        });
        inner += '</div>';

        inner += '<h3 class="n2j-h3 mt-5">Лучшее начало отпуска по месяцам</h3>' +
            '<div class="mt-2 overflow-x-auto"><table class="n2j-table min-w-[480px]"><thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400"><tr>' +
            '<th class="py-2 pr-3">Месяц</th><th class="py-2 pr-3">Начало</th><th class="py-2 pr-3">Подряд</th><th class="py-2">Бонус</th></tr></thead>' +
            '<tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-200">';
        r.by_month.forEach(function (w) {
            inner += '<tr><td class="py-1.5 pr-3 font-semibold">' + MONTHS[w.month - 1] + '</td><td class="py-1.5 pr-3">' + esc(w.start_human) + '</td><td class="py-1.5 pr-3">' + days(w.rest.len) + '</td><td class="py-1.5 font-semibold text-emerald-700 dark:text-emerald-300">+' + w.bonus + '</td></tr>';
        });
        inner += '</tbody></table></div>' + notes(r.warnings);

        var best = r.top[0];
        shell('Выгодные даты отпуска на ' + r.year + ' год', esc(best.start_human.replace(/, .*/, '')) + ' — ' + days(best.rest.len) + ' подряд',
            'за ' + days(r.days) + ' отпуска (лучший вариант)', inner,
            'Выгодный отпуск ' + r.days + ' дн. в ' + r.year + ': с ' + best.start_human + ' по ' + best.end_human + ' — ' + best.rest.len + ' дней подряд без работы.', shareQuery('plan', p));

        document.querySelectorAll('.vac-open').forEach(function (b) {
            b.addEventListener('click', function () {
                $('vac-d-start').value = b.getAttribute('data-start');
                $('vac-d-days').value = b.getAttribute('data-days');
                setTab('dates');
            });
        });
    }

    function renderDates(r, p) {
        var inner = '<div class="mt-4 grid gap-3 sm:grid-cols-3">' +
            stat('Последний день отпуска', esc(r.end_human)) + stat('Выход на работу', esc(r.return_human)) +
            stat('Отпускные выплатить до', esc(r.pay_before_human), 'за 3 календарных дня до начала') + '</div>' +
            '<div class="mt-3 grid gap-3 sm:grid-cols-3">' +
            stat('Длительность', days(r.span) + ' в календаре', 'оплачивается ' + days(r.days)) +
            stat('Слитный отдых', days(r.rest.len), esc(r.rest.from_human) + ' — ' + esc(r.rest.to_human)) +
            stat('Рабочих дней пропускаете', String(r.work_skipped)) + '</div>';
        if (r.holidays.length) {
            inner += '<p class="mt-4 text-sm text-gray-700 dark:text-gray-200"><i class="fas fa-star mr-1.5 text-rose-400"></i>Праздники внутри отпуска (не входят в ' + r.days + ' ' + plural(r.days, 'день', 'дня', 'дней') + ', отпуск продлён): ' +
                r.holidays.map(function (h) { return '<b>' + esc(h.human) + '</b> (' + esc(h.weekday) + ')'; }).join(', ') + '.</p>';
        } else {
            inner += '<p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Праздничных дней внутри отпуска нет — он длится ровно ' + days(r.days) + '.</p>';
        }
        inner += notes(r.warnings);
        shell('Отпуск с ' + r.start_human.replace(/, .*/, ''), 'по ' + esc(r.end_human.replace(/, .*/, '')), 'на работу — ' + esc(r.return_human), inner,
            'Отпуск ' + r.days + ' дн.: с ' + r.start_human + ' по ' + r.end_human + ', выход на работу ' + r.return_human + '.', shareQuery('dates', p));
    }

    function renderPay(r, p) {
        var inner = '<div class="mt-4 grid gap-3 sm:grid-cols-3">' +
            stat('Средний дневной заработок', esc(rub(r.avg_daily_kop)), esc(rub(r.income_kop)) + ' ÷ ' + r.months + ' ÷ 29,3') +
            stat('Начислено', esc(rub(r.gross_kop)), days(r.days)) +
            stat('НДФЛ ' + r.rate + ' %', esc(rub(r.ndfl_kop))) + '</div>' +
            '<h3 class="n2j-h3 mt-5">Сколько будет за другой срок</h3>' +
            '<div class="mt-2 overflow-x-auto"><table class="n2j-table min-w-[360px] text-right"><thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400"><tr>' +
            '<th class="py-2 pr-3 text-left">Дней</th><th class="py-2 pr-3">Начислено</th><th class="py-2">На руки</th></tr></thead><tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-200">' +
            r.table.map(function (t) { return '<tr><td class="py-1.5 pr-3 text-left font-semibold">' + t.days + '</td><td class="py-1.5 pr-3">' + fmt(t.gross_kop) + '</td><td class="py-1.5">' + fmt(t.net_kop) + '</td></tr>'; }).join('') +
            '</tbody></table></div>' + notes(r.warnings);
        shell('Отпускные за ' + days(r.days), 'На руки ' + esc(rub(r.net_kop)), 'начислено ' + esc(rub(r.gross_kop)) + ', НДФЛ ' + esc(rub(r.ndfl_kop)), inner,
            'Отпускные за ' + r.days + ' дн.: начислено ' + rub(r.gross_kop) + ', НДФЛ ' + rub(r.ndfl_kop) + ', на руки ' + rub(r.net_kop) + ' (средний дневной заработок ' + rub(r.avg_daily_kop) + ').', shareQuery('pay', p));
    }

    function renderComp(r, p) {
        var inner = '<div class="mt-4 grid gap-3 sm:grid-cols-3">' +
            stat('Отработано', r.months + ' мес. ' + r.extra_days + ' дн.', 'в расчёте — ' + r.months_rounded + ' мес.') +
            stat('Положено дней', String(r.earned_days).replace('.', ','), r.annual_days + ' ÷ 12 × ' + r.months_rounded) +
            stat('Неиспользовано', String(r.unused_days).replace('.', ','), 'к оплате — ' + days(r.paid_days)) + '</div>' +
            '<div class="mt-3 grid gap-3 sm:grid-cols-3">' +
            stat('Средний дневной заработок', esc(rub(r.avg_daily_kop))) + stat('Начислено', esc(rub(r.gross_kop))) + stat('НДФЛ ' + r.rate + ' %', esc(rub(r.ndfl_kop))) + '</div>' +
            notes(r.warnings);
        shell('Компенсация за неиспользованный отпуск', esc(rub(r.net_kop)) + ' на руки', 'начислено ' + esc(rub(r.gross_kop)) + ' за ' + days(r.paid_days), inner,
            'Компенсация за неиспользованный отпуск (' + r.paid_days + ' дн.): начислено ' + rub(r.gross_kop) + ', НДФЛ ' + rub(r.ndfl_kop) + ', на руки ' + rub(r.net_kop) + '.', shareQuery('comp', p));
    }

    var RENDER = { plan: renderPlan, dates: renderDates, pay: renderPay, comp: renderComp };

    function hint(msg) {
        $('vac-result').innerHTML = '<div class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500 dark:border-gray-600 dark:text-gray-400"><i class="fas fa-circle-info mr-2"></i>' + esc(msg) + '</div>';
    }
    function renderError(msg) {
        $('vac-result').innerHTML = '<div class="n2j-flash n2j-flash-err block"><i class="fas fa-triangle-exclamation mr-2"></i>' + esc(msg) + '</div>';
    }

    // --------------------------------------------------- запрос
    function calc() {
        var tab = state.tab, p = params(tab);
        history.replaceState(null, '', shareQuery(tab, p));
        if (!ready(tab, p)) { hint(HINTS[tab]); return; }

        var my = ++seq;
        fetch('/nj504/api/calc', { method: 'POST', body: new URLSearchParams(p) })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (my !== seq) return;
                if (j.ok) RENDER[tab](j.result, p); else renderError(j.message || 'Не удалось посчитать');
            })
            .catch(function () { if (my === seq) renderError('Нет связи с сервером — попробуйте ещё раз.'); });
    }
    function later() { clearTimeout(timer); timer = setTimeout(calc, 250); }

    function setTab(tab) {
        state.tab = tab;
        document.querySelectorAll('.vac-tab').forEach(function (b) {
            var on = b.getAttribute('data-tab') === tab;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            TAB_ON.forEach(function (c) { b.classList.toggle(c, on); });
            TAB_OFF.forEach(function (c) { b.classList.toggle(c, !on); });
        });
        document.querySelectorAll('.vac-form').forEach(function (f) {
            f.classList.toggle('hidden', f.getAttribute('data-form') !== tab);
        });
        calc();
    }

    // --------------------------------------------------- события
    document.querySelectorAll('.vac-form input, .vac-form select').forEach(function (el) {
        el.addEventListener('input', later);
        el.addEventListener('change', later);
    });
    document.querySelectorAll('.vac-tab').forEach(function (b) {
        b.addEventListener('click', function () { setTab(b.getAttribute('data-tab')); });
    });
    document.querySelectorAll('.vac-preset').forEach(function (b) {
        b.addEventListener('click', function () { $('vac-p-days').value = b.getAttribute('data-days'); calc(); });
    });
    // «или зарплата в месяц» → доход за период
    $('vac-y-monthly').addEventListener('input', function () {
        var v = parseFloat(this.value.replace(/[\s\u00A0]/g, '').replace(',', '.'));
        var m = parseInt($('vac-y-months').value, 10) || 12;
        if (!isNaN(v) && v > 0) { $('vac-y-income').value = String(Math.round(v * m * 100) / 100); later(); }
    });

    setTab(state.tab);
})();
</script>
