<?php defined('SYSPATH') or die('No direct script access.');
$in = 'n2j-input mt-1 w-full';
$lab = 'n2j-label text-xs';
$hint = 'mt-1 text-xs text-gray-500 dark:text-gray-400';
$card = 'rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-4';
$h2 = 'text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<?php echo View::factory('nj669/head', ['error' => $error]); ?>
<div class="flex flex-wrap items-end justify-between gap-2">
	<div>
		<h1 class="n2j-h1"><i class="fas fa-wand-magic-sparkles mr-1.5 text-blue-500" aria-hidden="true"></i>Новая 3D-деталь</h1>
		<p class="mt-1 max-w-3xl text-xs text-gray-600 sm:text-sm dark:text-gray-300">Одна деталь — один проект. Опишите её, при желании добавьте фото: ИИ напишет параметрический код и соберёт STL и GLB.</p>
	</div>
	<a href="/solid3d/catalog" class="n2j-btn-secondary n2j-btn-sm">Каталог</a>
</div>

<div class="mt-3 grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(300px,360px)]">
	<form method="post" enctype="multipart/form-data" action="/solid3d/create" class="<?php echo $card; ?>" data-solid-form autocomplete="off" data-n2j-no-remember>
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
		<input type="hidden" name="remix" value="<?php echo HTML::chars((string) Arr::get($data, 'remix', '')); ?>">

		<label for="solid-title" class="<?php echo $lab; ?>">Название <span class="font-normal text-gray-500">— необязательно</span></label>
		<input id="solid-title" name="title" type="text" maxlength="200" autocomplete="off" class="<?php echo $in; ?>" value="<?php echo HTML::chars((string) Arr::get($data, 'title', '')); ?>" placeholder="Например, кронштейн для полки">
		<p class="<?php echo $hint; ?>">Если пусто, название возьмём из начала описания.</p>

		<label for="solid-prompt" class="<?php echo $lab; ?> mt-4">Описание детали</label>
		<textarea id="solid-prompt" name="prompt" rows="8" maxlength="6000" autocomplete="off" class="n2j-textarea mt-1 w-full font-[inherit] leading-relaxed" placeholder="Назначение, размеры в мм, отверстия, допуски. Например: кронштейн 60×40×30 мм, стенка 4 мм, четыре отверстия под М4."><?php echo HTML::chars((string) Arr::get($data, 'prompt', '')); ?></textarea>
		<p class="<?php echo $hint; ?>">Без описания нужно фото. Размеры, которых нет в тексте, ИИ выберет сам и запишет в комментарии кода.</p>

		<label for="solid-photo" class="<?php echo $lab; ?> mt-4">Фото-референс <span class="font-normal text-gray-500">— необязательно</span></label>
		<input id="solid-photo" type="file" accept="image/jpeg,image/png,image/webp" name="photo" autocomplete="off" class="mt-1 block w-full text-sm text-gray-700 file:mr-3 file:rounded-lg file:border file:border-gray-300 file:bg-white file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-gray-700 hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-700 dark:file:bg-gray-800 dark:file:text-gray-200">
		<p class="<?php echo $hint; ?>">JPEG, PNG или WebP до 5 МБ и 24 Мп. Фото видит ИИ, в каталог оно не попадает. По фото масштаб не определить: укажите размеры в тексте.</p>

		<div class="mt-5 flex flex-wrap items-center gap-2">
			<button class="n2j-btn-cta" type="submit">Создать деталь</button>
			<span class="text-xs text-gray-500 dark:text-gray-400">Сборка занимает несколько минут. Можно закрыть страницу.</span>
		</div>
	</form>

	<aside class="space-y-3">
		<section class="<?php echo $card; ?>">
			<h2 class="<?php echo $h2; ?>">Ваши квоты</h2>
			<dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
				<div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800"><dt class="text-xs text-gray-500 dark:text-gray-400">Проекты</dt><dd class="font-semibold text-gray-900 dark:text-white"><?php echo (int) $limits['projects_used'].' / '.(int) $limits['projects']; ?></dd></div>
				<div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800"><dt class="text-xs text-gray-500 dark:text-gray-400">Запуски сегодня</dt><dd class="font-semibold text-gray-900 dark:text-white"><?php echo (int) $limits['runs_used'].' / '.(int) $limits['daily_runs']; ?></dd></div>
			</dl>
			<a class="mt-2 inline-block text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/solid3d/limits">Подробнее о лимитах</a>
		</section>

		<section class="<?php echo $card; ?>">
			<h2 class="<?php echo $h2; ?>">Как это работает</h2>
			<ol class="mt-2 list-decimal space-y-1.5 pl-4 text-xs text-gray-700 dark:text-gray-300">
				<li>ИИ пишет параметрический код по вашему описанию.</li>
				<li>Сборщик собирает деталь: STL для печати, GLB для просмотра, три ракурса.</li>
				<li>Ошибка сборки исправляется автоматически, до двух повторов в пределах одного запуска.</li>
				<li>Проверку ракурсов ИИ запускает администратор отдельно, по запросу.</li>
			</ol>
		</section>

		<?php if ($ideas): ?>
		<details class="<?php echo $card; ?>">
			<summary class="cursor-pointer text-xs font-semibold text-gray-700 dark:text-gray-200">Идеи для старта (<?php echo count($ideas); ?>)</summary>
			<div class="mt-2 flex flex-col gap-1.5">
				<?php foreach ($ideas as $idea): ?>
					<button type="button" class="n2j-btn-secondary n2j-btn-sm text-left" data-solid-idea="<?php echo HTML::chars($idea); ?>"><?php echo HTML::chars(mb_substr($idea, 0, 130).(mb_strlen($idea) > 130 ? '…' : '')); ?></button>
				<?php endforeach; ?>
			</div>
		</details>
		<?php endif; ?>
	</aside>
</div>
<script defer src="/assets/js/nj669-solid3d.js?v=20261016d"></script>
</div>
