<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Cron-человечизатор» для главной (card_view манифеста nj621_cron).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-list-check"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Cron-человечизатор</h2>
    </div>
    <p class="n2j-hint mt-2">Cron-выражение на человеческом языке + следующие запуски.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/cron" class="hover:text-blue-600 dark:hover:text-blue-400">→ Расшифровать выражение</a></li>
        <li><a href="/nj621/api/explain" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
    </ul>
</div>
