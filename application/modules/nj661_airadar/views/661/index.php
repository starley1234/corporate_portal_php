<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /airadar — дашборд «ИИ-радара».
 *
 * Владелец (08.10.2026): «при запуске „Собрать сейчас" должен быть виден процесс
 * поиска» — по дизайн-эталону /pages/required_design_sample: спиннер, название
 * этапа и секундомер одним НЕРАЗРЫВНЫМ рядом (правило 4), полос хода нет,
 * «сколько» показываем числом. Этапы конвейера идут лестницей текстом:
 * Источники → Чтение → Анализ → Отчёт (текущий подсвечен, пройденные отмечены).
 *
 * Статус-ряд рисует сервер (Service_Nj661_Airadar::run_progress), а дальше его
 * ведёт nj661-airadar.js: сразу после нажатия кнопки (fetch, без перезагрузки),
 * затем опросом /airadar/progress раз в 3 секунды до терминального статуса.
 *
 * @var array       $ov               сводка дашборда (overview)
 * @var array       $categories       словарь категорий
 * @var string      $csrf             токен форм
 * @var array       $flash            ['notice' => …, 'error' => …]
 * @var string      $mail_to          адрес писем ('' — письма не уходят)
 * @var array|NULL  $progress         ход последнего прогона (run_progress)
 * @var array|NULL  $latest_published последний опубликованный отчёт
 * @var int         $catalog_total    сколько отчётов опубликовано
 */
$f = 'Service_Nj661_Airadar';
$c = $ov['counts'];
$report = $ov['report'];
$run = $ov['run'];
$running = ($run !== NULL AND in_array($run['status'], ['new', 'running'], TRUE));

// Ход прогона: у дашборда и у /airadar/progress одна форма данных. Если прогона
// ещё не было — карточка стоит скрытой, движок покажет её сразу после запуска.
$progress = isset($progress) ? $progress : $f::run_progress($run);
$latest_published = isset($latest_published) ? $latest_published : NULL;
$catalog_total = isset($catalog_total) ? (int) $catalog_total : 0;

// Секундомер статус-ряда: «0:07», «12:40» — считаем от постановки задачи.
$elapsed_text = function ($sec) {
	$sec = max(0, (int) $sec);
	return floor($sec / 60).':'.str_pad((string) ($sec % 60), 2, '0', STR_PAD_LEFT);
};

// Лестница этапов: пройденные — зелёные, текущий — жирный, будущие — серые.
$stage_class = function ($i, $stage_index) {
	if ($stage_index < 0 OR $i > $stage_index)
	{
		return 'text-gray-400 dark:text-gray-500';
	}

	return $i === $stage_index
		? 'font-bold text-blue-700 dark:text-blue-300'
		: 'text-emerald-600 dark:text-emerald-400';
};

// Производные для статус-ряда: живой прогон, завершённый, плашка статуса.
$prog_live = (bool) ($progress AND $progress['live']);
$prog_done = (bool) ($progress AND $progress['status'] === 'done');
$pill_text = ! $progress ? '' : ($prog_live ? 'идёт' : ($prog_done ? 'завершён' : 'остановлен'));
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-nj661-view>
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<span class="font-semibold text-gray-700 dark:text-gray-300">ИИ-радар</span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-satellite-dish text-blue-600 dark:text-blue-400" aria-hidden="true"></i>ИИ-радар</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Конвейер следит за прогрессом ИИ — открытые модели и фреймворки, окна возможностей, прорывы — копит базу знаний из фактов и присылает письма: на что обратить внимание и что реализовать на n2j.</p>
		</div>
		<div class="flex flex-wrap items-center gap-2">
			<?php // data-атрибуты — контракт с nj661-airadar.js: fetch на /airadar/run даёт JSON,
			      // дальше опрос /airadar/progress оживляет статус-ряд без перезагрузки страницы. ?>
			<form method="post" action="/airadar/run" class="inline" data-nj661-run-form data-nj661-progress="/airadar/progress">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<button type="submit" class="n2j-btn-process" <?php if ($running) echo 'disabled title="Прогон уже идёт"'; ?> data-nj661-run-button><i class="fas fa-play mr-2" aria-hidden="true"></i><span data-nj661-run-label><?php echo $running ? 'Конвейер работает…' : 'Собрать сейчас'; ?></span></button>
			</form>
			<form method="post" action="/airadar/mail" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<button data-n2j-arm="Точно прислать?" title="Прислать письмо с последним отчётом сейчас?" type="submit" class="n2j-btn-secondary"><i class="fas fa-envelope mr-2" aria-hidden="true"></i>Прислать письмо</button>
			</form>
			<form method="post" action="/airadar/tick" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<button type="submit" class="n2j-btn-secondary" title="Проверить, пора ли запускать по расписанию"><i class="fas fa-clock mr-2" aria-hidden="true"></i>Расписание</button>
			</form>
		</div>
	</div>

	<?php if ($flash['notice'] !== ''): ?>
		<p class="n2j-flash n2j-flash-ok mt-5 block"><?php echo HTML::chars($flash['notice']); ?></p>
	<?php endif; ?>
	<?php if ($flash['error'] !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-5 block"><?php echo HTML::chars($flash['error']); ?></p>
	<?php endif; ?>

	<?php if ($mail_to === ''): ?>
		<p class="n2j-flash n2j-flash-warn mt-5 block">Письма не уходят: укажите адрес в <code class="font-mono">application/modules/nj661_airadar/config/nj661_airadar.php</code> → <code class="font-mono">mail.to_email</code>.</p>
	<?php endif; ?>

	<?php
	// Живой ход прогона. Правило 4 эталона: спиннер, название этапа и секундомер —
	// один неразрывный ряд; полос хода нет, «сколько» показываем числом. Без
	// прогона карточка скрыта: движок покажет её сразу после «Собрать сейчас».
	?>
	<section class="mt-5 rounded-xl border <?php echo $prog_live
			? 'border-blue-200 bg-blue-50/40 dark:border-blue-900 dark:bg-blue-950/20'
			: 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'; ?> p-4 shadow-xs sm:p-5"
			data-nj661-run="<?php echo $progress ? (int) $progress['id'] : 0; ?>"
			data-nj661-status="<?php echo $progress ? HTML::chars($progress['status']) : ''; ?>"
			data-nj661-elapsed="<?php echo $progress ? (int) $progress['elapsed_sec'] : 0; ?>"
			data-nj661-stage-index="<?php echo $progress ? (int) $progress['stage_index'] : -1; ?>"
			data-nj661-empty="<?php echo $progress ? '0' : '1'; ?>"
			<?php echo $progress ? '' : ' hidden'; ?>>

		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<h2 class="n2j-h3 flex flex-wrap items-center gap-2">
					<i class="fas fa-satellite-dish text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Ход сбора
					<span class="font-mono text-xs font-normal text-gray-400 dark:text-gray-500" data-nj661-run-id<?php echo $progress ? '' : ' hidden'; ?>>#<?php echo $progress ? (int) $progress['id'] : ''; ?></span>
				</h2>
				<p class="n2j-hint">Источники → чтение страниц → разбор моделью → отчёт. Страница обновит отчёт сама, когда конвейер закончит.</p>
			</div>
			<span class="n2j-badge <?php echo $prog_live ? 'n2j-badge-run' : 'n2j-badge-wait'; ?>" data-nj661-status-pill><?php echo HTML::chars($pill_text); ?></span>
		</div>

		<?php // Неразрывный статус-ряд: спиннер, этап и секундомер всегда рядом. ?>
		<div class="mt-3.5 flex flex-wrap items-center gap-x-2.5 gap-y-1">
			<i class="fas <?php echo $prog_live ? 'fa-spinner animate-spin text-blue-600 dark:text-blue-400'
					: ($prog_done ? 'fa-circle-check text-emerald-500' : 'fa-triangle-exclamation text-rose-500'); ?> text-sm"
				data-nj661-spin aria-hidden="true"></i>
			<span class="text-sm font-bold text-gray-900 dark:text-white" data-nj661-stage><?php echo $progress ? HTML::chars($progress['stage']) : 'Ставлю задачу в очередь…'; ?></span>
			<span class="n2j-badge n2j-badge-wait font-mono tabular-nums" data-nj661-elapsed-text><?php echo $elapsed_text($progress ? $progress['elapsed_sec'] : 0); ?></span>
			<span class="n2j-badge n2j-badge-info" data-nj661-counter><?php echo $progress ? HTML::chars($progress['counters_text']) : 'собираю источники…'; ?></span>
		</div>

		<p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300" data-nj661-stage-note><?php echo $progress ? HTML::chars($progress['stage_note']) : ''; ?></p>

		<?php // Лестница этапов — текстом, без полос: пройденные зелёные, текущий жирный. ?>
		<ol class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" data-nj661-stages>
			<?php foreach ($f::$stages as $i => $stage_name): ?>
			<li data-nj661-stage-item="<?php echo (int) $i; ?>" class="<?php echo $stage_class($i, $progress ? (int) $progress['stage_index'] : -1); ?>"><?php echo ($progress AND (int) $progress['stage_index'] > $i) ? '<i class="fas fa-check mr-1 text-[10px]"></i>' : ''; ?><?php echo HTML::chars($stage_name); ?></li>
			<?php endforeach; ?>
		</ol>

		<?php if ($progress AND (string) $progress['error'] !== ''): ?>
			<?php // Красным — только провал; у завершённого прогона это замечание (часть материалов не разобрана). ?>
			<p class="n2j-flash <?php echo (string) $progress['status'] === 'fail' ? 'n2j-flash-err' : 'n2j-flash-warn'; ?> mt-3 text-xs block" data-nj661-error><?php echo HTML::chars($progress['error']); ?></p>
		<?php endif; ?>

		<?php $src = (array) ($progress['sources'] ?? []); ?>
		<?php if ($src): ?>
			<?php $src_line = []; foreach ($src as $src_name => $src_n) $src_line[] = $src_name.' '.(int) $src_n; ?>
			<p class="n2j-hint mt-2" data-nj661-sources>Источники: <?php echo HTML::chars(implode(' · ', $src_line)); ?></p>
		<?php endif; ?>
		<?php $src_err = (array) ($progress['source_errors'] ?? []); ?>
		<?php if ($src_err): ?>
			<p class="mt-1 text-xs text-amber-600 dark:text-amber-400">Не отдались: <?php echo HTML::chars(implode(', ', array_keys($src_err))); ?> — причины в логе воркера.</p>
		<?php endif; ?>
	</section>

	<?php
	// Каталог отчётов: дайджест последней публикации + вход в каталог
	// (владелец, 08.10.2026: «такую же страницу-каталог в /airadar»).
	?>
	<section class="n2j-card mt-5">
		<div class="flex flex-wrap items-center gap-x-3 gap-y-2">
			<i class="fas fa-book-open text-base text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
			<h2 class="n2j-h3">Каталог отчётов</h2>
			<span class="n2j-badge n2j-badge-wait">опубликовано: <?php echo $catalog_total; ?></span>
			<a href="/airadar/catalog" class="ml-auto inline-flex min-h-[36px] items-center gap-1.5 text-xs font-semibold text-blue-700 transition hover:underline dark:text-blue-400">
				Все отчёты<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i>
			</a>
		</div>

		<?php if ($latest_published !== NULL): ?>
		<div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-700">
			<p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Свежая публикация</p>
			<a href="/airadar/report/<?php echo (int) $latest_published['id']; ?>" class="mt-0.5 block text-sm font-bold text-gray-900 transition hover:text-blue-600 hover:underline sm:text-base dark:text-white dark:hover:text-blue-400"><?php echo HTML::chars(trim((string) $latest_published['title']) !== '' ? (string) $latest_published['title'] : 'Отчёт #'.(int) $latest_published['id']); ?></a>
			<?php $lead = trim(preg_replace('/\s+/u', ' ', (string) $latest_published['summary'])); ?>
			<p class="n2j-hint"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $latest_published['created_at']))); ?><?php if ($lead !== ''): ?> · <?php echo HTML::chars(mb_substr($lead, 0, 180)); ?><?php echo mb_strlen($lead) > 180 ? '…' : ''; ?><?php endif; ?></p>
		</div>
		<?php else: ?>
		<p class="n2j-hint mt-3 border-t border-gray-100 pt-3">Опубликованных отчётов пока нет — первый же прогон попадёт в каталог.</p>
		<?php endif; ?>
	</section>

	<div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">База знаний</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format((float) Arr::get($c, 'facts', 0), 0, ',', ' '); ?></p>
			<p class="n2j-hint mt-0.5">фактов · за 30 дн. +<?php echo (int) Arr::get($c, 'facts_30d', 0); ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Идеи для N2J</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($c, 'ideas_new', 0); ?> <span class="text-sm font-medium text-gray-400">новых</span></p>
			<p class="n2j-hint mt-0.5">в работе: <?php echo (int) Arr::get($c, 'ideas_accepted', 0); ?> · реализовано: <?php echo (int) Arr::get($c, 'ideas_done', 0); ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Материалы</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($c, 'items_30d', 0); ?></p>
			<p class="n2j-hint mt-0.5">за 30 дн. · всего полезных: <?php echo (int) Arr::get($c, 'items_ok', 0); ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Отчёты</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) Arr::get($c, 'reports', 0); ?></p>
			<p class="n2j-hint mt-0.5">из них письмами: <?php echo (int) Arr::get($c, 'reports_emailed', 0); ?></p>
		</div>
	</div>

	<div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
		<main class="min-w-0 space-y-5">
			<?php if ($report !== NULL): ?>
			<section class="n2j-card">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<h2 class="n2j-h3">Последний отчёт</h2>
					<a href="/airadar/report/<?php echo (int) $report['id']; ?>" class="text-sm font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300">Полный отчёт →</a>
				</div>
				<p class="mt-2 text-base font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) Arr::get($report, 'title', '')); ?></p>
				<p class="n2j-hint"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $report['created_at']))); ?><?php echo ! empty($report['emailed']) ? ' · письмо отправлено' : ''; ?></p>
				<?php $summary = trim((string) Arr::get($report, 'summary', '')); ?>
				<?php if ($summary !== ''): ?><p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-gray-700 dark:text-gray-300"><?php echo HTML::chars($summary); ?></p><?php endif; ?>
				<?php if ($report['highlights']): ?>
					<ul class="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
						<?php foreach ($report['highlights'] as $h): ?><li><?php echo HTML::chars((string) $h); ?></li><?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>
			<?php else: ?>
			<section class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-600 dark:text-gray-500">
				Отчётов ещё нет. Нажмите «Собрать сейчас» — конвейер пройдёт источники, проанализирует находки и соберёт первый отчёт.
			</section>
			<?php endif; ?>

			<?php if ($ov['top_items']): ?>
			<section class="n2j-card">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<h2 class="n2j-h3">Топ находок (30 дней)</h2>
					<a href="/airadar/items" class="text-sm font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300">Все материалы →</a>
				</div>
				<div class="mt-3 space-y-3">
					<?php foreach ($ov['top_items'] as $row): ?>
						<div class="rounded-lg border border-gray-100 p-3 dark:border-gray-700">
							<div class="flex flex-wrap items-baseline gap-2">
								<a href="<?php echo HTML::chars((string) $row['url']); ?>" rel="noopener nofollow" class="text-sm font-semibold text-gray-900 hover:text-blue-700 dark:text-white dark:hover:text-blue-300"><?php echo HTML::chars((string) $row['title']); ?></a>
								<span class="n2j-badge n2j-badge-ok"><?php echo (int) $row['score']; ?>/100</span>
								<span class="n2j-badge n2j-badge-wait"><?php echo HTML::chars((string) Arr::get($categories, (string) $row['category'], (string) $row['category'])); ?></span>
								<span class="text-xs uppercase tracking-wide text-gray-400"><?php echo HTML::chars((string) $row['source']); ?></span>
							</div>
							<?php if ((string) $row['why'] !== ''): ?><p class="n2j-hint"><?php echo HTML::chars((string) $row['why']); ?></p><?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>
		</main>

		<aside class="space-y-4">
			<section class="n2j-card">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<h2 class="n2j-h3">💡 Идеи для N2J</h2>
					<a href="/airadar/ideas" class="text-xs font-semibold text-blue-700 hover:text-blue-900 dark:text-blue-300">Борд →</a>
				</div>
				<?php if ( ! $ov['top_ideas']): ?>
					<p class="mt-3 text-sm text-gray-400 dark:text-gray-500">Идей ещё нет — конвейер предложит, что можно реализовать на платформе.</p>
				<?php else: ?>
					<div class="mt-3 space-y-3">
						<?php foreach ($ov['top_ideas'] as $idea): ?>
							<div class="rounded-lg border border-blue-100 bg-blue-50/50 p-3 dark:border-blue-900 dark:bg-blue-950/20">
								<p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $idea['title']); ?> <span class="text-xs font-bold text-blue-600 dark:text-blue-300">(<?php echo (int) $idea['score']; ?>)</span></p>
								<?php if ((string) $idea['benefit'] !== ''): ?><p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Польза: <?php echo HTML::chars((string) $idea['benefit']); ?></p><?php endif; ?>
								<?php if ((string) $idea['fit'] !== ''): ?><p class="mt-1 text-xs text-blue-700 dark:text-blue-300">Куда: <?php echo HTML::chars((string) $idea['fit']); ?></p><?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="n2j-card">
				<h2 class="n2j-h3">Как работает конвейер</h2>
				<ol class="mt-3 list-decimal space-y-1.5 pl-5 text-xs leading-relaxed text-gray-500 dark:text-gray-400">
					<li><b>Источники</b>: HuggingFace, OpenRouter, RSS ИИ-гигантов, поиск «утки» (DuckDuckGo), lmarena.</li>
					<li><b>Дедуп</b>: виденные URL не обрабатываются заново.</li>
					<li><b>Чтение и анализ LLM</b>: категории, скор пользы, факты (не вода), идеи для N2J.</li>
					<li><b>База знаний</b>: факт повторился → sightings растёт, дубль не создаётся (в т.ч. семантически).</li>
					<li><b>Отчёт и письмо</b>: если есть находка ≥ порога или новая идея — письмо владельцу.</li>
				</ol>
				<p class="n2j-hint mt-3">Расписание — cron дёргает <code class="font-mono">/airadar/api/tick</code>; период и пороги — в конфиге модуля.</p>
			</section>
		</aside>
	</div>
</div>
