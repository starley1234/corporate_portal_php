<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичный каталог опубликованных графов (nj667).
 *
 * Открыт всем: витрина, не личный кабинет. «Как шаблон» копирует снимок себе.
 */

$logged     = isset($logged) ? (bool) $logged : FALSE;
$can_start  = isset($can_start) ? (bool) $can_start : FALSE;
$catalog    = isset($catalog) ? (array) $catalog : [];
$query      = isset($query) ? (string) $query : '';
$error      = isset($error) ? (string) $error : '';
$csrf       = isset($csrf) ? (string) $csrf : '';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-book-open mr-2 text-blue-500"></i>Каталог</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Опубликованные конструкции. Можно крутить на странице просмотра или взять как шаблон в свой проект.</p>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<form method="get" action="/graph3d/catalog" class="mt-5 flex max-w-xl flex-wrap gap-2">
		<input type="search" name="q" value="<?php echo HTML::chars($query); ?>" maxlength="200"
			placeholder="Поиск: велосипед, стул…"
			class="n2j-input min-w-[10rem] flex-1">
		<button type="submit" class="n2j-btn-process shrink-0">Найти</button>
	</form>

	<?php if ( ! $catalog): ?>
	<p class="mt-8 text-sm text-gray-500 dark:text-gray-400"><?php echo $query !== '' ? 'Ничего не нашлось.' : 'Пока ничего не опубликовано.'; ?></p>
	<?php else: ?>
	<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($catalog as $row): ?>
		<article class="n2j-card flex min-w-0 flex-col p-3">
			<a href="<?php echo HTML::chars(Service_Nj667_Graph3d::catalog_href($row)); ?>" class="block min-w-0">
				<?php $pb = (int) Arr::get($row, 'published_build_id', 0); ?>
				<?php if ($pb > 0): ?>
				<?php /* Опубликована сборка — её изометрия из модели; нет карточки — схема графа. */ ?>
				<img src="/graph3d/b/<?php echo HTML::chars(rawurlencode((string) Arr::get($row, 'code'))); ?>?build=<?php echo $pb; ?>&amp;file=card" width="160" height="160" loading="lazy"
					alt="Сборка «<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>»"
					class="mx-auto h-40 w-40 rounded-md bg-blue-50/60 object-contain dark:bg-gray-800"
					onerror="this.onerror=null;this.src='<?php echo HTML::chars(Service_Nj667_Graph3d::preview_href($row, 'day')); ?>'">
				<?php else: ?>
				<?php echo Service_Nj667_Graph3d::preview_img($row, 'Схема графа «'.(string) Arr::get($row, 'title').'»', 'h-40 w-full rounded-md border border-gray-200 object-contain dark:border-gray-700'); ?>
				<?php endif; ?>
				<h2 class="n2j-h3 mt-2 line-clamp-2 sm:line-clamp-1">
					<?php echo ((int) Arr::get($row, 'featured') === 1) ? '<i class="fas fa-star mr-1 text-amber-400"></i>' : ''; ?>
					<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
				</h2>
			</a>
			<p class="n2j-hint line-clamp-3 flex-1 break-words">
				<?php echo HTML::chars(UTF8::substr((string) Arr::get($row, 'summary') ?: Service_Nj667_Graph3d::graph_text(Service_Nj667_Graph3d::graph_of($row)), 0, 280)); ?>
			</p>
			<div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-400">
				<span><?php echo (int) Arr::get($row, 'nodes_count'); ?> узл.</span>
				<span><?php echo (int) Arr::get($row, 'views'); ?> просмотров</span>
				<?php $pb = (int) Arr::get($row, 'published_build_id', 0); ?>
				<?php if ($pb > 0): ?>
					<a href="/graph3d/b/<?php echo HTML::chars(rawurlencode((string) Arr::get($row, 'code'))); ?>?build=<?php echo $pb; ?>" title="Опубликованная сборка: модель, детали на печать и спецификация"
						class="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2 py-0.5 font-semibold text-blue-700 hover:bg-blue-100 dark:bg-blue-950 dark:text-blue-300 dark:hover:bg-blue-900"><i class="fas fa-cubes"></i>Изделие</a>
				<?php endif; ?>
				<form method="post" action="/graph3d/catalog" class="ml-auto inline">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="remix" value="<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>">
					<input type="hidden" name="r" value="<?php echo (int) Arr::get($row, 'revision_no', 0); ?>">
					<button type="submit" class="n2j-link" <?php echo ($logged AND $can_start) ? '' : 'disabled'; ?>>
						как шаблон
					</button>
				</form>
			</div>
		</article>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>
