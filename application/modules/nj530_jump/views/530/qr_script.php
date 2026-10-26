<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj530: страница ссылки /x/<code>/qr — QR + копирование -->
<script>
(function () {
    'use strict';

    // QR-код (SVG, локальная библиотека qrcode-generator)
    var qrBox = document.getElementById('nj530-qr-page');

    if (qrBox && typeof window.qrcode === 'function') {
        var qr = window.qrcode(0, 'M');
        qr.addData(qrBox.getAttribute('data-qr-url') || window.location.href);
        qr.make();
        qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
        var svg = qrBox.querySelector('svg');
        if (svg) { svg.setAttribute('width', '160'); svg.setAttribute('height', '160'); }
    }

    // Копирование короткой ссылки
    var $copy = document.getElementById('nj530-qr-copy');

    function toast(msg) { N2J.toast(msg); }

    if ($copy) {
        $copy.addEventListener('click', function () {
            var url = qrBox ? qrBox.getAttribute('data-qr-url') : window.location.href;
            N2J.copyText(url).then(function () { toast('Короткая ссылка скопирована'); }, function () { N2J.toast('Не удалось скопировать'); });
        });
    }
})();
</script>
