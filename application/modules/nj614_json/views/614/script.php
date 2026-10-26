<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * nj614_json — клиентское зеркало Service_Nj614_Json.
 * Форматирование/валидация/минификация без обращений к серверу.
 */
(function () {
    'use strict';

    var input = document.getElementById('nj614-input');
    var output = document.getElementById('nj614-output');
    var indentSel = document.getElementById('nj614-indent');
    var sortChk = document.getElementById('nj614-sort');
    var statusEl = document.getElementById('nj614-status');
    var errorEl = document.getElementById('nj614-error');
    var statsWrap = document.getElementById('nj614-stats-wrap');
    var statsEl = document.getElementById('nj614-stats');
    var copyBtn = document.getElementById('nj614-copy');
    var sampleBtn = document.getElementById('nj614-sample');
    var clearBtn = document.getElementById('nj614-clear');
    var hintEl = document.getElementById('nj614-hint');

    var SAMPLE = JSON.stringify({
        "user": { "name": "Анна", "roles": ["admin", "lead"], "active": true },
        "meta": { "ids": [1, 2, 3], "note": null, "ratio": 0.25 },
        "order": ["заказ-1", "заказ-2"]
    }, null, 2);

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function setStatus(kind, text) {
        var dot = '<span class="inline-block h-2 w-2 rounded-full ' +
            (kind === 'ok' ? 'bg-emerald-500' : kind === 'err' ? 'bg-rose-500' : kind === 'busy' ? 'bg-blue-500 animate-pulse' : 'bg-gray-300 dark:bg-gray-600') + '"></span>';
        statusEl.innerHTML = dot + '<span>' + esc(text) + '</span>';
    }

    function showError(msg, pos) {
        if (msg) {
            errorEl.classList.remove('hidden');
            errorEl.textContent = pos != null ? msg + ' [позиция ' + pos + ']' : msg;
        } else {
            errorEl.classList.add('hidden');
        }
    }

    // подсветка: строки/числа/ключи/скобки (без внешних библиотек)
    function highlight(json) {
        var html = esc(json);
        html = html.replace(/(&quot;)(.*?)(&quot;)(\s*:)/g, '<span class="text-blue-700 dark:text-blue-300">$1$2$3</span>$4');
        html = html.replace(/(:&nbsp;|\s*:\s*)(&quot;.*?&quot;)/g, '$1<span class="text-emerald-700 dark:text-emerald-300">$2</span>');
        html = html.replace(/\b(true|false)\b/g, '<span class="text-rose-700 dark:text-rose-300">$1</span>');
        html = html.replace(/\bnull\b/g, '<span class="text-gray-500 dark:text-gray-400">null</span>');
        html = html.replace(/(-?\b\d+(\.\d+)?([eE][+-]?\d+)?\b)/g, '<span class="text-amber-700 dark:text-amber-300">$1</span>');
        return html;
    }

    function renderOk(json, stats) {
        setStatus('ok', 'валидно · ' + json.length + ' симв.');
        showError('');
        output.innerHTML = highlight(json) || '<span class="text-gray-400">пусто</span>';

        if (stats) {
            statsWrap.classList.remove('hidden');
            var rows = [
                ['Ключей', stats.total_keys || 0],
                ['Объектов', stats.objects || 0],
                ['Массивов', stats.arrays || 0],
                ['Строк', stats.strings || 0],
                ['Чисел', stats.numbers || 0],
                ['Вложенность', stats.max_depth || 0]
            ];
            statsEl.innerHTML = rows.map(function (r) {
                return '<div class="flex items-baseline justify-between border-b border-gray-100 pb-1 dark:border-gray-700/60">' +
                    '<dt class="text-xs text-gray-500 dark:text-gray-400">' + r[0] + '</dt>' +
                    '<dd class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100">' + r[1] + '</dd></div>';
            }).join('');
        } else {
            statsWrap.classList.add('hidden');
        }
        copyBtn.disabled = false;
    }

    function renderEmpty() {
        setStatus('idle', 'готов к работе');
        showError('');
        statsWrap.classList.add('hidden');
        output.innerHTML = '<span class="text-gray-400">Результат появится здесь — начните вводить или вставьте JSON.</span>';
        copyBtn.disabled = true;
    }

    function run() {
        var text = input.value;

        if (!text || !text.trim()) {
            renderEmpty();
            updateUrl('', indentSel.value);
            return;
        }

        var indentRaw = indentSel.value;
        var minify = indentRaw === '0';
        var tab = indentRaw === '-1';
        var sp = parseInt(indentRaw, 10) || 2;

        var value, err = null;
        try {
            value = JSON.parse(text);
        } catch (e) {
            err = e;
        }

        if (err) {
            setStatus('err', 'ошибка');
            showError(err.message, positionOf(text, err.message));
            statsWrap.classList.add('hidden');
            copyBtn.disabled = false; // копируем исходник тоже
            output.innerHTML = esc(text);
            updateUrl(text, indentRaw);
            return;
        }

        var json;
        if (minify) {
            json = JSON.stringify(value);
        } else if (tab) {
            json = JSON.stringify(value, null, 4).split('\n').map(function (ln) {
                var m = ln.match(/^\s*/)[0].length;
                return new Array(Math.floor(m / 4) + 1).join('\t') + ln.replace(/^\s+/, '');
            }).join('\n');
        } else {
            json = JSON.stringify(value, null, sp);
        }

        var stats = walkStats(value);
        renderOk(json, stats);
        updateUrl(text, indentRaw);
    }

    // приблизительная позиция ошибки по сообщению браузера (position N)
    function positionOf(text, msg) {
        var m = /position\s+(\d+)/.exec(msg || '');
        return m ? parseInt(m[1], 10) : null;
    }

    function walkStats(v, acc, depth) {
        acc = acc || { total_keys: 0, objects: 0, arrays: 0, strings: 0, numbers: 0, booleans: 0, nulls: 0, max_depth: 0 };
        depth = depth || 0;
        acc.max_depth = Math.max(acc.max_depth, depth);

        if (Array.isArray(v)) {
            acc.arrays++;
            v.forEach(function (x) { walkStats(x, acc, depth + 1); });
        } else if (v && typeof v === 'object') {
            acc.objects++;
            Object.keys(v).forEach(function (k) {
                acc.total_keys++;
                walkStats(v[k], acc, depth + 1);
            });
        } else if (typeof v === 'string') {
            acc.strings++;
        } else if (typeof v === 'number') {
            acc.numbers++;
        } else if (typeof v === 'boolean') {
            acc.booleans++;
        } else if (v === null) {
            acc.nulls++;
        }
        return acc;
    }

    function updateUrl(text, indentRaw) {
        try {
            if (!text || !text.trim()) {
                history.replaceState(null, '', location.pathname);
                return;
            }
            var p = new URLSearchParams(location.search);
            if (text.length < 2000) p.set('q', text); else p.delete('q');
            p.set('indent', indentRaw);
            if (sortChk.checked) p.set('sort', '1'); else p.delete('sort');
            var qs = p.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        } catch (e) { /* file:// или старые браузеры */ }
    }

    function toast(msg) { N2J.toast(msg); }

    function copyText(text) {
        N2J.copyText(text).then(function () { toast('Скопировано в буфер'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    var debounce = null;
    input.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(run, 250);
    });
    indentSel.addEventListener('change', run);
    sortChk.addEventListener('change', run);

    copyBtn.addEventListener('click', function () {
        var text = input.value.trim();
        // копируем результат, если он валиден — иначе исходный текст
        try {
            JSON.parse(text);
            var v = JSON.parse(text);
            var minify = indentSel.value === '0';
            var json = minify ? JSON.stringify(v) : JSON.stringify(v, null, parseInt(indentSel.value, 10) || 2);
            copyText(json);
        } catch (e) {
            copyText(text);
        }
    });
    sampleBtn.addEventListener('click', function () {
        input.value = SAMPLE;
        run();
        input.focus();
    });
    clearBtn.addEventListener('click', function () {
        input.value = '';
        run();
        input.focus();
    });

    if (input.value.trim() !== '') run(); else renderEmpty();
    if (hintEl) hintEl.textContent = 'Живой пересчёт без отправки на сервер; состояние сохраняется в ссылке.';
})();
</script>
