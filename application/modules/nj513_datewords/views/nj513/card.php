<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-calendar-check"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Дата прописью</h2>
    </div>
    <p class="n2j-hint mt-2">Для договора, приказа, акта или периода действия документа.</p>
    <div class="mt-4 flex flex-wrap gap-2 text-sm text-gray-600 dark:text-gray-300">
        <a href="/date-words?date=2026-09-01" class="hover:text-blue-600">→ первого сентября 2026 года</a>
        <a href="/date-words?date=2026-09-01&end=2026-09-30" class="hover:text-blue-600">→ диапазон дат</a>
        <a href="/calendar" class="hover:text-blue-600">→ производственный календарь</a>
    </div>
</div>
