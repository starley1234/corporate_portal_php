<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj631_text — текстовые инструменты (клиентское зеркало Service_Nj631_Text). */
(function () {
    'use strict';
    var textEl = document.getElementById('nj631-text');
    var go = document.getElementById('nj631-go');
    var clearBtn = document.getElementById('nj631-clear');
    var copyBtn = document.getElementById('nj631-copy');
    var out = document.getElementById('nj631-out');
    var statsEl = document.getElementById('nj631-stats');
    var toolName = document.getElementById('nj631-toolname');
    var params = document.getElementById('nj631-params');
    var findEl = document.getElementById('nj631-find');
    var replaceEl = document.getElementById('nj631-replace');

    function toolValue() {
        var el = document.querySelector('input[name="nj631-tool"]:checked');
        return el ? el.value : 'stats';
    }

    function stats(t) {
        var chars = Array.from(t).length;
        var withoutSpaces = Array.from(t.replace(/[\s]/gu, '')).length;
        var words = (t.trim().match(/[\p{L}\p{N}'-]+/gu) || []).length;
        var lines = t === '' ? 0 : t.split('\n').length;
        var reading = Math.max(1, Math.round(words / 200 * 60));
        return { chars: chars, chars_ns: withoutSpaces, words: words, lines: lines, reading_s: reading };
    }

    function readingHuman(s) {
        return s < 60 ? 'меньше минуты' : Math.round(s / 60) + ' мин';
    }

    function apply(tool, t) {
        var lines, outText;
        switch (tool) {
            case 'upper': return t.toLocaleUpperCase('ru');
            case 'lower': return t.toLocaleLowerCase('ru');
            case 'title':
                return t.toLowerCase().replace(/(^|[.!?…\n]\s*)([\p{L}\p{N}])/gu, function (m, prefix, letter) { return prefix + letter.toUpperCase(); });
            case 'dedupe':
                lines = t.split('\n'); var seen = {}, kept = [];
                lines.forEach(function (l) {
                    if (l.trim() !== '' && seen[l]) return;
                    if (l.trim() !== '') seen[l] = true;
                    kept.push(l);
                });
                return kept.join('\n');
            case 'sort':
                lines = t.split('\n').filter(function (l) { return l.trim() !== ''; });
                lines.sort(function (a, b) { return a.localeCompare(b, 'ru'); });
                return lines.join('\n');
            case 'trim':
                return t.split('\n').filter(function (l) { return l.trim() !== ''; }).map(function (l) { return l.trim(); }).join('\n');
            case 'replace':
                var f = findEl.value;
                return f === '' ? t : t.split(f).join(replaceEl.value);
            default: return null;
        }
    }

    function liveStats() {
        var s = stats(textEl.value);
        statsEl.textContent = 'Символов: ' + s.chars + ' (без пробелов ' + s.chars_ns + ') · Слов: ' + s.words + ' · Строк: ' + s.lines + ' · Чтение: ' + readingHuman(s.reading_s);
    }

    function run() {
        var tool = toolValue();
        params.classList.toggle('hidden', tool !== 'replace');
        toolName.textContent = document.querySelector('input[name="nj631-tool"][value="' + tool + '"]').closest('label').querySelector('.font-medium').textContent;

        if (tool === 'stats') {
            var s = stats(textEl.value);
            out.innerHTML =
                '<div class="grid grid-cols-2 gap-2 sm:grid-cols-5">' +
                [['символов', s.chars], ['без пробелов', s.chars_ns], ['слов', s.words], ['строк', s.lines], ['чтение', readingHuman(s.reading_s)]]
                    .map(function (row) {
                        return '<div class="rounded-lg bg-gray-50 p-3 text-center dark:bg-gray-950"><div class="text-2xl font-bold text-gray-900 dark:text-white">' + row[1] + '</div><div class="mt-0.5 text-xs text-gray-400">' + row[0] + '</div></div>';
                    }).join('') + '</div>';
            copyBtn.disabled = true;
            return;
        }

        var result = apply(tool, textEl.value);
        if (result === null) { out.innerHTML = '<p class="text-gray-400">Выберите инструмент и вставьте текст.</p>'; copyBtn.disabled = true; return; }
        out.innerHTML = '<pre class="whitespace-pre-wrap break-words rounded-lg bg-gray-50 p-3 font-mono text-xs text-gray-800 dark:bg-gray-950 dark:text-gray-100">' + result.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</pre>';
        copyBtn.disabled = false;
        copyBtn.__value = result;
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    go.addEventListener('click', run);
    copyBtn.addEventListener('click', function () { copyText(copyBtn.__value); });
    clearBtn.addEventListener('click', function () { textEl.value = ''; liveStats(); run(); });
    textEl.addEventListener('input', liveStats);
    document.querySelectorAll('input[name="nj631-tool"]').forEach(function (r) { r.addEventListener('change', run); });

    liveStats();
    run();
})();
</script>
