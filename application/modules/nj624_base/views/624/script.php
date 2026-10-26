<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj624_base — системы счисления и битовые операции (клиентское зеркало Service_Nj624_Base). */
(function () {
    'use strict';
    var input = document.getElementById('nj624-in');
    var fromSel = document.getElementById('nj624-from');
    var toSel = document.getElementById('nj624-to');
    var out = document.getElementById('nj624-out');
    var copyBtn = document.getElementById('nj624-copy');
    var bitOut = document.getElementById('nj624-bitout');

    function digit(v, base) {
        var max = base - 1;
        return v.split('').every(function (c) { return parseInt(c, 16) <= parseInt(max.toString(16), 16); });
    }

    function isValid(v, base) {
        v = String(v).toLowerCase().replace(/[\s_]/g, '').replace(/^0x/, '');
        if (!/^[0-9a-f]+$/.test(v)) return false;
        return digit(v, base);
    }

    function fmtBin(n) {
        if (n < 0) n = 0xFFFFFFFF + n + 1;
        var s = n.toString(2);
        var pad = Math.ceil(s.length / 4) * 4;
        s = ('00000000000000000000000000000000' + s).slice(-Math.max(pad, 4));
        return s.replace(/(.{4})/g, '$1 ').replace(/^\s+|\s+$/g, '');
    }

    function compute() {
        var raw = input.value.trim();
        var from = parseInt(fromSel.value, 10);
        var to = parseInt(toSel.value, 10);
        var v = raw.toLowerCase().replace(/[\s_]/g, '').replace(/^0x/, '');

        if (!isValid(v, from)) {
            out.innerHTML = '<p class="text-rose-600">Не удалось перевести: цифры не вписываются в основание «из».</p>';
            copyBtn.disabled = true;
            bitOut.classList.add('hidden');
            return;
        }

        var dec = parseInt(v, from);
        var rendered = [
            ['двоичная (2)', padGroup(dec.toString(2), 4)],
            ['восьмеричная (8)', dec.toString(8)],
            ['десятичная (10)', dec.toString(10)],
            ['шестнадцатеричная (16)', dec.toString(16).toUpperCase()]
        ];

        out.innerHTML = rendered.map(function (row) {
            var isDest = row[0].indexOf('(' + to + ')') === 0;
            return '<div class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 ' +
                (isDest ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-gray-50 dark:bg-gray-950') + '">' +
                '<span class="text-gray-400">' + row[0] + '</span>' +
                '<span class="break-all font-bold text-gray-800 dark:text-gray-100">' + row[1] + '</span>' +
                '</div>';
        }).join('');

        copyBtn.disabled = false;
        copyBtn.__value = rendered.filter(function (r) { return r[0].indexOf('(' + to + ')') === 0; })[0][1];
        bitOut.classList.add('hidden');
    }

    function padGroup(s, n) {
        while (s.length % n) s = '0' + s;
        return s.replace(new RegExp('(.{' + n + '})', 'g'), '$1 ').replace(/ $/, '');
    }

    function bitop(op) {
        var v = input.value.trim().toLowerCase().replace(/[\s_]/g, '').replace(/^0x/, '');
        var from = parseInt(fromSel.value, 10);
        if (!isValid(v, from)) { bitOut.innerHTML = 'Сначала введите корректное число.'; bitOut.classList.remove('hidden'); return; }

        var x = parseInt(v, from);
        var dec, label, note;
        switch (op) {
            case 'and': dec = x & 3; label = 'x AND 3'; note = 'маска младших двух битов: x & 0b11'; break;
            case 'or':  dec = x | 3; label = 'x OR 3';  note = 'включить два младших бита: x | 0b11'; break;
            case 'xor': dec = x ^ 3; label = 'x XOR 3'; note = 'инвертировать два младших бита: x ^ 0b11'; break;
            case 'not': dec = ~x; label = 'NOT x'; note = 'побитовое отрицание (32 бита): ~x'; break;
            case 'shl': dec = x << 1; label = 'x << 1'; note = 'сдвиг влево — умножение на 2'; break;
            case 'shr': dec = x >> 1; label = 'x >> 1'; note = 'сдвиг вправо — деление на 2 нацело'; break;
        }

        bitOut.innerHTML = '<div class="flex items-center justify-between"><span>' + label + '</span><span class="font-bold">' + dec + '</span></div>' +
            '<div class="mt-1 flex items-center justify-between"><span class="text-gray-400">bin</span><span>' + fmtBin(dec) + '</span></div>' +
            '<div class="mt-1 flex items-center justify-between"><span class="text-gray-400">hex</span><span>' + fmtHex(dec) + '</span></div>' +
            '<div class="mt-1 text-gray-400">' + note + '</div>';
        bitOut.classList.remove('hidden');
    }

    function fmtHex(n) {
        if (n < 0) n = 0xFFFFFFFF + n + 1;
        return '0x' + n.toString(16).toUpperCase();
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    input.addEventListener('input', compute);
    fromSel.addEventListener('change', compute);
    toSel.addEventListener('change', compute);
    copyBtn.addEventListener('click', function () { copyText(copyBtn.__value); });
    document.querySelectorAll('.nj624-op').forEach(function (b) { b.addEventListener('click', function () { bitop(b.getAttribute('data-op')); }); });
    document.querySelectorAll('.nj624-example').forEach(function (b) {
        b.addEventListener('click', function () {
            input.value = b.getAttribute('data-value');
            fromSel.value = b.getAttribute('data-from');
            toSel.value = b.getAttribute('data-to');
            compute();
        });
    });

    compute();
})();
</script>
