<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var array $city @var array $nearby */ ?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/geo" class="transition hover:text-blue-600 dark:hover:text-blue-400">Города и посёлки</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<?php if ($city['region'] !== ''): ?>
			<a href="/geo?region=<?php echo rawurlencode($city['region']); ?>" class="transition hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars($city['region']); ?></a>
			<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<?php endif; ?>
		<span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars($city['title']); ?></span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1"><?php echo HTML::chars($city['title_full'] !== '' ? $city['title_full'] : $city['title']); ?></h1>
			<?php if ($city['title_full'] !== '' AND $city['title_full'] !== $city['title']): ?>
				<p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?php echo HTML::chars($city['title']); ?> · <?php echo HTML::chars($city['region']); ?></p>
			<?php endif; ?>
		</div>
		<?php if ($city['is_dup']): ?><span class="n2j-badge n2j-badge-run" title="В справочнике есть одноимённый населённый пункт">одноимённый пункт есть</span><?php endif; ?>
	</div>

	<div class="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
		<main class="min-w-0">
			<section class="n2j-card" aria-labelledby="city-facts">
				<h2 id="city-facts" class="n2j-h3">Коротко</h2>
				<dl class="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
					<div class="flex justify-between gap-3 border-b border-gray-100 pb-2 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Регион</dt><dd class="text-right font-semibold text-gray-900 dark:text-white"><?php echo $city['region'] !== '' ? HTML::chars($city['region']) : '—'; ?></dd></div>
					<div class="flex justify-between gap-3 border-b border-gray-100 pb-2 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Район</dt><dd class="text-right font-semibold text-gray-900 dark:text-white"><?php echo $city['district'] !== '' ? HTML::chars($city['district']) : '—'; ?></dd></div>
					<div class="flex justify-between gap-3 border-b border-gray-100 pb-2 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Вид</dt><dd class="text-right font-semibold text-gray-900 dark:text-white"><?php echo $city['kind'] !== '' ? HTML::chars($city['kind']) : '—'; ?></dd></div>
					<div class="flex justify-between gap-3 border-b border-gray-100 pb-2 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Население</dt><dd class="text-right font-semibold tabular-nums text-gray-900 dark:text-white"><?php echo (int) $city['population'] > 0 ? number_format((int) $city['population'], 0, ',', ' ') : 'нет данных'; ?></dd></div>
					<div class="flex justify-between gap-3 border-b border-gray-100 pb-2 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Координаты</dt><dd class="text-right font-mono text-xs font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($city['lat']); ?>, <?php echo HTML::chars($city['lon']); ?></dd></div>
					<div class="flex justify-between gap-3 border-b border-gray-100 pb-2 dark:border-gray-700"><dt class="text-gray-500 dark:text-gray-400">Латиницей</dt><dd class="text-right font-mono text-xs font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($city['name_latin']); ?></dd></div>
				</dl>
			</section>
		</main>

		<?php if ($nearby): ?>
		<aside class="space-y-4">
			<section class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-location-dot mr-2 text-blue-500" aria-hidden="true"></i>Крупнейшие в регионе</h2>
				<ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($nearby as $row): ?>
						<li class="py-1.5">
							<a href="/geo/<?php echo HTML::chars($row['slug']); ?>" class="flex items-baseline justify-between gap-3 text-sm text-gray-700 transition hover:text-blue-700 dark:text-gray-300 dark:hover:text-blue-300">
								<span class="truncate"><?php echo HTML::chars($row['title']); ?></span>
								<span class="shrink-0 text-xs tabular-nums text-gray-400 dark:text-gray-500"><?php echo (int) $row['population'] > 0 ? number_format((int) $row['population'], 0, ',', ' ') : '—'; ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<a href="/geo?region=<?php echo rawurlencode($city['region']); ?>" class="n2j-btn-secondary n2j-btn-sm mt-3">Все населённые пункты региона →</a>
			</section>
		</aside>
		<?php endif; ?>
	</div>
</div>
