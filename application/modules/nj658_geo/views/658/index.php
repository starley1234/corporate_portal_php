<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array $rows @var int $total @var int $page @var int $pages @var string $query @var string $region @var array $regions */
$query_str = http_build_query(array_filter([
	'q' => $query !== '' ? $query : NULL,
	'region' => $region !== '' ? $region : NULL,
]));
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-city text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Города и посёлки</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Справочник населённых пунктов России: <?php echo number_format($total, 0, ',', ' '); ?> найдено по текущему фильтру. Поиск понимает «ё» и латиницу, крупные города — первыми.</p>
		</div>
		<span class="n2j-badge n2j-badge-wait">2020 записей · 82 региона</span>
	</div>

	<form method="get" action="/geo" class="n2j-card mt-5 flex flex-wrap items-end gap-3">
		<div class="min-w-[12rem] flex-1">
			<label class="n2j-label" for="geo-q">Название</label>
			<input id="geo-q" name="q" class="n2j-input mt-1.5 w-full" value="<?php echo HTML::chars($query); ?>" placeholder="москв, Абан, zverinogolovskoe…">
		</div>
		<div class="min-w-[12rem]">
			<label class="n2j-label" for="geo-region">Регион</label>
			<select id="geo-region" name="region" class="n2j-input n2j-select mt-1.5 w-full">
				<option value="">все регионы</option>
				<?php foreach ($regions as $r): ?>
					<option value="<?php echo HTML::chars($r['title']); ?>" <?php echo $region === $r['title'] ? 'selected' : ''; ?>><?php echo HTML::chars($r['title']); ?> (<?php echo (int) $r['cities']; ?>)</option>
				<?php endforeach; ?>
			</select>
		</div>
		<button type="submit" class="n2j-btn-process">Найти</button>
		<?php if ($query !== '' OR $region !== ''): ?>
			<a href="/geo" class="min-h-[44px] inline-flex items-center px-2 text-sm font-semibold text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">Сбросить</a>
		<?php endif; ?>
	</form>

	<?php if ( ! $rows): ?>
		<div class="mt-6 rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-6 text-center dark:border-blue-900 dark:bg-blue-950/20">
			<i class="fas fa-city text-2xl text-blue-500" aria-hidden="true"></i>
			<p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Ничего не найдено</p>
			<p class="n2j-hint mx-auto max-w-md">Попробуйте начало названия или выберите регион. Если места нет в справочнике — его можно добавить в панели управления.</p>
		</div>
	<?php else: ?>
		<div class="n2j-card p-0 mt-6 overflow-x-auto">
			<table class="n2j-table">
				<thead>
					<tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
						<th class="px-4 py-3 font-semibold">Название</th>
						<th class="px-4 py-3 font-semibold">Регион</th>
						<th class="hidden px-4 py-3 font-semibold md:table-cell">Район</th>
						<th class="px-4 py-3 text-right font-semibold">Население</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($rows as $row): ?>
						<tr class="transition hover:bg-gray-50 dark:hover:bg-gray-900/60">
							<td class="px-4 py-2.5">
								<a href="/geo/<?php echo HTML::chars($row['slug']); ?>" class="font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300"><?php echo HTML::chars($row['title']); ?></a>
								<?php if ($row['kind'] !== ''): ?><span class="ml-1.5 text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($row['kind']); ?></span><?php endif; ?>
							</td>
							<td class="px-4 py-2.5 text-gray-600 dark:text-gray-300"><?php echo $row['region'] !== '' ? HTML::chars($row['region']) : '—'; ?></td>
							<td class="hidden px-4 py-2.5 text-gray-500 md:table-cell dark:text-gray-400"><?php echo $row['district'] !== '' ? HTML::chars($row['district']) : '—'; ?></td>
							<td class="px-4 py-2.5 text-right tabular-nums text-gray-600 dark:text-gray-300"><?php echo (int) $row['population'] > 0 ? number_format((int) $row['population'], 0, ',', ' ') : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ($pages > 1): ?>
			<div class="mt-5 flex items-center justify-between">
				<?php if ($page > 1): ?>
					<a href="/geo?<?php echo HTML::chars($query_str.'&page='.($page - 1)); ?>" class="n2j-btn-secondary">← Назад</a>
				<?php else: ?><span></span><?php endif; ?>
				<span class="text-sm text-gray-500 dark:text-gray-400">страница <?php echo $page; ?> из <?php echo $pages; ?></span>
				<?php if ($page < $pages): ?>
					<a href="/geo?<?php echo HTML::chars($query_str.'&page='.($page + 1)); ?>" class="n2j-btn-secondary">Вперёд →</a>
				<?php else: ?><span></span><?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<p class="n2j-hint mt-8">Справочник общий для сервисов сайта: подсказки городов в формах (например, вакансий) берутся отсюда. Данные — <a class="underline hover:text-blue-600" href="/geo/api/regions">открытый JSON-API</a>.</p>
</div>
