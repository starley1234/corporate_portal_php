<?php defined('SYSPATH') or die('No direct script access.'); ?>
<!-- nj649_diff: страница сравнения — копирование готовой заплатки -->
<script>
(function () {
    'use strict';

    var btn = document.getElementById('diff-copy');
    var box = document.getElementById('diff-unified');

    if (!btn || !box) return;

    btn.addEventListener('click', function () {
        var text = box.textContent || '';
        var done = function () {
            if (window.N2J && N2J.toast) { N2J.toast('Заплатка скопирована'); }
        };

        if (window.N2J && N2J.copyText) {
            N2J.copyText(text).then(done, function () { });
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { });
        }
    });
})();
</script>
