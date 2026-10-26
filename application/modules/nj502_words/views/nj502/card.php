<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Сумма прописью» для главной (подключается через nj110_catalog,
 * манифест: card_view). Контент хранится в модуле.
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-spell-check"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Сумма прописью</h2>
    </div>
    <p class="n2j-hint mt-2">Для договоров, счетов и платёжек: рубли словами, копейки как нужно.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/summa-propisyu?a=22122" class="hover:text-blue-600 dark:hover:text-blue-400">→ 22 122 прописью</a></li>
        <li><a href="/summa-propisyu?k=1" class="hover:text-blue-600 dark:hover:text-blue-400">→ Копейки словами</a></li>
        <li><a href="/summa-propisyu?u=1&k=1" class="hover:text-blue-600 dark:hover:text-blue-400">→ С большой буквы <span class="text-xs text-gray-400">(для договоров)</span></a></li>
        <li><a href="/nj502/api/convert" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для 1С и ботов)</span></a></li>
    </ul>
</div>
