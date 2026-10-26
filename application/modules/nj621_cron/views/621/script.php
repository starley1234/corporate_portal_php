<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj621_cron — локальный расчёт (обращаемся к серверному API, чтобы показать «прогон через сервер»). */
(function () {
    'use strict';
    var exprEl = document.getElementById('nj621-expr');
    var out = document.getElementById('nj621-out');
    var LOCAL = false;

    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    var MONTHS = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
    var WDAYS_RU = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

    function fields(expr) {
        var p = expr.trim().split(/\s+/);
        return { minute: p[0], hour: p[1], day: p[2], month: p[3], weekday: p[4], command: p.slice(5).join(' ') };
    }

    function human(expr) {
        var f = fields(expr);
        function minute(m) {
            if (m === '*') return 'каждую минуту';
            if (/^\*\/\d+$/.test(m)) return 'каждые ' + Number(m.slice(2)) + ' минут';
            if (/^\d+-\d+$/.test(m)) { var r = m.split('-'); return 'каждую минуту с ' + Number(r[0]) + ' по ' + Number(r[1]); }
            return 'в минуту ' + m;
        }
        function month(m) {
            if (m === '*') return ' каждый месяц';
            if (/^\*\/\d+$/.test(m)) return ' каждые ' + Number(m.slice(2)) + ' месяцев';
            return ' в месяцах ' + m;
        }
        var m = minute(f.minute);
        var h = f.hour === '*' ? '' : (f.hour === '0' ? ' в полночь' : ' в ' + String(f.hour).padStart(2, '0') + ':00');
        var days = f.day === '*' ? '' : ' по дням ' + f.day;
        var wd = f.weekday === '*' ? '' : ' (' + WDAYS_RU[Number(f.weekday) % 7] + ')';
        return 'Выполняется ' + m + h + days + month(f.month) + wd + '.';
    }

    function match(field, value) {
        return field.split(',').some(function (piece) {
            piece = piece.trim();
            if (piece === '*' || piece === '') return true;
            if (/^\*\/\d+$/.test(piece)) return value % Number(piece.slice(2)) === 0;
            if (/^(\d+)-(\d+)$/.test(piece)) { var r = piece.split('-'); return value >= Number(r[0]) && value <= Number(r[1]); }
            if (/^(\d+)-(\d+)\/(\d+)$/.test(piece)) { var q = piece.split(/[-/]/); var step = Number(q[2]); return value >= Number(q[0]) && value <= Number(q[1]) && ((value - Number(q[0])) % step) === 0; }
            return Number(piece) === value;
        });
    }

    function nextRuns(expr, limit) {
        var f = fields(expr);
        var runs = [];
        var d = new Date();
        d.setSeconds(0, 0);
        var guard = 0;
        while (runs.length < limit && guard < 20 * 366 * 24 * 60) {
            d = new Date(d.getTime() + 60000);
            guard++;
            if (!match(f.minute, d.getMinutes())) continue;
            if (!match(f.hour, d.getHours())) continue;
            if (!match(f.day, d.getDate())) continue;
            if (!match(f.month, d.getMonth() + 1)) continue;
            if (!match(f.weekday.replace('7', '0'), d.getDay())) continue;
            runs.push(d.toISOString().slice(0, 16));
        }
        return runs;
    }

    function render() {
        var expr = exprEl.value.trim();
        if (!expr) { out.innerHTML = ''; return; }
        var p = expr.split(/\s+/);
        if (p.length < 5) {
            out.innerHTML = '<div class="n2j-flash n2j-flash-err block">Нужны минимум 5 полей: минута час день месяц день_недели.</div>';
            return;
        }
        var runs;
        try { runs = nextRuns(expr, 5); } catch (e) { runs = []; }
        out.innerHTML =
            '<div class="rounded-xl border-2 border-blue-500 bg-blue-50/60 p-4 sm:p-5 shadow-xs dark:border-blue-500/60 dark:bg-blue-900/20">' +
                '<p class="text-sm font-semibold text-blue-700 dark:text-blue-300">Значение</p>' +
                '<p class="mt-2 text-lg leading-relaxed text-gray-900 dark:text-white">' + esc(human(expr)) + '</p>' +
            '</div>' +
            '<div class="n2j-card">' +
                '<p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Следующие запуски (Москва)</p>' +
                '<ul class="mt-2 space-y-1 font-mono text-sm text-gray-700 dark:text-gray-200">' + runs.map(function (r) { return '<li class="flex items-center gap-2"><span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>' + r + '</li>'; }).join('') + '</ul>' +
            '</div>';
        updateUrl(expr);
    }

    function updateUrl(e) {
        try {
            var p = new URLSearchParams(location.search);
            if (e.length < 300) p.set('e', e); else p.delete('e');
            var qs = p.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        } catch (err) { }
    }

    var deb = null;
    exprEl.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(render, 200); });
    document.querySelectorAll('.nj621-preset').forEach(function (b) {
        b.addEventListener('click', function () { exprEl.value = b.getAttribute('data-expr'); render(); exprEl.focus(); });
    });

    if (exprEl.value.trim()) render();
})();
</script>
