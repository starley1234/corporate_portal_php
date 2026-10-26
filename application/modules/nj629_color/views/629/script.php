<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj629_color — конвертер цветов (клиентское зеркало Service_Nj629_Color). */
(function () {
    'use strict';
    var input = document.getElementById('nj629-in');
    var picker = document.getElementById('nj629-picker');
    var swatch = document.getElementById('nj629-swatch');
    var values = document.getElementById('nj629-values');
    var shades = document.getElementById('nj629-shades');
    var copyBtn = document.getElementById('nj629-copy');

    function parse(value) {
        var v = String(value).toLowerCase().trim();
        var m;
        if ((m = v.match(/^#?([0-9a-f]{3}|[0-9a-f]{6})$/))) {
            var hex = m[1];
            if (hex.length === 3) hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            return [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
        }
        if ((m = v.match(/^(?:rgb\()?\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)?$/))) {
            var rgb = [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)];
            if (rgb.every(function (c) { return c >= 0 && c <= 255; })) return rgb;
        }
        return null;
    }

    function toHex(rgb) { return '#' + rgb.map(function (c) { return ('0' + c.toString(16)).slice(-2).toUpperCase(); }).join(''); }
    function toHsl(rgb) {
        var r = rgb[0] / 255, g = rgb[1] / 255, b = rgb[2] / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2;
        if (max === min) return [0, 0, Math.round(l * 100)];
        var d = max - min;
        var s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
        var h;
        switch (max) {
            case r: h = (g - b) / d + (g < b ? 6 : 0); break;
            case g: h = (b - r) / d + 2; break;
            default: h = (r - g) / d + 4;
        }
        return [Math.round(h * 60), Math.round(s * 100), Math.round(l * 100)];
    }

    function lighten(rgb, pct) {
        return rgb.map(function (c) { return Math.max(0, Math.min(255, Math.round(c + c * pct / 100))); });
    }

    function run() {
        var rgb = parse(input.value);
        if (!rgb) {
            values.innerHTML = '<p class="text-rose-600">Не удалось разобрать цвет. Примеры: #3B82F6, 59,130,246, rgb(59,130,246).</p>';
            shades.innerHTML = '<div class="col-span-5 text-xs text-gray-400">Оттенки появятся после ввода корректного цвета.</div>';
            copyBtn.disabled = true;
            return;
        }
        var hex = toHex(rgb);
        var hsl = toHsl(rgb);
        var luma = Math.round(0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]);
        swatch.style.background = hex;
        values.innerHTML =
            '<p class="flex flex-wrap gap-x-2"><span class="text-gray-400">HEX</span><b class="' + (luma > 128 ? 'text-gray-800' : 'text-white') + '" style="background:' + hex + ';padding:0 0.25rem;border-radius:0.25rem">' + hex + '</b></p>' +
            '<p class="flex flex-wrap gap-x-2"><span class="text-gray-400">RGB</span><b class="text-gray-900 dark:text-white">' + rgb.join(', ') + '</b></p>' +
            '<p class="flex flex-wrap gap-x-2"><span class="text-gray-400">HSL</span><b class="text-gray-900 dark:text-white">hsl(' + hsl[0] + ', ' + hsl[1] + '%, ' + hsl[2] + '%)</b></p>' +
            '<p class="text-xs text-gray-400">яркость ' + luma + ' → ' + (luma > 128 ? 'тёмный текст читается лучше' : 'светлый текст читается лучше') + '</p>';
        var sh = [-20, -10, 10, 20];
        shades.innerHTML = sh.map(function (p) {
            var c = toHex(lighten(rgb, p));
            return '<div class="text-center"><div class="h-10 rounded-lg" style="background:' + c + '"></div><span class="mt-1 block text-xs text-gray-400">' + (p > 0 ? '+' : '') + p + '%</span></div>';
        }).join('');
        // исходный цвет — последний квадрат (рамкой)
        shades.innerHTML += '<div class="text-center"><div class="h-10 rounded-lg ring-2 ring-blue-500 ring-offset-1" style="background:' + hex + '"></div><span class="mt-1 block text-xs text-gray-400">base</span></div>';
        picker.value = hex;
        copyBtn.disabled = false;
        copyBtn.__value = hex + '\n' + rgb.join(', ') + '\n' + 'hsl(' + hsl[0] + ', ' + hsl[1] + '%, ' + hsl[2] + '%)';
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    input.addEventListener('input', run);
    picker.addEventListener('input', function () { input.value = picker.value; run(); });
    copyBtn.addEventListener('click', function () { copyText(copyBtn.__value); });
    document.querySelectorAll('.nj629-example').forEach(function (b) {
        b.addEventListener('click', function () { input.value = b.getAttribute('data-value'); run(); });
    });

    run();
})();
</script>
