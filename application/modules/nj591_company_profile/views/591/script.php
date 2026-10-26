<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Инлайновый JS страницы профиля /company/<code>:
 * пока сбор идёт — баннер с колёсиком и СЕКУНДОМЕРОМ рядом + фазы воркера;
 * готово/ошибка — один reload; кнопки «Скопировать JSON».
 */
?>

<script>
(function () {

    // ---- копирование JSON ----
    document.querySelectorAll('[data-n591-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var done = function () {
                var old = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check mr-1"></i>Скопировано';
                setTimeout(function () { btn.innerHTML = old; }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(btn.getAttribute('data-n591-copy')).then(done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = btn.getAttribute('data-n591-copy');
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                done();
            }
        });
    });

    /* Живой статус-ряд (спиннер, секундомер, этап, «нет пульса», остановка)
     * рисует ОБЩИЙ механизм — partials/live_status + partials/live_status_js
     * (эталон /trends/<code>). Свой баннер со своим поллингом и своим
     * таймером удалён: он показывал «Воркер работает…» и при молчащем
     * воркере, и при застывшем прогрессе — то есть ровно в тех случаях,
     * когда владелец видел «зависло».
     */
})();
</script>
