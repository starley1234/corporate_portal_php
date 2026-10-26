<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj620_jwt — разбор JWT локально (клиентское зеркало Service_Nj620_Jwt). */
(function () {
    'use strict';
    var tokenEl = document.getElementById('nj620-token');
    var out = document.getElementById('nj620-out');
    var demoBtn = document.getElementById('nj620-demo');
    var clearBtn = document.getElementById('nj620-clear');

    function b64urlDecode(seg) {
        var b64 = seg.replace(/-/g, '+').replace(/_/g, '/');
        while (b64.length % 4) b64 += '=';
        var bin = atob(b64);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new TextDecoder('utf-8', { fatal: false }).decode(bytes);
    }

    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function claimDate(name, v) {
        if (!isFinite(v)) return '';
        var iso = new Date(v * 1000).toISOString();
        var local = new Date(v * 1000).toLocaleString('ru-RU', { timeZone: 'Europe/Moscow', hour12: false });
        return '<div class="mt-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-950">' +
            '<span class="font-semibold text-gray-700 dark:text-gray-200">' + name + '</span> = ' + v +
            '<p class="text-xs text-gray-500 dark:text-gray-400">' + esc(iso) + '</p>' +
            '<p class="text-xs text-gray-500 dark:text-gray-400">' + esc(local) + ' (Москва)</p></div>';
    }

    function section(title, obj, tone) {
        var pre = JSON.stringify(obj, null, 2);
        var body = pre ? '<pre class="mt-2 overflow-auto rounded-lg bg-gray-50 p-4 font-mono text-xs text-gray-800 dark:bg-gray-950 dark:text-gray-100">' + esc(pre) + '</pre>' : '';
        var dates = '';
        if (obj) {
            ['exp', 'iat', 'nbf'].forEach(function (k) { if (obj[k] !== undefined) dates += claimDate(k, obj[k]); });
        }
        return '<div class="rounded-xl border p-4 sm:p-5 shadow-xs ' + (tone === 'blue' ? 'border-2 border-blue-500 bg-blue-50/60 dark:border-blue-500/60 dark:bg-blue-900/20' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800') + '">' +
            '<p class="text-sm font-semibold ' + (tone === 'blue' ? 'text-blue-700 dark:text-blue-300' : 'text-gray-700 dark:text-gray-300') + '">' + title + '</p>' + body + dates + '</div>';
    }

    function render() {
        var token = tokenEl.value.trim();
        if (!token) { out.innerHTML = ''; return; }

        var parts = token.split('.');
        if (parts.length !== 3) {
            out.innerHTML = '<div class="n2j-flash n2j-flash-err block">Токен должен состоять из трёх секций через точку: header.payload.signature.</div>';
            return;
        }

        var html = '';
        try {
            var header = JSON.parse(b64urlDecode(parts[0]));
            html += section('Заголовок (header)', header, 'blue');
        } catch (e) { html += '<div class="n2j-flash n2j-flash-err block">Header — не JSON: ' + esc(e.message) + '</div>'; }

        try {
            var payload = JSON.parse(b64urlDecode(parts[1]));
            html += section('Данные (payload)', payload, 'blue');
        } catch (e) { html += '<div class="n2j-flash n2j-flash-err block">Payload — не JSON: ' + esc(e.message) + '</div>'; }

        html += '<div class="n2j-card">' +
            '<p class="text-sm font-semibold text-gray-700 dark:text-gray-300">Подпись (signature)</p>' +
            '<p class="mt-2 break-all font-mono text-xs text-gray-500 dark:text-gray-400">' + esc(parts[2]) + '</p></div>';

        out.innerHTML = html;
        updateUrl(token);
    }

    function updateUrl(t) {
        try {
            var p = new URLSearchParams(location.search);
            if (t.length < 2500) p.set('t', t); else p.delete('t');
            var qs = p.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        } catch (e) { }
    }

    // base64url без ошибки на кириллице: btoa падает на не-Latin1 символах,
    // поэтому гоняем через байты UTF-8.
    function b64url(o) {
        var json = JSON.stringify(o);
        var bytes = new TextEncoder().encode(json);
        var bin = '';
        bytes.forEach(function (b) { bin += String.fromCharCode(b); });
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    var deb = null;
    tokenEl.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(render, 200); });
    demoBtn.addEventListener('click', function () {
        var h = { alg: 'HS256', typ: 'JWT' };
        var now = Math.floor(Date.now() / 1000);
        var p = { sub: '1234567890', name: 'Иван Иванов', email: 'ivan@example.com', iat: now, exp: now + 3600, iss: 'https://n2j.ru', aud: 'n2j-app' };
        tokenEl.value = b64url(h) + '.' + b64url(p) + '.' + 'SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
        render();
        tokenEl.focus();
    });
    clearBtn.addEventListener('click', function () { tokenEl.value = ''; render(); tokenEl.focus(); });

    if (tokenEl.value.trim()) render();
})();
</script>
