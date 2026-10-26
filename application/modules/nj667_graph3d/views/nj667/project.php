<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Рабочее место графа (nj667): 3D-холст, правка, история, публикация.
 *
 * Редактор владельца хранит историю и позволяет править граф. Блок «Описание →
 * граф» оставлен только модератору; остальные пользователи работают с готовым
 * графом и его ручными правками.
 *
 * three.js подключён картой импорта на вендорный файл: CDN в проекте запрещён
 * (проверка assets_cdn_lint), поэтому сборка лежит в /assets/vendor/three/mod.
 *
 * Всё состояние холста живёт в браузере (nodes/edges), сервер получает уже
 * готовый граф и считает свою разность для истории (RFC 6902).
 */

$project    = isset($project) ? (array) $project : [];
$graph      = isset($graph) ? (array) $graph : ['meta' => [], 'nodes' => [], 'edges' => []];
$revisions  = isset($revisions) ? (array) $revisions : [];
$events     = isset($events) ? (array) $events : [];
$can_start  = isset($can_start) ? (bool) $can_start : FALSE;
$can_moderate = isset($can_moderate) ? (bool) $can_moderate : FALSE;
$model      = isset($model) ? (string) $model : '';
$rag        = isset($rag) ? (bool) $rag : TRUE;
$node_types = isset($node_types) ? (array) $node_types : [];
$edge_types = isset($edge_types) ? (array) $edge_types : [];
$limits     = isset($limits) ? (array) $limits : [];
$canvas_cfg = isset($canvas) ? (array) $canvas : [];
$defaults   = isset($defaults) ? (array) $defaults : [];
$rate_text  = isset($rate_text) ? (string) $rate_text : '';
$quota_limits = isset($quota_limits) ? (array) $quota_limits
	: Service_Nj667_Graph3d::quota_limits((int) Arr::get($project, 'user_id', 0));
$daily_quota = isset($daily_quota) && is_array($daily_quota)
	? $daily_quota : Service_Nj667_Graph3d::llm_quota_state(0,
		(int) Arr::get($quota_limits, 'llm_operations_per_day', 5));
$is_owner   = isset($is_owner) ? (bool) $is_owner : TRUE;
// Чужой проект у модератора — только чтение: LLM-операции и правки не предлагаем.
$can_run_llm = $is_owner && $can_moderate && !empty($daily_quota['ready']) && !empty($daily_quota['allowed']);
$csrf       = isset($csrf) ? (string) $csrf : '';
$simple_flow = isset($simple_flow) ? (array) $simple_flow : Service_Nj667_Graph3d::simple_flow_state($project);
$auto_simple_flow = isset($auto_simple_flow) ? (bool) $auto_simple_flow : FALSE;

$code      = (string) Arr::get($project, 'code');
$pending     = isset($pending) ? $pending : NULL;
$published   = isset($published) ? $published : NULL;
$premoderate = isset($premoderate) ? (bool) $premoderate : FALSE;
$reviewing   = (is_array($pending) OR (string) Arr::get($project, 'status', '') === 'review');
$in_catalog  = ((string) Arr::get($project, 'status', '') === 'published' OR is_array($published));
$status      = $reviewing ? 'review'
	: ($in_catalog ? 'published' : (string) Arr::get($project, 'status', 'draft'));
$job_id    = Service_Nj667_Graph3d::live_job_id($project);
// Скобки нужны: «= a AND b» без них присваивает только a — и оверлей
// ожидания висел бы на проекте, где узлы уже есть.
$waiting  = ($job_id > 0 AND ! count((array) Arr::get($graph, 'nodes', [])));
$statuses = [
	'draft'     => ['Черновик', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'],
	'review'    => ['На модерации', 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'],
	'published' => ['Опубликован', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
	'rejected'  => ['Отклонён', 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'],
];
$row    = Arr::get($statuses, $status, $statuses['draft']);
$guard  = Service_Nj667_Graph3d::graph_validate($graph);
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
		<h1 class="n2j-h1 break-words">
			<i class="fas fa-cube mr-1.5 text-blue-500"></i><?php echo HTML::chars((string) Arr::get($project, 'title')); ?>
		</h1>
		<span class="rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $row[1]; ?>"><?php echo HTML::chars($row[0]); ?></span>
	</div>

	<?php echo View::factory('nj667/_nav', ['code' => $code, 'nav' => 'p', 'owner' => TRUE])->render(); ?>

	<?php if ( ! $is_owner): ?>
	<div class="n2j-flash n2j-flash-warn mt-4 flex items-start gap-3 text-xs">
		<i class="fas fa-user-shield mt-0.5 shrink-0"></i>
		<div class="min-w-0 flex-1">
			<p class="font-semibold">Режим контроля: это чужой проект — владелец
				<?php $own_uid = (int) Arr::get($project, 'user_id', 0); ?>
				<?php if ($own_uid > 0): ?><a href="/nj600/admin/owner/u:<?php echo $own_uid; ?>" class="underline">u:<?php echo $own_uid; ?></a>
				<?php else: ?><span class="font-mono"><?php echo HTML::chars((string) Arr::get($project, 'visitor_uid', '—')); ?></span><?php endif; ?>.
				Вы видите граф, ревизии и журнал, потому что у вас право модератора.</p>
			<p class="mt-1 opacity-80">Изменения, откат ревизий и LLM-операции доступны только владельцу — сервер их отклонит.</p>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($auto_simple_flow AND ! empty($simple_flow['enabled'])): ?>
	<div id="nj667-simple-flow" data-code="<?php echo HTML::chars($code); ?>" data-csrf="<?php echo HTML::chars($csrf); ?>"
		role="status" aria-live="polite" class="n2j-flash n2j-flash-info mt-4 flex items-start gap-3 text-xs">
		<svg class="nj667-spin n2j-spin mt-0.5 h-5 w-5 shrink-0 text-blue-500" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
		<div class="min-w-0 flex-1">
			<p id="nj667-simple-flow-text" class="font-semibold">Создаю граф, затем автоматически подготовлю сборку из палочек и детали.</p>
			<p class="mt-1 text-xs opacity-80">Когда сборка появится, открою её автоматически. Рендер можно оставить работать.</p>
		</div>
		<a href="/graph3d/p/<?php echo rawurlencode($code); ?>" class="shrink-0 text-xs font-semibold underline">Остаться на графе</a>
	</div>
	<?php endif; ?>

	<?php if ( ! $waiting AND ! Arr::get($guard, 'ok')): ?>
	<div class="n2j-flash n2j-flash-warn mt-4 flex items-start gap-3 text-xs">
		<i class="fas fa-triangle-exclamation mt-0.5 shrink-0"></i>
		<span>Пока нельзя публиковать: <?php echo HTML::chars(implode(' ', (array) Arr::get($guard, 'errors', []))); ?></span>
	</div>
	<?php endif; ?>

	<?php if ((string) Arr::get($project, 'moderation_note', '') !== ''): ?>
	<div class="mt-4 flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-700 sm:text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
		<i class="fas fa-comment-dots mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars((string) Arr::get($project, 'moderation_note')); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-5 gap-5 lg:grid lg:grid-cols-3 lg:items-start">

		<!-- ==================== Холст (2/3) ==================== -->
		<div class="min-w-0 lg:col-span-2">

			<!-- Холст: панель поверх сцены, ожидание — только здесь. -->
			<div id="nj667-canvas" class="nj667-canvas-box relative h-[420px] w-full overflow-hidden rounded-xl border border-gray-200 bg-white sm:h-[520px] dark:border-gray-800 dark:bg-gray-900">
				<div id="nj667-toolbar" class="relative z-20 flex items-center gap-1 border-b border-gray-200 bg-white/95 px-2 py-1.5 backdrop-blur-sm dark:border-gray-700 dark:bg-gray-900">
					<div id="nj667-mode-group" class="n2j-seg">
						<button type="button" data-mode="translate" class="n2j-seg-item is-active">Двигать</button>
						<button type="button" data-mode="scale" class="n2j-seg-item">Размер</button>
					</div>

					<button type="button" id="nj667-snap-btn" aria-pressed="false" class="n2j-chip py-1.5"><i class="fas fa-magnet mr-1"></i>Магнит</button>
					<button type="button" id="nj667-grid-btn" aria-pressed="false" class="n2j-chip py-1.5"><i class="fas fa-border-all mr-1"></i>Сетка</button>

					<span class="mx-0.5 h-5 w-px bg-gray-300 dark:bg-gray-600"></span>

					<button type="button" id="nj667-add-btn" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-plus mr-1"></i>Узел</button>
					<button type="button" id="nj667-connect-btn" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-link mr-1"></i>Связать</button>
					<button type="button" id="nj667-del-node-btn" disabled class="n2j-btn-danger n2j-btn-sm">Удалить узел</button>
					<button type="button" id="nj667-del-edge-btn" disabled class="n2j-btn-danger n2j-btn-sm">Удалить связь</button>
					<button type="button" id="nj667-undo-btn" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate-left mr-1"></i>Отменить</button>
					<button type="button" id="nj667-redo-btn" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate-right mr-1"></i>Повторить</button>
					<button type="button" id="nj667-save-icon-btn" disabled title="Сохранить правки" aria-label="Сохранить правки"
						class="n2j-btn-icon">
						<i class="fas fa-save text-xs" aria-hidden="true"></i>
					</button>

					<button type="button" id="nj667-full-btn" title="На весь экран" aria-label="На весь экран"
						class="n2j-btn-icon ml-auto">
						<i class="fas fa-expand text-xs" aria-hidden="true"></i>
					</button>
				</div>

				<div id="nj667-stage" class="relative min-h-0 w-full flex-1"></div>

				<div id="nj667-working" class="<?php echo $waiting ? '' : 'hidden '; ?>absolute inset-0 z-10 flex items-center justify-center bg-white/80 px-6 text-center dark:bg-gray-900/80">
					<div>
						<svg class="nj667-spin n2j-spin mx-auto h-10 w-10 text-blue-500 dark:text-blue-300" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
						<p id="nj667-working-title" class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">Модель строит граф</p>
						<p id="nj667-elapsed" class="mt-2 font-mono text-2xl font-semibold tabular-nums tracking-wide text-gray-900 dark:text-white">0:00</p>
					</div>
				</div>

				<div id="nj667-hud" class="pointer-events-none relative z-20 shrink-0 border-t border-gray-200 bg-white/95 px-2 py-1 text-xs text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
					выбрано: <b id="nj667-selected" class="font-semibold text-gray-800 dark:text-white">ничего не выбрано</b>
				</div>
			</div>

			<p id="nj667-note" class="n2j-hint mt-2"></p>

			<?php if ($can_moderate AND $is_owner): ?>
			<!-- Сборка по описанию — только ACL nj667.moderate -->
			<div class="n2j-card mt-5">
				<h2 class="n2j-h3"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Описание → граф</h2>
				<p class="n2j-hint">Опишите изделие словами и/или приложите фото. Модель построит пространственный граф: узлы-детали и связи между ними.</p>
				<p id="nj667-daily-quota" class="n2j-hint mt-2">
					<?php if (!empty($daily_quota['ready'])): ?>
						LLM-операции сегодня: <b><?php echo (int) Arr::get($daily_quota, 'used', 0); ?> / <?php echo (int) Arr::get($daily_quota, 'limit', 5); ?></b>, осталось <?php echo (int) Arr::get($daily_quota, 'remaining', 0); ?> (UTC).
					<?php else: ?>
						<?php echo HTML::chars((string) Arr::get($daily_quota, 'error', 'Дневная квота временно недоступна.')); ?>
					<?php endif; ?>
				</p>
				<?php if ( ! $can_run_llm): ?>
				<p class="mt-1 text-xs font-semibold text-amber-700 dark:text-amber-400">Новая LLM-операция сейчас недоступна; ручное редактирование и экспорт продолжают работать.</p>
				<?php endif; ?>
				<div class="mt-3 flex flex-col gap-2 sm:flex-row">
					<textarea id="nj667-prompt" rows="2" maxlength="<?php echo (int) Arr::get($limits, 'max_prompt', 1000); ?>"
						autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
						placeholder="Например: трёхколёсный велосипед с корзиной сзади"
						class="n2j-textarea min-h-[44px] flex-1 resize-y"></textarea>
					<button type="button" id="nj667-generate-btn" <?php echo $can_run_llm ? '' : 'disabled'; ?>
						class="n2j-btn-process">
						Построить граф
					</button>
				</div>
				<div class="mt-2 flex flex-wrap gap-1.5">
					<button type="button" data-nj667-preset="Исправь ошибки"
						class="n2j-btn-secondary n2j-btn-sm">Исправь ошибки</button>
					<button type="button" data-nj667-preset="Улучши и усовершенствуй"
						class="n2j-btn-secondary n2j-btn-sm">Улучши и усовершенствуй</button>
				</div>
				<div class="mt-2">
					<label for="nj667-photos" class="n2j-label">Фото изделия <span class="font-normal text-gray-400">(до 4 кадров)</span></label>
					<input id="nj667-photos" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple
						class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-300 dark:file:bg-blue-950 dark:file:text-blue-300">
				</div>
				<?php if ($can_moderate): ?>
				<p class="n2j-hint mt-2">модель: <code><?php echo HTML::chars($model !== '' ? $model : 'воркера по умолчанию'); ?></code>
					· <a href="/graph3d/settings" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">настройки</a></p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- LLM-достройка доступна только ACL nj667.moderate. -->
			<?php if ($can_moderate AND $is_owner): ?>
			<div id="nj667-inpaint" class="mt-4 hidden rounded-xl border border-blue-200 bg-blue-50/60 p-4 sm:p-5 dark:border-blue-900 dark:bg-blue-950/30">
				<h2 class="n2j-h3"><i class="fas fa-vector-square mr-1.5 text-blue-500"></i>Достроить выделенное</h2>
				<p class="mt-1 text-xs text-gray-600 dark:text-gray-300">В модель уедет выбранный узел и его соседи — не весь проект. Ответ приклеится к существующему графу по id.</p>
				<div class="mt-3 flex flex-col gap-2 sm:flex-row">
					<input id="nj667-inpaint-prompt" type="text" maxlength="<?php echo (int) Arr::get($limits, 'max_prompt', 1000); ?>"
						placeholder="Например: сделай спортивный руль-баран"
						class="n2j-input flex-1">
					<button type="button" id="nj667-inpaint-btn" <?php echo $can_run_llm ? '' : 'disabled'; ?>
						class="n2j-btn-process">
						Достроить
					</button>
				</div>
			</div>
			<?php endif; ?>

			<div class="mt-4 flex flex-wrap gap-2">
				<button type="button" id="nj667-save-btn" disabled class="n2j-btn-cta">Сохранить правки</button>
				<button type="button" id="nj667-milestone-btn" class="n2j-btn-secondary">Контрольная точка</button>
				<button type="button" id="nj667-publish-btn" <?php echo ($reviewing OR ( ! $premoderate AND $in_catalog)) ? 'disabled' : ''; ?>
					class="min-h-[44px] rounded-lg border border-emerald-300 px-4 text-sm font-semibold text-emerald-700 disabled:opacity-50 dark:border-emerald-800 dark:text-emerald-400"><?php
					if ($premoderate)
					{
						echo $reviewing ? 'На модерации' : 'Отправить на модерацию';
					}
					else
					{
						echo $in_catalog ? 'В каталоге' : 'Опубликовать';
					}
					?></button>
				<button type="button" id="nj667-export-glb-btn" class="n2j-btn-secondary"><i class="fas fa-cube mr-1"></i>Скачать GLB</button>
				<button type="button" id="nj667-export-json-btn" class="n2j-btn-secondary"><i class="fas fa-code mr-1"></i>Скачать JSON</button>
				<button type="button" id="nj667-delete-btn" data-n2j-arm="Удалить проект?" title="Удалить проект" aria-label="Удалить проект"
					class="n2j-btn-icon-danger ml-auto">
					<i class="fas fa-trash-alt text-xs" aria-hidden="true"></i>
				</button>
			</div>
		</div>

		<!-- ==================== Инспектор и история (1/3) ==================== -->
		<div class="mt-5 min-w-0 space-y-5 lg:mt-0">

			<div id="nj667-inspector" class="n2j-card hidden">
				<h2 class="n2j-h3"><i class="fas fa-sliders mr-1.5 text-blue-500"></i>Узел</h2>
				<div class="mt-3 space-y-3">
					<div>
						<label for="nj667-in-type" class="n2j-label">Тип</label>
						<select id="nj667-in-type" class="n2j-input n2j-select mt-1 w-full">
							<?php foreach ($node_types as $key => $info): ?>
							<option value="<?php echo HTML::chars((string) $key); ?>"><?php echo HTML::chars((string) Arr::get($info, 'title', $key)); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<p id="nj667-in-pos" class="n2j-hint font-mono"></p>
				</div>
			</div>

			<div class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 shadow-sm dark:border-blue-900 dark:bg-blue-950/40">
				<h2 class="n2j-h3"><i class="fas fa-route mr-1.5 text-blue-500"></i>Что дальше с этим графом</h2>
				<p class="mt-1.5 text-xs leading-relaxed text-gray-700 dark:text-gray-300">Превратите граф в настоящую вещь: 3D-модель, узлы для печати и чертёж.</p>
				<a href="/graph3d/b/<?php echo HTML::chars($code); ?>" class="n2j-btn-process mt-3"><i class="fas fa-arrow-right" aria-hidden="true"></i>Перейти к изделию</a>
			</div>

			<div class="n2j-card">
				<ul class="space-y-1 text-xs text-gray-600 dark:text-gray-300">
					<li>узлов: <b id="nj667-stat-nodes" class="font-semibold text-gray-900 dark:text-white"><?php echo (int) count(Arr::get($graph, 'nodes', [])); ?></b></li>
					<li>связей: <b id="nj667-stat-edges" class="font-semibold text-gray-900 dark:text-white"><?php echo (int) count(Arr::get($graph, 'edges', [])); ?></b></li>
					<li>лимит: <b class="font-semibold text-gray-900 dark:text-white"><?php echo HTML::chars($rate_text); ?></b></li>
				</ul>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-clock-rotate-left mr-1.5 text-blue-500"></i>История правок</h2>
				<p class="n2j-hint">Каждая правка — патч RFC 6902: «назад» и «вперёд» не ограничены по глубине.</p>
				<div id="nj667-history" class="mt-3 max-h-64 overflow-y-auto text-xs"></div>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-code-branch mr-1.5 text-blue-500"></i>Ревизии на сервере</h2>
				<div class="mt-3 max-h-64 space-y-2 overflow-y-auto text-xs">
					<?php if ( ! $revisions): ?>
					<p class="text-gray-500 dark:text-gray-400">Ревизий пока нет.</p>
					<?php else: ?>
					<ul class="divide-y divide-gray-100 dark:divide-gray-800">
						<?php foreach ($revisions as $rev): ?>
						<li class="py-1.5">
							<div class="flex items-center justify-between gap-2">
								<span class="truncate text-gray-700 dark:text-gray-300">
									<?php echo HTML::chars((string) Arr::get($rev, 'label') ?: ('ревизия №'.(int) Arr::get($rev, 'no'))); ?>
								</span>
								<span class="n2j-badge n2j-badge-wait shrink-0">
									<?php
										$rst = (string) Arr::get($rev, 'status', '');
										echo $rst === 'review' ? 'на модерации'
											: ($rst === 'published' ? 'в каталоге'
											: (Arr::get($rev, 'kind') === 'milestone' ? 'точка' : (Arr::get($rev, 'author_kind') === 'ai' ? 'модель' : 'вручную')));
									?>
								</span>
							</div>
							<div class="mt-0.5 flex items-center gap-2 text-xs text-gray-400">
								<span>№<?php echo (int) Arr::get($rev, 'no'); ?></span>
								<span><?php echo HTML::chars((string) Arr::get($rev, 'created_at')); ?></span>
								<?php if ($is_owner): ?>
								<form method="post" action="/graph3d/p/<?php echo HTML::chars($code); ?>" class="ml-auto inline">
									<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
									<input type="hidden" name="restore" value="<?php echo (int) Arr::get($rev, 'id'); ?>">
									<button type="submit" class="n2j-link">откатить</button>
								</form>
								<form method="post" action="/graph3d/p/<?php echo HTML::chars($code); ?>" class="inline">
									<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
									<input type="hidden" name="forget_revision" value="<?php echo (int) Arr::get($rev, 'id'); ?>">
									<button data-n2j-arm="Удалить?" type="submit" title="Удалить ревизию" aria-label="Удалить ревизию"
										class="n2j-btn-icon-danger h-6 w-6">
										<i class="fas fa-trash-alt text-[10px]" aria-hidden="true"></i>
									</button>
								</form>
								<?php endif; ?>
							</div>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>
			</div>

			<?php if ($events): ?>
			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-list-check mr-1.5 text-blue-500"></i>Журнал</h2>
				<ul class="mt-3 space-y-1.5 text-xs text-gray-600 dark:text-gray-300">
					<?php foreach ($events as $event): ?>
					<li>
						<span class="font-semibold"><?php echo HTML::chars((string) Arr::get($event, 'kind')); ?></span>
						— <?php echo HTML::chars((string) Arr::get($event, 'note')); ?>
						<span class="text-gray-400"><?php echo HTML::chars((string) Arr::get($event, 'created_at')); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php /* design-audit: allow style — холст three.js: тулбар и HUD в колонке над сценой, :fullscreen (и -webkit-) — классом не выразить */ ?>
<style>
	/* Тулбар: днём тёмный текст на светлой полосе (белое на белом не читается). */
	#nj667-canvas.nj667-canvas-box { display: flex; flex-direction: column; }
	#nj667-toolbar {
		background: rgba(255, 255, 255, .95); color: #1f2937;
		flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; -webkit-overflow-scrolling: touch; flex-shrink: 0;
	}
	#nj667-stage { position: relative; flex: 1 1 auto; min-height: 0; }
	#nj667-hud { flex-shrink: 0; background: rgba(255, 255, 255, .95); color: #4b5563; }
	#nj667-hud b { color: #1f2937; }
	#nj667-toolbar button, #nj667-mode-group { flex-shrink: 0; }
	#nj667-toolbar button { color: #1f2937; border-color: #d1d5db; }
	#nj667-toolbar button.bg-blue-600 { color: #fff; border-color: transparent; }
	html.dark #nj667-toolbar { background: #111827; color: #fff; }
	html.dark #nj667-toolbar button { color: #fff; border-color: #4b5563; }
	html.dark #nj667-toolbar button.bg-blue-600 { border-color: transparent; }
	html.dark #nj667-hud { background: #111827; color: #d1d5db; }
	html.dark #nj667-hud b { color: #fff; }
	#nj667-canvas:fullscreen, #nj667-canvas:-webkit-full-screen {
		width: 100%; height: 100%; border-radius: 0; border: 0; background: #fff;
	}
	html.dark #nj667-canvas:fullscreen, html.dark #nj667-canvas:-webkit-full-screen { background: #111827; }
</style>

<script type="importmap">
{
	"imports": {
		"three": "/assets/vendor/three/mod/three.module.js",
		"three/addons/": "/assets/vendor/three/mod/addons/"
	}
}
</script>

<script type="application/json" id="nj667-config">
<?php echo json_encode([
	'code'       => $code,
	'csrf'       => $csrf,
	'title'      => (string) Arr::get($project, 'title', ''),
	'job_id'     => $job_id,
	'graph'      => $graph,
	'limits'     => $limits,
	'canvas'     => $canvas_cfg,
	'defaults'   => $defaults,
	'node_types' => $node_types,
	'edge_types' => $edge_types,
	'rag'         => $rag ? TRUE : FALSE,
	'review'      => $reviewing ? TRUE : FALSE,
	'published'   => $in_catalog ? TRUE : FALSE,
	'premoderate' => $premoderate ? TRUE : FALSE,
	'llm_allowed' => $can_run_llm ? TRUE : FALSE,
	'quota'       => $daily_quota,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
</script>

<script type="module">
	window.NJ667 = JSON.parse(document.getElementById('nj667-config').textContent);
</script>
<script type="module" src="/assets/js/nj667-graph3d.js?v=20261015"></script>
<?php if ($auto_simple_flow AND ! empty($simple_flow['enabled'])): ?>
<script>
(function () {
	var box = document.getElementById('nj667-simple-flow');
	var label = document.getElementById('nj667-simple-flow-text');
	if (!box || !label) { return; }

	var code = box.getAttribute('data-code') || '';
	var csrf = box.getAttribute('data-csrf') || '';
	var tries = 0;

	function fail(message) {
		var spinner = box.querySelector('svg');
		if (spinner) { spinner.style.display = 'none'; }
		label.textContent = message || 'Автоматический маршрут остановился. Граф проекта сохранён — сборку можно запустить отдельно.';
		box.classList.remove('n2j-flash-info');
		box.classList.add('n2j-flash-warn');
	}

	function poll() {
		tries++;
		fetch('/graph3d/api/status?code=' + encodeURIComponent(code) + '&csrf=' + encodeURIComponent(csrf), {
			headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
			credentials: 'same-origin'
		})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (!data || !data.ok) {
					fail(data && data.error ? data.error : 'Не удалось проверить состояние проекта.');
					return;
				}

				var flow = data.simple_flow || {};
				var buildId = Number(flow.build_id || 0);
				var state = String(flow.state || 'waiting');

				if (!flow.enabled) {
					fail('Автоматический маршрут не найден. Граф проекта сохранён — сборку можно запустить отдельно.');
					return;
				}

				if (buildId > 0) {
					label.textContent = 'Граф готов. Открываю сборку из палочек и комплект деталей…';
					window.location.replace('/graph3d/b/' + encodeURIComponent(code) + '?build=' + buildId + '&auto=1');
					return;
				}

				if (state === 'error' || state === 'partial') {
					fail(flow.error || 'Автоматическая сборка не запустилась. Граф проекта сохранён.');
					return;
				}

				var nodes = Number(data.nodes || 0);
				var job = data.job || {};
				var jobState = String(job.status || job.state || '').toLowerCase();
				var jobFailed = ['failed', 'error', 'cancelled'].indexOf(jobState) >= 0;

				if (nodes > 0) {
					label.textContent = 'Граф готов. Рассчитываю палочки и запускаю сборку деталей…';
				} else if (jobFailed || (Number(data.job_id || 0) <= 0 && tries > 2)) {
					fail(job.error || 'Граф не удалось построить. Попробуйте упростить описание или откройте проект и запустите генерацию ещё раз.');
					return;
				} else {
					label.textContent = 'Модель создаёт граф по описанию…';
				}

				if (tries < 360) { window.setTimeout(poll, 2000); }
				else { fail('Маршрут ещё работает. Проект сохранён — вернитесь на страницу сборки чуть позже.'); }
			})
			.catch(function () {
				if (tries < 360) { window.setTimeout(poll, 3000); }
				else { fail('Не удалось получить состояние проекта. Обновите страницу или откройте сборку позже.'); }
			});
	}

	poll();
})();
</script>
<?php endif; ?>
