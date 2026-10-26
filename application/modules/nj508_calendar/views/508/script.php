<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj508_calendar: инлайн-скрипт страницы /calendar (расчёты — на сервере, Service_Nj508_Calendar) -->
<script>
/**
 * nj508_calendar — вкладки и расчёты. Сервис-истина — PHP, поэтому тут нет
 * зеркала формул: скрипт собирает параметры, вызывает POST /nj508/api/calc
 * и рисует ответ. Ошибка no_calendar (года нет в таблице) показывается
 * предупреждением с кнопкой «посчитать приблизительно».
 */
(function () {
    'use strict';

    var TODAY = <?php echo json_encode($today); ?>;
    var state = { tab: <?php echo json_encode($tab); ?>, approx: false };
    var $ = function (id) { return document.getElementById(id); };
    var result = $('cal-result');
    if (!result) return;

    var ON = ['is-on'];
    var OFF = [];

    // ---------- утилиты ----------

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }
    function num(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0'); }
    function plural(n, one, few, many) {
        n = Math.abs(n); var m10 = n % 10, m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return one;
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
        return many;
    }
    function days(n) { return num(n) + ' ' + plural(n, 'день', 'дня', 'дней'); }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function iso(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function addDays(s, n) { var p = s.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2] + n); return iso(d); }

    function card(label, value, accent, sub) {
        return '<div class="' + (accent
                ? 'rounded-xl border-2 border-blue-500 bg-blue-50/60 p-4 text-center shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20'
                : 'n2j-card p-4 text-center') + '">' +
            '<p class="text-xs font-semibold uppercase tracking-wide ' + (accent ? 'text-blue-700 dark:text-blue-300' : 'text-gray-500 dark:text-gray-400') + '">' + esc(label) + '</p>' +
            '<p class="mt-1 text-2xl font-extrabold ' + (accent ? 'text-blue-700 dark:text-blue-300' : 'text-gray-900 dark:text-white') + '">' + value + '</p>' +
            (sub ? '<p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">' + esc(sub) + '</p>' : '') + '</div>';
    }

    function grid(cols, html) {
        return '<div class="grid gap-3 ' + cols + '">' + html + '</div>';
    }

    function approxNote(r) {
        return r.approx ? '<div class="n2j-flash n2j-flash-warn mt-3 block">' +
            '<i class="fas fa-triangle-exclamation mr-1.5"></i>Приблизительно: на ' + esc((r.missing_years || []).join(', ')) +
            ' год у нас нет производственного календаря, праздники не учтены — выходными считаются только суббота и воскресенье.</div>' : '';
    }

    function copyBlock(text) {
        return '<button type="button" class="cal-copy mt-3 n2j-btn-secondary n2j-btn-sm" data-copy="' + esc(text) + '"><i class="fas fa-copy"></i> Копировать</button>' +
            ' <button type="button" class="cal-share mt-3 n2j-btn-secondary n2j-btn-sm"><i class="fas fa-link"></i> Ссылка на расчёт</button>';
    }

    // ---------- отрисовка результатов ----------

    function renderBetween(r) {
        var html = grid('sm:grid-cols-2 lg:grid-cols-4',
            card('Рабочих дней', num(r.work), true, num(r.hours) + ' ч при 40-часовой неделе') +
            card('Календарных дней', num(r.calendar), false, r.weeks + ' ' + plural(r.weeks, 'неделя', 'недели', 'недель') + (r.weeks_days ? ' и ' + days(r.weeks_days) : '')) +
            card('Выходных', num(r.weekend), false, 'суббота и воскресенье') +
            card('Праздничных', num(r.holiday), false, r.short ? 'сокращённых дней: ' + r.short : 'будни, не рабочие'));
        var text = 'С ' + r.from + ' по ' + r.to + ': рабочих дней — ' + r.work + ', календарных — ' + r.calendar + ', выходных и праздничных — ' + r.off + ', часов — ' + r.hours + '.';
        return html + (r.swapped ? '<p class="mt-2 text-xs text-gray-500">Даты были в обратном порядке — мы поменяли их местами.</p>' : '') + approxNote(r) + copyBlock(text);
    }

    function renderUntil(r) {
        if (r.is_today) {
            return '<div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-6 text-center text-xl font-extrabold text-blue-700 dark:bg-blue-900/20 dark:text-blue-300">Это сегодня 🎉</div>';
        }
        var head = (r.past ? 'Прошло ' : 'Осталось ') + days(r.abs_days);
        var html = '<div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-5 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20">' +
            '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">' + esc(r.target_human) + ', ' + esc(r.weekday) + '</p>' +
            '<p class="mt-1 text-3xl font-extrabold text-blue-700 dark:text-blue-300">' + esc(head) + '</p>' +
            '<p class="mt-1 text-sm text-gray-700 dark:text-gray-200">' + esc(r.period_text) + (r.weeks ? ' · ' + r.weeks + ' ' + plural(r.weeks, 'неделя', 'недели', 'недель') + (r.weeks_days ? ' и ' + days(r.weeks_days) : '') : '') + '</p></div>';
        if (typeof r.work === 'number') {
            html += '<div class="mt-3">' + grid('sm:grid-cols-3',
                card('Рабочих дней', num(r.work), false, num(r.hours) + ' ч при 40-часовой неделе') +
                card('Выходных и праздничных', num(r.off), false, '') +
                card('Всего часов', num(r.abs_days * 24), false, 'как будто час за часом')) + '</div>';
        } else if (r.work_error) {
            html += '<div class="mt-3">' + noCalendar(r.work_error) + '</div>';
        }
        var text = (r.past ? 'С ' : 'До ') + r.target_human + ' (' + r.weekday + ') — ' + days(r.abs_days) + (typeof r.work === 'number' ? ', из них рабочих — ' + r.work : '') + '.';
        return html + approxNote(r) + copyBlock(text);
    }

    function renderAge(r) {
        var html = '<div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-5 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20">' +
            '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">' + (r.inclusive ? 'Стаж' : 'Возраст') + ' на ' + esc(r.on_human || r.on) + '</p>' +
            '<p class="mt-1 text-3xl font-extrabold text-blue-700 dark:text-blue-300">' + esc(r.text) + '</p>' +
            '<p class="mt-1 text-sm text-gray-700 dark:text-gray-200">Дата рождения: ' + esc(r.birth_weekday) + ', ' + esc(r.birth_human) + '</p></div>';
        html += '<div class="mt-3">' + grid('sm:grid-cols-2 lg:grid-cols-4',
            card('Всего дней', num(r.days_total), false, '') +
            card('Всего недель', num(r.weeks_total), false, '') +
            card('Всего месяцев', num(r.months_total), false, '') +
            card('Всего часов', num(r.hours_total), false, '')) + '</div>';
        if (!r.inclusive) {
            html += '<div class="n2j-card mt-3 text-sm text-gray-700 dark:text-gray-200">' +
                (r.is_birthday ? '🎂 <b>Сегодня день рождения!</b> Исполняется ' + r.turns + '. ' : '🎂 Следующий день рождения (' + r.turns + ') — <b>' + esc(r.next_birthday_human) + '</b>, ' + esc(r.next_birthday_weekday) + ', через ' + days(r.days_to_birthday) + '. ') +
                '<br>🗓 ' + num(r.milestone) + '-й день жизни наступит <b>' + esc(r.milestone_human) + '</b>.</div>';
        }
        var text = (r.inclusive ? 'Стаж' : 'Возраст') + ' с ' + r.birth + ' по ' + r.on + ': ' + r.text + ' (' + days(r.days_total) + ').';
        return html + copyBlock(text);
    }

    function renderAdd(r) {
        var kindText = r.kind === 'work' ? 'рабочих дней' : 'календарных дней';
        var typeNote = '';
        if (r.day_type === 'holiday') typeNote = ' — нерабочий праздничный день (' + esc(r.day_name) + ')';
        else if (r.day_type === 'weekend') typeNote = ' — выходной';
        var html = '<div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-5 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20">' +
            '<p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">' + (r.n >= 0 ? '+' : '−') + num(Math.abs(r.n)) + ' ' + kindText + ' от ' + esc(r.from) + '</p>' +
            '<p class="mt-1 text-3xl font-extrabold text-blue-700 dark:text-blue-300">' + esc(r.human) + '</p>' +
            '<p class="mt-1 text-sm text-gray-700 dark:text-gray-200">' + esc(r.weekday) + typeNote + ' · ' + days(r.calendar_days) + ' по календарю</p></div>';
        var text = (r.n >= 0 ? '+' : '−') + Math.abs(r.n) + ' ' + kindText + ' от ' + r.from + ' = ' + r.human + ' (' + r.weekday + ').';
        return html + approxNote(r) + copyBlock(text);
    }

    function noCalendar(e) {
        return '<div class="n2j-flash n2j-flash-warn block">' +
            '<p><i class="fas fa-triangle-exclamation mr-1.5"></i>' + esc(e.message) + '</p>' +
            '<p class="mt-1 text-xs">Есть календари на: ' + esc((e.known_years || []).join(', ')) + '.</p>' +
            '<button type="button" id="cal-approx" class="n2j-btn-process n2j-btn-sm mt-3">Посчитать приблизительно</button></div>';
    }

    function renderError(e) {
        if (e && e.error === 'no_calendar') return noCalendar(e);
        return '<div class="n2j-flash n2j-flash-err block">' + esc((e && e.message) || 'Не удалось посчитать') + '</div>';
    }

    // ---------- сбор параметров и запрос ----------

    function params() {
        switch (state.tab) {
            case 'between': return { mode: 'between', from: $('cal-b-from').value, to: $('cal-b-to').value, inclusive: $('cal-b-incl').checked ? '1' : '0' };
            case 'until':   return { mode: 'until', target: $('cal-u-target').value, today: TODAY };
            case 'age':     return { mode: 'age', birth: $('cal-a-birth').value, on: $('cal-a-on').value, inclusive: $('cal-a-incl').checked ? '1' : '0' };
            case 'add':     return { mode: 'add', from: $('cal-d-from').value, n: $('cal-d-n').value, kind: $('cal-d-kind').value };
        }
        return null;
    }

    function ready(p) {
        if (!p) return false;
        if (p.mode === 'between') return !!(p.from && p.to);
        if (p.mode === 'until') return !!p.target;
        if (p.mode === 'age') return !!(p.birth && p.on);
        if (p.mode === 'add') return !!(p.from && p.n !== '');
        return false;
    }

    var seq = 0, timer = null;

    function run() {
        var p = params();
        syncUrl(p);
        if (!ready(p)) { result.innerHTML = ''; return; }
        if (state.approx) p.approx = '1';
        var my = ++seq;
        fetch('/nj508/api/calc', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(p) })
            .then(function (res) { return res.json(); })
            .then(function (d) {
                if (my !== seq) return;
                if (!d.ok) { result.innerHTML = renderError(d); return; }
                var r = d.result;
                result.innerHTML = ({ between: renderBetween, until: renderUntil, age: renderAge, add: renderAdd })[p.mode](r);
            })
            .catch(function () { if (my === seq) result.innerHTML = renderError({ message: 'Нет связи с сервером — попробуйте ещё раз.' }); });
    }

    function schedule() { state.approx = false; clearTimeout(timer); timer = setTimeout(run, 200); }

    function syncUrl(p) {
        if (!window.history || !history.replaceState) return;
        var q = new URLSearchParams();
        q.set('m', state.tab);
        if (p) Object.keys(p).forEach(function (k) {
            if (k === 'mode' || k === 'today' || k === 'approx') return;
            if (p[k] !== '' && p[k] != null) q.set(k, p[k]);
        });
        history.replaceState(null, '', '/calendar?' + q.toString());
    }

    // ---------- вкладки ----------

    function setTab(tab, first) {
        state.tab = tab; state.approx = false;
        document.querySelectorAll('.cal-tab').forEach(function (b) {
            var on = b.getAttribute('data-tab') === tab;
            b.setAttribute('aria-selected', on ? 'true' : 'false');
            (on ? OFF : ON).forEach(function (c) { b.classList.remove(c); });
            (on ? ON : OFF).forEach(function (c) { b.classList.add(c); });
        });
        var isYear = tab === 'year';
        $('cal-year').classList.toggle('hidden', !isYear);
        $('cal-forms').classList.toggle('hidden', isYear);
        result.classList.toggle('hidden', isYear);
        document.querySelectorAll('.cal-form').forEach(function (f) {
            f.classList.toggle('hidden', f.getAttribute('data-form') !== tab);
        });
        if (isYear) {
            if (!first && window.history && history.replaceState) history.replaceState(null, '', location.pathname.indexOf('/calendar/') === 0 ? location.pathname : '/calendar');
            return;
        }
        run();
    }

    document.querySelectorAll('.cal-tab').forEach(function (b) {
        b.addEventListener('click', function () { setTab(b.getAttribute('data-tab'), false); });
    });
    ['cal-b-from', 'cal-b-to', 'cal-b-incl', 'cal-u-target', 'cal-a-birth', 'cal-a-on', 'cal-a-incl', 'cal-d-from', 'cal-d-n', 'cal-d-kind'].forEach(function (id) {
        var el = $(id);
        if (el) { el.addEventListener('input', schedule); el.addEventListener('change', schedule); }
    });

    // ---------- пресеты ----------

    function monthRange(y, m) { return [iso(new Date(y, m, 1)), iso(new Date(y, m + 1, 0))]; }
    document.querySelectorAll('.cal-preset').forEach(function (b) {
        b.addEventListener('click', function () {
            var k = b.getAttribute('data-preset'), t = new Date(), y = t.getFullYear(), m = t.getMonth(), r;
            if (k === 'month') { r = monthRange(y, m); $('cal-b-from').value = r[0]; $('cal-b-to').value = r[1]; }
            else if (k === 'nextmonth') { r = monthRange(y, m + 1); $('cal-b-from').value = r[0]; $('cal-b-to').value = r[1]; }
            else if (k === 'quarter') { var q = Math.floor(m / 3) * 3; $('cal-b-from').value = iso(new Date(y, q, 1)); $('cal-b-to').value = iso(new Date(y, q + 3, 0)); }
            else if (k === 'year') { $('cal-b-from').value = y + '-01-01'; $('cal-b-to').value = y + '-12-31'; }
            else if (k === 'add') { $('cal-d-n').value = b.getAttribute('data-n'); $('cal-d-kind').value = b.getAttribute('data-kind'); }
            else {
                var next = function (mm, dd) { var d = new Date(y, mm, dd); if (iso(d) <= TODAY) d = new Date(y + 1, mm, dd); return iso(d); };
                var map = { ny: [0, 1], feb23: [1, 23], mar8: [2, 8], may1: [4, 1], jun1: [5, 1], sep1: [8, 1] };
                if (map[k]) $('cal-u-target').value = next(map[k][0], map[k][1]);
            }
            schedule();
        });
    });

    // ---------- действия в результате ----------

    result.addEventListener('click', function (e) {
        var t = e.target.closest('button');
        if (!t) return;
        if (t.id === 'cal-approx') { state.approx = true; clearTimeout(timer); run(); }
        else if (t.classList.contains('cal-copy') && window.N2J) {
            N2J.copyText(t.getAttribute('data-copy')).then(function () { N2J.toast('Скопировано'); });
        } else if (t.classList.contains('cal-share') && window.N2J) {
            N2J.copyText(location.href).then(function () { N2J.toast('Ссылка скопирована'); });
        }
    });

    // предзаполнение значений по умолчанию, которых нет в URL
    if (!$('cal-b-to').value) { var r0 = monthRange(new Date().getFullYear(), new Date().getMonth()); $('cal-b-to').value = r0[1]; }
    if (!$('cal-u-target').value) { var yy = new Date().getFullYear(); var d1 = new Date(yy + 1, 0, 1); $('cal-u-target').value = iso(d1); }

    setTab(state.tab, true);
})();
</script>
