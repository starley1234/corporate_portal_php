<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Растение по фото» для главной (card_view манифеста nj652_plant).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-seedling"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Растение по фото</h2>
    </div>
    <p class="n2j-hint mt-2">Определит вид, назовёт причину пятен и соберёт план ухода с календарём полива.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/plant" class="hover:text-emerald-600">→ Сфотографировать растение</a></li>
    </ul>
</div>
