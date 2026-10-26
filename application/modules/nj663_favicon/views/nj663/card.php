<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Генератор favicon» для главной (card_view манифеста nj663_favicon).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
	<div class="flex items-center gap-3">
		<span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-icons"></i></span>
		<h2 class="text-base font-semibold text-gray-900 dark:text-white">Генератор favicon</h2>
		<span class="n2j-badge n2j-badge-run ml-auto uppercase">
			Новое
		</span>
	</div>
	<p class="n2j-hint mt-2">Картинка, буква или эмодзи — и готов комплект иконок сайта: PNG 16–512, favicon.ico, apple-touch-icon, webmanifest и HTML-код.</p>
	<ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
		<li><a href="/favicon-gen" class="hover:text-amber-600">→ Собрать иконки</a></li>
		<li><a href="/favicon-gen/api/sizes" class="hover:text-amber-600">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
	</ul>
</div>
