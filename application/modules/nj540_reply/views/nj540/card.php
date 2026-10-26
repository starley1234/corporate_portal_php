<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Отзыв 2 Ответ» для главной (nj110_catalog, манифест: card_view).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-comment-dots"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Отзыв 2 Ответ</h2>
        <span class="n2j-badge n2j-badge-info ml-auto uppercase">ИИ</span>
    </div>
    <p class="n2j-hint mt-2">Review 2 Reply: вставьте отзыв клиента — ИИ разберёт тональность и напишет публичный ответ нужным тоном.</p>
    <ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
        <li><a href="/review" class="hover:text-blue-600 dark:hover:text-blue-400">→ Ответить на отзыв</a></li>
        <li>Анализ: тональность, темы, эскалации</li>
        <li>5 тонов ответа, перегенерация</li>
    </ul>
</div>
