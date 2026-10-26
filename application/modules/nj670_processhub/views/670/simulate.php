<?php defined('SYSPATH') or die('No direct script access.');
$process = (array) $process;
$graph = (array) $graph;
$input = (array) $input;
$sim = (array) $sim;
$roles = (array) $roles;
$kind = (string) $kind;
$metrics = (array) $metrics;
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900';
$code = (string) $process['code'];
$by_role = [];
foreach ($roles as $role) { $by_role[(string) Arr::get($role, 'code', '')] = (string) Arr::get($role, 'name', ''); }

// Роли из схемы — для полей «сколько людей». Даже роль без работы показываем:
// владелец должен видеть, что она есть, а не искать её в другом месте.
$graph_roles = [];
foreach ((array) Arr::get($graph, 'nodes', []) as $node)
{
	$role_code = trim((string) Arr::get($node, 'role_code', ''));
	if ($role_code === '') { continue; }
	$graph_roles[$role_code] = Arr::get($by_role, $role_code, $role_code);
}
ksort($graph_roles);

$rows = (array) Arr::get($sim, 'roles', []);
$bottleneck = Arr::get($sim, 'bottleneck');
$feasible = (bool) Arr::get($sim, 'feasible', TRUE);
$overload = [];
foreach ($rows as $row) {
	if ((int) $row['required'] > (int) $row['people']) {
		$overload[] = $row;
	}
}
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<nav class="mb-3 flex flex-wrap items-center gap-1.5 n2j-ds-xs text-gray-500 dark:text-gray-400">
		<a href="/process" class="hover:text-blue-600 dark:hover:text-blue-400"><i class="fas fa-layer-group mr-1"></i>Реестр</a>
		<i class="fas fa-chevron-right text-[8px] text-gray-400"></i>
		<a href="/process/<?php echo HTML::chars($code); ?>/passport" class="hover:text-blue-600 dark:hover:text-blue-400"><?php echo HTML::chars((string) $process['title']); ?></a>
		<i class="fas fa-chevron-right text-[8px] text-gray-400"></i>
		<span class="font-semibold text-gray-700 dark:text-gray-200">Что если</span>
	</nav>

	<section class="n2j-card sm:p-6">
		<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 n2j-ds-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300"><i class="fas fa-flask mr-1"></i>Что если · <?php echo $kind === 'fact' ? 'факт' : 'план'; ?></span>
		<h1 class="n2j-h1 mt-2">Сколько людей нужно процессу</h1>
		<p class="mt-2 max-w-3xl n2j-ds-body leading-relaxed text-gray-600 dark:text-gray-300">Задайте поток заявок и численность ролей — увидите загрузку, очередь и срок с учётом ожидания. Это оценка ёмкости, а не покадровая симуляция: работы ролей складываются, параллельные ветки не разводятся.</p>
		<div class="mt-3 flex flex-wrap gap-2 n2j-ds-xs">
			<a href="/process/<?php echo HTML::chars($code); ?>/simulate?kind=plan" class="n2j-chip<?php echo $kind === 'plan' ? ' is-on' : ''; ?>">План</a>
			<a href="/process/<?php echo HTML::chars($code); ?>/simulate?kind=fact" class="n2j-chip<?php echo $kind === 'fact' ? ' is-on' : ''; ?>">Факт</a>
			<a href="/process/<?php echo HTML::chars($code); ?>" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-diagram-project mr-1"></i>Схема</a>
			<a href="/process/<?php echo HTML::chars($code); ?>/passport" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-id-card mr-1"></i>Паспорт</a>
		</div>
	</section>

	<div class="mt-3 grid gap-3 xl:grid-cols-4">
		<form method="get" action="/process/<?php echo HTML::chars($code); ?>/simulate" class="<?php echo $card; ?> xl:col-span-1">
			<input type="hidden" name="kind" value="<?php echo HTML::chars($kind); ?>">
			<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-sliders mr-1 text-gray-400"></i>Параметры</h2>
			<div class="mt-2 space-y-2">
				<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Заявок в месяц
					<input type="number" name="volume" min="1" max="1000000" value="<?php echo (int) Arr::get($input, 'volume', 200); ?>" class="n2j-input mt-0.5 w-full py-1.5 n2j-ds-sm min-h-0">
				</label>
				<div class="grid grid-cols-2 gap-2">
					<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Раб. дней
						<input type="number" name="workdays" min="1" max="31" value="<?php echo (int) Arr::get($input, 'workdays', 21); ?>" class="n2j-input mt-0.5 w-full py-1.5 n2j-ds-sm min-h-0">
					</label>
					<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Мин/день
						<input type="number" name="workday_minutes" min="60" max="1440" step="30" value="<?php echo (int) Arr::get($input, 'workday_minutes', 480); ?>" class="n2j-input mt-0.5 w-full py-1.5 n2j-ds-sm min-h-0">
					</label>
				</div>
				<div class="pt-1">
					<div class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-400">Людей в роли</div>
					<?php if (empty($graph_roles)): ?>
					<p class="mt-1 n2j-ds-xs text-gray-400">В схеме нет ролей.</p>
					<?php else: ?>
					<div class="mt-1 space-y-1.5">
						<?php foreach ($graph_roles as $role_code => $role_name): ?>
						<label class="flex items-center justify-between gap-2 n2j-ds-xs text-gray-600 dark:text-gray-300">
							<span class="min-w-0 truncate"><?php echo HTML::chars((string) $role_name); ?></span>
							<input type="number" name="people[<?php echo HTML::chars((string) $role_code); ?>]" min="1" max="1000" value="<?php echo (int) Arr::get((array) Arr::get($input, 'people', []), (string) $role_code, 1); ?>" class="n2j-input w-16 shrink-0 py-1 text-center n2j-ds-sm min-h-0">
						</label>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
				<button type="submit" class="n2j-btn-process mt-2 w-full"><i class="fas fa-play mr-1.5"></i>Пересчитать</button>
			</div>
		</form>

		<div class="space-y-3 xl:col-span-3">
			<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
				<?php
				$tiles = [
					['Срок без очереди', Service_Nj670_Repo::minutes_text((int) Arr::get($sim, 'base_cycle', 0)), 'clock', 'indigo'],
					['Очереди в сумме', Service_Nj670_Repo::minutes_text((int) Arr::get($sim, 'queue_minutes', 0)), 'hourglass-half', 'amber'],
					['Срок с очередью', Service_Nj670_Repo::minutes_text((int) Arr::get($sim, 'cycle_minutes', 0)), 'stopwatch', $feasible ? 'sky' : 'rose'],
					['Пропускная способность', (Arr::get($sim, 'throughput_max') !== NULL ? (int) $sim['throughput_max'].' / мес' : '—'), 'gauge-high', 'emerald'],
				];
				foreach ($tiles as $tile):
				?>
				<div class="<?php echo $card; ?>">
					<div class="flex items-center gap-1.5 n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-<?php echo $tile[2]; ?> text-gray-400"></i><?php echo HTML::chars($tile[0]); ?></div>
					<div class="mt-1 n2j-ds-h3 font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $tile[1]); ?></div>
				</div>
				<?php endforeach; ?>
			</div>

			<section class="<?php echo $card; ?>">
				<div class="flex flex-wrap items-center justify-between gap-2">
					<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-users-gear mr-1 text-gray-400"></i>Загрузка ролей</h2>
					<?php if ($bottleneck): ?>
					<span class="rounded-full bg-rose-50 px-2 py-0.5 n2j-ds-xs font-semibold text-rose-700 dark:bg-rose-950/40 dark:text-rose-300"><i class="fas fa-triangle-exclamation mr-1"></i>Узкое место: <?php echo HTML::chars((string) $bottleneck['name']); ?> · <?php echo round((float) $bottleneck['utilization'] * 100); ?> %</span>
					<?php endif; ?>
				</div>

				<?php if (empty($rows)): ?>
				<p class="mt-2 n2j-ds-sm text-gray-500 dark:text-gray-400">Нет ролей — нечего считать. Задайте роли на холсте.</p>
				<?php else: ?>
				<div class="mt-2 overflow-x-auto">
					<table class="n2j-table n2j-ds-xs">
						<thead class="n2j-ds-xs uppercase tracking-wide text-gray-400">
							<tr>
								<th class="py-1 pr-2 font-semibold">Роль</th>
								<th class="py-1 pr-2 text-right font-semibold">Шагов</th>
								<th class="py-1 pr-2 text-right font-semibold">Работа/кейс</th>
								<th class="py-1 pr-2 text-right font-semibold">Люди</th>
								<th class="py-1 pr-2 font-semibold">Загрузка</th>
								<th class="py-1 pr-2 text-right font-semibold">Очередь</th>
								<th class="py-1 pr-2 text-right font-semibold">Нужно людей</th>
								<th class="py-1 pr-2 text-right font-semibold">Макс/мес</th>
							</tr>
						</thead>
						<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
							<?php foreach ($rows as $row): ?>
							<?php
							$util = (float) $row['utilization'];
							// загрузка числом и цветом (полос нет — AGENTS.md п. 10)
							$text = 'text-emerald-700 dark:text-emerald-300';
							if ($util >= 1) { $text = 'text-rose-700 dark:text-rose-300'; }
							elseif ($util >= 0.8) { $text = 'text-amber-700 dark:text-amber-300'; }
							?>
							<tr>
								<td class="py-1.5 pr-2 font-medium text-gray-800 dark:text-gray-100"><?php echo HTML::chars((string) $row['name']); ?></td>
								<td class="py-1.5 pr-2 text-right text-gray-500"><?php echo (int) $row['steps']; ?></td>
								<td class="py-1.5 pr-2 text-right text-gray-600 dark:text-gray-300"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $row['work'])); ?></td>
								<td class="py-1.5 pr-2 text-right text-gray-600 dark:text-gray-300"><?php echo (int) $row['people']; ?></td>
								<td class="py-1.5 pr-2">
									<div class="flex items-center gap-1.5">
										<span class="font-semibold <?php echo $text; ?>"><?php echo round($util * 100); ?> %</span>
									</div>
								</td>
								<td class="py-1.5 pr-2 text-right <?php echo $row['queue'] === NULL ? 'text-rose-600 dark:text-rose-400' : 'text-gray-600 dark:text-gray-300'; ?>">
									<?php echo $row['queue'] === NULL ? 'растёт' : HTML::chars(Service_Nj670_Repo::minutes_text((int) $row['queue'])); ?>
								</td>
								<td class="py-1.5 pr-2 text-right font-semibold <?php echo (int) $row['required'] > (int) $row['people'] ? 'text-rose-600 dark:text-rose-400' : 'text-gray-600 dark:text-gray-300'; ?>"><?php echo (int) $row['required']; ?></td>
								<td class="py-1.5 pr-2 text-right text-gray-600 dark:text-gray-300"><?php echo (int) $row['throughput']; ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="mt-2 n2j-ds-xs text-gray-400">«Нужно людей» — для загрузки <?php echo (int) round((float) Arr::get($sim, 'target_utilization', 0.8) * 100); ?> % (запас на отпуска и пики). «Макс/мес» — сколько кейсов роль успевает обработать текущим составом.</p>
				<?php endif; ?>
			</section>

			<?php if ($overload OR ! $feasible): ?>
			<section class="<?php echo $card; ?> border-rose-200 dark:border-rose-900/60">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-rose-600 dark:text-rose-300"><i class="fas fa-user-plus mr-1"></i>Что делать</h2>
				<ul class="mt-2 space-y-1 n2j-ds-xs text-gray-700 dark:text-gray-200">
					<?php foreach ($overload as $row): ?>
					<li class="flex items-start gap-2"><i class="fas fa-arrow-right mt-0.5 text-[9px] text-rose-500"></i><span>Добавить <span class="font-bold"><?php echo max(0, (int) $row['required'] - (int) $row['people']); ?></span> чел. в роль «<?php echo HTML::chars((string) $row['name']); ?>» — иначе очередь не разойдётся.</span></li>
					<?php endforeach; ?>
					<li class="flex items-start gap-2"><i class="fas fa-arrow-right mt-0.5 text-[9px] text-gray-400"></i><span>Либо убрать ожидание/передать шаги в систему: очередь считается по сумме работ роли.</span></li>
				</ul>
			</section>
			<?php endif; ?>

			<section class="<?php echo $card; ?>">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400"><i class="fas fa-circle-info mr-1 text-gray-400"></i>Условия расчёта</h2>
				<ul class="mt-2 space-y-1 n2j-ds-xs text-gray-600 dark:text-gray-300">
					<?php foreach ((array) Arr::get($sim, 'warnings', []) as $warning): ?>
					<li class="flex items-start gap-1.5"><i class="fas fa-minus mt-0.5 text-[8px] text-gray-400"></i><span><?php echo HTML::chars((string) $warning); ?></span></li>
					<?php endforeach; ?>
					<li class="flex items-start gap-1.5"><i class="fas fa-minus mt-0.5 text-[8px] text-gray-400"></i><span>ФОТ ролей при текущем составе: <?php echo HTML::chars(Service_Nj670_Repo::money((float) Arr::get($sim, 'monthly_cost', 0))); ?> в месяц (по ставкам онтологии).</span></li>
				</ul>
			</section>
		</div>
	</div>
</div>
