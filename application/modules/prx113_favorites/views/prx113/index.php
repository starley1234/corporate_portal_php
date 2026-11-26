<?php defined('SYSPATH') or die('No direct script access.');

/**
 * prx113 — управление Избранным: drag&drop порядок, категории, переименование,
 * заметки. Сохранение — обычная форма: перетаскивание меняет порядок узлов,
 * параллельные массивы уходят в POST в DOM-порядке. Без JS тоже работает
 * (правка полей, порядок — только кнопками «выше/ниже» не требуется).
 */
?>
<div class="prx-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

<?php echo View::factory('partials/page_header', [
    'crumbs' => [['Главная', '/'], ['Избранное — управление']],
    'title'  => 'Избранное — управление',
    'pill'   => ['порядок · категории', 'fa-star'],
    'lead'   => 'Перетащите пункт за ручку или кнопками-стрелками. Категории группируют пункты в левой панели; пункт без категории — в общем списке.',
    'actions' => [['label' => 'Все сервисы', 'href' => '/services', 'icon' => 'fa-grip', 'role' => 'secondary']],
]); ?>

<?php if ((string) $flash !== ''): ?>
    <p class="prx-flash prx-flash-info mt-3" role="status"><?php echo HTML::chars((string) $flash); ?></p>
<?php endif; ?>

    <form method="post" action="/favorites/save" autocomplete="off" class="mt-5">
        <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">

        <section class="prx-card" aria-labelledby="prx113-list-title">
            <div class="prx-card-head">
                <h2 id="prx113-list-title" class="prx-h3">Закреплённые <span class="prx-count ml-1"><?php echo count($items); ?></span></h2>
                <button type="submit" class="prx-btn-cta"><i class="fas fa-floppy-disk mr-1.5" aria-hidden="true"></i>Сохранить</button>
            </div>

<?php if (empty($items)): ?>
        <div class="prx-empty">
            <div class="prx-empty-icon"><i class="fas fa-star" aria-hidden="true"></i></div>
            <p class="prx-empty-text mt-4">Пока ничего не закреплено. Добавляйте звёздочкой в каталоге /services или в шапке любой страницы.</p>
        </div>
<?php else: ?>
        <datalist id="prx113-categories">
<?php foreach ($categories as $category): ?>
            <option value="<?php echo HTML::chars($category); ?>"></option>
<?php endforeach; ?>
        </datalist>

        <ol class="mt-1 space-y-1" data-fav-rows aria-label="Пункты избранного в порядке отображения">
<?php foreach ($items as $item): ?>
            <li class="flex flex-wrap items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-gray-50 dark:hover:bg-gray-800/50" data-fav-row draggable="true">
                <button type="button" class="prx-btn-icon cursor-grab text-gray-400" data-fav-drag
                        title="Перетащить" aria-label="Перетащить пункт <?php echo HTML::chars((string) $item['title']); ?>"><i class="fas fa-grip-vertical" aria-hidden="true"></i></button>
                <i class="<?php echo HTML::chars((string) $item['icon']); ?> w-5 shrink-0 text-center text-[15px] text-gray-400" data-fav-preview aria-hidden="true"></i>
                <input type="hidden" name="href[]" value="<?php echo HTML::chars((string) $item['href']); ?>">
                <label class="sr-only">Название</label>
                <input type="text" name="title[]" value="<?php echo HTML::chars((string) $item['title']); ?>" maxlength="200"
                       class="prx-input min-w-[10rem] max-w-full flex-1" autocomplete="off">
                <label class="sr-only">Категория</label>
                <input type="text" name="category[]" value="<?php echo HTML::chars((string) ($item['category'] ?? '')); ?>" maxlength="80"
                       list="prx113-categories" placeholder="категория" class="prx-input w-36" autocomplete="off">
                <label class="sr-only">Иконка</label>
                <input type="text" name="icon[]" value="<?php echo HTML::chars((string) $item['icon']); ?>" maxlength="64"
                       class="prx-input hidden w-44 font-mono text-xs sm:block" autocomplete="off">
                <label class="sr-only">Заметка</label>
                <input type="text" name="note[]" value="<?php echo HTML::chars((string) ($item['note'] ?? '')); ?>" maxlength="300"
                       placeholder="зачем это здесь" class="prx-input hidden min-w-[8rem] max-w-[16rem] flex-1 sm:block" autocomplete="off">
                <div class="ml-auto flex shrink-0 items-center gap-0.5">
                    <a href="<?php echo HTML::chars((string) $item['href']); ?>" class="prx-btn-icon" title="Открыть <?php echo HTML::chars((string) $item['title']); ?>" aria-label="Открыть <?php echo HTML::chars((string) $item['title']); ?>"><i class="fas fa-arrow-up-right-from-square text-xs" aria-hidden="true"></i></a>
                    <button type="button" class="prx-btn-icon" data-fav-up title="Выше" aria-label="Поднять <?php echo HTML::chars((string) $item['title']); ?>"><i class="fas fa-arrow-up text-xs" aria-hidden="true"></i></button>
                    <button type="button" class="prx-btn-icon" data-fav-down title="Ниже" aria-label="Опустить <?php echo HTML::chars((string) $item['title']); ?>"><i class="fas fa-arrow-down text-xs" aria-hidden="true"></i></button>
                </div>
            </li>
<?php endforeach; ?>
        </ol>
        <p class="prx-hint mt-2">Название, категория, иконка и заметка переопределяют данные каталога только для вас. Категории появляются в левой панели подзаголовками.</p>
<?php endif; ?>
        </section>
    </form>
</div>

<script>
// Порядок строки: drag&drop за ручку + кнопки выше/ниже. DOM — источник
// правды: параллельные массивы формы уходят в POST в порядке узлов.
(function () {
    'use strict';
    var rows = document.querySelector('[data-fav-rows]');
    if (!rows) return;

    function item(row) { return row.closest('[data-fav-row]') || row; }

    rows.querySelectorAll('[data-fav-up]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = item(btn), prev = row.previousElementSibling;
            if (prev) rows.insertBefore(row, prev);
            row.querySelector('input[name^="title"]').focus({ preventScroll: true });
        });
    });
    rows.querySelectorAll('[data-fav-down]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = item(btn), next = row.nextElementSibling;
            if (next) rows.insertBefore(next, row);
            row.querySelector('input[name^="title"]').focus({ preventScroll: true });
        });
    });

    var dragged = null;
    rows.querySelectorAll('[data-fav-row]').forEach(function (row) {
        row.addEventListener('dragstart', function (event) {
            dragged = row;
            row.classList.add('opacity-40');
            try { event.dataTransfer.setData('text/plain', ''); } catch (e) { /* IE */ }
            event.dataTransfer.effectAllowed = 'move';
        });
        row.addEventListener('dragend', function () {
            row.classList.remove('opacity-40');
            dragged = null;
        });
        row.addEventListener('dragover', function (event) {
            if (!dragged || dragged === row) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            var rect = row.getBoundingClientRect();
            rows.insertBefore(dragged, event.clientY - rect.top > rect.height / 2 ? row.nextSibling : row);
        });
    });
})();
</script>
