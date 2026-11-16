<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * /services — полный каталог опубликованных сервисов PRL.
 * SSR: всё видимо поисковику и без JS; поле поиска фильтрует на клиенте.
 */
?>

<?php /* каталог — каркас эталона v6: плоский фон, шапка page_header, разделы .prl-card */ ?>
<div class="prl-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
    <?php echo View::factory('partials/page_header', [
        'crumbs' => [['Главная', '/'], ['Сервисы']],
        'title'  => 'Каталог сервисов PRL',
        'lead'   => (int) $total.' инструментов по разделам: расчёты, документы, данные и ИИ.',
    ]); ?>


    <!-- Единый поиск по каталогу; область поиска не смешивается с поиском по содержимому сайта. -->
    <div class="mb-6 mt-6 max-w-2xl">
        <label class="sr-only" for="catalog-search">Поиск по каталогу сервисов</label>
        <div class="relative">
            <i class="fas fa-magnifying-glass pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-gray-400" aria-hidden="true"></i>
            <input id="catalog-search" type="search" autocomplete="off" spellcheck="false"
                   aria-controls="catalog-groups" aria-describedby="catalog-search-empty"
                   placeholder="Поиск по названию или ключевому слову…"
                   class="prl-input pl-10">
        </div>
        <p id="catalog-search-empty" class="prl-hint mt-2 hidden" role="status" aria-live="polite">Ничего не найдено — измените запрос или выберите раздел.</p>
    </div>

    <!-- CSS-колонки заполняются по высоте карточек: маленькие разделы не оставляют пустые ячейки рядом с большими. -->
    <div id="catalog-groups" class="columns-1 gap-5 [column-fill:balance] sm:columns-2 xl:columns-3 2xl:columns-4">
        <?php foreach ($groups as $g): ?>
        <section class="catalog-group prl-card mb-5 break-inside-avoid"
                 data-search="<?php echo HTML::chars(mb_strtolower($g['title'])); ?>">
            <a href="/services/<?php echo HTML::chars($g['slug']); ?>" class="group flex items-center gap-2.5">
                <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/80 dark:text-blue-400"><i class="<?php echo HTML::chars($g['icon']); ?>"></i></span>
                <h2 class="prl-h3 min-w-0 truncate"><?php echo HTML::chars($g['title']); ?></h2>
                <span class="prl-count ml-auto shrink-0"><?php echo (int) $g['count']; ?></span>
            </a>
            <ul class="mt-3.5 space-y-1 text-sm leading-snug text-gray-600 dark:text-gray-300">
                <?php foreach ($g['items'] as $m): ?>
                <li class="catalog-item group/item flex items-center gap-1 break-inside-avoid" data-search="<?php echo HTML::chars(mb_strtolower($m['title'].' '.($m['short'] ?? '').' '.($m['keywords'] ?? ''))); ?>">
                    <a href="<?php echo HTML::chars($m['href']); ?>" class="inline-flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-0.5 -mx-1.5 transition hover:bg-gray-50 hover:text-blue-600 dark:hover:bg-gray-700/50 dark:hover:text-blue-400">
                        <i class="<?php echo HTML::chars($m['icon']); ?> w-4 shrink-0 text-center text-sm text-gray-400"></i>
                        <span class="truncate"><?php echo HTML::chars($m['title']); ?></span>
                        <?php if ( ! empty($m['badge'])): ?>
                        <span class="prl-count shrink-0 uppercase"><?php echo HTML::chars((string) $m['badge']); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php // Звёздочка «в Избранное»: проявляется при наведении на строку. Закрепление видно в меню сразу (prl.nav.v1). ?>
                    <button type="button"
                            data-nav-pin-card="<?php echo HTML::chars($m['href']); ?>"
                            data-nav-pin-title="<?php echo HTML::chars($m['title']); ?>"
                            data-nav-pin-icon="<?php echo HTML::chars($m['icon']); ?>"
                            class="prl-btn-icon nav-pin-card shrink-0 opacity-0 group-hover/item:opacity-100 h-7 w-7 text-sm focus-visible:opacity-100"
                            title="В Избранное" aria-label="В Избранное"><i class="fas fa-star"></i></button>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>

    <script>
        (function () {
            var input = document.getElementById('catalog-search');
            var empty = document.getElementById('catalog-search-empty');
            var groups = document.getElementById('catalog-groups');
            if (!input || !empty || !groups) return;

            function apply() {
                var q = input.value.trim().toLowerCase();
                var any = false;
                groups.querySelectorAll('.catalog-group').forEach(function (g) {
                    var gMatch = (g.getAttribute('data-search') || '').indexOf(q) !== -1;
                    var shown = 0;
                    g.querySelectorAll('.catalog-item').forEach(function (it) {
                        var hay = it.getAttribute('data-search') || '';
                        var show = !q || gMatch || hay.indexOf(q) !== -1;
                        it.classList.toggle('hidden', !show);
                        if (show) shown++;
                    });
                    g.classList.toggle('hidden', !(gMatch || shown > 0 || !q));
                    if (!g.classList.contains('hidden')) any = true;
                });
                empty.classList.toggle('hidden', any || !q);
            }

            input.addEventListener('input', apply);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { input.value = ''; apply(); }
            });
        })();
    </script>
</div>
