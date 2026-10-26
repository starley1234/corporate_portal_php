<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Карточка «Кузницы систем» на главной (манифест: card_view).
 *
 * Слово владельца: «нужно, чтобы модуль формировал ценные и полезные артефакты и
 * был интересен» — поэтому в карточке не «синтез миров», а то, что человек
 * унесёт: спецификация, схема данных, контракт API, промпт. И честная половина
 * правды: цикл запускает владелец, читать выпущенное может каждый.
 */
?>
<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
	<div class="flex items-center gap-3">
		<span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-hammer" aria-hidden="true"></i></span>
		<h2 class="text-base font-semibold text-gray-900 dark:text-white">Кузница систем</h2>
	</div>
	<p class="n2j-hint mt-2">Группа LLM-ролей проектирует систему по вашей задумке и выпускает готовые документы: спецификацию, схему данных, контракт API, алгоритм, промпт, план проверки, риски, метрики, оценку и оффер. У каждого — ценность 0–100 от Критика, поэтому в каталоге первыми идут те, по которым можно начинать работу.</p>
	<p class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
		<a href="/forge/artifacts" class="font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300">→ Каталог артефактов</a>
		<a href="/forge" class="font-semibold text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Запустить цикл</a>
	</p>
</div>
