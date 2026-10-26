<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj625_bytes — единицы информации (клиентское зеркало Service_Nj625_Bytes). */
(function () {
    'use strict';
    var input = document.getElementById('nj625-in');
    var go = document.getElementById('nj625-go');
    var table = document.getElementById('nj625-table');
    var humanOutput = document.getElementById('nj625-human');
    var copyBtn = document.getElementById('nj625-copy');

    var UNITS = {
        'bit': [0.125, 'бит'],
        'B': [1, 'байт'],
        'KiB': [1024, 'кибибайт (1024)'],
        'KB': [1000, 'килобайт (1000)'],
        'MiB': [1048576, 'мебибайт (1024²)'],
        'MB': [1000000, 'мегабайт (1000²)'],
        'GiB': [1073741824, 'гибибайт (1024³)'],
        'GB': [1000000000, 'гигабайт (1000³)'],
        'TiB': [1099511627776, 'тебибайт (1024⁴)'],
        'TB': [1000000000000, 'терабайт (1000⁴)']
    };

    var ALIASES = {
        bit: 'bit', бит: 'bit', b: 'bit',
        byte: 'B', байт: 'B',
        kib: 'KiB', киб: 'KiB', кибибайт: 'KiB',
        kb: 'KB', кб: 'KB', кбайт: 'KB', килобайт: 'KB',
        mib: 'MiB', миб: 'MiB', мебибайт: 'MiB',
        mb: 'MB', мб: 'MB', мбайт: 'MB', мегабайт: 'MB',
        gib: 'GiB', гиб: 'GiB', гибибайт: 'GiB',
        gb: 'GB', гб: 'GB', гбайт: 'GB', гигабайт: 'GB',
        tib: 'TiB', тиб: 'TiB', тебибайт: 'TiB',
        tb: 'TB', тб: 'TB', тбайт: 'TB', терабайт: 'TB'
    };

    function fmt(n) {
        var s;
        if (Math.abs(n) >= 100) s = n.toFixed(2);
        else if (Math.abs(n) >= 1) s = n.toFixed(3);
        else s = String(parseFloat(n.toFixed(6)));
        return s;
    }

    function human(bytes) {
        var order = ['TiB', 'GiB', 'MiB', 'KiB'];
        for (var i = 0; i < order.length; i++) {
            var code = order[i];
            if (bytes >= UNITS[code][0]) return fmt(bytes / UNITS[code][0]) + ' ' + code;
        }
        return fmt(bytes) + ' байт';
    }

    function compute() {
        var raw = input.value.trim();
        var m = raw.match(/^(\d+(?:[.,]\d+)?)\s*([a-zа-яё]+)$/i);
        if (!m) { renderError('Формат: «число + единица», например 500 МБ.'); return; }
        var amount = parseFloat(m[1].replace(',', '.'));
        var unit = ALIASES[(m[2] || '').toLowerCase()];
        if (!unit || amount < 0) { renderError('Не знаю такую единицу. Попробуйте: бит, байт, КБ, МБ, ГБ, ТБ, КиБ, МиБ, ГиБ, ТиБ.'); return; }

        var bytes = amount * UNITS[unit][0];
        humanOutput.textContent = '= ' + human(bytes);
        var html = '';
        Object.keys(UNITS).forEach(function (code) {
            var isIn = code === unit;
            html += '<div class="flex items-center justify-between rounded-lg px-3 py-2 ' +
                (isIn ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-gray-50 dark:bg-gray-950') + '">' +
                '<span class="text-gray-500 dark:text-gray-400">' + code + '</span>' +
                '<span class="font-mono font-bold text-gray-800 dark:text-gray-100">' + fmt(bytes / UNITS[code][0]) + '</span>' +
                '</div>';
        });
        table.innerHTML = html;
        copyBtn.disabled = false;
        copyBtn.__value = Object.keys(UNITS).map(function (c) { return c + ' ' + fmt(bytes / UNITS[c][0]); }).join('\n');
    }

    function renderError(msg) {
        humanOutput.textContent = '';
        table.innerHTML = '<p class="col-span-full text-rose-600">' + msg + '</p>';
        copyBtn.disabled = true;
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    go.addEventListener('click', compute);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); compute(); } });
    copyBtn.addEventListener('click', function () { copyText(copyBtn.__value); });
    input.addEventListener('input', function () { if (input.value.trim()) compute(); });
    document.querySelectorAll('.nj625-example').forEach(function (b) {
        b.addEventListener('click', function () { input.value = b.getAttribute('data-value'); compute(); });
    });

    compute();
})();
</script>
