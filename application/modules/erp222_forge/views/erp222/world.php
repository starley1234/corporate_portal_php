<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/**
 * /forge/world/<code> — страница мира: постановка, ход, выпущенное, история.
 *
 * Зачем отдельная страница мира, если есть дашборд: у мира своя постановка и
 * своя история. Здесь видно, из чего роли исходили (задумка, цель, протокол),
 * что делают прямо сейчас (ход цикла), что уже выпустили (артефакты с ценностью)
 * и как это было (история циклов и лента событий). Дашборд — про «запустить»,
 * эта страница — про «разобраться».
 *
 * Дизайн — по эталону /pages/required_design_sample.
 *
 * @var array  $world     мир
 * @var array  $roles     роли мира (code, title, focus, types[])
 * @var array  $artifacts артефакты мира (последние)
 * @var array  $runs      история циклов
 * @var array|NULL $run   текущий или последний цикл
 * @var array|NULL $progress ход цикла (run_progress)
 * @var array  $events    лента событий мира
 * @var array  $types     словарь типов
 * @var int    $steps_default
 * @var int    $steps_max
 * @var string $csrf
 * @var array  $flash
 */
$prog_live = (bool) ($progress AND $progress['live']);
$status    = (string) $world['status'];
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<?php if ( ! empty($flash['text'])): ?>
	<div class="n2j-flash <?php echo $flash['tone'] === 'error' ? 'n2j-flash-err' : 'n2j-flash-ok'; ?> mb-5">
		<i class="fas <?php echo $flash['tone'] === 'error' ? 'fa-circle-exclamation text-rose-600 dark:text-rose-400' : 'fa-circle-check text-emerald-600 dark:text-emerald-400'; ?> shrink-0 text-base"></i>
		<span class="flex-1"><?php echo HTML::chars((string) $flash['text']); ?></span>
	</div>
	<?php endif; ?>

	<nav class="flex items-center overflow-x-auto whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" aria-label="Хлебные крошки">
		<a href="/forge" class="transition hover:text-blue-600 dark:hover:text-blue-400">Кузница систем</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<a href="/forge/worlds" class="transition hover:text-blue-600 dark:hover:text-blue-400">Миры</a>
		<span class="mx-1.5 text-gray-300 dark:text-gray-600">/</span>
		<span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo HTML::chars((string) $world['title']); ?></span>
	</nav>

	<!-- ============ Шапка мира ============ -->
	<div class="mt-3 flex flex-wrap items-start justify-between gap-4">
		<div class="min-w-0">
			<h1 class="n2j-h1 flex flex-wrap items-center gap-3">
				<i class="fas fa-globe text-blue-600 dark:text-blue-400" aria-hidden="true"></i><?php echo HTML::chars((string) $world['title']); ?>
				<?php if ($status === 'active'): ?>
				<span class="n2j-badge n2j-badge-ok"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>активен</span>
				<?php else: ?>
				<span class="n2j-badge n2j-badge-run">на паузе</span>
				<?php endif; ?>
			</h1>
			<div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-400 dark:text-gray-500">
				<span class="font-mono"><?php echo HTML::chars((string) $world['code']); ?></span>
				<?php if (trim((string) $world['domain']) !== ''): ?>
				<span><i class="fas fa-tag mr-1 text-[11px]" aria-hidden="true"></i><?php echo HTML::chars((string) $world['domain']); ?></span>
				<?php endif; ?>
				<span>создан <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $world['created_at']))); ?></span>
				<span>обновлён <?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $world['updated_at']))); ?></span>
			</div>
		</div>

		<div class="flex flex-wrap items-center gap-2">
			<a href="/forge/worlds" class="n2j-btn-secondary">
				<i class="fas fa-arrow-left text-[13px]" aria-hidden="true"></i>Все миры
			</a>
			<form method="post" action="/forge/world/<?php echo HTML::chars((string) $world['code']); ?>/status">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="status" value="<?php echo $status === 'active' ? 'paused' : 'active'; ?>">
				<button type="submit" class="n2j-btn-secondary">
					<i class="fas <?php echo $status === 'active' ? 'fa-pause' : 'fa-play'; ?> text-[13px]" aria-hidden="true"></i><?php echo $status === 'active' ? 'Поставить на паузу' : 'Вернуть в работу'; ?>
				</button>
			</form>
		</div>
	</div>

	<div class="mt-5 grid gap-5 lg:grid-cols-3">

		<!-- ============ Левая колонка: постановка, ход, артефакты ============ -->
		<div class="space-y-5 lg:col-span-2">

			<!-- Постановка -->
			<section class="n2j-card">
				<h2 class="n2j-h3 flex items-center gap-2">
					<i class="fas fa-crosshairs text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Постановка
				</h2>
				<div class="mt-3 space-y-4 text-sm leading-relaxed">
					<div>
						<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Задумка</p>
						<p class="mt-1 whitespace-pre-wrap break-words text-gray-700 dark:text-gray-200"><?php echo HTML::chars(trim((string) $world['brief']) !== '' ? (string) $world['brief'] : '— не описана, мир живёт на одном названии'); ?></p>
					</div>
					<?php if (trim((string) $world['goal']) !== ''): ?>
					<div>
						<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Цель</p>
						<p class="mt-1 whitespace-pre-wrap break-words text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $world['goal']); ?></p>
					</div>
					<?php endif; ?>
					<?php if (trim((string) $world['protocol']) !== ''): ?>
					<div>
						<p class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">Правила</p>
						<p class="mt-1 whitespace-pre-wrap break-words text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $world['protocol']); ?></p>
					</div>
					<?php endif; ?>
				</div>

				<h3 class="mt-5 border-t border-gray-100 pt-4 text-xs font-bold uppercase tracking-wider text-gray-400 dark:border-gray-700/60 dark:text-gray-500">Роли мира</h3>
				<div class="mt-2 grid gap-2 sm:grid-cols-2">
					<?php foreach ($roles as $r): ?>
					<div class="rounded-lg border border-gray-200 bg-gray-50/50 p-3 dark:border-gray-700/70 dark:bg-gray-900/30">
						<div class="flex items-center gap-2">
							<i class="<?php echo HTML::chars((string) Arr::path(Service_Erp222_Forge::roles(), $r['code'].'.icon', 'fas fa-user')); ?> text-xs text-blue-600 dark:text-blue-400" aria-hidden="true"></i>
							<span class="text-xs font-bold text-gray-900 dark:text-white"><?php echo HTML::chars((string) $r['title']); ?></span>
							<?php if ( ! $r['enabled']): ?><span class="text-xs text-gray-400">выключена</span><?php endif; ?>
						</div>
						<p class="n2j-hint"><?php echo HTML::chars((string) $r['focus']); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</section>

			<!-- Ход цикла -->
			<section class="rounded-xl border <?php echo $prog_live
					? 'border-blue-200/80 bg-blue-50/40 dark:border-blue-900/60 dark:bg-blue-950/20'
					: 'border-gray-200 bg-white dark:border-gray-700/80 dark:bg-gray-800'; ?> p-4 shadow-xs sm:p-5"
					data-erp222-run="<?php echo $progress ? (int) $progress['id'] : 0; ?>"
					data-erp222-status="<?php echo $progress ? HTML::chars($progress['status']) : ''; ?>"
					data-erp222-elapsed="<?php echo $progress ? (int) $progress['elapsed_sec'] : 0; ?>"
					data-erp222-stage-index="<?php echo $progress ? (int) $progress['stage_index'] : -1; ?>"
					data-erp222-empty="<?php echo $progress ? '0' : '1'; ?>"
					<?php echo $progress ? '' : ' hidden'; ?>>
				<div class="flex flex-wrap items-start justify-between gap-3">
					<h2 class="n2j-h3 flex flex-wrap items-center gap-2">
						<i class="fas fa-industry text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Ход цикла
						<span class="font-mono text-xs font-normal text-gray-400 dark:text-gray-500" data-erp222-run-id<?php echo $progress ? '' : ' hidden'; ?>>#<?php echo $progress ? (int) $progress['id'] : ''; ?></span>
						<?php // Роль текущего шага: строка всегда в разметке (скрыта без роли) — движок показывает её без перезагрузки. ?>
						<span class="n2j-badge n2j-badge-info"
							data-erp222-role<?php echo ($progress AND (string) $progress['role_title'] !== '') ? '' : ' hidden'; ?>><?php echo $progress ? HTML::chars($progress['role_title']) : ''; ?></span>
					</h2>
					<span class="n2j-badge <?php echo $prog_live ? 'n2j-badge-run' : 'n2j-badge-wait'; ?>" data-erp222-status-pill><?php echo $progress ? ($prog_live ? 'идёт' : ($progress['status'] === 'done' ? 'завершён' : 'остановлен')) : ''; ?></span>
				</div>

				<?php if ($progress): ?>
				<div class="mt-3.5 flex flex-wrap items-center gap-x-2.5 gap-y-1">
					<i class="fas <?php echo $prog_live ? 'fa-spinner animate-spin text-blue-600 dark:text-blue-400'
							: ($progress['status'] === 'done' ? 'fa-circle-check text-emerald-500' : 'fa-triangle-exclamation text-rose-500'); ?> text-sm" data-erp222-spin aria-hidden="true"></i>
					<span class="text-sm font-bold text-gray-900 dark:text-white" data-erp222-stage><?php echo HTML::chars($progress['stage']); ?></span>
					<span class="n2j-badge n2j-badge-info font-mono tabular-nums" data-erp222-elapsed-text><?php echo HTML::chars($progress['elapsed_text']); ?></span>
					<span class="n2j-badge n2j-badge-wait" data-erp222-counter><?php echo HTML::chars($progress['counters_text']); ?></span>
				</div>
				<p class="mt-1.5 text-xs text-gray-600 dark:text-gray-300" data-erp222-stage-note><?php echo HTML::chars($progress['stage_note']); ?></p>
				<?php if ((string) $progress['error'] !== ''): ?>
				<p class="n2j-flash n2j-flash-err mt-3 text-xs block" data-erp222-error><?php echo HTML::chars($progress['error']); ?></p>
				<?php endif; ?>
				<?php else: ?>
				<p class="n2j-hint mt-2">Ещё не запускался ни один цикл. Нажмите «Запустить цикл» — ход появится здесь.</p>
				<?php endif; ?>
			</section>

			<!-- Запуск -->
			<section class="n2j-card">
				<h2 class="n2j-h3">Запустить цикл</h2>
				<p class="n2j-hint mt-1.5">Каждый шаг — один вызов модели одной ролью. Роли идут по кругу от Архитектора к Редактору; Критик по ходу снижает ценность слабых артефактов, поэтому итог каталога — не сырые черновики.</p>

				<form method="post" action="/forge/step" class="mt-4 flex flex-wrap items-end gap-3"
					  data-erp222-run-form data-erp222-progress="/forge/progress">
					<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
					<input type="hidden" name="world_id" value="<?php echo (int) $world['id']; ?>">
					<input type="hidden" name="back" value="/forge/world/<?php echo HTML::chars((string) $world['code']); ?>">

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

				<?php if ($status !== 'active'): ?>
				<p class="n2j-flash n2j-flash-warn mt-3 text-xs block">Мир на паузе: цикл не запустится, пока не вернёте его в работу. Выпущенное никуда не девается.</p>
				<?php endif; ?>
			</section>

			<!-- Артефакты мира -->
			<section class="n2j-card p-0">
				<div class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-gray-700/60">
					<h2 class="n2j-h3 flex items-center gap-2">
						<i class="fas fa-cubes text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Выпущено в этом мире
					</h2>
					<span class="n2j-badge n2j-badge-wait"><?php echo count($artifacts); ?></span>
					<a href="/forge/artifacts" class="ml-auto inline-flex min-h-[36px] items-center gap-1.5 text-xs font-semibold text-blue-600 transition hover:underline dark:text-blue-400">Каталог<i class="fas fa-arrow-right text-[11px]" aria-hidden="true"></i></a>
				</div>

				<?php if (empty($artifacts)): ?>
				<p class="px-4 py-8 text-center text-sm text-gray-500 sm:px-5 dark:text-gray-400">Пока пусто: первый цикл создаст спецификацию, схему данных и остальные документы.</p>
				<?php else: ?>
				<div class="divide-y divide-gray-100 dark:divide-gray-700/60">
					<?php foreach ($artifacts as $a): ?>
					<?php $a = Service_Erp222_Forge::decorate($a); ?>
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
								<?php if ( ! $a['published']): ?>
								<span class="rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-300">снят с публикации</span>
								<?php endif; ?>
								<?php if (trim((string) $a['critique']) !== ''): ?>
								<span class="text-amber-600 dark:text-amber-400"><i class="fas fa-scale-balanced mr-1 text-[11px]" aria-hidden="true"></i>разобран</span>
								<?php endif; ?>
							</div>
							<h3 class="mt-1 text-sm font-bold leading-snug text-gray-900 dark:text-white">
								<a href="/forge/artifact/<?php echo (int) $a['id']; ?>" class="transition hover:text-blue-600 hover:underline dark:hover:text-blue-400"><?php echo HTML::chars((string) $a['title']); ?></a>
							</h3>
							<?php $lead = trim((string) $a['summary']); ?>
							<?php if ($lead !== ''): ?>
							<p class="n2j-hint mt-0.5"><?php echo HTML::chars(mb_strlen($lead) > 200 ? mb_substr($lead, 0, 200).'…' : $lead); ?></p>
							<?php endif; ?>
						</div>
						<span class="n2j-badge n2j-badge-ok shrink-0" title="<?php echo HTML::chars($a['score_word']); ?>"><?php echo (int) $a['value_score']; ?></span>
					</article>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
			</section>
		</div>

		<!-- ============ Правая колонка: итоги, история, лента ============ -->
		<aside class="space-y-4">

			<div class="n2j-card">
				<h3 class="text-sm font-bold text-gray-900 dark:text-white">Итоги мира</h3>
				<dl class="mt-3 grid grid-cols-2 gap-3 text-center">
					<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
						<dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Артефактов</dt>
						<dd class="mt-0.5 text-xl font-bold text-gray-900 dark:text-white"><?php echo (int) $world['artifacts_done']; ?></dd>
					</div>
					<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
						<dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Шагов</dt>
						<dd class="mt-0.5 text-xl font-bold text-gray-900 dark:text-white"><?php echo (int) $world['steps_done']; ?></dd>
					</div>
					<div class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-950/30">
						<dt class="text-xs font-semibold uppercase tracking-wider text-emerald-700/70 dark:text-emerald-300/70">Ценность</dt>
						<dd class="mt-0.5 text-xl font-bold text-emerald-700 dark:text-emerald-300"><?php echo (int) $world['value_total']; ?></dd>
					</div>
					<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-900/40">
						<dt class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">Циклов</dt>
						<dd class="mt-0.5 text-xl font-bold text-gray-900 dark:text-white"><?php echo count($runs); ?></dd>
					</div>
				</dl>
				<p class="n2j-hint mt-2">Ценность — сумма оценок выпущенных артефактов: чем ближе к 100 у каждого, тем полезнее набор.</p>
			</div>

			<div class="n2j-card">
				<h3 class="text-sm font-bold text-gray-900 dark:text-white">История циклов</h3>
				<?php if (empty($runs)): ?>
				<p class="n2j-hint mt-2">Циклов ещё не было.</p>
				<?php else: ?>
				<ol class="mt-2.5 space-y-2">
					<?php foreach ($runs as $r): ?>
					<?php $r_live = in_array((string) $r['status'], ['new', 'running'], TRUE); ?>
					<li class="rounded-lg border border-gray-200 p-2.5 dark:border-gray-700/70">
						<div class="flex flex-wrap items-center gap-2 text-xs">
							<span class="font-mono text-gray-400 dark:text-gray-500">#<?php echo (int) $r['id']; ?></span>
							<span class="rounded-md px-2 py-0.5 font-semibold <?php
								echo $r_live
									? 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200'
									: ((string) $r['status'] === 'done'
										? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300'
										: 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300');
							?>"><?php echo HTML::chars((string) $r['status']); ?></span>
							<span class="text-gray-400 dark:text-gray-500"><?php echo HTML::chars(date('d.m.Y H:i', strtotime((string) $r['created_at']))); ?></span>
						</div>
						<p class="n2j-hint">шагов: <?php echo (int) $r['steps_done']; ?> из <?php echo (int) $r['steps_planned']; ?> · артефактов: <?php echo (int) $r['artifacts_new']; ?><?php echo trim((string) $r['trigger']) !== '' ? ' · запуск: '.HTML::chars((string) $r['trigger']) : ''; ?></p>
						<?php if (trim((string) $r['error']) !== ''): ?>
						<p class="mt-1 text-xs leading-relaxed text-rose-600 dark:text-rose-400"><?php echo HTML::chars(mb_substr(trim((string) $r['error']), 0, 160)); ?></p>
						<?php endif; ?>
					</li>
					<?php endforeach; ?>
				</ol>
				<?php endif; ?>
			</div>

			<div class="n2j-card">
				<h3 class="flex items-center gap-2 text-sm font-bold text-gray-900 dark:text-white">
					<i class="fas fa-list-check text-blue-600 dark:text-blue-400" aria-hidden="true"></i>Что происходило
				</h3>
				<?php if (empty($events)): ?>
				<p class="n2j-hint mt-2">Событий нет.</p>
				<?php else: ?>
				<ol class="mt-2.5 space-y-2">
					<?php foreach ($events as $e): ?>
					<li class="flex items-start gap-2.5">
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
			</div>
		</aside>
	</div>
</div>
