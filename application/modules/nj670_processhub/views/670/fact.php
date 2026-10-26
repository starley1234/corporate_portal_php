<?php defined('SYSPATH') or die('No direct script access.');
$process = isset($process) ? (array) $process : [];
$version = isset($version) ? $version : NULL;
$metrics = isset($metrics) ? $metrics : NULL;
$imports = isset($imports) ? (array) $imports : [];
$plan = isset($plan) ? $plan : NULL;
$svg = isset($svg) ? (string) $svg : '';
$error = isset($error) ? (string) $error : '';
$limits = isset($limits) ? (array) $limits : [];
?>
<div class="n2j-ds is-125 w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($notice)): ?>
	<div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 n2j-ds-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"><?php echo HTML::chars($notice); ?></div>
	<?php endif; ?>

	<div class="flex flex-wrap items-start justify-between gap-2">
		<div>
			<div class="n2j-ds-xs text-gray-500 dark:text-gray-400"><a href="/process/<?php echo HTML::chars($process['code']); ?>" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">← К холсту</a></div>
			<h1 class="n2j-h1 mt-1"><i class="fas fa-database mr-2 text-emerald-600"></i>Факт: журнал событий</h1>
			<p class="mt-1 max-w-3xl n2j-ds-body text-gray-600 dark:text-gray-300">Как процесс идёт на самом деле. Загрузите выгрузку из учётной системы — схема соберётся по частотам переходов и паузам, без пересказа регламента.</p>
		</div>
		<?php if ($version): ?>
		<a href="/process/<?php echo HTML::chars($process['code']); ?>?kind=fact" class="n2j-btn-secondary">Открыть факт на холсте</a>
		<?php endif; ?>
	</div>

	<?php if ($error !== ''): ?>
	<div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 n2j-ds-body font-semibold text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300"><?php echo HTML::chars($error); ?></div>
	<?php endif; ?>

	<?php echo View::factory('670/_job')->set('job', isset($job) ? $job : [])->set('code', isset($process) ? (string) $process['code'] : '')->render(); ?>

	<?php if ( ! $plan): ?>
	<div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 n2j-ds-body text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"><i class="fas fa-circle-info mr-1 text-gray-400"></i>Сначала соберите план: факт сравнивается с регламентом на странице аудита.</div>
	<?php endif; ?>

	<div class="mt-5 grid gap-4 lg:grid-cols-5">
		<section class="n2j-card lg:col-span-2">
			<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white">Загрузить журнал</h2>
			<form method="post" action="/process/<?php echo HTML::chars($process['code']); ?>/fact" enctype="multipart/form-data" class="mt-3 space-y-3">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<label class="block n2j-ds-sm font-semibold text-gray-700 dark:text-gray-200">CSV или XLSX
					<input type="file" name="journal" accept=".csv,.xlsx" class="mt-1 block w-full cursor-pointer rounded-lg border border-dashed border-gray-300 bg-gray-50 px-3 py-2 n2j-ds-sm text-gray-700 file:mr-3 file:rounded file:border-0 file:bg-emerald-700 file:px-2.5 file:py-1 file:text-[13px] file:font-semibold file:text-white dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
				</label>
				<div class="text-center n2j-ds-xs uppercase tracking-wide text-gray-400">или вставьте CSV</div>
				<textarea name="journal" rows="6" class="n2j-textarea w-full resize-y font-mono n2j-ds-xs" placeholder="Case_ID,Activity,Timestamp,Resource&#10;C-001,Создание заявки,2026-09-01 09:10,Инициатор&#10;C-001,Согласование,2026-09-01 11:40,Руководитель"></textarea>
				<button type="button" id="nj670-sample" class="n2j-ds-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-400">Вставить пример журнала</button>
				<button type="submit" class="n2j-btn-process w-full"><i class="fas fa-diagram-project mr-2"></i>Построить факт</button>
				<p class="n2j-ds-xs leading-relaxed text-gray-500 dark:text-gray-400">Колонки: Case_ID, Activity, Timestamp, Resource. Регистр и русские названия колонок понимаются. Файл до <?php echo round((int) Arr::get($limits, 'max_import_bytes', 8388608) / 1048576); ?> МБ, до <?php echo (int) Arr::get($limits, 'max_import_rows', 100000); ?> событий.</p>
			</form>
		</section>

		<section class="space-y-3 lg:col-span-3">
			<?php if ($metrics): ?>
			<div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
				<?php $events = $imports ? (int) $imports[0]['rows_count'] : 0; ?>
				<?php foreach ([
					['Событий', $events > 0 ? (string) $events : '—'],
					['Срок', Service_Nj670_Repo::minutes_text((int) $metrics['elapsed_minutes'])],
					['Ожидание', Service_Nj670_Repo::minutes_text((int) $metrics['wait_minutes'])],
					['Стоимость', Service_Nj670_Repo::money((float) $metrics['cost_rub'])],
				] as $tile): ?>
				<div class="n2j-card p-3">
					<div class="n2j-ds-xs font-semibold uppercase tracking-wide text-gray-400"><?php echo HTML::chars($tile[0]); ?></div>
					<div class="mt-0.5 n2j-ds-body font-bold text-gray-900 dark:text-white"><?php echo HTML::chars($tile[1]); ?></div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div class="n2j-card p-3">
				<div class="flex items-center justify-between gap-2">
					<h2 class="n2j-ds-body font-bold text-gray-900 dark:text-white">Схема по журналу</h2>
					<?php if ($version): ?><span class="rounded-full bg-emerald-50 px-2 py-0.5 n2j-ds-xs font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"><?php echo HTML::chars($version['version_tag']); ?></span><?php endif; ?>
				</div>
				<?php if ($svg !== ''): ?>
				<div class="mt-2 overflow-x-auto rounded-lg border border-gray-100 bg-gray-50 p-2 dark:border-gray-800 dark:bg-gray-950"><?php echo $svg; ?></div>
				<?php else: ?>
				<div class="mt-3 rounded-lg border border-dashed border-gray-300 p-6 text-center n2j-ds-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"><i class="fas fa-diagram-project text-2xl text-gray-300"></i><p class="mt-2">Факт ещё не построен. Загрузите журнал слева.</p></div>
				<?php endif; ?>
			</div>

			<?php if ($version): ?>
			<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 n2j-ds-xs leading-relaxed text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
				<p><span class="font-bold">Как читать:</span> толщина связи — частота перехода, красные связи — паузы от 8 часов (480 мин), оранжевый пунктир на шаге — операция вне регламента. Число у стрелки — сколько раз переход встретился в журнале.</p>
			</div>
			<?php endif; ?>
		</section>
	</div>

	<?php if ($imports): ?>
	<section class="n2j-card p-0 mt-5">
		<h2 class="border-b border-gray-100 px-4 py-3 n2j-ds-body font-bold text-gray-900 dark:border-gray-800 dark:text-white">История загрузок</h2>
		<div class="overflow-x-auto">
			<table class="n2j-table min-w-full">
				<thead class="bg-gray-50 n2j-ds-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/60 dark:text-gray-400">
					<tr><th class="px-4 py-2">Файл</th><th class="px-4 py-2">Формат</th><th class="px-4 py-2">Событий</th><th class="px-4 py-2">Статус</th><th class="px-4 py-2">Когда</th></tr>
				</thead>
				<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
					<?php foreach ($imports as $import): ?>
					<tr>
						<td class="px-4 py-2 font-medium text-gray-800 dark:text-gray-200"><?php echo HTML::chars((string) $import['filename']); ?><?php if ((string) $import['error'] !== ''): ?><div class="n2j-ds-xs text-rose-600 dark:text-rose-300"><?php echo HTML::chars($import['error']); ?></div><?php endif; ?></td>
						<td class="px-4 py-2 font-mono n2j-ds-xs uppercase text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) $import['kind']); ?></td>
						<td class="px-4 py-2 text-gray-600 dark:text-gray-300"><?php echo (int) $import['rows_count']; ?></td>
						<td class="px-4 py-2"><span class="n2j-badge <?php echo (string) $import['status'] === 'done' ? 'n2j-badge-ok' : ((string) $import['status'] === 'error' ? 'n2j-badge-err' : 'n2j-badge-run'); ?>"><?php echo HTML::chars((string) $import['status']); ?></span></td>
						<td class="px-4 py-2 text-gray-500 dark:text-gray-400"><?php echo HTML::chars(Service_Nj670_Repo::date_text($import['created_at'])); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>

	<div class="mt-5 flex flex-wrap gap-2">
		<a href="/process/<?php echo HTML::chars($process['code']); ?>/audit" class="n2j-btn-process"><i class="fas fa-shield-halved mr-2"></i>Сравнить с планом</a>
		<a href="/process/<?php echo HTML::chars($process['code']); ?>" class="n2j-btn-secondary">Вернуться на холст</a>
	</div>
</div>

<script>
(function () {
	var btn = document.getElementById('nj670-sample');
	if (!btn) { return; }
	btn.addEventListener('click', function () {
		var area = document.querySelector('textarea[name="journal"]');
		if (!area) { return; }
		var rows = [['Case_ID', 'Activity', 'Timestamp', 'Resource']];
		var steps = [
			['Создание заявки в 1С', 'Инициатор', 0],
			['Согласование руководителя', 'Руководитель подразделения', 180],
			['Пересылка документов в Telegram', 'Инициатор', 45],
			['Проверка юриста', 'Юрист', 600],
			['Возврат на доработку', 'Юрист', 240],
			['Повторная проверка юриста', 'Юрист', 480],
			['Проверка СБ', 'Служба безопасности', 2880],
			['Ручное выбивание счёта', 'Инициатор', 300],
			['Оплата счёта', 'Бухгалтерия', 240]
		];
		var base = new Date('2026-09-01T09:00:00');
		for (var c = 1; c <= 6; c++) {
			var t = base.getTime() + c * 3600000;
			for (var i = 0; i < steps.length; i++) {
				if (c === 2 || c === 4) {
					if (i === 3) { continue; }
				}
				var d = new Date(t);
				var pad = function (n) { return String(n).padStart(2, '0'); };
				var stamp = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
				rows.push(['C-' + String(c).padStart(3, '0'), steps[i][0], stamp, steps[i][1]]);
				t += steps[i][2] * 60000;
			}
		}
		area.value = rows.map(function (r) { return r.join(';'); }).join('\n');
		area.focus();
	});
})();
</script>
