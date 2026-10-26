<?php defined('SYSPATH') or die('No direct script access.');
$card = 'rounded-xl border border-gray-200 bg-white p-3 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-4';
$in = 'n2j-input mt-1 w-full py-1.5 text-sm min-h-0';
$lab = 'n2j-label text-xs';
$muted = 'text-xs text-gray-500 dark:text-gray-400';
$btn = 'n2j-btn-secondary n2j-btn-sm';
$primary = 'n2j-btn-cta';
?>
<div class="n2j-ds w-full px-3.5 pt-4 pb-16 sm:px-6 lg:px-8">
<?php echo View::factory('nj669/head', ['error' => $error]); ?>
<h1 class="n2j-h1">Лимиты 3D-деталей</h1>
<?php if ($moderator): ?>
<form method="get" action="/solid3d/limits" class="mt-3 flex flex-wrap items-end gap-2" autocomplete="off" data-n2j-no-remember>
	<label for="solid-user" class="<?php echo $lab; ?>">ID пользователя
		<input id="solid-user" name="user" type="number" min="1" required autocomplete="off" data-n2j-no-remember value="<?php echo (int) $target; ?>" class="<?php echo $in; ?> w-40">
	</label>
	<button class="<?php echo $btn; ?>">Показать</button>
</form>
<?php endif; ?>

<section class="<?php echo $card; ?> mt-3 max-w-3xl">
	<h2 class="n2j-h3"><?php echo $target === $uid ? 'Ваши квоты' : 'Квоты пользователя №'.(int) $target; ?></h2>
	<dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
		<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><dt class="text-xs text-gray-500 dark:text-gray-400">Активные проекты</dt><dd class="mt-0.5 text-lg font-bold text-gray-900 dark:text-white"><?php echo (int) $limits['projects_used'].' / '.(int) $limits['projects']; ?></dd></div>
		<div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800"><dt class="text-xs text-gray-500 dark:text-gray-400">Запуски за день</dt><dd class="mt-0.5 text-lg font-bold text-gray-900 dark:text-white"><?php echo (int) $limits['runs_used'].' / '.(int) $limits['daily_runs']; ?></dd></div>
	</dl>
	<p class="mt-3 <?php echo $muted; ?>">Создание, ИИ-правка, ИИ-карточка и идеи тратят одну общую суточную квоту: это запуски с языковой моделью. Неудачные запуски тоже считаются: модель и сборка уже потратили ресурсы. До двух автоисправлений сборки входят в один запуск. День начинается в полночь по часовому поясу БД. Удаление проекта квоту дня не возвращает.</p>
	<p class="mt-2 <?php echo $muted; ?>">Пересчёт размеров и ручной пересчёт кода модели не вызывают, квоту не тратят. ИИ-проверка ракурсов по запросу администратора квоту владельца не тратит.</p>
</section>

<?php if ($moderator): ?>
<form method="post" action="/solid3d/limits?user=<?php echo (int) $target; ?>" class="<?php echo $card; ?> mt-3 max-w-3xl" autocomplete="off" data-n2j-no-remember>
	<h2 class="n2j-h3">Свои лимиты пользователя</h2>
	<input type="hidden" name="csrf" value="<?php echo HTML::chars($csrf); ?>">
	<div class="mt-3 grid gap-3 sm:grid-cols-2">
	<?php foreach (['projects' => 'Активные проекты', 'daily_runs' => 'Запуски за день'] as $key => $title): ?>
		<div>
			<label for="solid-<?php echo $key; ?>" class="<?php echo $lab; ?>"><?php echo $title; ?></label>
			<input type="number" name="<?php echo $key; ?>" id="solid-<?php echo $key; ?>" min="0" max="1000" autocomplete="off" data-n2j-no-remember class="<?php echo $in; ?>" value="<?php echo (int) $limits[$key]; ?>" placeholder="По умолчанию">
		</div>
	<?php endforeach; ?>
	</div>
	<p class="mt-2 <?php echo $muted; ?>">Поля показывают действующие квоты. Пустое поле возвращает значение по умолчанию; ноль запрещает операцию.</p>
	<button class="<?php echo $primary; ?> mt-3">Сохранить лимиты</button>
</form>
<?php endif; ?>
</div>
