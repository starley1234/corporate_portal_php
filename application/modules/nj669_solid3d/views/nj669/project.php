<?php defined('SYSPATH') or die('No direct script access.');
$h = function ($text) { return HTML::chars((string) $text); };
$code = $p['code']; $r = $revision; $ready = ($r AND $r['status'] === 'done');
$busy = FALSE; $started = 0;
// Указатель current ставится только на готовую версию, даже если ей больше 100 попыток.
foreach ($history as $row) { if (in_array($row['status'], ['queued', 'running'], TRUE)) { $busy = TRUE; $started = strtotime($row['created_at']); break; } }
$base = $r ? '/solid3d/f/'.$code.'/'.(int) $r['id'].'/' : '';
$polling = ($busy OR $verify_busy OR $export_busy OR count(array_filter($captions, function ($t) { return in_array($t['status'], ['queued', 'running'], TRUE); })) > 0);
$files = $r ? (array) json_decode($r['files'], TRUE) : [];
$statuses = ['queued' => 'В очереди', 'running' => 'Создание и проверка', 'done' => 'Готово', 'error' => 'Не удалось'];
$pub = [
	'draft' => ['Черновик', 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'],
	'review' => ['На модерации', 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'],
	'published' => ['Опубликовано', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'],
	'rejected' => ['Нужна доработка', 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200'],
];
$vlm = $r ? (string) $r['vlm_status'] : 'none';
$vlm_busy = in_array($vlm, ['queued', 'running'], TRUE);
$is_true = function ($v) { return in_array($v, [TRUE, 't', 'true', 1, '1'], TRUE); };
$vlm_ok = $is_true(Arr::get($r, 'visual_ok'));
// Версия в каталоге или на модерации. Правка снимает её с публикации автоматически (withdraw в сервисе),
// поэтому блокировки нет: только предупреждение. Присваивание через && (а не AND): у AND приоритет ниже «=».
$frozen = $r && ((int) $r['id'] === (int) $p['published_revision_id'] || (int) $r['id'] === (int) $p['review_revision_id']);
// Расход токенов версии: видит владелец и модератор, не публика.
$tokens_line = $r ? Service_Nj669_Solid3d::tokens_text(Arr::get($r, 'tokens_in', 0), Arr::get($r, 'tokens_out', 0)) : '—';
$vlm_tokens_line = $r ? Service_Nj669_Solid3d::tokens_text(Arr::get($r, 'vlm_tokens_in', 0), Arr::get($r, 'vlm_tokens_out', 0)) : '—';
// Классы: одна точка правды на странице (стиль Graph3D build=195).
$card = 'rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900';
$h2 = 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
$in = 'n2j-textarea mt-1 w-full disabled:opacity-60';
$lab = 'n2j-label text-xs';
$btn = 'n2j-btn-secondary n2j-btn-sm';
$btn_sm = 'n2j-btn-secondary n2j-btn-sm';
$btn_primary = 'n2j-btn-cta n2j-btn-sm';
$btn_danger = 'n2j-btn-danger n2j-btn-sm';
$alert_info = 'rounded-lg border border-blue-200 bg-blue-50 p-2.5 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200';
$alert_warn = 'rounded-lg border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200';
$alert_bad = 'rounded-lg border border-rose-300 bg-rose-50 p-2.5 text-xs text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200';
$alert_ok = 'rounded-lg border border-emerald-300 bg-emerald-50 p-2.5 text-xs text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
$muted = 'text-xs text-gray-500 dark:text-gray-400';
// Вкладки левой колонки: владельцу — правка, версии, каталог; модератору — проверка.
$tabs = [];
if ( ! $public)
{
	$tabs = $owner ? ['edit' => 'Изменить', 'versions' => 'Версии', 'catalog' => 'Каталог'] : ['versions' => 'Версии'];
	if ($moderator) { $tabs['moderate'] = 'Модерация'; }
}
$first_tab = $owner ? 'edit' : ($moderator ? 'moderate' : 'versions');
$can_delete_revision = function ($row) use ($p, $busy) {
	return ! $busy AND in_array($row['status'], ['done', 'error'], TRUE)
		AND (int) $row['id'] !== (int) $p['current_revision_id']
		AND (int) $row['id'] !== (int) $p['published_revision_id']
		AND (int) $row['id'] !== (int) $p['review_revision_id']
		AND ! in_array(Arr::get($row, 'vlm_status', 'none'), ['queued', 'running'], TRUE);
};
// Ползунки всегда собирают временный STL: версия, код, каталог и модерация не меняются. Пересчёт кода — отдельный блок.
$param_mode = 'export';
$guest = ! $uid;
// Статус сборки показываем только у той версии, для которой она запускалась.
$export_row = ($export AND $r AND (int) $export['revision_id'] === (int) $r['id']) ? $export : NULL;
$param_args = ['p' => $p, 'revision' => $r, 'parameters' => $parameters, 'csrf' => $csrf, 'busy' => $busy, 'frozen' => $frozen,
	'public' => $public, 'guest' => $guest, 'moderator' => $moderator, 'export' => $export_row, 'export_busy' => $export_busy];
// Публичная страница и гости: без владельческих панелей, но с панелью сборки STL справа.
$show_export = ! $owner && $ready && $parameters !== [];
$grid = ($public AND ! $show_export) ? 'mt-3' : 'mt-3 xl:h-[calc(100dvh-9.5rem)] xl:min-h-[560px] xl:grid-rows-[minmax(0,1fr)] xl:grid-cols-[minmax(0,1fr)_minmax(300px,360px)]';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-6 sm:px-6 lg:px-8" <?php if ($polling): ?>data-solid-poll="/solid3d/<?php echo $public ? 'c' : 'p'; ?>/<?php echo $h($code); ?>?state=1" data-signature="<?php echo $h($signature); ?>"<?php endif; ?>>
<?php echo View::factory('nj669/head', ['error' => $error]); ?>

<header class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
	<div class="min-w-0">
		<h1 class="n2j-h1 break-words"><?php echo $h($p['title']); ?></h1>
		<div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
			<span class="rounded-md bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-200"><?php echo $r ? 'Версия №'.(int) $r['id'] : 'Новая деталь'; ?></span>
			<?php if ( ! $public): ?><span class="rounded-md px-1.5 py-0.5 font-semibold <?php echo $pub[$p['publication']][1]; ?>"><?php echo $pub[$p['publication']][0]; ?></span><?php endif; ?>
			<span class="<?php echo $muted; ?>"><?php echo $public ? 'Опубликованная версия' : 'Личное рабочее место'; ?></span>
			<?php if (Arr::get($p, 'tags', '') !== ''): ?><span class="<?php echo $muted; ?>">· <?php echo $h($p['tags']); ?></span><?php endif; ?>
		</div>
		<?php if ((string) $p['summary'] !== ''): ?><p class="mt-1 line-clamp-2 max-w-3xl text-xs text-gray-600 dark:text-gray-300"><?php echo $h($p['summary']); ?></p><?php endif; ?>
	</div>
	<div class="flex flex-wrap items-center gap-1.5">
		<?php if ($public AND $can_start): ?><a class="<?php echo $btn_primary; ?>" href="/solid3d/create?from=<?php echo $h($code); ?>">Использовать как шаблон</a><?php endif; ?>
		<?php if ($owner AND $p['published_revision_id']): ?><a class="<?php echo $btn; ?>" href="/solid3d/c/<?php echo $h($code); ?>">Публичная версия</a><?php endif; ?>
	</div>
</header>

<?php if ($notice !== ''): ?><div class="<?php echo $alert_info; ?> mt-3" role="status"><?php echo $h($notice); ?></div><?php endif; ?>
<div id="solid-new-result" class="<?php echo $alert_info; ?> mt-3" hidden>Состояние задачи изменилось. <a class="font-semibold underline" href="/solid3d/p/<?php echo $h($code); ?>">Открыть результат</a>. Введённый текст пока сохранён на этой странице.</div>

<div class="<?php echo $grid; ?> grid gap-3">

	<!-- Деталь: в DOM первой, чтобы на телефоне модель была сверху. На xl — главная колонка слева. -->
	<section class="nj669-fill flex min-h-0 min-w-0 flex-col p-3 sm:p-4 xl:col-start-1 xl:row-start-1 <?php echo $card; ?>">
		<div class="flex flex-wrap items-center justify-between gap-2">
			<h2 class="<?php echo $h2; ?>"><i class="fas fa-cube mr-1.5 text-blue-500"></i>Модель</h2>
			<?php if ($busy): ?>
			<div class="n2j-flash n2j-flash-info inline-flex max-w-full flex-wrap items-center gap-x-2 gap-y-1 text-xs" role="status">
				<span class="nj667-spin-ring" aria-hidden="true"></span>
				<span data-solid-stage class="font-semibold"><?php echo $h($live['stage']); ?></span>
				<span data-solid-clock="<?php echo (int) $started; ?>" class="font-mono tabular-nums"></span>
				<span data-solid-heartbeat class="text-blue-700 dark:text-blue-300"></span>
				<?php if ($owner): ?>
				<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="inline" autocomplete="off" data-n2j-no-remember>
					<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
					<input type="hidden" name="revision" value="<?php echo (int) $live['revision_id']; ?>">
					<button name="op" value="cancel" class="font-semibold text-rose-700 underline hover:no-underline dark:text-rose-300">Остановить</button>
				</form>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php if ( ! $public AND $r AND ($owner OR $moderator)): ?>
		<p class="mt-1 <?php echo $muted; ?>">Расход версии №<?php echo (int) $r['id']; ?>, вход → выход: <span class="font-mono text-gray-700 dark:text-gray-200"><?php echo $h($tokens_line); ?></span><?php if ($vlm_tokens_line !== '—'): ?> · проверка ИИ: <span class="font-mono text-gray-700 dark:text-gray-200"><?php echo $h($vlm_tokens_line); ?></span><?php endif; ?></p>
		<?php endif; ?>
		<?php if ($busy AND $owner): ?><p class="mt-1 <?php echo $muted; ?>">Можно уйти со страницы: задача продолжится. Остановка не возвращает квоту, поздний результат не заменит текущую деталь.</p><?php endif; ?>

		<?php if ($ready): ?>
		<!-- Контракт общего просмотрщика /graph3d: id, data-атрибуты и порядок не менять. -->
		<div id="nj667-stl" class="mt-2 w-full" data-src="<?php echo $h($base.'glb'); ?>" data-kind="glb" data-state="done" data-status="">
			<div class="nj667-stl-tools"><label class="inline-flex cursor-pointer items-center gap-1.5 text-xs font-semibold text-gray-700 dark:text-gray-200"><input type="checkbox" id="nj667-grid-toggle" autocomplete="off" class="h-3.5 w-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"> Сетка</label><span id="nj667-grid-info" class="text-xs text-gray-500 dark:text-gray-400" style="display:none"></span></div>
			<button type="button" id="nj667-stl-full" aria-label="На весь экран" title="На весь экран">⛶</button>
		</div>
		<div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs"><p id="nj667-stl-state" class="<?php echo $muted; ?>">Загрузка GLB…</p><span id="nj667-stl-meta" class="<?php echo $muted; ?>"></span></div>
		<?php else: ?>
		<div class="mt-2 flex min-h-[240px] flex-1 items-center justify-center rounded-xl bg-gray-100 p-4 text-center text-sm text-gray-500 dark:bg-gray-800 dark:text-gray-400">
			<div><p class="font-semibold text-gray-700 dark:text-gray-200">Здесь появится деталь</p><p class="mt-1 <?php echo $muted; ?>">Опишите деталь и запустите создание. Модель появится после сборки.</p></div>
		</div>
		<?php endif; ?>

		<?php if ($r AND $r['error'] !== ''): ?><div class="<?php echo $alert_bad; ?> mt-3"><?php echo $h($r['error']); ?></div><?php endif; ?>

		<div class="mt-auto border-t border-gray-100 pt-3 dark:border-gray-800">
			<?php if ($moderator AND $ready): ?>
			<div id="solid-check" class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-2.5 dark:border-gray-800 dark:bg-gray-950">
				<div class="flex flex-wrap items-center justify-between gap-2">
					<div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-gray-800 dark:text-gray-100">
						<span>Проверка ракурсов</span>
						<span class="n2j-badge <?php echo $vlm === 'done' ? ($vlm_ok ? 'n2j-badge-ok' : 'n2j-badge-run') : ($vlm === 'error' ? 'n2j-badge-err' : 'n2j-badge-wait'); ?>"><?php echo $vlm_busy ? 'Проверяется' : ($vlm === 'done' ? ($vlm_ok ? 'Соответствует' : 'Есть замечания') : ($vlm === 'error' ? 'Ошибка' : 'Не проверялась')); ?></span>
					</div>
					<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" autocomplete="off" data-n2j-no-remember>
						<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
						<input type="hidden" name="revision" value="<?php echo (int) $r['id']; ?>">
						<button name="op" value="vlm" class="<?php echo $btn_sm; ?>" <?php if ($busy OR $vlm_busy OR $verify_busy): ?>disabled<?php endif; ?>><?php echo $vlm === 'none' ? 'Проверить ракурсы' : 'Проверить снова'; ?></button>
					</form>
				</div>
				<?php if ($vlm_busy): ?>
				<p class="mt-1.5 <?php echo $muted; ?>"><span class="nj667-spin-ring align-[-2px]" aria-hidden="true"></span> Три ракурса проверяются отдельной задачей. Страницу можно закрыть.</p>
				<?php elseif ($vlm === 'done'): ?>
				<p class="mt-1.5 whitespace-pre-line text-xs <?php echo $vlm_ok ? 'text-emerald-800 dark:text-emerald-200' : 'text-amber-800 dark:text-amber-200'; ?>"><?php echo $h($r['review'] !== '' ? $r['review'] : 'Визуально соответствует описанию.'); ?></p>
				<p class="mt-1 <?php echo $muted; ?>">Проверено <?php echo $h(substr((string) $r['vlm_checked_at'], 0, 16)); ?>. По картинкам, не обмер и не расчёт прочности. Квоту владельца не тратит.</p>
				<?php elseif ($vlm === 'error'): ?>
				<p class="mt-1.5 text-xs text-rose-800 dark:text-rose-200"><?php echo $h($r['vlm_error'] !== '' ? $r['vlm_error'] : 'Проверка не завершилась.'); ?></p>
				<?php else: ?>
				<p class="mt-1.5 <?php echo $muted; ?>">Сборка проверку не запускает. Отдельный запуск по кнопке, квоту владельца не тратит.</p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php if ($ready): ?>
			<div class="flex flex-wrap gap-1.5">
				<a class="n2j-btn-process n2j-btn-sm" href="<?php echo $h($base.'stl'); ?>"><i class="fas fa-download" aria-hidden="true"></i> STL для печати</a>
				<a class="<?php echo $btn; ?>" href="<?php echo $h($base.'glb'); ?>" download>GLB</a>
			</div>
			<?php if (count(array_intersect(array_keys($files), ['png', 'png2', 'png3'])) > 0): ?>
			<details class="mt-2 rounded-lg border border-gray-200 dark:border-gray-800">
				<summary class="cursor-pointer px-3 py-2 text-xs font-semibold text-gray-700 dark:text-gray-200">Три ракурса детали</summary>
				<div class="grid grid-cols-3 gap-2 p-2">
					<?php foreach (['png' => 'Изометрия', 'png2' => 'Обратный ракурс', 'png3' => 'Сверху'] as $kind => $label): if ($kind !== 'png' AND ! isset($files[$kind])) { continue; } ?>
					<figure class="min-w-0">
						<img loading="lazy" class="aspect-square w-full rounded-lg bg-gray-100 object-contain dark:bg-gray-800" src="<?php echo $h($base.$kind); ?>" alt="<?php echo $h($label); ?>">
						<figcaption class="mt-1 truncate text-center <?php echo $muted; ?>"><?php echo $h($label); ?></figcaption>
					</figure>
					<?php endforeach; ?>
				</div>
			</details>
			<?php endif; ?>
			<?php endif; ?>
			<p class="n2j-hint mt-2">Единицы — миллиметры. Проверьте размеры и замкнутость сетки в слайсере. По фото масштаб не определить. Для нагруженных деталей нужен инженерный расчёт.</p>
		</div>
	</section>

	<?php if ( ! $public OR $show_export): ?>
	<!-- Управление: слева на xl, вкладками. Гостю и не владельцу — только панель сборки STL. -->
	<aside class="min-h-0 min-w-0 space-y-2.5 xl:col-start-2 xl:row-start-1 xl:overflow-y-auto xl:pr-1">
		<?php if ($show_export): ?><?php echo View::factory('nj669/parameters', $param_args + ['mode' => 'export']); ?><?php endif; ?>
		<?php if ( ! $public): ?>
		<nav class="n2j-seg gap-1" role="tablist" aria-label="Разделы детали" data-solid-tabs="<?php echo $h($first_tab); ?>">
			<?php foreach ($tabs as $key => $label): ?>
			<button type="button" role="tab" class="nj669-tab n2j-seg-item flex-1" data-solid-tab="<?php echo $h($key); ?>" aria-controls="solid-panel-<?php echo $h($key); ?>" aria-selected="<?php echo $key === $first_tab ? 'true' : 'false'; ?>"><?php echo $h($label); ?></button>
			<?php endforeach; ?>
		</nav>

		<?php if ($owner): ?>
		<div id="solid-panel-edit" role="tabpanel" data-solid-panel="edit" class="space-y-3">
			<?php if ($can_start): ?>
			<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="<?php echo $card; ?> p-3" data-solid-form autocomplete="off" data-n2j-no-remember>
				<h2 class="<?php echo $h2; ?>"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i><?php echo (int) $p['current_revision_id'] > 0 ? 'Изменить с ИИ' : 'Создать деталь'; ?></h2>
				<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="op" value="generate">
				<label for="solid-prompt" class="sr-only">Что сделать</label>
				<textarea id="solid-prompt" name="prompt" required maxlength="6000" rows="4" autocomplete="off" data-n2j-no-remember placeholder="Например: увеличь отверстия до 5 мм, остальное сохрани" class="<?php echo $in; ?> mt-2 leading-relaxed"><?php echo (int) $p['current_revision_id'] === 0 ? $h($p['summary']) : ''; ?></textarea>
				<button class="<?php echo $btn_primary; ?> mt-2 w-full" <?php if ($busy): ?>disabled<?php endif; ?>><?php echo (int) $p['current_revision_id'] > 0 ? 'Изменить деталь' : 'Создать деталь'; ?></button>
				<p class="mt-2 <?php echo $muted; ?>"><?php echo (int) $p['current_revision_id'] > 0 ? 'Правка идёт от текущей версии. Чтобы продолжить старую, сначала восстановите её из вкладки «Версии». ' : ''; ?>Запуски сегодня: <?php echo (int) $limits['runs_used'].' / '.(int) $limits['daily_runs']; ?>.</p>
			</form>
			<?php endif; ?>

			<?php if ($r AND $ready AND $parameters): ?><?php echo View::factory('nj669/parameters', $param_args + ['mode' => $param_mode]); ?><?php endif; ?>
		</div>

		<div id="solid-panel-catalog" role="tabpanel" data-solid-panel="catalog" class="space-y-3">
			<section class="<?php echo $card; ?> p-3">
				<h2 class="<?php echo $h2; ?>">Публикация</h2>
				<div class="mt-2 flex flex-wrap items-center gap-2"><span class="rounded-md px-1.5 py-0.5 text-xs font-semibold <?php echo $pub[$p['publication']][1]; ?>"><?php echo $pub[$p['publication']][0]; ?></span><?php if ($p['published_revision_id']): ?><span class="<?php echo $muted; ?>">в каталоге: версия №<?php echo (int) $p['published_revision_id']; ?></span><?php endif; ?></div>
				<?php if ($p['moderation_note'] !== ''): ?><p class="mt-2 <?php echo $alert_warn; ?>"><?php echo $h($p['moderation_note']); ?></p><?php endif; ?>
				<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="mt-3 flex flex-wrap gap-1.5" autocomplete="off" data-n2j-no-remember>
					<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
					<button class="<?php echo $btn_primary; ?>" name="op" value="submit" <?php if ( ! $ready OR $busy OR $p['publication'] === 'review'): ?>disabled<?php endif; ?>>На модерацию</button>
					<button class="<?php echo $btn; ?>" name="op" value="unpublish" <?php if ($p['publication'] !== 'published'): ?>disabled<?php endif; ?>>Снять с каталога</button>
				</form>
				<p class="mt-2 <?php echo $muted; ?>">Отправляется текущая версия. Любая правка детали, включая карточку, снимает её с каталога; опубликованная версия остаётся в истории.</p>
			</section>

			<?php echo View::factory('nj669/metadata', ['p' => $p, 'csrf' => $csrf, 'moderator' => $moderator, 'captions' => $captions]); ?>

			<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="<?php echo $card; ?> p-3" autocomplete="off" data-n2j-no-remember>
				<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="op" value="delete"><input type="hidden" name="armed" value="0">
				<p class="<?php echo $muted; ?> mb-2">Удаление стирает проект, все версии и файлы. Отменить нельзя.</p>
				<button type="button" data-solid-delete data-n2j-confirm="Проект удалится вместе со всеми версиями и файлами." data-n2j-title="Удалить проект?" class="<?php echo $btn_danger; ?> w-full">Удалить проект</button>
			</form>
		</div>
		<?php endif; ?>

		<div id="solid-panel-versions" role="tabpanel" data-solid-panel="versions" class="space-y-3">
			<section class="<?php echo $card; ?> p-3">
				<div class="flex items-center justify-between gap-2"><h2 class="<?php echo $h2; ?>">История версий</h2><span class="<?php echo $muted; ?>">стр. <?php echo (int) $history_page; ?></span></div>
				<?php if ( ! $history_rows): ?><p class="mt-2 <?php echo $muted; ?>">Первая версия ещё не создана.</p><?php endif; ?>
				<ul class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
				<?php foreach ($history_rows as $row): $rid = (int) $row['id']; ?>
					<li class="py-2 first:pt-0 last:pb-0">
						<div class="flex items-start justify-between gap-2">
							<div class="min-w-0">
								<div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs">
									<a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/solid3d/p/<?php echo $h($code); ?>?revision=<?php echo $rid; ?>">№<?php echo $rid; ?><?php echo Arr::get($row, 'label', '') !== '' ? ' '.$h($row['label']) : ''; ?></a>
									<?php if ($is_true(Arr::get($row, 'milestone'))): ?><span class="text-amber-500" title="Контрольная точка">★</span><?php endif; ?>
									<?php if ($rid === (int) $p['current_revision_id']): ?><span class="n2j-badge n2j-badge-info">текущая</span><?php endif; ?>
									<span class="text-gray-500 dark:text-gray-400"><?php echo $h($statuses[$row['status']] ?? $row['status']); ?></span>
									<?php if ($moderator AND Arr::get($row, 'vlm_status') === 'done'): ?><span class="<?php echo $is_true(Arr::get($row, 'visual_ok')) ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'; ?>">· проверка: <?php echo $is_true(Arr::get($row, 'visual_ok')) ? 'ок' : 'замечания'; ?></span><?php endif; ?>
								</div>
								<p class="mt-0.5 line-clamp-2 text-xs text-gray-600 dark:text-gray-300"><?php echo $h(mb_substr((string) $row['prompt'], 0, 240)); ?></p>
								<?php if (($owner OR $moderator) AND ((int) Arr::get($row, 'tokens_in', 0) + (int) Arr::get($row, 'tokens_out', 0) + (int) Arr::get($row, 'vlm_tokens_in', 0) + (int) Arr::get($row, 'vlm_tokens_out', 0)) > 0): ?>
								<p class="n2j-hint mt-0.5 font-mono">токены <?php echo $h(Service_Nj669_Solid3d::tokens_text(Arr::get($row, 'tokens_in', 0), Arr::get($row, 'tokens_out', 0))); ?><?php if ((int) Arr::get($row, 'vlm_tokens_in', 0) + (int) Arr::get($row, 'vlm_tokens_out', 0) > 0): ?> · ИИ-проверка <?php echo $h(Service_Nj669_Solid3d::tokens_text(Arr::get($row, 'vlm_tokens_in', 0), Arr::get($row, 'vlm_tokens_out', 0))); ?><?php endif; ?></p>
								<?php endif; ?>
								<?php if ($row['error']): ?><p class="mt-0.5 text-xs text-rose-600 dark:text-rose-400"><?php echo $h($row['error']); ?></p><?php endif; ?>
							</div>
							<div class="flex shrink-0 flex-col items-end gap-1">
								<?php if ($owner AND $row['status'] === 'done' AND $rid !== (int) $p['current_revision_id']): ?>
								<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" autocomplete="off" data-n2j-no-remember>
									<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="revision" value="<?php echo $rid; ?>">
									<button class="<?php echo $btn_sm; ?>" name="op" value="restore" <?php if ($busy): ?>disabled<?php endif; ?>>Восстановить</button>
								</form>
								<?php endif; ?>
								<?php if (($owner OR $moderator) AND $can_delete_revision($row)): ?>
								<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" autocomplete="off" data-n2j-no-remember>
									<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="revision" value="<?php echo $rid; ?>"><input type="hidden" name="op" value="delete_revision"><input type="hidden" name="armed" value="0">
									<button type="button" data-solid-delete data-n2j-arm="Удалить версию?" title="Удалить версию №<?php echo $rid; ?>" class="n2j-btn-danger n2j-btn-sm">Удалить</button>
								</form>
								<?php endif; ?>
							</div>
						</div>
					</li>
				<?php endforeach; ?>
				</ul>
				<div class="mt-3 flex items-center gap-2 text-xs">
					<?php if ($history_page > 1): ?><a class="<?php echo $btn; ?>" href="?history_page=<?php echo (int) $history_page - 1; ?>">← Новее</a><?php endif; ?>
					<?php if (count($history_rows) === 100): ?><a class="<?php echo $btn; ?>" href="?history_page=<?php echo (int) $history_page + 1; ?>">Раньше →</a><?php endif; ?>
				</div>
				<?php if ($owner AND $ready): ?>
				<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800" data-solid-form autocomplete="off" data-n2j-no-remember>
					<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="revision" value="<?php echo (int) $r['id']; ?>">
					<label for="solid-checkpoint" class="<?php echo $lab; ?>">Название версии №<?php echo (int) $r['id']; ?></label>
					<input id="solid-checkpoint" name="label" maxlength="160" autocomplete="off" data-n2j-no-remember value="<?php echo $h(Arr::get($r, 'label', '')); ?>" class="n2j-input mt-1 w-full py-1.5 text-sm disabled:opacity-60 min-h-0">
					<label class="mt-2 inline-flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300"><input type="checkbox" name="milestone" value="1" autocomplete="off" <?php if ($is_true(Arr::get($r, 'milestone'))): ?>checked<?php endif; ?> class="h-3.5 w-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500"> Контрольная точка</label>
					<div class="mt-2"><button name="op" value="checkpoint" class="<?php echo $btn; ?>">Сохранить отметку</button></div>
				</form>
				<?php endif; ?>
			</section>
		</div>

		<?php if ($moderator): ?>
		<div id="solid-panel-moderate" role="tabpanel" data-solid-panel="moderate" class="space-y-3">
			<?php if ( ! $owner): ?>
			<?php echo View::factory('nj669/metadata', ['p' => $p, 'csrf' => $csrf, 'moderator' => TRUE, 'captions' => $captions]); ?>
			<?php endif; ?>
			<?php if ($p['publication'] === 'review'): ?>
			<section class="<?php echo $card; ?> p-3">
				<h2 class="<?php echo $h2; ?>">Решение модератора</h2>
				<?php $submitted = (array) json_decode($p['review_meta'], TRUE); ?>
				<p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white"><?php echo $h(Arr::get($submitted, 'title', '')); ?></p>
				<p class="mt-1 text-xs text-gray-600 dark:text-gray-300"><?php echo $h(Arr::get($submitted, 'summary', '')); ?></p>
				<p class="mt-1 <?php echo $muted; ?>"><?php echo $h(Arr::get($submitted, 'tags', '')); ?></p>
				<p class="mt-2 <?php echo $muted; ?>">Утверждается снимок карточки и версия №<?php echo (int) $p['review_revision_id']; ?>, а не последующие правки.</p>
				<a class="mt-2 inline-block text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/solid3d/p/<?php echo $h($code); ?>?revision=<?php echo (int) $p['review_revision_id']; ?>">Открыть версию на проверке</a>
				<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="mt-3" autocomplete="off" data-n2j-no-remember>
					<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
					<label for="solid-note" class="<?php echo $lab; ?>">Комментарий</label>
					<textarea id="solid-note" name="note" maxlength="1000" rows="3" autocomplete="off" data-n2j-no-remember class="<?php echo $in; ?>"></textarea>
					<div class="mt-2 flex flex-wrap gap-1.5">
						<button class="<?php echo $btn_primary; ?>" name="op" value="publish">Опубликовать</button>
						<button class="<?php echo $btn_danger; ?>" name="op" value="reject">Отклонить</button>
					</div>
				</form>
			</section>
			<?php endif; ?>

			<?php if ($r AND $r['source'] !== ''): ?>
			<details class="<?php echo $card; ?> p-3">
				<summary class="cursor-pointer text-xs font-semibold text-gray-700 dark:text-gray-200">Код модели (служебно)</summary>
				<p class="mt-2 <?php echo $muted; ?>">Генерация: <span class="font-mono"><?php echo $h(Arr::get($r, 'model', '') !== '' ? $r['model'] : 'по умолчанию'); ?></span> · проверка: <span class="font-mono"><?php echo $h(Arr::get($r, 'vision_model', '') !== '' ? $r['vision_model'] : 'общая'); ?></span></p>
				<?php if ($can_start): ?>
				<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="mt-3" data-solid-form autocomplete="off" data-n2j-no-remember>
					<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="op" value="render">
					<label for="solid-source" class="<?php echo $lab; ?>">Код: размеры в мм, ось Z вверх</label>
					<textarea id="solid-source" name="source" maxlength="60000" rows="12" spellcheck="false" autocomplete="off" data-n2j-no-remember class="<?php echo $in; ?> font-mono text-xs leading-relaxed"><?php echo $h($r['source']); ?></textarea>
					<div class="mt-2 flex flex-wrap items-center gap-2">
						<button class="<?php echo $btn; ?>" <?php if ($busy): ?>disabled<?php endif; ?>>Пересчитать код</button>
						<span class="<?php echo $muted; ?>">Сборка без модели, квоту не тратит. Внешние файлы не подключаются.<?php echo $frozen ? ' Пересчёт снимет деталь с каталога.' : ''; ?></span>
					</div>
				</form>
				<?php else: ?>
				<pre class="mt-3 max-h-[420px] overflow-auto rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-200"><?php echo $h($r['source']); ?></pre>
				<?php endif; ?>
				<?php if (Arr::get($r, 'build_log', '') !== ''): ?><pre class="mt-3 max-h-[220px] overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs leading-relaxed text-gray-700 dark:bg-gray-950 dark:text-gray-300"><?php echo $h($r['build_log']); ?></pre><?php endif; ?>
			</details>
			<?php endif; ?>

			<form method="post" action="/solid3d/p/<?php echo $h($code); ?>" class="<?php echo $card; ?> p-3" autocomplete="off" data-n2j-no-remember>
				<input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="op" value="delete"><input type="hidden" name="armed" value="0">
				<p class="<?php echo $muted; ?> mb-2">Модератор может удалить любой проект. Удаление стирает все версии и файлы.</p>
				<button type="button" data-solid-delete data-n2j-confirm="Проект удалится вместе со всеми версиями и файлами." data-n2j-title="Удалить проект?" class="<?php echo $btn_danger; ?> w-full">Удалить проект</button>
			</form>
		</div>
		<?php endif; ?>
		<?php endif; ?>
	</aside>
	<?php endif; ?>
</div>

<?php if ($ready): ?><script type="importmap">{"imports":{"three":"/assets/vendor/three/mod/three.module.js","three/addons/":"/assets/vendor/three/mod/addons/"}}</script><script type="module" src="/assets/js/nj667-stl.js?v=20261015a"></script><?php endif; ?>
<script defer src="/assets/js/nj669-solid3d.js?v=20261016d"></script>
</div>
