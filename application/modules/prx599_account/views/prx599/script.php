<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx599: автоотправка кода 2FA и локальный QR второго фактора. Опасные
 * действия (2FA-выкл, «выйти везде») — вторым нажатием, data-prx-arm (app.js).
 */
?>

<script>
(function () {
    'use strict';

    /* автозаполнение: если в поле 2FA ввели 6 цифр — отправляем */
    var code = document.getElementById('prx599-code');
    if (code) {
        code.addEventListener('input', function () {
            if (/^\d{6}$/.test(code.value.trim())) { code.form.submit(); }
        });
    }
})();
</script>

<script>
(function () {
    'use strict';

    // QR второго фактора — локально, той же библиотекой, что и в prx530.
    // Секрет TOTP не должен покидать браузер: до 28.09.2026 он уезжал в
    // адресе картинки на сторонний сервис генерации QR.
    var box = document.getElementById('prx599-totp-qr');

    if ( ! box || typeof window.qrcode !== 'function') {
        return;
    }

    var uri = box.getAttribute('data-qr-uri') || '';

    if (uri === '') {
        return;
    }

    var qr = window.qrcode(0, 'M');
    qr.addData(uri);
    qr.make();
    box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });

    var svg = box.querySelector('svg');

    if (svg) {
        svg.setAttribute('width', '100%');
        svg.setAttribute('height', '100%');
    }
})();
</script>
