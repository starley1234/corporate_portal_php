<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Проекты» (nj667): список графов текущего человека.
 *
 * Удаление — маленькая иконка, вторым нажатием (confirm/alert в проекте нет).
 */

$logged     = isset($logged) ? (bool) $logged : FALSE;
$mine       = isset($mine) ? (array) $mine : [];
$error      = isset($error) ? (string) $error : '';
$statuses   = isset($statuses) ? (array) $statuses : [];
$csrf       = isset($csrf) ? (string) $csrf : '';
$rate_text  = isset($rate_text) ? (string) $rate_text : '';
$limits     = isset($limits) ? (array) $limits : [];
$quota_limits = isset($quota_limits) ? (array) $quota_limits : Service_Nj667_Graph3d::quota_limits(0);
$project_count = isset($project_count) ? max(0, (int) $project_count) : count($mine);
$project_limit = max(0, (int) Arr::get($quota_limits, 'active_projects', 5));
$query      = isset($query) ? (string) $query : '';

$badges = [
	'draft'     => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
	'review'    => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
	'published' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
	'rejected'  => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
];
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<div class="flex flex-wrap items-end justify-between gap-3">
		<div>
			<h1 class="n2j-h1"><i class="fas fa-folder-open mr-2 text-blue-500"></i>Проекты</h1>
			<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Ваши графы: черновики, на модерации и уже в каталоге. Новую сборку — на странице <a href="/graph3d/create" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">Создать</a>.</p>
		</div>
		<a href="/graph3d/create" class="n2j-btn-cta">Новая сборка</a>
	</div>

	<?php if ($logged): ?>
	<p class="n2j-hint mt-2">
		Активные проекты: <b><?php echo $project_count; ?> / <?php echo $project_limit; ?></b>.
		На проекте хранится текущая версия и до <?php echo (int) Arr::get($quota_limits, 'historical_revisions', 3); ?> предыдущих ревизий.
	</p>
	<?php endif; ?>

	<?php if ($logged): ?>
	<form method="get" action="/graph3d/projects" class="mt-5 flex max-w-xl flex-wrap gap-2">
		<input type="search" name="q" value="<?php echo HTML::chars($query); ?>" maxlength="200"
			placeholder="Поиск: название, теги, описание…"
			class="n2j-input min-w-[10rem] flex-1">
		<button type="submit" class="n2j-btn-process shrink-0">Найти</button>
		<?php if ($query !== ''): ?>
		<a href="/graph3d/projects" class="inline-flex min-h-[44px] items-center px-2 text-sm font-semibold text-gray-500 hover:underline dark:text-gray-400">Сбросить</a>
		<?php endif; ?>
	</form>
	<?php endif; ?>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<?php if (empty($logged)): ?>
	<div class="mt-6">
		<?php echo Gate::wall('graph3d', [
			'icon'   => 'fas fa-folder-open',
			'title'  => 'Проекты — по аккаунту',
			'text'   => 'Список ваших графов хранится на сервере. Войдите, чтобы видеть черновики и продолжать правку. Каталог открыт и без входа.',
			'points' => [
				'Черновики, заявки и опубликованные — в одном списке',
				'Удаление иконкой, вторым нажатием',
				'Лимит: '.$rate_text,
			],
			'stats'  => ['до '.$project_limit.' активных проектов на аккаунт'],
			'cta'    => 'Войти к проектам',
		]); ?>
	</div>
	<?php elseif ( ! $mine): ?>
	<div class="mt-6 rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center dark:border-gray-700 dark:bg-gray-900">
		<p class="text-sm text-gray-500 dark:text-gray-400"><?php echo $query !== '' ? 'Ничего не нашлось по запросу «'.HTML::chars($query).'».' : 'Проектов пока нет.'; ?></p>
		<a href="/graph3d/create" class="mt-3 inline-flex min-h-[44px] items-center text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400">Описать изделие →</a>
	</div>
	<?php else: ?>
	<ul class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
		<?php foreach ($mine as $row): ?>
		<?php // min-w-0 на карточке обязателен: заголовок и статус в одну строку
		// (line-clamp/truncate) дают min-content на всю длину текста, и без min-w-0
		// сетка на телефоне распирает страницу по ширине. ?>
		<li class="n2j-card flex min-w-0 gap-3 p-3">
			<a href="/graph3d/p/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>" class="block min-w-0 shrink-0">
				<?php echo Service_Nj667_Graph3d::preview_img($row, '', 'h-14 w-20 rounded-md border border-gray-200 object-contain dark:border-gray-700'); ?>
			</a>
			<div class="min-w-0 flex-1">
				<a href="/graph3d/p/<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>"
					class="line-clamp-2 text-sm font-semibold text-gray-900 hover:underline dark:text-white">
					<?php echo HTML::chars((string) Arr::get($row, 'title')); ?>
				</a>
				<div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
					<span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold <?php echo Arr::get($badges, (string) Arr::get($row, 'status'), Arr::get($badges, 'draft')); ?>">
						<?php echo HTML::chars(Arr::get($statuses, (string) Arr::get($row, 'status'), (string) Arr::get($row, 'status'))); ?>
					</span>
					<span class="min-w-0 break-words text-xs text-gray-500 dark:text-gray-400">
						узлов <?php echo (int) Arr::get($row, 'nodes_count'); ?>,
						связей <?php echo (int) Arr::get($row, 'edges_count'); ?>
						· <?php echo HTML::chars((string) Arr::get($row, 'updated_at')); ?>
					</span>
					<form method="post" action="/graph3d/projects" class="nj667-del-form ml-auto shrink-0">
						<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
						<input type="hidden" name="delete" value="<?php echo HTML::chars((string) Arr::get($row, 'code')); ?>">
						<button data-n2j-arm="Удалить?" type="submit" title="Удалить" aria-label="Удалить"
							class="n2j-btn-icon-danger">
							<i class="fas fa-trash-alt text-xs" aria-hidden="true"></i>
						</button>
					</form>
				</div>
			</div>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
</div>

