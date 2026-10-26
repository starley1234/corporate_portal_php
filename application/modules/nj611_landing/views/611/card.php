<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Сервисы по задачам» для главной (nj110_catalog, card_view).
 * Оформление — единое с карточками витрины.
 */
?>
<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-compass"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Сервисы по задачам</h2>
    </div>
    <p class="n2j-hint mt-2">Подборки инструментов под задачу — с быстрым стартом прямо со страницы: найти клиентов, проверить компанию, подготовить документы.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/s/klienty" class="hover:text-amber-600">→ Найти клиентов и заказы</a></li>
        <li><a href="/s/company" class="hover:text-amber-600">→ Всё о компании</a></li>
        <li><a href="/s/docs" class="hover:text-amber-600">→ Документы и материалы</a></li>
        <li><a href="/s/tools" class="hover:text-amber-600">→ Быстрые инструменты</a></li>
        <li><a href="/s" class="hover:text-amber-600">→ Все подборки</a></li>
    </ul>
</div>
