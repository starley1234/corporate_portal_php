<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Виджет «Графовый 3D-конструктор» для главной (card_view манифеста nj667).
 *
 * Внутри — интерактивный образец «клон с палочками» (Service_Nj667_Graph3d::home_sample):
 * его можно вращать мышью, 3D подгружается лениво — когда карточка в экране.
 * Кнопка ведёт в модуль. Без образца карточка остаётся текстовой.
 */

$sample_json = json_encode(Service_Nj667_Graph3d::home_sample(), JSON_UNESCAPED_UNICODE);
?>

<div class="nj-fade-up flex flex-col n2j-card transition hover:-translate-y-0.5 hover:border-blue-300 dark:hover:border-blue-800">
	<div class="flex items-center gap-3">
		<span class="rounded-lg bg-blue-50 p-2 text-sm font-bold text-blue-600 dark:bg-blue-950/60 dark:text-blue-300"><i class="fas fa-cube"></i></span>
		<h2 class="text-base font-semibold text-gray-900 dark:text-white"><a href="/graph3d" class="hover:text-blue-600 hover:dark:text-blue-400">Графовый 3D-конструктор</a></h2>
		<span class="n2j-badge n2j-badge-info ml-auto uppercase">
			Новое
		</span>
	</div>

	<div class="relative mt-4 h-48 overflow-hidden rounded-lg bg-blue-50/60 dark:bg-gray-900/60"
		data-nj667-home3d data-graph="<?php echo HTML::chars($sample_json); ?>"
		title="Образец: исходный уголок, его копия и палочки между ними. Тяните мышью, чтобы вращать.">
		<div data-home3d-stage class="absolute inset-0"></div>
		<p data-home3d-note class="n2j-hint pointer-events-none absolute inset-0 flex items-center justify-center">Загружаем 3D-образец…</p>
	</div>

	<p class="n2j-hint mt-3">Описание изделия словами превращается в пространственный граф: узлы-детали и палочки-связи. Граф можно тянуть на 3D-холсте, достраивать, пересчитать в модель для печати и опубликовать в каталог.</p>

	<a href="/graph3d" class="n2j-btn-process mt-4 self-start">Открыть конструктор →</a>
</div>

<script src="/assets/js/nj667-home3d.js?v=20261015a" defer></script>
