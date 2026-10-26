<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php /** @var array $rows @var string $status @var string $csrf @var array $flash */
$statuses = [
	'new'      => ['label' => 'новая',      'cls' => 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300'],
	'accepted' => ['label' => 'в работе',   'cls' => 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300'],
	'done'     => ['label' => 'реализовано', 'cls' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
	'rejected' => ['label' => 'отклонена',  'cls' => 'bg-gray-100 text-gray-500 dark:bg-gray-900 dark:text-gray-400'],
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/airadar" class="transition hover:text-blue-600 dark:hover:text-blue-400">ИИ-радар</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">Идеи</span>
	</nav>

	<h1 class="n2j-h1 mt-3">💡 Идеи для N2J</h1>
	<p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Что конвейер предлагает реализовать на платформе: каждая идея — с пользой и местом применения. Дубль по смыслу заголовка не создаётся, повтор только поднимает скор.</p>

	<?php if ($flash['notice'] !== ''): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block"><?php echo HTML::chars($flash['notice']); ?></p>
	<?php endif; ?>
	<?php if ($flash['error'] !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($flash['error']); ?></p>
	<?php endif; ?>

	<div class="mt-5 flex flex-wrap gap-2">
		<?php $tabs = ['' => ['label' => 'все']] + $statuses; ?>
		<?php foreach ($tabs as $key => $meta): ?>
			<a href="/airadar/ideas<?php echo $key !== '' ? '?status='.HTML::chars($key) : ''; ?>" class="n2j-chip<?php echo $status === (string) $key ? ' is-on' : ''; ?>"><?php echo HTML::chars($meta['label']); ?></a>
		<?php endforeach; ?>
	</div>

	<?php if ( ! $rows): ?>
		<p class="mt-6 rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">Идей пока нет — они появятся после прогонов конвейера.</p>
	<?php else: ?>
		<div class="mt-5 space-y-3">
			<?php foreach ($rows as $row): $meta = Arr::get($statuses, (string) $row['status'], $statuses['new']); ?>
				<article class="n2j-card">
					<div class="flex flex-wrap items-baseline gap-2">
						<p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $row['title']); ?></p>
						<span class="n2j-badge n2j-badge-info">скор <?php echo (int) $row['score']; ?></span>
						<span class="rounded-full px-2 py-0.5 text-xs font-bold <?php echo $meta['cls']; ?>"><?php echo HTML::chars($meta['label']); ?></span>
					</div>
					<?php if ((string) $row['body'] !== ''): ?><p class="mt-1.5 text-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $row['body']); ?></p><?php endif; ?>
					<?php if ((string) $row['benefit'] !== ''): ?><p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300"><b>Польза:</b> <?php echo HTML::chars((string) $row['benefit']); ?></p><?php endif; ?>
					<?php if ((string) $row['fit'] !== ''): ?><p class="mt-1 text-xs text-blue-700 dark:text-blue-300"><b>Куда на n2j:</b> <?php echo HTML::chars((string) $row['fit']); ?></p><?php endif; ?>
					<div class="mt-2 flex flex-wrap items-center gap-2">
						<?php if ((string) $row['source_url'] !== ''): ?><a href="<?php echo HTML::chars((string) $row['source_url']); ?>" rel="noopener nofollow" class="text-xs text-blue-700 hover:text-blue-900 dark:text-blue-300">источник</a><?php endif; ?>
						<span class="text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.y', strtotime((string) $row['created_at']))); ?></span>
						<form method="post" action="/airadar/idea" class="ml-auto flex gap-1.5">
							<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
							<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
							<button type="submit" name="status" value="accepted" class="n2j-btn-secondary n2j-btn-sm">В работу</button>
							<button type="submit" name="status" value="done" class="n2j-btn-secondary n2j-btn-sm">Реализовано</button>
							<button type="submit" name="status" value="rejected" class="n2j-btn-secondary n2j-btn-sm">Отклонить</button>
							<?php if ((string) $row['status'] !== 'new'): ?>
								<button type="submit" name="status" value="new" class="n2j-btn-secondary n2j-btn-sm">Сброс</button>
							<?php endif; ?>
						</form>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
