<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Unicode-анализатор» для главной (card_view манифеста nj626_unicode).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-spell-check"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Unicode-анализатор</h2>
    </div>
    <p class="n2j-hint mt-2">Кодовые точки, UTF-8 байты и \\u-escape для любого символа.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/unicode" class="hover:text-blue-600 dark:hover:text-blue-400">→ Разобрать текст</a></li>
        <li><a href="/nj626/api/analyze?text=Привет" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
    </ul>
</div>
