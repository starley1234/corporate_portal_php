<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj502_words: инлайн-скрипт «Сумма прописью» (источник один — этот файл) -->
<script>
/**
 * Зеркало Service_Nj502_Words (копейки, half-up, тот же алгоритм триад).
 * При изменении формул менять и PHP-сервис, и этот файл (см. AGENTS модуля).
 */
(function () {
    'use strict';

    var ONES_M = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    var ONES_F = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
    var TEENS  = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать',
                  'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
    var TENS   = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят',
                  'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
    var HUNDREDS = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот',
                    'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
    var FORMS = {
        rub: ['рубль', 'рубля', 'рублей'],
        kop: ['копейка', 'копейки', 'копеек'],
        thousand: ['тысяча', 'тысячи', 'тысяч'],
        million: ['миллион', 'миллиона', 'миллионов'],
        billion: ['миллиард', 'миллиарда', 'миллиардов']
    };
    var MAX_RUB = 999999999999;

    function plural(n, forms) {
        var n100 = n % 100, n10 = n % 10;
        if (n100 >= 11 && n100 <= 14) return forms[2];
        if (n10 === 1) return forms[0];
        if (n10 >= 2 && n10 <= 4) return forms[1];
        return forms[2];
    }

    function groupWords(g, feminine) {
        var w = [];
        var h = Math.floor(g / 100), t = Math.floor((g % 100) / 10), u = g % 10;
        if (h) w.push(HUNDREDS[h]);
        if (t === 1) w.push(TEENS[g % 100 - 10]);
        else {
            if (t) w.push(TENS[t]);
            if (u) w.push((feminine ? ONES_F : ONES_M)[u]);
        }
        return w.join(' ');
    }

    function toKop(raw) {
        if (raw == null) return null;
        var s = String(raw).replace(/[\s\u00A0\u202F\u2009]/g, '').replace(',', '.');
        if (!/^\d{1,15}(\.\d{1,2})?$/.test(s)) return null;
        var parts = s.split('.');
        var rub = parseInt(parts[0], 10) || 0;
        var fr = parts[1] !== undefined ? parseInt((parts[1] + '00').slice(0, 2), 10) || 0 : 0;
        var kop = rub * 100 + fr;
        if (Math.floor(kop / 100) > MAX_RUB) return null;
        return kop;
    }

    // → {text, rub, fr} или null
    function rubles(kop, kopWords) {
        if (kop == null) return null;
        var rub = Math.floor(kop / 100), fr = kop % 100;
        var words;
        if (rub === 0) {
            words = ['ноль'];
        } else {
            words = [];
            var scales = ['rub', 'thousand', 'million', 'billion'];
            var groups = [], rest = rub;
            while (rest > 0) { groups.push(rest % 1000); rest = Math.floor(rest / 1000); }
            for (var i = groups.length - 1; i >= 0; i--) {
                var g = groups[i];
                if (g === 0) continue;
                var sc = scales[i];
                words.push(groupWords(g, sc === 'thousand'));
                if (sc !== 'rub') words.push(plural(g, FORMS[sc]));
            }
        }
        var text = words.join(' ') + ' ' + plural(rub, FORMS.rub);
        if (kopWords) {
            text += ' ' + ((fr === 0 ? 'ноль' : groupWords(fr, true)) + ' ' + plural(fr, FORMS.kop));
        } else {
            text += ' ' + ('0' + fr).slice(-2) + ' ' + plural(fr, FORMS.kop);
        }
        return { text: text, rub: rub, fr: fr };
    }

    function formatRub(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    // ---------- DOM ----------
    var $in = document.getElementById('w2-amount');
    if (!$in) return;

    var $out = document.getElementById('w2-out');
    var $copy = document.getElementById('w2-copy');
    var $share = document.getElementById('w2-share');
    var $uc = document.getElementById('w2-ucfirst');
    var $kw = document.getElementById('w2-kopwords');
    var $rowAmount = document.getElementById('row-amount');
    var $rowSplit = document.getElementById('row-split');

    function currentText(res) {
        var text = res.text;
        if ($uc.checked) text = text.charAt(0).toUpperCase() + text.slice(1);
        return text;
    }

    function render() {
        var res = rubles(toKop($in.value), $kw.checked);
        var has = !!res;

        $out.textContent = has ? currentText(res) : '—';
        $rowAmount.textContent = has
            ? formatRub(res.rub) + ',' + ('0' + res.fr).slice(-2) + ' ₽'
            : '—';
        $rowSplit.textContent = has
            ? formatRub(res.rub) + ' / ' + ('0' + res.fr).slice(-2)
            : '—';

        $copy.disabled = !has;
        $share.disabled = !has;

        if (has) {
            var url = location.pathname +
                '?a=' + encodeURIComponent($in.value.replace(/\s/g, '')) +
                ($uc.checked ? '&u=1' : '') +
                ($kw.checked ? '&k=1' : '');
            try { history.replaceState(null, '', url); } catch (e) { }
        }
    }

    function toast(msg) { N2J.toast(msg); }

    $in.addEventListener('input', render);
    $uc.addEventListener('change', render);
    $kw.addEventListener('change', render);

    document.querySelectorAll('[data-chip]').forEach(function (b) {
        b.addEventListener('click', function () {
            $in.value = b.getAttribute('data-chip');
            render();
            $in.focus();
        });
    });

    function copyText(text, okMsg) {
        N2J.copyText(text).then(function () { toast(okMsg); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    $copy.addEventListener('click', function () {
        if (!$out.textContent || $out.textContent === '—') return;
        copyText($out.textContent, 'Сумма прописью скопирована');
    });

    $share.addEventListener('click', function () {
        copyText(location.href, 'Ссылка скопирована');
    });

    // первичный пересчёт, если сумма уже в URL (SSR тоже показывает)
    if ($in.value.trim() !== '') render();
})();
</script>
