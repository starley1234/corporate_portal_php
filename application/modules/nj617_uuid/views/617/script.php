<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj617_uuid — генератор UUID v4/v7 и ULID (клиентское зеркало Service_Nj617_Uuid). */
(function () {
    'use strict';
    var list = document.getElementById('nj617-list');
    var genBtn = document.getElementById('nj617-gen');
    var copyAll = document.getElementById('nj617-copy');
    var countIn = document.getElementById('nj617-count');
    var upperChk = document.getElementById('nj617-upper');

    var CROCKFORD = '0123456789abcdefghjkmnpqrstvwxyz';

    function uuid4() {
        var b = new Uint8Array(16);
        crypto.getRandomValues(b);
        b[6] = (b[6] & 0x0f) | 0x40;
        b[8] = (b[8] & 0x3f) | 0x80;
        var h = Array.from(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
    }

    function uuid7() {
        var ms = Date.now();
        var b = new Uint8Array(10);
        crypto.getRandomValues(b);
        var hex = ('000000000000' + ms.toString(16)).slice(-12) + Array.from(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
        var variant = (8 + (parseInt(hex[16], 16) & 0x3)).toString(16);
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-7' + hex.slice(13, 16) + '-' + variant + hex.slice(17, 20) + '-' + hex.slice(20);
    }

    function enum32(n, len) {
        var out = '';
        while (n > 0) { out = CROCKFORD[n % 32] + out; n = Math.floor(n / 32); }
        while (out.length < len) out = '0' + out;
        return out;
    }

    var lastMs = 0, lastRand = '';
    function incRand(s) {
        var chars = s.split(''), pos = chars.length - 1;
        while (pos >= 0) {
            var idx = CROCKFORD.indexOf(chars[pos]);
            if (idx === 31) { chars[pos] = '0'; pos--; continue; }
            chars[pos] = CROCKFORD[idx + 1]; break;
        }
        return chars.join('');
    }
    function rand80() {
        var b = new Uint8Array(10), bits = '', out = '';
        crypto.getRandomValues(b);
        b.forEach(function (x) { bits += ('00000000' + x.toString(2)).slice(-8); });
        for (var i = 0; i < 16; i++) out += CROCKFORD[parseInt(bits.slice(i * 5, i * 5 + 5), 2)];
        return out;
    }
    function ulid() {
        var ms = Date.now();
        if (ms === lastMs) lastRand = incRand(lastRand); else { lastMs = ms; lastRand = rand80(); }
        return enum32(ms, 10) + lastRand;
    }

    function generate() {
        var kind = (document.querySelector('input[name="nj617-kind"]:checked') || {}).value || 'uuid4';
        var n = Math.max(1, Math.min(100, parseInt(countIn.value, 10) || 1));
        var upper = upperChk.checked;
        var ids = [];
        for (var i = 0; i < n; i++) {
            var v = kind === 'uuid7' ? uuid7() : kind === 'ulid' ? ulid() : uuid4();
            ids.push(upper && kind !== 'ulid' ? v.toUpperCase() : v);
        }
        list.innerHTML = ids.map(function (id) {
            return '<li class="group flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-950"><span class="break-all">' + id + '</span>' +
                '<button type="button" class="nj617-one ml-auto shrink-0 rounded px-2 py-1 text-xs text-gray-400 opacity-0 transition group-hover:opacity-100 hover:text-blue-600" data-id="' + id + '"><i class="fas fa-copy"></i></button></li>';
        }).join('');
        copyAll.disabled = false;
        list.querySelectorAll('.nj617-one').forEach(function (b) {
            b.addEventListener('click', function () { copyText(b.getAttribute('data-id')); });
        });
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    genBtn.addEventListener('click', generate);
    copyAll.addEventListener('click', function () {
        var ids = Array.prototype.map.call(list.querySelectorAll('.nj617-one'), function (b) { return b.getAttribute('data-id'); });
        if (ids.length) copyText(ids.join('\n'));
    });
    document.querySelectorAll('input[name="nj617-kind"]').forEach(function (r) { r.addEventListener('change', generate); });

    generate();
})();
</script>
