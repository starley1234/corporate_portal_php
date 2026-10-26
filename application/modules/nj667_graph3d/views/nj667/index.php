<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Витрина nj667: описание сервиса и лучшие опубликованные графы.
 *
 * Сборка живёт на /graph3d/create — сюда заходят смотреть, что это, и крутить
 * чужие конструкции. Гостю стена входа не нужна: каталог открыт всем.
 * Вёрстка как у Каталога/Проектов: слева, без узкой колонки по центру.
 * Владелец, 11.10.2026.
 */

$logged    = isset($logged) ? (bool) $logged : FALSE;
$can_start = isset($can_start) ? (bool) $can_start : FALSE;
$showcase  = isset($showcase) ? (array) $showcase : [];
$latest_builds = isset($latest_builds) ? (array) $latest_builds : [];
$csrf      = isset($csrf) ? (string) $csrf : '';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-end justify-between gap-3">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-cube mr-2 text-blue-500"></i>Графовый 3D-конструктор</h1>
			<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Описание изделия словами или фото — в пространственный граф: узлы-детали и рёбра-связи. На 3D-холсте их можно тягать, достраивать выделенное промптом, откатывать правки и публиковать в <a href="/graph3d/catalog" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">каталог</a>.</p>
		</div>
		<a href="/graph3d/create" class="n2j-btn-cta">
			<i class="fas fa-wand-magic-sparkles mr-2"></i>Создать граф
		</a>
	</div>

	<div class="mt-6 grid gap-4 sm:grid-cols-3">
		<div class="n2j-card">
			<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-pen-nib"></i></div>
			<h2 class="n2j-h3 mt-3">1. Опишите изделие</h2>
			<p class="n2j-hint">Текст, фото или оба. Чем точнее список деталей и как они стыкуются, тем меньше править руками.</p>
		</div>
		<div class="n2j-card">
			<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-diagram-project"></i></div>
			<h2 class="n2j-h3 mt-3">2. Модель собирает граф</h2>
			<p class="n2j-hint">Сборка идёт в воркере: узлы в пространстве, прямые связи между ними. Похожие графы из каталога модель подглядывает сама.</p>
		</div>
		<div class="n2j-card">
			<div class="flex h-9 w-9 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-300"><i class="fas fa-cube"></i></div>
			<h2 class="n2j-h3 mt-3">3. Правите на холсте</h2>
			<p class="n2j-hint">WebGL, история патчами, публикация в каталог. Чужую конструкцию можно взять как шаблон в свой проект.</p>
		</div>
	</div>

	<?php if ($latest_builds): ?>
	<section class="mt-8" aria-labelledby="nj667-latest-builds">
		<h2 id="nj667-latest-builds" class="n2j-h2">Свежие опубликованные сборки</h2>
		<p class="n2j-hint">Три последние сборки, которые опубликовал модератор. Изометрия — на картинке.</p>

		<div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($latest_builds as $row):
			$title = (string) Arr::get($row, 'title');
			$href  = '/graph3d/b/'.rawurlencode((string) Arr::get($row, 'code')).'?build='.(int) Arr::get($row, 'build_id');
			$when  = strtotime((string) Arr::get($row, 'published_at'));
			$about = UTF8::substr(trim((string) Arr::get($row, 'summary')), 0, 200);
		?>
			<article class="n2j-card flex min-w-0 gap-4 p-3">
				<a href="<?php echo HTML::chars($href); ?>" class="shrink-0">
					<img src="<?php echo HTML::chars($href.'&file=card'); ?>" width="160" height="160" loading="lazy" alt="Изометрия сборки «<?php echo HTML::chars($title); ?>»"
						class="h-40 w-40 rounded-md bg-blue-50/60 dark:bg-gray-800">
				</a>
				<div class="min-w-0">
					<h3 class="line-clamp-2 text-sm font-semibold text-gray-900 dark:text-white">
						<a href="<?php echo HTML::chars($href); ?>" class="hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars($title); ?></a>
					</h3>
					<?php if ($about !== ''): ?>
					<p class="n2j-hint line-clamp-4 break-words"><?php echo HTML::chars($about); ?></p>
					<?php endif; ?>
					<p class="n2j-hint mt-2">опубликовано <?php echo $when ? date('d.m.Y', $when) : ''; ?></p>
				</div>
			</article>
		<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<div class="mt-8 flex flex-wrap items-end justify-between gap-3">
		<div>
			<h2 class="n2j-h2">Лучшие конструкции</h2>
			<p class="n2j-hint">Опубликованные графы: сначала отмеченные редакцией, затем самые просматриваемые.</p>
		</div>
		<a href="/graph3d/catalog" class="text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">Весь каталог →</a>
	</div>

	<?php if ( ! $showcase): ?>
	<p class="mt-6 text-sm text-gray-500 dark:text-gray-400">Пока никто не опубликовал граф. <a href="/graph3d/create" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Соберите первый</a>.</p>
	<?php else: ?>
	<div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($showcase as $row): ?>
		<article class="n2j-card flex min-w-0 flex-col p-3">
			<a href="<?php echo HTML::chars(Service_Nj667_Graph3d::catalog_href($row)); ?>" class="block min-w-0">
				<?php echo Service_Nj667_Graph3d::preview_img($row, 'Схема графа «'.(string) Arr::get($row, 'title').'»', 'h-40 w-full rounded-md border border-gray-200 object-contain dark:border-gray-700'); ?>
				<h3 class="mt-2 line-clamp-2 text-sm font-semibold text-gray-900 dark:text-white sm:line-clamp-1">
					<?php echo ((int) Arr::get($row, 'featured') === 1) ? '<i class="fas fa-star mr-1 text-amber-400"></i>' : ''; ?>
					<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
				</h3>
			</a>
			<p class="n2j-hint line-clamp-3 flex-1 break-words">
				<?php echo HTML::chars(UTF8::substr((string) Arr::get($row, 'summary') ?: Service_Nj667_Graph3d::graph_text(Service_Nj667_Graph3d::graph_of($row)), 0, 280)); ?>
			</p>
			<div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-400">
				<span><?php echo (int) Arr::get($row, 'nodes_count'); ?> узл.</span>
				<span><?php echo (int) Arr::get($row, 'views'); ?> просмотров</span>
			</div>
		</article>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

</div>
