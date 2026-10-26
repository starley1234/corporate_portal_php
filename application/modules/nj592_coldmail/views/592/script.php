<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инлайновый JS страницы письма /coldmail/<code>: копирование абзацев.
 * Удаление спрашивает окном (data-n2j-confirm, app.js). Живой статус-ряд — общий механизм
 * (partials/live_status), свой поллинг удалён.
 */
?>

<script>
(function () {

    // ---- копирование абзацев ----
    document.querySelectorAll('[data-n592-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var done = function () {
                var old = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check mr-1"></i>Скопировано';
                setTimeout(function () { btn.innerHTML = old; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(btn.getAttribute('data-n592-copy')).then(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = btn.getAttribute('data-n592-copy');
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                done();
            }
        });
    });

    /* Живой статус-ряд (спиннер, секундомер от фактического времени, этап,
     * «нет пульса», остановка) рисует ОБЩИЙ механизм — partials/live_status +
     * partials/live_status_js (эталон /trends/<code>). Свой баннер со своим
     * поллингом и своим таймером удалён: он «замирал» вместе с воркером.
     */
})();
</script>
