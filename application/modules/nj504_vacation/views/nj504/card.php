<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Отпуск» для главной (card_view манифеста nj504_vacation).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-umbrella-beach"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Отпуск</h2>
    </div>
    <p class="n2j-hint mt-2">Отпускные, даты с учётом праздников, компенсация при увольнении и самые выгодные даты отпуска.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/vacation" class="hover:text-blue-600 dark:hover:text-blue-400">→ Когда выгоднее взять отпуск</a></li>
        <li><a href="/vacation?m=pay" class="hover:text-blue-600 dark:hover:text-blue-400">→ Отпускные по среднему заработку</a></li>
        <li><a href="/vacation?m=dates" class="hover:text-blue-600 dark:hover:text-blue-400">→ Дата выхода из отпуска</a></li>
        <li><a href="/vacation?m=comp" class="hover:text-blue-600 dark:hover:text-blue-400">→ Компенсация при увольнении</a></li>
    </ul>
</div>
