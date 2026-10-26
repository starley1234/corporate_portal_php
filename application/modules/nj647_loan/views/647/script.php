<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj647_loan: переключение способа платежа и копирование ссылки на расчёт -->
<script>
(function () {
    'use strict';

    var form = document.getElementById('loan-form');

    // Тип платежа влияет на график и сумму переплаты, поэтому выбор сразу
    // отправляет обычную GET-форму на сервер — формул в браузере нет.
    if (form) {
        form.querySelectorAll('[data-loan-payment-type]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (!radio.checked) return;
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            });
        });
    }

    var copy = document.getElementById('loan-copy');
    if (!copy) return;

    copy.addEventListener('click', function () {
        // Все поля формы уже в адресе: ссылка открывает ровно этот расчёт.
        var url = window.location.href;
        var done = function () {
            if (window.N2J && N2J.toast) { N2J.toast('Ссылка на расчёт скопирована'); }
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
