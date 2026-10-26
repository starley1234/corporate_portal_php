<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Калькулятор процентов» для главной (card_view манифеста nj518_percent).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-percent"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Калькулятор процентов</h2>
    </div>
    <p class="n2j-hint mt-2">Десять режимов: от «15 % от числа» до наценки и маржи — с формулой.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/percent" class="hover:text-blue-600 dark:hover:text-blue-400">→ Процент от числа</a></li>
        <li><a href="/percent?m=change&a=80&b=100" class="hover:text-blue-600 dark:hover:text-blue-400">→ Прирост и убыль</a></li>
        <li><a href="/percent?m=markup&a=700&b=40" class="hover:text-blue-600 dark:hover:text-blue-400">→ Наценка и маржа</a></li>
        <li><a href="/nj518/api/modes" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для интеграций)</span></a></li>
    </ul>
</div>
