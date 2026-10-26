<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj509_password: инлайн-скрипт генератора (источник один — этот файл) -->
<script>
/**
 * Зеркало Service_Nj509_Password: те же наборы, то же исключение похожих,
 * тот же алгоритм (минимум по символу из набора + Fisher—Yates).
 * ГСЧ: crypto.getRandomValues с отбраковкой — равномерно, без modulo bias.
 * Пароли нигде не сохраняются.
 */
(function () {
    'use strict';

    var SETS = {
        upper:   'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        lower:   'abcdefghijklmnopqrstuvwxyz',
        digits:  '0123456789',
        symbols: '!@#$%^&*()-_=+[]{};:,.?/~'
    };
    var AMBIGUOUS = '0O1lI|`\'"';
    var MIN_LEN = 6, MAX_LEN = 96;

    var $out = document.getElementById('pw-out');
    if (!$out) return;

    var $length = document.getElementById('pw-length');
    var $lengthVal = document.getElementById('pw-length-val');
    var $entropy = document.getElementById('pw-entropy');
    var $strength = document.getElementById('pw-strength');
    var $regen = document.getElementById('pw-regen');
    var $copy = document.getElementById('pw-copy');
    var $bulk = document.getElementById('pw-bulk');
    var $bulkWrap = document.getElementById('pw-bulk-wrap');
    var $exclude = document.getElementById('pw-exclude-ambiguous');

    function randInt(maxExclusive) {
        // отбраковка: используем только полные кратные пула диапазоны
        var limit = Math.floor(4294967296 / maxExclusive) * maxExclusive;
        var buf = new Uint32Array(1);
        do {
            crypto.getRandomValues(buf);
        } while (buf[0] >= limit);
        return buf[0] % maxExclusive;
    }

    function randChar(chars) {
        return chars.charAt(randInt(chars.length));
    }

    function shuffle(arr) {
        for (var i = arr.length - 1; i > 0; i--) {
            var j = randInt(i + 1);
            var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
        }
        return arr;
    }

    function activeSets() {
        var sets = [];
        document.querySelectorAll('input[data-set]').forEach(function (cb) {
            if (cb.checked) {
                var chars = SETS[cb.getAttribute('data-set')];
                if ($exclude.checked) {
                    chars = chars.split('').filter(function (c) { return AMBIGUOUS.indexOf(c) === -1; }).join('');
                }
                if (chars) sets.push(chars);
            }
        });
        return sets;
    }

    // [подпись, css-классы бейджа] — зеркало Service_Nj509_Password::strength().
    function strengthOf(entropy) {
        if (entropy < 40) return ['слабый', 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'];
        if (entropy < 60) return ['средний', 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'];
        if (entropy < 80) return ['крепкий', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'];
        return ['бронированный', 'bg-emerald-600 text-white'];
    }

    function opts() {
        var length = parseInt($length.value, 10);
        if (!(length >= MIN_LEN && length <= MAX_LEN)) length = 16;
        return { length: length, sets: activeSets() };
    }

    function generate() {
        var o = opts();
        if (!o.sets.length) return null;

        var chars = [];
        o.sets.forEach(function (charsSet) { chars.push(randChar(charsSet)); });
        var pool = o.sets.join('');
        while (chars.length < o.length) chars.push(randChar(pool));

        return shuffle(chars.slice(0, o.length)).join('');
    }

    function entropyOf(length, poolSize) {
        return Math.round(length * Math.log2(poolSize) * 10) / 10;
    }

    function render() {
        var o = opts();
        var pw = generate();
        if (pw === null) return;

        $out.textContent = pw;

        var poolSize = o.sets.join('').length;
        var entropy = entropyOf(o.length, poolSize);
        var s = strengthOf(entropy);

        $entropy.textContent = entropy + ' бит';
        $strength.className = 'rounded-md px-2 py-0.5 text-xs font-bold ' + s[1];
        $strength.textContent = s[0];
    }

    function toast(msg) { N2J.toast(msg); }

    function copyText(text, okMsg) {
        N2J.copyText(text).then(function () { toast(okMsg); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    $length.addEventListener('input', function () {
        $lengthVal.textContent = $length.value;
        render();
    });

    document.querySelectorAll('input[data-set]').forEach(function (cb) {
        cb.addEventListener('change', function () {
            // подсветка активного тумблера
            var label = cb.closest('[data-set-label]');
            if (label) label.classList.toggle('is-on', cb.checked);
            if (activeSets().length) render();
        });
    });

    $exclude.addEventListener('change', function () {
        var label = $exclude.closest('[data-set-label]');
        if (label) label.classList.toggle('is-on', $exclude.checked);
        render();
    });

    $regen.addEventListener('click', render);

    $copy.addEventListener('click', function () {
        copyText($out.textContent, 'Пароль скопирован');
    });

    var bulkShown = false;
    $bulk.addEventListener('click', function () {
        bulkShown = !bulkShown;
        $bulkWrap.classList.toggle('hidden', !bulkShown);
        if (!bulkShown) return;

        var o = opts();
        var pool = o.sets.join('');
        for (var i = 0; i < 5; i++) {
            var slot = document.querySelector('[data-pw-slot="' + i + '"]');
            if (!slot) continue;
            if (!pool) { slot.textContent = '—'; continue; }
            var chars = [];
            o.sets.forEach(function (cs) { chars.push(randChar(cs)); });
            while (chars.length < o.length) chars.push(randChar(pool));
            slot.textContent = shuffle(chars.slice(0, o.length)).join('');
        }
    });

    document.querySelectorAll('[data-pw-copy-slot]').forEach(function (b) {
        b.addEventListener('click', function () {
            var i = b.getAttribute('data-pw-copy-slot');
            var slot = document.querySelector('[data-pw-slot="' + i + '"]');
            if (slot && slot.textContent && slot.textContent !== '—') {
                copyText(slot.textContent, 'Пароль скопирован');
            }
        });
    });
})();
</script>
