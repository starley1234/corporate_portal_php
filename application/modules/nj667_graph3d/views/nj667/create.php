<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Создать» (nj667): простой маршрут по умолчанию для пользователей с правом
 * nj667.start (в т.ч. роль login), локальный шаблон без LLM для остальных.
 *
 * Фото изделия — компактная кнопка-ссылка рядом с полем описания, а не
 * отдельный блок (владелец, 15.10.2026: «сделай не отдельным блоком, а как
 * кнопка-ссылка рядом с текстовым полем, чтобы не фокусировать внимание на
 * изображении»). Своё поле в каждой панели (Simple и LLM), потому что панель
 * выключена целиком, когда неактивна: лишний файл не уедет на сервер.
 * Лимит кадров и подписи — из ACL модератора.
 *
 * Кнопка отправки: у «Простой (для пользователя)» она зелёная (просьба
 * владельца), у каркаса и LLM-графа остаётся индиго — тон меняет скрипт.
 */

$logged       = isset($logged) ? (bool) $logged : FALSE;
$can_start    = isset($can_start) ? (bool) $can_start : FALSE;
$can_moderate = isset($can_moderate) ? (bool) $can_moderate : FALSE;
$error        = isset($error) ? (string) $error : '';
$rate_text    = isset($rate_text) ? (string) $rate_text : '';
$limits       = isset($limits) ? (array) $limits : [];
$quota_limits = isset($quota_limits) ? (array) $quota_limits : Service_Nj667_Graph3d::quota_limits(0);
$project_count = isset($project_count) ? max(0, (int) $project_count) : 0;
$project_limit = max(0, (int) Arr::get($quota_limits, 'active_projects', 5));
$daily_quota  = isset($daily_quota) && is_array($daily_quota)
	? $daily_quota : Service_Nj667_Graph3d::llm_quota_state(0,
		(int) Arr::get($quota_limits, 'llm_operations_per_day', 5));
$can_create_project = $logged && $project_count < $project_limit;
$can_run_llm = $can_start && !empty($daily_quota['ready']) && !empty($daily_quota['allowed']);
$csrf         = isset($csrf) ? (string) $csrf : '';
$post         = isset($post) ? (array) $post : [];
$model        = isset($model) ? (string) $model : '';
$rag          = isset($rag) ? (bool) $rag : TRUE;
$ideas        = isset($ideas) ? (array) $ideas : [];
$templates    = isset($simple_templates) ? (array) $simple_templates : Service_Nj667_Graph3d::simple_templates();
$mode         = strtolower(trim((string) (isset($creation_mode) ? $creation_mode : Arr::get($post, 'creation_mode', 'simple'))));

if ( ! in_array($mode, ['llm', 'template', 'simple'], TRUE) OR ( ! $can_start AND $mode !== 'template'))
{
	$mode = 'template';
}

if ( ! $templates)
{
	$templates = Service_Nj667_Graph3d::simple_templates();
}

if (count($ideas) < 3)
{
	$ideas = Service_Nj667_Graph3d::idea_presets_pick(3);
}

$template_key = (string) Arr::get($post, 'template', array_key_first($templates));

if ( ! isset($templates[$template_key]))
{
	$template_key = (string) array_key_first($templates);
}

$advanced = ((string) Arr::get($post, 'title', '') !== '')
	|| ((string) Arr::get($post, 'tags', '') !== '')
	|| array_key_exists('rag', $post);
$advanced_open = $advanced;

// Компактное поле фото: кликабельная плашка-«ссылка» с камерой и статусом,
// миниатюры — ниже. Правила (сколько кадров и на сколько МБ) не висят
// подсказкой: они в title плашки, а нарушение объясняет сам статус. Значения
// id первого (простого) поля оставлены прежними — их знают стенды и
// браузерный тест; у LLM-панели суффикс -llm.
$photo_field = function ($suffix = '') use ($can_moderate) {
	$id_in      = 'nj667-photos'.($suffix !== '' ? '-'.$suffix : '');
	$id_status  = 'nj667-photo-status'.($suffix !== '' ? '-'.$suffix : '');
	$id_preview = 'nj667-photo-preview'.($suffix !== '' ? '-'.$suffix : '');
	$limits     = $can_moderate ? 'необязательно: до 4 кадров, до 5 МБ каждое' : 'необязательно: один кадр, до 5 МБ';
	?>
	<div class="mt-3" data-nj667-photo>
		<div class="flex flex-wrap items-center gap-x-3 gap-y-2">
			<?php // input внутри плашки: тогда focus-within подсвечивает её и в клавиатуре ?>
			<label for="<?php echo $id_in; ?>" title="<?php echo HTML::chars($limits); ?>" class="inline-flex min-h-[40px] cursor-pointer items-center gap-2 rounded-lg border border-dashed border-gray-300 bg-white px-3 text-xs font-semibold text-gray-600 transition hover:border-blue-400 hover:text-blue-600 focus-within:border-blue-500 focus-within:text-blue-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-blue-500 dark:hover:text-blue-300">
				<i class="fas fa-camera text-[13px]" aria-hidden="true"></i>Фото изделия
				<input id="<?php echo $id_in; ?>" data-nj667-photo-input name="photos[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-max="<?php echo $can_moderate ? 4 : 1; ?>" <?php echo $can_moderate ? 'multiple' : ''; ?> aria-describedby="<?php echo $id_status; ?>" class="sr-only">
			</label>
			<span id="<?php echo $id_status; ?>" data-nj667-photo-status class="text-xs font-semibold text-gray-500 dark:text-gray-400" role="status" aria-live="polite">Фото не выбрано.</span>
		</div>
		<div id="<?php echo $id_preview; ?>" data-nj667-photo-preview class="mt-2 flex flex-wrap gap-2" hidden></div>
	</div>
	<?php
};
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>Создать проект</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Выберите, как начать: готовый каркас без модели, граф по описанию или полностью автоматическая сборка из палочек для мороженого.</p>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-6 max-w-4xl space-y-5">

		<?php if (empty($logged)): ?>
		<?php echo Gate::wall('graph3d', [
			'icon'   => 'fas fa-cube',
			'title'  => 'Графовый 3D-конструктор — по бесплатному аккаунту',
			'text'   => 'Готовые каркасы считаются на сайте. Для хранения проектов, истории правок и сборки по описанию войдите — аккаунт бесплатный, а каталог открыт и без него.',
			'points' => [
				'Готовый граф из шаблона без вызова LLM',
				'Описание словами → пространственный граф',
				'Автосборка: палочки, модель и узлы к печати',
				'Лимит запусков: '.$rate_text,
			],
			'stats'  => ['до '.(int) Arr::get($limits, 'max_nodes', 500).' узлов', 'three.js, вендор'],
			'cta'    => 'Войти и создать проект',
		]); ?>
		<?php endif; ?>

		<form method="post" action="/graph3d/create" enctype="multipart/form-data" class="n2j-card" autocomplete="off">
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

			<?php if ($logged): ?>
			<div class="n2j-flash n2j-flash-info mb-4 text-xs block">
				<p>Активные проекты: <b><?php echo $project_count; ?> / <?php echo $project_limit; ?></b>.</p>
				<p id="nj667-daily-quota" class="mt-1">
					<?php if (!empty($daily_quota['ready'])): ?>
						LLM-операции сегодня: <b><?php echo (int) Arr::get($daily_quota, 'used', 0); ?> / <?php echo (int) Arr::get($daily_quota, 'limit', 5); ?></b>,
						осталось <?php echo (int) Arr::get($daily_quota, 'remaining', 0); ?> (UTC).
					<?php else: ?>
						<?php echo HTML::chars((string) Arr::get($daily_quota, 'error', 'Дневная квота временно недоступна.')); ?>
					<?php endif; ?>
				</p>
				<?php if ( ! $can_create_project): ?>
				<p class="mt-1 font-semibold">Лимит активных проектов достигнут. Удалите ненужный проект, чтобы создать новый.</p>
				<?php elseif ($can_start AND ! $can_run_llm): ?>
				<p class="mt-1 font-semibold">Генерация по описанию сейчас недоступна; готовый каркас можно создать без LLM.</p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<fieldset class="border-0 p-0" aria-label="Способ создания">
				<legend class="text-sm font-semibold text-gray-900 dark:text-white">Как создаём?</legend>
				<div class="mt-3 grid gap-2 sm:grid-cols-3">
					<label data-mode-card data-selected="<?php echo $mode === 'simple' ? '1' : '0'; ?>" data-disabled="<?php echo $can_run_llm ? '0' : '1'; ?>"
						class="data-[selected='1']:border-blue-500 data-[selected='1']:bg-blue-50 data-[selected='1']:ring-1 data-[selected='1']:ring-blue-500 dark:data-[selected='1']:border-blue-500 dark:data-[selected='1']:bg-blue-950 data-[disabled='1']:opacity-50 flex cursor-pointer gap-2.5 rounded-lg border border-blue-200 p-3 dark:border-blue-900 <?php echo $can_run_llm ? '' : 'cursor-not-allowed'; ?>">
						<input type="radio" name="creation_mode" value="simple" data-mode-choice <?php echo $mode === 'simple' ? 'checked' : ''; ?> <?php echo $can_run_llm ? '' : 'disabled'; ?> class="mt-0.5 h-4 w-4 shrink-0 text-blue-600 dark:dark:text-blue-400">
						<span><span class="block text-xs font-semibold text-gray-900 dark:text-white">Простой (для пользователя)</span><span class="mt-1 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">Один текст или фото → граф, 3D-сборка и детали.</span></span>
					</label>
					<label data-mode-card data-selected="<?php echo $mode === 'template' ? '1' : '0'; ?>" data-disabled="0"
						class="data-[selected='1']:border-blue-500 data-[selected='1']:bg-blue-50 data-[selected='1']:ring-1 data-[selected='1']:ring-blue-500 dark:data-[selected='1']:border-blue-500 dark:data-[selected='1']:bg-blue-950 data-[disabled='1']:opacity-50 flex cursor-pointer gap-2.5 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
						<input type="radio" name="creation_mode" value="template" data-mode-choice <?php echo $mode === 'template' ? 'checked' : ''; ?> class="mt-0.5 h-4 w-4 shrink-0 text-blue-600 dark:dark:text-blue-400">
						<span><span class="block text-xs font-semibold text-gray-900 dark:text-white">Готовый каркас</span><span class="mt-1 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">Выберите готовую конструкцию и создайте её без вызова LLM.</span></span>
					</label>
					<label data-mode-card data-selected="<?php echo $mode === 'llm' ? '1' : '0'; ?>" data-disabled="<?php echo $can_run_llm ? '0' : '1'; ?>"
						class="data-[selected='1']:border-blue-500 data-[selected='1']:bg-blue-50 data-[selected='1']:ring-1 data-[selected='1']:ring-blue-500 dark:data-[selected='1']:border-blue-500 dark:data-[selected='1']:bg-blue-950 data-[disabled='1']:opacity-50 flex cursor-pointer gap-2.5 rounded-lg border border-gray-200 p-3 dark:border-gray-700 <?php echo $can_run_llm ? '' : 'cursor-not-allowed'; ?>">
						<input type="radio" name="creation_mode" value="llm" data-mode-choice <?php echo $mode === 'llm' ? 'checked' : ''; ?> <?php echo $can_run_llm ? '' : 'disabled'; ?> class="mt-0.5 h-4 w-4 shrink-0 text-blue-600 dark:dark:text-blue-400">
						<span><span class="block text-xs font-semibold text-gray-900 dark:text-white">Только граф</span><span class="mt-1 block text-xs leading-relaxed text-gray-500 dark:text-gray-400">Постройте граф по описанию или фото, а сборку настройте отдельно.</span></span>
					</label>
				</div>
			</fieldset>

			<?php if ( ! $can_start): ?>
			<p class="mt-2 text-xs text-amber-700 dark:text-amber-400">Шаблоны без LLM доступны вошедшим пользователям. Генерация по описанию и автоматический OpenSCAD-рендер требуют права nj667.start (роль login, vip или admin).</p>
			<?php endif; ?>

			<noscript>
				<p class="n2j-hint mt-3">Чтобы переключить режим без JavaScript, выберите его по ссылке:
					<a href="/graph3d/create?mode=template" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">каркас</a><?php if ($can_run_llm): ?> ·
					<a href="/graph3d/create?mode=llm" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">граф по описанию</a> ·
					<a href="/graph3d/create?mode=simple" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">всё автоматически</a><?php endif; ?>
				</p>
			</noscript>

			<fieldset data-creation-panel="llm" class="mt-5 border-0 p-0" <?php echo $mode === 'llm' ? '' : 'hidden disabled'; ?>>
				<h2 class="n2j-h3"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Новая сборка по описанию</h2>

				<div class="mt-3">
					<label for="nj667-prompt" class="n2j-label">Что построить</label>
					<textarea id="nj667-prompt" name="prompt" rows="4" maxlength="<?php echo (int) Arr::get($limits, 'max_prompt', 1000); ?>"
						autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
						class="n2j-textarea mt-2 w-full resize-y leading-relaxed"
						placeholder="Трёхколёсный велосипед с корзиной сзади: два колеса по бокам сзади, одно спереди, рама между ними, руль, сиденье и корзина над задней осью"><?php echo HTML::chars((string) Arr::get($post, 'prompt', '')); ?></textarea>
					<?php if ($ideas): ?>
					<div class="mt-2 flex flex-wrap items-center gap-1.5">
						<div id="nj667-ideas" class="flex flex-wrap gap-1.5">
						<?php foreach (array_slice($ideas, 0, 3) as $idea): ?>
						<?php $idea = (string) $idea; ?>
						<button type="button" data-nj667-preset="<?php echo HTML::chars($idea); ?>"
							title="<?php echo HTML::chars($idea); ?>"
							class="n2j-btn-secondary n2j-btn-sm max-w-full truncate">
							<?php echo HTML::chars(UTF8::substr($idea, 0, 42)); ?><?php echo UTF8::strlen($idea) > 42 ? '…' : ''; ?>
						</button>
						<?php endforeach; ?>
						</div>
						<button type="button" id="nj667-ideas-refresh" aria-label="Другие идеи" title="Другие идеи"
							class="n2j-btn-icon shrink-0">
							<i class="fas fa-rotate"></i>
						</button>
					</div>
					<?php endif; ?>
					<p class="n2j-hint mt-1.5">Текст, фото или оба. Чем точнее описание, тем меньше править руками. Похожие графы из каталога модель увидит сама.</p>
				</div>

				<?php // Фото — рядом с описанием, а не отдельным блоком ниже формы ?>
				<?php $photo_field('llm'); ?>


				<details id="nj667-advanced" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800" <?php echo $advanced_open ? 'open' : ''; ?>>
					<summary class="cursor-pointer select-none text-sm font-semibold text-gray-700 dark:text-gray-300">Расширенное отображение</summary>
					<fieldset id="nj667-advanced-fields" class="mt-3 space-y-3 border-0 p-0" <?php echo $advanced_open ? '' : 'disabled'; ?>>
						<div>
							<label for="nj667-title" class="n2j-label">Название проекта <span class="font-normal text-gray-400">(необязательно)</span></label>
							<input id="nj667-title" name="title" type="text" maxlength="200" autocomplete="off"
								value="<?php echo HTML::chars((string) Arr::get($post, 'title', '')); ?>"
								placeholder="Например: трёхколёсный велосипед с корзиной"
								class="n2j-input mt-1 w-full">
						</div>

						<div>
							<input type="hidden" name="rag" value="0">
							<label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
								<input id="nj667-rag" type="checkbox" name="rag" value="1" <?php echo $rag ? 'checked' : ''; ?> class="h-4 w-4 rounded text-blue-600 dark:dark:text-blue-400">
								<span>RAG: похожие графы из каталога</span>
							</label>
							<p class="n2j-hint mt-0.5">Только для пустого проекта. Значение по умолчанию берётся из настроек конструктора.</p>
						</div>

						<div>
							<label for="nj667-tags" class="n2j-label">Теги через запятую <span class="font-normal text-gray-400">(для каталога)</span></label>
							<input id="nj667-tags" name="tags" type="text" maxlength="300" autocomplete="off"
								value="<?php echo HTML::chars((string) Arr::get($post, 'tags', '')); ?>"
								placeholder="велосипед, транспорт, дача"
								class="n2j-input mt-1 w-full">
						</div>
					</fieldset>
				</details>
			</fieldset>

			<fieldset data-creation-panel="template" class="mt-5 border-0 p-0" <?php echo $mode === 'template' ? '' : 'hidden disabled'; ?>>
				<h2 class="n2j-h3"><i class="fas fa-cubes mr-1.5 text-emerald-500"></i>Готовый каркас — без LLM</h2>
				<p class="n2j-hint">Выберите основу. Проект и история создадутся сразу; дальше его можно двигать на холсте и отдельно открыть сборку.</p>
				<label for="nj667-template" class="mt-3 block text-xs font-semibold text-gray-700 dark:text-gray-300">Тип конструкции
					<select id="nj667-template" name="template" class="n2j-input n2j-select mt-1 w-full">
						<?php foreach ($templates as $key => $item): $item = (array) $item; ?>
						<option value="<?php echo HTML::chars((string) $key); ?>" data-description="<?php echo HTML::chars((string) Arr::get($item, 'description', '')); ?>" <?php echo (string) $key === $template_key ? 'selected' : ''; ?>>
							<?php echo HTML::chars((string) Arr::get($item, 'title', (string) $key)); ?> — <?php echo (int) Arr::get($item, 'nodes', 0); ?> узл., <?php echo (int) Arr::get($item, 'edges', 0); ?> палочек
						</option>
						<?php endforeach; ?>
					</select>
				</label>
				<p id="nj667-template-description" class="n2j-hint"><?php echo HTML::chars((string) Arr::get((array) $templates[$template_key], 'description', '')); ?></p>
				<label for="nj667-template-title" class="mt-3 block text-xs font-semibold text-gray-700 dark:text-gray-300">Название проекта <span class="font-normal text-gray-400">(необязательно)</span>
					<input id="nj667-template-title" name="template_title" type="text" maxlength="200" autocomplete="off" value="<?php echo HTML::chars((string) Arr::get($post, 'template_title', '')); ?>"
						placeholder="По умолчанию — название каркаса"
						class="n2j-input mt-1 w-full">
				</label>
			</fieldset>

			<fieldset data-creation-panel="simple" class="mt-5 border-0 p-0" <?php echo $mode === 'simple' ? '' : 'hidden disabled'; ?>>
				<h2 class="n2j-h3"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500"></i>Один текст — готовая сборка</h2>
				<p class="n2j-hint">Опишите идею обычными словами. Система создаст отдельный проект с графом, подготовит конструкцию из плоских палочек для мороженого и автоматически поставит в очередь модель и набор деталей.</p>
				<textarea id="nj667-simple-prompt" name="simple_prompt" rows="4" maxlength="<?php echo (int) Arr::get($limits, 'max_prompt', 1000); ?>" autocomplete="off" autocorrect="off" autocapitalize="sentences" aria-label="Что построить"
					class="n2j-textarea mt-3 w-full resize-y leading-relaxed"
					placeholder="Например: компактная кормушка для птиц с крышей, двумя опорами и перекладиной для подвеса"><?php echo HTML::chars((string) Arr::get($post, 'simple_prompt', '')); ?></textarea>

				<?php // Фото — кнопка-ссылка рядом с полем описания (просьба владельца) ?>
				<?php $photo_field(); ?>
			</fieldset>

			<?php // «Простой (для пользователя)» — зелёная кнопка (просьба владельца),
			      // каркас и LLM-граф остаются индиго; тон меняет selectMode(). ?>
			<button id="nj667-create-submit" type="submit"
				data-can-create="<?php echo $can_create_project ? '1' : '0'; ?>"
				data-can-llm="<?php echo $can_run_llm ? '1' : '0'; ?>"
				<?php echo ( ! $can_create_project OR ($mode !== 'template' AND ! $can_run_llm)) ? 'disabled' : ''; ?>
				class="<?php echo $mode === 'simple' ? 'n2j-btn-cta' : 'n2j-btn-process'; ?> mt-5 w-full sm:w-auto sm:px-6">
				<?php echo $mode === 'template' ? 'Создать каркас' : ($mode === 'simple' ? 'Создать проект и собрать всё' : 'Построить граф'); ?>
			</button>

			<?php if ($can_moderate): ?>
			<p class="n2j-hint mt-2">модель: <code><?php echo HTML::chars($model !== '' ? $model : 'воркера по умолчанию'); ?></code>
				· <a href="/graph3d/settings" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">настройки</a>
				· <a href="/graph3d/ideas" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">идеи</a></p>
			<?php endif; ?>

			<?php if ($logged AND $can_start): ?>
			<p class="n2j-hint mt-2">Частотный ограничитель LLM: <?php echo HTML::chars($rate_text); ?>. Отдельно действует дневная квота выше.</p>
			<?php endif; ?>
		</form>
	</div>
</div>
<script>
(function () {
	var panels = Array.prototype.slice.call(document.querySelectorAll('[data-creation-panel]'));
	var choices = Array.prototype.slice.call(document.querySelectorAll('[data-mode-choice]'));
	var cards = Array.prototype.slice.call(document.querySelectorAll('[data-mode-card]'));
	var submit = document.getElementById('nj667-create-submit');
	var prompt = document.getElementById('nj667-prompt');
	var template = document.getElementById('nj667-template');
	var templateDescription = document.getElementById('nj667-template-description');
	var advanced = document.getElementById('nj667-advanced');
	var advancedFields = document.getElementById('nj667-advanced-fields');
	var chipClass = 'max-w-full truncate rounded-full border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-800';

	function modeName(mode) {
		return mode === 'template' ? 'Создать каркас'
			: (mode === 'simple' ? 'Создать проект и собрать всё' : 'Построить граф');
	}

	function selectMode(mode) {
		panels.forEach(function (panel) {
			var active = panel.getAttribute('data-creation-panel') === mode;
			panel.hidden = !active;
			panel.disabled = !active;
		});
		cards.forEach(function (card) {
			var radio = card.querySelector('[data-mode-choice]');
			card.setAttribute('data-selected', radio && radio.value === mode ? '1' : '0');
		});
		if (submit) {
			submit.textContent = modeName(mode);
			var canCreate = submit.getAttribute('data-can-create') === '1';
			var canLlm = submit.getAttribute('data-can-llm') === '1';
			// «Простой (для пользователя)» — зелёная кнопка (просьба владельца),
			// каркас и LLM-граф — синие (роль Process дизайна v6).
			var tones = {
				emerald: ['n2j-btn-cta'],
				blue: ['n2j-btn-process']
			};
			var want = mode === 'simple' ? 'emerald' : 'blue';
			var drop = want === 'emerald' ? tones.blue : tones.emerald;
			drop.forEach(function (cls) { submit.classList.remove(cls); });
			tones[want].forEach(function (cls) { submit.classList.add(cls); });
			submit.disabled = !canCreate || (mode !== 'template' && !canLlm);
		}
	}

	choices.forEach(function (radio) {
		radio.addEventListener('change', function () {
			if (radio.checked) { selectMode(radio.value); }
		});
	});

	if (advanced && advancedFields) {
		var syncAdvanced = function () { advancedFields.disabled = !advanced.open; };
		advanced.addEventListener('toggle', syncAdvanced);
		syncAdvanced();
	}

	function bindPreset(btn) {
		btn.addEventListener('click', function () {
			if (!prompt) { return; }
			prompt.value = btn.getAttribute('data-nj667-preset') || '';
			prompt.focus();
		});
	}

	function paintIdeas(list) {
		var wrap = document.getElementById('nj667-ideas');
		if (!wrap || !list || !list.length) { return; }
		wrap.textContent = '';
		list.slice(0, 3).forEach(function (idea) {
			idea = String(idea || '');
			if (!idea) { return; }
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.setAttribute('data-nj667-preset', idea);
			btn.title = idea;
			btn.className = chipClass;
			btn.textContent = idea.length > 42 ? idea.slice(0, 42) + '…' : idea;
			bindPreset(btn);
			wrap.appendChild(btn);
		});
	}

	Array.prototype.forEach.call(document.querySelectorAll('[data-nj667-preset]'), bindPreset);

	var refresh = document.getElementById('nj667-ideas-refresh');
	if (refresh) {
		var busy = false;
		refresh.addEventListener('click', function () {
			if (busy) { return; }
			busy = true;
			refresh.disabled = true;
			fetch('/graph3d/api/ideas', { headers: { 'Accept': 'application/json' } })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data && data.ok && data.ideas) { paintIdeas(data.ideas); }
				})
				.catch(function () { })
				.then(function () {
					busy = false;
					refresh.disabled = false;
				});
		});
	}

	if (template && templateDescription) {
		var paintTemplate = function () {
			var option = template.options[template.selectedIndex];
			templateDescription.textContent = option ? (option.getAttribute('data-description') || '') : '';
		};
		template.addEventListener('change', paintTemplate);
	}

	// Компактные поля фото: одно на панель (Simple и LLM). Неактивная панель
	// выключена целиком (fieldset disabled), поэтому файл уезжает ровно один раз.
	function initPhotoField(group) {
		var photoInput = group.querySelector('[data-nj667-photo-input]');
		var photoStatus = group.querySelector('[data-nj667-photo-status]');
		var photoPreview = group.querySelector('[data-nj667-photo-preview]');

		if (!photoInput || !photoStatus || !photoPreview) { return; }

		var photoMax = Math.max(1, parseInt(photoInput.getAttribute('data-max') || '1', 10));
		var photoUrls = [];
		var maxPhotoBytes = 5 * 1024 * 1024;

		function photoSize(bytes) {
			return bytes >= 1024 * 1024 ? (bytes / (1024 * 1024)).toFixed(1).replace('.', ',') + ' МБ'
				: Math.max(1, Math.round(bytes / 1024)) + ' КБ';
		}

		function setPhotoFiles(files) {
			if (typeof DataTransfer === 'undefined') { return false; }
			try {
				var transfer = new DataTransfer();
				files.forEach(function (file) { transfer.items.add(file); });
				photoInput.files = transfer.files;
				return true;
			} catch (e) { return false; }
		}

		function paintPhotos(files, message) {
			photoUrls.forEach(function (url) { URL.revokeObjectURL(url); });
			photoUrls = [];
			photoPreview.textContent = '';
			photoPreview.hidden = !files.length;
			photoStatus.textContent = message || (files.length
				? 'Выбрано: ' + files.length + ' из ' + photoMax + '.'
				: 'Фото не выбрано.');

			files.forEach(function (file, index) {
				var card = document.createElement('div');
				// Миниатюра: фото здесь вспомогательное, а не главное.
				var card = document.createElement('div');
				card.className = 'relative h-14 w-14 shrink-0 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700';
				var url = URL.createObjectURL(file);
				photoUrls.push(url);
				var img = document.createElement('img');
				img.src = url;
				img.alt = file.name;
				img.title = file.name + ' · ' + photoSize(file.size);
				img.className = 'h-full w-full bg-gray-100 object-cover dark:bg-gray-800';
				card.appendChild(img);

				var remove = document.createElement('button');
				remove.type = 'button';
				remove.className = 'absolute right-0 top-0 inline-flex h-5 w-5 items-center justify-center rounded-bl-lg bg-black/60 text-xs text-white transition hover:bg-rose-600';
				remove.setAttribute('aria-label', 'Убрать фото ' + file.name);
				var cross = document.createElement('i');
				cross.className = 'fas fa-xmark';
				cross.setAttribute('aria-hidden', 'true');
				remove.appendChild(cross);
				remove.addEventListener('click', function () {
					var current = Array.prototype.slice.call(photoInput.files || []);
					current.splice(index, 1);
					if (!setPhotoFiles(current)) { photoInput.value = ''; current = []; }
					paintPhotos(current);
				});
				card.appendChild(remove);

				if (file.size > maxPhotoBytes || !/^image\/(jpeg|png|webp|gif)$/i.test(file.type || '')) {
					card.classList.add('border-rose-400');
				}
				photoPreview.appendChild(card);
			});
		}

		photoInput.addEventListener('change', function () {
			var files = Array.prototype.slice.call(photoInput.files || []);
			var message = '';
			if (files.length > photoMax) {
				files = files.slice(0, photoMax);
				if (!setPhotoFiles(files)) { photoInput.value = ''; files = []; }
				message = 'Лимит — ' + photoMax + ' фото. Лишние снимки не добавлены.';
			}
			if (files.some(function (file) { return file.size > maxPhotoBytes; })) {
				message = 'Есть фото больше 5 МБ — уменьшите файл или уберите его.';
			}
			paintPhotos(files, message);
		});
	}

	Array.prototype.forEach.call(document.querySelectorAll('[data-nj667-photo]'), initPhotoField);

	// Сервер уже отметил активный режим; синхронизируем подпись кнопки и disabled-поля.
	var activeChoice = choices.find(function (radio) { return radio.checked; });
	if (activeChoice) { selectMode(activeChoice.value); }
})();
</script>
