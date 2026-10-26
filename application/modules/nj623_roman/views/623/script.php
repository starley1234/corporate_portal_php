<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj623_roman — римские числа (клиентское зеркало Service_Nj623_Roman). */
(function () {
    'use strict';
    var input = document.getElementById('nj623-in');
    var go = document.getElementById('nj623-go');
    var out = document.getElementById('nj623-out');
    var dirBadge = document.getElementById('nj623-dir');
    var hint = document.getElementById('nj623-hint');
    var copyBtn = document.getElementById('nj623-copy');

    var MAP = [
        [1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'],
        [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'],
        [5, 'V'], [4, 'IV'], [1, 'I']
    ];

    function toRoman(n) {
        if (n < 1 || n > 3999) return null;
        var s = '';
        MAP.forEach(function (pair) { while (n >= pair[0]) { s += pair[1]; n -= pair[0]; } });
        return s;
    }

    function fromRoman(s) {
        s = s.toUpperCase().replace(/^\s+|\s+$/g, '');
        if (!s) return null;
        if (!/^M{0,3}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/.test(s)) return null;
        var value = { M: 1000, D: 500, C: 100, L: 50, X: 10, V: 5, I: 1 };
        var total = 0, i = 0;
        while (i < s.length) {
            var cur = value[s[i]];
            var nxt = (i + 1 < s.length) ? value[s[i + 1]] : 0;
            if (nxt > cur) { total += nxt - cur; i += 2; } else { total += cur; i += 1; }
        }
        return total;
    }

    function convert(value) {
        var v = value.replace(/^\s+|\s+$/g, '');
        if (!v) { render('', ''); return; }
        if (/^[0-9]+$/.test(v)) {
            var r = toRoman(parseInt(v, 10));
            if (r === null) { render('err', ''); return; }
            render('to', v + ' = <b>' + r + '</b>');
        } else {
            var n = fromRoman(v);
            if (n === null) { render('err', ''); return; }
            render('from', v.toUpperCase() + ' = <b>' + n + '</b>');
        }
    }

    function render(dir, html) {
        if (dir === 'err') {
            out.textContent = 'Не удалось перевести';
            out.classList.remove('font-bold');
            out.classList.add('text-rose-600');
            dirBadge.classList.add('hidden');
            hint.textContent = 'Арабское число должно быть 1–3999, римская запись — строгой (например XIV, а не XIIII).';
            hint.classList.remove('hidden');
            copyBtn.disabled = true;
            return;
        }
        if (!html) {
            out.textContent = '';
            out.classList.remove('text-rose-600');
            out.classList.add('font-bold');
            dirBadge.classList.add('hidden');
            hint.classList.add('hidden');
            copyBtn.disabled = true;
            return;
        }
        out.innerHTML = html;
        out.classList.remove('text-rose-600');
        out.classList.add('font-bold');
        dirBadge.textContent = dir === 'to' ? 'арабские → римские' : 'римские → арабские';
        dirBadge.classList.remove('hidden');
        hint.textContent = dir === 'to' ? 'Складываем разряды: 1987 = M (1000) + CM (900) + LXXX (80) + VII (7).' : 'Читайте складывая цифры; меньшая перед большей вычитается.';
        hint.classList.remove('hidden');
        copyBtn.disabled = false;
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    go.addEventListener('click', function () { convert(input.value); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); convert(input.value); } });
    copyBtn.addEventListener('click', function () { if (out.textContent) copyText(out.textContent); });
    document.querySelectorAll('.nj623-example').forEach(function (b) {
        b.addEventListener('click', function () { input.value = b.getAttribute('data-value'); convert(input.value); });
    });

    convert(input.value);
})();
</script>
