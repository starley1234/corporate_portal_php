<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj628_checksum — контрольные цифры (клиентское зеркало Service_Nj628_Checksum). */
(function () {
    'use strict';
    var input = document.getElementById('nj628-in');
    var go = document.getElementById('nj628-go');
    var out = document.getElementById('nj628-out');
    var placeholders = {
        luhn: '4556 7375 8689 9855', inn: '7707083893', ogrn: '1027700132195',
        snils: '123-456-789 64', ean: '4607086561966', crc32: 'n2j.ru'
    };

    function algoValue() {
        var el = document.querySelector('input[name="nj628-algo"]:checked');
        return el ? el.value : 'luhn';
    }

    function luhn(v) {
        v = v.replace(/[\s-]/g, '');
        if (!/^[0-9]{8,19}$/.test(v)) return { valid: false, note: 'Карта — 8–19 цифр.' };
        var sum = 0, alt = false;
        for (var i = v.length - 1; i >= 0; i--) {
            var d = parseInt(v[i], 10);
            if (alt) { d *= 2; if (d > 9) d -= 9; }
            sum += d; alt = !alt;
        }
        return sum % 10 === 0
            ? { valid: true, note: 'Контрольная цифра сходится — номер выглядит корректным.' }
            : { valid: false, note: 'Номер не прошёл алгоритм Луна: проверьте цифры.' };
    }

    function innCheck(digits, weights) {
        var sum = 0;
        weights.forEach(function (w, i) { sum += parseInt(digits[i], 10) * w; });
        return sum % 11 % 10;
    }

    function inn(v) {
        v = v.replace(/\D/g, '');
        var ok = false;
        if (v.length === 10) ok = innCheck(v.slice(0, 9), [2, 4, 10, 3, 5, 9, 4, 6, 8]) === parseInt(v[9], 10);
        else if (v.length === 12) ok = innCheck(v.slice(0, 10), [7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === parseInt(v[10], 10) && innCheck(v.slice(0, 11), [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8]) === parseInt(v[11], 10);
        else return { valid: false, note: 'ИНН — 10 (юрлицо) или 12 (физлицо) цифр.' };
        return ok ? { valid: true, note: 'Контрольные цифры ИНН сходятся.' } : { valid: false, note: 'Контрольная цифра ИНН не сходится: похоже на опечатку.' };
    }

    function ogrn(v) {
        v = v.replace(/\D/g, '');
        var ok = false;
        if (v.length === 13) ok = (parseInt(v.slice(0, 12), 10) % 11 % 10) === parseInt(v[12], 10);
        else if (v.length === 15) ok = (parseInt(v.slice(0, 14), 10) % 13 % 10) === parseInt(v[14], 10);
        else return { valid: false, note: 'ОГРН — 13 цифр, ОГРНИП — 15 цифр.' };
        return ok ? { valid: true, note: 'Контрольная цифра сходится.' } : { valid: false, note: 'Контрольная цифра не сходится.' };
    }

    function snils(v) {
        var d = v.replace(/\D/g, '');
        if (d.length !== 11) return { valid: false, note: 'СНИЛС — 11 цифр: XXX-XXX-XXX YY.' };
        var sum = 0;
        for (var i = 0; i < 9; i++) sum += parseInt(d[i], 10) * (9 - i);
        var cd = sum < 100 ? sum : (sum === 100 ? 0 : sum % 101);
        return cd === parseInt(d.slice(9), 10)
            ? { valid: true, note: 'Контрольное число СНИЛС сходится.' }
            : { valid: false, note: 'СНИЛС выглядит неверно: проверьте число.' };
    }

    function ean(v) {
        v = v.replace(/\D/g, '');
        var len = v.length;
        if (len !== 12 && len !== 13) return { valid: false, note: 'Штрихкод — 12 (UPC-A) или 13 (EAN-13) цифр.' };
        var check = parseInt(v[len - 1], 10);
        var sum = 0;
        for (var i = 0; i < len - 1; i++) sum += (i % 2 === 0) ? parseInt(v[i], 10) : parseInt(v[i], 10) * 3;
        var calc = (10 - (sum % 10)) % 10;
        return calc === check
            ? { valid: true, note: 'Контрольная цифра штрихкода сходится.' }
            : { valid: false, note: 'Неверная контрольная цифра штрихкода.' };
    }

    function crc32(v) {
        // Простая реализация CRC32 (IEEE 802.3), табличный метод
        var table = (function () {
            var t = [], c, n, k;
            for (n = 0; n < 256; n++) { c = n; for (k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; }
            return t;
        })();
        var bytes = new TextEncoder().encode(v);
        var crc = 0xFFFFFFFF;
        for (var i = 0; i < bytes.length; i++) crc = (crc >>> 8) ^ table[(crc ^ bytes[i]) & 0xFF];
        crc = (crc ^ 0xFFFFFFFF) >>> 0;
        var hex = crc.toString(16).toUpperCase().padStart(8, '0');
        return { valid: true, crc: hex, dec: String(crc), note: 'CRC32 — только для контроля целостности, не для паролей.' };
    }

    function render(r) {
        if (r.crc) {
            out.innerHTML = '<span class="inline-flex items-center gap-2 rounded-lg bg-blue-50 px-3 py-2 font-mono text-sm font-bold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"><i class="fas fa-hashtag"></i>0x' + r.crc + '</span>' +
                '<span class="font-mono text-sm text-gray-500 dark:text-gray-400">dec ' + r.dec + '</span>';
            return;
        }
        var ok = r.valid;
        out.innerHTML = '<span class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-bold ' +
            (ok ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-rose-50 text-rose-700 dark:bg-rose-950/60 dark:text-rose-400') + '">' +
            '<i class="' + (ok ? 'fas fa-circle-check' : 'fas fa-circle-xmark') + '"></i>' + (ok ? 'Сходится' : 'Не сходится') + '</span>' +
            '<span class="text-sm text-gray-600 dark:text-gray-300">' + r.note.replace(/</g, '&lt;') + '</span>';
    }

    function run() {
        var a = algoValue();
        var v = input.value;
        input.placeholder = placeholders[a] || '';
        var r;
        switch (a) {
            case 'inn': r = inn(v); break;
            case 'ogrn': r = ogrn(v); break;
            case 'snils': r = snils(v); break;
            case 'ean': r = ean(v); break;
            case 'crc32': r = crc32(v); break;
            default: r = luhn(v);
        }
        render(r);
    }

    go.addEventListener('click', run);
    input.addEventListener('input', run);
    document.querySelectorAll('input[name="nj628-algo"]').forEach(function (r) { r.addEventListener('change', run); });
    document.querySelectorAll('.nj628-example').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('input[name="nj628-algo"]').forEach(function (r) { r.checked = (r.value === b.getAttribute('data-a')); });
            input.value = b.getAttribute('data-v');
            run();
        });
    });

    run();
})();
</script>
