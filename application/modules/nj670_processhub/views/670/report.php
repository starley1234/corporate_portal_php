<?php defined('SYSPATH') or die('No direct script access.');
$process = isset($process) ? (array) $process : [];
$document = isset($document) ? $document : NULL;
$reports = isset($reports) ? (array) $reports : [];
$version = isset($version) ? $version : NULL;
$kind = isset($kind) ? (string) $kind : 'plan';
$audit = isset($audit) ? $audit : NULL;
$error = isset($error) ? (string) $error : '';
$sev_text = ['critical' => 'критический', 'high' => 'высокий', 'medium' => 'средний', 'low' => 'низкий'];
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($notice)): ?>
	<div class="no-print mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 n2j-ds-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"><?php echo HTML::chars($notice); ?></div>
	<?php endif; ?>

	<?php echo View::factory('670/_job')->set('job', isset($job) ? $job : [])->set('code', isset($process) ? (string) $process['code'] : '')->render(); ?>

	<div class="no-print flex flex-wrap items-start justify-between gap-2">
		<div>
			<div class="n2j-ds-xs text-gray-500 dark:text-gray-400"><a href="/process/<?php echo HTML::chars($process['code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">← К холсту</a></div>
			<h1 class="n2j-h1 mt-1"><i class="fas fa-file-word mr-2 text-blue-500"></i>Регламент процесса</h1>
			<p class="mt-1 max-w-3xl n2j-ds-body text-gray-600 dark:text-gray-300">DOCX и PDF из тех же данных, что на холсте: титул, версия, лист согласования, операционная таблица и матрица RACI. Без ручной пересборки.</p>
		</div>
		<div class="flex flex-wrap gap-1.5">
			<form method="post" action="/process/<?php echo HTML::chars($process['code']); ?>/report?kind=<?php echo HTML::chars($kind); ?>" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="kind" value="<?php echo HTML::chars($kind); ?>">
				<button type="submit" class="n2j-btn-cta"><i class="fas fa-file-export mr-1.5"></i>Выпустить DOCX/PDF</button>
			</form>
			<button type="button" onclick="window.print()" class="n2j-btn-secondary"><i class="fas fa-print mr-1.5"></i>Печать / PDF из браузера</button>
		</div>
	</div>

	<?php if ($error !== ''): ?>
	<div class="no-print mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 n2j-ds-body font-semibold text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars($error); ?></div>
	<?php endif; ?>

	<div class="no-print mt-3 flex flex-wrap items-center gap-2">
		<span class="n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Версия для документа:</span>
		<a href="/process/<?php echo HTML::chars($process['code']); ?>/report?kind=plan" class="n2j-chip<?php echo $kind === 'plan' ? ' is-on' : ''; ?>">План<?php echo $plan ? ' · '.HTML::chars($plan['version_tag']) : ''; ?></a>
		<a href="/process/<?php echo HTML::chars($process['code']); ?>/report?kind=fact" class="n2j-chip<?php echo $kind === 'fact' ? ' is-on' : ''; ?>">Факт<?php echo $fact ? ' · '.HTML::chars($fact['version_tag']) : ''; ?></a>
		<?php if ($audit): ?><span class="rounded-full bg-amber-50 px-2.5 py-1 n2j-ds-xs font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-200">аудит #<?php echo (int) $audit['id']; ?> приложится</span><?php endif; ?>
	</div>

	<?php if ( ! $version): ?>
	<div class="mt-5 rounded-xl border border-dashed border-gray-300 p-6 text-center dark:border-gray-700"><p class="n2j-ds-body text-gray-600 dark:text-gray-300">Для выбранной схемы нет версии. Соберите её на холсте.</p></div>
	<?php elseif ( ! $document): ?>
	<div class="mt-5 rounded-xl border border-dashed border-gray-300 p-6 text-center dark:border-gray-700"><p class="n2j-ds-body text-gray-600 dark:text-gray-300">Не удалось собрать структуру документа.</p></div>
	<?php else: ?>

	<div class="mt-5 grid gap-3 lg:grid-cols-4">
		<div class="n2j-card no-print p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Шагов в таблице</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"><i class="fas fa-diagram-project text-[11px]"></i></span></div>
			<div class="n2j-ds-h3 font-bold text-gray-900 dark:text-white"><?php echo count((array) $document['steps']); ?></div>
		</div>
		<div class="n2j-card no-print p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Ролей в RACI</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><i class="fas fa-users text-[11px]"></i></span></div>
			<div class="n2j-ds-h3 font-bold text-gray-900 dark:text-white"><?php echo count((array) Arr::path($document, 'raci.columns', [])); ?></div>
		</div>
		<div class="n2j-card no-print p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Срок</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300"><i class="fas fa-clock text-[11px]"></i></span></div>
			<div class="n2j-ds-h3 font-bold text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) Arr::path($document, 'metrics.elapsed_minutes', 0))); ?></div>
		</div>
		<div class="n2j-card no-print p-3">
			<div class="flex items-center justify-between gap-2"><div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Стоимость</div><span class="inline-flex h-6 w-6 items-center justify-center rounded-md bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300"><i class="fas fa-coins text-[11px]"></i></span></div>
			<div class="n2j-ds-h3 font-bold text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj670_Repo::money((float) Arr::path($document, 'metrics.cost_rub', 0))); ?></div>
		</div>
	</div>

	<div id="nj670-print" class="n2j-card n2j-print-area print:border-0 print:p-0 print:shadow-none mt-5 text-gray-900 sm:p-10 dark:bg-white dark:text-gray-900">

		<div class="text-center">
			<p class="text-sm font-semibold"><?php echo HTML::chars((string) $document['org_name']); ?></p>
			<p class="mt-8 text-base font-bold tracking-wide">РЕГЛАМЕНТ БИЗНЕС-ПРОЦЕССА</p>
			<p class="mt-3 text-xl font-bold">«<?php echo HTML::chars((string) $document['title']); ?>»</p>
			<p class="mt-6 text-xs">Версия: <?php echo HTML::chars((string) $document['version_tag']); ?> · <?php echo HTML::chars((string) $document['kind_label']); ?></p>
			<p class="mt-1 text-xs">Статус: <?php echo HTML::chars((string) $document['status_label']); ?></p>
			<p class="mt-1 text-xs">Код процесса: <?php echo HTML::chars((string) $document['code']); ?></p>
			<p class="mt-1 text-xs">Владелец: <?php echo HTML::chars((string) $document['owner']); ?></p>
			<p class="mt-10 text-xs"><?php echo HTML::chars((string) $document['city']); ?>, <?php echo HTML::chars((string) $document['year']); ?></p>
		</div>

		<h2 class="n2j-h3 mt-10 border-b border-gray-300 pb-1">1. Общие сведения</h2>
		<table class="n2j-table mt-2">
			<tbody>
				<tr><td class="w-44 py-1 font-semibold">Организация</td><td class="py-1"><?php echo HTML::chars((string) $document['org_name']); ?></td></tr>
				<tr><td class="py-1 font-semibold">Подразделение</td><td class="py-1"><?php echo HTML::chars((string) $document['department']); ?></td></tr>
				<tr><td class="py-1 font-semibold">Владелец процесса</td><td class="py-1"><?php echo HTML::chars((string) $document['owner']); ?></td></tr>
				<tr><td class="py-1 font-semibold">Дата формирования</td><td class="py-1"><?php echo HTML::chars((string) $document['date_text']); ?></td></tr>
				<tr><td class="py-1 font-semibold">Формат</td><td class="py-1"><?php echo HTML::chars((string) $document['gost']); ?></td></tr>
				<tr><td class="py-1 font-semibold">Срок / стоимость</td><td class="py-1"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) Arr::path($document, 'metrics.elapsed_minutes', 0))); ?> · <?php echo HTML::chars(Service_Nj670_Repo::money((float) Arr::path($document, 'metrics.cost_rub', 0))); ?></td></tr>
			</tbody>
		</table>
		<?php if (trim((string) $document['description']) !== ''): ?>
		<p class="mt-2 text-xs leading-relaxed"><span class="font-semibold">Назначение:</span> <?php echo HTML::chars((string) $document['description']); ?></p>
		<?php endif; ?>

		<h2 class="n2j-h3 mt-6 border-b border-gray-300 pb-1">2. Операционная таблица шагов</h2>
		<div class="mt-2 overflow-x-auto">
			<table class="n2j-table border-collapse leading-snug">
				<thead>
					<tr class="bg-gray-100">
						<th class="border border-gray-300 px-1.5 py-1 text-left">№</th>
						<th class="border border-gray-300 px-1.5 py-1 text-left">Роль</th>
						<th class="border border-gray-300 px-1.5 py-1 text-left">Вход</th>
						<th class="border border-gray-300 px-1.5 py-1 text-left">Действие</th>
						<th class="border border-gray-300 px-1.5 py-1 text-left">Система</th>
						<th class="border border-gray-300 px-1.5 py-1 text-left">Выход</th>
						<th class="border border-gray-300 px-1.5 py-1 text-left">SLA</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ((array) $document['steps'] as $step): ?>
					<tr>
						<td class="border border-gray-300 px-1.5 py-1 align-top"><?php echo (int) $step['n']; ?></td>
						<td class="border border-gray-300 px-1.5 py-1 align-top"><?php echo HTML::chars((string) $step['role']); ?></td>
						<td class="border border-gray-300 px-1.5 py-1 align-top"><?php echo HTML::chars((string) $step['input']); ?></td>
						<td class="border border-gray-300 px-1.5 py-1 align-top font-semibold"><?php echo HTML::chars((string) $step['title']); ?></td>
						<td class="border border-gray-300 px-1.5 py-1 align-top"><?php echo HTML::chars((string) $step['system']); ?></td>
						<td class="border border-gray-300 px-1.5 py-1 align-top"><?php echo HTML::chars((string) $step['output']); ?></td>
						<td class="border border-gray-300 px-1.5 py-1 align-top"><?php echo HTML::chars((string) $step['sla']); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<h2 class="n2j-h3 mt-6 border-b border-gray-300 pb-1">3. Матрица RACI</h2>
		<?php $raci = (array) $document['raci']; ?>
		<?php if ( ! empty($raci['columns']) AND ! empty($raci['rows'])): ?>
		<div class="mt-2 overflow-x-auto">
			<table class="n2j-table border-collapse">
				<thead>
					<tr class="bg-gray-100">
						<th class="border border-gray-300 px-1.5 py-1 text-left">Шаг</th>
						<?php foreach ($raci['columns'] as $column): ?><th class="border border-gray-300 px-1.5 py-1 text-center"><?php echo HTML::chars((string) $column['name']); ?></th><?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($raci['rows'] as $row): ?>
					<tr>
						<td class="border border-gray-300 px-1.5 py-1"><?php echo HTML::chars((string) $row['title']); ?></td>
						<?php foreach ($raci['columns'] as $column): ?><td class="border border-gray-300 px-1.5 py-1 text-center font-bold"><?php echo HTML::chars((string) Arr::get((array) $row['cells'], (string) $column['code'], '')); ?></td><?php endforeach; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="mt-1 text-xs text-gray-600">R — исполнитель, A — ответственный, C — консультирует, I — информируется.</p>
		<?php else: ?>
		<p class="mt-1 text-xs">Роли в схеме не заданы.</p>
		<?php endif; ?>

		<?php if ( ! empty($document['rules'])): ?>
		<h2 class="n2j-h3 mt-6 border-b border-gray-300 pb-1">4. Контрольные правила</h2>
		<table class="n2j-table mt-2 border-collapse">
			<thead><tr class="bg-gray-100"><th class="border border-gray-300 px-1.5 py-1 text-left">Код</th><th class="border border-gray-300 px-1.5 py-1 text-left">Правило</th><th class="border border-gray-300 px-1.5 py-1 text-left">Комментарий</th></tr></thead>
			<tbody>
				<?php foreach ((array) $document['rules'] as $rule): ?>
				<tr><td class="border border-gray-300 px-1.5 py-1 font-mono"><?php echo HTML::chars((string) $rule['code']); ?></td><td class="border border-gray-300 px-1.5 py-1"><?php echo HTML::chars((string) $rule['name']); ?></td><td class="border border-gray-300 px-1.5 py-1"><?php echo HTML::chars((string) $rule['note']); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ( ! empty($document['audit'])): ?>
		<?php $doc_audit = (array) $document['audit']; ?>
		<h2 class="n2j-h3 mt-6 border-b border-gray-300 pb-1">5. Результаты аудита «План ↔ Факт»</h2>
		<p class="mt-2 text-xs leading-relaxed"><?php echo HTML::chars((string) $doc_audit['summary']); ?></p>
		<p class="mt-1 text-xs"><span class="font-semibold">Риск:</span> <?php echo HTML::chars((string) Arr::get($sev_text, (string) $doc_audit['risk_level'], (string) $doc_audit['risk_level'])); ?> · <span class="font-semibold">Оценка потерь:</span> <?php echo HTML::chars(Service_Nj670_Repo::money((float) $doc_audit['impact_rub'])); ?></p>
		<table class="n2j-table mt-2 border-collapse">
			<thead><tr class="bg-gray-100"><th class="border border-gray-300 px-1.5 py-1 text-left">Уровень</th><th class="border border-gray-300 px-1.5 py-1 text-left">Находка</th><th class="border border-gray-300 px-1.5 py-1 text-left">Доказательство</th><th class="border border-gray-300 px-1.5 py-1 text-left">Рекомендация</th><th class="border border-gray-300 px-1.5 py-1 text-right">Эффект</th></tr></thead>
			<tbody>
				<?php foreach ((array) $doc_audit['findings'] as $finding): ?>
				<tr>
					<td class="border border-gray-300 px-1.5 py-1"><?php echo HTML::chars((string) Arr::get($sev_text, (string) Arr::get($finding, 'severity', 'medium'), '')); ?></td>
					<td class="border border-gray-300 px-1.5 py-1 font-semibold"><?php echo HTML::chars((string) Arr::get($finding, 'title', '')); ?></td>
					<td class="border border-gray-300 px-1.5 py-1"><?php echo HTML::chars((string) Arr::get($finding, 'evidence', '')); ?></td>
					<td class="border border-gray-300 px-1.5 py-1"><?php echo HTML::chars((string) Arr::get($finding, 'recommendation', '')); ?></td>
					<td class="border border-gray-300 px-1.5 py-1 text-right"><?php echo HTML::chars(Service_Nj670_Repo::money((float) Arr::get($finding, 'impact_rub', 0))); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<h2 class="n2j-h3 mt-6 border-b border-gray-300 pb-1">Лист согласования</h2>
		<div class="mt-3 grid gap-5 text-xs sm:grid-cols-2">
			<div>
				<p class="font-semibold">Владелец процесса</p>
				<p class="mt-1"><?php echo HTML::chars((string) $document['owner']); ?></p>
				<p class="n2j-hint mt-6 border-t border-gray-400 pt-1">подпись / дата</p>
			</div>
			<div>
				<p class="font-semibold"><?php echo HTML::chars((string) $document['approve_role']); ?></p>
				<p class="n2j-hint mt-6 border-t border-gray-400 pt-1">подпись / дата</p>
			</div>
		</div>
		<p class="n2j-hint mt-8">Документ сформирован ProcessHub <?php echo HTML::chars((string) $document['date_text']); ?> и соответствует <?php echo HTML::chars((string) $document['gost']); ?>.</p>
	</div>

	<section class="n2j-card p-0 no-print mt-5">
		<h2 class="border-b border-gray-100 px-4 py-3 n2j-ds-body font-bold text-gray-900 dark:border-gray-800 dark:text-white">Выпущенные файлы</h2>
		<?php if (empty($reports)): ?>
		<p class="px-4 py-4 n2j-ds-sm text-gray-500 dark:text-gray-400">Файлов ещё нет. Нажмите «Выпустить DOCX/PDF».</p>
		<?php else: ?>
		<div class="divide-y divide-gray-100 dark:divide-gray-800">
			<?php foreach ($reports as $report): ?>
			<div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
				<div>
					<div class="n2j-ds-sm font-semibold text-gray-800 dark:text-gray-200">Отчёт #<?php echo (int) $report['id']; ?> · <?php echo HTML::chars(Service_Nj670_Repo::date_text($report['created_at'])); ?></div>
					<div class="mt-0.5 flex flex-wrap gap-1.5 n2j-ds-xs">
						<?php $status = (string) $report['status']; ?>
						<span class="rounded-full px-1.5 py-0.5 font-bold <?php echo $status === 'done' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : ($status === 'error' ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300'); ?>"><?php echo HTML::chars($status); ?></span>
						<?php if ((string) $report['error'] !== ''): ?><span class="text-rose-600 dark:text-rose-300"><?php echo HTML::chars(UTF8::substr((string) $report['error'], 0, 200)); ?></span><?php endif; ?>
						<?php if ((string) $report['model'] !== ''): ?><span class="text-gray-400"><?php echo HTML::chars((string) $report['model']); ?></span><?php endif; ?>
					</div>
				</div>
				<div class="flex flex-wrap gap-1.5">
					<?php if ((string) $report['docx_path'] !== ''): ?>
					<a href="/process/r/<?php echo (int) $report['id']; ?>/docx" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-file-word mr-1"></i>DOCX · <?php echo number_format((int) $report['docx_size'] / 1024, 0, '.', ' '); ?> КБ</a>
					<?php endif; ?>
					<?php if ((string) $report['pdf_path'] !== ''): ?>
					<a href="/process/r/<?php echo (int) $report['id']; ?>/pdf" class="n2j-btn-process n2j-btn-sm"><i class="fas fa-file-pdf mr-1"></i>PDF · <?php echo number_format((int) $report['pdf_size'] / 1024, 0, '.', ' '); ?> КБ</a>
					<?php endif; ?>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<?php endif; ?>
</div>
