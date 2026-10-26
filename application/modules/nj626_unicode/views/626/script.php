<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj626_unicode — разбор символов (клиентское зеркало Service_Nj626_Unicode). */
(function () {
    'use strict';
    var input = document.getElementById('nj626-in');
    var go = document.getElementById('nj626-go');
    var copyBtn = document.getElementById('nj626-copy');
    var table = document.getElementById('nj626-table');
    var decoded = document.getElementById('nj626-decoded');

    function toUtf8Bytes(cp) {
        if (cp < 0x80) return [cp];
        if (cp < 0x800) return [0xC0 | (cp >> 6), 0x80 | (cp & 0x3F)];
        if (cp < 0x10000) return [0xE0 | (cp >> 12), 0x80 | ((cp >> 6) & 0x3F), 0x80 | (cp & 0x3F)];
        return [0xF0 | (cp >> 18), 0x80 | ((cp >> 12) & 0x3F), 0x80 | ((cp >> 6) & 0x3F), 0x80 | (cp & 0x3F)];
    }

    function hex2(n) { return ('0' + n.toString(16)).slice(-2); }
    function hex4(cp) { return ('000' + cp.toString(16)).slice(-4).toUpperCase(); }

    function escUtf16(cp) {
        if (cp <= 0xFFFF) return '\\u' + hex4(cp);
        var v = cp - 0x10000;
        var high = 0xD800 + (v >> 10), low = 0xDC00 + (v & 0x3FF);
        return '\\u' + hex4(high) + '\\u' + hex4(low);
    }

    function decodeEscapes(t) {
        t = t.replace(/\\u\{([0-9a-fA-F]{1,6})\}/g, function (m, h) { return String.fromCodePoint(parseInt(h, 16)); });
        t = t.replace(/\\u([0-9a-fA-F]{4})/g, function (m, h) { return String.fromCharCode(parseInt(h, 16)); });
        return t;
    }

    function compute() {
        var coded = input.value;

        var stripped = decodeEscapes(coded);
        decoded.classList.remove('hidden');
        decoded.innerHTML = '<span class="text-gray-400">раскодировано:</span> ' +
            stripped.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        var chars = Array.from(stripped);
        if (!chars.length) { table.innerHTML = '<p class="text-gray-400">Введите текст.</p>'; copyBtn.disabled = true; return; }

        var html = '';
        var codes = [];
        chars.forEach(function (ch) {
            var cp = ch.codePointAt(0);
            codes.push(cp.toString(16).toUpperCase());
            var utf8 = toUtf8Bytes(cp).map(hex2).join(' ').toUpperCase();
            html += '<div class="flex items-center gap-3 border-b border-gray-100 py-2 font-mono text-xs dark:border-gray-700">' +
                '<span class="w-8 text-center text-xl text-gray-800 dark:text-gray-100">' + ch.replace(/</g, '&lt;') + '</span>' +
                '<span class="font-bold text-blue-600 dark:text-blue-400">U+' + hex4(cp) + '</span>' +
                '<span class="text-gray-400">UTF-8</span><span class="text-gray-700 dark:text-gray-300">' + utf8 + '</span>' +
                '<span class="text-gray-400">esc</span><span class="break-all text-gray-700 dark:text-gray-300">' + escUtf16(cp) + '</span>' +
                '<span class="text-gray-400">JS</span><span class="break-all text-gray-700 dark:text-gray-300">\\u{' + cp.toString(16).toUpperCase() + '}</span>' +
                '</div>';
        });
        table.innerHTML = html;
        copyBtn.disabled = false;
        copyBtn.__value = chars.map(function (ch) { return 'U+' + hex4(ch.codePointAt(0)) + ' ' + ch; }).join('\n');
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    go.addEventListener('click', compute);
    copyBtn.addEventListener('click', function () { copyText(copyBtn.__value); });
    document.querySelectorAll('.nj626-example').forEach(function (b) {
        b.addEventListener('click', function () { input.value = b.getAttribute('data-value'); compute(); });
    });

    compute();
})();
</script>
