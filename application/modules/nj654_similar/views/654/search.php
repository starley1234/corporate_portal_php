<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Поиск похожего (/similar) — страница целиком.
 *
 * Оформление одно: владелец выбрал вариант 1 («Рабочая колонка») 09.10.2026,
 * остальные два удалены вместе с переключателем `?ui=`. Рабочая область живёт
 * в 654/search_body и делит движок (654/search_js) и общие части
 * (654/panel, 654/results, 654/tile).
 *
 * Общее поведение (владелец, 08.10.2026): нажали «Найти» — форма
 * вместе с полями уступает место карточке «что ищем», под ней идёт
 * неразрывный статус-ряд (спиннер + этап + секундомер, полос хода нет),
 * находки появляются плитками ниже, страница НЕ перезагружается, а фокус
 * после отправки в поле не возвращается — на телефоне клавиатура закрывается
 * сама и не закрывает результаты.
 *
 * Оформление — по дизайн-эталону /pages/required_design_sample: радиусы
 * xl/lg/md, тач-таргеты 44 px, карточки p-4 / sm:p-6, рабочая область 2/3 + 1/3
 * у «рабочей колонки» (остальные варианты узкие по делу — короткая форма без
 * боковой колонки, как и разрешает эталон).
 *
 * @var bool   $logged      вошёл ли человек (иначе — плашка входа вместо формы)
 * @var array  $stats       состояние банка (total, images, waiting, ready…)
 * @var array  $query       текущий поиск или NULL
 * @var array  $hits        находки готового поиска
 * @var bool   $result_page TRUE на /similar/result/<id>
 * @var int    $top         сколько совпадений показывать
 * @var int    $max         предел «сколько показать»
 * @var int    $mb          предел файла, МБ
 * @var string $csrf        токен формы
 * @var string $flash       сообщение о прошлом действии
 */

$logged = ! empty($logged);
$query  = $query ?: NULL;
$hits  = (array) ($hits ?? []);
$flash = trim((string) ($flash ?? ''));
$stats = (array) ($stats ?? []);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">


    <?php // Ни крошек, ни бейджей с названиями соседних страниц: переходы по
          // разделу уже стоят чипами в шапке сайта, второй такой же ряд рядом
          // с заголовком — лишний шум. ?>
    <!-- ============ Шапка модуля ============ -->
    <header>
        <h1 class="n2j-h1">Похожие картинки</h1>
        <p class="mt-1.5 text-xs text-gray-600 sm:text-sm dark:text-gray-300">
            Опишите словами или покажите картинку — найдём самое похожее среди своих изображений.
        </p>

    </header>

    <?php if ($logged AND empty($stats['ready'])): ?>
        <div class="n2j-flash n2j-flash-warn mt-5 flex items-center gap-3 text-xs">
            <i class="fas fa-info-circle shrink-0 text-base text-amber-600 dark:text-amber-400" aria-hidden="true"></i>
            <span>Таблицы модуля ещё не созданы — выполните миграции.</span>
        </div>
    <?php endif; ?>

    <?php // Сообщение о прошлом действии (в том числе «нужен текст или картинка»
          // от сервера, если форму отправили пустой): одна строка на всю страницу. ?>
    <?php if ($logged AND $flash !== ''): ?>
        <div class="n2j-flash n2j-flash-ok mt-5 flex items-start gap-2.5 text-xs">
            <i class="fas fa-circle-check mt-0.5 shrink-0" aria-hidden="true"></i>
            <span><?php echo HTML::chars($flash); ?></span>
        </div>
    <?php endif; ?>

    <?php if ( ! $logged): ?>
    <div class="mt-6 grid gap-5 lg:grid-cols-2">
        <?php echo Gate::wall('similar', [
            'icon'   => 'fas fa-magnifying-glass',
            'title'  => 'Похожие картинки — по бесплатному аккаунту',
            'text'   => 'Банк картинок и история поисков привязаны к человеку: войдите — и тот же банк с векторами откроется с любого устройства. Очередь эмбеддингов при этом не расходуется анонимами.',
            'points' => [
                'Поиск по смыслу: словами, картинкой или обоими входами сразу',
                'Свой банк: загрузка пачкой до '.(int) $mb.' МБ на файл и правка подписей',
                'Совпадения с числом близости — видно, почему картинка нашлась',
                'Повторяемость: один и тот же запрос даёт тот же вектор',
            ],
            'stats'  => ['Beta, бесплатно', 'показать от '.(int) $top.' до '.(int) $max.' совпадений'],
            'cta'    => 'Войти и найти похожее',
            'note'   => 'Картинки банка видны только вам: чужие элементы в поиск не попадают, а свои удаляются по кнопке.',
        ]); ?>

        <section class="n2j-card">
            <h2 class="n2j-h3">Зачем это нужно</h2>
            <ul class="mt-3 space-y-2.5 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                <li class="flex gap-2.5"><i class="fas fa-copy mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Найти дубли.</span> «Есть ли у меня уже такая картинка?» — загрузите её и посмотрите ближайшие совпадения.</span></li>
                <li class="flex gap-2.5"><i class="fas fa-keyboard mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Найти без имени файла.</span> Описывайте то, что видно на картинке, — поиск идёт по смыслу, а не по названию.</span></li>
                <li class="flex gap-2.5"><i class="fas fa-layer-group mt-1 text-blue-500" aria-hidden="true"></i>
                    <span><span class="font-semibold text-gray-900 dark:text-white">Собрать банк.</span> Загрузите свои изображения пачкой — дальше по ним ищут описанием другие сервисы.</span></li>
            </ul>
        </section>
    </div>
    <?php else: ?>

        <?php echo View::factory('654/search_body', [
            'logged'      => $logged,
            'stats'       => $stats,
            'query'       => $query,
            'hits'        => $hits,
            'result_page' => ! empty($result_page),
            'top'         => (int) $top,
            'max'         => (int) $max,
            'mb'          => (int) $mb,
            'csrf'        => (string) $csrf,
            'flash'       => $flash,
        ]); ?>

        <?php // Просмотр картинки целиком и движок поиска — один раз на страницу. ?>
        <?php echo View::factory('654/zoom'); ?>
        <?php echo View::factory('654/search_js'); ?>

    <?php endif; ?>
</div>
