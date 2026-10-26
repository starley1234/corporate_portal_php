<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Микроскрипты «Отзыв 2 Ответ» (инлайн, без внешних файлов).
 * Счётчик формы, защита от двойной отправки и копирование ответа.
 * Живой статус-ряд — общий (partials/live_status_js), здесь его нет.
 */
?>
<script>
(function () {
    'use strict';

    // --- Форма: счётчик символов + блокировка кнопки до минимума -------------
    var review = document.getElementById('nj540-review');
    var submit = document.getElementById('nj540-submit');

    if (review && submit) {
        var counter = document.getElementById('nj540-counter');
        var minLen = <?php echo (int) $min_len; ?>;

        var sync = function () {
            var len = review.value.length;
            if (counter) {
                counter.textContent = len + ' / <?php echo (int) $max_len; ?>'
                    + (len && len < minLen ? '  ·  минимум ' + minLen : '');
            }
            submit.disabled = len < minLen;
        };

        review.addEventListener('input', sync);
        sync();

        // защита от двойной отправки
        review.form.addEventListener('submit', function () {
            if (!submit.disabled) {
                submit.disabled = true;
                submit.innerHTML = '<i class="fas fa-circle-notch fa-spin mr-1"></i>Ставим в очередь…';
            } else {
                window.setTimeout(function () { submit.disabled = false; }, 500);
            }
        });
    }

    // --- Копирование ответа --------------------------------------------------
    var copyBtn = document.getElementById('nj540-copy');

    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var done = function () {
                copyBtn.textContent = 'Скопировано ✓';
                window.setTimeout(function () { copyBtn.textContent = 'Скопировать'; }, 2000);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(copyBtn.getAttribute('data-text')).then(done);
            } else {
                var r = document.createRange();
                r.selectNodeContents(document.getElementById('nj540-reply'));
                var s = window.getSelection();
                s.removeAllRanges();
                s.addRange(r);
                document.execCommand('copy');
                done();
            }
        });
    }

})();
</script>
