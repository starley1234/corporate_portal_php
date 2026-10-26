<?php defined('SYSPATH') or die('No direct script access.');
$process = isset($process) ? (array) $process : [];
$version = isset($version) ? $version : NULL;
$graph = isset($graph) ? (array) $graph : ['lanes' => [], 'nodes' => [], 'edges' => []];
$metrics = isset($metrics) ? (array) $metrics : [];
$ontology = isset($ontology) ? (array) $ontology : [];
$kind = isset($kind) ? (string) $kind : 'plan';
$statuses = isset($statuses) ? (array) $statuses : [];
$roles = (array) Arr::path($ontology, 'list.role', []);
$systems = (array) Arr::path($ontology, 'list.system', []);
$artifacts = (array) Arr::path($ontology, 'list.artifact', []);
$version_status = $version ? (string) $version['status'] : '';
$select = 'n2j-input w-full py-1.5 n2j-ds-sm min-h-0';
?>
<?php /* design-audit: allow style — SVG-холст схемы: заливки и штрихи узлов по типам, порты, связи, полноэкранный режим — классом не выразить */ ?>
<style>
	.nj670-canvas-shell{position:relative;min-height:560px;overflow:hidden;border-radius:.75rem;background:#f9fafb;background-image:radial-gradient(#d1d5db 1px,transparent 1px);background-size:22px 22px;}
	#nj670-canvas{display:block;width:100%;height:640px;touch-action:none;cursor:grab;}
	/* Полноэкранный холст: только схема, без палитры и панелей. */
	body.nj670-fullscreen{overflow:hidden;}
	.nj670-canvas-shell.is-fullscreen{position:fixed;inset:0;z-index:80;border-radius:0;min-height:0;height:100vh;width:100vw;border:0;}
	.nj670-canvas-shell.is-fullscreen #nj670-canvas{height:100vh;}
	.nj670-canvas-shell.is-fullscreen .nj670-full-hint{display:block;}
	.nj670-full-hint{display:none;position:absolute;left:12px;bottom:12px;z-index:2;border-radius:8px;background:rgba(17,24,39,.82);padding:6px 10px;font:600 12px 'Segoe UI',Arial,sans-serif;color:#e5e7eb;pointer-events:none;}
	.nj670-full-tools{display:none;position:absolute;right:12px;top:12px;z-index:3;gap:6px;}
	.nj670-canvas-shell.is-fullscreen .nj670-full-tools{display:flex;}
	.nj670-full-tools button{border-radius:8px;background:rgba(17,24,39,.82);padding:7px 11px;font:600 12px 'Segoe UI',Arial,sans-serif;color:#f3f4f6;border:1px solid rgba(156,163,175,.35);}
	#nj670-canvas.is-dragging{cursor:grabbing;}
	.nj670-node{transition:filter .12s ease;}
	.nj670-node:hover{filter:drop-shadow(0 3px 6px rgba(17,24,39,.18));}
	.nj670-token-dot{fill:#1d4ed8;stroke:#fff;stroke-width:2;filter:drop-shadow(0 0 6px rgba(29,78,216,.6));}
	.nj670-token-ring{fill:none;stroke:#93c5fd;stroke-width:2;opacity:.7;}
	.nj670-node.is-selected .nj670-shape{stroke:#1e40af;stroke-width:3;}
	.nj670-node.is-selected .nj670-title{fill:#172554;}
	.nj670-shape{stroke:#9ca3af;stroke-width:1.5;fill:#fff;}
	.nj670-node[data-type="start"] .nj670-shape,.nj670-node[data-type="end"] .nj670-shape{fill:#eef4f0;stroke:#5f8a72;}
	.nj670-node[data-type="end"] .nj670-shape{fill:#f8eeee;stroke:#a06a6a;stroke-width:2.2;}
	.nj670-node[data-type="service_task"] .nj670-shape{fill:#eef1f6;stroke:#7186a3;}
	.nj670-node[data-type="manual_task"] .nj670-shape{fill:#f8f3e9;stroke:#9c8660;}
	.nj670-node[data-type="gateway_xor"] .nj670-shape,.nj670-node[data-type="gateway_and"] .nj670-shape{fill:#f7f3e2;stroke:#99803c;}
	.nj670-node[data-type="timer"] .nj670-shape{fill:#edf3f5;stroke:#6b8b96;}
	.nj670-title{font:600 11.5px/1.25 'Segoe UI',Arial,sans-serif;fill:#1f2937;pointer-events:none;}
	.nj670-meta{font:9.5px/1.25 'Segoe UI',Arial,sans-serif;fill:#6b7280;pointer-events:none;}
	.nj670-badge{font:700 9px 'Segoe UI',Arial,sans-serif;fill:#7a5a24;pointer-events:none;}
	.nj670-link{fill:none;stroke:#9ca3af;stroke-width:1.6;}
	.nj670-link.is-conditional{stroke:#99803c;stroke-dasharray:6 4;}
	.nj670-link.is-loop{stroke:#a06a6a;stroke-dasharray:4 4;}
	.nj670-link.is-hot{stroke:#a94b5e;}
	.nj670-arrow{fill:#9ca3af;}
	.nj670-edge-label{font:600 10px/1.2 'Segoe UI',Arial,sans-serif;fill:#4b5563;pointer-events:none;paint-order:stroke;stroke:#fff;stroke-width:2.6px;stroke-linejoin:round;}
	.nj670-lane{fill:#ffffff;stroke:#e5e7eb;}
	.nj670-lane-label{font:700 10px 'Segoe UI',Arial,sans-serif;fill:#374151;letter-spacing:.02em;}
	.nj670-port{fill:#fff;stroke:#9ca3af;stroke-width:2;cursor:crosshair;}
	.nj670-empty{font:12px 'Segoe UI',Arial,sans-serif;fill:#9ca3af;}
</style>

<div class="n2j-ds is-125 w-full px-3.5 pt-3 pb-10 sm:px-6 lg:px-8">

	<?php if ( ! empty($parent_process)): ?>
	<div class="mb-3 flex flex-wrap items-center gap-1.5 n2j-ds-xs text-gray-500 dark:text-gray-400">
		<span class="font-semibold uppercase tracking-wide text-gray-400">Входит в</span>
		<a href="/process/<?php echo HTML::chars($parent_process['code']); ?>" class="rounded bg-gray-100 px-2 py-0.5 font-semibold text-gray-700 hover:bg-gray-200 hover:text-gray-900 dark:bg-gray-800 dark:text-gray-200"><i class="fas fa-diagram-project mr-1"></i><?php echo HTML::chars($parent_process['title']); ?></a>
		<?php foreach ((array) $child_processes as $child): ?>
		<a href="/process/<?php echo HTML::chars($child['code']); ?>" class="rounded bg-gray-100 px-2 py-0.5 font-semibold text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300"><i class="fas fa-turn-up fa-rotate-90 mr-1"></i><?php echo HTML::chars($child['title']); ?></a>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
	<?php if ( ! empty($notice)): ?>
	<div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 n2j-ds-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"><i class="fas fa-circle-check mr-1"></i><?php echo HTML::chars($notice); ?></div>
	<?php endif; ?>
	<?php if ( ! empty($warn)): ?>
	<div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 n2j-ds-sm font-medium text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"><i class="fas fa-triangle-exclamation mr-1"></i><?php echo HTML::chars($warn); ?></div>
	<?php endif; ?>

	<div class="flex flex-wrap items-start justify-between gap-3">
		<div class="min-w-0">
			<div class="flex flex-wrap items-center gap-2 n2j-ds-xs text-gray-500 dark:text-gray-400">
				<a href="/process" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">ProcessHub</a>
				<span>/</span>
				<span class="font-mono"><?php echo HTML::chars($process['code']); ?></span>
				<?php if ( ! empty($process['is_demo'])): ?><span class="rounded-full bg-gray-100 px-2 py-0.5 font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">ДЕМО</span><?php endif; ?>
			</div>
			<h1 class="n2j-h1 mt-0.5 truncate"><?php echo HTML::chars($process['title']); ?></h1>
			<div class="mt-1 flex flex-wrap items-center gap-2 n2j-ds-xs">
				<span class="rounded-full bg-gray-100 px-2 py-0.5 font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($statuses, $version_status, 'без версии')); ?></span>
				<?php if ($version): ?><span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars($version['version_tag']); ?></span><?php endif; ?>
				<span class="text-gray-500 dark:text-gray-400"><?php echo HTML::chars((string) Arr::get($process, 'owner_role_name', 'владелец не задан')); ?></span>
			</div>
		</div>
		<div class="flex flex-wrap items-center gap-1.5">
			<a href="/process/<?php echo HTML::chars($process['code']); ?>/fact" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-database mr-1.5 text-gray-400"></i>Факт</a>
			<a href="/process/<?php echo HTML::chars($process['code']); ?>/audit" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-shield-halved mr-1.5 text-gray-400"></i>AI-аудит</a>
			<a href="/process/<?php echo HTML::chars($process['code']); ?>/report" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-file-word mr-1.5 text-gray-400"></i>Регламент</a>
			<button type="button" id="nj670-save-btn" class="n2j-btn-cta n2j-btn-sm" <?php echo $version ? '' : 'disabled'; ?>><i class="fas fa-floppy-disk mr-1.5"></i><span id="nj670-save-text">Сохранить</span></button>
			<?php if ($kind === 'plan' AND $version AND ! empty($can_start) AND in_array($version_status, [Service_Nj670_Process::S_DRAFT, Service_Nj670_Process::S_IN_REVIEW], TRUE)): ?>
			<form method="post" action="/process/<?php echo HTML::chars($process['code']); ?>/approve" class="inline">
				<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
				<input type="hidden" name="version_id" value="<?php echo (int) $version['id']; ?>">
				<button type="submit" data-n2j-confirm="Утвердить версию <?php echo HTML::chars($version['version_tag']); ?>? Текущая активная версия уйдёт в архив." data-n2j-tone="cta" data-n2j-ok="Утвердить" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-check-double mr-1.5 text-emerald-600 dark:text-emerald-400"></i>Утвердить план</button>
			</form>
			<?php endif; ?>
		</div>
	</div>

	<div class="mt-3 flex flex-wrap items-center justify-between gap-2">
		<div class="n2j-seg inline-flex">
			<a href="/process/<?php echo HTML::chars($process['code']); ?>?kind=plan" class="n2j-seg-item<?php echo $kind === 'plan' ? ' is-active' : ''; ?>"><i class="fas fa-file-pen mr-1"></i>План (To-Be)</a>
			<a href="/process/<?php echo HTML::chars($process['code']); ?>?kind=fact" class="n2j-seg-item<?php echo $kind === 'fact' ? ' is-active' : ''; ?>"><i class="fas fa-timeline mr-1"></i>Факт (As-Is)</a>
		</div>
		<div class="flex flex-wrap items-center gap-1.5">
			<div class="inline-flex items-center rounded-lg border border-gray-300 bg-white p-0.5 shadow-sm dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="История правок">
				<button type="button" id="nj670-undo-btn" class="n2j-btn-icon" disabled title="Отменить"><i class="fas fa-rotate-left"></i></button>
				<button type="button" id="nj670-redo-btn" class="n2j-btn-icon" disabled title="Повторить"><i class="fas fa-rotate-right"></i></button>
			</div>
			<div class="inline-flex items-center rounded-lg border border-gray-300 bg-white p-0.5 shadow-sm dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Масштаб и просмотр">
				<button type="button" id="nj670-zoom-out" class="n2j-btn-icon" title="Уменьшить"><i class="fas fa-magnifying-glass-minus"></i></button>
				<button type="button" id="nj670-zoom-in" class="n2j-btn-icon" title="Увеличить"><i class="fas fa-magnifying-glass-plus"></i></button>
				<button type="button" id="nj670-fit-btn" class="n2j-btn-icon" title="Показать всю схему (0)"><i class="fas fa-arrows-to-dot"></i></button>
				<button type="button" id="nj670-play-btn" class="rounded-md px-2 py-1.5 n2j-ds-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700" title="Прогнать токен по схеме" aria-pressed="false"><i class="fas fa-play"></i></button>
				<button type="button" id="nj670-full-btn" class="rounded-md px-2 py-1.5 n2j-ds-sm font-semibold text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700" title="На весь экран (F)" aria-pressed="false"><i class="fas fa-expand"></i></button>
			</div>
			<div class="inline-flex items-center rounded-lg border border-gray-300 bg-white p-0.5 shadow-sm dark:border-gray-700 dark:bg-gray-800" role="group" aria-label="Экспорт и анализ">
				<a href="/process/<?php echo HTML::chars($process['code']); ?>/bpmn?kind=<?php echo HTML::chars($kind); ?>" class="n2j-btn-icon" title="Скачать BPMN 2.0 XML" download><i class="fas fa-file-export"></i></a>
				<a href="/process/<?php echo HTML::chars($process['code']); ?>/plantuml?kind=<?php echo HTML::chars($kind); ?>" class="n2j-btn-icon" title="Скачать PlantUML для Confluence" download><i class="fas fa-seedling"></i></a>
				<a href="/process/<?php echo HTML::chars($process['code']); ?>/simulate?kind=<?php echo HTML::chars($kind); ?>" class="n2j-btn-icon" title="Что если: загрузка ролей и очередь"><i class="fas fa-flask"></i></a>
			</div>
			<a href="/process/<?php echo HTML::chars($process['code']); ?>/passport" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-id-card mr-1"></i>Паспорт</a>
		</div>
	</div>

	<div class="mt-3 grid gap-3 xl:grid-cols-12">

		<aside class="space-y-3 xl:col-span-2">
			<section class="n2j-card p-3">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Палитра BPMN</h2>
				<p class="mt-1 n2j-ds-xs text-gray-400">Нажмите, чтобы добавить в центр холста.</p>
				<div class="mt-2 grid grid-cols-2 gap-1.5">
					<?php
					$palette_tone = [
						'emerald' => 'text-emerald-600 dark:text-emerald-400',
						'rose' => 'text-rose-600 dark:text-rose-400',
						'slate' => 'text-gray-500 dark:text-gray-400',
						'indigo' => 'text-blue-500 dark:text-blue-400',
						'amber' => 'text-amber-600 dark:text-amber-400',
						'yellow' => 'text-amber-700 dark:text-amber-500',
						'cyan' => 'text-blue-700 dark:text-blue-400',
						'teal' => 'text-emerald-700 dark:text-emerald-400',
					];
					?>
					<?php foreach ([
						['start', 'Начало', 'fa-play', 'emerald'],
						['end', 'Конец', 'fa-flag-checkered', 'rose'],
						['user_task', 'Задача', 'fa-user', 'slate'],
						['service_task', 'Авто', 'fa-robot', 'indigo'],
						['manual_task', 'Вручную', 'fa-hand', 'amber'],
						['gateway_xor', 'XOR', 'fa-code-branch', 'yellow'],
						['gateway_and', 'AND', 'fa-equals', 'yellow'],
						['timer', 'Таймер', 'fa-clock', 'cyan'],
						['subprocess', 'Подпроцесс', 'fa-diagram-project', 'teal'],
					] as $palette): ?>
					<button type="button" data-add="<?php echo $palette[0]; ?>" class="n2j-btn-secondary n2j-btn-sm text-left"><i class="fas <?php echo $palette[2]; ?> <?php echo Arr::get($palette_tone, $palette[3], $palette_tone['slate']); ?>"></i><?php echo HTML::chars($palette[1]); ?></button>
					<?php endforeach; ?>
				</div>
				<button type="button" id="nj670-link-btn" aria-pressed="false" class="n2j-chip mt-2 w-full justify-center"><i class="fas fa-link mr-1"></i>Связать шаги</button>
				<p class="mt-1 n2j-ds-xs text-gray-400">Режим: выберите источник, затем приёмник. Esc — выход.</p>
			</section>

			<section class="n2j-card p-3">
				<div class="flex items-center justify-between gap-2">
					<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Проверка схемы</h2>
					<span id="nj670-issue-count" class="n2j-badge n2j-badge-wait">—</span>
				</div>
				<ul id="nj670-issues" class="mt-2 space-y-1 n2j-ds-xs leading-snug text-gray-500 dark:text-gray-400">
					<li>Проверяем связи…</li>
				</ul>
			</section>

			<section class="n2j-card p-3">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Показатели</h2>
				<dl class="mt-2 space-y-1.5 n2j-ds-xs">
					<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Срок</dt><dd id="nj670-stat-elapsed" class="font-bold text-gray-900 dark:text-white"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) Arr::get($metrics, 'elapsed_minutes', 0))); ?></dd></div>
					<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Работа</dt><dd id="nj670-stat-work" class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) Arr::get($metrics, 'work_minutes', 0))); ?></dd></div>
					<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Ожидание</dt><dd id="nj670-stat-wait" class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars(Service_Nj670_Repo::minutes_text((int) Arr::get($metrics, 'wait_minutes', 0))); ?></dd></div>
					<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Стоимость</dt><dd id="nj670-stat-cost" class="font-semibold text-gray-800 dark:text-gray-200"><?php echo HTML::chars(Service_Nj670_Repo::money((float) Arr::get($metrics, 'cost_rub', 0))); ?></dd></div>
					<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Шаги / связи</dt><dd id="nj670-stat-nodes" class="font-semibold text-gray-800 dark:text-gray-200"><?php echo (int) Arr::get($metrics, 'nodes_count', 0); ?> / <?php echo (int) Arr::get($metrics, 'edges_count', 0); ?></dd></div>
					<div class="flex justify-between gap-2"><dt class="text-gray-500 dark:text-gray-400">Петли / теневые</dt><dd class="font-semibold text-gray-800 dark:text-gray-200"><span id="nj670-stat-loops"><?php echo (int) Arr::get($metrics, 'loop_count', 0); ?></span> / <span id="nj670-stat-shadow"><?php echo (int) Arr::get($metrics, 'shadow_count', 0); ?></span></dd></div>
				</dl>
				<div class="mt-2 rounded-lg bg-gray-50 p-2 n2j-ds-xs leading-relaxed text-gray-500 dark:bg-gray-800 dark:text-gray-400" id="nj670-bottleneck-box">
					<?php $bottleneck = Arr::get($metrics, 'bottleneck'); ?>
					<?php if ($bottleneck): ?>
					<i class="fas fa-hourglass-half mr-1 text-rose-500"></i>Узкое место: <span class="font-semibold text-gray-700 dark:text-gray-200"><?php echo HTML::chars((string) $bottleneck['title']); ?></span> — <?php echo round((float) $bottleneck['share'] * 100, 1); ?> % срока.
					<?php else: ?>
					Узких мест по ожиданию нет.
					<?php endif; ?>
				</div>
			</section>
		</aside>

		<section class="xl:col-span-7">
			<div class="nj670-canvas-shell border border-gray-200 shadow-sm dark:border-gray-800">
				<svg id="nj670-canvas" role="img" aria-label="Схема бизнес-процесса"></svg>
				<div class="nj670-full-tools">
					<button type="button" id="nj670-full-exit" title="Выйти из полноэкранного режима"><i class="fas fa-compress mr-1"></i>Выйти</button>
				</div>
				<div class="nj670-full-hint">F — выйти · Ctrl+S — сохранить · колесо — масштаб · Shift+колесо — сдвиг · «+»/«−»/«0» — масштаб и вписать · перетаскивание — панорама</div>
			</div>
			<div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 n2j-ds-xs text-gray-500 dark:text-gray-400">
				<span class="font-semibold uppercase tracking-wide">Оверлеи аудита:</span>
				<span class="inline-flex items-center gap-1"><span class="inline-flex h-3.5 w-3.5 items-center justify-center rounded-full bg-rose-700 text-xs font-bold text-white">!</span>риск</span>
				<span class="inline-flex items-center gap-1"><span class="inline-flex h-3.5 w-3.5 items-center justify-center rounded-full bg-emerald-700 text-xs font-bold text-white">✓</span>контроль</span>
				<span class="inline-flex items-center gap-1"><span class="inline-flex h-3.5 w-3.5 items-center justify-center rounded-full bg-amber-700 text-xs font-bold text-white">?</span>вопрос</span>
				<span class="inline-flex items-center gap-1"><span class="inline-flex h-3.5 w-3.5 items-center justify-center rounded-full bg-gray-500 text-xs font-bold text-white">i</span>идея</span>
				<span class="inline-flex items-center gap-1"><i class="fas fa-comment-dots"></i>комментарий</span>
				<span class="text-gray-400 dark:text-gray-500">— выберите шаг и заполните поля в инспекторе; всё попадёт в регламент.</span>
			</div>
			<div class="mt-2 flex flex-wrap items-center justify-between gap-2 n2j-ds-xs">
				<div id="nj670-status" class="font-medium text-gray-500 dark:text-gray-400">Готово. Перетаскивайте шаги, связи — кнопкой «Связать».</div>
				<div class="flex flex-wrap items-center gap-1.5 text-gray-500 dark:text-gray-400">
					<?php
					$job = isset($job) ? (array) $job : [];
					$job_active = Service_Nj670_Process::job_running($job);
					?>
					<span id="nj670-worker" data-elapsed="<?php echo (int) Arr::get($job, 'elapsed_sec', 0); ?>" class="<?php echo $job_active ? 'inline-flex' : 'hidden'; ?> items-center gap-1.5 rounded-full bg-blue-50 px-2 py-0.5 font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300" title="Фоновый воркер обрабатывает задачу">
						<span class="n2j-dots text-blue-500" aria-hidden="true"><i></i><i></i><i></i></span>
						<span id="nj670-worker-text"><?php echo HTML::chars((string) ($job_active ? (string) Arr::get($job, 'stage', 'воркер работает') : 'воркер работает')); ?></span>
						<span id="nj670-worker-time" class="font-mono text-blue-500 dark:text-blue-300/80"></span>
					</span>
					<span id="nj670-version-note"><?php echo $version ? 'Версия '.HTML::chars($version['version_tag']) : 'Версия не создана'; ?></span>
					<?php if ($kind === 'fact'): ?><span class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700 dark:bg-gray-800 dark:text-gray-300">факт</span><?php endif; ?>
				</div>
			</div>
			<?php if ( ! $version): ?>
			<div class="mt-2 rounded-lg border border-gray-200 bg-gray-50 p-3 n2j-ds-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
				<i class="fas fa-circle-info mr-1 text-gray-400"></i>У процесса ещё нет такой версии. Соберите план из текста или загрузите журнал на странице «Факт».
			</div>
			<?php endif; ?>
		</section>

		<aside class="space-y-3 xl:col-span-3">
			<section class="n2j-card p-3">
				<div class="flex items-center justify-between gap-2">
					<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">Инспектор шага</h2>
					<button type="button" id="nj670-del-btn" class="n2j-btn-danger n2j-btn-sm" disabled><i class="fas fa-trash"></i></button>
				</div>
				<p id="nj670-insp-empty" class="mt-2 n2j-ds-xs text-gray-400">Выберите шаг на холсте.</p>
				<div id="nj670-insp" class="mt-2 hidden space-y-2">
					<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Название
						<input id="nj670-insp-title" maxlength="255" class="<?php echo $select; ?>" autocomplete="off">
					</label>
					<div class="grid grid-cols-2 gap-2">
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Тип
							<select id="nj670-insp-type" class="n2j-input n2j-select w-full py-1.5 n2j-ds-sm min-h-0">
								<?php foreach (Service_Nj670_Process::node_types() as $code => $label): ?>
								<option value="<?php echo HTML::chars($code); ?>"><?php echo HTML::chars($label); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Роль
							<select id="nj670-insp-role" class="n2j-input n2j-select w-full py-1.5 n2j-ds-sm min-h-0">
								<option value="">— нет —</option>
								<?php foreach ($roles as $role): ?><option value="<?php echo HTML::chars($role['code']); ?>"><?php echo HTML::chars($role['name']); ?></option><?php endforeach; ?>
							</select>
						</label>
					</div>
					<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Система
						<select id="nj670-insp-system" class="n2j-input n2j-select w-full py-1.5 n2j-ds-sm min-h-0">
							<option value="">— нет —</option>
							<?php foreach ($systems as $system): ?><option value="<?php echo HTML::chars($system['code']); ?>"><?php echo HTML::chars($system['name']); ?></option><?php endforeach; ?>
						</select>
					</label>
					<div class="grid grid-cols-2 gap-2">
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Вход
							<select id="nj670-insp-input" class="n2j-input n2j-select w-full py-1.5 n2j-ds-sm min-h-0">
								<option value="">— нет —</option>
								<?php foreach ($artifacts as $artifact): ?><option value="<?php echo HTML::chars($artifact['code']); ?>"><?php echo HTML::chars($artifact['name']); ?></option><?php endforeach; ?>
							</select>
						</label>
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Выход
							<select id="nj670-insp-output" class="n2j-input n2j-select w-full py-1.5 n2j-ds-sm min-h-0">
								<option value="">— нет —</option>
								<?php foreach ($artifacts as $artifact): ?><option value="<?php echo HTML::chars($artifact['code']); ?>"><?php echo HTML::chars($artifact['name']); ?></option><?php endforeach; ?>
							</select>
						</label>
					</div>
					<div class="grid grid-cols-2 gap-2">
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Работа, мин
							<input id="nj670-insp-duration" type="number" min="0" max="100000" step="5" class="<?php echo $select; ?>">
						</label>
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Ожидание, мин
							<input id="nj670-insp-wait" type="number" min="0" max="1000000" step="5" class="<?php echo $select; ?>">
						</label>
					</div>
					<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Вызывает процесс <span class="font-normal text-gray-400">(для подпроцесса)</span>
						<select id="nj670-insp-process" class="n2j-input n2j-select w-full py-1.5 n2j-ds-sm min-h-0">
							<option value="">— не выбран —</option>
							<?php foreach ((array) $siblings as $sibling): ?>
								<?php if ((int) $sibling['id'] === (int) $process['id']) { continue; } ?>
								<option value="<?php echo HTML::chars($sibling['code']); ?>"><?php echo HTML::chars($sibling['title']); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Условие перехода / примечание
						<input id="nj670-insp-cond" maxlength="300" class="<?php echo $select; ?>" placeholder="например: сумма > 500 000 ₽">
					</label>
					<div class="grid grid-cols-2 gap-2">
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400">Оверлей аудита
							<select id="nj670-insp-overlay" class="n2j-input n2j-select w-full py-1.5 n2j-ds-sm min-h-0">
								<?php
								$overlay_labels = [
									'' => '— нет —',
									'risk' => '⚠ Риск',
									'control' => '🛡 Контроль',
									'question' => '❓ Вопрос',
									'idea' => '💡 Идея',
								];
								foreach ($overlay_labels as $overlay_code => $overlay_label): ?>
								<option value="<?php echo HTML::chars($overlay_code); ?>"><?php echo HTML::chars($overlay_label); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="block n2j-ds-xs font-semibold text-gray-500 dark:text-gray-400" title="Виден на холсте и в регламенте">Комментарий ревьюера
							<textarea id="nj670-insp-comment" maxlength="500" rows="2" class="n2j-textarea w-full n2j-ds-sm resize-y" placeholder="что проверить или исправить"></textarea>
						</label>
					</div>
					<button type="button" id="nj670-insp-apply" class="n2j-btn-process n2j-btn-sm w-full" disabled>Применить к шагу</button>
					<p id="nj670-insp-meta" class="n2j-ds-xs leading-relaxed text-gray-400"></p>
				</div>
			</section>

			<section class="rounded-xl border border-blue-100 bg-blue-50/50 p-3 shadow-sm dark:border-blue-900/60 dark:bg-blue-950/20">
				<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300"><i class="fas fa-wand-magic-sparkles mr-1"></i>AI Copilot</h2>
				<p class="mt-1 n2j-ds-xs leading-relaxed text-gray-600 dark:text-gray-300/70">Выберите шаг и опишите правку. Модель вернёт изменённый фрагмент, координаты остальных шагов сохранятся.</p>
				<textarea id="nj670-copilot-text" rows="3" maxlength="2000" class="n2j-textarea mt-2 w-full resize-y n2j-ds-sm" placeholder="Добавь после юриста проверку СБ на 2 дня и свяжи её с оплатой."></textarea>
				<button type="button" id="nj670-copilot-run" class="n2j-btn-process n2j-btn-sm mt-1.5 w-full" <?php echo $version ? '' : 'disabled'; ?>><i class="fas fa-paper-plane mr-1"></i>Дополнить схему</button>
				<p id="nj670-copilot-note" class="mt-1.5 n2j-ds-xs text-gray-600 dark:text-gray-300/70"></p>
			</section>

			<section class="n2j-card p-3">
				<div class="flex items-center justify-between gap-2">
					<h2 class="n2j-ds-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400">История версий</h2>
					<a href="/process/<?php echo HTML::chars($process['code']); ?>/versions" class="n2j-ds-xs font-semibold text-blue-600 hover:underline dark:text-blue-400">сравнить</a>
				</div>
				<ul class="mt-2 space-y-1 n2j-ds-xs">
					<?php foreach (array_slice((array) $versions, 0, 6) as $row): ?>
					<li class="flex items-center justify-between gap-2 rounded bg-gray-50 px-2 py-1 dark:bg-gray-800">
						<span class="font-mono text-gray-700 dark:text-gray-200"><?php echo HTML::chars($row['version_tag']); ?></span>
						<span class="text-gray-400"><?php echo HTML::chars((string) Arr::get($statuses, (string) $row['status'], $row['status'])); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
		</aside>
	</div>

	<div id="nj670-conflict" class="n2j-flash n2j-flash-warn mt-3 hidden block p-3 n2j-ds-sm"></div>
</div>

<script>
window.NJ670 = <?php echo json_encode([
	'code'       => (string) $process['code'],
	'kind'       => $kind,
	'versionId'  => $version ? (int) $version['id'] : 0,
	'graph'      => $graph,
	'metrics'    => $metrics,
	'csrf'       => (string) $csrf,
	'api'        => '/process/api',
	'statuses'   => $statuses,
	'nodeTypes'  => Service_Nj670_Process::node_types(),
	'canSave'    => (bool) ($version AND $can_start AND ! in_array($version_status, ['queued', 'archived'], TRUE)),
	'updatedAt'  => $version ? (string) Arr::get($version, 'updated_at', '') : '',
	'planCode'   => (string) $process['code'],
	'siblings'   => array_values(array_map(function ($row) {
		return ['code' => (string) $row['code'], 'title' => (string) $row['title']];
	}, (array) $siblings)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php echo View::factory('670/script-canvas')->render(); ?>
