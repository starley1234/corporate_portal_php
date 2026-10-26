<?php defined('SYSPATH') or die('No direct script access.');
$titles = ['projects' => 'Мои детали', 'catalog' => 'Каталог 3D-деталей', 'moderate' => 'Модерация деталей'];
$in = 'n2j-input w-full';
$btn = 'n2j-btn-secondary';
// Каталог и модерация живут на одном адресе: модератор переключает вид параметром view.
$base = $mode === 'projects' ? '/solid3d/projects' : '/solid3d/catalog';
$view_qs = $mode === 'moderate' ? 'view=moderate&amp;' : '';
$tab = function ($active) { return 'rounded-md px-3 py-1.5 text-xs font-semibold ' . ($active ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'); };
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<?php echo View::factory('nj669/head', ['error' => $error]); ?>
<div class="flex flex-wrap items-center justify-between gap-2">
	<h1 class="n2j-h1"><?php echo $titles[$mode]; ?></h1>
	<div class="flex flex-wrap items-center gap-2">
		<?php if ($moderator AND in_array($mode, ['catalog', 'moderate'], TRUE)): ?>
		<nav class="inline-flex gap-1 rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-900" aria-label="Вид каталога">
			<a class="<?php echo $tab($mode === 'catalog'); ?>" href="/solid3d/catalog"<?php echo $mode === 'catalog' ? ' aria-current="page"' : ''; ?>>Каталог</a>
			<a class="<?php echo $tab($mode === 'moderate'); ?>" href="/solid3d/catalog?view=moderate"<?php echo $mode === 'moderate' ? ' aria-current="page"' : ''; ?>>Модерация</a>
		</nav>
		<?php endif; ?>
		<?php if ($mode === 'projects'): ?><a href="/solid3d/create" class="n2j-btn-cta">Новая деталь</a><?php endif; ?>
	</div>
</div>
<form method="get" action="<?php echo HTML::chars($base); ?>" class="mt-3 flex flex-wrap items-center gap-2" autocomplete="off" data-n2j-no-remember>
	<?php if ($mode === 'moderate'): ?><input type="hidden" name="view" value="moderate"><?php endif; ?>
	<input id="solid-search" name="q" type="search" maxlength="100" autocomplete="off" value="<?php echo HTML::chars($q); ?>" placeholder="Название, описание, теги" aria-label="Название, описание, теги" class="<?php echo $in; ?> max-w-md">
	<button class="<?php echo $btn; ?>" type="submit">Найти</button>
</form>
<?php if ($mode === 'moderate'): ?>
	<p class="n2j-flash n2j-flash-warn mt-3 text-xs block">Проверьте версию, отправленную на модерацию: геометрию, назначение и замечания ИИ-проверки. Приватные правки владельца сюда не попадают.</p>
<?php endif; ?>
<div class="mt-3"><?php echo View::factory('nj669/cards', ['items' => $items, 'mode' => $mode, 'moderator' => $moderator, 'csrf' => $csrf]); ?></div>
<nav class="mt-4 flex items-center gap-2 text-sm" aria-label="Страницы">
	<?php if ($page > 1): ?><a class="<?php echo $btn; ?>" href="?<?php echo $view_qs; ?>page=<?php echo $page - 1; ?>&amp;q=<?php echo rawurlencode($q); ?>">← Назад</a><?php endif; ?>
	<span class="text-xs text-gray-500 dark:text-gray-400">Страница <?php echo (int) $page; ?></span>
	<?php if (count($items) === 24): ?><a class="<?php echo $btn; ?>" href="?<?php echo $view_qs; ?>page=<?php echo $page + 1; ?>&amp;q=<?php echo rawurlencode($q); ?>">Дальше →</a><?php endif; ?>
</nav>
</div>
