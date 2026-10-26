<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Разговор с ИИ голосом» для главной (card_view манифеста nj677_talk).
 *
 * Бейджа «Новое» здесь нет намеренно: его выдаёт каталог (nj110) сам —
 * последним публичным карточкам, поэтому в разметке он не дублируется.
 */
?>

<div class="nj-fade-up n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
	<div class="flex items-center gap-3">
		<span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-microphone-lines"></i></span>
		<h2 class="text-base font-semibold text-gray-900 dark:text-white">Разговор с ИИ голосом</h2>
	</div>
	<p class="n2j-hint mt-2">Включили разговор — и просто говорите: конец фразы определяется сам, ответ читается вслух, перебить можно голосом.</p>
	<ul class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-300">
		<li><a href="/talk" class="hover:text-blue-600">→ Начать разговор</a></li>
		<li><a href="/voice" class="hover:text-blue-600">→ Озвучить текст без разговора</a></li>
	</ul>
</div>
