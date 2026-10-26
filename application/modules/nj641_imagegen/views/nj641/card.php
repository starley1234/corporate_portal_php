<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Генератор изображений» для главной (card_view манифеста nj641_imagegen).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-wand-magic-sparkles"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Генератор изображений</h2>
        <span class="n2j-badge n2j-badge-info ml-auto uppercase">
            <span class="inline-block h-1 w-1 rounded-full bg-blue-500 animate-pulse"></span>AI
        </span>
    </div>
    <p class="n2j-hint mt-2">Картинки по описанию, правка и мульти-референсы. Серверная генерация через воркера — готова для презентаций и статей.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/imagegen" class="hover:text-blue-600 dark:hover:text-blue-400">→ Сгенерировать изображение</a></li>
        <li><a href="/imagegen/api/result/1" class="hover:text-blue-600 dark:hover:text-blue-400">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
    </ul>
</div>
