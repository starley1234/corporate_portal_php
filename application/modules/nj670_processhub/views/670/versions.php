<?php defined('SYSPATH') or die('No direct script access.');
$process = isset($process) ? (array) $process : [];
$versions = isset($versions) ? (array) $versions : [];
$statuses = isset($statuses) ? (array) $statuses : [];
$left = isset($left) && $left ? (array) $left : NULL;
$right = isset($right) && $right ? (array) $right : NULL;
$diff = isset($diff) && $diff ? (array) $diff : NULL;
$summary = (array) Arr::get($diff, 'summary', []);
$code = (string) Arr::get($process, 'code', '');
$card = 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900';
$kind_label = function ($kind) {
	return $kind === 'fact' ? 'Факт (As-Is)' : 'План (To-Be)';
};
$delta = function ($after, $before) {
	$d = (int) $after - (int) $before;

	if ($d === 0)
	{
		return '<span class="text-gray-400">без изменений</span>';
	}

	return $d > 0
		? '<span class="font-bold text-rose-600 dark:text-rose-400">+'.HTML::chars(Service_Nj670_Repo::minutes_text($d)).'</span>'
		: '<span class="font-bold text-emerald-600 dark:text-emerald-400">−'.HTML::chars(Service_Nj670_Repo::minutes_text(abs($d))).'</span>';
};
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-center justify-between gap-2">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-code-compare mr-2 text-blue-500"></i>Версии процесса</h1>
			<p class="mt-1 n2j-ds-body text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $process['title']); ?> · история редакций и сравнение двух схем: что добавили, что убрали и что поменялось по срокам.</p>
		</div>
		<div class="flex items-center gap-2 n2j-ds-sm font-semibold">
			<a href="/process/<?php echo HTML::chars($code); ?>/editor" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-diagram-project mr-1"></i>Холст</a>
			<a href="/process/<?php echo HTML::chars($code); ?>/report" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-file-word mr-1"></i>Регламент</a>
		</div>
	</div>

	<?php echo View::factory('670/_job')->set('job', isset($job) ? $job : [])->set('code', isset($process) ? (string) $process['code'] : '')->render(); ?>

	<?php if (empty($versions)): ?>
	<section class="mt-5 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center dark:border-gray-700 dark:bg-gray-900/60">
		<i class="fas fa-code-branch text-3xl text-blue-200 dark:text-blue-800"></i>
		<h2 class="mt-3 n2j-ds-body font-bold text-gray-900 dark:text-white">У процесса ещё нет версий</h2>
		<p class="mt-1 n2j-ds-body text-gray-500 dark:text-gray-400">Соберите схему из текста или загрузите журнал — появится первая редакция.</p>
	</section>
	<?php else: ?>

	<section class="mt-5 <?php echo $card; ?>">
		<form method="get" action="/process/<?php echo HTML::chars($code); ?>/versions" class="flex flex-wrap items-end gap-3 p-4">
			<label class="n2j-ds-xs font-semibold text-gray-600 dark:text-gray-300">Было
				<select name="a" class="n2j-input n2j-select mt-1 py-1.5 n2j-ds-sm min-h-0">
					<?php foreach ($versions as $row): ?>
					<option value="<?php echo (int) $row['id']; ?>" <?php echo $left && (int) $left['id'] === (int) $row['id'] ? 'selected' : ''; ?>><?php echo HTML::chars((string) $row['version_tag']); ?> · <?php echo HTML::chars($kind_label((string) $row['kind'])); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="n2j-ds-xs font-semibold text-gray-600 dark:text-gray-300">Стало
				<select name="b" class="n2j-input n2j-select mt-1 py-1.5 n2j-ds-sm min-h-0">
					<?php foreach ($versions as $row): ?>
					<option value="<?php echo (int) $row['id']; ?>" <?php echo $right && (int) $right['id'] === (int) $row['id'] ? 'selected' : ''; ?>><?php echo HTML::chars((string) $row['version_tag']); ?> · <?php echo HTML::chars($kind_label((string) $row['kind'])); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="n2j-btn-process"><i class="fas fa-code-compare mr-1.5"></i>Сравнить</button>
			<span class="n2j-ds-xs text-gray-400">Совет: сравнивайте plan-v1 с plan-v2, чтобы увидеть эффект оптимизации.</span>
		</form>
	</section>

	<?php if ($diff): ?>
	<section class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
		<?php foreach ([
			['Шагов стало', (int) $summary['nodes_before'].' → '.(int) $summary['nodes_after'], 'fa-list-ol'],
			['Добавлено / убрано', '+'.(int) $summary['added'].' / −'.(int) $summary['removed'], 'fa-plus-minus'],
			['Изменено шагов', (int) $summary['changed'], 'fa-pen'],
			['Срок', Service_Nj670_Repo::minutes_text((int) $summary['total_before']).' → '.Service_Nj670_Repo::minutes_text((int) $summary['total_after']), 'fa-clock'],
		] as $tile): ?>
		<div class="<?php echo $card; ?> p-3">
			<div class="flex items-center justify-between gap-1 n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">
				<span><?php echo HTML::chars($tile[0]); ?></span>
				<i class="fas <?php echo $tile[2]; ?> text-gray-400"></i>
			</div>
			<div class="mt-1 n2j-ds-body font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($tile[1]); ?></div>
		</div>
		<?php endforeach; ?>
	</section>

	<section class="mt-3 <?php echo $card; ?> p-4">
		<div class="flex flex-wrap items-center gap-2 n2j-ds-xs text-gray-500 dark:text-gray-400">
			<span class="rounded bg-blue-50 px-2 py-0.5 font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><?php echo HTML::chars((string) $left['version_tag']); ?></span>
			<i class="fas fa-arrow-right text-[9px]"></i>
			<span class="rounded bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><?php echo HTML::chars((string) $right['version_tag']); ?></span>
			<span>· работа <?php echo $delta((int) $summary['minutes_after'], (int) $summary['minutes_before']); ?></span>
			<span>· ожидание <?php echo $delta((int) $summary['wait_after'], (int) $summary['wait_before']); ?></span>
			<span>· связей +<?php echo (int) $summary['edges_added']; ?> / −<?php echo (int) $summary['edges_removed']; ?></span>
		</div>
		<?php if ( ! empty($summary['is_empty'])): ?>
		<p class="mt-3 rounded-lg bg-emerald-50 px-3 py-2 n2j-ds-sm font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"><i class="fas fa-equals mr-1"></i>Схемы совпадают по шагам, связям и срокам.</p>
		<?php endif; ?>
	</section>

	<div class="mt-3 grid gap-3 xl:grid-cols-3">

		<section class="<?php echo $card; ?>">
			<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="n2j-ds-body font-bold text-emerald-700 dark:text-emerald-300"><i class="fas fa-plus mr-1"></i>Добавленные шаги</h2>
				<span class="n2j-ds-xs font-bold text-gray-400"><?php echo count((array) Arr::get($diff, 'added', [])); ?></span>
			</div>
			<?php if (empty($diff['added'])): ?>
			<p class="px-4 py-3 n2j-ds-sm text-gray-400">Нет.</p>
			<?php else: ?>
			<ul class="divide-y divide-gray-100 n2j-ds-sm dark:divide-gray-800">
				<?php foreach ($diff['added'] as $row): ?>
				<li class="px-4 py-2">
					<div class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $row['title']); ?></div>
					<div class="mt-0.5 flex flex-wrap gap-1.5 n2j-ds-xs text-gray-500 dark:text-gray-400">
						<?php if ( ! empty($row['role_code'])): ?><span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-400">@<?php echo HTML::chars((string) $row['role_code']); ?></span><?php endif; ?>
						<?php if ((int) $row['minutes'] > 0): ?><span><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $row['minutes'])); ?></span><?php endif; ?>
						<?php if ((int) $row['wait'] > 0): ?><span class="text-amber-600 dark:text-amber-400">ожидание <?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $row['wait'])); ?></span><?php endif; ?>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</section>

		<section class="<?php echo $card; ?>">
			<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="n2j-ds-body font-bold text-rose-700 dark:text-rose-300"><i class="fas fa-minus mr-1"></i>Удалённые шаги</h2>
				<span class="n2j-ds-xs font-bold text-gray-400"><?php echo count((array) Arr::get($diff, 'removed', [])); ?></span>
			</div>
			<?php if (empty($diff['removed'])): ?>
			<p class="px-4 py-3 n2j-ds-sm text-gray-400">Нет.</p>
			<?php else: ?>
			<ul class="divide-y divide-gray-100 n2j-ds-sm dark:divide-gray-800">
				<?php foreach ($diff['removed'] as $row): ?>
				<li class="px-4 py-2">
					<div class="font-semibold text-gray-500 line-through dark:text-gray-400"><?php echo HTML::chars((string) $row['title']); ?></div>
					<div class="mt-0.5 n2j-ds-xs text-gray-400"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $row['minutes'] + (int) $row['wait'])); ?> освободилось</div>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</section>

		<section class="<?php echo $card; ?>">
			<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="n2j-ds-body font-bold text-amber-700 dark:text-amber-300"><i class="fas fa-pen mr-1"></i>Изменённые шаги</h2>
				<span class="n2j-ds-xs font-bold text-gray-400"><?php echo count((array) Arr::get($diff, 'changed', [])); ?></span>
			</div>
			<?php if (empty($diff['changed'])): ?>
			<p class="px-4 py-3 n2j-ds-sm text-gray-400">Нет.</p>
			<?php else: ?>
			<ul class="divide-y divide-gray-100 n2j-ds-sm dark:divide-gray-800">
				<?php foreach ($diff['changed'] as $row): ?>
				<li class="px-4 py-2">
					<div class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $row['title']); ?></div>
					<div class="mt-0.5 space-y-0.5 n2j-ds-xs">
						<?php foreach ((array) $row['fields'] as $field): ?>
						<div class="flex flex-wrap items-center gap-1">
							<span class="text-gray-400"><?php echo HTML::chars((string) $field); ?>:</span>
							<span class="rounded bg-rose-50 px-1 text-rose-700 line-through dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars((string) Arr::get((array) $row['before'], $field, '—')); ?></span>
							<i class="fas fa-arrow-right text-[8px] text-gray-400"></i>
							<span class="rounded bg-emerald-50 px-1 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><?php echo HTML::chars((string) Arr::get((array) $row['after'], $field, '—')); ?></span>
						</div>
						<?php endforeach; ?>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</section>
	</div>
	<?php endif; ?>

	<section class="mt-3 <?php echo $card; ?>">
		<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
			<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-1.5 text-gray-400"></i>Все версии</h2>
			<span class="n2j-ds-xs text-gray-400"><?php echo count($versions); ?></span>
		</div>
		<div class="overflow-x-auto">
			<table class="n2j-table min-w-full">
				<thead class="bg-gray-50 n2j-ds-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
					<tr>
						<th class="px-4 py-2">Версия</th>
						<th class="px-4 py-2">Контур</th>
						<th class="px-4 py-2">Статус</th>
						<th class="px-4 py-2">Срок</th>
						<th class="px-4 py-2">Стоимость</th>
						<th class="px-4 py-2">Источник</th>
						<th class="px-4 py-2">Создана</th>
						<th class="px-4 py-2 text-right">Сравнить</th>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
					<?php foreach ($versions as $row): ?>
					<tr class="hover:bg-gray-50/70 dark:hover:bg-gray-800/40">
						<td class="px-4 py-2 font-mono n2j-ds-xs font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $row['version_tag']); ?></td>
						<td class="px-4 py-2"><span class="n2j-badge <?php echo (string) $row['kind'] === 'fact' ? 'n2j-badge-wait' : 'n2j-badge-wait'; ?>"><?php echo HTML::chars($kind_label((string) $row['kind'])); ?></span></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($statuses, (string) $row['status'], (string) $row['status'])); ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) Arr::get($row, 'total_duration_minutes', 0))); ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Service_Nj670_Repo::money((float) Arr::get($row, 'total_cost_rub', 0))); ?></td>
						<td class="px-4 py-2 n2j-ds-xs text-gray-400"><?php echo HTML::chars((string) Arr::get($row, 'model', '')); ?><?php echo Arr::get($row, 'model') ? ' · ' : ''; ?><?php echo HTML::chars((string) Arr::get($row, 'source_kind', '')); ?></td>
						<td class="px-4 py-2 n2j-ds-xs text-gray-400"><?php echo HTML::chars((string) $row['created_at']); ?></td>
						<td class="px-4 py-2 text-right whitespace-nowrap">
							<?php if ($right && (int) $right['id'] === (int) $row['id']): ?>
							<span class="n2j-ds-xs font-semibold text-blue-600 dark:text-blue-400">выбрана</span>
							<?php elseif ($right): ?>
							<a href="/process/<?php echo HTML::chars($code); ?>/versions?a=<?php echo (int) $right['id']; ?>&b=<?php echo (int) $row['id']; ?>" class="rounded border border-gray-300 px-1.5 py-0.5 n2j-ds-xs font-semibold text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">с «<?php echo HTML::chars((string) $right['version_tag']); ?>»</a>
							<?php else: ?>
							<a href="/process/<?php echo HTML::chars($code); ?>/versions?b=<?php echo (int) $row['id']; ?>" class="rounded border border-gray-300 px-1.5 py-0.5 n2j-ds-xs font-semibold text-gray-600 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-700">сравнить</a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>
</div>
