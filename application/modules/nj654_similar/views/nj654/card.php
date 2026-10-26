<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Похожие картинки» для главной (card_view манифеста nj654_similar).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-images"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Похожие картинки</h2>
        <span class="n2j-badge n2j-badge-info ml-auto uppercase">
            <span class="inline-block h-1 w-1 rounded-full bg-blue-500 animate-pulse"></span>новое
        </span>
    </div>
    <p class="n2j-hint mt-2">Свой банк изображений с поиском по смыслу: опишите словами или покажите картинку — найдётся самое похожее.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/similar" class="hover:text-blue-600">→ Найти похожее</a></li>
        <li><a href="/similar/collection" class="hover:text-blue-600">→ Пополнить банк картинок</a></li>
    </ul>
</div>
