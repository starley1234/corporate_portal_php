<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Панель модератора каталога иллюстраций (nj668).
 * Слева — очередь, в центре SVG, справа — вердикт.
 * Публикация без ревизии; здесь — одобрить, образец, отклонить.
 * Владелец, 11.10.2026.
 */

$queue    = isset($queue) ? (array) $queue : [];
$current  = isset($current) ? (array) $current : NULL;
$svg      = isset($svg) ? (string) $svg : '';
$status   = isset($status) ? (string) $status : '';
$statuses = isset($statuses) ? (array) $statuses : [];
$error    = isset($error) ? (string) $error : '';
$csrf     = isset($csrf) ? (string) $csrf : '';

$badges = [
	'draft'     => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
	'published' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
	'rejected'  => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-gavel mr-2 text-blue-500"></i>Модерация иллюстраций</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Слева — заявки каталога. В центре — вектор целиком. Справа — решение: одобрить, отметить как образец или отклонить. Причина по желанию.</p>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-4 flex flex-wrap gap-2">
		<a href="/draw2d/moderate" class="n2j-chip<?php echo $status === '' ? ' is-on' : ''; ?>">Очередь</a>
		<?php foreach ($statuses as $key => $title): ?>
		<a href="/draw2d/moderate?status=<?php echo rawurlencode((string) $key); ?>"
			class="n2j-chip<?php echo $status === (string) $key ? ' is-on' : ''; ?>">
			<?php echo HTML::chars((string) $title); ?>
		</a>
		<?php endforeach; ?>
	</div>

	<div class="mt-5 gap-5 lg:grid lg:grid-cols-3 lg:items-start">

		<div class="min-w-0">
			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-list mr-1.5 text-blue-500"></i>Заявки</h2>

				<?php if ( ! $queue): ?>
				<p class="n2j-hint mt-3">Ничего нет.</p>
				<?php else: ?>
				<ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
					<?php foreach ($queue as $row): ?>
					<?php
						$active = $current && ((int) Arr::get($row, 'id') === (int) Arr::get($current, 'id'));
						$q = '/draw2d/moderate?code='.rawurlencode((string) Arr::get($row, 'code'));
						if ($status !== '') { $q .= '&status='.rawurlencode($status); }
					?>
					<li>
						<a href="<?php echo HTML::chars($q); ?>"
							class="flex items-center gap-2 py-2 <?php echo $active ? 'font-semibold text-blue-600 dark:text-blue-400' : ''; ?>">
							<?php echo Service_Nj668_Draw2d::preview_img($row, '', 'h-8 w-12 shrink-0 rounded border border-gray-200 object-contain dark:border-gray-700'); ?>
							<span class="min-w-0 flex-1 truncate text-xs text-gray-800 dark:text-gray-200">
								<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
							</span>
							<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold <?php echo Arr::get($badges, (string) Arr::get($row, 'status'), Arr::get($badges, 'draft')); ?>">
								<?php echo HTML::chars(Arr::get($statuses, (string) Arr::get($row, 'status'), (string) Arr::get($row, 'status'))); ?>
							</span>
						</a>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>
		</div>

		<div class="mt-5 min-w-0 lg:mt-0">
			<?php if ($current === NULL): ?>
			<div class="n2j-card text-center text-sm text-gray-500 dark:text-gray-400">
				Выберите заявку слева.
			</div>
			<?php else: ?>
			<div class="n2j-card p-0 overflow-hidden">
				<div class="nj668-hero flex justify-center [&_svg]:block [&_svg]:h-auto [&_svg]:max-h-[min(70vh,720px)] [&_svg]:w-auto [&_svg]:max-w-full"><?php echo $svg; ?></div>
			</div>
			<p class="n2j-hint mt-2">фигур: <?php echo (int) Arr::get($current, 'shapes_count'); ?>
				· просмотров: <?php echo (int) Arr::get($current, 'views'); ?></p>
			<?php endif; ?>
		</div>

		<div class="mt-5 min-w-0 lg:mt-0">
			<?php if ($current !== NULL): ?>
			<?php $code = (string) Arr::get($current, 'code'); ?>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-stamp mr-1.5 text-blue-500"></i>Решение</h2>

				<p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($current, 'title')); ?></p>
				<p class="n2j-hint"><?php echo HTML::chars((string) Arr::get($current, 'summary') ?: (string) Arr::get($current, 'prompt', '')); ?></p>

				<form method="post" action="/draw2d/moderate<?php echo $status !== '' ? '?status='.rawurlencode($status) : ''; ?>" class="mt-4 space-y-3">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="code" value="<?php echo HTML::chars($code); ?>">

					<div>
						<label for="nj668-note" class="n2j-label">Причина <span class="font-normal text-gray-400">(по желанию)</span></label>
						<textarea id="nj668-note" name="note" rows="3" maxlength="500"
							placeholder="Например: пустая сцена — не публикуем"
							class="n2j-textarea mt-1 w-full resize-y"></textarea>
					</div>

					<div class="grid gap-2">
						<button type="submit" name="verdict" value="approve"
							class="n2j-btn-cta">Одобрить</button>
						<button type="submit" name="verdict" value="feature"
							class="n2j-btn-secondary">Одобрить и в образцы</button>
						<?php if ((int) Arr::get($current, 'featured') === 1): ?>
						<button type="submit" name="verdict" value="unfeature"
							class="n2j-btn-secondary">Снять «образец»</button>
						<?php endif; ?>
						<button type="submit" name="verdict" value="reject"
							class="n2j-btn-danger">Отклонить</button>
					</div>
				</form>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>
