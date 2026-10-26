<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Страница просмотра иллюстрации (nj668).
 *
 * Открыта всем, если работа в каталоге. Править нельзя — это витрина.
 */

$project = isset($project) ? (array) $project : [];
$scene   = isset($scene) ? (array) $scene : Service_Nj668_Scene::scene_new();
$svg     = isset($svg) ? (string) $svg : '';
$own     = isset($own) ? (bool) $own : FALSE;
$logged  = isset($logged) ? (bool) $logged : FALSE;

$code  = (string) Arr::get($project, 'code');
$tags  = array_filter(array_map('trim', explode(',', (string) Arr::get($project, 'tags', ''))));
$shapes = (array) Arr::get($scene, 'shapes', []);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-center gap-3">
		<h1 class="n2j-h1">
			<?php echo ((int) Arr::get($project, 'featured') === 1) ? '<i class="fas fa-star mr-2 text-amber-400"></i>' : '<i class="fas fa-bezier-curve mr-2 text-blue-500"></i>'; ?>
			<?php echo HTML::chars((string) Arr::get($project, 'title')); ?>
		</h1>
		<?php if ($own): ?>
		<a href="/draw2d/p/<?php echo HTML::chars($code); ?>" class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Править на холсте</a>
		<?php endif; ?>
	</div>

	<p class="mt-1.5 max-w-3xl text-sm text-gray-600 dark:text-gray-300">
		<?php echo HTML::chars((string) Arr::get($project, 'summary') ?: (string) Arr::get($project, 'prompt', '')); ?>
	</p>

	<div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
		<span>фигур: <?php echo (int) count($shapes); ?></span>
		<span>просмотров: <?php echo (int) Arr::get($project, 'views'); ?></span>
		<?php if ((string) Arr::get($project, 'model', '') !== ''): ?>
		<span>модель: <?php echo HTML::chars((string) Arr::get($project, 'model')); ?></span>
		<?php endif; ?>
	</div>

	<?php if ($tags): ?>
	<div class="mt-3 flex flex-wrap gap-1.5">
		<?php foreach ($tags as $tag): ?>
		<a href="/draw2d/catalog?q=<?php echo rawurlencode((string) $tag); ?>"
			class="n2j-chip">#<?php echo HTML::chars((string) $tag); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="mt-5 gap-5 lg:grid lg:grid-cols-3 lg:items-start">
		<div class="min-w-0 lg:col-span-2">
			<div class="n2j-card p-0 overflow-hidden">
				<div class="nj668-hero flex justify-center [&_svg]:block [&_svg]:h-auto [&_svg]:max-h-[min(70vh,720px)] [&_svg]:w-auto [&_svg]:max-w-full"><?php echo $svg; ?></div>
			</div>
			<p class="n2j-hint mt-2">Это просмотр: править фигуры можно в своей работе.</p>

			<div class="n2j-card mt-5">
				<h2 class="n2j-h3"><i class="fas fa-code mr-1.5 text-blue-500"></i>Сцена (источник правды)</h2>
				<p class="n2j-hint">Картинку всегда можно перерисовать из JSON. Из PNG геометрию не достать.</p>
				<pre class="mt-3 max-h-80 overflow-auto rounded-lg bg-gray-950 p-3 text-xs leading-relaxed text-gray-200"><?php echo HTML::chars(json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
			</div>
		</div>
		<aside class="mt-5 space-y-3 lg:mt-0">
			<div class="n2j-card text-sm text-gray-600 dark:text-gray-300">
				<p>Прямые, кривые Безье и цветные заливки. Модель собрала сцену по описанию, браузер показал SVG.</p>
				<a href="/draw2d/create" class="mt-3 inline-flex min-h-[44px] items-center text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">Нарисовать свою →</a>
			</div>
		</aside>
	</div>
</div>


