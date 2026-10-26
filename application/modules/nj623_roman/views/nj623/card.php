<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Римские числа» для главной (card_view манифеста nj623_roman).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-list-ol"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Римские числа</h2>
    </div>
    <p class="n2j-hint mt-2">Римские ↔ арабские числа: для дат, оглавлений и титульных листов.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/roman" class="hover:text-blue-600 dark:hover:text-blue-400">→ Перевести</a></li>
        <li><a href="/nj623/api/convert?value=1987" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
    </ul>
</div>
