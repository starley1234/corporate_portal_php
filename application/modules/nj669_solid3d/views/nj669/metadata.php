<?php defined('SYSPATH') or die('No direct script access.');
// Карточка каталога: только чтение. Название, описание и теги пишет дешёвая модель после первой
// удачной сборки; администратор перегенерирует её иконкой. Владелец полей не получает.
$ui = [
	'card' => 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900',
	'h2' => 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400',
	'muted' => 'text-xs text-gray-500 dark:text-gray-400',
	'icon' => 'n2j-btn-icon h-8 w-8',
];
$last = $captions ? $captions[0] : NULL;
$last_busy = $last && in_array($last['status'], ['queued', 'running'], TRUE); // && в присваивании: AND здесь не сработал бы
?>
<section class="<?php echo $ui['card']; ?> p-3">
	<div class="flex items-center justify-between gap-2">
		<h2 class="<?php echo $ui['h2']; ?>">Карточка в каталоге</h2>
		<?php if ($moderator): ?>
		<form method="post" action="/solid3d/p/<?php echo HTML::chars($p['code']); ?>" autocomplete="off" data-n2j-no-remember>
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
			<input type="hidden" name="op" value="caption">
			<button type="submit" class="<?php echo $ui['icon']; ?>" title="Перегенерировать карточку" aria-label="Перегенерировать карточку" <?php echo $last_busy ? 'disabled' : ''; ?>><i class="fas fa-rotate <?php echo $last_busy ? 'fa-spin' : ''; ?>" aria-hidden="true"></i></button>
		</form>
		<?php endif; ?>
	</div>

	<p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($p, 'title', '')); ?></p>
	<?php if ((string) Arr::get($p, 'summary', '') !== ''): ?>
	<p class="mt-1 text-xs text-gray-700 dark:text-gray-300"><?php echo HTML::chars($p['summary']); ?></p>
	<?php endif; ?>
	<?php if ((string) Arr::get($p, 'tags', '') !== ''): ?>
	<p class="mt-1 <?php echo $ui['muted']; ?>"><?php echo HTML::chars($p['tags']); ?></p>
	<?php endif; ?>

	<?php if ($last_busy): ?>
	<p class="mt-2 <?php echo $ui['muted']; ?>"><span class="nj667-spin-ring align-[-2px]" aria-hidden="true"></span> Подбираем название и описание.</p>
	<?php elseif ($last AND $last['status'] === 'error'): ?>
	<p class="mt-2 text-xs text-rose-600 dark:text-rose-400"><?php echo $moderator ? HTML::chars($last['error']) : 'Карточку не удалось подобрать автоматически.'; ?></p>
	<?php endif; ?>

	<p class="mt-2 <?php echo $ui['muted']; ?>">Подбирается автоматически после первой удачной сборки. Перегенерация или любая правка детали снимает её с каталога.</p>
</section>
