<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj530_jump: инлайн-скрипт (копирование короткой ссылки) -->
<script>
(function () {
    'use strict';

    // QR-код созданной ссылки (SVG, локальная библиотека qrcode-generator)
    var qrBox = document.getElementById('nj530-qr');

    if (qrBox && typeof window.qrcode === 'function') {
        var qr = window.qrcode(0, 'M');
        qr.addData(qrBox.getAttribute('data-qr-url') || window.location.href);
        qr.make();
        qrBox.innerHTML = qr.createSvgTag({ cellSize: 3, margin: 0, scalable: true });
        var svg = qrBox.querySelector('svg');
        if (svg) { svg.setAttribute('width', '120'); svg.setAttribute('height', '120'); }
    }

    var $copy = document.getElementById('jump-copy');
    var $short = document.getElementById('jump-short');

    function toast(msg) { N2J.toast(msg); }

    if ($copy && $short) {
        $copy.addEventListener('click', function () {
            var text = $short.textContent;
            N2J.copyText(text).then(function () { toast('Короткая ссылка скопирована'); }, function () { N2J.toast('Не удалось скопировать'); });
        });
    }
})();
</script>
