<?php defined('SYSPATH') or die('No direct script access.');
$overview = isset($overview) ? (array) $overview : [];
$statuses = isset($statuses) ? (array) $statuses : [];
$processes = (array) Arr::get($overview, 'processes', []);
$systems = (array) Arr::get($overview, 'systems', []);
$roles = (array) Arr::get($overview, 'roles', []);
$artifacts = (array) Arr::get($overview, 'artifacts', []);
$departments = (array) Arr::get($overview, 'departments', []);
$matrix = (array) Arr::get($overview, 'matrix', []);
$gaps = (array) Arr::get($overview, 'gaps', []);
$insights = (array) Arr::get($overview, 'insights', []);
$calls = (array) Arr::get($overview, 'calls', []);
$tree = (array) Arr::get($overview, 'tree', []);
$roots = (array) Arr::get($overview, 'roots', []);
$tree_by_id = (array) Arr::get($tree, 'by_id', []);
$tree_children = (array) Arr::get($tree, 'children', []);

// Рекурсивная отрисовка ветки: глубина ограничена 6 уровнями, чтобы
// случайный цикл parent_id не превратил карту в бесконечную лестницу.
$render_branch = function ($parent_id, $depth = 0) use (&$render_branch, $tree_by_id, $tree_children) {
	if ($depth > 6)
	{
		return '';
	}

	$html = '';
	$kids = (array) Arr::get($tree_children, $parent_id, []);

	foreach ($kids as $child_id)
	{
		$child = (array) Arr::get($tree_by_id, $child_id, []);

		if ( ! $child)
		{
			continue;
		}

		$html .= '<div class="ml-'.($depth > 0 ? '4' : '0').' border-l border-gray-200 pl-3 dark:border-gray-700" style="margin-left:'.($depth * 14).'px">';
		$html .= '<div class="flex flex-wrap items-center gap-1.5 py-1">';
		$html .= '<a href="/process/'.HTML::chars((string) $child['code']).'" class="group flex flex-wrap items-center gap-1.5 n2j-ds-xs">';
		$html .= '<i class="fas fa-diagram-project text-[10px] text-gray-400"></i>';
		$html .= '<span class="font-semibold text-gray-800 group-hover:text-blue-600 dark:text-gray-200">'.HTML::chars((string) $child['title']).'</span>';
		$html .= '<span class="n2j-ds-xs text-gray-400">'.((int) Arr::get($child, 'plan_minutes', 0) > 0 ? HTML::chars(Service_Nj670_Repo::minutes_text((int) $child['plan_minutes'])) : 'без плана').'</span>';
		$html .= '</a>';
		$html .= '<a href="/process/'.HTML::chars((string) $child['code']).'/passport" class="n2j-ds-xs text-gray-400 hover:text-blue-600 dark:hover:text-gray-400" title="Паспорт процесса"><i class="fas fa-id-card"></i></a>';
		$html .= '</div>';
		$html .= $render_branch($child_id, $depth + 1);
		$html .= '</div>';
	}

	return $html;
};

$ontology = (array) Arr::get($overview, 'ontology', []);
$card = 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900';

$owned = 0; $with_fact = 0; $with_plan = 0;
foreach ($processes as $process)
{
	if ((int) Arr::get($process, 'owner_role_id', 0) > 0) { $owned++; }
	if ((int) Arr::get($process, 'fact_minutes', 0) > 0) { $with_fact++; }
	if ((int) Arr::get($process, 'plan_minutes', 0) > 0) { $with_plan++; }
}
$total = count($processes);
$coverage = $total > 0 ? round($owned / $total * 100) : 0;
$system_codes = [];
foreach ($systems as $system) { $system_codes[] = (string) $system['code']; }
$kpi = function ($value, $label, $hint = '', $icon = 'fa-chart-simple', $tone = 'text-gray-400') {
	return [$value, $label, $hint, $icon, $tone];
};
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-start justify-between gap-3">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-building mr-2 text-blue-500"></i>Карта организации</h1>
			<p class="mt-1 max-w-3xl n2j-ds-body text-gray-600 dark:text-gray-300">Одна картина: процессы по подразделениям, владельцы, системы, артефакты и пробелы. Всё собрано из версий схем и справочника — карта не меняет данные.</p>
		</div>
		<div class="flex flex-wrap items-center gap-2 n2j-ds-sm font-semibold">
			<a href="/process/ontology" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-wand-magic-sparkles mr-1"></i>Разобрать документ</a>
			<a href="/process/ontology" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-sitemap mr-1"></i>Онтология</a>
			<a href="/process" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-diagram-project mr-1"></i>Процессы</a>
		</div>
	</div>

	<?php if ($total === 0): ?>
	<section class="mt-5 rounded-xl border border-dashed border-gray-300 bg-white px-6 py-12 text-center dark:border-gray-700 dark:bg-gray-900">
		<i class="fas fa-building-circle-exclamation text-3xl text-gray-300 dark:text-gray-600"></i>
		<h2 class="mt-3 n2j-ds-body font-bold text-gray-900 dark:text-white">Карта пока пуста</h2>
		<p class="mx-auto mt-1 max-w-xl n2j-ds-body text-gray-500 dark:text-gray-400">Загрузите регламент на странице онтологии: модель предложит роли, системы, артефакты и процессы. После сохранения здесь появится структура компании целиком.</p>
		<a href="/process/ontology" class="n2j-btn-process mt-4"><i class="fas fa-wand-magic-sparkles mr-1.5"></i>Заполнить из документа</a>
	</section>
	<?php else: ?>

	<!-- KPI -->
	<div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
		<?php
		$kpis = [
			$kpi($total, 'процессов', 'в реестре', 'fa-diagram-project', 'text-blue-600 dark:text-blue-400'),
			$kpi($ontology['system'] ?? 0, 'систем', count($systems).' используются', 'fa-server', 'text-emerald-700 dark:text-emerald-400'),
			$kpi($ontology['role'] ?? 0, 'ролей', count($roles).' задействованы', 'fa-user-tie', 'text-gray-500 dark:text-gray-400'),
			$kpi($ontology['department'] ?? 0, 'подразделений', count($departments).' с процессами', 'fa-building', 'text-gray-500 dark:text-gray-400'),
			$kpi($coverage.'%', 'с владельцем', $owned.' из '.$total, 'fa-user-shield', $coverage >= 80 ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'),
		];
		foreach ($kpis as $item): ?>
		<div class="<?php echo $card; ?> p-3">
			<div class="flex items-start justify-between gap-1">
				<span class="text-2xl font-black text-gray-900 dark:text-white"><?php echo HTML::chars((string) $item[0]); ?></span>
				<i class="fas <?php echo $item[3]; ?> <?php echo $item[4]; ?>"></i>
			</div>
			<div class="mt-0.5 n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><?php echo HTML::chars($item[1]); ?></div>
			<div class="n2j-ds-xs text-gray-400"><?php echo HTML::chars($item[2]); ?></div>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- insights -->
	<?php if ($insights): ?>
	<div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
		<?php
		$insight_tone = [
			'fa-scale-unbalanced' => 'text-rose-600 dark:text-rose-400',
			'fa-server' => 'text-emerald-700 dark:text-emerald-400',
			'fa-user-tie' => 'text-blue-600 dark:text-blue-400',
			'fa-database' => 'text-amber-600 dark:text-amber-400',
		];
		?>
		<?php foreach ($insights as $insight): ?>
		<a href="<?php echo HTML::chars((string) Arr::get($insight, 'href', '/process')); ?>" class="<?php echo $card; ?> flex gap-2 p-3 transition hover:border-blue-300 hover:shadow dark:hover:border-blue-700">
			<i class="fas <?php echo HTML::chars((string) Arr::get($insight, 'icon', 'fa-lightbulb')); ?> mt-0.5 <?php echo Arr::get($insight_tone, (string) Arr::get($insight, 'icon', ''), 'text-gray-400'); ?>"></i>
			<div>
				<div class="n2j-ds-xs font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $insight['title']); ?></div>
				<div class="mt-0.5 n2j-ds-xs leading-relaxed text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $insight['text']); ?></div>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="mt-3 grid gap-3 xl:grid-cols-12">

		<!-- подразделения -->
		<section class="xl:col-span-7 <?php echo $card; ?>">
			<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-sitemap mr-1.5 text-gray-400"></i>Структура по подразделениям</h2>
				<span class="n2j-ds-xs text-gray-400"><?php echo count($departments); ?> групп</span>
			</div>
			<div class="divide-y divide-gray-100 dark:divide-gray-800">
				<?php foreach ($departments as $department): ?>
				<?php $items = (array) $department['processes']; ?>
				<div class="px-4 py-3">
					<div class="flex items-center justify-between gap-2">
						<h3 class="n2j-ds-sm font-bold text-gray-800 dark:text-gray-200"><i class="fas fa-folder-tree mr-1.5 text-gray-400"></i><?php echo HTML::chars((string) $department['name']); ?></h3>
						<span class="rounded bg-gray-100 px-1.5 py-0.5 n2j-ds-xs font-bold text-gray-500 dark:bg-gray-800 dark:text-gray-400"><?php echo count($items); ?></span>
					</div>
					<div class="mt-2 space-y-1">
						<?php foreach ($items as $process): ?>
						<?php $ratio = ((int) Arr::get($process, 'plan_minutes', 0) > 0 AND (int) Arr::get($process, 'fact_minutes', 0) > 0)
							? (int) $process['fact_minutes'] / (int) $process['plan_minutes'] : 0; ?>
						<a href="/process/<?php echo HTML::chars((string) $process['code']); ?>" class="flex flex-wrap items-center gap-1.5 rounded-md px-2 py-1.5 n2j-ds-xs hover:bg-gray-50 dark:hover:bg-gray-800">
							<span class="min-w-0 flex-1 truncate font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $process['title']); ?></span>
							<?php if ( ! empty($process['owner_role_name'])): ?><span class="rounded bg-gray-100 px-1.5 py-0.5 n2j-ds-xs text-gray-700 dark:bg-gray-800 dark:text-gray-400"><i class="fas fa-user-tie mr-0.5"></i><?php echo HTML::chars((string) $process['owner_role_name']); ?></span>
							<?php else: ?><span class="rounded bg-rose-50 px-1.5 py-0.5 n2j-ds-xs text-rose-600 dark:bg-rose-950/40 dark:text-rose-300">без владельца</span><?php endif; ?>
							<?php if ((int) Arr::get($process, 'plan_minutes', 0) > 0): ?><span class="n2j-ds-xs text-gray-400">план <?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $process['plan_minutes'])); ?></span><?php endif; ?>
							<?php if ((int) Arr::get($process, 'fact_minutes', 0) > 0): ?><span class="n2j-badge <?php echo $ratio >= 2 ? 'n2j-badge-err' : ($ratio >= 1.3 ? 'n2j-badge-run' : 'n2j-badge-ok'); ?>">факт <?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $process['fact_minutes'])); ?><?php echo $ratio > 0 ? ' ×'.number_format($ratio, 1, ',', '') : ''; ?></span><?php endif; ?>
							<?php if ((int) Arr::get($process, 'audits_count', 0) > 0): ?><i class="fas fa-circle-check text-[10px] text-emerald-500" title="Аудит выполнен"></i><?php endif; ?>
						</a>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</section>

		<div class="space-y-3 xl:col-span-5">
			<!-- системы -->
			<section class="<?php echo $card; ?>">
				<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
					<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-server mr-1.5 text-gray-400"></i>Системы в процессах</h2>
					<span class="n2j-ds-xs text-gray-400"><?php echo count($systems); ?></span>
				</div>
				<?php if (empty($systems)): ?>
				<p class="px-4 py-3 n2j-ds-sm text-gray-500 dark:text-gray-400">Ни один шаг не привязан к системе.</p>
				<?php else: ?>
				<div class="space-y-2 px-4 py-3">
					<?php foreach ($systems as $system): ?>
					<div>
						<div class="flex items-center justify-between gap-2 n2j-ds-xs">
							<span class="truncate font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $system['name']); ?></span>
							<span class="shrink-0 text-gray-400"><?php echo (int) $system['processes']; ?> проц. · <?php echo (int) $system['steps']; ?> шагов</span>
						</div>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</section>

			<!-- роли -->
			<section class="<?php echo $card; ?>">
				<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
					<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-user-tie mr-1.5 text-gray-400"></i>Роли</h2>
					<span class="n2j-ds-xs text-gray-400"><?php echo count($roles); ?></span>
				</div>
				<?php if (empty($roles)): ?>
				<p class="px-4 py-3 n2j-ds-sm text-gray-500 dark:text-gray-400">Шаги ещё не привязаны к ролям.</p>
				<?php else: ?>
				<div class="max-h-64 overflow-y-auto">
					<table class="n2j-table min-w-full n2j-ds-xs">
						<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
							<?php foreach ($roles as $role): ?>
							<tr>
								<td class="px-4 py-1.5 font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $role['name']); ?></td>
								<td class="px-1 py-1.5 text-right text-gray-400"><?php echo (int) $role['processes']; ?> проц.</td>
								<td class="px-1 py-1.5 text-right text-gray-400"><?php echo (int) $role['steps']; ?> шагов</td>
								<td class="px-4 py-1.5 text-right"><?php if ((int) $role['owned'] > 0): ?><span class="rounded bg-gray-100 px-1.5 py-0.5 n2j-ds-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-400">владелец <?php echo (int) $role['owned']; ?></span><?php endif; ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

			<!-- артефакты -->
			<section class="<?php echo $card; ?>">
				<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
					<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-file-lines mr-1.5 text-gray-400"></i>Артефакты</h2>
					<span class="n2j-ds-xs text-gray-400"><?php echo count($artifacts); ?></span>
				</div>
				<?php if (empty($artifacts)): ?>
				<p class="px-4 py-3 n2j-ds-sm text-gray-500 dark:text-gray-400">Входы и выходы шагов пока пусты.</p>
				<?php else: ?>
				<div class="flex flex-wrap gap-1.5 px-4 py-3">
					<?php foreach (array_slice($artifacts, 0, 14) as $artifact): ?>
					<span class="rounded-md bg-amber-50 px-1.5 py-0.5 n2j-ds-xs font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-300" title="<?php echo (int) $artifact['steps']; ?> шагов в <?php echo (int) $artifact['processes']; ?> процессах"><?php echo HTML::chars((string) $artifact['name']); ?></span>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</section>
		</div>
	</div>

	<div class="mt-3 grid gap-3 lg:grid-cols-2">

		<!-- иерархия -->
		<section class="<?php echo $card; ?>">
			<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-sitemap mr-1.5 text-gray-400"></i>Иерархия процессов</h2>
				<span class="n2j-ds-xs text-gray-400"><?php echo count($roots); ?> верхних</span>
			</div>
			<?php if ( ! $roots): ?>
			<p class="px-4 py-3 n2j-ds-sm text-gray-500 dark:text-gray-400">Процессы пока не связаны в дерево. Родительский процесс выбирается при создании или импорте.</p>
			<?php else: ?>
			<div class="px-4 py-2">
				<?php echo $render_branch(0); ?>
			</div>
			<?php endif; ?>
		</section>

		<!-- связи-вызовы -->
		<section class="<?php echo $card; ?>">
			<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
				<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-link mr-1.5 text-gray-400"></i>Вызовы из схем</h2>
				<span class="n2j-ds-xs text-gray-400"><?php echo count($calls); ?> связей</span>
			</div>
			<?php if ( ! $calls): ?>
			<p class="px-4 py-3 n2j-ds-sm text-gray-500 dark:text-gray-400">Нет подпроцессов-ссылок. Добавьте шаг «Подпроцесс» на холсте и выберите процесс — связь появится здесь.</p>
			<?php else: ?>
			<ul class="divide-y divide-gray-100 dark:divide-gray-800">
				<?php foreach ($calls as $call): ?>
				<li class="flex flex-wrap items-center gap-1.5 px-4 py-2 n2j-ds-xs">
					<a href="/process/<?php echo HTML::chars((string) $call['from_code']); ?>" class="font-semibold text-gray-800 hover:text-blue-600 dark:text-gray-200"><?php echo HTML::chars((string) $call['from_title']); ?></a>
					<i class="fas fa-arrow-right text-[9px] text-gray-400"></i>
					<a href="/process/<?php echo HTML::chars((string) $call['to_code']); ?>" class="rounded bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars((string) $call['to_code']); ?></a>
					<?php if ( ! empty($call['step'])): ?><span class="n2j-ds-xs text-gray-400">из шага «<?php echo HTML::chars((string) $call['step']); ?>»</span><?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</section>
	</div>

	<!-- матрица -->
	<section class="mt-3 overflow-hidden <?php echo $card; ?>">
		<div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
			<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-table-cells mr-1.5 text-gray-400"></i>Матрица «процесс × система»</h2>
			<span class="n2j-ds-xs text-gray-400">число шагов в системе · клик по названию — холст</span>
		</div>
		<div class="overflow-x-auto">
			<table class="n2j-table min-w-full n2j-ds-xs">
				<thead class="bg-gray-50 n2j-ds-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
					<tr>
						<th class="sticky left-0 z-10 bg-gray-50 px-4 py-2 dark:bg-gray-800">Процесс</th>
						<th class="px-2 py-2 text-center">План</th>
						<th class="px-2 py-2 text-center">Факт</th>
						<?php foreach ($systems as $system): ?>
						<th class="px-2 py-2 text-center" title="<?php echo HTML::chars((string) $system['name']); ?>"><span class="inline-block max-w-[84px] truncate align-middle"><?php echo HTML::chars((string) $system['name']); ?></span></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
					<?php foreach ($processes as $process): ?>
					<?php $row = (array) Arr::get($matrix, (string) (int) $process['id'], []); ?>
					<tr class="hover:bg-gray-50/70 dark:hover:bg-gray-800/40">
						<td class="sticky left-0 z-10 max-w-[260px] truncate bg-white px-4 py-1.5 font-semibold text-gray-800 dark:bg-gray-900 dark:text-gray-200"><a href="/process/<?php echo HTML::chars((string) $process['code']); ?>" class="hover:text-blue-600 hover:underline"><?php echo HTML::chars((string) $process['title']); ?></a></td>
						<td class="px-2 py-1.5 text-center text-gray-500"><?php echo (int) Arr::get($process, 'plan_minutes', 0) > 0 ? HTML::chars(Service_Nj670_Repo::minutes_text((int) $process['plan_minutes'])) : '—'; ?></td>
						<td class="px-2 py-1.5 text-center text-gray-500"><?php echo (int) Arr::get($process, 'fact_minutes', 0) > 0 ? HTML::chars(Service_Nj670_Repo::minutes_text((int) $process['fact_minutes'])) : '—'; ?></td>
						<?php foreach ($systems as $system): ?>
						<?php $hits = (int) Arr::get($row, (string) $system['code'], 0); ?>
						<td class="px-2 py-1.5 text-center"><?php if ($hits > 0): ?><span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-gray-200 px-1 n2j-ds-xs font-bold text-gray-800 dark:bg-gray-800 dark:text-gray-300"><?php echo $hits; ?></span><?php else: ?><span class="text-gray-200 dark:text-gray-700">·</span><?php endif; ?></td>
						<?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<!-- пробелы -->
	<section class="mt-3 <?php echo $card; ?>">
		<div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
			<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><i class="fas fa-triangle-exclamation mr-1.5 text-gray-400"></i>Пробелы и расхождения</h2>
			<span class="n2j-ds-xs text-gray-400">что не видно в отдельной схеме</span>
		</div>
		<div class="grid gap-0 md:grid-cols-2">
			<?php foreach ($gaps as $gap): ?>
			<?php $items = (array) Arr::get($gap, 'items', []); if (empty($items)) { continue; } ?>
			<?php $tone = Arr::get($gap, 'type', 'muted'); ?>
			<div class="border-b border-gray-100 px-4 py-3 md:border-r dark:border-gray-800">
				<div class="flex items-center justify-between gap-2">
					<h3 class="n2j-ds-sm font-bold <?php echo $tone === 'danger' ? 'text-rose-700 dark:text-rose-300' : ($tone === 'warn' ? 'text-amber-700 dark:text-amber-300' : 'text-gray-700 dark:text-gray-300'); ?>"><?php echo HTML::chars((string) $gap['title']); ?></h3>
					<span class="rounded bg-gray-100 px-1.5 py-0.5 n2j-ds-xs font-bold text-gray-500 dark:bg-gray-800 dark:text-gray-400"><?php echo count($items); ?></span>
				</div>
				<p class="mt-0.5 n2j-ds-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $gap['text']); ?></p>
				<div class="mt-1.5 flex flex-wrap gap-1">
					<?php foreach (array_slice($items, 0, 10) as $item): ?>
					<a href="/process/<?php echo HTML::chars((string) $item['code']); ?>" class="rounded bg-gray-100 px-1.5 py-0.5 n2j-ds-xs text-gray-600 hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars((string) $item['name']); ?></a>
					<?php endforeach; ?>
					<?php if (count($items) > 10): ?><span class="n2j-ds-xs text-gray-400">+<?php echo count($items) - 10; ?></span><?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</section>

	<p class="mt-3 n2j-ds-xs text-gray-500 dark:text-gray-400">
		Покрытие владельцами — <?php echo $coverage; ?>%, план есть у <?php echo $with_plan; ?> из <?php echo $total; ?>, факт — у <?php echo $with_fact; ?>.
		<?php if ($with_fact < $total): ?>Загрузите журнал событий на странице процесса, чтобы увидеть реальную картину.<?php endif; ?>
	</p>

	<?php endif; ?>
</div>
