<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Создать» (nj668): новая иллюстрация по описанию и/или фото.
 * Форма работает и без JS (POST на /draw2d/create).
 */
$logged       = isset($logged) ? (bool) $logged : FALSE;
$can_start    = isset($can_start) ? (bool) $can_start : FALSE;
$can_moderate = isset($can_moderate) ? (bool) $can_moderate : FALSE;
$error        = isset($error) ? (string) $error : '';
$rate_text    = isset($rate_text) ? (string) $rate_text : '';
$limits       = isset($limits) ? (array) $limits : [];
$ideas        = isset($ideas) ? (array) $ideas : [];
$csrf         = isset($csrf) ? (string) $csrf : '';
$post         = isset($post) ? (array) $post : [];
if (count($ideas) < 3)
{
	$ideas = Service_Nj668_Draw2d::idea_presets_pick(3);
}
?>

<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">

	<h1 class="n2j-h1"><i class="fas fa-wand-magic-sparkles mr-2 text-blue-500"></i>Создать иллюстрацию</h1>
	<p class="mt-1.5 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Опишите сцену словами или приложите фото — модель соберёт вектор из прямых, кривых Безье и заливок. Дальше на холсте их можно править и публиковать в <a href="/draw2d/catalog" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">каталог</a>.</p>

	<?php if ($error !== ''): ?>
	<div class="n2j-flash n2j-flash-err mt-4 flex items-start gap-3 text-xs font-semibold">
		<i class="fas fa-circle-exclamation mt-0.5 shrink-0"></i>
		<span><?php echo HTML::chars($error); ?></span>
	</div>
	<?php endif; ?>

	<div class="mt-6 max-w-3xl space-y-5">

		<?php if (empty($logged)): ?>
		<?php echo Gate::wall('draw2d', [
			'icon'   => 'fas fa-bezier-curve',
			'title'  => 'Плоские иллюстрации — по бесплатному аккаунту',
			'text'   => 'Сцену рисует модель, а холст считает браузер. Работы хранятся на сервере, поэтому войдите — аккаунт бесплатный, каталог открыт и без него.',
			'points' => [
				'Описание темы или фото → вектор: прямые, Безье, заливки',
				'Правка на SVG-холсте: цвет, сдвиг, новые фигуры, ревизии',
				'Публикация в каталог',
				'Лимит: '.$rate_text,
			],
			'stats'  => ['до '.(int) Arr::get($limits, 'max_shapes', 120).' фигур'],
			'cta'    => 'Войти и нарисовать',
		]); ?>
		<?php endif; ?>

		<form method="post" action="/draw2d/create" enctype="multipart/form-data" class="n2j-card" autocomplete="off">
			<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">

			<label for="nj668-prompt" class="n2j-label">Что нарисовать</label>
			<textarea id="nj668-prompt" name="prompt" rows="4" maxlength="<?php echo (int) Arr::get($limits, 'max_prompt', 800); ?>"
				autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
				class="n2j-textarea mt-2 w-full resize-y leading-relaxed"
				placeholder="Закат над морем: лодка с белым парусом, волны мягкими дугами, тёплое солнце у горизонта"><?php echo HTML::chars((string) Arr::get($post, 'prompt', '')); ?></textarea>

			<?php if ($ideas): ?>
			<div class="mt-2 flex flex-wrap items-center gap-1.5">
				<div id="nj668-ideas" class="flex flex-wrap gap-1.5">
				<?php foreach (array_slice($ideas, 0, 3) as $idea): ?>
				<?php $idea = (string) $idea; ?>
				<button type="button" data-nj668-preset="<?php echo HTML::chars($idea); ?>"
					title="<?php echo HTML::chars($idea); ?>"
					class="n2j-btn-secondary n2j-btn-sm nj668-preset max-w-full truncate">
					<?php echo HTML::chars(UTF8::substr($idea, 0, 42)); ?><?php echo UTF8::strlen($idea) > 42 ? '…' : ''; ?>
				</button>
				<?php endforeach; ?>
				</div>
				<button type="button" id="nj668-ideas-refresh" aria-label="Другие идеи" title="Другие идеи"
					class="n2j-btn-icon shrink-0">
					<i class="fas fa-rotate"></i>
				</button>
			</div>
			<?php endif; ?>
			<p class="n2j-hint mt-1.5">Текст, фото или оба. Чем точнее описание, тем меньше править руками. Кнопки — готовые идеи, можно править.</p>

			<div class="mt-3">
				<label for="nj668-photos" class="n2j-label">Фото сцены <span class="font-normal text-gray-400">(необязательно, до 4 кадров)</span></label>
				<input id="nj668-photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple
					class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-300 dark:file:bg-blue-950 dark:file:text-blue-300">
				<p class="n2j-hint mt-1.5">Можно без текста: модель нарисует вектор по фото. jpeg, png, webp до 5 МБ.</p>
			</div>

			<div class="mt-4 flex flex-wrap items-center gap-2">
				<button type="submit" id="nj668-submit"<?php echo ($logged AND $can_start) ? '' : ' disabled'; ?>
					class="n2j-btn-process">
					<i class="fas fa-wand-magic-sparkles mr-2"></i>Нарисовать
				</button>
				<?php if ($logged AND ! $can_start): ?>
				<span class="text-xs text-amber-700 dark:text-amber-400">Сборку запускают vip и администраторы.</span>
				<?php endif; ?>
			</div>

			<?php if ($can_moderate): ?>
			<p class="n2j-hint mt-2"><a href="/draw2d/ideas" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">идеи</a>
				· <a href="/draw2d/moderate" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">модерация</a></p>
			<?php endif; ?>
		</form>
	</div>
</div>

<script>
(function () {
	var ta = document.getElementById('nj668-prompt');
	var chipClass = 'nj668-preset max-w-full truncate rounded-full border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-800';

	function bindPreset(btn) {
		btn.addEventListener('click', function () {
			if (!ta) { return; }
			ta.value = btn.getAttribute('data-nj668-preset') || '';
			ta.focus();
		});
	}

	function paintIdeas(list) {
		var wrap = document.getElementById('nj668-ideas');
		if (!wrap || !list || !list.length) { return; }
		wrap.textContent = '';
		list.slice(0, 3).forEach(function (idea) {
			idea = String(idea || '');
			if (!idea) { return; }
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.setAttribute('data-nj668-preset', idea);
			btn.title = idea;
			btn.className = chipClass;
			btn.textContent = idea.length > 42 ? idea.slice(0, 42) + '…' : idea;
			bindPreset(btn);
			wrap.appendChild(btn);
		});
	}

	Array.prototype.forEach.call(document.querySelectorAll('[data-nj668-preset]'), bindPreset);

	var refresh = document.getElementById('nj668-ideas-refresh');
	if (refresh) {
		var busy = false;
		refresh.addEventListener('click', function () {
			if (busy) { return; }
			busy = true;
			refresh.disabled = true;
			fetch('/draw2d/api/ideas', { headers: { 'Accept': 'application/json' } })
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
})();
</script>
