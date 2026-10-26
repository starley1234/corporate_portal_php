<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Чек по фото» для главной (card_view манифеста nj650_receipt).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-receipt"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Чек по фото</h2>
    </div>
    <p class="n2j-hint mt-2">Снимок чека → позиции, сверка с итогом, траты по категориям и честный дележ счёта на компанию.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/receipt" class="hover:text-blue-600 dark:hover:text-blue-400">→ Сфотографировать чек</a></li>
        <li><span class="text-gray-400">Суммы считает сервер, модель только читает строки</span></li>
    </ul>
</div>
