<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Генератор паролей» для главной (подключается через nj110_catalog,
 * манифест: card_view). Контент хранится в модуле.
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-key"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Генератор паролей</h2>
    </div>
    <p class="n2j-hint mt-2">CSPRNG, до 96 символов, исключение похожих. Пароли нигде не сохраняются.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/password" class="hover:text-blue-600 dark:hover:text-blue-400">→ Сгенерировать пароль</a></li>
        <li><a href="/password?l=32" class="hover:text-blue-600 dark:hover:text-blue-400">→ Длинный (32 символа)</a></li>
        <li><a href="/password?x=1" class="hover:text-blue-600 dark:hover:text-blue-400">→ Без похожих символов</a></li>
        <li><a href="/nj509/api/generate" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для скриптов)</span></a></li>
    </ul>
</div>
