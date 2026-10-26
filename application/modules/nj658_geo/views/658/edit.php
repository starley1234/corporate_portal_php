<?php defined('SYSPATH') or die('No direct script access.'); ?>
<?php
/** @var array|null $city @var bool $is_new @var array $form @var array $kinds @var array $regions @var string $csrf @var array $errors */
$label = 'n2j-label';
$input = 'n2j-input mt-1.5 w-full';
$check = 'mt-1 h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900';
$hint  = 'mt-1.5 text-xs leading-relaxed text-gray-400 dark:text-gray-500';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
	<div class="flex flex-wrap items-start justify-between gap-4">
		<div>
			<h1 class="n2j-h1 flex items-center gap-3"><i class="fas fa-city text-blue-600 dark:text-blue-400" aria-hidden="true"></i><?php echo $is_new ? 'Новый населённый пункт' : 'Правка: '.HTML::chars($form['title']); ?></h1>
			<p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">Справочник общий: изменение сразу влияет на подсказки и фильтры всех модулей, которые его читают.</p>
		</div>
		<a href="/geo/admin" class="n2j-btn-secondary">К списку</a>
	</div>

	<?php if ($errors): ?>
		<div class="n2j-flash n2j-flash-err mt-5 block" role="alert">
			<p class="font-semibold">Проверьте заполнение:</p>
			<ul class="mt-1 list-disc space-y-0.5 pl-5">
				<?php foreach ($errors as $err): ?><li><?php echo HTML::chars($err); ?></li><?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="/geo/admin/save" class="mt-6 xl:max-w-4xl">
		<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
		<input type="hidden" name="id" value="<?php echo HTML::chars($form['id']); ?>">

		<section class="n2j-card" aria-labelledby="geo-edit-main">
			<h2 id="geo-edit-main" class="n2j-h3">Название и адрес</h2>
			<div class="mt-4 grid gap-4 sm:grid-cols-2">
				<div>
					<label class="<?php echo $label; ?>" for="f-title">Название</label>
					<input id="f-title" name="title" class="<?php echo $input; ?>" maxlength="100" required value="<?php echo HTML::chars($form['title']); ?>" placeholder="Красноярск">
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-slug">Slug (адрес страницы)</label>
					<input id="f-slug" name="slug" class="n2j-input mt-1.5 w-full font-mono" maxlength="60" value="<?php echo HTML::chars($form['slug']); ?>" placeholder="krasnoyarsk (пусто — из названия)">
					<p class="<?php echo $hint; ?>">Страница: /geo/&lt;slug&gt;. Уникален.</p>
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-full">Полное название</label>
					<input id="f-full" name="title_full" class="<?php echo $input; ?>" maxlength="160" value="<?php echo HTML::chars($form['title_full']); ?>" placeholder="город Красноярск">
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-latin">Латиницей</label>
					<input id="f-latin" name="name_latin" class="n2j-input mt-1.5 w-full font-mono" maxlength="60" value="<?php echo HTML::chars($form['name_latin']); ?>" placeholder="krasnoyarsk">
					<p class="<?php echo $hint; ?>">По латинскому имени тоже работает поиск.</p>
				</div>
			</div>
		</section>

		<section class="n2j-card mt-5" aria-labelledby="geo-edit-place">
			<h2 id="geo-edit-place" class="n2j-h3">Место и данные</h2>
			<div class="mt-4 grid gap-4 sm:grid-cols-2">
				<div>
					<label class="<?php echo $label; ?>" for="f-region">Регион</label>
					<input id="f-region" name="region" class="<?php echo $input; ?>" maxlength="120" list="nj658-regions" value="<?php echo HTML::chars($form['region']); ?>" placeholder="Красноярский край">
					<datalist id="nj658-regions">
						<?php foreach ($regions as $r): ?><option value="<?php echo HTML::chars($r['title']); ?>"></option><?php endforeach; ?>
					</datalist>
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-district">Район</label>
					<input id="f-district" name="district" class="<?php echo $input; ?>" maxlength="140" value="<?php echo HTML::chars($form['district']); ?>" placeholder="городской округ Красноярск">
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-kind">Вид</label>
					<select id="f-kind" name="kind" class="n2j-input n2j-select mt-1.5 w-full">
						<option value="">— не указан —</option>
						<?php foreach ($kinds as $kind): ?>
							<option value="<?php echo HTML::chars($kind); ?>" <?php echo $form['kind'] === $kind ? 'selected' : ''; ?>><?php echo HTML::chars($kind); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-population">Население</label>
					<input id="f-population" name="population" type="number" min="0" max="50000000" step="1" class="<?php echo $input; ?>" value="<?php echo HTML::chars($form['population']); ?>">
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-lat">Широта</label>
					<input id="f-lat" name="lat" class="n2j-input mt-1.5 w-full font-mono" value="<?php echo HTML::chars($form['lat']); ?>" placeholder="56.010563">
				</div>
				<div>
					<label class="<?php echo $label; ?>" for="f-lon">Долгота</label>
					<input id="f-lon" name="lon" class="n2j-input mt-1.5 w-full font-mono" value="<?php echo HTML::chars($form['lon']); ?>" placeholder="92.852572">
				</div>
				<div class="flex flex-wrap items-end gap-5 sm:col-span-2">
					<label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200"><input type="checkbox" name="is_active" value="1" class="<?php echo $check; ?>" <?php echo $form['is_active'] ? 'checked' : ''; ?>>Показывать в справочнике</label>
					<label class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200" title="Есть одноимённый населённый пункт"><input type="checkbox" name="is_dup" value="1" class="<?php echo $check; ?>" <?php echo $form['is_dup'] ? 'checked' : ''; ?>>Одноимённый пункт есть</label>
					<?php if ( ! $is_new AND Arr::get($form, 'source') !== ''): ?>
						<span class="text-xs text-gray-400 dark:text-gray-500">источник: <?php echo HTML::chars($form['source']) === 'manual' ? 'добавлен вручную' : 'сид справочника'; ?></span>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<div class="mt-5 flex flex-wrap items-center gap-3">
			<button type="submit" class="n2j-btn-cta">Сохранить</button>
			<a href="/geo/admin" class="inline-flex min-h-[44px] items-center px-2 text-sm font-semibold text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">Отмена</a>
		</div>
	</form>
</div>
