<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инлайновый JS страницы прогона /design/<code>. Живой статус-ряд —
 * общий механизм (partials/live_status): свой баннер удалён.
 */
?>

<script>
(function () {
    /* Живой статус-ряд (спиннер, секундомер от фактического времени, этап,
     * «нет пульса», остановка) рисует ОБЩИЙ механизм — partials/live_status +
     * partials/live_status_js (эталон /trends/<code>). Свой баннер со своим
     * поллингом и своим таймером удалён: он «замирал» вместе с воркером.
     */
})();
</script>
