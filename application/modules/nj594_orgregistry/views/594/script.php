<?php defined('SYSPATH') or die('No direct script access.');

/**
 * JS справочника организаций (nj594): занятость кнопок обновления из ФНС (запрос занимает несколько секунд).
 */
?>

<script>
(function () {
    'use strict';

    /* Обновление из ФНС: блокируем повторную отправку и показываем прогресс. */
    document.querySelectorAll('form.nj594-refresh').forEach(function (form) {
        form.addEventListener('submit', function () {
            var label = form.querySelector('.nj594-refresh-label');
            var btn   = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('opacity-70');
            }
            if (label) {
                label.dataset.orig = label.textContent;
                label.textContent = 'Запрашиваю ФНС…';
            }
            /* страховка: вернуть кнопку через 30 с, если что-то пошло не так */
            setTimeout(function () {
                if (btn) { btn.disabled = false; btn.classList.remove('opacity-70'); }
                if (label && label.dataset.orig) { label.textContent = label.dataset.orig; }
            }, 30000);
        });
    });
})();
</script>
