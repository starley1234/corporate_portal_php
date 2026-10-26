<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Находки: заголовок со счётчиком, плотная сетка плиток, пустой исход и
 * заготовка плитки для JS — общей частью на все три варианта оформления.
 *
 * Одна разметка на серверную отрисовку и на догрузку без перезагрузки: движок
 * (654/search_js) наполняет ту же сетку по якорям `data-nj654-*`, клонируя
 * <template>. Поэтому готовый результат, пришедший по API, неотличим от
 * серверного — и вёрстку правят в одном месте.
 *
 * @var array  $query      строка nj_654_queries или NULL
 * @var array  $hits       находки (id, title, kind, file, score)
 * @var string $head       заголовок блока («Найдено в банке»)
 * @var string $grid_class классы сетки (плотность у вариантов своя)
 */

$query = (array) ($query ?? []);
$hits  = (array) ($hits ?? []);
$id    = (int) ($query['id'] ?? 0);
$state = (string) ($query['status'] ?? '');
$head  = trim((string) ($head ?? '')) !== '' ? (string) $head : 'Найдено в банке';
$grid  = trim((string) ($grid_class ?? '')) !== ''
    ? (string) $grid_class
    : 'grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6';

$ready = $state === 'ready';
?>
<section class="<?php echo $id > 0 ? '' : 'hidden'; ?> mt-8 scroll-mt-4" data-nj654-results>

    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <h2 class="n2j-h3"><?php echo HTML::chars($head); ?></h2>
        <p class="n2j-hint" data-nj654-results-note><?php
            if ($ready)
            {
                echo 'Найдено: '.count($hits).' · ближе всего — сверху';
            }
            elseif ($state === 'error')
            {
                echo 'Поиск не выполнен.';
            }
            else
            {
                echo 'Ищем похожие — список появится здесь сам.';
            }
        ?></p>
    </div>

    <ul class="mt-3 <?php echo HTML::chars($grid); ?>" data-nj654-grid>
        <?php foreach ($hits as $hit): ?>
            <?php echo View::factory('654/tile', ['hit' => $hit]); ?>
        <?php endforeach; ?>
    </ul>

    <?php // Пустой исход показываем только у завершённого поиска: во время работы
          // тут ещё нечего сообщать — о ходе работы говорит карточка запроса. ?>
    <div class="<?php echo $ready && ! $hits ? '' : 'hidden'; ?> mt-3 rounded-xl border border-gray-200 bg-white p-4 text-xs text-gray-600 shadow-xs sm:text-sm dark:border-gray-700/80 dark:bg-gray-800 dark:text-gray-300"
         data-nj654-empty>
        Ничего похожего не нашлось: в банке нет картинки, близкой по смыслу.
        <a href="/similar/collection" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Пополнить банк картинок</a>
        — и поиск найдёт её со следующего раза.
    </div>

    <template data-nj654-tile-tpl><?php echo View::factory('654/tile', ['hit' => []]); ?></template>
</section>
