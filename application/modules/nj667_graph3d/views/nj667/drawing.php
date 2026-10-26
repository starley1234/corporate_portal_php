<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Чертёж графа с размерами (nj667): три проекции, масштаб, спецификация.
 *
 * Размеры считает сайт по координатам, не модель (владелец, 11.10.2026).
 * 1 клетка графа — сколько мм/см/м, решает человек: мелочь в миллиметрах,
 * каркас навеса — в метрах. PDF — печать листа (браузер «Сохранить как PDF»).
 */

$project = isset($project) ? (array) $project : [];
$plan    = isset($plan) ? (array) $plan : [];
$svg     = isset($svg) ? (string) $svg : '';
$opt     = isset($opt) ? (array) $opt : [];
$owner   = isset($owner) ? (bool) $owner : FALSE;
$csrf    = isset($csrf) ? (string) $csrf : '';
$units   = isset($units) ? (array) $units : Service_Nj667_Graph3d::drawing_units();
$scales  = isset($scales) ? (array) $scales : Service_Nj667_Graph3d::drawing_scales();
$error   = isset($error) ? (string) $error : '';

$code = (string) Arr::get($project, 'code');
$unit = (string) Arr::get($opt, 'unit', 'mm');
$qs   = function (array $extra = []) use ($opt) {
	$p = array_merge([
		'unit'      => Arr::get($opt, 'unit'),
		'unit_size' => Arr::get($opt, 'unit_size'),
		'scale'     => Arr::get($opt, 'scale'),
		'paper'     => Arr::get($opt, 'paper'),
		'ids'       => Arr::get($opt, 'ids') ? '1' : NULL,
	], $extra);
	return '?'.http_build_query($p);
};
$base = '/graph3d/d/'.rawurlencode($code);
$back = $owner ? '/graph3d/p/'.rawurlencode($code) : '/graph3d/c/'.rawurlencode($code);
$info = Arr::get($units, $unit, Arr::get($units, 'mm'));
$views = isset($views) ? (array) $views : [];
$graph = isset($graph) ? (array) $graph : [];

if ( ! $views AND $graph AND Arr::get($plan, 'ok') AND class_exists('Service_Nj667_Graph3d'))
{
	$views = Service_Nj667_Graph3d::drawing_view_svgs($graph, $opt);
}
?>

<?php /* design-audit: allow style — размер листа @page зависит от выбранного формата (A4/A3); классом не выразить */ ?>
<style>@media print { @page { size: <?php echo (string) Arr::get($opt, 'paper', 'a4') === 'a3' ? 'A3' : 'A4'; ?> landscape; margin: 8mm; } }</style>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php
		$draw_title = trim((string) Arr::get($project, 'title'));
		$draw_head  = $draw_title !== '' ? UTF8::substr($draw_title, 0, 70).' - чертёж' : 'Чертёж';
	?>
	<div class="no-print flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
		<h1 class="n2j-h1 break-words">
			<i class="fas fa-ruler-combined mr-1.5 text-blue-500"></i><?php echo HTML::chars($draw_head); ?>
		</h1>
	</div>

	<?php echo View::factory('nj667/_nav', ['code' => $code, 'nav' => 'd', 'owner' => $owner])->render(); ?>

	<p class="no-print mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Каркас в реальных размерах: вы задаёте, сколько <?php echo HTML::chars((string) Arr::get($info, 'short', 'мм')); ?> в одной клетке графа, и масштаб листа. Цифры считает сайт по координатам узлов, не модель. Толщину материала и отверстия чертёж не знает — это заготовки по осям.</p>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err no-print mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<form method="get" action="<?php echo HTML::chars($base); ?>" class="n2j-card no-print mt-5">
		<fieldset>
			<legend class="text-sm font-semibold text-gray-900 dark:text-white">Единица измерения</legend>
			<p class="n2j-hint">Мелочь и мебель — миллиметры. Навес, каркас, планировка — метры.</p>
			<div class="mt-2 flex flex-wrap gap-3">
				<?php foreach ($units as $key => $row): ?>
				<label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
					<input type="radio" name="unit" value="<?php echo HTML::chars((string) $key); ?>"
						<?php echo $unit === (string) $key ? 'checked' : ''; ?>
						class="h-4 w-4 text-blue-600 dark:dark:text-blue-400">
					<span><?php echo HTML::chars((string) Arr::get($row, 'title')); ?> <span class="text-gray-400">(<?php echo HTML::chars((string) Arr::get($row, 'hint')); ?>)</span></span>
				</label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<div class="mt-4">
			<label for="nj667-unit-size" class="n2j-label">В одной клетке графа <span class="font-normal text-gray-400">(<?php echo HTML::chars((string) Arr::get($info, 'short')); ?>)</span></label>
			<input id="nj667-unit-size" name="unit_size" type="number" min="0.001" max="10000" step="any"
				value="<?php echo HTML::chars((string) Arr::get($opt, 'unit_size')); ?>"
				class="n2j-input mt-1 w-40">
			<div class="mt-2 flex flex-wrap gap-1.5">
				<?php foreach ((array) Arr::get($info, 'sizes', []) as $size): ?>
				<a href="<?php echo HTML::chars($base.$qs(['unit_size' => $size])); ?>"
					class="n2j-btn-secondary n2j-btn-sm"><?php echo HTML::chars((string) $size); ?> <?php echo HTML::chars((string) Arr::get($info, 'short')); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="mt-4 flex flex-wrap gap-4">
			<div>
				<label for="nj667-scale" class="n2j-label">Масштаб листа</label>
				<select id="nj667-scale" name="scale"
					class="n2j-input n2j-select mt-1 w-auto">
					<?php foreach ($scales as $key => $title): ?>
					<option value="<?php echo HTML::chars((string) $key); ?>" <?php echo (string) Arr::get($opt, 'scale') === (string) $key ? 'selected' : ''; ?>><?php echo HTML::chars((string) $title); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label for="nj667-paper" class="n2j-label">Лист</label>
				<select id="nj667-paper" name="paper"
					class="n2j-input n2j-select mt-1 w-auto">
					<option value="a4" <?php echo (string) Arr::get($opt, 'paper') === 'a4' ? 'selected' : ''; ?>>A4 альбом</option>
					<option value="a3" <?php echo (string) Arr::get($opt, 'paper') === 'a3' ? 'selected' : ''; ?>>A3 альбом</option>
				</select>
			</div>
		</div>

		<label class="mt-4 flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
			<input type="checkbox" name="ids" value="1" <?php echo Arr::get($opt, 'ids') ? 'checked' : ''; ?> class="h-4 w-4 rounded border-gray-300 text-blue-600 dark:dark:border-gray-600 dark:dark:text-blue-400">
			<span>Показать номера узлов <span class="text-xs text-gray-500 dark:text-gray-400">(по умолчанию выключено: на сложных узлах цифры налезают друг на друга)</span></span>
		</label>

		<div class="mt-4 flex flex-wrap gap-2">
			<button type="submit" class="n2j-btn-process">Показать чертёж</button>
			<a href="<?php echo HTML::chars($base.$qs(['format' => 'print'])); ?>"
				class="n2j-btn-secondary"
				id="nj667-pdf-btn" target="_blank" rel="noopener">Экспорт в PDF</a>
			<a href="<?php echo HTML::chars($base.$qs(['format' => 'svg'])); ?>"
				class="n2j-btn-secondary">Скачать SVG</a>
			<a href="<?php echo HTML::chars($base.$qs(['format' => 'csv'])); ?>"
				class="n2j-btn-secondary">Спецификация CSV</a>
		</div>
	</form>

	<?php if ($owner): ?>
	<form method="post" action="<?php echo HTML::chars($base); ?>" class="no-print mt-3">
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
		<input type="hidden" name="unit" value="<?php echo HTML::chars((string) Arr::get($opt, 'unit')); ?>">
		<input type="hidden" name="unit_size" value="<?php echo HTML::chars((string) Arr::get($opt, 'unit_size')); ?>">
		<input type="hidden" name="scale" value="<?php echo HTML::chars((string) Arr::get($opt, 'scale')); ?>">
		<input type="hidden" name="paper" value="<?php echo HTML::chars((string) Arr::get($opt, 'paper')); ?>">
		<?php if (Arr::get($opt, 'ids')): ?><input type="hidden" name="ids" value="1"><?php endif; ?>
		<button type="submit" name="save" value="1" class="n2j-btn-secondary">Запомнить масштаб для этого проекта</button>
	</form>
	<?php endif; ?>

	<?php if ( ! Arr::get($plan, 'ok')): ?>
	<p class="mt-6 text-sm text-gray-500 dark:text-gray-400">Сначала соберите граф — чертить нечего.</p>
	<?php else: ?>

	<div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
		<span>габарит: <b class="text-gray-700 dark:text-gray-300"><?php echo HTML::chars(
			Service_Nj667_Graph3d::drawing_fmt(Arr::get(Arr::get($plan, 'span', []), 'x'), $unit).' × '
			.Service_Nj667_Graph3d::drawing_fmt(Arr::get(Arr::get($plan, 'span', []), 'z'), $unit).' × '
			.Service_Nj667_Graph3d::drawing_fmt(Arr::get(Arr::get($plan, 'span', []), 'y'), $unit)
		); ?></b> (Д×Ш×В)</span>
		<span>масштаб: <b class="text-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($plan, 'scale')); ?></b></span>
		<span>самая длинная связь: <b class="text-gray-700 dark:text-gray-300"><?php echo HTML::chars(Service_Nj667_Graph3d::drawing_fmt((float) Arr::get($plan, 'longest'), $unit)); ?></b></span>
		<span>сумма заготовок: <b class="text-gray-700 dark:text-gray-300"><?php echo HTML::chars(Service_Nj667_Graph3d::drawing_fmt((float) Arr::get($plan, 'sum_length'), $unit)); ?></b></span>
	</div>

	<?php if ( ! Arr::get($plan, 'fits', TRUE)): ?>
	<p class="mt-2 text-xs font-semibold text-amber-700 dark:text-amber-400">При выбранном масштабе на лист не влезает: проекции вписаны, цифры на выносках верные. Для печати в масштабе выберите мельче или лист A3.</p>
	<?php endif; ?>

	<?php /* Лист в mm на телефоне обрезает виды: на экране — 100% ширины; до 640px
	   четыре вида столбиком, каждый на всю ширину. На печати — весь лист. */ ?>
	<div id="nj667-drawing-sheet" class="n2j-card mt-5 overflow-hidden p-2 [&_svg]:block [&_svg]:h-auto [&_svg]:w-full [&_svg]:max-w-full">
		<?php if ($views): ?>
		<div class="nj667-drawing-views grid grid-cols-1 gap-3 sm:hidden print:hidden [&_svg]:bg-white">
			<?php foreach ($views as $name => $view_svg): ?>
			<figure class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:dark:border-gray-700 dark:dark:bg-gray-900">
				<?php echo $view_svg; ?>
			</figure>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="nj667-drawing-full overflow-auto<?php echo $views ? ' hidden sm:block print:block' : ''; ?>"><?php echo $svg; ?></div>
	</div>

	<div class="n2j-card no-print mt-6 overflow-x-auto">
		<h2 class="n2j-h3"><i class="fas fa-table mr-1.5 text-blue-500"></i>Заготовки (длины связей)</h2>
		<p class="n2j-hint">Сколько материала по оси между узлами. Резать с запасом на соединение.</p>
		<table class="n2j-table mt-3">
			<thead>
				<tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-800">
					<th class="py-1.5 pr-3 font-semibold">id</th>
					<th class="py-1.5 pr-3 font-semibold">от</th>
					<th class="py-1.5 pr-3 font-semibold">к</th>
					<th class="py-1.5 pr-3 font-semibold">длина</th>
					<th class="py-1.5 font-semibold">тип</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach (array_slice((array) Arr::get($plan, 'edges', []), 0, 40) as $edge): ?>
				<tr class="border-b border-gray-100 dark:border-gray-800">
					<td class="py-1.5 pr-3 font-mono"><?php echo HTML::chars((string) Arr::get($edge, 'id')); ?></td>
					<td class="py-1.5 pr-3 font-mono"><?php echo HTML::chars((string) Arr::get($edge, 'source')); ?></td>
					<td class="py-1.5 pr-3 font-mono"><?php echo HTML::chars((string) Arr::get($edge, 'target')); ?></td>
					<td class="py-1.5 pr-3"><?php echo HTML::chars(Service_Nj667_Graph3d::drawing_fmt((float) Arr::get($edge, 'length'), $unit)); ?></td>
					<td class="py-1.5"><?php echo HTML::chars((string) Arr::get($edge, 'type')); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="n2j-card no-print mt-5 overflow-x-auto">
		<h2 class="n2j-h3"><i class="fas fa-location-dot mr-1.5 text-blue-500"></i>Координаты узлов</h2>
		<p class="n2j-hint">Разметка в выбранных единицах. Y — вверх.</p>
		<table class="n2j-table mt-3">
			<thead>
				<tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-800">
					<th class="py-1.5 pr-3 font-semibold">id</th>
					<th class="py-1.5 pr-3 font-semibold">X</th>
					<th class="py-1.5 pr-3 font-semibold">Y</th>
					<th class="py-1.5 pr-3 font-semibold">Z</th>
					<th class="py-1.5 font-semibold">тип</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach (array_slice((array) Arr::get($plan, 'nodes', []), 0, 40) as $node): ?>
				<tr class="border-b border-gray-100 dark:border-gray-800">
					<td class="py-1.5 pr-3 font-mono"><?php echo HTML::chars((string) Arr::get($node, 'id')); ?></td>
					<td class="py-1.5 pr-3 font-mono"><?php echo HTML::chars(Service_Nj667_Graph3d::drawing_fmt((float) Arr::get($node, 'rx'), $unit)); ?></td>
					<td class="py-1.5 pr-3 font-mono"><?php echo HTML::chars(Service_Nj667_Graph3d::drawing_fmt((float) Arr::get($node, 'ry'), $unit)); ?></td>
					<td class="py-1.5 pr-3 font-mono"><?php echo HTML::chars(Service_Nj667_Graph3d::drawing_fmt((float) Arr::get($node, 'rz'), $unit)); ?></td>
					<td class="py-1.5"><?php echo HTML::chars((string) Arr::get($node, 'type')); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>
