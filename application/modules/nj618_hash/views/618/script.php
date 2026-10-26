<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj618_hash — хеши MD5/SHA локально через WebCrypto (клиентское зеркало Service_Nj618_Hash). */
(function () {
    'use strict';
    var input = document.getElementById('nj618-input');
    var upperChk = document.getElementById('nj618-upper');

    function algoSelValue() {
        var el = document.querySelector('input[name="nj618-algo"]:checked');
        return el ? el.value : 'sha256';
    }
    var results = document.getElementById('nj618-results');
    var copyBtn = document.getElementById('nj618-copy');

    var ALGOS = {
        md5: { name: 'MD5', async: true, len: 32 },
        sha1: { name: 'SHA-1', async: false, len: 40 },
        sha256: { name: 'SHA-256', async: false, len: 64 },
        sha384: { name: 'SHA-384', async: false, len: 96 },
        sha512: { name: 'SHA-512', async: false, len: 128 }
    };

    // MD5 нет в WebCrypto — компактная JS-реализация (для небольших строк)
    function md5(s) {
        function rl(v, s) { return (v << s) | (v >>> (32 - s)); }
        function add(x, y) { var l = (x & 0xffff) + (y & 0xffff); var m = (x >> 16) + (y >> 16) + (l >> 16); return (m << 16) | (l & 0xffff); }
        function cmn(q, a, b, x, s2, t) { return add(rl(add(add(a, q), add(x, t)), s2), b); }
        function ff(a, b, c, d, x, s2, t) { return cmn((b & c) | (~b & d), a, b, x, s2, t); }
        function gg(a, b, c, d, x, s2, t) { return cmn((b & d) | (c & ~d), a, b, x, s2, t); }
        function hh(a, b, c, d, x, s2, t) { return cmn(b ^ c ^ d, a, b, x, s2, t); }
        function ii(a, b, c, d, x, s2, t) { return cmn(c ^ (b | ~d), a, b, x, s2, t); }

        var bytes = [];
        var enc = new TextEncoder().encode(s);
        var i, k = enc.length, n = 0;
        var len = k * 8;
        for (i = 0; i < k; i++) bytes.push(enc[i]);
        bytes.push(0x80);
        while (bytes.length % 64 !== 56) bytes.push(0);
        for (i = 0; i < 8; i++) bytes.push((len >>> (i * 8)) & 0xff);

        var a = 0x67452301, b = 0xefcdab89, c = 0x98badcfe, d = 0x10325476;

        for (i = 0; i < bytes.length; i += 64) {
            var M = [];
            for (var j = 0; j < 16; j++) {
                M[j] = bytes[i + j * 4] | (bytes[i + j * 4 + 1] << 8) | (bytes[i + j * 4 + 2] << 16) | (bytes[i + j * 4 + 3] << 24);
            }
            var aa = a, bb = b, cc = c, dd = d;
            a = ff(a, b, c, d, M[0], 7, 0xd76aa478); d = ff(d, a, b, c, M[1], 12, 0xe8c7b756); c = ff(c, d, a, b, M[2], 17, 0x242070db); b = ff(b, c, d, a, M[3], 22, 0xc1bdceee);
            a = ff(a, b, c, d, M[4], 7, 0xf57c0faf); d = ff(d, a, b, c, M[5], 12, 0x4787c62a); c = ff(c, d, a, b, M[6], 17, 0xa8304613); b = ff(b, c, d, a, M[7], 22, 0xfd469501);
            a = ff(a, b, c, d, M[8], 7, 0x698098d8); d = ff(d, a, b, c, M[9], 12, 0x8b44f7af); c = ff(c, d, a, b, M[10], 17, 0xffff5bb1); b = ff(b, c, d, a, M[11], 22, 0x895cd7be);
            a = ff(a, b, c, d, M[12], 7, 0x6b901122); d = ff(d, a, b, c, M[13], 12, 0xfd987193); c = ff(c, d, a, b, M[14], 17, 0xa679438e); b = ff(b, c, d, a, M[15], 22, 0x49b40821);
            a = gg(a, b, c, d, M[1], 5, 0xf61e2562); d = gg(d, a, b, c, M[6], 9, 0xc040b340); c = gg(c, d, a, b, M[11], 14, 0x265e5a51); b = gg(b, c, d, a, M[0], 20, 0xe9b6c7aa);
            a = gg(a, b, c, d, M[5], 5, 0xd62f105d); d = gg(d, a, b, c, M[10], 9, 0x02441453); c = gg(c, d, a, b, M[15], 14, 0xd8a1e681); b = gg(b, c, d, a, M[4], 20, 0xe7d3fbc8);
            a = gg(a, b, c, d, M[9], 5, 0x21e1cde6); d = gg(d, a, b, c, M[14], 9, 0xc33707d6); c = gg(c, d, a, b, M[3], 14, 0xf4d50d87); b = gg(b, c, d, a, M[8], 20, 0x455a14ed);
            a = gg(a, b, c, d, M[13], 5, 0xa9e3e905); d = gg(d, a, b, c, M[2], 9, 0xfcefa3f8); c = gg(c, d, a, b, M[7], 14, 0x676f02d9); b = gg(b, c, d, a, M[12], 20, 0x8d2a4c8a);
            a = hh(a, b, c, d, M[5], 4, 0xfffa3942); d = hh(d, a, b, c, M[8], 11, 0x8771f681); c = hh(c, d, a, b, M[11], 16, 0x6d9d6122); b = hh(b, c, d, a, M[14], 23, 0xfde5380c);
            a = hh(a, b, c, d, M[1], 4, 0xa4beea44); d = hh(d, a, b, c, M[4], 11, 0x4bdecfa9); c = hh(c, d, a, b, M[7], 16, 0xf6bb4b60); b = hh(b, c, d, a, M[10], 23, 0xbebfbc70);
            a = hh(a, b, c, d, M[13], 4, 0x289b7ec6); d = hh(d, a, b, c, M[0], 11, 0xeaa127fa); c = hh(c, d, a, b, M[3], 16, 0xd4ef3085); b = hh(b, c, d, a, M[6], 23, 0x04881d05);
            a = hh(a, b, c, d, M[9], 4, 0xd9d4d039); d = hh(d, a, b, c, M[12], 11, 0xe6db99e5); c = hh(c, d, a, b, M[15], 16, 0x1fa27cf8); b = hh(b, c, d, a, M[2], 23, 0xc4ac5665);
            a = ii(a, b, c, d, M[0], 6, 0xf4292244); d = ii(d, a, b, c, M[7], 10, 0x432aff97); c = ii(c, d, a, b, M[14], 15, 0xab9423a7); b = ii(b, c, d, a, M[5], 21, 0xfc93a039);
            a = ii(a, b, c, d, M[12], 6, 0x655b59c3); d = ii(d, a, b, c, M[3], 10, 0x8f0ccc92); c = ii(c, d, a, b, M[10], 15, 0xffeff47d); b = ii(b, c, d, a, M[1], 21, 0x85845dd1);
            a = ii(a, b, c, d, M[8], 6, 0x6fa87e4f); d = ii(d, a, b, c, M[15], 10, 0xfe2ce6e0); c = ii(c, d, a, b, M[6], 15, 0xa3014314); b = ii(b, c, d, a, M[13], 21, 0x4e0811a1);
            a = ii(a, b, c, d, M[4], 6, 0xf7537e82); d = ii(d, a, b, c, M[11], 10, 0xbd3af235); c = ii(c, d, a, b, M[2], 15, 0x2ad7d2bb); b = ii(b, c, d, a, M[9], 21, 0xeb86d391);
            a = add(a, aa); b = add(b, bb); c = add(c, cc); d = add(d, dd);
        }

        function hx(v) { return ('00000000' + (v >>> 0).toString(16)).slice(-8); }
        return hx(a) + hx(b) + hx(c) + hx(d);
    }

    // MD5 обёртка с эмуляцией «известных» значений для сверки — используется
    // напрямую; остальные алгоритмы — через async WebCrypto (sha1 недоступен
    // в WebCrypto — используем тот же subtle.digest, где браузер даёт SHA-1).
    async function shaHex(algo, text) {
        var data = new TextEncoder().encode(text);
        var buf = await crypto.subtle.digest(algo, data);
        return Array.prototype.map.call(new Uint8Array(buf), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    }

    async function run() {
        var text = input.value;
        var algo = algoSelValue();
        var upper = upperChk.checked;

        if (!text) {
            results.innerHTML = '<p class="text-xs text-gray-400 sm:col-span-3">Введите текст — появятся хеши всех алгоритмов.</p>';
            copyBtn.disabled = true;
            return;
        }

        var rows = [];
        for (var code in ALGOS) {
            var hex;
            if (code === 'md5') hex = md5(text);
            else if (code === 'sha1') hex = await shaHex('SHA-1', text);
            else hex = await shaHex(ALGOS[code].name, text);
            if (upper) hex = hex.toUpperCase();
            rows.push({ code: code, name: ALGOS[code].name, hex: hex });
        }

        results.innerHTML = rows.map(function (r) {
            var active = algoSelValue() === r.code;
            return '<div class="rounded-xl border p-4 shadow-xs ' + (active ? 'border-2 border-blue-500 bg-blue-50/60 dark:border-blue-500/60 dark:bg-blue-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800') + '">' +
                '<p class="text-xs font-semibold uppercase tracking-wide ' + (active ? 'text-blue-700 dark:text-blue-300' : 'text-gray-500 dark:text-gray-400') + '">' + r.name + '</p>' +
                '<p class="mt-1 break-all font-mono text-xs leading-relaxed ' + (active ? 'text-blue-800 dark:text-blue-200' : 'text-gray-700 dark:text-gray-200') + '">' + r.hex + '</p></div>';
        }).join('');
        copyBtn.disabled = false;
    }

    function currentHex() {
        var text = input.value, algo = algoSelValue(), upper = upperChk.checked;
        if (!text) return null;
        var hex;
        if (algo === 'md5') hex = md5(text); else return null; // асинхронные — см. run()
        return upper ? hex.toUpperCase() : hex;
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    var deb = null;
    input.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(run, 250); });
    document.querySelectorAll('input[name="nj618-algo"]').forEach(function (r) { r.addEventListener('change', run); });
    upperChk.addEventListener('change', run);

    copyBtn.addEventListener('click', async function () {
        var text = input.value, algo = algoSelValue(), upper = upperChk.checked;
        if (!text) return;
        var hex = algo === 'md5' ? md5(text) : algo === 'sha1' ? await shaHex('SHA-1', text) : await shaHex(ALGOS[algo].name, text);
        copyText(upper ? hex.toUpperCase() : hex);
    });

    run();
})();
</script>
