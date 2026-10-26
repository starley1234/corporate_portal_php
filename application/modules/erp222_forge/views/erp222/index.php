<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /forge — дашборд «Кузницы систем».
 *
 * Что видит владелец:
 *   1. ХОД ЦИКЛА — статус-ряд по дизайн-эталону /pages/required_design_sample:
 *      спиннер + этап + секундомер одним НЕРАЗРЫВНЫМ рядом (правило 4), полос
 *      хода нет, «сколько» — числом. Лестница этапов текстом: Роли → Синтез →
 *      Критика → Сводка. Ряд ведёт erp222-forge.js: сразу после нажатия кнопки
 *      (fetch без перезагрузки) и опросом /forge/progress раз в 3 секунды.
 *   2. ЧТО СДЕЛАНО — счётчики (миры, артефакты, средняя ценность, доля
 *      разобранного Критиком) и свежие артефакты с оценкой.
 *   3. С ЧЕГО НАЧАТЬ — кнопки запуска: «Запустить цикл» (N шагов) и «Один шаг».
 *      Шаг = один вызов LLM, поэтому длину забега владелец выбирает сам.
 *
 * @var array  $ov             сводка (world, worlds, run, counts, latest, events)
 * @var array  $counts         счётчики сервиса
 * @var array  $latest         свежие артефакты (карточки)
 * @var array  $events         лента событий
 * @var array|NULL $world      выбранный мир
 * @var array|NULL $run        последний цикл
 * @var array|NULL $progress   ход цикла (run_progress)
 * @var array  $roles          словарь ролей
 * @var array  $types          словарь типов артефактов
 * @var int    $steps_default  сколько шагов предлагаем по умолчанию
 * @var int    $steps_max      верхняя граница шагов
 * @var string $csrf           токен форм
 * @var array  $flash          ['tone' => notice|error, 'text' => …]
 */
$prog_live = (bool) ($progress AND $progress['live']);
$prog_done = (bool) ($progress AND $progress['status'] === 'done');
$pill_text = ! $progress ? '' : ($prog_live ? 'идёт' : ($prog_done ? 'завершён' : 'остановлен'));

$stage_class = function ($i, $stage_index) {
	if ($stage_index < 0 OR $i > $stage_index)
	{
		return 'text-gray-400 dark:text-gray-500';
	}

	return $i === $stage_index
		? 'font-bold text-blue-700 dark:text-blue-300'
		: 'text-emerald-600 dark:text-emerald-400';
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8" data-erp222-view>

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<!-- ============ Шапка ============ -->
	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<span class="font-semibold text-gray-700 dark:text-gray-300">Кузница систем</span>
	</nav>

	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3">
				<i class="fas fa-hammer text-blue-600 dark:text-blue-400"></i>Кузница систем
			</h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
				Группа LLM-ролей проектирует систему по вашей задумке и выпускает готовые артефакты: спецификацию, схему данных, контракт API, алгоритм, промпт, план проверки, риски, метрики, оценку и оффер. У каждого — ценность от Критика, а не «нравится/не нравится».
			</p>
		</div>
		<div class="flex flex-wrap items-center gap-2">
			<a href="/forge/worlds" class="n2j-btn-secondary">
				<i class="fas fa-globe text-[13px]" aria-hidden="true"></i>Миры
			</a>
			<a href="/forge/artifacts" class="n2j-btn-process">
				<i class="fas fa-cubes text-[13px]" aria-hidden="true"></i>Каталог артефактов
			</a>
		</div>
	</div>

	<!-- ============ Ход цикла ============ -->
	<section class="mt-5 rounded-xl border <?php echo $prog_live
			? 'border-blue-200/80 bg-blue-50/40 dark:border-blue-900/60 dark:bg-blue-950/20'
			: 'border-gray-200 bg-white dark:border-gray-700/80 dark:bg-gray-800'; ?> p-4 shadow-xs sm:p-5"
			data-erp222-run="<?php echo $progress ? (int) $progress['id'] : 0; ?>"
			data-erp222-status="<?php echo $progress ? HTML::chars($progress['status']) : ''; ?>"
			data-erp222-elapsed="<?php echo $progress ? (int) $progress['elapsed_sec'] : 0; ?>"
			data-erp222-stage-index="<?php echo $progress ? (int) $progress['stage_index'] : -1; ?>"
			data-erp222-empty="<?php echo $progress ? '0' : '1'; ?>"
			<?php echo $progress ? '' : ' hidden'; ?>>

		<div class="flex flex-wrap items-start justify-between gap-3">
			<div>
				<h2 class="n2j-h3 flex flex-wrap items-center gap-2">
					<i class="fas fa-industry text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Ход цикла
					<span class="font-mono text-xs font-normal text-gray-400 dark:text-gray-500" data-erp222-run-id<?php echo $progress ? '' : ' hidden'; ?>>#<?php echo $progress ? (int) $progress['id'] : ''; ?></span>
					<?php // Роль текущего шага: строка всегда в разметке (скрыта без роли), чтобы движок мог показать её без перезагрузки. ?>
					<span class="n2j-badge n2j-badge-info"
						data-erp222-role<?php echo ($progress AND (string) $progress['role_title'] !== '') ? '' : ' hidden'; ?>><?php echo $progress ? HTML::chars($progress['role_title']) : ''; ?></span>
				</h2>
				<p class="n2j-hint">Каждый шаг — один артефакт от одной роли. Страница обновит список сама, когда цикл закончится.</p>
			</div>
			<span class="n2j-badge <?php echo $prog_live ? 'n2j-badge-run' : 'n2j-badge-wait'; ?>" data-erp222-status-pill><?php echo HTML::chars($pill_text); ?></span>
		</div>

		<?php // Неразрывный статус-ряд: спиннер, этап и секундомер всегда рядом. ?>
		<div class="mt-3.5 flex flex-wrap items-center gap-x-2.5 gap-y-1">
			<i class="fas <?php echo $prog_live ? 'fa-spinner animate-spin text-blue-600 dark:text-blue-400'
					: ($prog_done ? 'fa-circle-check text-emerald-500' : 'fa-triangle-exclamation text-rose-500'); ?> text-sm"
				data-erp222-spin aria-hidden="true"></i>
			<span class="text-sm font-bold text-gray-900 dark:text-white" data-erp222-stage><?php echo $progress ? HTML::chars($progress['stage']) : 'Готовлю роли мира…'; ?></span>
			<span class="n2j-badge n2j-badge-info font-mono tabular-nums" data-erp222-elapsed-text><?php echo $progress ? HTML::chars($progress['elapsed_text']) : '0:00'; ?></span>
			<span class="n2j-badge n2j-badge-wait" data-erp222-counter><?php echo $progress ? HTML::chars($progress['counters_text']) : 'ставлю задачу в очередь…'; ?></span>
		</div>

		<p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300" data-erp222-stage-note><?php echo $progress ? HTML::chars($progress['stage_note']) : ''; ?></p>

		<?php // Лестница этапов — текстом, без полос: пройденные зелёные, текущий жирный. ?>
		<ol class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" data-erp222-stages>
			<?php foreach (Service_Erp222_Forge::$stages as $i => $stage_name): ?>
			<li data-erp222-stage-item="<?php echo (int) $i; ?>" class="<?php echo $stage_class($i, $progress ? (int) $progress['stage_index'] : -1); ?>"><?php echo ($progress AND (int) $progress['stage_index'] > $i) ? '<i class="fas fa-check mr-1 text-[10px]"></i>' : ''; ?><?php echo HTML::chars($stage_name); ?></li>
			<?php endforeach; ?>
		</ol>

		<?php if ($progress AND (string) $progress['error'] !== ''): ?>
		<p class="n2j-flash n2j-flash-err mt-3 text-xs block" data-erp222-error><?php echo HTML::chars($progress['error']); ?></p>
		<?php endif; ?>
	</section>

	<!-- ============ Запуск ============ -->
	<section class="n2j-card mt-5">
		<h2 class="n2j-h3">Что делаем дальше</h2>
		<p class="n2j-hint mt-1.5">
			Шаг — это один вызов модели одной ролью. Цикл из <?php echo (int) $steps_default; ?> шагов успевает пройти все роли, включая Критика и Редактора, поэтому в наборе появляется не только замысел, но и разбор с редактурой.
		</p>

		<?php if (empty($ov['worlds'])): ?>
		<p class="mt-4 rounded-lg border border-dashed border-gray-300 bg-gray-50/60 px-4 py-3 text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
			Миров ещё нет. <a href="/forge/worlds" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Создайте первый мир</a> — опишите, что строим, и запустите цикл.
		</p>
		<?php else: ?>
		<form method="post" action="/forge/step" class="mt-4 flex flex-wrap items-end gap-3"
			  data-erp222-run-form data-erp222-progress="/forge/progress">
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
			<input type="hidden" name="back" value="/forge">

			<div class="min-w-0 flex-1 sm:max-w-md">
				<label for="erp222-world" class="n2j-label">Мир</label>
				<select id="erp222-world" name="world_id" class="n2j-input n2j-select mt-1.5 w-full cursor-pointer">
					<?php foreach ($ov['worlds'] as $w): ?>
					<option value="<?php echo (int) $w['id']; ?>" <?php echo ($world AND (int) $world['id'] === (int) $w['id']) ? 'selected' : ''; ?>>
						<?php echo HTML::chars((string) $w['title']); ?><?php echo (string) $w['status'] !== 'active' ? ' (на паузе)' : ''; ?> · артефактов: <?php echo (int) Arr::get($w, 'artifacts', 0); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div>
				<label for="erp222-steps" class="n2j-label">Шагов в цикле</label>
				<input id="erp222-steps" type="number" name="steps" min="1" max="<?php echo (int) $steps_max; ?>" value="<?php echo (int) $steps_default; ?>"
					   class="n2j-input mt-1.5 w-28">
			</div>

			<button type="submit" class="n2j-btn-process" data-erp222-run-button>
				<i class="fas fa-play text-[13px]" aria-hidden="true"></i><span data-erp222-run-label>Запустить цикл</span>
			</button>

			<button type="submit" name="quick" value="one" class="n2j-btn-secondary" data-erp222-one-step>
				<i class="fas fa-shoe-prints text-[13px]" aria-hidden="true"></i>Один шаг
			</button>
		</form>
		<p class="n2j-hint mt-2">Цикл идёт в фоне: можно уйти со страницы и вернуться — ход сохранится в истории мира.</p>
		<?php endif; ?>
	</section>

	<!-- ============ Счётчики ============ -->
	<div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Миры</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) $counts['worlds']; ?></p>
			<p class="n2j-hint mt-0.5">активных: <?php echo (int) $counts['worlds_active']; ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Артефакты</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) $counts['artifacts']; ?></p>
			<p class="n2j-hint mt-0.5">в каталоге: <?php echo (int) $counts['published']; ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Средняя ценность</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) $counts['value_avg']; ?><span class="text-sm font-semibold text-gray-400">/100</span></p>
			<p class="n2j-hint mt-0.5">суммарно: <?php echo (int) $counts['value_total']; ?></p>
		</div>
		<div class="n2j-card">
			<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Разобрано Критиком</p>
			<p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?php echo (int) $counts['critiqued']; ?></p>
			<p class="n2j-hint mt-0.5">из <?php echo (int) $counts['artifacts']; ?> артефактов</p>
		</div>
	</div>

	<div class="mt-5 grid gap-4 lg:grid-cols-3">
		<!-- ============ Свежие артефакты ============ -->
		<section class="n2j-card p-0 lg:col-span-2">
			<div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
				<h2 class="n2j-h3 flex items-center gap-2">
					<i class="fas fa-cubes text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Свежие артефакты
				</h2>
				<a href="/forge/artifacts" class="ml-auto inline-flex min-h-[36px] items-center gap-1.5 text-xs font-semibold text-blue-600 transition hover:underline dark:text-blue-400">
					Весь каталог<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i>
				</a>
			</div>

			<?php if (empty($latest)): ?>
			<p class="px-4 py-6 text-sm text-gray-500 sm:px-5 dark:text-gray-400">Артефактов пока нет — запустите цикл, и роли начнут выпускать документы.</p>
			<?php else: ?>
			<div class="divide-y divide-gray-100 dark:divide-gray-700/60">
				<?php foreach ($latest as $a): ?>
				<article class="flex flex-wrap items-start gap-3 px-4 py-3.5 transition-colors hover:bg-gray-50/70 sm:px-5 dark:hover:bg-gray-700/20">
					<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400">
						<i class="<?php echo HTML::chars((string) Arr::path($types, $a['type'].'.icon', 'fas fa-file-lines')); ?>" aria-hidden="true"></i>
					</div>
					<div class="min-w-0 flex-1">
						<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
							<span class="rounded-md bg-gray-100 px-2 py-0.5 font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300"><?php echo HTML::chars($a['type_title']); ?></span>
							<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars($a['role_title']); ?></span>
							<span class="text-gray-300 dark:text-gray-600">·</span>
							<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $a['created_at']))); ?></span>
						</div>
						<h3 class="mt-1 text-sm font-bold leading-snug text-gray-900 dark:text-white">
							<a href="/forge/artifact/<?php echo (int) $a['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400"><?php echo HTML::chars((string) $a['title']); ?></a>
						</h3>
						<?php $lead = trim((string) $a['summary']); ?>
						<?php if ($lead !== ''): ?>
						<p class="n2j-hint mt-0.5"><?php echo HTML::chars(mb_substr($lead, 0, 200)); ?><?php echo mb_strlen($lead) > 200 ? '…' : ''; ?></p>
						<?php endif; ?>
					</div>
					<span class="n2j-badge n2j-badge-ok shrink-0" title="<?php echo HTML::chars($a['score_word']); ?>"><?php echo (int) $a['value_score']; ?></span>
				</article>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</section>

		<!-- ============ Лента событий ============ -->
		<section class="n2j-card p-0">
			<div class="border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
				<h2 class="n2j-h3 flex items-center gap-2">
					<i class="fas fa-list-check text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Что происходило
				</h2>
			</div>
			<?php if (empty($events)): ?>
			<p class="n2j-hint px-4 py-6 sm:px-5">Событий нет: первый цикл покажет здесь шаги, критику и остановки.</p>
			<?php else: ?>
			<ol class="divide-y divide-gray-100 dark:divide-gray-700/60">
				<?php foreach ($events as $e): ?>
				<li class="flex items-start gap-2.5 px-4 py-2.5 sm:px-5">
					<i class="fas <?php
						switch ((string) $e['kind'])
						{
							case 'artifact': echo 'fa-file-circle-plus text-emerald-500'; break;
							case 'critique': echo 'fa-scale-balanced text-amber-500'; break;
							case 'error':    echo 'fa-circle-exclamation text-rose-500'; break;
							case 'run':      echo 'fa-play text-blue-500'; break;
							default:         echo 'fa-circle-small text-gray-400'; break;
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

	<!-- ============ Как это работает ============ -->
	<section class="n2j-card mt-5">
		<h2 class="n2j-h3">Роли и что от них приходит</h2>
		<p class="n2j-hint mt-1.5">Порядок ролей — это порядок мысли: сначала что строим, потом как, потом чем мерим, потом что сломается, и только затем — редактура в готовые к работе документы.</p>
		<div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
			<?php foreach ($roles as $code => $role): ?>
			<div class="rounded-lg border border-gray-200 bg-gray-50/50 p-3.5 dark:border-gray-700/70 dark:bg-gray-900/30">
				<div class="flex items-center gap-2">
					<i class="<?php echo HTML::chars((string) Arr::get($role, 'icon', 'fas fa-user')); ?> text-sm text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
					<span class="text-sm font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $role['title']); ?></span>
				</div>
				<p class="n2j-hint mt-1.5"><?php echo HTML::chars((string) $role['focus']); ?></p>
				<p class="mt-2 flex flex-wrap gap-1">
					<?php foreach ((array) $role['types'] as $t): ?>
					<span class="rounded-md bg-white px-1.5 py-0.5 text-xs font-semibold text-gray-500 shadow-xs dark:bg-gray-800 dark:text-gray-400"><?php echo HTML::chars((string) Arr::path($types, $t.'.title', $t)); ?></span>
					<?php endforeach; ?>
				</p>
			</div>
			<?php endforeach; ?>
		</div>
	</section>
</div>
