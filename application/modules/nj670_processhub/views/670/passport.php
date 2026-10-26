<?php defined('SYSPATH') or die('No direct script access.');
$process = (array) $process;
$plan = $plan ? (array) $plan : NULL;
$fact = $fact ? (array) $fact : NULL;
$plan_metrics = (array) $plan_metrics;
$fact_metrics = (array) $fact_metrics;
$coverage_plan = (array) $coverage_plan;
$coverage_fact = (array) $coverage_fact;
$architecture = (array) $architecture;
$raci = (array) $raci;
$versions = (array) $versions;
$audits = (array) $audits;
$reports = (array) $reports;
$calls = (array) $calls;
$children = (array) $children;
$comments = (array) $comments;
$findings = (array) $findings;
$statuses = (array) $statuses;
$node_types = (array) $node_types;
$overlay_labels = Service_Nj670_Report::overlay_labels();
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900';
$code = (string) $process['code'];
$plan_min = (int) Arr::get($plan_metrics, 'elapsed_minutes', 0);
$fact_min = (int) Arr::get($fact_metrics, 'elapsed_minutes', 0);
$delta_min = ($fact_min > 0 AND $plan_min > 0) ? $fact_min - $plan_min : 0;
$all_comments = 0;
foreach ($comments as $comment) { if ($comment['comment'] !== '') { $all_comments++; } }
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<nav class="mb-3 flex flex-wrap items-center gap-1.5 n2j-ds-xs text-gray-500 dark:text-gray-400">
		<a href="/process" class="hover:text-blue-600 dark:hover:text-blue-400"><i class="fas fa-layer-group mr-1"></i>Реестр</a>
		<i class="fas fa-chevron-right text-[8px] text-gray-400"></i>
		<span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $process['title']); ?></span>
	</nav>

	<?php echo View::factory('670/_job')->set('job', isset($job) ? $job : [])->set('code', isset($process) ? (string) $process['code'] : '')->render(); ?>

	<section class="n2j-panel overflow-hidden">
		<div class="px-4 py-5 sm:px-6 sm:py-6">
			<div class="flex flex-wrap items-start justify-between gap-3">
				<div class="min-w-0">
					<div class="flex flex-wrap items-center gap-2">
						<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 font-mono n2j-ds-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars($code); ?></span>
						<?php if ( ! empty($process['is_demo'])): ?>
						<span class="rounded-full bg-gray-100 px-2 py-0.5 n2j-ds-xs font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">ДЕМО</span>
						<?php endif; ?>
						<?php if ($plan): ?>
						<?php
						$status_tone = [
							'active' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
							'in_review' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
							'error' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
						];
						?>
						<span class="rounded-full px-2 py-0.5 n2j-ds-xs font-semibold <?php echo Arr::get($status_tone, (string) $plan['status'], 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'); ?>"><?php echo HTML::chars((string) Arr::get($statuses, (string) $plan['status'], (string) $plan['status'])); ?></span>
						<?php endif; ?>
						<?php if ($fact): ?>
						<span class="rounded-full bg-emerald-50 px-2 py-0.5 n2j-ds-xs font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">факт собран</span>
						<?php endif; ?>
					</div>
					<h1 class="n2j-h1 mt-2"><?php echo HTML::chars((string) $process['title']); ?></h1>
					<p class="mt-1.5 max-w-3xl n2j-ds-body leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars(trim((string) Arr::get($process, 'description', '')) !== '' ? (string) $process['description'] : 'Описание пока не заполнено.'); ?></p>
					<div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 n2j-ds-xs text-gray-600 dark:text-gray-300">
						<span><i class="fas fa-user-tie mr-1 text-gray-400"></i><?php echo HTML::chars((string) Arr::get($process, 'owner_role_name', 'владелец не назначен')); ?></span>
						<span><i class="fas fa-building mr-1 text-gray-400"></i><?php echo HTML::chars(trim((string) Arr::get($process, 'department', '')) !== '' ? (string) $process['department'] : 'подразделение не указано'); ?></span>
						<?php if ($parent): ?>
						<span><i class="fas fa-turn-up mr-1 text-gray-400"></i>входит в <a class="font-semibold text-gray-700 hover:underline dark:text-gray-300" href="/process/<?php echo HTML::chars((string) $parent['code']); ?>/passport"><?php echo HTML::chars((string) $parent['title']); ?></a></span>
						<?php endif; ?>
						<span><i class="fas fa-diagram-project mr-1 text-gray-400"></i>подпроцессов: <?php echo count($children); ?></span>
					</div>
				</div>
				<div class="flex flex-wrap gap-2">
					<a href="/process/<?php echo HTML::chars($code); ?>" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-diagram-project mr-2"></i>Схема</a>
					<?php if ($fact): ?>
					<a href="/process/<?php echo HTML::chars($code); ?>/audit" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-shield-halved mr-2 text-gray-400"></i>Аудит</a>
					<?php endif; ?>
					<a href="/process/<?php echo HTML::chars($code); ?>/report" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-file-word mr-2 text-gray-400"></i>Регламент</a>
					<a href="/process/<?php echo HTML::chars($code); ?>/versions" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-code-compare mr-2 text-gray-400"></i>Версии</a>
					<a href="/process/<?php echo HTML::chars($code); ?>/simulate" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-flask mr-2 text-gray-400"></i>Что если</a>
				</div>
			</div>
		</div>
	</section>

	<div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
		<?php
		$tiles = [
			['План: срок', $plan_min > 0 ? Service_Nj670_Repo::minutes_text($plan_min) : '—', 'clock', ''],
			['Факт: срок', $fact_min > 0 ? Service_Nj670_Repo::minutes_text($fact_min) : 'не собран', 'stopwatch', $delta_min > 0 ? '+'.Service_Nj670_Repo::minutes_text($delta_min) : ''],
			['Потери', Service_Nj670_Repo::money((float) Arr::get($process, 'audit_impact', 0)), 'scale-unbalanced', ''],
			['Покрытие ролями', ((int) Arr::get($coverage_plan, 'total', 0) > 0 ? round((float) $coverage_plan['role_share'] * 100) : 0).' %', 'users', ''],
		];
		foreach ($tiles as $tile):
		?>
		<div class="<?php echo $card; ?>">
			<div class="flex items-center gap-2 n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-<?php echo $tile[2]; ?> text-gray-400"></i><?php echo HTML::chars($tile[0]); ?></div>
			<div class="mt-1 flex items-baseline gap-2">
				<span class="n2j-ds-h2 font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $tile[1]); ?></span>
				<?php if ($tile[3] !== ''): ?><span class="n2j-ds-xs font-semibold text-rose-600 dark:text-rose-400"><?php echo HTML::chars($tile[3]); ?></span><?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
	</div>

	<div class="mt-3 grid gap-3 xl:grid-cols-3">

		<div class="space-y-3 xl:col-span-2">

			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-cubes mr-1 text-gray-400"></i>Архитектура: роли, системы, артефакты</h2>
				<div class="mt-3 grid gap-3 md:grid-cols-3">
					<?php
					$blocks = [
						['role' => 'Роли', 'icon' => 'users', 'rows' => (array) Arr::get($architecture, 'role', [])],
						['role' => 'Системы', 'icon' => 'server', 'rows' => (array) Arr::get($architecture, 'system', [])],
						['role' => 'Артефакты', 'icon' => 'file-lines', 'rows' => (array) Arr::get($architecture, 'artifact', [])],
					];
					foreach ($blocks as $block):
					?>
					<div class="rounded-lg border border-gray-100 bg-gray-50/60 p-2.5 dark:border-gray-800 dark:bg-gray-800/40">
						<div class="flex items-center gap-1.5 n2j-ds-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300"><i class="fas fa-<?php echo $block['icon']; ?> text-gray-400"></i><?php echo HTML::chars($block['role']); ?> <span class="font-normal text-gray-400"><?php echo count($block['rows']); ?></span></div>
						<?php if (empty($block['rows'])): ?>
						<p class="mt-1.5 n2j-ds-xs text-gray-400">Не заданы в схеме.</p>
						<?php else: ?>
						<ul class="mt-1.5 space-y-1">
							<?php foreach (array_slice($block['rows'], 0, 8) as $row): ?>
							<li class="flex items-start justify-between gap-2 n2j-ds-xs text-gray-700 dark:text-gray-200">
								<span class="min-w-0 truncate"><?php echo HTML::chars((string) $row['name']); ?></span>
								<span class="shrink-0 font-mono n2j-ds-xs text-gray-400">
									<?php if (in_array($block['role'], ['Роли', 'Системы'], TRUE)): ?>
									п<?php echo (int) $row['plan']; ?> / ф<?php echo (int) $row['fact']; ?>
									<?php else: ?>
									вх <?php echo (int) $row['in_plan'] + (int) $row['in_fact']; ?> · вых <?php echo (int) $row['out_plan'] + (int) $row['out_fact']; ?>
									<?php endif; ?>
								</span>
							</li>
							<?php endforeach; ?>
							<?php if (count($block['rows']) > 8): ?>
							<li class="n2j-ds-xs text-gray-400">и ещё <?php echo count($block['rows']) - 8; ?>…</li>
							<?php endif; ?>
						</ul>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="<?php echo $card; ?>">
				<div class="flex items-center justify-between gap-2">
					<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-list-ol mr-1 text-gray-400"></i>Шаги плана: <?php echo (int) Arr::get($coverage_plan, 'total', 0); ?></h2>
					<a href="/process/<?php echo HTML::chars($code); ?>" class="n2j-ds-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Открыть схему</a>
				</div>
				<?php
				$steps = [];
				foreach ((array) Arr::get($plan_graph, 'nodes', []) as $node)
				{
					$type = (string) Arr::get($node, 'type', '');
					if (in_array($type, ['start', 'end', 'gateway_xor', 'gateway_and'], TRUE))
					{
						continue;
					}
					$steps[] = $node;
				}
				?>
				<?php if (empty($steps)): ?>
				<p class="mt-2 n2j-ds-sm text-gray-500 dark:text-gray-400">В план-версии пока нет шагов. Соберите схему из текста регламента.</p>
				<?php else: ?>
				<div class="mt-2 overflow-x-auto">
					<table class="n2j-table n2j-ds-xs">
						<thead class="n2j-ds-xs uppercase tracking-wide text-gray-400">
							<tr><th class="py-1 pr-2 font-semibold">№</th><th class="py-1 pr-2 font-semibold">Действие</th><th class="py-1 pr-2 font-semibold">Роль</th><th class="py-1 pr-2 font-semibold">Система</th><th class="py-1 pr-2 text-right font-semibold">SLA</th></tr>
						</thead>
						<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
							<?php foreach ($steps as $i => $node): ?>
							<tr>
								<td class="py-1.5 pr-2 text-gray-400"><?php echo $i + 1; ?></td>
								<td class="py-1.5 pr-2 font-medium text-gray-800 dark:text-gray-100">
									<?php echo HTML::chars((string) Arr::get($node, 'title', '')); ?>
									<?php $overlay = (string) Arr::get((array) Arr::get($node, 'properties', []), 'overlay', ''); ?>
									<?php if (isset($overlay_labels[$overlay])): ?>
									<span class="n2j-badge <?php echo $overlay === 'risk' ? 'n2j-badge-err' : (($overlay === 'control') ? 'n2j-badge-ok' : (($overlay === 'question') ? 'n2j-badge-run' : 'n2j-badge-wait')); ?> ml-1"><?php echo HTML::chars($overlay_labels[$overlay]); ?></span>
									<?php endif; ?>
								</td>
								<td class="py-1.5 pr-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get((array) Arr::get($node, 'properties', []), 'role_label', Arr::get($node, 'role_code', ''))); ?></td>
								<td class="py-1.5 pr-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get((array) Arr::get($node, 'properties', []), 'system_label', Arr::get($node, 'system_code', ''))); ?></td>
								<td class="py-1.5 pr-2 text-right text-gray-500"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) Arr::get($node, 'duration_minutes', 0) + (int) Arr::get($node, 'wait_time_minutes', 0))); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

			<?php if ($comments): ?>
			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-comment-dots mr-1 text-gray-400"></i>Комментарии ревьюера: <?php echo $all_comments; ?></h2>
				<ul class="mt-2 space-y-2">
					<?php foreach ($comments as $comment): ?>
					<li class="rounded-lg border border-gray-100 bg-gray-50 p-2.5 dark:border-gray-800 dark:bg-gray-800/50">
						<div class="flex flex-wrap items-center gap-1.5 n2j-ds-xs font-semibold text-gray-800 dark:text-gray-100">
							<?php echo HTML::chars((string) $comment['section']); ?>
							<?php if ($comment['overlay'] !== ''): ?><span class="rounded-full bg-white px-1.5 py-0.5 n2j-ds-xs font-bold text-blue-700 dark:bg-gray-900 dark:text-blue-300"><?php echo HTML::chars((string) $comment['overlay']); ?></span><?php endif; ?>
						</div>
						<?php if ($comment['comment'] !== ''): ?><p class="mt-1 n2j-ds-xs leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $comment['comment']); ?></p><?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endif; ?>

			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-table-list mr-1 text-gray-400"></i>Матрица RACI <span class="font-normal normal-case text-gray-400">(по план-схеме)</span></h2>
				<?php
				$columns = (array) Arr::get($raci, 'columns', []);
				$raci_rows = (array) Arr::get($raci, 'rows', []);
				?>
				<?php if ($columns AND $raci_rows): ?>
				<div class="mt-2 overflow-x-auto">
					<table class="n2j-table n2j-ds-xs">
						<thead class="n2j-ds-xs uppercase tracking-wide text-gray-400">
							<tr>
								<th class="py-1 pr-2 font-semibold">Шаг</th>
								<?php foreach ($columns as $column): ?><th class="py-1 pr-2 text-center font-semibold"><?php echo HTML::chars((string) $column['name']); ?></th><?php endforeach; ?>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
							<?php foreach ($raci_rows as $row): ?>
							<tr>
								<td class="py-1.5 pr-2 text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $row['title']); ?></td>
								<?php foreach ($columns as $column): ?>
								<td class="py-1.5 pr-2 text-center font-mono font-semibold text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get((array) $row['cells'], (string) $column['code'], '·')); ?></td>
								<?php endforeach; ?>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php else: ?>
				<p class="mt-2 n2j-ds-sm text-gray-500 dark:text-gray-400">Роли в схеме не заданы — матрица не строится.</p>
				<?php endif; ?>
			</section>

			<?php if ($latest_audit): ?>
			<section class="<?php echo $card; ?>">
				<div class="flex flex-wrap items-center justify-between gap-2">
					<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-shield-halved mr-1 text-gray-400"></i>Последний аудит</h2>
					<span class="n2j-ds-xs text-gray-400"><?php echo HTML::chars((string) Arr::get($latest_audit, 'created_at', '')); ?></span>
				</div>
				<p class="mt-2 n2j-ds-sm leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) Arr::get($latest_audit, 'summary', '')); ?></p>
				<div class="mt-2 flex flex-wrap gap-2 n2j-ds-xs">
					<span class="rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">потери <?php echo HTML::chars(Service_Nj670_Repo::money((float) Arr::get($latest_audit, 'total_impact_rub', 0))); ?></span>
					<span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">находок <?php echo count($findings); ?></span>
				</div>
				<?php if ($findings): ?>
				<ul class="mt-2 space-y-1.5">
					<?php foreach (array_slice($findings, 0, 4) as $finding): ?>
					<li class="flex items-start gap-2 n2j-ds-xs text-gray-600 dark:text-gray-300">
						<i class="fas fa-circle-exclamation mt-0.5 text-rose-500"></i>
						<span><?php echo HTML::chars((string) Arr::get($finding, 'title', Arr::get($finding, 'problem', ''))); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<a href="/process/<?php echo HTML::chars($code); ?>/audit" class="mt-2 inline-flex items-center n2j-ds-xs font-semibold text-rose-600 hover:underline dark:text-rose-400">Полный отчёт аудита<i class="fas fa-arrow-right ml-1 text-[9px]"></i></a>
			</section>
			<?php endif; ?>
		</div>

		<aside class="space-y-3">
			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-gauge-high mr-1 text-gray-400"></i>Покрытие схемы</h2>
				<div class="mt-2 space-y-2">
					<?php
					$gauges = [
						['Роли назначены', (float) $coverage_plan['role_share'], (int) $coverage_plan['with_role'], (int) $coverage_plan['total']],
						['Системы указаны', (float) $coverage_plan['system_share'], (int) $coverage_plan['with_system'], (int) $coverage_plan['total']],
					];
					foreach ($gauges as $gauge):
					?>
					<div>
						<div class="flex items-center justify-between n2j-ds-xs text-gray-500 dark:text-gray-400"><span><?php echo HTML::chars($gauge[0]); ?></span><span class="font-semibold"><?php echo $gauge[2]; ?> / <?php echo $gauge[3]; ?> · <?php echo (int) round($gauge[1] * 100); ?> %</span></div>
					</div>
					<?php endforeach; ?>
					<div class="grid grid-cols-3 gap-1.5 pt-1 text-center">
						<div class="rounded-lg bg-rose-50 p-1.5 dark:bg-rose-950/30"><div class="n2j-ds-body font-bold text-rose-700 dark:text-rose-300"><?php echo (int) Arr::get($coverage_plan, 'shadow', 0); ?></div><div class="n2j-ds-xs text-rose-600 dark:text-rose-400">теневых</div></div>
						<div class="rounded-lg bg-amber-50 p-1.5 dark:bg-amber-950/30"><div class="n2j-ds-body font-bold text-amber-700 dark:text-amber-300"><?php echo (int) Arr::get($coverage_plan, 'overlays', 0); ?></div><div class="n2j-ds-xs text-amber-600 dark:text-amber-400">оверлеев</div></div>
						<div class="rounded-lg bg-gray-100 p-1.5 dark:bg-gray-800"><div class="n2j-ds-body font-bold text-gray-700 dark:text-gray-300"><?php echo (int) Arr::get($coverage_plan, 'comments', 0); ?></div><div class="n2j-ds-xs text-gray-600 dark:text-gray-400">коммент.</div></div>
					</div>
					<?php if ($fact): ?>
					<p class="pt-1 n2j-ds-xs text-gray-400">Факт: роли <?php echo (int) $coverage_fact['with_role']; ?> / <?php echo (int) $coverage_fact['total']; ?>, системы <?php echo (int) $coverage_fact['with_system']; ?> / <?php echo (int) $coverage_fact['total']; ?>.</p>
					<?php endif; ?>
				</div>
			</section>

			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-arrows-split-up-and-left mr-1 text-gray-400"></i>Связи процесса</h2>
				<ul class="mt-2 space-y-1.5 n2j-ds-xs">
					<?php if ($parent): ?>
					<li class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><i class="fas fa-turn-up text-gray-400"></i>Родитель: <a class="font-semibold text-gray-700 hover:underline dark:text-gray-300" href="/process/<?php echo HTML::chars((string) $parent['code']); ?>/passport"><?php echo HTML::chars((string) $parent['title']); ?></a></li>
					<?php endif; ?>
					<?php foreach ($children as $child): ?>
					<li class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><i class="fas fa-turn-down text-gray-400"></i>Дочерний: <a class="font-semibold text-gray-700 hover:underline dark:text-gray-300" href="/process/<?php echo HTML::chars((string) $child['code']); ?>/passport"><?php echo HTML::chars((string) $child['title']); ?></a></li>
					<?php endforeach; ?>
					<?php foreach ($calls as $call): ?>
					<li class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><i class="fas fa-diagram-project text-gray-400"></i>Вызывает: <a class="font-semibold text-gray-700 hover:underline dark:text-gray-400" href="/process/<?php echo HTML::chars((string) $call['to_code']); ?>/passport"><?php echo HTML::chars((string) $call['to_title']); ?></a></li>
					<?php endforeach; ?>
					<?php if ( ! $parent AND empty($children) AND empty($calls)): ?>
					<li class="text-gray-400">Связей нет — процесс живёт сам по себе.</li>
					<?php endif; ?>
				</ul>
			</section>

			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-code-branch mr-1 text-gray-400"></i>Версии</h2>
				<ul class="mt-2 space-y-1.5 n2j-ds-xs">
					<?php foreach (array_slice($versions, 0, 5) as $version_row): ?>
					<li class="flex items-center justify-between gap-2">
						<span class="min-w-0 truncate text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $version_row['version_tag']); ?> <span class="text-gray-400">· <?php echo $version_row['kind'] === 'fact' ? 'факт' : 'план'; ?></span></span>
						<span class="shrink-0 n2j-ds-xs font-semibold uppercase text-gray-400"><?php echo HTML::chars((string) Arr::get($statuses, (string) $version_row['status'], (string) $version_row['status'])); ?></span>
					</li>
					<?php endforeach; ?>
					<?php if (empty($versions)): ?><li class="text-gray-400">Версий нет.</li><?php endif; ?>
				</ul>
				<a href="/process/<?php echo HTML::chars($code); ?>/versions" class="mt-2 inline-flex items-center n2j-ds-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">Сравнить версии<i class="fas fa-arrow-right ml-1 text-[9px]"></i></a>
			</section>

			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-file-word mr-1 text-gray-400"></i>Документы</h2>
				<ul class="mt-2 space-y-1.5 n2j-ds-xs">
					<?php foreach (array_slice($reports, 0, 4) as $report): ?>
					<li class="flex items-center justify-between gap-2">
						<span class="min-w-0 truncate text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($report, 'version_tag', 'регламент')); ?> <span class="text-gray-400">· <?php echo HTML::chars(Service_Nj670_Repo::date_text((string) Arr::get($report, 'created_at', ''))); ?></span></span>
						<?php if ((string) Arr::get($report, 'status', '') === 'done'): ?>
						<span class="flex shrink-0 gap-1">
							<a class="rounded border border-gray-300 px-1 n2j-ds-xs font-bold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300" href="/process/r/<?php echo (int) $report['id']; ?>/docx">DOCX</a>
							<a class="rounded border border-gray-300 px-1 n2j-ds-xs font-bold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300" href="/process/r/<?php echo (int) $report['id']; ?>/pdf">PDF</a>
						</span>
						<?php else: ?>
						<span class="shrink-0 n2j-ds-xs uppercase text-gray-400"><?php echo HTML::chars((string) Arr::get($report, 'status', '')); ?></span>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
					<?php if (empty($reports)): ?><li class="text-gray-400">Регламент ещё не выгружали.</li><?php endif; ?>
				</ul>
			</section>
		</aside>
	</div>
</div>
