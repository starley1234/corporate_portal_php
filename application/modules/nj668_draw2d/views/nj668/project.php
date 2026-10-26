<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Рабочее место иллюстрации (nj668): SVG-холст, правка, публикация.
 *
 * Порядок: описал тему → модель собрала сцену → потягал фигуры → сохранил →
 * опубликовал. Статус и ошибка живут на холсте, не баннером над ним.
 */

$project    = isset($project) ? (array) $project : [];
$scene      = isset($scene) ? (array) $scene : Service_Nj668_Scene::scene_new();
$revisions  = isset($revisions) ? (array) $revisions : [];
$can_start  = isset($can_start) ? (bool) $can_start : FALSE;
$limits     = isset($limits) ? (array) $limits : [];
$rate_text  = isset($rate_text) ? (string) $rate_text : '';
$csrf       = isset($csrf) ? (string) $csrf : '';

$code      = (string) Arr::get($project, 'code');
$status    = (string) Arr::get($project, 'status', 'draft');
$job_id    = Service_Nj668_Draw2d::live_job_id($project);
$shapes_n  = count((array) Arr::get($scene, 'shapes', []));
$waiting   = ($job_id > 0 AND $shapes_n === 0);
$in_catalog = $status === 'published';
$error     = (string) Arr::get($project, 'error', '');
$statuses  = [
	'draft'     => ['Черновик', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300'],
	'published' => ['В каталоге', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
	'rejected'  => ['Отклонена', 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'],
];
$row = Arr::get($statuses, $status, $statuses['draft']);
?>

<?php /* design-audit: allow style — холст SVG: высота сцены по брейкпойнтам с min(), тулбар и HUD в колонке, :fullscreen (и -webkit-) — классом не выразить */ ?>
<style>
/* Сцена не наезжает на тулбар: колонка, не absolute поверх панели
   (владелец, 11.10.2026). */
#nj668-canvas {
	display: flex;
	flex-direction: column;
	height: 360px;
}
@media (min-width: 640px) {
	#nj668-canvas { height: 480px; }
}
@media (min-width: 1024px) {
	#nj668-canvas { height: min(70vh, 720px); }
}
#nj668-toolbar {
	position: relative;
	z-index: 20;
	flex-shrink: 0;
	flex-wrap: nowrap;
	overflow-x: auto;
	overflow-y: hidden;
	-webkit-overflow-scrolling: touch;
	background: #ffffff;
	color: #1f2937;
}
html.dark #nj668-toolbar { background: #111827; color: #e5e7eb; }
#nj668-toolbar button,
#nj668-tool-group,
#nj668-toolbar label {
	flex-shrink: 0;
}
#nj668-stage {
	position: relative;
	flex: 1 1 auto;
	min-height: 0;
	cursor: crosshair;
	overflow: hidden;
}
#nj668-stage svg { width: 100%; height: 100%; display: block; }
#nj668-working, #nj668-fail { z-index: 30; }
#nj668-hud {
	position: relative;
	z-index: 20;
	flex-shrink: 0;
	background: #ffffff;
	color: #1f2937;
}
html.dark #nj668-hud { background: #111827; color: #e5e7eb; }
#nj668-canvas:fullscreen,
#nj668-canvas:-webkit-full-screen {
	width: 100%;
	height: 100%;
	border-radius: 0;
	border: 0;
	background: #fff;
}
html.dark #nj668-canvas:fullscreen,
html.dark #nj668-canvas:-webkit-full-screen { background: #111827; }
</style>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-center gap-3">
		<h1 class="n2j-h1">
			<i class="fas fa-bezier-curve mr-2 text-blue-500"></i><?php echo HTML::chars((string) Arr::get($project, 'title')); ?>
		</h1>
		<span class="rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $row[1]; ?>"><?php echo HTML::chars($row[0]); ?></span>
		<a href="/draw2d/c/<?php echo HTML::chars($code); ?>" class="text-xs text-blue-600 hover:underline dark:text-blue-400">Страница просмотра</a>
	</div>

	<div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
		<span>фигур: <b id="nj668-stat-shapes" class="text-gray-800 dark:text-gray-200"><?php echo (int) $shapes_n; ?></b></span>
		<span>лимит: <?php echo HTML::chars($rate_text); ?></span>
	</div>

	<div class="mt-5 gap-5 lg:grid lg:grid-cols-3 lg:items-start">

		<div class="min-w-0 lg:col-span-2">

			<div id="nj668-canvas" class="relative w-full overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
				<div id="nj668-toolbar" class="relative z-20 flex items-center gap-1 border-b border-gray-200 px-2 py-1.5 dark:border-gray-700">
					<div id="nj668-tool-group" class="n2j-seg">
						<button type="button" data-tool="select" class="n2j-seg-item is-active">Выбрать</button>
						<button type="button" data-tool="rect" class="n2j-seg-item">Прямоуг.</button>
						<button type="button" data-tool="circle" class="n2j-seg-item">Круг</button>
						<button type="button" data-tool="line" class="n2j-seg-item">Прямая</button>
						<button type="button" data-tool="cubic" class="n2j-seg-item">Безье</button>
						<button type="button" data-tool="poly" class="n2j-seg-item">Многоуг.</button>
					</div>

					<label class="ml-1 inline-flex items-center gap-1 text-xs font-semibold text-gray-800 dark:text-white" title="Заливка">
						<i class="fas fa-fill-drip" aria-hidden="true"></i>
						<input id="nj668-fill" type="color" value="#74c69d" class="h-7 w-8 cursor-pointer rounded border border-gray-300 bg-white p-0 dark:border-gray-600">
					</label>
					<label class="inline-flex items-center gap-1 text-xs font-semibold text-gray-800 dark:text-white" title="Обводка">
						<i class="fas fa-pen" aria-hidden="true"></i>
						<input id="nj668-stroke" type="color" value="#1e293b" class="h-7 w-8 cursor-pointer rounded border border-gray-300 bg-white p-0 dark:border-gray-600">
					</label>

					<button type="button" id="nj668-del-shape-btn" data-n2j-arm="Удалить фигуру?" disabled title="Удалить фигуру" aria-label="Удалить фигуру"
						class="n2j-btn-danger n2j-btn-sm">Удалить фигуру</button>
					<button type="button" id="nj668-undo-btn" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate-left mr-1"></i>Отменить</button>
					<button type="button" id="nj668-redo-btn" class="n2j-btn-secondary n2j-btn-sm"><i class="fas fa-rotate-right mr-1"></i>Повторить</button>
					<button type="button" id="nj668-save-icon-btn" disabled title="Сохранить правки" aria-label="Сохранить правки"
						class="n2j-btn-icon">
						<i class="fas fa-save text-xs" aria-hidden="true"></i>
					</button>

					<button type="button" id="nj668-full-btn" title="На весь экран" aria-label="На весь экран"
						class="n2j-btn-icon ml-auto">
						<i class="fas fa-expand text-xs" aria-hidden="true"></i>
					</button>
				</div>

				<div id="nj668-stage"></div>

				<div id="nj668-working" class="<?php echo $waiting ? '' : 'hidden '; ?>absolute inset-0 z-10 flex items-center justify-center bg-white/80 px-6 text-center dark:bg-gray-900/80">
					<div>
						<svg class="nj668-spin n2j-spin mx-auto h-10 w-10 text-blue-500 dark:text-blue-300" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
						<p id="nj668-working-title" class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">Модель рисует сцену</p>
						<p id="nj668-elapsed" class="mt-2 font-mono text-2xl font-semibold tabular-nums tracking-wide text-gray-900 dark:text-white">0:00</p>
					</div>
				</div>

				<div id="nj668-fail" class="<?php echo ($error !== '' AND ! $waiting) ? '' : 'hidden '; ?>absolute inset-0 z-10 flex items-center justify-center bg-white/85 px-6 text-center dark:bg-gray-900/85">
					<div>
						<p class="text-sm font-semibold text-rose-700 dark:text-rose-300"><i class="fas fa-circle-exclamation mr-1"></i>Сборка не вышла</p>
						<p id="nj668-fail-text" class="mt-2 max-w-md text-xs text-gray-800 dark:text-gray-200"><?php echo HTML::chars($error); ?></p>
						<button type="button" id="nj668-fail-close" class="n2j-btn-secondary n2j-btn-sm mt-3">Закрыть</button>
					</div>
				</div>

				<div id="nj668-hud" class="pointer-events-none relative z-20 border-t border-gray-200 px-2 py-1 text-xs dark:border-gray-700">
					выбрано: <b id="nj668-selected" class="font-semibold text-gray-900 dark:text-white">ничего не выбрано</b>
				</div>
			</div>

			<div class="n2j-card mt-5">
				<h2 class="n2j-h3"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Описание → сцена</h2>
				<p class="n2j-hint">Опишите, что нарисовать. Если сцена уже есть — модель правит её, а не рисует с нуля.</p>
				<div class="mt-3 flex flex-col gap-2 sm:flex-row">
					<textarea id="nj668-prompt" rows="2" maxlength="<?php echo (int) Arr::get($limits, 'max_prompt', 800); ?>"
						autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
						placeholder="Например: закат над морем, лодка с белым парусом"
						class="n2j-textarea min-h-[44px] flex-1 resize-y"><?php echo HTML::chars((string) Arr::get($project, 'prompt', '')); ?></textarea>
					<button type="button" id="nj668-generate-btn" <?php echo $can_start ? '' : 'disabled'; ?>
						class="n2j-btn-process">
						Нарисовать
					</button>
				</div>
				<div class="mt-2 flex flex-wrap gap-1.5">
					<button type="button" data-nj668-preset="Исправь ошибки"
						class="n2j-btn-secondary n2j-btn-sm">Исправь ошибки</button>
					<button type="button" data-nj668-preset="Улучши и усовершенствуй"
						class="n2j-btn-secondary n2j-btn-sm">Улучши и усовершенствуй</button>
				</div>
				<?php if ( ! $can_start): ?>
				<p class="mt-2 text-xs text-amber-700 dark:text-amber-400">Сборку запускают привилегированные аккаунты (роль vip) и администраторы.</p>
				<?php endif; ?>
			</div>

			<div class="mt-4 flex flex-wrap gap-2">
				<button type="button" id="nj668-publish-btn" <?php echo $in_catalog ? 'disabled' : ''; ?>
					class="n2j-btn-secondary"><?php echo $in_catalog ? 'В каталоге' : 'Опубликовать'; ?></button>
				<button type="button" id="nj668-export-svg-btn" class="n2j-btn-secondary"><i class="fas fa-download mr-1"></i>Скачать SVG</button>
				<button type="button" id="nj668-export-json-btn" class="n2j-btn-secondary"><i class="fas fa-code mr-1"></i>Скачать JSON</button>
				<button type="button" id="nj668-delete-btn" data-n2j-arm="Удалить иллюстрацию?" title="Удалить иллюстрацию" aria-label="Удалить иллюстрацию"
					class="n2j-btn-icon-danger">
					<i class="fas fa-trash-alt" aria-hidden="true"></i>
				</button>
			</div>
		</div>

		<aside class="mt-5 space-y-3 lg:mt-0">
			<div class="n2j-card text-sm text-gray-600 dark:text-gray-300">
				<p class="font-semibold text-gray-900 dark:text-white">Как править</p>
				<ul class="mt-2 list-disc space-y-1 pl-4 text-xs">
					<li>Выбрать — сдвинуть фигуру</li>
					<li>Прямая — два клика</li>
					<li>Безье — четыре клика (начало, два рычага, конец)</li>
					<li>Многоугольник — клики, двойной клик закрывает</li>
					<li>Удаление — вторым нажатием</li>
					<li>Сохранить — ревизия на сервере</li>
				</ul>
			</div>

			<div class="n2j-card">
				<h2 class="n2j-h3"><i class="fas fa-code-branch mr-1.5 text-blue-500"></i>Ревизии</h2>
				<p class="n2j-hint">Снимок сцены. Откат не пишет новую ревизию.</p>
				<div class="mt-3 max-h-64 space-y-2 overflow-y-auto text-xs">
					<?php if ( ! $revisions): ?>
					<p class="text-gray-500 dark:text-gray-400">Ревизий пока нет — сохраните правки.</p>
					<?php else: ?>
					<ul class="divide-y divide-gray-100 dark:divide-gray-800">
						<?php foreach ($revisions as $rev): ?>
						<li class="py-1.5">
							<div class="flex items-center justify-between gap-2">
								<span class="truncate text-gray-700 dark:text-gray-300">
									<?php echo HTML::chars((string) Arr::get($rev, 'label') ?: ('ревизия №'.(int) Arr::get($rev, 'no'))); ?>
								</span>
								<span class="n2j-badge n2j-badge-wait shrink-0">
									<?php echo Arr::get($rev, 'author_kind') === 'ai' ? 'модель' : 'вручную'; ?>
								</span>
							</div>
							<div class="mt-0.5 flex items-center gap-2 text-xs text-gray-400">
								<span>№<?php echo (int) Arr::get($rev, 'no'); ?></span>
								<span><?php echo HTML::chars((string) Arr::get($rev, 'created_at')); ?></span>
								<form method="post" action="/draw2d/p/<?php echo HTML::chars($code); ?>" class="ml-auto inline">
									<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
									<input type="hidden" name="restore" value="<?php echo (int) Arr::get($rev, 'id'); ?>">
									<button type="submit" class="n2j-link">откатить</button>
								</form>
								<form method="post" action="/draw2d/p/<?php echo HTML::chars($code); ?>" class="inline">
									<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
									<input type="hidden" name="forget_revision" value="<?php echo (int) Arr::get($rev, 'id'); ?>">
									<button data-n2j-arm="Удалить?" type="submit" title="Удалить ревизию" aria-label="Удалить ревизию"
										class="n2j-btn-icon-danger h-6 w-6">
										<i class="fas fa-trash-alt text-[10px]" aria-hidden="true"></i>
									</button>
								</form>
							</div>
						</li>
						<?php endforeach; ?>
					</ul>
					<?php endif; ?>
				</div>
			</div>
		</aside>
	</div>
</div>

<script>
window.NJ668 = {
	code: <?php echo json_encode($code, JSON_UNESCAPED_UNICODE); ?>,
	csrf: <?php echo json_encode($csrf, JSON_UNESCAPED_UNICODE); ?>,
	scene: <?php echo json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
	jobId: <?php echo (int) $job_id; ?>,
	canStart: <?php echo $can_start ? 'true' : 'false'; ?>,
	published: <?php echo $in_catalog ? 'true' : 'false'; ?>,
	maxShapes: <?php echo (int) Arr::get($limits, 'max_shapes', 120); ?>
};
</script>
<?php echo View::factory('nj668/script-canvas')->render(); ?>
