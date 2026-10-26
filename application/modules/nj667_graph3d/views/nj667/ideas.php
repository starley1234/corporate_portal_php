<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Админка пресетов идей (nj667): тема → 30 описаний, удаление по одному.
 *
 * Страница только для админа (право nj667.moderate). Генерация — канал
 * nj667.ideas, без проекта. Владелец, 11.10.2026.
 */

$presets = isset($presets) ? (array) $presets : [];
$job     = isset($job) ? (array) $job : [];
$waiting = isset($waiting) ? (bool) $waiting : FALSE;
$error   = isset($error) ? (string) $error : '';
$csrf    = isset($csrf) ? (string) $csrf : '';
$theme   = isset($theme) ? (string) $theme : '';
$job_note = isset($job_note) ? (string) $job_note : '';
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-lightbulb mr-2 text-blue-500"></i>Идеи для сборки</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Тема уходит в модель — она придумывает 30 коротких описаний изделий. Три случайных показываются кнопками под полем «Что построить» на странице <a href="/graph3d/create" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Создать</a>. Лишние удаляйте иконкой (второе нажатие).</p>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<?php if ($waiting): ?>
	<div id="nj667-ideas-wait" class="n2j-flash n2j-flash-info mt-4 block">
		Модель придумывает 30 идей<?php echo $theme !== '' ? ' по теме «'.HTML::chars($theme).'»' : ''; ?>… страница обновится сама.
	</div>
	<?php elseif ($job_note !== ''): ?>
	<div class="n2j-flash n2j-flash-warn mt-4 block">
		<?php echo HTML::chars($job_note); ?>
	</div>
	<?php endif; ?>

	<form method="post" action="/graph3d/ideas" class="n2j-card mt-5 max-w-xl" autocomplete="off">
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
		<input type="hidden" name="generate" value="1">

		<label for="nj667-ideas-theme" class="n2j-label">Тема пачки</label>
		<input id="nj667-ideas-theme" name="theme" type="text" maxlength="200" required autocomplete="off"
			value="<?php echo HTML::chars($theme); ?>"
			placeholder="Например: дачная мебель, детский транспорт, кухонная утварь"
			class="n2j-input mt-2 w-full">
		<p class="n2j-hint mt-1.5">Чем конкретнее тема, тем разнообразнее описания. Каждая пачка добавляется к уже существующим.</p>

		<button type="submit" <?php echo $waiting ? 'disabled' : ''; ?>
			class="n2j-btn-process mt-4">
			Сгенерировать 30 идей
		</button>
	</form>

	<div class="mt-8">
		<h2 class="n2j-h3">В копилке: <?php echo count($presets); ?></h2>

		<?php if ( ! $presets): ?>
		<p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Пока пусто — сгенерируйте первую пачку. Пока копилка пуста, на «Создать» показываются три запасных идеи.</p>
		<?php else: ?>
		<ul class="mt-3 divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white dark:divide-gray-800 dark:border-gray-800 dark:bg-gray-900">
			<?php foreach ($presets as $row): ?>
			<li class="flex items-start gap-3 px-3 py-2.5 sm:px-4">
				<div class="min-w-0 flex-1">
					<p class="text-sm text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($row, 'body')); ?></p>
					<p class="n2j-hint mt-0.5">
						<?php echo HTML::chars((string) Arr::get($row, 'theme') ?: 'без темы'); ?>
						· <?php echo HTML::chars((string) Arr::get($row, 'created_at')); ?>
					</p>
				</div>
				<form method="post" action="/graph3d/ideas" class="shrink-0">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="delete" value="<?php echo (int) Arr::get($row, 'id'); ?>">
					<button data-n2j-arm="Удалить?" type="submit" title="Удалить" aria-label="Удалить"
						class="n2j-btn-icon-danger">
						<i class="fas fa-trash-alt text-xs" aria-hidden="true"></i>
					</button>
				</form>
			</li>
			<?php endforeach; ?>
		</ul>
		<?php endif; ?>
	</div>
</div>

<script>
(function () {
	if (document.getElementById('nj667-ideas-wait')) {
		setTimeout(function () { location.reload(); }, 4000);
	}

})();
</script>
