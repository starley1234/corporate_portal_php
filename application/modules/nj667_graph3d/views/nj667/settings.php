<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Настройки «Графового 3D-конструктора» (nj667): какую модель звать на сборку,
 * размер кадра для vision, RAG по умолчанию и файлы на диске: отчёт, очистка
 * по категориям, полная очистка с защитой опубликованных файлов, фильтр,
 * группировка и ручное удаление.
 *
 * Страница только для модератора (ACL nj667.moderate). Выбор модели пишется в
 * nj_667_settings и уезжает в задачу воркера полем model; если в воркере задан
 * NJ667_MODEL, он важнее этого выбора.
 */

$models        = isset($models) ? (array) $models : [];
$model         = isset($model) ? (string) $model : '';
$photo_max_px  = isset($photo_max_px) ? (int) $photo_max_px : 512;
$rag           = isset($rag) ? (bool) $rag : TRUE;
$error         = isset($error) ? (string) $error : '';
$csrf          = isset($csrf) ? (string) $csrf : '';
$storage       = isset($storage) && is_array($storage) ? $storage : NULL;
$cleaned       = isset($cleaned) && is_array($cleaned) ? $cleaned : NULL;
$deleted       = isset($deleted) && is_array($deleted) ? $deleted : NULL;
$purged        = isset($purged) && is_array($purged) ? $purged : NULL;
$cfg_storage   = $storage ? (array) Arr::get($storage, 'cfg', []) : [];
$saved         = ! empty($saved);

$known = FALSE;

foreach ($models as $row)
{
	if ((string) Arr::get($row, 'spec') === $model)
	{
		$known = TRUE;
		break;
	}
}

$kind_titles = ['photo' => 'кадр', 'build' => 'сборка', 'part' => 'узел', 'other' => 'файл'];

$cat_badges = [
	'keep'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300',
	'stale'   => 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
	'orphan'  => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
	'deleted' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
	'other'   => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
	'published' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
];

$sort_now = $storage ? (string) Arr::get($storage, 'sort', 'size_desc') : 'size_desc';
// Опубликованные сборки скрыты по умолчанию; галочка показывает и разрешает их удалить.
$show_pub = $storage ? (bool) Arr::get($storage, 'show_published', FALSE) : FALSE;
$hidden   = $storage ? (array) Arr::get($storage, 'hidden', ['files' => 0, 'bytes' => 0]) : ['files' => 0, 'bytes' => 0];
$type_now      = $storage ? (string) Arr::get($storage, 'type', 'all') : 'all';
$type_options  = $storage ? (array) Arr::get($storage, 'types', ['all' => 'Все типы']) : ['all' => 'Все типы'];
$group_now     = $storage ? (string) Arr::get($storage, 'group_by', 'build') : 'build';
$group_default = ['build' => 'по сборке / проекту', 'type' => 'по типу файла', 'none' => 'без группировки'];
$group_options = $storage ? (array) Arr::get($storage, 'groups', $group_default) : $group_default;
$purge_default = ['files' => 0, 'bytes' => 0, 'kept_files' => 0, 'kept_bytes' => 0];
$purge_stats   = $storage ? (array) Arr::get($storage, 'purge', $purge_default) : $purge_default;
$files_matching = $storage ? (int) Arr::get($storage, 'files_matching', Arr::get($storage, 'files_total', 0)) : 0;
$group_stats  = $storage ? (array) Arr::get($storage, 'group_stats', []) : [];
$shown_groups = [];

if ($storage)
{
	foreach ((array) Arr::get($storage, 'files_shown', []) as $sf)
	{
		$group_key = (string) Arr::get($sf, 'group_key', '');

		if ($group_key !== '')
		{
			$shown_groups[$group_key] = (int) Arr::get($shown_groups, $group_key, 0) + 1;
		}
	}
}
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-end justify-between gap-3">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-sliders mr-2 text-blue-500"></i>Настройки конструктора</h1>
			<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Модель сборки, кадр для vision, RAG и место на диске. Настройки видны только модератору конструктора. Проекты — в разделе <a href="/graph3d/projects" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Проекты</a>.</p>
		</div>
		<div class="flex flex-wrap gap-2">
			<a href="/graph3d/limits" class="n2j-btn-secondary"><i class="fas fa-gauge-high text-blue-500"></i>Лимиты пользователей</a>
			<a href="/graph3d/projects" class="n2j-btn-process">Проекты</a>
		</div>
	</div>

	<?php if ($saved): ?>
	<div class="n2j-flash n2j-flash-ok mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-check mt-0.5 shrink-0"></i>
		<span>Настройки сохранены: новая сборка пойдёт с этими значениями.</span>
	</div>
	<?php endif; ?>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-5 grid gap-5 xl:grid-cols-3">

		<div class="min-w-0 space-y-5 xl:col-span-2">

			<form method="post" action="/graph3d/settings" class="n2j-card">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

				<div class="grid gap-5 lg:grid-cols-2 lg:gap-x-8">

					<div class="min-w-0">
						<fieldset>
							<legend class="text-sm font-semibold text-gray-900 dark:text-white">Модель генерации</legend>

							<label class="mt-3 flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
								<input type="radio" name="model" value="" <?php echo $model === '' ? 'checked' : ''; ?>
									class="h-4 w-4 text-blue-600 dark:dark:text-blue-400">
								<span>модель воркера по умолчанию</span>
							</label>

							<?php foreach ($models as $row): ?>
							<label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
								<input type="radio" name="model" value="<?php echo HTML::chars((string) Arr::get($row, 'spec')); ?>"
									<?php echo $model === (string) Arr::get($row, 'spec') ? 'checked' : ''; ?>
									class="h-4 w-4 text-blue-600 dark:dark:text-blue-400">
								<span><?php echo HTML::chars((string) Arr::get($row, 'label')); ?></span>
							</label>
							<?php endforeach; ?>
						</fieldset>

						<div class="mt-4">
							<label for="nj667-model-custom" class="n2j-label">Своя модель <span class="font-normal text-gray-400">(провайдер::имя — перекрывает список)</span></label>
							<input id="nj667-model-custom" name="model_custom" type="text" maxlength="160"
								value="<?php echo HTML::chars(($model !== '' AND ! $known) ? $model : ''); ?>"
								placeholder="local::qwen3-coder-30b-a3b-instruct"
								class="n2j-input mt-1 w-full">
						</div>
					</div>

					<div class="min-w-0 space-y-5">
						<fieldset>
							<legend class="text-sm font-semibold text-gray-900 dark:text-white">Кадр для модели</legend>
							<p class="n2j-hint">Если фото больше — уменьшаем по длинной стороне. По умолчанию 512 px: vision отвечает быстрее и дешевле. На диск кадр пишется до 1280 px.</p>
							<label for="nj667-photo-max" class="n2j-label mt-3">Длинная сторона, px <span class="font-normal text-gray-400">(128–2048)</span></label>
							<input id="nj667-photo-max" name="photo_max_px" type="number" min="128" max="2048" step="64"
								value="<?php echo (int) $photo_max_px; ?>"
								class="n2j-input mt-1 w-40">
						</fieldset>

						<fieldset class="border-t border-gray-100 pt-5 dark:border-gray-800">
							<legend class="text-sm font-semibold text-gray-900 dark:text-white">RAG</legend>
							<p class="n2j-hint">Подмешивать похожие графы из каталога в подсказку при первой сборке пустого проекта. На странице сборки это можно выключить.</p>
							<input type="hidden" name="rag" value="0">
							<label class="mt-3 flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
								<input type="checkbox" name="rag" value="1" <?php echo $rag ? 'checked' : ''; ?>
									class="h-4 w-4 rounded text-blue-600 dark:dark:text-blue-400">
								<span>использовать RAG по умолчанию</span>
							</label>
						</fieldset>
					</div>
				</div>

				<button type="submit" class="n2j-btn-cta mt-5">Сохранить настройки</button>
			</form>

			<section id="nj667-storage" class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-hard-drive mr-2 text-blue-500"></i>Место на диске</h2>

				<?php if ($cleaned !== NULL): ?>
				<?php $left = (int) $cleaned['left']; ?>
				<div class="n2j-flash n2j-flash-ok mt-3 text-xs block">
					<span class="font-semibold">Очищено: <?php echo (int) $cleaned['removed']; ?> <?php echo $cleaned['removed'] === 1 ? 'файл' : 'файлов'; ?>, <?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($cleaned['bytes'])); ?>.</span>
					<?php if ($left > 0): ?>
					Осталось ещё <?php echo $left; ?> — нажмите кнопку снова.
					<?php else: ?>
					В этой категории больше нечего очищать.
					<?php endif; ?>
					<?php if ((int) $cleaned['failed'] > 0): ?>
					Не удалось удалить: <?php echo (int) $cleaned['failed']; ?> (нет прав или файл занят).
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ($purged !== NULL): ?>
				<div class="n2j-flash n2j-flash-ok mt-3 text-xs block">
					<span class="font-semibold">Полная очистка завершена: удалено <?php echo (int) $purged['removed']; ?> файлов (<?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($purged['bytes'])); ?>).</span>
					Сохранено опубликованных файлов: <?php echo (int) $purged['kept']; ?> (<?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($purged['kept_bytes'])); ?>).
					<?php if ((int) $purged['left'] > 0): ?>Осталось проверить/удалить: <?php echo (int) $purged['left']; ?>.<?php endif; ?>
					<?php if ((int) $purged['failed'] > 0): ?>Не удалось удалить: <?php echo (int) $purged['failed']; ?>.<?php endif; ?>
					<?php if ((int) $purged['missing'] > 0): ?>Уже отсутствовало: <?php echo (int) $purged['missing']; ?>.<?php endif; ?>
				</div>
				<?php endif; ?>

				<?php if ($storage === NULL OR ! $storage['root_ok']): ?>
				<p class="n2j-hint mt-3">Каталога с файлами модуля пока нет: записывать пока нечего.</p>
				<?php else: ?>

				<div class="mt-4 grid gap-3 sm:grid-cols-2">
					<div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800/60">
						<div class="text-xs text-gray-500 dark:text-gray-400">Занято файлами</div>
						<div class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($storage['bytes'])); ?></div>
						<div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">в <?php echo (int) $storage['files']; ?> <?php echo $storage['files'] === 1 ? 'файле' : 'файлах'; ?></div>
					</div>
					<?php if ($storage['disk'] !== NULL): ?>
					<div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-800/60">
						<div class="text-xs text-gray-500 dark:text-gray-400">Свободно на диске</div>
						<div class="mt-1 text-xl font-semibold tabular-nums text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($storage['disk']['free'])); ?></div>
						<div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">из <?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($storage['disk']['total'])); ?></div>
					</div>
					<?php endif; ?>
				</div>

				<div class="mt-4 rounded-xl border border-rose-200 bg-rose-50/70 p-4 dark:border-rose-900 dark:bg-rose-950/20">
					<h3 class="text-sm font-semibold text-rose-900 dark:text-rose-200">Полная очистка, кроме опубликованного</h3>
					<p class="mt-1 text-xs leading-relaxed text-rose-800 dark:text-rose-300">Будет удалено <?php echo (int) Arr::get($purge_stats, 'files', 0); ?> файлов (<?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes(Arr::get($purge_stats, 'bytes', 0))); ?>). Останутся только опубликованные GLB сборок, PNG-превью сборок, STL деталей и PNG-превью деталей. Фото, черновики, SCAD, STL сборок и неизвестные файлы будут удалены; восстановить их нельзя.</p>
					<form method="post" action="/graph3d/settings" class="mt-3">
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<input type="hidden" name="storage_purge" value="1">
						<button data-n2j-confirm="Удалятся все файлы, кроме опубликованного." data-n2j-title="Полная очистка?" data-n2j-ok="Очистить" type="submit" <?php echo (int) Arr::get($purge_stats, 'files', 0) > 0 ? '' : 'disabled'; ?> class="n2j-btn-danger">Очистить всё, кроме опубликованных</button>
					</form>
					<p class="mt-2 text-xs text-rose-800 dark:text-rose-300">В защищённом списке останется <?php echo (int) Arr::get($purge_stats, 'kept_files', 0); ?> файлов (<?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes(Arr::get($purge_stats, 'kept_bytes', 0))); ?>).</p>
				</div>

				<h3 class="mt-6 text-xs font-semibold text-gray-700 dark:text-gray-300">Очистка по категориям</h3>
				<p class="n2j-hint">Порция — до <?php echo (int) Arr::get($cfg_storage, 'batch', 500); ?> файлов, крупные первыми. Первое нажатие взводит кнопку, второе выполняет.</p>

				<div class="mt-3 overflow-x-auto">
					<table class="n2j-table min-w-full">
						<thead>
							<tr class="text-gray-500 dark:text-gray-400">
								<th class="py-2 pr-3 font-semibold">Что</th>
								<th class="py-2 pr-3 text-right font-semibold">Файлов</th>
								<th class="py-2 pr-3 text-right font-semibold">Занято</th>
								<th class="py-2 font-semibold"><span class="sr-only">Действие</span></th>
							</tr>
						</thead>
						<tbody class="text-gray-700 dark:text-gray-300">
							<?php foreach ($storage['rows'] as $row): ?>
							<tr class="border-t border-gray-100 dark:border-gray-800">
								<td class="py-2 pr-3 align-top">
									<div class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($row['title']); ?></div>
									<div class="mt-0.5 text-gray-500 dark:text-gray-400"><?php echo HTML::chars($row['rule']); ?></div>
								</td>
								<td class="py-2 pr-3 text-right align-top tabular-nums"><?php echo (int) $row['files']; ?></td>
								<td class="py-2 pr-3 text-right align-top tabular-nums whitespace-nowrap"><?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($row['bytes'])); ?></td>
								<td class="py-2 text-right align-top">
									<?php if ($row['cleanable'] AND $row['files'] > 0): ?>
									<form method="post" action="/graph3d/settings" class="inline-block">
										<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
										<input type="hidden" name="storage_clean" value="<?php echo HTML::chars($row['key']); ?>">
										<button data-n2j-arm="Очистить?" type="submit" class="n2j-btn-danger n2j-btn-sm whitespace-nowrap">Очистить порцией</button>
									</form>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</section>

		</div>

		<div class="min-w-0 space-y-5">
			<div class="n2j-card text-xs text-gray-600 dark:text-gray-300 sm:text-sm">
				<h2 class="font-semibold text-gray-900 dark:text-white">Что удаляется порцией</h2>
				<ul class="mt-2 list-disc space-y-1.5 pl-4">
					<li><span class="font-semibold">Устаревшие сборки</span> — не последняя и не последняя готовая сборка, старше <?php echo (int) Arr::get($cfg_storage, 'stale_days', 30); ?> дн. Сборка остаётся в списке со статусом «спецификация».</li>
					<li><span class="font-semibold">Сироты</span> — файлы без строки в базе, старше <?php echo (int) round(((int) Arr::get($cfg_storage, 'orphan_min_age', 3600)) / 60); ?> мин.</li>
					<li><span class="font-semibold">Удалённые проекты</span> — файлы мягко удалённых проектов. Восстановить их нельзя.</li>
				</ul>
				<h2 class="mt-4 font-semibold text-gray-900 dark:text-white">Файлы проектов — вручную</h2>
				<p class="mt-2">Фильтр по расширению, группировка по сборке и групповые отметки помогают выбрать, например, STL одной или нескольких сборок. Полная очистка пропускает только опубликованный GLB сборки, его PNG-превью, STL деталей и PNG-превью деталей. Ручное удаление необратимо; если удалить исходные файлы, проект или сборку придётся восстановить заново.</p>
			</div>

			<?php if ($storage !== NULL AND $storage['root_ok'] AND $storage['top']): ?>
			<section class="n2j-card">
				<h2 class="n2j-h3">Самые тяжёлые проекты</h2>
				<ul class="mt-2 divide-y divide-gray-100 text-xs text-gray-700 dark:divide-gray-800 dark:text-gray-300">
					<?php foreach ($storage['top'] as $t): ?>
					<li class="py-2">
						<div class="flex flex-wrap items-baseline justify-between gap-x-2">
							<span class="font-mono text-gray-500 dark:text-gray-400"><?php echo HTML::chars($t['code']); ?></span>
							<?php if ($t['deleted']): ?>
							<span class="n2j-badge n2j-badge-err">удалён</span>
							<?php endif; ?>
							<span class="ml-auto tabular-nums whitespace-nowrap font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($t['bytes'])); ?></span>
						</div>
						<div class="truncate"><?php echo $t['known'] ? HTML::chars($t['title']) : 'каталог без проекта'; ?></div>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endif; ?>

			<div class="n2j-card text-xs text-gray-600 dark:text-gray-300 sm:text-sm">
				<h2 class="font-semibold text-gray-900 dark:text-white">Как нажимать</h2>
				<p class="mt-2">Первое нажатие взводит кнопку, второе выполняет. Если второе нажатие не последовало в течение 4 секунд, кнопка сбрасывается. Изменение отметок тоже сбрасывает взвод.</p>
				<p class="mt-2">Пороги: <code>NJ667_STORAGE_STALE_DAYS</code>, <code>NJ667_STORAGE_BATCH</code>, <code>NJ667_STORAGE_ORPHAN_MIN_AGE</code>.</p>
			</div>
		</div>
	</div>

	<?php if ($storage !== NULL AND $storage['root_ok']): ?>
	<section id="nj667-files" class="n2j-card mt-5">

		<div class="flex flex-wrap items-end justify-between gap-4">
			<div class="min-w-0">
				<h2 class="n2j-h3"><i class="fas fa-folder-open mr-2 text-blue-500"></i>Файлы проектов</h2>
				<p class="n2j-hint">
					<?php if ($files_matching > count($storage['files_shown'])): ?>
					Показаны первые <?php echo count($storage['files_shown']); ?> из <?php echo $files_matching; ?> подходящих файлов.
					<?php else: ?>
					Подходящих файлов: <?php echo $files_matching; ?>.
					<?php endif; ?>
					Отметьте отдельные строки или целую группу и удалите (до 500 за запрос).
				</p>
			</div>

			<form method="get" action="/graph3d/settings" class="flex flex-wrap items-end gap-2">
				<label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-xs font-semibold text-gray-700 dark:text-gray-300">
					<input type="checkbox" id="nj667-published" name="published" value="1" <?php echo $show_pub ? 'checked' : ''; ?> class="h-4 w-4 rounded text-blue-600 dark:dark:text-blue-400">
					Показать опубликованные
				</label>
				<div>
					<label for="nj667-type" class="n2j-label">Тип файла</label>
					<select id="nj667-type" name="type" class="n2j-input n2j-select mt-1 w-auto">
						<?php foreach ($type_options as $key => $label): ?>
						<option value="<?php echo HTML::chars($key); ?>" <?php echo $key === $type_now ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label for="nj667-group" class="n2j-label">Группировать</label>
					<select id="nj667-group" name="group_by" class="n2j-input n2j-select mt-1 w-auto">
						<?php foreach ($group_options as $key => $label): ?>
						<option value="<?php echo HTML::chars($key); ?>" <?php echo $key === $group_now ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label for="nj667-sort" class="n2j-label">Сортировка</label>
					<select id="nj667-sort" name="sort"
						class="n2j-input n2j-select mt-1 w-auto">
						<?php foreach ($storage['sorts'] as $key => $label): ?>
						<option value="<?php echo HTML::chars($key); ?>" <?php echo $key === $sort_now ? 'selected' : ''; ?>><?php echo HTML::chars($label); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<button type="submit" class="n2j-btn-secondary">Показать</button>
			</form>
		</div>

		<?php if ( ! $show_pub AND (int) $hidden['files'] > 0): ?>
		<p class="n2j-flash n2j-flash-info mt-4 text-xs block">
			<i class="fas fa-eye-slash mr-1.5"></i>Скрыто опубликованных файлов: <?php echo (int) $hidden['files']; ?> (<?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($hidden['bytes'])); ?>). Галочка «Показать опубликованные» вернёт их в список.
		</p>
		<?php endif; ?>

		<?php if ($deleted !== NULL): ?>
		<div class="n2j-flash n2j-flash-ok mt-4 text-xs block">
			<span class="font-semibold">Удалено: <?php echo (int) $deleted['removed']; ?>, <?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($deleted['bytes'])); ?>.</span>
			<?php if ((int) $deleted['missing'] > 0): ?>
			Уже не было на диске: <?php echo (int) $deleted['missing']; ?>.
			<?php endif; ?>
			<?php if ((int) $deleted['failed'] > 0): ?>
			Не удалось удалить: <?php echo (int) $deleted['failed']; ?> (нет прав или файл занят).
			<?php endif; ?>
			<?php if ((int) Arr::get($deleted, 'protected', 0) > 0): ?>
			Пропущено опубликованных: <?php echo (int) Arr::get($deleted, 'protected', 0); ?> — они удаляются только с показанной галочкой.
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<form id="nj667-files-form" method="post" action="/graph3d/settings" class="mt-4">
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
			<input type="hidden" name="storage_delete" value="1">
			<input type="hidden" name="sort" value="<?php echo HTML::chars($sort_now); ?>">
			<input type="hidden" name="type" value="<?php echo HTML::chars($type_now); ?>">
			<input type="hidden" name="group_by" value="<?php echo HTML::chars($group_now); ?>">
			<?php if ($show_pub): ?>
			<input type="hidden" name="published" value="1">
			<?php endif; ?>

			<?php if (! $storage['files_shown']): ?>
			<p class="n2j-hint py-6 text-center"><?php echo (int) $storage['files'] > 0 ? 'По выбранным фильтрам файлов нет.' : 'В каталоге пока нет файлов.'; ?></p>
			<?php else: ?>
			<div class="overflow-x-auto">
				<table class="n2j-table min-w-full">
					<thead>
						<tr class="text-gray-500 dark:text-gray-400">
							<th class="py-2 pr-3 font-semibold"><label class="flex min-h-[44px] w-8 cursor-pointer items-center"><input type="checkbox" data-nj667-all aria-label="Отметить все показанные" class="h-4 w-4 rounded text-blue-600 dark:dark:text-blue-400"></label></th>
							<th class="py-2 pr-3 font-semibold">Файл</th>
							<th class="py-2 pr-3 font-semibold">Проект</th>
							<th class="py-2 pr-3 text-right font-semibold">Размер</th>
							<th class="py-2 pr-3 font-semibold whitespace-nowrap">Изменён</th>
							<th class="py-2 font-semibold">Статус</th>
						</tr>
					</thead>
					<tbody class="text-gray-700 dark:text-gray-300">
						<?php $last_group = NULL; foreach ($storage['files_shown'] as $f): ?>
						<?php $group_key = (string) Arr::get($f, 'group_key', 'all'); ?>
						<?php if ($group_now !== 'none' AND $group_key !== $last_group): ?>
						<?php $group_row = (array) Arr::get($group_stats, $group_key, []); $visible_in_group = (int) Arr::get($shown_groups, $group_key, 0); ?>
						<tr class="border-t border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/70">
							<td colspan="6" class="px-2 py-2">
								<div class="flex flex-wrap items-center gap-x-3 gap-y-1">
									<label class="flex min-h-[36px] cursor-pointer items-center gap-2 text-xs font-semibold text-blue-700 dark:text-blue-300">
										<input type="checkbox" data-nj667-group-all value="<?php echo HTML::chars($group_key); ?>" aria-label="Отметить группу <?php echo HTML::chars((string) Arr::get($f, 'group_title', '')); ?>" class="h-4 w-4 rounded text-blue-600 dark:dark:text-blue-400">
										Выбрать группу
									</label>
									<span class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($f, 'group_title', 'Группа файлов')); ?></span>
									<span class="text-xs text-gray-500 dark:text-gray-400"><?php echo (int) Arr::get($group_row, 'files', $visible_in_group); ?> файлов · <?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes(Arr::get($group_row, 'bytes', 0))); ?><?php if ((int) Arr::get($group_row, 'files', $visible_in_group) > $visible_in_group): ?> (на странице <?php echo $visible_in_group; ?>)<?php endif; ?></span>
								</div>
							</td>
						</tr>
						<?php $last_group = $group_key; endif; ?>
						<tr class="border-t border-gray-100 align-top dark:border-gray-800">
							<td class="py-2 pr-3">
								<label class="flex min-h-[44px] w-8 cursor-pointer items-center">
									<input type="checkbox" name="files[]" value="<?php echo HTML::chars($f['rel']); ?>" data-nj667-file data-nj667-group="<?php echo HTML::chars($group_key); ?>" aria-label="Отметить <?php echo HTML::chars($f['name']); ?>" class="h-4 w-4 rounded text-blue-600 dark:dark:text-blue-400">
								</label>
							</td>
							<td class="py-2 pr-3">
								<div class="break-all font-mono text-gray-900 dark:text-white"><?php echo HTML::chars($f['name']); ?></div>
								<div class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars(Arr::get($kind_titles, $f['kind'], 'файл')); ?><?php echo (string) Arr::get($f, 'type_title', '') !== '' ? ' · '.HTML::chars((string) Arr::get($f, 'type_title')) : ''; ?></div>
							</td>
							<td class="py-2 pr-3">
								<div class="font-mono text-gray-500 dark:text-gray-400"><?php echo HTML::chars($f['code']); ?></div>
								<div class="min-w-0"><?php echo $f['known'] ? HTML::chars($f['title']) : 'каталог без проекта'; ?></div>
							</td>
							<td class="py-2 pr-3 text-right tabular-nums whitespace-nowrap font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj667_Storage::fmt_bytes($f['bytes'])); ?></td>
							<td class="py-2 pr-3 tabular-nums whitespace-nowrap text-gray-500 dark:text-gray-400"><?php echo $f['mtime'] > 0 ? HTML::chars(Date::formatted_time('@'.(int) $f['mtime'], 'd.m.y H:i')) : '—'; ?></td>
							<td class="py-2">
								<span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap <?php echo Arr::get($cat_badges, $f['cat'], $cat_badges['other']); ?>"><?php echo HTML::chars($f['cat_title']); ?></span>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<div class="mt-4 flex flex-wrap items-center gap-3">
				<button data-n2j-arm="Удалить выбранные?" type="submit" class="n2j-btn-danger">Удалить выбранные</button>
				<span data-nj667-count class="text-xs text-gray-500 dark:text-gray-400">Выбрано: 0</span>
			</div>
		</form>
	</section>
	<?php endif; ?>

</div>

<script>
(function () {

	// Список файлов: отметки, «все», счётчик и кнопка удаления
	var form = document.getElementById('nj667-files-form');
	var all = document.querySelector('input[data-nj667-all]');
	var boxes = Array.prototype.slice.call(document.querySelectorAll('input[data-nj667-file]'));
	var groupAll = Array.prototype.slice.call(document.querySelectorAll('input[data-nj667-group-all]'));
	var count = document.querySelector('[data-nj667-count]');
	var btn = form ? form.querySelector('button[type="submit"]') : null;

	// Сменились отметки — взвод «Удалить выбранные» снимаем (data-n2j-arm, app.js):
	// подтверждать надо уже новый набор файлов.
	function unarm() {
		if (btn && window.N2J) { window.N2J.disarm(btn); }
	}

	function boxesInGroup(group) {
		return boxes.filter(function (box) { return box.getAttribute('data-nj667-group') === group; });
	}

	function refresh() {
		var n = boxes.filter(function (b) { return b.checked; }).length;
		if (count) { count.textContent = 'Выбрано: ' + n; }
		if (btn) {
			btn.disabled = n === 0;
			btn.textContent = n ? 'Удалить выбранные (' + n + ')' : 'Удалить выбранные';
		}
		if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
		groupAll.forEach(function (groupBox) {
			var groupBoxes = boxesInGroup(groupBox.value);
			var checked = groupBoxes.filter(function (box) { return box.checked; }).length;
			groupBox.checked = groupBoxes.length > 0 && checked === groupBoxes.length;
			groupBox.indeterminate = checked > 0 && checked < groupBoxes.length;
		});
	}

	boxes.forEach(function (b) {
		b.addEventListener('change', function () { unarm(); refresh(); });
	});

	if (all) {
		all.addEventListener('change', function () {
			boxes.forEach(function (b) { b.checked = all.checked; });
			unarm();
			refresh();
		});
	}

	groupAll.forEach(function (groupBox) {
		groupBox.addEventListener('change', function () {
			boxesInGroup(groupBox.value).forEach(function (box) { box.checked = groupBox.checked; });
			unarm();
			refresh();
		});
	});

	['nj667-sort', 'nj667-type', 'nj667-group'].forEach(function (id) {
		var select = document.getElementById(id);
		if (select) { select.addEventListener('change', function () { select.form.submit(); }); }
	});

	var pub = document.getElementById('nj667-published');
	if (pub) {
		pub.addEventListener('change', function () { pub.form.submit(); });
	}

	refresh();
})();
</script>
