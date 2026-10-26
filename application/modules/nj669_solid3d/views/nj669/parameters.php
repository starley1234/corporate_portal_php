<?php defined('SYSPATH') or die('No direct script access.');
// Размеры: карточка и сетка 2 колонки по образцу «Из чего печатаем» nj667 build.php.
// Два режима. parameters — владелец меняет черновую версию: JSON у неё же, новая версия не появляется.
// export — любой вошедший собирает временный STL: версия, каталог и история не меняются, файл живёт час.
$ui = [
	'card' => 'nj667-card rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900',
	'h2' => 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400',
	'lab' => 'block min-w-0 truncate text-xs font-semibold text-gray-700 dark:text-gray-200',
	'muted' => 'text-xs text-gray-500 dark:text-gray-400',
	'alert' => 'rounded-lg border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
	'info' => 'rounded-lg border border-blue-200 bg-blue-50 p-2.5 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200',
	'bad' => 'rounded-lg border border-rose-300 bg-rose-50 p-2.5 text-xs text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200',
	'in' => 'w-full rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:opacity-60 dark:border-gray-700 dark:bg-gray-800 dark:text-white',
	'btn' => 'n2j-btn-secondary n2j-btn-sm',
	'slot' => 'n2j-btn-secondary n2j-btn-sm',
	'primary' => 'n2j-btn-process n2j-btn-sm',
];
// Число без хвостовых нулей: 80.000 → 80, 2.5 → 2.5. Так же записываются значения в data-атрибутах.
$fmt = function ($v) { return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.'); };
$as_string = function ($field, $v) use ($fmt) {
	if ($field['type'] === 'bool') { return $v ? '1' : '0'; }
	if ($field['type'] === 'text') { return (string) $v; }
	return $fmt($v);
};
// Режим export: сборка временного STL по версии, которая не меняется (БД и каталог не трогаются).
// Гостю и во время своей сборки поля закрыты. Режим parameters сейчас не выводится (ползунки всегда export).
$export_mode = ($mode === 'export');
$locked = $export_mode ? ($guest OR $export_busy) : (bool) $busy;
$action = ($public ? '/solid3d/c/' : '/solid3d/p/').HTML::chars($p['code']);
$is_true = function ($v) { return in_array($v, [TRUE, 't', 'true', 1, '1'], TRUE); };
$export_alive = $export !== NULL && $is_true($export['alive'] ?? NULL);
$export_until = $export_alive ? (new DateTime((string) $export['expires_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('H:i') : '';
?>
<form method="post" action="<?php echo $action; ?>" class="<?php echo $ui['card']; ?>" data-solid-form data-solid-params data-param-mode="<?php echo $export_mode ? 'export' : 'parameters'; ?>" data-slots-key="nj669-slots:<?php echo HTML::chars($p['code']); ?>" autocomplete="off" data-n2j-no-remember>
	<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
	<input type="hidden" name="op" value="<?php echo $export_mode ? 'export' : 'parameters'; ?>">
	<input type="hidden" name="revision" value="<?php echo (int) $revision['id']; ?>">
	<fieldset class="contents" data-lock-panel <?php echo $locked ? 'disabled' : ''; ?>>
		<div class="flex items-baseline justify-between gap-2">
			<h2 class="<?php echo $ui['h2']; ?>"><i class="fas fa-sliders mr-1.5 text-blue-500"></i>Размеры</h2>
			<span class="<?php echo $ui['muted']; ?>">версия №<?php echo (int) $revision['id']; ?></span>
		</div>
		<?php if ($export_mode): ?>
			<?php if ($guest): ?>
			<p class="mt-2 <?php echo $ui['alert']; ?>">Войдите в аккаунт, чтобы собрать STL под свои размеры. Опубликованный STL скачивается и без входа.</p>
			<?php else: ?>
			<p class="mt-2 <?php echo $ui['muted']; ?>">Версия не меняется. Файл собирается отдельно, доступен час и квоту не тратит.</p>
			<?php endif; ?>
		<?php endif; ?>

		<?php /* Слоты живут в браузере: слот «по умолчанию» — значения из исходника версии. */ ?>
		<div class="mt-2 flex flex-wrap items-center gap-1" role="group" aria-label="Наборы значений">
			<button type="button" class="<?php echo $ui['slot']; ?>" data-slot-default title="Значения по умолчанию из исходника">По умолчанию</button>
			<?php for ($slot = 0; $slot < 3; $slot++): ?>
			<span class="inline-flex items-center" data-slot-group="<?php echo $slot; ?>">
				<button type="button" class="<?php echo $ui['slot']; ?> rounded-r-none" data-slot-load="<?php echo $slot; ?>" title="Слот <?php echo $slot + 1; ?>: загрузить сохранённые значения">
					<span data-slot-label><?php echo $slot + 1; ?></span>
				</button>
				<button type="button" class="<?php echo $ui['slot']; ?> -ml-px rounded-l-none px-1.5" data-slot-save="<?php echo $slot; ?>" title="Сохранить текущие значения в слот <?php echo $slot + 1; ?>" aria-label="Сохранить в слот <?php echo $slot + 1; ?>"><i class="fas fa-floppy-disk" aria-hidden="true"></i></button>
			</span>
			<?php endfor; ?>
			<span class="ml-auto text-xs text-gray-400 dark:text-gray-500">в этом браузере</span>
		</div>

		<div class="mt-2 grid grid-cols-2 gap-2">
			<?php foreach ($parameters as $name => $field): $id = 'param-'.HTML::chars($name); $initial = $as_string($field, $field['value']); $default = $as_string($field, $field['default'] ?? $field['value']); ?>
			<div class="min-w-0 rounded-lg border border-gray-200 p-2 dark:border-gray-700 <?php echo $field['type'] === 'number' ? 'col-span-2' : ''; ?>" data-param-field data-param-type="<?php echo $field['type']; ?>" data-param-name="<?php echo HTML::chars($name); ?>" data-default="<?php echo HTML::chars($default); ?>" data-initial="<?php echo HTML::chars($initial); ?>">
				<div class="flex items-center justify-between gap-2">
					<label for="<?php echo $id; ?>" class="<?php echo $ui['lab']; ?>" title="<?php echo HTML::chars($field['label']); ?>"><?php echo HTML::chars($field['label']); ?></label>
					<?php if ($field['type'] === 'number'): ?>
					<input type="number" id="<?php echo $id; ?>-num" data-param-number min="<?php echo $fmt($field['min']); ?>" max="<?php echo $fmt($field['max']); ?>" step="<?php echo $fmt($field['step']); ?>" value="<?php echo $fmt($field['value']); ?>" autocomplete="off" aria-label="<?php echo HTML::chars($field['label']); ?>, точное значение" class="<?php echo $ui['in']; ?> w-24 text-right font-mono tabular-nums">
					<?php elseif ($field['type'] === 'bool'): ?>
					<input type="hidden" name="params[<?php echo HTML::chars($name); ?>]" value="0">
					<input id="<?php echo $id; ?>" type="checkbox" name="params[<?php echo HTML::chars($name); ?>]" value="1" data-param-input <?php echo $field['value'] ? 'checked' : ''; ?> autocomplete="off" class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 disabled:cursor-not-allowed">
					<?php endif; ?>
				</div>

				<?php if ($field['type'] === 'number'): ?>
				<input id="<?php echo $id; ?>" type="range" name="params[<?php echo HTML::chars($name); ?>]" data-param-input
					min="<?php echo $fmt($field['min']); ?>" max="<?php echo $fmt($field['max']); ?>" step="<?php echo $fmt($field['step']); ?>"
					value="<?php echo $fmt($field['value']); ?>" autocomplete="off"
					class="mt-1.5 h-1.5 w-full cursor-pointer appearance-none rounded-full bg-gray-200 accent-blue-600 disabled:cursor-not-allowed dark:bg-gray-700">
				<div class="mt-0.5 flex justify-between font-mono text-xs text-gray-400 dark:text-gray-500"><span><?php echo $fmt($field['min']); ?></span><span><?php echo $fmt($field['max']); ?></span></div>
				<?php elseif ($field['type'] === 'choice'): ?>
				<select id="<?php echo $id; ?>" name="params[<?php echo HTML::chars($name); ?>]" data-param-input autocomplete="off" class="<?php echo $ui['in']; ?> mt-1.5">
					<?php foreach ($field['options'] as $option): ?>
					<option value="<?php echo $fmt($option['value']); ?>" <?php echo (float) $option['value'] === (float) $field['value'] ? 'selected' : ''; ?>><?php echo HTML::chars($option['label']); ?></option>
					<?php endforeach; ?>
				</select>
				<?php elseif ($field['type'] === 'text'): ?>
				<input id="<?php echo $id; ?>" type="text" name="params[<?php echo HTML::chars($name); ?>]" data-param-input maxlength="<?php echo (int) $field['maxlength']; ?>" value="<?php echo HTML::chars($field['value']); ?>" autocomplete="off" data-n2j-no-remember class="<?php echo $ui['in']; ?> mt-1.5">
				<?php endif; ?>
			</div>
			<?php endforeach; ?>
		</div>

		<?php if ($export_mode AND $export): ?>
		<?php /* Статус последней сборки этого пользователя; опрос страницы обновит его сам. */ ?>
		<div class="mt-3" role="status" aria-live="polite">
			<?php if (in_array($export['status'], ['queued', 'running'], TRUE)): ?>
			<p class="<?php echo $ui['info']; ?>"><i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i>STL собирается с этими размерами. Страница обновится сама.</p>
			<?php elseif ($export['status'] === 'done' AND $export_alive): ?>
			<a class="<?php echo $ui['primary']; ?> w-full" href="/solid3d/x/<?php echo HTML::chars($p['code']); ?>/<?php echo (int) $export['id']; ?>" download><i class="fas fa-download" aria-hidden="true"></i> Скачать STL</a>
			<p class="mt-1 <?php echo $ui['muted']; ?>">Доступен до <?php echo HTML::chars($export_until); ?> по Москве. Потом файл удаляется.</p>
			<?php elseif ($export['status'] === 'error'): ?>
			<p class="<?php echo $ui['bad']; ?>"><?php echo HTML::chars($export['error'] !== '' ? $export['error'] : 'Не удалось собрать STL.'); ?></p>
			<?php if ($moderator AND (string) ($export['detail'] ?? '') !== ''): ?><pre class="mt-2 max-h-[160px] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-2 text-xs text-gray-700 dark:bg-gray-950 dark:text-gray-300"><?php echo HTML::chars($export['detail']); ?></pre><?php endif; ?>
			<?php else: ?>
			<p class="<?php echo $ui['muted']; ?>">Файл истёк. Соберите STL заново.</p>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<div class="mt-3 flex gap-2">
			<button type="button" class="<?php echo $ui['btn']; ?> flex-1" data-param-reset>Вернуть</button>
			<button type="submit" class="<?php echo $ui['primary']; ?> flex-[2]" data-param-apply<?php echo $export_mode ? '' : ' disabled'; ?>><?php echo $export_mode ? 'Собрать STL' : 'Пересчитать'; ?></button>
		</div>
		<p class="mt-2 <?php echo $ui['muted']; ?>" data-param-hint data-hint-changed="<?php echo $export_mode ? 'Изменено: {n}. Соберите STL: версия не меняется, файл доступен час.' : 'Изменено: {n}. Пересборка идёт по той же версии и квоту не тратит.'; ?>"><?php echo $export_mode ? 'Единицы — мм. STL с вашими размерами; версия и каталог не меняются.' : 'Единицы — мм. Пересборка идёт по той же версии и квоту не тратит.'; ?></p>
	</fieldset>
</form>
