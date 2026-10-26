<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Калькулятор НДС» для главной (подключается через nj110_catalog,
 * манифест: card_view). Контент хранится в модуле — правится здесь,
 * на главной обновится сам.
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-percent"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Калькулятор НДС</h2>
    </div>
    <p class="n2j-hint mt-2">Ставки 2026 (22%, УСН 5/7%), расчёт в копейках — всё локально.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/vat" class="hover:text-blue-600 dark:hover:text-blue-400">→ Выделить НДС из суммы</a></li>
        <li><a href="/vat?m=add" class="hover:text-blue-600 dark:hover:text-blue-400">→ Начислить НДС сверху</a></li>
        <li><a href="/vat?r=5&m=extract" class="hover:text-blue-600 dark:hover:text-blue-400">→ Ставки УСН 5% и 7%</a></li>
        <li><a href="/nj501/api/rates" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для интеграций)</span></a></li>
    </ul>
</div>
