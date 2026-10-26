<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Кодировщик» для главной (card_view манифеста nj616_convert).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-shield-halved"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Кодировщик</h2>
    </div>
    <p class="n2j-hint mt-2">Base64 с кириллицей и URL encode/decode — мгновенно.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/convert" class="hover:text-blue-600 dark:hover:text-blue-400">→ Открыть кодировщик</a></li>
        <li><a href="/convert?m=url_encode" class="hover:text-blue-600 dark:hover:text-blue-400">→ URL encode</a></li>
    </ul>
</div>
