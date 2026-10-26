<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * /plant — после разбора увести посетителя на карточку растения.
 *
 * Съёмка, отправка и статус-ряд — общий `window.N2JVision`; своего поведения
 * у страницы нет, поэтому здесь только переход.
 */
(function () {
    'use strict';

    if (!window.N2JVision) { return; }

    N2JVision.mount('nj652', {
        onDone: function (result, data) {
            window.location.href = '/plant/' + Number(data.run_id);
        }
    });
})();
</script>
