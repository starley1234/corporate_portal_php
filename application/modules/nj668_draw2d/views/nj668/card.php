<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Плоские иллюстрации» для главной (card_view манифеста nj668).
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
	<div class="flex items-center gap-3">
		<span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-bezier-curve"></i></span>
		<h2 class="text-base font-semibold text-gray-900 dark:text-white">Плоские иллюстрации</h2>
		<span class="n2j-badge n2j-badge-info ml-auto uppercase">
			Новое
		</span>
	</div>
	<p class="n2j-hint mt-2">Тема словами превращается в векторную картинку: прямые, кривые Безье и цветные заливки. На холсте фигуры можно двигать и перекрашивать.</p>
	<ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
		<li><a href="/draw2d/create" class="hover:text-blue-600">→ Нарисовать по описанию</a></li>
		<li><a href="/draw2d/catalog" class="hover:text-blue-600">→ Каталог иллюстраций</a></li>
	</ul>
</div>
