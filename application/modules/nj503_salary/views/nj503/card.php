<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Калькулятор зарплаты» для главной (card_view манифеста nj503_salary).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-wallet"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Калькулятор зарплаты</h2>
    </div>
    <p class="n2j-hint mt-2">Зарплата на руки и до налога: НДФЛ 13–22 %, вычеты на детей, взносы работодателя.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/salary" class="hover:text-blue-600 dark:hover:text-blue-400">→ Из оклада — на руки</a></li>
        <li><a href="/salary?m=net" class="hover:text-blue-600 dark:hover:text-blue-400">→ Из «на руки» — оклад до налога</a></li>
        <li><a href="/salary?c=2" class="hover:text-blue-600 dark:hover:text-blue-400">→ С вычетом на детей</a></li>
    </ul>
</div>
