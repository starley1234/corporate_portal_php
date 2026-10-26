<?php defined('SYSPATH') or die('No direct script access.');
$processes = isset($processes) ? (array) $processes : [];
$totals = isset($totals) ? (array) $totals : [];
$ontology = isset($ontology) ? (array) $ontology : [];
$workspace = isset($workspace) ? $workspace : NULL;
$query = isset($query) ? (array) $query : ['q' => '', 'department' => '', 'owner' => 0, 'state' => ''];
$facets = isset($facets) ? (array) $facets : ['departments' => [], 'owners' => []];
$all_count = isset($all_count) ? (int) $all_count : count($processes);
$filtered = ($query['q'] !== '' OR $query['department'] !== '' OR (int) $query['owner'] > 0 OR $query['state'] !== '');
$worker = isset($worker) ? (array) $worker : [];
$card = 'rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900';
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($notice)): ?>
	<div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 n2j-ds-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
		<i class="fas fa-circle-check mr-1"></i><?php echo HTML::chars($notice); ?>
	</div>

	<?php endif; ?>

	<?php if ($worker AND (empty($worker['online']) OR (int) Arr::get($worker, 'queued', 0) > 0)): ?>
	<div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 n2j-ds-sm <?php echo empty($worker['online']) ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200' : 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200'; ?>">
		<?php if (empty($worker['online'])): ?>
		<i class="fas fa-plug-circle-xmark"></i>
		<span class="font-semibold">Воркер не отвечает.</span>
		<span class="text-amber-900/80 dark:text-amber-200/80">Новые PDF, сканы и аудио подождут запуска обработчика. <?php echo HTML::chars((string) Arr::get($worker, 'hint', '')); ?></span>
		<?php else: ?>
		<span class="n2j-dots text-gray-500" aria-hidden="true"><i></i><i></i><i></i></span>
		<span class="font-semibold">Воркер обрабатывает <?php echo (int) $worker['queued']; ?> задач(и).</span>
		<span class="text-gray-500 dark:text-gray-400">Схемы и отчёты обновятся сами — страницу перезагружать не нужно.</span>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<section class="n2j-panel overflow-hidden">
		<div class="px-4 py-5 sm:px-6 sm:py-7">
			<span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 n2j-ds-xs font-semibold uppercase tracking-wide text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">BPMN · онтология · process mining</span>
			<h1 class="mt-2 n2j-ds-h1 font-bold tracking-tight text-gray-900 dark:text-white">Процесс, который видно насквозь</h1>
			<p class="mt-2 max-w-3xl n2j-ds-body leading-relaxed text-gray-600 dark:text-gray-300">Опишите регламент словами или приложите PDF — ProcessHub соберёт схему с ролями, системами и артефактами. Загрузите журнал событий — и сравните план с фактом: где обходят юриста, что ушло в Telegram и сколько это стоит.</p>
			<div class="mt-4 flex flex-wrap gap-2">
				<a href="/process/create" class="n2j-btn-process"><i class="fas fa-wand-magic-sparkles mr-2"></i>Собрать схему</a>
				<a href="/process/demo" class="n2j-btn-secondary"><i class="fas fa-circle-play mr-2 text-blue-500"></i>Демо за 5 минут</a>
				<a href="/process/ontology" class="n2j-btn-secondary"><i class="fas fa-sitemap mr-2 text-blue-500"></i>Онтология</a>
				<a href="/process/map" class="n2j-btn-secondary"><i class="fas fa-building mr-2 text-blue-500"></i>Карта организации</a>
			</div>
			<p class="mt-3 n2j-ds-xs text-gray-500 dark:text-gray-400">Рабочее пространство: <?php echo HTML::chars((string) Arr::get((array) $workspace, 'company_name', 'не заполнено')); ?> · <?php echo HTML::chars((string) Arr::get((array) $workspace, 'title', '')); ?></p>
		</div>
	</section>

	<div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4">
		<?php
		$tiles = [
			['Процессы', (int) Arr::get($totals, 'processes', 0), 'diagram-project', 'indigo'],
			['Аудиты', (int) Arr::get($totals, 'audits', 0), 'shield-halved', 'teal'],
			['Регламенты', (int) Arr::get($totals, 'reports', 0), 'file-word', 'emerald'],
			['Оценка потерь', Service_Nj670_Repo::money((float) Arr::get($totals, 'impact', 0)), 'scale-unbalanced', 'rose'],
		];
		$tone = [
			'indigo' => 'bg-blue-50 text-blue-600 dark:bg-blue-950/40 dark:text-blue-300',
			'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
			'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
			'teal' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
			'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
		];
		foreach ($tiles as $tile):
		?>
		<div class="<?php echo $card; ?>">
			<div class="flex items-center gap-2 n2j-ds-label text-gray-500 dark:text-gray-400"><span class="inline-flex h-7 w-7 items-center justify-center rounded-md <?php echo Arr::get($tone, $tile[3], $tone['indigo']); ?>"><i class="fas fa-<?php echo $tile[2]; ?> text-[12px]"></i></span><?php echo HTML::chars($tile[0]); ?></div>
			<div class="mt-1 n2j-ds-h2 font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $tile[1]); ?></div>
		</div>
		<?php endforeach; ?>
	</div>

	<?php if ($all_count > 0): ?>
	<form method="get" action="/process" class="mt-5 <?php echo $card; ?>">
		<div class="flex flex-wrap items-end gap-2">
			<label class="min-w-[220px] flex-1">
				<span class="mb-1 block n2j-ds-label text-gray-500 dark:text-gray-400">Поиск</span>
				<div class="relative">
					<i class="fas fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
					<input type="search" name="q" value="<?php echo HTML::chars((string) $query['q']); ?>" placeholder="Название, код, подразделение, владелец…" class="n2j-input w-full pl-8">
				</div>
			</label>
			<label class="min-w-[170px]">
				<span class="mb-1 block n2j-ds-label text-gray-500 dark:text-gray-400">Подразделение</span>
				<select name="department" class="n2j-input n2j-select w-full">
					<option value="">Все</option>
					<?php foreach ($facets['departments'] as $department): ?>
					<option value="<?php echo HTML::chars($department); ?>"<?php echo $department === (string) $query['department'] ? ' selected' : ''; ?>><?php echo HTML::chars($department); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="min-w-[170px]">
				<span class="mb-1 block n2j-ds-label text-gray-500 dark:text-gray-400">Владелец</span>
				<select name="owner" class="n2j-input n2j-select w-full">
					<option value="0">Все</option>
					<?php foreach ($facets['owners'] as $owner_id => $owner_name): ?>
					<option value="<?php echo (int) $owner_id; ?>"<?php echo (int) $owner_id === (int) $query['owner'] ? ' selected' : ''; ?>><?php echo HTML::chars($owner_name); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="min-w-[160px]">
				<span class="mb-1 block n2j-ds-label text-gray-500 dark:text-gray-400">Состояние</span>
				<select name="state" class="n2j-input n2j-select w-full">
					<?php foreach (['' => 'Любое', 'no_fact' => 'Нет факта', 'no_owner' => 'Без владельца', 'audited' => 'С аудитом'] as $state_value => $state_label): ?>
					<option value="<?php echo HTML::chars($state_value); ?>"<?php echo $state_value === (string) $query['state'] ? ' selected' : ''; ?>><?php echo HTML::chars($state_label); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="n2j-btn-process"><i class="fas fa-filter mr-2"></i>Показать</button>
			<?php if ($filtered): ?>
			<a href="/process" class="n2j-btn-secondary"><i class="fas fa-xmark mr-1.5"></i>Сбросить</a>
			<?php endif; ?>
		</div>
		<?php if ($filtered): ?>
		<p class="mt-2 n2j-ds-xs text-gray-500 dark:text-gray-400">Найдено <?php echo count($processes); ?> из <?php echo $all_count; ?> процессов.</p>
		<?php endif; ?>
	</form>
	<?php endif; ?>

	<?php if ($all_count > 0): ?>
	<?php
	// Быстрые фильтры: один клик вместо выбора в списке. Условия поиска,
	// подразделения и владельца сохраняются — чипы не сбрасывают форму.
	$chip_states = [
		'' => ['Все', 'fa-list'],
		'no_fact' => ['Без факта', 'fa-database'],
		'no_owner' => ['Без владельца', 'fa-user-slash'],
		'audited' => ['С аудитом', 'fa-shield-halved'],
	];
	$chip_base = [
		'q' => (string) $query['q'],
		'department' => (string) $query['department'],
		'owner' => (int) $query['owner'],
	];
	?>
	<div class="mt-3 flex flex-wrap items-center gap-1.5">
		<span class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">Показать:</span>
		<?php foreach ($chip_states as $chip_value => $chip): ?>
		<?php
		$chip_query = $chip_base;

		if ($chip_value !== '')
		{
			$chip_query['state'] = $chip_value;
		}

		$chip_href = '/process'.($chip_query ? '?'.http_build_query($chip_query) : '');
		$chip_on = (string) $query['state'] === $chip_value;
		?>
		<a href="<?php echo HTML::chars($chip_href); ?>"<?php echo $chip_on ? ' aria-current="true"' : ''; ?> class="n2j-chip<?php echo $chip_on ? ' is-on' : ''; ?>"><i class="fas <?php echo $chip[1]; ?> opacity-70"></i><?php echo HTML::chars($chip[0]); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if (empty($processes) AND $all_count > 0): ?>
	<section class="mt-5 rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center dark:border-gray-700 dark:bg-gray-900/60">
		<i class="fas fa-magnifying-glass text-3xl text-gray-400"></i>
		<h2 class="mt-3 n2j-ds-h3 font-bold text-gray-900 dark:text-white">Ничего не найдено</h2>
		<p class="mx-auto mt-1 max-w-xl n2j-ds-body text-gray-600 dark:text-gray-300">Под текущий фильтр не подходит ни один процесс. Сбросьте условия или заведите новый.</p>
		<div class="mt-4 flex flex-wrap justify-center gap-2">
			<a href="/process" class="n2j-btn-secondary">Сбросить фильтр</a>
			<a href="/process/create" class="n2j-btn-cta">Создать процесс</a>
		</div>
	</section>
	<?php elseif (empty($processes)): ?>
	<section class="mt-5 rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center dark:border-gray-700 dark:bg-gray-900/60">
		<i class="fas fa-diagram-project text-3xl text-blue-300 dark:text-blue-700"></i>
		<h2 class="mt-3 n2j-ds-h3 font-bold text-gray-900 dark:text-white">Пока ни одного процесса</h2>
		<p class="mx-auto mt-1 max-w-xl n2j-ds-body text-gray-600 dark:text-gray-300">Начните с демо: оно сразу покажет разрыв между регламентом и реальностью — 3 дня против 14, Telegram вместо СЭД и 18 400 ₽ потерь на процедуру.</p>
		<div class="mt-4 flex flex-wrap justify-center gap-2">
			<a href="/process/demo" class="n2j-btn-secondary">Открыть демо</a>
			<a href="/process/create" class="n2j-btn-secondary">Создать свой</a>
		</div>
	</section>
	<?php else: ?>
	<div class="mt-5 flex items-center justify-between">
		<h2 class="n2j-ds-h3 font-semibold text-gray-900 dark:text-white">Мои процессы<?php if ($filtered): ?> <span class="n2j-ds-xs font-normal text-gray-500 dark:text-gray-400">· найдено <?php echo count($processes); ?> из <?php echo $all_count; ?></span><?php endif; ?></h2>
		<a href="/process/create" class="n2j-ds-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">+ Новый процесс</a>
	</div>
	<div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($processes as $process): ?>
		<?php
		$plan_metrics = (array) Arr::get($process, 'plan_metrics', []);
		$fact_metrics = (array) Arr::get($process, 'fact_metrics', []);
		$plan_min = (int) Arr::get($process, 'plan_minutes', 0);
		$fact_min = (int) Arr::get($process, 'fact_minutes', 0);
		$status = (string) Arr::get($process, 'plan_status', '');
		$gap = ($plan_min > 0 AND $fact_min > 0) ? $fact_min / $plan_min : 0;
		$gap_class = $gap >= 1.15
			? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
			: (($gap > 0 AND $gap <= 0.95)
				? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
				: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300');
		?>
		<a href="/process/<?php echo HTML::chars($process['code']); ?>" class="group <?php echo $card; ?> transition hover:border-blue-300 hover:shadow-sm dark:hover:border-blue-800">
			<div class="flex items-start justify-between gap-2">
				<h3 class="n2j-ds-body font-bold leading-snug text-gray-900 group-hover:text-blue-700 dark:text-white dark:group-hover:text-blue-300"><?php echo HTML::chars($process['title']); ?></h3>
				<?php if ( ! empty($process['is_demo'])): ?>
				<span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 n2j-ds-badge font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">ДЕМО</span>
				<?php endif; ?>
			</div>
			<p class="mt-1 line-clamp-2 n2j-ds-sm text-gray-600 dark:text-gray-300"><?php echo HTML::chars(UTF8::substr(trim((string) $process['description']), 0, 160)); ?></p>
			<div class="mt-3 grid grid-cols-2 gap-2 n2j-ds-xs">
				<div class="rounded-lg bg-blue-50/70 px-2 py-1.5 dark:bg-blue-950/25">
					<div class="text-blue-700 dark:text-blue-300">План</div>
					<div class="font-semibold text-gray-900 dark:text-white"><?php echo $plan_min > 0 ? HTML::chars(Service_Nj670_Repo::minutes_text($plan_min)) : '—'; ?></div>
				</div>
				<div class="rounded-lg bg-emerald-50/70 px-2 py-1.5 dark:bg-emerald-950/25">
					<div class="text-emerald-700 dark:text-emerald-300">Факт</div>
					<div class="font-semibold text-gray-900 dark:text-white"><?php echo $fact_min > 0 ? HTML::chars(Service_Nj670_Repo::minutes_text($fact_min)) : '—'; ?></div>
				</div>
			</div>
			<div class="mt-3 flex flex-wrap items-center gap-1.5 n2j-ds-badge font-semibold">
				<?php
				$status_tone = [
					'active' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
					'in_review' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
					'error' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
				];
				?>
				<span class="rounded-full px-2 py-0.5 <?php echo Arr::get($status_tone, $status, 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'); ?>"><?php echo HTML::chars((string) Arr::get($statuses, $status, 'черновик')); ?></span>
				<?php if ( ! empty($process['owner_role_name'])): ?>
				<span class="rounded-full bg-blue-50 px-2 py-0.5 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-user-tie mr-0.5"></i><?php echo HTML::chars((string) $process['owner_role_name']); ?></span>
				<?php else: ?>
				<span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300" title="Назначьте владельца в онтологии">без владельца</span>
				<?php endif; ?>
				<?php if (trim((string) Arr::get($process, 'department', '')) !== ''): ?>
				<span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-600 dark:bg-gray-800 dark:text-gray-300"><i class="fas fa-building mr-0.5"></i><?php echo HTML::chars((string) $process['department']); ?></span>
				<?php endif; ?>
				<?php if ($gap > 0): ?>
				<span class="rounded-full px-2 py-0.5 <?php echo $gap_class; ?>" title="Отношение фактического срока к плану">факт ×<?php echo number_format($gap, 1, ',', ' '); ?></span>
				<?php endif; ?>
				<?php if ((float) Arr::get($process, 'audit_impact', 0) > 0): ?>
				<span class="rounded-full bg-rose-50 px-2 py-0.5 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">потери <?php echo HTML::chars(Service_Nj670_Repo::money((float) $process['audit_impact'])); ?></span>
				<?php endif; ?>
				<span class="text-gray-500 dark:text-gray-400"><?php echo (int) $process['versions_count']; ?> верс. · <?php echo (int) $process['reports_count']; ?> отч.</span>
			</div>
		</a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<div class="mt-6 grid gap-3 lg:grid-cols-3">
		<?php foreach ([
			['Онтология', 'sitemap', 'Роли, системы, артефакты и правила компании — общий словарь для всех схем. Модель сопоставляет текст с ним, а не выдумывает новые системы.'],
			['Факт без магии', 'database', 'CSV/XLSX с Case_ID, Activity, Timestamp, Resource. Частоты переходов и паузы — честная картина, а не пересказ регламента.'],
			['Регламент по ГОСТ', 'file-export', 'DOCX/PDF: титул, версия, лист согласования, операционная таблица и матрица RACI — из тех же данных, что на холсте.'],
		] as $block): ?>
		<div class="<?php echo $card; ?>">
			<div class="flex items-center gap-2"><i class="fas fa-<?php echo $block[1]; ?> text-blue-500"></i><h3 class="n2j-ds-body font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($block[0]); ?></h3></div>
			<p class="mt-1.5 n2j-ds-sm leading-relaxed text-gray-600 dark:text-gray-300"><?php echo HTML::chars($block[2]); ?></p>
		</div>
		<?php endforeach; ?>
	</div>

	<?php if ( ! empty($ontology['role'])): ?>
	<div class="n2j-card mt-5">
		<h2 class="n2j-ds-body font-semibold text-gray-900 dark:text-white">Справочник ролей</h2>
		<div class="mt-2 flex flex-wrap gap-1.5">
			<?php foreach ((array) $ontology['role'] as $role): ?>
			<span class="rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 n2j-ds-xs font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"><?php echo HTML::chars($role['name']); ?> <span class="text-gray-400"><?php echo HTML::chars($role['code']); ?></span></span>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<p class="mt-5 n2j-ds-xs leading-relaxed text-gray-500 dark:text-gray-400">Лимиты: до <?php echo (int) Arr::get($limits, 'max_nodes', 80); ?> шагов на схему, до <?php echo (int) Arr::get($limits, 'synth_per_day', 10); ?> сборок и <?php echo (int) Arr::get($limits, 'audit_per_day', 10); ?> аудитов в сутки. Схемы и отчёты — только вашего рабочего пространства.</p>
</div>
