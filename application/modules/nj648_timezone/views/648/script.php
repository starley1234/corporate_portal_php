<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj648_timezone: страница созвона — копирование ссылки с составом участников -->
<script>
(function () {
    'use strict';

    var copy = document.getElementById('tz-copy');

    if (!copy) return;

    copy.addEventListener('click', function () {
        // Состав участников, дата и рамки рабочего дня уже в адресе страницы
        var url = window.location.href;
        var done = function () {
            if (window.N2J && N2J.toast) { N2J.toast('Ссылка скопирована — можно слать коллегам'); }
        };

        if (window.N2J && N2J.copyText) {
            N2J.copyText(url).then(done, function () { });
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, function () { });
        }
    });
})();
</script>
