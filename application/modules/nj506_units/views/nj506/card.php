<?php defined('SYSPATH') or die('No direct script access.'); ?>
<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
    <div class="flex items-center gap-3">
        <span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-right-left"></i></span>
        <h2 class="text-base font-semibold text-gray-900 dark:text-white">Конвертер единиц</h2>
    </div>
    <p class="n2j-hint mt-2">Величины и русские бытовые меры — от сотки до версты.</p>
    <div class="mt-4 flex flex-wrap gap-2 text-sm text-gray-600 dark:text-gray-300">
        <a href="/units?c=area&v=1&from=ha&to=are" class="hover:text-blue-600">→ гектары и сотки</a>
        <a href="/units?c=mass&v=1&from=pud&to=kg" class="hover:text-blue-600">→ пуды и килограммы</a>
        <a href="/units?c=length&v=1&from=verst&to=km" class="hover:text-blue-600">→ вёрсты и километры</a>
    </div>
</div>
