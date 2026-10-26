<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj630_ipcalc — подсети IPv4 (клиентское зеркало Service_Nj630_Ipcalc). */
(function () {
    'use strict';
    var input = document.getElementById('nj630-in');
    var go = document.getElementById('nj630-go');
    var out = document.getElementById('nj630-out');

    function parse(v) {
        var m = String(v).trim().match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/);
        if (!m) return null;
        var oct = [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10), parseInt(m[4], 10)];
        if (!oct.every(function (o) { return o >= 0 && o <= 255; })) return null;
        var prefix = (m[5] !== undefined && m[5] !== '') ? parseInt(m[5], 10) : 24;
        if (prefix < 0 || prefix > 32) return null;
        // uint32 ip
        var ip = ((oct[0] * 256 + oct[1]) * 256 + oct[2]) * 256 + oct[3];
        return { ip: ip, prefix: prefix, oct: oct };
    }

    function ip(n) {
        return ((n >>> 24) & 255) + '.' + ((n >>> 16) & 255) + '.' + ((n >>> 8) & 255) + '.' + (n & 255);
    }

    function report(v) {
        var p = parse(v);
        if (!p) return null;
        var mask = p.prefix === 0 ? 0 : (0xFFFFFFFF << (32 - p.prefix)) >>> 0;
        var wild = (~mask) >>> 0;
        var network = (p.ip & mask) >>> 0;
        var broadcast = (network | wild) >>> 0;
        var total = p.prefix >= 31 ? (p.prefix === 32 ? 1 : 2) : Math.pow(2, 32 - p.prefix);
        var first = p.prefix >= 31 ? network : network + 1;
        var last = p.prefix >= 31 ? broadcast : broadcast - 1;
        var klass = p.oct[0] < 128 ? 'A' : p.oct[0] < 192 ? 'B' : p.oct[0] < 224 ? 'C' : p.oct[0] < 240 ? 'D (multicast)' : 'E (reserved)';
        var priv = (p.oct[0] === 10) || (p.oct[0] === 172 && p.oct[1] >= 16 && p.oct[1] <= 31) || (p.oct[0] === 192 && p.oct[1] === 168);

        return {
            network: ip(network), broadcast: ip(broadcast), mask: ip(mask), prefix: p.prefix, wild: ip(wild),
            first: ip(first), last: ip(last), klass: klass, hosts: Math.max(0, total - 2),
            total: total, priv: priv, loop: p.oct[0] === 127
        };
    }

    function nf(n) { return n.toLocaleString('ru-RU'); }

    function compute() {
        var r = report(input.value);
        if (!r) {
            out.innerHTML = '<p class="col-span-full text-rose-600">Не удалось разобрать подсеть. Формат: a.b.c.d/n.</p>';
            return;
        }
        var rows = [
            ['Адрес сети', r.network + ' /' + r.prefix],
            ['Broadcast', r.broadcast],
            ['Маска', r.mask],
            ['Wildcard', r.wild],
            ['Первый хост', r.first],
            ['Последний хост', r.last],
            ['Класс', r.klass + (r.priv ? ' · приватная' : '') + (r.loop ? ' · loopback' : '')],
            ['Хостов', nf(r.hosts) + '  (всего ' + nf(r.total) + ' адресов)']
        ];
        out.innerHTML = rows.map(function (row) {
            return '<div class="flex items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-950">' +
                '<span class="text-gray-500 dark:text-gray-400">' + row[0] + '</span>' +
                '<span class="break-all text-right font-bold text-gray-800 dark:text-gray-100">' + row[1] + '</span></div>';
        }).join('');
    }

    input.addEventListener('input', compute);
    go.addEventListener('click', compute);
    document.querySelectorAll('.nj630-example').forEach(function (b) {
        b.addEventListener('click', function () { input.value = b.getAttribute('data-value'); compute(); });
    });

    compute();
})();
</script>
