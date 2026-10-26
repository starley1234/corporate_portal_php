<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * Карточка «Диалога двух ИИ» на главной и в каталоге (манифест: card_view).
 *
 * В карточке — то, что человек унесёт: два агента обсуждают его тему по делу,
 * при необходимости ищут в интернете, а разговор можно опубликовать как готовый
 * разбор. Поэтому ссылки две: рабочий стол (создать разговор) и каталог
 * опубликованных (почитать чужое).
 */
?>
<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
	<div class="flex items-center gap-3">
		<span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-comments" aria-hidden="true"></i></span>
		<h2 class="text-base font-semibold text-gray-900 dark:text-white">Диалог двух ИИ</h2>
	</div>
	<p class="n2j-hint mt-2">Дайте тему — два ИИ-агента обсудят её по делу: один держит структуру и критерии, второй ищет ход и упрощает проверку. Разговор ведёт сервер обменами реплик, агенты при необходимости смотрят в интернет, а готовый разбор публикуется одной кнопкой.</p>
	<p class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
		<a href="/dialog" class="font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300">→ Начать диалог</a>
		<a href="/dialog/list" class="font-semibold text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">Опубликованные разговоры</a>
	</p>
</div>
