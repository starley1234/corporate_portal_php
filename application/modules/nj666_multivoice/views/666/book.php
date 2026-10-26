<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Рабочее место книги (nj666): персонажи с голосами и замочками, блоки с
 * плеером, живой статус и скачивание архива.
 *
 * Страница ничего не считает сама: разбор и озвучка идут в воркере, а здесь
 * только поллинг /multivoice/api/status раз в несколько секунд. Удаление
 * (книги и персонажа) подтверждается ВТОРЫМ нажатием на ту же кнопку —
 * без alert, так эргономичнее (владелец, 10.10.2026). Play/stop у голоса и
 * «удаление с переназначением» — 11.10.2026.
 */

$logged     = isset($logged) ? (bool) $logged : FALSE;
$can_run    = isset($can_run) ? (bool) $can_run : FALSE;
$enabled    = isset($enabled) ? (bool) $enabled : TRUE;
$book       = isset($book) ? (array) $book : [];
$characters = isset($characters) ? (array) $characters : [];
$blocks     = isset($blocks) ? (array) $blocks : [];
$progress   = isset($progress) ? (array) $progress : [];
$voices     = isset($voices) ? (array) $voices : [];
$genders    = isset($genders) ? (array) $genders : [];
$ages       = isset($ages) ? (array) $ages : [];
$modes      = isset($modes) ? (array) $modes : [];
$languages  = isset($languages) ? (array) $languages : [];
$defaults   = isset($defaults) ? (array) $defaults : [];
$limits     = isset($limits) ? (array) $limits : [];
$excerpt    = isset($excerpt) ? (string) $excerpt : '';
$rate_text  = isset($rate_text) ? (string) $rate_text : '';
$csrf       = isset($csrf) ? (string) $csrf : '';

$code    = (string) Arr::get($book, 'code');
$status  = (string) Arr::get($book, 'status', 'new');
$mode    = (string) Arr::get($book, 'mode', 'auto');
$speed   = (float) Arr::get($book, 'speed', 1.0);
$format  = (string) Arr::get($book, 'format', 'mp3');
$single  = (int) Arr::get($book, 'single_block', 0) === 1;
$error   = (string) Arr::get($book, 'error');

$total_blocks = (int) Arr::get($progress, 'total', 0);
$ready_blocks = (int) Arr::get($progress, 'ready', 0);
$percent      = (int) Arr::get($progress, 'percent', 0);

$statuses = [
	'new'      => ['Новая', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300', 'far fa-clock'],
	'planning' => ['Разбирается', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'fas fa-spinner fa-spin'],
	'planned'  => ['Разобрана', 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300', 'fas fa-list-check'],
	'voicing'  => ['Озвучивается', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'fas fa-spinner fa-spin'],
	'done'     => ['Готова', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', 'fas fa-circle-check'],
	'error'    => ['Ошибка', 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300', 'fas fa-circle-exclamation'],
];

$badge = Arr::get($statuses, $status, Arr::get($statuses, 'new'));

// Короткая подпись режима: описание из конфига — это абзац, в шапку не идёт.
$mode_short = $mode === 'search' ? '«утка го»' : 'автоматически';

$busy = in_array($status, ['planning', 'voicing'], TRUE);

$numbers = function ($value) { return number_format((int) $value, 0, ',', ' '); };

$time_of = function ($chars) use ($speed) {
	return Service_Nj666_Multivoice::human_time(
		Service_Nj666_Multivoice::estimate_seconds((int) $chars, $speed));
};
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" id="nj666-book" data-code="<?php echo HTML::chars($code); ?>">

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/" class="transition hover:text-blue-600 dark:hover:text-blue-400">Главная</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<a href="/multivoice" class="transition hover:text-blue-600 dark:hover:text-blue-400">Многоголосая озвучка</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-medium text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) Arr::get($book, 'title')); ?></span>
	</nav>

	<!-- ==================== Шапка книги ==================== -->
	<div class="n2j-card mt-3">

		<div class="flex flex-wrap items-start gap-3">
			<div class="min-w-0 flex-1">
				<h1 class="n2j-h1 truncate">
					<i class="fas fa-book-open mr-2 text-blue-500"></i><?php echo HTML::chars((string) Arr::get($book, 'title')); ?>
				</h1>
				<p class="n2j-hint flex flex-wrap items-center gap-x-3 gap-y-1">
					<?php if ((string) Arr::get($book, 'author', '') !== ''): ?>
					<span><i class="fas fa-user-pen mr-1 text-gray-300"></i><?php echo HTML::chars((string) Arr::get($book, 'author')); ?></span>
					<?php endif; ?>
					<span><i class="fas fa-text-width mr-1 text-gray-300"></i><?php echo $numbers(Arr::get($book, 'chars', 0)); ?> знаков</span>
					<span><i class="fas fa-clock mr-1 text-gray-300"></i>≈ <?php echo HTML::chars($time_of((int) Arr::get($book, 'chars', 0))); ?></span>
					<span><i class="fas fa-scissors mr-1 text-gray-300"></i><?php echo $single ? 'одним блоком' : 'блоки по '.(float) Arr::get($book, 'block_min_min', 7).'–'.(float) Arr::get($book, 'block_max_min', 15).' мин'; ?></span>
					<span><i class="fas fa-masks-theater mr-1 text-gray-300"></i><?php echo HTML::chars($mode_short); ?></span>
				</p>
			</div>

			<span id="nj666-badge" class="rounded-full px-2.5 py-1 text-xs font-semibold <?php echo HTML::chars((string) Arr::get($badge, 1)); ?>">
				<i id="nj666-badge-icon" class="<?php echo HTML::chars((string) Arr::get($badge, 2)); ?> mr-1"></i><span id="nj666-badge-text"><?php echo HTML::chars((string) Arr::get($badge, 0)); ?></span>
			</span>
		</div>

		<!-- Ход: этап («блок 2 из 3») и доля числом; полос хода нет (AGENTS.md п. 10) -->
		<div class="mt-4">
			<div class="flex items-center justify-between text-xs">
				<span id="nj666-stage" class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) Arr::get($book, 'stage', '')); ?></span>
				<span id="nj666-percent" class="font-bold text-blue-600 dark:text-blue-400"><?php echo (int) Arr::get($book, 'progress', 0); ?>%</span>
			</div>
			<p id="nj666-note" class="n2j-hint mt-1.5"><?php echo HTML::chars((string) Arr::get($book, 'stage_note', '')); ?></p>
		</div>

		<?php if ($error !== ''): ?>
		<div id="nj666-error" class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs">
			<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
			<span><?php echo HTML::chars($error); ?></span>
		</div>
		<?php else: ?>
		<div id="nj666-error" class="n2j-flash n2j-flash-err mt-4 hidden flex items-start gap-3 text-xs"></div>
		<?php endif; ?>

		<div class="mt-4 flex flex-wrap items-center gap-2">
			<button type="button" id="nj666-plan"<?php echo $can_run ? '' : ' disabled'; ?>
				class="n2j-btn-process">
				<i class="fas fa-list-check"></i><span><?php echo $total_blocks ? 'Разобрать заново' : 'Разобрать книгу'; ?></span>
			</button>

			<button type="button" id="nj666-voice"<?php echo ($can_run AND $total_blocks) ? '' : ' disabled'; ?>
				class="n2j-btn-cta">
				<i class="fas fa-microphone-lines"></i><span><?php echo $ready_blocks ? 'Озвучить заново' : 'Озвучить'; ?></span>
			</button>

			<a href="/multivoice/zip/<?php echo HTML::chars($code); ?>"
				class="n2j-btn-secondary">
				<i class="fas fa-file-zipper"></i><span>Скачать архив</span>
			</a>

			<button type="button" id="nj666-del-book" data-n2j-arm="Удалить книгу?"
				class="n2j-btn-icon-danger ml-auto"
				title="Удалить книгу" aria-label="Удалить книгу">
				<i class="fas fa-trash-alt" aria-hidden="true"></i>
			</button>
		</div>

		<p id="nj666-status" class="n2j-hint mt-2" role="status" aria-live="polite"></p>

		<?php if ($logged AND ! $can_run): ?>
		<p class="mt-1 text-xs text-amber-700 dark:text-amber-400"><i class="fas fa-lock mr-1"></i>Запуск разбора и озвучки доступен привилегированным аккаунтам (роль vip) и администраторам — книга это десятки вызовов модели и часы синтеза. Редактировать персонажей и слушать можно и без него.</p>
		<?php endif; ?>
	</div>

	<div class="mt-5 gap-5 space-y-5 lg:grid lg:grid-cols-5 lg:items-start lg:space-y-0">

		<!-- ==================== Персонажи ==================== -->
		<div class="lg:col-span-3">
			<div class="n2j-card p-0">
				<div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
					<h2 class="n2j-h3"><i class="fas fa-masks-theater mr-1.5 text-blue-500"></i>Персонажи и голоса</h2>
					<span id="nj666-char-count" class="n2j-badge n2j-badge-wait"><?php echo count($characters); ?></span>
					<span class="ml-auto text-xs text-gray-400">Замочек — не давать модели менять персонажа</span>
				</div>

				<?php if ( ! $characters): ?>
				<p class="n2j-hint px-4 py-6 text-center">Персонажи появятся после разбора. Можно добавить их и руками — ниже.</p>
				<?php else: ?>
				<!-- name/id уникальны на карточку: иначе Chrome копирует выбранный голос по всем спискам (владелец, 11.10.2026). -->
				<ul id="nj666-chars" class="divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($characters as $one): ?>
					<?php
						$id     = (int) Arr::get($one, 'id');
						$locked = (int) Arr::get($one, 'locked', 0) === 1;
						$voice  = (string) Arr::get($one, 'voice');
						$is_nar = (string) Arr::get($one, 'kind') === 'narrator';
					?>
					<li class="px-4 py-3" data-id="<?php echo $id; ?>">
						<div class="flex flex-wrap items-center gap-2">

							<button type="button" class="nj666-lock n2j-btn-icon h-8 w-8<?php echo $locked ? ' text-amber-600 dark:text-amber-400' : ''; ?>" aria-pressed="<?php echo $locked ? 'true' : 'false'; ?>"
								data-id="<?php echo $id; ?>" data-locked="<?php echo $locked ? 1 : 0; ?>"
								title="<?php echo $locked ? 'Замочек стоит: модель не меняет персонажа. Нажмите, чтобы снять.' : 'Поставить замочек: модель не будет менять этого персонажа.'; ?>">
								<i class="<?php echo $locked ? 'fas fa-lock' : 'fas fa-lock-open'; ?>"></i>
							</button>

							<input type="text" class="nj666-name min-w-0 flex-1 rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm font-semibold text-gray-900 hover:border-gray-200 focus:border-blue-500 focus:outline-none dark:text-white dark:hover:border-gray-700"
								id="nj666-c-name-<?php echo $id; ?>" name="nj666-c-name-<?php echo $id; ?>"
								form="nj666-nof-<?php echo $id; ?>"
								data-id="<?php echo $id; ?>" value="<?php echo HTML::chars((string) Arr::get($one, 'name')); ?>"
								maxlength="120" autocomplete="off" <?php echo $is_nar ? 'readonly' : ''; ?>>

							<?php if ($is_nar): ?>
							<span class="n2j-badge n2j-badge-info">рассказчик</span>
							<?php endif; ?>

							<span class="text-xs text-gray-400" title="сколько реплик у персонажа"><i class="fas fa-comment mr-1"></i><?php echo (int) Arr::get($one, 'mentions', 0); ?></span>

							<?php if ( ! $is_nar): ?>
							<button type="button" class="n2j-btn-secondary n2j-btn-sm nj666-del-char"
								data-id="<?php echo $id; ?>" data-n2j-arm="Удалить?" title="Удалить персонажа">
								<i class="fas fa-trash"></i>
							</button>
							<?php endif; ?>
						</div>

						<div class="mt-2 grid gap-2 sm:grid-cols-3">
							<select class="n2j-input n2j-select nj666-gender w-full py-1.5 text-xs min-h-0"
								id="nj666-c-gender-<?php echo $id; ?>" name="nj666-c-gender-<?php echo $id; ?>"
								form="nj666-nof-<?php echo $id; ?>"
								data-id="<?php echo $id; ?>" autocomplete="off">
								<?php foreach ($genders as $key => $title): ?>
								<option value="<?php echo HTML::chars((string) $key); ?>"<?php echo (string) Arr::get($one, 'gender', '') === (string) $key ? ' selected' : ''; ?>><?php echo HTML::chars((string) $title); ?></option>
								<?php endforeach; ?>
							</select>

							<select class="n2j-input n2j-select nj666-age w-full py-1.5 text-xs min-h-0"
								id="nj666-c-age-<?php echo $id; ?>" name="nj666-c-age-<?php echo $id; ?>"
								form="nj666-nof-<?php echo $id; ?>"
								data-id="<?php echo $id; ?>" autocomplete="off">
								<?php foreach ($ages as $key => $title): ?>
								<option value="<?php echo HTML::chars((string) $key); ?>"<?php echo (string) Arr::get($one, 'age', '') === (string) $key ? ' selected' : ''; ?>><?php echo HTML::chars((string) $title); ?></option>
								<?php endforeach; ?>
							</select>

							<div class="flex min-w-0 items-center gap-1">
								<select class="n2j-input n2j-select nj666-voice-sel min-w-0 flex-1 py-1.5 text-xs min-h-0"
									id="nj666-c-voice-<?php echo $id; ?>" name="nj666-c-voice-<?php echo $id; ?>"
									form="nj666-nof-<?php echo $id; ?>"
									data-id="<?php echo $id; ?>" autocomplete="off">
									<option value=""><?php echo $is_nar ? 'голос книги' : 'как у всех'; ?></option>
									<?php foreach ($voices as $v): ?>
									<option value="<?php echo HTML::chars((string) Arr::get($v, 'id')); ?>"<?php echo $voice === (string) Arr::get($v, 'id') ? ' selected' : ''; ?>><?php echo HTML::chars((string) Arr::get($v, 'id')); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="n2j-btn-icon nj666-voice-play shrink-0"
									data-id="<?php echo $id; ?>" title="Прослушать голос" aria-label="Прослушать голос">
									<i class="fas fa-play" aria-hidden="true"></i>
								</button>
							</div>
						</div>

						<?php if ( ! $is_nar): ?>
						<div class="mt-2 flex flex-wrap items-center gap-2">
							<span class="text-xs text-gray-400">Удаление с переназначением</span>
							<select class="n2j-input n2j-select nj666-remap-to min-w-0 flex-1 py-1.5 text-xs min-h-0"
								id="nj666-c-remap-<?php echo $id; ?>" name="nj666-c-remap-<?php echo $id; ?>"
								form="nj666-nof-<?php echo $id; ?>"
								data-id="<?php echo $id; ?>" autocomplete="off"
								title="Кому отдать реплики этого персонажа">
								<option value="">кому отдать реплики…</option>
								<?php foreach ($characters as $other): ?>
								<?php $oid = (int) Arr::get($other, 'id'); if ($oid === $id) continue; ?>
								<option value="<?php echo $oid; ?>"><?php echo HTML::chars((string) Arr::get($other, 'name')); ?><?php echo (string) Arr::get($other, 'kind') === 'narrator' ? ' (рассказчик)' : ''; ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" disabled class="n2j-btn-secondary n2j-btn-sm nj666-del-remap"
								data-id="<?php echo $id; ?>" data-n2j-arm="Удалить?" title="Удаление с переназначением: сначала выберите, кому отдать реплики">
								<i class="fas fa-user-minus"></i>
							</button>
						</div>
						<?php endif; ?>

						<p class="n2j-hint mt-1.5"><?php echo HTML::chars((string) Arr::get($one, 'description', '')); ?></p>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>

				<div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">
					<button type="button" id="nj666-add-char"
						class="n2j-btn-secondary n2j-btn-sm">
						<i class="fas fa-plus"></i><span>Добавить персонажа</span>
					</button>
				</div>
			</div>

			<!-- ==================== Блоки ==================== -->
			<div class="n2j-card p-0 mt-5">
				<div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
					<h2 class="n2j-h3"><i class="fas fa-list-ol mr-1.5 text-blue-500"></i>Блоки</h2>
					<span class="n2j-badge n2j-badge-wait"><?php echo $ready_blocks.' / '.$total_blocks; ?></span>
					<span class="ml-auto text-xs text-gray-400">Переозвучка — по одному блоку</span>
				</div>

				<?php if ( ! $blocks): ?>
				<p class="n2j-hint px-4 py-6 text-center">Блоки появятся после разбора книги.</p>
				<?php else: ?>
				<ul id="nj666-blocks" class="divide-y divide-gray-100 dark:divide-gray-700">
					<?php foreach ($blocks as $one): ?>
					<?php
						$ready = (string) Arr::get($one, 'status') === 'voiced';
						$fail  = (string) Arr::get($one, 'status') === 'error';
					?>
					<li class="px-4 py-3" data-id="<?php echo (int) Arr::get($one, 'id'); ?>" data-no="<?php echo (int) Arr::get($one, 'no'); ?>">
						<div class="flex flex-wrap items-center gap-2">
							<span class="w-9 shrink-0 rounded-md bg-gray-100 py-0.5 text-center text-xs font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?php echo sprintf('%02d', (int) Arr::get($one, 'no')); ?></span>
							<a href="/multivoice/b/<?php echo HTML::chars($code); ?>/k/<?php echo (int) Arr::get($one, 'no'); ?>"
								class="min-w-0 flex-1 truncate text-sm text-blue-700 hover:underline dark:text-blue-300"
								title="Открыть блок"><?php echo HTML::chars((string) Arr::get($one, 'title')); ?></a>
							<span class="text-xs text-gray-400"><?php echo $numbers(Arr::get($one, 'chars', 0)); ?> зн · ≈ <?php echo HTML::chars($time_of((int) Arr::get($one, 'chars', 0))); ?></span>

							<?php if ($fail): ?>
							<span class="n2j-badge n2j-badge-err" title="<?php echo HTML::chars((string) Arr::get($one, 'error')); ?>">ошибка</span>
							<?php endif; ?>

							<button type="button" class="n2j-btn-secondary n2j-btn-sm nj666-voice-one"
								data-id="<?php echo (int) Arr::get($one, 'id'); ?>" title="Озвучить этот блок заново">
								<i class="fas fa-rotate-right"></i>
							</button>
						</div>

						<?php if ($ready): ?>
						<audio controls preload="none" class="mt-2 w-full" src="/multivoice/f/<?php echo HTML::chars($code); ?>/<?php echo (int) Arr::get($one, 'no'); ?>"></audio>
						<?php elseif ($fail): ?>
						<p class="mt-1.5 text-xs text-rose-600 dark:text-rose-400"><?php echo HTML::chars((string) Arr::get($one, 'error')); ?></p>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
			</div>
		</div>

		<!-- ==================== Настройки и текст ==================== -->
		<div class="space-y-5 lg:col-span-2">

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-sliders mr-1.5 text-blue-500"></i>Настройки книги</h2>

				<div class="mt-3 space-y-3">
					<div>
						<label for="nj666-f-title" class="n2j-label">Название</label>
						<input id="nj666-f-title" type="text" maxlength="300" value="<?php echo HTML::chars((string) Arr::get($book, 'title')); ?>"
							class="n2j-input mt-1 w-full">
					</div>

					<div>
						<label for="nj666-f-voice" class="n2j-label">Голос по умолчанию <span class="font-normal text-gray-400">(кто говорит, если неясно)</span></label>
						<select id="nj666-f-voice" name="nj666-f-voice" autocomplete="off"
							class="n2j-input n2j-select mt-1 w-full">
							<option value="">из настроек сервиса</option>
							<?php foreach ($voices as $v): ?>
							<option value="<?php echo HTML::chars((string) Arr::get($v, 'id')); ?>"<?php echo (string) Arr::get($book, 'default_voice', '') === (string) Arr::get($v, 'id') ? ' selected' : ''; ?>><?php echo HTML::chars((string) Arr::get($v, 'id')); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="grid grid-cols-2 gap-3">
						<div>
							<label for="nj666-f-min" class="n2j-label">Блок от <b id="nj666-v-min"><?php echo (float) Arr::get($book, 'block_min_min', 7); ?></b> мин</label>
							<input id="nj666-f-min" type="range" min="<?php echo (float) Arr::get($limits, 'block_min_min', 1); ?>" max="<?php echo (float) Arr::get($limits, 'block_max_min', 60); ?>" step="0.5"
								value="<?php echo (float) Arr::get($book, 'block_min_min', 7); ?>" class="mt-2 w-full accent-blue-600">
						</div>
						<div>
							<label for="nj666-f-max" class="n2j-label">до <b id="nj666-v-max"><?php echo (float) Arr::get($book, 'block_max_min', 15); ?></b> мин</label>
							<input id="nj666-f-max" type="range" min="<?php echo (float) Arr::get($limits, 'block_min_min', 1); ?>" max="<?php echo (float) Arr::get($limits, 'block_max_min', 60); ?>" step="0.5"
								value="<?php echo (float) Arr::get($book, 'block_max_min', 15); ?>" class="mt-2 w-full accent-blue-600">
						</div>
					</div>

					<div>
						<label for="nj666-f-speed" class="flex items-center justify-between text-xs font-semibold text-gray-700 dark:text-gray-300">
							<span>Скорость речи</span>
							<span id="nj666-f-speed-value" class="font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($speed, 2, '.', ''); ?>×</span>
						</label>
						<input id="nj666-f-speed" type="range" min="0.5" max="2" step="0.05" value="<?php echo $speed; ?>" class="mt-2 w-full accent-blue-600">
					</div>

					<div class="flex items-center gap-2">
						<button type="button" id="nj666-save"
							class="n2j-btn-cta n2j-btn-sm">
							<i class="fas fa-floppy-disk"></i><span>Сохранить</span>
						</button>
						<span id="nj666-save-note" class="text-xs text-gray-400">Длину блока и скорость применяем при следующей озвучке.</span>
					</div>
				</div>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-file-lines mr-1.5 text-gray-400"></i>Начало текста</h2>
				<p class="mt-2 max-h-40 overflow-auto whitespace-pre-line rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-600 dark:bg-gray-800 dark:text-gray-300"><?php echo HTML::chars($excerpt); ?><?php echo $excerpt !== '' ? '…' : ''; ?></p>
				<p class="n2j-hint mt-2">Весь текст лежит рядом с книгой: <?php echo $numbers(Arr::get($book, 'chars', 0)); ?> знаков.</p>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-circle-question mr-1.5 text-blue-500"></i>Что в архиве</h2>
				<ul class="mt-2 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
					<li><code class="rounded bg-gray-100 px-1 dark:bg-gray-800">project/project.json</code> — книга, персонажи и реплики: по нему книгу можно восстановить и продолжить.</li>
					<li><code class="rounded bg-gray-100 px-1 dark:bg-gray-800">project/audio/</code> — файлы блоков как есть.</li>
					<li><code class="rounded bg-gray-100 px-1 dark:bg-gray-800">production/</code> — нумерованные главы, плейлист <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">.m3u</code> и описание с составом голосов.</li>
				</ul>
			</div>

		</div>
	</div>
</div>

<?php echo View::factory('666/script-book', [
	'book'   => $book,
	'busy'   => $busy,
	'csrf'   => $csrf,
]); ?>
