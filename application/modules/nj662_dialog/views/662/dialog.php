<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /dialog/<id> — страница диалога двух ИИ (владельцу).
 *
 * Три ответа на одном экране:
 *   1. «что сейчас происходит» — статус-ряд по дизайн-эталону
 *      /pages/required_design_sample: спиннер (или чек) + этап + заметка +
 *      секундомер + счётчики одним НЕРАЗРЫВНЫМ рядом, полос хода нет. Ведёт его
 *      nj662-dialog.js: опрос /dialog/status/<id> раз в 3 секунды, секундомер
 *      между опросами тикает локально;
 *   2. «что сказано» — лента реплик: стороны разные, у каждой реплики имя
 *      автора и модель; перед репликами видно, что агент нашёл в интернете
 *      (запрос, ссылки на источники) — это доказательство, а не украшение;
 *   3. «что можно поправить» — настройки (модели участников, число обменов,
 *      контекст, суммаризация, частота поиска), материалы диалога с состоянием
 *      индексации и публикация.
 *
 * Настройки показываем только до/после хода: менять их на ходу нельзя (сервис
 * это тоже проверяет — форма не единственный сторож).
 *
 * @var array  $dialog     строка диалога
 * @var array  $progress   живые данные хода (Service_Nj662_Dialog::progress)
 * @var array  $messages   реплики
 * @var array  $searches   поиски в интернете
 * @var array  $knowledge  материалы диалога
 * @var array  $events     лента событий
 * @var int    $replies    всего реплик
 * @var bool   $can_start  право запускать/продолжать разговор (vip или админ)
 * @var array  $models     варианты моделей из конфига
 * @var array  $limits     пределы полей
 * @var string $csrf       токен форм
 * @var array  $flash      ['tone' => notice|error, 'text' => …]
 */
$live    = (bool) $progress['live'];
$running = (string) $dialog['status'] === 'running';
$pairs   = (int) $dialog['pairs'];
$turn    = (int) $dialog['turn'];
$done    = (string) $dialog['status'] === 'done';

// Индексы поисков по обмену: лента показывает поиск ПЕРЕД репликами обмена.
$search_by_turn = [];

foreach ($searches as $s)
{
	$search_by_turn[(int) $s['turn']][] = $s;
}

$who_class = [
	'a' => [
		'wrap'   => 'border-blue-200/80 bg-blue-50/50 dark:border-blue-900/60 dark:bg-blue-950/20',
		'badge'  => 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200',
		'icon'   => 'fa-cubes',
	],
	'b' => [
		'wrap'   => 'border-emerald-200/80 bg-emerald-50/50 dark:border-emerald-900/60 dark:bg-emerald-950/20',
		'badge'  => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200',
		'icon'   => 'fa-lightbulb',
	],
	// Реплика владельца: третий цвет, чтобы в ленте было видно, где разговор
	// повернул человек, а не агент (решение владельца 09.10.2026).
	'user' => [
		'wrap'   => 'border-amber-300/80 bg-amber-50/60 dark:border-amber-800/70 dark:bg-amber-950/20',
		'badge'  => 'bg-amber-100 text-amber-900 dark:bg-amber-900/60 dark:text-amber-200',
		'icon'   => 'fa-user-pen',
	],
];

/** Стиль реплики по её автору: a | b | user (всё прочее — за первого). */
$style_of = function ($who) use ($who_class) {
	return $who_class[(string) $who === 'b' ? 'b' : ((string) $who === 'user' ? 'user' : 'a')];
};

$index_state = [
	'new'     => ['в очереди', 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
	'queued'  => ['индексируется', 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'],
	'indexed' => ['в индексе', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'],
	'error'   => ['не в индексе', 'bg-rose-50 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300'],
];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj662-view
	 data-nj662-run="<?php echo (int) $dialog['id']; ?>"
	 data-nj662-status="<?php echo HTML::chars((string) $dialog['status']); ?>"
	 data-nj662-elapsed="<?php echo (int) $progress['elapsed_sec']; ?>"
	 data-nj662-turn="<?php echo (int) $turn; ?>"
	 data-nj662-replies="<?php echo (int) $replies; ?>"
	 data-nj662-progress="/dialog/status/<?php echo (int) $dialog['id']; ?>">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<!-- ============ Шапка ============ -->
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/dialog" class="transition hover:text-blue-600 dark:hover:text-blue-400">Мои диалоги</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300">Диалог #<?php echo (int) $dialog['id']; ?></span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div class="min-w-0">
			<h1 class="n2j-h1"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $dialog['topic'], 160)); ?></h1>
			<p class="n2j-hint mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
				<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $who_class['a']['badge']; ?>">
					<i class="fas <?php echo $who_class['a']['icon']; ?> text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $dialog['a_name']); ?>
				</span>
				<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Nj662_Models::label((string) $dialog['a_model'])); ?></span>
				<span class="text-gray-300 dark:text-gray-600">×</span>
				<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $who_class['b']['badge']; ?>">
					<i class="fas <?php echo $who_class['b']['icon']; ?> text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $dialog['b_name']); ?>
				</span>
				<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Nj662_Models::label((string) $dialog['b_model'])); ?></span>
			</p>
		</div>

		<div class="flex flex-wrap items-center gap-2">
			<a href="/dialog/read/<?php echo (int) $dialog['id']; ?>" class="n2j-btn-secondary">
				<i class="fas fa-book-open-reader text-[13px]" aria-hidden="true"></i>Читать
			</a>

			<?php if (Service_Nj662_Dialog::as_bool($dialog['is_public'])): ?>
			<a href="/dialog/<?php echo (int) $dialog['id']; ?>" class="n2j-btn-secondary">
				<i class="fas fa-globe text-[13px]" aria-hidden="true"></i>Опубликован
			</a>
			<?php endif; ?>

			<?php if ($running OR (string) $dialog['status'] === 'quick'): ?>
			<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/pause" data-nj662-action>
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<button type="submit" class="n2j-btn-secondary">
					<i class="fas fa-pause text-[13px]" aria-hidden="true"></i>Пауза
				</button>
			</form>

			<?php // Стоп — отличается от паузы: задание снимается с очереди сразу. ?>
			<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/stop" data-nj662-action data-nj662-follow>
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<button data-n2j-arm="Остановить обмен?" type="submit" class="n2j-btn-danger">
					<i class="fas fa-stop text-[13px]" aria-hidden="true"></i>Стоп
				</button>
			</form>
			<?php elseif ( ! $done AND $turn < $pairs): ?>
			<?php if ($can_start): ?>
			<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/start" data-nj662-action>
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<button type="submit" class="n2j-btn-process">
					<i class="fas fa-play text-[13px]" aria-hidden="true"></i><?php echo $turn > 0 ? 'Продолжить разговор' : 'Начать разговор'; ?>
				</button>
			</form>
			<?php else: ?>
			<p class="n2j-flash n2j-flash-warn inline-flex min-h-[44px] items-center gap-2 text-xs font-semibold">
				<i class="fas fa-user-lock text-[13px]" aria-hidden="true"></i>Продолжить могут привилегированные аккаунты
			</p>
			<?php endif; ?>
			<?php endif; ?>

			<?php if ($done AND $can_start): ?>
			<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/quick" data-nj662-action>
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="autostart" value="1">
				<button type="submit" class="n2j-btn-secondary">
					<i class="fas fa-wand-magic-sparkles text-[13px]" aria-hidden="true"></i>Новые агенты под тему
				</button>
			</form>
			<?php endif; ?>
		</div>
	</div>

	<!-- ============ Ход диалога ============ -->
	<section class="mt-5 rounded-xl border <?php echo $live
			? 'border-blue-200/80 bg-blue-50/40 dark:border-blue-900/60 dark:bg-blue-950/20'
			: 'border-gray-200 bg-white dark:border-gray-700/80 dark:bg-gray-800'; ?> p-4 shadow-xs sm:p-5"
			data-nj662-run-box>

		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<h2 class="n2j-h3 flex flex-wrap items-center gap-2">
					<i class="fas fa-comments text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Ход разговора
					<span class="n2j-badge n2j-badge-wait" data-nj662-status-pill><?php echo HTML::chars($progress['status_word']); ?></span>
				</h2>
				<p class="n2j-hint">
					Обмен — это реплика <?php echo HTML::chars((string) $dialog['a_name']); ?> и ответ <?php echo HTML::chars((string) $dialog['b_name']); ?>. Разговор ведёт сервер: страницу можно закрыть.
				</p>
			</div>
			<span class="n2j-badge n2j-badge-ok"><?php echo HTML::chars($progress['turn_word']); ?></span>
		</div>

		<?php // Неразрывный статус-ряд: спиннер, этап и секундомер всегда рядом. ?>
		<div class="mt-3.5 flex flex-wrap items-center gap-x-2.5 gap-y-1">
			<i class="fas <?php echo $live ? 'fa-spinner animate-spin text-blue-600 dark:text-blue-400'
					: ($done ? 'fa-circle-check text-emerald-500' : ($progress['tone'] === 'error' ? 'fa-triangle-exclamation text-rose-500' : 'fa-circle-pause text-gray-400')); ?> text-sm"
				data-nj662-spin aria-hidden="true"></i>
			<span class="text-sm font-bold text-gray-900 dark:text-white" data-nj662-stage><?php echo HTML::chars($progress['stage'] !== '' ? $progress['stage'] : $progress['status_word']); ?></span>
			<span class="n2j-badge n2j-badge-info font-mono tabular-nums" data-nj662-elapsed-text><?php echo HTML::chars($progress['elapsed_text']); ?></span>
			<span class="n2j-badge n2j-badge-wait" data-nj662-counter><?php echo HTML::chars($progress['counters_text']); ?></span>
			<span class="n2j-badge n2j-badge-wait" title="поиски агентов в интернете">
				<i class="fas fa-magnifying-glass text-[10px]" aria-hidden="true"></i> <?php echo HTML::chars(Service_Nj662_Dialog::ddg_label((int) $dialog['ddg'])); ?>
			</span>
		</div>

		<p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300" data-nj662-stage-note><?php echo HTML::chars((string) $dialog['stage_note']); ?></p>

		<?php // Лестница этапов — текстом, без полос: пройденные зелёные, текущий жирный. ?>
		<ol class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
			<?php foreach (Service_Nj662_Dialog::$stages as $i => $stage_name): ?>
			<li data-nj662-stage-item="<?php echo (int) $i; ?>" class="<?php
				echo (int) $progress['stage_index'] > $i ? 'text-emerald-600 dark:text-emerald-400'
					: ((int) $progress['stage_index'] === $i ? 'font-bold text-blue-700 dark:text-blue-300' : 'text-gray-400 dark:text-gray-500');
			?>"><?php echo (int) $progress['stage_index'] > $i ? '<i class="fas fa-check mr-1 text-[10px]"></i>' : ''; ?><?php echo HTML::chars($stage_name); ?></li>
			<?php endforeach; ?>
		</ol>

		<?php if (trim((string) $dialog['error']) !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-3 text-xs block" data-nj662-error><?php echo HTML::chars((string) $dialog['error']); ?></p>
		<?php endif; ?>

		<?php if ($done AND trim((string) $dialog['outcome']) !== ''): ?>
		<p class="n2j-flash n2j-flash-ok mt-3 text-xs block">
			<i class="fas fa-flag-checkered mr-1" aria-hidden="true"></i><?php echo HTML::chars((string) $dialog['outcome']); ?>
		</p>
		<?php endif; ?>

		<?php // ===== Своя реплика: поправить направление и продолжить ===== ?>
		<div class="mt-4 rounded-lg border border-gray-200 bg-white/70 p-3 dark:border-gray-700/70 dark:bg-gray-900/30" data-nj662-say-box>
			<h3 class="flex items-center gap-2 text-xs font-bold text-gray-900 dark:text-white">
				<i class="fas fa-user-pen text-amber-600 dark:text-amber-400" aria-hidden="true"></i>Своя реплика
			</h3>

			<?php if ($live): ?>
			<p class="n2j-hint mt-1.5">
				Разговор идёт — реплику добавляют на паузе: остановите его («Пауза» или «Стоп») и напишите, куда повернуть.
			</p>

			<?php elseif ( ! $can_start): ?>
			<p class="n2j-hint mt-1.5">
				Добавить реплику и продолжить разговор могут привилегированные аккаунты (роль vip) и администратор: продолжение — это запуск обмена, а он расходует очередь.
			</p>

			<?php else: ?>
			<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/say" class="mt-2" data-nj662-action data-nj662-say>
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

				<label for="nj662-say-text" class="n2j-label">Что сказать агентам</label>
				<textarea id="nj662-say-text" name="text" rows="3" required
					maxlength="<?php echo (int) Arr::get($limits, 'say_max', 2000); ?>"
					placeholder="Например: хватит общих слов — договоритесь о плане на неделю и посчитайте деньги"
					class="n2j-textarea mt-1.5 w-full"></textarea>

				<div class="mt-2 flex flex-wrap items-end gap-x-4 gap-y-2">
					<label class="text-xs font-semibold text-gray-500 dark:text-gray-400">Обменов добавить
						<input type="number" name="add" value="0" min="0" max="<?php echo (int) Arr::get($limits, 'say_add_max', 5); ?>"
							class="n2j-input ml-1.5 min-h-[38px] w-20 text-sm">
					</label>
					<p class="n2j-hint">
						Счётчик обменов идёт ОТ НАЧАЛА диалога: сейчас <?php echo HTML::chars($progress['turn_word']); ?>.
						Пауза, стоп и своя реплика его не обнуляют. «0» — разговор пойдёт по плану;
						поставьте 1–<?php echo (int) Arr::get($limits, 'say_add_max', 5); ?>, если обменов не хватает
						(когда план уже исчерпан, обмен добавится сам).
					</p>
				</div>

				<div class="mt-3 flex flex-wrap gap-2">
					<button type="submit" name="resume" value="1"
						class="n2j-btn-cta">
						<i class="fas fa-reply text-[13px]" aria-hidden="true"></i>Добавить и продолжить
					</button>
					<button type="submit" name="resume" value="0"
						class="n2j-btn-secondary">
						<i class="fas fa-pen text-[13px]" aria-hidden="true"></i>Только добавить реплику
					</button>
				</div>
				<p class="n2j-hint mt-1.5">
					Реплика ложится в ленту разговора и попадает в контекст агентов следующим обменом — так вы правите направление, не перезапуская диалог.
				</p>
			</form>
			<?php endif; ?>
		</div>
	</section>

	<div class="mt-5 grid gap-4 lg:grid-cols-3">

		<!-- ============ Лента разговора ============ -->
		<section class="n2j-card p-0 lg:col-span-2" data-nj662-feed>
			<div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
				<h2 class="n2j-h3 flex items-center gap-2">
					<i class="fas fa-comment-dots text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Разговор
				</h2>
				<span class="n2j-badge n2j-badge-wait ml-auto">реплик: <?php echo (int) $replies; ?></span>
			</div>

			<?php if (empty($messages)): ?>
			<p class="px-4 py-6 text-sm text-gray-500 sm:px-5 dark:text-gray-400">
				Реплик пока нет<?php echo $live ? ' — агенты готовят первый обмен.' : '. Нажмите «Начать разговор» — участники скажут первое.'; ?>
			</p>
			<?php else: ?>
			<div class="space-y-3 px-4 py-4 sm:px-5">
				<?php $shown_turn = 0; ?>
				<?php foreach ($messages as $m): ?>
					<?php if ((int) $m['turn'] !== $shown_turn): ?>
						<?php $shown_turn = (int) $m['turn']; ?>
						<div class="flex items-center gap-3 pt-2">
							<span class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Nj662_Dialog::pair_word($shown_turn, $pairs)); ?></span>
							<span class="h-px flex-1 bg-gray-100 dark:bg-gray-700/60"></span>
						</div>
						<?php foreach (Arr::get($search_by_turn, $shown_turn, []) as $s): ?>
						<div class="rounded-lg border border-amber-200/80 bg-amber-50/60 px-3.5 py-3 dark:border-amber-900/60 dark:bg-amber-950/20">
							<p class="flex flex-wrap items-center gap-2 text-xs text-amber-900 dark:text-amber-200">
								<i class="fas fa-magnifying-glass" aria-hidden="true"></i>
								<span class="font-semibold">Агенты искали в интернете:</span>
								<span class="font-mono text-xs"><?php echo HTML::chars((string) $s['query']); ?></span>
								<span class="text-amber-700/80 dark:text-amber-300/80">находок: <?php echo (int) $s['found']; ?></span>
							</p>
							<?php if (trim((string) $s['error']) !== ''): ?>
							<p class="mt-1 text-xs text-rose-700 dark:text-rose-300"><?php echo HTML::chars((string) $s['error']); ?></p>
							<?php endif; ?>
							<?php if ( ! empty($s['results'])): ?>
							<ul class="mt-2 space-y-1">
								<?php foreach (array_slice((array) $s['results'], 0, 5) as $r): ?>
								<li class="text-xs leading-relaxed">
									<a href="<?php echo HTML::chars((string) Arr::get($r, 'url', '#')); ?>" target="_blank" rel="nofollow noopener noreferrer" class="font-semibold text-amber-900 underline decoration-amber-300 hover:text-amber-700 dark:text-amber-200"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) Arr::get($r, 'title', ''), 90)); ?></a>
									<span class="text-amber-700/80 dark:text-amber-300/80"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) Arr::get($r, 'body', ''), 140)); ?></span>
								</li>
								<?php endforeach; ?>
							</ul>
							<?php endif; ?>
						</div>
						<?php endforeach; ?>
					<?php endif; ?>

					<?php $style = $style_of($m['who']); ?>
					<article class="rounded-xl border px-3.5 py-3 <?php echo $style['wrap']; ?>">
						<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
							<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 font-semibold <?php echo $style['badge']; ?>">
								<i class="fas <?php echo $style['icon']; ?> text-xs" aria-hidden="true"></i><?php echo HTML::chars((string) $m['author_name']); ?>
							</span>
							<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(Service_Nj662_Models::label((string) $m['model'])); ?></span>
							<?php if ((int) $m['tokens'] > 0): ?>
							<span class="text-gray-300 dark:text-gray-600">·</span>
							<span class="text-gray-400 dark:text-gray-500"><?php echo (int) $m['tokens']; ?> токенов</span>
							<?php endif; ?>
							<span class="ml-auto text-xs text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('H:i:s', strtotime((string) $m['created_at']))); ?></span>
						</div>
						<div class="mt-2 space-y-2 text-sm leading-relaxed text-gray-800 dark:text-gray-100">
							<?php foreach (preg_split('/\n{2,}/u', trim((string) $m['content'])) as $para): ?>
							<p><?php echo nl2br(HTML::chars(trim($para))); ?></p>
							<?php endforeach; ?>
						</div>
						<?php if (trim((string) $m['summary']) !== ''): ?>
						<p class="n2j-hint mt-2 rounded-lg bg-white/70 px-2.5 py-2">
							<i class="fas fa-compress mr-1" aria-hidden="true"></i><?php echo HTML::chars((string) $m['summary']); ?>
						</p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</section>

		<div class="space-y-4">
			<!-- ============ Настройки ============ -->
			<section class="n2j-card p-0">
				<div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
					<h2 class="n2j-h3 flex items-center gap-2">
						<i class="fas fa-sliders text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Настройки
					</h2>
				</div>

				<?php if ($live): ?>
				<p class="n2j-hint px-4 py-4">
					Разговор идёт — настройки меняются на паузе. Так честнее: агент не меняет модель посреди фразы.
				</p>
				<?php else: ?>
				<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/settings" class="px-4 py-4" data-nj662-action data-nj662-settings>
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

					<label for="nj662-topic-edit" class="n2j-label">Тема</label>
					<textarea id="nj662-topic-edit" name="topic" rows="2" maxlength="<?php echo (int) Arr::get($limits, 'topic_max', 300); ?>"
						class="n2j-textarea mt-1.5 w-full"><?php echo HTML::chars((string) $dialog['topic']); ?></textarea>

					<label for="nj662-brief-edit" class="n2j-label mt-3">Что уже известно</label>
					<textarea id="nj662-brief-edit" name="brief" rows="3" maxlength="<?php echo (int) Arr::get($limits, 'brief_max', 2000); ?>"
						placeholder="Факты, цифры, условия — агенты получат это до первого вопроса"
						class="n2j-textarea mt-1.5 w-full"><?php echo HTML::chars((string) $dialog['brief']); ?></textarea>

					<?php foreach (['a' => $dialog['a_name'], 'b' => $dialog['b_name']] as $code => $name): ?>
					<?php $which = $code === 'a' ? 'a' : 'b'; ?>
					<div class="mt-4 rounded-lg border border-gray-200 bg-gray-50/50 p-3 dark:border-gray-700/70 dark:bg-gray-900/30">
						<div class="flex flex-wrap items-center gap-2">
							<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold <?php echo $who_class[$which]['badge']; ?>">
								<i class="fas <?php echo $who_class[$which]['icon']; ?> text-xs" aria-hidden="true"></i>Участник <?php echo $which === 'a' ? '1' : '2'; ?>
							</span>
							<input type="text" name="<?php echo $which; ?>_name" value="<?php echo HTML::chars((string) $name); ?>" maxlength="<?php echo (int) Arr::get($limits, 'name_max', 60); ?>"
								class="n2j-input min-h-[38px] min-w-0 flex-1 text-sm">
						</div>

						<label for="nj662-model-<?php echo $which; ?>" class="n2j-label mt-2.5">Модель</label>
						<select id="nj662-model-<?php echo $which; ?>" name="<?php echo $which; ?>_model" data-nj662-model
							class="n2j-input n2j-select mt-1.5 w-full cursor-pointer">
							<option value="">модель воркера по умолчанию</option>
							<?php foreach ($models as $offer): ?>
							<option value="<?php echo HTML::chars((string) $offer['spec']); ?>" <?php echo (string) $dialog[$which.'_model'] === (string) $offer['spec'] ? 'selected' : ''; ?>><?php echo HTML::chars((string) $offer['label']); ?></option>
							<?php endforeach; ?>
							<option value="custom" <?php echo ((string) $dialog[$which.'_model'] !== '' AND ! in_array((string) $dialog[$which.'_model'], Service_Nj662_Models::specs(), TRUE)) ? 'selected' : ''; ?>>своя модель…</option>
						</select>
						<input type="text" name="<?php echo $which; ?>_model_custom" value="<?php echo HTML::chars((string) $dialog[$which.'_model']); ?>" maxlength="<?php echo (int) Arr::get($limits, 'model_max', 160); ?>"
							placeholder="провайдер::модель (пусто — модель воркера)"
							class="n2j-input mt-1.5 <?php echo ((string) $dialog[$which.'_model'] !== '' AND ! in_array((string) $dialog[$which.'_model'], Service_Nj662_Models::specs(), TRUE)) ? '' : 'hidden'; ?> w-full py-2 font-mono text-xs min-h-0"
							data-nj662-model-custom>

						<label for="nj662-system-<?php echo $which; ?>" class="n2j-label mt-2.5">Характер (системная подсказка)</label>
						<textarea id="nj662-system-<?php echo $which; ?>" name="<?php echo $which; ?>_system" rows="4" maxlength="<?php echo (int) Arr::get($limits, 'system_max', 4000); ?>"
							class="n2j-textarea mt-1.5 w-full leading-relaxed"><?php echo HTML::chars((string) $dialog[$which.'_system']); ?></textarea>
					</div>
					<?php endforeach; ?>

					<div class="mt-4 grid grid-cols-2 gap-3">
						<div>
							<label for="nj662-pairs" class="n2j-label">Обменов в разговоре</label>
							<input id="nj662-pairs" type="number" name="pairs" min="<?php echo (int) Arr::get($limits, 'pairs_min', 1); ?>" max="<?php echo (int) Arr::get($limits, 'pairs_max', 12); ?>" value="<?php echo (int) $dialog['pairs']; ?>"
								class="n2j-input mt-1.5 w-full">
						</div>
						<div>
							<label for="nj662-context" class="n2j-label">Обменов в контексте</label>
							<input id="nj662-context" type="number" name="context_pairs" min="<?php echo (int) Arr::get($limits, 'context_min', 0); ?>" max="<?php echo (int) Arr::get($limits, 'context_max', 10); ?>" value="<?php echo (int) $dialog['context_pairs']; ?>"
								class="n2j-input mt-1.5 w-full">
						</div>
					</div>
					<p class="n2j-hint">
					Контекст — сколько прошлых обменов агент видит дословно; остальное живёт в выжимке.
					Счётчик «обмен N из M» идёт от начала диалога: пауза, стоп и своя реплика его не обнуляют.
				</p>

					<label class="mt-3 flex items-start gap-2.5 rounded-lg border border-gray-200 bg-gray-50/50 p-3 text-xs dark:border-gray-700/70 dark:bg-gray-900/30">
						<input type="checkbox" name="summarize" value="1" <?php echo Service_Nj662_Dialog::as_bool($dialog['summarize']) ? 'checked' : ''; ?> class="mt-0.5 h-4 w-4 cursor-pointer rounded border-gray-300 text-blue-600 focus:ring-blue-500/30">
						<span>
							<span class="font-semibold text-gray-700 dark:text-gray-200">Сжимать сказанное в выжимку</span>
							<span class="mt-0.5 block text-gray-500 dark:text-gray-400">После каждого обмена модель коротко записывает суть — разговор не «расплывается» к десятому обмену.</span>
						</span>
					</label>

					<label for="nj662-ddg" class="n2j-label mt-3"><?php echo HTML::chars((string) Service_Nj662_Dialog::config('search.label', 'Поиск в интернете')); ?></label>
					<select id="nj662-ddg" name="ddg" class="n2j-input n2j-select mt-1.5 w-full cursor-pointer">
						<?php foreach ((array) Service_Nj662_Dialog::config('search.steps', [0, 1, 2, 3, 5]) as $step): ?>
						<option value="<?php echo (int) $step; ?>" <?php echo (int) $dialog['ddg'] === (int) $step ? 'selected' : ''; ?>><?php echo HTML::chars(Service_Nj662_Dialog::ddg_label((int) $step)); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="n2j-hint"><?php echo HTML::chars((string) Service_Nj662_Dialog::config('search.hint', '')); ?></p>

					<button type="submit" class="n2j-btn-cta mt-4 w-full">
						<i class="fas fa-floppy-disk text-[13px]" aria-hidden="true"></i>Сохранить настройки
					</button>
				</form>

				<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/delete" class="border-t border-gray-100 px-4 py-3 dark:border-gray-700/60"
					data-nj662-action data-nj662-follow>
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<button data-n2j-confirm="Диалог удалится вместе со всей перепиской." data-n2j-title="Удалить диалог?" type="submit" class="n2j-btn-danger n2j-btn-sm w-full">
						<i class="fas fa-trash-can text-[12px]" aria-hidden="true"></i>Удалить диалог
					</button>
				</form>
				<?php endif; ?>
			</section>

			<!-- ============ Публикация ============ -->
			<section class="n2j-card p-0">
				<div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
					<h2 class="n2j-h3 flex items-center gap-2">
						<i class="fas fa-globe text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Публикация
					</h2>
				</div>
				<div class="px-4 py-4">
					<?php if ($turn < 1): ?>
					<p class="n2j-hint">Публиковать пока нечего: в разговоре нет ни одного обмена. Сначала дайте агентам сказать первое.</p>
					<?php else: ?>
					<p class="n2j-hint">
						Опубликованный диалог читают все: он попадает в <a href="/dialog/list" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">каталог</a> и в карту сайта. Материалы и настройки остаются вашими — гость видит только сам разговор.
					</p>
					<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/publish" class="mt-3" data-nj662-action>
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<button type="submit" class="<?php echo Service_Nj662_Dialog::as_bool($dialog['is_public']) ? 'n2j-btn-secondary' : 'n2j-btn-cta'; ?> w-full">
							<i class="fas <?php echo Service_Nj662_Dialog::as_bool($dialog['is_public']) ? 'fa-eye-slash' : 'fa-share-nodes'; ?> text-[13px]" aria-hidden="true"></i><?php echo Service_Nj662_Dialog::as_bool($dialog['is_public']) ? 'Снять с публикации' : 'Опубликовать разговор'; ?>
						</button>
					</form>
					<?php endif; ?>
				</div>
			</section>

			<!-- ============ Материалы ============ -->
			<section class="n2j-card p-0">
				<div class="flex flex-wrap items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
					<h2 class="n2j-h3 flex items-center gap-2">
						<i class="fas fa-book text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Материалы
					</h2>
					<span class="ml-auto text-xs text-gray-400 dark:text-gray-500" title="Материалы индексируются общей семантикой платформы (nj115), как в «Лаборатории векторов» /veclab">
						<i class="fas fa-vector-square" aria-hidden="true"></i> семантика
					</span>
				</div>

				<div class="px-4 py-4">
					<?php if (empty($knowledge)): ?>
					<p class="n2j-hint">
						Материалов нет. Положите текст или ссылку — агенты будут опираться на них и ссылаться на источник, а не выдумывать.
					</p>
					<?php else: ?>
					<ul class="space-y-2">
						<?php foreach ($knowledge as $k): ?>
						<?php $state = $index_state[(string) $k['index_state']] ?? $index_state['new']; ?>
						<li class="rounded-lg border border-gray-200 bg-gray-50/50 px-3 py-2.5 dark:border-gray-700/70 dark:bg-gray-900/30">
							<div class="flex flex-wrap items-center gap-2">
								<span class="min-w-0 flex-1 truncate text-xs font-semibold text-gray-800 dark:text-gray-100" title="<?php echo HTML::chars((string) $k['title']); ?>"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $k['title'], 70)); ?></span>
								<span class="rounded-md px-1.5 py-0.5 text-xs font-semibold <?php echo $state[1]; ?>" title="<?php echo HTML::chars((string) $k['index_note']); ?>"><?php echo HTML::chars($state[0]); ?></span>
							</div>
							<?php if (trim((string) $k['source']) !== ''): ?>
							<a href="<?php echo HTML::chars((string) $k['source']); ?>" target="_blank" rel="nofollow noopener noreferrer" class="mt-0.5 block truncate text-xs text-blue-600 hover:underline dark:text-blue-400"><?php echo HTML::chars((string) $k['source']); ?></a>
							<?php endif; ?>
							<p class="n2j-hint"><?php echo HTML::chars(Service_Nj662_Dialog::excerpt((string) $k['preview'], 180)); ?></p>
							<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/knowledge_delete" class="mt-1.5" data-nj662-action>
								<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
								<input type="hidden" name="knowledge_id" value="<?php echo (int) $k['id']; ?>">
								<button type="submit" class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-400 transition hover:text-rose-600 dark:text-gray-500 dark:hover:text-rose-400">
									<i class="fas fa-xmark text-[10px]" aria-hidden="true"></i>убрать
								</button>
							</form>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>

					<form method="post" action="/dialog/<?php echo (int) $dialog['id']; ?>/knowledge_add" class="mt-3 space-y-2" data-nj662-action>
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<input type="text" name="title" maxlength="300" placeholder="Название материала"
							class="n2j-input min-h-[38px] w-full text-xs">
						<input type="text" name="source" maxlength="<?php echo (int) Arr::get($limits, 'knowledge_source', 500); ?>" placeholder="Ссылка (сайт прочитает её сам)"
							class="n2j-input min-h-[38px] w-full text-xs">
						<textarea name="text" rows="3" maxlength="<?php echo (int) Arr::get($limits, 'knowledge_chars', 20000); ?>" placeholder="…или текст материала"
							class="n2j-textarea w-full leading-relaxed"></textarea>
						<button type="submit" class="n2j-btn-secondary n2j-btn-sm w-full">
							<i class="fas fa-plus text-[11px]" aria-hidden="true"></i>Добавить и проиндексировать
						</button>
					</form>
				</div>
			</section>

			<!-- ============ Что происходило ============ -->
			<section class="n2j-card p-0">
				<div class="border-b border-gray-100 px-4 py-3 dark:border-gray-700/60">
					<h2 class="n2j-h3 flex items-center gap-2">
						<i class="fas fa-clock-rotate-left text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Что происходило
					</h2>
				</div>
				<?php if (empty($events)): ?>
				<p class="n2j-hint px-4 py-5">Событий нет: запуск и обмены появятся здесь.</p>
				<?php else: ?>
				<ol class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($events as $e): ?>
					<li class="flex items-start gap-2.5 px-4 py-2.5">
						<i class="fas <?php
							switch ((string) $e['kind'])
							{
								case 'turn':      echo 'fa-comment text-blue-500'; break;
								case 'search':    echo 'fa-magnifying-glass text-amber-500'; break;
								case 'quick':     echo 'fa-wand-magic-sparkles text-blue-500'; break;
								case 'done':      echo 'fa-flag-checkered text-emerald-500'; break;
								case 'publish':   echo 'fa-globe text-emerald-500'; break;
								case 'pause':     echo 'fa-pause text-amber-500'; break;
								case 'stop':      echo 'fa-stop text-rose-500'; break;
								case 'say':       echo 'fa-user-pen text-amber-600'; break;
								case 'fail':
								case 'error':     echo 'fa-circle-exclamation text-rose-500'; break;
								default:          echo 'fa-circle-small text-gray-400'; break;
							}
						?> mt-0.5 text-xs" aria-hidden="true"></i>
						<div class="min-w-0 flex-1">
							<p class="text-xs leading-relaxed text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $e['message']); ?></p>
							<p class="n2j-hint mt-0.5"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $e['created_at']))); ?></p>
						</div>
					</li>
					<?php endforeach; ?>
				</ol>
				<?php endif; ?>
			</section>
		</div>
	</div>
</div>
