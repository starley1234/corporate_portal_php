<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Конструктор А4» для главной (card_view манифеста nj638_pdfplan).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-file-lines"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Конструктор А4</h2>
    </div>
    <p class="n2j-hint mt-2">Шапка, одна или две колонки, нумерация и подвал — аккуратный документ за минуту.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/pdf-plan" class="hover:text-blue-600 dark:hover:text-blue-400">→ Собрать документ</a></li>
        <li><a href="/pdf-plan/api/build" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
    </ul>
</div>
