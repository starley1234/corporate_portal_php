<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Озвучивание текста» для главной (card_view манифеста nj665_voice).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
	<div class="flex items-center gap-3">
		<span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-volume-high"></i></span>
		<h2 class="text-base font-semibold text-gray-900 dark:text-white">Озвучивание текста</h2>
		<span class="n2j-badge n2j-badge-info ml-auto uppercase">
			Новое
		</span>
	</div>
	<p class="n2j-hint mt-2">Текст — в голос: модель, тембр, скорость и формат файла. Плеер в браузере, скачивание и история озвучек.</p>
	<ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
		<li><a href="/voice" class="hover:text-blue-600">→ Озвучить текст</a></li>
		<li><a href="/voice/api/voices" class="hover:text-blue-600">→ JSON API <span class="text-xs text-gray-400">(для агентов)</span></a></li>
	</ul>
</div>
