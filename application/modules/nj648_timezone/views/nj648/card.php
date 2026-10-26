<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Созвон по часовым поясам» для главной (card_view манифеста nj648_timezone).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-earth-europe"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Созвон по часовым поясам</h2>
    </div>
    <p class="n2j-hint mt-2">Время в городах сейчас и час, который попадает в рабочий день у всех участников встречи.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/timezones" class="hover:text-blue-600 dark:hover:text-blue-400">→ Подобрать время созвона</a></li>
        <li><a href="/timezones?p%5B%5D=moscow&amp;p%5B%5D=berlin&amp;p%5B%5D=newyork" class="hover:text-blue-600 dark:hover:text-blue-400">→ Москва · Берлин · Нью-Йорк</a></li>
        <li><a href="/nj648/api/now?p%5B%5D=moscow&amp;p%5B%5D=Asia/Tokyo" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
    </ul>
</div>
