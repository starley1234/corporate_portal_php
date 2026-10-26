<?php defined('SYSPATH') or die('No direct script access.');
$overview = isset($overview) ? (array) $overview : [];
$workspaces = (array) Arr::get($overview, 'workspaces', []);
$processes = (array) Arr::get($overview, 'processes', []);
$jobs = (array) Arr::get($overview, 'jobs', []);
$errors = (array) Arr::get($overview, 'errors', []);
$config = isset($config) ? (array) $config : [];
$worker = isset($worker) ? (array) $worker : [];
$worker_online = ! empty($worker['online']);
$card = 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900';
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-center justify-between gap-2">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-gauge-high mr-2 text-blue-500"></i>ProcessHub — админ-панель</h1>
			<p class="mt-1 n2j-ds-body text-gray-600 dark:text-gray-300">Рабочие пространства, процессы и состояние каналов. Данные только для чтения.</p>
		</div>
		<a href="/process" class="n2j-ds-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">← К процессам</a>
	</div>

	<section class="mt-4 rounded-xl border <?php echo $worker_online ? 'border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900' : 'border-amber-200 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/30'; ?> p-4">
		<div class="flex flex-wrap items-center justify-between gap-2">
			<h2 class="n2j-ds-body font-bold <?php echo $worker_online ? 'text-gray-900 dark:text-white' : 'text-amber-900 dark:text-amber-200'; ?>"><i class="fas fa-circle mr-1 text-[7px] <?php echo $worker_online ? 'text-emerald-500' : 'text-amber-500'; ?>"></i>Воркер: <span class="<?php echo $worker_online ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-300'; ?>"><?php echo $worker_online ? 'на связи' : 'не отвечает'; ?></span></h2>
			<span class="n2j-ds-xs font-medium text-gray-600 dark:text-gray-300">признак: <?php echo HTML::chars((string) Arr::get($worker, 'source', 'none')); ?> · окно <?php echo (int) Arr::get($worker, 'presence_minutes', 5); ?> мин</span>
		</div>
		<p class="mt-1 n2j-ds-sm leading-relaxed <?php echo $worker_online ? 'text-gray-600 dark:text-gray-300' : 'text-amber-900/90 dark:text-amber-100/90'; ?>"><?php echo HTML::chars((string) Arr::get($worker, 'hint', 'Пульс ещё не приходил.')); ?></p>
		<div class="mt-2 grid gap-2 n2j-ds-xs sm:grid-cols-2 lg:grid-cols-4">
			<?php foreach ([
				['Пульс', ! empty($worker['last_ping_at']) ? Service_Nj670_Repo::date_text($worker['last_ping_at']) : 'не было'],
				['Воркер', (string) Arr::get($worker, 'worker', '—') !== '' ? (string) $worker['worker'] : '—'],
				['Версия', (string) Arr::get($worker, 'version', '') !== '' ? (string) $worker['version'] : '—'],
				['Задач в очереди', (string) (int) Arr::get($worker, 'queued', 0)],
			] as $row): ?>
			<div class="rounded-lg bg-gray-50 px-2.5 py-1.5 dark:bg-gray-800/60"><span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row[0]); ?>:</span> <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars($row[1]); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php if ( ! empty($worker['channels'])): ?>
		<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Каналы пульса: <span class="font-mono"><?php echo HTML::chars(implode(', ', (array) $worker['channels'])); ?></span></p>
		<?php endif; ?>
		<p class="mt-1 n2j-ds-xs leading-relaxed text-gray-500 dark:text-gray-400">Если пульса нет: проверьте на сайте <span class="font-mono">bin/console processhub:doctor</span>, а в <span class="font-mono">workers/.env</span> — <span class="font-mono">NJ670_API_BASE</span> (адрес <span class="font-mono">/process/w</span>) и <span class="font-mono">NJ670_SITE_TOKEN</span> = <span class="font-mono">WORKER_API_TOKEN</span> сайта.</p>
	</section>

	<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<?php foreach ([
			['Пространств', count($workspaces), 'users'],
			['Процессов', count($processes), 'diagram-project'],
			['Задач за 7 дней', array_sum(array_map(function ($j) { return (int) $j['n']; }, $jobs)), 'list-check'],
			['Ошибок за 14 дней', count($errors), 'triangle-exclamation'],
		] as $tile): ?>
		<div class="<?php echo $card; ?> p-3">
			<div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-400"><i class="fas fa-<?php echo $tile[2]; ?> mr-1"></i><?php echo HTML::chars($tile[0]); ?></div>
			<div class="mt-0.5 n2j-ds-h2 font-bold text-gray-900 dark:text-white"><?php echo (int) $tile[1]; ?></div>
		</div>
		<?php endforeach; ?>
	</div>

	<div class="mt-4 grid gap-4 lg:grid-cols-2">
		<section class="<?php echo $card; ?>">
			<h2 class="border-b border-gray-100 px-4 py-3 n2j-ds-body font-bold text-gray-900 dark:border-gray-800 dark:text-white">Рабочие пространства</h2>
			<div class="overflow-x-auto">
				<table class="n2j-table min-w-full">
					<thead class="bg-gray-50 n2j-ds-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400"><tr><th class="px-4 py-2">Компания</th><th class="px-4 py-2">Польз.</th><th class="px-4 py-2">Процессы</th><th class="px-4 py-2">Версии</th><th class="px-4 py-2">Аудиты</th></tr></thead>
					<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
						<?php foreach ($workspaces as $workspace): ?>
						<tr>
							<td class="px-4 py-2"><div class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $workspace['title']); ?></div><div class="n2j-ds-xs text-gray-400"><?php echo HTML::chars((string) $workspace['company_name']); ?> · user #<?php echo (int) $workspace['user_id']; ?></div></td>
							<td class="px-4 py-2 text-gray-500"><?php echo (int) $workspace['user_id']; ?></td>
							<td class="px-4 py-2 text-gray-700 dark:text-gray-300"><?php echo (int) $workspace['processes_count']; ?></td>
							<td class="px-4 py-2 text-gray-700 dark:text-gray-300"><?php echo (int) $workspace['versions_count']; ?></td>
							<td class="px-4 py-2 text-gray-700 dark:text-gray-300"><?php echo (int) $workspace['audits_count']; ?></td>
						</tr>
						<?php endforeach; ?>
						<?php if (empty($workspaces)): ?><tr><td colspan="5" class="px-4 py-4 text-gray-500">Пока пусто.</td></tr><?php endif; ?>
					</tbody>
				</table>
			</div>
		</section>

		<section class="<?php echo $card; ?>">
			<h2 class="border-b border-gray-100 px-4 py-3 n2j-ds-body font-bold text-gray-900 dark:border-gray-800 dark:text-white">Каналы за 7 дней</h2>
			<div class="flex flex-wrap gap-2 p-4">
				<?php foreach ($jobs as $job): ?>
				<span class="rounded-lg border border-gray-200 px-2.5 py-1.5 n2j-ds-xs dark:border-gray-700"><span class="font-mono font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $job['channel']); ?></span> <span class="ml-1 rounded-full bg-gray-100 px-1.5 py-0.5 n2j-ds-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars((string) $job['status']); ?> · <?php echo (int) $job['n']; ?></span></span>
				<?php endforeach; ?>
				<?php if (empty($jobs)): ?><span class="n2j-ds-sm text-gray-500">Задач нет.</span><?php endif; ?>
			</div>
			<div class="border-t border-gray-100 px-4 py-3 n2j-ds-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
				Очередь: <span class="font-mono">nj120</span> · каналы <span class="font-mono">nj670.synthesize</span>, <span class="font-mono">nj670.mine</span>, <span class="font-mono">nj670.diff</span>, <span class="font-mono">nj670.export</span>. Модель: <?php echo HTML::chars((string) Arr::get($config, 'model', 'по умолчанию')); ?>. Правиловый фолбэк: <?php echo ! empty($config['allow_rule_fallback']) ? 'включён' : 'выключен'; ?>.
			</div>
		</section>
	</div>

	<section class="<?php echo $card; ?> mt-4">
		<h2 class="border-b border-gray-100 px-4 py-3 n2j-ds-body font-bold text-gray-900 dark:border-gray-800 dark:text-white">Последние процессы</h2>
		<div class="overflow-x-auto">
			<table class="n2j-table min-w-full">
				<thead class="bg-gray-50 n2j-ds-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400"><tr><th class="px-4 py-2">Процесс</th><th class="px-4 py-2">Пространство</th><th class="px-4 py-2">Владелец</th><th class="px-4 py-2">План</th><th class="px-4 py-2">Факт</th><th class="px-4 py-2">Аудиты</th><th class="px-4 py-2">Отчёты</th><th class="px-4 py-2">Обновлён</th></tr></thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
					<?php foreach ($processes as $process): ?>
					<tr>
						<td class="px-4 py-2"><a href="/process/<?php echo HTML::chars($process['code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $process['title']); ?></a><div class="font-mono n2j-ds-xs text-gray-400"><?php echo HTML::chars((string) $process['code']); ?></div></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) $process['workspace_title']); ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($process, 'owner_role_name', '—')); ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo (int) $process['plan_versions']; ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo (int) $process['fact_versions']; ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo (int) $process['audits_count']; ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo (int) $process['reports_count']; ?></td>
						<td class="px-4 py-2 text-gray-500 dark:text-gray-400"><?php echo HTML::chars(Service_Nj670_Repo::date_text($process['updated_at'])); ?></td>
					</tr>
					<?php endforeach; ?>
				</table>
		</div>
	</section>

	<section class="<?php echo $card; ?> mt-4">
		<h2 class="border-b border-gray-100 px-4 py-3 n2j-ds-body font-bold text-gray-900 dark:border-gray-800 dark:text-white">Ошибки</h2>
		<?php if (empty($errors)): ?>
		<p class="px-4 py-4 n2j-ds-sm text-gray-500 dark:text-gray-400">Ошибок за две недели нет.</p>
		<?php else: ?>
		<ul class="divide-y divide-gray-100 n2j-ds-sm dark:divide-gray-800">
			<?php foreach ($errors as $error): ?>
			<li class="px-4 py-2"><div class="font-semibold text-rose-700 dark:text-rose-300"><?php echo HTML::chars((string) $error['kind']); ?> · процесс #<?php echo (int) $error['process_id']; ?> · <?php echo HTML::chars(Service_Nj670_Repo::date_text($error['created_at'])); ?></div><div class="mt-0.5 text-gray-600 dark:text-gray-300"><?php echo HTML::chars(UTF8::substr((string) $error['message'], 0, 400)); ?></div></li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</section>
</div>
