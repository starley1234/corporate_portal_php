<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * /meal — довести кадр до страницы разбора, сохранив профиль в адресе.
 *
 * Съёмку, отправку и живой статус-ряд делает общий `window.N2JVision`.
 * Своё здесь одно: профиль (пол, вес, рост, возраст, активность, цель) должен
 * уехать вместе с переходом, иначе на странице разбора доля дневной нормы
 * считалась бы по «среднему взрослому», а не по человеку.
 */
(function () {
    'use strict';

    if (!window.N2JVision) { return; }

    var PROFILE = <?php echo json_encode($profile, JSON_UNESCAPED_UNICODE); ?>;

    function query() {
        return Object.keys(PROFILE).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(PROFILE[key]);
        }).join('&');
    }

    N2JVision.mount('nj651', {
        onDone: function (result, data) {
            window.location.href = '/meal/' + Number(data.run_id) + '?' + query();
        }
    });
})();
</script>
