<?php defined('SYSPATH') or die('No direct script access.');
$process = isset($process) ? (array) $process : [];
$audit = isset($audit) ? $audit : NULL;
$findings = isset($findings) ? (array) $findings : [];
$severities = isset($severities) ? (array) $severities : [];
$plan_metrics = isset($plan_metrics) ? $plan_metrics : NULL;
$fact_metrics = isset($fact_metrics) ? $fact_metrics : NULL;
$audits = isset($audits) ? (array) $audits : [];
$error = isset($error) ? (string) $error : '';
$plan_svg = isset($plan_svg) ? (string) $plan_svg : '';
$fact_svg = isset($fact_svg) ? (string) $fact_svg : '';
$sev_text = ['critical' => 'критический', 'high' => 'высокий', 'medium' => 'средний', 'low' => 'низкий'];
$sev_class = [
	'critical' => 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200',
	'high' => 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
	'medium' => 'border-gray-300 bg-gray-100 text-gray-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200',
	'low' => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200',
];
$risk = $audit ? (string) $audit['risk_level'] : '';
$impact = $audit ? (float) $audit['total_impact_rub'] : 0.0;
$findings_json = $audit ? Service_Nj670_Process::json_decode(Arr::get($audit, 'findings', [])) : [];
$summary = $audit ? (string) $audit['summary'] : '';
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($notice)): ?>
	<div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 n2j-ds-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"><?php echo HTML::chars($notice); ?></div>
	<?php endif; ?>

	<div class="flex flex-wrap items-start justify-between gap-2">
		<div>
			<div class="n2j-ds-xs text-gray-500 dark:text-gray-400"><a href="/process/<?php echo HTML::chars($process['code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">← К холсту</a></div>
			<h1 class="n2j-h1 mt-1"><i class="fas fa-shield-halved mr-2 text-blue-500"></i>AI-аудит «План ↔ Факт»</h1>
			<p class="mt-1 max-w-3xl n2j-ds-body text-gray-600 dark:text-gray-300">Семантическое сопоставление схем: какие шаги потерялись, что появилось вне регламента, где обойдён контроль и во сколько это обходится компании.</p>
		</div>
		<div class="flex flex-wrap gap-1.5">
			<?php if ($plan_metrics AND $fact_metrics): ?>
			<form method="post" action="/process/<?php echo HTML::chars($process['code']); ?>/audit" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="engine" value="fast">
				<button type="submit" class="n2j-btn-process"><i class="fas fa-calculator mr-1.5"></i>Быстрый аудит</button>
			</form>
			<form method="post" action="/process/<?php echo HTML::chars($process['code']); ?>/audit" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="engine" value="llm">
				<button type="submit" class="n2j-btn-secondary"><i class="fas fa-robot mr-1.5 text-gray-400"></i>AI-аудит (LLM)</button>
			</form>
			<?php endif; ?>
			<a href="/process/<?php echo HTML::chars($process['code']); ?>/report" class="n2j-btn-secondary"><i class="fas fa-file-word mr-1.5 text-gray-400"></i>В регламент</a>
		</div>
	</div>

	<?php if ($error !== ''): ?>
	<div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 n2j-ds-body font-semibold text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars($error); ?></div>
	<?php endif; ?>

	<?php echo View::factory('670/_job')->set('job', isset($job) ? $job : [])->set('code', isset($process) ? (string) $process['code'] : '')->render(); ?>

	<?php if ( ! $audit): ?>
	<div class="mt-5 rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-8 text-center dark:border-gray-700 dark:bg-gray-900/60">
		<i class="fas fa-scale-balanced text-3xl text-blue-200 dark:text-blue-800"></i>
		<h2 class="mt-2 n2j-ds-h3 font-bold text-gray-900 dark:text-white">Аудит ещё не запускался</h2>
		<p class="mx-auto mt-1 max-w-xl n2j-ds-body text-gray-600 dark:text-gray-300">Нужны обе схемы: план из текста и факт из журнала. «Быстрый аудит» считает разрывы правилами и работает без LLM.</p>
		<?php if ( ! $plan_metrics): ?><p class="mt-2 n2j-ds-sm font-semibold text-amber-700 dark:text-amber-300">Плана нет — соберите его на холсте.</p><?php endif; ?>
		<?php if ( ! $fact_metrics): ?><p class="mt-1 n2j-ds-sm font-semibold text-amber-700 dark:text-amber-300">Факта нет — загрузите журнал.</p><?php endif; ?>
	</div>
	<?php else: ?>

	<div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
		<div class="n2j-card p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Риск</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md text-rose-600 bg-rose-50 dark:bg-rose-950/40 dark:text-rose-300"><i class="fas fa-gauge-high text-[11px]"></i></span></div>
			<div class="mt-0.5 n2j-ds-h3 font-bold <?php echo $risk === 'critical' ? 'text-rose-600 dark:text-rose-400' : ($risk === 'high' ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'); ?>"><?php echo HTML::chars((string) Arr::get($sev_text, $risk, $risk)); ?></div>
		</div>
		<div class="n2j-card p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Потери на процедуру</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md text-amber-600 bg-amber-50 dark:bg-amber-950/40 dark:text-amber-300"><i class="fas fa-coins text-[11px]"></i></span></div>
			<div class="mt-0.5 n2j-ds-h3 font-bold text-rose-600 dark:text-rose-400"><?php echo HTML::chars(Service_Nj670_Repo::money($impact)); ?></div>
		</div>
		<div class="n2j-card p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Находок</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md text-blue-600 bg-blue-50 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-magnifying-glass-chart text-[11px]"></i></span></div>
			<div class="mt-0.5 n2j-ds-h3 font-bold text-gray-900 dark:text-white"><?php echo count($findings_json); ?></div>
			<div class="mt-0.5 n2j-ds-xs text-gray-500 dark:text-gray-400">крит. <?php echo (int) Arr::get($severities, 'critical', 0); ?> · высок. <?php echo (int) Arr::get($severities, 'high', 0); ?> · средн. <?php echo (int) Arr::get($severities, 'medium', 0); ?></div>
		</div>
		<div class="n2j-card p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Движок</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md text-gray-600 bg-gray-100 dark:bg-gray-800 dark:text-gray-300"><i class="fas fa-microchip text-[11px]"></i></span></div>
			<div class="mt-0.5 n2j-ds-body font-bold text-gray-900 dark:text-white"><?php echo (string) $audit['mode'] === 'llm' ? 'LLM' : ((string) $audit['mode'] === 'hybrid' ? 'LLM + правила' : 'Правила'); ?></div>
			<div class="mt-0.5 n2j-ds-xs text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $audit['model']); ?></div>
		</div>
	</div>

	<div class="mt-4 grid gap-4 lg:grid-cols-3">
		<section class="n2j-card lg:col-span-2">
			<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white">Сводка аудита</h2>
			<p class="mt-2 whitespace-pre-line n2j-ds-body leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars($summary); ?></p>
			<div class="mt-4 grid gap-2 sm:grid-cols-3">
				<?php foreach ([
					['План', $plan_metrics ? Service_Nj670_Repo::minutes_text((int) $plan_metrics['elapsed_minutes']) : '—'],
					['Факт', $fact_metrics ? Service_Nj670_Repo::minutes_text((int) $fact_metrics['elapsed_minutes']) : '—'],
					['Разрыв', ($plan_metrics AND $fact_metrics) ? Service_Nj670_Repo::minutes_text(max(0, (int) $fact_metrics['elapsed_minutes'] - (int) $plan_metrics['elapsed_minutes'])) : '—'],
				] as $row): ?>
				<div class="rounded-lg border border-gray-100 bg-gray-50 p-2.5 dark:border-gray-800 dark:bg-gray-800/60">
					<div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo HTML::chars($row[0]); ?></div>
					<div class="mt-0.5 n2j-ds-body font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($row[1]); ?></div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php if ($plan_metrics AND $fact_metrics): ?>
			<div class="mt-3 grid gap-2 n2j-ds-xs sm:grid-cols-2">
				<div class="rounded-lg border border-gray-100 p-2.5 dark:border-gray-800">
					<div class="font-semibold text-gray-700 dark:text-gray-200">Работа / ожидание</div>
					<div class="mt-1 flex items-center justify-between text-gray-600 dark:text-gray-300"><span>План</span><span><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $plan_metrics['work_minutes'])); ?> / <?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $plan_metrics['wait_minutes'])); ?></span></div>
					<div class="mt-0.5 flex items-center justify-between text-gray-600 dark:text-gray-300"><span>Факт</span><span><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $fact_metrics['work_minutes'])); ?> / <?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) $fact_metrics['wait_minutes'])); ?></span></div>
				</div>
				<div class="rounded-lg border border-gray-100 p-2.5 dark:border-gray-800">
					<div class="font-semibold text-gray-700 dark:text-gray-200">Теневые шаги</div>
					<div class="mt-1 flex items-center justify-between text-gray-600 dark:text-gray-300"><span>В факте</span><span><?php echo (int) $fact_metrics['shadow_count']; ?></span></div>
					<div class="mt-0.5 flex items-center justify-between text-gray-600 dark:text-gray-300"><span>Петель возврата</span><span><?php echo (int) $fact_metrics['loop_count']; ?></span></div>
				</div>
			</div>
			<?php endif; ?>
		</section>

		<section class="n2j-card">
			<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white">Наложение схем</h2>
			<div class="n2j-seg mt-2 inline-flex">
				<button type="button" data-view="plan" class="n2j-seg-item">План</button>
				<button type="button" data-view="fact" class="n2j-seg-item is-active">Факт</button>
				<button type="button" data-view="both" class="n2j-seg-item">Оба</button>
			</div>
			<div id="nj670-overlay" class="relative mt-2 min-h-[220px] overflow-hidden rounded-lg border border-gray-100 bg-gray-50 dark:border-gray-800 dark:bg-gray-950">
				<div data-pane="plan" class="hidden"><?php echo $plan_svg; ?></div>
				<div data-pane="fact" class=""><?php echo $fact_svg; ?></div>
				<div data-pane="both" class="pointer-events-none absolute inset-0 hidden">
					<div class="absolute inset-0 opacity-50"><?php echo $plan_svg; ?></div>
					<div class="absolute inset-0 opacity-60"><?php echo $fact_svg; ?></div>
				</div>
			</div>
			<p class="mt-2 n2j-ds-xs leading-relaxed text-gray-500 dark:text-gray-400">Полупрозрачное наложение: зелёные элементы — план, красные/оранжевые — фактические отклонения. Для деталей откройте схемы на холсте.</p>
		</section>
	</div>

	<section class="mt-4">
		<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white">Находки аудита</h2>
		<div class="mt-2 space-y-2">
			<?php foreach ($findings_json as $i => $finding): ?>
			<?php $sev = (string) Arr::get($finding, 'severity', 'medium'); ?>
			<article class="rounded-xl border p-4 <?php echo Arr::get($sev_class, $sev, $sev_class['medium']); ?>">
				<div class="flex flex-wrap items-start justify-between gap-2">
					<div class="flex items-start gap-2">
						<span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-white/70 n2j-ds-xs font-bold text-gray-700 dark:bg-gray-900/60 dark:text-gray-200"><?php echo (int) $i + 1; ?></span>
						<div>
							<h3 class="n2j-ds-body font-bold"><?php echo HTML::chars((string) Arr::get($finding, 'title', '')); ?></h3>
							<p class="mt-0.5 n2j-ds-xs font-semibold uppercase tracking-wide opacity-70"><?php echo HTML::chars((string) Arr::get($sev_text, $sev, $sev)); ?><?php $cat = (string) Arr::get($finding, 'category', ''); ?><?php if ($cat !== ''): ?> · <?php echo HTML::chars($cat); ?><?php endif; ?><?php $metric = (string) Arr::get($finding, 'metric', ''); ?><?php if ($metric !== ''): ?> · <?php echo HTML::chars($metric); ?><?php endif; ?></p>
						</div>
					</div>
					<?php if ((float) Arr::get($finding, 'impact_rub', 0) > 0): ?>
					<span class="rounded-full bg-white/80 px-2.5 py-1 n2j-ds-sm font-bold text-rose-700 dark:bg-gray-900/70 dark:text-rose-300">−<?php echo HTML::chars(Service_Nj670_Repo::money((float) $finding['impact_rub'])); ?></span>
					<?php endif; ?>
				</div>
				<div class="mt-2 grid gap-2 n2j-ds-sm sm:grid-cols-3">
					<div class="sm:col-span-1"><div class="n2j-ds-xs font-bold uppercase tracking-wide opacity-60">Что не так</div><p class="mt-0.5 leading-relaxed"><?php echo HTML::chars((string) Arr::get($finding, 'description', '')); ?></p></div>
					<div><div class="n2j-ds-xs font-bold uppercase tracking-wide opacity-60">Доказательство</div><p class="mt-0.5 leading-relaxed"><?php echo HTML::chars((string) Arr::get($finding, 'evidence', '')); ?></p></div>
					<div><div class="n2j-ds-xs font-bold uppercase tracking-wide opacity-60">Что сделать</div><p class="mt-0.5 leading-relaxed"><?php echo HTML::chars((string) Arr::get($finding, 'recommendation', '')); ?></p></div>
				</div>
				<?php $node_id = (string) Arr::get($finding, 'node_id', ''); ?>
				<?php if ($node_id !== ''): ?><div class="mt-2 n2j-ds-xs opacity-60">Шаг: <span class="font-mono"><?php echo HTML::chars($node_id); ?></span> · <a href="/process/<?php echo HTML::chars($process['code']); ?>?kind=fact" class="font-semibold underline decoration-dotted">показать на холсте</a></div><?php endif; ?>
			</article>
			<?php endforeach; ?>
		</div>
	</section>

	<?php endif; ?>

	<?php if ($audits): ?>
	<section class="n2j-card p-0 mt-5">
		<h2 class="border-b border-gray-100 px-4 py-3 n2j-ds-body font-bold text-gray-900 dark:border-gray-800 dark:text-white">История аудитов</h2>
		<div class="overflow-x-auto">
			<table class="n2j-table min-w-full">
				<thead class="bg-gray-50 n2j-ds-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
					<tr><th class="px-4 py-2">#</th><th class="px-4 py-2">Статус</th><th class="px-4 py-2">Риск</th><th class="px-4 py-2">Потери</th><th class="px-4 py-2">Движок</th><th class="px-4 py-2">Дата</th></tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
					<?php foreach ($audits as $row): ?>
					<tr>
						<td class="px-4 py-2 font-mono n2j-ds-xs text-gray-500">#<?php echo (int) $row['id']; ?></td>
						<td class="px-4 py-2"><span class="n2j-badge <?php echo (string) $row['status'] === 'done' ? 'n2j-badge-ok' : ((string) $row['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-run'); ?>"><?php echo HTML::chars((string) $row['status']); ?></span></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($sev_text, (string) $row['risk_level'], '—')); ?></td>
						<td class="px-4 py-2 font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars(Service_Nj670_Repo::money((float) $row['total_impact_rub'])); ?></td>
						<td class="px-4 py-2 text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $row['mode']); ?></td>
						<td class="px-4 py-2 text-gray-500 dark:text-gray-400"><?php echo HTML::chars(Service_Nj670_Repo::date_text($row['created_at'])); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>
</div>

<script>
(function () {
	var box = document.getElementById('nj670-overlay');
	if (!box) { return; }
	var buttons = document.querySelectorAll('[data-view]');
	var panes = box.querySelectorAll('[data-pane]');

	function show(view) {
		for (var i = 0; i < panes.length; i++) {
			var pane = panes[i];
			var name = pane.getAttribute('data-pane');
			var on = name === view || (view === 'both' && name === 'both');
			pane.classList.toggle('hidden', !on);
			pane.classList.toggle('relative', name !== 'both' && on);
		}
		for (var j = 0; j < buttons.length; j++) {
			var name = buttons[j].getAttribute('data-view');
			var active = name === view;
			buttons[j].classList.toggle('is-active', active);
		}
	}

	for (var i = 0; i < buttons.length; i++) {
		buttons[i].addEventListener('click', function () { show(this.getAttribute('data-view')); });
	}
})();
</script>
