<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Карта сходства коллекции (/veclab/map).
 *
 * Рисуем обычным SVG прямо в разметке: холст маленький (сотня точек), данные
 * уже посчитаны на сервере, и тащить ради этого библиотеку с CDN нельзя
 * (правило платформы: внешних скриптов во вьюхах нет).
 *
 * @var array  $map     ['points' => […], 'links' => […], 'pairs' => […], 'note' => '']
 * @var float  $link    порог косинуса, выше которого нарисована связь
 * @var string $visitor чья коллекция показана
 * @var bool   $mine    своя ли это коллекция
 * @var bool   $admin   админ ли смотрит
 * @var array  $stats   хранилище
 * @var int    $count   сколько элементов с векторами взяли
 */

$points = (array) $map['points'];
$links  = (array) $map['links'];
$side   = 720;                      // сторона холста в единицах SVG

/** Цвет точки по виду элемента: текст, картинка, текст с картинкой. */
$tone = function ($kind) {
    if ($kind === 'image')  return '#2563eb';
    if ($kind === 'both')   return '#f59e0b';

    return '#059669';
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="n2j-h1">Карта сходства</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
                Каждая точка — элемент коллекции, расстояние между точками — непохожесть по мнению
                модели. Соседство здесь настоящее: карта построена из тех же векторов, по которым
                работает поиск, а не нарисована на глаз. Линия между точками означает, что пара
                похожа сильнее порога.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="rounded-full bg-blue-50 px-3 py-1 font-semibold text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                <i class="fas fa-circle-nodes mr-1"></i><?php echo count($points); ?> точек
            </span>
            <span class="rounded-full bg-gray-100 px-3 py-1 font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                <i class="fas fa-link mr-1"></i><?php echo count($links); ?> связей
            </span>
            <?php if ( ! $mine): ?>
            <span class="rounded-full bg-amber-50 px-3 py-1 font-semibold text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
                <i class="fas fa-user-shield mr-1"></i>чужая коллекция: <?php echo HTML::chars(mb_substr($visitor, 0, 12)); ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" action="/veclab/map" class="mt-4 flex flex-wrap items-end gap-2">
        <?php if ($admin): ?>
        <label class="block">
            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Посетитель</span>
            <input type="text" name="visitor" value="<?php echo HTML::chars($visitor); ?>"
                   class="n2j-input mt-1 w-56">
        </label>
        <?php endif; ?>

        <label class="block">
            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">Связь от косинуса</span>
            <input type="number" name="link" step="0.05" min="-1" max="1" value="<?php echo HTML::chars(number_format((float) $link, 2, '.', '')); ?>"
                   class="n2j-input mt-1 w-28">
        </label>

        <button type="submit" class="n2j-btn-process">
            <i class="fas fa-arrows-rotate mr-1"></i>Перестроить
        </button>
        <a href="/veclab" class="n2j-btn-secondary">
            к лаборатории
        </a>
    </form>

    <?php if ((string) $map['note'] !== ''): ?>
        <div class="n2j-flash n2j-flash-warn mt-5 block">
            <i class="fas fa-triangle-exclamation mr-1"></i><?php echo HTML::chars((string) $map['note']); ?>
            Положите в коллекцию несколько картинок и текстов на <a class="underline" href="/veclab">/veclab</a> — и карта появится.
        </div>
    <?php else: ?>

    <div class="mt-5 gap-6 xl:flex xl:items-start">
        <div class="n2j-card min-w-0 flex-1 p-3">
            <svg viewBox="0 0 <?php echo $side; ?> <?php echo $side; ?>" role="img"
                 aria-label="Карта сходства элементов коллекции"
                 class="h-auto w-full">
                <rect x="0" y="0" width="<?php echo $side; ?>" height="<?php echo $side; ?>" rx="14"
                      fill="none" stroke="#e5e7eb" stroke-width="1"></rect>

                <?php foreach ($links as $edge): ?>
                    <?php
                    $a = $points[$edge['a']];
                    $b = $points[$edge['b']];
                    // Чем ближе пара, тем заметнее линия: иначе сильные и
                    // случайные связи выглядят одинаково.
                    $weight = max(0.08, min(0.55, ((float) $edge['cos'] - 0.3) / 0.7));
                    ?>
                    <line x1="<?php echo round($a['x'] * $side, 1); ?>" y1="<?php echo round($a['y'] * $side, 1); ?>"
                          x2="<?php echo round($b['x'] * $side, 1); ?>" y2="<?php echo round($b['y'] * $side, 1); ?>"
                          stroke="#6366f1" stroke-width="<?php echo round(0.6 + $weight * 2.4, 2); ?>"
                          stroke-opacity="<?php echo round($weight, 2); ?>"></line>
                <?php endforeach; ?>

                <?php foreach ($points as $n => $point): ?>
                    <?php
                    $cx = round($point['x'] * $side, 1);
                    $cy = round($point['y'] * $side, 1);
                    $title = ($point['label'] !== '' ? $point['label'] : 'Элемент #'.$point['id'])
                        .($point['near'] !== ''
                            ? ' · ближайший: '.$point['near'].' ('.number_format((float) $point['near_cos'], 3, ',', ' ').')'
                            : '');
                    ?>
                    <g>
                        <title><?php echo HTML::chars($title); ?></title>
                        <circle cx="<?php echo $cx; ?>" cy="<?php echo $cy; ?>" r="7"
                                fill="<?php echo $tone($point['kind']); ?>" fill-opacity="0.85"
                                stroke="#ffffff" stroke-width="1.5"></circle>
                        <text x="<?php echo $cx + 10; ?>" y="<?php echo $cy + 4; ?>"
                              font-size="11" fill="#6b7280"><?php echo HTML::chars(mb_substr($point['label'] !== '' ? $point['label'] : '#'.$point['id'], 0, 22)); ?></text>
                    </g>
                <?php endforeach; ?>
            </svg>

            <div class="mt-2 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-emerald-600"></span>текст</span>
                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-blue-600"></span>картинка</span>
                <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-amber-500"></span>текст и картинка</span>
                <span>порог связи: <?php echo HTML::chars(number_format((float) $link, 2, ',', ' ')); ?></span>
            </div>
        </div>

        <aside class="mt-5 w-full shrink-0 xl:mt-0 xl:w-80">
            <div class="n2j-card">
                <h2 class="n2j-h3">Самые близкие пары</h2>

                <?php if ( ! $map['pairs']): ?>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Ни одна пара не перешагнула порог — попробуйте понизить его в поле выше.
                    </p>
                <?php else: ?>
                <ul class="mt-3 space-y-2">
                    <?php foreach ($map['pairs'] as $pair): ?>
                    <li class="rounded-lg border border-gray-200 p-2.5 text-sm dark:border-gray-700">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(number_format((float) $pair['cos'], 4, ',', ' ')); ?></span>
                            <span class="text-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $pair['say']['title']); ?></span>
                        </div>
                        <div class="mt-1 truncate text-gray-700 dark:text-gray-200"><?php echo HTML::chars(mb_substr((string) $pair['a'], 0, 48)); ?></div>
                        <div class="truncate text-gray-500 dark:text-gray-400"><?php echo HTML::chars(mb_substr((string) $pair['b'], 0, 48)); ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="n2j-card mt-4">
                <h2 class="n2j-h3">Как читать карту</h2>
                <ul class="mt-2 space-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <li>Точки рядом — модель считает элементы похожими по смыслу.</li>
                    <li>Картинка рядом со своей подписью — модель действительно мультимодальная.</li>
                    <li>Одинокая точка в углу — в коллекции нет ничего похожего.</li>
                    <li>Плоскость — это сжатые <?php echo (int) ($stats['dims'] ?? 0); ?> измерений, поэтому точные
                        числа берите из «близких пар», а не из линейки.</li>
                </ul>
            </div>
        </aside>
    </div>
    <?php endif; ?>
</div>
