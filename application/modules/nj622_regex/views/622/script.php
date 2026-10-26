<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj622_regex — прогон через серверный API (показывает «серверный анализ»). */
(function () {
    'use strict';
    var patternEl = document.getElementById('nj622-pattern');
    var testEl = document.getElementById('nj622-test');
    var replaceEl = document.getElementById('nj622-replace');
    var out = document.getElementById('nj622-out');
    var busy = false;
    var lastReq = 0;

    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function flags() {
        return Array.prototype.map.call(document.querySelectorAll('.nj622-flag:checked'), function (c) { return c.value; }).join('');
    }

    function card(title, body, tone) {
        return '<div class="rounded-xl border p-4 sm:p-5 shadow-xs ' + (tone === 'blue' ? 'border-2 border-blue-500 bg-blue-50/60 dark:border-blue-500/60 dark:bg-blue-900/20' : tone === 'red' ? 'border-rose-200 bg-rose-50 dark:border-rose-950/40 dark:bg-rose-950/40' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800') + '">' +
            '<p class="text-sm font-semibold ' + (tone === 'blue' ? 'text-blue-700 dark:text-blue-300' : tone === 'red' ? 'text-rose-700 dark:text-rose-300' : 'text-gray-700 dark:text-gray-300') + '">' + title + '</p>' + body + '</div>';
    }

    function renderError(msg) {
        out.innerHTML = card('Ошибка', '<p class="mt-2 text-sm text-rose-700 dark:text-rose-300">' + esc(msg) + '</p>', 'red');
    }

    function render(res) {
        if (!res.ok) { renderError(res.error || 'Неизвестная ошибка'); return; }

        var html = '';

        // сводка
        var meta = '<div class="mt-2 flex flex-wrap gap-2">' +
            '<span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">Совпадений: ' + res.match_count + '</span>' +
            (res.replace_count !== undefined && replaceEl.value ? '<span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">Замен: ' + res.replace_count + '</span>' : '') +
            '</div>';
        html += card('Сводка', meta, 'blue');

        // совпадения
        if (res.match_count === 0) {
            html += card('Совпадения', '<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Совпадений не найдено.</p>');
        } else {
            var rows = (res.matches || []).map(function (m) {
                var grp = (m.groups && m.groups.length) ? '<ul class="mt-1 space-y-0.5 text-xs text-gray-500 dark:text-gray-400">' + m.groups.map(function (g) {
                    return '<li>#' + g.index + ' = ' + (g.text === null ? '<i>нет</i>' : '<code class="font-mono text-blue-600">' + esc(g.text) + '</code>') + ' (поз. ' + g.pos + ')</li>';
                }).join('') + '</ul>' : '';
                return '<li class="rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-950">' +
                    '<span class="text-xs font-bold text-gray-400">#' + m.n + '</span> ' +
                    '<code class="font-mono text-sm text-blue-700 dark:text-blue-300">' + esc(m.text) + '</code> ' +
                    '<span class="text-xs text-gray-400">поз. ' + m.pos + '</span>' + grp + '</li>';
            }).join('');
            html += card('Совпадения', '<ul class="mt-2 space-y-1.5">' + rows + '</ul>');
        }

        // замена
        if (replaceEl.value) {
            html += card('Результат замены', '<pre class="mt-2 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-4 font-mono text-sm text-gray-800 dark:bg-gray-950 dark:text-gray-100">' + esc(res.replaced || '') + '</pre>');
        }

        out.innerHTML = html;
    }

    function run() {
        var pattern = patternEl.value;
        var test = testEl.value;
        var replace = replaceEl.value;
        if (!pattern) { out.innerHTML = ''; return; }

        var seq = ++lastReq;
        busy = true;

        fetch('/nj622/api/test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pattern: pattern, test: test, replace: replace, flags: flags() })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (seq === lastReq) render(res); })
        .catch(function () { if (seq === lastReq) renderError('Сервер недоступен. Попробуйте ещё раз.'); })
        .finally(function () { busy = false; updateUrl(pattern, test, replace); });
    }

    function updateUrl(p, t, r) {
        try {
            var q = new URLSearchParams();
            if (p.length < 500) q.set('p', p); else q.delete('p');
            if (t.length < 1500) q.set('t', t); else q.delete('t');
            q.set('f', flags());
            var qs = q.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        } catch (e) { }
    }

    var deb = null;
    function schedule() { clearTimeout(deb); deb = setTimeout(run, 300); }
    patternEl.addEventListener('input', schedule);
    testEl.addEventListener('input', schedule);
    replaceEl.addEventListener('input', schedule);
    document.querySelectorAll('.nj622-flag').forEach(function (c) { c.addEventListener('change', schedule); });

    // примеры: подставляют паттерн, текст и снимают/ставят нужные флаги
    document.querySelectorAll('.nj622-example').forEach(function (b) {
        b.addEventListener('click', function () {
            patternEl.value = '/' + b.getAttribute('data-pattern') + '/';
            testEl.value = b.getAttribute('data-test') || '';
            replaceEl.value = '';
            var want = b.getAttribute('data-flags') || '';
            document.querySelectorAll('.nj622-flag').forEach(function (c) {
                c.checked = want.indexOf(c.value) !== -1;
            });
            run();
            patternEl.focus();
        });
    });

    if (patternEl.value.trim()) run();
})();
</script>
