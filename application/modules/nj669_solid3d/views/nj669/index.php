<?php defined('SYSPATH') or die('No direct script access.');
$card = 'rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-4';
$h2 = 'text-sm font-semibold text-gray-900 dark:text-white';
$p = 'mt-1 text-xs text-gray-600 dark:text-gray-300';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<?php echo View::factory('nj669/head', ['error' => $error]); ?>
<section class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 shadow-sm dark:border-blue-900 dark:bg-gray-900 sm:p-6">
	<span class="inline-flex items-center rounded-md bg-blue-600/10 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-400/10 dark:text-blue-300">Параметрическая · GLB · STL</span>
	<h1 class="n2j-h1 mt-2">От идеи — к 3D-детали</h1>
	<p class="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">Опишите одну деталь, укажите размеры или приложите фото. ИИ напишет параметрический код и соберёт модель, которую можно вращать и скачать как STL для печати.</p>
	<div class="mt-4 flex flex-wrap gap-2">
		<a class="n2j-btn-cta" href="/solid3d/create">Создать деталь</a>
		<a class="n2j-btn-secondary" href="/solid3d/catalog">Каталог деталей</a>
	</div>
	<?php if (! empty($totals) AND (int) $totals['runs'] + (int) $totals['assist_runs'] > 0): ?>
	<p class="mt-3 text-xs text-gray-600 dark:text-gray-300">Ваш расход языковой модели (вход → выход): детали <span class="font-mono font-semibold"><?php echo Service_Nj669_Solid3d::tokens_text($totals['tokens_in'], $totals['tokens_out']); ?></span> · помощник <span class="font-mono font-semibold"><?php echo Service_Nj669_Solid3d::tokens_text($totals['assist_tokens_in'], $totals['assist_tokens_out']); ?></span> · <a class="font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/solid3d/usage">подробнее</a></p>
	<?php endif; ?>
	<p class="n2j-hint mt-3">Не граф и не сборка: один проект — одна деталь. Создание доступно после входа, в пределах квот. Каталог открыт всем.</p>
</section>

<div class="mt-3 grid gap-3 md:grid-cols-3">
	<section class="<?php echo $card; ?>"><span class="text-xs font-semibold text-blue-600 dark:text-blue-400">Шаг 1</span><h2 class="<?php echo $h2; ?>">Опишите задачу</h2><p class="<?php echo $p; ?>">Назначение, размеры в мм, посадки, диаметр отверстий, толщина стенок. Фото — ориентир, а не точный обмер.</p></section>
	<section class="<?php echo $card; ?>"><span class="text-xs font-semibold text-blue-600 dark:text-blue-400">Шаг 2</span><h2 class="<?php echo $h2; ?>">Посмотрите и уточните</h2><p class="<?php echo $p; ?>">Вращайте модель, попросите ИИ изменить деталь или поправьте параметры в коде. Удачные версии сохраняются в истории.</p></section>
	<section class="<?php echo $card; ?>"><span class="text-xs font-semibold text-blue-600 dark:text-blue-400">Шаг 3</span><h2 class="<?php echo $h2; ?>">Заберите STL</h2><p class="<?php echo $p; ?>">Скачайте STL для слайсера. Опубликуйте через модерацию или используйте чужую деталь как шаблон.</p></section>
</div>

<p class="n2j-flash n2j-flash-warn mt-3 text-xs block">Проверка ИИ не заменяет инженерный расчёт. Перед печатью проверьте единицы, размеры и сетку в слайсере. STL не хранит цвет и материал.</p>

<div class="mt-5 flex items-center justify-between gap-2">
	<h2 class="n2j-h3">Детали из каталога</h2>
	<a class="text-xs font-semibold text-blue-600 hover:underline dark:text-blue-400" href="/solid3d/catalog">Все детали →</a>
</div>
<div class="mt-3"><?php echo View::factory('nj669/cards', ['items' => array_slice($items, 0, 8), 'mode' => 'catalog']); ?></div>
</div>
