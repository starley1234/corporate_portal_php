<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Просмотр картинки целиком (модальное окно).
 *
 * Подключается на любой странице модуля, где есть миниатюры. Разметка одна
 * на страницу, а открывает её любой элемент с атрибутами:
 *   data-nj654-zoom="<адрес файла>"  — обязательный,
 *   data-nj654-zoom-title="<подпись>" — необязательный.
 *
 * Новая вкладка для этого не годится: человек листает находки и хочет
 * вернуться к списку одним нажатием Esc, а не кнопкой «назад». Ссылка на
 * сам файл при этом никуда не девается — она в подвале окна.
 */
?>

<div id="nj654-zoom" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/80 p-4 backdrop-blur-xs"
     role="dialog" aria-modal="true" aria-label="Просмотр картинки">

    <figure class="relative flex max-h-full w-full max-w-5xl flex-col items-center gap-3">
        <img data-nj654-zoom-img src="" alt=""
             class="max-h-[78vh] max-w-full rounded-xl bg-white object-contain shadow-sm dark:bg-gray-900">

        <figcaption class="flex max-w-full flex-wrap items-center justify-center gap-2.5 text-white">
            <span data-nj654-zoom-title class="max-w-full truncate text-sm font-semibold"></span>
            <a data-nj654-zoom-link href="#" target="_blank" rel="noopener"
               class="inline-flex min-h-[36px] items-center gap-1.5 rounded-lg bg-white/15 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/25">
                <i class="fas fa-up-right-from-square text-[11px]"></i>Файл целиком
            </a>
        </figcaption>

        <button type="button" data-nj654-zoom-close aria-label="Закрыть"
                class="absolute -top-2 right-0 inline-flex h-9 w-9 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/30">
            <i class="fas fa-xmark"></i>
        </button>
    </figure>
</div>

<script>
// Одно окно на страницу: слушаем клики по всему документу, поэтому плитки,
// строки таблиц и миниатюры, появившиеся позже, работают без доработок.
(function () {
    var box = document.getElementById('nj654-zoom');

    if (!box) { return; }

    var img   = box.querySelector('[data-nj654-zoom-img]');
    var cap   = box.querySelector('[data-nj654-zoom-title]');
    var link  = box.querySelector('[data-nj654-zoom-link]');

    function close() {
        box.classList.add('hidden');
        box.classList.remove('flex');
        img.src = '';
        document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('click', function (e) {
        var hit = e.target.closest ? e.target.closest('[data-nj654-zoom]') : null;

        if (hit) {
            e.preventDefault();
            var src = hit.getAttribute('data-nj654-zoom');
            var ttl = hit.getAttribute('data-nj654-zoom-title') || '';
            img.src = src;
            img.alt = ttl;
            cap.textContent = ttl;
            link.href = src;
            box.classList.remove('hidden');
            box.classList.add('flex');
            document.body.classList.add('overflow-hidden');

            return;
        }

        if (box.classList.contains('hidden')) { return; }

        // Клик мимо картинки (по затемнению) и крестик — закрывают.
        if (e.target === box || (e.target.closest && e.target.closest('[data-nj654-zoom-close]'))) {
            close();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { close(); }
    });
})();
</script>
