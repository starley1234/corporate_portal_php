<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Кредит и ипотека» для главной (card_view манифеста nj647_loan).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-building-columns"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Кредит и ипотека</h2>
    </div>
    <p class="n2j-hint mt-2">Платёж, переплата и график по месяцам. Досрочные погашения — со сравнением «срок или платёж».</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/loan" class="hover:text-blue-600 dark:hover:text-blue-400">→ Рассчитать платёж</a></li>
        <li><a href="/loan?sum=5+000+000&amp;rate=18&amp;term=20&amp;extra=10000&amp;extra_mode=term" class="hover:text-blue-600 dark:hover:text-blue-400">→ Пример с досрочным погашением</a></li>
        <li><a href="/nj647/api/calc?sum=5000000&amp;rate=18&amp;term=20" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
    </ul>
</div>
