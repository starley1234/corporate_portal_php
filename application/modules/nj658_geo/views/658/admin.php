<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array $stats @var array $rows @var int $total @var int $page @var int $pages @var string $query @var string $csrf @var string $notice @var string $error */
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-pen-to-square text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Города и посёлки · управление</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Справочник общий для платформы: правки сразу видны всем модулям, которые его читают (подсказки форм, фильтры по городам).</p>
		</div>
		<div class="flex items-center gap-2">
			<a href="/geo/admin/export" class="n2j-btn-secondary"><i class="fas fa-file-csv mr-2" aria-hidden="true"></i>CSV</a>
			<a href="/geo/admin/city" class="n2j-btn-cta"><i class="fas fa-plus mr-2" aria-hidden="true"></i>Добавить</a>
		</div>
	</div>

	<?php if ($notice): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block"><?php echo HTML::chars($notice); ?></p>
	<?php endif; ?>
	<?php if ($error): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($error); ?></p>
	<?php endif; ?>

	<div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Всего записей</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format((int) Arr::get($stats, 'total', 0), 0, ',', ' '); ?></p></div>
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Из них городов</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format((int) Arr::get($stats, 'cities', 0), 0, ',', ' '); ?></p></div>
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Регионов</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($stats, 'regions', 0); ?></p></div>
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Активных</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format((int) Arr::get($stats, 'active', 0), 0, ',', ' '); ?></p></div>
		<div class="n2j-card"><p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Добавлено вручную</p><p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($stats, 'manual', 0); ?></p></div>
	</div>

	<form method="get" action="/geo/admin" class="n2j-card mt-5 flex flex-wrap items-end gap-3">
		<div class="min-w-[14rem] flex-1">
			<label class="n2j-label" for="admq">Поиск (название, регион, slug)</label>
			<input id="admq" name="q" class="n2j-input mt-1.5 w-full" value="<?php echo HTML::chars($query); ?>" placeholder="москв, Хакасия, abakan…">
		</div>
		<button type="submit" class="n2j-btn-process">Найти</button>
		<?php if ($query !== ''): ?><a href="/geo/admin" class="min-h-[44px] inline-flex items-center px-2 text-sm font-semibold text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">Сбросить</a><?php endif; ?>
		<span class="ml-auto self-center text-xs text-gray-400 dark:text-gray-500"><?php echo number_format($total, 0, ',', ' '); ?> записей</span>
	</form>

	<?php if ( ! $rows): ?>
		<div class="mt-6 rounded-xl border border-dashed border-blue-200 bg-blue-50/60 p-6 text-center dark:border-blue-900 dark:bg-blue-950/20">
			<i class="fas fa-city text-2xl text-blue-500" aria-hidden="true"></i>
			<p class="mt-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Ничего не найдено</p>
		</div>
	<?php else: ?>
		<div class="mt-6 space-y-2">
			<?php foreach ($rows as $row): ?>
				<div class="n2j-card p-0 flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
					<div class="min-w-0 flex-1">
						<div class="flex flex-wrap items-center gap-2">
							<a href="/geo/admin/city/<?php echo (int) $row['id']; ?>" class="text-sm font-semibold text-gray-900 hover:text-blue-700 dark:text-white dark:hover:text-blue-300"><?php echo HTML::chars($row['title']); ?></a>
							<?php if ( ! $row['is_active']): ?><span class="n2j-badge n2j-badge-run">скрыт</span><?php endif; ?>
							<?php if ($row['source'] === 'manual'): ?><span class="n2j-badge n2j-badge-info">добавлен вручную</span><?php endif; ?>
							<span class="font-mono text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars($row['slug']); ?></span>
						</div>
						<p class="n2j-hint mt-0.5 truncate"><?php echo HTML::chars($row['region']); ?><?php echo $row['district'] !== '' ? ' · '.HTML::chars($row['district']) : ''; ?> · <?php echo HTML::chars($row['kind']); ?> · <?php echo (int) $row['population'] > 0 ? number_format((int) $row['population'], 0, ',', ' ') : '—'; ?></p>
					</div>
					<a href="/geo/<?php echo HTML::chars($row['slug']); ?>" class="text-xs font-semibold text-gray-500 transition hover:text-blue-700 dark:text-gray-400 dark:hover:text-blue-300" title="Открыть публичную страницу">страница →</a>
					<a href="/geo/admin/city/<?php echo (int) $row['id']; ?>" class="n2j-btn-secondary n2j-btn-sm">Изменить</a>
					<form method="post" action="/geo/admin/delete" class="inline">
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
						<button type="submit" class="n2j-btn-danger n2j-btn-sm">Удалить</button>
					</form>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ($pages > 1): ?>
			<div class="mt-6 flex items-center justify-between">
				<?php if ($page > 1): ?>
					<a href="/geo/admin?q=<?php echo HTML::chars(rawurlencode($query).'&page='.($page - 1)); ?>" class="n2j-btn-secondary">← Назад</a>
				<?php else: ?><span></span><?php endif; ?>
				<span class="text-sm text-gray-500 dark:text-gray-400">страница <?php echo $page; ?> из <?php echo $pages; ?></span>
				<?php if ($page < $pages): ?>
					<a href="/geo/admin?q=<?php echo HTML::chars(rawurlencode($query).'&page='.($page + 1)); ?>" class="n2j-btn-secondary">Вперёд →</a>
				<?php else: ?><span></span><?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
