<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JS калькулятора зарплаты (nj503_salary).
 * Истина — PHP (Service_Nj503_Salary): JS только рисует результат API;
 * первый расчёт приходит вместе со страницей (NJ503_INIT).
 *
 * @var array      $form
 * @var array|NULL $init
 */
?>
<script>
(function () {
    'use strict';

    var INIT = <?php echo json_encode($init, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var DEFAULT_YEAR = <?php echo (int) Service_Nj503_Salary::default_year(); ?>;
    var MONTHS_FULL = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }

    var NBSP = '\u00A0';
    /** копейки → «87 000» или «87 000,50» */
    function fmt(kop) {
        var neg = kop < 0; kop = Math.abs(kop);
        var r = Math.floor(kop / 100), c = kop % 100;
        var s = String(r).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
        if (c) s += ',' + (c < 10 ? '0' : '') + c;
        return (neg ? '\u2212' : '') + s;
    }
    function rub(kop) { return fmt(kop) + NBSP + '\u20BD'; }
    function pct(x) { return String(x).replace('.', ',') + NBSP + '%'; }

    var state = { mode: <?php echo json_encode($form['mode']); ?>, last: INIT };
    var timer = null, seq = 0;

    // ------------------------------------------------------------ чтение формы
    function read() {
        return {
            mode: state.mode,
            amount: $('sal-amount').value.trim(),
            year: $('sal-year').value,
            children: $('sal-children').value || '0',
            disabled: $('sal-disabled').value || '0',
            injury: $('sal-injury').value || '0.2',
            single: $('sal-single').checked ? '1' : '0',
            resident: $('sal-nr').checked ? '0' : '1',
            sme: $('sal-sme').checked ? '1' : '0'
        };
    }

    /** Адрес расчёта: только то, что отличается от умолчаний. */
    function shareQuery(f) {
        var q = [];
        if (f.mode === 'net') q.push('m=net');
        q.push('s=' + encodeURIComponent(f.amount.replace(/[\s\u00A0]/g, '')));
        if (+f.year !== DEFAULT_YEAR) q.push('y=' + f.year);
        if (+f.children) q.push('c=' + f.children);
        if (+f.disabled) q.push('d=' + f.disabled);
        if (f.single === '1') q.push('single=1');
        if (f.resident === '0') q.push('nr=1');
        if (f.sme === '1') q.push('sme=1');
        if (parseFloat(f.injury) !== 0.2) q.push('i=' + f.injury);
        return '/salary?' + q.join('&');
    }

    // ------------------------------------------------------------ отрисовка
    function card(title, value, sub, tone) {
        var tones = {
            green: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/40 dark:text-emerald-300',
            rose:  'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-300',
            amber: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-300',
            blue:  'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/60 dark:bg-blue-950/40 dark:text-blue-300'
        };
        return '<div class="rounded-xl border p-4 ' + tones[tone] + '">' +
            '<p class="text-xs font-semibold uppercase tracking-wide opacity-80">' + esc(title) + '</p>' +
            '<p class="mt-1 text-xl font-extrabold leading-tight sm:text-2xl">' + value + '</p>' +
            '<p class="mt-1 text-xs opacity-80">' + sub + '</p></div>';
    }

    function render(r) {
        var box = $('sal-result');
        var f = read();
        var h = '';
        var y = r.year_total;

        var head = r.mode === 'net'
            ? '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Чтобы получать ' + esc(rub(r.input_kop)) + ' на руки</p>' +
              '<p class="mt-1 text-3xl font-extrabold text-gray-900 dark:text-white sm:text-4xl">Оклад ' + esc(rub(r.gross_kop)) + '</p>' +
              '<p class="mt-1 text-sm text-gray-600 dark:text-gray-300">до вычета налога' + (r.exact ? '' : ' (ближайшая сумма: из-за округления НДФЛ до рубля ровно столько нельзя получить)') + '</p>'
            : '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Оклад ' + esc(rub(r.gross_kop)) + ' в месяц, ' + r.year + ' год</p>' +
              '<p class="mt-1 text-3xl font-extrabold text-gray-900 dark:text-white sm:text-4xl">На руки ' + esc(rub(r.net_kop)) + '</p>' +
              '<p class="mt-1 text-sm text-gray-600 dark:text-gray-300">в первый месяц; в среднем за год — ' + esc(rub(r.net_avg_kop)) + ' в месяц</p>';

        h += '<div class="n2j-card">' + head;

        h += '<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">' +
            card('На руки', esc(rub(r.net_kop)), 'за первый месяц', 'green') +
            card('НДФЛ', esc(rub(r.ndfl_kop)), 'ставка ' + esc(pct(r.ndfl_rate)) + (r.deduction_kop ? ', вычет на детей ' + esc(rub(r.deduction_kop)) : ''), 'rose') +
            card('Взносы работодателя', esc(rub(r.contrib_kop + r.injury_kop)), 'пенсионные, медицинские, соцстрах и травматизм', 'amber') +
            card('Стоимость сотрудника', esc(rub(r.cost_kop)), 'оклад + взносы в месяц', 'blue') + '</div>';

        // доли в расходах работодателя — числами, без полосы (полосы убраны везде)
        h += '<div class="mt-5"><p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Из каждых 100\u00A0\u20BD расходов работодателя за год сотрудник получает ' + esc(String(r.share_net).replace('.', ',')) + '\u00A0\u20BD</p>' +
            '<p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">' +
            '<span><i class="fas fa-circle mr-1 text-emerald-500"></i>на руки ' + esc(pct(r.share_net)) + '</span>' +
            '<span><i class="fas fa-circle mr-1 text-rose-500"></i>НДФЛ ' + esc(pct(r.share_ndfl)) + '</span>' +
            '<span><i class="fas fa-circle mr-1 text-amber-400"></i>взносы ' + esc(pct(r.share_contrib)) + '</span></p></div>';

        // предупреждения и повышение ставки
        var notes = [];
        (r.rate_steps || []).forEach(function (s) {
            var m = r.months[s.m - 1], prev = r.months[s.m - 2];
            notes.push('С месяца «' + esc(MONTHS_FULL[s.m - 1]) + '» ставка НДФЛ вырастает до ' + esc(pct(s.rate)) + ': на руки будет ' + esc(rub(m.net)) + ' вместо ' + esc(rub(prev.net)) + '.');
        });
        (r.warnings || []).forEach(function (w) { notes.push(esc(w)); });
        if (notes.length) {
            h += '<ul class="mt-4 space-y-1.5 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">' +
                notes.map(function (n) { return '<li><i class="fas fa-circle-info mr-1.5"></i>' + n + '</li>'; }).join('') + '</ul>';
        }

        h += '<div class="mt-4 flex flex-wrap gap-2">' +
            '<button type="button" id="sal-copy" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-copy"></i> Копировать итог</button>' +
            '<button type="button" id="sal-share" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-link"></i> Ссылка на расчёт</button></div>';
        h += '</div>';

        // таблица месяцев
        h += '<div class="n2j-card mt-4">' +
            '<h2 class="n2j-h3">Помесячно за ' + r.year + ' год</h2>' +
            '<div class="mt-3 overflow-x-auto"><table class="n2j-table min-w-[620px] text-right">' +
            '<thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400"><tr>' +
            '<th class="py-2 pr-3 text-left">Месяц</th><th class="py-2 pr-3">До налога</th><th class="py-2 pr-3">Вычет</th><th class="py-2 pr-3">НДФЛ</th><th class="py-2 pr-3">На руки</th><th class="py-2 pr-3">Взносы</th><th class="py-2">Ставка</th></tr></thead>' +
            '<tbody class="divide-y divide-gray-100 text-gray-700 dark:divide-gray-700 dark:text-gray-200">';
        r.months.forEach(function (m, i) {
            var bump = i > 0 && m.rate > r.months[i - 1].rate;
            h += '<tr' + (bump ? ' class="bg-amber-50/70 dark:bg-amber-950/20"' : '') + '><td class="py-1.5 pr-3 text-left font-semibold">' + esc(m.name) + '</td>' +
                '<td class="py-1.5 pr-3">' + fmt(m.gross) + '</td>' +
                '<td class="py-1.5 pr-3">' + (m.deduction ? fmt(m.deduction) : '\u2014') + '</td>' +
                '<td class="py-1.5 pr-3">' + fmt(m.ndfl) + '</td>' +
                '<td class="py-1.5 pr-3 font-semibold text-emerald-700 dark:text-emerald-300">' + fmt(m.net) + '</td>' +
                '<td class="py-1.5 pr-3">' + fmt(m.contrib + m.injury) + '</td>' +
                '<td class="py-1.5">' + esc(pct(m.rate)) + '</td></tr>';
        });
        h += '</tbody><tfoot class="border-t-2 border-gray-300 font-bold text-gray-900 dark:border-gray-600 dark:text-white"><tr>' +
            '<td class="py-2 pr-3 text-left">Год</td><td class="py-2 pr-3">' + fmt(y.gross) + '</td><td class="py-2 pr-3">' + (y.deduction ? fmt(y.deduction) : '\u2014') + '</td>' +
            '<td class="py-2 pr-3">' + fmt(y.ndfl) + '</td><td class="py-2 pr-3">' + fmt(y.net) + '</td><td class="py-2 pr-3">' + fmt(y.contrib + y.injury) + '</td>' +
            '<td class="py-2">' + esc(pct(r.ndfl_effective)) + '</td></tr></tfoot></table></div>' +
            '<p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Последний столбец «Год» — эффективная ставка НДФЛ за год. Стоимость сотрудника за год: ' + esc(rub(y.cost)) + '.</p></div>';

        box.innerHTML = h;
        bindResult(r, f);
    }

    function renderError(msg) {
        $('sal-result').innerHTML = '<div class="n2j-flash n2j-flash-err block"><i class="fas fa-triangle-exclamation mr-2"></i>' + esc(msg) + '</div>';
    }

    function bindResult(r, f) {
        var copy = $('sal-copy'), share = $('sal-share');
        if (copy) copy.addEventListener('click', function () {
            var t = (r.mode === 'net'
                ? 'Чтобы получать ' + rub(r.input_kop) + ' на руки, оклад до налога — ' + rub(r.gross_kop)
                : 'Оклад ' + rub(r.gross_kop) + ' → на руки ' + rub(r.net_kop)) +
                ' (НДФЛ ' + rub(r.ndfl_kop) + ', ' + r.year + ' г.). Стоимость сотрудника для работодателя ' + rub(r.cost_kop) + ' в месяц.';
            window.N2J.copyText(t).then(function () { window.N2J.toast('Скопировано'); });
        });
        if (share) share.addEventListener('click', function () {
            window.N2J.copyText(location.origin + shareQuery(f)).then(function () { window.N2J.toast('Ссылка скопирована'); });
        });
    }

    // ------------------------------------------------------------ запрос
    function calc() {
        var f = read();
        history.replaceState(null, '', shareQuery(f));

        if (f.amount === '') { $('sal-result').innerHTML = ''; state.last = null; return; }

        var my = ++seq;
        var body = new URLSearchParams({
            amount: f.amount, mode: f.mode, year: f.year, children: f.children, disabled: f.disabled,
            injury: f.injury, single: f.single, resident: f.resident, sme: f.sme
        });
        fetch('/nj503/api/calc', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (j) {
                if (my !== seq) return;
                if (j.ok) { state.last = j.result; render(j.result); }
                else { state.last = null; renderError(j.message || 'Не удалось посчитать'); }
            })
            .catch(function () { if (my === seq) renderError('Нет связи с сервером — попробуйте ещё раз.'); });
    }

    function later() { clearTimeout(timer); timer = setTimeout(calc, 250); }

    // ------------------------------------------------------------ события
    ['sal-amount', 'sal-year', 'sal-children', 'sal-disabled', 'sal-injury', 'sal-single', 'sal-nr', 'sal-sme'].forEach(function (id) {
        $(id).addEventListener('input', later);
        $(id).addEventListener('change', later);
    });

    function setMode(mode, carry) {
        state.mode = mode;
        document.querySelectorAll('.sal-mode').forEach(function (b) {
            var on = b.getAttribute('data-mode') === mode;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            var onCls = ['is-on'];
            var offCls = [];
            onCls.forEach(function (c) { b.classList.toggle(c, on); });
            offCls.forEach(function (c) { b.classList.toggle(c, !on); });
        });
        $('sal-amount-label').textContent = mode === 'net' ? 'Нужно получать на руки, ₽ в месяц' : 'Оклад до вычета налога, ₽ в месяц';

        // переключение «туда-обратно»: подставляем найденную сумму, чтобы сразу видеть обратную задачу
        if (carry && state.last) {
            var kop = mode === 'net' ? state.last.net_kop : state.last.gross_kop;
            $('sal-amount').value = (kop / 100).toFixed(2).replace(/\.00$/, '');
        }
        calc();
    }

    document.querySelectorAll('.sal-mode').forEach(function (b) {
        b.addEventListener('click', function () { if (b.getAttribute('data-mode') !== state.mode) setMode(b.getAttribute('data-mode'), true); });
    });
    document.querySelectorAll('.sal-preset').forEach(function (b) {
        b.addEventListener('click', function () { $('sal-amount').value = b.getAttribute('data-amount'); calc(); });
    });

    if (INIT) render(INIT);
    else if ($('sal-amount').value.trim() !== '') calc();
})();
</script>
