<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Витрина nj668: описание сервиса и лучшие опубликованные иллюстрации.
 * Вёрстка слева, без узкой колонки по центру (как Каталог/Проекты).
 * Картинка целиком — и на узком, и на широком (владелец, 11.10.2026).
 */
$logged    = isset($logged) ? (bool) $logged : FALSE;
$can_start = isset($can_start) ? (bool) $can_start : FALSE;
$showcase  = isset($showcase) ? (array) $showcase : [];
$csrf      = isset($csrf) ? (string) $csrf : '';
$demo      = Service_Nj668_Scene::svg(Service_Nj668_Scene::sample('sunset'));
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-end justify-between gap-3">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-bezier-curve mr-2 text-blue-500"></i>Плоские иллюстрации</h1>
			<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Опишите тему словами или приложите фото — модель соберёт векторную сцену: прямые, кривые Безье и цветные заливки. На холсте фигуры можно двигать, перекрашивать и публиковать в <a href="/draw2d/catalog" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">каталог</a>.</p>
		</div>
		<a href="/draw2d/create" class="n2j-btn-cta">
			<i class="fas fa-wand-magic-sparkles mr-2"></i>Создать иллюстрацию
		</a>
	</div>

	<div class="n2j-card p-0 mt-6 overflow-hidden">
		<div class="nj668-hero flex justify-center [&_svg]:block [&_svg]:h-auto [&_svg]:max-h-[min(70vh,720px)] [&_svg]:w-auto [&_svg]:max-w-full"><?php echo $demo; ?></div>
	</div>

	<div class="mt-6 grid gap-4 sm:grid-cols-3">
		<div class="n2j-card">
			<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-pen-nib"></i></div>
			<h2 class="n2j-h3 mt-3">1. Опишите сцену</h2>
			<p class="n2j-hint">Текст, фото или оба. Чем конкретнее палитра и композиция, тем меньше править руками.</p>
		</div>
		<div class="n2j-card">
			<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-bezier-curve"></i></div>
			<h2 class="n2j-h3 mt-3">2. Модель рисует вектор</h2>
			<p class="n2j-hint">Не растр: прямоугольники, круги, ломаные и кубические Безье с заливкой. Сцену всегда можно пересчитать в SVG.</p>
		</div>
		<div class="n2j-card">
			<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-up-down-left-right"></i></div>
			<h2 class="n2j-h3 mt-3">3. Правите на холсте</h2>
			<p class="n2j-hint">Двигайте фигуры, меняйте цвет, сохраняйте ревизии. Готовое — в каталог.</p>
		</div>
	</div>

	<div class="mt-8 flex flex-wrap items-end justify-between gap-3">
		<div>
			<h2 class="n2j-h2">Лучшие иллюстрации</h2>
			<p class="n2j-hint">Опубликованные работы: сначала отмеченные редакцией, затем самые просматриваемые.</p>
		</div>
		<a href="/draw2d/catalog" class="text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">Весь каталог →</a>
	</div>

	<?php if ( ! $showcase): ?>
	<p class="mt-6 text-sm text-gray-500 dark:text-gray-400">Пока никто не опубликовал иллюстрацию. <a href="/draw2d/create" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Нарисуйте первую</a>.</p>
	<?php else: ?>
	<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($showcase as $row): ?>
		<article class="n2j-card flex flex-col p-3">
			<a href="/draw2d/c/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>" class="block">
				<?php echo Service_Nj668_Draw2d::preview_img($row, (string) Arr::get($row, 'title'), 'aspect-[3/2] w-full rounded-md border border-gray-200 dark:border-gray-700'); ?>
				<h3 class="mt-2 truncate text-sm font-semibold text-gray-900 dark:text-white">
					<?php echo ((int) Arr::get($row, 'featured') === 1) ? '<i class="fas fa-star mr-1 text-amber-400"></i>' : ''; ?>
					<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
				</h3>
			</a>
			<p class="n2j-hint line-clamp-3 flex-1"><?php echo HTML::chars(UTF8::substr((string) Arr::get($row, 'summary', ''), 0, 280)); ?></p>
			<div class="mt-3 flex items-center gap-2 text-xs text-gray-400">
				<span><?php echo (int) Arr::get($row, 'shapes_count'); ?> фигур</span>
				<span><?php echo (int) Arr::get($row, 'views'); ?> просмотров</span>
			</div>
		</article>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>
