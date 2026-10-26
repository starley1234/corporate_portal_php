<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Виджет «Новости сайта» для главной (подключается через nj110_catalog,
 * манифест: card_view). Оформление — единое с карточками витрины
 * (nj-fade-up, цветная рамка, список ссылок со стрелкой).
 */
?>
<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-bullhorn"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Новости сайта</h2>
        <span class="n2j-badge n2j-badge-ok ml-auto uppercase">
            <span class="inline-block h-1 w-1 rounded-full bg-emerald-500 animate-pulse"></span>новое
        </span>
    </div>
    <p class="n2j-hint mt-2">Новые возможности платформы и обновления сервисов — по-человечески, без технического жаргона.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/news" class="hover:text-blue-600 dark:hover:text-blue-400">→ Что нового на N2J.ru</a></li>
        <li><a href="/news/rss.xml" class="hover:text-blue-600 dark:hover:text-blue-400">→ RSS-лента <span class="text-xs text-gray-400">(для читалок)</span></a></li>
    </ul>
</div>
