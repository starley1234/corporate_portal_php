<?php
defined('SYSPATH') or die('No direct script access.');
/**
 * nj602_content: витрина «Рост знаний» — карточки метрик + рост по месяцам.
 */
$card = 'rounded-xl border border-gray-200 bg-white p-5 shadow-xs dark:border-gray-700/80 dark:bg-gray-800';
$lbl  = 'text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500';
$num  = 'text-3xl font-black text-gray-900 dark:text-white';

// суммарные векторы семантического индекса
$vectors = 0;
foreach ((array) $s as $c) {
    $vectors += (int) ($c['vectors'] ?? 0);
}

?>
<div class="n2j-ds w-full space-y-6 px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

    <div class="<?php echo $card; ?>">
        <h1 class="n2j-h1"><i class="fas fa-brain mr-2 text-blue-500"></i>Рост знаний платформы</h1>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Сервисы N2J запоминают то, с чем работают: источники, выжимки, связи между сущностями. Каждая новая задача делает следующие ответы точнее.</p>
    </div>

    <!-- Ключевые цифры -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="<?php echo $card; ?>">
            <p class="<?php echo $lbl; ?>">База знаний</p>
            <p class="mt-1 <?php echo $num; ?>"><?php echo (int) ($k['docs'] ?? 0); ?></p>
            <p class="n2j-hint">заметок · за 7 дней: <?php echo (int) ($k['fresh'] ?? 0); ?> · использованы <?php echo (int) ($k['hits'] ?? 0); ?>×</p>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="<?php echo $lbl; ?>">Семантический индекс</p>
            <p class="mt-1 <?php echo $num; ?>"><?php echo $vectors; ?></p>
            <p class="n2j-hint">векторов в <?php echo count((array) $s); ?> коллекциях (эмбеддинги)</p>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="<?php echo $lbl; ?>">Статьи журнала</p>
            <p class="mt-1 <?php echo $num; ?>"><?php echo (int) ($a['published'] ?? 0); ?></p>
            <p class="n2j-hint">опубликовано · на модерации <?php echo (int) ($a['review'] ?? 0); ?> · собирается <?php echo (int) ($a['collecting'] ?? 0); ?></p>
        </div>
        <div class="<?php echo $card; ?>">
            <p class="<?php echo $lbl; ?>">Прочитано источников</p>
            <p class="mt-1 <?php echo $num; ?>"><?php echo (int) ($a['sources'] ?? 0); ?></p>
            <p class="n2j-hint">страниц интернета легло в основу статей</p>
        </div>
    </div>

    <!-- Рост по месяцам -->
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3"><i class="fas fa-chart-column mr-1.5 text-blue-500"></i>Рост по месяцам</h2>
        <?php if (empty($months)): ?>
        <p class="n2j-hint mt-3">Пока нет данных — сервисы только запустились.</p>
        <?php else: ?>
        <div class="mt-4 space-y-2.5">
            <?php foreach ($months as $m): $docs = (int) $m['docs']; $arts = (int) $m['articles']; ?>
            <?php // Рост — числами по месяцам: столбики (полосы) убраны везде. ?>
            <div class="flex items-baseline justify-between gap-3">
                <span class="w-16 shrink-0 text-xs font-semibold text-gray-500 dark:text-gray-400"><?php echo HTML::chars($m['label']); ?></span>
                <span class="flex-1 text-xs text-gray-500 dark:text-gray-400">
                    заметок <b class="text-gray-900 dark:text-white"><?php echo $docs; ?></b> ·
                    статей <b class="text-gray-900 dark:text-white"><?php echo $arts; ?></b>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Откуда знания -->
    <?php if ( ! empty($k['by_source'])): ?>
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3"><i class="fas fa-sitemap mr-1.5 text-blue-500"></i>Знания по источникам</h2>
        <div class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($k['by_source'] as $src): ?>
            <span class="rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300"><?php echo HTML::chars((string) $src['source_module']); ?> · <?php echo HTML::chars((string) $src['kind']); ?> <b class="text-blue-600 dark:text-blue-400"><?php echo (int) $src['cnt']; ?></b></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Последние заметки -->
    <?php $recent = class_exists('Service_Nj560_Knowledge') ? Service_Nj560_Knowledge::recent(8) : []; ?>
    <?php if ( ! empty($recent)): ?>
    <div class="<?php echo $card; ?>">
        <h2 class="n2j-h3"><i class="fas fa-clock-rotate-left mr-1.5 text-blue-500"></i>Последние записи</h2>
        <ul class="mt-3 divide-y divide-gray-50 text-sm dark:divide-gray-700/40">
            <?php foreach ($recent as $r): ?>
            <li class="flex flex-wrap items-baseline gap-2 py-2">
                <span class="n2j-badge n2j-badge-info font-mono"><?php echo HTML::chars((string) $r['source_module']); ?></span>
                <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $r['title']); ?></span>
                <span class="ml-auto text-xs text-gray-400"><?php echo HTML::chars(Date::formatted_time((string) $r['created_at'], 'd.m.Y H:i')); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
