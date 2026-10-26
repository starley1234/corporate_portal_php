<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Пени и неустойка» для главной (card_view манифеста nj505_penalty).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-gavel"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Пени и неустойка</h2>
    </div>
    <p class="n2j-hint mt-2">Пени по налогам, проценты по ст. 395 ГК и договорная неустойка — по дням и по ключевой ставке.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/penalty" class="hover:text-blue-600 dark:hover:text-blue-400">→ Пени по налогам (1/300 и 1/150)</a></li>
        <li><a href="/penalty?m=gk395" class="hover:text-blue-600 dark:hover:text-blue-400">→ Проценты по ст. 395 ГК</a></li>
        <li><a href="/penalty?m=contract" class="hover:text-blue-600 dark:hover:text-blue-400">→ Договорная неустойка</a></li>
    </ul>
</div>
