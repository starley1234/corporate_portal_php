<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var array $rows @var array $categories @var string $csrf @var string $q $source $category $status */ ?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/airadar" class="transition hover:text-blue-600 dark:hover:text-blue-400">ИИ-радар</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">Материалы</span>
	</nav>

	<h1 class="n2j-h1 mt-3">Материалы радара</h1>
	<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Всё, что конвейер нашёл и оценил: дубли по URL отсекаются при приёме, шум помечен отдельно.</p>

	<form method="get" action="/airadar/items" class="n2j-card mt-5 flex flex-wrap items-end gap-2">
		<label class="flex flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Поиск</span>
			<input type="text" name="q" value="<?php echo HTML::chars($q); ?>" placeholder="слово из заголовка или смысла" class="n2j-input w-56"></label>
		<label class="flex flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Источник</span>
			<select name="source" class="n2j-input n2j-select w-auto">
				<option value="">все</option>
				<?php foreach (['huggingface', 'openrouter', 'rss', 'ddg', 'arena'] as $s): ?>
					<option value="<?php echo $s; ?>"<?php echo $source === $s ? ' selected' : ''; ?>><?php echo $s; ?></option>
				<?php endforeach; ?>
			</select></label>
		<label class="flex flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Категория</span>
			<select name="category" class="n2j-input n2j-select w-auto">
				<option value="">все</option>
				<?php foreach ($categories as $key => $label): ?>
					<option value="<?php echo HTML::chars($key); ?>"<?php echo $category === $key ? ' selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
				<?php endforeach; ?>
			</select></label>
		<label class="flex flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Статус</span>
			<select name="status" class="n2j-input n2j-select w-auto">
				<option value="">все</option>
				<?php foreach (['new', 'analyzed', 'noise'] as $s): ?>
					<option value="<?php echo $s; ?>"<?php echo $status === $s ? ' selected' : ''; ?>><?php echo $s; ?></option>
				<?php endforeach; ?>
			</select></label>
		<button type="submit" class="n2j-btn-process">Фильтр</button>
	</form>

	<?php if ( ! $rows): ?>
		<p class="mt-6 rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">Ничего не найдено — запустите прогон на дашборде.</p>
	<?php else: ?>
		<div class="mt-5 space-y-3">
			<?php foreach ($rows as $row): ?>
				<article class="n2j-card">
					<div class="flex flex-wrap items-baseline gap-2">
						<a href="<?php echo HTML::chars((string) $row['url']); ?>" rel="noopener nofollow" class="text-sm font-semibold text-gray-900 hover:text-blue-700 dark:text-white dark:hover:text-blue-300"><?php echo HTML::chars((string) $row['title']); ?></a>
						<?php if ((int) $row['score'] > 0): ?><span class="n2j-badge n2j-badge-ok"><?php echo (int) $row['score']; ?>/100</span><?php endif; ?>
						<?php if ((string) $row['category'] !== ''): ?><span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars((string) Arr::get($categories, (string) $row['category'], (string) $row['category'])); ?></span><?php endif; ?>
						<span class="text-xs uppercase tracking-wide text-gray-400"><?php echo HTML::chars((string) $row['source']); ?> · <?php echo HTML::chars((string) $row['host']); ?></span>
						<form method="post" action="/airadar/star" class="ml-auto">
							<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
							<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
							<input type="hidden" name="starred" value="<?php echo ! empty($row['starred']) ? '0' : '1'; ?>">
							<button type="submit" class="rounded px-2 py-1 text-xs <?php echo ! empty($row['starred']) ? 'text-amber-500' : 'text-gray-300 hover:text-amber-400 dark:text-gray-600 dark:hover:text-amber-400'; ?>" title="<?php echo ! empty($row['starred']) ? 'Убрать из избранного' : 'В избранное'; ?>">★</button>
						</form>
					</div>
					<?php if ((string) $row['why'] !== ''): ?><p class="mt-1.5 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $row['why']); ?></p><?php endif; ?>
					<?php if ((string) $row['snippet'] !== ''): ?><p class="n2j-hint"><?php echo HTML::chars(mb_substr((string) $row['snippet'], 0, 260)); ?></p><?php endif; ?>
					<p class="n2j-hint mt-1.5"><?php echo HTML::chars((string) $row['status']); ?> · <?php echo HTML::chars(date('d.m.y H:i', strtotime((string) $row['created_at']))); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
