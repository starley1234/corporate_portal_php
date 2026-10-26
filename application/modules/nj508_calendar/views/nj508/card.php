<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Календарь и даты» для главной (card_view манифеста nj508_calendar).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-calendar-days"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Календарь и даты</h2>
    </div>
    <p class="n2j-hint mt-2">Производственный календарь РФ с переносами, рабочие дни между датами, возраст и стаж.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/calendar" class="hover:text-blue-600 dark:hover:text-blue-400">→ Календарь года и норма часов</a></li>
        <li><a href="/calendar?m=between" class="hover:text-blue-600 dark:hover:text-blue-400">→ Рабочие дни между датами</a></li>
        <li><a href="/calendar?m=until" class="hover:text-blue-600 dark:hover:text-blue-400">→ Сколько дней до даты</a></li>
        <li><a href="/calendar?m=age" class="hover:text-blue-600 dark:hover:text-blue-400">→ Точный возраст и стаж</a></li>
    </ul>
</div>
