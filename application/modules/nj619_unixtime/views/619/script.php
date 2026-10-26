<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj619_unixtime — клиентское зеркало Service_Nj619_UnixTime. */
(function () {
    'use strict';
    var tsEl = document.getElementById('nj619-ts');
    var out = document.getElementById('nj619-out');
    var nowBtn = document.getElementById('nj619-now');
    var utilityBtn = document.getElementById('nj619-utility');

    function tzValue() {
        var el = document.querySelector('input[name="nj619-tz"]:checked');
        return el ? el.value : 'Europe/Moscow';
    }
    var TZ_LABELS = {};
    document.querySelectorAll('input[name="nj619-tz"]').forEach(function (r) {
        var span = r.parentElement.querySelector('span span') || r.parentElement.querySelector('span');
        TZ_LABELS[r.value] = (span ? span.textContent.trim() : r.value);
    });
    function tzLabel(tz) { return TZ_LABELS[tz] || tz; }

    function asSeconds(raw) {
        raw = String(raw).trim();
        if (!/^-?\d+$/.test(raw)) return null;
        var n = Number(raw);
        if (/^-?\d{11,13}$/.test(raw)) n = Math.trunc(n / 1000);
        return n;
    }

    function card(label, value, accent) {
        return '<div class="rounded-xl border p-4 shadow-xs ' + (accent ? 'border-2 border-blue-500 bg-blue-50/60 dark:border-blue-500/60 dark:bg-blue-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800') + '">' +
            '<p class="text-xs font-semibold uppercase tracking-wide ' + (accent ? 'text-blue-700 dark:text-blue-300' : 'text-gray-500 dark:text-gray-400') + '">' + label + '</p>' +
            '<p class="mt-1 break-all font-mono text-sm ' + (accent ? 'text-blue-800 dark:text-blue-200' : 'text-gray-700 dark:text-gray-200') + '">' + value + '</p></div>';
    }

    function render() {
        var raw = tsEl.value.trim();
        if (!raw) {
            out.innerHTML = '';
            return;
        }
        var sec = asSeconds(raw);
        if (sec === null || !isFinite(sec)) {
            out.innerHTML = card('Ошибка', 'Введите целое число (секунды или миллисекунды)', false);
            return;
        }
        var d = new Date(sec * 1000);
        if (isNaN(d.getTime())) {
            out.innerHTML = card('Ошибка', 'Значение вне диапазона дат', false);
            return;
        }
        var tz = tzValue();
        var local;
        try {
            local = d.toLocaleString('ru-RU', { timeZone: tz, hour12: false, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
        } catch (e) { local = d.toISOString(); }

        var unit = String(raw).replace('-', '').length >= 11 ? 'мс' : 'с';
        out.innerHTML =
            card('Дата (' + (TZ_LABELS[tz] || tz) + ')', local, true) +
            card('ISO 8601', d.toISOString(), false) +
            card('UTC', d.toISOString().slice(0, 19) + ' UTC', false) +
            card('День недели', d.toLocaleDateString('ru-RU', { timeZone: tz, weekday: 'long' }), false) +
            card('Секунды', String(sec), false) +
            card('Миллисекунды', String(sec * 1000) + ' (' + unit + ' введено)', false);
        updateUrl(raw);
    }

    function updateUrl(raw) {
        try {
            var p = new URLSearchParams(location.search);
            p.set('ts', raw);
            p.set('tz', tzValue());
            var qs = p.toString();
            history.replaceState(null, '', location.pathname + '?' + qs);
        } catch (e) { }
    }

    var deb = null;
    tsEl.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(render, 150); });
    document.querySelectorAll('input[name="nj619-tz"]').forEach(function (r) { r.addEventListener('change', render); });
    nowBtn.addEventListener('click', function () { tsEl.value = Math.floor(Date.now() / 1000); render(); tsEl.focus(); });
    utilityBtn.addEventListener('click', function () { tsEl.value = String(1700000000); render(); tsEl.focus(); });

    if (tsEl.value.trim()) render();
})();
</script>
