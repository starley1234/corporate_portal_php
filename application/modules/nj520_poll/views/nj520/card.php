<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Быстрый опрос» для главной (nj110_catalog, манифест: card_view).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-chart-simple"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Быстрый опрос</h2>
    </div>
    <p class="n2j-hint mt-2">Ask 2 Vote: вопрос и варианты — короткая ссылка и QR, один голос с браузера.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/poll" class="hover:text-blue-600 dark:hover:text-blue-400">→ Создать опрос</a></li>
        <li>Результаты — после своего голоса</li>
        <li>Защита от повторных голосов</li>
    </ul>
</div>
