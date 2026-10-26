<?php defined('SYSPATH') or die('No direct script access.');
$badges = [
	'draft' => ['Черновик', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'],
	'review' => ['На модерации', 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'],
	'published' => ['Опубликовано', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'],
	'rejected' => ['Нужна доработка', 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200'],
];
$small_btn = 'n2j-btn-secondary n2j-btn-sm';
$field = 'n2j-textarea mt-1 w-full';
$action = $mode === 'moderate' ? '/solid3d/catalog?view=moderate' : '/solid3d/catalog';
// Главная вызывает карточки без прав: кнопки модерации показываются только явно переданному модератору.
$moderator = ! empty($moderator);
$csrf = (string) ($csrf ?? '');
?>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
<?php foreach ($items as $item):
	$rid = (int) ($mode === 'projects' ? $item['current_revision_id'] : ($mode === 'moderate' ? $item['review_revision_id'] : $item['published_revision_id']));
	$href = '/solid3d/'.($mode === 'catalog' ? 'c' : 'p').'/'.$item['code'].($mode === 'moderate' ? '?revision='.$rid : '');
	$badge = $badges[$item['publication']] ?? $badges['draft'];
	$vlm = (string) ($item['review_vlm_status'] ?? '');
	if ($mode === 'moderate')
	{
		// PostgreSQL отдаёт boolean строкой 't'/'f'; проверка ракурсов хранит вердикт в visual_ok.
		$passed = in_array($item['review_visual_ok'] ?? NULL, [TRUE, 't', 'true', 1, '1'], TRUE);
		if ($vlm === 'done') { $check = $passed ? ['ИИ: ракурсы в порядке', 'text-emerald-700 dark:text-emerald-300'] : ['ИИ: есть замечания', 'text-amber-700 dark:text-amber-300']; }
		elseif ($vlm === 'error') { $check = ['ИИ: проверка не прошла', 'text-rose-700 dark:text-rose-300']; }
		elseif ($vlm === 'queued' OR $vlm === 'running') { $check = ['ИИ: проверка идёт', 'text-blue-700 dark:text-blue-300']; }
		else { $check = ['ИИ не проверял', 'text-gray-500 dark:text-gray-400']; }
	}
?>
	<article class="n2j-card flex min-w-0 flex-col p-3 transition hover:border-blue-300 dark:hover:border-blue-700">
		<a href="<?php echo HTML::chars($href); ?>" class="flex min-w-0 flex-1 flex-col">
			<div class="flex h-36 items-center justify-center rounded-lg bg-blue-50/60 p-2 dark:bg-gray-800/70">
			<?php if ($rid): ?>
				<img loading="lazy" class="max-h-full max-w-full object-contain" src="/solid3d/f/<?php echo HTML::chars($item['code']).'/'.$rid; ?>/png" alt="Превью детали: <?php echo HTML::chars($item['title']); ?>">
			<?php else: ?>
				<i class="fas fa-cube text-2xl text-gray-400" aria-hidden="true"></i>
			<?php endif; ?>
			</div>
			<h2 class="n2j-h3 mt-2 line-clamp-2"><?php echo HTML::chars($item['title']); ?></h2>
			<?php if ((string) $item['summary'] !== ''): ?><p class="mt-1 line-clamp-2 text-xs text-gray-600 dark:text-gray-300"><?php echo HTML::chars(mb_substr($item['summary'], 0, 180)); ?></p><?php endif; ?>
		</a>
		<div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
			<?php if ($mode !== 'catalog'): ?>
				<span class="rounded-md px-1.5 py-0.5 font-semibold <?php echo $badge[1]; ?>"><?php echo $badge[0]; ?></span>
				<span>Версия <?php echo $rid ?: 'ещё нет'; ?></span>
			<?php endif; ?>
			<?php if ($mode === 'moderate'): ?><span class="<?php echo $check[1]; ?>"><?php echo $check[0]; ?></span><?php endif; ?>
			<?php if ($mode !== 'catalog' AND Arr::get($item, 'tags', '') !== ''): ?><span class="min-w-0 truncate"><?php echo HTML::chars($item['tags']); ?></span><?php endif; ?>
		</div>
		<?php if ($mode === 'catalog' AND $moderator): ?>
		<form method="post" action="<?php echo HTML::chars($action); ?>" autocomplete="off" data-n2j-no-remember class="mt-2 border-t border-gray-100 pt-2 dark:border-gray-800">
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
			<input type="hidden" name="code" value="<?php echo HTML::chars($item['code']); ?>">
			<button class="<?php echo $small_btn; ?>" type="submit" name="op" value="unpublish">Снять с публикации</button>
		</form>
		<?php elseif ($mode === 'moderate' AND $moderator): ?>
		<div class="mt-2 space-y-2 border-t border-gray-100 pt-2 dark:border-gray-800">
			<form method="post" action="<?php echo HTML::chars($action); ?>" autocomplete="off" data-n2j-no-remember class="flex flex-wrap gap-1.5">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="code" value="<?php echo HTML::chars($item['code']); ?>">
				<button class="n2j-btn-cta n2j-btn-sm" type="submit" name="op" value="publish">Опубликовать</button>
			</form>
			<details class="text-xs">
				<summary class="cursor-pointer font-semibold text-rose-700 dark:text-rose-300">Отклонить с причиной</summary>
				<form method="post" action="<?php echo HTML::chars($action); ?>" autocomplete="off" data-n2j-no-remember class="mt-1.5">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="code" value="<?php echo HTML::chars($item['code']); ?>">
					<label class="block text-xs font-semibold text-gray-600 dark:text-gray-300">Причина (увидит владелец)
						<textarea name="note" rows="2" maxlength="1000" required autocomplete="off" data-n2j-no-remember class="<?php echo $field; ?>"></textarea>
					</label>
					<button class="n2j-btn-danger n2j-btn-sm mt-1.5" type="submit" name="op" value="reject">Отклонить</button>
				</form>
			</details>
		</div>
		<?php endif; ?>
	</article>
<?php endforeach; ?>
</div>
<?php if ( ! $items): ?><div class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">Здесь пока нет деталей.</div><?php endif; ?>
