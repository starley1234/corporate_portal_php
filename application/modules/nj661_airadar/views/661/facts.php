<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var array $rows @var string $q */ ?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/airadar" class="transition hover:text-blue-600 dark:hover:text-blue-400">ИИ-радар</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">База знаний</span>
	</nav>

	<h1 class="n2j-h1 mt-3">База знаний: факты</h1>
	<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Не вода, а найденное: конкретные факты с числами и источником. Повтор факта не создаёт дубль — растёт счётчик встреч (в т.ч. семантически похожие).</p>

	<form method="get" action="/airadar/facts" class="n2j-card mt-5 flex flex-wrap items-end gap-2">
		<label class="flex flex-col gap-1"><span class="text-xs font-bold uppercase tracking-wide text-gray-400 dark:text-gray-500">Поиск по фактам и тегам</span>
			<input type="text" name="q" value="<?php echo HTML::chars($q); ?>" placeholder="например: mixtral или контекст" class="n2j-input w-72"></label>
		<button type="submit" class="n2j-btn-process">Найти</button>
	</form>

	<?php if ( ! $rows): ?>
		<p class="mt-6 rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">Пока пусто — факты появятся после первого прогона конвейера.</p>
	<?php else: ?>
		<div class="mt-5 space-y-2">
			<?php foreach ($rows as $row): ?>
				<article class="n2j-card">
					<p class="text-sm leading-relaxed text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) $row['text']); ?></p>
					<div class="mt-2 flex flex-wrap items-center gap-2">
						<?php foreach (array_slice(array_filter(array_map('trim', explode(',', (string) $row['tags']))), 0, 6) as $tag): ?>
							<span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars($tag); ?></span>
						<?php endforeach; ?>
						<?php if ((int) $row['sightings'] > 1): ?><span class="n2j-badge n2j-badge-info">встречался ×<?php echo (int) $row['sightings']; ?></span><?php endif; ?>
						<?php if ((string) $row['source_url'] !== ''): ?><a href="<?php echo HTML::chars((string) $row['source_url']); ?>" rel="noopener nofollow" class="text-xs text-blue-700 hover:text-blue-900 dark:text-blue-300">источник</a><?php endif; ?>
						<span class="ml-auto text-xs text-gray-400 dark:text-gray-500">последний раз: <?php echo HTML::chars(date('d.m.y', strtotime((string) $row['last_seen']))); ?></span>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
