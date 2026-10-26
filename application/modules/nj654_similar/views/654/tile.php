<?php defined('SYSPATH') or die('No direct script access.'); ?>

<?php
/**
 * Плитка находки — одна картинка с подписью и числом близости.
 *
 * Одна разметка на два случая (и потому один источник правды о вёрстке):
 *   1. серверная отрисовка готового поиска — передаётся `$hit`;
 *   2. заготовка для JS (`<template data-nj654-tile-tpl>`): карточки приходят
 *      из `/similar/api/status` без перезагрузки страницы и заполняются по
 *      якорям `data-nj654-tile-*`. Пустая `$hit` рисует ровно тот же `<li>`,
 *      только с местом под значения — поэтому плитка, пришедшая из браузера,
 *      выглядит как серверная.
 *
 * @var array $hit id, title, file (путь относительно storage/nj654), score
 */

$hit   = (array) ($hit ?? []);
$file  = trim((string) ($hit['file'] ?? ''));
$url   = $file !== '' ? Service_Nj654_Bank::file_url($file) : '';
$title = trim((string) ($hit['title'] ?? ''));
$say   = Service_Nj654_Bank::say((float) ($hit['score'] ?? 0));

// Точка-индикатор близости: цветом видно «это почти то же» до чтения числа.
$tone = [
    'emerald' => 'bg-emerald-500',
    'sky'     => 'bg-blue-500',
    'amber'   => 'bg-amber-500',
    'gray'    => 'bg-gray-400',
][(string) ($say['tone'] ?? 'gray')] ?? 'bg-gray-400';
?>
<li class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xs transition hover:border-blue-400 dark:border-gray-700/80 dark:bg-gray-800"
    data-nj654-tile>

    <button type="button"
            class="block w-full cursor-zoom-in <?php echo $url === '' ? 'hidden' : ''; ?>"
            data-nj654-tile-photo
            data-nj654-zoom="<?php echo HTML::chars($url); ?>"
            data-nj654-zoom-title="<?php echo HTML::chars($title); ?>">
        <img src="<?php echo HTML::chars($url); ?>"
             alt="<?php echo HTML::chars($title); ?>"
             loading="lazy"
             data-nj654-tile-img
             class="aspect-square w-full bg-gray-50 object-cover dark:bg-gray-900">
    </button>

    <div class="flex aspect-square w-full items-center justify-center bg-gray-50 text-gray-300 dark:bg-gray-900 dark:text-gray-600 <?php echo $url === '' ? '' : 'hidden'; ?>"
         data-nj654-tile-blank>
        <i class="fas fa-align-left" aria-hidden="true"></i>
    </div>

    <div class="px-2 py-1.5">
        <p class="truncate text-xs font-semibold text-gray-900 dark:text-white"
           title="<?php echo HTML::chars($title); ?>"
           data-nj654-tile-title><?php echo HTML::chars($title); ?></p>
        <p class="mt-0.5 flex items-center gap-1.5">
            <span class="h-1.5 w-1.5 shrink-0 rounded-full <?php echo $tone; ?>"
                  data-nj654-tile-tone aria-hidden="true"></span>
            <span class="font-mono text-xs font-bold text-gray-700 dark:text-gray-200"
                  data-nj654-tile-score><?php echo HTML::chars(number_format((float) ($say['value'] ?? 0), 3, ',', ' ')); ?></span>
            <span class="truncate text-xs text-gray-500 dark:text-gray-400"
                  data-nj654-tile-say><?php echo HTML::chars($say['title']); ?></span>
        </p>
    </div>
</li>
