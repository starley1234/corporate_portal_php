<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * /receipt — единственная задача скрипта: довести кадр до страницы разбора.
 *
 * Весь конвейер (съёмка, уменьшение кадра, отправка, живой статус-ряд, опрос
 * статуса) делает общий `window.N2JVision`. Здесь только то, что своё:
 * куда идти, когда чек прочитан.
 */
(function () {
    'use strict';

    if (!window.N2JVision) { return; }

    var box = N2JVision.mount('nj650', {
        onDone: function (result, data) {
            // Разбор постоянный: у него свой адрес, его можно переслать тем,
            // с кем делите счёт, и открыть из «Моих чеков».
            window.location.href = '/receipt/' + Number(data.run_id);
        }
    });

    if (box) { box.tell(''); }
})();
</script>
