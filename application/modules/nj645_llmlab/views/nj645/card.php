<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Лаборатория LLM» для главной (card_view манифеста nj645_llmlab).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-flask"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Лаборатория LLM</h2>
        <span class="n2j-badge n2j-badge-ok ml-auto uppercase">
            <span class="inline-block h-1 w-1 rounded-full bg-emerald-500 animate-pulse"></span>новое
        </span>
    </div>
    <p class="n2j-hint mt-2">Как модель видит текст: токены, температура, эмбеддинги и разбор промпта — на живой базе знаний.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/llmlab" class="hover:text-blue-600 dark:hover:text-blue-400">→ Открыть лабораторию</a></li>
        <li><a href="/llmlab#tokens" class="hover:text-blue-600 dark:hover:text-blue-400">→ Посчитать токены и цену текста</a></li>
    </ul>
</div>
