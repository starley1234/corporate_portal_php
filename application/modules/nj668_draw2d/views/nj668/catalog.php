<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный каталог опубликованных иллюстраций (nj668).
 */

$logged = isset($logged) ? (bool) $logged : FALSE;
$items  = isset($items) ? (array) $items : [];
$q      = isset($q) ? (string) $q : '';
$csrf   = isset($csrf) ? (string) $csrf : '';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-book-open mr-2 text-blue-500"></i>Каталог</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Опубликованные плоские иллюстрации. Можно смотреть на странице просмотра; править — в своей копии, если она у вас есть.</p>

	<form method="get" action="/draw2d/catalog" class="mt-5 flex max-w-xl gap-2">
		<input type="search" name="q" value="<?php echo HTML::chars($q); ?>" maxlength="80"
			placeholder="Поиск: закат, дом, шар…"
			class="n2j-input flex-1">
		<button type="submit" class="n2j-btn-process">Найти</button>
	</form>

	<?php if ( ! $items): ?>
	<p class="mt-8 text-sm text-gray-500 dark:text-gray-400"><?php echo $q !== '' ? 'Ничего не нашлось.' : 'Пока ничего не опубликовано.'; ?></p>
	<?php else: ?>
	<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($items as $row): ?>
		<article class="n2j-card flex flex-col p-3">
			<a href="/draw2d/c/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>" class="block">
				<?php echo Service_Nj668_Draw2d::preview_img($row, (string) Arr::get($row, 'title'), 'aspect-[3/2] w-full rounded-md border border-gray-200 dark:border-gray-700'); ?>
				<h2 class="n2j-h3 mt-2 truncate">
					<?php echo ((int) Arr::get($row, 'featured') === 1) ? '<i class="fas fa-star mr-1 text-amber-400"></i>' : ''; ?>
					<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
				</h2>
			</a>
			<p class="n2j-hint line-clamp-3 flex-1">
				<?php echo HTML::chars(UTF8::substr((string) Arr::get($row, 'summary', ''), 0, 280)); ?>
			</p>
			<div class="mt-3 flex items-center gap-2 text-xs text-gray-400">
				<span><?php echo (int) Arr::get($row, 'shapes_count'); ?> фигур</span>
				<span><?php echo (int) Arr::get($row, 'views'); ?> просмотров</span>
			</div>
		</article>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>
