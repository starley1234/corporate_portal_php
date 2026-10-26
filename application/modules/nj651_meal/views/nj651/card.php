<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Калории по фото тарелки» для главной (card_view манифеста nj651_meal).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-utensils"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Калории по фото</h2>
    </div>
    <p class="n2j-hint mt-2">Снимок тарелки → блюда, вес порций, калории и БЖУ, доля вашей суточной нормы.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/meal" class="hover:text-emerald-600">→ Сфотографировать тарелку</a></li>
        <li><a href="/meal?sex=m&amp;weight=80&amp;height=180&amp;age=35&amp;activity=medium&amp;goal=loss" class="hover:text-emerald-600">→ Норма для снижения веса</a></li>
    </ul>
</div>
