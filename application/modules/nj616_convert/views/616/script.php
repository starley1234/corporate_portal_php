<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj616_convert — клиентское зеркало Service_Nj616_Convert (Base64/URL). */
(function () {
    'use strict';
    var input = document.getElementById('nj616-input');
    var output = document.getElementById('nj616-output');
    var errorEl = document.getElementById('nj616-error');
    var copyBtn = document.getElementById('nj616-copy');
    var swapBtn = document.getElementById('nj616-swap');

    function mode() {
        var el = document.querySelector('input[name="nj616-mode"]:checked');
        return el ? el.value : 'b64_encode';
    }

    // UTF-8-безопасный Base64 (браузерный btoa не умеет кириллицу)
    function b64Encode(s) {
        var bytes = new TextEncoder().encode(s);
        var bin = '';
        bytes.forEach(function (b) { bin += String.fromCharCode(b); });
        return btoa(bin);
    }
    function b64Decode(s) {
        try {
            var bin = atob(s.replace(/[\s\r\n]/g, ''));
            var bytes = new Uint8Array(bin.length);
            for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
            return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
        } catch (e) { return null; }
    }

    function run() {
        var text = input.value;
        if (!text) {
            output.innerHTML = '<span class="text-gray-400">Результат появится здесь.</span>';
            errorEl.classList.add('hidden');
            copyBtn.disabled = true;
            updateUrl('', mode());
            return;
        }
        var m = mode();
        var res = null;
        switch (m) {
            case 'b64_encode': res = b64Encode(text); break;
            case 'b64_decode': res = b64Decode(text); break;
            case 'url_encode': res = encodeURIComponent(text).replace(/%20/g, '+'); break;
            case 'url_decode': res = decodeURIComponent(text.replace(/\+/g, ' ')); break;
            case 'urlraw_encode': res = encodeURIComponent(text); break;
            case 'urlraw_decode': res = decodeURIComponent(text); break;
        }
        if (res === null) {
            errorEl.classList.remove('hidden');
            errorEl.textContent = 'Невалидный Base64 — проверьте входную строку.';
            output.innerHTML = '';
            copyBtn.disabled = true;
            return;
        }
        errorEl.classList.add('hidden');
        output.textContent = res;
        copyBtn.disabled = false;
        updateUrl(text, m);
    }

    function updateUrl(text, m) {
        try {
            if (!text) { history.replaceState(null, '', location.pathname); return; }
            var p = new URLSearchParams(location.search);
            if (text.length < 2000) p.set('q', text); else p.delete('q');
            p.set('m', m);
            var qs = p.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        } catch (e) { }
    }

    function toast(msg) { N2J.toast(msg); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    var deb = null;
    input.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(run, 150); });
    document.querySelectorAll('input[name="nj616-mode"]').forEach(function (r) { r.addEventListener('change', run); });
    copyBtn.addEventListener('click', function () { var t = output.textContent; if (t) copyText(t); });

    swapBtn.addEventListener('click', function () {
        var cur = output.textContent.trim();
        if (!cur) return;
        input.value = cur;
        var m = mode();
        var invert = { b64_encode: 'b64_decode', b64_decode: 'b64_encode', url_encode: 'url_decode', url_decode: 'url_encode', urlraw_encode: 'urlraw_decode', urlraw_decode: 'urlraw_encode' };
        var target = invert[m] || m;
        document.querySelector('input[name="nj616-mode"][value="' + target + '"]').checked = true;
        run();
        input.focus();
    });

    if (input.value.trim()) run(); else { copyBtn.disabled = true; }
})();
</script>
